import { execFileSync } from 'node:child_process'
import * as Y from 'yjs'

const fromBase64 = value => Uint8Array.from(Buffer.from(value, 'base64'))
const toBase64 = bytes => Buffer.from(bytes).toString('base64')

const phpOutput = execFileSync('php', ['tools/php-generated-updates.php'], { encoding: 'utf8' })
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

const normalizeXmlAttributeValue = value => {
  const typeName = value?.constructor?.name
  if (['YArray', 'YMap', 'YText', 'YXmlElement', 'YXmlFragment', 'YXmlHook', 'YXmlText'].includes(typeName)) {
    return value.toJSON()
  }

  return value
}

const assertOptionalHookJson = (doc, testCase, label) => {
  if (!Object.hasOwn(testCase, 'hookJson')) {
    return
  }

  const hook = doc.getXmlFragment('xml').get(0)
  if (hook?.constructor?.name !== 'YXmlHook') {
    throw new Error(`${label}: expected first XML child to be YXmlHook, got ${hook?.constructor?.name ?? 'null'}`)
  }

  assertSameJson(hook.toJSON(), testCase.hookJson, `${label} hook JSON`)
}

const assertOptionalXmlHookChildren = (doc, testCase, label) => {
  if (!Object.hasOwn(testCase, 'xmlHookChildren')) {
    return
  }

  const xml = doc.getXmlFragment('xml')
  for (const { index, hookName, json } of testCase.xmlHookChildren) {
    const hook = xml.get(index)
    if (hook?.constructor?.name !== 'YXmlHook') {
      throw new Error(`${label}: expected XML child ${index} to be YXmlHook, got ${hook?.constructor?.name ?? 'null'}`)
    }

    assertSameJson(hook.hookName, hookName, `${label} XML hook ${index} name`)
    assertSameJson(hook.toJSON(), json, `${label} XML hook ${index} JSON`)
  }
}

const assertOptionalXmlElementAttributes = (doc, testCase, label) => {
  if (!Object.hasOwn(testCase, 'xmlElementAttributes')) {
    return
  }

  const element = doc.getXmlFragment('xml').get(0)
  if (element?.constructor?.name !== 'YXmlElement') {
    throw new Error(`${label}: expected first XML child to be YXmlElement, got ${element?.constructor?.name ?? 'null'}`)
  }

  const attributes = Object.fromEntries(
    Object.entries(element.getAttributes())
      .map(([key, value]) => [key, normalizeXmlAttributeValue(value)])
  )

  assertSameJson(attributes, testCase.xmlElementAttributes, `${label} XML element attributes`)
}

const assertOptionalXmlTextAttributes = (doc, testCase, label) => {
  if (!Object.hasOwn(testCase, 'xmlTextAttributes')) {
    return
  }

  const xmlText = doc.getXmlFragment('xml').get(0)
  if (xmlText?.constructor?.name !== 'YXmlText') {
    throw new Error(`${label}: expected first XML child to be YXmlText, got ${xmlText?.constructor?.name ?? 'null'}`)
  }

  const attributes = Object.fromEntries(
    Object.entries(xmlText.getAttributes())
      .map(([key, value]) => [key, normalizeXmlAttributeValue(value)])
  )

  assertSameJson(attributes, testCase.xmlTextAttributes, `${label} XML text attributes`)
}

const assertOptionalTextAttributes = (doc, testCase, label) => {
  if (!Object.hasOwn(testCase, 'textAttributes')) {
    return
  }

  assertSameJson(doc.getText('content').getAttributes(), testCase.textAttributes, `${label} text attributes`)
}

const assertOptionalNestedTextAttributes = (doc, testCase, label) => {
  if (!Object.hasOwn(testCase, 'nestedTextAttributes')) {
    return
  }

  const nestedText = doc.getArray('array').get(0)
  if (nestedText?.constructor?.name !== 'YText') {
    throw new Error(`${label}: expected first array item to be YText, got ${nestedText?.constructor?.name ?? 'null'}`)
  }

  assertSameJson(nestedText.getAttributes(), testCase.nestedTextAttributes, `${label} nested text attributes`)
}

