import fs from 'node:fs'
import path from 'node:path'
import * as Y from 'yjs'
import * as encoding from 'lib0/encoding'
import * as syncProtocol from 'y-protocols/sync'
import * as awarenessProtocol from 'y-protocols/awareness'

const outDir = path.resolve('fixtures/generated/yjs-13.6.31')
fs.mkdirSync(outDir, { recursive: true })

const toBase64 = bytes => Buffer.from(bytes).toString('base64')
const fromBase64 = value => new Uint8Array(Buffer.from(value, 'base64'))
const stateVector = entries => {
  const encoder = encoding.createEncoder()
  encoding.writeVarUint(encoder, entries.length)
  for (const [client, clock] of entries) {
    encoding.writeVarUint(encoder, client)
    encoding.writeVarUint(encoder, clock)
  }
  return encoding.toUint8Array(encoder)
}
const normalizeValue = value => {
  if (value === undefined) {
    return { type: 'Undefined' }
  }
  if (typeof value === 'number' && !Number.isFinite(value)) {
    return { type: 'Number', value: Number.isNaN(value) ? 'NaN' : String(value) }
  }
  if (typeof value === 'bigint') {
    return { type: 'BigInt', value: value.toString() }
  }
  if (value instanceof Uint8Array) {
    return { type: 'Uint8Array', base64: toBase64(value) }
  }
  if (value instanceof Y.XmlElement || value instanceof Y.XmlText || value instanceof Y.XmlHook || value instanceof Y.XmlFragment) {
    return value.toString()
  }
  if (value instanceof Y.Text) {
    return value.toString()
  }
  if (value instanceof Y.Array || value instanceof Y.Map) {
    return normalizeValue(value.toJSON())
  }
  if (value instanceof Y.Doc) {
    return {}
  }
  if (Array.isArray(value)) {
    return value.map(normalizeValue)
  }
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.entries(value).map(([key, nested]) => [key, normalizeValue(nested)]))
  }
  return value
}
const normalizeSemanticJsonValue = value => {
  if (value instanceof Uint8Array) {
    return Array.from(value)
  }
  if (value instanceof Y.XmlElement || value instanceof Y.XmlText || value instanceof Y.XmlHook || value instanceof Y.XmlFragment) {
    return value.toString()
  }
  if (value instanceof Y.Text) {
    return value.toString()
  }
  if (value instanceof Y.Array || value instanceof Y.Map) {
    return normalizeSemanticJsonValue(value.toJSON())
  }
  if (value instanceof Y.Doc) {
    return {}
  }
  if (Array.isArray(value)) {
    return value.map(normalizeSemanticJsonValue)
  }
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.entries(value).map(([key, nested]) => [key, normalizeSemanticJsonValue(nested)]))
  }
  return value
}
const xmlNodeSummary = node => ({
  type: node.constructor.name,
  nodeName: node.nodeName ?? null,
  string: node.toString(),
  json: node.toJSON()
})

const normalizeId = id => id == null ? null : { client: id.client, clock: id.clock }
const normalizeDeleteSet = ds => Object.fromEntries(Array.from(ds.clients.entries()).map(([client, deletes]) => [
  client,
  deletes.map(deleteItem => ({ clock: deleteItem.clock, length: deleteItem.len }))
]))
const normalizeStateMap = state => Object.fromEntries(Array.from(state.entries()).sort(([left], [right]) => right - left))
const normalizeTransactionEvent = transaction => ({
  origin: transaction.origin,
  local: transaction.local,
  beforeStateVector: normalizeStateMap(transaction.beforeState),
  afterStateVector: normalizeStateMap(transaction.afterState),
  deleteSet: normalizeDeleteSet(transaction.deleteSet),
  changedTypeNames: Array.from(transaction.changed.keys()).map(type => type.constructor.name).sort(),
  changedParentTypeNames: Array.from(transaction.changedParentTypes.keys()).map(type => type.constructor.name).sort()
})
const concurrentFixtureWithMergedMetadata = fixture => {
  const mergedV1 = new Y.Doc({ guid: `${fixture.name}-metadata-v1`, gc: false })
  for (const update of fixture.updatesV1) {
    Y.applyUpdate(mergedV1, fromBase64(update))
  }
  const mergedV2 = new Y.Doc({ guid: `${fixture.name}-metadata-v2`, gc: false })
  for (const update of fixture.updatesV2) {
    Y.applyUpdateV2(mergedV2, fromBase64(update))
  }
  const mergedUpdateV1 = Y.encodeStateAsUpdate(mergedV1)
  const mergedUpdateV2 = Y.encodeStateAsUpdateV2(mergedV2)

  return {
    ...fixture,
    stateVectorV1: toBase64(Y.encodeStateVector(mergedV1)),
    decodedMergedV1: {
      structs: Y.decodeUpdate(mergedUpdateV1).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdate(mergedUpdateV1).ds)
    },
    decodedMergedV2: {
      structs: Y.decodeUpdateV2(mergedUpdateV2).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(mergedUpdateV2).ds)
    }
  }
}
const typeRefByName = {
  YArray: 0,
  YMap: 1,
  YText: 2,
  YXmlElement: 3,
  YXmlFragment: 4,
  YXmlHook: 5,
  YXmlText: 6
}
const normalizeStruct = struct => {
  if (struct.constructor.name !== 'Item') {
    return {
      type: struct.constructor.name,
      id: normalizeId(struct.id),
      length: struct.length
    }
  }

  const content = struct.content
  const contentType = content.constructor.name
  let normalizedContent

  switch (contentType) {
    case 'ContentAny':
      normalizedContent = { type: contentType, values: normalizeValue(content.arr) }
      break
    case 'ContentString':
      normalizedContent = { type: contentType, value: content.str }
      break
    case 'ContentFormat':
      normalizedContent = { type: contentType, key: content.key, value: normalizeValue(content.value) }
      break
    case 'ContentDeleted':
      normalizedContent = { type: contentType, length: content.len }
      break
    case 'ContentJSON':
      normalizedContent = { type: contentType, values: normalizeValue(content.arr) }
      break
    case 'ContentBinary':
      normalizedContent = { type: contentType, base64: toBase64(content.content) }
      break
    case 'ContentEmbed':
      normalizedContent = { type: contentType, value: normalizeValue(content.embed) }
      break
    case 'ContentDoc':
      normalizedContent = { type: contentType, guid: content.doc.guid, opts: normalizeValue(content.opts) }
      break
    case 'ContentType':
      normalizedContent = {
        type: contentType,
        typeRef: typeRefByName[content.type.constructor.name],
        typeName: content.type.constructor.name
      }
      if (content.type.nodeName !== undefined) {
        normalizedContent.nodeName = content.type.nodeName
      }
      if (content.type.hookName !== undefined) {
        normalizedContent.hookName = content.type.hookName
      }
      break
    default:
      normalizedContent = { type: contentType }
  }

  return {
    type: 'Item',
    id: normalizeId(struct.id),
    length: struct.length,
    origin: normalizeId(struct.origin),
    rightOrigin: normalizeId(struct.rightOrigin),
    parent: typeof struct.parent === 'string' ? struct.parent : normalizeId(struct.parent),
    parentSub: struct.parentSub,
    content: normalizedContent
  }
}

const updateCase = (name, build, options = {}) => {
  const doc = new Y.Doc({ guid: `${name}-doc`, gc: options.gc ?? false })
  build(doc)

  const update = Y.encodeStateAsUpdate(doc)
  const updateV2 = Y.encodeStateAsUpdateV2(doc)
  const decoded = Y.decodeUpdate(update)
  const meta = Y.parseUpdateMeta(update)
  const metaV2 = Y.parseUpdateMetaV2(updateV2)

  const extra = options.inspect ? options.inspect(doc) : {}

  return {
    name,
    updateV1: toBase64(update),
    updateV2: toBase64(updateV2),
    stateVectorV1: toBase64(Y.encodeStateVector(doc)),
    meta: {
      from: Object.fromEntries(meta.from),
      to: Object.fromEntries(meta.to)
    },
    metaV2: {
      from: Object.fromEntries(metaV2.from),
      to: Object.fromEntries(metaV2.to)
    },
    decoded: {
      structs: decoded.structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(decoded.ds)
    },
    json: doc.toJSON(),
    ...extra
  }
}

const decodedOnlyUpdateCase = (name, build) => {
  const doc = new Y.Doc({ guid: `${name}-doc`, gc: false })
  build(doc)

  const updateV1 = Y.encodeStateAsUpdate(doc)
  const updateV2 = Y.encodeStateAsUpdateV2(doc)

  return {
    name,
    updateV1: toBase64(updateV1),
    updateV2: toBase64(updateV2),
    decodedV1: {
      structs: Y.decodeUpdate(updateV1).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdate(updateV1).ds)
    },
    decodedV2: {
      structs: Y.decodeUpdateV2(updateV2).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(updateV2).ds)
    }
  }
}

const varUintValues = [
  0,
  1,
  2,
  42,
  127,
  128,
  129,
  255,
  256,
  16383,
  16384,
  65535,
  65536,
  1048576,
  2147483647
]

const encodingFixtures = {
  varUint: varUintValues.map(value => {
    const encoder = encoding.createEncoder()
    encoding.writeVarUint(encoder, value)
    return { value, base64: toBase64(encoding.toUint8Array(encoder)) }
  }),
  varString: ['', 'a', 'hello', 'collaboration', 'emoji: 😀', 'multi\nline'].map(value => {
    const encoder = encoding.createEncoder()
    encoding.writeVarString(encoder, value)
    return { value, base64: toBase64(encoding.toUint8Array(encoder)) }
  }),
  varUint8Array: [[], [104, 101, 108, 108, 111], [0, 1, 255]].map(value => {
    const bytes = Uint8Array.from(value)
    const encoder = encoding.createEncoder()
    encoding.writeVarUint8Array(encoder, bytes)
    return { value: toBase64(bytes), base64: toBase64(encoding.toUint8Array(encoder)) }
  }),
  any: [
    undefined,
    null,
    true,
    false,
    'emoji: 😀 / slash',
    42,
    -42,
    1.0,
    1.5,
    Math.PI,
    Number.NaN,
    Infinity,
    -Infinity,
    2147483647,
    2147483648,
    2147483649,
    0n,
    9223372036854775807n,
    -9223372036854775808n,
    ['nested', 1.5, { ok: true, text: 'snowman: ☃', big: 7n }],
    { alpha: 1, beta: [false, null], slash: 'a/b', big: -8n },
    Uint8Array.from([0, 1, 255])
  ].map(value => {
    const encoder = encoding.createEncoder()
    encoding.writeAny(encoder, value)
    return { value: normalizeValue(value), base64: toBase64(encoding.toUint8Array(encoder)) }
  })
}

fs.writeFileSync(
  path.join(outDir, 'lib0-encoding.json'),
  `${JSON.stringify(encodingFixtures, null, 2)}\n`
)

const relativePositionCase = (name, position) => {
  const encoded = Y.encodeRelativePosition(position)

  return {
    name,
    json: Y.relativePositionToJSON(position),
    encoded: toBase64(encoded),
    decodedJson: Y.relativePositionToJSON(Y.decodeRelativePosition(encoded))
  }
}

const relativePositionFromTypeIndexCase = (name, type, index, assoc = 0) => relativePositionCase(
  name,
  Y.createRelativePositionFromTypeIndex(type, index, assoc)
)

const absolutePositionValue = (absolute, rootTypeName = null) => {
  if (absolute === null) {
    return null
  }

  if (absolute.type._item === null) {
    return {
      typeName: rootTypeName,
      index: absolute.index,
      assoc: absolute.assoc
    }
  } else {
    return {
      typeId: normalizeId(absolute.type._item.id),
      typeName: absolute.type.constructor.name,
      index: absolute.index,
      assoc: absolute.assoc
    }
  }
}

const absolutePositionCase = (name, rootTypeName, doc, position) => {
  const absolute = Y.createAbsolutePositionFromRelativePosition(position, doc)

  return {
    name,
    relativeJson: Y.relativePositionToJSON(position),
    relativeEncoded: toBase64(Y.encodeRelativePosition(position)),
    absolute: absolutePositionValue(absolute, rootTypeName)
  }
}

const deletedTargetReinsertCases = (kind, assocs) => assocs.map(assoc => {
  if (kind === 'text') {
    const doc = new Y.Doc({ guid: `absolute-text-delete-reinsert-${assoc}-doc`, gc: false })
    doc.clientID = 431 + assoc
    const text = doc.getText('content')
    text.insert(0, 'ABC')
    const position = Y.createRelativePositionFromTypeIndex(text, 1, assoc)
    text.delete(1, 1)
    text.insert(1, 'X')
    return absolutePositionCase(`text-deleted-target-reinsert-assoc-${assoc}`, 'content', doc, Y.decodeRelativePosition(Y.encodeRelativePosition(position)))
  }

  if (kind === 'array') {
    const doc = new Y.Doc({ guid: `absolute-array-delete-reinsert-${assoc}-doc`, gc: false })
    doc.clientID = 441 + assoc
    const array = doc.getArray('items')
    array.insert(0, ['A', 'B', 'C'])
    const position = Y.createRelativePositionFromTypeIndex(array, 1, assoc)
    array.delete(1, 1)
    array.insert(1, ['X'])
    return absolutePositionCase(`array-deleted-target-reinsert-assoc-${assoc}`, 'items', doc, Y.decodeRelativePosition(Y.encodeRelativePosition(position)))
  }

  if (kind === 'xml-fragment') {
    const doc = new Y.Doc({ guid: `absolute-xml-delete-reinsert-${assoc}-doc`, gc: false })
    doc.clientID = 451 + assoc
    const xml = doc.getXmlFragment('xml')
    xml.insert(0, [new Y.XmlElement('a'), new Y.XmlElement('b'), new Y.XmlElement('c')])
    const position = Y.createRelativePositionFromTypeIndex(xml, 1, assoc)
    xml.delete(1, 1)
    xml.insert(1, [new Y.XmlElement('x')])
    return absolutePositionCase(`xml-deleted-target-reinsert-assoc-${assoc}`, 'xml', doc, Y.decodeRelativePosition(Y.encodeRelativePosition(position)))
  }

  if (kind === 'nested-array') {
    const doc = new Y.Doc({ guid: `absolute-nested-array-delete-reinsert-${assoc}-doc`, gc: false })
    doc.clientID = 461 + assoc
    const array = new Y.Array()
    doc.getMap('map').set('items', array)
    array.insert(0, ['A', 'B', 'C'])
    const position = Y.createRelativePositionFromTypeIndex(array, 1, assoc)
    array.delete(1, 1)
    array.insert(1, ['X'])
    return absolutePositionCase(`nested-array-deleted-target-reinsert-assoc-${assoc}`, null, doc, Y.decodeRelativePosition(Y.encodeRelativePosition(position)))
  }

  if (kind === 'xml-element') {
    const doc = new Y.Doc({ guid: `absolute-xml-element-delete-reinsert-${assoc}-doc`, gc: false })
    doc.clientID = 471 + assoc
    const element = new Y.XmlElement('p')
    doc.getXmlFragment('xml').insert(0, [element])
    element.insert(0, [new Y.XmlElement('a'), new Y.XmlElement('b'), new Y.XmlElement('c')])
    const position = Y.createRelativePositionFromTypeIndex(element, 1, assoc)
    element.delete(1, 1)
    element.insert(1, [new Y.XmlElement('x')])
    return absolutePositionCase(`xml-element-deleted-target-reinsert-assoc-${assoc}`, null, doc, Y.decodeRelativePosition(Y.encodeRelativePosition(position)))
  }

  if (kind === 'nested-xml-fragment') {
    const doc = new Y.Doc({ guid: `absolute-nested-xml-fragment-delete-reinsert-${assoc}-doc`, gc: false })
    doc.clientID = 481 + assoc
    const fragment = new Y.XmlFragment()
    doc.getArray('items').insert(0, [fragment])
    fragment.insert(0, [new Y.XmlElement('a'), new Y.XmlElement('b'), new Y.XmlElement('c')])
    const position = Y.createRelativePositionFromTypeIndex(fragment, 1, assoc)
    fragment.delete(1, 1)
    fragment.insert(1, [new Y.XmlElement('x')])
    return absolutePositionCase(`nested-xml-fragment-deleted-target-reinsert-assoc-${assoc}`, null, doc, Y.decodeRelativePosition(Y.encodeRelativePosition(position)))
  }

  if (kind === 'map-xml-fragment') {
    const doc = new Y.Doc({ guid: `absolute-map-xml-fragment-delete-reinsert-${assoc}-doc`, gc: false })
    doc.clientID = 491 + assoc
    const fragment = new Y.XmlFragment()
    doc.getMap('map').set('xml', fragment)
    fragment.insert(0, [new Y.XmlElement('a'), new Y.XmlElement('b'), new Y.XmlElement('c')])
    const position = Y.createRelativePositionFromTypeIndex(fragment, 1, assoc)
    fragment.delete(1, 1)
    fragment.insert(1, [new Y.XmlElement('x')])
    return absolutePositionCase(`map-xml-fragment-deleted-target-reinsert-assoc-${assoc}`, null, doc, Y.decodeRelativePosition(Y.encodeRelativePosition(position)))
  }

  if (kind === 'xml-hook-text') {
    const doc = new Y.Doc({ guid: `absolute-xml-hook-text-delete-reinsert-${assoc}-doc`, gc: false })
    doc.clientID = 501 + assoc
    const hook = new Y.XmlHook('mention')
    doc.getXmlFragment('xml').insert(0, [hook])
    const text = new Y.Text()
    hook.set('body', text)
    text.insert(0, 'ABC')
    const position = Y.createRelativePositionFromTypeIndex(text, 1, assoc)
    text.delete(1, 1)
    text.insert(1, 'X')
    return absolutePositionCase(`xml-hook-text-deleted-target-reinsert-assoc-${assoc}`, null, doc, Y.decodeRelativePosition(Y.encodeRelativePosition(position)))
  }

  if (kind === 'xml-hook-xml-element') {
    const doc = new Y.Doc({ guid: `absolute-xml-hook-element-delete-reinsert-${assoc}-doc`, gc: false })
    doc.clientID = 511 + assoc
    const hook = new Y.XmlHook('mention')
    doc.getXmlFragment('xml').insert(0, [hook])
    const element = new Y.XmlElement('p')
    hook.set('element', element)
    element.insert(0, [new Y.XmlElement('a'), new Y.XmlElement('b'), new Y.XmlElement('c')])
    const position = Y.createRelativePositionFromTypeIndex(element, 1, assoc)
    element.delete(1, 1)
    element.insert(1, [new Y.XmlElement('x')])
    return absolutePositionCase(`xml-hook-xml-element-deleted-target-reinsert-assoc-${assoc}`, null, doc, Y.decodeRelativePosition(Y.encodeRelativePosition(position)))
  }

  if (kind === 'xml-hook-xml-fragment') {
    const doc = new Y.Doc({ guid: `absolute-xml-hook-fragment-delete-reinsert-${assoc}-doc`, gc: false })
    doc.clientID = 521 + assoc
    const hook = new Y.XmlHook('mention')
    doc.getXmlFragment('xml').insert(0, [hook])
    const fragment = new Y.XmlFragment()
    hook.set('fragment', fragment)
    fragment.insert(0, [new Y.XmlElement('a'), new Y.XmlElement('b'), new Y.XmlElement('c')])
    const position = Y.createRelativePositionFromTypeIndex(fragment, 1, assoc)
    fragment.delete(1, 1)
    fragment.insert(1, [new Y.XmlElement('x')])
    return absolutePositionCase(`xml-hook-xml-fragment-deleted-target-reinsert-assoc-${assoc}`, null, doc, Y.decodeRelativePosition(Y.encodeRelativePosition(position)))
  }

  if (kind === 'xml-text-attribute-text') {
    const doc = new Y.Doc({ guid: `absolute-xml-text-attribute-text-delete-reinsert-${assoc}-doc`, gc: false })
    doc.clientID = 531 + assoc
    const xmlText = new Y.XmlText()
    xmlText.insert(0, 'Xml')
    doc.getXmlFragment('xml').insert(0, [xmlText])
    const text = new Y.Text()
    xmlText.setAttribute('body', text)
    text.insert(0, 'ABC')
    const position = Y.createRelativePositionFromTypeIndex(text, 1, assoc)
    text.delete(1, 1)
    text.insert(1, 'X')
    return absolutePositionCase(`xml-text-attribute-text-deleted-target-reinsert-assoc-${assoc}`, null, doc, Y.decodeRelativePosition(Y.encodeRelativePosition(position)))
  }

  if (kind === 'xml-text-attribute-xml-element') {
    const doc = new Y.Doc({ guid: `absolute-xml-text-attribute-element-delete-reinsert-${assoc}-doc`, gc: false })
    doc.clientID = 541 + assoc
    const xmlText = new Y.XmlText()
    xmlText.insert(0, 'Xml')
    doc.getXmlFragment('xml').insert(0, [xmlText])
    const element = new Y.XmlElement('p')
    xmlText.setAttribute('element', element)
    element.insert(0, [new Y.XmlElement('a'), new Y.XmlElement('b'), new Y.XmlElement('c')])
    const position = Y.createRelativePositionFromTypeIndex(element, 1, assoc)
    element.delete(1, 1)
    element.insert(1, [new Y.XmlElement('x')])
    return absolutePositionCase(`xml-text-attribute-xml-element-deleted-target-reinsert-assoc-${assoc}`, null, doc, Y.decodeRelativePosition(Y.encodeRelativePosition(position)))
  }

  throw new Error(`Unknown deleted target reinsert kind: ${kind}`)
})

const relativePositionDoc = new Y.Doc({ guid: 'relative-position-doc', gc: false })
relativePositionDoc.clientID = 281
const relativePositionText = relativePositionDoc.getText('content')
relativePositionText.insert(0, 'Hello')

const relativePositionArrayDoc = new Y.Doc({ guid: 'relative-position-array-doc', gc: false })
relativePositionArrayDoc.clientID = 282
const relativePositionArray = relativePositionArrayDoc.getArray('items')
relativePositionArray.insert(0, ['A', 'B', 'C'])

const relativePositionXmlDoc = new Y.Doc({ guid: 'relative-position-xml-doc', gc: false })
relativePositionXmlDoc.clientID = 283
const relativePositionXml = relativePositionXmlDoc.getXmlFragment('xml')
const relativePositionXmlText = new Y.XmlText()
relativePositionXmlText.insert(0, 'A')
const relativePositionXmlElement = new Y.XmlElement('p')
relativePositionXml.insert(0, [relativePositionXmlText, relativePositionXmlElement])

const relativePositions = [
  Y.createRelativePositionFromJSON({ tname: 'content' }),
  Y.createRelativePositionFromJSON({ tname: 'content', assoc: -1 }),
  Y.createRelativePositionFromJSON({ item: { client: 42, clock: 7 }, assoc: 1 }),
  Y.createRelativePositionFromJSON({ type: { client: 99, clock: 3 } }),
  Y.createRelativePositionFromTypeIndex(relativePositionText, 2, -1),
  Y.createRelativePositionFromTypeIndex(relativePositionText, relativePositionText.length, 1)
]

const relativePositionFixtures = {
  cases: [
    relativePositionCase('root-type-name-end', relativePositions[0]),
    relativePositionCase('root-type-name-left-assoc', relativePositions[1]),
    relativePositionCase('item-id-position', relativePositions[2]),
    relativePositionCase('type-id-position', relativePositions[3]),
    relativePositionCase('text-index-inside-item', relativePositions[4]),
    relativePositionCase('text-index-end', relativePositions[5])
  ],
  compare: {
    sameObject: Y.compareRelativePositions(relativePositions[0], relativePositions[0]),
    sameJSON: Y.compareRelativePositions(relativePositions[0], Y.createRelativePositionFromJSON(Y.relativePositionToJSON(relativePositions[0]))),
    differentAssoc: Y.compareRelativePositions(relativePositions[0], relativePositions[1]),
    differentKind: Y.compareRelativePositions(relativePositions[0], relativePositions[2]),
    nullLeft: Y.compareRelativePositions(null, relativePositions[0]),
    nullBoth: Y.compareRelativePositions(null, null)
  },
  typeIndexCases: [
    relativePositionFromTypeIndexCase('text-start-left-assoc', relativePositionText, 0, -1),
    relativePositionFromTypeIndexCase('text-middle-default-assoc', relativePositionText, 2),
    relativePositionFromTypeIndexCase('text-end-default-assoc', relativePositionText, relativePositionText.length),
    relativePositionFromTypeIndexCase('text-end-left-assoc', relativePositionText, relativePositionText.length, -1),
    relativePositionFromTypeIndexCase('array-middle-default-assoc', relativePositionArray, 1),
    relativePositionFromTypeIndexCase('array-end-left-assoc', relativePositionArray, relativePositionArray.length, -1),
    relativePositionFromTypeIndexCase('xml-middle-default-assoc', relativePositionXml, 1),
    relativePositionFromTypeIndexCase('xml-end-left-assoc', relativePositionXml, relativePositionXml.length, -1)
  ],
  nestedTypeIndexCases: [
    (() => {
      const doc = new Y.Doc({ guid: 'relative-nested-text-doc', gc: false })
      doc.clientID = 301
      const text = new Y.Text()
      doc.getArray('items').insert(0, [text])
      text.insert(0, 'Hi')
      return relativePositionFromTypeIndexCase('nested-text-middle-default-assoc', text, 1)
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'relative-nested-array-doc', gc: false })
      doc.clientID = 302
      const array = new Y.Array()
      doc.getMap('map').set('items', array)
      array.insert(0, ['A', 'B'])
      return relativePositionFromTypeIndexCase('nested-array-end-left-assoc', array, array.length, -1)
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'relative-map-text-doc', gc: false })
      doc.clientID = 305
      const text = new Y.Text()
      doc.getMap('map').set('body', text)
      text.insert(0, 'Hi')
      return relativePositionFromTypeIndexCase('map-text-middle-default-assoc', text, 1)
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'relative-xml-text-doc', gc: false })
      doc.clientID = 303
      const xmlText = new Y.XmlText()
      doc.getXmlFragment('xml').insert(0, [xmlText])
      xmlText.insert(0, 'Hi')
      return relativePositionFromTypeIndexCase('xml-text-middle-default-assoc', xmlText, 1)
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'relative-xml-element-doc', gc: false })
      doc.clientID = 304
      const element = new Y.XmlElement('p')
      doc.getXmlFragment('xml').insert(0, [element])
      const text = new Y.XmlText()
      text.insert(0, 'A')
      const span = new Y.XmlElement('span')
      element.insert(0, [text, span])
      return relativePositionFromTypeIndexCase('xml-element-middle-default-assoc', element, 1)
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'relative-nested-xml-fragment-doc', gc: false })
      doc.clientID = 313
      const fragment = new Y.XmlFragment()
      const text = new Y.XmlText()
      text.insert(0, 'A')
      fragment.insert(0, [text, new Y.XmlElement('p')])
      doc.getArray('items').insert(0, [fragment])
      return relativePositionFromTypeIndexCase('nested-xml-fragment-middle-default-assoc', fragment, 1)
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'relative-map-xml-fragment-doc', gc: false })
      doc.clientID = 314
      const fragment = new Y.XmlFragment()
      const text = new Y.XmlText()
      text.insert(0, 'A')
      fragment.insert(0, [text, new Y.XmlElement('p')])
      doc.getMap('map').set('xml', fragment)
      return relativePositionFromTypeIndexCase('map-xml-fragment-middle-default-assoc', fragment, 1)
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'relative-xml-hook-text-doc', gc: false })
      doc.clientID = 317
      const hook = new Y.XmlHook('mention')
      doc.getXmlFragment('xml').insert(0, [hook])
      const text = new Y.Text()
      hook.set('body', text)
      text.insert(0, 'Hi')
      return relativePositionFromTypeIndexCase('xml-hook-text-middle-default-assoc', text, 1)
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'relative-xml-hook-array-doc', gc: false })
      doc.clientID = 318
      const hook = new Y.XmlHook('mention')
      doc.getXmlFragment('xml').insert(0, [hook])
      const array = new Y.Array()
      hook.set('items', array)
      array.insert(0, ['A', 'B'])
      return relativePositionFromTypeIndexCase('xml-hook-array-end-left-assoc', array, array.length, -1)
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'relative-xml-hook-element-doc', gc: false })
      doc.clientID = 319
      const hook = new Y.XmlHook('mention')
      doc.getXmlFragment('xml').insert(0, [hook])
      const element = new Y.XmlElement('p')
      hook.set('element', element)
      const text = new Y.XmlText()
      text.insert(0, 'A')
      element.insert(0, [text, new Y.XmlElement('span')])
      return relativePositionFromTypeIndexCase('xml-hook-xml-element-middle-default-assoc', element, 1)
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'relative-xml-hook-fragment-doc', gc: false })
      doc.clientID = 320
      const hook = new Y.XmlHook('mention')
      doc.getXmlFragment('xml').insert(0, [hook])
      const fragment = new Y.XmlFragment()
      hook.set('fragment', fragment)
      const text = new Y.XmlText()
      text.insert(0, 'A')
      fragment.insert(0, [text, new Y.XmlElement('p')])
      return relativePositionFromTypeIndexCase('xml-hook-xml-fragment-middle-default-assoc', fragment, 1)
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'relative-xml-element-attribute-text-doc', gc: false })
      doc.clientID = 325
      const element = new Y.XmlElement('p')
      doc.getXmlFragment('xml').insert(0, [element])
      const text = new Y.Text()
      element.setAttribute('body', text)
      text.insert(0, 'Hi')
      return relativePositionFromTypeIndexCase('xml-element-attribute-text-middle-default-assoc', text, 1)
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'relative-xml-element-attribute-element-doc', gc: false })
      doc.clientID = 326
      const element = new Y.XmlElement('p')
      doc.getXmlFragment('xml').insert(0, [element])
      const inline = new Y.XmlElement('span')
      element.setAttribute('inline', inline)
      const text = new Y.XmlText()
      text.insert(0, 'A')
      inline.insert(0, [text, new Y.XmlElement('em')])
      return relativePositionFromTypeIndexCase('xml-element-attribute-xml-element-middle-default-assoc', inline, 1)
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'relative-xml-text-attribute-text-doc', gc: false })
      doc.clientID = 327
      const xmlText = new Y.XmlText()
      xmlText.insert(0, 'Xml')
      doc.getXmlFragment('xml').insert(0, [xmlText])
      const text = new Y.Text()
      xmlText.setAttribute('body', text)
      text.insert(0, 'Hi')
      return relativePositionFromTypeIndexCase('xml-text-attribute-text-middle-default-assoc', text, 1)
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'relative-xml-text-attribute-element-doc', gc: false })
      doc.clientID = 328
      const xmlText = new Y.XmlText()
      xmlText.insert(0, 'Xml')
      doc.getXmlFragment('xml').insert(0, [xmlText])
      const inline = new Y.XmlElement('span')
      xmlText.setAttribute('inline', inline)
      const text = new Y.XmlText()
      text.insert(0, 'A')
      inline.insert(0, [text, new Y.XmlElement('em')])
      return relativePositionFromTypeIndexCase('xml-text-attribute-xml-element-middle-default-assoc', inline, 1)
    })()
  ],
  absoluteCases: [
    (() => {
      const doc = new Y.Doc({ guid: 'absolute-text-insert-before-doc', gc: false })
      doc.clientID = 291
      const text = doc.getText('content')
      text.insert(0, 'Hello')
      const position = Y.createRelativePositionFromTypeIndex(text, 2)
      text.insert(0, 'X')
      return absolutePositionCase('text-insert-before-item', 'content', doc, position)
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'absolute-text-end-doc', gc: false })
      doc.clientID = 292
      const text = doc.getText('content')
      text.insert(0, 'Hello')
      const position = Y.createRelativePositionFromTypeIndex(text, text.length)
      text.insert(text.length, '!')
      return absolutePositionCase('text-end-follows-insert', 'content', doc, position)
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'absolute-array-delete-target-doc', gc: false })
      doc.clientID = 293
      const array = doc.getArray('items')
      array.insert(0, ['A', 'B', 'C'])
      const position = Y.createRelativePositionFromTypeIndex(array, 1)
      array.delete(1, 1)
      return absolutePositionCase('array-deleted-target', 'items', doc, position)
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'absolute-xml-insert-before-doc', gc: false })
      doc.clientID = 294
      const xml = doc.getXmlFragment('xml')
      const text = new Y.XmlText()
      text.insert(0, 'A')
      const element = new Y.XmlElement('p')
      xml.insert(0, [text, element])
      const position = Y.createRelativePositionFromTypeIndex(xml, 1)
      const before = new Y.XmlText()
      before.insert(0, 'B')
      xml.insert(0, [before])
      return absolutePositionCase('xml-insert-before-item', 'xml', doc, position)
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'absolute-xml-hook-insert-before-doc', gc: false })
      doc.clientID = 315
      const xml = doc.getXmlFragment('xml')
      const text = new Y.XmlText()
      text.insert(0, 'A')
      const hook = new Y.XmlHook('mention')
      xml.insert(0, [text, hook])
      const position = Y.createRelativePositionFromTypeIndex(xml, 1)
      const before = new Y.XmlText()
      before.insert(0, 'B')
      xml.insert(1, [before])
      return absolutePositionCase('xml-hook-insert-before-item', 'xml', doc, position)
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'absolute-nested-text-insert-before-doc', gc: false })
      doc.clientID = 305
      const text = new Y.Text()
      doc.getArray('items').insert(0, [text])
      text.insert(0, 'Hi')
      const position = Y.createRelativePositionFromTypeIndex(text, 1)
      text.insert(0, 'X')
      return absolutePositionCase('nested-text-insert-before-item', null, doc, Y.decodeRelativePosition(Y.encodeRelativePosition(position)))
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'absolute-nested-array-delete-target-doc', gc: false })
      doc.clientID = 306
      const array = new Y.Array()
      doc.getMap('map').set('items', array)
      array.insert(0, ['A', 'B', 'C'])
      const position = Y.createRelativePositionFromTypeIndex(array, 1)
      array.delete(1, 1)
      return absolutePositionCase('nested-array-deleted-target', null, doc, Y.decodeRelativePosition(Y.encodeRelativePosition(position)))
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'absolute-map-text-insert-before-doc', gc: false })
      doc.clientID = 311
      const text = new Y.Text()
      doc.getMap('map').set('body', text)
      text.insert(0, 'Hi')
      const position = Y.createRelativePositionFromTypeIndex(text, 1)
      text.insert(0, 'X')
      return absolutePositionCase('map-text-insert-before-item', null, doc, Y.decodeRelativePosition(Y.encodeRelativePosition(position)))
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'absolute-xml-text-insert-before-doc', gc: false })
      doc.clientID = 307
      const xmlText = new Y.XmlText()
      doc.getXmlFragment('xml').insert(0, [xmlText])
      xmlText.insert(0, 'Hi')
      const position = Y.createRelativePositionFromTypeIndex(xmlText, 1)
      xmlText.insert(0, 'X')
      return absolutePositionCase('xml-text-insert-before-item', null, doc, Y.decodeRelativePosition(Y.encodeRelativePosition(position)))
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'absolute-xml-element-insert-before-doc', gc: false })
      doc.clientID = 308
      const element = new Y.XmlElement('p')
      doc.getXmlFragment('xml').insert(0, [element])
      const text = new Y.XmlText()
      text.insert(0, 'A')
      const span = new Y.XmlElement('span')
      element.insert(0, [text, span])
      const position = Y.createRelativePositionFromTypeIndex(element, 1)
      const before = new Y.XmlText()
      before.insert(0, 'B')
      element.insert(0, [before])
      return absolutePositionCase('xml-element-insert-before-item', null, doc, Y.decodeRelativePosition(Y.encodeRelativePosition(position)))
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'absolute-xml-element-hook-insert-before-doc', gc: false })
      doc.clientID = 316
      const element = new Y.XmlElement('p')
      doc.getXmlFragment('xml').insert(0, [element])
      const text = new Y.XmlText()
      text.insert(0, 'A')
      const hook = new Y.XmlHook('mention')
      element.insert(0, [text, hook])
      const position = Y.createRelativePositionFromTypeIndex(element, 1)
      const before = new Y.XmlText()
      before.insert(0, 'B')
      element.insert(1, [before])
      return absolutePositionCase('xml-element-hook-insert-before-item', null, doc, Y.decodeRelativePosition(Y.encodeRelativePosition(position)))
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'absolute-nested-xml-fragment-insert-before-doc', gc: false })
      doc.clientID = 313
      const fragment = new Y.XmlFragment()
      const text = new Y.XmlText()
      text.insert(0, 'A')
      fragment.insert(0, [text, new Y.XmlElement('p')])
      doc.getArray('items').insert(0, [fragment])
      const position = Y.createRelativePositionFromTypeIndex(fragment, 1)
      const before = new Y.XmlText()
      before.insert(0, 'B')
      fragment.insert(0, [before])
      return absolutePositionCase('nested-xml-fragment-insert-before-item', null, doc, Y.decodeRelativePosition(Y.encodeRelativePosition(position)))
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'absolute-map-xml-fragment-insert-before-doc', gc: false })
      doc.clientID = 314
      const fragment = new Y.XmlFragment()
      const text = new Y.XmlText()
      text.insert(0, 'A')
      fragment.insert(0, [text, new Y.XmlElement('p')])
      doc.getMap('map').set('xml', fragment)
      const position = Y.createRelativePositionFromTypeIndex(fragment, 1)
      const before = new Y.XmlText()
      before.insert(0, 'B')
      fragment.insert(0, [before])
      return absolutePositionCase('map-xml-fragment-insert-before-item', null, doc, Y.decodeRelativePosition(Y.encodeRelativePosition(position)))
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'absolute-xml-hook-text-insert-before-doc', gc: false })
      doc.clientID = 321
      const hook = new Y.XmlHook('mention')
      doc.getXmlFragment('xml').insert(0, [hook])
      const text = new Y.Text()
      hook.set('body', text)
      text.insert(0, 'Hi')
      const position = Y.createRelativePositionFromTypeIndex(text, 1)
      text.insert(0, 'X')
      return absolutePositionCase('xml-hook-text-insert-before-item', null, doc, Y.decodeRelativePosition(Y.encodeRelativePosition(position)))
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'absolute-xml-hook-array-delete-target-doc', gc: false })
      doc.clientID = 322
      const hook = new Y.XmlHook('mention')
      doc.getXmlFragment('xml').insert(0, [hook])
      const array = new Y.Array()
      hook.set('items', array)
      array.insert(0, ['A', 'B', 'C'])
      const position = Y.createRelativePositionFromTypeIndex(array, 1)
      array.delete(1, 1)
      return absolutePositionCase('xml-hook-array-deleted-target', null, doc, Y.decodeRelativePosition(Y.encodeRelativePosition(position)))
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'absolute-xml-hook-element-insert-before-doc', gc: false })
      doc.clientID = 323
      const hook = new Y.XmlHook('mention')
      doc.getXmlFragment('xml').insert(0, [hook])
      const element = new Y.XmlElement('p')
      hook.set('element', element)
      const text = new Y.XmlText()
      text.insert(0, 'A')
      element.insert(0, [text, new Y.XmlElement('span')])
      const position = Y.createRelativePositionFromTypeIndex(element, 1)
      const before = new Y.XmlText()
      before.insert(0, 'B')
      element.insert(0, [before])
      return absolutePositionCase('xml-hook-xml-element-insert-before-item', null, doc, Y.decodeRelativePosition(Y.encodeRelativePosition(position)))
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'absolute-xml-hook-fragment-insert-before-doc', gc: false })
      doc.clientID = 324
      const hook = new Y.XmlHook('mention')
      doc.getXmlFragment('xml').insert(0, [hook])
      const fragment = new Y.XmlFragment()
      hook.set('fragment', fragment)
      const text = new Y.XmlText()
      text.insert(0, 'A')
      fragment.insert(0, [text, new Y.XmlElement('p')])
      const position = Y.createRelativePositionFromTypeIndex(fragment, 1)
      const before = new Y.XmlText()
      before.insert(0, 'B')
      fragment.insert(0, [before])
      return absolutePositionCase('xml-hook-xml-fragment-insert-before-item', null, doc, Y.decodeRelativePosition(Y.encodeRelativePosition(position)))
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'absolute-xml-text-attribute-text-insert-before-doc', gc: false })
      doc.clientID = 329
      const xmlText = new Y.XmlText()
      xmlText.insert(0, 'Xml')
      doc.getXmlFragment('xml').insert(0, [xmlText])
      const text = new Y.Text()
      xmlText.setAttribute('body', text)
      text.insert(0, 'Hi')
      const position = Y.createRelativePositionFromTypeIndex(text, 1)
      text.insert(0, 'X')
      return absolutePositionCase('xml-text-attribute-text-insert-before-item', null, doc, Y.decodeRelativePosition(Y.encodeRelativePosition(position)))
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'absolute-xml-text-attribute-element-insert-before-doc', gc: false })
      doc.clientID = 330
      const xmlText = new Y.XmlText()
      xmlText.insert(0, 'Xml')
      doc.getXmlFragment('xml').insert(0, [xmlText])
      const inline = new Y.XmlElement('span')
      xmlText.setAttribute('inline', inline)
      const text = new Y.XmlText()
      text.insert(0, 'A')
      inline.insert(0, [text, new Y.XmlElement('em')])
      const position = Y.createRelativePositionFromTypeIndex(inline, 1)
      const before = new Y.XmlText()
      before.insert(0, 'B')
      inline.insert(0, [before])
      return absolutePositionCase('xml-text-attribute-xml-element-insert-before-item', null, doc, Y.decodeRelativePosition(Y.encodeRelativePosition(position)))
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'absolute-missing-item-doc', gc: false })
      doc.clientID = 309
      const position = Y.createRelativePositionFromJSON({ item: { client: 309, clock: 99 } })
      return absolutePositionCase('missing-item', null, doc, Y.decodeRelativePosition(Y.encodeRelativePosition(position)))
    })(),
    (() => {
      const doc = new Y.Doc({ guid: 'absolute-missing-type-doc', gc: false })
      doc.clientID = 310
      const position = Y.createRelativePositionFromJSON({ type: { client: 310, clock: 99 } })
      return absolutePositionCase('missing-type', null, doc, Y.decodeRelativePosition(Y.encodeRelativePosition(position)))
    })(),
    ...deletedTargetReinsertCases('text', [-1, 0, 1]),
    ...deletedTargetReinsertCases('array', [-1, 0, 1]),
    ...deletedTargetReinsertCases('xml-fragment', [-1, 0, 1]),
    ...deletedTargetReinsertCases('nested-array', [-1, 0, 1]),
    ...deletedTargetReinsertCases('xml-element', [-1, 0, 1]),
    ...deletedTargetReinsertCases('nested-xml-fragment', [-1, 0, 1]),
    ...deletedTargetReinsertCases('map-xml-fragment', [-1, 0, 1]),
    ...deletedTargetReinsertCases('xml-hook-text', [-1, 0, 1]),
    ...deletedTargetReinsertCases('xml-hook-xml-element', [-1, 0, 1]),
    ...deletedTargetReinsertCases('xml-hook-xml-fragment', [-1, 0, 1]),
    ...deletedTargetReinsertCases('xml-text-attribute-text', [-1, 0, 1]),
    ...deletedTargetReinsertCases('xml-text-attribute-xml-element', [-1, 0, 1])
  ]
}

fs.writeFileSync(
  path.join(outDir, 'relative-positions.json'),
  `${JSON.stringify(relativePositionFixtures, null, 2)}\n`
)

const waitForPermanentUserData = () => new Promise(resolve => setTimeout(resolve, 0))
const permanentUserDataDoc = new Y.Doc({ guid: 'permanent-user-data-doc', gc: false })
permanentUserDataDoc.clientID = 311
const permanentUserData = new Y.PermanentUserData(permanentUserDataDoc)
permanentUserData.setUserMapping(permanentUserDataDoc, 311, 'alice')
permanentUserDataDoc.getText('content').insert(0, 'ABCDE')
permanentUserDataDoc.getText('content').delete(1, 2)
await waitForPermanentUserData()
await waitForPermanentUserData()
const permanentUser = permanentUserDataDoc.getMap('users').get('alice')

const filteredPermanentUserDataDoc = new Y.Doc({ guid: 'permanent-user-data-filtered-doc', gc: false })
filteredPermanentUserDataDoc.clientID = 312
const filteredPermanentUserData = new Y.PermanentUserData(filteredPermanentUserDataDoc)
filteredPermanentUserData.setUserMapping(filteredPermanentUserDataDoc, 312, 'bob', { filter: () => false })
filteredPermanentUserDataDoc.getText('content').insert(0, 'AB')
filteredPermanentUserDataDoc.getText('content').delete(0, 1)
await waitForPermanentUserData()
await waitForPermanentUserData()
const filteredPermanentUser = filteredPermanentUserDataDoc.getMap('users').get('bob')

const permanentUserDataFixtures = {
  user: {
    clientID: 311,
    description: 'alice',
    updateV1: toBase64(Y.encodeStateAsUpdate(permanentUserDataDoc)),
    updateV2: toBase64(Y.encodeStateAsUpdateV2(permanentUserDataDoc)),
    ids: permanentUser.get('ids').toArray(),
    encodedDeleteSets: permanentUser.get('ds').toArray().map(toBase64),
    clientLookup: permanentUserData.getUserByClientId(311),
    deletedLookups: [
      { id: { client: 311, clock: 5 }, user: permanentUserData.getUserByDeletedId(Y.createID(311, 5)) },
      { id: { client: 311, clock: 6 }, user: permanentUserData.getUserByDeletedId(Y.createID(311, 6)) },
      { id: { client: 311, clock: 4 }, user: permanentUserData.getUserByDeletedId(Y.createID(311, 4)) },
      { id: { client: 311, clock: 7 }, user: permanentUserData.getUserByDeletedId(Y.createID(311, 7)) }
    ]
  },
  filtered: {
    clientID: 312,
    description: 'bob',
    ids: filteredPermanentUser.get('ids').toArray(),
    encodedDeleteSets: filteredPermanentUser.get('ds').toArray().map(toBase64),
    clientLookup: filteredPermanentUserData.getUserByClientId(312),
    deletedLookup: filteredPermanentUserData.getUserByDeletedId(Y.createID(312, 5))
  }
}

fs.writeFileSync(
  path.join(outDir, 'permanent-user-data.json'),
  `${JSON.stringify(permanentUserDataFixtures, null, 2)}\n`
)

const doc = new Y.Doc({ guid: 'fixture-doc', gc: false })
doc.clientID = 1
const text = doc.getText('content')
text.insert(0, 'Hello')
text.insert(5, ' Yjs', { bold: true })
text.delete(5, 1)

const docFixtures = {
  text: text.toString(),
  stateVectorV1: toBase64(Y.encodeStateVector(doc)),
  updateV1: toBase64(Y.encodeStateAsUpdate(doc)),
  updateV2: toBase64(Y.encodeStateAsUpdateV2(doc))
}

const updateV1Meta = Y.parseUpdateMeta(Y.encodeStateAsUpdate(doc))
docFixtures.updateV1Meta = {
  from: Object.fromEntries(updateV1Meta.from),
  to: Object.fromEntries(updateV1Meta.to)
}
const updateV2Meta = Y.parseUpdateMetaV2(Y.encodeStateAsUpdateV2(doc))
docFixtures.updateV2Meta = {
  from: Object.fromEntries(updateV2Meta.from),
  to: Object.fromEntries(updateV2Meta.to)
}

const pendingGapSource = new Y.Doc({ guid: 'pending-gap-source', gc: false })
pendingGapSource.clientID = 401
pendingGapSource.getText('content').insert(0, 'ABCD')
const pendingGapSuffixStateVector = stateVector([[401, 2]])
const pendingGapSuffixV1 = Y.encodeStateAsUpdate(pendingGapSource, pendingGapSuffixStateVector)
const pendingGapSuffixV2 = Y.encodeStateAsUpdateV2(pendingGapSource, pendingGapSuffixStateVector)
const pendingGapSuffixMetaV1 = Y.parseUpdateMeta(pendingGapSuffixV1)
const pendingGapSuffixMetaV2 = Y.parseUpdateMetaV2(pendingGapSuffixV2)
const pendingGapPrefixV1 = (() => {
  const prefix = new Y.Doc({ guid: 'pending-gap-prefix', gc: false })
  prefix.clientID = 401
  prefix.getText('content').insert(0, 'AB')
  return Y.encodeStateAsUpdate(prefix)
})()
const pendingGapPrefixV2 = (() => {
  const prefix = new Y.Doc({ guid: 'pending-gap-prefix-v2', gc: false })
  prefix.clientID = 401
  prefix.getText('content').insert(0, 'AB')
  return Y.encodeStateAsUpdateV2(prefix)
})()
const pendingGapPrefixMetaV1 = Y.parseUpdateMeta(pendingGapPrefixV1)
const pendingGapPrefixMetaV2 = Y.parseUpdateMetaV2(pendingGapPrefixV2)
const pendingGapDoc = new Y.Doc({ guid: 'pending-gap-doc', gc: false })
pendingGapDoc.getText('content')
Y.applyUpdate(pendingGapDoc, pendingGapSuffixV1)
const pendingGapStateVectorAfterSuffix = Y.encodeStateVector(pendingGapDoc)
Y.applyUpdate(pendingGapDoc, pendingGapPrefixV1)
docFixtures.pendingGap = {
  suffixUpdateV1: toBase64(pendingGapSuffixV1),
  suffixUpdateV2: toBase64(pendingGapSuffixV2),
  prefixUpdateV1: toBase64(pendingGapPrefixV1),
  prefixUpdateV2: toBase64(pendingGapPrefixV2),
  suffixMetaV1: {
    from: Object.fromEntries(pendingGapSuffixMetaV1.from),
    to: Object.fromEntries(pendingGapSuffixMetaV1.to)
  },
  suffixMetaV2: {
    from: Object.fromEntries(pendingGapSuffixMetaV2.from),
    to: Object.fromEntries(pendingGapSuffixMetaV2.to)
  },
  prefixMetaV1: {
    from: Object.fromEntries(pendingGapPrefixMetaV1.from),
    to: Object.fromEntries(pendingGapPrefixMetaV1.to)
  },
  prefixMetaV2: {
    from: Object.fromEntries(pendingGapPrefixMetaV2.from),
    to: Object.fromEntries(pendingGapPrefixMetaV2.to)
  },
  stateVectorAfterSuffixV1: toBase64(pendingGapStateVectorAfterSuffix),
  stateVectorAfterPrefixV1: toBase64(Y.encodeStateVector(pendingGapDoc)),
  json: pendingGapDoc.toJSON()
}

fs.writeFileSync(
  path.join(outDir, 'document-updates.json'),
  `${JSON.stringify(docFixtures, null, 2)}\n`
)

const updateFixtures = [
  updateCase('text-format-delete', doc => {
    doc.clientID = 1
    const text = doc.getText('content')
    text.insert(0, 'Hello')
    text.insert(5, ' Yjs', { bold: true })
    text.delete(5, 1)
  }),
  updateCase('array-primitives', doc => {
    doc.clientID = 2
    doc.getArray('array').insert(0, [1, 'two', true, null, { nested: ['x'] }])
  }),
  updateCase('array-binary', doc => {
    doc.clientID = 19
    doc.getArray('array').insert(0, [Uint8Array.from([1, 2, 255])])
  }),
  updateCase('array-subdoc', doc => {
    doc.clientID = 26
    doc.getArray('array').insert(0, [
      new Y.Doc({ guid: 'array-subdoc', meta: { kind: 'note' } })
    ])
  }),
  updateCase('array-delete-all-empty', doc => {
    doc.clientID = 23
    const array = doc.getArray('array')
    array.insert(0, [1, 2, 3])
    array.delete(0, 3)
  }),
  updateCase('gc-text-delete-content-deleted', doc => {
    doc.clientID = 21
    const text = doc.getText('content')
    text.insert(0, 'ABCD')
    text.delete(1, 2)
  }, { gc: true }),
  updateCase('gc-array-delete-content-deleted', doc => {
    doc.clientID = 22
    const array = doc.getArray('array')
    array.insert(0, ['A', 'B', 'C', 'D'])
    array.delete(1, 2)
  }, { gc: true }),
  updateCase('map-primitives-delete', doc => {
    doc.clientID = 3
    const map = doc.getMap('map')
    map.set('title', 'Hello')
    map.set('count', 3)
    map.delete('count')
  }),
  updateCase('map-replace-null-value', doc => {
    doc.clientID = 17
    const map = doc.getMap('map')
    map.set('title', null)
    map.set('title', 'Hello')
  }),
  updateCase('map-subdoc', doc => {
    doc.clientID = 27
    doc.getMap('map').set('subdoc', new Y.Doc({ guid: 'map-subdoc', autoLoad: true }))
  }),
  updateCase('unicode-text', doc => {
    doc.clientID = 4
    doc.getText('content').insert(0, 'A😀B')
  }),
  updateCase('array-embedded-shared-types', doc => {
    doc.clientID = 5
    doc.getArray('array').insert(0, [
      new Y.Array(),
      new Y.Map(),
      new Y.Text()
    ])
  }),
  updateCase('array-nested-shared-types-content', doc => {
    doc.clientID = 13
    const nestedArray = new Y.Array()
    nestedArray.insert(0, [1, 2])
    const nestedMap = new Y.Map()
    nestedMap.set('title', 'Nested')
    const nestedText = new Y.Text()
    nestedText.insert(0, 'Hi')
    doc.getArray('array').insert(0, [
      nestedArray,
      nestedMap,
      nestedText
    ])
  }),
  updateCase('array-nested-array-delete-all-empty', doc => {
    doc.clientID = 24
    const nestedArray = new Y.Array()
    nestedArray.insert(0, [1, 2, 3])
    doc.getArray('array').insert(0, [nestedArray])
    nestedArray.delete(0, 3)
  }),
  updateCase('map-nested-shared-types-content', doc => {
    doc.clientID = 14
    const nestedArray = new Y.Array()
    nestedArray.insert(0, ['A', 'B'])
    const nestedText = new Y.Text()
    nestedText.insert(0, 'Nested text')
    const map = doc.getMap('map')
    map.set('items', nestedArray)
    map.set('body', nestedText)
  }),
  updateCase('nested-map-replace-value', doc => {
    doc.clientID = 18
    const nestedMap = new Y.Map()
    doc.getArray('array').insert(0, [nestedMap])
    nestedMap.set('title', null)
    nestedMap.set('title', 'Nested')
  }),
  updateCase('xml-element-type', doc => {
    doc.clientID = 6
    doc.getXmlFragment('xml').insert(0, [
      new Y.XmlElement('Paragraph')
    ])
  }),
  updateCase('xml-text-type', doc => {
    doc.clientID = 7
    doc.getXmlFragment('xml').insert(0, [
      new Y.XmlText()
    ])
  }),
  updateCase('xml-hook-type', doc => {
    doc.clientID = 8
    doc.getXmlFragment('xml').insert(0, [
      new Y.XmlHook('mention')
    ])
  }),
  updateCase('xml-hook-shared-type-values', doc => {
    doc.clientID = 31
    const hook = new Y.XmlHook('mention')
    const body = new Y.Text()
    body.insert(0, 'Hook text')
    const meta = new Y.Map()
    meta.set('role', 'author')
    const items = new Y.Array()
    items.insert(0, ['A', 'B'])
    hook.set('body', body)
    hook.set('meta', meta)
    hook.set('items', items)
    doc.getXmlFragment('xml').insert(0, [hook])
  }, {
    inspect: doc => {
      const hook = doc.getXmlFragment('xml').get(0)
      return {
        hookJson: normalizeValue(hook.toJSON()),
        hookTextDelta: normalizeValue(hook.get('body').toDelta()),
        hookMapJson: normalizeValue(hook.get('meta').toJSON()),
        hookArrayJson: normalizeValue(hook.get('items').toJSON())
      }
    }
  }),
  updateCase('xml-hook-xml-shared-type-values', doc => {
    doc.clientID = 32
    const hook = new Y.XmlHook('mention')
    const element = new Y.XmlElement('p')
    const elementText = new Y.XmlText()
    const text = new Y.XmlText()
    const nestedHook = new Y.XmlHook('note')
    const fragment = new Y.XmlFragment()
    const fragmentText = new Y.XmlText()
    hook.set('element', element)
    hook.set('text', text)
    hook.set('hook', nestedHook)
    hook.set('fragment', fragment)
    doc.getXmlFragment('xml').insert(0, [hook])
    element.insert(0, [elementText])
    elementText.insert(0, 'Element')
    text.insert(0, 'Xml text')
    nestedHook.set('ok', true)
    fragment.insert(0, [fragmentText])
    fragmentText.insert(0, 'Frag')
  }, {
    inspect: doc => {
      const hook = doc.getXmlFragment('xml').get(0)
      return {
        hookJson: normalizeValue(hook.toJSON()),
        hookElementXml: hook.get('element').toString(),
        hookTextXml: hook.get('text').toString(),
        hookNestedHookJson: normalizeValue(hook.get('hook').toJSON()),
        hookFragmentXml: hook.get('fragment').toString()
      }
    }
  }),
  updateCase('xml-element-attribute-text', doc => {
    doc.clientID = 9
    const paragraph = new Y.XmlElement('p')
    const xmlText = new Y.XmlText()
    paragraph.setAttribute('class', 'lead')
    paragraph.insert(0, [xmlText])
    doc.getXmlFragment('xml').insert(0, [paragraph])
    xmlText.insert(0, 'Hello XML')
  }),
  updateCase('xml-text-formatting', doc => {
    doc.clientID = 25
    const paragraph = new Y.XmlElement('p')
    const xmlText = new Y.XmlText()
    paragraph.setAttribute('class', 'lead')
    paragraph.insert(0, [xmlText])
    doc.getXmlFragment('xml').insert(0, [paragraph])
    xmlText.insert(0, 'Hello')
    xmlText.format(1, 3, { bold: true })
    xmlText.insert(5, '!', { italic: true })
    xmlText.delete(5, 1)
    xmlText.insert(5, '?')
  }),
  updateCase('xml-special-character-rendering', doc => {
    doc.clientID = 28
    const paragraph = new Y.XmlElement('p')
    const xmlText = new Y.XmlText()
    paragraph.setAttribute('title', 'A&B "Q" <tag>')
    paragraph.insert(0, [xmlText])
    doc.getXmlFragment('xml').insert(0, [paragraph])
    xmlText.insert(0, 'A&B < C')
    xmlText.format(0, 3, { mark: 'x&y' })
  }),
  updateCase('xml-nested-order-delete', doc => {
    doc.clientID = 20
    const fragment = doc.getXmlFragment('xml')
    const tail = new Y.XmlText()
    tail.insert(0, 'tail')
    fragment.insert(0, [tail])
    const article = new Y.XmlElement('article')
    fragment.insert(0, [article])
    const b = new Y.XmlText()
    b.insert(0, 'B')
    article.insert(0, [b])
    const strong = new Y.XmlElement('strong')
    article.insert(0, [strong])
    const strongText = new Y.XmlText()
    strongText.insert(0, 'A')
    strong.insert(0, [strongText])
    const c = new Y.XmlText()
    c.insert(0, 'C')
    article.insert(2, [c])
    article.delete(1, 1)
    fragment.delete(1, 1)
  })
]

const decodedOnlyUpdateFixtures = [
  decodedOnlyUpdateCase('array-special-number-content-any', doc => {
    doc.clientID = 29
    doc.getArray('array').insert(0, [
      Number.NaN,
      Infinity,
      -Infinity,
      { nested: Number.NaN }
    ])
  }),
  decodedOnlyUpdateCase('map-undefined-null-content-any', doc => {
    doc.clientID = 30
    const map = doc.getMap('map')
    map.set('u', undefined)
    map.set('n', null)
  })
]

fs.writeFileSync(
  path.join(outDir, 'updates-v1.json'),
  `${JSON.stringify({ cases: updateFixtures, decodedOnlyCases: decodedOnlyUpdateFixtures }, null, 2)}\n`
)

const incrementalCase = (name, clientID, transact) => {
  const doc = new Y.Doc({ guid: `${name}-doc`, gc: false })
  doc.clientID = clientID
  const updatesV1 = []
  const updatesV2 = []
  doc.on('update', update => updatesV1.push(update))
  doc.on('updateV2', update => updatesV2.push(update))
  transact(doc)

  return {
    name,
    updatesV1: updatesV1.map(toBase64),
    updatesV2: updatesV2.map(toBase64),
    finalStateVectorV1: toBase64(Y.encodeStateVector(doc)),
    finalUpdateV1: toBase64(Y.encodeStateAsUpdate(doc)),
    finalUpdateV2: toBase64(Y.encodeStateAsUpdateV2(doc)),
    json: doc.toJSON()
  }
}

const incrementalFixtures = [
  incrementalCase('text-incremental-insert-delete', 10, doc => {
    const text = doc.getText('content')
    text.insert(0, 'Hello')
    text.insert(5, ' Yjs')
    text.delete(5, 1)
  }),
  incrementalCase('map-incremental-set-delete', 11, doc => {
    const map = doc.getMap('map')
    map.set('title', 'Hello')
    map.set('count', 3)
    map.delete('count')
  }),
  incrementalCase('map-incremental-replace-null', 19, doc => {
    const map = doc.getMap('map')
    map.set('title', null)
    map.set('title', 'Hello')
  }),
  incrementalCase('array-incremental-inserts', 12, doc => {
    const array = doc.getArray('array')
    array.insert(0, [1])
    array.insert(1, ['two', true])
    array.insert(3, [{ nested: ['x'] }])
  }),
  incrementalCase('array-nested-shared-types-incremental', 15, doc => {
    const nestedArray = new Y.Array()
    const nestedMap = new Y.Map()
    const nestedText = new Y.Text()
    const array = doc.getArray('array')
    array.insert(0, [nestedArray, nestedMap, nestedText])
    nestedArray.insert(0, [1])
    nestedArray.insert(1, [2])
    nestedMap.set('title', 'Nested')
    nestedText.insert(0, 'Hi')
  }),
  incrementalCase('map-nested-shared-types-incremental', 16, doc => {
    const nestedArray = new Y.Array()
    const nestedText = new Y.Text()
    const map = doc.getMap('map')
    map.set('items', nestedArray)
    map.set('body', nestedText)
    nestedArray.insert(0, ['A'])
    nestedArray.insert(1, ['B'])
    nestedText.insert(0, 'Nested text')
  })
]

fs.writeFileSync(
  path.join(outDir, 'incremental-v1.json'),
  `${JSON.stringify({ cases: incrementalFixtures }, null, 2)}\n`
)

const concurrentCase = (name, type, buildLeft, buildRight) => {
  const left = new Y.Doc({ guid: `${name}-left`, gc: false })
  left.clientID = 1
  const right = new Y.Doc({ guid: `${name}-right`, gc: false })
  right.clientID = 2
  buildLeft(left)
  buildRight(right)

  const leftUpdate = Y.encodeStateAsUpdate(left)
  const rightUpdate = Y.encodeStateAsUpdate(right)
  const leftUpdateV2 = Y.encodeStateAsUpdateV2(left)
  const rightUpdateV2 = Y.encodeStateAsUpdateV2(right)
  const merged = new Y.Doc({ guid: `${name}-merged`, gc: false })
  if (type === 'text') {
    merged.getText('content')
  } else if (type === 'array') {
    merged.getArray('array')
  } else if (type === 'map') {
    merged.getMap('map')
  }
  Y.applyUpdate(merged, rightUpdate)
  Y.applyUpdate(merged, leftUpdate)

  return {
    name,
    updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate)],
    updatesV2: [toBase64(rightUpdateV2), toBase64(leftUpdateV2)],
    json: merged.toJSON()
  }
}

const concurrentFixtures = [
  concurrentCase(
    'text-concurrent-root-inserts',
    'text',
    doc => doc.getText('content').insert(0, 'A'),
    doc => doc.getText('content').insert(0, 'B')
  ),
  concurrentCase(
    'array-concurrent-root-inserts',
    'array',
    doc => doc.getArray('array').insert(0, ['A']),
    doc => doc.getArray('array').insert(0, ['B'])
  ),
  concurrentCase(
    'map-concurrent-distinct-keys',
    'map',
    doc => doc.getMap('map').set('a', 1),
    doc => doc.getMap('map').set('b', 2)
  ),
  concurrentCase(
    'map-concurrent-same-key',
    'map',
    doc => doc.getMap('map').set('title', 'A'),
    doc => doc.getMap('map').set('title', 'B')
  ),
  (() => {
    const base = new Y.Doc({ guid: 'text-concurrent-root-attribute-same-key-base', gc: false })
    base.clientID = 99
    const baseText = base.getText('content')
    baseText.insert(0, 'Text')
    baseText.setAttribute('lang', 'base')
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'text-concurrent-root-attribute-same-key-left', gc: false })
    left.clientID = 1
    left.getText('content')
    Y.applyUpdate(left, baseUpdate)
    left.getText('content').setAttribute('lang', 'left')

    const right = new Y.Doc({ guid: 'text-concurrent-root-attribute-same-key-right', gc: false })
    right.clientID = 2
    right.getText('content')
    Y.applyUpdate(right, baseUpdate)
    right.getText('content').setAttribute('lang', 'right')

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'text-concurrent-root-attribute-same-key-merged', gc: false })
    const mergedText = merged.getText('content')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'text-concurrent-root-attribute-same-key',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON(),
      textAttributes: normalizeValue(mergedText.getAttributes())
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'array-nested-text-concurrent-attribute-same-key-base', gc: false })
    base.clientID = 99
    const baseText = new Y.Text()
    base.getArray('array').insert(0, [baseText])
    baseText.insert(0, 'Nested')
    baseText.setAttribute('lang', 'base')
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'array-nested-text-concurrent-attribute-same-key-left', gc: false })
    left.clientID = 1
    left.getArray('array')
    Y.applyUpdate(left, baseUpdate)
    left.getArray('array').get(0).setAttribute('lang', 'left')

    const right = new Y.Doc({ guid: 'array-nested-text-concurrent-attribute-same-key-right', gc: false })
    right.clientID = 2
    right.getArray('array')
    Y.applyUpdate(right, baseUpdate)
    right.getArray('array').get(0).setAttribute('lang', 'right')

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'array-nested-text-concurrent-attribute-same-key-merged', gc: false })
    merged.getArray('array')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)
    const mergedNestedText = merged.getArray('array').get(0)

    return {
      name: 'array-nested-text-concurrent-attribute-same-key',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON(),
      nestedTextIdKey: '99:0',
      nestedTextAttributes: normalizeValue(mergedNestedText.getAttributes())
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'text-concurrent-after-origin-base', gc: false })
    base.clientID = 99
    base.getText('content').insert(0, 'X')
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'text-concurrent-after-origin-left', gc: false })
    left.clientID = 1
    left.getText('content')
    Y.applyUpdate(left, baseUpdate)
    left.getText('content').insert(1, 'A')

    const right = new Y.Doc({ guid: 'text-concurrent-after-origin-right', gc: false })
    right.clientID = 2
    right.getText('content')
    Y.applyUpdate(right, baseUpdate)
    right.getText('content').insert(1, 'B')

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'text-concurrent-after-origin-merged', gc: false })
    merged.getText('content')
    Y.applyUpdate(merged, baseUpdate)
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)

    return {
      name: 'text-concurrent-after-same-origin',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'text-concurrent-three-way-after-origin-base', gc: false })
    base.clientID = 99
    base.getText('content').insert(0, 'X')
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const makeReplica = (clientID, value) => {
      const doc = new Y.Doc({ guid: `text-concurrent-three-way-after-origin-${clientID}`, gc: false })
      doc.clientID = clientID
      doc.getText('content')
      Y.applyUpdate(doc, baseUpdate)
      doc.getText('content').insert(1, value)
      return doc
    }

    const first = makeReplica(1, 'A')
    const second = makeReplica(2, 'B')
    const third = makeReplica(3, 'C')
    const firstUpdate = Y.encodeStateAsUpdate(first, baseStateVector)
    const secondUpdate = Y.encodeStateAsUpdate(second, baseStateVector)
    const thirdUpdate = Y.encodeStateAsUpdate(third, baseStateVector)
    const merged = new Y.Doc({ guid: 'text-concurrent-three-way-after-origin-merged', gc: false })
    merged.getText('content')
    Y.applyUpdate(merged, thirdUpdate)
    Y.applyUpdate(merged, firstUpdate)
    Y.applyUpdate(merged, baseUpdate)
    Y.applyUpdate(merged, secondUpdate)

    return {
      name: 'text-concurrent-three-way-after-same-origin',
      updatesV1: [toBase64(thirdUpdate), toBase64(firstUpdate), toBase64(baseUpdate), toBase64(secondUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(third, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(first, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base)),
        toBase64(Y.encodeStateAsUpdateV2(second, baseStateVector))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'text-concurrent-between-neighbors-base', gc: false })
    base.clientID = 99
    base.getText('content').insert(0, 'XY')
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'text-concurrent-between-neighbors-left', gc: false })
    left.clientID = 1
    left.getText('content')
    Y.applyUpdate(left, baseUpdate)
    left.getText('content').insert(1, 'A')

    const right = new Y.Doc({ guid: 'text-concurrent-between-neighbors-right', gc: false })
    right.clientID = 2
    right.getText('content')
    Y.applyUpdate(right, baseUpdate)
    right.getText('content').insert(1, 'B')

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'text-concurrent-between-neighbors-merged', gc: false })
    merged.getText('content')
    Y.applyUpdate(merged, baseUpdate)
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)

    return {
      name: 'text-concurrent-between-same-neighbors',
      updatesV1: [toBase64(baseUpdate), toBase64(rightUpdate), toBase64(leftUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(base)),
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'text-concurrent-keeps-item-content-contiguous-base', gc: false })
    base.clientID = 90
    base.getText('content').insert(0, 'ABC')
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'text-concurrent-keeps-item-content-contiguous-left', gc: false })
    left.clientID = 1
    left.getText('content')
    Y.applyUpdate(left, baseUpdate)
    left.getText('content').insert(2, 'aXY')

    const right = new Y.Doc({ guid: 'text-concurrent-keeps-item-content-contiguous-right', gc: false })
    right.clientID = 2
    right.getText('content')
    Y.applyUpdate(right, baseUpdate)
    right.getText('content').insert(0, 'a')
    right.getText('content').insert(3, '😀')

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'text-concurrent-keeps-item-content-contiguous-merged', gc: false })
    merged.getText('content')
    Y.applyUpdate(merged, baseUpdate)
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)

    return {
      name: 'text-concurrent-keeps-item-content-contiguous',
      updatesV1: [toBase64(baseUpdate), toBase64(rightUpdate), toBase64(leftUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(base)),
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'text-insert-splits-surrogate-pair-base', gc: false })
    base.clientID = 91
    base.getText('content').insert(0, 'A😀BC')
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const split = new Y.Doc({ guid: 'text-insert-splits-surrogate-pair-split', gc: false })
    split.clientID = 1
    split.getText('content')
    Y.applyUpdate(split, baseUpdate)
    split.getText('content').insert(2, 'XY')

    const splitUpdate = Y.encodeStateAsUpdate(split, baseStateVector)
    const merged = new Y.Doc({ guid: 'text-insert-splits-surrogate-pair-merged', gc: false })
    merged.getText('content')
    Y.applyUpdate(merged, splitUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'text-insert-splits-surrogate-pair',
      updatesV1: [toBase64(splitUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(split, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const source = new Y.Doc({ guid: 'text-overlapping-full-then-diff-source', gc: false })
    source.clientID = 302
    source.getText('content').insert(0, 'ABCD')
    const fullUpdate = Y.encodeStateAsUpdate(source)
    const fullUpdateV2 = Y.encodeStateAsUpdateV2(source)
    const partialStateVector = (() => {
      const encoder = encoding.createEncoder()
      encoding.writeVarUint(encoder, 1)
      encoding.writeVarUint(encoder, 302)
      encoding.writeVarUint(encoder, 2)
      return encoding.toUint8Array(encoder)
    })()
    const diffUpdate = Y.encodeStateAsUpdate(source, partialStateVector)
    const diffUpdateV2 = Y.encodeStateAsUpdateV2(source, partialStateVector)
    const merged = new Y.Doc({ guid: 'text-overlapping-full-then-diff-merged', gc: false })
    merged.getText('content')
    Y.applyUpdate(merged, fullUpdate)
    Y.applyUpdate(merged, diffUpdate)

    return {
      name: 'text-overlapping-full-then-diff',
      updatesV1: [toBase64(fullUpdate), toBase64(diffUpdate)],
      updatesV2: [toBase64(fullUpdateV2), toBase64(diffUpdateV2)],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const source = new Y.Doc({ guid: 'text-overlapping-diff-then-full-source', gc: false })
    source.clientID = 303
    source.getText('content').insert(0, 'ABCD')
    const fullUpdate = Y.encodeStateAsUpdate(source)
    const fullUpdateV2 = Y.encodeStateAsUpdateV2(source)
    const partialStateVector = (() => {
      const encoder = encoding.createEncoder()
      encoding.writeVarUint(encoder, 1)
      encoding.writeVarUint(encoder, 303)
      encoding.writeVarUint(encoder, 2)
      return encoding.toUint8Array(encoder)
    })()
    const diffUpdate = Y.encodeStateAsUpdate(source, partialStateVector)
    const diffUpdateV2 = Y.encodeStateAsUpdateV2(source, partialStateVector)
    const merged = new Y.Doc({ guid: 'text-overlapping-diff-then-full-merged', gc: false })
    merged.getText('content')
    Y.applyUpdate(merged, diffUpdate)
    Y.applyUpdate(merged, fullUpdate)

    return {
      name: 'text-overlapping-diff-then-full',
      updatesV1: [toBase64(diffUpdate), toBase64(fullUpdate)],
      updatesV2: [toBase64(diffUpdateV2), toBase64(fullUpdateV2)],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'array-nested-concurrent-out-of-order-base', gc: false })
    base.clientID = 99
    const nested = new Y.Array()
    base.getArray('array').insert(0, [nested])
    nested.insert(0, ['X'])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'array-nested-concurrent-out-of-order-left', gc: false })
    left.clientID = 1
    left.getArray('array')
    Y.applyUpdate(left, baseUpdate)
    left.getArray('array').get(0).insert(1, ['A'])

    const right = new Y.Doc({ guid: 'array-nested-concurrent-out-of-order-right', gc: false })
    right.clientID = 2
    right.getArray('array')
    Y.applyUpdate(right, baseUpdate)
    right.getArray('array').get(0).insert(1, ['B'])

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'array-nested-concurrent-out-of-order-merged', gc: false })
    merged.getArray('array')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'array-nested-concurrent-out-of-order',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'array-nested-text-concurrent-edits-base', gc: false })
    base.clientID = 99
    const nested = new Y.Text()
    base.getArray('array').insert(0, [nested])
    nested.insert(0, 'XY')
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'array-nested-text-concurrent-edits-left', gc: false })
    left.clientID = 1
    left.getArray('array')
    Y.applyUpdate(left, baseUpdate)
    left.getArray('array').get(0).insert(1, 'A')
    left.getArray('array').get(0).format(0, 2, { bold: true })

    const right = new Y.Doc({ guid: 'array-nested-text-concurrent-edits-right', gc: false })
    right.clientID = 2
    right.getArray('array')
    Y.applyUpdate(right, baseUpdate)
    right.getArray('array').get(0).delete(0, 1)
    right.getArray('array').get(0).insert(1, 'B', { italic: true })

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'array-nested-text-concurrent-edits-merged', gc: false })
    merged.getArray('array')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'array-nested-text-concurrent-edits',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'array-concurrent-overlapping-deletes-empty-base', gc: false })
    base.clientID = 404
    base.getArray('array').insert(0, [1, 2, 3])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'array-concurrent-overlapping-deletes-empty-left', gc: false })
    left.clientID = 1
    left.getArray('array')
    Y.applyUpdate(left, baseUpdate)
    left.getArray('array').insert(1, [20])
    left.getArray('array').delete(0, 3)

    const right = new Y.Doc({ guid: 'array-concurrent-overlapping-deletes-empty-right', gc: false })
    right.clientID = 2
    right.getArray('array')
    Y.applyUpdate(right, baseUpdate)
    right.getArray('array').delete(1, 2)

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'array-concurrent-overlapping-deletes-empty-merged', gc: false })
    merged.getArray('array')
    Y.applyUpdate(merged, baseUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, rightUpdate)

    return {
      name: 'array-concurrent-overlapping-deletes-empty',
      updatesV1: [toBase64(baseUpdate), toBase64(leftUpdate), toBase64(rightUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(base)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'array-concurrent-replace-same-deleted-item-base', gc: false })
    base.clientID = 92
    base.getArray('array').insert(0, ['base'])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'array-concurrent-replace-same-deleted-item-left', gc: false })
    left.clientID = 1
    left.getArray('array')
    Y.applyUpdate(left, baseUpdate)
    left.getArray('array').delete(0, 1)
    left.getArray('array').insert(0, ['L'])

    const right = new Y.Doc({ guid: 'array-concurrent-replace-same-deleted-item-right', gc: false })
    right.clientID = 2
    right.getArray('array')
    Y.applyUpdate(right, baseUpdate)
    right.getArray('array').delete(0, 1)
    right.getArray('array').insert(0, ['R'])

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'array-concurrent-replace-same-deleted-item-merged', gc: false })
    merged.getArray('array')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'array-concurrent-replace-same-deleted-item',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'text-concurrent-insert-after-deleted-origin-base', gc: false })
    base.clientID = 99
    base.getText('content').insert(0, 'XY')
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'text-concurrent-insert-after-deleted-origin-left', gc: false })
    left.clientID = 1
    left.getText('content')
    Y.applyUpdate(left, baseUpdate)
    left.getText('content').delete(0, 1)

    const right = new Y.Doc({ guid: 'text-concurrent-insert-after-deleted-origin-right', gc: false })
    right.clientID = 2
    right.getText('content')
    Y.applyUpdate(right, baseUpdate)
    right.getText('content').insert(1, 'A')

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'text-concurrent-insert-after-deleted-origin-merged', gc: false })
    merged.getText('content')
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'text-concurrent-insert-after-deleted-origin',
      updatesV1: [toBase64(leftUpdate), toBase64(rightUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'text-concurrent-insert-before-deleted-right-origin-base', gc: false })
    base.clientID = 99
    base.getText('content').insert(0, 'XY')
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'text-concurrent-insert-before-deleted-right-origin-left', gc: false })
    left.clientID = 1
    left.getText('content')
    Y.applyUpdate(left, baseUpdate)
    left.getText('content').delete(1, 1)

    const right = new Y.Doc({ guid: 'text-concurrent-insert-before-deleted-right-origin-right', gc: false })
    right.clientID = 2
    right.getText('content')
    Y.applyUpdate(right, baseUpdate)
    right.getText('content').insert(1, 'A')

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'text-concurrent-insert-before-deleted-right-origin-merged', gc: false })
    merged.getText('content')
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'text-concurrent-insert-before-deleted-right-origin',
      updatesV1: [toBase64(leftUpdate), toBase64(rightUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'text-three-way-insert-delete-deleted-origin-base', gc: false })
    base.clientID = 99
    base.getText('content').insert(0, 'X')
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const makeInsertReplica = (clientID, value) => {
      const doc = new Y.Doc({ guid: `text-three-way-insert-delete-deleted-origin-${clientID}`, gc: false })
      doc.clientID = clientID
      doc.getText('content')
      Y.applyUpdate(doc, baseUpdate)
      doc.getText('content').insert(1, value)
      return doc
    }

    const first = makeInsertReplica(1, 'A')
    const second = makeInsertReplica(2, 'B')
    const third = new Y.Doc({ guid: 'text-three-way-insert-delete-deleted-origin-3', gc: false })
    third.clientID = 3
    third.getText('content')
    Y.applyUpdate(third, baseUpdate)
    third.getText('content').delete(0, 1)
    third.getText('content').insert(0, 'C')

    const firstUpdate = Y.encodeStateAsUpdate(first, baseStateVector)
    const secondUpdate = Y.encodeStateAsUpdate(second, baseStateVector)
    const thirdUpdate = Y.encodeStateAsUpdate(third, baseStateVector)
    const merged = new Y.Doc({ guid: 'text-three-way-insert-delete-deleted-origin-merged', gc: false })
    merged.getText('content')
    Y.applyUpdate(merged, thirdUpdate)
    Y.applyUpdate(merged, secondUpdate)
    Y.applyUpdate(merged, firstUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'text-three-way-insert-delete-deleted-origin',
      updatesV1: [toBase64(thirdUpdate), toBase64(secondUpdate), toBase64(firstUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(third, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(second, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(first, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'array-concurrent-insert-after-deleted-origin-base', gc: false })
    base.clientID = 99
    base.getArray('array').insert(0, ['X', 'Y'])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'array-concurrent-insert-after-deleted-origin-left', gc: false })
    left.clientID = 1
    left.getArray('array')
    Y.applyUpdate(left, baseUpdate)
    left.getArray('array').delete(0, 1)

    const right = new Y.Doc({ guid: 'array-concurrent-insert-after-deleted-origin-right', gc: false })
    right.clientID = 2
    right.getArray('array')
    Y.applyUpdate(right, baseUpdate)
    right.getArray('array').insert(1, ['A'])

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'array-concurrent-insert-after-deleted-origin-merged', gc: false })
    merged.getArray('array')
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'array-concurrent-insert-after-deleted-origin',
      updatesV1: [toBase64(leftUpdate), toBase64(rightUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'text-three-way-concurrent-after-origin-base', gc: false })
    base.clientID = 99
    base.getText('content').insert(0, 'X')
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const makeReplica = (clientID, value) => {
      const doc = new Y.Doc({ guid: `text-three-way-concurrent-after-origin-${clientID}`, gc: false })
      doc.clientID = clientID
      doc.getText('content')
      Y.applyUpdate(doc, baseUpdate)
      doc.getText('content').insert(1, value)
      return doc
    }

    const first = makeReplica(1, 'A')
    const second = makeReplica(2, 'B')
    const third = makeReplica(3, 'C')
    const firstUpdate = Y.encodeStateAsUpdate(first, baseStateVector)
    const secondUpdate = Y.encodeStateAsUpdate(second, baseStateVector)
    const thirdUpdate = Y.encodeStateAsUpdate(third, baseStateVector)
    const merged = new Y.Doc({ guid: 'text-three-way-concurrent-after-origin-merged', gc: false })
    merged.getText('content')
    Y.applyUpdate(merged, thirdUpdate)
    Y.applyUpdate(merged, secondUpdate)
    Y.applyUpdate(merged, firstUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'text-three-way-concurrent-after-origin',
      updatesV1: [toBase64(thirdUpdate), toBase64(secondUpdate), toBase64(firstUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(third, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(second, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(first, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'text-three-way-concurrent-between-same-neighbors-base', gc: false })
    base.clientID = 99
    base.getText('content').insert(0, 'XY')
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const makeReplica = (clientID, value) => {
      const doc = new Y.Doc({ guid: `text-three-way-concurrent-between-same-neighbors-${clientID}`, gc: false })
      doc.clientID = clientID
      doc.getText('content')
      Y.applyUpdate(doc, baseUpdate)
      doc.getText('content').insert(1, value)
      return doc
    }

    const first = makeReplica(1, 'A')
    const second = makeReplica(2, 'B')
    const third = makeReplica(3, 'C')
    const firstUpdate = Y.encodeStateAsUpdate(first, baseStateVector)
    const secondUpdate = Y.encodeStateAsUpdate(second, baseStateVector)
    const thirdUpdate = Y.encodeStateAsUpdate(third, baseStateVector)
    const merged = new Y.Doc({ guid: 'text-three-way-concurrent-between-same-neighbors-merged', gc: false })
    merged.getText('content')
    Y.applyUpdate(merged, thirdUpdate)
    Y.applyUpdate(merged, secondUpdate)
    Y.applyUpdate(merged, firstUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'text-three-way-concurrent-between-same-neighbors',
      updatesV1: [toBase64(thirdUpdate), toBase64(secondUpdate), toBase64(firstUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(third, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(second, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(first, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'xml-concurrent-children-same-origin-base', gc: false })
    base.clientID = 99
    const root = new Y.XmlElement('root')
    const rootText = new Y.XmlText()
    rootText.insert(0, 'X')
    root.insert(0, [rootText])
    base.getXmlFragment('xml').insert(0, [root])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const makeReplica = (clientID, name, value) => {
      const doc = new Y.Doc({ guid: `xml-concurrent-children-same-origin-${clientID}`, gc: false })
      doc.clientID = clientID
      doc.getXmlFragment('xml')
      Y.applyUpdate(doc, baseUpdate)
      const element = new Y.XmlElement(name)
      const text = new Y.XmlText()
      text.insert(0, value)
      element.insert(0, [text])
      doc.getXmlFragment('xml').get(0).insert(1, [element])
      return doc
    }

    const left = makeReplica(1, 'left', 'A')
    const right = makeReplica(2, 'right', 'B')
    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'xml-concurrent-children-same-origin-merged', gc: false })
    merged.getXmlFragment('xml')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'xml-concurrent-children-same-origin',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'xml-text-concurrent-format-delete-base', gc: false })
    base.clientID = 99
    const paragraph = new Y.XmlElement('p')
    const text = new Y.XmlText()
    text.insert(0, 'XY')
    paragraph.insert(0, [text])
    base.getXmlFragment('xml').insert(0, [paragraph])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'xml-text-concurrent-format-delete-left', gc: false })
    left.clientID = 1
    left.getXmlFragment('xml')
    Y.applyUpdate(left, baseUpdate)
    const leftText = left.getXmlFragment('xml').get(0).get(0)
    leftText.insert(1, 'A')
    leftText.format(0, 2, { bold: true })

    const right = new Y.Doc({ guid: 'xml-text-concurrent-format-delete-right', gc: false })
    right.clientID = 2
    right.getXmlFragment('xml')
    Y.applyUpdate(right, baseUpdate)
    const rightText = right.getXmlFragment('xml').get(0).get(0)
    rightText.delete(0, 1)
    rightText.insert(1, 'B', { italic: true })

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'xml-text-concurrent-format-delete-merged', gc: false })
    merged.getXmlFragment('xml')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'xml-text-concurrent-format-delete',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON(),
      xmlTextDelta: normalizeValue(merged.getXmlFragment('xml').get(0).get(0).toDelta())
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'xml-text-concurrent-insert-after-deleted-origin-base', gc: false })
    base.clientID = 99
    const paragraph = new Y.XmlElement('p')
    const text = new Y.XmlText()
    text.insert(0, 'XY')
    paragraph.insert(0, [text])
    base.getXmlFragment('xml').insert(0, [paragraph])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const makeInsertReplica = (clientID, value) => {
      const doc = new Y.Doc({ guid: `xml-text-concurrent-insert-after-deleted-origin-insert-${clientID}`, gc: false })
      doc.clientID = clientID
      doc.getXmlFragment('xml')
      Y.applyUpdate(doc, baseUpdate)
      doc.getXmlFragment('xml').get(0).get(0).insert(1, value)
      return doc
    }

    const first = makeInsertReplica(1, 'A')
    const second = makeInsertReplica(2, 'B')
    const deleteReplica = new Y.Doc({ guid: 'xml-text-concurrent-insert-after-deleted-origin-delete', gc: false })
    deleteReplica.clientID = 3
    deleteReplica.getXmlFragment('xml')
    Y.applyUpdate(deleteReplica, baseUpdate)
    deleteReplica.getXmlFragment('xml').get(0).get(0).delete(0, 1)

    const firstUpdate = Y.encodeStateAsUpdate(first, baseStateVector)
    const secondUpdate = Y.encodeStateAsUpdate(second, baseStateVector)
    const deleteUpdate = Y.encodeStateAsUpdate(deleteReplica, baseStateVector)
    const merged = new Y.Doc({ guid: 'xml-text-concurrent-insert-after-deleted-origin-merged', gc: false })
    merged.getXmlFragment('xml')
    Y.applyUpdate(merged, deleteUpdate)
    Y.applyUpdate(merged, secondUpdate)
    Y.applyUpdate(merged, firstUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'xml-text-concurrent-insert-after-deleted-origin',
      updatesV1: [toBase64(deleteUpdate), toBase64(secondUpdate), toBase64(firstUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(deleteReplica, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(second, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(first, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON(),
      xmlTextDelta: normalizeValue(merged.getXmlFragment('xml').get(0).get(0).toDelta())
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'xml-text-concurrent-inside-multichar-origin-base', gc: false })
    base.clientID = 99
    const paragraph = new Y.XmlElement('p')
    const text = new Y.XmlText()
    text.insert(0, 'ABC')
    paragraph.insert(0, [text])
    base.getXmlFragment('xml').insert(0, [paragraph])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const makeReplica = (clientID, value) => {
      const doc = new Y.Doc({ guid: `xml-text-concurrent-inside-multichar-origin-${clientID}`, gc: false })
      doc.clientID = clientID
      doc.getXmlFragment('xml')
      Y.applyUpdate(doc, baseUpdate)
      doc.getXmlFragment('xml').get(0).get(0).insert(2, value)
      return doc
    }

    const left = makeReplica(1, 'x')
    const right = makeReplica(2, 'y')
    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'xml-text-concurrent-inside-multichar-origin-merged', gc: false })
    merged.getXmlFragment('xml')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'xml-text-concurrent-inside-multichar-origin',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON(),
      xmlTextDelta: normalizeValue(merged.getXmlFragment('xml').get(0).get(0).toDelta())
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'xml-text-attribute-text-concurrent-insert-after-deleted-origin-base', gc: false })
    base.clientID = 99
    const xmlText = new Y.XmlText()
    xmlText.insert(0, 'Xml')
    const body = new Y.Text()
    body.insert(0, 'XY')
    xmlText.setAttribute('body', body)
    base.getXmlFragment('xml').insert(0, [xmlText])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const insertReplica = (clientID, value) => {
      const doc = new Y.Doc({ guid: `xml-text-attribute-text-concurrent-insert-after-deleted-origin-insert-${clientID}`, gc: false })
      doc.clientID = clientID
      doc.getXmlFragment('xml')
      Y.applyUpdate(doc, baseUpdate)
      doc.getXmlFragment('xml').get(0).getAttribute('body').insert(1, value)
      return doc
    }

    const deleteReplica = new Y.Doc({ guid: 'xml-text-attribute-text-concurrent-insert-after-deleted-origin-delete', gc: false })
    deleteReplica.clientID = 3
    deleteReplica.getXmlFragment('xml')
    Y.applyUpdate(deleteReplica, baseUpdate)
    deleteReplica.getXmlFragment('xml').get(0).getAttribute('body').delete(0, 1)

    const first = insertReplica(1, 'A')
    const second = insertReplica(2, 'B')
    const firstUpdate = Y.encodeStateAsUpdate(first, baseStateVector)
    const secondUpdate = Y.encodeStateAsUpdate(second, baseStateVector)
    const deleteUpdate = Y.encodeStateAsUpdate(deleteReplica, baseStateVector)
    const merged = new Y.Doc({ guid: 'xml-text-attribute-text-concurrent-insert-after-deleted-origin-merged', gc: false })
    merged.getXmlFragment('xml')
    Y.applyUpdate(merged, deleteUpdate)
    Y.applyUpdate(merged, secondUpdate)
    Y.applyUpdate(merged, firstUpdate)
    Y.applyUpdate(merged, baseUpdate)
    const mergedText = merged.getXmlFragment('xml').get(0)

    return {
      name: 'xml-text-attribute-text-concurrent-insert-after-deleted-origin',
      updatesV1: [toBase64(deleteUpdate), toBase64(secondUpdate), toBase64(firstUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(deleteReplica, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(second, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(first, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON(),
      xmlTextAttributes: normalizeValue(mergedText.getAttributes())
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'map-xml-concurrent-insert-after-deleted-origin-base', gc: false })
    base.clientID = 99
    const fragment = new Y.XmlFragment()
    const intro = new Y.XmlText()
    intro.insert(0, 'X')
    const tail = new Y.XmlElement('tail')
    const tailText = new Y.XmlText()
    tailText.insert(0, 'Z')
    tail.insert(0, [tailText])
    fragment.insert(0, [intro, tail])
    base.getMap('map').set('xml', fragment)
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'map-xml-concurrent-insert-after-deleted-origin-left', gc: false })
    left.clientID = 1
    left.getMap('map')
    Y.applyUpdate(left, baseUpdate)
    left.getMap('map').get('xml').delete(0, 1)

    const right = new Y.Doc({ guid: 'map-xml-concurrent-insert-after-deleted-origin-right', gc: false })
    right.clientID = 2
    right.getMap('map')
    Y.applyUpdate(right, baseUpdate)
    const inserted = new Y.XmlElement('inserted')
    const insertedText = new Y.XmlText()
    insertedText.insert(0, 'A')
    inserted.insert(0, [insertedText])
    right.getMap('map').get('xml').insert(1, [inserted])

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'map-xml-concurrent-insert-after-deleted-origin-merged', gc: false })
    merged.getMap('map')
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, baseUpdate)
    const mergedXml = merged.getMap('map').get('xml')

    return {
      name: 'map-xml-concurrent-insert-after-deleted-origin',
      updatesV1: [toBase64(leftUpdate), toBase64(rightUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      rootTypes: { map: 'map' },
      json: merged.toJSON(),
      mapXmlKey: 'xml',
      mapXmlString: mergedXml.toString(),
      mapXmlChildren: mergedXml.toArray().map(xmlNodeSummary)
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'array-three-way-concurrent-after-origin-base', gc: false })
    base.clientID = 99
    base.getArray('array').insert(0, ['X'])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const makeReplica = (clientID, value) => {
      const doc = new Y.Doc({ guid: `array-three-way-concurrent-after-origin-${clientID}`, gc: false })
      doc.clientID = clientID
      doc.getArray('array')
      Y.applyUpdate(doc, baseUpdate)
      doc.getArray('array').insert(1, [value])
      return doc
    }

    const first = makeReplica(1, 'A')
    const second = makeReplica(2, 'B')
    const third = makeReplica(3, 'C')
    const firstUpdate = Y.encodeStateAsUpdate(first, baseStateVector)
    const secondUpdate = Y.encodeStateAsUpdate(second, baseStateVector)
    const thirdUpdate = Y.encodeStateAsUpdate(third, baseStateVector)
    const merged = new Y.Doc({ guid: 'array-three-way-concurrent-after-origin-merged', gc: false })
    merged.getArray('array')
    Y.applyUpdate(merged, thirdUpdate)
    Y.applyUpdate(merged, secondUpdate)
    Y.applyUpdate(merged, firstUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'array-three-way-concurrent-after-origin',
      updatesV1: [toBase64(thirdUpdate), toBase64(secondUpdate), toBase64(firstUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(third, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(second, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(first, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'array-three-way-concurrent-between-same-neighbors-base', gc: false })
    base.clientID = 99
    base.getArray('array').insert(0, ['X', 'Y'])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const makeReplica = (clientID, value) => {
      const doc = new Y.Doc({ guid: `array-three-way-concurrent-between-same-neighbors-${clientID}`, gc: false })
      doc.clientID = clientID
      doc.getArray('array')
      Y.applyUpdate(doc, baseUpdate)
      doc.getArray('array').insert(1, [value])
      return doc
    }

    const first = makeReplica(1, 'A')
    const second = makeReplica(2, 'B')
    const third = makeReplica(3, 'C')
    const firstUpdate = Y.encodeStateAsUpdate(first, baseStateVector)
    const secondUpdate = Y.encodeStateAsUpdate(second, baseStateVector)
    const thirdUpdate = Y.encodeStateAsUpdate(third, baseStateVector)
    const merged = new Y.Doc({ guid: 'array-three-way-concurrent-between-same-neighbors-merged', gc: false })
    merged.getArray('array')
    Y.applyUpdate(merged, thirdUpdate)
    Y.applyUpdate(merged, secondUpdate)
    Y.applyUpdate(merged, firstUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'array-three-way-concurrent-between-same-neighbors',
      updatesV1: [toBase64(thirdUpdate), toBase64(secondUpdate), toBase64(firstUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(third, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(second, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(first, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'text-deterministic-concurrent-fuzz-base', gc: false })
    base.clientID = 99
    base.getText('content').insert(0, 'ABCDE')
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const first = new Y.Doc({ guid: 'text-deterministic-concurrent-fuzz-first', gc: false })
    first.clientID = 1
    first.getText('content')
    Y.applyUpdate(first, baseUpdate)
    first.getText('content').insert(1, 'x')
    first.getText('content').delete(4, 1)

    const second = new Y.Doc({ guid: 'text-deterministic-concurrent-fuzz-second', gc: false })
    second.clientID = 2
    second.getText('content')
    Y.applyUpdate(second, baseUpdate)
    second.getText('content').delete(1, 2)
    second.getText('content').insert(2, 'y')

    const third = new Y.Doc({ guid: 'text-deterministic-concurrent-fuzz-third', gc: false })
    third.clientID = 3
    third.getText('content')
    Y.applyUpdate(third, baseUpdate)
    third.getText('content').insert(5, 'z')
    third.getText('content').delete(0, 1)

    const firstUpdate = Y.encodeStateAsUpdate(first, baseStateVector)
    const secondUpdate = Y.encodeStateAsUpdate(second, baseStateVector)
    const thirdUpdate = Y.encodeStateAsUpdate(third, baseStateVector)
    const merged = new Y.Doc({ guid: 'text-deterministic-concurrent-fuzz-merged', gc: false })
    merged.getText('content')
    Y.applyUpdate(merged, thirdUpdate)
    Y.applyUpdate(merged, firstUpdate)
    Y.applyUpdate(merged, secondUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'text-deterministic-concurrent-fuzz',
      updatesV1: [toBase64(thirdUpdate), toBase64(firstUpdate), toBase64(secondUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(third, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(first, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(second, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'text-concurrent-format-insert-delete-base', gc: false })
    base.clientID = 99
    base.getText('content').insert(0, 'WXYZ')
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'text-concurrent-format-insert-delete-left', gc: false })
    left.clientID = 1
    left.getText('content')
    Y.applyUpdate(left, baseUpdate)
    left.getText('content').format(1, 2, { bold: true })
    left.getText('content').insert(2, 'L', { italic: true })

    const right = new Y.Doc({ guid: 'text-concurrent-format-insert-delete-right', gc: false })
    right.clientID = 2
    right.getText('content')
    Y.applyUpdate(right, baseUpdate)
    right.getText('content').delete(1, 2)
    right.getText('content').insert(1, 'R')

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'text-concurrent-format-insert-delete-merged', gc: false })
    merged.getText('content')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'text-concurrent-format-insert-delete',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'array-deterministic-concurrent-fuzz-base', gc: false })
    base.clientID = 99
    base.getArray('array').insert(0, ['A', 'B', 'C', 'D', 'E'])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const first = new Y.Doc({ guid: 'array-deterministic-concurrent-fuzz-first', gc: false })
    first.clientID = 1
    first.getArray('array')
    Y.applyUpdate(first, baseUpdate)
    first.getArray('array').insert(2, ['x', 'y'])
    first.getArray('array').delete(4, 1)

    const second = new Y.Doc({ guid: 'array-deterministic-concurrent-fuzz-second', gc: false })
    second.clientID = 2
    second.getArray('array')
    Y.applyUpdate(second, baseUpdate)
    second.getArray('array').delete(1, 2)
    second.getArray('array').insert(1, ['m'])

    const third = new Y.Doc({ guid: 'array-deterministic-concurrent-fuzz-third', gc: false })
    third.clientID = 3
    third.getArray('array')
    Y.applyUpdate(third, baseUpdate)
    third.getArray('array').insert(5, ['z'])
    third.getArray('array').delete(0, 1)

    const firstUpdate = Y.encodeStateAsUpdate(first, baseStateVector)
    const secondUpdate = Y.encodeStateAsUpdate(second, baseStateVector)
    const thirdUpdate = Y.encodeStateAsUpdate(third, baseStateVector)
    const merged = new Y.Doc({ guid: 'array-deterministic-concurrent-fuzz-merged', gc: false })
    merged.getArray('array')
    Y.applyUpdate(merged, thirdUpdate)
    Y.applyUpdate(merged, firstUpdate)
    Y.applyUpdate(merged, secondUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'array-deterministic-concurrent-fuzz',
      updatesV1: [toBase64(thirdUpdate), toBase64(firstUpdate), toBase64(secondUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(third, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(first, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(second, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'map-concurrent-delete-and-set-same-key-base', gc: false })
    base.clientID = 99
    base.getMap('map').set('title', 'Base')
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'map-concurrent-delete-and-set-same-key-left', gc: false })
    left.clientID = 1
    left.getMap('map')
    Y.applyUpdate(left, baseUpdate)
    left.getMap('map').delete('title')

    const right = new Y.Doc({ guid: 'map-concurrent-delete-and-set-same-key-right', gc: false })
    right.clientID = 2
    right.getMap('map')
    Y.applyUpdate(right, baseUpdate)
    right.getMap('map').set('title', 'Right')

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'map-concurrent-delete-and-set-same-key-merged', gc: false })
    merged.getMap('map')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'map-concurrent-delete-and-set-same-key',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'map-concurrent-delete-and-replace-binary-base', gc: false })
    base.clientID = 99
    base.getMap('map').set('bytes', Uint8Array.from([1, 2, 3]))
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'map-concurrent-delete-and-replace-binary-left', gc: false })
    left.clientID = 1
    left.getMap('map')
    Y.applyUpdate(left, baseUpdate)
    left.getMap('map').delete('bytes')

    const right = new Y.Doc({ guid: 'map-concurrent-delete-and-replace-binary-right', gc: false })
    right.clientID = 2
    right.getMap('map')
    Y.applyUpdate(right, baseUpdate)
    right.getMap('map').set('bytes', Uint8Array.from([9, 8, 7]))

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'map-concurrent-delete-and-replace-binary-merged', gc: false })
    merged.getMap('map')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'map-concurrent-delete-and-replace-binary',
      rootTypes: { map: 'map' },
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: normalizeSemanticJsonValue(merged.toJSON())
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'map-concurrent-delete-and-replace-subdoc-base', gc: false })
    base.clientID = 99
    base.getMap('map').set('child', new Y.Doc({
      guid: 'base-subdoc',
      meta: { role: 'base' }
    }))
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'map-concurrent-delete-and-replace-subdoc-left', gc: false })
    left.clientID = 1
    left.getMap('map')
    Y.applyUpdate(left, baseUpdate)
    left.getMap('map').delete('child')

    const right = new Y.Doc({ guid: 'map-concurrent-delete-and-replace-subdoc-right', gc: false })
    right.clientID = 2
    right.getMap('map')
    Y.applyUpdate(right, baseUpdate)
    right.getMap('map').set('child', new Y.Doc({
      guid: 'replacement-subdoc',
      autoLoad: true,
      meta: { role: 'replacement' }
    }))

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'map-concurrent-delete-and-replace-subdoc-merged', gc: false })
    merged.getMap('map')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)
    const subdoc = merged.getMap('map').get('child')

    return {
      name: 'map-concurrent-delete-and-replace-subdoc',
      rootTypes: { map: 'map' },
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: normalizeSemanticJsonValue(merged.toJSON()),
      mapSubdocKey: 'child',
      mapSubdocGuid: subdoc.guid,
      mapSubdocMeta: normalizeSemanticJsonValue(subdoc.meta),
      mapSubdocShouldLoad: subdoc.shouldLoad
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'array-concurrent-delete-and-replace-subdoc-base', gc: false })
    base.clientID = 99
    base.getArray('array').insert(0, [new Y.Doc({
      guid: 'base-array-subdoc',
      meta: { role: 'base' }
    })])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'array-concurrent-delete-and-replace-subdoc-left', gc: false })
    left.clientID = 1
    left.getArray('array')
    Y.applyUpdate(left, baseUpdate)
    left.getArray('array').delete(0, 1)

    const right = new Y.Doc({ guid: 'array-concurrent-delete-and-replace-subdoc-right', gc: false })
    right.clientID = 2
    right.getArray('array')
    Y.applyUpdate(right, baseUpdate)
    right.getArray('array').delete(0, 1)
    right.getArray('array').insert(0, [new Y.Doc({
      guid: 'replacement-array-subdoc',
      autoLoad: true,
      meta: { role: 'replacement' }
    })])

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'array-concurrent-delete-and-replace-subdoc-merged', gc: false })
    merged.getArray('array')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)
    const subdoc = merged.getArray('array').get(0)

    return {
      name: 'array-concurrent-delete-and-replace-subdoc',
      rootTypes: { array: 'array' },
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: normalizeSemanticJsonValue(merged.toJSON()),
      arraySubdocIndex: 0,
      arraySubdocGuid: subdoc.guid,
      arraySubdocMeta: normalizeSemanticJsonValue(subdoc.meta),
      arraySubdocShouldLoad: subdoc.shouldLoad
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'map-concurrent-delete-and-edit-nested-text-base', gc: false })
    base.clientID = 99
    const nestedText = new Y.Text()
    base.getMap('map').set('body', nestedText)
    nestedText.insert(0, 'XY')
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'map-concurrent-delete-and-edit-nested-text-left', gc: false })
    left.clientID = 1
    left.getMap('map')
    Y.applyUpdate(left, baseUpdate)
    left.getMap('map').delete('body')

    const right = new Y.Doc({ guid: 'map-concurrent-delete-and-edit-nested-text-right', gc: false })
    right.clientID = 2
    right.getMap('map')
    Y.applyUpdate(right, baseUpdate)
    right.getMap('map').get('body').insert(1, 'A', { bold: true })

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'map-concurrent-delete-and-edit-nested-text-merged', gc: false })
    merged.getMap('map')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'map-concurrent-delete-and-edit-nested-text',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'map-concurrent-delete-and-attribute-nested-text-base', gc: false })
    base.clientID = 99
    const nestedText = new Y.Text()
    base.getMap('map').set('body', nestedText)
    nestedText.insert(0, 'XY')
    nestedText.setAttribute('lang', 'base')
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'map-concurrent-delete-and-attribute-nested-text-left', gc: false })
    left.clientID = 1
    left.getMap('map')
    Y.applyUpdate(left, baseUpdate)
    left.getMap('map').delete('body')

    const right = new Y.Doc({ guid: 'map-concurrent-delete-and-attribute-nested-text-right', gc: false })
    right.clientID = 2
    right.getMap('map')
    Y.applyUpdate(right, baseUpdate)
    right.getMap('map').get('body').setAttribute('lang', 'right')
    right.getMap('map').get('body').setAttribute('mark', { color: 'green' })

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'map-concurrent-delete-and-attribute-nested-text-merged', gc: false })
    merged.getMap('map')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'map-concurrent-delete-and-attribute-nested-text',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'map-nested-text-concurrent-formatted-edits-base', gc: false })
    base.clientID = 99
    const nestedText = new Y.Text()
    base.getMap('map').set('body', nestedText)
    nestedText.insert(0, 'ABCD')
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'map-nested-text-concurrent-formatted-edits-left', gc: false })
    left.clientID = 1
    left.getMap('map')
    Y.applyUpdate(left, baseUpdate)
    left.getMap('map').get('body').insert(2, 'L', { bold: true })

    const right = new Y.Doc({ guid: 'map-nested-text-concurrent-formatted-edits-right', gc: false })
    right.clientID = 2
    right.getMap('map')
    Y.applyUpdate(right, baseUpdate)
    right.getMap('map').get('body').insert(2, 'R', { italic: true })

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'map-nested-text-concurrent-formatted-edits-merged', gc: false })
    merged.getMap('map')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'map-nested-text-concurrent-formatted-edits',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON(),
      mapTextKey: 'body',
      mapTextDelta: normalizeValue(merged.getMap('map').get('body').toDelta())
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'map-concurrent-delete-and-edit-nested-array-base', gc: false })
    base.clientID = 99
    const nestedArray = new Y.Array()
    base.getMap('map').set('items', nestedArray)
    nestedArray.insert(0, ['A', 'B'])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'map-concurrent-delete-and-edit-nested-array-left', gc: false })
    left.clientID = 1
    left.getMap('map')
    Y.applyUpdate(left, baseUpdate)
    left.getMap('map').delete('items')

    const right = new Y.Doc({ guid: 'map-concurrent-delete-and-edit-nested-array-right', gc: false })
    right.clientID = 2
    right.getMap('map')
    Y.applyUpdate(right, baseUpdate)
    right.getMap('map').get('items').push(['C'])

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'map-concurrent-delete-and-edit-nested-array-merged', gc: false })
    merged.getMap('map')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'map-concurrent-delete-and-edit-nested-array',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'array-concurrent-delete-and-edit-nested-text-base', gc: false })
    base.clientID = 99
    const nestedText = new Y.Text()
    base.getArray('array').insert(0, [nestedText])
    nestedText.insert(0, 'XY')
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'array-concurrent-delete-and-edit-nested-text-left', gc: false })
    left.clientID = 1
    left.getArray('array')
    Y.applyUpdate(left, baseUpdate)
    left.getArray('array').delete(0, 1)

    const right = new Y.Doc({ guid: 'array-concurrent-delete-and-edit-nested-text-right', gc: false })
    right.clientID = 2
    right.getArray('array')
    Y.applyUpdate(right, baseUpdate)
    right.getArray('array').get(0).insert(1, 'A', { bold: true })
    right.getArray('array').get(0).format(0, 2, { italic: true })

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'array-concurrent-delete-and-edit-nested-text-merged', gc: false })
    merged.getArray('array')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'array-concurrent-delete-and-edit-nested-text',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'array-concurrent-delete-and-attribute-nested-text-base', gc: false })
    base.clientID = 99
    const nestedText = new Y.Text()
    base.getArray('array').insert(0, [nestedText])
    nestedText.insert(0, 'XY')
    nestedText.setAttribute('lang', 'base')
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'array-concurrent-delete-and-attribute-nested-text-left', gc: false })
    left.clientID = 1
    left.getArray('array')
    Y.applyUpdate(left, baseUpdate)
    left.getArray('array').delete(0, 1)

    const right = new Y.Doc({ guid: 'array-concurrent-delete-and-attribute-nested-text-right', gc: false })
    right.clientID = 2
    right.getArray('array')
    Y.applyUpdate(right, baseUpdate)
    right.getArray('array').get(0).setAttribute('lang', 'right')
    right.getArray('array').get(0).setAttribute('mark', { color: 'green' })

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'array-concurrent-delete-and-attribute-nested-text-merged', gc: false })
    merged.getArray('array')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'array-concurrent-delete-and-attribute-nested-text',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'array-concurrent-delete-and-edit-nested-array-base', gc: false })
    base.clientID = 99
    const nestedArray = new Y.Array()
    base.getArray('array').insert(0, [nestedArray])
    nestedArray.insert(0, ['A', 'B'])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'array-concurrent-delete-and-edit-nested-array-left', gc: false })
    left.clientID = 1
    left.getArray('array')
    Y.applyUpdate(left, baseUpdate)
    left.getArray('array').delete(0, 1)

    const right = new Y.Doc({ guid: 'array-concurrent-delete-and-edit-nested-array-right', gc: false })
    right.clientID = 2
    right.getArray('array')
    Y.applyUpdate(right, baseUpdate)
    right.getArray('array').get(0).delete(0, 1)
    right.getArray('array').get(0).push(['C'])

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'array-concurrent-delete-and-edit-nested-array-merged', gc: false })
    merged.getArray('array')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'array-concurrent-delete-and-edit-nested-array',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'array-concurrent-delete-and-edit-nested-map-base', gc: false })
    base.clientID = 99
    const nestedMap = new Y.Map()
    base.getArray('array').insert(0, [nestedMap])
    nestedMap.set('title', 'Base')
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'array-concurrent-delete-and-edit-nested-map-left', gc: false })
    left.clientID = 1
    left.getArray('array')
    Y.applyUpdate(left, baseUpdate)
    left.getArray('array').delete(0, 1)

    const right = new Y.Doc({ guid: 'array-concurrent-delete-and-edit-nested-map-right', gc: false })
    right.clientID = 2
    right.getArray('array')
    Y.applyUpdate(right, baseUpdate)
    right.getArray('array').get(0).set('title', 'Right')
    right.getArray('array').get(0).set('status', 'ready')

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'array-concurrent-delete-and-edit-nested-map-merged', gc: false })
    merged.getArray('array')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'array-concurrent-delete-and-edit-nested-map',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'map-concurrent-nested-replacements-same-key-base', gc: false })
    base.clientID = 99
    base.getMap('map').set('slot', 'base')
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'map-concurrent-nested-replacements-same-key-left', gc: false })
    left.clientID = 1
    left.getMap('map')
    Y.applyUpdate(left, baseUpdate)
    const leftNested = new Y.Map()
    leftNested.set('from', 'left')
    leftNested.set('rank', 1)
    left.getMap('map').set('slot', leftNested)

    const right = new Y.Doc({ guid: 'map-concurrent-nested-replacements-same-key-right', gc: false })
    right.clientID = 2
    right.getMap('map')
    Y.applyUpdate(right, baseUpdate)
    const rightNested = new Y.Map()
    rightNested.set('from', 'right')
    rightNested.set('rank', 2)
    right.getMap('map').set('slot', rightNested)

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'map-concurrent-nested-replacements-same-key-merged', gc: false })
    merged.getMap('map')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'map-concurrent-nested-replacements-same-key',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'array-xml-fragment-concurrent-edits-base', gc: false })
    base.clientID = 99
    const fragment = new Y.XmlFragment()
    const paragraph = new Y.XmlElement('p')
    const text = new Y.XmlText()
    text.insert(0, 'Hi')
    paragraph.insert(0, [text])
    fragment.insert(0, [paragraph])
    base.getArray('array').insert(0, [fragment])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'array-xml-fragment-concurrent-edits-left', gc: false })
    left.clientID = 1
    left.getArray('array')
    Y.applyUpdate(left, baseUpdate)
    left.getArray('array').get(0).get(0).get(0).insert(2, '!')

    const right = new Y.Doc({ guid: 'array-xml-fragment-concurrent-edits-right', gc: false })
    right.clientID = 2
    right.getArray('array')
    Y.applyUpdate(right, baseUpdate)
    right.getArray('array').get(0).insert(1, [' tail'])
    right.getArray('array').get(0).insert(2, [new Y.XmlElement('br')])

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'array-xml-fragment-concurrent-edits-merged', gc: false })
    merged.getArray('array')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'array-xml-fragment-concurrent-edits',
      rootTypes: { array: 'array' },
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'array-concurrent-delete-and-edit-xml-fragment-base', gc: false })
    base.clientID = 99
    const fragment = new Y.XmlFragment()
    const paragraph = new Y.XmlElement('p')
    const text = new Y.XmlText()
    text.insert(0, 'Base')
    paragraph.insert(0, [text])
    fragment.insert(0, [paragraph])
    base.getArray('array').insert(0, [fragment])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'array-concurrent-delete-and-edit-xml-fragment-left', gc: false })
    left.clientID = 1
    left.getArray('array')
    Y.applyUpdate(left, baseUpdate)
    left.getArray('array').delete(0, 1)

    const right = new Y.Doc({ guid: 'array-concurrent-delete-and-edit-xml-fragment-right', gc: false })
    right.clientID = 2
    right.getArray('array')
    Y.applyUpdate(right, baseUpdate)
    right.getArray('array').get(0).get(0).get(0).insert(4, '!')
    right.getArray('array').get(0).insert(1, [new Y.XmlElement('br')])

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'array-concurrent-delete-and-edit-xml-fragment-merged', gc: false })
    merged.getArray('array')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'array-concurrent-delete-and-edit-xml-fragment',
      rootTypes: { array: 'array' },
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'map-concurrent-delete-and-edit-xml-fragment-base', gc: false })
    base.clientID = 99
    const fragment = new Y.XmlFragment()
    const paragraph = new Y.XmlElement('p')
    const text = new Y.XmlText()
    text.insert(0, 'Base')
    paragraph.insert(0, [text])
    fragment.insert(0, [paragraph])
    base.getMap('map').set('xml', fragment)
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'map-concurrent-delete-and-edit-xml-fragment-left', gc: false })
    left.clientID = 1
    left.getMap('map')
    Y.applyUpdate(left, baseUpdate)
    left.getMap('map').delete('xml')

    const right = new Y.Doc({ guid: 'map-concurrent-delete-and-edit-xml-fragment-right', gc: false })
    right.clientID = 2
    right.getMap('map')
    Y.applyUpdate(right, baseUpdate)
    right.getMap('map').get('xml').get(0).get(0).insert(4, ' edit')
    right.getMap('map').get('xml').insert(1, [' tail'])

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'map-concurrent-delete-and-edit-xml-fragment-merged', gc: false })
    merged.getMap('map')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'map-concurrent-delete-and-edit-xml-fragment',
      rootTypes: { map: 'map' },
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'map-three-way-delete-edit-replace-xml-fragment-base', gc: false })
    base.clientID = 99
    const fragment = new Y.XmlFragment()
    const paragraph = new Y.XmlElement('p')
    const text = new Y.XmlText()
    text.insert(0, 'Base')
    paragraph.insert(0, [text])
    fragment.insert(0, [paragraph])
    base.getMap('map').set('xml', fragment)
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const deleter = new Y.Doc({ guid: 'map-three-way-delete-edit-replace-xml-fragment-delete', gc: false })
    deleter.clientID = 1
    deleter.getMap('map')
    Y.applyUpdate(deleter, baseUpdate)
    deleter.getMap('map').delete('xml')

    const editor = new Y.Doc({ guid: 'map-three-way-delete-edit-replace-xml-fragment-edit', gc: false })
    editor.clientID = 2
    editor.getMap('map')
    Y.applyUpdate(editor, baseUpdate)
    editor.getMap('map').get('xml').get(0).get(0).insert(4, ' edit')
    editor.getMap('map').get('xml').insert(1, [' tail'])

    const replacer = new Y.Doc({ guid: 'map-three-way-delete-edit-replace-xml-fragment-replace', gc: false })
    replacer.clientID = 3
    replacer.getMap('map')
    Y.applyUpdate(replacer, baseUpdate)
    const replacement = new Y.XmlFragment()
    const replacementElement = new Y.XmlElement('replacement')
    const replacementText = new Y.XmlText()
    replacementText.insert(0, 'New')
    replacementElement.insert(0, [replacementText])
    replacement.insert(0, [replacementElement])
    replacer.getMap('map').set('xml', replacement)

    const deleteUpdate = Y.encodeStateAsUpdate(deleter, baseStateVector)
    const editUpdate = Y.encodeStateAsUpdate(editor, baseStateVector)
    const replaceUpdate = Y.encodeStateAsUpdate(replacer, baseStateVector)
    const merged = new Y.Doc({ guid: 'map-three-way-delete-edit-replace-xml-fragment-merged', gc: false })
    merged.getMap('map')
    Y.applyUpdate(merged, editUpdate)
    Y.applyUpdate(merged, replaceUpdate)
    Y.applyUpdate(merged, deleteUpdate)
    Y.applyUpdate(merged, baseUpdate)
    const mergedXml = merged.getMap('map').get('xml')

    return {
      name: 'map-three-way-delete-edit-replace-xml-fragment',
      rootTypes: { map: 'map' },
      updatesV1: [toBase64(editUpdate), toBase64(replaceUpdate), toBase64(deleteUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(editor, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(replacer, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(deleter, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON(),
      mapXmlKey: 'xml',
      mapXmlString: mergedXml.toString(),
      mapXmlChildren: mergedXml.toArray().map(xmlNodeSummary)
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'map-three-way-delete-edit-replace-nested-text-base', gc: false })
    base.clientID = 99
    const baseText = new Y.Text()
    baseText.insert(0, 'Base')
    base.getMap('map').set('slot', baseText)
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const deleter = new Y.Doc({ guid: 'map-three-way-delete-edit-replace-nested-text-delete', gc: false })
    deleter.clientID = 1
    deleter.getMap('map')
    Y.applyUpdate(deleter, baseUpdate)
    deleter.getMap('map').delete('slot')

    const editor = new Y.Doc({ guid: 'map-three-way-delete-edit-replace-nested-text-edit', gc: false })
    editor.clientID = 2
    editor.getMap('map')
    Y.applyUpdate(editor, baseUpdate)
    editor.getMap('map').get('slot').insert(4, ' edit', { author: 'editor' })
    editor.getMap('map').get('slot').setAttribute('lang', 'en')

    const replacer = new Y.Doc({ guid: 'map-three-way-delete-edit-replace-nested-text-replace', gc: false })
    replacer.clientID = 3
    replacer.getMap('map')
    Y.applyUpdate(replacer, baseUpdate)
    const replacementText = new Y.Text()
    replacementText.insert(0, 'Replacement')
    replacementText.setAttribute('lang', 'new')
    replacer.getMap('map').set('slot', replacementText)

    const deleteUpdate = Y.encodeStateAsUpdate(deleter, baseStateVector)
    const editUpdate = Y.encodeStateAsUpdate(editor, baseStateVector)
    const replaceUpdate = Y.encodeStateAsUpdate(replacer, baseStateVector)
    const merged = new Y.Doc({ guid: 'map-three-way-delete-edit-replace-nested-text-merged', gc: false })
    merged.getMap('map')
    Y.applyUpdate(merged, editUpdate)
    Y.applyUpdate(merged, replaceUpdate)
    Y.applyUpdate(merged, deleteUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'map-three-way-delete-edit-replace-nested-text',
      updatesV1: [toBase64(editUpdate), toBase64(replaceUpdate), toBase64(deleteUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(editor, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(replacer, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(deleter, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON(),
      mapTextKey: 'slot',
      mapTextAttributes: merged.getMap('map').get('slot').getAttributes()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'xml-concurrent-delete-element-edit-child-base', gc: false })
    base.clientID = 99
    const paragraph = new Y.XmlElement('p')
    const text = new Y.XmlText()
    text.insert(0, 'Hi')
    paragraph.insert(0, [text])
    base.getXmlFragment('xml').insert(0, [paragraph])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'xml-concurrent-delete-element-edit-child-left', gc: false })
    left.clientID = 1
    left.getXmlFragment('xml')
    Y.applyUpdate(left, baseUpdate)
    left.getXmlFragment('xml').delete(0, 1)

    const right = new Y.Doc({ guid: 'xml-concurrent-delete-element-edit-child-right', gc: false })
    right.clientID = 2
    right.getXmlFragment('xml')
    Y.applyUpdate(right, baseUpdate)
    right.getXmlFragment('xml').get(0).get(0).insert(2, '!')

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'xml-concurrent-delete-element-edit-child-merged', gc: false })
    merged.getXmlFragment('xml')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'xml-concurrent-delete-element-edit-child',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'xml-concurrent-delete-element-format-child-base', gc: false })
    base.clientID = 99
    const paragraph = new Y.XmlElement('p')
    const text = new Y.XmlText()
    text.insert(0, 'Hi')
    paragraph.insert(0, [text])
    base.getXmlFragment('xml').insert(0, [paragraph])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'xml-concurrent-delete-element-format-child-left', gc: false })
    left.clientID = 1
    left.getXmlFragment('xml')
    Y.applyUpdate(left, baseUpdate)
    left.getXmlFragment('xml').delete(0, 1)

    const right = new Y.Doc({ guid: 'xml-concurrent-delete-element-format-child-right', gc: false })
    right.clientID = 2
    right.getXmlFragment('xml')
    Y.applyUpdate(right, baseUpdate)
    const rightText = right.getXmlFragment('xml').get(0).get(0)
    rightText.insert(2, '!')
    rightText.format(0, 3, { bold: true })

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'xml-concurrent-delete-element-format-child-merged', gc: false })
    merged.getXmlFragment('xml')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'xml-concurrent-delete-element-format-child',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'xml-concurrent-delete-child-edit-attributes-and-text-base', gc: false })
    base.clientID = 99
    const section = new Y.XmlElement('section')
    const paragraph = new Y.XmlElement('p')
    paragraph.setAttribute('class', 'base')
    const paragraphText = new Y.XmlText()
    paragraphText.insert(0, 'Hi')
    paragraph.insert(0, [paragraphText])
    const tail = new Y.XmlElement('aside')
    const tailText = new Y.XmlText()
    tailText.insert(0, 'Tail')
    tail.insert(0, [tailText])
    section.insert(0, [paragraph, tail])
    base.getXmlFragment('xml').insert(0, [section])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'xml-concurrent-delete-child-edit-attributes-and-text-left', gc: false })
    left.clientID = 1
    left.getXmlFragment('xml')
    Y.applyUpdate(left, baseUpdate)
    left.getXmlFragment('xml').get(0).delete(0, 1)

    const right = new Y.Doc({ guid: 'xml-concurrent-delete-child-edit-attributes-and-text-right', gc: false })
    right.clientID = 2
    right.getXmlFragment('xml')
    Y.applyUpdate(right, baseUpdate)
    const rightParagraph = right.getXmlFragment('xml').get(0).get(0)
    rightParagraph.setAttribute('class', 'right')
    rightParagraph.setAttribute('data-seen', 'yes')
    const rightText = rightParagraph.get(0)
    rightText.insert(2, '!')
    rightText.format(0, 3, { bold: true })

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'xml-concurrent-delete-child-edit-attributes-and-text-merged', gc: false })
    merged.getXmlFragment('xml')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)
    const xml = merged.getXmlFragment('xml')

    return {
      name: 'xml-concurrent-delete-child-edit-attributes-and-text',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON(),
      xmlString: xml.toString(),
      xmlChildren: xml.toArray().map(xmlNodeSummary)
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'xml-concurrent-attribute-same-key-base', gc: false })
    base.clientID = 99
    const paragraph = new Y.XmlElement('p')
    paragraph.setAttribute('class', 'base')
    base.getXmlFragment('xml').insert(0, [paragraph])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'xml-concurrent-attribute-same-key-left', gc: false })
    left.clientID = 1
    left.getXmlFragment('xml')
    Y.applyUpdate(left, baseUpdate)
    left.getXmlFragment('xml').get(0).setAttribute('class', 'left')

    const right = new Y.Doc({ guid: 'xml-concurrent-attribute-same-key-right', gc: false })
    right.clientID = 2
    right.getXmlFragment('xml')
    Y.applyUpdate(right, baseUpdate)
    right.getXmlFragment('xml').get(0).setAttribute('class', 'right')

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'xml-concurrent-attribute-same-key-merged', gc: false })
    merged.getXmlFragment('xml')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'xml-concurrent-attribute-same-key',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'xml-concurrent-attribute-delete-update-base', gc: false })
    base.clientID = 99
    const paragraph = new Y.XmlElement('p')
    paragraph.setAttribute('class', 'base')
    base.getXmlFragment('xml').insert(0, [paragraph])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'xml-concurrent-attribute-delete-update-left', gc: false })
    left.clientID = 1
    left.getXmlFragment('xml')
    Y.applyUpdate(left, baseUpdate)
    left.getXmlFragment('xml').get(0).removeAttribute('class')

    const right = new Y.Doc({ guid: 'xml-concurrent-attribute-delete-update-right', gc: false })
    right.clientID = 2
    right.getXmlFragment('xml')
    Y.applyUpdate(right, baseUpdate)
    right.getXmlFragment('xml').get(0).setAttribute('class', 'right')

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'xml-concurrent-attribute-delete-update-merged', gc: false })
    merged.getXmlFragment('xml')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'xml-concurrent-attribute-delete-update',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'xml-hook-concurrent-children-same-origin-base', gc: false })
    base.clientID = 99
    const lead = new Y.XmlText()
    lead.insert(0, 'A')
    const tail = new Y.XmlText()
    tail.insert(0, 'Z')
    base.getXmlFragment('xml').insert(0, [lead, tail])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'xml-hook-concurrent-children-same-origin-left', gc: false })
    left.clientID = 1
    left.getXmlFragment('xml')
    Y.applyUpdate(left, baseUpdate)
    const leftHook = new Y.XmlHook('left')
    leftHook.set('side', 'left')
    left.getXmlFragment('xml').insert(1, [leftHook])

    const right = new Y.Doc({ guid: 'xml-hook-concurrent-children-same-origin-right', gc: false })
    right.clientID = 2
    right.getXmlFragment('xml')
    Y.applyUpdate(right, baseUpdate)
    const rightHook = new Y.XmlHook('right')
    rightHook.set('side', 'right')
    right.getXmlFragment('xml').insert(1, [rightHook])

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'xml-hook-concurrent-children-same-origin-merged', gc: false })
    merged.getXmlFragment('xml')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'xml-hook-concurrent-children-same-origin',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON(),
      xmlString: merged.getXmlFragment('xml').toString(),
      xmlChildren: merged.getXmlFragment('xml').toArray().map(xmlNodeSummary)
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'xml-hook-concurrent-map-same-key-base', gc: false })
    base.clientID = 99
    const hook = new Y.XmlHook('mention')
    hook.set('role', 'base')
    base.getXmlFragment('xml').insert(0, [hook])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'xml-hook-concurrent-map-same-key-left', gc: false })
    left.clientID = 1
    left.getXmlFragment('xml')
    Y.applyUpdate(left, baseUpdate)
    left.getXmlFragment('xml').get(0).set('role', 'left')

    const right = new Y.Doc({ guid: 'xml-hook-concurrent-map-same-key-right', gc: false })
    right.clientID = 2
    right.getXmlFragment('xml')
    Y.applyUpdate(right, baseUpdate)
    right.getXmlFragment('xml').get(0).set('role', 'right')

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'xml-hook-concurrent-map-same-key-merged', gc: false })
    merged.getXmlFragment('xml')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'xml-hook-concurrent-map-same-key',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON(),
      hookJson: normalizeValue(merged.getXmlFragment('xml').get(0).toJSON())
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'xml-hook-concurrent-map-delete-update-base', gc: false })
    base.clientID = 99
    const hook = new Y.XmlHook('mention')
    hook.set('role', 'base')
    hook.set('label', 'Ada')
    base.getXmlFragment('xml').insert(0, [hook])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'xml-hook-concurrent-map-delete-update-left', gc: false })
    left.clientID = 1
    left.getXmlFragment('xml')
    Y.applyUpdate(left, baseUpdate)
    left.getXmlFragment('xml').get(0).delete('role')

    const right = new Y.Doc({ guid: 'xml-hook-concurrent-map-delete-update-right', gc: false })
    right.clientID = 2
    right.getXmlFragment('xml')
    Y.applyUpdate(right, baseUpdate)
    right.getXmlFragment('xml').get(0).set('role', 'right')

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'xml-hook-concurrent-map-delete-update-merged', gc: false })
    merged.getXmlFragment('xml')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'xml-hook-concurrent-map-delete-update',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON(),
      hookJson: normalizeValue(merged.getXmlFragment('xml').get(0).toJSON())
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'xml-hook-concurrent-xml-shared-type-same-key-base', gc: false })
    base.clientID = 99
    const hook = new Y.XmlHook('mention')
    hook.set('slot', 'base')
    base.getXmlFragment('xml').insert(0, [hook])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'xml-hook-concurrent-xml-shared-type-same-key-left', gc: false })
    left.clientID = 1
    left.getXmlFragment('xml')
    Y.applyUpdate(left, baseUpdate)
    const leftElement = new Y.XmlElement('p')
    const leftText = new Y.XmlText()
    left.getXmlFragment('xml').get(0).set('slot', leftElement)
    leftElement.insert(0, [leftText])
    leftText.insert(0, 'Left')

    const right = new Y.Doc({ guid: 'xml-hook-concurrent-xml-shared-type-same-key-right', gc: false })
    right.clientID = 2
    right.getXmlFragment('xml')
    Y.applyUpdate(right, baseUpdate)
    const rightText = new Y.XmlText()
    right.getXmlFragment('xml').get(0).set('slot', rightText)
    rightText.insert(0, 'Right')

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'xml-hook-concurrent-xml-shared-type-same-key-merged', gc: false })
    merged.getXmlFragment('xml')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'xml-hook-concurrent-xml-shared-type-same-key',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON(),
      hookJson: normalizeValue(merged.getXmlFragment('xml').get(0).toJSON())
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'xml-hook-concurrent-delete-edit-xml-shared-type-base', gc: false })
    base.clientID = 99
    const hook = new Y.XmlHook('mention')
    const element = new Y.XmlElement('p')
    const text = new Y.XmlText()
    hook.set('element', element)
    base.getXmlFragment('xml').insert(0, [hook])
    element.insert(0, [text])
    text.insert(0, 'Base')
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'xml-hook-concurrent-delete-edit-xml-shared-type-left', gc: false })
    left.clientID = 1
    left.getXmlFragment('xml')
    Y.applyUpdate(left, baseUpdate)
    left.getXmlFragment('xml').get(0).delete('element')

    const right = new Y.Doc({ guid: 'xml-hook-concurrent-delete-edit-xml-shared-type-right', gc: false })
    right.clientID = 2
    right.getXmlFragment('xml')
    Y.applyUpdate(right, baseUpdate)
    const rightElement = right.getXmlFragment('xml').get(0).get('element')
    rightElement.setAttribute('class', 'right')
    rightElement.get(0).insert(4, ' right')

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'xml-hook-concurrent-delete-edit-xml-shared-type-merged', gc: false })
    merged.getXmlFragment('xml')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'xml-hook-concurrent-delete-edit-xml-shared-type',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON(),
      hookJson: normalizeValue(merged.getXmlFragment('xml').get(0).toJSON())
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'xml-element-concurrent-xml-shared-type-same-key-base', gc: false })
    base.clientID = 99
    const element = new Y.XmlElement('p')
    element.setAttribute('slot', 'base')
    base.getXmlFragment('xml').insert(0, [element])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'xml-element-concurrent-xml-shared-type-same-key-left', gc: false })
    left.clientID = 1
    left.getXmlFragment('xml')
    Y.applyUpdate(left, baseUpdate)
    const leftElement = new Y.XmlElement('strong')
    const leftText = new Y.XmlText()
    left.getXmlFragment('xml').get(0).setAttribute('slot', leftElement)
    leftElement.insert(0, [leftText])
    leftText.insert(0, 'Left')

    const right = new Y.Doc({ guid: 'xml-element-concurrent-xml-shared-type-same-key-right', gc: false })
    right.clientID = 2
    right.getXmlFragment('xml')
    Y.applyUpdate(right, baseUpdate)
    const rightText = new Y.XmlText()
    right.getXmlFragment('xml').get(0).setAttribute('slot', rightText)
    rightText.insert(0, 'Right')

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'xml-element-concurrent-xml-shared-type-same-key-merged', gc: false })
    merged.getXmlFragment('xml')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)
    const mergedElement = merged.getXmlFragment('xml').get(0)

    return {
      name: 'xml-element-concurrent-xml-shared-type-same-key',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON(),
      elementAttributes: normalizeValue(mergedElement.getAttributes())
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'xml-text-concurrent-xml-shared-type-same-key-base', gc: false })
    base.clientID = 99
    const xmlText = new Y.XmlText()
    xmlText.insert(0, 'Base')
    xmlText.setAttribute('slot', 'base')
    base.getXmlFragment('xml').insert(0, [xmlText])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'xml-text-concurrent-xml-shared-type-same-key-left', gc: false })
    left.clientID = 1
    left.getXmlFragment('xml')
    Y.applyUpdate(left, baseUpdate)
    const leftElement = new Y.XmlElement('strong')
    const leftText = new Y.XmlText()
    left.getXmlFragment('xml').get(0).setAttribute('slot', leftElement)
    leftElement.insert(0, [leftText])
    leftText.insert(0, 'Left')

    const right = new Y.Doc({ guid: 'xml-text-concurrent-xml-shared-type-same-key-right', gc: false })
    right.clientID = 2
    right.getXmlFragment('xml')
    Y.applyUpdate(right, baseUpdate)
    const rightText = new Y.Text()
    right.getXmlFragment('xml').get(0).setAttribute('slot', rightText)
    rightText.insert(0, 'Right')

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'xml-text-concurrent-xml-shared-type-same-key-merged', gc: false })
    merged.getXmlFragment('xml')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)
    const mergedText = merged.getXmlFragment('xml').get(0)

    return {
      name: 'xml-text-concurrent-xml-shared-type-same-key',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON(),
      xmlTextAttributes: normalizeValue(mergedText.getAttributes())
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'xml-element-concurrent-delete-edit-xml-shared-type-base', gc: false })
    base.clientID = 99
    const element = new Y.XmlElement('p')
    const inline = new Y.XmlElement('span')
    const text = new Y.XmlText()
    element.setAttribute('inline', inline)
    base.getXmlFragment('xml').insert(0, [element])
    inline.insert(0, [text])
    text.insert(0, 'Base')
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'xml-element-concurrent-delete-edit-xml-shared-type-left', gc: false })
    left.clientID = 1
    left.getXmlFragment('xml')
    Y.applyUpdate(left, baseUpdate)
    left.getXmlFragment('xml').get(0).removeAttribute('inline')

    const right = new Y.Doc({ guid: 'xml-element-concurrent-delete-edit-xml-shared-type-right', gc: false })
    right.clientID = 2
    right.getXmlFragment('xml')
    Y.applyUpdate(right, baseUpdate)
    const rightInline = right.getXmlFragment('xml').get(0).getAttribute('inline')
    rightInline.setAttribute('class', 'right')
    rightInline.get(0).insert(4, ' right')

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'xml-element-concurrent-delete-edit-xml-shared-type-merged', gc: false })
    merged.getXmlFragment('xml')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)
    const mergedElement = merged.getXmlFragment('xml').get(0)

    return {
      name: 'xml-element-concurrent-delete-edit-xml-shared-type',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON(),
      elementAttributes: normalizeValue(mergedElement.getAttributes())
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'xml-text-concurrent-delete-edit-xml-shared-type-base', gc: false })
    base.clientID = 99
    const xmlText = new Y.XmlText()
    const inline = new Y.XmlElement('span')
    const text = new Y.XmlText()
    xmlText.insert(0, 'Host')
    xmlText.setAttribute('inline', inline)
    base.getXmlFragment('xml').insert(0, [xmlText])
    inline.insert(0, [text])
    text.insert(0, 'Base')
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'xml-text-concurrent-delete-edit-xml-shared-type-left', gc: false })
    left.clientID = 1
    left.getXmlFragment('xml')
    Y.applyUpdate(left, baseUpdate)
    left.getXmlFragment('xml').get(0).removeAttribute('inline')

    const right = new Y.Doc({ guid: 'xml-text-concurrent-delete-edit-xml-shared-type-right', gc: false })
    right.clientID = 2
    right.getXmlFragment('xml')
    Y.applyUpdate(right, baseUpdate)
    const rightInline = right.getXmlFragment('xml').get(0).getAttribute('inline')
    rightInline.setAttribute('class', 'right')
    rightInline.get(0).insert(4, ' right')

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'xml-text-concurrent-delete-edit-xml-shared-type-merged', gc: false })
    merged.getXmlFragment('xml')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)
    const mergedText = merged.getXmlFragment('xml').get(0)

    return {
      name: 'xml-text-concurrent-delete-edit-xml-shared-type',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON(),
      xmlTextAttributes: normalizeValue(mergedText.getAttributes())
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'xml-hook-concurrent-delete-edit-map-base', gc: false })
    base.clientID = 99
    const hook = new Y.XmlHook('mention')
    hook.set('role', 'base')
    hook.set('label', 'Ada')
    base.getXmlFragment('xml').insert(0, [hook])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const left = new Y.Doc({ guid: 'xml-hook-concurrent-delete-edit-map-left', gc: false })
    left.clientID = 1
    left.getXmlFragment('xml')
    Y.applyUpdate(left, baseUpdate)
    left.getXmlFragment('xml').delete(0, 1)

    const right = new Y.Doc({ guid: 'xml-hook-concurrent-delete-edit-map-right', gc: false })
    right.clientID = 2
    right.getXmlFragment('xml')
    Y.applyUpdate(right, baseUpdate)
    right.getXmlFragment('xml').get(0).set('label', 'Grace')
    right.getXmlFragment('xml').get(0).set('active', true)

    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'xml-hook-concurrent-delete-edit-map-merged', gc: false })
    merged.getXmlFragment('xml')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'xml-hook-concurrent-delete-edit-map',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'xml-concurrent-text-nodes-same-origin-base', gc: false })
    base.clientID = 99
    const root = new Y.XmlElement('root')
    const seed = new Y.XmlText()
    seed.insert(0, 'X')
    root.insert(0, [seed])
    base.getXmlFragment('xml').insert(0, [root])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const makeReplica = (clientID, value, attrs) => {
      const doc = new Y.Doc({ guid: `xml-concurrent-text-nodes-same-origin-${clientID}`, gc: false })
      doc.clientID = clientID
      doc.getXmlFragment('xml')
      Y.applyUpdate(doc, baseUpdate)
      const text = new Y.XmlText()
      text.insert(0, value, attrs)
      doc.getXmlFragment('xml').get(0).insert(1, [text])
      return doc
    }

    const left = makeReplica(1, 'A', { bold: true })
    const right = makeReplica(2, 'B', { italic: true })
    const leftUpdate = Y.encodeStateAsUpdate(left, baseStateVector)
    const rightUpdate = Y.encodeStateAsUpdate(right, baseStateVector)
    const merged = new Y.Doc({ guid: 'xml-concurrent-text-nodes-same-origin-merged', gc: false })
    merged.getXmlFragment('xml')
    Y.applyUpdate(merged, rightUpdate)
    Y.applyUpdate(merged, leftUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'xml-concurrent-text-nodes-same-origin',
      updatesV1: [toBase64(rightUpdate), toBase64(leftUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(right, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(left, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'xml-three-way-insert-delete-deleted-right-origin-base', gc: false })
    base.clientID = 99
    const root = new Y.XmlElement('root')
    root.insert(0, [new Y.XmlElement('seed')])
    base.getXmlFragment('xml').insert(0, [root])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const makeInsertReplica = (clientID, nodeName) => {
      const doc = new Y.Doc({ guid: `xml-three-way-insert-delete-deleted-right-origin-${clientID}`, gc: false })
      doc.clientID = clientID
      doc.getXmlFragment('xml')
      Y.applyUpdate(doc, baseUpdate)
      doc.getXmlFragment('xml').get(0).insert(1, [new Y.XmlElement(nodeName)])
      return doc
    }

    const first = makeInsertReplica(1, 'a')
    const second = makeInsertReplica(2, 'b')
    const third = new Y.Doc({ guid: 'xml-three-way-insert-delete-deleted-right-origin-3', gc: false })
    third.clientID = 3
    third.getXmlFragment('xml')
    Y.applyUpdate(third, baseUpdate)
    third.getXmlFragment('xml').get(0).delete(0, 1)
    third.getXmlFragment('xml').get(0).insert(0, [new Y.XmlElement('c')])

    const firstUpdate = Y.encodeStateAsUpdate(first, baseStateVector)
    const secondUpdate = Y.encodeStateAsUpdate(second, baseStateVector)
    const thirdUpdate = Y.encodeStateAsUpdate(third, baseStateVector)
    const merged = new Y.Doc({ guid: 'xml-three-way-insert-delete-deleted-right-origin-merged', gc: false })
    merged.getXmlFragment('xml')
    Y.applyUpdate(merged, thirdUpdate)
    Y.applyUpdate(merged, secondUpdate)
    Y.applyUpdate(merged, firstUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'xml-three-way-insert-delete-deleted-right-origin',
      updatesV1: [toBase64(thirdUpdate), toBase64(secondUpdate), toBase64(firstUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(third, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(second, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(first, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })(),
  (() => {
    const base = new Y.Doc({ guid: 'xml-deterministic-concurrent-child-fuzz-base', gc: false })
    base.clientID = 99
    const root = new Y.XmlElement('root')
    root.insert(0, [
      new Y.XmlElement('a'),
      new Y.XmlElement('b'),
      new Y.XmlElement('c'),
      new Y.XmlElement('d'),
      new Y.XmlElement('e')
    ])
    base.getXmlFragment('xml').insert(0, [root])
    const baseUpdate = Y.encodeStateAsUpdate(base)
    const baseStateVector = Y.encodeStateVector(base)

    const first = new Y.Doc({ guid: 'xml-deterministic-concurrent-child-fuzz-first', gc: false })
    first.clientID = 1
    first.getXmlFragment('xml')
    Y.applyUpdate(first, baseUpdate)
    first.getXmlFragment('xml').get(0).insert(2, [new Y.XmlElement('x'), new Y.XmlElement('y')])
    first.getXmlFragment('xml').get(0).delete(4, 1)

    const second = new Y.Doc({ guid: 'xml-deterministic-concurrent-child-fuzz-second', gc: false })
    second.clientID = 2
    second.getXmlFragment('xml')
    Y.applyUpdate(second, baseUpdate)
    second.getXmlFragment('xml').get(0).delete(1, 2)
    second.getXmlFragment('xml').get(0).insert(1, [new Y.XmlElement('m')])

    const third = new Y.Doc({ guid: 'xml-deterministic-concurrent-child-fuzz-third', gc: false })
    third.clientID = 3
    third.getXmlFragment('xml')
    Y.applyUpdate(third, baseUpdate)
    third.getXmlFragment('xml').get(0).insert(5, [new Y.XmlElement('z')])
    third.getXmlFragment('xml').get(0).delete(0, 1)

    const firstUpdate = Y.encodeStateAsUpdate(first, baseStateVector)
    const secondUpdate = Y.encodeStateAsUpdate(second, baseStateVector)
    const thirdUpdate = Y.encodeStateAsUpdate(third, baseStateVector)
    const merged = new Y.Doc({ guid: 'xml-deterministic-concurrent-child-fuzz-merged', gc: false })
    merged.getXmlFragment('xml')
    Y.applyUpdate(merged, thirdUpdate)
    Y.applyUpdate(merged, firstUpdate)
    Y.applyUpdate(merged, secondUpdate)
    Y.applyUpdate(merged, baseUpdate)

    return {
      name: 'xml-deterministic-concurrent-child-fuzz',
      updatesV1: [toBase64(thirdUpdate), toBase64(firstUpdate), toBase64(secondUpdate), toBase64(baseUpdate)],
      updatesV2: [
        toBase64(Y.encodeStateAsUpdateV2(third, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(first, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(second, baseStateVector)),
        toBase64(Y.encodeStateAsUpdateV2(base))
      ],
      json: merged.toJSON()
    }
  })()
]

fs.writeFileSync(
  path.join(outDir, 'concurrent-v1.json'),
  `${JSON.stringify({ cases: concurrentFixtures.map(concurrentFixtureWithMergedMetadata) }, null, 2)}\n`
)

const syncDoc = new Y.Doc({ guid: 'sync-doc', gc: false })
syncDoc.clientID = 20
syncDoc.getText('content').insert(0, 'Sync')
const emptyRemote = new Y.Doc({ guid: 'sync-empty', gc: false })
emptyRemote.clientID = 21
const partialRemote = new Y.Doc({ guid: 'sync-partial', gc: false })
partialRemote.clientID = 22
partialRemote.getText('content').insert(0, 'S')
const deleteSetOnlyRemoteStateVector = (() => {
  const encoder = encoding.createEncoder()
  encoding.writeVarUint(encoder, 1)
  encoding.writeVarUint(encoder, 24)
  encoding.writeVarUint(encoder, 4)
  return encoding.toUint8Array(encoder)
})()
const syncDeleteSetDoc = new Y.Doc({ guid: 'sync-delete-set-doc', gc: false })
syncDeleteSetDoc.clientID = 24
syncDeleteSetDoc.getText('content').insert(0, 'ABCD')
syncDeleteSetDoc.getText('content').delete(1, 2)

const syncSubdocSource = new Y.Doc({ guid: 'sync-subdoc-source', gc: false })
syncSubdocSource.clientID = 32
syncSubdocSource.getArray('array').insert(0, ['known'])
const syncSubdocPrefix = new Y.Doc({ guid: 'sync-subdoc-prefix', gc: false })
syncSubdocPrefix.clientID = 32
syncSubdocPrefix.getArray('array').insert(0, ['known'])
syncSubdocSource.getArray('array').insert(1, [
  new Y.Doc({
    guid: 'sync-subdoc-child',
    autoLoad: true,
    meta: { kind: 'sync-subdoc' }
  })
])
const syncSubdocStep2Encoder = encoding.createEncoder()
syncProtocol.writeSyncStep2(syncSubdocStep2Encoder, syncSubdocSource, Y.encodeStateVector(syncSubdocPrefix))
const syncSubdocStep2V2Encoder = encoding.createEncoder()
encoding.writeVarUint(syncSubdocStep2V2Encoder, syncProtocol.messageYjsSyncStep2)
encoding.writeVarUint8Array(
  syncSubdocStep2V2Encoder,
  Y.encodeStateAsUpdateV2(syncSubdocSource, Y.encodeStateVector(syncSubdocPrefix))
)

const syncStep1Encoder = encoding.createEncoder()
syncProtocol.writeSyncStep1(syncStep1Encoder, syncDoc)

const syncStep2Encoder = encoding.createEncoder()
syncProtocol.writeSyncStep2(syncStep2Encoder, syncDoc, Y.encodeStateVector(emptyRemote))

const syncStep2V2Encoder = encoding.createEncoder()
encoding.writeVarUint(syncStep2V2Encoder, syncProtocol.messageYjsSyncStep2)
encoding.writeVarUint8Array(syncStep2V2Encoder, Y.encodeStateAsUpdateV2(syncDoc, Y.encodeStateVector(emptyRemote)))

const syncStep2PartialEncoder = encoding.createEncoder()
syncProtocol.writeSyncStep2(syncStep2PartialEncoder, syncDoc, Y.encodeStateVector(partialRemote))

const syncStep2V2PartialEncoder = encoding.createEncoder()
encoding.writeVarUint(syncStep2V2PartialEncoder, syncProtocol.messageYjsSyncStep2)
encoding.writeVarUint8Array(syncStep2V2PartialEncoder, Y.encodeStateAsUpdateV2(syncDoc, Y.encodeStateVector(partialRemote)))

const syncStep2CurrentEncoder = encoding.createEncoder()
syncProtocol.writeSyncStep2(syncStep2CurrentEncoder, syncDoc, Y.encodeStateVector(syncDoc))

const syncStep2V2CurrentEncoder = encoding.createEncoder()
encoding.writeVarUint(syncStep2V2CurrentEncoder, syncProtocol.messageYjsSyncStep2)
encoding.writeVarUint8Array(syncStep2V2CurrentEncoder, Y.encodeStateAsUpdateV2(syncDoc, Y.encodeStateVector(syncDoc)))

const syncStep2DeleteSetOnlyEncoder = encoding.createEncoder()
syncProtocol.writeSyncStep2(syncStep2DeleteSetOnlyEncoder, syncDeleteSetDoc, deleteSetOnlyRemoteStateVector)

const syncStep2V2DeleteSetOnlyEncoder = encoding.createEncoder()
encoding.writeVarUint(syncStep2V2DeleteSetOnlyEncoder, syncProtocol.messageYjsSyncStep2)
encoding.writeVarUint8Array(
  syncStep2V2DeleteSetOnlyEncoder,
  Y.encodeStateAsUpdateV2(syncDeleteSetDoc, deleteSetOnlyRemoteStateVector)
)

const syncUpdate = Y.encodeStateAsUpdate(syncDoc)
const syncUpdateV2 = Y.encodeStateAsUpdateV2(syncDoc)
const syncUpdateEncoder = encoding.createEncoder()
syncProtocol.writeUpdate(syncUpdateEncoder, syncUpdate)
const syncUpdateV2Encoder = encoding.createEncoder()
syncProtocol.writeUpdate(syncUpdateV2Encoder, syncUpdateV2)

const syncFixtures = {
  docJson: syncDoc.toJSON(),
  stateVectorV1: toBase64(Y.encodeStateVector(syncDoc)),
  updateV1: toBase64(syncUpdate),
  updateV2: toBase64(syncUpdateV2),
  syncStep1: toBase64(encoding.toUint8Array(syncStep1Encoder)),
  syncStep2ForEmpty: toBase64(encoding.toUint8Array(syncStep2Encoder)),
  syncStep2V2ForEmpty: toBase64(encoding.toUint8Array(syncStep2V2Encoder)),
  syncStep2ForCurrent: toBase64(encoding.toUint8Array(syncStep2CurrentEncoder)),
  syncStep2V2ForCurrent: toBase64(encoding.toUint8Array(syncStep2V2CurrentEncoder)),
  syncStep2ForPartial: toBase64(encoding.toUint8Array(syncStep2PartialEncoder)),
  syncStep2V2ForPartial: toBase64(encoding.toUint8Array(syncStep2V2PartialEncoder)),
  syncStep2DeleteSetOnly: toBase64(encoding.toUint8Array(syncStep2DeleteSetOnlyEncoder)),
  syncStep2V2DeleteSetOnly: toBase64(encoding.toUint8Array(syncStep2V2DeleteSetOnlyEncoder)),
  updateMessage: toBase64(encoding.toUint8Array(syncUpdateEncoder)),
  updateV2Message: toBase64(encoding.toUint8Array(syncUpdateV2Encoder)),
  subdocPartial: {
    expectedJson: normalizeValue(syncSubdocSource.toJSON()),
    sourceStateVectorV1: toBase64(Y.encodeStateVector(syncSubdocSource)),
    prefixJson: normalizeValue(syncSubdocPrefix.toJSON()),
    prefixStateVectorV1: toBase64(Y.encodeStateVector(syncSubdocPrefix)),
    prefixUpdateV1: toBase64(Y.encodeStateAsUpdate(syncSubdocPrefix)),
    prefixUpdateV2: toBase64(Y.encodeStateAsUpdateV2(syncSubdocPrefix)),
    syncStep2ForPrefix: toBase64(encoding.toUint8Array(syncSubdocStep2Encoder)),
    syncStep2V2ForPrefix: toBase64(encoding.toUint8Array(syncSubdocStep2V2Encoder)),
    expectedArraySubdocs: [
      {
        index: 1,
        guid: 'sync-subdoc-child',
        meta: { kind: 'sync-subdoc' },
        shouldLoad: true
      }
    ]
  }
}

fs.writeFileSync(
  path.join(outDir, 'sync-protocol.json'),
  `${JSON.stringify(syncFixtures, null, 2)}\n`
)

const normalizeObserverKeys = keys =>
  Object.fromEntries(Array.from(keys.entries()).map(([key, change]) => {
    const normalized = { action: change.action }
    if (change.oldValue !== undefined) {
      normalized.oldValue = normalizeValue(change.oldValue)
    }
    return [key, normalized]
  }))

const captureObserverEvent = (name, setup, mutate) => {
  const doc = new Y.Doc({ guid: `${name}-observer-doc`, gc: false })
  doc.clientID = 170
  const type = setup(doc)
  const events = []
  type.observe(event => {
    const normalizedEvent = {
      path: event.path,
      changes: {
        keys: normalizeObserverKeys(event.changes.keys),
        delta: normalizeValue(event.changes.delta)
      }
    }
    if (event.attributesChanged !== undefined) {
      const attributesChanged = Array.from(event.attributesChanged).sort()
      if (attributesChanged.length > 0) {
        normalizedEvent.changes.attributesChanged = attributesChanged
      }
    }
    events.push(normalizedEvent)
  })
  doc.transact(() => mutate(type), `${name}-origin`)
  return events[0]
}

const normalizeObserverEvent = event => {
  const normalizedEvent = {
    path: event.path,
    changes: {
      keys: normalizeObserverKeys(event.changes.keys),
      delta: normalizeValue(event.changes.delta)
    }
  }
  if (event.attributesChanged !== undefined) {
    const attributesChanged = Array.from(event.attributesChanged).sort()
    if (attributesChanged.length > 0) {
      normalizedEvent.changes.attributesChanged = attributesChanged
    }
  }
  return normalizedEvent
}

const captureRootArrayDeepObserverEvents = () => {
  const doc = new Y.Doc({ guid: 'array-deep-observer-paths-doc', gc: false })
  doc.clientID = 170
  const array = doc.getArray('array')
  const map = new Y.Map()
  const child = new Y.Array()
  array.insert(0, [map])
  map.set('child', child)
  const events = []

  array.observeDeep(deepEvents => {
    for (const event of deepEvents) {
      events.push({
        targetType: event.target.constructor.name,
        ...normalizeObserverEvent(event)
      })
    }
  })

  doc.transact(() => {
    map.set('title', 'A')
  }, 'array-deep-observer-paths-origin')

  return events.sort((left, right) => left.targetType.localeCompare(right.targetType))
}

const captureRootMapDeepObserverEvents = () => {
  const doc = new Y.Doc({ guid: 'map-deep-observer-paths-doc', gc: false })
  doc.clientID = 170
  const map = doc.getMap('map')
  const array = new Y.Array()
  const child = new Y.Map()
  map.set('items', array)
  array.insert(0, [child])
  const events = []

  map.observeDeep(deepEvents => {
    for (const event of deepEvents) {
      events.push({
        targetType: event.target.constructor.name,
        ...normalizeObserverEvent(event)
      })
    }
  })

  doc.transact(() => {
    child.set('title', 'A')
  }, 'map-deep-observer-paths-origin')

  return events.sort((left, right) => left.targetType.localeCompare(right.targetType))
}

const captureRootMapNestedTextDeepObserverEvents = () => {
  const doc = new Y.Doc({ guid: 'map-nested-text-deep-observer-paths-doc', gc: false })
  doc.clientID = 170
  const map = doc.getMap('map')
  const text = new Y.Text()
  text.insert(0, 'Map')
  map.set('body', text)
  const events = []

  map.observeDeep(deepEvents => {
    for (const event of deepEvents) {
      events.push({
        targetType: event.target.constructor.name,
        ...normalizeObserverEvent(event)
      })
    }
  })

  doc.transact(() => {
    text.setAttribute('lang', 'en')
    text.insert(3, ' text', { emphasis: true })
  }, 'map-nested-text-deep-observer-paths-origin')

  return events.sort((left, right) => left.targetType.localeCompare(right.targetType))
}

const captureRootMapNestedXmlFragmentDeepObserverEvents = () => {
  const doc = new Y.Doc({ guid: 'map-nested-xml-fragment-deep-observer-paths-doc', gc: false })
  doc.clientID = 170
  const map = doc.getMap('map')
  const fragment = new Y.XmlFragment()
  const text = new Y.XmlText()
  text.insert(0, 'A')
  const paragraph = new Y.XmlElement('p')
  const paragraphText = new Y.XmlText()
  paragraphText.insert(0, 'C')
  paragraph.insert(0, [paragraphText])
  fragment.insert(0, [text, paragraph])
  map.set('xml', fragment)
  const events = []

  map.observeDeep(deepEvents => {
    for (const event of deepEvents) {
      events.push({
        targetType: event.target.constructor.name,
        ...normalizeObserverEvent(event)
      })
    }
  })

  doc.transact(() => {
    text.insert(1, '!')
    paragraph.setAttribute('class', 'lead')
    fragment.insert(2, [new Y.XmlElement('br')])
  }, 'map-nested-xml-fragment-deep-observer-paths-origin')

  return events.sort((left, right) => {
    const typeOrder = left.targetType.localeCompare(right.targetType)
    return typeOrder === 0 ? JSON.stringify(left.path).localeCompare(JSON.stringify(right.path)) : typeOrder
  })
}

const captureRootMapNestedMapTextDeepObserverEvents = () => {
  const doc = new Y.Doc({ guid: 'map-nested-map-text-deep-observer-paths-doc', gc: false })
  doc.clientID = 170
  const map = doc.getMap('map')
  const root = new Y.Map()
  const text = new Y.Text()
  text.insert(0, 'Deep')
  map.set('root', root)
  root.set('body', text)
  root.set('status', 'draft')
  const events = []

  map.observeDeep(deepEvents => {
    for (const event of deepEvents) {
      events.push({
        targetType: event.target.constructor.name,
        ...normalizeObserverEvent(event)
      })
    }
  })

  doc.transact(() => {
    root.set('status', 'published')
    text.setAttribute('lang', 'en')
    text.insert(4, ' text', { bold: true })
  }, 'map-nested-map-text-deep-observer-paths-origin')

  return events.sort((left, right) => {
    const typeOrder = left.targetType.localeCompare(right.targetType)
    return typeOrder === 0 ? JSON.stringify(left.path).localeCompare(JSON.stringify(right.path)) : typeOrder
  })
}

const captureRootTextDeepObserverEvents = () => {
  const doc = new Y.Doc({ guid: 'text-deep-observer-paths-doc', gc: false })
  doc.clientID = 170
  const text = doc.getText('text')
  const events = []

  text.observeDeep(deepEvents => {
    for (const event of deepEvents) {
      events.push({
        targetType: event.target.constructor.name,
        ...normalizeObserverEvent(event)
      })
    }
  })

  doc.transact(() => {
    text.insert(0, 'Hi')
  }, 'text-deep-observer-paths-origin')

  return events
}

const captureRootArrayParentAndChildObserverEvent = () => {
  const doc = new Y.Doc({ guid: 'array-parent-child-observer-doc', gc: false })
  doc.clientID = 170
  const array = doc.getArray('array')
  const map = new Y.Map()
  const child = new Y.Array()
  array.insert(0, [map])
  map.set('child', child)
  const events = []

  array.observe(event => {
    events.push(normalizeObserverEvent(event))
  })

  doc.transact(() => {
    array.insert(1, ['tail'])
    child.insert(0, ['child'])
  }, 'array-parent-child-observer-origin')

  return events[0]
}

const captureRootMapParentAndChildObserverEvent = () => {
  const doc = new Y.Doc({ guid: 'map-parent-child-observer-doc', gc: false })
  doc.clientID = 170
  const map = doc.getMap('map')
  const child = new Y.Array()
  map.set('child', child)
  const events = []

  map.observe(event => {
    events.push(normalizeObserverEvent(event))
  })

  doc.transact(() => {
    map.set('title', 'parent')
    child.insert(0, ['child'])
  }, 'map-parent-child-observer-origin')

  return events[0]
}

const captureNestedArrayDeepObserverEvents = () => {
  const doc = new Y.Doc({ guid: 'nested-array-deep-observer-paths-doc', gc: false })
  doc.clientID = 170
  const root = doc.getArray('array')
  const array = new Y.Array()
  const child = new Y.Map()
  root.insert(0, [array])
  array.insert(0, [child])
  const events = []

  array.observeDeep(deepEvents => {
    for (const event of deepEvents) {
      events.push({
        targetType: event.target.constructor.name,
        ...normalizeObserverEvent(event)
      })
    }
  })

  doc.transact(() => {
    array.insert(1, ['x'])
  }, 'nested-array-deep-observer-paths-origin')

  return events.sort((left, right) => left.targetType.localeCompare(right.targetType))
}

const captureNestedMapDeepObserverEvents = () => {
  const doc = new Y.Doc({ guid: 'nested-map-deep-observer-paths-doc', gc: false })
  doc.clientID = 170
  const root = doc.getArray('array')
  const map = new Y.Map()
  const child = new Y.Array()
  root.insert(0, [map])
  map.set('child', child)
  const events = []

  map.observeDeep(deepEvents => {
    for (const event of deepEvents) {
      events.push({
        targetType: event.target.constructor.name,
        ...normalizeObserverEvent(event)
      })
    }
  })

  doc.transact(() => {
    child.insert(0, ['x'])
  }, 'nested-map-deep-observer-paths-origin')

  return events.sort((left, right) => left.targetType.localeCompare(right.targetType))
}

const captureNestedTextDeepObserverEvents = () => {
  const doc = new Y.Doc({ guid: 'nested-text-deep-observer-paths-doc', gc: false })
  doc.clientID = 170
  const root = doc.getArray('array')
  const text = new Y.Text()
  root.insert(0, [text])
  const events = []

  text.observeDeep(deepEvents => {
    for (const event of deepEvents) {
      events.push({
        targetType: event.target.constructor.name,
        ...normalizeObserverEvent(event)
      })
    }
  })

  doc.transact(() => {
    text.insert(0, 'Hi')
  }, 'nested-text-deep-observer-paths-origin')

  return events
}

const captureNestedTextAttributeDeepObserverEvents = () => {
  const doc = new Y.Doc({ guid: 'nested-text-attribute-deep-observer-paths-doc', gc: false })
  doc.clientID = 170
  const root = doc.getArray('array')
  const text = new Y.Text()
  text.insert(0, 'Hello')
  text.setAttribute('lang', 'en')
  root.insert(0, [text])
  const events = []

  text.observeDeep(deepEvents => {
    for (const event of deepEvents) {
      events.push({
        targetType: event.target.constructor.name,
        ...normalizeObserverEvent(event)
      })
    }
  })

  doc.transact(() => {
    text.setAttribute('lang', 'fr')
    text.setAttribute('mark', { color: 'green' })
    text.removeAttribute('mark')
  }, 'nested-text-attribute-deep-observer-paths-origin')

  return events
}

const captureNestedXmlFragmentDeepObserverEvents = () => {
  const doc = new Y.Doc({ guid: 'nested-xml-fragment-deep-observer-paths-doc', gc: false })
  doc.clientID = 170
  const root = doc.getArray('array')
  const fragment = new Y.XmlFragment()
  const first = new Y.XmlText()
  first.insert(0, 'A')
  fragment.insert(0, [first])
  root.insert(0, [fragment])
  const events = []

  fragment.observeDeep(deepEvents => {
    for (const event of deepEvents) {
      events.push({
        targetType: event.target.constructor.name,
        ...normalizeObserverEvent(event)
      })
    }
  })

  doc.transact(() => {
    const second = new Y.XmlText()
    second.insert(0, 'B')
    const paragraph = new Y.XmlElement('p')
    const paragraphText = new Y.XmlText()
    paragraphText.insert(0, 'C')
    paragraph.insert(0, [paragraphText])
    fragment.insert(1, [second, paragraph])
  }, 'nested-xml-fragment-deep-observer-paths-origin')

  return events.sort((left, right) => left.targetType.localeCompare(right.targetType))
}

const captureXmlDeepObserverEvents = () => {
  const doc = new Y.Doc({ guid: 'xml-deep-observer-paths-doc', gc: false })
  doc.clientID = 170
  const xml = doc.getXmlFragment('xml')
  const paragraph = new Y.XmlElement('p')
  const text = new Y.XmlText()
  text.insert(0, 'Hi')
  paragraph.insert(0, [text])
  xml.insert(0, [paragraph])
  const events = []

  xml.observeDeep(deepEvents => {
    for (const event of deepEvents) {
      events.push({
        targetType: event.target.constructor.name,
        ...normalizeObserverEvent(event)
      })
    }
  })

  doc.transact(() => {
    paragraph.setAttribute('class', 'lead')
    text.insert(2, '!')
  }, 'xml-deep-observer-paths-origin')

  return events.sort((left, right) => left.targetType.localeCompare(right.targetType))
}

const captureXmlElementDeepObserverEvents = () => {
  const doc = new Y.Doc({ guid: 'xml-element-deep-observer-paths-doc', gc: false })
  doc.clientID = 170
  const xml = doc.getXmlFragment('xml')
  const paragraph = new Y.XmlElement('p')
  const text = new Y.XmlText()
  text.insert(0, 'Hi')
  paragraph.insert(0, [text])
  xml.insert(0, [paragraph])
  const events = []

  paragraph.observeDeep(deepEvents => {
    for (const event of deepEvents) {
      events.push({
        targetType: event.target.constructor.name,
        ...normalizeObserverEvent(event)
      })
    }
  })

  doc.transact(() => {
    paragraph.setAttribute('class', 'lead')
    text.insert(2, '!')
  }, 'xml-element-deep-observer-paths-origin')

  return events.sort((left, right) => left.targetType.localeCompare(right.targetType))
}

const captureXmlElementSharedAttributeDeepObserverEvents = () => {
  const doc = new Y.Doc({ guid: 'xml-element-shared-attribute-deep-observer-paths-doc', gc: false })
  doc.clientID = 170
  const paragraph = new Y.XmlElement('p')
  const body = new Y.Text()
  const element = new Y.XmlElement('span')
  paragraph.setAttribute('body', body)
  paragraph.setAttribute('element', element)
  doc.getXmlFragment('xml').insert(0, [paragraph])
  const events = []

  paragraph.observeDeep(deepEvents => {
    for (const event of deepEvents) {
      events.push({
        targetType: event.target.constructor.name,
        ...normalizeObserverEvent(event)
      })
    }
  })

  doc.transact(() => {
    paragraph.setAttribute('role', 'lead')
    body.insert(0, 'Hi')
    element.setAttribute('class', 'lead')
    const elementText = new Y.XmlText()
    element.insert(0, [elementText])
    elementText.insert(0, 'Xml')
  }, 'xml-element-shared-attribute-deep-observer-paths-origin')

  return events.sort((left, right) => JSON.stringify(left.path).localeCompare(JSON.stringify(right.path)) || left.targetType.localeCompare(right.targetType))
}

const captureXmlElementSharedAttributeReplaceDeepObserverEvents = () => {
  const doc = new Y.Doc({ guid: 'xml-element-shared-attribute-replace-deep-observer-paths-doc', gc: false })
  doc.clientID = 170
  const paragraph = new Y.XmlElement('p')
  const body = new Y.Text()
  paragraph.setAttribute('body', body)
  body.insert(0, 'Old')
  doc.getXmlFragment('xml').insert(0, [paragraph])
  const events = []

  paragraph.observeDeep(deepEvents => {
    for (const event of deepEvents) {
      events.push({
        targetType: event.target.constructor.name,
        ...normalizeObserverEvent(event)
      })
    }
  })

  doc.transact(() => {
    paragraph.setAttribute('body', 'plain')
    paragraph.setAttribute('inline', new Y.XmlElement('span'))
  }, 'xml-element-shared-attribute-replace-deep-observer-paths-origin')

  return events.sort((left, right) => JSON.stringify(left.path).localeCompare(JSON.stringify(right.path)) || left.targetType.localeCompare(right.targetType))
}

const captureXmlElementBulkChildDeepObserverEvents = () => {
  const doc = new Y.Doc({ guid: 'xml-element-bulk-child-deep-observer-paths-doc', gc: false })
  doc.clientID = 170
  const xml = doc.getXmlFragment('xml')
  const paragraph = new Y.XmlElement('p')
  const text = new Y.XmlText()
  text.insert(0, 'A')
  paragraph.insert(0, [text])
  xml.insert(0, [paragraph])
  const events = []

  paragraph.observeDeep(deepEvents => {
    for (const event of deepEvents) {
      events.push({
        targetType: event.target.constructor.name,
        ...normalizeObserverEvent(event)
      })
    }
  })

  doc.transact(() => {
    paragraph.insert(1, [
      new Y.XmlElement('em'),
      new Y.XmlHook('mention')
    ])
    text.insert(1, '!')
  }, 'xml-element-bulk-child-deep-observer-paths-origin')

  return events.sort((left, right) => left.targetType.localeCompare(right.targetType))
}

const captureXmlElementReplaceChildDeepObserverEvents = () => {
  const doc = new Y.Doc({ guid: 'xml-element-replace-child-deep-observer-paths-doc', gc: false })
  doc.clientID = 170
  const xml = doc.getXmlFragment('xml')
  const paragraph = new Y.XmlElement('p')
  const text = new Y.XmlText()
  text.insert(0, 'A')
  const middle = new Y.XmlElement('em')
  const middleText = new Y.XmlText()
  middleText.insert(0, 'B')
  middle.insert(0, [middleText])
  paragraph.insert(0, [text, middle])
  xml.insert(0, [paragraph])
  const events = []

  paragraph.observeDeep(deepEvents => {
    for (const event of deepEvents) {
      events.push({
        targetType: event.target.constructor.name,
        ...normalizeObserverEvent(event)
      })
    }
  })

  doc.transact(() => {
    text.insert(1, '!')
    paragraph.delete(1, 1)
    const strong = new Y.XmlElement('strong')
    const strongText = new Y.XmlText()
    strongText.insert(0, 'C')
    strong.insert(0, [strongText])
    paragraph.insert(1, [strong])
  }, 'xml-element-replace-child-deep-observer-paths-origin')

  return events.sort((left, right) => left.targetType.localeCompare(right.targetType))
}

const captureXmlTextDeepObserverEvents = () => {
  const doc = new Y.Doc({ guid: 'xml-text-deep-observer-paths-doc', gc: false })
  doc.clientID = 170
  const xml = doc.getXmlFragment('xml')
  const text = new Y.XmlText()
  text.insert(0, 'Hi')
  xml.insert(0, [text])
  const events = []

  text.observeDeep(deepEvents => {
    for (const event of deepEvents) {
      events.push({
        targetType: event.target.constructor.name,
        ...normalizeObserverEvent(event)
      })
    }
  })

  doc.transact(() => {
    text.insert(2, '!')
  }, 'xml-text-deep-observer-paths-origin')

  return events
}

const captureXmlTextSharedAttributeDeepObserverEvents = () => {
  const doc = new Y.Doc({ guid: 'xml-text-shared-attribute-deep-observer-paths-doc', gc: false })
  doc.clientID = 170
  const xmlText = new Y.XmlText()
  xmlText.insert(0, 'Hi')
  const body = new Y.Text()
  const element = new Y.XmlElement('span')
  xmlText.setAttribute('body', body)
  xmlText.setAttribute('element', element)
  doc.getXmlFragment('xml').insert(0, [xmlText])
  const events = []

  xmlText.observeDeep(deepEvents => {
    for (const event of deepEvents) {
      events.push({
        targetType: event.target.constructor.name,
        ...normalizeObserverEvent(event)
      })
    }
  })

  doc.transact(() => {
    xmlText.setAttribute('role', 'lead')
    body.insert(0, 'Hi')
    element.setAttribute('class', 'lead')
    const elementText = new Y.XmlText()
    element.insert(0, [elementText])
    elementText.insert(0, 'Xml')
  }, 'xml-text-shared-attribute-deep-observer-paths-origin')

  return events.sort((left, right) => JSON.stringify(left.path).localeCompare(JSON.stringify(right.path)) || left.targetType.localeCompare(right.targetType))
}

const captureXmlTextSharedAttributeReplaceDeepObserverEvents = () => {
  const doc = new Y.Doc({ guid: 'xml-text-shared-attribute-replace-deep-observer-paths-doc', gc: false })
  doc.clientID = 170
  const xmlText = new Y.XmlText()
  xmlText.insert(0, 'Hi')
  doc.getXmlFragment('xml').insert(0, [xmlText])
  const body = new Y.Text()
  xmlText.setAttribute('body', body)
  body.insert(0, 'Old')
  const events = []

  xmlText.observeDeep(deepEvents => {
    for (const event of deepEvents) {
      events.push({
        targetType: event.target.constructor.name,
        ...normalizeObserverEvent(event)
      })
    }
  })

  doc.transact(() => {
    xmlText.setAttribute('body', 'plain')
    xmlText.setAttribute('inline', new Y.XmlElement('span'))
  }, 'xml-text-shared-attribute-replace-deep-observer-paths-origin')

  return events.sort((left, right) => JSON.stringify(left.path).localeCompare(JSON.stringify(right.path)) || left.targetType.localeCompare(right.targetType))
}

const captureXmlHookDeepObserverEvents = () => {
  const doc = new Y.Doc({ guid: 'xml-hook-deep-observer-paths-doc', gc: false })
  doc.clientID = 170
  const hook = new Y.XmlHook('mention')
  const body = new Y.Text()
  const element = new Y.XmlElement('p')
  hook.set('body', body)
  hook.set('element', element)
  doc.getXmlFragment('xml').insert(0, [hook])
  const events = []

  hook.observeDeep(deepEvents => {
    for (const event of deepEvents) {
      events.push({
        targetType: event.target.constructor.name,
        ...normalizeObserverEvent(event)
      })
    }
  })

  doc.transact(() => {
    hook.set('role', 'lead')
    body.insert(0, 'Hi')
    element.setAttribute('class', 'lead')
    const elementText = new Y.XmlText()
    element.insert(0, [elementText])
    elementText.insert(0, 'Xml')
  }, 'xml-hook-deep-observer-paths-origin')

  return events.sort((left, right) => JSON.stringify(left.path).localeCompare(JSON.stringify(right.path)) || left.targetType.localeCompare(right.targetType))
}

const captureObserverAddedDuringDispatchCalls = () => {
  const doc = new Y.Doc({ guid: 'observer-added-during-dispatch-doc', gc: false })
  doc.clientID = 170
  const text = doc.getText('content')
  const calls = []

  text.observe(() => {
    calls.push('first')
    text.observe(() => {
      calls.push('third')
    })
  })
  text.observe(() => {
    calls.push('second')
  })

  text.insert(0, 'A')
  text.insert(1, 'B')

  return calls
}

const captureReentrantTextMutation = () => {
  const doc = new Y.Doc({ guid: 'reentrant-text-mutation-observer-doc', gc: false })
  doc.clientID = 179
  const text = doc.getText('content')
  const notificationOrder = []
  const observerEvents = []

  text.observe(event => {
    notificationOrder.push(`observe:${event.transaction.origin ?? 'null'}`)
    observerEvents.push({
      value: text.toString(),
      origin: event.transaction.origin
    })

    if (text.toString() === 'A') {
      text.insert(1, 'B')
    }
  })
  doc.on('afterTransaction', transaction => {
    notificationOrder.push(`afterTransaction:${transaction.origin ?? 'null'}`)
  })
  doc.on('update', (_update, origin) => {
    notificationOrder.push(`update:${origin ?? 'null'}`)
  })

  doc.transact(() => {
    text.insert(0, 'A')
  }, 'reentrant-text-mutation-origin')

  return {
    json: normalizeValue(doc.toJSON()),
    notificationOrderPrefix: notificationOrder.slice(0, 3),
    observerEvents
  }
}

const captureDocumentLifecycleOrder = () => {
  const doc = new Y.Doc({ guid: 'document-lifecycle-order-doc', gc: false })
  doc.clientID = 180
  const text = doc.getText('content')
  const order = []
  const originFor = transaction => transaction.origin ?? 'null'

  doc.on('beforeAllTransactions', () => {
    order.push('beforeAllTransactions')
  })
  doc.on('beforeTransaction', transaction => {
    order.push(`beforeTransaction:${originFor(transaction)}`)
  })
  doc.on('beforeObserverCalls', transaction => {
    order.push(`beforeObserverCalls:${originFor(transaction)}`)
  })
  text.observe(event => {
    order.push(`observe:${originFor(event.transaction)}`)
  })
  doc.on('afterTransaction', transaction => {
    order.push(`afterTransaction:${originFor(transaction)}`)
  })
  doc.on('afterTransactionCleanup', transaction => {
    order.push(`afterTransactionCleanup:${originFor(transaction)}`)
  })
  doc.on('update', (_update, origin) => {
    order.push(`update:${origin ?? 'null'}`)
  })
  doc.on('updateV2', (_update, origin) => {
    order.push(`updateV2:${origin ?? 'null'}`)
  })
  doc.on('afterAllTransactions', () => {
    order.push('afterAllTransactions')
  })

  doc.transact(() => {
    text.insert(0, 'A')
  }, 'lifecycle-origin')

  return order
}

const captureRemoteDocumentLifecycleOrder = () => {
  const source = new Y.Doc({ guid: 'remote-document-lifecycle-source-doc', gc: false })
  source.clientID = 181
  source.getText('content').insert(0, 'A')
  const update = Y.encodeStateAsUpdate(source)
  const doc = new Y.Doc({ guid: 'remote-document-lifecycle-target-doc', gc: false })
  doc.clientID = 182
  const text = doc.getText('content')
  const firstApply = []
  const duplicateApply = []
  const originFor = transaction => transaction.origin ?? 'null'
  let activeOrder = firstApply

  doc.on('beforeAllTransactions', () => {
    activeOrder.push('beforeAllTransactions')
  })
  doc.on('beforeTransaction', transaction => {
    activeOrder.push(`beforeTransaction:${originFor(transaction)}`)
  })
  doc.on('beforeObserverCalls', transaction => {
    activeOrder.push(`beforeObserverCalls:${originFor(transaction)}`)
  })
  text.observe(event => {
    activeOrder.push(`observe:${originFor(event.transaction)}`)
  })
  doc.on('afterTransaction', transaction => {
    activeOrder.push(`afterTransaction:${originFor(transaction)}`)
  })
  doc.on('afterTransactionCleanup', transaction => {
    activeOrder.push(`afterTransactionCleanup:${originFor(transaction)}`)
  })
  doc.on('update', (_update, origin) => {
    activeOrder.push(`update:${origin ?? 'null'}`)
  })
  doc.on('updateV2', (_update, origin) => {
    activeOrder.push(`updateV2:${origin ?? 'null'}`)
  })
  doc.on('afterAllTransactions', () => {
    activeOrder.push('afterAllTransactions')
  })

  Y.applyUpdate(doc, update, 'remote-lifecycle-origin')
  activeOrder = duplicateApply
  Y.applyUpdate(doc, update, 'remote-duplicate-lifecycle-origin')

  return {
    firstApply,
    duplicateApply
  }
}

const normalizeSubdoc = subdoc => ({
  guid: subdoc.guid,
  meta: subdoc.meta,
  shouldLoad: subdoc.shouldLoad
})

const normalizeSubdocEvent = (event, doc, transaction) => ({
  origin: transaction.origin ?? null,
  local: transaction.local,
  loaded: Array.from(event.loaded).map(normalizeSubdoc),
  added: Array.from(event.added).map(normalizeSubdoc),
  removed: Array.from(event.removed).map(normalizeSubdoc),
  liveGuids: Array.from(doc.getSubdocGuids())
})

const captureSubdocEvents = () => {
  const doc = new Y.Doc({ guid: 'subdoc-events-doc', gc: false })
  doc.clientID = 183
  const array = doc.getArray('array')
  const map = doc.getMap('map')
  const events = []
  const order = []
  const arrayChild = new Y.Doc({ guid: 'array-child', meta: { scope: 'array' }, shouldLoad: false })
  const transactionalChild = new Y.Doc({ guid: 'transactional-child', meta: { scope: 'transactional' }, shouldLoad: false })

  doc.on('update', (_update, origin) => {
    order.push(`update:${origin ?? 'null'}`)
  })
  doc.on('updateV2', (_update, origin) => {
    order.push(`updateV2:${origin ?? 'null'}`)
  })
  doc.on('subdocs', (event, observedDoc, transaction) => {
    order.push(`subdocs:${transaction.origin ?? 'null'}`)
    events.push(normalizeSubdocEvent(event, observedDoc, transaction))
  })
  doc.on('afterAllTransactions', () => {
    order.push('afterAllTransactions')
  })

  doc.transact(() => {
    array.insert(0, [arrayChild])
    array.insert(1, [transactionalChild])
    map.set('child', new Y.Doc({ guid: 'map-child', meta: { scope: 'map' }, autoLoad: true }))
  }, 'subdoc-add-origin')

  arrayChild.load()

  doc.transact(() => {
    transactionalChild.load()
    doc.getText('content').insert(0, 'L')
  }, 'subdoc-load-transaction-origin')

  doc.transact(() => {
    array.insert(1, [new Y.Doc({ guid: 'transient-child', meta: { scope: 'transient' }, autoLoad: true })])
    array.delete(1, 1)
  }, 'subdoc-add-delete-same-origin')

  doc.transact(() => {
    map.delete('child')
  }, 'subdoc-remove-origin')

  return {
    order,
    events,
    liveGuids: Array.from(doc.getSubdocGuids())
  }
}

const captureRemoteSubdocEvents = () => {
  const source = new Y.Doc({ guid: 'remote-subdoc-source-doc', gc: false })
  source.clientID = 184
  source.getArray('array').insert(0, [new Y.Doc({ guid: 'remote-array-child', meta: { source: 'array' }, autoLoad: true })])
  source.getMap('map').set('child', new Y.Doc({ guid: 'remote-map-child', meta: { source: 'map' }, shouldLoad: false }))
  const update = Y.encodeStateAsUpdate(source)
  const doc = new Y.Doc({ guid: 'remote-subdoc-target-doc', gc: false })
  doc.clientID = 185
  doc.getArray('array')
  doc.getMap('map')
  const firstApply = []
  const duplicateApply = []
  let activeEvents = firstApply

  doc.on('subdocs', (event, observedDoc, transaction) => {
    activeEvents.push(normalizeSubdocEvent(event, observedDoc, transaction))
  })

  Y.applyUpdate(doc, update, 'remote-subdoc-origin')
  activeEvents = duplicateApply
  Y.applyUpdate(doc, update, 'remote-subdoc-duplicate-origin')

  return {
    firstApply,
    duplicateApply,
    liveGuids: Array.from(doc.getSubdocGuids())
  }
}

const observerFixtures = {
  deepArrayPaths: captureRootArrayDeepObserverEvents(),
  deepMapPaths: captureRootMapDeepObserverEvents(),
  deepMapNestedTextPaths: captureRootMapNestedTextDeepObserverEvents(),
  deepMapNestedMapTextPaths: captureRootMapNestedMapTextDeepObserverEvents(),
  deepTextPaths: captureRootTextDeepObserverEvents(),
  rootArrayParentAndChildEvent: captureRootArrayParentAndChildObserverEvent(),
  rootMapParentAndChildEvent: captureRootMapParentAndChildObserverEvent(),
  deepNestedArrayPaths: captureNestedArrayDeepObserverEvents(),
  deepNestedMapPaths: captureNestedMapDeepObserverEvents(),
  deepNestedTextPaths: captureNestedTextDeepObserverEvents(),
  deepNestedTextAttributePaths: captureNestedTextAttributeDeepObserverEvents(),
  deepMapNestedXmlFragmentPaths: captureRootMapNestedXmlFragmentDeepObserverEvents(),
  deepNestedXmlFragmentPaths: captureNestedXmlFragmentDeepObserverEvents(),
  deepXmlPaths: captureXmlDeepObserverEvents(),
  deepXmlElementPaths: captureXmlElementDeepObserverEvents(),
  deepXmlElementSharedAttributePaths: captureXmlElementSharedAttributeDeepObserverEvents(),
  deepXmlElementSharedAttributeReplacePaths: captureXmlElementSharedAttributeReplaceDeepObserverEvents(),
  deepXmlElementBulkChildPaths: captureXmlElementBulkChildDeepObserverEvents(),
  deepXmlElementReplaceChildPaths: captureXmlElementReplaceChildDeepObserverEvents(),
  deepXmlTextPaths: captureXmlTextDeepObserverEvents(),
  deepXmlTextSharedAttributePaths: captureXmlTextSharedAttributeDeepObserverEvents(),
  deepXmlTextSharedAttributeReplacePaths: captureXmlTextSharedAttributeReplaceDeepObserverEvents(),
  deepXmlHookPaths: captureXmlHookDeepObserverEvents(),
  observerAddedDuringDispatchCalls: captureObserverAddedDuringDispatchCalls(),
  documentLifecycleOrder: captureDocumentLifecycleOrder(),
  remoteDocumentLifecycleOrder: captureRemoteDocumentLifecycleOrder(),
  subdocEvents: captureSubdocEvents(),
  remoteSubdocEvents: captureRemoteSubdocEvents(),
  reentrantTextMutation: captureReentrantTextMutation(),
  cases: [
    {
      name: 'text-insert-delete-insert',
      type: 'text',
      event: captureObserverEvent(
        'text-insert-delete-insert',
        doc => doc.getText('content'),
        text => {
          text.insert(0, 'AB')
          text.delete(1, 1)
          text.insert(1, 'C')
        }
      )
    },
    {
      name: 'text-format-range',
      type: 'text',
      event: captureObserverEvent(
        'text-format-range',
        doc => {
          const text = doc.getText('content')
          text.insert(0, 'Hello')
          return text
        },
        text => {
          text.format(1, 3, { bold: true })
        }
      )
    },
    {
      name: 'array-insert-delete-insert',
      type: 'array',
      event: captureObserverEvent(
        'array-insert-delete-insert',
        doc => doc.getArray('array'),
        array => {
          array.insert(0, ['A', 'B'])
          array.delete(0, 1)
          array.insert(1, ['C'])
        }
      )
    },
    {
      name: 'map-add-key',
      type: 'map',
      event: captureObserverEvent(
        'map-add-key',
        doc => doc.getMap('map'),
        map => {
          map.set('a', 1)
        }
      )
    },
    {
      name: 'map-update-key',
      type: 'map',
      event: captureObserverEvent(
        'map-update-key',
        doc => {
          const map = doc.getMap('map')
          map.set('a', 1)
          return map
        },
        map => {
          map.set('a', 2)
        }
      )
    },
    {
      name: 'map-delete-key',
      type: 'map',
      event: captureObserverEvent(
        'map-delete-key',
        doc => {
          const map = doc.getMap('map')
          map.set('a', 1)
          return map
        },
        map => {
          map.delete('a')
        }
      )
    },
    {
      name: 'nested-array-insert-delete-insert',
      type: 'array',
      event: captureObserverEvent(
        'nested-array-insert-delete-insert',
        doc => {
          const nested = new Y.Array()
          doc.getArray('array').insert(0, [nested])
          nested.insert(0, ['A'])
          return nested
        },
        nested => {
          nested.insert(1, ['B', 'D'])
          nested.delete(1, 1)
          nested.insert(1, ['C'])
        }
      )
    },
    {
      name: 'nested-map-update-key',
      type: 'map',
      event: captureObserverEvent(
        'nested-map-update-key',
        doc => {
          const nested = new Y.Map()
          doc.getMap('map').set('nested', nested)
          nested.set('a', 1)
          return nested
        },
        nested => {
          nested.set('a', 2)
          nested.set('b', null)
          nested.delete('b')
        }
      )
    },
    {
      name: 'nested-text-attribute-update-key',
      type: 'array',
      event: captureObserverEvent(
        'nested-text-attribute-update-key',
        doc => {
          const text = new Y.Text()
          text.insert(0, 'Hello')
          text.setAttribute('lang', 'en')
          doc.getArray('array').insert(0, [text])
          return text
        },
        text => {
          text.setAttribute('lang', 'fr')
          text.setAttribute('mark', { color: 'green' })
          text.removeAttribute('mark')
        }
      )
    },
    {
      name: 'nested-xml-fragment-insert-delete-insert',
      type: 'array',
      event: captureObserverEvent(
        'nested-xml-fragment-insert-delete-insert',
        doc => {
          const fragment = new Y.XmlFragment()
          const first = new Y.XmlText()
          first.insert(0, 'A')
          fragment.insert(0, [first])
          doc.getArray('array').insert(0, [fragment])
          return fragment
        },
        fragment => {
          const removed = new Y.XmlText()
          removed.insert(0, 'B')
          fragment.insert(1, [removed])
          fragment.delete(1, 1)
          const paragraph = new Y.XmlElement('p')
          const paragraphText = new Y.XmlText()
          paragraphText.insert(0, 'C')
          paragraph.insert(0, [paragraphText])
          fragment.insert(1, [paragraph])
        }
      )
    },
    {
      name: 'xml-fragment-insert-delete-insert',
      type: 'xml',
      event: captureObserverEvent(
        'xml-fragment-insert-delete-insert',
        doc => doc.getXmlFragment('xml'),
        xml => {
          const text = new Y.XmlText()
          text.insert(0, 'A')
          xml.insert(0, [text])
          xml.delete(0, 1)
          const paragraph = new Y.XmlElement('p')
          const paragraphText = new Y.XmlText()
          paragraphText.insert(0, 'B')
          paragraph.insert(0, [paragraphText])
          xml.insert(0, [paragraph])
        }
      )
    },
    {
      name: 'xml-fragment-hook-insert-delete',
      type: 'xml',
      event: captureObserverEvent(
        'xml-fragment-hook-insert-delete',
        doc => {
          const xml = doc.getXmlFragment('xml')
          const text = new Y.XmlText()
          text.insert(0, 'A')
          xml.insert(0, [text])
          return xml
        },
        xml => {
          xml.insert(1, [new Y.XmlHook('removed')])
          xml.delete(1, 1)
          xml.insert(1, [new Y.XmlHook('kept')])
        }
      )
    },
    {
      name: 'xml-element-attribute-update',
      type: 'xml',
      event: captureObserverEvent(
        'xml-element-attribute-update',
        doc => {
          const paragraph = new Y.XmlElement('p')
          doc.getXmlFragment('xml').insert(0, [paragraph])
          return paragraph
        },
        paragraph => {
          paragraph.setAttribute('class', 'lead')
          paragraph.setAttribute('class', 'quiet')
          paragraph.removeAttribute('class')
        }
      )
    },
    {
      name: 'xml-element-attribute-add-key',
      type: 'xml',
      event: captureObserverEvent(
        'xml-element-attribute-add-key',
        doc => {
          const paragraph = new Y.XmlElement('p')
          doc.getXmlFragment('xml').insert(0, [paragraph])
          return paragraph
        },
        paragraph => {
          paragraph.setAttribute('class', 'lead')
        }
      )
    },
    {
      name: 'xml-element-attribute-update-key',
      type: 'xml',
      event: captureObserverEvent(
        'xml-element-attribute-update-key',
        doc => {
          const paragraph = new Y.XmlElement('p')
          paragraph.setAttribute('class', 'base')
          doc.getXmlFragment('xml').insert(0, [paragraph])
          return paragraph
        },
        paragraph => {
          paragraph.setAttribute('class', 'lead')
        }
      )
    },
    {
      name: 'xml-element-attribute-delete-key',
      type: 'xml',
      event: captureObserverEvent(
        'xml-element-attribute-delete-key',
        doc => {
          const paragraph = new Y.XmlElement('p')
          paragraph.setAttribute('class', 'base')
          doc.getXmlFragment('xml').insert(0, [paragraph])
          return paragraph
        },
        paragraph => {
          paragraph.removeAttribute('class')
        }
      )
    },
    {
      name: 'xml-element-child-insert-delete',
      type: 'xml',
      event: captureObserverEvent(
        'xml-element-child-insert-delete',
        doc => {
          const paragraph = new Y.XmlElement('p')
          const first = new Y.XmlText()
          first.insert(0, 'A')
          paragraph.insert(0, [first])
          doc.getXmlFragment('xml').insert(0, [paragraph])
          return paragraph
        },
        paragraph => {
          const removed = new Y.XmlText()
          removed.insert(0, 'B')
          paragraph.insert(1, [removed])
          paragraph.delete(1, 1)
          const strong = new Y.XmlElement('strong')
          const strongText = new Y.XmlText()
          strongText.insert(0, 'C')
          strong.insert(0, [strongText])
          paragraph.insert(1, [strong])
        }
      )
    },
    {
      name: 'xml-element-child-replace-middle',
      type: 'xml',
      event: captureObserverEvent(
        'xml-element-child-replace-middle',
        doc => {
          const paragraph = new Y.XmlElement('p')
          const first = new Y.XmlText()
          first.insert(0, 'A')
          const middle = new Y.XmlElement('em')
          const middleText = new Y.XmlText()
          middleText.insert(0, 'B')
          middle.insert(0, [middleText])
          const last = new Y.XmlText()
          last.insert(0, 'C')
          paragraph.insert(0, [first, middle, last])
          doc.getXmlFragment('xml').insert(0, [paragraph])
          return paragraph
        },
        paragraph => {
          paragraph.delete(1, 1)
          const strong = new Y.XmlElement('strong')
          const strongText = new Y.XmlText()
          strongText.insert(0, 'D')
          strong.insert(0, [strongText])
          paragraph.insert(1, [strong])
        }
      )
    },
    {
      name: 'xml-element-child-and-text-update',
      type: 'xml',
      event: captureObserverEvent(
        'xml-element-child-and-text-update',
        doc => {
          const paragraph = new Y.XmlElement('p')
          const first = new Y.XmlText()
          first.insert(0, 'A')
          const middle = new Y.XmlElement('em')
          const middleText = new Y.XmlText()
          middleText.insert(0, 'B')
          middle.insert(0, [middleText])
          paragraph.insert(0, [first, middle])
          doc.getXmlFragment('xml').insert(0, [paragraph])
          return paragraph
        },
        paragraph => {
          paragraph.get(0).insert(1, '!')
          paragraph.delete(1, 1)
          const strong = new Y.XmlElement('strong')
          const strongText = new Y.XmlText()
          strongText.insert(0, 'C')
          strong.insert(0, [strongText])
          paragraph.insert(1, [strong])
        }
      )
    },
    {
      name: 'xml-fragment-delete-middle-range',
      type: 'xml',
      event: captureObserverEvent(
        'xml-fragment-delete-middle-range',
        doc => {
          const xml = doc.getXmlFragment('xml')
          xml.insert(0, [
            new Y.XmlElement('a'),
            new Y.XmlElement('b'),
            new Y.XmlElement('c'),
            new Y.XmlElement('d')
          ])
          return xml
        },
        xml => {
          xml.delete(1, 2)
        }
      )
    },
    {
      name: 'xml-fragment-insert-multiple-elements',
      type: 'xml',
      event: captureObserverEvent(
        'xml-fragment-insert-multiple-elements',
        doc => doc.getXmlFragment('xml'),
        xml => {
          xml.insert(0, [
            new Y.XmlElement('a'),
            new Y.XmlElement('b')
          ])
        }
      )
    },
    {
      name: 'xml-element-insert-multiple-child-types',
      type: 'xml',
      event: captureObserverEvent(
        'xml-element-insert-multiple-child-types',
        doc => {
          const paragraph = new Y.XmlElement('p')
          doc.getXmlFragment('xml').insert(0, [paragraph])
          return paragraph
        },
        paragraph => {
          const text = new Y.XmlText()
          text.insert(0, 'A')
          paragraph.insert(0, [
            text,
            new Y.XmlElement('em'),
            new Y.XmlHook('mention')
          ])
        }
      )
    },
    {
      name: 'xml-element-hook-insert-delete',
      type: 'xml',
      event: captureObserverEvent(
        'xml-element-hook-insert-delete',
        doc => {
          const paragraph = new Y.XmlElement('p')
          const first = new Y.XmlText()
          first.insert(0, 'A')
          paragraph.insert(0, [first])
          doc.getXmlFragment('xml').insert(0, [paragraph])
          return paragraph
        },
        paragraph => {
          paragraph.insert(1, [new Y.XmlHook('removed')])
          paragraph.delete(1, 1)
          paragraph.insert(1, [new Y.XmlHook('kept')])
        }
      )
    },
    {
      name: 'xml-hook-map-update-key',
      type: 'xml',
      event: captureObserverEvent(
        'xml-hook-map-update-key',
        doc => {
          const hook = new Y.XmlHook('mention')
          doc.getXmlFragment('xml').insert(0, [hook])
          hook.set('role', 'base')
          return hook
        },
        hook => {
          hook.set('role', 'lead')
          hook.set('count', 2)
          hook.delete('count')
        }
      )
    },
    {
      name: 'xml-hook-map-add-update-delete-keys',
      type: 'xml',
      event: captureObserverEvent(
        'xml-hook-map-add-update-delete-keys',
        doc => {
          const hook = new Y.XmlHook('mention')
          doc.getXmlFragment('xml').insert(0, [hook])
          hook.set('role', 'base')
          hook.set('removeMe', true)
          return hook
        },
        hook => {
          hook.set('role', 'lead')
          hook.set('active', true)
          hook.delete('removeMe')
        }
      )
    },
    {
      name: 'xml-text-format-range',
      type: 'xml',
      event: captureObserverEvent(
        'xml-text-format-range',
        doc => {
          const text = new Y.XmlText()
          text.insert(0, 'Hello')
          doc.getXmlFragment('xml').insert(0, [text])
          return text
        },
        text => {
          text.format(1, 3, { bold: true })
        }
      )
    },
    {
      name: 'xml-text-attribute-update-key',
      type: 'xml',
      event: captureObserverEvent(
        'xml-text-attribute-update-key',
        doc => {
          const text = new Y.XmlText()
          text.insert(0, 'Hello')
          text.setAttribute('lang', 'en')
          doc.getXmlFragment('xml').insert(0, [text])
          return text
        },
        text => {
          text.setAttribute('lang', 'fr')
          text.setAttribute('mark', { color: 'green' })
          text.removeAttribute('mark')
        }
      )
    },
    {
      name: 'xml-text-shared-attribute-update-key',
      type: 'xml',
      event: captureObserverEvent(
        'xml-text-shared-attribute-update-key',
        doc => {
          const text = new Y.XmlText()
          text.insert(0, 'Hello')
          doc.getXmlFragment('xml').insert(0, [text])
          const body = new Y.Text()
          text.setAttribute('body', body)
          body.insert(0, 'Old')
          return text
        },
        text => {
          text.setAttribute('body', 'plain')
          text.setAttribute('inline', new Y.XmlElement('span'))
        }
      )
    }
  ]
}

fs.writeFileSync(
  path.join(outDir, 'observer-events.json'),
  `${JSON.stringify(observerFixtures, null, 2)}\n`
)

const transactionLocalDoc = new Y.Doc({ guid: 'transaction-local-doc', gc: false })
transactionLocalDoc.clientID = 301
const transactionLocalEvents = []
transactionLocalDoc.on('afterTransaction', transaction => {
  transactionLocalEvents.push(normalizeTransactionEvent(transaction))
})
transactionLocalDoc.transact(() => {
  const text = transactionLocalDoc.getText('content')
  text.insert(0, 'ABCD')
  text.delete(1, 2)
}, 'local-transaction-origin')

const transactionRemoteSource = new Y.Doc({ guid: 'transaction-remote-source', gc: false })
transactionRemoteSource.clientID = 302
transactionRemoteSource.getText('content').insert(0, 'ABCD')
transactionRemoteSource.getText('content').delete(1, 2)
const transactionRemoteUpdateV1 = Y.encodeStateAsUpdate(transactionRemoteSource)
const transactionRemoteUpdateV2 = Y.encodeStateAsUpdateV2(transactionRemoteSource)
const transactionRemoteTarget = new Y.Doc({ guid: 'transaction-remote-target', gc: false })
transactionRemoteTarget.getText('content')
const transactionRemoteEvents = []
transactionRemoteTarget.on('afterTransaction', transaction => {
  transactionRemoteEvents.push(normalizeTransactionEvent(transaction))
})
Y.applyUpdate(transactionRemoteTarget, transactionRemoteUpdateV1, 'remote-transaction-origin')

const transactionRootMixedDoc = new Y.Doc({ guid: 'transaction-root-mixed-doc', gc: false })
transactionRootMixedDoc.clientID = 303
const transactionRootMixedEvents = []
transactionRootMixedDoc.on('afterTransaction', transaction => {
  transactionRootMixedEvents.push(normalizeTransactionEvent(transaction))
})
transactionRootMixedDoc.transact(() => {
  transactionRootMixedDoc.getText('content').insert(0, 'Hi')
  transactionRootMixedDoc.getArray('array').insert(0, ['A'])
  transactionRootMixedDoc.getMap('map').set('title', 'Doc')
  transactionRootMixedDoc.getXmlFragment('xml').insert(0, [new Y.XmlElement('p')])
}, 'local-root-mixed-origin')

const transactionNestedArrayDoc = new Y.Doc({ guid: 'transaction-nested-array-doc', gc: false })
transactionNestedArrayDoc.clientID = 304
const transactionNestedArrayRoot = transactionNestedArrayDoc.getArray('array')
const transactionNestedArrayChild = new Y.Array()
transactionNestedArrayRoot.insert(0, [transactionNestedArrayChild])
const transactionNestedArrayEvents = []
transactionNestedArrayDoc.on('afterTransaction', transaction => {
  transactionNestedArrayEvents.push(normalizeTransactionEvent(transaction))
})
transactionNestedArrayDoc.transact(() => {
  transactionNestedArrayChild.insert(0, ['A', 'B'])
  transactionNestedArrayChild.delete(0, 1)
}, 'local-nested-array-origin')

const transactionArrayNewNestedTextDoc = new Y.Doc({ guid: 'transaction-array-new-nested-text-doc', gc: false })
transactionArrayNewNestedTextDoc.clientID = 313
const transactionArrayNewNestedTextRoot = transactionArrayNewNestedTextDoc.getArray('array')
const transactionArrayNewNestedTextEvents = []
transactionArrayNewNestedTextDoc.on('afterTransaction', transaction => {
  transactionArrayNewNestedTextEvents.push(normalizeTransactionEvent(transaction))
})
transactionArrayNewNestedTextDoc.transact(() => {
  const text = new Y.Text()
  transactionArrayNewNestedTextRoot.insert(0, [text])
  text.insert(0, 'Nested')
  text.format(0, 6, { bold: true })
}, 'local-array-new-nested-text-origin')

const transactionXmlParentDoc = new Y.Doc({ guid: 'transaction-xml-parent-doc', gc: false })
transactionXmlParentDoc.clientID = 305
const transactionXmlFragment = transactionXmlParentDoc.getXmlFragment('xml')
const transactionXmlParagraph = new Y.XmlElement('p')
const transactionXmlText = new Y.XmlText()
transactionXmlText.insert(0, 'Hi')
transactionXmlParagraph.insert(0, [transactionXmlText])
transactionXmlFragment.insert(0, [transactionXmlParagraph])
const transactionXmlParentEvents = []
transactionXmlParentDoc.on('afterTransaction', transaction => {
  transactionXmlParentEvents.push(normalizeTransactionEvent(transaction))
})
transactionXmlParentDoc.transact(() => {
  transactionXmlParagraph.setAttribute('class', 'lead')
  transactionXmlText.insert(2, '!')
}, 'local-xml-parent-chain-origin')

const transactionMapReplaceDeleteDoc = new Y.Doc({ guid: 'transaction-map-replace-delete-doc', gc: false })
transactionMapReplaceDeleteDoc.clientID = 306
const transactionMapReplaceDeleteMap = transactionMapReplaceDeleteDoc.getMap('map')
transactionMapReplaceDeleteMap.set('title', 'Draft')
transactionMapReplaceDeleteMap.set('status', 'ready')
const transactionMapReplaceDeleteEvents = []
transactionMapReplaceDeleteDoc.on('afterTransaction', transaction => {
  transactionMapReplaceDeleteEvents.push(normalizeTransactionEvent(transaction))
})
transactionMapReplaceDeleteDoc.transact(() => {
  transactionMapReplaceDeleteMap.set('title', 'Published')
  transactionMapReplaceDeleteMap.delete('status')
  transactionMapReplaceDeleteMap.set('flag', true)
}, 'local-map-replace-delete-origin')

const transactionXmlTextAttributeDoc = new Y.Doc({ guid: 'transaction-xml-text-attribute-doc', gc: false })
transactionXmlTextAttributeDoc.clientID = 307
const transactionXmlTextAttributeText = new Y.XmlText()
transactionXmlTextAttributeText.insert(0, 'Xml')
transactionXmlTextAttributeText.setAttribute('lang', 'en')
transactionXmlTextAttributeDoc.getXmlFragment('xml').insert(0, [transactionXmlTextAttributeText])
const transactionXmlTextAttributeEvents = []
transactionXmlTextAttributeDoc.on('afterTransaction', transaction => {
  transactionXmlTextAttributeEvents.push(normalizeTransactionEvent(transaction))
})
transactionXmlTextAttributeDoc.transact(() => {
  transactionXmlTextAttributeText.setAttribute('lang', 'fr')
  transactionXmlTextAttributeText.setAttribute('mark', { color: 'green' })
}, 'local-xml-text-attribute-origin')

const transactionXmlHookAttributeDoc = new Y.Doc({ guid: 'transaction-xml-hook-attribute-doc', gc: false })
transactionXmlHookAttributeDoc.clientID = 308
const transactionXmlHookAttributeHook = new Y.XmlHook('mention')
transactionXmlHookAttributeHook.set('role', 'base')
transactionXmlHookAttributeHook.set('removeMe', true)
transactionXmlHookAttributeDoc.getXmlFragment('xml').insert(0, [transactionXmlHookAttributeHook])
const transactionXmlHookAttributeEvents = []
transactionXmlHookAttributeDoc.on('afterTransaction', transaction => {
  transactionXmlHookAttributeEvents.push(normalizeTransactionEvent(transaction))
})
transactionXmlHookAttributeDoc.transact(() => {
  transactionXmlHookAttributeHook.set('role', 'lead')
  transactionXmlHookAttributeHook.set('active', true)
  transactionXmlHookAttributeHook.delete('removeMe')
}, 'local-xml-hook-attribute-origin')

const transactionXmlElementSharedAttributeDoc = new Y.Doc({ guid: 'transaction-xml-element-shared-attribute-doc', gc: false })
transactionXmlElementSharedAttributeDoc.clientID = 312
const transactionXmlElementSharedAttributeElement = new Y.XmlElement('p')
transactionXmlElementSharedAttributeElement.setAttribute('role', 'base')
const transactionXmlElementSharedAttributeBody = new Y.Text()
transactionXmlElementSharedAttributeElement.setAttribute('body', transactionXmlElementSharedAttributeBody)
const transactionXmlElementSharedAttributeInline = new Y.XmlElement('span')
transactionXmlElementSharedAttributeElement.setAttribute('inline', transactionXmlElementSharedAttributeInline)
transactionXmlElementSharedAttributeDoc.getXmlFragment('xml').insert(0, [transactionXmlElementSharedAttributeElement])
const transactionXmlElementSharedAttributeEvents = []
transactionXmlElementSharedAttributeDoc.on('afterTransaction', transaction => {
  transactionXmlElementSharedAttributeEvents.push(normalizeTransactionEvent(transaction))
})
transactionXmlElementSharedAttributeDoc.transact(() => {
  transactionXmlElementSharedAttributeElement.setAttribute('role', 'lead')
  transactionXmlElementSharedAttributeBody.insert(0, 'Body')
  transactionXmlElementSharedAttributeInline.setAttribute('class', 'lead')
  const inlineText = new Y.XmlText()
  inlineText.insert(0, 'Inline')
  transactionXmlElementSharedAttributeInline.insert(0, [inlineText])
}, 'local-xml-element-shared-attribute-origin')

const transactionXmlTextSharedAttributeDoc = new Y.Doc({ guid: 'transaction-xml-text-shared-attribute-doc', gc: false })
transactionXmlTextSharedAttributeDoc.clientID = 314
const transactionXmlTextSharedAttributeText = new Y.XmlText()
transactionXmlTextSharedAttributeText.insert(0, 'Xml')
transactionXmlTextSharedAttributeText.setAttribute('role', 'base')
const transactionXmlTextSharedAttributeBody = new Y.Text()
transactionXmlTextSharedAttributeText.setAttribute('body', transactionXmlTextSharedAttributeBody)
const transactionXmlTextSharedAttributeInline = new Y.XmlElement('span')
transactionXmlTextSharedAttributeText.setAttribute('inline', transactionXmlTextSharedAttributeInline)
transactionXmlTextSharedAttributeDoc.getXmlFragment('xml').insert(0, [transactionXmlTextSharedAttributeText])
const transactionXmlTextSharedAttributeEvents = []
transactionXmlTextSharedAttributeDoc.on('afterTransaction', transaction => {
  transactionXmlTextSharedAttributeEvents.push(normalizeTransactionEvent(transaction))
})
transactionXmlTextSharedAttributeDoc.transact(() => {
  transactionXmlTextSharedAttributeText.setAttribute('role', 'lead')
  transactionXmlTextSharedAttributeBody.insert(0, 'Body')
  transactionXmlTextSharedAttributeInline.setAttribute('class', 'lead')
  const inlineText = new Y.XmlText()
  inlineText.insert(0, 'Inline')
  transactionXmlTextSharedAttributeInline.insert(0, [inlineText])
}, 'local-xml-text-shared-attribute-origin')

const transactionNestedTextAttributeDoc = new Y.Doc({ guid: 'transaction-nested-text-attribute-doc', gc: false })
transactionNestedTextAttributeDoc.clientID = 309
const transactionNestedTextAttributeText = new Y.Text()
transactionNestedTextAttributeText.insert(0, 'Nested')
transactionNestedTextAttributeText.setAttribute('lang', 'en')
transactionNestedTextAttributeDoc.getArray('array').insert(0, [transactionNestedTextAttributeText])
const transactionNestedTextAttributeEvents = []
transactionNestedTextAttributeDoc.on('afterTransaction', transaction => {
  transactionNestedTextAttributeEvents.push(normalizeTransactionEvent(transaction))
})
transactionNestedTextAttributeDoc.transact(() => {
  transactionNestedTextAttributeText.setAttribute('lang', 'fr')
  transactionNestedTextAttributeText.setAttribute('mark', { color: 'green' })
  transactionNestedTextAttributeText.removeAttribute('mark')
}, 'local-nested-text-attribute-origin')

const transactionMapTextDoc = new Y.Doc({ guid: 'transaction-map-text-doc', gc: false })
transactionMapTextDoc.clientID = 310
const transactionMapTextText = new Y.Text()
transactionMapTextText.insert(0, 'Map')
transactionMapTextDoc.getMap('map').set('body', transactionMapTextText)
const transactionMapTextEvents = []
transactionMapTextDoc.on('afterTransaction', transaction => {
  transactionMapTextEvents.push(normalizeTransactionEvent(transaction))
})
transactionMapTextDoc.transact(() => {
  transactionMapTextText.setAttribute('lang', 'en')
  transactionMapTextText.insert(3, ' text', { emphasis: true })
}, 'local-map-text-origin')

const transactionEventFixtures = {
  local: {
    json: transactionLocalDoc.toJSON(),
    event: transactionLocalEvents[0]
  },
  remote: {
    updateV1: toBase64(transactionRemoteUpdateV1),
    updateV2: toBase64(transactionRemoteUpdateV2),
    json: transactionRemoteTarget.toJSON(),
    event: transactionRemoteEvents[0]
  },
  cases: [
    {
      name: 'local-root-mixed',
      json: transactionRootMixedDoc.toJSON(),
      event: transactionRootMixedEvents[0]
    },
    {
      name: 'local-nested-array',
      json: transactionNestedArrayDoc.toJSON(),
      event: transactionNestedArrayEvents[0]
    },
    {
      name: 'local-array-new-nested-text',
      json: transactionArrayNewNestedTextDoc.toJSON(),
      event: transactionArrayNewNestedTextEvents[0]
    },
    {
      name: 'local-xml-parent-chain',
      json: transactionXmlParentDoc.toJSON(),
      event: transactionXmlParentEvents[0]
    },
    {
      name: 'local-map-replace-delete',
      json: transactionMapReplaceDeleteDoc.toJSON(),
      event: transactionMapReplaceDeleteEvents[0]
    },
    {
      name: 'local-xml-text-attribute',
      json: transactionXmlTextAttributeDoc.toJSON(),
      event: transactionXmlTextAttributeEvents[0]
    },
    {
      name: 'local-xml-hook-attribute',
      json: transactionXmlHookAttributeDoc.toJSON(),
      event: transactionXmlHookAttributeEvents[0]
    },
    {
      name: 'local-xml-element-shared-attribute',
      json: transactionXmlElementSharedAttributeDoc.toJSON(),
      event: transactionXmlElementSharedAttributeEvents[0]
    },
    {
      name: 'local-xml-text-shared-attribute',
      json: transactionXmlTextSharedAttributeDoc.toJSON(),
      event: transactionXmlTextSharedAttributeEvents[0]
    },
    {
      name: 'local-nested-text-attribute',
      json: transactionNestedTextAttributeDoc.toJSON(),
      event: transactionNestedTextAttributeEvents[0]
    },
    {
      name: 'local-map-text',
      json: transactionMapTextDoc.toJSON(),
      event: transactionMapTextEvents[0]
    }
  ]
}

fs.writeFileSync(
  path.join(outDir, 'transaction-events.json'),
  `${JSON.stringify(transactionEventFixtures, null, 2)}\n`
)

const undoManagerOriginCase = (name, options, origin) => {
  const doc = new Y.Doc({ guid: `${name}-doc`, gc: false })
  doc.clientID = 306
  const text = doc.getText('content')
  text.insert(0, 'A')
  const undoManager = new Y.UndoManager(text, options)
  doc.transact(() => {
    text.insert(1, 'B')
  }, origin)

  return {
    name,
    canUndo: undoManager.canUndo(),
    undoStackLength: undoManager.undoStack.length,
    text: text.toString()
  }
}

class UndoTrackedOrigin {}

const undoManagerConstructorTrackedOriginCase = () => {
  const doc = new Y.Doc({ guid: 'constructor-tracked-origin-doc', gc: false })
  doc.clientID = 311
  const text = doc.getText('content')
  text.insert(0, 'A')
  const origin = new UndoTrackedOrigin()
  const undoManager = new Y.UndoManager(text, {
    trackedOrigins: new Set([UndoTrackedOrigin])
  })
  doc.transact(() => {
    text.insert(1, 'B')
  }, origin)

  return {
    name: 'constructor-tracked-origin',
    canUndo: undoManager.canUndo(),
    undoStackLength: undoManager.undoStack.length,
    text: text.toString()
  }
}

const undoManagerSelfTrackedOriginCase = () => {
  const doc = new Y.Doc({ guid: 'self-tracked-origin-doc', gc: false })
  doc.clientID = 312
  const text = doc.getText('content')
  text.insert(0, 'A')
  const undoManager = new Y.UndoManager(text)
  doc.transact(() => {
    text.insert(1, 'B')
  }, undoManager)

  return {
    name: 'self-tracked-origin',
    canUndo: undoManager.canUndo(),
    undoStackLength: undoManager.undoStack.length,
    text: text.toString()
  }
}

const undoManagerCaptureTransactionCase = () => {
  const doc = new Y.Doc({ guid: 'capture-transaction-filter-doc', gc: false })
  doc.clientID = 307
  const text = doc.getText('content')
  text.insert(0, 'A')
  const undoManager = new Y.UndoManager(text, {
    trackedOrigins: new Set(['skip-origin', 'keep-origin']),
    captureTransaction: transaction => transaction.origin !== 'skip-origin'
  })
  doc.transact(() => {
    text.insert(1, 'B')
  }, 'skip-origin')
  doc.transact(() => {
    text.insert(2, 'C')
  }, 'keep-origin')
  const canUndo = undoManager.canUndo()
  const undoStackLength = undoManager.undoStack.length
  undoManager.undo()

  return {
    name: 'capture-transaction-filter',
    canUndo,
    undoStackLength,
    textBeforeUndo: 'ABC',
    textAfterUndo: text.toString()
  }
}

const undoManagerDeleteFilterCase = () => {
  const doc = new Y.Doc({ guid: 'delete-filter-doc', gc: false })
  doc.clientID = 312
  const text = doc.getText('content')
  text.insert(0, 'A')
  const undoManager = new Y.UndoManager(text, {
    deleteFilter: item => item.content?.str !== 'B'
  })
  text.insert(1, 'B')
  const canUndoBeforeUndo = undoManager.canUndo()
  const undoStackLengthBeforeUndo = undoManager.undoStack.length
  const undoResult = undoManager.undo()

  return {
    name: 'delete-filter-keeps-inserted-text',
    canUndoBeforeUndo,
    undoStackLengthBeforeUndo,
    undoReturnedStackItem: undoResult !== null,
    textAfterUndo: text.toString(),
    canUndoAfterUndo: undoManager.canUndo(),
    canRedoAfterUndo: undoManager.canRedo(),
    undoStackLengthAfterUndo: undoManager.undoStack.length,
    redoStackLengthAfterUndo: undoManager.redoStack.length
  }
}

const undoManagerRemoteMapConflictCase = ignoreRemoteMapChanges => {
  const local = new Y.Doc({ guid: `remote-map-conflict-${ignoreRemoteMapChanges ? 'overwrite' : 'preserve'}-doc`, gc: false })
  local.clientID = 320
  const map = local.getMap('map')
  map.set('title', 'A')
  const remote = new Y.Doc({ guid: `remote-map-conflict-${ignoreRemoteMapChanges ? 'overwrite' : 'preserve'}-remote-doc`, gc: false })
  remote.clientID = 321
  Y.applyUpdate(remote, Y.encodeStateAsUpdate(local))
  const undoManager = new Y.UndoManager(map, {
    captureTimeout: 0,
    ignoreRemoteMapChanges
  })
  map.set('title', 'B')
  remote.getMap('map').set('title', 'Remote')
  Y.applyUpdate(local, Y.encodeStateAsUpdate(remote, Y.encodeStateVector(local)), 'remote-origin')
  const beforeUndo = local.toJSON()
  const undoResult = undoManager.undo()

  return {
    name: ignoreRemoteMapChanges ? 'remote-map-overwrite' : 'remote-map-preserve',
    ignoreRemoteMapChanges,
    beforeUndo,
    afterUndo: local.toJSON(),
    undoReturnedStackItem: undoResult !== null,
    canUndoAfterUndo: undoManager.canUndo(),
    canRedoAfterUndo: undoManager.canRedo(),
    undoStackLengthAfterUndo: undoManager.undoStack.length,
    redoStackLengthAfterUndo: undoManager.redoStack.length
  }
}

const undoManagerAddToScopeCase = () => {
  const doc = new Y.Doc({ guid: 'add-to-scope-doc', gc: false })
  doc.clientID = 313
  const text = doc.getText('content')
  const map = doc.getMap('map')
  text.insert(0, 'A')
  map.set('title', 'Draft')
  const undoManager = new Y.UndoManager(text, { captureTimeout: 0 })
  undoManager.addToScope(map)
  map.set('title', 'Published')
  const canUndo = undoManager.canUndo()
  const undoStackLength = undoManager.undoStack.length
  const undoResult = undoManager.undo()

  return {
    name: 'add-to-scope-map',
    canUndo,
    undoStackLength,
    undoReturnedStackItem: undoResult !== null,
    afterUndo: doc.toJSON(),
    canRedoAfterUndo: undoManager.canRedo(),
    redoStackLengthAfterUndo: undoManager.redoStack.length
  }
}

const undoManagerTextAttributeCase = () => {
  const doc = new Y.Doc({ guid: 'undo-text-attribute-only-doc', gc: false })
  doc.clientID = 316
  const text = doc.getText('content')
  text.insert(0, 'Text')
  text.setAttribute('lang', 'en')
  const undoManager = new Y.UndoManager(text, {
    captureTimeout: 0,
    trackedOrigins: new Set(['text-attribute-edit'])
  })
  const events = []
  undoManager.on('stack-item-added', event => {
    events.push({ type: 'stack-item-added', stack: event.type })
  })
  undoManager.on('stack-item-popped', event => {
    events.push({ type: 'stack-item-popped', stack: event.type })
  })

  doc.transact(() => {
    text.setAttribute('lang', 'fr')
    text.setAttribute('mark', { color: 'green' })
  }, 'text-attribute-edit')
  const beforeUndo = {
    json: doc.toJSON(),
    text: text.toString(),
    attributes: normalizeValue(text.getAttributes()),
    canUndo: undoManager.canUndo(),
    canRedo: undoManager.canRedo(),
    undoStackLength: undoManager.undoStack.length,
    redoStackLength: undoManager.redoStack.length
  }
  const undoResult = undoManager.undo()
  const afterUndo = {
    undoReturnedStackItem: undoResult !== null,
    json: doc.toJSON(),
    text: text.toString(),
    attributes: normalizeValue(text.getAttributes()),
    canUndo: undoManager.canUndo(),
    canRedo: undoManager.canRedo(),
    undoStackLength: undoManager.undoStack.length,
    redoStackLength: undoManager.redoStack.length
  }
  const redoResult = undoManager.redo()

  return {
    name: 'root-text-attribute-only',
    beforeUndo,
    afterUndo,
    afterRedo: {
      redoReturnedStackItem: redoResult !== null,
      json: doc.toJSON(),
      text: text.toString(),
      attributes: normalizeValue(text.getAttributes()),
      canUndo: undoManager.canUndo(),
      canRedo: undoManager.canRedo(),
      undoStackLength: undoManager.undoStack.length,
      redoStackLength: undoManager.redoStack.length,
      events
    }
  }
}

const undoManagerTextContentAndAttributeCase = () => {
  const doc = new Y.Doc({ guid: 'undo-text-content-and-attributes-doc', gc: false })
  doc.clientID = 317
  const text = doc.getText('content')
  text.insert(0, 'Text')
  text.setAttribute('lang', 'en')
  const undoManager = new Y.UndoManager(text, {
    captureTimeout: 0,
    trackedOrigins: new Set(['text-content-and-attribute-edit'])
  })
  const events = []
  undoManager.on('stack-item-added', event => {
    events.push({ type: 'stack-item-added', stack: event.type })
  })
  undoManager.on('stack-item-popped', event => {
    events.push({ type: 'stack-item-popped', stack: event.type })
  })

  doc.transact(() => {
    text.insert(4, '!')
    text.setAttribute('lang', 'fr')
    text.setAttribute('mark', { color: 'green' })
  }, 'text-content-and-attribute-edit')
  const beforeUndo = {
    json: doc.toJSON(),
    text: text.toString(),
    attributes: normalizeValue(text.getAttributes()),
    canUndo: undoManager.canUndo(),
    canRedo: undoManager.canRedo(),
    undoStackLength: undoManager.undoStack.length,
    redoStackLength: undoManager.redoStack.length
  }
  const undoResult = undoManager.undo()
  const afterUndo = {
    undoReturnedStackItem: undoResult !== null,
    json: doc.toJSON(),
    text: text.toString(),
    attributes: normalizeValue(text.getAttributes()),
    canUndo: undoManager.canUndo(),
    canRedo: undoManager.canRedo(),
    undoStackLength: undoManager.undoStack.length,
    redoStackLength: undoManager.redoStack.length
  }
  const redoResult = undoManager.redo()

  return {
    name: 'root-text-content-and-attributes',
    beforeUndo,
    afterUndo,
    afterRedo: {
      redoReturnedStackItem: redoResult !== null,
      json: doc.toJSON(),
      text: text.toString(),
      attributes: normalizeValue(text.getAttributes()),
      canUndo: undoManager.canUndo(),
      canRedo: undoManager.canRedo(),
      undoStackLength: undoManager.undoStack.length,
      redoStackLength: undoManager.redoStack.length,
      events
    }
  }
}

const undoManagerNestedTextAttributeCase = () => {
  const doc = new Y.Doc({ guid: 'undo-nested-text-attribute-only-doc', gc: false })
  doc.clientID = 318
  const text = new Y.Text()
  doc.getArray('array').insert(0, [text])
  text.insert(0, 'Nested')
  text.setAttribute('lang', 'en')
  const undoManager = new Y.UndoManager(text, {
    captureTimeout: 0,
    trackedOrigins: new Set(['nested-text-attribute-edit'])
  })
  const events = []
  undoManager.on('stack-item-added', event => {
    events.push({ type: 'stack-item-added', stack: event.type })
  })
  undoManager.on('stack-item-popped', event => {
    events.push({ type: 'stack-item-popped', stack: event.type })
  })

  doc.transact(() => {
    text.setAttribute('lang', 'fr')
    text.setAttribute('mark', { color: 'green' })
  }, 'nested-text-attribute-edit')
  const beforeUndo = {
    json: doc.toJSON(),
    text: text.toString(),
    attributes: normalizeValue(text.getAttributes()),
    canUndo: undoManager.canUndo(),
    canRedo: undoManager.canRedo(),
    undoStackLength: undoManager.undoStack.length,
    redoStackLength: undoManager.redoStack.length
  }
  const undoResult = undoManager.undo()
  const afterUndo = {
    undoReturnedStackItem: undoResult !== null,
    json: doc.toJSON(),
    text: text.toString(),
    attributes: normalizeValue(text.getAttributes()),
    canUndo: undoManager.canUndo(),
    canRedo: undoManager.canRedo(),
    undoStackLength: undoManager.undoStack.length,
    redoStackLength: undoManager.redoStack.length
  }
  const redoResult = undoManager.redo()

  return {
    name: 'nested-text-attribute-only',
    beforeUndo,
    afterUndo,
    afterRedo: {
      redoReturnedStackItem: redoResult !== null,
      json: doc.toJSON(),
      text: text.toString(),
      attributes: normalizeValue(text.getAttributes()),
      canUndo: undoManager.canUndo(),
      canRedo: undoManager.canRedo(),
      undoStackLength: undoManager.undoStack.length,
      redoStackLength: undoManager.redoStack.length,
      events
    }
  }
}

const undoManagerNestedTextContentAndAttributeCase = () => {
  const doc = new Y.Doc({ guid: 'undo-nested-text-content-and-attributes-doc', gc: false })
  doc.clientID = 319
  const text = new Y.Text()
  doc.getArray('array').insert(0, [text])
  text.insert(0, 'Nested')
  text.setAttribute('lang', 'en')
  const undoManager = new Y.UndoManager(text, {
    captureTimeout: 0,
    trackedOrigins: new Set(['nested-text-content-and-attribute-edit'])
  })
  const events = []
  undoManager.on('stack-item-added', event => {
    events.push({ type: 'stack-item-added', stack: event.type })
  })
  undoManager.on('stack-item-popped', event => {
    events.push({ type: 'stack-item-popped', stack: event.type })
  })

  doc.transact(() => {
    text.insert(6, '!')
    text.setAttribute('lang', 'fr')
    text.setAttribute('mark', { color: 'green' })
  }, 'nested-text-content-and-attribute-edit')
  const beforeUndo = {
    json: doc.toJSON(),
    text: text.toString(),
    attributes: normalizeValue(text.getAttributes()),
    canUndo: undoManager.canUndo(),
    canRedo: undoManager.canRedo(),
    undoStackLength: undoManager.undoStack.length,
    redoStackLength: undoManager.redoStack.length
  }
  const undoResult = undoManager.undo()
  const afterUndo = {
    undoReturnedStackItem: undoResult !== null,
    json: doc.toJSON(),
    text: text.toString(),
    attributes: normalizeValue(text.getAttributes()),
    canUndo: undoManager.canUndo(),
    canRedo: undoManager.canRedo(),
    undoStackLength: undoManager.undoStack.length,
    redoStackLength: undoManager.redoStack.length
  }
  const redoResult = undoManager.redo()

  return {
    name: 'nested-text-content-and-attributes',
    beforeUndo,
    afterUndo,
    afterRedo: {
      redoReturnedStackItem: redoResult !== null,
      json: doc.toJSON(),
      text: text.toString(),
      attributes: normalizeValue(text.getAttributes()),
      canUndo: undoManager.canUndo(),
      canRedo: undoManager.canRedo(),
      undoStackLength: undoManager.undoStack.length,
      redoStackLength: undoManager.redoStack.length,
      events
    }
  }
}

const nestedXmlFragmentState = (doc, fragment, undoManager) => ({
  json: normalizeValue(doc.toJSON()),
  fragment: fragment.toString(),
  length: fragment.length,
  canUndo: undoManager.canUndo(),
  canRedo: undoManager.canRedo(),
  undoStackLength: undoManager.undoStack.length,
  redoStackLength: undoManager.redoStack.length
})

const xmlChildState = child => {
  if (child instanceof Y.XmlElement) {
    return {
      type: 'element',
      nodeName: child.nodeName,
      attributes: normalizeValue(child.getAttributes()),
      string: child.toString(),
      children: child.toArray().map(xmlChildState)
    }
  }

  if (child instanceof Y.XmlText) {
    return {
      type: 'text',
      string: child.toString(),
      delta: normalizeValue(child.toDelta())
    }
  }

  if (child instanceof Y.XmlHook) {
    return {
      type: 'hook',
      hookName: child.hookName,
      attributes: normalizeValue(JSON.parse(JSON.stringify(child))),
      string: child.toString()
    }
  }

  return {
    type: 'text',
    string: `${child}`
  }
}

const xmlElementState = (doc, element, undoManager) => ({
  json: normalizeValue(doc.toJSON()),
  element: element.toString(),
  length: element.length,
  children: element.toArray().map(xmlChildState),
  canUndo: undoManager.canUndo(),
  canRedo: undoManager.canRedo(),
  undoStackLength: undoManager.undoStack.length,
  redoStackLength: undoManager.redoStack.length
})

const createParagraph = text => {
  const paragraph = new Y.XmlElement('p')
  const xmlText = new Y.XmlText()
  xmlText.insert(0, text)
  paragraph.insert(0, [xmlText])

  return paragraph
}

const undoManagerNestedXmlFragmentInArrayCase = () => {
  const doc = new Y.Doc({ guid: 'undo-nested-xml-fragment-array-doc', gc: false })
  doc.clientID = 323
  const array = doc.getArray('array')
  const fragment = new Y.XmlFragment()
  array.insert(0, [fragment])
  fragment.insert(0, [createParagraph('Lead')])
  const undoManager = new Y.UndoManager(fragment, {
    captureTimeout: 0,
    trackedOrigins: new Set(['nested-xml-fragment-array-edit'])
  })
  const events = []
  undoManager.on('stack-item-added', event => {
    events.push({ type: 'stack-item-added', stack: event.type })
  })
  undoManager.on('stack-item-popped', event => {
    events.push({ type: 'stack-item-popped', stack: event.type })
  })

  doc.transact(() => {
    const strong = new Y.XmlElement('strong')
    const strongText = new Y.XmlText()
    strongText.insert(0, 'Bold')
    strong.insert(0, [strongText])
    const hook = new Y.XmlHook('mention')
    hook.set('label', 'Ada')
    fragment.insert(1, [strong, hook])
  }, 'nested-xml-fragment-array-edit')
  const beforeUndo = nestedXmlFragmentState(doc, fragment, undoManager)
  const undoResult = undoManager.undo()
  const afterUndo = {
    undoReturnedStackItem: undoResult !== null,
    ...nestedXmlFragmentState(doc, fragment, undoManager)
  }
  const redoResult = undoManager.redo()

  return {
    name: 'nested-xml-fragment-in-array',
    beforeUndo,
    afterUndo,
    afterRedo: {
      redoReturnedStackItem: redoResult !== null,
      ...nestedXmlFragmentState(doc, fragment, undoManager),
      events
    }
  }
}

const undoManagerNestedXmlFragmentInMapCase = () => {
  const doc = new Y.Doc({ guid: 'undo-nested-xml-fragment-map-doc', gc: false })
  doc.clientID = 324
  const map = doc.getMap('map')
  const fragment = new Y.XmlFragment()
  map.set('xml', fragment)
  fragment.insert(0, [createParagraph('Base')])
  const undoManager = new Y.UndoManager(fragment, {
    captureTimeout: 0,
    trackedOrigins: new Set(['nested-xml-fragment-map-edit'])
  })

  doc.transact(() => {
    fragment.delete(0, 1)
    fragment.insert(0, [createParagraph('Changed'), new Y.XmlElement('aside')])
  }, 'nested-xml-fragment-map-edit')
  const beforeUndo = nestedXmlFragmentState(doc, fragment, undoManager)
  const undoResult = undoManager.undo()
  const afterUndo = {
    undoReturnedStackItem: undoResult !== null,
    ...nestedXmlFragmentState(doc, fragment, undoManager)
  }
  const redoResult = undoManager.redo()

  return {
    name: 'nested-xml-fragment-in-map',
    beforeUndo,
    afterUndo,
    afterRedo: {
      redoReturnedStackItem: redoResult !== null,
      ...nestedXmlFragmentState(doc, fragment, undoManager)
    }
  }
}

const undoManagerXmlElementDeleteMixedChildrenCase = () => {
  const doc = new Y.Doc({ guid: 'undo-xml-element-delete-mixed-children-doc', gc: false })
  doc.clientID = 325
  const root = doc.getXmlFragment('xml')
  const paragraph = new Y.XmlElement('p')
  const lead = new Y.XmlText()
  lead.insert(0, 'Lead')
  const strong = new Y.XmlElement('strong')
  strong.setAttribute('data-id', 's1')
  const strongText = new Y.XmlText()
  strongText.insert(0, 'Bold')
  strongText.format(0, 4, { bold: true })
  strong.insert(0, [strongText])
  const hook = new Y.XmlHook('mention')
  hook.set('label', 'Ada')
  hook.set('kind', 'user')
  const tail = new Y.XmlText()
  tail.insert(0, 'Tail')
  paragraph.insert(0, [lead, strong, hook, tail])
  root.insert(0, [paragraph])
  const undoManager = new Y.UndoManager(paragraph, {
    captureTimeout: 0,
    trackedOrigins: new Set(['xml-element-delete-mixed-children-edit'])
  })

  doc.transact(() => {
    paragraph.delete(1, 2)
  }, 'xml-element-delete-mixed-children-edit')
  const beforeUndo = xmlElementState(doc, paragraph, undoManager)
  const undoResult = undoManager.undo()
  const afterUndo = {
    undoReturnedStackItem: undoResult !== null,
    ...xmlElementState(doc, paragraph, undoManager)
  }
  const redoResult = undoManager.redo()

  return {
    name: 'xml-element-delete-mixed-children',
    beforeUndo,
    afterUndo,
    afterRedo: {
      redoReturnedStackItem: redoResult !== null,
      ...xmlElementState(doc, paragraph, undoManager)
    }
  }
}

const undoManagerDynamicTrackedOriginCase = () => {
  const doc = new Y.Doc({ guid: 'dynamic-tracked-origin-doc', gc: false })
  doc.clientID = 308
  const text = doc.getText('content')
  text.insert(0, 'A')
  const undoManager = new Y.UndoManager(text, {
    trackedOrigins: new Set()
  })
  undoManager.addTrackedOrigin('tracked-origin')
  doc.transact(() => {
    text.insert(1, 'B')
  }, 'tracked-origin')
  undoManager.removeTrackedOrigin('tracked-origin')
  doc.transact(() => {
    text.insert(2, 'C')
  }, 'tracked-origin')
  const canUndo = undoManager.canUndo()
  const undoStackLength = undoManager.undoStack.length
  undoManager.undo()

  return {
    name: 'dynamic-tracked-origin',
    canUndo,
    undoStackLength,
    textBeforeUndo: 'ABC',
    textAfterUndo: text.toString()
  }
}

const undoManagerStopCapturingCase = () => {
  const doc = new Y.Doc({ guid: 'stop-capturing-doc', gc: false })
  doc.clientID = 322
  const text = doc.getText('content')
  text.insert(0, 'A')
  const undoManager = new Y.UndoManager(text)
  text.insert(1, 'B')
  undoManager.stopCapturing()
  text.insert(2, 'C')
  const beforeUndo = {
    text: text.toString(),
    canUndo: undoManager.canUndo(),
    canRedo: undoManager.canRedo(),
    undoStackLength: undoManager.undoStack.length,
    redoStackLength: undoManager.redoStack.length
  }
  const firstUndoResult = undoManager.undo()
  const afterFirstUndo = {
    undoReturnedStackItem: firstUndoResult !== null,
    text: text.toString(),
    canUndo: undoManager.canUndo(),
    canRedo: undoManager.canRedo(),
    undoStackLength: undoManager.undoStack.length,
    redoStackLength: undoManager.redoStack.length
  }
  const secondUndoResult = undoManager.undo()

  return {
    beforeUndo,
    afterFirstUndo,
    afterSecondUndo: {
      undoReturnedStackItem: secondUndoResult !== null,
      text: text.toString(),
      canUndo: undoManager.canUndo(),
      canRedo: undoManager.canRedo(),
      undoStackLength: undoManager.undoStack.length,
      redoStackLength: undoManager.redoStack.length
    }
  }
}

const undoManagerStackEventCase = () => {
  const doc = new Y.Doc({ guid: 'stack-event-doc', gc: false })
  doc.clientID = 309
  const text = doc.getText('content')
  text.insert(0, 'A')
  const undoManager = new Y.UndoManager(text)
  const events = []
  const stackEvent = (type, event) => ({
    type,
    stack: event.type,
    changedParentTypeNames: Array.from(event.changedParentTypes.keys()).map(changedType => changedType.constructor.name).sort()
  })
  undoManager.on('stack-item-added', event => {
    events.push(stackEvent('stack-item-added', event))
  })
  undoManager.on('stack-item-updated', event => {
    events.push(stackEvent('stack-item-updated', event))
  })
  undoManager.on('stack-item-popped', event => {
    events.push(stackEvent('stack-item-popped', event))
  })
  undoManager.on('stack-cleared', event => {
    events.push({
      type: 'stack-cleared',
      undoStackCleared: event.undoStackCleared,
      redoStackCleared: event.redoStackCleared
    })
  })

  text.insert(1, 'B')
  text.insert(2, 'C')
  undoManager.undo()
  undoManager.clear()

  return events
}

const undoManagerClearOptionsCase = () => {
  const doc = new Y.Doc({ guid: 'clear-options-doc', gc: false })
  doc.clientID = 310
  const text = doc.getText('content')
  text.insert(0, 'A')
  const undoManager = new Y.UndoManager(text, { captureTimeout: 0 })
  const events = []
  undoManager.on('stack-cleared', event => {
    events.push({
      undoStackCleared: event.undoStackCleared,
      redoStackCleared: event.redoStackCleared,
      canUndo: undoManager.canUndo(),
      canRedo: undoManager.canRedo()
    })
  })

  text.insert(1, 'B')
  text.insert(2, 'C')
  undoManager.undo()
  undoManager.clear(false, true)
  const afterRedoOnlyClear = {
    canUndo: undoManager.canUndo(),
    canRedo: undoManager.canRedo(),
    undoStackLength: undoManager.undoStack.length,
    redoStackLength: undoManager.redoStack.length
  }
  undoManager.clear(true, false)

  return {
    events,
    afterRedoOnlyClear,
    afterAllClear: {
      canUndo: undoManager.canUndo(),
      canRedo: undoManager.canRedo(),
      undoStackLength: undoManager.undoStack.length,
      redoStackLength: undoManager.redoStack.length
    }
  }
}

const undoManagerDestroyCase = () => {
  const doc = new Y.Doc({ guid: 'destroy-doc', gc: false })
  doc.clientID = 314
  const text = doc.getText('content')
  text.insert(0, 'A')
  const undoManager = new Y.UndoManager(text, { captureTimeout: 0 })
  const events = []
  undoManager.on('stack-item-popped', event => {
    events.push({ type: 'stack-item-popped', stack: event.type })
  })
  undoManager.on('stack-cleared', () => {
    events.push({ type: 'stack-cleared' })
  })

  text.insert(1, 'B')
  const beforeDestroy = {
    canUndo: undoManager.canUndo(),
    canRedo: undoManager.canRedo(),
    undoStackLength: undoManager.undoStack.length,
    redoStackLength: undoManager.redoStack.length,
    text: text.toString()
  }
  undoManager.destroy()
  const afterDestroy = {
    canUndo: undoManager.canUndo(),
    canRedo: undoManager.canRedo(),
    undoStackLength: undoManager.undoStack.length,
    redoStackLength: undoManager.redoStack.length,
    text: text.toString()
  }
  text.insert(2, 'C')
  const afterFutureEdit = {
    canUndo: undoManager.canUndo(),
    canRedo: undoManager.canRedo(),
    undoStackLength: undoManager.undoStack.length,
    redoStackLength: undoManager.redoStack.length,
    text: text.toString()
  }
  const undoResult = undoManager.undo()

  return {
    beforeDestroy,
    afterDestroy,
    afterFutureEdit,
    afterUndo: {
      undoReturnedStackItem: undoResult !== null,
      canUndo: undoManager.canUndo(),
      canRedo: undoManager.canRedo(),
      undoStackLength: undoManager.undoStack.length,
      redoStackLength: undoManager.redoStack.length,
      text: text.toString(),
      events
    }
  }
}

const undoManagerEventEmitterCase = () => {
  const doc = new Y.Doc({ guid: 'event-emitter-doc', gc: false })
  doc.clientID = 315
  const text = doc.getText('content')
  text.insert(0, 'A')
  const undoManager = new Y.UndoManager(text, { captureTimeout: 0 })
  const events = []
  const changedParentTypeNames = event => Array.from(event.changedParentTypes.keys()).map(changedType => changedType.constructor.name).sort()
  const added = event => {
    events.push({
      listener: 'on-added',
      stack: event.type,
      origin: event.origin == null ? null : event.origin.constructor.name,
      changedParentTypeNames: changedParentTypeNames(event),
      changedParentTypes: changedParentTypeNames(event)
    })
  }
  undoManager.on('stack-item-added', added)
  undoManager.once('stack-item-popped', event => {
    events.push({
      listener: 'once-popped',
      stack: event.type,
      origin: event.origin == null ? null : event.origin.constructor.name,
      changedParentTypeNames: changedParentTypeNames(event),
      changedParentTypes: changedParentTypeNames(event)
    })
  })
  undoManager.once('custom', (left, right) => {
    events.push({ listener: 'custom-once', left, right })
  })

  text.insert(1, 'B')
  undoManager.off('stack-item-added', added)
  text.insert(2, 'C')
  undoManager.undo()
  undoManager.undo()
  undoManager.emit('custom', ['left', 'right'])
  undoManager.emit('custom', ['ignored', 'ignored'])

  return events
}

const undoManagerListenerAddedDuringDispatchCase = () => {
  const doc = new Y.Doc({ guid: 'listener-added-during-dispatch-doc', gc: false })
  doc.clientID = 316
  const text = doc.getText('content')
  const undoManager = new Y.UndoManager(text, { captureTimeout: 0 })
  const calls = []

  undoManager.on('stack-item-added', () => {
    calls.push('first')
    undoManager.on('stack-item-added', () => {
      calls.push('third')
    })
  })
  undoManager.on('stack-item-added', () => {
    calls.push('second')
  })

  text.insert(0, 'A')
  text.insert(1, 'B')

  return calls
}

const undoManagerFixtures = {
  originCases: [
    undoManagerOriginCase('default-null-origin', undefined, null),
    undoManagerOriginCase('default-named-origin', undefined, 'named-origin'),
    undoManagerOriginCase('tracked-named-origin', { trackedOrigins: new Set(['named-origin']) }, 'named-origin'),
    undoManagerOriginCase('tracked-empty-origin-set', { trackedOrigins: new Set() }, null)
  ],
  constructorTrackedOrigin: undoManagerConstructorTrackedOriginCase(),
  selfTrackedOrigin: undoManagerSelfTrackedOriginCase(),
  captureTransaction: undoManagerCaptureTransactionCase(),
  deleteFilter: undoManagerDeleteFilterCase(),
  remoteMapConflicts: [
    undoManagerRemoteMapConflictCase(false),
    undoManagerRemoteMapConflictCase(true)
  ],
  addToScope: undoManagerAddToScopeCase(),
  textAttributes: {
    attributeOnly: undoManagerTextAttributeCase(),
    contentAndAttributes: undoManagerTextContentAndAttributeCase(),
    nestedAttributeOnly: undoManagerNestedTextAttributeCase(),
    nestedContentAndAttributes: undoManagerNestedTextContentAndAttributeCase()
  },
  xmlFragments: {
    nestedInArray: undoManagerNestedXmlFragmentInArrayCase(),
    nestedInMap: undoManagerNestedXmlFragmentInMapCase(),
    elementDeleteMixedChildren: undoManagerXmlElementDeleteMixedChildrenCase()
  },
  dynamicTrackedOrigin: undoManagerDynamicTrackedOriginCase(),
  stopCapturing: undoManagerStopCapturingCase(),
  stackEvents: undoManagerStackEventCase(),
  clearOptions: undoManagerClearOptionsCase(),
  destroy: undoManagerDestroyCase(),
  eventEmitter: undoManagerEventEmitterCase(),
  listenerAddedDuringDispatch: undoManagerListenerAddedDuringDispatchCase()
}

fs.writeFileSync(
  path.join(outDir, 'undo-manager.json'),
  `${JSON.stringify(undoManagerFixtures, null, 2)}\n`
)

const awarenessDoc = new Y.Doc({ guid: 'awareness-doc' })
awarenessDoc.clientID = 77
const awareness = new awarenessProtocol.Awareness(awarenessDoc)
awareness.setLocalState({
  user: { name: 'Ada' },
  cursor: { anchor: 3, head: 7 }
})
const awarenessUpdate = awarenessProtocol.encodeAwarenessUpdate(awareness, [77])
const awarenessModifiedUpdate = awarenessProtocol.modifyAwarenessUpdate(awarenessUpdate, state => state === null ? null : { user: { name: state.user.name } })
const awarenessStates = normalizeValue(Object.fromEntries(awareness.getStates()))
const awarenessMessageEncoder = encoding.createEncoder()
encoding.writeVarUint(awarenessMessageEncoder, 1)
encoding.writeVarUint8Array(awarenessMessageEncoder, awarenessUpdate)
const awarenessQueryMessageEncoder = encoding.createEncoder()
encoding.writeVarUint(awarenessQueryMessageEncoder, 0)
encoding.writeVarUint8Array(awarenessQueryMessageEncoder, new Uint8Array())
awareness.setLocalState(null)
const awarenessRemoveUpdate = awarenessProtocol.encodeAwarenessUpdate(awareness, [77])
const awarenessRemovedStates = normalizeValue(Object.fromEntries(awareness.getStates()))
const awarenessRemoveMessageEncoder = encoding.createEncoder()
encoding.writeVarUint(awarenessRemoveMessageEncoder, 1)
encoding.writeVarUint8Array(awarenessRemoveMessageEncoder, awarenessRemoveUpdate)
awareness.destroy()
const sameClockRemoveEncoder = encoding.createEncoder()
encoding.writeVarUint(sameClockRemoveEncoder, 1)
encoding.writeVarUint(sameClockRemoveEncoder, 77)
encoding.writeVarUint(sameClockRemoveEncoder, 1)
encoding.writeVarString(sameClockRemoveEncoder, 'null')
const awarenessSameClockRemoveUpdate = encoding.toUint8Array(sameClockRemoveEncoder)

const awarenessUndefinedDoc = new Y.Doc({ guid: 'awareness-undefined-doc' })
awarenessUndefinedDoc.clientID = 81
const awarenessUndefined = new awarenessProtocol.Awareness(awarenessUndefinedDoc)
awarenessUndefined.setLocalState({
  user: { name: 'Ada', badge: undefined },
  items: [undefined, null, { hidden: undefined, visible: true }]
})
const awarenessUndefinedUpdate = awarenessProtocol.encodeAwarenessUpdate(awarenessUndefined, [81])
awarenessUndefined.destroy()

const awarenessSpecialNumberDoc = new Y.Doc({ guid: 'awareness-special-number-doc' })
awarenessSpecialNumberDoc.clientID = 82
const awarenessSpecialNumber = new awarenessProtocol.Awareness(awarenessSpecialNumberDoc)
awarenessSpecialNumber.setLocalState({
  metrics: {
    nan: NaN,
    positiveInfinity: Infinity,
    negativeInfinity: -Infinity,
    finite: 1.5
  },
  list: [NaN, Infinity, -Infinity, 3]
})
const awarenessSpecialNumberUpdate = awarenessProtocol.encodeAwarenessUpdate(awarenessSpecialNumber, [82])
awarenessSpecialNumber.destroy()

const awarenessFieldDoc = new Y.Doc({ guid: 'awareness-field-doc' })
awarenessFieldDoc.clientID = 77
const awarenessField = new awarenessProtocol.Awareness(awarenessFieldDoc)
awarenessField.setLocalState({
  user: { name: 'Ada' }
})
awarenessField.setLocalStateField('cursor', { anchor: 1, head: 4 })
const awarenessFieldUpdate = awarenessProtocol.encodeAwarenessUpdate(awarenessField, [77])
awarenessField.destroy()

const awarenessMultiDoc = new Y.Doc({ guid: 'awareness-multi-doc' })
awarenessMultiDoc.clientID = 77
const awarenessMulti = new awarenessProtocol.Awareness(awarenessMultiDoc)
awarenessMulti.setLocalState({
  user: { name: 'Ada' }
})
const awarenessPeerDoc = new Y.Doc({ guid: 'awareness-peer-doc' })
awarenessPeerDoc.clientID = 78
const awarenessPeer = new awarenessProtocol.Awareness(awarenessPeerDoc)
awarenessPeer.setLocalState({
  user: { name: 'Grace' },
  cursor: { anchor: 9, head: 9 }
})
awarenessProtocol.applyAwarenessUpdate(
  awarenessMulti,
  awarenessProtocol.encodeAwarenessUpdate(awarenessPeer, [78]),
  'fixture'
)
const awarenessMultiUpdate = awarenessProtocol.encodeAwarenessUpdate(awarenessMulti, [77, 78])
const awarenessMultiSubsetUpdate = awarenessProtocol.encodeAwarenessUpdate(awarenessMulti, [78])
const awarenessMultiStates = normalizeValue(Object.fromEntries(awarenessMulti.getStates()))
awarenessMulti.destroy()
awarenessPeer.destroy()

const awarenessRichState = {
  user: {
    name: 'Zoë',
    roles: ['editor', 'reviewer'],
    flags: { online: true, muted: false },
    meta: null
  },
  cursor: {
    anchor: { type: 'relative', id: { client: 1, clock: 2 }, assoc: -1 },
    ranges: [{ anchor: 0, head: 4 }]
  },
  emoji: '😀'
}
const awarenessRichPeerState = {
  user: {
    name: 'Lin',
    roles: ['viewer']
  },
  viewport: {
    x: 10,
    y: 20,
    zoom: 1.25
  }
}
const awarenessRichDoc = new Y.Doc({ guid: 'awareness-rich-doc' })
awarenessRichDoc.clientID = 79
const awarenessRich = new awarenessProtocol.Awareness(awarenessRichDoc)
awarenessRich.setLocalState(awarenessRichState)
const awarenessRichPeerDoc = new Y.Doc({ guid: 'awareness-rich-peer-doc' })
awarenessRichPeerDoc.clientID = 80
const awarenessRichPeer = new awarenessProtocol.Awareness(awarenessRichPeerDoc)
awarenessRichPeer.setLocalState(awarenessRichPeerState)
awarenessProtocol.applyAwarenessUpdate(
  awarenessRich,
  awarenessProtocol.encodeAwarenessUpdate(awarenessRichPeer, [80]),
  'fixture-rich'
)
const awarenessRichUpdate = awarenessProtocol.encodeAwarenessUpdate(awarenessRich, [79, 80])
const awarenessRichStates = normalizeValue(Object.fromEntries(awarenessRich.getStates()))
const awarenessRichMessageEncoder = encoding.createEncoder()
encoding.writeVarUint(awarenessRichMessageEncoder, 1)
encoding.writeVarUint8Array(awarenessRichMessageEncoder, awarenessRichUpdate)
awarenessRich.setLocalState(null)
const awarenessRichRemoveUpdate = awarenessProtocol.encodeAwarenessUpdate(awarenessRich, [79])
const awarenessRichRemoveMessageEncoder = encoding.createEncoder()
encoding.writeVarUint(awarenessRichRemoveMessageEncoder, 1)
encoding.writeVarUint8Array(awarenessRichRemoveMessageEncoder, awarenessRichRemoveUpdate)
awarenessRich.destroy()
awarenessRichPeer.destroy()

const awarenessEventUpdate = (clientID, clock, state) => {
  const encoder = encoding.createEncoder()
  encoding.writeVarUint(encoder, 1)
  encoding.writeVarUint(encoder, clientID)
  encoding.writeVarUint(encoder, clock)
  encoding.writeVarString(encoder, JSON.stringify(state))
  return encoding.toUint8Array(encoder)
}
const awarenessEventDoc = new Y.Doc({ guid: 'awareness-event-doc' })
awarenessEventDoc.clientID = 79
const awarenessEventTarget = new awarenessProtocol.Awareness(awarenessEventDoc)
const awarenessEventSequence = []
awarenessEventTarget.on('change', (event, origin) => {
  awarenessEventSequence.push({ type: 'change', event, origin })
})
awarenessEventTarget.on('update', (event, origin) => {
  awarenessEventSequence.push({ type: 'update', event, origin })
})
awarenessProtocol.applyAwarenessUpdate(
  awarenessEventTarget,
  awarenessEventUpdate(77, 1, { user: { name: 'Ada' } }),
  'remote-add'
)
awarenessProtocol.applyAwarenessUpdate(
  awarenessEventTarget,
  awarenessEventUpdate(77, 1, { user: { name: 'Ada' } }),
  'remote-same-clock'
)
awarenessProtocol.applyAwarenessUpdate(
  awarenessEventTarget,
  awarenessEventUpdate(77, 2, { user: { name: 'Ada' } }),
  'remote-newer-same-state'
)
awarenessProtocol.applyAwarenessUpdate(
  awarenessEventTarget,
  awarenessEventUpdate(77, 3, { user: { name: 'Grace' } }),
  'remote-newer-different-state'
)
awarenessProtocol.applyAwarenessUpdate(
  awarenessEventTarget,
  awarenessEventUpdate(77, 3, null),
  'remote-same-clock-remove'
)
const awarenessRemoteEventSequence = [...awarenessEventSequence]
awarenessEventTarget.destroy()

const awarenessStaleAfterRemoveDoc = new Y.Doc({ guid: 'awareness-stale-after-remove-doc' })
awarenessStaleAfterRemoveDoc.clientID = 79
const awarenessStaleAfterRemoveTarget = new awarenessProtocol.Awareness(awarenessStaleAfterRemoveDoc)
const awarenessStaleAfterRemoveSequence = []
awarenessStaleAfterRemoveTarget.on('change', (event, origin) => {
  awarenessStaleAfterRemoveSequence.push({ type: 'change', event, origin })
})
awarenessStaleAfterRemoveTarget.on('update', (event, origin) => {
  awarenessStaleAfterRemoveSequence.push({ type: 'update', event, origin })
})
awarenessProtocol.applyAwarenessUpdate(
  awarenessStaleAfterRemoveTarget,
  awarenessEventUpdate(77, 2, { user: { name: 'Ada' } }),
  'remote-add-clock-2'
)
awarenessProtocol.applyAwarenessUpdate(
  awarenessStaleAfterRemoveTarget,
  awarenessEventUpdate(77, 3, null),
  'remote-remove-clock-3'
)
awarenessProtocol.applyAwarenessUpdate(
  awarenessStaleAfterRemoveTarget,
  awarenessEventUpdate(77, 2, { user: { name: 'Grace' } }),
  'remote-stale-clock-2-state'
)
awarenessProtocol.applyAwarenessUpdate(
  awarenessStaleAfterRemoveTarget,
  awarenessEventUpdate(77, 3, { user: { name: 'Lin' } }),
  'remote-same-clock-state-after-remove'
)
awarenessProtocol.applyAwarenessUpdate(
  awarenessStaleAfterRemoveTarget,
  awarenessEventUpdate(77, 3, null),
  'remote-duplicate-remove'
)
const awarenessStaleAfterRemoveRemoteSequence = [...awarenessStaleAfterRemoveSequence]
const awarenessStaleAfterRemoveHasClient77 = awarenessStaleAfterRemoveTarget.getStates().has(77)
awarenessStaleAfterRemoveTarget.destroy()

const awarenessFixtures = {
  update: toBase64(awarenessUpdate),
  modifiedUpdate: toBase64(awarenessModifiedUpdate),
  message: toBase64(encoding.toUint8Array(awarenessMessageEncoder)),
  queryMessage: toBase64(encoding.toUint8Array(awarenessQueryMessageEncoder)),
  decodedUpdate: [
    {
      clientID: 77,
      clock: 1,
      state: {
        user: { name: 'Ada' },
        cursor: { anchor: 3, head: 7 }
      }
    }
  ],
  states: awarenessStates,
  removedStates: awarenessRemovedStates,
  removeUpdate: toBase64(awarenessRemoveUpdate),
  removeMessage: toBase64(encoding.toUint8Array(awarenessRemoveMessageEncoder)),
  decodedRemoveUpdate: [
    {
      clientID: 77,
      clock: 2,
      state: null
    }
  ],
  sameClockRemoveUpdate: toBase64(awarenessSameClockRemoveUpdate),
  decodedSameClockRemoveUpdate: [
    {
      clientID: 77,
      clock: 1,
      state: null
    }
  ],
  undefinedUpdate: toBase64(awarenessUndefinedUpdate),
  decodedUndefinedUpdate: [
    {
      clientID: 81,
      clock: 1,
      state: {
        user: { name: 'Ada' },
        items: [null, null, { visible: true }]
      }
    }
  ],
  specialNumberUpdate: toBase64(awarenessSpecialNumberUpdate),
  decodedSpecialNumberUpdate: [
    {
      clientID: 82,
      clock: 1,
      state: {
        metrics: {
          nan: null,
          positiveInfinity: null,
          negativeInfinity: null,
          finite: 1.5
        },
        list: [null, null, null, 3]
      }
    }
  ],
  fieldUpdate: toBase64(awarenessFieldUpdate),
  decodedFieldUpdate: [
    {
      clientID: 77,
      clock: 2,
      state: {
        user: { name: 'Ada' },
        cursor: { anchor: 1, head: 4 }
      }
    }
  ],
  multiUpdate: toBase64(awarenessMultiUpdate),
  decodedMultiUpdate: [
    {
      clientID: 77,
      clock: 1,
      state: {
        user: { name: 'Ada' }
      }
    },
    {
      clientID: 78,
      clock: 1,
      state: {
        user: { name: 'Grace' },
        cursor: { anchor: 9, head: 9 }
      }
    }
  ],
  multiSubsetUpdate: toBase64(awarenessMultiSubsetUpdate),
  decodedMultiSubsetUpdate: [
    {
      clientID: 78,
      clock: 1,
      state: {
        user: { name: 'Grace' },
        cursor: { anchor: 9, head: 9 }
      }
    }
  ],
  multiStates: awarenessMultiStates,
  richUpdate: toBase64(awarenessRichUpdate),
  richMessage: toBase64(encoding.toUint8Array(awarenessRichMessageEncoder)),
  decodedRichUpdate: [
    {
      clientID: 79,
      clock: 1,
      state: awarenessRichState
    },
    {
      clientID: 80,
      clock: 1,
      state: awarenessRichPeerState
    }
  ],
  richStates: awarenessRichStates,
  richRemoveUpdate: toBase64(awarenessRichRemoveUpdate),
  richRemoveMessage: toBase64(encoding.toUint8Array(awarenessRichRemoveMessageEncoder)),
  decodedRichRemoveUpdate: [
    {
      clientID: 79,
      clock: 2,
      state: null
    }
  ],
  remoteEventSequence: awarenessRemoteEventSequence,
  staleAfterRemoveSequence: awarenessStaleAfterRemoveRemoteSequence,
  staleAfterRemoveHasClient77: awarenessStaleAfterRemoveHasClient77
}

fs.writeFileSync(
  path.join(outDir, 'awareness.json'),
  `${JSON.stringify(awarenessFixtures, null, 2)}\n`
)

const partialDiffDoc = new Y.Doc({ guid: 'partial-diff-doc', gc: false })
partialDiffDoc.clientID = 130
partialDiffDoc.getText('content').insert(0, 'A😀BC')
const partialDiffUpdate = Y.encodeStateAsUpdate(partialDiffDoc)
const partialDiffStateVector = (() => {
  const encoder = encoding.createEncoder()
  encoding.writeVarUint(encoder, 1)
  encoding.writeVarUint(encoder, 130)
  encoding.writeVarUint(encoder, 3)
  return encoding.toUint8Array(encoder)
})()
const partialDiff = Y.encodeStateAsUpdate(partialDiffDoc, partialDiffStateVector)
const partialDiffV2 = Y.encodeStateAsUpdateV2(partialDiffDoc, partialDiffStateVector)
const partialDiffSurrogateStateVector = stateVector([[130, 2]])
const partialDiffSurrogate = Y.encodeStateAsUpdate(partialDiffDoc, partialDiffSurrogateStateVector)
const partialDiffSurrogateV2 = Y.encodeStateAsUpdateV2(partialDiffDoc, partialDiffSurrogateStateVector)

const partialArrayDoc = new Y.Doc({ guid: 'partial-array-diff-doc', gc: false })
partialArrayDoc.clientID = 131
partialArrayDoc.getArray('array').insert(0, [1, 2, 3, 4])
const partialArrayUpdate = Y.encodeStateAsUpdate(partialArrayDoc)
const partialArrayStateVector = (() => {
  const encoder = encoding.createEncoder()
  encoding.writeVarUint(encoder, 1)
  encoding.writeVarUint(encoder, 131)
  encoding.writeVarUint(encoder, 2)
  return encoding.toUint8Array(encoder)
})()
const partialArrayDiff = Y.encodeStateAsUpdate(partialArrayDoc, partialArrayStateVector)
const partialArrayDiffV2 = Y.encodeStateAsUpdateV2(partialArrayDoc, partialArrayStateVector)

const partialArrayMixedDoc = new Y.Doc({ guid: 'partial-array-mixed-diff-doc', gc: false })
partialArrayMixedDoc.clientID = 143
partialArrayMixedDoc.getArray('array').insert(0, [{ nested: ['x'] }, [2], 'tail', true, null])
const partialArrayMixedPrefixDoc = new Y.Doc({ guid: 'partial-array-mixed-prefix-doc', gc: false })
partialArrayMixedPrefixDoc.clientID = 143
partialArrayMixedPrefixDoc.getArray('array').insert(0, [{ nested: ['x'] }, [2]])
const partialArrayMixedPrefixUpdate = Y.encodeStateAsUpdate(partialArrayMixedPrefixDoc)
const partialArrayMixedPrefixUpdateV2 = Y.encodeStateAsUpdateV2(partialArrayMixedPrefixDoc)
const partialArrayMixedUpdate = Y.encodeStateAsUpdate(partialArrayMixedDoc)
const partialArrayMixedStateVector = stateVector([[143, 2]])
const partialArrayMixedDiff = Y.encodeStateAsUpdate(partialArrayMixedDoc, partialArrayMixedStateVector)
const partialArrayMixedDiffV2 = Y.encodeStateAsUpdateV2(partialArrayMixedDoc, partialArrayMixedStateVector)

const partialDeleteDoc = new Y.Doc({ guid: 'partial-delete-diff-doc', gc: false })
partialDeleteDoc.clientID = 132
partialDeleteDoc.getText('content').insert(0, 'ABCD')
partialDeleteDoc.getText('content').delete(1, 2)
const partialDeleteUpdate = Y.encodeStateAsUpdate(partialDeleteDoc)
const partialDeleteStateVector = (() => {
  const encoder = encoding.createEncoder()
  encoding.writeVarUint(encoder, 1)
  encoding.writeVarUint(encoder, 132)
  encoding.writeVarUint(encoder, 4)
  return encoding.toUint8Array(encoder)
})()
const partialDeleteDiff = Y.encodeStateAsUpdate(partialDeleteDoc, partialDeleteStateVector)
const partialDeleteDiffV2 = Y.encodeStateAsUpdateV2(partialDeleteDoc, partialDeleteStateVector)

const partialMapReplaceDoc = new Y.Doc({ guid: 'partial-map-replace-diff-doc', gc: false })
partialMapReplaceDoc.clientID = 133
partialMapReplaceDoc.getMap('map').set('title', null)
partialMapReplaceDoc.getMap('map').set('title', 'Hello')
const partialMapReplaceUpdate = Y.encodeStateAsUpdate(partialMapReplaceDoc)
const partialMapReplaceStateVector = (() => {
  const encoder = encoding.createEncoder()
  encoding.writeVarUint(encoder, 1)
  encoding.writeVarUint(encoder, 133)
  encoding.writeVarUint(encoder, 1)
  return encoding.toUint8Array(encoder)
})()
const partialMapReplaceDiff = Y.encodeStateAsUpdate(partialMapReplaceDoc, partialMapReplaceStateVector)
const partialMapReplaceDiffV2 = Y.encodeStateAsUpdateV2(partialMapReplaceDoc, partialMapReplaceStateVector)

const partialBinaryDoc = new Y.Doc({ guid: 'partial-binary-diff-doc', gc: false })
partialBinaryDoc.clientID = 134
partialBinaryDoc.getArray('array').insert(0, ['before'])
partialBinaryDoc.getArray('array').insert(1, [Uint8Array.from([1, 2, 255])])
const partialBinaryUpdate = Y.encodeStateAsUpdate(partialBinaryDoc)
const partialBinaryStateVector = (() => {
  const encoder = encoding.createEncoder()
  encoding.writeVarUint(encoder, 1)
  encoding.writeVarUint(encoder, 134)
  encoding.writeVarUint(encoder, 1)
  return encoding.toUint8Array(encoder)
})()
const partialBinaryDiff = Y.encodeStateAsUpdate(partialBinaryDoc, partialBinaryStateVector)
const partialBinaryDiffV2 = Y.encodeStateAsUpdateV2(partialBinaryDoc, partialBinaryStateVector)

const partialEmbedDoc = new Y.Doc({ guid: 'partial-embed-diff-doc', gc: false })
partialEmbedDoc.clientID = 145
partialEmbedDoc.getText('content').insert(0, 'A')
partialEmbedDoc.getText('content').insertEmbed(1, { image: 'partial.png' }, { alt: 'Partial' })
const partialEmbedUpdate = Y.encodeStateAsUpdate(partialEmbedDoc)
const partialEmbedStateVector = stateVector([[145, 1]])
const partialEmbedDiff = Y.encodeStateAsUpdate(partialEmbedDoc, partialEmbedStateVector)
const partialEmbedDiffV2 = Y.encodeStateAsUpdateV2(partialEmbedDoc, partialEmbedStateVector)

const partialSubdocDoc = new Y.Doc({ guid: 'partial-subdoc-diff-doc', gc: false })
partialSubdocDoc.clientID = 146
partialSubdocDoc.getArray('array').insert(0, ['before'])
const partialSubdocPrefixUpdate = Y.encodeStateAsUpdate(partialSubdocDoc)
const partialSubdocPrefixUpdateV2 = Y.encodeStateAsUpdateV2(partialSubdocDoc)
partialSubdocDoc.getArray('array').insert(1, [new Y.Doc({
  guid: 'partial-subdoc-child',
  meta: { kind: 'partial' },
  autoLoad: true
})])
const partialSubdocUpdate = Y.encodeStateAsUpdate(partialSubdocDoc)
const partialSubdocStateVector = stateVector([[146, 1]])
const partialSubdocDiff = Y.encodeStateAsUpdate(partialSubdocDoc, partialSubdocStateVector)
const partialSubdocDiffV2 = Y.encodeStateAsUpdateV2(partialSubdocDoc, partialSubdocStateVector)

const partialMapSubdocDoc = new Y.Doc({ guid: 'partial-map-subdoc-diff-doc', gc: false })
partialMapSubdocDoc.clientID = 169
partialMapSubdocDoc.getMap('map').set('known', 'before')
const partialMapSubdocPrefixUpdate = Y.encodeStateAsUpdate(partialMapSubdocDoc)
const partialMapSubdocPrefixUpdateV2 = Y.encodeStateAsUpdateV2(partialMapSubdocDoc)
partialMapSubdocDoc.getMap('map').set('child', new Y.Doc({
  guid: 'partial-map-subdoc-child',
  meta: { kind: 'partial-map' },
  autoLoad: true
}))
const partialMapSubdocUpdate = Y.encodeStateAsUpdate(partialMapSubdocDoc)
const partialMapSubdocStateVector = stateVector([[169, 1]])
const partialMapSubdocDiff = Y.encodeStateAsUpdate(partialMapSubdocDoc, partialMapSubdocStateVector)
const partialMapSubdocDiffV2 = Y.encodeStateAsUpdateV2(partialMapSubdocDoc, partialMapSubdocStateVector)

const partialFormatDoc = new Y.Doc({ guid: 'partial-format-diff-doc', gc: false })
partialFormatDoc.clientID = 135
partialFormatDoc.getText('content').insert(0, 'Hello')
partialFormatDoc.getText('content').format(1, 3, { bold: true })
const partialFormatUpdate = Y.encodeStateAsUpdate(partialFormatDoc)
const partialFormatStateVector = (() => {
  const encoder = encoding.createEncoder()
  encoding.writeVarUint(encoder, 1)
  encoding.writeVarUint(encoder, 135)
  encoding.writeVarUint(encoder, 5)
  return encoding.toUint8Array(encoder)
})()
const partialFormatDiff = Y.encodeStateAsUpdate(partialFormatDoc, partialFormatStateVector)
const partialFormatDiffV2 = Y.encodeStateAsUpdateV2(partialFormatDoc, partialFormatStateVector)

const partialTextAttributesDoc = new Y.Doc({ guid: 'partial-text-attributes-diff-doc', gc: false })
partialTextAttributesDoc.clientID = 165
const partialTextAttributesText = partialTextAttributesDoc.getText('content')
partialTextAttributesText.insert(0, 'Text')
const partialTextAttributesPrefixUpdate = Y.encodeStateAsUpdate(partialTextAttributesDoc)
const partialTextAttributesPrefixUpdateV2 = Y.encodeStateAsUpdateV2(partialTextAttributesDoc)
const partialTextAttributesStateVector = Y.encodeStateVector(partialTextAttributesDoc)
partialTextAttributesText.setAttribute('lang', 'base')
partialTextAttributesText.setAttribute('lang', 'en')
partialTextAttributesText.setAttribute('mark', { color: 'green' })
const partialTextAttributesUpdate = Y.encodeStateAsUpdate(partialTextAttributesDoc)
const partialTextAttributesDiff = Y.encodeStateAsUpdate(partialTextAttributesDoc, partialTextAttributesStateVector)
const partialTextAttributesDiffV2 = Y.encodeStateAsUpdateV2(partialTextAttributesDoc, partialTextAttributesStateVector)

const partialNestedTextDoc = new Y.Doc({ guid: 'partial-nested-text-diff-doc', gc: false })
partialNestedTextDoc.clientID = 136
const partialNestedText = new Y.Text()
partialNestedTextDoc.getArray('array').insert(0, [partialNestedText])
partialNestedText.insert(0, 'Nested')
const partialNestedTextUpdate = Y.encodeStateAsUpdate(partialNestedTextDoc)
const partialNestedTextStateVector = (() => {
  const encoder = encoding.createEncoder()
  encoding.writeVarUint(encoder, 1)
  encoding.writeVarUint(encoder, 136)
  encoding.writeVarUint(encoder, 1)
  return encoding.toUint8Array(encoder)
})()
const partialNestedTextDiff = Y.encodeStateAsUpdate(partialNestedTextDoc, partialNestedTextStateVector)
const partialNestedTextDiffV2 = Y.encodeStateAsUpdateV2(partialNestedTextDoc, partialNestedTextStateVector)

const partialNestedTextAttributesDoc = new Y.Doc({ guid: 'partial-nested-text-attributes-diff-doc', gc: false })
partialNestedTextAttributesDoc.clientID = 166
const partialNestedTextAttributesText = new Y.Text()
partialNestedTextAttributesDoc.getArray('array').insert(0, [partialNestedTextAttributesText])
partialNestedTextAttributesText.insert(0, 'Nested')
const partialNestedTextAttributesPrefixUpdate = Y.encodeStateAsUpdate(partialNestedTextAttributesDoc)
const partialNestedTextAttributesPrefixUpdateV2 = Y.encodeStateAsUpdateV2(partialNestedTextAttributesDoc)
const partialNestedTextAttributesStateVector = Y.encodeStateVector(partialNestedTextAttributesDoc)
partialNestedTextAttributesText.setAttribute('lang', 'base')
partialNestedTextAttributesText.setAttribute('lang', 'en')
partialNestedTextAttributesText.setAttribute('mark', { color: 'green' })
const partialNestedTextAttributesUpdate = Y.encodeStateAsUpdate(partialNestedTextAttributesDoc)
const partialNestedTextAttributesDiff = Y.encodeStateAsUpdate(partialNestedTextAttributesDoc, partialNestedTextAttributesStateVector)
const partialNestedTextAttributesDiffV2 = Y.encodeStateAsUpdateV2(partialNestedTextAttributesDoc, partialNestedTextAttributesStateVector)

const partialXmlTextDoc = new Y.Doc({ guid: 'partial-xml-text-diff-doc', gc: false })
partialXmlTextDoc.clientID = 137
const partialXmlParagraph = new Y.XmlElement('p')
const partialXmlText = new Y.XmlText()
partialXmlParagraph.insert(0, [partialXmlText])
partialXmlTextDoc.getXmlFragment('xml').insert(0, [partialXmlParagraph])
partialXmlText.insert(0, 'Hello')
partialXmlText.format(1, 3, { bold: true })
const partialXmlTextUpdate = Y.encodeStateAsUpdate(partialXmlTextDoc)
const partialXmlTextStateVector = (() => {
  const encoder = encoding.createEncoder()
  encoding.writeVarUint(encoder, 1)
  encoding.writeVarUint(encoder, 137)
  encoding.writeVarUint(encoder, 4)
  return encoding.toUint8Array(encoder)
})()
const partialXmlTextDiff = Y.encodeStateAsUpdate(partialXmlTextDoc, partialXmlTextStateVector)
const partialXmlTextDiffV2 = Y.encodeStateAsUpdateV2(partialXmlTextDoc, partialXmlTextStateVector)

const partialXmlTextSurrogateDoc = new Y.Doc({ guid: 'partial-xml-text-surrogate-diff-doc', gc: false })
partialXmlTextSurrogateDoc.clientID = 164
const partialXmlSurrogateParagraph = new Y.XmlElement('p')
const partialXmlSurrogateText = new Y.XmlText()
partialXmlSurrogateParagraph.insert(0, [partialXmlSurrogateText])
partialXmlTextSurrogateDoc.getXmlFragment('xml').insert(0, [partialXmlSurrogateParagraph])
partialXmlSurrogateText.insert(0, 'A😀BC')
const partialXmlTextSurrogateUpdate = Y.encodeStateAsUpdate(partialXmlTextSurrogateDoc)
const partialXmlTextSurrogateStateVector = stateVector([[164, 4]])
const partialXmlTextSurrogateDiff = Y.encodeStateAsUpdate(partialXmlTextSurrogateDoc, partialXmlTextSurrogateStateVector)
const partialXmlTextSurrogateDiffV2 = Y.encodeStateAsUpdateV2(partialXmlTextSurrogateDoc, partialXmlTextSurrogateStateVector)

const partialXmlTextFormattedSurrogateDoc = new Y.Doc({ guid: 'partial-xml-text-formatted-surrogate-diff-doc', gc: false })
partialXmlTextFormattedSurrogateDoc.clientID = 171
const partialXmlFormattedSurrogateParagraph = new Y.XmlElement('p')
const partialXmlFormattedSurrogateText = new Y.XmlText()
partialXmlFormattedSurrogateParagraph.insert(0, [partialXmlFormattedSurrogateText])
partialXmlTextFormattedSurrogateDoc.getXmlFragment('xml').insert(0, [partialXmlFormattedSurrogateParagraph])
partialXmlFormattedSurrogateText.insert(0, 'A😀BC')
partialXmlFormattedSurrogateText.format(1, 3, { bold: true })
const partialXmlTextFormattedSurrogateStateVector = stateVector([[171, 4]])

const partialXmlAttributesDoc = new Y.Doc({ guid: 'partial-xml-attributes-diff-doc', gc: false })
partialXmlAttributesDoc.clientID = 144
const partialXmlAttributesElement = new Y.XmlElement('p')
partialXmlAttributesDoc.getXmlFragment('xml').insert(0, [partialXmlAttributesElement])
partialXmlAttributesElement.setAttribute('class', 'lead')
partialXmlAttributesElement.setAttribute('class', 'quiet')
partialXmlAttributesElement.setAttribute('data-id', '42')
const partialXmlAttributesUpdate = Y.encodeStateAsUpdate(partialXmlAttributesDoc)
const partialXmlAttributesStateVector = stateVector([[144, 1]])
const partialXmlAttributesDiff = Y.encodeStateAsUpdate(partialXmlAttributesDoc, partialXmlAttributesStateVector)
const partialXmlAttributesDiffV2 = Y.encodeStateAsUpdateV2(partialXmlAttributesDoc, partialXmlAttributesStateVector)

const partialXmlHookMapDoc = new Y.Doc({ guid: 'partial-xml-hook-map-diff-doc', gc: false })
partialXmlHookMapDoc.clientID = 158
const partialXmlHook = new Y.XmlHook('mention')
partialXmlHookMapDoc.getXmlFragment('xml').insert(0, [partialXmlHook])
const partialXmlHookMapPrefixUpdate = Y.encodeStateAsUpdate(partialXmlHookMapDoc)
const partialXmlHookMapPrefixUpdateV2 = Y.encodeStateAsUpdateV2(partialXmlHookMapDoc)
partialXmlHook.set('role', 'base')
partialXmlHook.set('label', 'Ada')
const partialXmlHookMapUpdate = Y.encodeStateAsUpdate(partialXmlHookMapDoc)
const partialXmlHookMapStateVector = stateVector([[158, 1]])
const partialXmlHookMapDiff = Y.encodeStateAsUpdate(partialXmlHookMapDoc, partialXmlHookMapStateVector)
const partialXmlHookMapDiffV2 = Y.encodeStateAsUpdateV2(partialXmlHookMapDoc, partialXmlHookMapStateVector)

const partialXmlHookXmlSharedDoc = new Y.Doc({ guid: 'partial-xml-hook-xml-shared-type-diff-doc', gc: false })
partialXmlHookXmlSharedDoc.clientID = 172
const partialXmlHookXmlSharedHook = new Y.XmlHook('mention')
const partialXmlHookXmlSharedElement = new Y.XmlElement('p')
const partialXmlHookXmlSharedFragment = new Y.XmlFragment()
partialXmlHookXmlSharedHook.set('element', partialXmlHookXmlSharedElement)
partialXmlHookXmlSharedHook.set('fragment', partialXmlHookXmlSharedFragment)
partialXmlHookXmlSharedDoc.getXmlFragment('xml').insert(0, [partialXmlHookXmlSharedHook])
const partialXmlHookXmlSharedPrefixUpdate = Y.encodeStateAsUpdate(partialXmlHookXmlSharedDoc)
const partialXmlHookXmlSharedPrefixUpdateV2 = Y.encodeStateAsUpdateV2(partialXmlHookXmlSharedDoc)
const partialXmlHookXmlSharedStateVector = Y.encodeStateVector(partialXmlHookXmlSharedDoc)
const partialXmlHookXmlSharedElementText = new Y.XmlText()
partialXmlHookXmlSharedElement.insert(0, [partialXmlHookXmlSharedElementText])
partialXmlHookXmlSharedElementText.insert(0, 'Partial')
const partialXmlHookXmlSharedFragmentText = new Y.XmlText()
partialXmlHookXmlSharedFragment.insert(0, [partialXmlHookXmlSharedFragmentText])
partialXmlHookXmlSharedFragmentText.insert(0, 'Frag')
const partialXmlHookXmlSharedUpdate = Y.encodeStateAsUpdate(partialXmlHookXmlSharedDoc)
const partialXmlHookXmlSharedDiff = Y.encodeStateAsUpdate(partialXmlHookXmlSharedDoc, partialXmlHookXmlSharedStateVector)
const partialXmlHookXmlSharedDiffV2 = Y.encodeStateAsUpdateV2(partialXmlHookXmlSharedDoc, partialXmlHookXmlSharedStateVector)

const partialXmlTextXmlSharedDoc = new Y.Doc({ guid: 'partial-xml-text-xml-shared-type-diff-doc', gc: false })
partialXmlTextXmlSharedDoc.clientID = 173
const partialXmlTextXmlSharedText = new Y.XmlText()
partialXmlTextXmlSharedText.insert(0, 'Xml')
const partialXmlTextXmlSharedBody = new Y.Text()
const partialXmlTextXmlSharedElement = new Y.XmlElement('span')
const partialXmlTextXmlSharedFragment = new Y.XmlFragment()
partialXmlTextXmlSharedText.setAttribute('body', partialXmlTextXmlSharedBody)
partialXmlTextXmlSharedText.setAttribute('element', partialXmlTextXmlSharedElement)
partialXmlTextXmlSharedText.setAttribute('fragment', partialXmlTextXmlSharedFragment)
partialXmlTextXmlSharedDoc.getXmlFragment('xml').insert(0, [partialXmlTextXmlSharedText])
const partialXmlTextXmlSharedPrefixUpdate = Y.encodeStateAsUpdate(partialXmlTextXmlSharedDoc)
const partialXmlTextXmlSharedPrefixUpdateV2 = Y.encodeStateAsUpdateV2(partialXmlTextXmlSharedDoc)
const partialXmlTextXmlSharedStateVector = Y.encodeStateVector(partialXmlTextXmlSharedDoc)
partialXmlTextXmlSharedBody.insert(0, 'Body')
const partialXmlTextXmlSharedElementText = new Y.XmlText()
partialXmlTextXmlSharedElement.insert(0, [partialXmlTextXmlSharedElementText])
partialXmlTextXmlSharedElementText.insert(0, 'Element')
const partialXmlTextXmlSharedFragmentText = new Y.XmlText()
partialXmlTextXmlSharedFragment.insert(0, [partialXmlTextXmlSharedFragmentText])
partialXmlTextXmlSharedFragmentText.insert(0, 'Frag')
const partialXmlTextXmlSharedUpdate = Y.encodeStateAsUpdate(partialXmlTextXmlSharedDoc)
const partialXmlTextXmlSharedDiff = Y.encodeStateAsUpdate(partialXmlTextXmlSharedDoc, partialXmlTextXmlSharedStateVector)
const partialXmlTextXmlSharedDiffV2 = Y.encodeStateAsUpdateV2(partialXmlTextXmlSharedDoc, partialXmlTextXmlSharedStateVector)

const partialXmlTextSharedAttributeReplaceDoc = new Y.Doc({ guid: 'partial-xml-text-shared-attribute-replace-diff-doc', gc: false })
partialXmlTextSharedAttributeReplaceDoc.clientID = 174
const partialXmlTextSharedAttributeReplaceText = new Y.XmlText()
partialXmlTextSharedAttributeReplaceText.insert(0, 'Xml')
partialXmlTextSharedAttributeReplaceDoc.getXmlFragment('xml').insert(0, [partialXmlTextSharedAttributeReplaceText])
const partialXmlTextSharedAttributeReplaceBody = new Y.Text()
partialXmlTextSharedAttributeReplaceText.setAttribute('body', partialXmlTextSharedAttributeReplaceBody)
partialXmlTextSharedAttributeReplaceBody.insert(0, 'Old')
const partialXmlTextSharedAttributeReplacePrefixUpdate = Y.encodeStateAsUpdate(partialXmlTextSharedAttributeReplaceDoc)
const partialXmlTextSharedAttributeReplacePrefixUpdateV2 = Y.encodeStateAsUpdateV2(partialXmlTextSharedAttributeReplaceDoc)
const partialXmlTextSharedAttributeReplaceStateVector = Y.encodeStateVector(partialXmlTextSharedAttributeReplaceDoc)
partialXmlTextSharedAttributeReplaceText.setAttribute('body', 'plain')
partialXmlTextSharedAttributeReplaceText.setAttribute('inline', new Y.XmlElement('span'))
const partialXmlTextSharedAttributeReplaceUpdate = Y.encodeStateAsUpdate(partialXmlTextSharedAttributeReplaceDoc)
const partialXmlTextSharedAttributeReplaceDiff = Y.encodeStateAsUpdate(partialXmlTextSharedAttributeReplaceDoc, partialXmlTextSharedAttributeReplaceStateVector)
const partialXmlTextSharedAttributeReplaceDiffV2 = Y.encodeStateAsUpdateV2(partialXmlTextSharedAttributeReplaceDoc, partialXmlTextSharedAttributeReplaceStateVector)

const partialXmlChildrenDoc = new Y.Doc({ guid: 'partial-xml-children-diff-doc', gc: false })
partialXmlChildrenDoc.clientID = 159
const partialXmlChildrenRoot = new Y.XmlElement('root')
partialXmlChildrenDoc.getXmlFragment('xml').insert(0, [partialXmlChildrenRoot])
const partialXmlChildrenText = new Y.XmlText()
partialXmlChildrenText.insert(0, 'A')
partialXmlChildrenRoot.insert(0, [partialXmlChildrenText])
partialXmlChildrenRoot.insert(1, [new Y.XmlElement('br')])
const partialXmlChildrenUpdate = Y.encodeStateAsUpdate(partialXmlChildrenDoc)
const partialXmlChildrenStateVector = stateVector([[159, 1]])
const partialXmlChildrenDiff = Y.encodeStateAsUpdate(partialXmlChildrenDoc, partialXmlChildrenStateVector)
const partialXmlChildrenDiffV2 = Y.encodeStateAsUpdateV2(partialXmlChildrenDoc, partialXmlChildrenStateVector)

const partialNestedXmlFragmentDoc = new Y.Doc({ guid: 'partial-nested-xml-fragment-diff-doc', gc: false })
partialNestedXmlFragmentDoc.clientID = 167
const partialNestedXmlFragment = new Y.XmlFragment()
partialNestedXmlFragmentDoc.getArray('array').insert(0, [partialNestedXmlFragment])
const partialNestedXmlFragmentPrefixUpdate = Y.encodeStateAsUpdate(partialNestedXmlFragmentDoc)
const partialNestedXmlFragmentPrefixUpdateV2 = Y.encodeStateAsUpdateV2(partialNestedXmlFragmentDoc)
const partialNestedXmlFragmentStateVector = Y.encodeStateVector(partialNestedXmlFragmentDoc)
const partialNestedXmlFragmentText = new Y.XmlText()
partialNestedXmlFragmentText.insert(0, 'A')
const partialNestedXmlFragmentParagraph = new Y.XmlElement('p')
const partialNestedXmlFragmentParagraphText = new Y.XmlText()
partialNestedXmlFragmentParagraphText.insert(0, 'B')
partialNestedXmlFragmentParagraph.insert(0, [partialNestedXmlFragmentParagraphText])
partialNestedXmlFragment.insert(0, [
  partialNestedXmlFragmentText,
  partialNestedXmlFragmentParagraph
])
const partialNestedXmlFragmentUpdate = Y.encodeStateAsUpdate(partialNestedXmlFragmentDoc)
const partialNestedXmlFragmentDiff = Y.encodeStateAsUpdate(partialNestedXmlFragmentDoc, partialNestedXmlFragmentStateVector)
const partialNestedXmlFragmentDiffV2 = Y.encodeStateAsUpdateV2(partialNestedXmlFragmentDoc, partialNestedXmlFragmentStateVector)

const partialMapXmlFragmentDoc = new Y.Doc({ guid: 'partial-map-xml-fragment-diff-doc', gc: false })
partialMapXmlFragmentDoc.clientID = 168
const partialMapXmlFragment = new Y.XmlFragment()
partialMapXmlFragmentDoc.getMap('map').set('xml', partialMapXmlFragment)
const partialMapXmlFragmentPrefixUpdate = Y.encodeStateAsUpdate(partialMapXmlFragmentDoc)
const partialMapXmlFragmentPrefixUpdateV2 = Y.encodeStateAsUpdateV2(partialMapXmlFragmentDoc)
const partialMapXmlFragmentStateVector = Y.encodeStateVector(partialMapXmlFragmentDoc)
const partialMapXmlFragmentText = new Y.XmlText()
partialMapXmlFragmentText.insert(0, 'A')
const partialMapXmlFragmentParagraph = new Y.XmlElement('p')
const partialMapXmlFragmentParagraphText = new Y.XmlText()
partialMapXmlFragmentParagraphText.insert(0, 'B')
partialMapXmlFragmentParagraph.insert(0, [partialMapXmlFragmentParagraphText])
partialMapXmlFragment.insert(0, [
  partialMapXmlFragmentText,
  partialMapXmlFragmentParagraph
])
const partialMapXmlFragmentUpdate = Y.encodeStateAsUpdate(partialMapXmlFragmentDoc)
const partialMapXmlFragmentDiff = Y.encodeStateAsUpdate(partialMapXmlFragmentDoc, partialMapXmlFragmentStateVector)
const partialMapXmlFragmentDiffV2 = Y.encodeStateAsUpdateV2(partialMapXmlFragmentDoc, partialMapXmlFragmentStateVector)

const partialDeletedTextSliceDoc = new Y.Doc({ guid: 'partial-deleted-text-slice-diff-doc', gc: false })
partialDeletedTextSliceDoc.clientID = 138
partialDeletedTextSliceDoc.getText('content').insert(0, 'ABCD')
partialDeletedTextSliceDoc.getText('content').delete(1, 2)
const partialDeletedTextSliceUpdate = Y.encodeStateAsUpdate(partialDeletedTextSliceDoc)
const partialDeletedTextSliceStateVector = stateVector([[138, 1]])
const partialDeletedTextSliceDiff = Y.encodeStateAsUpdate(partialDeletedTextSliceDoc, partialDeletedTextSliceStateVector)
const partialDeletedTextSliceDiffV2 = Y.encodeStateAsUpdateV2(partialDeletedTextSliceDoc, partialDeletedTextSliceStateVector)

const partialDeletedArraySliceDoc = new Y.Doc({ guid: 'partial-deleted-array-slice-diff-doc', gc: false })
partialDeletedArraySliceDoc.clientID = 139
partialDeletedArraySliceDoc.getArray('array').insert(0, ['A', 'B', 'C', 'D'])
partialDeletedArraySliceDoc.getArray('array').delete(1, 2)
const partialDeletedArraySliceUpdate = Y.encodeStateAsUpdate(partialDeletedArraySliceDoc)
const partialDeletedArraySliceStateVector = stateVector([[139, 1]])
const partialDeletedArraySliceDiff = Y.encodeStateAsUpdate(partialDeletedArraySliceDoc, partialDeletedArraySliceStateVector)
const partialDeletedArraySliceDiffV2 = Y.encodeStateAsUpdateV2(partialDeletedArraySliceDoc, partialDeletedArraySliceStateVector)

const partialNestedArrayMapDoc = new Y.Doc({ guid: 'partial-nested-array-map-diff-doc', gc: false })
partialNestedArrayMapDoc.clientID = 142
const partialNestedArrayMapRoot = partialNestedArrayMapDoc.getArray('array')
const partialNestedArrayMapArray = new Y.Array()
const partialNestedArrayMapMap = new Y.Map()
partialNestedArrayMapRoot.insert(0, [partialNestedArrayMapArray, partialNestedArrayMapMap])
partialNestedArrayMapArray.insert(0, ['child'])
partialNestedArrayMapMap.set('key', 'value')
const partialNestedArrayMapUpdate = Y.encodeStateAsUpdate(partialNestedArrayMapDoc)
const partialNestedArrayMapStateVector = stateVector([[142, 2]])
const partialNestedArrayMapDiff = Y.encodeStateAsUpdate(partialNestedArrayMapDoc, partialNestedArrayMapStateVector)
const partialNestedArrayMapDiffV2 = Y.encodeStateAsUpdateV2(partialNestedArrayMapDoc, partialNestedArrayMapStateVector)

const partialConflictCascadeCase = concurrentFixtures.find(({ name }) => name === 'map-concurrent-delete-and-edit-nested-text')
const partialConflictCascadeDoc = new Y.Doc({ guid: 'partial-conflict-cascade-delete-set-doc', gc: false })
partialConflictCascadeDoc.getMap('map')
for (const update of partialConflictCascadeCase.updatesV1) {
  Y.applyUpdate(partialConflictCascadeDoc, fromBase64(update))
}
const partialConflictCascadeUpdate = Y.encodeStateAsUpdate(partialConflictCascadeDoc)
const partialConflictCascadeUpdateV2 = Y.encodeStateAsUpdateV2(partialConflictCascadeDoc)
const partialConflictCascadeStateVector = Y.encodeStateVector(partialConflictCascadeDoc)
const partialConflictCascadeDiff = Y.encodeStateAsUpdate(partialConflictCascadeDoc, partialConflictCascadeStateVector)
const partialConflictCascadeDiffV2 = Y.encodeStateAsUpdateV2(partialConflictCascadeDoc, partialConflictCascadeStateVector)

const partialArrayTextConflictCascadeCase = concurrentFixtures.find(({ name }) => name === 'array-concurrent-delete-and-edit-nested-text')
const partialArrayTextConflictCascadeDoc = new Y.Doc({ guid: 'partial-array-text-conflict-cascade-delete-set-doc', gc: false })
partialArrayTextConflictCascadeDoc.getArray('array')
for (const update of partialArrayTextConflictCascadeCase.updatesV1) {
  Y.applyUpdate(partialArrayTextConflictCascadeDoc, fromBase64(update))
}
const partialArrayTextConflictCascadeUpdate = Y.encodeStateAsUpdate(partialArrayTextConflictCascadeDoc)
const partialArrayTextConflictCascadeUpdateV2 = Y.encodeStateAsUpdateV2(partialArrayTextConflictCascadeDoc)
const partialArrayTextConflictCascadeStateVector = Y.encodeStateVector(partialArrayTextConflictCascadeDoc)
const partialArrayTextConflictCascadeDiff = Y.encodeStateAsUpdate(partialArrayTextConflictCascadeDoc, partialArrayTextConflictCascadeStateVector)
const partialArrayTextConflictCascadeDiffV2 = Y.encodeStateAsUpdateV2(partialArrayTextConflictCascadeDoc, partialArrayTextConflictCascadeStateVector)

const partialArrayArrayConflictCascadeCase = concurrentFixtures.find(({ name }) => name === 'array-concurrent-delete-and-edit-nested-array')
const partialArrayArrayConflictCascadeDoc = new Y.Doc({ guid: 'partial-array-array-conflict-cascade-delete-set-doc', gc: false })
partialArrayArrayConflictCascadeDoc.getArray('array')
for (const update of partialArrayArrayConflictCascadeCase.updatesV1) {
  Y.applyUpdate(partialArrayArrayConflictCascadeDoc, fromBase64(update))
}
const partialArrayArrayConflictCascadeUpdate = Y.encodeStateAsUpdate(partialArrayArrayConflictCascadeDoc)
const partialArrayArrayConflictCascadeUpdateV2 = Y.encodeStateAsUpdateV2(partialArrayArrayConflictCascadeDoc)
const partialArrayArrayConflictCascadeStateVector = Y.encodeStateVector(partialArrayArrayConflictCascadeDoc)
const partialArrayArrayConflictCascadeDiff = Y.encodeStateAsUpdate(partialArrayArrayConflictCascadeDoc, partialArrayArrayConflictCascadeStateVector)
const partialArrayArrayConflictCascadeDiffV2 = Y.encodeStateAsUpdateV2(partialArrayArrayConflictCascadeDoc, partialArrayArrayConflictCascadeStateVector)

const partialArrayXmlConflictCascadeCase = concurrentFixtures.find(({ name }) => name === 'array-concurrent-delete-and-edit-xml-fragment')
const partialArrayXmlConflictCascadeDoc = new Y.Doc({ guid: 'partial-array-xml-conflict-cascade-delete-set-doc', gc: false })
partialArrayXmlConflictCascadeDoc.getArray('array')
for (const update of partialArrayXmlConflictCascadeCase.updatesV1) {
  Y.applyUpdate(partialArrayXmlConflictCascadeDoc, fromBase64(update))
}
const partialArrayXmlConflictCascadeUpdate = Y.encodeStateAsUpdate(partialArrayXmlConflictCascadeDoc)
const partialArrayXmlConflictCascadeUpdateV2 = Y.encodeStateAsUpdateV2(partialArrayXmlConflictCascadeDoc)
const partialArrayXmlConflictCascadeStateVector = Y.encodeStateVector(partialArrayXmlConflictCascadeDoc)
const partialArrayXmlConflictCascadeDiff = Y.encodeStateAsUpdate(partialArrayXmlConflictCascadeDoc, partialArrayXmlConflictCascadeStateVector)
const partialArrayXmlConflictCascadeDiffV2 = Y.encodeStateAsUpdateV2(partialArrayXmlConflictCascadeDoc, partialArrayXmlConflictCascadeStateVector)

const partialTextThreeWayConflictCase = concurrentFixtures.find(({ name }) => name === 'text-three-way-concurrent-between-same-neighbors')
const partialTextThreeWayConflictDoc = new Y.Doc({ guid: 'partial-text-three-way-conflict-doc', gc: false })
partialTextThreeWayConflictDoc.getText('content')
for (const update of partialTextThreeWayConflictCase.updatesV1) {
  Y.applyUpdate(partialTextThreeWayConflictDoc, fromBase64(update))
}
const partialTextThreeWayKnownDoc = new Y.Doc({ guid: 'partial-text-three-way-conflict-known-doc', gc: false })
partialTextThreeWayKnownDoc.getText('content')
const [
  ,
  ,
  partialTextThreeWayFirstUpdate,
  partialTextThreeWayBaseUpdate
] = partialTextThreeWayConflictCase.updatesV1.map(fromBase64)
Y.applyUpdate(partialTextThreeWayKnownDoc, partialTextThreeWayBaseUpdate)
Y.applyUpdate(partialTextThreeWayKnownDoc, partialTextThreeWayFirstUpdate)
const partialTextThreeWayStateVector = Y.encodeStateVector(partialTextThreeWayKnownDoc)

const partialArrayThreeWayConflictCase = concurrentFixtures.find(({ name }) => name === 'array-three-way-concurrent-between-same-neighbors')
const partialArrayThreeWayConflictDoc = new Y.Doc({ guid: 'partial-array-three-way-conflict-doc', gc: false })
partialArrayThreeWayConflictDoc.getArray('array')
for (const update of partialArrayThreeWayConflictCase.updatesV1) {
  Y.applyUpdate(partialArrayThreeWayConflictDoc, fromBase64(update))
}
const partialArrayThreeWayKnownDoc = new Y.Doc({ guid: 'partial-array-three-way-conflict-known-doc', gc: false })
partialArrayThreeWayKnownDoc.getArray('array')
const [
  ,
  ,
  partialArrayThreeWayFirstUpdate,
  partialArrayThreeWayBaseUpdate
] = partialArrayThreeWayConflictCase.updatesV1.map(fromBase64)
Y.applyUpdate(partialArrayThreeWayKnownDoc, partialArrayThreeWayBaseUpdate)
Y.applyUpdate(partialArrayThreeWayKnownDoc, partialArrayThreeWayFirstUpdate)
const partialArrayThreeWayStateVector = Y.encodeStateVector(partialArrayThreeWayKnownDoc)

const partialXmlConflictCase = concurrentFixtures.find(({ name }) => name === 'xml-three-way-insert-delete-deleted-right-origin')
const partialXmlConflictDoc = new Y.Doc({ guid: 'partial-xml-three-way-conflict-doc', gc: false })
partialXmlConflictDoc.getXmlFragment('xml')
for (const update of partialXmlConflictCase.updatesV1) {
  Y.applyUpdate(partialXmlConflictDoc, fromBase64(update))
}
const partialXmlConflictKnownDoc = new Y.Doc({ guid: 'partial-xml-three-way-conflict-known-doc', gc: false })
partialXmlConflictKnownDoc.getXmlFragment('xml')
const [
  ,
  ,
  partialXmlConflictFirstUpdate,
  partialXmlConflictBaseUpdate
] = partialXmlConflictCase.updatesV1.map(fromBase64)
Y.applyUpdate(partialXmlConflictKnownDoc, partialXmlConflictBaseUpdate)
Y.applyUpdate(partialXmlConflictKnownDoc, partialXmlConflictFirstUpdate)
const partialXmlConflictStateVector = Y.encodeStateVector(partialXmlConflictKnownDoc)

const partialXmlTextDeletedOriginConflictCase = concurrentFixtures.find(({ name }) => name === 'xml-text-concurrent-insert-after-deleted-origin')
const partialXmlTextDeletedOriginConflictDoc = new Y.Doc({ guid: 'partial-xml-text-deleted-origin-conflict-doc', gc: false })
partialXmlTextDeletedOriginConflictDoc.getXmlFragment('xml')
for (const update of partialXmlTextDeletedOriginConflictCase.updatesV1) {
  Y.applyUpdate(partialXmlTextDeletedOriginConflictDoc, fromBase64(update))
}
const partialXmlTextDeletedOriginConflictKnownDoc = new Y.Doc({ guid: 'partial-xml-text-deleted-origin-conflict-known-doc', gc: false })
partialXmlTextDeletedOriginConflictKnownDoc.getXmlFragment('xml')
const [
  ,
  ,
  partialXmlTextDeletedOriginConflictFirstUpdate,
  partialXmlTextDeletedOriginConflictBaseUpdate
] = partialXmlTextDeletedOriginConflictCase.updatesV1.map(fromBase64)
Y.applyUpdate(partialXmlTextDeletedOriginConflictKnownDoc, partialXmlTextDeletedOriginConflictBaseUpdate)
Y.applyUpdate(partialXmlTextDeletedOriginConflictKnownDoc, partialXmlTextDeletedOriginConflictFirstUpdate)
const partialXmlTextDeletedOriginConflictStateVector = Y.encodeStateVector(partialXmlTextDeletedOriginConflictKnownDoc)

const partialMultiClientDiffDoc = new Y.Doc({ guid: 'partial-multi-client-diff-doc', gc: false })
partialMultiClientDiffDoc.getText('content')
const partialMultiClientLeft = new Y.Doc({ guid: 'partial-multi-client-left', gc: false })
partialMultiClientLeft.clientID = 140
partialMultiClientLeft.getText('content').insert(0, 'ABC')
const partialMultiClientRight = new Y.Doc({ guid: 'partial-multi-client-right', gc: false })
partialMultiClientRight.clientID = 141
partialMultiClientRight.getText('content').insert(0, 'XYZ')
Y.applyUpdate(partialMultiClientDiffDoc, Y.encodeStateAsUpdate(partialMultiClientLeft))
Y.applyUpdate(partialMultiClientDiffDoc, Y.encodeStateAsUpdate(partialMultiClientRight))
const partialMultiClientUpdate = Y.encodeStateAsUpdate(partialMultiClientDiffDoc)
const partialMultiClientStateVector = stateVector([[140, 2]])
const partialMultiClientDiff = Y.encodeStateAsUpdate(partialMultiClientDiffDoc, partialMultiClientStateVector)
const partialMultiClientDiffV2 = Y.encodeStateAsUpdateV2(partialMultiClientDiffDoc, partialMultiClientStateVector)

const partialReinsertArrayKnownFullDoc = new Y.Doc({ guid: 'partial-reinsert-array-known-full-doc', gc: false })
partialReinsertArrayKnownFullDoc.clientID = 160
partialReinsertArrayKnownFullDoc.getArray('array').insert(0, ['A', 'B', 'C'])
partialReinsertArrayKnownFullDoc.getArray('array').delete(1, 1)
partialReinsertArrayKnownFullDoc.getArray('array').insert(1, ['X'])
const partialReinsertArrayKnownFullStateVector = stateVector([[160, 3]])

const partialReinsertArrayKnownPrefixDoc = new Y.Doc({ guid: 'partial-reinsert-array-known-prefix-doc', gc: false })
partialReinsertArrayKnownPrefixDoc.clientID = 161
partialReinsertArrayKnownPrefixDoc.getArray('array').insert(0, ['A', 'B', 'C'])
partialReinsertArrayKnownPrefixDoc.getArray('array').delete(1, 1)
partialReinsertArrayKnownPrefixDoc.getArray('array').insert(1, ['X'])
const partialReinsertArrayKnownPrefixStateVector = stateVector([[161, 1]])

const partialReinsertTextKnownFullDoc = new Y.Doc({ guid: 'partial-reinsert-text-known-full-doc', gc: false })
partialReinsertTextKnownFullDoc.clientID = 162
partialReinsertTextKnownFullDoc.getText('content').insert(0, 'ABC')
partialReinsertTextKnownFullDoc.getText('content').delete(1, 1)
partialReinsertTextKnownFullDoc.getText('content').insert(1, 'X')
const partialReinsertTextKnownFullStateVector = stateVector([[162, 3]])

const partialReinsertXmlChildrenKnownFullDoc = new Y.Doc({ guid: 'partial-reinsert-xml-children-known-full-doc', gc: false })
partialReinsertXmlChildrenKnownFullDoc.clientID = 163
const partialReinsertXmlRoot = new Y.XmlElement('root')
partialReinsertXmlChildrenKnownFullDoc.getXmlFragment('xml').insert(0, [partialReinsertXmlRoot])
partialReinsertXmlRoot.insert(0, [new Y.XmlElement('a'), new Y.XmlElement('b'), new Y.XmlElement('c')])
partialReinsertXmlRoot.delete(1, 1)
partialReinsertXmlRoot.insert(1, [new Y.XmlElement('x')])
const partialReinsertXmlChildrenKnownFullStateVector = stateVector([[163, 4]])

const partialDiffFixtureCase = (name, doc, targetStateVector) => {
  const updateV1 = Y.encodeStateAsUpdate(doc)
  const updateV2 = Y.encodeStateAsUpdateV2(doc)
  const diffV1 = Y.encodeStateAsUpdate(doc, targetStateVector)
  const diffV2 = Y.encodeStateAsUpdateV2(doc, targetStateVector)

  return {
    name,
    updateV1: toBase64(updateV1),
    updateV2: toBase64(updateV2),
    targetStateVectorV1: toBase64(targetStateVector),
    expectedDiffV1: toBase64(diffV1),
    expectedDiffV2: toBase64(diffV2),
    expectedDecodedDiff: {
      structs: Y.decodeUpdate(diffV1).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdate(diffV1).ds)
    },
    expectedDecodedDiffV2: {
      structs: Y.decodeUpdateV2(diffV2).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(diffV2).ds)
    }
  }
}

const partialPrefixDiffFixtureCase = (name, type, doc, targetStateVector, prefixDoc) => ({
  ...partialDiffFixtureCase(name, doc, targetStateVector),
  type,
  prefixUpdateV1: toBase64(Y.encodeStateAsUpdate(prefixDoc)),
  prefixUpdateV2: toBase64(Y.encodeStateAsUpdateV2(prefixDoc)),
  json: normalizeValue(doc.toJSON())
})

const partialDiffFixtures = {
  cases: [
    {
      name: 'text-partial-utf16',
      updateV1: toBase64(partialDiffUpdate),
      updateV2: toBase64(Y.encodeStateAsUpdateV2(partialDiffDoc)),
      targetStateVectorV1: toBase64(partialDiffStateVector),
      expectedDiffV1: toBase64(partialDiff),
      expectedDiffV2: toBase64(partialDiffV2),
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialDiff).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialDiff).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialDiffV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialDiffV2).ds)
      }
    },
    {
      name: 'text-partial-inside-utf16-surrogate',
      updateV1: toBase64(partialDiffUpdate),
      updateV2: toBase64(Y.encodeStateAsUpdateV2(partialDiffDoc)),
      targetStateVectorV1: toBase64(partialDiffSurrogateStateVector),
      expectedDiffV1: toBase64(partialDiffSurrogate),
      expectedDiffV2: toBase64(partialDiffSurrogateV2),
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialDiffSurrogate).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialDiffSurrogate).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialDiffSurrogateV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialDiffSurrogateV2).ds)
      }
    },
    {
      name: 'array-partial-any',
      updateV1: toBase64(partialArrayUpdate),
      updateV2: toBase64(Y.encodeStateAsUpdateV2(partialArrayDoc)),
      targetStateVectorV1: toBase64(partialArrayStateVector),
      expectedDiffV1: toBase64(partialArrayDiff),
      expectedDiffV2: toBase64(partialArrayDiffV2),
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialArrayDiff).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialArrayDiff).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialArrayDiffV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialArrayDiffV2).ds)
      }
    },
    {
      name: 'array-partial-any-mixed-values',
      type: 'array',
      updateV1: toBase64(partialArrayMixedUpdate),
      updateV2: toBase64(Y.encodeStateAsUpdateV2(partialArrayMixedDoc)),
      prefixUpdateV1: toBase64(partialArrayMixedPrefixUpdate),
      prefixUpdateV2: toBase64(partialArrayMixedPrefixUpdateV2),
      targetStateVectorV1: toBase64(partialArrayMixedStateVector),
      expectedDiffV1: toBase64(partialArrayMixedDiff),
      expectedDiffV2: toBase64(partialArrayMixedDiffV2),
      json: normalizeValue(partialArrayMixedDoc.toJSON()),
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialArrayMixedDiff).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialArrayMixedDiff).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialArrayMixedDiffV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialArrayMixedDiffV2).ds)
      }
    },
    {
      name: 'delete-set-only',
      updateV1: toBase64(partialDeleteUpdate),
      updateV2: toBase64(Y.encodeStateAsUpdateV2(partialDeleteDoc)),
      targetStateVectorV1: toBase64(partialDeleteStateVector),
      expectedDiffV1: toBase64(partialDeleteDiff),
      expectedDiffV2: toBase64(partialDeleteDiffV2),
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialDeleteDiff).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialDeleteDiff).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialDeleteDiffV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialDeleteDiffV2).ds)
      }
    },
    {
      name: 'map-replace-from-middle',
      updateV1: toBase64(partialMapReplaceUpdate),
      updateV2: toBase64(Y.encodeStateAsUpdateV2(partialMapReplaceDoc)),
      targetStateVectorV1: toBase64(partialMapReplaceStateVector),
      expectedDiffV1: toBase64(partialMapReplaceDiff),
      expectedDiffV2: toBase64(partialMapReplaceDiffV2),
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialMapReplaceDiff).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialMapReplaceDiff).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialMapReplaceDiffV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialMapReplaceDiffV2).ds)
      }
    },
    {
      name: 'binary-after-known-clock',
      updateV1: toBase64(partialBinaryUpdate),
      updateV2: toBase64(Y.encodeStateAsUpdateV2(partialBinaryDoc)),
      targetStateVectorV1: toBase64(partialBinaryStateVector),
      expectedDiffV1: toBase64(partialBinaryDiff),
      expectedDiffV2: toBase64(partialBinaryDiffV2),
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialBinaryDiff).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialBinaryDiff).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialBinaryDiffV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialBinaryDiffV2).ds)
      }
    },
    {
      name: 'embed-after-known-text',
      updateV1: toBase64(partialEmbedUpdate),
      updateV2: toBase64(Y.encodeStateAsUpdateV2(partialEmbedDoc)),
      targetStateVectorV1: toBase64(partialEmbedStateVector),
      expectedDiffV1: toBase64(partialEmbedDiff),
      expectedDiffV2: toBase64(partialEmbedDiffV2),
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialEmbedDiff).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialEmbedDiff).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialEmbedDiffV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialEmbedDiffV2).ds)
      }
    },
    {
      name: 'subdoc-after-known-array-item',
      type: 'array',
      updateV1: toBase64(partialSubdocUpdate),
      updateV2: toBase64(Y.encodeStateAsUpdateV2(partialSubdocDoc)),
      prefixUpdateV1: toBase64(partialSubdocPrefixUpdate),
      prefixUpdateV2: toBase64(partialSubdocPrefixUpdateV2),
      targetStateVectorV1: toBase64(partialSubdocStateVector),
      expectedDiffV1: toBase64(partialSubdocDiff),
      expectedDiffV2: toBase64(partialSubdocDiffV2),
      json: normalizeValue(partialSubdocDoc.toJSON()),
      subdocs: [
        {
          root: 'array',
          path: [1],
          guid: 'partial-subdoc-child',
          meta: { kind: 'partial' },
          shouldLoad: true
        }
      ],
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialSubdocDiff).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialSubdocDiff).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialSubdocDiffV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialSubdocDiffV2).ds)
      }
    },
    {
      name: 'subdoc-after-known-map-entry',
      type: 'map',
      updateV1: toBase64(partialMapSubdocUpdate),
      updateV2: toBase64(Y.encodeStateAsUpdateV2(partialMapSubdocDoc)),
      prefixUpdateV1: toBase64(partialMapSubdocPrefixUpdate),
      prefixUpdateV2: toBase64(partialMapSubdocPrefixUpdateV2),
      targetStateVectorV1: toBase64(partialMapSubdocStateVector),
      expectedDiffV1: toBase64(partialMapSubdocDiff),
      expectedDiffV2: toBase64(partialMapSubdocDiffV2),
      json: normalizeValue(partialMapSubdocDoc.toJSON()),
      subdocs: [
        {
          root: 'map',
          path: ['child'],
          guid: 'partial-map-subdoc-child',
          meta: { kind: 'partial-map' },
          shouldLoad: true
        }
      ],
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialMapSubdocDiff).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialMapSubdocDiff).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialMapSubdocDiffV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialMapSubdocDiffV2).ds)
      }
    },
    {
      name: 'format-after-known-text',
      updateV1: toBase64(partialFormatUpdate),
      updateV2: toBase64(Y.encodeStateAsUpdateV2(partialFormatDoc)),
      targetStateVectorV1: toBase64(partialFormatStateVector),
      expectedDiffV1: toBase64(partialFormatDiff),
      expectedDiffV2: toBase64(partialFormatDiffV2),
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialFormatDiff).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialFormatDiff).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialFormatDiffV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialFormatDiffV2).ds)
      }
    },
    {
      name: 'root-text-attributes-after-known-content',
      type: 'text',
      updateV1: toBase64(partialTextAttributesUpdate),
      updateV2: toBase64(Y.encodeStateAsUpdateV2(partialTextAttributesDoc)),
      prefixUpdateV1: toBase64(partialTextAttributesPrefixUpdate),
      prefixUpdateV2: toBase64(partialTextAttributesPrefixUpdateV2),
      targetStateVectorV1: toBase64(partialTextAttributesStateVector),
      expectedDiffV1: toBase64(partialTextAttributesDiff),
      expectedDiffV2: toBase64(partialTextAttributesDiffV2),
      json: normalizeValue(partialTextAttributesDoc.toJSON()),
      textAttributes: normalizeValue(partialTextAttributesText.getAttributes()),
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialTextAttributesDiff).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialTextAttributesDiff).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialTextAttributesDiffV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialTextAttributesDiffV2).ds)
      }
    },
    {
      name: 'nested-text-after-parent-type',
      updateV1: toBase64(partialNestedTextUpdate),
      updateV2: toBase64(Y.encodeStateAsUpdateV2(partialNestedTextDoc)),
      targetStateVectorV1: toBase64(partialNestedTextStateVector),
      expectedDiffV1: toBase64(partialNestedTextDiff),
      expectedDiffV2: toBase64(partialNestedTextDiffV2),
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialNestedTextDiff).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialNestedTextDiff).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialNestedTextDiffV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialNestedTextDiffV2).ds)
      }
    },
    {
      name: 'nested-text-attributes-after-known-content',
      updateV1: toBase64(partialNestedTextAttributesUpdate),
      updateV2: toBase64(Y.encodeStateAsUpdateV2(partialNestedTextAttributesDoc)),
      prefixUpdateV1: toBase64(partialNestedTextAttributesPrefixUpdate),
      prefixUpdateV2: toBase64(partialNestedTextAttributesPrefixUpdateV2),
      targetStateVectorV1: toBase64(partialNestedTextAttributesStateVector),
      expectedDiffV1: toBase64(partialNestedTextAttributesDiff),
      expectedDiffV2: toBase64(partialNestedTextAttributesDiffV2),
      json: normalizeValue(partialNestedTextAttributesDoc.toJSON()),
      nestedTextAttributes: normalizeValue(partialNestedTextAttributesText.getAttributes()),
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialNestedTextAttributesDiff).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialNestedTextAttributesDiff).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialNestedTextAttributesDiffV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialNestedTextAttributesDiffV2).ds)
      }
    },
    {
      name: 'xml-text-partial-formatting',
      updateV1: toBase64(partialXmlTextUpdate),
      updateV2: toBase64(Y.encodeStateAsUpdateV2(partialXmlTextDoc)),
      targetStateVectorV1: toBase64(partialXmlTextStateVector),
      expectedDiffV1: toBase64(partialXmlTextDiff),
      expectedDiffV2: toBase64(partialXmlTextDiffV2),
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialXmlTextDiff).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialXmlTextDiff).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialXmlTextDiffV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialXmlTextDiffV2).ds)
      }
    },
    {
      name: 'xml-text-partial-inside-utf16-surrogate',
      updateV1: toBase64(partialXmlTextSurrogateUpdate),
      updateV2: toBase64(Y.encodeStateAsUpdateV2(partialXmlTextSurrogateDoc)),
      targetStateVectorV1: toBase64(partialXmlTextSurrogateStateVector),
      expectedDiffV1: toBase64(partialXmlTextSurrogateDiff),
      expectedDiffV2: toBase64(partialXmlTextSurrogateDiffV2),
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialXmlTextSurrogateDiff).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialXmlTextSurrogateDiff).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialXmlTextSurrogateDiffV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialXmlTextSurrogateDiffV2).ds)
      }
    },
    partialDiffFixtureCase(
      'xml-text-formatted-partial-inside-utf16-surrogate',
      partialXmlTextFormattedSurrogateDoc,
      partialXmlTextFormattedSurrogateStateVector
    ),
    {
      name: 'xml-element-attributes-after-known-element',
      updateV1: toBase64(partialXmlAttributesUpdate),
      updateV2: toBase64(Y.encodeStateAsUpdateV2(partialXmlAttributesDoc)),
      targetStateVectorV1: toBase64(partialXmlAttributesStateVector),
      expectedDiffV1: toBase64(partialXmlAttributesDiff),
      expectedDiffV2: toBase64(partialXmlAttributesDiffV2),
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialXmlAttributesDiff).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialXmlAttributesDiff).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialXmlAttributesDiffV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialXmlAttributesDiffV2).ds)
      }
    },
    {
      name: 'xml-element-children-after-known-element',
      updateV1: toBase64(partialXmlChildrenUpdate),
      updateV2: toBase64(Y.encodeStateAsUpdateV2(partialXmlChildrenDoc)),
      targetStateVectorV1: toBase64(partialXmlChildrenStateVector),
      expectedDiffV1: toBase64(partialXmlChildrenDiff),
      expectedDiffV2: toBase64(partialXmlChildrenDiffV2),
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialXmlChildrenDiff).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialXmlChildrenDiff).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialXmlChildrenDiffV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialXmlChildrenDiffV2).ds)
      }
    },
    {
      name: 'nested-xml-fragment-children-after-known-fragment',
      type: 'array',
      updateV1: toBase64(partialNestedXmlFragmentUpdate),
      updateV2: toBase64(Y.encodeStateAsUpdateV2(partialNestedXmlFragmentDoc)),
      prefixUpdateV1: toBase64(partialNestedXmlFragmentPrefixUpdate),
      prefixUpdateV2: toBase64(partialNestedXmlFragmentPrefixUpdateV2),
      targetStateVectorV1: toBase64(partialNestedXmlFragmentStateVector),
      expectedDiffV1: toBase64(partialNestedXmlFragmentDiff),
      expectedDiffV2: toBase64(partialNestedXmlFragmentDiffV2),
      json: normalizeValue(partialNestedXmlFragmentDoc.toJSON()),
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialNestedXmlFragmentDiff).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialNestedXmlFragmentDiff).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialNestedXmlFragmentDiffV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialNestedXmlFragmentDiffV2).ds)
      }
    },
    {
      name: 'map-xml-fragment-children-after-known-fragment',
      type: 'map',
      updateV1: toBase64(partialMapXmlFragmentUpdate),
      updateV2: toBase64(Y.encodeStateAsUpdateV2(partialMapXmlFragmentDoc)),
      prefixUpdateV1: toBase64(partialMapXmlFragmentPrefixUpdate),
      prefixUpdateV2: toBase64(partialMapXmlFragmentPrefixUpdateV2),
      targetStateVectorV1: toBase64(partialMapXmlFragmentStateVector),
      expectedDiffV1: toBase64(partialMapXmlFragmentDiff),
      expectedDiffV2: toBase64(partialMapXmlFragmentDiffV2),
      json: normalizeValue(partialMapXmlFragmentDoc.toJSON()),
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialMapXmlFragmentDiff).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialMapXmlFragmentDiff).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialMapXmlFragmentDiffV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialMapXmlFragmentDiffV2).ds)
      }
    },
    {
      name: 'xml-hook-map-after-known-hook',
      type: 'xml',
      updateV1: toBase64(partialXmlHookMapUpdate),
      updateV2: toBase64(Y.encodeStateAsUpdateV2(partialXmlHookMapDoc)),
      prefixUpdateV1: toBase64(partialXmlHookMapPrefixUpdate),
      prefixUpdateV2: toBase64(partialXmlHookMapPrefixUpdateV2),
      targetStateVectorV1: toBase64(partialXmlHookMapStateVector),
      expectedDiffV1: toBase64(partialXmlHookMapDiff),
      expectedDiffV2: toBase64(partialXmlHookMapDiffV2),
      json: normalizeValue(partialXmlHookMapDoc.toJSON()),
      hookJson: normalizeValue(partialXmlHook.toJSON()),
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialXmlHookMapDiff).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialXmlHookMapDiff).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialXmlHookMapDiffV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialXmlHookMapDiffV2).ds)
      }
    },
    {
      name: 'xml-hook-xml-shared-type-children-after-known-values',
      type: 'xml',
      updateV1: toBase64(partialXmlHookXmlSharedUpdate),
      updateV2: toBase64(Y.encodeStateAsUpdateV2(partialXmlHookXmlSharedDoc)),
      prefixUpdateV1: toBase64(partialXmlHookXmlSharedPrefixUpdate),
      prefixUpdateV2: toBase64(partialXmlHookXmlSharedPrefixUpdateV2),
      targetStateVectorV1: toBase64(partialXmlHookXmlSharedStateVector),
      expectedDiffV1: toBase64(partialXmlHookXmlSharedDiff),
      expectedDiffV2: toBase64(partialXmlHookXmlSharedDiffV2),
      json: normalizeValue(partialXmlHookXmlSharedDoc.toJSON()),
      hookJson: normalizeValue(partialXmlHookXmlSharedHook.toJSON()),
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialXmlHookXmlSharedDiff).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialXmlHookXmlSharedDiff).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialXmlHookXmlSharedDiffV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialXmlHookXmlSharedDiffV2).ds)
      }
    },
    {
      name: 'xml-text-xml-shared-type-children-after-known-values',
      type: 'xml',
      updateV1: toBase64(partialXmlTextXmlSharedUpdate),
      updateV2: toBase64(Y.encodeStateAsUpdateV2(partialXmlTextXmlSharedDoc)),
      prefixUpdateV1: toBase64(partialXmlTextXmlSharedPrefixUpdate),
      prefixUpdateV2: toBase64(partialXmlTextXmlSharedPrefixUpdateV2),
      targetStateVectorV1: toBase64(partialXmlTextXmlSharedStateVector),
      expectedDiffV1: toBase64(partialXmlTextXmlSharedDiff),
      expectedDiffV2: toBase64(partialXmlTextXmlSharedDiffV2),
      json: normalizeValue(partialXmlTextXmlSharedDoc.toJSON()),
      xmlTextAttributes: normalizeValue(partialXmlTextXmlSharedText.getAttributes()),
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialXmlTextXmlSharedDiff).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialXmlTextXmlSharedDiff).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialXmlTextXmlSharedDiffV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialXmlTextXmlSharedDiffV2).ds)
      }
    },
    {
      name: 'xml-text-shared-attribute-replace-after-known-value',
      type: 'xml',
      updateV1: toBase64(partialXmlTextSharedAttributeReplaceUpdate),
      updateV2: toBase64(Y.encodeStateAsUpdateV2(partialXmlTextSharedAttributeReplaceDoc)),
      prefixUpdateV1: toBase64(partialXmlTextSharedAttributeReplacePrefixUpdate),
      prefixUpdateV2: toBase64(partialXmlTextSharedAttributeReplacePrefixUpdateV2),
      targetStateVectorV1: toBase64(partialXmlTextSharedAttributeReplaceStateVector),
      expectedDiffV1: toBase64(partialXmlTextSharedAttributeReplaceDiff),
      expectedDiffV2: toBase64(partialXmlTextSharedAttributeReplaceDiffV2),
      json: normalizeValue(partialXmlTextSharedAttributeReplaceDoc.toJSON()),
      xmlTextAttributes: normalizeValue(partialXmlTextSharedAttributeReplaceText.getAttributes()),
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialXmlTextSharedAttributeReplaceDiff).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialXmlTextSharedAttributeReplaceDiff).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialXmlTextSharedAttributeReplaceDiffV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialXmlTextSharedAttributeReplaceDiffV2).ds)
      }
    },
    {
      name: 'deleted-text-sliced-from-middle',
      updateV1: toBase64(partialDeletedTextSliceUpdate),
      updateV2: toBase64(Y.encodeStateAsUpdateV2(partialDeletedTextSliceDoc)),
      targetStateVectorV1: toBase64(partialDeletedTextSliceStateVector),
      expectedDiffV1: toBase64(partialDeletedTextSliceDiff),
      expectedDiffV2: toBase64(partialDeletedTextSliceDiffV2),
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialDeletedTextSliceDiff).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialDeletedTextSliceDiff).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialDeletedTextSliceDiffV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialDeletedTextSliceDiffV2).ds)
      }
    },
    {
      name: 'deleted-array-sliced-from-middle',
      updateV1: toBase64(partialDeletedArraySliceUpdate),
      updateV2: toBase64(Y.encodeStateAsUpdateV2(partialDeletedArraySliceDoc)),
      targetStateVectorV1: toBase64(partialDeletedArraySliceStateVector),
      expectedDiffV1: toBase64(partialDeletedArraySliceDiff),
      expectedDiffV2: toBase64(partialDeletedArraySliceDiffV2),
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialDeletedArraySliceDiff).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialDeletedArraySliceDiff).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialDeletedArraySliceDiffV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialDeletedArraySliceDiffV2).ds)
      }
    },
    {
      name: 'nested-array-map-after-known-parent-types',
      updateV1: toBase64(partialNestedArrayMapUpdate),
      updateV2: toBase64(Y.encodeStateAsUpdateV2(partialNestedArrayMapDoc)),
      targetStateVectorV1: toBase64(partialNestedArrayMapStateVector),
      expectedDiffV1: toBase64(partialNestedArrayMapDiff),
      expectedDiffV2: toBase64(partialNestedArrayMapDiffV2),
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialNestedArrayMapDiff).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialNestedArrayMapDiff).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialNestedArrayMapDiffV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialNestedArrayMapDiffV2).ds)
      }
    },
    {
      name: 'conflict-cascade-delete-set-only',
      updateV1: toBase64(partialConflictCascadeUpdate),
      updateV2: toBase64(partialConflictCascadeUpdateV2),
      targetStateVectorV1: toBase64(partialConflictCascadeStateVector),
      expectedDiffV1: toBase64(partialConflictCascadeDiff),
      expectedDiffV2: toBase64(partialConflictCascadeDiffV2),
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialConflictCascadeDiff).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialConflictCascadeDiff).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialConflictCascadeDiffV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialConflictCascadeDiffV2).ds)
      }
    },
    {
      name: 'array-text-conflict-cascade-delete-set-only',
      updateV1: toBase64(partialArrayTextConflictCascadeUpdate),
      updateV2: toBase64(partialArrayTextConflictCascadeUpdateV2),
      targetStateVectorV1: toBase64(partialArrayTextConflictCascadeStateVector),
      expectedDiffV1: toBase64(partialArrayTextConflictCascadeDiff),
      expectedDiffV2: toBase64(partialArrayTextConflictCascadeDiffV2),
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialArrayTextConflictCascadeDiff).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialArrayTextConflictCascadeDiff).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialArrayTextConflictCascadeDiffV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialArrayTextConflictCascadeDiffV2).ds)
      }
    },
    {
      name: 'array-array-conflict-cascade-delete-set-only',
      updateV1: toBase64(partialArrayArrayConflictCascadeUpdate),
      updateV2: toBase64(partialArrayArrayConflictCascadeUpdateV2),
      targetStateVectorV1: toBase64(partialArrayArrayConflictCascadeStateVector),
      expectedDiffV1: toBase64(partialArrayArrayConflictCascadeDiff),
      expectedDiffV2: toBase64(partialArrayArrayConflictCascadeDiffV2),
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialArrayArrayConflictCascadeDiff).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialArrayArrayConflictCascadeDiff).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialArrayArrayConflictCascadeDiffV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialArrayArrayConflictCascadeDiffV2).ds)
      }
    },
    {
      name: 'array-xml-conflict-cascade-delete-set-only',
      updateV1: toBase64(partialArrayXmlConflictCascadeUpdate),
      updateV2: toBase64(partialArrayXmlConflictCascadeUpdateV2),
      targetStateVectorV1: toBase64(partialArrayXmlConflictCascadeStateVector),
      expectedDiffV1: toBase64(partialArrayXmlConflictCascadeDiff),
      expectedDiffV2: toBase64(partialArrayXmlConflictCascadeDiffV2),
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialArrayXmlConflictCascadeDiff).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialArrayXmlConflictCascadeDiff).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialArrayXmlConflictCascadeDiffV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialArrayXmlConflictCascadeDiffV2).ds)
      }
    },
    partialPrefixDiffFixtureCase(
      'text-three-way-conflict-after-known-base-and-first',
      'text',
      partialTextThreeWayConflictDoc,
      partialTextThreeWayStateVector,
      partialTextThreeWayKnownDoc
    ),
    partialPrefixDiffFixtureCase(
      'array-three-way-conflict-after-known-base-and-first',
      'array',
      partialArrayThreeWayConflictDoc,
      partialArrayThreeWayStateVector,
      partialArrayThreeWayKnownDoc
    ),
    partialPrefixDiffFixtureCase(
      'xml-three-way-conflict-after-known-base-and-first',
      'xml',
      partialXmlConflictDoc,
      partialXmlConflictStateVector,
      partialXmlConflictKnownDoc
    ),
    {
      ...partialPrefixDiffFixtureCase(
        'xml-text-deleted-origin-conflict-after-known-base-and-first',
        'xml',
        partialXmlTextDeletedOriginConflictDoc,
        partialXmlTextDeletedOriginConflictStateVector,
        partialXmlTextDeletedOriginConflictKnownDoc
      ),
      xmlTextDelta: normalizeValue(partialXmlTextDeletedOriginConflictDoc.getXmlFragment('xml').get(0).get(0).toDelta())
    },
    {
      name: 'multi-client-partial-state-vector',
      updateV1: toBase64(partialMultiClientUpdate),
      updateV2: toBase64(Y.encodeStateAsUpdateV2(partialMultiClientDiffDoc)),
      targetStateVectorV1: toBase64(partialMultiClientStateVector),
      expectedDiffV1: toBase64(partialMultiClientDiff),
      expectedDiffV2: toBase64(partialMultiClientDiffV2),
      expectedDecodedDiff: {
        structs: Y.decodeUpdate(partialMultiClientDiff).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(partialMultiClientDiff).ds)
      },
      expectedDecodedDiffV2: {
        structs: Y.decodeUpdateV2(partialMultiClientDiffV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(partialMultiClientDiffV2).ds)
      }
    },
    partialDiffFixtureCase('array-delete-reinsert-after-known-full-struct', partialReinsertArrayKnownFullDoc, partialReinsertArrayKnownFullStateVector),
    partialDiffFixtureCase('array-delete-reinsert-after-known-prefix-slice', partialReinsertArrayKnownPrefixDoc, partialReinsertArrayKnownPrefixStateVector),
    partialDiffFixtureCase('text-delete-reinsert-after-known-full-struct', partialReinsertTextKnownFullDoc, partialReinsertTextKnownFullStateVector),
    partialDiffFixtureCase('xml-children-delete-reinsert-after-known-full-struct', partialReinsertXmlChildrenKnownFullDoc, partialReinsertXmlChildrenKnownFullStateVector)
  ]
}

fs.writeFileSync(
  path.join(outDir, 'partial-diffs-v1.json'),
  `${JSON.stringify(partialDiffFixtures, null, 2)}\n`
)

const textDeltaCase = (name, clientID, build) => {
  const doc = new Y.Doc({ guid: `${name}-doc`, gc: false })
  doc.clientID = clientID
  const text = doc.getText('content')
  build(text)

  return {
    name,
    updateV1: toBase64(Y.encodeStateAsUpdate(doc)),
    updateV2: toBase64(Y.encodeStateAsUpdateV2(doc)),
    json: doc.toJSON(),
    delta: text.toDelta()
  }
}

const textDeltaFixtures = {
  cases: [
    textDeltaCase('insert-attribute-delta', 140, text => {
      text.insert(0, 'Hi', { bold: true })
    }),
    textDeltaCase('format-range-delta', 141, text => {
      text.insert(0, 'Hi there')
      text.format(3, 5, { italic: true })
    }),
    textDeltaCase('overlapping-format-delete-delta', 142, text => {
      text.insert(0, 'Hello world')
      text.format(0, 5, { bold: true })
      text.format(3, 5, { italic: true })
      text.delete(5, 1)
    }),
    textDeltaCase('embed-delta', 143, text => {
      text.insert(0, 'A')
      text.insertEmbed(1, { image: 'cat.png' }, { alt: 'Cat' })
      text.insert(2, 'B')
    })
  ]
}

fs.writeFileSync(
  path.join(outDir, 'text-deltas.json'),
  `${JSON.stringify(textDeltaFixtures, null, 2)}\n`
)

const applyDeltaCase = (name, clientID, target, delta, options = null, seed = null, seedDelta = null) => {
  const doc = new Y.Doc({ guid: `${name}-doc`, gc: false })
  doc.clientID = clientID
  let text

  if (target === 'root') {
    text = doc.getText('content')
  } else if (target === 'nested') {
    text = new Y.Text()
    doc.getArray('items').insert(0, [text])
  } else if (target === 'map') {
    text = new Y.Text()
    doc.getMap('map').set('body', text)
  } else if (target === 'xml') {
    text = new Y.XmlText()
    doc.getXmlFragment('xml').insert(0, [text])
  } else {
    throw new Error(`Unknown applyDelta target: ${target}`)
  }

  if (seed !== null) {
    text.insert(0, seed)
  }
  if (seedDelta !== null) {
    text.applyDelta(seedDelta)
  }

  if (options === null) {
    text.applyDelta(delta)
  } else {
    text.applyDelta(delta, options)
  }

  return {
    name,
    target,
    seed,
    seedDelta: normalizeValue(seedDelta),
    delta: normalizeValue(delta),
    options,
    expectedString: text.toString(),
    expectedDelta: normalizeValue(text.toDelta()),
    expectedJson: normalizeValue(doc.toJSON())
  }
}

const applyDeltaFixtures = {
  cases: [
    applyDeltaCase('root-default-keeps-trailing-newline', 144, 'root', [{ insert: 'Hello\n' }]),
    applyDeltaCase('root-sanitize-true-keeps-trailing-newline', 145, 'root', [{ insert: 'Hello\n' }], { sanitize: true }),
    applyDeltaCase('root-sanitize-false-strips-trailing-newline', 146, 'root', [{ insert: 'Hello\n' }], { sanitize: false }),
    applyDeltaCase('root-sanitize-false-empty-newline', 147, 'root', [{ insert: '\n' }], { sanitize: false }),
    applyDeltaCase('root-sanitize-false-keeps-mid-text-newline', 148, 'root', [{ retain: 3 }, { insert: 'X\n' }], { sanitize: false }, 'Existing'),
    applyDeltaCase('root-sanitize-false-after-delete-strips-at-end', 149, 'root', [{ delete: 2 }, { insert: 'C\n' }], { sanitize: false }, 'AB'),
    applyDeltaCase('nested-sanitize-false-strips-trailing-newline', 150, 'nested', [{ insert: 'Nested\n' }], { sanitize: false }),
    applyDeltaCase('xml-sanitize-false-strips-trailing-newline', 151, 'xml', [{ insert: 'Xml\n' }], { sanitize: false }),
    applyDeltaCase('root-format-retain', 152, 'root', [{ retain: 1, attributes: { bold: true } }], null, 'Hi'),
    applyDeltaCase('root-insert-embed', 153, 'root', [
      { insert: 'A' },
      { insert: { image: 'cat.png' }, attributes: { alt: 'Cat' } },
      { insert: 'B' }
    ]),
    applyDeltaCase('nested-utf16-format-retain', 154, 'nested', [{ retain: 3, attributes: { bold: true } }], null, 'A😀B'),
    applyDeltaCase('nested-insert-embed', 159, 'nested', [
      { insert: 'A' },
      { insert: { mention: 'Grace' }, attributes: { role: 'reviewer' } },
      { insert: 'B' }
    ]),
    applyDeltaCase('xml-format-retain', 155, 'xml', [{ retain: 2, attributes: { bold: true } }], null, 'Xml'),
    applyDeltaCase('xml-insert-embed', 160, 'xml', [
      { insert: 'A' },
      { insert: { mention: 'Lin' }, attributes: { role: 'editor' } },
      { insert: 'B' }
    ]),
    applyDeltaCase('root-insert-clears-unmentioned-active-format', 161, 'root', [
      { retain: 5 },
      { insert: '!', attributes: { bold: true } }
    ], null, null, [
      { insert: 'Hello', attributes: { bold: true, italic: true } }
    ]),
    applyDeltaCase('xml-insert-clears-unmentioned-active-format', 162, 'xml', [
      { retain: 3 },
      { insert: '!', attributes: { bold: true } }
    ], null, null, [
      { insert: 'Xml', attributes: { bold: true, italic: true } }
    ]),
    applyDeltaCase('nested-embed-clears-unmentioned-active-format', 163, 'nested', [
      { retain: 4 },
      { insert: { mention: 'Ada' }, attributes: { bold: true } }
    ], null, null, [
      { insert: 'Seed', attributes: { bold: true, italic: true } }
    ]),
    applyDeltaCase('map-format-delete-insert', 156, 'map', [
      { retain: 3, attributes: { bold: true } },
      { delete: 1 },
      { insert: ' body', attributes: { italic: true } }
    ], null, 'MapText'),
    applyDeltaCase('map-sanitize-false-strips-trailing-newline', 157, 'map', [{ insert: 'Map\n' }], { sanitize: false }),
    applyDeltaCase('map-insert-embed', 158, 'map', [
      { insert: 'A' },
      { insert: { mention: 'Ada' }, attributes: { role: 'author' } },
      { insert: 'B' }
    ])
  ]
}

fs.writeFileSync(
  path.join(outDir, 'apply-delta.json'),
  `${JSON.stringify(applyDeltaFixtures, null, 2)}\n`
)

const textAttributesDoc = new Y.Doc({ guid: 'text-attributes-doc', gc: false })
textAttributesDoc.clientID = 180
const textAttributesText = textAttributesDoc.getText('content')
textAttributesText.insert(0, 'Text')
textAttributesText.setAttribute('lang', 'en')
textAttributesText.setAttribute('temporary', true)
textAttributesText.setAttribute('mark', { color: 'green' })
textAttributesText.removeAttribute('temporary')

const textAttributesOnlyDoc = new Y.Doc({ guid: 'text-attributes-only-doc', gc: false })
textAttributesOnlyDoc.clientID = 181
const textAttributesOnlyText = textAttributesOnlyDoc.getText('content')
textAttributesOnlyText.setAttribute('lang', 'en')

const textAttributesFixtures = {
  withContent: {
    updateV1: toBase64(Y.encodeStateAsUpdate(textAttributesDoc)),
    updateV2: toBase64(Y.encodeStateAsUpdateV2(textAttributesDoc)),
    json: normalizeValue(textAttributesDoc.toJSON()),
    text: textAttributesText.toString(),
    delta: normalizeValue(textAttributesText.toDelta()),
    attributes: normalizeValue(textAttributesText.getAttributes()),
    lang: textAttributesText.getAttribute('lang'),
    hasTemporary: textAttributesText.getAttribute('temporary') !== undefined
  },
  attributeOnly: {
    updateV1: toBase64(Y.encodeStateAsUpdate(textAttributesOnlyDoc)),
    updateV2: toBase64(Y.encodeStateAsUpdateV2(textAttributesOnlyDoc)),
    jsonAfterGetText: normalizeValue(textAttributesOnlyDoc.toJSON()),
    text: textAttributesOnlyText.toString(),
    delta: normalizeValue(textAttributesOnlyText.toDelta()),
    attributes: normalizeValue(textAttributesOnlyText.getAttributes())
  }
}

fs.writeFileSync(
  path.join(outDir, 'text-attributes.json'),
  `${JSON.stringify(textAttributesFixtures, null, 2)}\n`
)

const xmlNavigationDoc = new Y.Doc({ guid: 'xml-navigation-doc', gc: false })
xmlNavigationDoc.clientID = 152
const xmlNavigation = xmlNavigationDoc.getXmlFragment('xml')
const xmlNavigationIntro = new Y.XmlText()
xmlNavigationIntro.insert(0, 'intro')
const xmlNavigationArticle = new Y.XmlElement('article')
xmlNavigationArticle.setAttribute('class', 'lead')
const xmlNavigationParagraph = new Y.XmlElement('p')
const xmlNavigationParagraphText = new Y.XmlText()
xmlNavigationParagraphText.insert(0, 'Hello')
xmlNavigationParagraph.insert(0, [xmlNavigationParagraphText])
const xmlNavigationStrong = new Y.XmlElement('strong')
const xmlNavigationStrongText = new Y.XmlText()
xmlNavigationStrongText.insert(0, 'B')
xmlNavigationStrong.insert(0, [xmlNavigationStrongText])
xmlNavigationArticle.insert(0, [xmlNavigationParagraph, xmlNavigationStrong])
const xmlNavigationAside = new Y.XmlElement('aside')
const xmlNavigationHook = new Y.XmlHook('mention')
xmlNavigation.insert(0, [xmlNavigationIntro, xmlNavigationArticle, xmlNavigationAside, xmlNavigationHook])

const xmlNavigationFixtures = {
  updateV1: toBase64(Y.encodeStateAsUpdate(xmlNavigationDoc)),
  updateV2: toBase64(Y.encodeStateAsUpdateV2(xmlNavigationDoc)),
  json: normalizeValue(xmlNavigationDoc.toJSON()),
  firstChild: xmlNodeSummary(xmlNavigation.firstChild),
  sliceFromOne: xmlNavigation.slice(1).map(xmlNodeSummary),
  sliceNegativeOne: xmlNavigation.slice(-1).map(xmlNodeSummary),
  queryArticle: xmlNodeSummary(xmlNavigation.querySelector('article')),
  queryUppercaseArticle: xmlNodeSummary(xmlNavigation.querySelector('ARTICLE')),
  queryStrong: xmlNodeSummary(xmlNavigation.querySelector('strong')),
  queryMissing: xmlNavigation.querySelector('missing'),
  queryAllStrong: xmlNavigation.querySelectorAll('strong').map(xmlNodeSummary),
  queryAllUppercaseStrong: xmlNavigation.querySelectorAll('STRONG').map(xmlNodeSummary),
  treeWalker: Array.from(xmlNavigation.createTreeWalker()).map(xmlNodeSummary),
  treeWalkerElements: Array.from(xmlNavigation.createTreeWalker(node => node instanceof Y.XmlElement)).map(xmlNodeSummary),
  fragmentJson: xmlNavigation.toJSON(),
  articleFirstChild: xmlNodeSummary(xmlNavigationArticle.firstChild),
  articleSlice: xmlNavigationArticle.slice(0).map(xmlNodeSummary),
  articleQuerySelf: xmlNavigationArticle.querySelector('article'),
  articleQueryStrong: xmlNodeSummary(xmlNavigationArticle.querySelector('strong')),
  articleQueryUppercaseStrong: xmlNodeSummary(xmlNavigationArticle.querySelector('STRONG')),
  articleQueryAllStrong: xmlNavigationArticle.querySelectorAll('strong').map(xmlNodeSummary),
  articleQueryAllUppercaseStrong: xmlNavigationArticle.querySelectorAll('STRONG').map(xmlNodeSummary),
  articleTreeWalker: Array.from(xmlNavigationArticle.createTreeWalker()).map(xmlNodeSummary),
  articleTreeWalkerElements: Array.from(xmlNavigationArticle.createTreeWalker(node => node instanceof Y.XmlElement)).map(xmlNodeSummary),
  hook: xmlNodeSummary(xmlNavigationHook)
}

fs.writeFileSync(
  path.join(outDir, 'xml-navigation.json'),
  `${JSON.stringify(xmlNavigationFixtures, null, 2)}\n`
)

const xmlInsertAfterDoc = new Y.Doc({ guid: 'xml-insert-after-doc', gc: false })
xmlInsertAfterDoc.clientID = 178
const xmlInsertAfter = xmlInsertAfterDoc.getXmlFragment('xml')
const xmlInsertAfterLead = new Y.XmlText()
xmlInsertAfterLead.insert(0, 'lead')
xmlInsertAfter.insertAfter(null, [xmlInsertAfterLead])
const xmlInsertAfterArticle = new Y.XmlElement('article')
xmlInsertAfter.insertAfter(xmlInsertAfterLead, [xmlInsertAfterArticle])
const xmlInsertAfterTail = new Y.XmlText()
xmlInsertAfterTail.insert(0, 'tail')
xmlInsertAfter.insertAfter(xmlInsertAfterArticle, [xmlInsertAfterTail])
const xmlInsertAfterMention = new Y.XmlHook('mention')
xmlInsertAfter.insertAfter(xmlInsertAfterTail, [xmlInsertAfterMention])
const xmlInsertAfterStrong = new Y.XmlElement('strong')
xmlInsertAfterArticle.insertAfter(null, [xmlInsertAfterStrong])
const xmlInsertAfterStrongText = new Y.XmlText()
xmlInsertAfterStrongText.insert(0, 'B')
xmlInsertAfterStrong.insertAfter(null, [xmlInsertAfterStrongText])
const xmlInsertAfterMiddle = new Y.XmlText()
xmlInsertAfterMiddle.insert(0, 'middle')
xmlInsertAfterArticle.insertAfter(xmlInsertAfterStrong, [xmlInsertAfterMiddle])
const xmlInsertAfterNote = new Y.XmlHook('note')
xmlInsertAfterArticle.insertAfter(xmlInsertAfterMiddle, [xmlInsertAfterNote])
const xmlInsertAfterEnd = new Y.XmlText()
xmlInsertAfterEnd.insert(0, 'end')
xmlInsertAfterArticle.insertAfter(xmlInsertAfterNote, [xmlInsertAfterEnd])

const xmlInsertAfterFixtures = {
  updateV1: toBase64(Y.encodeStateAsUpdate(xmlInsertAfterDoc)),
  updateV2: toBase64(Y.encodeStateAsUpdateV2(xmlInsertAfterDoc)),
  json: normalizeValue(xmlInsertAfterDoc.toJSON()),
  fragmentJson: xmlInsertAfter.toJSON(),
  fragmentChildren: xmlInsertAfter.slice(0).map(xmlNodeSummary),
  articleChildren: xmlInsertAfterArticle.slice(0).map(xmlNodeSummary),
  treeWalker: Array.from(xmlInsertAfter.createTreeWalker()).map(xmlNodeSummary),
  articleTreeWalker: Array.from(xmlInsertAfterArticle.createTreeWalker()).map(xmlNodeSummary)
}

fs.writeFileSync(
  path.join(outDir, 'xml-insert-after.json'),
  `${JSON.stringify(xmlInsertAfterFixtures, null, 2)}\n`
)

const xmlTextAttributesDoc = new Y.Doc({ guid: 'xml-text-attributes-doc', gc: false })
xmlTextAttributesDoc.clientID = 179
const xmlTextAttributesFragment = xmlTextAttributesDoc.getXmlFragment('xml')
const xmlTextAttributesText = new Y.XmlText()
xmlTextAttributesText.insert(0, 'Xml')
xmlTextAttributesText.setAttribute('lang', 'en')
xmlTextAttributesText.setAttribute('temporary', true)
xmlTextAttributesText.setAttribute('mark', { color: 'blue' })
xmlTextAttributesText.removeAttribute('temporary')
xmlTextAttributesFragment.insert(0, [xmlTextAttributesText])

const xmlTextAttributesFixtures = {
  updateV1: toBase64(Y.encodeStateAsUpdate(xmlTextAttributesDoc)),
  updateV2: toBase64(Y.encodeStateAsUpdateV2(xmlTextAttributesDoc)),
  json: normalizeValue(xmlTextAttributesDoc.toJSON()),
  text: xmlTextAttributesText.toString(),
  delta: normalizeValue(xmlTextAttributesText.toDelta()),
  attributes: normalizeValue(xmlTextAttributesText.getAttributes()),
  lang: xmlTextAttributesText.getAttribute('lang'),
  hasTemporary: xmlTextAttributesText.getAttribute('temporary') !== undefined
}

fs.writeFileSync(
  path.join(outDir, 'xml-text-attributes.json'),
  `${JSON.stringify(xmlTextAttributesFixtures, null, 2)}\n`
)

const updateUtilsDoc = new Y.Doc({ guid: 'update-utils-doc', gc: false })
updateUtilsDoc.clientID = 250
const updateUtilsUpdatesV1 = []
const updateUtilsUpdatesV2 = []
updateUtilsDoc.on('update', update => updateUtilsUpdatesV1.push(update))
updateUtilsDoc.on('updateV2', update => updateUtilsUpdatesV2.push(update))
const updateUtilsText = updateUtilsDoc.getText('content')
updateUtilsText.insert(0, 'A')
updateUtilsText.insert(1, 'BC')
updateUtilsText.delete(1, 1)
updateUtilsDoc.getMap('map').set('title', 'Hi')
const updateUtilsMergeInputV1 = [
  updateUtilsUpdatesV1[0],
  updateUtilsUpdatesV1[0],
  updateUtilsUpdatesV1[1],
  updateUtilsUpdatesV1[2],
  updateUtilsUpdatesV1[3]
]
const updateUtilsMergeInputV2 = [
  updateUtilsUpdatesV2[0],
  updateUtilsUpdatesV2[0],
  updateUtilsUpdatesV2[1],
  updateUtilsUpdatesV2[2],
  updateUtilsUpdatesV2[3]
]
const updateUtilsMergedV1 = Y.mergeUpdates(updateUtilsMergeInputV1)
const updateUtilsMergedV2 = Y.mergeUpdatesV2(updateUtilsMergeInputV2)
const updateUtilsTargetStateVector = Y.encodeStateVectorFromUpdate(updateUtilsUpdatesV1[0])
const updateUtilsDiffV1 = Y.diffUpdate(updateUtilsMergedV1, updateUtilsTargetStateVector)
const updateUtilsDiffV2 = Y.diffUpdateV2(updateUtilsMergedV2, updateUtilsTargetStateVector)
const updateUtilsConvertedV2 = Y.convertUpdateFormatV1ToV2(updateUtilsMergedV1)
const updateUtilsConvertedV1 = Y.convertUpdateFormatV2ToV1(updateUtilsMergedV2)
const updateUtilsEmptyMergedV1 = Y.mergeUpdates([])
const updateUtilsEmptyMergedV2 = Y.mergeUpdatesV2([])
const updateUtilsEmptyStateVector = stateVector([])
const updateUtilsEmptyDiffV1 = Y.diffUpdate(updateUtilsEmptyMergedV1, updateUtilsEmptyStateVector)
const updateUtilsEmptyDiffV2 = Y.diffUpdateV2(updateUtilsEmptyMergedV2, updateUtilsEmptyStateVector)

const deepMapUpdateUtilsDoc = new Y.Doc({ guid: 'deep-map-update-utils-doc', gc: false })
deepMapUpdateUtilsDoc.clientID = 266
const deepMapUpdateUtilsUpdatesV1 = []
const deepMapUpdateUtilsUpdatesV2 = []
deepMapUpdateUtilsDoc.on('update', update => deepMapUpdateUtilsUpdatesV1.push(update))
deepMapUpdateUtilsDoc.on('updateV2', update => deepMapUpdateUtilsUpdatesV2.push(update))
const deepMapUpdateUtilsMap = deepMapUpdateUtilsDoc.getMap('map')
const deepMapUpdateUtilsRoot = new Y.Map()
deepMapUpdateUtilsMap.set('root', deepMapUpdateUtilsRoot)
deepMapUpdateUtilsRoot.set('title', 'Base')
const deepMapUpdateUtilsItems = new Y.Array()
deepMapUpdateUtilsRoot.set('items', deepMapUpdateUtilsItems)
deepMapUpdateUtilsItems.insert(0, ['A', 'C'])
deepMapUpdateUtilsItems.insert(1, ['B'])
const deepMapUpdateUtilsBody = new Y.Text()
deepMapUpdateUtilsRoot.set('body', deepMapUpdateUtilsBody)
deepMapUpdateUtilsBody.insert(0, 'Deep text!')
deepMapUpdateUtilsBody.format(0, 4, { bold: true })
const deepMapUpdateUtilsMeta = new Y.Map()
deepMapUpdateUtilsRoot.set('meta', deepMapUpdateUtilsMeta)
deepMapUpdateUtilsMeta.set('count', 3)
deepMapUpdateUtilsMeta.set('status', 'ok')
deepMapUpdateUtilsRoot.set('bytes', Uint8Array.from([7, 8, 255]))
const deepMapUpdateUtilsXml = new Y.XmlFragment()
deepMapUpdateUtilsRoot.set('xml', deepMapUpdateUtilsXml)
const deepMapUpdateUtilsXmlIntro = new Y.XmlText()
deepMapUpdateUtilsXmlIntro.insert(0, 'Intro')
const deepMapUpdateUtilsXmlParagraph = new Y.XmlElement('p')
deepMapUpdateUtilsXmlParagraph.setAttribute('class', 'body')
const deepMapUpdateUtilsXmlParagraphText = new Y.XmlText()
deepMapUpdateUtilsXmlParagraphText.insert(0, 'Body')
deepMapUpdateUtilsXmlParagraph.insert(0, [deepMapUpdateUtilsXmlParagraphText])
deepMapUpdateUtilsXml.insert(0, [deepMapUpdateUtilsXmlIntro, deepMapUpdateUtilsXmlParagraph])
const deepMapUpdateUtilsMergeInputV1 = [
  deepMapUpdateUtilsUpdatesV1[0],
  deepMapUpdateUtilsUpdatesV1[0],
  ...deepMapUpdateUtilsUpdatesV1.slice(1)
]
const deepMapUpdateUtilsMergeInputV2 = [
  deepMapUpdateUtilsUpdatesV2[0],
  deepMapUpdateUtilsUpdatesV2[0],
  ...deepMapUpdateUtilsUpdatesV2.slice(1)
]
const deepMapUpdateUtilsMergedV1 = Y.mergeUpdates(deepMapUpdateUtilsMergeInputV1)
const deepMapUpdateUtilsMergedV2 = Y.mergeUpdatesV2(deepMapUpdateUtilsMergeInputV2)
const deepMapUpdateUtilsPrefixV1 = Y.mergeUpdates(deepMapUpdateUtilsUpdatesV1.slice(0, 2))
const deepMapUpdateUtilsPrefixV2 = Y.mergeUpdatesV2(deepMapUpdateUtilsUpdatesV2.slice(0, 2))
const deepMapUpdateUtilsTargetStateVector = Y.encodeStateVectorFromUpdate(deepMapUpdateUtilsPrefixV1)
const deepMapUpdateUtilsDiffV1 = Y.diffUpdate(deepMapUpdateUtilsMergedV1, deepMapUpdateUtilsTargetStateVector)
const deepMapUpdateUtilsDiffV2 = Y.diffUpdateV2(deepMapUpdateUtilsMergedV2, deepMapUpdateUtilsTargetStateVector)
const deepMapUpdateUtilsConvertedV2 = Y.convertUpdateFormatV1ToV2(deepMapUpdateUtilsMergedV1)
const deepMapUpdateUtilsConvertedV1 = Y.convertUpdateFormatV2ToV1(deepMapUpdateUtilsMergedV2)
const deepMapUpdateUtilsTextStruct = Y.decodeUpdate(deepMapUpdateUtilsMergedV1).structs.find(struct =>
  struct.constructor.name === 'Item' &&
  struct.content.constructor.name === 'ContentString' &&
  struct.content.str === 'Deep text!'
)
if (deepMapUpdateUtilsTextStruct === undefined) {
  throw new Error('Expected deep map update-utils text struct fixture')
}
const deepMapUpdateUtilsPartialTextStateVector = stateVector([[266, deepMapUpdateUtilsTextStruct.id.clock + 4]])
const deepMapUpdateUtilsPartialTextDiffV1 = Y.diffUpdate(deepMapUpdateUtilsMergedV1, deepMapUpdateUtilsPartialTextStateVector)
const deepMapUpdateUtilsPartialTextDiffV2 = Y.diffUpdateV2(deepMapUpdateUtilsMergedV2, deepMapUpdateUtilsPartialTextStateVector)

const xmlHookUpdateUtilsDoc = new Y.Doc({ guid: 'xml-hook-update-utils-doc', gc: false })
xmlHookUpdateUtilsDoc.clientID = 267
const xmlHookUpdateUtilsUpdatesV1 = []
const xmlHookUpdateUtilsUpdatesV2 = []
xmlHookUpdateUtilsDoc.on('update', update => xmlHookUpdateUtilsUpdatesV1.push(update))
xmlHookUpdateUtilsDoc.on('updateV2', update => xmlHookUpdateUtilsUpdatesV2.push(update))
const xmlHookUpdateUtilsHook = new Y.XmlHook('mention')
xmlHookUpdateUtilsDoc.getXmlFragment('xml').insert(0, [xmlHookUpdateUtilsHook])
const xmlHookUpdateUtilsBody = new Y.Text()
const xmlHookUpdateUtilsItems = new Y.Array()
const xmlHookUpdateUtilsMeta = new Y.Map()
const xmlHookUpdateUtilsElement = new Y.XmlElement('p')
const xmlHookUpdateUtilsFragment = new Y.XmlFragment()
xmlHookUpdateUtilsDoc.transact(() => {
  xmlHookUpdateUtilsHook.set('known', 'before')
  xmlHookUpdateUtilsHook.set('body', xmlHookUpdateUtilsBody)
  xmlHookUpdateUtilsHook.set('items', xmlHookUpdateUtilsItems)
  xmlHookUpdateUtilsHook.set('meta', xmlHookUpdateUtilsMeta)
  xmlHookUpdateUtilsHook.set('element', xmlHookUpdateUtilsElement)
  xmlHookUpdateUtilsHook.set('fragment', xmlHookUpdateUtilsFragment)
})
xmlHookUpdateUtilsDoc.transact(() => {
  xmlHookUpdateUtilsBody.insert(0, 'Hook text')
  xmlHookUpdateUtilsItems.insert(0, ['A', 'B'])
  xmlHookUpdateUtilsMeta.set('role', 'author')
  xmlHookUpdateUtilsElement.setAttribute('class', 'lead')
  const elementText = new Y.XmlText()
  xmlHookUpdateUtilsElement.insert(0, [elementText])
  elementText.insert(0, 'Element')
  const fragmentText = new Y.XmlText()
  const fragmentStrong = new Y.XmlElement('strong')
  const fragmentStrongText = new Y.XmlText()
  fragmentStrong.insert(0, [fragmentStrongText])
  fragmentStrongText.insert(0, 'B')
  fragmentText.insert(0, 'A')
  xmlHookUpdateUtilsFragment.insert(0, [fragmentText, fragmentStrong])
})
const xmlHookUpdateUtilsMergeInputV1 = [
  xmlHookUpdateUtilsUpdatesV1[0],
  xmlHookUpdateUtilsUpdatesV1[0],
  ...xmlHookUpdateUtilsUpdatesV1.slice(1)
]
const xmlHookUpdateUtilsMergeInputV2 = [
  xmlHookUpdateUtilsUpdatesV2[0],
  xmlHookUpdateUtilsUpdatesV2[0],
  ...xmlHookUpdateUtilsUpdatesV2.slice(1)
]
const xmlHookUpdateUtilsMergedV1 = Y.mergeUpdates(xmlHookUpdateUtilsMergeInputV1)
const xmlHookUpdateUtilsMergedV2 = Y.mergeUpdatesV2(xmlHookUpdateUtilsMergeInputV2)
const xmlHookUpdateUtilsPrefixV1 = Y.mergeUpdates(xmlHookUpdateUtilsUpdatesV1.slice(0, 2))
const xmlHookUpdateUtilsPrefixV2 = Y.mergeUpdatesV2(xmlHookUpdateUtilsUpdatesV2.slice(0, 2))
const xmlHookUpdateUtilsTargetStateVector = Y.encodeStateVectorFromUpdate(xmlHookUpdateUtilsPrefixV1)
const xmlHookUpdateUtilsDiffV1 = Y.diffUpdate(xmlHookUpdateUtilsMergedV1, xmlHookUpdateUtilsTargetStateVector)
const xmlHookUpdateUtilsDiffV2 = Y.diffUpdateV2(xmlHookUpdateUtilsMergedV2, xmlHookUpdateUtilsTargetStateVector)
const xmlHookUpdateUtilsConvertedV2 = Y.convertUpdateFormatV1ToV2(xmlHookUpdateUtilsMergedV1)
const xmlHookUpdateUtilsConvertedV1 = Y.convertUpdateFormatV2ToV1(xmlHookUpdateUtilsMergedV2)

const xmlElementUpdateUtilsDoc = new Y.Doc({ guid: 'xml-element-update-utils-doc', gc: false })
xmlElementUpdateUtilsDoc.clientID = 268
const xmlElementUpdateUtilsUpdatesV1 = []
const xmlElementUpdateUtilsUpdatesV2 = []
xmlElementUpdateUtilsDoc.on('update', update => xmlElementUpdateUtilsUpdatesV1.push(update))
xmlElementUpdateUtilsDoc.on('updateV2', update => xmlElementUpdateUtilsUpdatesV2.push(update))
const xmlElementUpdateUtilsElement = new Y.XmlElement('p')
xmlElementUpdateUtilsDoc.getXmlFragment('xml').insert(0, [xmlElementUpdateUtilsElement])
const xmlElementUpdateUtilsBody = new Y.Text()
const xmlElementUpdateUtilsItems = new Y.Array()
const xmlElementUpdateUtilsMeta = new Y.Map()
const xmlElementUpdateUtilsInline = new Y.XmlElement('span')
xmlElementUpdateUtilsDoc.transact(() => {
  xmlElementUpdateUtilsElement.setAttribute('known', 'before')
  xmlElementUpdateUtilsElement.setAttribute('body', xmlElementUpdateUtilsBody)
  xmlElementUpdateUtilsElement.setAttribute('items', xmlElementUpdateUtilsItems)
  xmlElementUpdateUtilsElement.setAttribute('meta', xmlElementUpdateUtilsMeta)
  xmlElementUpdateUtilsElement.setAttribute('inline', xmlElementUpdateUtilsInline)
})
xmlElementUpdateUtilsDoc.transact(() => {
  xmlElementUpdateUtilsBody.insert(0, 'Element text')
  xmlElementUpdateUtilsItems.insert(0, ['A', 'B'])
  xmlElementUpdateUtilsMeta.set('role', 'author')
  xmlElementUpdateUtilsInline.setAttribute('class', 'lead')
  const inlineText = new Y.XmlText()
  xmlElementUpdateUtilsInline.insert(0, [inlineText])
  inlineText.insert(0, 'Inline')
})
const xmlElementUpdateUtilsMergeInputV1 = [
  xmlElementUpdateUtilsUpdatesV1[0],
  xmlElementUpdateUtilsUpdatesV1[0],
  ...xmlElementUpdateUtilsUpdatesV1.slice(1)
]
const xmlElementUpdateUtilsMergeInputV2 = [
  xmlElementUpdateUtilsUpdatesV2[0],
  xmlElementUpdateUtilsUpdatesV2[0],
  ...xmlElementUpdateUtilsUpdatesV2.slice(1)
]
const xmlElementUpdateUtilsMergedV1 = Y.mergeUpdates(xmlElementUpdateUtilsMergeInputV1)
const xmlElementUpdateUtilsMergedV2 = Y.mergeUpdatesV2(xmlElementUpdateUtilsMergeInputV2)
const xmlElementUpdateUtilsPrefixV1 = Y.mergeUpdates(xmlElementUpdateUtilsUpdatesV1.slice(0, 2))
const xmlElementUpdateUtilsPrefixV2 = Y.mergeUpdatesV2(xmlElementUpdateUtilsUpdatesV2.slice(0, 2))
const xmlElementUpdateUtilsTargetStateVector = Y.encodeStateVectorFromUpdate(xmlElementUpdateUtilsPrefixV1)
const xmlElementUpdateUtilsDiffV1 = Y.diffUpdate(xmlElementUpdateUtilsMergedV1, xmlElementUpdateUtilsTargetStateVector)
const xmlElementUpdateUtilsDiffV2 = Y.diffUpdateV2(xmlElementUpdateUtilsMergedV2, xmlElementUpdateUtilsTargetStateVector)
const xmlElementUpdateUtilsConvertedV2 = Y.convertUpdateFormatV1ToV2(xmlElementUpdateUtilsMergedV1)
const xmlElementUpdateUtilsConvertedV1 = Y.convertUpdateFormatV2ToV1(xmlElementUpdateUtilsMergedV2)

const xmlTextUpdateUtilsDoc = new Y.Doc({ guid: 'xml-text-update-utils-doc', gc: false })
xmlTextUpdateUtilsDoc.clientID = 269
const xmlTextUpdateUtilsUpdatesV1 = []
const xmlTextUpdateUtilsUpdatesV2 = []
xmlTextUpdateUtilsDoc.on('update', update => xmlTextUpdateUtilsUpdatesV1.push(update))
xmlTextUpdateUtilsDoc.on('updateV2', update => xmlTextUpdateUtilsUpdatesV2.push(update))
const xmlTextUpdateUtilsText = new Y.XmlText()
xmlTextUpdateUtilsText.insert(0, 'Xml')
xmlTextUpdateUtilsDoc.getXmlFragment('xml').insert(0, [xmlTextUpdateUtilsText])
const xmlTextUpdateUtilsBody = new Y.Text()
const xmlTextUpdateUtilsItems = new Y.Array()
const xmlTextUpdateUtilsMeta = new Y.Map()
const xmlTextUpdateUtilsInline = new Y.XmlElement('span')
const xmlTextUpdateUtilsLabel = new Y.XmlText()
const xmlTextUpdateUtilsFragment = new Y.XmlFragment()
xmlTextUpdateUtilsDoc.transact(() => {
  xmlTextUpdateUtilsText.setAttribute('known', 'before')
  xmlTextUpdateUtilsText.setAttribute('body', xmlTextUpdateUtilsBody)
  xmlTextUpdateUtilsText.setAttribute('items', xmlTextUpdateUtilsItems)
  xmlTextUpdateUtilsText.setAttribute('meta', xmlTextUpdateUtilsMeta)
  xmlTextUpdateUtilsText.setAttribute('inline', xmlTextUpdateUtilsInline)
  xmlTextUpdateUtilsText.setAttribute('label', xmlTextUpdateUtilsLabel)
  xmlTextUpdateUtilsText.setAttribute('fragment', xmlTextUpdateUtilsFragment)
})
xmlTextUpdateUtilsDoc.transact(() => {
  xmlTextUpdateUtilsBody.insert(0, 'XML text body')
  xmlTextUpdateUtilsItems.insert(0, ['A', 'B'])
  xmlTextUpdateUtilsMeta.set('role', 'caption')
  xmlTextUpdateUtilsInline.setAttribute('class', 'lead')
  const inlineText = new Y.XmlText()
  xmlTextUpdateUtilsInline.insert(0, [inlineText])
  inlineText.insert(0, 'Inline')
  xmlTextUpdateUtilsLabel.insert(0, 'Label')
  const fragmentText = new Y.XmlText()
  fragmentText.insert(0, 'Frag')
  xmlTextUpdateUtilsFragment.insert(0, [fragmentText])
})
const xmlTextUpdateUtilsMergeInputV1 = [
  xmlTextUpdateUtilsUpdatesV1[0],
  xmlTextUpdateUtilsUpdatesV1[0],
  ...xmlTextUpdateUtilsUpdatesV1.slice(1)
]
const xmlTextUpdateUtilsMergeInputV2 = [
  xmlTextUpdateUtilsUpdatesV2[0],
  xmlTextUpdateUtilsUpdatesV2[0],
  ...xmlTextUpdateUtilsUpdatesV2.slice(1)
]
const xmlTextUpdateUtilsMergedV1 = Y.mergeUpdates(xmlTextUpdateUtilsMergeInputV1)
const xmlTextUpdateUtilsMergedV2 = Y.mergeUpdatesV2(xmlTextUpdateUtilsMergeInputV2)
const xmlTextUpdateUtilsPrefixV1 = Y.mergeUpdates(xmlTextUpdateUtilsUpdatesV1.slice(0, 2))
const xmlTextUpdateUtilsPrefixV2 = Y.mergeUpdatesV2(xmlTextUpdateUtilsUpdatesV2.slice(0, 2))
const xmlTextUpdateUtilsTargetStateVector = Y.encodeStateVectorFromUpdate(xmlTextUpdateUtilsPrefixV1)
const xmlTextUpdateUtilsDiffV1 = Y.diffUpdate(xmlTextUpdateUtilsMergedV1, xmlTextUpdateUtilsTargetStateVector)
const xmlTextUpdateUtilsDiffV2 = Y.diffUpdateV2(xmlTextUpdateUtilsMergedV2, xmlTextUpdateUtilsTargetStateVector)
const xmlTextUpdateUtilsConvertedV2 = Y.convertUpdateFormatV1ToV2(xmlTextUpdateUtilsMergedV1)
const xmlTextUpdateUtilsConvertedV1 = Y.convertUpdateFormatV2ToV1(xmlTextUpdateUtilsMergedV2)

const obfuscateDoc = new Y.Doc({ guid: 'obfuscate-update-doc', gc: false })
obfuscateDoc.clientID = 270
const obfuscateText = obfuscateDoc.getText('content')
obfuscateText.insert(0, 'Secret 😀 text', { bold: true, color: 'red' })
obfuscateText.insertEmbed(obfuscateText.length, { image: 'secret.png' }, { alt: 'Secret' })
obfuscateDoc.getMap('map').set('title', 'Private')
obfuscateDoc.getMap('map').set('count', 42)
obfuscateDoc.getArray('array').insert(0, ['alpha', { nested: true }, Uint8Array.from([1, 2, 255])])
obfuscateDoc.getArray('array').insert(3, [new Y.Doc({ guid: 'secret-subdoc', meta: { private: true } })])
const obfuscateParagraph = new Y.XmlElement('secret-p')
const obfuscateXmlText = new Y.XmlText()
obfuscateXmlText.insert(0, 'Hidden', { mark: 'secret-mark' })
obfuscateParagraph.setAttribute('class', 'private')
obfuscateParagraph.insert(0, [obfuscateXmlText, new Y.XmlHook('secret-hook')])
obfuscateDoc.getXmlFragment('xml').insert(0, [obfuscateParagraph])
const obfuscateUpdateV1 = Y.encodeStateAsUpdate(obfuscateDoc)
const obfuscateUpdateV2 = Y.encodeStateAsUpdateV2(obfuscateDoc)
const obfuscatedUpdateV1 = Y.obfuscateUpdate(obfuscateUpdateV1)
const obfuscatedUpdateV2 = Y.obfuscateUpdateV2(obfuscateUpdateV2)
const obfuscateOptionVariants = {
  noFormatting: { formatting: false },
  noSubdocs: { subdocs: false },
  noYxml: { yxml: false },
  noMetadata: { formatting: false, subdocs: false, yxml: false }
}
const obfuscateOptionVariantFixtures = Object.fromEntries(
  Object.entries(obfuscateOptionVariants).map(([name, options]) => {
    const obfuscatedV1 = Y.obfuscateUpdate(obfuscateUpdateV1, options)
    const obfuscatedV2 = Y.obfuscateUpdateV2(obfuscateUpdateV2, options)

    return [name, {
      options,
      obfuscatedV1: toBase64(obfuscatedV1),
      obfuscatedV2: toBase64(obfuscatedV2),
      obfuscatedDecodedV1: {
        structs: Y.decodeUpdate(obfuscatedV1).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdate(obfuscatedV1).ds)
      },
      obfuscatedDecodedV2: {
        structs: Y.decodeUpdateV2(obfuscatedV2).structs.map(normalizeStruct),
        deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(obfuscatedV2).ds)
      }
    }]
  })
)

const overlappingMergeDoc = new Y.Doc({ guid: 'overlapping-merge-doc', gc: false })
overlappingMergeDoc.clientID = 260
overlappingMergeDoc.getText('content').insert(0, 'ABCD')
const overlappingMergeFullV1 = Y.encodeStateAsUpdate(overlappingMergeDoc)
const overlappingMergeFullV2 = Y.encodeStateAsUpdateV2(overlappingMergeDoc)
const overlappingMergeTargetStateVector = (() => {
  const encoder = encoding.createEncoder()
  encoding.writeVarUint(encoder, 1)
  encoding.writeVarUint(encoder, 260)
  encoding.writeVarUint(encoder, 2)
  return encoding.toUint8Array(encoder)
})()
const overlappingMergeDiffV1 = Y.encodeStateAsUpdate(overlappingMergeDoc, overlappingMergeTargetStateVector)
const overlappingMergeDiffV2 = Y.encodeStateAsUpdateV2(overlappingMergeDoc, overlappingMergeTargetStateVector)
const overlappingMergeMergedV1 = Y.mergeUpdates([overlappingMergeDiffV1, overlappingMergeFullV1])
const overlappingMergeMergedV2 = Y.mergeUpdatesV2([overlappingMergeDiffV2, overlappingMergeFullV2])

const overlappingDeletedMergeDoc = new Y.Doc({ guid: 'overlapping-deleted-merge-doc', gc: false })
overlappingDeletedMergeDoc.clientID = 261
overlappingDeletedMergeDoc.getArray('array').insert(0, ['A', 'B', 'C', 'D', 'E'])
overlappingDeletedMergeDoc.getArray('array').delete(1, 3)
const overlappingDeletedMergeFullV1 = Y.encodeStateAsUpdate(overlappingDeletedMergeDoc)
const overlappingDeletedMergeFullV2 = Y.encodeStateAsUpdateV2(overlappingDeletedMergeDoc)
const overlappingDeletedMergeTargetStateVector = stateVector([[261, 2]])
const overlappingDeletedMergeDiffV1 = Y.encodeStateAsUpdate(overlappingDeletedMergeDoc, overlappingDeletedMergeTargetStateVector)
const overlappingDeletedMergeDiffV2 = Y.encodeStateAsUpdateV2(overlappingDeletedMergeDoc, overlappingDeletedMergeTargetStateVector)
const overlappingDeletedMergeMergedV1 = Y.mergeUpdates([overlappingDeletedMergeDiffV1, overlappingDeletedMergeFullV1])
const overlappingDeletedMergeMergedV2 = Y.mergeUpdatesV2([overlappingDeletedMergeDiffV2, overlappingDeletedMergeFullV2])

const overlappingGcMergeDoc = new Y.Doc({ guid: 'overlapping-gc-merge-doc', gc: true })
overlappingGcMergeDoc.clientID = 264
overlappingGcMergeDoc.getText('content').insert(0, 'ABCDE')
overlappingGcMergeDoc.getText('content').delete(1, 3)
const overlappingGcMergeFullV1 = Y.encodeStateAsUpdate(overlappingGcMergeDoc)
const overlappingGcMergeFullV2 = Y.encodeStateAsUpdateV2(overlappingGcMergeDoc)
const overlappingGcMergeTargetStateVector = stateVector([[264, 2]])
const overlappingGcMergeDiffV1 = Y.encodeStateAsUpdate(overlappingGcMergeDoc, overlappingGcMergeTargetStateVector)
const overlappingGcMergeDiffV2 = Y.encodeStateAsUpdateV2(overlappingGcMergeDoc, overlappingGcMergeTargetStateVector)
const overlappingGcMergeMergedV1 = Y.mergeUpdates([overlappingGcMergeDiffV1, overlappingGcMergeFullV1])
const overlappingGcMergeMergedV2 = Y.mergeUpdatesV2([overlappingGcMergeDiffV2, overlappingGcMergeFullV2])
const overlappingGcMergeReDiffStateVector = stateVector([[264, 1]])
const overlappingGcMergeReDiffV1 = Y.diffUpdate(overlappingGcMergeMergedV1, overlappingGcMergeReDiffStateVector)
const overlappingGcMergeReDiffV2 = Y.diffUpdateV2(overlappingGcMergeMergedV2, overlappingGcMergeReDiffStateVector)

const deleteSetOnlyDiffDoc = new Y.Doc({ guid: 'delete-set-only-update-utils-doc', gc: false })
deleteSetOnlyDiffDoc.clientID = 262
deleteSetOnlyDiffDoc.getText('content').insert(0, 'ABCD')
deleteSetOnlyDiffDoc.getText('content').delete(1, 2)
const deleteSetOnlyDiffUpdateV1 = Y.encodeStateAsUpdate(deleteSetOnlyDiffDoc)
const deleteSetOnlyDiffUpdateV2 = Y.encodeStateAsUpdateV2(deleteSetOnlyDiffDoc)
const deleteSetOnlyDiffTargetStateVector = stateVector([[262, 4]])
const deleteSetOnlyDiffV1 = Y.diffUpdate(deleteSetOnlyDiffUpdateV1, deleteSetOnlyDiffTargetStateVector)
const deleteSetOnlyDiffV2 = Y.diffUpdateV2(deleteSetOnlyDiffUpdateV2, deleteSetOnlyDiffTargetStateVector)
const overlappingDeleteSetDoc = new Y.Doc({ guid: 'overlapping-delete-set-utils-doc', gc: false })
overlappingDeleteSetDoc.clientID = 262
overlappingDeleteSetDoc.getText('content').insert(0, 'ABCDEFG')
overlappingDeleteSetDoc.getText('content').delete(2, 3)
const overlappingDeleteSetUpdateV1 = Y.encodeStateAsUpdate(overlappingDeleteSetDoc)
const deleteSetA = Y.decodeUpdate(deleteSetOnlyDiffUpdateV1).ds
const deleteSetB = Y.decodeUpdate(overlappingDeleteSetUpdateV1).ds
const mergedDeleteSet = Y.mergeDeleteSets([deleteSetA, deleteSetB])
const mergedDeleteSetReverse = Y.mergeDeleteSets([deleteSetB, deleteSetA])

const gapStateVectorDoc = new Y.Doc({ guid: 'gap-state-vector-update-utils-doc', gc: false })
gapStateVectorDoc.clientID = 263
gapStateVectorDoc.getText('content').insert(0, 'ABCD')
const gapStateVectorSuffixStateVector = stateVector([[263, 2]])
const gapStateVectorSuffixV1 = Y.encodeStateAsUpdate(gapStateVectorDoc, gapStateVectorSuffixStateVector)
const gapStateVectorSuffixV2 = Y.encodeStateAsUpdateV2(gapStateVectorDoc, gapStateVectorSuffixStateVector)
const gapStateVectorPrefixDoc = new Y.Doc({ guid: 'gap-state-vector-update-utils-prefix-doc', gc: false })
gapStateVectorPrefixDoc.clientID = 263
gapStateVectorPrefixDoc.getText('content').insert(0, 'AB')
const gapStateVectorPrefixV1 = Y.encodeStateAsUpdate(gapStateVectorPrefixDoc)
const gapStateVectorPrefixV2 = Y.encodeStateAsUpdateV2(gapStateVectorPrefixDoc)
const gapStateVectorMergedV1 = Y.mergeUpdates([gapStateVectorSuffixV1, gapStateVectorPrefixV1])
const gapStateVectorMergedV2 = Y.mergeUpdatesV2([gapStateVectorSuffixV2, gapStateVectorPrefixV2])

const textAttributeMergeBase = new Y.Doc({ guid: 'text-attribute-merge-base', gc: false })
textAttributeMergeBase.clientID = 265
const textAttributeMergeBaseText = textAttributeMergeBase.getText('content')
textAttributeMergeBaseText.insert(0, 'Text')
textAttributeMergeBaseText.setAttribute('lang', 'base')
const textAttributeMergeBaseUpdateV1 = Y.encodeStateAsUpdate(textAttributeMergeBase)
const textAttributeMergeBaseUpdateV2 = Y.encodeStateAsUpdateV2(textAttributeMergeBase)
const textAttributeMergeBaseStateVector = Y.encodeStateVector(textAttributeMergeBase)
const textAttributeMergeLeft = new Y.Doc({ guid: 'text-attribute-merge-left', gc: false })
textAttributeMergeLeft.clientID = 1
textAttributeMergeLeft.getText('content')
Y.applyUpdate(textAttributeMergeLeft, textAttributeMergeBaseUpdateV1)
textAttributeMergeLeft.getText('content').setAttribute('lang', 'left')
const textAttributeMergeRight = new Y.Doc({ guid: 'text-attribute-merge-right', gc: false })
textAttributeMergeRight.clientID = 2
textAttributeMergeRight.getText('content')
Y.applyUpdate(textAttributeMergeRight, textAttributeMergeBaseUpdateV1)
textAttributeMergeRight.getText('content').setAttribute('lang', 'right')
const textAttributeMergeLeftUpdateV1 = Y.encodeStateAsUpdate(textAttributeMergeLeft, textAttributeMergeBaseStateVector)
const textAttributeMergeLeftUpdateV2 = Y.encodeStateAsUpdateV2(textAttributeMergeLeft, textAttributeMergeBaseStateVector)
const textAttributeMergeRightUpdateV1 = Y.encodeStateAsUpdate(textAttributeMergeRight, textAttributeMergeBaseStateVector)
const textAttributeMergeRightUpdateV2 = Y.encodeStateAsUpdateV2(textAttributeMergeRight, textAttributeMergeBaseStateVector)
const textAttributeMergeUpdatesV1 = [textAttributeMergeRightUpdateV1, textAttributeMergeLeftUpdateV1, textAttributeMergeBaseUpdateV1]
const textAttributeMergeUpdatesV2 = [textAttributeMergeRightUpdateV2, textAttributeMergeLeftUpdateV2, textAttributeMergeBaseUpdateV2]
const textAttributeMergeMergedV1 = Y.mergeUpdates(textAttributeMergeUpdatesV1)
const textAttributeMergeMergedV2 = Y.mergeUpdatesV2(textAttributeMergeUpdatesV2)
const textAttributeMergeDoc = new Y.Doc({ guid: 'text-attribute-merge-doc', gc: false })
const textAttributeMergeText = textAttributeMergeDoc.getText('content')
Y.applyUpdate(textAttributeMergeDoc, textAttributeMergeMergedV1)

const threeWayConflictUpdateUtilsCase = concurrentFixtures.find(({ name }) => name === 'text-three-way-concurrent-between-same-neighbors')
const threeWayConflictUpdateUtilsUpdatesV1 = threeWayConflictUpdateUtilsCase.updatesV1.map(fromBase64)
const threeWayConflictUpdateUtilsUpdatesV2 = threeWayConflictUpdateUtilsCase.updatesV2.map(fromBase64)
const threeWayConflictUpdateUtilsMergedV1 = Y.mergeUpdates(threeWayConflictUpdateUtilsUpdatesV1)
const threeWayConflictUpdateUtilsMergedV2 = Y.mergeUpdatesV2(threeWayConflictUpdateUtilsUpdatesV2)
const threeWayConflictUpdateUtilsPrefixV1 = Y.mergeUpdates([
  threeWayConflictUpdateUtilsUpdatesV1[3],
  threeWayConflictUpdateUtilsUpdatesV1[2]
])
const threeWayConflictUpdateUtilsPrefixV2 = Y.mergeUpdatesV2([
  threeWayConflictUpdateUtilsUpdatesV2[3],
  threeWayConflictUpdateUtilsUpdatesV2[2]
])
const threeWayConflictUpdateUtilsTargetStateVector = Y.encodeStateVectorFromUpdate(threeWayConflictUpdateUtilsPrefixV1)
const threeWayConflictUpdateUtilsDiffV1 = Y.diffUpdate(threeWayConflictUpdateUtilsMergedV1, threeWayConflictUpdateUtilsTargetStateVector)
const threeWayConflictUpdateUtilsDiffV2 = Y.diffUpdateV2(threeWayConflictUpdateUtilsMergedV2, threeWayConflictUpdateUtilsTargetStateVector)

const xmlTextDeleteEditConflictUpdateUtilsCase = concurrentFixtures.find(({ name }) => name === 'xml-text-concurrent-delete-edit-xml-shared-type')
const xmlTextDeleteEditConflictUpdateUtilsUpdatesV1 = xmlTextDeleteEditConflictUpdateUtilsCase.updatesV1.map(fromBase64)
const xmlTextDeleteEditConflictUpdateUtilsUpdatesV2 = xmlTextDeleteEditConflictUpdateUtilsCase.updatesV2.map(fromBase64)
const xmlTextDeleteEditConflictUpdateUtilsMergedV1 = Y.mergeUpdates(xmlTextDeleteEditConflictUpdateUtilsUpdatesV1)
const xmlTextDeleteEditConflictUpdateUtilsMergedV2 = Y.mergeUpdatesV2(xmlTextDeleteEditConflictUpdateUtilsUpdatesV2)
const xmlTextDeleteEditConflictUpdateUtilsConvertedV2 = Y.convertUpdateFormatV1ToV2(xmlTextDeleteEditConflictUpdateUtilsMergedV1)
const xmlTextDeleteEditConflictUpdateUtilsConvertedV1 = Y.convertUpdateFormatV2ToV1(xmlTextDeleteEditConflictUpdateUtilsMergedV2)

const updateUtilsFixtures = {
  json: updateUtilsDoc.toJSON(),
  updatesV1: updateUtilsMergeInputV1.map(toBase64),
  updatesV2: updateUtilsMergeInputV2.map(toBase64),
  mergedV1: toBase64(updateUtilsMergedV1),
  mergedV2: toBase64(updateUtilsMergedV2),
  mergedDecodedV1: {
    structs: Y.decodeUpdate(updateUtilsMergedV1).structs.map(normalizeStruct),
    deleteSet: normalizeDeleteSet(Y.decodeUpdate(updateUtilsMergedV1).ds)
  },
  mergedDecodedV2: {
    structs: Y.decodeUpdateV2(updateUtilsMergedV2).structs.map(normalizeStruct),
    deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(updateUtilsMergedV2).ds)
  },
  stateVectorFromMergedV1: toBase64(Y.encodeStateVectorFromUpdate(updateUtilsMergedV1)),
  stateVectorFromMergedV2: toBase64(Y.encodeStateVectorFromUpdateV2(updateUtilsMergedV2)),
  targetStateVectorV1: toBase64(updateUtilsTargetStateVector),
  diffV1: toBase64(updateUtilsDiffV1),
  diffV2: toBase64(updateUtilsDiffV2),
  diffDecodedV1: {
    structs: Y.decodeUpdate(updateUtilsDiffV1).structs.map(normalizeStruct),
    deleteSet: normalizeDeleteSet(Y.decodeUpdate(updateUtilsDiffV1).ds)
  },
  diffDecodedV2: {
    structs: Y.decodeUpdateV2(updateUtilsDiffV2).structs.map(normalizeStruct),
    deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(updateUtilsDiffV2).ds)
  },
  convertedV2: toBase64(updateUtilsConvertedV2),
  convertedDecodedV2: {
    structs: Y.decodeUpdateV2(updateUtilsConvertedV2).structs.map(normalizeStruct),
    deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(updateUtilsConvertedV2).ds)
  },
  convertedV1: toBase64(updateUtilsConvertedV1),
  convertedDecodedV1: {
    structs: Y.decodeUpdate(updateUtilsConvertedV1).structs.map(normalizeStruct),
    deleteSet: normalizeDeleteSet(Y.decodeUpdate(updateUtilsConvertedV1).ds)
  },
  empty: {
    stateVectorV1: toBase64(updateUtilsEmptyStateVector),
    mergedV1: toBase64(updateUtilsEmptyMergedV1),
    mergedV2: toBase64(updateUtilsEmptyMergedV2),
    diffV1: toBase64(updateUtilsEmptyDiffV1),
    diffV2: toBase64(updateUtilsEmptyDiffV2),
    convertedV2: toBase64(Y.convertUpdateFormatV1ToV2(updateUtilsEmptyMergedV1)),
    convertedV1: toBase64(Y.convertUpdateFormatV2ToV1(updateUtilsEmptyMergedV2)),
    stateVectorFromMergedV1: toBase64(Y.encodeStateVectorFromUpdate(updateUtilsEmptyMergedV1)),
    stateVectorFromMergedV2: toBase64(Y.encodeStateVectorFromUpdateV2(updateUtilsEmptyMergedV2)),
    decodedV1: {
      structs: Y.decodeUpdate(updateUtilsEmptyMergedV1).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdate(updateUtilsEmptyMergedV1).ds)
    },
    decodedV2: {
      structs: Y.decodeUpdateV2(updateUtilsEmptyMergedV2).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(updateUtilsEmptyMergedV2).ds)
    }
  },
  deepMap: {
    json: normalizeSemanticJsonValue(deepMapUpdateUtilsDoc.toJSON()),
    bodyDelta: normalizeValue(deepMapUpdateUtilsBody.toDelta()),
    xmlString: deepMapUpdateUtilsXml.toString(),
    xmlLength: deepMapUpdateUtilsXml.length,
    xmlChildren: deepMapUpdateUtilsXml.toArray().map(xmlNodeSummary),
    prefixV1: toBase64(deepMapUpdateUtilsPrefixV1),
    prefixV2: toBase64(deepMapUpdateUtilsPrefixV2),
    updatesV1: deepMapUpdateUtilsMergeInputV1.map(toBase64),
    updatesV2: deepMapUpdateUtilsMergeInputV2.map(toBase64),
    mergedV1: toBase64(deepMapUpdateUtilsMergedV1),
    mergedV2: toBase64(deepMapUpdateUtilsMergedV2),
    mergedDecodedV1: {
      structs: Y.decodeUpdate(deepMapUpdateUtilsMergedV1).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdate(deepMapUpdateUtilsMergedV1).ds)
    },
    mergedDecodedV2: {
      structs: Y.decodeUpdateV2(deepMapUpdateUtilsMergedV2).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(deepMapUpdateUtilsMergedV2).ds)
    },
    targetStateVectorV1: toBase64(deepMapUpdateUtilsTargetStateVector),
    diffV1: toBase64(deepMapUpdateUtilsDiffV1),
    diffV2: toBase64(deepMapUpdateUtilsDiffV2),
    diffDecodedV1: {
      structs: Y.decodeUpdate(deepMapUpdateUtilsDiffV1).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdate(deepMapUpdateUtilsDiffV1).ds)
    },
    diffDecodedV2: {
      structs: Y.decodeUpdateV2(deepMapUpdateUtilsDiffV2).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(deepMapUpdateUtilsDiffV2).ds)
    },
    convertedV2: toBase64(deepMapUpdateUtilsConvertedV2),
    convertedDecodedV2: {
      structs: Y.decodeUpdateV2(deepMapUpdateUtilsConvertedV2).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(deepMapUpdateUtilsConvertedV2).ds)
    },
    convertedV1: toBase64(deepMapUpdateUtilsConvertedV1),
    convertedDecodedV1: {
      structs: Y.decodeUpdate(deepMapUpdateUtilsConvertedV1).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdate(deepMapUpdateUtilsConvertedV1).ds)
    },
    partialTextTargetStateVectorV1: toBase64(deepMapUpdateUtilsPartialTextStateVector),
    partialTextDiffDecodedV1: {
      structs: Y.decodeUpdate(deepMapUpdateUtilsPartialTextDiffV1).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdate(deepMapUpdateUtilsPartialTextDiffV1).ds)
    },
    partialTextDiffDecodedV2: {
      structs: Y.decodeUpdateV2(deepMapUpdateUtilsPartialTextDiffV2).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(deepMapUpdateUtilsPartialTextDiffV2).ds)
    }
  },
  xmlHook: {
    json: normalizeValue(xmlHookUpdateUtilsDoc.toJSON()),
    hookJson: normalizeValue(xmlHookUpdateUtilsHook.toJSON()),
    bodyDelta: normalizeValue(xmlHookUpdateUtilsBody.toDelta()),
    elementXml: xmlHookUpdateUtilsElement.toString(),
    fragmentXml: xmlHookUpdateUtilsFragment.toString(),
    prefixV1: toBase64(xmlHookUpdateUtilsPrefixV1),
    prefixV2: toBase64(xmlHookUpdateUtilsPrefixV2),
    updatesV1: xmlHookUpdateUtilsMergeInputV1.map(toBase64),
    updatesV2: xmlHookUpdateUtilsMergeInputV2.map(toBase64),
    mergedV1: toBase64(xmlHookUpdateUtilsMergedV1),
    mergedV2: toBase64(xmlHookUpdateUtilsMergedV2),
    mergedDecodedV1: {
      structs: Y.decodeUpdate(xmlHookUpdateUtilsMergedV1).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdate(xmlHookUpdateUtilsMergedV1).ds)
    },
    mergedDecodedV2: {
      structs: Y.decodeUpdateV2(xmlHookUpdateUtilsMergedV2).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(xmlHookUpdateUtilsMergedV2).ds)
    },
    targetStateVectorV1: toBase64(xmlHookUpdateUtilsTargetStateVector),
    diffV1: toBase64(xmlHookUpdateUtilsDiffV1),
    diffV2: toBase64(xmlHookUpdateUtilsDiffV2),
    diffDecodedV1: {
      structs: Y.decodeUpdate(xmlHookUpdateUtilsDiffV1).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdate(xmlHookUpdateUtilsDiffV1).ds)
    },
    diffDecodedV2: {
      structs: Y.decodeUpdateV2(xmlHookUpdateUtilsDiffV2).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(xmlHookUpdateUtilsDiffV2).ds)
    },
    convertedV2: toBase64(xmlHookUpdateUtilsConvertedV2),
    convertedDecodedV2: {
      structs: Y.decodeUpdateV2(xmlHookUpdateUtilsConvertedV2).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(xmlHookUpdateUtilsConvertedV2).ds)
    },
    convertedV1: toBase64(xmlHookUpdateUtilsConvertedV1),
    convertedDecodedV1: {
      structs: Y.decodeUpdate(xmlHookUpdateUtilsConvertedV1).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdate(xmlHookUpdateUtilsConvertedV1).ds)
    }
  },
  xmlElement: {
    json: normalizeValue(xmlElementUpdateUtilsDoc.toJSON()),
    elementAttributes: normalizeValue(xmlElementUpdateUtilsElement.getAttributes()),
    bodyDelta: normalizeValue(xmlElementUpdateUtilsBody.toDelta()),
    inlineXml: xmlElementUpdateUtilsInline.toString(),
    prefixV1: toBase64(xmlElementUpdateUtilsPrefixV1),
    prefixV2: toBase64(xmlElementUpdateUtilsPrefixV2),
    updatesV1: xmlElementUpdateUtilsMergeInputV1.map(toBase64),
    updatesV2: xmlElementUpdateUtilsMergeInputV2.map(toBase64),
    mergedV1: toBase64(xmlElementUpdateUtilsMergedV1),
    mergedV2: toBase64(xmlElementUpdateUtilsMergedV2),
    mergedDecodedV1: {
      structs: Y.decodeUpdate(xmlElementUpdateUtilsMergedV1).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdate(xmlElementUpdateUtilsMergedV1).ds)
    },
    mergedDecodedV2: {
      structs: Y.decodeUpdateV2(xmlElementUpdateUtilsMergedV2).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(xmlElementUpdateUtilsMergedV2).ds)
    },
    targetStateVectorV1: toBase64(xmlElementUpdateUtilsTargetStateVector),
    diffV1: toBase64(xmlElementUpdateUtilsDiffV1),
    diffV2: toBase64(xmlElementUpdateUtilsDiffV2),
    diffDecodedV1: {
      structs: Y.decodeUpdate(xmlElementUpdateUtilsDiffV1).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdate(xmlElementUpdateUtilsDiffV1).ds)
    },
    diffDecodedV2: {
      structs: Y.decodeUpdateV2(xmlElementUpdateUtilsDiffV2).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(xmlElementUpdateUtilsDiffV2).ds)
    },
    convertedV2: toBase64(xmlElementUpdateUtilsConvertedV2),
    convertedDecodedV2: {
      structs: Y.decodeUpdateV2(xmlElementUpdateUtilsConvertedV2).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(xmlElementUpdateUtilsConvertedV2).ds)
    },
    convertedV1: toBase64(xmlElementUpdateUtilsConvertedV1),
    convertedDecodedV1: {
      structs: Y.decodeUpdate(xmlElementUpdateUtilsConvertedV1).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdate(xmlElementUpdateUtilsConvertedV1).ds)
    }
  },
  xmlText: {
    json: normalizeValue(xmlTextUpdateUtilsDoc.toJSON()),
    textAttributes: normalizeValue(xmlTextUpdateUtilsText.getAttributes()),
    bodyDelta: normalizeValue(xmlTextUpdateUtilsBody.toDelta()),
    inlineXml: xmlTextUpdateUtilsInline.toString(),
    labelDelta: normalizeValue(xmlTextUpdateUtilsLabel.toDelta()),
    fragmentXml: xmlTextUpdateUtilsFragment.toString(),
    prefixV1: toBase64(xmlTextUpdateUtilsPrefixV1),
    prefixV2: toBase64(xmlTextUpdateUtilsPrefixV2),
    updatesV1: xmlTextUpdateUtilsMergeInputV1.map(toBase64),
    updatesV2: xmlTextUpdateUtilsMergeInputV2.map(toBase64),
    mergedV1: toBase64(xmlTextUpdateUtilsMergedV1),
    mergedV2: toBase64(xmlTextUpdateUtilsMergedV2),
    mergedDecodedV1: {
      structs: Y.decodeUpdate(xmlTextUpdateUtilsMergedV1).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdate(xmlTextUpdateUtilsMergedV1).ds)
    },
    mergedDecodedV2: {
      structs: Y.decodeUpdateV2(xmlTextUpdateUtilsMergedV2).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(xmlTextUpdateUtilsMergedV2).ds)
    },
    targetStateVectorV1: toBase64(xmlTextUpdateUtilsTargetStateVector),
    diffV1: toBase64(xmlTextUpdateUtilsDiffV1),
    diffV2: toBase64(xmlTextUpdateUtilsDiffV2),
    diffDecodedV1: {
      structs: Y.decodeUpdate(xmlTextUpdateUtilsDiffV1).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdate(xmlTextUpdateUtilsDiffV1).ds)
    },
    diffDecodedV2: {
      structs: Y.decodeUpdateV2(xmlTextUpdateUtilsDiffV2).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(xmlTextUpdateUtilsDiffV2).ds)
    },
    convertedV2: toBase64(xmlTextUpdateUtilsConvertedV2),
    convertedDecodedV2: {
      structs: Y.decodeUpdateV2(xmlTextUpdateUtilsConvertedV2).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(xmlTextUpdateUtilsConvertedV2).ds)
    },
    convertedV1: toBase64(xmlTextUpdateUtilsConvertedV1),
    convertedDecodedV1: {
      structs: Y.decodeUpdate(xmlTextUpdateUtilsConvertedV1).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdate(xmlTextUpdateUtilsConvertedV1).ds)
    }
  },
  obfuscate: {
    updateV1: toBase64(obfuscateUpdateV1),
    updateV2: toBase64(obfuscateUpdateV2),
    obfuscatedV1: toBase64(obfuscatedUpdateV1),
    obfuscatedV2: toBase64(obfuscatedUpdateV2),
    obfuscatedDecodedV1: {
      structs: Y.decodeUpdate(obfuscatedUpdateV1).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdate(obfuscatedUpdateV1).ds)
    },
    obfuscatedDecodedV2: {
      structs: Y.decodeUpdateV2(obfuscatedUpdateV2).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(obfuscatedUpdateV2).ds)
    },
    optionVariants: obfuscateOptionVariantFixtures
  },
  overlappingMerge: {
    json: overlappingMergeDoc.toJSON(),
    updatesV1: [overlappingMergeDiffV1, overlappingMergeFullV1].map(toBase64),
    updatesV2: [overlappingMergeDiffV2, overlappingMergeFullV2].map(toBase64),
    mergedDecodedV1: {
      structs: Y.decodeUpdate(overlappingMergeMergedV1).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdate(overlappingMergeMergedV1).ds)
    },
    mergedDecodedV2: {
      structs: Y.decodeUpdateV2(overlappingMergeMergedV2).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(overlappingMergeMergedV2).ds)
    }
  },
  overlappingDeletedMerge: {
    json: overlappingDeletedMergeDoc.toJSON(),
    updatesV1: [overlappingDeletedMergeDiffV1, overlappingDeletedMergeFullV1].map(toBase64),
    updatesV2: [overlappingDeletedMergeDiffV2, overlappingDeletedMergeFullV2].map(toBase64),
    mergedDecodedV1: {
      structs: Y.decodeUpdate(overlappingDeletedMergeMergedV1).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdate(overlappingDeletedMergeMergedV1).ds)
    },
    mergedDecodedV2: {
      structs: Y.decodeUpdateV2(overlappingDeletedMergeMergedV2).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(overlappingDeletedMergeMergedV2).ds)
    }
  },
  overlappingGcMerge: {
    json: overlappingGcMergeDoc.toJSON(),
    updatesV1: [overlappingGcMergeDiffV1, overlappingGcMergeFullV1].map(toBase64),
    updatesV2: [overlappingGcMergeDiffV2, overlappingGcMergeFullV2].map(toBase64),
    diffTargetStateVectorV1: toBase64(overlappingGcMergeReDiffStateVector),
    mergedDecodedV1: {
      structs: Y.decodeUpdate(overlappingGcMergeMergedV1).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdate(overlappingGcMergeMergedV1).ds)
    },
    mergedDecodedV2: {
      structs: Y.decodeUpdateV2(overlappingGcMergeMergedV2).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(overlappingGcMergeMergedV2).ds)
    },
    diffDecodedV1: {
      structs: Y.decodeUpdate(overlappingGcMergeReDiffV1).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdate(overlappingGcMergeReDiffV1).ds)
    },
    diffDecodedV2: {
      structs: Y.decodeUpdateV2(overlappingGcMergeReDiffV2).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(overlappingGcMergeReDiffV2).ds)
    }
  },
  deleteSetOnlyDiff: {
    updateV1: toBase64(deleteSetOnlyDiffUpdateV1),
    updateV2: toBase64(deleteSetOnlyDiffUpdateV2),
    targetStateVectorV1: toBase64(deleteSetOnlyDiffTargetStateVector),
    diffDecodedV1: {
      structs: Y.decodeUpdate(deleteSetOnlyDiffV1).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdate(deleteSetOnlyDiffV1).ds)
    },
    diffDecodedV2: {
      structs: Y.decodeUpdateV2(deleteSetOnlyDiffV2).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(deleteSetOnlyDiffV2).ds)
    }
  },
  deleteSetUtils: {
    deleteSetA: normalizeDeleteSet(deleteSetA),
    deleteSetB: normalizeDeleteSet(deleteSetB),
    merged: normalizeDeleteSet(mergedDeleteSet),
    mergedReverse: normalizeDeleteSet(mergedDeleteSetReverse),
    equalMergedOrders: Y.equalDeleteSets(mergedDeleteSet, mergedDeleteSetReverse),
    equalOriginals: Y.equalDeleteSets(deleteSetA, deleteSetB)
  },
  gapStateVectors: {
    suffixUpdateV1: toBase64(gapStateVectorSuffixV1),
    suffixUpdateV2: toBase64(gapStateVectorSuffixV2),
    prefixUpdateV1: toBase64(gapStateVectorPrefixV1),
    prefixUpdateV2: toBase64(gapStateVectorPrefixV2),
    mergedV1: toBase64(gapStateVectorMergedV1),
    mergedV2: toBase64(gapStateVectorMergedV2),
    stateVectorFromSuffixV1: toBase64(Y.encodeStateVectorFromUpdate(gapStateVectorSuffixV1)),
    stateVectorFromSuffixV2: toBase64(Y.encodeStateVectorFromUpdateV2(gapStateVectorSuffixV2)),
    stateVectorFromMergedV1: toBase64(Y.encodeStateVectorFromUpdate(gapStateVectorMergedV1)),
    stateVectorFromMergedV2: toBase64(Y.encodeStateVectorFromUpdateV2(gapStateVectorMergedV2))
  },
  textAttributeMerge: {
    json: textAttributeMergeDoc.toJSON(),
    textAttributes: normalizeValue(textAttributeMergeText.getAttributes()),
    updatesV1: textAttributeMergeUpdatesV1.map(toBase64),
    updatesV2: textAttributeMergeUpdatesV2.map(toBase64),
    mergedDecodedV1: {
      structs: Y.decodeUpdate(textAttributeMergeMergedV1).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdate(textAttributeMergeMergedV1).ds)
    },
    mergedDecodedV2: {
      structs: Y.decodeUpdateV2(textAttributeMergeMergedV2).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(textAttributeMergeMergedV2).ds)
    }
  },
  threeWayConflictMerge: {
    json: threeWayConflictUpdateUtilsCase.json,
    updatesV1: threeWayConflictUpdateUtilsCase.updatesV1,
    updatesV2: threeWayConflictUpdateUtilsCase.updatesV2,
    prefixV1: toBase64(threeWayConflictUpdateUtilsPrefixV1),
    prefixV2: toBase64(threeWayConflictUpdateUtilsPrefixV2),
    mergedV1: toBase64(threeWayConflictUpdateUtilsMergedV1),
    mergedV2: toBase64(threeWayConflictUpdateUtilsMergedV2),
    mergedDecodedV1: {
      structs: Y.decodeUpdate(threeWayConflictUpdateUtilsMergedV1).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdate(threeWayConflictUpdateUtilsMergedV1).ds)
    },
    mergedDecodedV2: {
      structs: Y.decodeUpdateV2(threeWayConflictUpdateUtilsMergedV2).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(threeWayConflictUpdateUtilsMergedV2).ds)
    },
    targetStateVectorV1: toBase64(threeWayConflictUpdateUtilsTargetStateVector),
    diffV1: toBase64(threeWayConflictUpdateUtilsDiffV1),
    diffV2: toBase64(threeWayConflictUpdateUtilsDiffV2),
    diffDecodedV1: {
      structs: Y.decodeUpdate(threeWayConflictUpdateUtilsDiffV1).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdate(threeWayConflictUpdateUtilsDiffV1).ds)
    },
    diffDecodedV2: {
      structs: Y.decodeUpdateV2(threeWayConflictUpdateUtilsDiffV2).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(threeWayConflictUpdateUtilsDiffV2).ds)
    }
  },
  xmlTextDeleteEditConflictMerge: {
    json: xmlTextDeleteEditConflictUpdateUtilsCase.json,
    xmlTextAttributes: xmlTextDeleteEditConflictUpdateUtilsCase.xmlTextAttributes,
    updatesV1: xmlTextDeleteEditConflictUpdateUtilsCase.updatesV1,
    updatesV2: xmlTextDeleteEditConflictUpdateUtilsCase.updatesV2,
    mergedV1: toBase64(xmlTextDeleteEditConflictUpdateUtilsMergedV1),
    mergedV2: toBase64(xmlTextDeleteEditConflictUpdateUtilsMergedV2),
    convertedV2: toBase64(xmlTextDeleteEditConflictUpdateUtilsConvertedV2),
    convertedV1: toBase64(xmlTextDeleteEditConflictUpdateUtilsConvertedV1),
    mergedDecodedV1: {
      structs: Y.decodeUpdate(xmlTextDeleteEditConflictUpdateUtilsMergedV1).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdate(xmlTextDeleteEditConflictUpdateUtilsMergedV1).ds)
    },
    mergedDecodedV2: {
      structs: Y.decodeUpdateV2(xmlTextDeleteEditConflictUpdateUtilsMergedV2).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(xmlTextDeleteEditConflictUpdateUtilsMergedV2).ds)
    },
    convertedDecodedV2: {
      structs: Y.decodeUpdateV2(xmlTextDeleteEditConflictUpdateUtilsConvertedV2).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdateV2(xmlTextDeleteEditConflictUpdateUtilsConvertedV2).ds)
    },
    convertedDecodedV1: {
      structs: Y.decodeUpdate(xmlTextDeleteEditConflictUpdateUtilsConvertedV1).structs.map(normalizeStruct),
      deleteSet: normalizeDeleteSet(Y.decodeUpdate(xmlTextDeleteEditConflictUpdateUtilsConvertedV1).ds)
    }
  }
}

fs.writeFileSync(
  path.join(outDir, 'update-utils.json'),
  `${JSON.stringify(updateUtilsFixtures, null, 2)}\n`
)

const normalizeSnapshot = snapshot => ({
  deleteSet: normalizeDeleteSet(snapshot.ds),
  stateVector: Object.fromEntries(snapshot.sv)
})

const snapshotDoc = new Y.Doc({ guid: 'snapshot-doc', gc: false })
snapshotDoc.clientID = 280
snapshotDoc.getText('content').insert(0, 'ABCDE')
snapshotDoc.getText('content').delete(1, 2)
snapshotDoc.getMap('map').set('title', 'Snapshot')
const snapshotValue = Y.snapshot(snapshotDoc)
const snapshotEncodedV1 = Y.encodeSnapshot(snapshotValue)
const snapshotEncodedV2 = Y.encodeSnapshotV2(snapshotValue)
const snapshotDecodedV1 = Y.decodeSnapshot(snapshotEncodedV1)
const snapshotDecodedV2 = Y.decodeSnapshotV2(snapshotEncodedV2)
const emptySnapshotEncodedV1 = Y.encodeSnapshot(Y.emptySnapshot)
const emptySnapshotEncodedV2 = Y.encodeSnapshotV2(Y.emptySnapshot)
const alteredSnapshot = Y.createSnapshot(snapshotValue.ds, new Map([[280, 1]]))
const snapshotContainedUpdateV1 = Y.encodeStateAsUpdate(snapshotDoc)
const snapshotContainedUpdateV2 = Y.encodeStateAsUpdateV2(snapshotDoc)
const snapshotFutureDoc = new Y.Doc({ guid: 'snapshot-future-doc', gc: false })
snapshotFutureDoc.clientID = 281
snapshotFutureDoc.getText('content')
snapshotFutureDoc.getMap('map')
Y.applyUpdate(snapshotFutureDoc, snapshotContainedUpdateV1)
snapshotFutureDoc.getText('content').insert(snapshotFutureDoc.getText('content').length, 'Z')
const snapshotFutureUpdateV1 = Y.encodeStateAsUpdate(snapshotFutureDoc)
const snapshotFutureUpdateV2 = Y.encodeStateAsUpdateV2(snapshotFutureDoc)
const snapshotExtraDeleteDoc = new Y.Doc({ guid: 'snapshot-extra-delete-doc', gc: false })
snapshotExtraDeleteDoc.clientID = 280
snapshotExtraDeleteDoc.getText('content').insert(0, 'ABCDE')
snapshotExtraDeleteDoc.getText('content').delete(3, 1)
snapshotExtraDeleteDoc.getMap('map').set('title', 'Snapshot')
const snapshotExtraDeleteUpdateV1 = Y.encodeStateAsUpdate(snapshotExtraDeleteDoc)
const snapshotExtraDeleteUpdateV2 = Y.encodeStateAsUpdateV2(snapshotExtraDeleteDoc)
const snapshotRestoreDoc = new Y.Doc({ guid: 'snapshot-restore-doc', gc: false })
snapshotRestoreDoc.clientID = 282
snapshotRestoreDoc.getText('content').insert(0, 'ABCDE')
const snapshotRestoreBeforeDelete = Y.snapshot(snapshotRestoreDoc)
snapshotRestoreDoc.getText('content').delete(1, 2)
snapshotRestoreDoc.getMap('map').set('title', 'Restored')
const snapshotRestoreAfterDelete = Y.snapshot(snapshotRestoreDoc)
const snapshotRestorePartial = Y.createSnapshot(Y.createDeleteSet(), new Map([[282, 2]]))
const restoredBeforeDeleteDoc = Y.createDocFromSnapshot(snapshotRestoreDoc, snapshotRestoreBeforeDelete)
const restoredAfterDeleteDoc = Y.createDocFromSnapshot(snapshotRestoreDoc, snapshotRestoreAfterDelete)
const restoredPartialDoc = Y.createDocFromSnapshot(snapshotRestoreDoc, snapshotRestorePartial)
restoredBeforeDeleteDoc.getText('content')
restoredAfterDeleteDoc.getText('content')
restoredAfterDeleteDoc.getMap('map')
restoredPartialDoc.getText('content')

const snapshotReadDoc = new Y.Doc({ guid: 'snapshot-read-doc', gc: false })
snapshotReadDoc.clientID = 283
const snapshotReadArray = snapshotReadDoc.getArray('items')
const snapshotReadMap = snapshotReadDoc.getMap('meta')
const snapshotReadText = snapshotReadDoc.getText('content')
const snapshotReadXml = snapshotReadDoc.getXmlFragment('xml')
snapshotReadArray.insert(0, ['A', 'B', 'C'])
snapshotReadMap.set('title', 'Before')
snapshotReadMap.set('count', 1)
snapshotReadText.insert(0, 'Hello')
snapshotReadText.format(1, 3, { bold: true })
const snapshotReadXmlParagraph = new Y.XmlElement('p')
snapshotReadXmlParagraph.setAttribute('class', 'before')
const snapshotReadXmlText = new Y.XmlText()
snapshotReadXmlText.insert(0, 'Xml')
snapshotReadXmlParagraph.insert(0, [snapshotReadXmlText])
snapshotReadXml.insert(0, [snapshotReadXmlParagraph])
const snapshotReadXmlSharedBody = new Y.Text()
snapshotReadXmlParagraph.setAttribute('body', snapshotReadXmlSharedBody)
snapshotReadXmlSharedBody.insert(0, 'BodyBefore')
const snapshotReadXmlSharedElement = new Y.XmlElement('span')
snapshotReadXmlParagraph.setAttribute('inline', snapshotReadXmlSharedElement)
snapshotReadXmlSharedElement.setAttribute('class', 'before')
const snapshotReadXmlSharedElementText = new Y.XmlText()
snapshotReadXmlSharedElementText.insert(0, 'InlineBefore')
snapshotReadXmlSharedElement.insert(0, [snapshotReadXmlSharedElementText])
const snapshotReadNestedArray = new Y.Array()
snapshotReadNestedArray.insert(0, ['nA', 'nB'])
const snapshotReadNestedMap = new Y.Map()
snapshotReadNestedMap.set('name', 'NestedBefore')
const snapshotReadNestedText = new Y.Text()
snapshotReadNestedText.insert(0, 'NestedText')
snapshotReadNestedText.format(0, 6, { italic: true })
const snapshotReadNestedXmlFragment = new Y.XmlFragment()
const snapshotReadNestedXmlText = new Y.XmlText()
snapshotReadNestedXmlText.insert(0, 'NestedXml')
const snapshotReadNestedXmlElement = new Y.XmlElement('span')
snapshotReadNestedXmlElement.setAttribute('class', 'before')
const snapshotReadNestedXmlElementText = new Y.XmlText()
snapshotReadNestedXmlElementText.insert(0, 'Before')
snapshotReadNestedXmlElement.insert(0, [snapshotReadNestedXmlElementText])
snapshotReadNestedXmlFragment.insert(0, [snapshotReadNestedXmlText, snapshotReadNestedXmlElement])
const snapshotReadMapText = new Y.Text()
snapshotReadMapText.insert(0, 'MapText')
snapshotReadMapText.format(0, 3, { bold: true })
snapshotReadMapText.setAttribute('lang', 'en')
snapshotReadMap.set('body', snapshotReadMapText)
const snapshotReadMapXmlFragment = new Y.XmlFragment()
const snapshotReadMapXmlText = new Y.XmlText()
snapshotReadMapXmlText.insert(0, 'MapXml')
const snapshotReadMapXmlElement = new Y.XmlElement('em')
snapshotReadMapXmlElement.setAttribute('class', 'before')
const snapshotReadMapXmlElementText = new Y.XmlText()
snapshotReadMapXmlElementText.insert(0, 'Before')
snapshotReadMapXmlElement.insert(0, [snapshotReadMapXmlElementText])
snapshotReadMapXmlFragment.insert(0, [snapshotReadMapXmlText, snapshotReadMapXmlElement])
snapshotReadMap.set('xmlBody', snapshotReadMapXmlFragment)
snapshotReadArray.insert(3, [snapshotReadNestedArray, snapshotReadNestedMap, snapshotReadNestedText, snapshotReadNestedXmlFragment])
const snapshotReadBefore = Y.snapshot(snapshotReadDoc)
snapshotReadArray.delete(1, 1)
snapshotReadArray.insert(2, ['D'])
snapshotReadMap.set('title', 'After')
snapshotReadMap.delete('count')
snapshotReadMap.set('extra', true)
snapshotReadMapText.delete(3, 4)
snapshotReadMapText.insert(3, ' body', { italic: true })
snapshotReadMapText.setAttribute('lang', 'fr')
snapshotReadMapText.setAttribute('mark', { color: 'green' })
snapshotReadMapXmlText.insert(6, '!')
snapshotReadMapXmlElement.setAttribute('class', 'after')
snapshotReadMapXmlElementText.delete(0, 6)
snapshotReadMapXmlElementText.insert(0, 'After')
snapshotReadMapXmlFragment.insert(2, [new Y.XmlElement('hr')])
snapshotReadText.delete(1, 2)
snapshotReadText.insert(1, 'i')
snapshotReadNestedArray.delete(0, 1)
snapshotReadNestedArray.push(['nC'])
snapshotReadNestedMap.set('name', 'NestedAfter')
snapshotReadNestedMap.set('extra', 'yes')
snapshotReadNestedText.delete(6, 4)
snapshotReadNestedText.insert(6, '!')
snapshotReadNestedXmlText.insert(9, '!')
snapshotReadNestedXmlElement.setAttribute('class', 'after')
snapshotReadNestedXmlElementText.delete(0, 6)
snapshotReadNestedXmlElementText.insert(0, 'After')
snapshotReadNestedXmlFragment.insert(2, [new Y.XmlElement('br')])
snapshotReadXmlParagraph.setAttribute('class', 'after')
snapshotReadXmlSharedBody.delete(4, 6)
snapshotReadXmlSharedBody.insert(4, 'After')
snapshotReadXmlSharedElement.setAttribute('class', 'after')
snapshotReadXmlSharedElementText.delete(6, 6)
snapshotReadXmlSharedElementText.insert(6, 'After')
snapshotReadXmlText.insert(3, '!')
snapshotReadXmlText.format(0, 3, { bold: true })
const snapshotReadAfter = Y.snapshot(snapshotReadDoc)

const snapshotReadFixture = snapshot => {
  const restored = Y.createDocFromSnapshot(snapshotReadDoc, snapshot)
  const restoredArray = restored.getArray('items')
  const restoredMap = restored.getMap('meta')
  const restoredText = restored.getText('content')
  const restoredXml = restored.getXmlFragment('xml')
  const restoredMapText = restoredMap.get('body')
  const restoredXmlParagraph = restoredXml.get(0)
  const restoredXmlText = restoredXmlParagraph.get(0)
  const restoredMapXmlFragment = restoredMap.get('xmlBody')
  const restoredMapXmlText = restoredMapXmlFragment.get(0)
  const restoredMapXmlElement = restoredMapXmlFragment.get(1)
  const restoredMapXmlElementText = restoredMapXmlElement.get(0)
  const restoredNestedXmlFragment = restoredArray.get(6)
  const restoredNestedXmlText = restoredNestedXmlFragment.get(0)
  const restoredNestedXmlElement = restoredNestedXmlFragment.get(1)
  const restoredNestedXmlElementText = restoredNestedXmlElement.get(0)

  return {
    array: normalizeValue(restoredArray.toJSON()),
    arrayLength: restoredArray.length,
    arrayFirst: normalizeValue(restoredArray.get(0) ?? null),
    arraySlice: normalizeValue(restoredArray.toJSON().slice(1, -1)),
    nestedArray: normalizeValue(restoredArray.get(3).toJSON()),
    nestedArrayLength: restoredArray.get(3).length,
    nestedArrayFirst: normalizeValue(restoredArray.get(3).get(0) ?? null),
    nestedArraySlice: normalizeValue(restoredArray.get(3).toArray().slice(1)),
    nestedMapAll: normalizeValue(restoredArray.get(4).toJSON()),
    nestedMapSize: restoredArray.get(4).size,
    nestedMapHasName: restoredArray.get(4).has('name'),
    nestedMapHasExtra: restoredArray.get(4).has('extra'),
    nestedMapName: normalizeValue(restoredArray.get(4).get('name') ?? null),
    nestedMapExtra: normalizeValue(restoredArray.get(4).get('extra') ?? null),
    nestedText: restoredArray.get(5).toString(),
    nestedTextDelta: normalizeValue(restoredArray.get(5).toDelta()),
    nestedXmlFragment: restoredNestedXmlFragment.toString(),
    nestedXmlFragmentLength: restoredNestedXmlFragment.length,
    nestedXmlFragmentFirstChild: restoredNestedXmlFragment.get(0).toString(),
    nestedXmlFragmentArray: restoredNestedXmlFragment.toArray().map(child => child.toString()),
    nestedXmlFragmentSlice: restoredNestedXmlFragment.toArray().slice(0, 2).map(child => child.toString()),
    nestedXmlElement: restoredNestedXmlElement.toString(),
    nestedXmlElementLength: restoredNestedXmlElement.length,
    nestedXmlElementFirstChild: restoredNestedXmlElement.get(0).toString(),
    nestedXmlElementSlice: restoredNestedXmlElement.toArray().slice(0, 1).map(child => child.toString()),
    nestedXmlAttributes: normalizeValue(restoredNestedXmlElement.getAttributes()),
    nestedXmlClass: normalizeValue(restoredNestedXmlElement.getAttribute('class') ?? null),
    nestedXmlText: restoredNestedXmlText.toString(),
    nestedXmlTextLength: restoredNestedXmlText.length,
    nestedXmlDelta: normalizeValue(restoredNestedXmlText.toDelta()),
    nestedXmlElementText: restoredNestedXmlElementText.toString(),
    nestedXmlElementTextLength: restoredNestedXmlElementText.length,
    nestedXmlElementDelta: normalizeValue(restoredNestedXmlElementText.toDelta()),
    mapAll: normalizeValue(restoredMap.toJSON()),
    mapSize: restoredMap.size,
    mapHasCount: restoredMap.has('count'),
    mapHasExtra: restoredMap.has('extra'),
    mapTitle: normalizeValue(restoredMap.get('title') ?? null),
    mapCount: normalizeValue(restoredMap.get('count') ?? null),
    mapExtra: normalizeValue(restoredMap.get('extra') ?? null),
    mapText: restoredMapText.toString(),
    mapTextLength: restoredMapText.toString().length,
    mapTextSlice: restoredMapText.toString().slice(3),
    mapTextDelta: normalizeValue(restoredMapText.toDelta()),
    mapTextAttributes: normalizeValue(restoredMapText.getAttributes()),
    mapTextLang: normalizeValue(restoredMapText.getAttribute('lang') ?? null),
    mapTextMark: normalizeValue(restoredMapText.getAttribute('mark') ?? null),
    mapTextHasLang: Object.hasOwn(restoredMapText.getAttributes(), 'lang'),
    mapTextHasMissing: Object.hasOwn(restoredMapText.getAttributes(), 'missing'),
    mapXmlFragment: restoredMapXmlFragment.toString(),
    mapXmlFragmentLength: restoredMapXmlFragment.length,
    mapXmlFragmentFirstChild: restoredMapXmlFragment.get(0).toString(),
    mapXmlFragmentArray: restoredMapXmlFragment.toArray().map(child => child.toString()),
    mapXmlFragmentSlice: restoredMapXmlFragment.toArray().slice(0, 2).map(child => child.toString()),
    mapXmlElement: restoredMapXmlElement.toString(),
    mapXmlElementLength: restoredMapXmlElement.length,
    mapXmlElementFirstChild: restoredMapXmlElement.get(0).toString(),
    mapXmlElementSlice: restoredMapXmlElement.toArray().slice(0, 1).map(child => child.toString()),
    mapXmlAttributes: normalizeValue(restoredMapXmlElement.getAttributes()),
    mapXmlClass: normalizeValue(restoredMapXmlElement.getAttribute('class') ?? null),
    mapXmlText: restoredMapXmlText.toString(),
    mapXmlTextLength: restoredMapXmlText.length,
    mapXmlDelta: normalizeValue(restoredMapXmlText.toDelta()),
    mapXmlElementText: restoredMapXmlElementText.toString(),
    mapXmlElementTextLength: restoredMapXmlElementText.length,
    mapXmlElementDelta: normalizeValue(restoredMapXmlElementText.toDelta()),
    text: restoredText.toString(),
    textLength: restoredText.toString().length,
    textSlice: restoredText.toString().slice(1, -1),
    textDelta: normalizeValue(restoredText.toDelta()),
    nestedTextLength: restoredArray.get(5).toString().length,
    nestedTextSlice: restoredArray.get(5).toString().slice(6),
    xml: restoredXml.toString(),
    xmlLength: restoredXml.length,
    xmlFirstChild: restoredXml.get(0).toString(),
    xmlSlice: restoredXml.toArray().slice(0, 1).map(child => child.toString()),
    xmlElement: restoredXmlParagraph.toString(),
    xmlElementLength: restoredXmlParagraph.length,
    xmlElementFirstChild: restoredXmlParagraph.get(0).toString(),
    xmlElementSlice: restoredXmlParagraph.toArray().slice(0, 1).map(child => child.toString()),
    xmlAttributes: normalizeValue(restoredXmlParagraph.getAttributes()),
    xmlClass: normalizeValue(restoredXmlParagraph.getAttribute('class') ?? null),
    xmlText: restoredXmlText.toString(),
    xmlTextLength: restoredXmlText.length,
    xmlDelta: normalizeValue(restoredXmlText.toDelta())
  }
}

const snapshotFixtures = {
  snapshotV1: toBase64(snapshotEncodedV1),
  snapshotV2: toBase64(snapshotEncodedV2),
  decodedV1: normalizeSnapshot(snapshotDecodedV1),
  decodedV2: normalizeSnapshot(snapshotDecodedV2),
  emptyV1: toBase64(emptySnapshotEncodedV1),
  emptyV2: toBase64(emptySnapshotEncodedV2),
  emptyDecodedV1: normalizeSnapshot(Y.decodeSnapshot(emptySnapshotEncodedV1)),
  emptyDecodedV2: normalizeSnapshot(Y.decodeSnapshotV2(emptySnapshotEncodedV2)),
  equalDecoded: Y.equalSnapshots(snapshotDecodedV1, snapshotDecodedV2),
  equalAltered: Y.equalSnapshots(snapshotValue, alteredSnapshot),
  contains: {
    containedUpdateV1: toBase64(snapshotContainedUpdateV1),
    containedUpdateV2: toBase64(snapshotContainedUpdateV2),
    contained: Y.snapshotContainsUpdate(snapshotValue, snapshotContainedUpdateV1),
    futureUpdateV1: toBase64(snapshotFutureUpdateV1),
    futureUpdateV2: toBase64(snapshotFutureUpdateV2),
    future: Y.snapshotContainsUpdate(snapshotValue, snapshotFutureUpdateV1),
    extraDeleteUpdateV1: toBase64(snapshotExtraDeleteUpdateV1),
    extraDeleteUpdateV2: toBase64(snapshotExtraDeleteUpdateV2),
    extraDelete: Y.snapshotContainsUpdate(snapshotValue, snapshotExtraDeleteUpdateV1)
  },
  restore: {
    sourceUpdateV1: toBase64(Y.encodeStateAsUpdate(snapshotRestoreDoc)),
    sourceUpdateV2: toBase64(Y.encodeStateAsUpdateV2(snapshotRestoreDoc)),
    beforeDeleteSnapshotV1: toBase64(Y.encodeSnapshot(snapshotRestoreBeforeDelete)),
    afterDeleteSnapshotV1: toBase64(Y.encodeSnapshot(snapshotRestoreAfterDelete)),
    partialSnapshotV1: toBase64(Y.encodeSnapshot(snapshotRestorePartial)),
    beforeDeleteJson: normalizeValue(restoredBeforeDeleteDoc.toJSON()),
    afterDeleteJson: normalizeValue(restoredAfterDeleteDoc.toJSON()),
    partialJson: normalizeValue(restoredPartialDoc.toJSON()),
    beforeDeleteStateVectorV1: toBase64(Y.encodeStateVector(restoredBeforeDeleteDoc)),
    afterDeleteStateVectorV1: toBase64(Y.encodeStateVector(restoredAfterDeleteDoc)),
    partialStateVectorV1: toBase64(Y.encodeStateVector(restoredPartialDoc))
  },
  reads: {
    sourceUpdateV1: toBase64(Y.encodeStateAsUpdate(snapshotReadDoc)),
    sourceUpdateV2: toBase64(Y.encodeStateAsUpdateV2(snapshotReadDoc)),
    beforeSnapshotV1: toBase64(Y.encodeSnapshot(snapshotReadBefore)),
    afterSnapshotV1: toBase64(Y.encodeSnapshot(snapshotReadAfter)),
    before: snapshotReadFixture(snapshotReadBefore),
    after: snapshotReadFixture(snapshotReadAfter)
  }
}

fs.writeFileSync(
  path.join(outDir, 'snapshots.json'),
  `${JSON.stringify(snapshotFixtures, null, 2)}\n`
)
