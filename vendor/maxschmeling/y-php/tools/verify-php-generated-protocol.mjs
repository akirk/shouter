import { execFileSync } from 'node:child_process'
import * as decoding from 'lib0/decoding'
import * as encoding from 'lib0/encoding'
import * as Y from 'yjs'
import * as awarenessProtocol from 'y-protocols/awareness'
import * as syncProtocol from 'y-protocols/sync'

const fromBase64 = value => Uint8Array.from(Buffer.from(value, 'base64'))
const toBase64 = bytes => Buffer.from(bytes).toString('base64')

const phpOutput = execFileSync('php', ['tools/php-generated-protocol.php'], { encoding: 'utf8' })
const fixtures = JSON.parse(phpOutput)

const normalizeJson = value => {
  if (value instanceof Y.Doc) {
    return []
  }
  if (value instanceof Uint8Array) {
    return Array.from(value)
  }
  if (Array.isArray(value)) {
    return value.map(normalizeJson)
  }
  if (value && typeof value === 'object') {
    return Object.fromEntries(
      Object.entries(value)
        .sort(([left], [right]) => left.localeCompare(right))
        .map(([key, nested]) => [key, normalizeJson(nested)])
    )
  }
  return value
}

const assertSameJson = (actual, expected, label) => {
  const actualJson = JSON.stringify(normalizeJson(actual))
  const expectedJson = JSON.stringify(normalizeJson(expected))
  if (actualJson !== expectedJson) {
    throw new Error(`${label}: expected ${expectedJson}, got ${actualJson}`)
  }
}

const expectedDocJson = value => {
  const normalized = normalizeJson(value)
  return Array.isArray(normalized) && normalized.length === 0 ? {} : normalized
}

const expectedObjectJson = value => {
  const normalized = normalizeJson(value)
  return Array.isArray(normalized) && normalized.length === 0 ? {} : normalized
}

const assertOptionalArrayXmlFragments = (doc, expectedFragments, label) => {
  if (!Array.isArray(expectedFragments)) {
    return
  }

  const array = doc.getArray('array')
  for (const { index, xml } of expectedFragments) {
    const value = array.get(index)
    if (value?.constructor?.name !== 'YXmlFragment') {
      throw new Error(`${label}: expected array item ${index} to be YXmlFragment, got ${value?.constructor?.name ?? 'null'}`)
    }

    assertSameJson(value.toString(), xml, `${label} array XML fragment ${index}`)
  }
}

const assertOptionalArraySubdocs = (doc, expectedSubdocs, label) => {
  if (!Array.isArray(expectedSubdocs)) {
    return
  }

  const array = doc.getArray('array')
  for (const { index, guid, meta, shouldLoad } of expectedSubdocs) {
    const value = array.get(index)
    if (!(value instanceof Y.Doc)) {
      throw new Error(`${label}: expected array item ${index} to be Y.Doc, got ${value?.constructor?.name ?? 'null'}`)
    }

    assertSameJson(value.guid, guid, `${label} array subdoc ${index} guid`)
    assertSameJson(value.meta, meta, `${label} array subdoc ${index} meta`)
    assertSameJson(value.shouldLoad, shouldLoad, `${label} array subdoc ${index} shouldLoad`)
  }
}

const assertOptionalMapXmlFragments = (doc, expectedFragments, label) => {
  if (!Array.isArray(expectedFragments)) {
    return
  }

  const map = doc.getMap('map')
  for (const { key, xml } of expectedFragments) {
    const value = map.get(key)
    if (value?.constructor?.name !== 'YXmlFragment') {
      throw new Error(`${label}: expected map value ${key} to be YXmlFragment, got ${value?.constructor?.name ?? 'null'}`)
    }

    assertSameJson(value.toString(), xml, `${label} map XML fragment ${key}`)
  }
}

const assertOptionalMapSubdocs = (doc, expectedSubdocs, label) => {
  if (!Array.isArray(expectedSubdocs)) {
    return
  }

  const map = doc.getMap('map')
  for (const { key, guid, meta, shouldLoad } of expectedSubdocs) {
    const value = map.get(key)
    if (!(value instanceof Y.Doc)) {
      throw new Error(`${label}: expected map value ${key} to be Y.Doc, got ${value?.constructor?.name ?? 'null'}`)
    }

    assertSameJson(value.guid, guid, `${label} map subdoc ${key} guid`)
    assertSameJson(value.meta, meta, `${label} map subdoc ${key} meta`)
    assertSameJson(value.shouldLoad, shouldLoad, `${label} map subdoc ${key} shouldLoad`)
  }
}

const assertOptionalXmlHookJson = (doc, expectedHookJson, label) => {
  if (expectedHookJson === undefined) {
    return
  }

  const hook = doc.getXmlFragment('xml').get(0)
  if (hook?.constructor?.name !== 'YXmlHook') {
    throw new Error(`${label}: expected first XML child to be YXmlHook, got ${hook?.constructor?.name ?? 'null'}`)
  }

  assertSameJson(hook.toJSON(), expectedHookJson, `${label} hook JSON`)
}

const normalizeXmlAttributeValue = value => {
  const typeName = value?.constructor?.name
  if (['YArray', 'YMap', 'YText', 'YXmlElement', 'YXmlFragment', 'YXmlHook', 'YXmlText'].includes(typeName)) {
    return value.toJSON()
  }

  return value
}

const xmlElementAttributes = doc => {
  const element = doc.getXmlFragment('xml').get(0)
  if (element?.constructor?.name !== 'YXmlElement') {
    throw new Error(`expected first XML child to be YXmlElement, got ${element?.constructor?.name ?? 'null'}`)
  }

  return Object.fromEntries(
    Object.entries(element.getAttributes())
      .map(([key, value]) => [key, normalizeXmlAttributeValue(value)])
  )
}

const xmlTextAttributes = doc => {
  const text = doc.getXmlFragment('xml').get(0)
  if (text?.constructor?.name !== 'YXmlText') {
    throw new Error(`expected first XML child to be YXmlText, got ${text?.constructor?.name ?? 'null'}`)
  }

  return Object.fromEntries(
    Object.entries(text.getAttributes())
      .map(([key, value]) => [key, normalizeXmlAttributeValue(value)])
  )
}

const xmlParagraphTextDelta = doc => {
  const paragraph = doc.getXmlFragment('xml').get(0)
  if (paragraph?.constructor?.name !== 'YXmlElement') {
    throw new Error(`expected first XML child to be YXmlElement, got ${paragraph?.constructor?.name ?? 'null'}`)
  }

  const text = paragraph.get(0)
  if (text?.constructor?.name !== 'YXmlText') {
    throw new Error(`expected first XML paragraph child to be YXmlText, got ${text?.constructor?.name ?? 'null'}`)
  }

  return text.toDelta()
}

const readProtocolMessage = message => {
  const decoder = decoding.createDecoder(message)
  const type = decoding.readVarUint(decoder)
  const payload = decoding.readVarUint8Array(decoder)
  if (decoding.hasContent(decoder)) {
    throw new Error('Protocol message contains trailing bytes')
  }
  return { type, payload }
}

const applySyncMessage = (doc, message, origin) => {
  const decoder = decoding.createDecoder(message)
  const reply = encoding.createEncoder()
  const type = syncProtocol.readSyncMessage(decoder, reply, doc, origin)
  if (decoding.hasContent(decoder)) {
    throw new Error('Sync message was not fully consumed')
  }
  return { type, reply: encoding.toUint8Array(reply) }
}

const applyV2SyncPayload = (doc, message, expectedType, origin, label) => {
  const protocolMessage = readProtocolMessage(message)
  if (protocolMessage.type !== expectedType) {
    throw new Error(`${label} type: expected ${expectedType}, got ${protocolMessage.type}`)
  }
  Y.applyUpdateV2(doc, protocolMessage.payload, origin)
}

const sync = fixtures.sync

const syncStep2Doc = new Y.Doc({ gc: false })
syncStep2Doc.getText('content')
const syncStep2Result = applySyncMessage(syncStep2Doc, fromBase64(sync.syncStep2ForEmpty), 'php-sync-step2')
if (syncStep2Result.type !== syncProtocol.messageYjsSyncStep2) {
  throw new Error(`sync step 2 type: expected ${syncProtocol.messageYjsSyncStep2}, got ${syncStep2Result.type}`)
}
assertSameJson(syncStep2Doc.toJSON(), sync.expectedJson, 'sync step 2 JSON')

const syncUpdateDoc = new Y.Doc({ gc: false })
syncUpdateDoc.getText('content')
const syncUpdateResult = applySyncMessage(syncUpdateDoc, fromBase64(sync.updateMessage), 'php-sync-update')
if (syncUpdateResult.type !== syncProtocol.messageYjsUpdate) {
  throw new Error(`sync update type: expected ${syncProtocol.messageYjsUpdate}, got ${syncUpdateResult.type}`)
}
assertSameJson(syncUpdateDoc.toJSON(), sync.expectedJson, 'sync update JSON')

const syncV2Doc = new Y.Doc({ gc: false })
syncV2Doc.getText('content')
applyV2SyncPayload(syncV2Doc, fromBase64(sync.syncStep2V2ForEmpty), syncProtocol.messageYjsSyncStep2, 'php-sync-v2-step2', 'sync V2 step 2')
assertSameJson(syncV2Doc.toJSON(), sync.expectedJson, 'sync V2 step 2 JSON')

const syncV2UpdateDoc = new Y.Doc({ gc: false })
syncV2UpdateDoc.getText('content')
applyV2SyncPayload(syncV2UpdateDoc, fromBase64(sync.updateV2Message), syncProtocol.messageYjsUpdate, 'php-sync-v2-update', 'sync V2 update')
assertSameJson(syncV2UpdateDoc.toJSON(), sync.expectedJson, 'sync V2 update JSON')

const observedUpdateDoc = new Y.Doc({ gc: false })
observedUpdateDoc.getText('content')
for (const message of sync.observedUpdateMessages.messages) {
  const result = applySyncMessage(observedUpdateDoc, fromBase64(message), 'php-observed-sync-update')
  if (result.type !== syncProtocol.messageYjsUpdate) {
    throw new Error(`observed sync update type: expected ${syncProtocol.messageYjsUpdate}, got ${result.type}`)
  }
}
assertSameJson(observedUpdateDoc.toJSON(), sync.observedUpdateMessages.expectedJson, 'observed sync update JSON')

const observedUpdateV2Doc = new Y.Doc({ gc: false })
observedUpdateV2Doc.getText('content')
for (const message of sync.observedUpdateV2Messages.messages) {
  applyV2SyncPayload(observedUpdateV2Doc, fromBase64(message), syncProtocol.messageYjsUpdate, 'php-observed-sync-v2-update', 'observed sync V2 update')
}
assertSameJson(observedUpdateV2Doc.toJSON(), sync.observedUpdateV2Messages.expectedJson, 'observed sync V2 update JSON')