const assertOptionalMapTextAttributes = (doc, testCase, label) => {
  if (!Object.hasOwn(testCase, 'mapTextAttributes')) {
    return
  }

  const nestedText = doc.getMap('map').get(testCase.mapTextKey)
  if (nestedText?.constructor?.name !== 'YText') {
    throw new Error(`${label}: expected map value "${testCase.mapTextKey}" to be YText, got ${nestedText?.constructor?.name ?? 'null'}`)
  }

  assertSameJson(nestedText.getAttributes(), testCase.mapTextAttributes, `${label} map text attributes`)
}

const assertOptionalArrayXmlFragments = (doc, testCase, label) => {
  if (!Object.hasOwn(testCase, 'arrayXmlFragments')) {
    return
  }

  const array = doc.getArray('array')
  for (const { index, xml } of testCase.arrayXmlFragments) {
    const value = array.get(index)
    if (value?.constructor?.name !== 'YXmlFragment') {
      throw new Error(`${label}: expected array item ${index} to be YXmlFragment, got ${value?.constructor?.name ?? 'null'}`)
    }

    assertSameJson(value.toString(), xml, `${label} array XML fragment ${index}`)
  }
}

const assertOptionalMapXmlFragments = (doc, testCase, label) => {
  if (!Object.hasOwn(testCase, 'mapXmlFragments')) {
    return
  }

  const map = doc.getMap('map')
  for (const { key, xml } of testCase.mapXmlFragments) {
    const value = map.get(key)
    if (value?.constructor?.name !== 'YXmlFragment') {
      throw new Error(`${label}: expected map value "${key}" to be YXmlFragment, got ${value?.constructor?.name ?? 'null'}`)
    }

    assertSameJson(value.toString(), xml, `${label} map XML fragment ${key}`)
  }
}

const assertOptionalMapXmlElementAttributes = (doc, testCase, label) => {
  if (!Object.hasOwn(testCase, 'mapXmlElementAttributes')) {
    return
  }

  const element = doc.getMap('map').get(testCase.mapXmlElementKey)
  if (element?.constructor?.name !== 'YXmlElement') {
    throw new Error(`${label}: expected map value "${testCase.mapXmlElementKey}" to be YXmlElement, got ${element?.constructor?.name ?? 'null'}`)
  }

  const attributes = Object.fromEntries(
    Object.entries(element.getAttributes())
      .map(([key, value]) => [key, normalizeXmlAttributeValue(value)])
  )

  assertSameJson(attributes, testCase.mapXmlElementAttributes, `${label} map XML element attributes`)
}

const assertOptionalSubdocs = (doc, testCase, label) => {
  if (!Object.hasOwn(testCase, 'subdocs')) {
    return
  }

  for (const { root, path, guid, meta, shouldLoad } of testCase.subdocs) {
    let value
    if (root === 'array') {
      value = doc.getArray('array')
    } else if (root === 'map') {
      value = doc.getMap('map')
    } else {
      throw new Error(`${label}: unknown subdoc root ${root}`)
    }

    for (const segment of path) {
      value = value?.get?.(segment)
    }

    if (!(value instanceof Y.Doc)) {
      throw new Error(`${label}: expected ${root} subdoc at ${JSON.stringify(path)}, got ${value?.constructor?.name ?? 'null'}`)
    }

    assertSameJson(value.guid, guid, `${label} subdoc ${root}:${JSON.stringify(path)} guid`)
    assertSameJson(value.meta, meta, `${label} subdoc ${root}:${JSON.stringify(path)} meta`)
    assertSameJson(value.shouldLoad, shouldLoad, `${label} subdoc ${root}:${JSON.stringify(path)} shouldLoad`)
  }
}