const observedRich = sync.observedRichUpdateMessages
const observedRichDoc = new Y.Doc({ gc: false })
observedRichDoc.getArray('array')
observedRichDoc.getMap('map')
observedRichDoc.getXmlFragment('xml')
for (const message of observedRich.messages) {
  const result = applySyncMessage(observedRichDoc, fromBase64(message), 'php-observed-rich-sync-update')
  if (result.type !== syncProtocol.messageYjsUpdate) {
    throw new Error(`observed rich sync update type: expected ${syncProtocol.messageYjsUpdate}, got ${result.type}`)
  }
}
assertSameJson(observedRichDoc.toJSON(), observedRich.expectedJson, 'observed rich sync update JSON')
assertSameJson(observedRichDoc.getArray('array').get(0).getAttributes(), observedRich.expectedNestedTextAttributes, 'observed rich sync nested text attributes')
assertSameJson(observedRichDoc.getMap('map').get(observedRich.expectedMapTextKey).getAttributes(), observedRich.expectedMapTextAttributes, 'observed rich sync map text attributes')
assertSameJson(
  observedRichDoc.getMap('map').get(observedRich.expectedDeepMapKey).get(observedRich.expectedDeepMapTextKey).toDelta(),
  observedRich.expectedDeepMapTextDelta,
  'observed rich sync deep map text delta'
)
assertOptionalArrayXmlFragments(observedRichDoc, observedRich.expectedArrayXmlFragments, 'observed rich sync update')
assertOptionalMapXmlFragments(observedRichDoc, observedRich.expectedMapXmlFragments, 'observed rich sync update')

const observedRichV2Doc = new Y.Doc({ gc: false })
observedRichV2Doc.getArray('array')
observedRichV2Doc.getMap('map')
observedRichV2Doc.getXmlFragment('xml')
for (const message of observedRich.messagesV2) {
  applyV2SyncPayload(observedRichV2Doc, fromBase64(message), syncProtocol.messageYjsUpdate, 'php-observed-rich-sync-v2-update', 'observed rich sync V2 update')
}
assertSameJson(observedRichV2Doc.toJSON(), observedRich.expectedJson, 'observed rich sync V2 update JSON')
assertSameJson(observedRichV2Doc.getArray('array').get(0).getAttributes(), observedRich.expectedNestedTextAttributes, 'observed rich sync V2 nested text attributes')
assertSameJson(observedRichV2Doc.getMap('map').get(observedRich.expectedMapTextKey).getAttributes(), observedRich.expectedMapTextAttributes, 'observed rich sync V2 map text attributes')
assertSameJson(
  observedRichV2Doc.getMap('map').get(observedRich.expectedDeepMapKey).get(observedRich.expectedDeepMapTextKey).toDelta(),
  observedRich.expectedDeepMapTextDelta,
  'observed rich sync V2 deep map text delta'
)
assertOptionalArrayXmlFragments(observedRichV2Doc, observedRich.expectedArrayXmlFragments, 'observed rich sync V2 update')
assertOptionalMapXmlFragments(observedRichV2Doc, observedRich.expectedMapXmlFragments, 'observed rich sync V2 update')

const rawGc = sync.rawGc
const rawGcUpdateDoc = new Y.Doc({ gc: false })
const rawGcUpdateResult = applySyncMessage(rawGcUpdateDoc, fromBase64(rawGc.updateMessage), 'php-raw-gc-update')
if (rawGcUpdateResult.type !== syncProtocol.messageYjsUpdate) {
  throw new Error(`raw GC update type: expected ${syncProtocol.messageYjsUpdate}, got ${rawGcUpdateResult.type}`)
}
assertSameJson(rawGcUpdateDoc.toJSON(), expectedDocJson(rawGc.expectedJson), 'raw GC update JSON')
if (toBase64(Y.encodeStateVector(rawGcUpdateDoc)) !== rawGc.stateVectorV1) {
  throw new Error('raw GC update state vector mismatch')
}

const rawGcStep2Doc = new Y.Doc({ gc: false })
const rawGcStep2Result = applySyncMessage(rawGcStep2Doc, fromBase64(rawGc.syncStep2ForEmpty), 'php-raw-gc-step2')
if (rawGcStep2Result.type !== syncProtocol.messageYjsSyncStep2) {
  throw new Error(`raw GC sync step 2 type: expected ${syncProtocol.messageYjsSyncStep2}, got ${rawGcStep2Result.type}`)
}
assertSameJson(rawGcStep2Doc.toJSON(), expectedDocJson(rawGc.expectedJson), 'raw GC sync step 2 JSON')
if (toBase64(Y.encodeStateVector(rawGcStep2Doc)) !== rawGc.stateVectorV1) {
  throw new Error('raw GC sync step 2 state vector mismatch')
}

const rawGcV2UpdateDoc = new Y.Doc({ gc: false })
applyV2SyncPayload(rawGcV2UpdateDoc, fromBase64(rawGc.updateV2Message), syncProtocol.messageYjsUpdate, 'php-raw-gc-v2-update', 'raw GC V2 update')
assertSameJson(rawGcV2UpdateDoc.toJSON(), expectedDocJson(rawGc.expectedJson), 'raw GC V2 update JSON')
if (toBase64(Y.encodeStateVector(rawGcV2UpdateDoc)) !== rawGc.stateVectorV1) {
  throw new Error('raw GC V2 update state vector mismatch')
}

const rawGcV2Step2Doc = new Y.Doc({ gc: false })
applyV2SyncPayload(rawGcV2Step2Doc, fromBase64(rawGc.syncStep2V2ForEmpty), syncProtocol.messageYjsSyncStep2, 'php-raw-gc-v2-step2', 'raw GC V2 sync step 2')
assertSameJson(rawGcV2Step2Doc.toJSON(), expectedDocJson(rawGc.expectedJson), 'raw GC V2 sync step 2 JSON')
if (toBase64(Y.encodeStateVector(rawGcV2Step2Doc)) !== rawGc.stateVectorV1) {
  throw new Error('raw GC V2 sync step 2 state vector mismatch')
}

const partial = sync.partial
const partialDoc = new Y.Doc({ gc: false })
partialDoc.getText('content')
Y.applyUpdate(partialDoc, fromBase64(partial.prefixUpdateV1), 'php-partial-prefix')
assertSameJson(partialDoc.toJSON(), partial.prefixJson, 'partial prefix JSON')
const partialStep2Result = applySyncMessage(partialDoc, fromBase64(partial.syncStep2ForPrefix), 'php-partial-sync-step2')
if (partialStep2Result.type !== syncProtocol.messageYjsSyncStep2) {
  throw new Error(`partial sync step 2 type: expected ${syncProtocol.messageYjsSyncStep2}, got ${partialStep2Result.type}`)
}
assertSameJson(partialDoc.toJSON(), sync.expectedJson, 'partial sync step 2 JSON')
if (toBase64(Y.encodeStateVector(partialDoc)) !== sync.stateVectorV1) {
  throw new Error('partial sync step 2 state vector mismatch')
}

const partialV2Doc = new Y.Doc({ gc: false })
partialV2Doc.getText('content')
Y.applyUpdateV2(partialV2Doc, fromBase64(partial.prefixUpdateV2), 'php-partial-v2-prefix')
assertSameJson(partialV2Doc.toJSON(), partial.prefixJson, 'partial V2 prefix JSON')
applyV2SyncPayload(partialV2Doc, fromBase64(partial.syncStep2V2ForPrefix), syncProtocol.messageYjsSyncStep2, 'php-partial-v2-step2', 'partial V2 sync step 2')
assertSameJson(partialV2Doc.toJSON(), sync.expectedJson, 'partial V2 sync step 2 JSON')
if (toBase64(Y.encodeStateVector(partialV2Doc)) !== sync.stateVectorV1) {
  throw new Error('partial V2 sync step 2 state vector mismatch')
}

const textAttributesPartial = sync.textAttributesPartial
const textAttributesPartialDoc = new Y.Doc({ gc: false })
textAttributesPartialDoc.getText('content')
Y.applyUpdate(textAttributesPartialDoc, fromBase64(textAttributesPartial.prefixUpdateV1), 'php-text-attributes-partial-prefix')
assertSameJson(textAttributesPartialDoc.toJSON(), textAttributesPartial.prefixJson, 'text attributes partial prefix JSON')
assertSameJson(textAttributesPartialDoc.getText('content').getAttributes(), expectedObjectJson(textAttributesPartial.prefixTextAttributes), 'text attributes partial prefix attributes')
const textAttributesPartialStep2Result = applySyncMessage(textAttributesPartialDoc, fromBase64(textAttributesPartial.syncStep2ForPrefix), 'php-text-attributes-partial-step2')
if (textAttributesPartialStep2Result.type !== syncProtocol.messageYjsSyncStep2) {
  throw new Error(`text attributes partial sync step 2 type: expected ${syncProtocol.messageYjsSyncStep2}, got ${textAttributesPartialStep2Result.type}`)
}
assertSameJson(textAttributesPartialDoc.toJSON(), textAttributesPartial.expectedJson, 'text attributes partial sync step 2 JSON')
assertSameJson(textAttributesPartialDoc.getText('content').getAttributes(), textAttributesPartial.expectedTextAttributes, 'text attributes partial sync step 2 attributes')
if (toBase64(Y.encodeStateVector(textAttributesPartialDoc)) !== textAttributesPartial.sourceStateVectorV1) {
  throw new Error('text attributes partial sync step 2 state vector mismatch')
}

const textAttributesPartialV2Doc = new Y.Doc({ gc: false })
textAttributesPartialV2Doc.getText('content')
Y.applyUpdateV2(textAttributesPartialV2Doc, fromBase64(textAttributesPartial.prefixUpdateV2), 'php-text-attributes-partial-v2-prefix')
assertSameJson(textAttributesPartialV2Doc.toJSON(), textAttributesPartial.prefixJson, 'text attributes partial V2 prefix JSON')
assertSameJson(textAttributesPartialV2Doc.getText('content').getAttributes(), expectedObjectJson(textAttributesPartial.prefixTextAttributes), 'text attributes partial V2 prefix attributes')
applyV2SyncPayload(textAttributesPartialV2Doc, fromBase64(textAttributesPartial.syncStep2V2ForPrefix), syncProtocol.messageYjsSyncStep2, 'php-text-attributes-partial-v2-step2', 'text attributes partial V2 sync step 2')
assertSameJson(textAttributesPartialV2Doc.toJSON(), textAttributesPartial.expectedJson, 'text attributes partial V2 sync step 2 JSON')
assertSameJson(textAttributesPartialV2Doc.getText('content').getAttributes(), textAttributesPartial.expectedTextAttributes, 'text attributes partial V2 sync step 2 attributes')
if (toBase64(Y.encodeStateVector(textAttributesPartialV2Doc)) !== textAttributesPartial.sourceStateVectorV1) {
  throw new Error('text attributes partial V2 sync step 2 state vector mismatch')
}