const assertOptionalDeltas = (doc, testCase, label) => {
  if (Object.hasOwn(testCase, 'textDelta')) {
    assertSameJson(doc.getText('content').toDelta(), testCase.textDelta, `${label} text delta`)
  }

  if (Object.hasOwn(testCase, 'nestedTextDelta')) {
    const nestedText = doc.getArray('array').get(0)
    if (nestedText?.constructor?.name !== 'YText') {
      throw new Error(`${label}: expected first array item to be YText, got ${nestedText?.constructor?.name ?? 'null'}`)
    }

    assertSameJson(nestedText.toDelta(), testCase.nestedTextDelta, `${label} nested text delta`)
  }

  if (Object.hasOwn(testCase, 'mapTextDelta')) {
    const nestedText = doc.getMap('map').get(testCase.mapTextKey)
    if (nestedText?.constructor?.name !== 'YText') {
      throw new Error(`${label}: expected map value "${testCase.mapTextKey}" to be YText, got ${nestedText?.constructor?.name ?? 'null'}`)
    }

    assertSameJson(nestedText.toDelta(), testCase.mapTextDelta, `${label} map text delta`)
  }

  if (Object.hasOwn(testCase, 'mapXmlTextDelta')) {
    const nestedText = doc.getMap('map').get(testCase.mapXmlTextKey)
    if (nestedText?.constructor?.name !== 'YXmlText') {
      throw new Error(`${label}: expected map value "${testCase.mapXmlTextKey}" to be YXmlText, got ${nestedText?.constructor?.name ?? 'null'}`)
    }

    assertSameJson(nestedText.toDelta(), testCase.mapXmlTextDelta, `${label} map XML text delta`)
  }

  if (Object.hasOwn(testCase, 'deepNestedMapTextDelta')) {
    const root = doc.getMap('map').get('root')
    if (root?.constructor?.name !== 'YMap') {
      throw new Error(`${label}: expected map value "root" to be YMap, got ${root?.constructor?.name ?? 'null'}`)
    }

    const nestedText = root.get('body')
    if (nestedText?.constructor?.name !== 'YText') {
      throw new Error(`${label}: expected nested map value "body" to be YText, got ${nestedText?.constructor?.name ?? 'null'}`)
    }

    assertSameJson(nestedText.toDelta(), testCase.deepNestedMapTextDelta, `${label} deep nested map text delta`)
  }

  if (Object.hasOwn(testCase, 'xmlTextDelta')) {
    const xmlNode = doc.getXmlFragment('xml').get(0)
    const xmlText = xmlNode?.constructor?.name === 'YXmlText' ? xmlNode : xmlNode?.get?.(0)
    if (xmlText?.constructor?.name !== 'YXmlText') {
      throw new Error(`${label}: expected XML text node, got ${xmlText?.constructor?.name ?? 'null'}`)
    }

    assertSameJson(xmlText.toDelta(), testCase.xmlTextDelta, `${label} XML text delta`)
  }
}

const expectedJson = testCase => {
  const json = normalizeJson(testCase.json)
  if (Array.isArray(json) && json.length === 0) {
    return {}
  }

  if (
    json
    && typeof json === 'object'
    && !Array.isArray(json)
    && Array.isArray(json.map)
    && json.map.length === 0
  ) {
    return { ...json, map: {} }
  }

  return json
}

const initializeType = (doc, type) => {
  if (type === 'text') {
    doc.getText('content')
  } else if (type === 'array') {
    doc.getArray('array')
  } else if (type === 'map') {
    doc.getMap('map')
  } else if (type === 'xml') {
    doc.getXmlFragment('xml')
  } else if (type === 'mixed') {
    doc.getArray('array')
    doc.getMap('map')
  } else if (type === 'text-array-map') {
    doc.getText('content')
    doc.getArray('array')
    doc.getMap('map')
  } else if (type === 'map-xml') {
    doc.getMap('map')
    doc.getXmlFragment('xml')
  } else if (type === 'array-xml') {
    doc.getArray('array')
    doc.getXmlFragment('xml')
  } else if (type === 'text-array-xml') {
    doc.getText('content')
    doc.getArray('array')
    doc.getXmlFragment('xml')
  } else if (type === 'all') {
    doc.getText('content')
    doc.getArray('array')
    doc.getMap('map')
    doc.getXmlFragment('xml')
  }
}