const nestedTextAttributesPartial = sync.nestedTextAttributesPartial
const nestedTextAttributesPartialDoc = new Y.Doc({ gc: false })
nestedTextAttributesPartialDoc.getArray('array')
Y.applyUpdate(nestedTextAttributesPartialDoc, fromBase64(nestedTextAttributesPartial.prefixUpdateV1), 'php-nested-text-attributes-partial-prefix')
assertSameJson(nestedTextAttributesPartialDoc.toJSON(), nestedTextAttributesPartial.prefixJson, 'nested text attributes partial prefix JSON')
assertSameJson(nestedTextAttributesPartialDoc.getArray('array').get(0).getAttributes(), expectedObjectJson(nestedTextAttributesPartial.prefixTextAttributes), 'nested text attributes partial prefix attributes')
const nestedTextAttributesPartialStep2Result = applySyncMessage(nestedTextAttributesPartialDoc, fromBase64(nestedTextAttributesPartial.syncStep2ForPrefix), 'php-nested-text-attributes-partial-step2')
if (nestedTextAttributesPartialStep2Result.type !== syncProtocol.messageYjsSyncStep2) {
  throw new Error(`nested text attributes partial sync step 2 type: expected ${syncProtocol.messageYjsSyncStep2}, got ${nestedTextAttributesPartialStep2Result.type}`)
}
assertSameJson(nestedTextAttributesPartialDoc.toJSON(), nestedTextAttributesPartial.expectedJson, 'nested text attributes partial sync step 2 JSON')
assertSameJson(nestedTextAttributesPartialDoc.getArray('array').get(0).getAttributes(), nestedTextAttributesPartial.expectedTextAttributes, 'nested text attributes partial sync step 2 attributes')
if (toBase64(Y.encodeStateVector(nestedTextAttributesPartialDoc)) !== nestedTextAttributesPartial.sourceStateVectorV1) {
  throw new Error('nested text attributes partial sync step 2 state vector mismatch')
}

const nestedTextAttributesPartialV2Doc = new Y.Doc({ gc: false })
nestedTextAttributesPartialV2Doc.getArray('array')
Y.applyUpdateV2(nestedTextAttributesPartialV2Doc, fromBase64(nestedTextAttributesPartial.prefixUpdateV2), 'php-nested-text-attributes-partial-v2-prefix')
assertSameJson(nestedTextAttributesPartialV2Doc.toJSON(), nestedTextAttributesPartial.prefixJson, 'nested text attributes partial V2 prefix JSON')
assertSameJson(nestedTextAttributesPartialV2Doc.getArray('array').get(0).getAttributes(), expectedObjectJson(nestedTextAttributesPartial.prefixTextAttributes), 'nested text attributes partial V2 prefix attributes')
applyV2SyncPayload(nestedTextAttributesPartialV2Doc, fromBase64(nestedTextAttributesPartial.syncStep2V2ForPrefix), syncProtocol.messageYjsSyncStep2, 'php-nested-text-attributes-partial-v2-step2', 'nested text attributes partial V2 sync step 2')
assertSameJson(nestedTextAttributesPartialV2Doc.toJSON(), nestedTextAttributesPartial.expectedJson, 'nested text attributes partial V2 sync step 2 JSON')
assertSameJson(nestedTextAttributesPartialV2Doc.getArray('array').get(0).getAttributes(), nestedTextAttributesPartial.expectedTextAttributes, 'nested text attributes partial V2 sync step 2 attributes')
if (toBase64(Y.encodeStateVector(nestedTextAttributesPartialV2Doc)) !== nestedTextAttributesPartial.sourceStateVectorV1) {
  throw new Error('nested text attributes partial V2 sync step 2 state vector mismatch')
}

const mapXmlPartial = sync.mapXmlPartial
const mapXmlPartialDoc = new Y.Doc({ gc: false })
mapXmlPartialDoc.getMap('map')
Y.applyUpdate(mapXmlPartialDoc, fromBase64(mapXmlPartial.prefixUpdateV1), 'php-map-xml-partial-prefix')
assertSameJson(mapXmlPartialDoc.toJSON(), mapXmlPartial.prefixJson, 'map XML partial prefix JSON')
assertOptionalMapXmlFragments(mapXmlPartialDoc, mapXmlPartial.prefixMapXmlFragments, 'map XML partial prefix')
const mapXmlPartialStep2Result = applySyncMessage(mapXmlPartialDoc, fromBase64(mapXmlPartial.syncStep2ForPrefix), 'php-map-xml-partial-step2')
if (mapXmlPartialStep2Result.type !== syncProtocol.messageYjsSyncStep2) {
  throw new Error(`map XML partial sync step 2 type: expected ${syncProtocol.messageYjsSyncStep2}, got ${mapXmlPartialStep2Result.type}`)
}
assertSameJson(mapXmlPartialDoc.toJSON(), mapXmlPartial.expectedJson, 'map XML partial sync step 2 JSON')
assertOptionalMapXmlFragments(mapXmlPartialDoc, mapXmlPartial.expectedMapXmlFragments, 'map XML partial sync step 2')
if (toBase64(Y.encodeStateVector(mapXmlPartialDoc)) !== mapXmlPartial.sourceStateVectorV1) {
  throw new Error('map XML partial sync step 2 state vector mismatch')
}

const mapXmlPartialV2Doc = new Y.Doc({ gc: false })
mapXmlPartialV2Doc.getMap('map')
Y.applyUpdateV2(mapXmlPartialV2Doc, fromBase64(mapXmlPartial.prefixUpdateV2), 'php-map-xml-partial-v2-prefix')
assertSameJson(mapXmlPartialV2Doc.toJSON(), mapXmlPartial.prefixJson, 'map XML partial V2 prefix JSON')
assertOptionalMapXmlFragments(mapXmlPartialV2Doc, mapXmlPartial.prefixMapXmlFragments, 'map XML partial V2 prefix')
applyV2SyncPayload(mapXmlPartialV2Doc, fromBase64(mapXmlPartial.syncStep2V2ForPrefix), syncProtocol.messageYjsSyncStep2, 'php-map-xml-partial-v2-step2', 'map XML partial V2 sync step 2')
assertSameJson(mapXmlPartialV2Doc.toJSON(), mapXmlPartial.expectedJson, 'map XML partial V2 sync step 2 JSON')
assertOptionalMapXmlFragments(mapXmlPartialV2Doc, mapXmlPartial.expectedMapXmlFragments, 'map XML partial V2 sync step 2')
if (toBase64(Y.encodeStateVector(mapXmlPartialV2Doc)) !== mapXmlPartial.sourceStateVectorV1) {
  throw new Error('map XML partial V2 sync step 2 state vector mismatch')
}

const mapBulkPartial = sync.mapBulkPartial
const mapBulkPartialDoc = new Y.Doc({ gc: false })
mapBulkPartialDoc.getMap('map')
Y.applyUpdate(mapBulkPartialDoc, fromBase64(mapBulkPartial.prefixUpdateV1), 'php-map-bulk-partial-prefix')
assertSameJson(mapBulkPartialDoc.toJSON(), mapBulkPartial.prefixJson, 'map bulk partial prefix JSON')
assertOptionalMapXmlFragments(mapBulkPartialDoc, mapBulkPartial.prefixMapXmlFragments, 'map bulk partial prefix')
const mapBulkPartialStep2Result = applySyncMessage(mapBulkPartialDoc, fromBase64(mapBulkPartial.syncStep2ForPrefix), 'php-map-bulk-partial-step2')
if (mapBulkPartialStep2Result.type !== syncProtocol.messageYjsSyncStep2) {
  throw new Error(`map bulk partial sync step 2 type: expected ${syncProtocol.messageYjsSyncStep2}, got ${mapBulkPartialStep2Result.type}`)
}
assertSameJson(mapBulkPartialDoc.toJSON(), mapBulkPartial.expectedJson, 'map bulk partial sync step 2 JSON')
assertOptionalMapXmlFragments(mapBulkPartialDoc, mapBulkPartial.expectedMapXmlFragments, 'map bulk partial sync step 2')
if (toBase64(Y.encodeStateVector(mapBulkPartialDoc)) !== mapBulkPartial.sourceStateVectorV1) {
  throw new Error('map bulk partial sync step 2 state vector mismatch')
}

const mapBulkPartialV2Doc = new Y.Doc({ gc: false })
mapBulkPartialV2Doc.getMap('map')
Y.applyUpdateV2(mapBulkPartialV2Doc, fromBase64(mapBulkPartial.prefixUpdateV2), 'php-map-bulk-partial-v2-prefix')
assertSameJson(mapBulkPartialV2Doc.toJSON(), mapBulkPartial.prefixJson, 'map bulk partial V2 prefix JSON')
assertOptionalMapXmlFragments(mapBulkPartialV2Doc, mapBulkPartial.prefixMapXmlFragments, 'map bulk partial V2 prefix')
applyV2SyncPayload(mapBulkPartialV2Doc, fromBase64(mapBulkPartial.syncStep2V2ForPrefix), syncProtocol.messageYjsSyncStep2, 'php-map-bulk-partial-v2-step2', 'map bulk partial V2 sync step 2')
assertSameJson(mapBulkPartialV2Doc.toJSON(), mapBulkPartial.expectedJson, 'map bulk partial V2 sync step 2 JSON')
assertOptionalMapXmlFragments(mapBulkPartialV2Doc, mapBulkPartial.expectedMapXmlFragments, 'map bulk partial V2 sync step 2')
if (toBase64(Y.encodeStateVector(mapBulkPartialV2Doc)) !== mapBulkPartial.sourceStateVectorV1) {
  throw new Error('map bulk partial V2 sync step 2 state vector mismatch')
}

const mapSubdocPartial = sync.mapSubdocPartial
const mapSubdocPartialDoc = new Y.Doc({ gc: false })
mapSubdocPartialDoc.getMap('map')
Y.applyUpdate(mapSubdocPartialDoc, fromBase64(mapSubdocPartial.prefixUpdateV1), 'php-map-subdoc-partial-prefix')
assertSameJson(mapSubdocPartialDoc.toJSON(), mapSubdocPartial.prefixJson, 'map subdoc partial prefix JSON')
const mapSubdocPartialStep2Result = applySyncMessage(mapSubdocPartialDoc, fromBase64(mapSubdocPartial.syncStep2ForPrefix), 'php-map-subdoc-partial-step2')
if (mapSubdocPartialStep2Result.type !== syncProtocol.messageYjsSyncStep2) {
  throw new Error(`map subdoc partial sync step 2 type: expected ${syncProtocol.messageYjsSyncStep2}, got ${mapSubdocPartialStep2Result.type}`)
}
assertSameJson(mapSubdocPartialDoc.toJSON(), mapSubdocPartial.expectedJson, 'map subdoc partial sync step 2 JSON')
assertOptionalMapSubdocs(mapSubdocPartialDoc, mapSubdocPartial.expectedMapSubdocs, 'map subdoc partial sync step 2')
if (toBase64(Y.encodeStateVector(mapSubdocPartialDoc)) !== mapSubdocPartial.sourceStateVectorV1) {
  throw new Error('map subdoc partial sync step 2 state vector mismatch')
}