const formatStructCount = decoded => decoded.structs.filter(struct => struct.content?.constructor?.name === 'ContentFormat').length
const contentAnyStructCount = decoded => decoded.structs.filter(struct => struct.content?.constructor?.name === 'ContentAny').length
const embedStructCount = decoded => decoded.structs.filter(struct => struct.content?.constructor?.name === 'ContentEmbed').length
const binaryStructCount = decoded => decoded.structs.filter(struct => struct.content?.constructor?.name === 'ContentBinary').length
const jsonStructCount = decoded => decoded.structs.filter(struct => struct.content?.constructor?.name === 'ContentJSON').length
const docStructCount = decoded => decoded.structs.filter(struct => struct.content?.constructor?.name === 'ContentDoc').length
const contentDeletedStructCount = decoded => decoded.structs.filter(struct => struct.content?.constructor?.name === 'ContentDeleted').length
const gcStructCount = decoded => decoded.structs.filter(struct => struct.constructor?.name === 'GC').length
const skipStructCount = decoded => decoded.structs.filter(struct => struct.constructor?.name === 'Skip').length
const normalizeDeleteSet = decoded => Object.fromEntries(
  Array.from(decoded.ds.clients.entries())
    .sort(([left], [right]) => right - left)
    .map(([client, deletes]) => [
      String(client),
      deletes.map(deleteItem => ({ clock: deleteItem.clock, length: deleteItem.len }))
    ])
)
const normalizeFixtureDeleteSet = deleteSet => Array.isArray(deleteSet) && deleteSet.length === 0 ? {} : deleteSet

const assertDecodedStructCounts = (decoded, testCase, label) => {
  const counts = {
    formatStructCount,
    contentAnyStructCount,
    embedStructCount,
    binaryStructCount,
    jsonStructCount,
    docStructCount,
    contentDeletedStructCount,
    gcStructCount,
    skipStructCount
  }

  for (const [key, count] of Object.entries(counts)) {
    const expected = testCase[key] ?? 0
    const actual = count(decoded)
    if (actual !== expected) {
      throw new Error(`${label} ${key}: expected ${expected}, got ${actual}`)
    }
  }
}

const normalizeAnyValue = value => {
  if (value === undefined) {
    return { type: 'Undefined' }
  }
  if (typeof value === 'number' && !Number.isFinite(value)) {
    return { type: 'Number', value: Number.isNaN(value) ? 'NaN' : String(value) }
  }
  if (value instanceof Uint8Array) {
    return { type: 'Uint8Array', base64: toBase64(value) }
  }
  if (Array.isArray(value)) {
    return value.map(normalizeAnyValue)
  }
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.entries(value).map(([key, nested]) => [key, normalizeAnyValue(nested)]))
  }
  return value
}

const normalizeContentAnyValues = decoded => {
  const struct = decoded.structs.find(struct => struct.content?.constructor?.name === 'ContentAny')
  return struct === undefined ? [] : normalizeAnyValue(struct.content.arr)
}

for (const testCase of fixtures.cases) {
  const fullDoc = new Y.Doc({ gc: false })
  initializeType(fullDoc, testCase.type)
  const updateV1 = fromBase64(testCase.updateV1)
  Y.applyUpdate(fullDoc, updateV1)
  assertSameJson(fullDoc.toJSON(), expectedJson(testCase), `${testCase.name} full update JSON`)
  assertOptionalHookJson(fullDoc, testCase, `${testCase.name} full update`)
  assertOptionalXmlHookChildren(fullDoc, testCase, `${testCase.name} full update`)
  assertOptionalXmlElementAttributes(fullDoc, testCase, `${testCase.name} full update`)
  assertOptionalXmlTextAttributes(fullDoc, testCase, `${testCase.name} full update`)
  assertOptionalTextAttributes(fullDoc, testCase, `${testCase.name} full update`)
  assertOptionalNestedTextAttributes(fullDoc, testCase, `${testCase.name} full update`)
  assertOptionalMapTextAttributes(fullDoc, testCase, `${testCase.name} full update`)
  assertOptionalArrayXmlFragments(fullDoc, testCase, `${testCase.name} full update`)
  assertOptionalMapXmlFragments(fullDoc, testCase, `${testCase.name} full update`)
  assertOptionalMapXmlElementAttributes(fullDoc, testCase, `${testCase.name} full update`)
  assertOptionalSubdocs(fullDoc, testCase, `${testCase.name} full update`)
  assertOptionalDeltas(fullDoc, testCase, `${testCase.name} full update`)
  const decodedV1 = Y.decodeUpdate(updateV1)
  assertSameJson(normalizeDeleteSet(decodedV1), normalizeFixtureDeleteSet(testCase.deleteSet), `${testCase.name} V1 delete set`)
  assertDecodedStructCounts(decodedV1, testCase, `${testCase.name} V1`)

  const fullStateVector = toBase64(Y.encodeStateVector(fullDoc))
  if (fullStateVector !== testCase.stateVectorV1) {
    throw new Error(`${testCase.name} state vector: expected ${testCase.stateVectorV1}, got ${fullStateVector}`)
  }

  const v2Doc = new Y.Doc({ gc: false })
  initializeType(v2Doc, testCase.type)
  const updateV2 = fromBase64(testCase.updateV2)
  Y.applyUpdateV2(v2Doc, updateV2)
  assertSameJson(v2Doc.toJSON(), expectedJson(testCase), `${testCase.name} V2 update JSON`)
  assertOptionalHookJson(v2Doc, testCase, `${testCase.name} V2 update`)
  assertOptionalXmlHookChildren(v2Doc, testCase, `${testCase.name} V2 update`)
  assertOptionalXmlElementAttributes(v2Doc, testCase, `${testCase.name} V2 update`)
  assertOptionalXmlTextAttributes(v2Doc, testCase, `${testCase.name} V2 update`)
  assertOptionalTextAttributes(v2Doc, testCase, `${testCase.name} V2 update`)
  assertOptionalNestedTextAttributes(v2Doc, testCase, `${testCase.name} V2 update`)
  assertOptionalMapTextAttributes(v2Doc, testCase, `${testCase.name} V2 update`)
  assertOptionalArrayXmlFragments(v2Doc, testCase, `${testCase.name} V2 update`)
  assertOptionalMapXmlFragments(v2Doc, testCase, `${testCase.name} V2 update`)
  assertOptionalMapXmlElementAttributes(v2Doc, testCase, `${testCase.name} V2 update`)
  assertOptionalSubdocs(v2Doc, testCase, `${testCase.name} V2 update`)
  assertOptionalDeltas(v2Doc, testCase, `${testCase.name} V2 update`)
  const decodedV2 = Y.decodeUpdateV2(updateV2)
  assertSameJson(normalizeDeleteSet(decodedV2), normalizeFixtureDeleteSet(testCase.deleteSet), `${testCase.name} V2 delete set`)
  assertDecodedStructCounts(decodedV2, testCase, `${testCase.name} V2`)

  const incrementalDoc = new Y.Doc({ gc: false })
  initializeType(incrementalDoc, testCase.type)
  for (const update of testCase.incrementalUpdatesV1) {
    Y.applyUpdate(incrementalDoc, fromBase64(update))
  }
  assertSameJson(incrementalDoc.toJSON(), expectedJson(testCase), `${testCase.name} incremental update JSON`)
  assertOptionalHookJson(incrementalDoc, testCase, `${testCase.name} incremental update`)
  assertOptionalXmlHookChildren(incrementalDoc, testCase, `${testCase.name} incremental update`)
  assertOptionalXmlElementAttributes(incrementalDoc, testCase, `${testCase.name} incremental update`)
  assertOptionalXmlTextAttributes(incrementalDoc, testCase, `${testCase.name} incremental update`)
  assertOptionalTextAttributes(incrementalDoc, testCase, `${testCase.name} incremental update`)
  assertOptionalNestedTextAttributes(incrementalDoc, testCase, `${testCase.name} incremental update`)
  assertOptionalMapTextAttributes(incrementalDoc, testCase, `${testCase.name} incremental update`)
  assertOptionalArrayXmlFragments(incrementalDoc, testCase, `${testCase.name} incremental update`)
  assertOptionalMapXmlFragments(incrementalDoc, testCase, `${testCase.name} incremental update`)
  assertOptionalMapXmlElementAttributes(incrementalDoc, testCase, `${testCase.name} incremental update`)
  assertOptionalSubdocs(incrementalDoc, testCase, `${testCase.name} incremental update`)
  assertOptionalDeltas(incrementalDoc, testCase, `${testCase.name} incremental update`)

  const incrementalV2Doc = new Y.Doc({ gc: false })
  initializeType(incrementalV2Doc, testCase.type)
  for (const update of testCase.incrementalUpdatesV2) {
    Y.applyUpdateV2(incrementalV2Doc, fromBase64(update))
  }
  assertSameJson(incrementalV2Doc.toJSON(), expectedJson(testCase), `${testCase.name} incremental V2 update JSON`)
  assertOptionalHookJson(incrementalV2Doc, testCase, `${testCase.name} incremental V2 update`)
  assertOptionalXmlHookChildren(incrementalV2Doc, testCase, `${testCase.name} incremental V2 update`)
  assertOptionalXmlElementAttributes(incrementalV2Doc, testCase, `${testCase.name} incremental V2 update`)
  assertOptionalXmlTextAttributes(incrementalV2Doc, testCase, `${testCase.name} incremental V2 update`)
  assertOptionalTextAttributes(incrementalV2Doc, testCase, `${testCase.name} incremental V2 update`)
  assertOptionalNestedTextAttributes(incrementalV2Doc, testCase, `${testCase.name} incremental V2 update`)
  assertOptionalMapTextAttributes(incrementalV2Doc, testCase, `${testCase.name} incremental V2 update`)
  assertOptionalArrayXmlFragments(incrementalV2Doc, testCase, `${testCase.name} incremental V2 update`)
  assertOptionalMapXmlFragments(incrementalV2Doc, testCase, `${testCase.name} incremental V2 update`)
  assertOptionalMapXmlElementAttributes(incrementalV2Doc, testCase, `${testCase.name} incremental V2 update`)
  assertOptionalSubdocs(incrementalV2Doc, testCase, `${testCase.name} incremental V2 update`)
  assertOptionalDeltas(incrementalV2Doc, testCase, `${testCase.name} incremental V2 update`)
}