const mapSubdocPartialV2Doc = new Y.Doc({ gc: false })
mapSubdocPartialV2Doc.getMap('map')
Y.applyUpdateV2(mapSubdocPartialV2Doc, fromBase64(mapSubdocPartial.prefixUpdateV2), 'php-map-subdoc-partial-v2-prefix')
assertSameJson(mapSubdocPartialV2Doc.toJSON(), mapSubdocPartial.prefixJson, 'map subdoc partial V2 prefix JSON')
applyV2SyncPayload(mapSubdocPartialV2Doc, fromBase64(mapSubdocPartial.syncStep2V2ForPrefix), syncProtocol.messageYjsSyncStep2, 'php-map-subdoc-partial-v2-step2', 'map subdoc partial V2 sync step 2')
assertSameJson(mapSubdocPartialV2Doc.toJSON(), mapSubdocPartial.expectedJson, 'map subdoc partial V2 sync step 2 JSON')
assertOptionalMapSubdocs(mapSubdocPartialV2Doc, mapSubdocPartial.expectedMapSubdocs, 'map subdoc partial V2 sync step 2')
if (toBase64(Y.encodeStateVector(mapSubdocPartialV2Doc)) !== mapSubdocPartial.sourceStateVectorV1) {
  throw new Error('map subdoc partial V2 sync step 2 state vector mismatch')
}

const arraySubdocPartial = sync.arraySubdocPartial
const arraySubdocPartialDoc = new Y.Doc({ gc: false })
arraySubdocPartialDoc.getArray('array')
Y.applyUpdate(arraySubdocPartialDoc, fromBase64(arraySubdocPartial.prefixUpdateV1), 'php-array-subdoc-partial-prefix')
assertSameJson(arraySubdocPartialDoc.toJSON(), arraySubdocPartial.prefixJson, 'array subdoc partial prefix JSON')
const arraySubdocPartialStep2Result = applySyncMessage(arraySubdocPartialDoc, fromBase64(arraySubdocPartial.syncStep2ForPrefix), 'php-array-subdoc-partial-step2')
if (arraySubdocPartialStep2Result.type !== syncProtocol.messageYjsSyncStep2) {
  throw new Error(`array subdoc partial sync step 2 type: expected ${syncProtocol.messageYjsSyncStep2}, got ${arraySubdocPartialStep2Result.type}`)
}
assertSameJson(arraySubdocPartialDoc.toJSON(), arraySubdocPartial.expectedJson, 'array subdoc partial sync step 2 JSON')
assertOptionalArraySubdocs(arraySubdocPartialDoc, arraySubdocPartial.expectedArraySubdocs, 'array subdoc partial sync step 2')
if (toBase64(Y.encodeStateVector(arraySubdocPartialDoc)) !== arraySubdocPartial.sourceStateVectorV1) {
  throw new Error('array subdoc partial sync step 2 state vector mismatch')
}

const arraySubdocPartialV2Doc = new Y.Doc({ gc: false })
arraySubdocPartialV2Doc.getArray('array')
Y.applyUpdateV2(arraySubdocPartialV2Doc, fromBase64(arraySubdocPartial.prefixUpdateV2), 'php-array-subdoc-partial-v2-prefix')
assertSameJson(arraySubdocPartialV2Doc.toJSON(), arraySubdocPartial.prefixJson, 'array subdoc partial V2 prefix JSON')
applyV2SyncPayload(arraySubdocPartialV2Doc, fromBase64(arraySubdocPartial.syncStep2V2ForPrefix), syncProtocol.messageYjsSyncStep2, 'php-array-subdoc-partial-v2-step2', 'array subdoc partial V2 sync step 2')
assertSameJson(arraySubdocPartialV2Doc.toJSON(), arraySubdocPartial.expectedJson, 'array subdoc partial V2 sync step 2 JSON')
assertOptionalArraySubdocs(arraySubdocPartialV2Doc, arraySubdocPartial.expectedArraySubdocs, 'array subdoc partial V2 sync step 2')
if (toBase64(Y.encodeStateVector(arraySubdocPartialV2Doc)) !== arraySubdocPartial.sourceStateVectorV1) {
  throw new Error('array subdoc partial V2 sync step 2 state vector mismatch')
}

const arrayXmlPartial = sync.arrayXmlPartial
const arrayXmlPartialDoc = new Y.Doc({ gc: false })
arrayXmlPartialDoc.getArray('array')
Y.applyUpdate(arrayXmlPartialDoc, fromBase64(arrayXmlPartial.prefixUpdateV1), 'php-array-xml-partial-prefix')
assertSameJson(arrayXmlPartialDoc.toJSON(), arrayXmlPartial.prefixJson, 'array XML partial prefix JSON')
assertOptionalArrayXmlFragments(arrayXmlPartialDoc, arrayXmlPartial.prefixArrayXmlFragments, 'array XML partial prefix')
const arrayXmlPartialStep2Result = applySyncMessage(arrayXmlPartialDoc, fromBase64(arrayXmlPartial.syncStep2ForPrefix), 'php-array-xml-partial-step2')
if (arrayXmlPartialStep2Result.type !== syncProtocol.messageYjsSyncStep2) {
  throw new Error(`array XML partial sync step 2 type: expected ${syncProtocol.messageYjsSyncStep2}, got ${arrayXmlPartialStep2Result.type}`)
}
assertSameJson(arrayXmlPartialDoc.toJSON(), arrayXmlPartial.expectedJson, 'array XML partial sync step 2 JSON')
assertOptionalArrayXmlFragments(arrayXmlPartialDoc, arrayXmlPartial.expectedArrayXmlFragments, 'array XML partial sync step 2')
if (toBase64(Y.encodeStateVector(arrayXmlPartialDoc)) !== arrayXmlPartial.sourceStateVectorV1) {
  throw new Error('array XML partial sync step 2 state vector mismatch')
}

const arrayXmlPartialV2Doc = new Y.Doc({ gc: false })
arrayXmlPartialV2Doc.getArray('array')
Y.applyUpdateV2(arrayXmlPartialV2Doc, fromBase64(arrayXmlPartial.prefixUpdateV2), 'php-array-xml-partial-v2-prefix')
assertSameJson(arrayXmlPartialV2Doc.toJSON(), arrayXmlPartial.prefixJson, 'array XML partial V2 prefix JSON')
assertOptionalArrayXmlFragments(arrayXmlPartialV2Doc, arrayXmlPartial.prefixArrayXmlFragments, 'array XML partial V2 prefix')
applyV2SyncPayload(arrayXmlPartialV2Doc, fromBase64(arrayXmlPartial.syncStep2V2ForPrefix), syncProtocol.messageYjsSyncStep2, 'php-array-xml-partial-v2-step2', 'array XML partial V2 sync step 2')
assertSameJson(arrayXmlPartialV2Doc.toJSON(), arrayXmlPartial.expectedJson, 'array XML partial V2 sync step 2 JSON')
assertOptionalArrayXmlFragments(arrayXmlPartialV2Doc, arrayXmlPartial.expectedArrayXmlFragments, 'array XML partial V2 sync step 2')
if (toBase64(Y.encodeStateVector(arrayXmlPartialV2Doc)) !== arrayXmlPartial.sourceStateVectorV1) {
  throw new Error('array XML partial V2 sync step 2 state vector mismatch')
}

const xmlHookSharedPartial = sync.xmlHookSharedPartial
const xmlHookSharedPartialDoc = new Y.Doc({ gc: false })
xmlHookSharedPartialDoc.getXmlFragment('xml')
Y.applyUpdate(xmlHookSharedPartialDoc, fromBase64(xmlHookSharedPartial.prefixUpdateV1), 'php-xml-hook-shared-partial-prefix')
assertSameJson(xmlHookSharedPartialDoc.toJSON(), xmlHookSharedPartial.prefixJson, 'XML hook shared partial prefix JSON')
assertOptionalXmlHookJson(xmlHookSharedPartialDoc, xmlHookSharedPartial.prefixHookJson, 'XML hook shared partial prefix')
const xmlHookSharedPartialStep2Result = applySyncMessage(xmlHookSharedPartialDoc, fromBase64(xmlHookSharedPartial.syncStep2ForPrefix), 'php-xml-hook-shared-partial-step2')
if (xmlHookSharedPartialStep2Result.type !== syncProtocol.messageYjsSyncStep2) {
  throw new Error(`XML hook shared partial sync step 2 type: expected ${syncProtocol.messageYjsSyncStep2}, got ${xmlHookSharedPartialStep2Result.type}`)
}
assertSameJson(xmlHookSharedPartialDoc.toJSON(), xmlHookSharedPartial.expectedJson, 'XML hook shared partial sync step 2 JSON')
assertOptionalXmlHookJson(xmlHookSharedPartialDoc, xmlHookSharedPartial.expectedHookJson, 'XML hook shared partial sync step 2')
if (toBase64(Y.encodeStateVector(xmlHookSharedPartialDoc)) !== xmlHookSharedPartial.sourceStateVectorV1) {
  throw new Error('XML hook shared partial sync step 2 state vector mismatch')
}

const xmlHookSharedPartialV2Doc = new Y.Doc({ gc: false })
xmlHookSharedPartialV2Doc.getXmlFragment('xml')
Y.applyUpdateV2(xmlHookSharedPartialV2Doc, fromBase64(xmlHookSharedPartial.prefixUpdateV2), 'php-xml-hook-shared-partial-v2-prefix')
assertSameJson(xmlHookSharedPartialV2Doc.toJSON(), xmlHookSharedPartial.prefixJson, 'XML hook shared partial V2 prefix JSON')
assertOptionalXmlHookJson(xmlHookSharedPartialV2Doc, xmlHookSharedPartial.prefixHookJson, 'XML hook shared partial V2 prefix')
applyV2SyncPayload(xmlHookSharedPartialV2Doc, fromBase64(xmlHookSharedPartial.syncStep2V2ForPrefix), syncProtocol.messageYjsSyncStep2, 'php-xml-hook-shared-partial-v2-step2', 'XML hook shared partial V2 sync step 2')
assertSameJson(xmlHookSharedPartialV2Doc.toJSON(), xmlHookSharedPartial.expectedJson, 'XML hook shared partial V2 sync step 2 JSON')
assertOptionalXmlHookJson(xmlHookSharedPartialV2Doc, xmlHookSharedPartial.expectedHookJson, 'XML hook shared partial V2 sync step 2')
if (toBase64(Y.encodeStateVector(xmlHookSharedPartialV2Doc)) !== xmlHookSharedPartial.sourceStateVectorV1) {
  throw new Error('XML hook shared partial V2 sync step 2 state vector mismatch')
}