for (const testCase of fixtures.decodedStructCases ?? []) {
  const updateV1 = fromBase64(testCase.updateV1)
  const decodedV1 = Y.decodeUpdate(updateV1)
  assertSameJson(normalizeDeleteSet(decodedV1), normalizeFixtureDeleteSet(testCase.deleteSet), `${testCase.name} V1 delete set`)
  assertDecodedStructCounts(decodedV1, testCase, `${testCase.name} V1`)
  if (testCase.contentAnyValues !== undefined) {
    assertSameJson(normalizeContentAnyValues(decodedV1), testCase.contentAnyValues, `${testCase.name} V1 ContentAny values`)
  }
  if (testCase.arrayValues !== undefined) {
    const doc = new Y.Doc({ gc: false })
    const array = doc.getArray('array')
    Y.applyUpdate(doc, updateV1)
    assertSameJson(normalizeAnyValue(array.toArray()), testCase.arrayValues, `${testCase.name} V1 array values`)
  }

  const updateV2 = fromBase64(testCase.updateV2)
  const decodedV2 = Y.decodeUpdateV2(updateV2)
  assertSameJson(normalizeDeleteSet(decodedV2), normalizeFixtureDeleteSet(testCase.deleteSet), `${testCase.name} V2 delete set`)
  assertDecodedStructCounts(decodedV2, testCase, `${testCase.name} V2`)
  if (testCase.contentAnyValues !== undefined) {
    assertSameJson(normalizeContentAnyValues(decodedV2), testCase.contentAnyValues, `${testCase.name} V2 ContentAny values`)
  }
  if (testCase.arrayValues !== undefined) {
    const doc = new Y.Doc({ gc: false })
    const array = doc.getArray('array')
    Y.applyUpdateV2(doc, updateV2)
    assertSameJson(normalizeAnyValue(array.toArray()), testCase.arrayValues, `${testCase.name} V2 array values`)
  }
}