const xmlElementSharedPartial = sync.xmlElementSharedPartial
const xmlElementSharedPartialDoc = new Y.Doc({ gc: false })
xmlElementSharedPartialDoc.getXmlFragment('xml')
Y.applyUpdate(xmlElementSharedPartialDoc, fromBase64(xmlElementSharedPartial.prefixUpdateV1), 'php-xml-element-shared-partial-prefix')
assertSameJson(xmlElementSharedPartialDoc.toJSON(), xmlElementSharedPartial.prefixJson, 'XML element shared partial prefix JSON')
assertSameJson(xmlElementAttributes(xmlElementSharedPartialDoc), xmlElementSharedPartial.prefixElementAttributes, 'XML element shared partial prefix attributes')
const xmlElementSharedPartialStep2Result = applySyncMessage(xmlElementSharedPartialDoc, fromBase64(xmlElementSharedPartial.syncStep2ForPrefix), 'php-xml-element-shared-partial-step2')
if (xmlElementSharedPartialStep2Result.type !== syncProtocol.messageYjsSyncStep2) {
  throw new Error(`XML element shared partial sync step 2 type: expected ${syncProtocol.messageYjsSyncStep2}, got ${xmlElementSharedPartialStep2Result.type}`)
}
assertSameJson(xmlElementSharedPartialDoc.toJSON(), xmlElementSharedPartial.expectedJson, 'XML element shared partial sync step 2 JSON')
assertSameJson(xmlElementAttributes(xmlElementSharedPartialDoc), xmlElementSharedPartial.expectedElementAttributes, 'XML element shared partial sync step 2 attributes')
if (toBase64(Y.encodeStateVector(xmlElementSharedPartialDoc)) !== xmlElementSharedPartial.sourceStateVectorV1) {
  throw new Error('XML element shared partial sync step 2 state vector mismatch')
}

const xmlElementSharedPartialV2Doc = new Y.Doc({ gc: false })
xmlElementSharedPartialV2Doc.getXmlFragment('xml')
Y.applyUpdateV2(xmlElementSharedPartialV2Doc, fromBase64(xmlElementSharedPartial.prefixUpdateV2), 'php-xml-element-shared-partial-v2-prefix')
assertSameJson(xmlElementSharedPartialV2Doc.toJSON(), xmlElementSharedPartial.prefixJson, 'XML element shared partial V2 prefix JSON')
assertSameJson(xmlElementAttributes(xmlElementSharedPartialV2Doc), xmlElementSharedPartial.prefixElementAttributes, 'XML element shared partial V2 prefix attributes')
applyV2SyncPayload(xmlElementSharedPartialV2Doc, fromBase64(xmlElementSharedPartial.syncStep2V2ForPrefix), syncProtocol.messageYjsSyncStep2, 'php-xml-element-shared-partial-v2-step2', 'XML element shared partial V2 sync step 2')
assertSameJson(xmlElementSharedPartialV2Doc.toJSON(), xmlElementSharedPartial.expectedJson, 'XML element shared partial V2 sync step 2 JSON')
assertSameJson(xmlElementAttributes(xmlElementSharedPartialV2Doc), xmlElementSharedPartial.expectedElementAttributes, 'XML element shared partial V2 sync step 2 attributes')
if (toBase64(Y.encodeStateVector(xmlElementSharedPartialV2Doc)) !== xmlElementSharedPartial.sourceStateVectorV1) {
  throw new Error('XML element shared partial V2 sync step 2 state vector mismatch')
}

const xmlTextSharedPartial = sync.xmlTextSharedPartial
const xmlTextSharedPartialDoc = new Y.Doc({ gc: false })
xmlTextSharedPartialDoc.getXmlFragment('xml')
Y.applyUpdate(xmlTextSharedPartialDoc, fromBase64(xmlTextSharedPartial.prefixUpdateV1), 'php-xml-text-shared-partial-prefix')
assertSameJson(xmlTextSharedPartialDoc.toJSON(), xmlTextSharedPartial.prefixJson, 'XML text shared partial prefix JSON')
assertSameJson(xmlTextAttributes(xmlTextSharedPartialDoc), xmlTextSharedPartial.prefixTextAttributes, 'XML text shared partial prefix attributes')
const xmlTextSharedPartialStep2Result = applySyncMessage(xmlTextSharedPartialDoc, fromBase64(xmlTextSharedPartial.syncStep2ForPrefix), 'php-xml-text-shared-partial-step2')
if (xmlTextSharedPartialStep2Result.type !== syncProtocol.messageYjsSyncStep2) {
  throw new Error(`XML text shared partial sync step 2 type: expected ${syncProtocol.messageYjsSyncStep2}, got ${xmlTextSharedPartialStep2Result.type}`)
}
assertSameJson(xmlTextSharedPartialDoc.toJSON(), xmlTextSharedPartial.expectedJson, 'XML text shared partial sync step 2 JSON')
assertSameJson(xmlTextAttributes(xmlTextSharedPartialDoc), xmlTextSharedPartial.expectedTextAttributes, 'XML text shared partial sync step 2 attributes')
if (toBase64(Y.encodeStateVector(xmlTextSharedPartialDoc)) !== xmlTextSharedPartial.sourceStateVectorV1) {
  throw new Error('XML text shared partial sync step 2 state vector mismatch')
}

const xmlTextSharedPartialV2Doc = new Y.Doc({ gc: false })
xmlTextSharedPartialV2Doc.getXmlFragment('xml')
Y.applyUpdateV2(xmlTextSharedPartialV2Doc, fromBase64(xmlTextSharedPartial.prefixUpdateV2), 'php-xml-text-shared-partial-v2-prefix')
assertSameJson(xmlTextSharedPartialV2Doc.toJSON(), xmlTextSharedPartial.prefixJson, 'XML text shared partial V2 prefix JSON')
assertSameJson(xmlTextAttributes(xmlTextSharedPartialV2Doc), xmlTextSharedPartial.prefixTextAttributes, 'XML text shared partial V2 prefix attributes')
applyV2SyncPayload(xmlTextSharedPartialV2Doc, fromBase64(xmlTextSharedPartial.syncStep2V2ForPrefix), syncProtocol.messageYjsSyncStep2, 'php-xml-text-shared-partial-v2-step2', 'XML text shared partial V2 sync step 2')
assertSameJson(xmlTextSharedPartialV2Doc.toJSON(), xmlTextSharedPartial.expectedJson, 'XML text shared partial V2 sync step 2 JSON')
assertSameJson(xmlTextAttributes(xmlTextSharedPartialV2Doc), xmlTextSharedPartial.expectedTextAttributes, 'XML text shared partial V2 sync step 2 attributes')
if (toBase64(Y.encodeStateVector(xmlTextSharedPartialV2Doc)) !== xmlTextSharedPartial.sourceStateVectorV1) {
  throw new Error('XML text shared partial V2 sync step 2 state vector mismatch')
}

const xmlReplacePartial = sync.xmlReplacePartial
const xmlReplacePartialDoc = new Y.Doc({ gc: false })
xmlReplacePartialDoc.getXmlFragment('xml')
Y.applyUpdate(xmlReplacePartialDoc, fromBase64(xmlReplacePartial.prefixUpdateV1), 'php-xml-replace-partial-prefix')
assertSameJson(xmlReplacePartialDoc.toJSON(), xmlReplacePartial.prefixJson, 'XML replacement partial prefix JSON')
const xmlReplacePartialStep2Result = applySyncMessage(xmlReplacePartialDoc, fromBase64(xmlReplacePartial.syncStep2ForPrefix), 'php-xml-replace-partial-step2')
if (xmlReplacePartialStep2Result.type !== syncProtocol.messageYjsSyncStep2) {
  throw new Error(`XML replacement partial sync step 2 type: expected ${syncProtocol.messageYjsSyncStep2}, got ${xmlReplacePartialStep2Result.type}`)
}
assertSameJson(xmlReplacePartialDoc.toJSON(), xmlReplacePartial.expectedJson, 'XML replacement partial sync step 2 JSON')
if (toBase64(Y.encodeStateVector(xmlReplacePartialDoc)) !== xmlReplacePartial.sourceStateVectorV1) {
  throw new Error('XML replacement partial sync step 2 state vector mismatch')
}

const xmlReplacePartialV2Doc = new Y.Doc({ gc: false })
xmlReplacePartialV2Doc.getXmlFragment('xml')
Y.applyUpdateV2(xmlReplacePartialV2Doc, fromBase64(xmlReplacePartial.prefixUpdateV2), 'php-xml-replace-partial-v2-prefix')
assertSameJson(xmlReplacePartialV2Doc.toJSON(), xmlReplacePartial.prefixJson, 'XML replacement partial V2 prefix JSON')
applyV2SyncPayload(xmlReplacePartialV2Doc, fromBase64(xmlReplacePartial.syncStep2V2ForPrefix), syncProtocol.messageYjsSyncStep2, 'php-xml-replace-partial-v2-step2', 'XML replacement partial V2 sync step 2')
assertSameJson(xmlReplacePartialV2Doc.toJSON(), xmlReplacePartial.expectedJson, 'XML replacement partial V2 sync step 2 JSON')
if (toBase64(Y.encodeStateVector(xmlReplacePartialV2Doc)) !== xmlReplacePartial.sourceStateVectorV1) {
  throw new Error('XML replacement partial V2 sync step 2 state vector mismatch')
}

const xmlReplaceHandledTarget = new Y.Doc({ gc: false })
xmlReplaceHandledTarget.getXmlFragment('xml')
Y.applyUpdate(xmlReplaceHandledTarget, fromBase64(xmlReplacePartial.prefixUpdateV1), 'php-xml-replace-handled-prefix')
const xmlReplaceHandledResult = applySyncMessage(xmlReplaceHandledTarget, fromBase64(xmlReplacePartial.handledSyncStep2ForPrefix), 'php-xml-replace-handled-reply')
if (xmlReplaceHandledResult.type !== syncProtocol.messageYjsSyncStep2) {
  throw new Error(`XML replacement handled reply type: expected ${syncProtocol.messageYjsSyncStep2}, got ${xmlReplaceHandledResult.type}`)
}
assertSameJson(xmlReplaceHandledTarget.toJSON(), xmlReplacePartial.expectedJson, 'XML replacement handled reply JSON')
if (toBase64(Y.encodeStateVector(xmlReplaceHandledTarget)) !== xmlReplacePartial.sourceStateVectorV1) {
  throw new Error('XML replacement handled reply state vector mismatch')
}

const xmlReplaceHandledV2Target = new Y.Doc({ gc: false })
xmlReplaceHandledV2Target.getXmlFragment('xml')
Y.applyUpdateV2(xmlReplaceHandledV2Target, fromBase64(xmlReplacePartial.prefixUpdateV2), 'php-xml-replace-handled-v2-prefix')
applyV2SyncPayload(xmlReplaceHandledV2Target, fromBase64(xmlReplacePartial.handledSyncStep2V2ForPrefix), syncProtocol.messageYjsSyncStep2, 'php-xml-replace-handled-v2-reply', 'XML replacement handled V2 reply')
assertSameJson(xmlReplaceHandledV2Target.toJSON(), xmlReplacePartial.expectedJson, 'XML replacement handled V2 reply JSON')
if (toBase64(Y.encodeStateVector(xmlReplaceHandledV2Target)) !== xmlReplacePartial.sourceStateVectorV1) {
  throw new Error('XML replacement handled V2 reply state vector mismatch')
}

const jsXmlReplaceSource = new Y.Doc({ gc: false })
jsXmlReplaceSource.clientID = 329
const jsXmlReplaceRoot = new Y.XmlElement('root')
const jsXmlReplaceText = new Y.XmlText()
jsXmlReplaceText.insert(0, 'A')
const jsXmlReplaceEm = new Y.XmlElement('em')
const jsXmlReplaceEmText = new Y.XmlText()
jsXmlReplaceEmText.insert(0, 'B')
jsXmlReplaceEm.insert(0, [jsXmlReplaceEmText])
const jsXmlReplaceTail = new Y.XmlText()
jsXmlReplaceTail.insert(0, 'T')
jsXmlReplaceRoot.insert(0, [jsXmlReplaceText, jsXmlReplaceEm, jsXmlReplaceTail])
jsXmlReplaceSource.getXmlFragment('xml').insert(0, [jsXmlReplaceRoot])
jsXmlReplaceText.insert(1, '!')
jsXmlReplaceRoot.delete(1, 1)
const jsXmlReplaceStrong = new Y.XmlElement('strong')
const jsXmlReplaceStrongText = new Y.XmlText()
jsXmlReplaceStrongText.insert(0, 'C')
jsXmlReplaceStrong.insert(0, [jsXmlReplaceStrongText])
jsXmlReplaceRoot.insert(1, [jsXmlReplaceStrong])
const jsXmlReplaceTarget = new Y.Doc({ gc: false })
jsXmlReplaceTarget.getXmlFragment('xml')
Y.applyUpdate(jsXmlReplaceTarget, fromBase64(xmlReplacePartial.prefixUpdateV1), 'php-xml-replace-js-handshake-prefix')
const jsXmlReplaceHandshake = applySyncMessage(jsXmlReplaceSource, fromBase64(xmlReplacePartial.syncStep1FromPrefix), 'php-xml-replace-step1')
if (jsXmlReplaceHandshake.type !== syncProtocol.messageYjsSyncStep1) {
  throw new Error(`XML replacement sync step 1 type: expected ${syncProtocol.messageYjsSyncStep1}, got ${jsXmlReplaceHandshake.type}`)
}
applySyncMessage(jsXmlReplaceTarget, jsXmlReplaceHandshake.reply, 'js-xml-replace-sync-reply')
assertSameJson(jsXmlReplaceTarget.toJSON(), xmlReplacePartial.expectedJson, 'JS reply to PHP XML replacement sync step 1 JSON')
if (toBase64(Y.encodeStateVector(jsXmlReplaceTarget)) !== xmlReplacePartial.sourceStateVectorV1) {
  throw new Error('JS reply to PHP XML replacement sync step 1 state vector mismatch')
}

const threeWayConflictPartial = sync.threeWayConflictPartial
const threeWayConflictDoc = new Y.Doc({ gc: false })
threeWayConflictDoc.getText('content')
Y.applyUpdate(threeWayConflictDoc, fromBase64(threeWayConflictPartial.prefixUpdateV1), 'php-three-way-conflict-prefix')
assertSameJson(threeWayConflictDoc.toJSON(), threeWayConflictPartial.prefixJson, 'three-way conflict partial prefix JSON')
const threeWayConflictStep2Result = applySyncMessage(threeWayConflictDoc, fromBase64(threeWayConflictPartial.syncStep2ForPrefix), 'php-three-way-conflict-step2')
if (threeWayConflictStep2Result.type !== syncProtocol.messageYjsSyncStep2) {
  throw new Error(`three-way conflict partial sync step 2 type: expected ${syncProtocol.messageYjsSyncStep2}, got ${threeWayConflictStep2Result.type}`)
}
assertSameJson(threeWayConflictDoc.toJSON(), threeWayConflictPartial.expectedJson, 'three-way conflict partial sync step 2 JSON')
if (toBase64(Y.encodeStateVector(threeWayConflictDoc)) !== threeWayConflictPartial.sourceStateVectorV1) {
  throw new Error('three-way conflict partial sync step 2 state vector mismatch')
}

const threeWayConflictV2Doc = new Y.Doc({ gc: false })
threeWayConflictV2Doc.getText('content')
Y.applyUpdateV2(threeWayConflictV2Doc, fromBase64(threeWayConflictPartial.prefixUpdateV2), 'php-three-way-conflict-v2-prefix')
assertSameJson(threeWayConflictV2Doc.toJSON(), threeWayConflictPartial.prefixJson, 'three-way conflict partial V2 prefix JSON')
applyV2SyncPayload(threeWayConflictV2Doc, fromBase64(threeWayConflictPartial.syncStep2V2ForPrefix), syncProtocol.messageYjsSyncStep2, 'php-three-way-conflict-v2-step2', 'three-way conflict partial V2 sync step 2')
assertSameJson(threeWayConflictV2Doc.toJSON(), threeWayConflictPartial.expectedJson, 'three-way conflict partial V2 sync step 2 JSON')
if (toBase64(Y.encodeStateVector(threeWayConflictV2Doc)) !== threeWayConflictPartial.sourceStateVectorV1) {
  throw new Error('three-way conflict partial V2 sync step 2 state vector mismatch')
}

const xmlTextConflictPartial = sync.xmlTextConflictPartial
const xmlTextConflictDoc = new Y.Doc({ gc: false })
xmlTextConflictDoc.getXmlFragment('xml')
Y.applyUpdate(xmlTextConflictDoc, fromBase64(xmlTextConflictPartial.prefixUpdateV1), 'php-xml-text-conflict-prefix')
assertSameJson(xmlTextConflictDoc.toJSON(), xmlTextConflictPartial.prefixJson, 'XML text conflict partial prefix JSON')
const xmlTextConflictStep2Result = applySyncMessage(xmlTextConflictDoc, fromBase64(xmlTextConflictPartial.syncStep2ForPrefix), 'php-xml-text-conflict-step2')
if (xmlTextConflictStep2Result.type !== syncProtocol.messageYjsSyncStep2) {
  throw new Error(`XML text conflict partial sync step 2 type: expected ${syncProtocol.messageYjsSyncStep2}, got ${xmlTextConflictStep2Result.type}`)
}
assertSameJson(xmlTextConflictDoc.toJSON(), xmlTextConflictPartial.expectedJson, 'XML text conflict partial sync step 2 JSON')
assertSameJson(xmlParagraphTextDelta(xmlTextConflictDoc), xmlTextConflictPartial.expectedXmlTextDelta, 'XML text conflict partial sync step 2 delta')
if (toBase64(Y.encodeStateVector(xmlTextConflictDoc)) !== xmlTextConflictPartial.sourceStateVectorV1) {
  throw new Error('XML text conflict partial sync step 2 state vector mismatch')
}

const xmlTextConflictV2Doc = new Y.Doc({ gc: false })
xmlTextConflictV2Doc.getXmlFragment('xml')
Y.applyUpdateV2(xmlTextConflictV2Doc, fromBase64(xmlTextConflictPartial.prefixUpdateV2), 'php-xml-text-conflict-v2-prefix')
assertSameJson(xmlTextConflictV2Doc.toJSON(), xmlTextConflictPartial.prefixJson, 'XML text conflict partial V2 prefix JSON')
applyV2SyncPayload(xmlTextConflictV2Doc, fromBase64(xmlTextConflictPartial.syncStep2V2ForPrefix), syncProtocol.messageYjsSyncStep2, 'php-xml-text-conflict-v2-step2', 'XML text conflict partial V2 sync step 2')
assertSameJson(xmlTextConflictV2Doc.toJSON(), xmlTextConflictPartial.expectedJson, 'XML text conflict partial V2 sync step 2 JSON')
assertSameJson(xmlParagraphTextDelta(xmlTextConflictV2Doc), xmlTextConflictPartial.expectedXmlTextDelta, 'XML text conflict partial V2 sync step 2 delta')
if (toBase64(Y.encodeStateVector(xmlTextConflictV2Doc)) !== xmlTextConflictPartial.sourceStateVectorV1) {
  throw new Error('XML text conflict partial V2 sync step 2 state vector mismatch')
}

const jsPartialSource = new Y.Doc({ gc: false })
jsPartialSource.clientID = 301
jsPartialSource.getText('content').insert(0, 'Protocol')
jsPartialSource.getText('content').insert(8, ' check')
const jsPartialTarget = new Y.Doc({ gc: false })
jsPartialTarget.getText('content')
Y.applyUpdate(jsPartialTarget, fromBase64(partial.prefixUpdateV1), 'php-partial-prefix')
const partialHandshake = applySyncMessage(jsPartialSource, fromBase64(partial.syncStep1FromPrefix), 'php-partial-sync-step1')
if (partialHandshake.type !== syncProtocol.messageYjsSyncStep1) {
  throw new Error(`partial sync step 1 type: expected ${syncProtocol.messageYjsSyncStep1}, got ${partialHandshake.type}`)
}
applySyncMessage(jsPartialTarget, partialHandshake.reply, 'js-partial-sync-reply')
assertSameJson(jsPartialTarget.toJSON(), sync.expectedJson, 'JS reply to PHP partial sync step 1 JSON')
if (toBase64(Y.encodeStateVector(jsPartialTarget)) !== sync.stateVectorV1) {
  throw new Error('JS reply to PHP partial sync step 1 state vector mismatch')
}

const phpHandledPartialTarget = new Y.Doc({ gc: false })
phpHandledPartialTarget.getText('content')
Y.applyUpdate(phpHandledPartialTarget, fromBase64(partial.prefixUpdateV1), 'php-handled-partial-prefix')
const phpHandledPartialResult = applySyncMessage(phpHandledPartialTarget, fromBase64(partial.handledSyncStep2ForPrefix), 'php-handled-partial-sync-reply')
if (phpHandledPartialResult.type !== syncProtocol.messageYjsSyncStep2) {
  throw new Error(`PHP handled partial sync reply type: expected ${syncProtocol.messageYjsSyncStep2}, got ${phpHandledPartialResult.type}`)
}
assertSameJson(phpHandledPartialTarget.toJSON(), sync.expectedJson, 'PHP handled partial sync reply JSON')
if (toBase64(Y.encodeStateVector(phpHandledPartialTarget)) !== sync.stateVectorV1) {
  throw new Error('PHP handled partial sync reply state vector mismatch')
}