for (const testCase of fixtures.partialDiffCases ?? []) {
  const diffDoc = new Y.Doc({ gc: false })
  initializeType(diffDoc, testCase.type)
  Y.applyUpdate(diffDoc, fromBase64(testCase.prefixUpdateV1))
  Y.applyUpdate(diffDoc, fromBase64(testCase.diffV1))
  assertSameJson(diffDoc.toJSON(), testCase.json, `${testCase.name} V1 partial diff JSON`)
  assertOptionalHookJson(diffDoc, testCase, `${testCase.name} V1 partial diff`)
  assertOptionalXmlHookChildren(diffDoc, testCase, `${testCase.name} V1 partial diff`)
  assertOptionalXmlElementAttributes(diffDoc, testCase, `${testCase.name} V1 partial diff`)
  assertOptionalXmlTextAttributes(diffDoc, testCase, `${testCase.name} V1 partial diff`)
  assertOptionalTextAttributes(diffDoc, testCase, `${testCase.name} V1 partial diff`)
  assertOptionalNestedTextAttributes(diffDoc, testCase, `${testCase.name} V1 partial diff`)
  assertOptionalMapTextAttributes(diffDoc, testCase, `${testCase.name} V1 partial diff`)
  assertOptionalArrayXmlFragments(diffDoc, testCase, `${testCase.name} V1 partial diff`)
  assertOptionalMapXmlFragments(diffDoc, testCase, `${testCase.name} V1 partial diff`)
  assertOptionalMapXmlElementAttributes(diffDoc, testCase, `${testCase.name} V1 partial diff`)
  assertOptionalSubdocs(diffDoc, testCase, `${testCase.name} V1 partial diff`)
  assertOptionalDeltas(diffDoc, testCase, `${testCase.name} V1 partial diff`)
  if (testCase.arrayValues !== undefined) {
    assertSameJson(normalizeAnyValue(diffDoc.getArray('array').toArray()), testCase.arrayValues, `${testCase.name} V1 partial diff array values`)
  }

  const diffV2Doc = new Y.Doc({ gc: false })
  initializeType(diffV2Doc, testCase.type)
  Y.applyUpdateV2(diffV2Doc, fromBase64(testCase.prefixUpdateV2))
  Y.applyUpdateV2(diffV2Doc, fromBase64(testCase.diffV2))
  assertSameJson(diffV2Doc.toJSON(), testCase.json, `${testCase.name} V2 partial diff JSON`)
  assertOptionalHookJson(diffV2Doc, testCase, `${testCase.name} V2 partial diff`)
  assertOptionalXmlHookChildren(diffV2Doc, testCase, `${testCase.name} V2 partial diff`)
  assertOptionalXmlElementAttributes(diffV2Doc, testCase, `${testCase.name} V2 partial diff`)
  assertOptionalXmlTextAttributes(diffV2Doc, testCase, `${testCase.name} V2 partial diff`)
  assertOptionalTextAttributes(diffV2Doc, testCase, `${testCase.name} V2 partial diff`)
  assertOptionalNestedTextAttributes(diffV2Doc, testCase, `${testCase.name} V2 partial diff`)
  assertOptionalMapTextAttributes(diffV2Doc, testCase, `${testCase.name} V2 partial diff`)
  assertOptionalArrayXmlFragments(diffV2Doc, testCase, `${testCase.name} V2 partial diff`)
  assertOptionalMapXmlFragments(diffV2Doc, testCase, `${testCase.name} V2 partial diff`)
  assertOptionalMapXmlElementAttributes(diffV2Doc, testCase, `${testCase.name} V2 partial diff`)
  assertOptionalSubdocs(diffV2Doc, testCase, `${testCase.name} V2 partial diff`)
  assertOptionalDeltas(diffV2Doc, testCase, `${testCase.name} V2 partial diff`)
  if (testCase.arrayValues !== undefined) {
    assertSameJson(normalizeAnyValue(diffV2Doc.getArray('array').toArray()), testCase.arrayValues, `${testCase.name} V2 partial diff array values`)
  }

  const fullDoc = new Y.Doc({ gc: false })
  initializeType(fullDoc, testCase.type)
  Y.applyUpdate(fullDoc, fromBase64(testCase.prefixUpdateV1))
  const targetStateVector = toBase64(Y.encodeStateVector(fullDoc))
  if (targetStateVector !== testCase.targetStateVectorV1) {
    throw new Error(`${testCase.name} target state vector: expected ${testCase.targetStateVectorV1}, got ${targetStateVector}`)
  }
}

console.log(`Verified ${fixtures.cases.length} PHP-generated update cases, ${(fixtures.partialDiffCases ?? []).length} partial diff cases, and ${(fixtures.decodedStructCases ?? []).length} decoded struct cases against yjs ${Y.Doc ? '13.6.31' : ''}`)