const phpHandledPartialV2Target = new Y.Doc({ gc: false })
phpHandledPartialV2Target.getText('content')
Y.applyUpdateV2(phpHandledPartialV2Target, fromBase64(partial.prefixUpdateV2), 'php-handled-partial-v2-prefix')
applyV2SyncPayload(phpHandledPartialV2Target, fromBase64(partial.handledSyncStep2V2ForPrefix), syncProtocol.messageYjsSyncStep2, 'php-handled-partial-v2-sync-reply', 'PHP handled partial V2 sync reply')
assertSameJson(phpHandledPartialV2Target.toJSON(), sync.expectedJson, 'PHP handled partial V2 sync reply JSON')
if (toBase64(Y.encodeStateVector(phpHandledPartialV2Target)) !== sync.stateVectorV1) {
  throw new Error('PHP handled partial V2 sync reply state vector mismatch')
}

const deleteOnly = sync.deleteOnly
const deleteDoc = new Y.Doc({ gc: false })
deleteDoc.getText('content')
Y.applyUpdate(deleteDoc, fromBase64(deleteOnly.prefixUpdateV1), 'php-delete-prefix')
assertSameJson(deleteDoc.toJSON(), deleteOnly.prefixJson, 'delete-only prefix JSON')
const deleteStep2Result = applySyncMessage(deleteDoc, fromBase64(deleteOnly.syncStep2ForPrefix), 'php-delete-sync-step2')
if (deleteStep2Result.type !== syncProtocol.messageYjsSyncStep2) {
  throw new Error(`delete-only sync step 2 type: expected ${syncProtocol.messageYjsSyncStep2}, got ${deleteStep2Result.type}`)
}
assertSameJson(deleteDoc.toJSON(), deleteOnly.expectedJson, 'delete-only sync step 2 JSON')
if (toBase64(Y.encodeStateVector(deleteDoc)) !== deleteOnly.prefixStateVectorV1) {
  throw new Error('delete-only sync step 2 should not change the state vector')
}

const deleteV2Doc = new Y.Doc({ gc: false })
deleteV2Doc.getText('content')
Y.applyUpdateV2(deleteV2Doc, fromBase64(deleteOnly.prefixUpdateV2), 'php-delete-v2-prefix')
assertSameJson(deleteV2Doc.toJSON(), deleteOnly.prefixJson, 'delete-only V2 prefix JSON')
applyV2SyncPayload(deleteV2Doc, fromBase64(deleteOnly.syncStep2V2ForPrefix), syncProtocol.messageYjsSyncStep2, 'php-delete-v2-step2', 'delete-only V2 sync step 2')
assertSameJson(deleteV2Doc.toJSON(), deleteOnly.expectedJson, 'delete-only V2 sync step 2 JSON')
if (toBase64(Y.encodeStateVector(deleteV2Doc)) !== deleteOnly.prefixStateVectorV1) {
  throw new Error('delete-only V2 sync step 2 should not change the state vector')
}

const gcPartial = sync.gcPartial
const gcPartialDoc = new Y.Doc({ gc: false })
gcPartialDoc.getText('content')
Y.applyUpdate(gcPartialDoc, fromBase64(gcPartial.prefixUpdateV1), 'php-gc-partial-prefix')
assertSameJson(gcPartialDoc.toJSON(), gcPartial.prefixJson, 'GC partial prefix JSON')
const gcPartialStep2Result = applySyncMessage(gcPartialDoc, fromBase64(gcPartial.syncStep2ForPrefix), 'php-gc-partial-sync-step2')
if (gcPartialStep2Result.type !== syncProtocol.messageYjsSyncStep2) {
  throw new Error(`GC partial sync step 2 type: expected ${syncProtocol.messageYjsSyncStep2}, got ${gcPartialStep2Result.type}`)
}
assertSameJson(gcPartialDoc.toJSON(), gcPartial.expectedJson, 'GC partial sync step 2 JSON')
if (toBase64(Y.encodeStateVector(gcPartialDoc)) !== gcPartial.sourceStateVectorV1) {
  throw new Error('GC partial sync step 2 state vector mismatch')
}

const gcPartialV2Doc = new Y.Doc({ gc: false })
gcPartialV2Doc.getText('content')
Y.applyUpdateV2(gcPartialV2Doc, fromBase64(gcPartial.prefixUpdateV2), 'php-gc-partial-v2-prefix')
assertSameJson(gcPartialV2Doc.toJSON(), gcPartial.prefixJson, 'GC partial V2 prefix JSON')
applyV2SyncPayload(gcPartialV2Doc, fromBase64(gcPartial.syncStep2V2ForPrefix), syncProtocol.messageYjsSyncStep2, 'php-gc-partial-v2-step2', 'GC partial V2 sync step 2')
assertSameJson(gcPartialV2Doc.toJSON(), gcPartial.expectedJson, 'GC partial V2 sync step 2 JSON')
if (toBase64(Y.encodeStateVector(gcPartialV2Doc)) !== gcPartial.sourceStateVectorV1) {
  throw new Error('GC partial V2 sync step 2 state vector mismatch')
}

const deleteReinsert = sync.deleteReinsert
const deleteReinsertDoc = new Y.Doc({ gc: false })
deleteReinsertDoc.getArray('array')
deleteReinsertDoc.getXmlFragment('xml')
Y.applyUpdate(deleteReinsertDoc, fromBase64(deleteReinsert.prefixUpdateV1), 'php-delete-reinsert-prefix')
assertSameJson(deleteReinsertDoc.toJSON(), deleteReinsert.prefixJson, 'delete/reinsert prefix JSON')
const deleteReinsertStep2Result = applySyncMessage(deleteReinsertDoc, fromBase64(deleteReinsert.syncStep2ForPrefix), 'php-delete-reinsert-sync-step2')
if (deleteReinsertStep2Result.type !== syncProtocol.messageYjsSyncStep2) {
  throw new Error(`delete/reinsert sync step 2 type: expected ${syncProtocol.messageYjsSyncStep2}, got ${deleteReinsertStep2Result.type}`)
}
assertSameJson(deleteReinsertDoc.toJSON(), deleteReinsert.expectedJson, 'delete/reinsert sync step 2 JSON')
if (toBase64(Y.encodeStateVector(deleteReinsertDoc)) !== deleteReinsert.sourceStateVectorV1) {
  throw new Error('delete/reinsert sync step 2 state vector mismatch')
}

const deleteReinsertV2Doc = new Y.Doc({ gc: false })
deleteReinsertV2Doc.getArray('array')
deleteReinsertV2Doc.getXmlFragment('xml')
Y.applyUpdateV2(deleteReinsertV2Doc, fromBase64(deleteReinsert.prefixUpdateV2), 'php-delete-reinsert-v2-prefix')
assertSameJson(deleteReinsertV2Doc.toJSON(), deleteReinsert.prefixJson, 'delete/reinsert V2 prefix JSON')
applyV2SyncPayload(deleteReinsertV2Doc, fromBase64(deleteReinsert.syncStep2V2ForPrefix), syncProtocol.messageYjsSyncStep2, 'php-delete-reinsert-v2-step2', 'delete/reinsert V2 sync step 2')
assertSameJson(deleteReinsertV2Doc.toJSON(), deleteReinsert.expectedJson, 'delete/reinsert V2 sync step 2 JSON')
if (toBase64(Y.encodeStateVector(deleteReinsertV2Doc)) !== deleteReinsert.sourceStateVectorV1) {
  throw new Error('delete/reinsert V2 sync step 2 state vector mismatch')
}

const jsSource = new Y.Doc({ gc: false })
jsSource.clientID = 301
jsSource.getText('content').insert(0, 'Protocol')
jsSource.getText('content').insert(8, ' check')
const jsTarget = new Y.Doc({ gc: false })
jsTarget.getText('content')
const handshake = applySyncMessage(jsSource, fromBase64(sync.syncStep1FromEmpty), 'php-sync-step1')
if (handshake.type !== syncProtocol.messageYjsSyncStep1) {
  throw new Error(`sync step 1 type: expected ${syncProtocol.messageYjsSyncStep1}, got ${handshake.type}`)
}
applySyncMessage(jsTarget, handshake.reply, 'js-sync-reply')
assertSameJson(jsTarget.toJSON(), sync.expectedJson, 'JS reply to PHP sync step 1 JSON')
if (toBase64(Y.encodeStateVector(jsTarget)) !== sync.stateVectorV1) {
  throw new Error('JS reply to PHP sync step 1 state vector mismatch')
}

const phpHandledTarget = new Y.Doc({ gc: false })
phpHandledTarget.getText('content')
const phpHandledResult = applySyncMessage(phpHandledTarget, fromBase64(sync.handledSyncStep2ForEmpty), 'php-handled-sync-reply')
if (phpHandledResult.type !== syncProtocol.messageYjsSyncStep2) {
  throw new Error(`PHP handled sync reply type: expected ${syncProtocol.messageYjsSyncStep2}, got ${phpHandledResult.type}`)
}
assertSameJson(phpHandledTarget.toJSON(), sync.expectedJson, 'PHP handled sync reply JSON')
if (toBase64(Y.encodeStateVector(phpHandledTarget)) !== sync.stateVectorV1) {
  throw new Error('PHP handled sync reply state vector mismatch')
}

const phpHandledV2Target = new Y.Doc({ gc: false })
phpHandledV2Target.getText('content')
applyV2SyncPayload(phpHandledV2Target, fromBase64(sync.handledSyncStep2V2ForEmpty), syncProtocol.messageYjsSyncStep2, 'php-handled-v2-sync-reply', 'PHP handled V2 sync reply')
assertSameJson(phpHandledV2Target.toJSON(), sync.expectedJson, 'PHP handled V2 sync reply JSON')
if (toBase64(Y.encodeStateVector(phpHandledV2Target)) !== sync.stateVectorV1) {
  throw new Error('PHP handled V2 sync reply state vector mismatch')
}

const awareness = fixtures.awareness
const awarenessDoc = new Y.Doc()
const jsAwareness = new awarenessProtocol.Awareness(awarenessDoc)
const queryMessage = readProtocolMessage(fromBase64(awareness.queryMessage))
if (queryMessage.type !== 0) {
  throw new Error(`awareness query message type: expected 0, got ${queryMessage.type}`)
}
if (queryMessage.payload.length !== 0) {
  throw new Error(`awareness query message payload: expected empty, got ${queryMessage.payload.length} bytes`)
}

const queryReplyAwareness = new awarenessProtocol.Awareness(new Y.Doc())
const queryReplyMessage = readProtocolMessage(fromBase64(awareness.queryReplyMessage))
if (queryReplyMessage.type !== 1) {
  throw new Error(`awareness query reply message type: expected 1, got ${queryReplyMessage.type}`)
}
awarenessProtocol.applyAwarenessUpdate(queryReplyAwareness, queryReplyMessage.payload, 'php-awareness-query-reply')
assertSameJson(queryReplyAwareness.getStates().get(77), awareness.expectedState, 'awareness query reply state')

const handledQueryReplyAwareness = new awarenessProtocol.Awareness(new Y.Doc())
const handledQueryReplyMessage = readProtocolMessage(fromBase64(awareness.handledQueryReplyMessage))
if (handledQueryReplyMessage.type !== 1) {
  throw new Error(`awareness handled query reply message type: expected 1, got ${handledQueryReplyMessage.type}`)
}
awarenessProtocol.applyAwarenessUpdate(handledQueryReplyAwareness, handledQueryReplyMessage.payload, 'php-awareness-handled-query-reply')
assertSameJson(handledQueryReplyAwareness.getStates().get(77), awareness.expectedState, 'awareness handled query reply state')

const stateMessage = readProtocolMessage(fromBase64(awareness.stateMessage))
if (stateMessage.type !== 1) {
  throw new Error(`awareness state message type: expected 1, got ${stateMessage.type}`)
}
awarenessProtocol.applyAwarenessUpdate(jsAwareness, stateMessage.payload, 'php-awareness-state')
assertSameJson(jsAwareness.getStates().get(77), awareness.expectedState, 'awareness state')

const removeMessage = readProtocolMessage(fromBase64(awareness.removeMessage))
if (removeMessage.type !== 1) {
  throw new Error(`awareness remove message type: expected 1, got ${removeMessage.type}`)
}
awarenessProtocol.applyAwarenessUpdate(jsAwareness, removeMessage.payload, 'php-awareness-remove')
if (jsAwareness.getStates().has(77)) {
  throw new Error('awareness remove message did not remove client 77')
}

const undefinedMessage = readProtocolMessage(fromBase64(awareness.undefined.stateMessage))
if (undefinedMessage.type !== 1) {
  throw new Error(`awareness undefined state message type: expected 1, got ${undefinedMessage.type}`)
}
awarenessProtocol.applyAwarenessUpdate(jsAwareness, undefinedMessage.payload, 'php-awareness-undefined-state')
assertSameJson(jsAwareness.getStates().get(81), awareness.undefined.expectedState, 'awareness undefined state')

const specialNumberMessage = readProtocolMessage(fromBase64(awareness.specialNumber.stateMessage))
if (specialNumberMessage.type !== 1) {
  throw new Error(`awareness special number state message type: expected 1, got ${specialNumberMessage.type}`)
}
awarenessProtocol.applyAwarenessUpdate(jsAwareness, specialNumberMessage.payload, 'php-awareness-special-number-state')
assertSameJson(jsAwareness.getStates().get(82), awareness.specialNumber.expectedState, 'awareness special number state')

const clearStateMessage = readProtocolMessage(fromBase64(awareness.clearStateMessage))
if (clearStateMessage.type !== 1) {
  throw new Error(`awareness clear state message type: expected 1, got ${clearStateMessage.type}`)
}
const clearJsAwareness = new awarenessProtocol.Awareness(new Y.Doc())
awarenessProtocol.applyAwarenessUpdate(clearJsAwareness, clearStateMessage.payload, 'php-awareness-clear-state')
assertSameJson(clearJsAwareness.getStates().get(90), { user: { name: 'Lin' } }, 'awareness clear state 90')
assertSameJson(clearJsAwareness.getStates().get(91), { user: { name: 'Mira' } }, 'awareness clear state 91')
const clearMessage = readProtocolMessage(fromBase64(awareness.clearMessage))
if (clearMessage.type !== 1) {
  throw new Error(`awareness clear message type: expected 1, got ${clearMessage.type}`)
}
awarenessProtocol.applyAwarenessUpdate(clearJsAwareness, clearMessage.payload, 'php-awareness-clear')
if (clearJsAwareness.getStates().has(90) || clearJsAwareness.getStates().has(91)) {
  throw new Error('awareness clear message did not remove all active clients')
}

const batchAwareness = awareness.batch
const batchJsAwareness = new awarenessProtocol.Awareness(new Y.Doc())
const batchMessage = readProtocolMessage(fromBase64(batchAwareness.stateMessage))
if (batchMessage.type !== 1) {
  throw new Error(`awareness batch state message type: expected 1, got ${batchMessage.type}`)
}
awarenessProtocol.applyAwarenessUpdate(batchJsAwareness, batchMessage.payload, 'php-awareness-batch')
for (const [clientID, state] of Object.entries(batchAwareness.expectedStates)) {
  assertSameJson(batchJsAwareness.getStates().get(Number(clientID)), state, `awareness batch state ${clientID}`)
}

const filteredAwareness = awareness.filtered
const filteredJsAwareness = new awarenessProtocol.Awareness(new Y.Doc())
const filteredStateMessage = readProtocolMessage(fromBase64(filteredAwareness.stateMessage))
if (filteredStateMessage.type !== 1) {
  throw new Error(`awareness filtered state message type: expected 1, got ${filteredStateMessage.type}`)
}
awarenessProtocol.applyAwarenessUpdate(filteredJsAwareness, filteredStateMessage.payload, 'php-awareness-filtered-state')
assertSameJson(filteredJsAwareness.getStates().get(96), filteredAwareness.expectedState, 'awareness filtered state 96')
if (filteredJsAwareness.getStates().has(98) || filteredJsAwareness.getStates().has(404)) {
  throw new Error('awareness filtered state included null or unknown clients')
}

const filteredRemoveJsAwareness = new awarenessProtocol.Awareness(new Y.Doc())
const filteredInitialMessage = readProtocolMessage(fromBase64(filteredAwareness.initialMessage))
if (filteredInitialMessage.type !== 1) {
  throw new Error(`awareness filtered initial message type: expected 1, got ${filteredInitialMessage.type}`)
}
awarenessProtocol.applyAwarenessUpdate(filteredRemoveJsAwareness, filteredInitialMessage.payload, 'php-awareness-filtered-initial')
for (const [clientID, state] of Object.entries(filteredAwareness.expectedInitialStates)) {
  assertSameJson(filteredRemoveJsAwareness.getStates().get(Number(clientID)), state, `awareness filtered initial state ${clientID}`)
}
const filteredRemoveMessage = readProtocolMessage(fromBase64(filteredAwareness.removeMessage))
if (filteredRemoveMessage.type !== 1) {
  throw new Error(`awareness filtered remove message type: expected 1, got ${filteredRemoveMessage.type}`)
}
awarenessProtocol.applyAwarenessUpdate(filteredRemoveJsAwareness, filteredRemoveMessage.payload, 'php-awareness-filtered-remove')
assertSameJson(filteredRemoveJsAwareness.getStates().get(96), filteredAwareness.expectedState, 'awareness filtered remove keeps 96')
if (filteredRemoveJsAwareness.getStates().has(97) || filteredRemoveJsAwareness.getStates().has(404)) {
  throw new Error('awareness filtered remove did not remove only the requested active client')
}

const observedAwarenessDoc = new Y.Doc()
const observedJsAwareness = new awarenessProtocol.Awareness(observedAwarenessDoc)
for (const [index, message] of awareness.observedMessages.entries()) {
  const decoded = readProtocolMessage(fromBase64(message))
  if (decoded.type !== 1) {
    throw new Error(`observed awareness message ${index} type: expected 1, got ${decoded.type}`)
  }
  awarenessProtocol.applyAwarenessUpdate(observedJsAwareness, decoded.payload, 'php-observed-awareness')
  const expectedState = awareness.observedExpectedStates[index]
  if (expectedState === null) {
    if (observedJsAwareness.getStates().has(88)) {
      throw new Error(`observed awareness message ${index} did not remove client 88`)
    }
  } else {
    assertSameJson(observedJsAwareness.getStates().get(88), expectedState, `observed awareness message ${index} state`)
  }
}
if (observedJsAwareness.getStates().has(89)) {
  throw new Error('observed awareness unobserve failed; client 89 reached JS awareness')
}

const timeoutAwareness = awareness.timeout
const timeoutJsAwareness = new awarenessProtocol.Awareness(new Y.Doc())
const timeoutStateMessage = readProtocolMessage(fromBase64(timeoutAwareness.stateMessage))
if (timeoutStateMessage.type !== 1) {
  throw new Error(`awareness timeout state message type: expected 1, got ${timeoutStateMessage.type}`)
}
awarenessProtocol.applyAwarenessUpdate(timeoutJsAwareness, timeoutStateMessage.payload, 'php-awareness-timeout-state')
for (const [clientID, state] of Object.entries(timeoutAwareness.expectedInitialStates)) {
  assertSameJson(timeoutJsAwareness.getStates().get(Number(clientID)), state, `awareness timeout initial state ${clientID}`)
}
if (timeoutAwareness.observedMessages.length !== 1) {
  throw new Error(`awareness timeout observed messages: expected 1, got ${timeoutAwareness.observedMessages.length}`)
}
assertSameJson(timeoutAwareness.removed, [92, 93], 'awareness timeout removed clients')
assertSameJson(timeoutAwareness.events, [
  {
    origin: 'php-awareness-timeout',
    added: [],
    updated: [],
    removed: [92, 93]
  }
], 'awareness timeout observer event')
const timeoutRemoveMessage = readProtocolMessage(fromBase64(timeoutAwareness.observedMessages[0]))
if (timeoutRemoveMessage.type !== 1) {
  throw new Error(`awareness timeout remove message type: expected 1, got ${timeoutRemoveMessage.type}`)
}
awarenessProtocol.applyAwarenessUpdate(timeoutJsAwareness, timeoutRemoveMessage.payload, 'php-awareness-timeout')
if (timeoutJsAwareness.getStates().has(92) || timeoutJsAwareness.getStates().has(93)) {
  throw new Error('awareness timeout message did not remove stale clients')
}

const verifyAwarenessSequence = (sequence, clientID, label) => {
  const sequenceAwareness = new awarenessProtocol.Awareness(new Y.Doc())
  for (const [index, message] of sequence.messages.entries()) {
    const decoded = readProtocolMessage(fromBase64(message))
    if (decoded.type !== 1) {
      throw new Error(`awareness ${label} message ${index} type: expected 1, got ${decoded.type}`)
    }
    awarenessProtocol.applyAwarenessUpdate(sequenceAwareness, decoded.payload, `php-awareness-${label}`)
    const expectedState = sequence.expectedStates[index]
    if (expectedState === null) {
      if (sequenceAwareness.getStates().has(clientID)) {
        throw new Error(`awareness ${label} message ${index} unexpectedly has client ${clientID}`)
      }
    } else {
      assertSameJson(sequenceAwareness.getStates().get(clientID), expectedState, `awareness ${label} message ${index} state`)
    }
  }

  return sequenceAwareness
}

const staleAfterRemoveJsAwareness = verifyAwarenessSequence(awareness.staleAfterRemove, 77, 'stale-after-remove')
const sameClockRemoveJsAwareness = verifyAwarenessSequence(awareness.sameClockRemove, 83, 'same-clock-remove')

jsAwareness.destroy()
queryReplyAwareness.destroy()
handledQueryReplyAwareness.destroy()
clearJsAwareness.destroy()
batchJsAwareness.destroy()
filteredJsAwareness.destroy()
filteredRemoveJsAwareness.destroy()
observedJsAwareness.destroy()
timeoutJsAwareness.destroy()
staleAfterRemoveJsAwareness.destroy()
sameClockRemoveJsAwareness.destroy()

console.log('Verified PHP-generated sync and awareness protocol messages against y-protocols')
