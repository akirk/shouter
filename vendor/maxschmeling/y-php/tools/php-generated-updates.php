<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yjs\YDoc;
use Yjs\UndoManager;

/**
 * @param callable(YDoc): void $mutate
 * @param null|callable(YDoc): array<string, mixed> $inspect
 * @return array<string, mixed>
 */
function update_case(string $name, string $type, int $clientID, callable $mutate, ?callable $inspect = null): array
{
    return update_case_with_doc($name, $type, new YDoc($clientID), $mutate, $inspect);
}

/**
 * @param callable(YDoc): void $mutate
 * @param null|callable(YDoc): array<string, mixed> $inspect
 * @return array<string, mixed>
 */
function update_case_with_doc(string $name, string $type, YDoc $doc, callable $mutate, ?callable $inspect = null): array
{
    $updatesV1 = [];
    $updatesV2 = [];
    $doc->observeUpdate(static function (string $update) use (&$updatesV1): void {
        $updatesV1[] = base64_encode($update);
    });
    $doc->observeUpdateV2(static function (string $update) use (&$updatesV2): void {
        $updatesV2[] = base64_encode($update);
    });

    $mutate($doc);

    $decoded = Yjs\Update\DecodedUpdate::decodeV1($doc->encodeStateAsUpdateV1());

    $counts = decoded_struct_counts($decoded['structs']);

    $case = [
        'name' => $name,
        'type' => $type,
        'updateV1' => base64_encode($doc->encodeStateAsUpdateV1()),
        'updateV2' => base64_encode($doc->encodeStateAsUpdateV2()),
        'incrementalUpdatesV1' => $updatesV1,
        'incrementalUpdatesV2' => $updatesV2,
        'stateVectorV1' => base64_encode($doc->encodeStateVector()),
        'json' => $doc->toJSON(),
        'deleteSet' => $decoded['deleteSet'],
    ] + $counts;

    if ($inspect !== null) {
        $case += $inspect($doc);
    }

    return $case;
}

/**
 * @param list<array<string, mixed>> $structs
 * @param array<int, list<array{clock: int, length: int}>> $deleteSet
 * @return array<string, mixed>
 */
function raw_update_case(string $name, string $type, array $structs, array $deleteSet = []): array
{
    $updateV1 = Yjs\Update\DecodedUpdate::encodeV1($structs, $deleteSet);
    $updateV2 = Yjs\Update\DecodedUpdate::encodeV2($structs, $deleteSet);
    $doc = new YDoc();
    $doc->applyUpdateV1($updateV1);
    $decoded = Yjs\Update\DecodedUpdate::decodeV1($updateV1);

    return [
        'name' => $name,
        'type' => $type,
        'updateV1' => base64_encode($updateV1),
        'updateV2' => base64_encode($updateV2),
        'incrementalUpdatesV1' => [base64_encode($updateV1)],
        'incrementalUpdatesV2' => [base64_encode($updateV2)],
        'stateVectorV1' => base64_encode($doc->encodeStateVector()),
        'json' => $doc->toJSON(),
        'deleteSet' => $decoded['deleteSet'],
    ] + decoded_struct_counts($decoded['structs']);
}

/**
 * @param list<array<string, mixed>> $structs
 * @param array<int, list<array{clock: int, length: int}>> $deleteSet
 * @return array<string, mixed>
 */
function decoded_struct_case(string $name, array $structs, array $deleteSet = []): array
{
    $updateV1 = Yjs\Update\DecodedUpdate::encodeV1($structs, $deleteSet);
    $updateV2 = Yjs\Update\DecodedUpdate::encodeV2($structs, $deleteSet);
    $decoded = Yjs\Update\DecodedUpdate::decodeV1($updateV1);

    return [
        'name' => $name,
        'updateV1' => base64_encode($updateV1),
        'updateV2' => base64_encode($updateV2),
        'deleteSet' => $decoded['deleteSet'],
    ] + decoded_struct_counts($decoded['structs']);
}

/**
 * @param list<array<string, mixed>> $structs
 * @return array<string, int>
 */
function decoded_struct_counts(array $structs): array
{
    return [
        'contentAnyStructCount' => count(array_filter(
            $structs,
            static fn (array $struct): bool => ($struct['content']['type'] ?? null) === 'ContentAny'
        )),
        'formatStructCount' => count(array_filter(
            $structs,
            static fn (array $struct): bool => ($struct['content']['type'] ?? null) === 'ContentFormat'
        )),
        'embedStructCount' => count(array_filter(
            $structs,
            static fn (array $struct): bool => ($struct['content']['type'] ?? null) === 'ContentEmbed'
        )),
        'binaryStructCount' => count(array_filter(
            $structs,
            static fn (array $struct): bool => ($struct['content']['type'] ?? null) === 'ContentBinary'
        )),
        'jsonStructCount' => count(array_filter(
            $structs,
            static fn (array $struct): bool => ($struct['content']['type'] ?? null) === 'ContentJSON'
        )),
        'docStructCount' => count(array_filter(
            $structs,
            static fn (array $struct): bool => ($struct['content']['type'] ?? null) === 'ContentDoc'
        )),
        'contentDeletedStructCount' => count(array_filter(
            $structs,
            static fn (array $struct): bool => ($struct['content']['type'] ?? null) === 'ContentDeleted'
        )),
        'gcStructCount' => count(array_filter(
            $structs,
            static fn (array $struct): bool => ($struct['type'] ?? null) === 'GC'
        )),
        'skipStructCount' => count(array_filter(
            $structs,
            static fn (array $struct): bool => ($struct['type'] ?? null) === 'Skip'
        )),
    ];
}

/**
 * @return array<string, mixed>
 */
function concurrent_fixture_update_case(string $name, string $type, string $fixtureName): array
{
    $case = concurrent_fixture_case($fixtureName);

    return update_case($name, $type, 401, static function (YDoc $doc) use ($case): void {
        foreach ($case['updatesV1'] as $encodedUpdate) {
            $update = base64_decode((string) $encodedUpdate, true);
            if (! is_string($update)) {
                throw new RuntimeException('Invalid concurrent fixture update.');
            }

            $doc->applyUpdateV1($update);
        }
    }, static function (YDoc $doc) use ($case): array {
        $metadata = [];

        if (array_key_exists('xmlTextAttributes', $case)) {
            $xmlText = $doc->getXmlFragment('xml')->get(0);
            if (! $xmlText instanceof Yjs\YXmlText) {
                throw new RuntimeException('Expected root XML text node.');
            }

            $attributes = $xmlText->getAttributes();
            $metadata['xmlTextAttributes'] = $attributes === [] ? new stdClass() : $attributes;
        }

        return $metadata;
    });
}

/**
 * @return array<string, mixed>
 */
function concurrent_fixture_case(string $fixtureName): array
{
    $fixturePath = __DIR__ . '/../fixtures/generated/yjs-13.6.31/concurrent-v1.json';
    $decoded = json_decode((string) file_get_contents($fixturePath), true);
    if (! is_array($decoded) || ! isset($decoded['cases']) || ! is_array($decoded['cases'])) {
        throw new RuntimeException('Unable to read concurrent update fixtures.');
    }

    foreach ($decoded['cases'] as $case) {
        if (($case['name'] ?? null) !== $fixtureName) {
            continue;
        }

        return $case;
    }

    throw new RuntimeException(sprintf('Concurrent fixture "%s" was not found.', $fixtureName));
}

/**
 * @param callable(YDoc): void $buildPrefix
 * @param callable(YDoc): void $buildFull
 * @param null|callable(YDoc): array<string, mixed> $inspect
 * @return array<string, mixed>
 */
function partial_diff_case(string $name, string $type, int $clientID, callable $buildPrefix, callable $buildFull, ?callable $inspect = null): array
{
    $prefix = new YDoc($clientID);
    $buildPrefix($prefix);

    $full = new YDoc($clientID);
    $buildFull($full);

    $targetStateVector = $prefix->encodeStateVector();

    $case = [
        'name' => $name,
        'type' => $type,
        'prefixUpdateV1' => base64_encode($prefix->encodeStateAsUpdateV1()),
        'prefixUpdateV2' => base64_encode($prefix->encodeStateAsUpdateV2()),
        'diffV1' => base64_encode($full->encodeStateAsUpdateV1($targetStateVector)),
        'diffV2' => base64_encode($full->encodeStateAsUpdateV2($targetStateVector)),
        'targetStateVectorV1' => base64_encode($targetStateVector),
        'json' => $full->toJSON(),
    ];

    if ($inspect !== null) {
        $case += $inspect($full);
    }

    return $case;
}

/**
 * @param null|callable(YDoc): array<string, mixed> $inspect
 * @return array<string, mixed>
 */
function partial_diff_case_with_docs(string $name, string $type, YDoc $prefix, YDoc $full, ?callable $inspect = null): array
{
    $targetStateVector = $prefix->encodeStateVector();

    $case = [
        'name' => $name,
        'type' => $type,
        'prefixUpdateV1' => base64_encode($prefix->encodeStateAsUpdateV1()),
        'prefixUpdateV2' => base64_encode($prefix->encodeStateAsUpdateV2()),
        'diffV1' => base64_encode($full->encodeStateAsUpdateV1($targetStateVector)),
        'diffV2' => base64_encode($full->encodeStateAsUpdateV2($targetStateVector)),
        'targetStateVectorV1' => base64_encode($targetStateVector),
        'json' => $full->toJSON(),
    ];

    if ($inspect !== null) {
        $case += $inspect($full);
    }

    return $case;
}

$cases = [
    update_case('php-text-middle-insert', 'text', 201, static function (YDoc $doc): void {
        $text = $doc->getText('content');
        $text->insert(0, 'HY');
        $text->insert(1, 'i');
    }),
    update_case('php-text-start-insert', 'text', 202, static function (YDoc $doc): void {
        $text = $doc->getText('content');
        $text->insert(0, 'Y');
        $text->insert(0, 'X');
    }),
    update_case('php-text-utf16-insert', 'text', 203, static function (YDoc $doc): void {
        $text = $doc->getText('content');
        $text->insert(0, 'A😀C');
        $text->insert(3, 'B');
    }),
    update_case('php-text-formatting', 'text', 210, static function (YDoc $doc): void {
        $text = $doc->getText('content');
        $text->insert(0, 'Hi', ['bold' => true]);
        $text->insert(2, ' there');
        $text->format(3, 5, ['italic' => true]);
    }),
    update_case('php-text-explicit-insert-clears-active-format', 'text', 264, static function (YDoc $doc): void {
        $text = $doc->getText('content');
        $text->insert(0, 'MapText');
        $text->format(0, 3, ['bold' => true]);
        $text->delete(3, 4);
        $text->insert(3, ' body', ['italic' => true]);
    }, static function (YDoc $doc): array {
        return ['textDelta' => $doc->getText('content')->toDelta()];
    }),
    update_case('php-text-embed', 'text', 211, static function (YDoc $doc): void {
        $text = $doc->getText('content');
        $text->insert(0, 'A');
        $text->insertEmbed(1, ['image' => 'cat.png'], ['alt' => 'Cat']);
        $text->insert(2, 'B');
    }),
    update_case('php-text-apply-delta', 'text', 225, static function (YDoc $doc): void {
        $text = $doc->getText('content');
        $text->applyDelta([
            ['insert' => 'Hello', 'attributes' => ['bold' => true]],
            ['insert' => ' world'],
        ]);
        $text->applyDelta([
            ['retain' => 6],
            ['delete' => 5],
            ['insert' => 'Yjs', 'attributes' => ['italic' => true]],
            ['insert' => ['image' => 'cat.png'], 'attributes' => ['alt' => 'Cat']],
        ]);
    }),
    update_case_with_doc('php-doc-set-client-id', 'text', (static function (): YDoc {
        $doc = new YDoc(null, 'php-generated-guid');
        $doc->setClientID(226);

        return $doc;
    })(), static function (YDoc $doc): void {
        $doc->getText('content')->insert(0, 'ID');
    }),
    update_case_with_doc('php-gc-text-delete-content-deleted', 'text', new YDoc(248, gc: true), static function (YDoc $doc): void {
        $text = $doc->getText('content');
        $text->insert(0, 'ABCD');
        $text->delete(1, 2);
    }),
    update_case('php-array-middle-insert', 'array', 204, static function (YDoc $doc): void {
        $array = $doc->getArray('array');
        $array->insert(0, ['A', 'C']);
        $array->insert(1, ['B']);
    }),
    update_case('php-array-delete', 'array', 205, static function (YDoc $doc): void {
        $array = $doc->getArray('array');
        $array->insert(0, ['A', 'B', 'C', 'D']);
        $array->delete(1, 2);
    }),
    update_case_with_doc('php-gc-array-delete-content-deleted', 'array', new YDoc(249, gc: true), static function (YDoc $doc): void {
        $array = $doc->getArray('array');
        $array->insert(0, ['A', 'B', 'C', 'D']);
        $array->delete(1, 2);
    }),
    update_case_with_doc('php-gc-nested-text-delete-content-deleted', 'array', new YDoc(253, gc: true), static function (YDoc $doc): void {
        $text = $doc->getArray('array')->insertText(0);
        $text->insert(0, 'ABCD');
        $text->delete(1, 2);
    }),
    update_case_with_doc('php-gc-nested-array-delete-content-deleted', 'array', new YDoc(254, gc: true), static function (YDoc $doc): void {
        $array = $doc->getArray('array')->insertArray(0);
        $array->insert(0, ['A', 'B', 'C', 'D']);
        $array->delete(1, 2);
    }),
    update_case_with_doc('php-gc-nested-map-replace-content-deleted', 'array', new YDoc(255, gc: true), static function (YDoc $doc): void {
        $map = $doc->getArray('array')->insertMap(0);
        $map->set('title', 'Draft');
        $map->set('title', 'Published');
    }),
    update_case('php-array-delete-target-reinsert', 'array', 240, static function (YDoc $doc): void {
        $array = $doc->getArray('array');
        $array->insert(0, ['A', 'B', 'C']);
        $array->delete(1, 1);
        $array->insert(1, ['X']);
    }),
    update_case('php-native-convenience-apis', 'mixed', 224, static function (YDoc $doc): void {
        $array = $doc->getArray('array');
        $array->push(['B']);
        $array->unshift(['A']);
        $array->push(['C']);
        $array->pop();
        $array->shift();
        $array->unshift(['A']);
        $nestedArray = $array->insertArray(2);
        $nestedArray->push([2]);
        $nestedArray->unshift([1]);
        $nestedArray->pop();
        $nestedArray->shift();
        $nestedArray->unshift([1]);
        $nestedArray->push([2]);
        $nestedArray->clear();
        $nestedArray->pop();
        $nestedArray->shift();
        $nestedArray->push(['reset']);
        $array->push(['remove']);
        $array->delete(3);

        $map = $doc->getMap('map');
        $map->set('a', 1);
        $map->set('b', 2);
        $map->clear();
        $nestedMap = $map->setMap('nested');
        $nestedMap->set('temp', false);
        $nestedMap->clear();
        $nestedMap->set('ok', true);
        $map->set('after', 'clear');
    }),
    update_case('php-native-array-splice-apis', 'array', 242, static function (YDoc $doc): void {
        $array = $doc->getArray('array');
        $array->insert(0, ['A', 'B', 'C', 'D']);
        $array->splice(1, 2, ['X', 'Y']);
        $array->splice(-1, 10, ['Z']);
        $array->splice(99, 1, ['tail']);

        $nested = $array->insertArray(5);
        $nested->insert(0, [1, 2, 3, 4]);
        $nested->splice(1, 2, ['nested']);
    }),
    update_case('php-native-bulk-map-xml-apis', 'map-xml', 239, static function (YDoc $doc): void {
        $map = $doc->getMap('map');
        $map->setAll(['title' => 'Draft', 'temp' => true, 'count' => 1]);
        $map->deleteAll(['temp']);
        $nestedMap = $map->setMap('nested');
        $nestedMap->setAll(['status' => 'draft', 'remove' => true]);
        $nestedMap->deleteAll(['remove']);
        $nestedMap->setAll(['status' => 'ready']);

        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $paragraph->setAttributes(['class' => 'lead', 'data-temp' => 'remove-me']);
        $paragraph->removeAttributes(['data-temp']);
        $paragraph->insertText(0, 'Bulk');
        $hook = $doc->getXmlFragment('xml')->insertHook(1, 'mention');
        $hook->setAttributes(['id' => 7, 'label' => 'Ada', 'temp' => true]);
        $hook->removeAttributes(['temp']);
    }, static function (YDoc $doc): array {
        $hook = $doc->getXmlFragment('xml')->get(1);
        if (! $hook instanceof Yjs\YXmlHook) {
            throw new \RuntimeException('Expected XML hook mention node.');
        }

        return [
            'xmlHookChildren' => [
                [
                    'index' => 1,
                    'hookName' => $hook->hookName(),
                    'json' => $hook->toJSON(),
                ],
            ],
        ];
    }),
    update_case('php-native-array-map-xml-shared-types', 'mixed', 266, static function (YDoc $doc): void {
        $array = $doc->getArray('array');
        $paragraph = $array->insertXmlElement(0, 'p');
        $paragraph->insertText(0, 'Hi');
        $array->appendXmlText('Text');
        $hook = $array->appendXmlHook('mention');
        $hook->set('id', 1);

        $map = $doc->getMap('map');
        $section = $map->setXmlElement('xml', 'section');
        $section->insertText(0, 'Map');
        $map->setXmlText('text', 'Map text');
        $mapHook = $map->setXmlHook('hook', 'note');
        $mapHook->set('ok', true);
    }),
    update_case('php-native-xml-fragment-shared-types', 'mixed', 287, static function (YDoc $doc): void {
        $array = $doc->getArray('array');
        $fragment = $array->insertXmlFragment(0);
        $paragraph = $fragment->appendElement('p');
        $paragraph->insertText(0, 'Array');
        $fragment->appendText(' tail');

        $nested = $array->appendArray();
        $nestedFragment = $nested->insertXmlFragment(0);
        $nestedFragment->push(['Nested']);
        $nestedFragment->appendElement('br');

        $nestedMap = $array->appendMap();
        $nestedMapFragment = $nestedMap->setXmlFragment('xml');
        $nestedMapFragment->appendText('Nested map');
        $hook = $nestedMapFragment->appendHook('note');
        $hook->set('ok', true);

        $map = $doc->getMap('map');
        $mapFragment = $map->setXmlFragment('xml');
        $section = $mapFragment->appendElement('section');
        $section->insertText(0, 'Map');
    }, static fn (YDoc $doc): array => [
        'arrayXmlFragments' => [
            ['index' => 0, 'xml' => '<p>Array</p> tail'],
        ],
    ]),
    update_case('php-native-xml-insert-after-apis', 'xml', 248, static function (YDoc $doc): void {
        $fragment = $doc->getXmlFragment('xml');
        $fragment->insertAfter(null, ['lead']);
        $lead = $fragment->get(0);
        if (! $lead instanceof Yjs\YXmlText) {
            throw new \RuntimeException('Expected XML text lead node.');
        }

        $article = $fragment->insertElementAfter($lead, 'article');
        $tail = $fragment->insertTextAfter($article, 'tail');
        $fragment->insertHookAfter($tail, 'mention');
        $strong = $article->insertElementAfter(null, 'strong');
        $strong->appendText('B');
        $article->insertAfter($strong, ['middle']);
        $middle = $article->get(1);
        if (! $middle instanceof Yjs\YXmlText) {
            throw new \RuntimeException('Expected XML text middle node.');
        }

        $article->insertHookAfter($middle, 'note');
        $note = $article->get(2);
        if (! $note instanceof Yjs\YXmlHook) {
            throw new \RuntimeException('Expected XML hook note node.');
        }

        $article->insertTextAfter($note, 'end');
    }),
    update_case('php-native-xml-bulk-text-apis', 'map-xml', 288, static function (YDoc $doc): void {
        $fragment = $doc->getXmlFragment('xml');
        $fragment->prependTexts(['B', 'C']);
        $fragment->insertTexts(1, ['x', 'y']);
        $fragment->appendTexts(['tail']);
        $paragraph = $fragment->appendElement('p');
        $paragraph->appendTexts(['A', 'C']);
        $paragraph->insertTexts(1, ['B']);
        $paragraph->prependTexts(['0']);

        $mapFragment = $doc->getMap('map')->setXmlFragment('xml');
        $mapFragment->appendTexts(['M', 'P']);
        $mapFragment->insertTexts(1, ['N', 'O']);
        $mapFragment->prependTexts(['L']);
    }, static fn (YDoc $doc): array => [
        'mapXmlFragments' => [
            ['key' => 'xml', 'xml' => 'LMNOP'],
        ],
    ]),
    update_case('php-native-xml-bulk-text-after-apis', 'map-xml', 289, static function (YDoc $doc): void {
        $fragment = $doc->getXmlFragment('xml');
        $lead = $fragment->appendText('lead');
        $fragment->insertTextsAfter($lead, ['A', 'B']);
        $paragraph = $fragment->appendElement('p');
        $paragraph->appendText('P');
        $paragraph->insertTextsAfter(null, ['0', '1']);
        $middle = $paragraph->get(1);
        if (! $middle instanceof Yjs\YXmlText) {
            throw new \RuntimeException('Expected XML text middle node.');
        }

        $paragraph->insertTextsAfter($middle, ['2', '3']);

        $mapFragment = $doc->getMap('map')->setXmlFragment('xml');
        $start = $mapFragment->appendText('M');
        $mapFragment->insertTextsAfter($start, ['N', 'O']);
    }, static fn (YDoc $doc): array => [
        'mapXmlFragments' => [
            ['key' => 'xml', 'xml' => 'MNO'],
        ],
    ]),
    update_case('php-native-xml-text-attribute-apis', 'xml', 249, static function (YDoc $doc): void {
        $xmlText = $doc->getXmlFragment('xml')->insertText(0, 'Xml');
        $xmlText->setAttribute('lang', 'en');
        $xmlText->setAttributes(['lang' => 'fr', 'mark' => ['color' => 'blue'], 'temporary' => true]);
        $xmlText->removeAttributes(['temporary']);
    }, static function (YDoc $doc): array {
        $xmlText = $doc->getXmlFragment('xml')->get(0);
        if (! $xmlText instanceof Yjs\YXmlText) {
            throw new \RuntimeException('Expected XML text node.');
        }

        return ['xmlTextAttributes' => $xmlText->getAttributes()];
    }),
    update_case('php-native-xml-text-shared-attribute-apis', 'xml', 334, static function (YDoc $doc): void {
        $xmlText = $doc->getXmlFragment('xml')->insertText(0, 'Xml');
        $body = $xmlText->setText('body');
        $body->insert(0, 'Text body');
        $items = $xmlText->setArray('items');
        $items->insert(0, ['A', 'B']);
        $meta = $xmlText->setMap('meta');
        $meta->set('role', 'caption');
        $inline = $xmlText->setXmlElement('inline', 'span');
        $inline->appendText('Inline');
        $xmlText->setXmlText('label', 'Xml label');
        $hook = $xmlText->setXmlHook('hook', 'note');
        $hook->set('ok', true);
        $fragment = $xmlText->setXmlFragment('fragment');
        $fragment->appendText('Frag');
    }, static function (YDoc $doc): array {
        $xmlText = $doc->getXmlFragment('xml')->get(0);
        if (! $xmlText instanceof Yjs\YXmlText) {
            throw new \RuntimeException('Expected XML text node.');
        }

        return ['xmlTextAttributes' => $xmlText->getAttributes()];
    }),
    update_case('php-native-array-access-apis', 'mixed', 229, static function (YDoc $doc): void {
        $array = $doc->getArray('array');
        $array[] = 'A';
        $array[] = 'B';
        $array[1] = 'C';
        $array[2] = 'D';
        unset($array[0]);

        $nestedArray = $array->insertArray(2);
        $nestedArray[] = 1;
        $nestedArray[] = 3;
        $nestedArray[1] = 2;
        unset($nestedArray[0]);

        $map = $doc->getMap('map');
        $map['title'] = 'Draft';
        $map['count'] = 1;
        $map['title'] = 'Published';
        $map['present'] = null;
        unset($map['count']);

        $nestedMap = $map->setMap('nested');
        $nestedMap['flag'] = false;
        $nestedMap['flag'] = true;
        $nestedMap['remove'] = 'gone';
        unset($nestedMap['remove']);
    }),
    update_case('php-cross-type-transaction-mutations', 'all', 232, static function (YDoc $doc): void {
        $doc->transact(static function (YDoc $doc): void {
            $text = $doc->getText('content');
            $text->insert(0, 'Hello');
            $text->format(0, 5, ['bold' => true]);
            $text->insertEmbed(5, ['kind' => 'separator'], ['block' => true]);
            $text->insert(6, 'Yjs');

            $array = $doc->getArray('array');
            $array->insert(0, ['root']);
            $array->insertBinary(1, "\x10\x20");
            $array->insertSubdoc(2, 'php-cross-subdoc', ['meta' => ['from' => 'array']]);
            $nestedArray = $array->insertArray(3);
            $nestedArray->insert(0, ['nested', 1]);
            $nestedMap = $nestedArray->insertMap(2);
            $nestedMap->set('flag', true);
            $nestedText = $nestedMap->setText('body');
            $nestedText->insert(0, 'Nested');
            $nestedText->format(0, 6, ['italic' => true]);

            $map = $doc->getMap('map');
            $map->set('title', 'Cross');
            $map->setBinary('bytes', "\x01\x02\x03");
            $map->setSubdoc('child', 'php-cross-map-subdoc', ['autoLoad' => true]);
            $mapArray = $map->setArray('items');
            $mapArray->insert(0, ['A', 'C']);
            $mapArray->insert(1, ['B']);
            $mapMap = $map->setMap('meta');
            $mapMap->set('count', 3);

            $fragment = $doc->getXmlFragment('xml');
            $paragraph = $fragment->insertElement(0, 'p');
            $paragraph->setAttribute('class', 'cross');
            $paragraphText = $paragraph->insertText(0, 'XML');
            $paragraphText->format(0, 3, ['mark' => 'x']);
            $paragraph->appendElement('br');
        }, 'php-cross-type');
    }, static fn (YDoc $doc): array => [
        'subdocs' => [
            [
                'root' => 'array',
                'path' => [2],
                'guid' => 'php-cross-subdoc',
                'meta' => ['from' => 'array'],
                'shouldLoad' => false,
            ],
            [
                'root' => 'map',
                'path' => ['child'],
                'guid' => 'php-cross-map-subdoc',
                'meta' => null,
                'shouldLoad' => true,
            ],
        ],
    ]),
    update_case('php-undo-manager-root-nested-xml-redo', 'all', 238, static function (YDoc $doc): void {
        $doc->getText('content')->insert(0, 'Seed');
        $doc->getArray('array')->insert(0, ['A']);
        $doc->getMap('map')->set('title', 'Draft');
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $paragraph->setAttribute('class', 'draft');
        $xmlText = $paragraph->insertText(0, 'Xml');
        $paragraph->appendElement('br');
        $nestedArray = $doc->getArray('array')->insertArray(1);
        $nestedMap = $doc->getArray('array')->insertMap(2);
        $nestedText = $doc->getArray('array')->insertText(3);
        $nestedArray->insert(0, ['nested-a']);
        $nestedMap->set('status', 'draft');
        $nestedText->insert(0, 'Nested');
        $undoManager = new UndoManager($doc, [
            'content',
            'map',
            $nestedArray->idKey(),
            $nestedMap->idKey(),
            $nestedText->idKey(),
            $xmlText->idKey(),
            $paragraph->idKey(),
        ], ['undoable-edit']);

        $doc->transact(static function () use ($doc, $nestedArray, $nestedMap, $nestedText, $xmlText, $paragraph): void {
            $doc->getText('content')->insert(4, '!');
            $doc->getMap('map')->set('title', 'Published');
            $nestedArray->push(['nested-b']);
            $nestedMap->set('status', 'ready');
            $nestedText->insert(6, '!');
            $xmlText->insert(3, '!');
            $paragraph->setAttribute('class', 'published');
            $paragraph->setAttribute('data-id', '7');
        }, 'undoable-edit');

        $undoManager->undo();
        $undoManager->redo();
    }),
    update_case('php-native-append-prepend-apis', 'all', 231, static function (YDoc $doc): void {
        $array = $doc->getArray('array');
        $array->append('B');
        $array->prepend('A');
        $array->prependBinary("\x01");
        $array->appendBinary("\x02");
        $array->prependSubdoc('php-prepend-subdoc', ['meta' => ['position' => 'first']]);
        $array->appendSubdoc('php-append-subdoc', ['meta' => ['position' => 'last']]);
        $array->prependText()->append('lead');
        $array->prependMap()->set('side', 'start');
        $nestedArray = $array->appendArray();
        $nestedArray->append(2);
        $nestedArray->prepend(1);
        $nestedArray->prependBinary("\x03");
        $nestedArray->appendBinary("\x04");
        $nestedArray->prependSubdoc('php-nested-prepend-subdoc', ['meta' => ['position' => 'first']]);
        $nestedArray->appendSubdoc('php-nested-append-subdoc', ['meta' => ['position' => 'last']]);
        $nestedArray->prependMap()->set('before', true);
        $nestedArray->appendText()->append('deep');
        $nestedArray->appendMap()->set('after', true);
        $nestedArray->prependArray()->append('inner-head');
        $nestedArray->prependText()->append('inner-text');
        $nestedText = $array->appendText();
        $nestedText->append('Nested');
        $nestedText->prepend('>');
        $array->appendMap()->set('side', 'end');

        $text = $doc->getText('content');
        $text->append('B');
        $text->prepend('A');
        $text->append('C', ['bold' => true]);

        $doc->getMap('map')->set('ok', true);

        $xmlText = $doc->getXmlFragment('xml')->insertText(0, '');
        $xmlText->append('Xml');
        $xmlText->prepend('>');
        $section = $doc->getXmlFragment('xml')->appendElement('section');
        $section->appendText('B');
        $section->prependText('A');
        $section->appendElement('strong')->appendText('C');
        $section->prependElement('em')->appendText('D');
        $section->appendHook('after');
        $section->prependHook('before');
        $doc->getXmlFragment('xml')->prependText('lead');
        $doc->getXmlFragment('xml')->appendHook('end');
        $doc->getXmlFragment('xml')->prependHook('start');
    }),
    update_case('php-native-text-splice-apis', 'text-array-xml', 261, static function (YDoc $doc): void {
        $text = $doc->getText('content');
        $text->insert(0, 'A😀BC');
        $text->splice(1, 2, 'x', ['bold' => true]);
        $text->splice(-1, 1, 'Z');
        $text->splice(99, 1, '!');

        $nestedText = $doc->getArray('array')->insertText(0);
        $nestedText->insert(0, 'N😀ST');
        $nestedText->splice(1, 2, 'y', ['italic' => true]);
        $nestedText->splice(-1, 1, '?');

        $xmlText = $doc->getXmlFragment('xml')->insertText(0, 'Z😀WX');
        $xmlText->splice(1, 2, 'q', ['mark' => true]);
        $xmlText->splice(-1, 1, '.');
    }),
    update_case('php-native-text-attribute-apis', 'text', 250, static function (YDoc $doc): void {
        $text = $doc->getText('content');
        $text->insert(0, 'Text');
        $text->setAttribute('lang', 'en');
        $text->setAttributes(['lang' => 'fr', 'mark' => ['color' => 'green'], 'temporary' => true]);
        $text->removeAttributes(['temporary']);
    }, static function (YDoc $doc): array {
        return ['textAttributes' => $doc->getText('content')->getAttributes()];
    }),
    (static function (): array {
        $nestedText = null;

        return update_case('php-native-nested-text-attribute-apis', 'array', 262, static function (YDoc $doc) use (&$nestedText): void {
            $nestedText = $doc->getArray('array')->insertText(0);
            $nestedText->insert(0, 'Nested');
            $nestedText->setAttribute('lang', 'en');
            $nestedText->setAttributes(['lang' => 'fr', 'mark' => ['color' => 'green'], 'temporary' => true]);
            $nestedText->removeAttributes(['temporary']);
        }, static function (YDoc $doc) use (&$nestedText): array {
            if (! $nestedText instanceof Yjs\YNestedText) {
                throw new \RuntimeException('Expected nested text node.');
            }

            return ['nestedTextAttributes' => $nestedText->getAttributes()];
        });
    })(),
    update_case('php-native-map-text-attribute-apis', 'map', 263, static function (YDoc $doc): void {
        $text = $doc->getMap('map')->setText('body');
        $text->insert(0, 'Map text');
        $text->setAttribute('lang', 'en');
        $text->setAttributes(['lang' => 'fr', 'mark' => ['color' => 'purple'], 'temporary' => true]);
        $text->removeAttributes(['temporary']);
        $text->insert(8, '!', ['emphasis' => true]);
    }, static function (YDoc $doc): array {
        $text = $doc->getMap('map')->getText('body');
        if (! $text instanceof Yjs\YNestedText) {
            throw new \RuntimeException('Expected map text node.');
        }

        return [
            'mapTextKey' => 'body',
            'mapTextAttributes' => $text->getAttributes(),
        ];
    }),
    update_case('php-map-text-explicit-insert-clears-active-format', 'map', 267, static function (YDoc $doc): void {
        $text = $doc->getMap('map')->setText('body');
        $text->insert(0, 'MapText');
        $text->format(0, 3, ['bold' => true]);
        $text->delete(3, 4);
        $text->insert(3, ' body', ['italic' => true]);
    }, static function (YDoc $doc): array {
        $text = $doc->getMap('map')->getText('body');
        if (! $text instanceof Yjs\YNestedText) {
            throw new \RuntimeException('Expected map text node.');
        }

        return [
            'mapTextKey' => 'body',
            'mapTextDelta' => $text->toDelta(),
        ];
    }),
    update_case('php-array-transaction-batched-delete', 'array', 217, static function (YDoc $doc): void {
        $doc->transact(static function (YDoc $doc): void {
            $array = $doc->getArray('array');
            $array->insert(0, ['A', 'B', 'C']);
            $array->delete(1, 1);
        }, 'php-batch');
    }),
    update_case('php-binary-content', 'mixed', 212, static function (YDoc $doc): void {
        $array = $doc->getArray('array');
        $array->insert(0, ['before']);
        $array->insertBinary(1, "\x01\x02\xff");
        $doc->getMap('map')->setBinary('bytes', "\x00\x7f\xff");
    }),
    update_case('php-subdoc-content', 'mixed', 227, static function (YDoc $doc): void {
        $doc->getArray('array')->insertSubdoc(0, 'php-array-subdoc', ['meta' => ['kind' => 'note']]);
        $doc->getMap('map')->setSubdoc('child', 'php-map-subdoc', ['autoLoad' => true]);
    }, static fn (YDoc $doc): array => [
        'subdocs' => [
            [
                'root' => 'array',
                'path' => [0],
                'guid' => 'php-array-subdoc',
                'meta' => ['kind' => 'note'],
                'shouldLoad' => false,
            ],
            [
                'root' => 'map',
                'path' => ['child'],
                'guid' => 'php-map-subdoc',
                'meta' => null,
                'shouldLoad' => true,
            ],
        ],
    ]),
    update_case('php-nested-subdoc-content', 'array', 228, static function (YDoc $doc): void {
        $array = $doc->getArray('array');
        $nestedArray = $array->insertArray(0);
        $nestedMap = $array->insertMap(1);
        $nestedArray->insertSubdoc(0, 'php-nested-array-subdoc', ['meta' => ['nested' => true]]);
        $nestedMap->setSubdoc('child', 'php-nested-map-subdoc', ['autoLoad' => true]);
    }, static fn (YDoc $doc): array => [
        'subdocs' => [
            [
                'root' => 'array',
                'path' => [0, 0],
                'guid' => 'php-nested-array-subdoc',
                'meta' => ['nested' => true],
                'shouldLoad' => false,
            ],
            [
                'root' => 'array',
                'path' => [1, 'child'],
                'guid' => 'php-nested-map-subdoc',
                'meta' => null,
                'shouldLoad' => true,
            ],
        ],
    ]),
    raw_update_case('php-content-json', 'mixed', [
        [
            'type' => 'Item',
            'id' => ['client' => 214, 'clock' => 0],
            'length' => 2,
            'origin' => null,
            'rightOrigin' => null,
            'parent' => 'array',
            'parentSub' => null,
            'content' => [
                'type' => 'ContentJSON',
                'values' => [
                    ['legacy' => true],
                    null,
                ],
            ],
        ],
        [
            'type' => 'Item',
            'id' => ['client' => 214, 'clock' => 2],
            'length' => 1,
            'origin' => null,
            'rightOrigin' => null,
            'parent' => 'map',
            'parentSub' => 'settings',
            'content' => [
                'type' => 'ContentJSON',
                'values' => [
                    ['enabled' => true, 'count' => 2],
                ],
            ],
        ],
    ]),
    raw_update_case('php-raw-gc-update', 'none', [
        [
            'type' => 'GC',
            'id' => ['client' => 245, 'clock' => 0],
            'length' => 3,
        ],
    ]),
    update_case('php-map-null-replace', 'map', 206, static function (YDoc $doc): void {
        $map = $doc->getMap('map');
        $map->set('title', null);
        $map->set('title', 'Hello');
    }),
    update_case_with_doc('php-gc-map-replace-content-deleted', 'map', new YDoc(250, gc: true), static function (YDoc $doc): void {
        $map = $doc->getMap('map');
        $map->set('title', 'Draft');
        $map->set('title', 'Published');
    }),
    update_case('php-map-delete-then-reuse-key', 'map', 235, static function (YDoc $doc): void {
        $map = $doc->getMap('map');
        $map->set('title', 'Draft');
        $map->delete('title');
        $map->set('title', 'Published');
    }),
    update_case('php-nested-map-delete-then-reuse-key', 'map', 236, static function (YDoc $doc): void {
        $nested = $doc->getMap('map')->setMap('nested');
        $nested->set('title', 'Draft');
        $nested->delete('title');
        $nested->set('title', 'Published');
    }),
    update_case('php-xml-element-attribute-text', 'xml', 207, static function (YDoc $doc): void {
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $paragraph->setAttribute('class', 'lead');
        $paragraph->setAttribute('data-temp', 'remove-me');
        $paragraph->removeAttribute('data-temp');
        $text = $paragraph->insertText(0, 'Hello');
        $text->insert(5, ' XML');
        $text->delete(5, 1);
        $text->insert(5, ' ');
    }),
    update_case('php-xml-attribute-replace', 'xml', 223, static function (YDoc $doc): void {
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $paragraph->setAttribute('class', 'draft');
        $paragraph->setAttribute('class', 'published');
    }),
    update_case_with_doc('php-gc-xml-attribute-replace-content-deleted', 'xml', new YDoc(251, gc: true), static function (YDoc $doc): void {
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $paragraph->setAttribute('class', 'draft');
        $paragraph->setAttribute('class', 'published');
    }),
    update_case('php-xml-attribute-delete-then-reuse-key', 'xml', 237, static function (YDoc $doc): void {
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $paragraph->setAttribute('class', 'draft');
        $paragraph->removeAttribute('class');
        $paragraph->setAttribute('class', 'published');
    }),
    update_case(
        'php-xml-hook-shared-type-values',
        'xml',
        298,
        static function (YDoc $doc): void {
            $hook = $doc->getXmlFragment('xml')->insertHook(0, 'mention');
            $hook->set('body', 'draft');
            $body = $hook->setText('body');
            $body->insert(0, 'Hook text');
            $meta = $hook->setMap('meta');
            $meta->set('role', 'author');
            $items = $hook->setArray('items');
            $items->insert(0, ['A', 'B']);
        },
        static function (YDoc $doc): array {
            $hook = $doc->getXmlFragment('xml')->get(0);
            if (! $hook instanceof Yjs\YXmlHook) {
                throw new RuntimeException('Expected XML hook.');
            }

            return ['hookJson' => $hook->toJSON()];
        }
    ),
    update_case(
        'php-xml-hook-xml-shared-type-values',
        'xml',
        299,
        static function (YDoc $doc): void {
            $hook = $doc->getXmlFragment('xml')->insertHook(0, 'mention');
            $element = $hook->setXmlElement('element', 'p');
            $element->appendText('Element');
            $hook->setXmlText('text', 'Xml text');
            $nestedHook = $hook->setXmlHook('hook', 'note');
            $nestedHook->set('ok', true);
            $fragment = $hook->setXmlFragment('fragment');
            $fragment->appendText('Frag');
        },
        static function (YDoc $doc): array {
            $hook = $doc->getXmlFragment('xml')->get(0);
            if (! $hook instanceof Yjs\YXmlHook) {
                throw new RuntimeException('Expected XML hook.');
            }

            return ['hookJson' => $hook->toJSON()];
        }
    ),
    update_case(
        'php-xml-hook-bulk-shared-type-values',
        'xml',
        300,
        static function (YDoc $doc): void {
            $hook = $doc->getXmlFragment('xml')->insertHook(0, 'mention');
            $arrays = $hook->setArrays(['items']);
            $maps = $hook->setMaps(['meta']);
            $texts = $hook->setTexts(['body']);
            $elements = $hook->setXmlElements(['element' => 'p']);
            $hook->setXmlTexts(['text' => 'Xml text']);
            $hooks = $hook->setXmlHooks(['hook' => 'note']);
            $fragments = $hook->setXmlFragments(['fragment']);

            $arrays['items']->insert(0, ['A', 'B']);
            $maps['meta']->set('role', 'author');
            $texts['body']->insert(0, 'Hook text');
            $elements['element']->appendText('Element');
            $hooks['hook']->set('ok', true);
            $fragments['fragment']->appendText('Frag');
        },
        static function (YDoc $doc): array {
            $hook = $doc->getXmlFragment('xml')->get(0);
            if (! $hook instanceof Yjs\YXmlHook) {
                throw new RuntimeException('Expected XML hook.');
            }

            return ['hookJson' => $hook->toJSON()];
        }
    ),
    update_case(
        'php-xml-element-bulk-shared-type-attributes',
        'xml',
        301,
        static function (YDoc $doc): void {
            $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
            $arrays = $paragraph->setArrays(['items']);
            $maps = $paragraph->setMaps(['meta']);
            $texts = $paragraph->setTexts(['body']);
            $elements = $paragraph->setXmlElements(['element' => 'span']);
            $paragraph->setXmlTexts(['text' => 'Xml text']);
            $hooks = $paragraph->setXmlHooks(['hook' => 'note']);
            $fragments = $paragraph->setXmlFragments(['fragment']);

            $arrays['items']->insert(0, ['A', 'B']);
            $maps['meta']->set('role', 'lead');
            $texts['body']->insert(0, 'Element text');
            $elements['element']->appendText('Element');
            $hooks['hook']->set('ok', true);
            $fragments['fragment']->appendText('Frag');
        },
        static function (YDoc $doc): array {
            $paragraph = $doc->getXmlFragment('xml')->get(0);
            if (! $paragraph instanceof Yjs\YXmlElement) {
                throw new RuntimeException('Expected XML element.');
            }

            return ['xmlElementAttributes' => $paragraph->getAttributes()];
        }
    ),
    update_case('php-xml-text-formatting', 'xml', 218, static function (YDoc $doc): void {
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $paragraph->setAttribute('class', 'lead');
        $text = $paragraph->insertText(0, 'Hello');
        $text->format(1, 3, ['bold' => true]);
        $text->insert(5, '!', ['italic' => true]);
        $text->delete(5, 1);
        $text->insert(5, '?');
        $text->insert(6, '😀');
    }),
    update_case('php-xml-text-explicit-insert-clears-active-format', 'xml', 265, static function (YDoc $doc): void {
        $text = $doc->getXmlFragment('xml')->insertElement(0, 'p')->insertText(0, 'MapText');
        $text->format(0, 3, ['bold' => true]);
        $text->delete(3, 4);
        $text->insert(3, ' body', ['italic' => true]);
    }, static function (YDoc $doc): array {
        $paragraph = $doc->getXmlFragment('xml')->get(0);
        if (! $paragraph instanceof Yjs\YXmlElement) {
            throw new \RuntimeException('Expected XML paragraph node.');
        }

        $text = $paragraph->get(0);
        if (! $text instanceof Yjs\YXmlText) {
            throw new \RuntimeException('Expected XML text node.');
        }

        return ['xmlTextDelta' => $text->toDelta()];
    }),
    update_case('php-xml-special-character-rendering', 'xml', 229, static function (YDoc $doc): void {
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $paragraph->setAttribute('title', 'A&B "Q" <tag>');
        $text = $paragraph->insertText(0, 'A&B < C');
        $text->format(0, 3, ['mark' => 'x&y']);
    }),
    update_case('php-xml-text-embed', 'xml', 220, static function (YDoc $doc): void {
        $text = $doc->getXmlFragment('xml')->insertText(0, '');
        $text->insertEmbed(0, ['image' => 'cat.png'], ['alt' => 'Cat']);
    }),
    update_case('php-xml-text-apply-delta', 'xml', 226, static function (YDoc $doc): void {
        $text = $doc->getXmlFragment('xml')->insertElement(0, 'p')->insertText(0, '');
        $text->applyDelta([
            ['insert' => 'Hello'],
            ['insert' => ' XML', 'attributes' => ['italic' => true]],
        ]);
        $text->applyDelta([
            ['retain' => 6],
            ['delete' => 2],
            ['insert' => 'Yjs', 'attributes' => ['bold' => true]],
        ]);
    }),
    update_case('php-xml-nested-order-delete', 'xml', 213, static function (YDoc $doc): void {
        $fragment = $doc->getXmlFragment('xml');
        $fragment->insertText(0, 'tail');
        $article = $fragment->insertElement(0, 'article');
        $article->insertText(0, 'B');
        $strong = $article->insertElement(0, 'strong');
        $strong->insertText(0, 'A');
        $article->insertText(2, 'C');
        $article->delete(1, 1);
        $fragment->delete(1, 1);
    }),
    update_case('php-xml-delete-target-reinsert', 'xml', 241, static function (YDoc $doc): void {
        $fragment = $doc->getXmlFragment('xml');
        $fragment->insertElement(0, 'a');
        $fragment->insertElement(1, 'b');
        $fragment->insertElement(2, 'c');
        $fragment->delete(1, 1);
        $fragment->insertElement(1, 'x');

        $element = $fragment->insertElement(3, 'p');
        $element->insertElement(0, 'a');
        $element->insertElement(1, 'b');
        $element->insertElement(2, 'c');
        $element->delete(1, 1);
        $element->insertElement(1, 'x');
    }),
    update_case_with_doc('php-gc-xml-delete-element-content-deleted', 'xml', new YDoc(252, gc: true), static function (YDoc $doc): void {
        $xml = $doc->getXmlFragment('xml');
        $xml->insertElement(0, 'p');
        $xml->insertElement(1, 'q');
        $xml->delete(0, 1);
    }),
    update_case('php-xml-array-access-apis', 'xml', 230, static function (YDoc $doc): void {
        $fragment = $doc->getXmlFragment('xml');
        $fragment[] = 'intro';
        $article = $fragment->appendElement('article');
        $fragment->appendHook('mention');
        $article[] = 'A';
        $article->appendElement('strong')->appendText('B');
        $article->appendHook('note');
        $article[0] = 'Lead';
        unset($article[2]);
        $fragment[0] = 'start';
        unset($fragment[2]);
        $fragment->appendText('tail');
    }),
    update_case('php-native-xml-splice-apis', 'xml', 243, static function (YDoc $doc): void {
        $fragment = $doc->getXmlFragment('xml');
        $fragment->appendText('A');
        $section = $fragment->appendElement('section');
        $fragment->appendHook('mention');
        $fragment->appendText('tail');

        $section->appendText('B');
        $section->appendElement('em')->appendText('D');
        $section->appendHook('inside');

        $section->splice(1, 1, ['M', 'N']);
        $fragment->splice(-2, 1, ['mid']);
        $fragment->splice(99, 1, ['end']);
    }),
    update_case('php-native-xml-pop-shift-apis', 'xml', 256, static function (YDoc $doc): void {
        $fragment = $doc->getXmlFragment('xml');
        $fragment->appendText('A');
        $section = $fragment->appendElement('section');
        $fragment->appendHook('mention');

        $section->appendText('B');
        $section->appendElement('strong')->appendText('C');
        $section->appendHook('note');

        $fragment->pop();
        $fragment->shift();
        $section->pop();
        $section->shift();
        $fragment->appendElement('empty')->pop();
        $fragment->clear();
        $fragment->pop();
        $fragment->shift();
        $fragment->appendElement('final')->appendText('ok');
    }),
    update_case('php-native-default-delete-length-apis', 'array-xml', 257, static function (YDoc $doc): void {
        $array = $doc->getArray('array');
        $array->insert(0, ['A', 'B', 'C']);
        $array->delete(1);
        $nestedArray = $array->insertArray(2);
        $nestedArray->insert(0, [1, 2, 3]);
        $nestedArray->delete(1);

        $xml = $doc->getXmlFragment('xml');
        $xml->appendText('A');
        $paragraph = $xml->appendElement('p');
        $xml->appendText('B');
        $xml->delete(2);
        $paragraph->appendText('x');
        $paragraph->appendElement('strong')->appendText('y');
        $paragraph->appendText('z');
        $paragraph->delete(1);
    }),
    update_case('php-native-xml-batch-delete-apis', 'xml', 297, static function (YDoc $doc): void {
        $xml = $doc->getXmlFragment('xml');
        $xml->appendText('A');
        $section = $xml->appendElement('section');
        $xml->appendText('C');
        $xml->appendElement('aside')->appendText('D');
        $xml->appendText('E');
        $xml->deleteAll([2, 3, 2]);

        $section->appendText('x');
        $section->appendElement('strong')->appendText('y');
        $section->appendText('z');
        $section->appendHook('note');
        $section->appendText('q');
        $section->deleteAll([0, 2, 4]);
    }),
    update_case('php-native-array-bulk-xml-shared-types', 'array', 290, static function (YDoc $doc): void {
        $array = $doc->getArray('array');
        $array->appendXmlElements(['header', 'main']);
        $array->insertXmlTexts(1, ['A', 'B']);
        $fragments = $array->insertXmlFragments(3, 2);
        $fragments[0]->appendText('Nested');
        $fragments[1]->appendElement('aside');

        $nested = $array->appendArray();
        $nested->appendXmlElements(['section', 'aside']);
        $nested->prependXmlTexts(['N']);
        $nestedFragments = $nested->insertXmlFragments(2, 1);
        $nestedFragments[0]->appendText('Inner');
    }),
    update_case('php-native-array-bulk-nested-shared-types', 'array', 291, static function (YDoc $doc): void {
        $array = $doc->getArray('array');
        $arrays = $array->prependArrays(2);
        $arrays[0]->push(['A0']);
        $arrays[1]->push(['A1']);
        $texts = $array->insertTexts(1, 2);
        $texts[0]->insert(0, 'T0');
        $texts[1]->insert(0, 'T1');
        $maps = $array->appendMaps(2);
        $maps[0]->set('title', 'M0');
        $maps[1]->set('title', 'M1');

        $nested = $arrays[1];
        $nestedTexts = $nested->prependTexts(1);
        $nestedTexts[0]->insert(0, 'N0');
        $nestedMaps = $nested->appendMaps(1);
        $nestedMaps[0]->set('kind', 'nested-map');
        $nestedArrays = $nested->insertArrays(1, 1);
        $nestedArrays[0]->push(['inner']);
    }),
    update_case('php-native-map-bulk-nested-shared-types', 'map', 295, static function (YDoc $doc): void {
        $map = $doc->getMap('map');
        $arrays = $map->setArrays(['itemsA', 'itemsB']);
        $arrays['itemsA']->push(['A0']);
        $arrays['itemsB']->push(['B0']);
        $texts = $map->setTexts(['titleA', 'titleB']);
        $texts['titleA']->insert(0, 'T0');
        $texts['titleB']->insert(0, 'T1');
        $maps = $map->setMaps(['metaA', 'metaB']);
        $maps['metaA']->set('kind', 'A');
        $maps['metaB']->set('kind', 'B');

        $nestedArrays = $maps['metaB']->setArrays(['nestedItems']);
        $nestedArrays['nestedItems']->push(['inner']);
        $nestedTexts = $maps['metaB']->setTexts(['nestedTitle']);
        $nestedTexts['nestedTitle']->insert(0, 'Nested');
        $nestedMaps = $maps['metaB']->setMaps(['nestedMeta']);
        $nestedMaps['nestedMeta']->set('depth', 2);
    }),
    update_case('php-native-map-bulk-xml-shared-types', 'map', 296, static function (YDoc $doc): void {
        $map = $doc->getMap('map');
        $map->setXmlElements(['header' => 'header', 'main' => 'main']);
        $map->setXmlTexts(['lead' => 'Hello', 'tail' => 'Bye']);
        $hooks = $map->setXmlHooks(['mention' => 'mention']);
        $hooks['mention']->set('id', 1);
        $fragments = $map->setXmlFragments(['body', 'aside']);
        $fragments['body']->appendText('Body');
        $fragments['aside']->appendElement('aside');

        $nested = $map->setMap('nested');
        $nested->setXmlElements(['section' => 'section']);
        $nested->setXmlTexts(['copy' => 'Nested']);
        $nestedHooks = $nested->setXmlHooks(['note' => 'note']);
        $nestedHooks['note']->set('ok', true);
        $nestedFragments = $nested->setXmlFragments(['content']);
        $nestedFragments['content']->appendText('Inner');
    }),
    update_case('php-native-array-bulk-binary-content', 'array', 292, static function (YDoc $doc): void {
        $array = $doc->getArray('array');
        $array->insert(0, ['middle']);
        $array->prependBinaries(["\x00", "\x01"]);
        $array->insertBinaries(2, ["\x02\x03", "\xff"]);
        $array->appendBinaries(["\x7f"]);

        $nested = $array->appendArray();
        $nested->appendBinaries(["\x10"]);
        $nested->prependBinaries(["\x11", "\x12"]);
        $nested->insertBinaries(1, ["\x13"]);
    }),
    update_case('php-native-array-bulk-subdocs', 'array', 293, static function (YDoc $doc): void {
        $array = $doc->getArray('array');
        $array->insert(0, ['middle']);
        $array->prependSubdocs([
            'php-bulk-first',
            ['guid' => 'php-bulk-second', 'opts' => ['meta' => ['position' => 'second']]],
        ]);
        $array->insertSubdocs(2, [
            ['guid' => 'php-bulk-inserted', 'opts' => ['autoLoad' => true]],
        ]);
        $array->appendSubdocs([
            ['guid' => 'php-bulk-last', 'opts' => ['meta' => ['position' => 'last']]],
        ]);

        $nested = $array->appendArray();
        $nested->appendSubdocs([
            'php-nested-bulk-first',
            ['guid' => 'php-nested-bulk-second', 'opts' => ['meta' => ['nested' => true]]],
        ]);
    }, static fn (YDoc $doc): array => [
        'subdocs' => [
            [
                'root' => 'array',
                'path' => [0],
                'guid' => 'php-bulk-first',
                'meta' => null,
                'shouldLoad' => false,
            ],
            [
                'root' => 'array',
                'path' => [1],
                'guid' => 'php-bulk-second',
                'meta' => ['position' => 'second'],
                'shouldLoad' => false,
            ],
            [
                'root' => 'array',
                'path' => [2],
                'guid' => 'php-bulk-inserted',
                'meta' => null,
                'shouldLoad' => true,
            ],
            [
                'root' => 'array',
                'path' => [4],
                'guid' => 'php-bulk-last',
                'meta' => ['position' => 'last'],
                'shouldLoad' => false,
            ],
            [
                'root' => 'array',
                'path' => [5, 0],
                'guid' => 'php-nested-bulk-first',
                'meta' => null,
                'shouldLoad' => false,
            ],
            [
                'root' => 'array',
                'path' => [5, 1],
                'guid' => 'php-nested-bulk-second',
                'meta' => ['nested' => true],
                'shouldLoad' => false,
            ],
        ],
    ]),
    update_case('php-native-map-bulk-binary-subdocs', 'map', 294, static function (YDoc $doc): void {
        $map = $doc->getMap('map');
        $map->set('title', 'Draft');
        $map->setBinaries([
            'bytesA' => "\x00\x7f",
            'bytesB' => "\xff",
        ]);
        $map->setSubdocs([
            'first' => 'php-map-bulk-first',
            'second' => ['guid' => 'php-map-bulk-second', 'opts' => ['autoLoad' => true]],
        ]);

        $nested = $map->setMap('nested');
        $nested->setBinaries([
            'nestedBytesA' => "\x10\x20",
            'nestedBytesB' => "\x30",
        ]);
        $nested->setSubdocs([
            'nestedFirst' => 'php-nested-map-bulk-first',
            'nestedSecond' => ['guid' => 'php-nested-map-bulk-second', 'opts' => ['meta' => ['scope' => 'nested']]],
        ]);
    }, static fn (YDoc $doc): array => [
        'subdocs' => [
            [
                'root' => 'map',
                'path' => ['first'],
                'guid' => 'php-map-bulk-first',
                'meta' => null,
                'shouldLoad' => false,
            ],
            [
                'root' => 'map',
                'path' => ['second'],
                'guid' => 'php-map-bulk-second',
                'meta' => null,
                'shouldLoad' => true,
            ],
            [
                'root' => 'map',
                'path' => ['nested', 'nestedFirst'],
                'guid' => 'php-nested-map-bulk-first',
                'meta' => null,
                'shouldLoad' => false,
            ],
            [
                'root' => 'map',
                'path' => ['nested', 'nestedSecond'],
                'guid' => 'php-nested-map-bulk-second',
                'meta' => ['scope' => 'nested'],
                'shouldLoad' => false,
            ],
        ],
    ]),
    update_case('php-native-text-default-delete-length-apis', 'text-array-xml', 259, static function (YDoc $doc): void {
        $text = $doc->getText('content');
        $text->insert(0, 'ABC');
        $text->delete(1);

        $nestedText = $doc->getArray('array')->insertText(0);
        $nestedText->insert(0, 'XYZ');
        $nestedText->delete(1);

        $xmlText = $doc->getXmlFragment('xml')->insertText(0, 'abc');
        $xmlText->delete(1);
    }),
    update_case('php-native-xml-generic-insert-apis', 'xml', 244, static function (YDoc $doc): void {
        $fragment = $doc->getXmlFragment('xml');
        $fragment->insert(0, ['B', 'C']);
        $fragment->unshift(['A']);
        $fragment->push(['D']);
        $section = $fragment->appendElement('section');
        $section->insert(0, ['2', '3']);
        $section->unshift(['1']);
        $section->push(['4']);
    }),
    update_case('php-native-xml-bulk-child-apis', 'xml', 258, static function (YDoc $doc): void {
        $fragment = $doc->getXmlFragment('xml');
        $fragment->appendText('tail');
        $fragmentElements = $fragment->prependElements(['header', 'main']);
        $fragment->appendHooks(['mention', 'marker']);

        $section = $fragmentElements[1];
        $section->appendText('B');
        $section->prependElements(['em', 'strong']);
        $section->appendHooks(['note', 'flag']);
    }),
    update_case('php-xml-hooks', 'xml', 216, static function (YDoc $doc): void {
        $fragment = $doc->getXmlFragment('xml');
        $fragment->insertHook(0, 'mention');
        $paragraph = $fragment->insertElement(1, 'p');
        $paragraph->insertHook(0, 'inner');
    }),
    update_case('php-xml-hook-map-state', 'xml', 233, static function (YDoc $doc): void {
        $hook = $doc->getXmlFragment('xml')->insertHook(0, 'mention');
        $hook->set('id', 7);
        $hook->set('label', 'Ada');
        $hook->set('active', true);
        $hook->set('label', 'Grace');
        $hook->delete('active');
    }, static function (YDoc $doc): array {
        $hook = $doc->getXmlFragment('xml')->get(0);

        return ['hookJson' => $hook instanceof Yjs\YXmlHook ? $hook->toJSON() : null];
    }),
    update_case('php-xml-hook-array-access-apis', 'xml', 234, static function (YDoc $doc): void {
        $hook = $doc->getXmlFragment('xml')->insertHook(0, 'mention');
        $hook['id'] = 7;
        $hook['label'] = 'Ada';
        $hook['active'] = true;
        unset($hook['active']);
        $hook->clear();
        $hook['label'] = 'Grace';
    }, static function (YDoc $doc): array {
        $hook = $doc->getXmlFragment('xml')->get(0);

        return ['hookJson' => $hook instanceof Yjs\YXmlHook ? $hook->toJSON() : null];
    }),
    update_case('php-undo-manager-xml-hook-redo', 'xml', 246, static function (YDoc $doc): void {
        $hook = $doc->getXmlFragment('xml')->insertHook(0, 'mention');
        $hook->set('label', 'Ada');
        $undoManager = new UndoManager($doc, $hook->idKey(), ['xml-hook-edit'], captureTimeout: 0);
        $doc->transact(static function () use ($hook): void {
            $hook->set('label', 'Grace');
            $hook->set('active', true);
        }, 'xml-hook-edit');
        $undoManager->undo();
        $undoManager->redo();
    }, static function (YDoc $doc): array {
        $hook = $doc->getXmlFragment('xml')->get(0);

        return ['hookJson' => $hook instanceof Yjs\YXmlHook ? $hook->toJSON() : null];
    }),
    update_case('php-undo-manager-xml-element-shared-attribute-redo', 'xml', 302, static function (YDoc $doc): void {
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $body = $paragraph->setText('body');
        $inline = $paragraph->setXmlElement('element', 'span');
        $undoManager = new UndoManager($doc, $paragraph->idKey(), ['xml-element-shared-attribute-edit'], captureTimeout: 0);
        $doc->transact(static function () use ($paragraph, $body, $inline): void {
            $paragraph->setAttribute('role', 'lead');
            $body->insert(0, 'Hi');
            $inline->setAttribute('class', 'lead');
            $inline->appendText('Xml');
        }, 'xml-element-shared-attribute-edit');
        $undoManager->undo();
        $undoManager->redo();
    }, static function (YDoc $doc): array {
        $paragraph = $doc->getXmlFragment('xml')->get(0);
        if (! $paragraph instanceof Yjs\YXmlElement) {
            throw new RuntimeException('Expected XML element.');
        }

        return ['xmlElementAttributes' => $paragraph->getAttributes()];
    }),
    update_case('php-undo-manager-xml-structure-redo', 'xml', 247, static function (YDoc $doc): void {
        $fragment = $doc->getXmlFragment('xml');
        $paragraph = $fragment->insertElement(0, 'p');
        $paragraph->appendText('Hi');
        $undoManager = new UndoManager($doc, ['xml', $paragraph->idKey()], ['xml-structure-edit'], captureTimeout: 0);
        $doc->transact(static function () use ($fragment, $paragraph): void {
            $strong = $paragraph->appendElement('strong');
            $text = $strong->appendText('Bold');
            $text->format(0, 4, ['bold' => true]);
            $fragment->appendElement('br');
        }, 'xml-structure-edit');
        $undoManager->undo();
        $undoManager->redo();
    }),
    update_case('php-undo-manager-bulk-xml-root-redo', 'xml', 260, static function (YDoc $doc): void {
        $fragment = $doc->getXmlFragment('xml');
        $fragment->appendText('lead');
        $undoManager = new UndoManager($doc, 'xml', ['xml-bulk-root-edit'], captureTimeout: 0);
        $doc->transact(static function () use ($fragment): void {
            $fragment->insertElements(1, ['section', 'aside']);
            $fragment->appendHooks(['mention', 'marker']);
        }, 'xml-bulk-root-edit');
        $undoManager->undo();
        $undoManager->redo();
    }),
    update_case('php-array-nested-shared-types', 'array', 208, static function (YDoc $doc): void {
        $array = $doc->getArray('array');
        $nestedArray = $array->insertArray(0);
        $nestedMap = $array->insertMap(1);
        $nestedText = $array->insertText(2);
        $nestedArray->insert(0, [1, 3]);
        $nestedArray->insert(1, [2]);
        $nestedMap->set('title', 'Nested');
        $nestedText->insert(0, 'Hi');
    }),
    update_case('php-map-nested-shared-types', 'map', 209, static function (YDoc $doc): void {
        $map = $doc->getMap('map');
        $items = $map->setArray('items');
        $meta = $map->setMap('meta');
        $body = $map->setText('body');
        $items->insert(0, ['A', 'C']);
        $items->insert(1, ['B']);
        $items->delete(0, 1);
        $meta->set('title', null);
        $meta->set('title', 'Nested');
        $body->insert(0, 'Nested text');
        $body->delete(6, 1);
    }),
    update_case('php-nested-array-map-xml-shared-types', 'array', 286, static function (YDoc $doc): void {
        $root = $doc->getArray('array');
        $nested = $root->insertArray(0);
        $paragraph = $nested->insertXmlElement(0, 'p');
        $paragraph->insertText(0, 'Nested');
        $nested->appendXmlText('Tail');
        $hook = $nested->appendXmlHook('note');
        $hook->set('ok', true);

        $map = $root->insertMap(1);
        $section = $map->setXmlElement('xml', 'section');
        $section->insertText(0, 'Map');
        $map->setXmlText('text', 'Map text');
        $mapHook = $map->setXmlHook('hook', 'flag');
        $mapHook->set('id', 7);
    }),
    update_case('php-deep-nested-map-content', 'map', 268, static function (YDoc $doc): void {
        $root = $doc->getMap('map')->setMap('root');
        $items = $root->setArray('items');
        $meta = $root->setMap('meta');
        $body = $root->setText('body');

        $root->setAll(['title' => 'Draft', 'temp' => true]);
        $root->deleteAll(['temp']);
        $root->setBinary('bytes', "\x00\x7f\xff");
        $root->setSubdoc('child', 'php-deep-nested-map-subdoc', ['meta' => ['scope' => 'nested-map']]);
        $items->push(['B']);
        $items->unshift(['A']);
        $items->insertBinary(1, "\x01\x02");
        $items->appendMap()->set('kind', 'inline');
        $meta->setAll(['count' => 2, 'remove' => false]);
        $meta->delete('remove');
        $meta->setText('summary')->insert(0, 'Meta');
        $body->insert(0, 'Deep map');
        $body->format(0, 4, ['bold' => true]);
    }, static function (YDoc $doc): array {
        $root = $doc->getMap('map')->getMap('root');
        if (! $root instanceof Yjs\YNestedMap) {
            throw new \RuntimeException('Expected nested root map.');
        }

        $body = $root->getText('body');
        if (! $body instanceof Yjs\YNestedText) {
            throw new \RuntimeException('Expected nested body text.');
        }

        return ['deepNestedMapTextDelta' => $body->toDelta()];
    }),
    update_case('php-nested-text-formatting', 'array', 219, static function (YDoc $doc): void {
        $text = $doc->getArray('array')->insertText(0);
        $text->insert(0, 'Hello');
        $text->format(1, 3, ['bold' => true]);
        $text->insert(5, '!', ['italic' => true]);
        $text->delete(5, 1);
        $text->insert(5, '?');
    }),
    update_case('php-nested-text-explicit-insert-clears-active-format', 'array', 266, static function (YDoc $doc): void {
        $text = $doc->getArray('array')->insertText(0);
        $text->insert(0, 'MapText');
        $text->format(0, 3, ['bold' => true]);
        $text->delete(3, 4);
        $text->insert(3, ' body', ['italic' => true]);
    }, static function (YDoc $doc): array {
        $text = $doc->getArray('array')->getText(0);
        if (! $text instanceof Yjs\YNestedText) {
            throw new \RuntimeException('Expected nested text node.');
        }

        return ['nestedTextDelta' => $text->toDelta()];
    }),
    update_case('php-nested-text-embed', 'array', 221, static function (YDoc $doc): void {
        $text = $doc->getArray('array')->insertText(0);
        $text->insert(0, 'A');
        $text->insertEmbed(1, ['image' => 'cat.png'], ['alt' => 'Cat']);
        $text->insert(2, 'B');
    }),
    update_case('php-nested-text-apply-delta', 'array', 227, static function (YDoc $doc): void {
        $text = $doc->getArray('array')->insertText(0);
        $text->applyDelta([
            ['insert' => 'A😀C'],
        ]);
        $text->applyDelta([
            ['retain' => 3],
            ['insert' => 'B'],
        ]);
        $text->applyDelta([
            ['retain' => 1, 'attributes' => ['bold' => true]],
        ]);
    }),
    update_case('php-deep-nested-shared-types', 'array', 215, static function (YDoc $doc): void {
        $root = $doc->getArray('array');
        $outerArray = $root->insertArray(0);
        $outerMap = $root->insertMap(1);

        $outerArray->insert(0, ['start']);
        $childArray = $outerArray->insertArray(1);
        $childMap = $outerArray->insertMap(2);
        $childText = $outerArray->insertText(3);
        $outerArray->insertBinary(4, "\x00\xff");
        $childArray->insert(0, [1, 2]);
        $childMap->set('ok', true);
        $childText->insert(0, 'nested');

        $mapArray = $outerMap->setArray('items');
        $mapMap = $outerMap->setMap('meta');
        $mapText = $outerMap->setText('body');
        $outerMap->setBinary('bytes', "\x01\x02");
        $mapArray->insert(0, ['A']);
        $mapMap->set('count', 2);
        $mapText->insert(0, 'Map text');
    }),
    concurrent_fixture_update_case(
        'php-concurrent-map-delete-edit-nested-text',
        'map',
        'map-concurrent-delete-and-edit-nested-text'
    ),
    concurrent_fixture_update_case(
        'php-concurrent-xml-text-delete-edit-shared-attribute',
        'xml',
        'xml-text-concurrent-delete-edit-xml-shared-type'
    ),
];

$partialDiffCases = [
    partial_diff_case(
        'php-partial-text-suffix',
        'text',
        501,
        static function (YDoc $doc): void {
            $doc->getText('content')->insert(0, 'AB');
        },
        static function (YDoc $doc): void {
            $doc->getText('content')->insert(0, 'ABCD');
        }
    ),
    partial_diff_case(
        'php-partial-text-utf16-suffix',
        'text',
        521,
        static function (YDoc $doc): void {
            $doc->getText('content')->insert(0, 'A');
        },
        static function (YDoc $doc): void {
            $doc->getText('content')->insert(0, 'A😀C');
        }
    ),
    partial_diff_case(
        'php-partial-array-suffix',
        'array',
        502,
        static function (YDoc $doc): void {
            $doc->getArray('array')->insert(0, ['A', 'B']);
        },
        static function (YDoc $doc): void {
            $doc->getArray('array')->insert(0, ['A', 'B', 'C', 'D']);
        }
    ),
    (static function (): array {
        $case = partial_diff_case_with_docs(
            'php-partial-raw-content-any-undefined-slice',
            'array',
            (static function (): YDoc {
                $doc = new YDoc();
                $doc->applyUpdateV1(Yjs\Update\DecodedUpdate::encodeV1([
                    [
                        'type' => 'Item',
                        'id' => ['client' => 532, 'clock' => 0],
                        'length' => 1,
                        'origin' => null,
                        'rightOrigin' => null,
                        'parent' => 'array',
                        'parentSub' => null,
                        'content' => [
                            'type' => 'ContentAny',
                            'values' => [
                                'A',
                            ],
                        ],
                    ],
                ]));

                return $doc;
            })(),
            (static function (): YDoc {
                $doc = new YDoc();
                $doc->applyUpdateV1(Yjs\Update\DecodedUpdate::encodeV1([
                    [
                        'type' => 'Item',
                        'id' => ['client' => 532, 'clock' => 0],
                        'length' => 4,
                        'origin' => null,
                        'rightOrigin' => null,
                        'parent' => 'array',
                        'parentSub' => null,
                        'content' => [
                            'type' => 'ContentAny',
                            'values' => [
                                'A',
                                Yjs\UndefinedValue::instance(),
                                null,
                                'tail',
                            ],
                        ],
                    ],
                ]));

                return $doc;
            })()
        );
        $case['json'] = ['array' => ['A', null, null, 'tail']];
        $case['arrayValues'] = ['A', ['type' => 'Undefined'], null, 'tail'];

        return $case;
    })(),
    (static function (): array {
        $case = partial_diff_case_with_docs(
            'php-partial-raw-content-any-special-number-slice',
            'array',
            (static function (): YDoc {
                $doc = new YDoc();
                $doc->applyUpdateV1(Yjs\Update\DecodedUpdate::encodeV1([
                    [
                        'type' => 'Item',
                        'id' => ['client' => 550, 'clock' => 0],
                        'length' => 2,
                        'origin' => null,
                        'rightOrigin' => null,
                        'parent' => 'array',
                        'parentSub' => null,
                        'content' => [
                            'type' => 'ContentAny',
                            'values' => [
                                Yjs\UndefinedValue::instance(),
                                NAN,
                            ],
                        ],
                    ],
                ]));

                return $doc;
            })(),
            (static function (): YDoc {
                $doc = new YDoc();
                $doc->applyUpdateV1(Yjs\Update\DecodedUpdate::encodeV1([
                    [
                        'type' => 'Item',
                        'id' => ['client' => 550, 'clock' => 0],
                        'length' => 5,
                        'origin' => null,
                        'rightOrigin' => null,
                        'parent' => 'array',
                        'parentSub' => null,
                        'content' => [
                            'type' => 'ContentAny',
                            'values' => [
                                Yjs\UndefinedValue::instance(),
                                NAN,
                                INF,
                                -INF,
                                ['nested' => NAN],
                            ],
                        ],
                    ],
                ]));

                return $doc;
            })()
        );
        $case['json'] = ['array' => [null, null, null, null, ['nested' => null]]];
        $case['arrayValues'] = [
            ['type' => 'Undefined'],
            ['type' => 'Number', 'value' => 'NaN'],
            ['type' => 'Number', 'value' => 'Infinity'],
            ['type' => 'Number', 'value' => '-Infinity'],
            ['nested' => ['type' => 'Number', 'value' => 'NaN']],
        ];

        return $case;
    })(),
    partial_diff_case_with_docs(
        'php-partial-raw-content-json-slice',
        'array',
        (static function (): YDoc {
            $doc = new YDoc();
            $doc->applyUpdateV1(Yjs\Update\DecodedUpdate::encodeV1([
                [
                    'type' => 'Item',
                    'id' => ['client' => 531, 'clock' => 0],
                    'length' => 1,
                    'origin' => null,
                    'rightOrigin' => null,
                    'parent' => 'array',
                    'parentSub' => null,
                    'content' => [
                        'type' => 'ContentJSON',
                        'values' => [
                            'A',
                        ],
                    ],
                ],
            ]));

            return $doc;
        })(),
        (static function (): YDoc {
            $doc = new YDoc();
            $doc->applyUpdateV1(Yjs\Update\DecodedUpdate::encodeV1([
                [
                    'type' => 'Item',
                    'id' => ['client' => 531, 'clock' => 0],
                    'length' => 3,
                    'origin' => null,
                    'rightOrigin' => null,
                    'parent' => 'array',
                    'parentSub' => null,
                    'content' => [
                        'type' => 'ContentJSON',
                        'values' => [
                            'A',
                            ['nested' => true],
                            null,
                        ],
                    ],
                ],
            ]));

            return $doc;
        })()
    ),
    (static function (): array {
        $case = partial_diff_case_with_docs(
            'php-partial-raw-content-json-undefined-slice',
            'array',
            (static function (): YDoc {
                $doc = new YDoc();
                $doc->applyUpdateV1(Yjs\Update\DecodedUpdate::encodeV1([
                    [
                        'type' => 'Item',
                        'id' => ['client' => 533, 'clock' => 0],
                        'length' => 1,
                        'origin' => null,
                        'rightOrigin' => null,
                        'parent' => 'array',
                        'parentSub' => null,
                        'content' => [
                            'type' => 'ContentJSON',
                            'values' => [
                                'A',
                            ],
                        ],
                    ],
                ]));

                return $doc;
            })(),
            (static function (): YDoc {
                $doc = new YDoc();
                $doc->applyUpdateV1(Yjs\Update\DecodedUpdate::encodeV1([
                    [
                        'type' => 'Item',
                        'id' => ['client' => 533, 'clock' => 0],
                        'length' => 4,
                        'origin' => null,
                        'rightOrigin' => null,
                        'parent' => 'array',
                        'parentSub' => null,
                        'content' => [
                            'type' => 'ContentJSON',
                            'values' => [
                                'A',
                                Yjs\UndefinedValue::instance(),
                                null,
                                'tail',
                            ],
                        ],
                    ],
                ]));

                return $doc;
            })()
        );
        $case['json'] = ['array' => ['A', null, null, 'tail']];
        $case['arrayValues'] = ['A', ['type' => 'Undefined'], null, 'tail'];

        return $case;
    })(),
    partial_diff_case(
        'php-partial-deleted-array-slice',
        'array',
        503,
        static function (YDoc $doc): void {
            $doc->getArray('array')->insert(0, ['A']);
        },
        static function (YDoc $doc): void {
            $array = $doc->getArray('array');
            $array->insert(0, ['A', 'B', 'C', 'D']);
            $array->delete(1, 2);
        }
    ),
    partial_diff_case_with_docs(
        'php-partial-gc-text-deleted-slice',
        'text',
        (static function (): YDoc {
            $doc = new YDoc(523);
            $doc->getText('content')->insert(0, 'AB');

            return $doc;
        })(),
        (static function (): YDoc {
            $doc = new YDoc(523, gc: true);
            $text = $doc->getText('content');
            $text->insert(0, 'ABCD');
            $text->delete(1, 2);

            return $doc;
        })()
    ),
    partial_diff_case_with_docs(
        'php-partial-gc-nested-array-deleted-slice',
        'array',
        (static function (): YDoc {
            $doc = new YDoc(524);
            $array = $doc->getArray('array')->insertArray(0);
            $array->insert(0, ['A', 'B']);

            return $doc;
        })(),
        (static function (): YDoc {
            $doc = new YDoc(524, gc: true);
            $array = $doc->getArray('array')->insertArray(0);
            $array->insert(0, ['A', 'B', 'C', 'D']);
            $array->delete(1, 2);

            return $doc;
        })()
    ),
    partial_diff_case(
        'php-partial-array-delete-reinsert-after-known-full-struct',
        'array',
        516,
        static function (YDoc $doc): void {
            $doc->getArray('array')->insert(0, ['A', 'B', 'C']);
        },
        static function (YDoc $doc): void {
            $array = $doc->getArray('array');
            $array->insert(0, ['A', 'B', 'C']);
            $array->delete(1, 1);
            $array->insert(1, ['X']);
        }
    ),
    partial_diff_case(
        'php-partial-array-delete-reinsert-after-known-prefix-slice',
        'array',
        517,
        static function (YDoc $doc): void {
            $doc->getArray('array')->insert(0, ['A']);
        },
        static function (YDoc $doc): void {
            $array = $doc->getArray('array');
            $array->insert(0, ['A', 'B', 'C']);
            $array->delete(1, 1);
            $array->insert(1, ['X']);
        }
    ),
    partial_diff_case(
        'php-partial-text-delete-reinsert-after-known-full-struct',
        'text',
        518,
        static function (YDoc $doc): void {
            $doc->getText('content')->insert(0, 'ABC');
        },
        static function (YDoc $doc): void {
            $text = $doc->getText('content');
            $text->insert(0, 'ABC');
            $text->delete(1, 1);
            $text->insert(1, 'X');
        }
    ),
    partial_diff_case(
        'php-partial-text-delete-only-after-known-content',
        'text',
        514,
        static function (YDoc $doc): void {
            $doc->getText('content')->insert(0, 'ABCD');
        },
        static function (YDoc $doc): void {
            $text = $doc->getText('content');
            $text->insert(0, 'ABCD');
            $text->delete(1, 2);
        }
    ),
    partial_diff_case(
        'php-partial-map-delete-only-after-known-key',
        'map',
        515,
        static function (YDoc $doc): void {
            $map = $doc->getMap('map');
            $map->set('title', 'Draft');
            $map->set('status', 'ready');
        },
        static function (YDoc $doc): void {
            $map = $doc->getMap('map');
            $map->set('title', 'Draft');
            $map->set('status', 'ready');
            $map->delete('status');
        }
    ),
    partial_diff_case(
        'php-partial-map-replace-from-known-key',
        'map',
        504,
        static function (YDoc $doc): void {
            $doc->getMap('map')->set('title', 'Draft');
        },
        static function (YDoc $doc): void {
            $map = $doc->getMap('map');
            $map->set('title', 'Draft');
            $map->set('title', 'Published');
            $map->set('status', 'ready');
        }
    ),
    partial_diff_case(
        'php-partial-map-subdoc-after-known-key',
        'map',
        536,
        static function (YDoc $doc): void {
            $doc->getMap('map')->set('known', 'before');
        },
        static function (YDoc $doc): void {
            $map = $doc->getMap('map');
            $map->set('known', 'before');
            $map->setSubdoc('child', 'php-partial-map-subdoc-child', [
                'autoLoad' => true,
                'meta' => ['kind' => 'partial-map'],
            ]);
        },
        static fn (YDoc $doc): array => [
            'subdocs' => [
                [
                    'root' => 'map',
                    'path' => ['child'],
                    'guid' => 'php-partial-map-subdoc-child',
                    'meta' => ['kind' => 'partial-map'],
                    'shouldLoad' => true,
                ],
            ],
        ]
    ),
    partial_diff_case(
        'php-partial-text-format-after-known-content',
        'text',
        505,
        static function (YDoc $doc): void {
            $doc->getText('content')->insert(0, 'Hello');
        },
        static function (YDoc $doc): void {
            $text = $doc->getText('content');
            $text->insert(0, 'Hello');
            $text->format(1, 3, ['bold' => true]);
        }
    ),
    (static function (): array {
        $full = new YDoc(539);
        $full->getText('content')->insert(0, 'HelloWorld', ['bold' => true]);
        $fullDecoded = Yjs\Update\DecodedUpdate::decodeV1($full->encodeStateAsUpdateV1());
        $prefixDecoded = $fullDecoded;
        $prefixDecoded['structs'] = [
            $fullDecoded['structs'][0],
            $fullDecoded['structs'][1],
        ];
        $prefixDecoded['structs'][1]['length'] = 5;
        $prefixDecoded['structs'][1]['content']['value'] = 'Hello';

        $prefix = new YDoc();
        $prefix->applyUpdateV1(Yjs\Update\DecodedUpdate::encodeV1($prefixDecoded['structs']));

        return partial_diff_case_with_docs(
            'php-partial-text-formatted-string-slice',
            'text',
            $prefix,
            $full,
            static function (YDoc $doc): array {
                return [
                    'textDelta' => $doc->getText('content')->toDelta(),
                ];
            }
        );
    })(),
    partial_diff_case(
        'php-partial-text-attributes-after-known-content',
        'text',
        526,
        static function (YDoc $doc): void {
            $doc->getText('content')->insert(0, 'Text');
        },
        static function (YDoc $doc): void {
            $text = $doc->getText('content');
            $text->insert(0, 'Text');
            $text->setAttribute('lang', 'en');
            $text->setAttribute('mark', ['color' => 'green']);
        },
        static function (YDoc $doc): array {
            return ['textAttributes' => $doc->getText('content')->getAttributes()];
        }
    ),
    (static function (): array {
        $fullText = null;

        return partial_diff_case(
            'php-partial-nested-text-attributes-after-known-node',
            'array',
            532,
            static function (YDoc $doc): void {
                $doc->getArray('array')->insertText(0)->insert(0, 'Nested');
            },
            static function (YDoc $doc) use (&$fullText): void {
                $fullText = $doc->getArray('array')->insertText(0);
                $fullText->insert(0, 'Nested');
                $fullText->setAttribute('lang', 'en');
                $fullText->setAttribute('mark', ['color' => 'green']);
            },
            static function (YDoc $doc) use (&$fullText): array {
                if (! $fullText instanceof Yjs\YNestedText) {
                    throw new \RuntimeException('Expected nested text node.');
                }

                return ['nestedTextAttributes' => $fullText->getAttributes()];
            }
        );
    })(),
    partial_diff_case(
        'php-partial-map-text-attributes-and-content-after-known-node',
        'map',
        534,
        static function (YDoc $doc): void {
            $doc->getMap('map')->setText('body')->insert(0, 'Map');
        },
        static function (YDoc $doc): void {
            $text = $doc->getMap('map')->setText('body');
            $text->insert(0, 'Map');
            $text->setAttribute('lang', 'en');
            $text->setAttribute('mark', ['color' => 'green']);
            $text->insert(3, ' text', ['emphasis' => true]);
        },
        static function (YDoc $doc): array {
            $text = $doc->getMap('map')->getText('body');
            if (! $text instanceof Yjs\YNestedText) {
                throw new \RuntimeException('Expected map text node.');
            }

            return [
                'mapTextKey' => 'body',
                'mapTextAttributes' => $text->getAttributes(),
            ];
        }
    ),
    partial_diff_case(
        'php-partial-xml-element-shared-attributes-after-known-types',
        'xml',
        537,
        static function (YDoc $doc): void {
            $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
            $paragraph->setText('body');
            $paragraph->setArray('items');
            $paragraph->setXmlElement('element', 'span');
            $paragraph->setXmlText('text');
            $paragraph->setXmlFragment('fragment');
        },
        static function (YDoc $doc): void {
            $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
            $body = $paragraph->setText('body');
            $items = $paragraph->setArray('items');
            $element = $paragraph->setXmlElement('element', 'span');
            $xmlText = $paragraph->setXmlText('text');
            $fragment = $paragraph->setXmlFragment('fragment');

            $body->insert(0, 'Element text');
            $items->insert(0, ['A', 'B']);
            $element->appendText('Element');
            $xmlText->insert(0, 'Xml text');
            $fragment->appendText('Frag');
        },
        static function (YDoc $doc): array {
            $paragraph = $doc->getXmlFragment('xml')->get(0);
            if (! $paragraph instanceof Yjs\YXmlElement) {
                throw new \RuntimeException('Expected XML element.');
            }

            return ['xmlElementAttributes' => $paragraph->getAttributes()];
        }
    ),
    partial_diff_case(
        'php-partial-deep-nested-map-content-after-known-map',
        'map',
        535,
        static function (YDoc $doc): void {
            $doc->getMap('map')->setMap('root');
        },
        static function (YDoc $doc): void {
            $root = $doc->getMap('map')->setMap('root');
            $items = $root->setArray('items');
            $meta = $root->setMap('meta');
            $body = $root->setText('body');

            $root->set('title', 'Partial');
            $root->setBinary('bytes', "\x03\x04");
            $items->insert(0, ['A', 'C']);
            $items->insert(1, ['B']);
            $meta->set('count', 3);
            $body->insert(0, 'Diff map');
            $body->format(0, 4, ['bold' => true]);
        },
        static function (YDoc $doc): array {
            $root = $doc->getMap('map')->getMap('root');
            if (! $root instanceof Yjs\YNestedMap) {
                throw new \RuntimeException('Expected nested root map.');
            }

            $body = $root->getText('body');
            if (! $body instanceof Yjs\YNestedText) {
                throw new \RuntimeException('Expected nested body text.');
            }

            return ['deepNestedMapTextDelta' => $body->toDelta()];
        }
    ),
    partial_diff_case(
        'php-partial-binary-after-known-array-item',
        'array',
        506,
        static function (YDoc $doc): void {
            $doc->getArray('array')->insert(0, ['A']);
        },
        static function (YDoc $doc): void {
            $array = $doc->getArray('array');
            $array->insert(0, ['A']);
            $array->insertBinary(1, "\x01\x02\xff");
        }
    ),
    partial_diff_case(
        'php-partial-embed-after-known-text',
        'text',
        507,
        static function (YDoc $doc): void {
            $doc->getText('content')->insert(0, 'A');
        },
        static function (YDoc $doc): void {
            $text = $doc->getText('content');
            $text->insert(0, 'A');
            $text->insertEmbed(1, ['image' => 'cat.png'], ['alt' => 'Cat']);
        }
    ),
    partial_diff_case(
        'php-partial-nested-text-after-parent-type',
        'array',
        508,
        static function (YDoc $doc): void {
            $doc->getArray('array')->insertText(0);
        },
        static function (YDoc $doc): void {
            $text = $doc->getArray('array')->insertText(0);
            $text->insert(0, 'Nested');
            $text->format(0, 6, ['italic' => true]);
        }
    ),
    partial_diff_case(
        'php-partial-nested-array-map-after-parent-types',
        'array',
        509,
        static function (YDoc $doc): void {
            $array = $doc->getArray('array');
            $array->insertArray(0);
            $array->insertMap(1);
        },
        static function (YDoc $doc): void {
            $array = $doc->getArray('array');
            $nestedArray = $array->insertArray(0);
            $nestedMap = $array->insertMap(1);
            $nestedArray->insert(0, ['child']);
            $nestedMap->set('key', 'value');
        }
    ),
    partial_diff_case(
        'php-partial-map-bulk-shared-types-after-known-nodes',
        'map',
        510,
        static function (YDoc $doc): void {
            $map = $doc->getMap('map');
            $map->setArrays(['items']);
            $map->setTexts(['body']);
            $map->setMaps(['meta']);
        },
        static function (YDoc $doc): void {
            $map = $doc->getMap('map');
            $arrays = $map->setArrays(['items']);
            $texts = $map->setTexts(['body']);
            $maps = $map->setMaps(['meta']);

            $arrays['items']->push(['A', 'B']);
            $texts['body']->insert(0, 'Bulk map diff');
            $maps['meta']->setAll(['count' => 2, 'ready' => true]);
        }
    ),
    partial_diff_case(
        'php-partial-map-bulk-xml-after-known-nodes',
        'map',
        511,
        static function (YDoc $doc): void {
            $map = $doc->getMap('map');
            $map->setXmlElements(['paragraph' => 'p']);
            $map->setXmlTexts(['lead' => 'A']);
            $map->setXmlFragments(['xml']);
        },
        static function (YDoc $doc): void {
            $map = $doc->getMap('map');
            $elements = $map->setXmlElements(['paragraph' => 'p']);
            $texts = $map->setXmlTexts(['lead' => 'A']);
            $fragments = $map->setXmlFragments(['xml']);

            $elements['paragraph']->appendText('Nested');
            $elements['paragraph']->setAttribute('class', 'partial');
            $texts['lead']->insert(1, '😀C');
            $texts['lead']->format(1, 2, ['emoji' => true]);
            $fragments['xml']->appendText('A');
            $fragments['xml']->appendElement('strong')->appendText('B');
            $fragments['xml']->appendText('C');
        },
        static fn (YDoc $doc): array => [
            'mapXmlElementKey' => 'paragraph',
            'mapXmlElementAttributes' => $doc->getMap('map')->getXmlElement('paragraph')?->getAttributes(),
            'mapXmlTextKey' => 'lead',
            'mapXmlTextDelta' => $doc->getMap('map')->getXmlText('lead')?->toDelta(),
            'mapXmlFragments' => [
                ['key' => 'xml', 'xml' => 'A<strong>B</strong>C'],
            ],
        ]
    ),
    partial_diff_case(
        'php-partial-nested-xml-after-known-collection-node',
        'array',
        546,
        static function (YDoc $doc): void {
            $nested = $doc->getArray('array')->insertArray(0);
            $nested->insertXmlElement(0, 'p');
        },
        static function (YDoc $doc): void {
            $nested = $doc->getArray('array')->insertArray(0);
            $paragraph = $nested->insertXmlElement(0, 'p');
            $paragraph->insertText(0, 'Nested');
            $hook = $nested->appendXmlHook('note');
            $hook->set('ok', true);
        }
    ),
    partial_diff_case(
        'php-partial-xml-fragment-after-known-collection-node',
        'array',
        547,
        static function (YDoc $doc): void {
            $doc->getArray('array')->insertXmlFragment(0);
        },
        static function (YDoc $doc): void {
            $fragment = $doc->getArray('array')->insertXmlFragment(0);
            $paragraph = $fragment->appendElement('p');
            $paragraph->insertText(0, 'Fragment');
            $fragment->appendText(' tail');
        },
        static fn (YDoc $doc): array => [
            'arrayXmlFragments' => [
                ['index' => 0, 'xml' => '<p>Fragment</p> tail'],
            ],
        ]
    ),
    partial_diff_case(
        'php-partial-map-xml-fragment-text-utf16-suffix',
        'map',
        548,
        static function (YDoc $doc): void {
            $doc->getMap('map')->setXmlFragment('xml')->appendText('A');
        },
        static function (YDoc $doc): void {
            $doc->getMap('map')->setXmlFragment('xml')->appendText('A😀C');
        },
        static fn (YDoc $doc): array => [
            'mapXmlFragments' => [
                ['key' => 'xml', 'xml' => 'A😀C'],
            ],
        ]
    ),
    partial_diff_case(
        'php-partial-xml-text-formatting-after-known-node',
        'xml',
        510,
        static function (YDoc $doc): void {
            $doc->getXmlFragment('xml')->insertElement(0, 'p')->insertText(0, '');
        },
        static function (YDoc $doc): void {
            $text = $doc->getXmlFragment('xml')->insertElement(0, 'p')->insertText(0, '');
            $text->insert(0, 'Hello');
            $text->format(1, 3, ['bold' => true]);
        }
    ),
    partial_diff_case(
        'php-partial-xml-text-utf16-suffix',
        'xml',
        522,
        static function (YDoc $doc): void {
            $doc->getXmlFragment('xml')->insertElement(0, 'p')->insertText(0, 'A');
        },
        static function (YDoc $doc): void {
            $doc->getXmlFragment('xml')->insertElement(0, 'p')->insertText(0, 'A😀C');
        }
    ),
    partial_diff_case(
        'php-partial-xml-text-formatted-embed-suffix',
        'xml',
        533,
        static function (YDoc $doc): void {
            $text = $doc->getXmlFragment('xml')->insertElement(0, 'p')->insertText(0, '');
            $text->insert(0, 'A😀');
        },
        static function (YDoc $doc): void {
            $text = $doc->getXmlFragment('xml')->insertElement(0, 'p')->insertText(0, '');
            $text->insert(0, 'A😀CD');
            $text->format(1, 3, ['bold' => true]);
            $text->insertEmbed(4, ['mention' => 'Ada'], ['data-id' => 7]);
            $text->insert(5, '!');
            $text->delete(3, 1);
        }
    ),
    partial_diff_case(
        'php-partial-xml-attributes-after-known-element',
        'xml',
        511,
        static function (YDoc $doc): void {
            $doc->getXmlFragment('xml')->insertElement(0, 'p');
        },
        static function (YDoc $doc): void {
            $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
            $paragraph->setAttribute('class', 'lead');
            $paragraph->setAttribute('class', 'quiet');
            $paragraph->setAttribute('data-id', '42');
        }
    ),
    partial_diff_case(
        'php-partial-xml-attribute-delete-only-after-known-key',
        'xml',
        527,
        static function (YDoc $doc): void {
            $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
            $paragraph->setAttribute('class', 'lead');
            $paragraph->setAttribute('data-id', '42');
        },
        static function (YDoc $doc): void {
            $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
            $paragraph->setAttribute('class', 'lead');
            $paragraph->setAttribute('data-id', '42');
            $paragraph->removeAttribute('data-id');
        }
    ),
    partial_diff_case(
        'php-partial-xml-text-attributes-after-known-node',
        'xml',
        525,
        static function (YDoc $doc): void {
            $doc->getXmlFragment('xml')->insertText(0, 'Xml');
        },
        static function (YDoc $doc): void {
            $xmlText = $doc->getXmlFragment('xml')->insertText(0, 'Xml');
            $xmlText->setAttribute('lang', 'en');
            $xmlText->setAttribute('mark', ['color' => 'blue']);
        },
        static function (YDoc $doc): array {
            $xmlText = $doc->getXmlFragment('xml')->get(0);
            if (! $xmlText instanceof Yjs\YXmlText) {
                throw new \RuntimeException('Expected XML text node.');
            }

            return ['xmlTextAttributes' => $xmlText->getAttributes()];
        }
    ),
    partial_diff_case(
        'php-partial-xml-text-attribute-replace-delete-after-known-keys',
        'xml',
        530,
        static function (YDoc $doc): void {
            $xmlText = $doc->getXmlFragment('xml')->insertText(0, 'Xml');
            $xmlText->setAttribute('lang', 'en');
            $xmlText->setAttribute('mark', ['color' => 'blue']);
        },
        static function (YDoc $doc): void {
            $xmlText = $doc->getXmlFragment('xml')->insertText(0, 'Xml');
            $xmlText->setAttribute('lang', 'en');
            $xmlText->setAttribute('mark', ['color' => 'blue']);
            $xmlText->setAttribute('lang', 'fr');
            $xmlText->removeAttribute('mark');
            $xmlText->setAttribute('tone', 'warm');
        },
        static function (YDoc $doc): array {
            $xmlText = $doc->getXmlFragment('xml')->get(0);
            if (! $xmlText instanceof Yjs\YXmlText) {
                throw new \RuntimeException('Expected XML text node.');
            }

            return ['xmlTextAttributes' => $xmlText->getAttributes()];
        }
    ),
    partial_diff_case(
        'php-partial-xml-text-shared-attributes-after-known-types',
        'xml',
        538,
        static function (YDoc $doc): void {
            $xmlText = $doc->getXmlFragment('xml')->insertText(0, 'Xml');
            $xmlText->setText('body');
            $xmlText->setArray('items');
            $xmlText->setXmlElement('element', 'span');
            $xmlText->setXmlText('text');
            $xmlText->setXmlFragment('fragment');
        },
        static function (YDoc $doc): void {
            $xmlText = $doc->getXmlFragment('xml')->insertText(0, 'Xml');
            $body = $xmlText->setText('body');
            $items = $xmlText->setArray('items');
            $element = $xmlText->setXmlElement('element', 'span');
            $attributeText = $xmlText->setXmlText('text');
            $fragment = $xmlText->setXmlFragment('fragment');

            $body->insert(0, 'Body text');
            $items->insert(0, ['A', 'B']);
            $element->appendText('Element');
            $attributeText->insert(0, 'Xml text');
            $fragment->appendText('Frag');
        },
        static function (YDoc $doc): array {
            $xmlText = $doc->getXmlFragment('xml')->get(0);
            if (! $xmlText instanceof Yjs\YXmlText) {
                throw new \RuntimeException('Expected XML text.');
            }

            return ['xmlTextAttributes' => $xmlText->getAttributes()];
        }
    ),
    partial_diff_case(
        'php-partial-xml-children-after-known-element',
        'xml',
        512,
        static function (YDoc $doc): void {
            $doc->getXmlFragment('xml')->insertElement(0, 'root');
        },
        static function (YDoc $doc): void {
            $root = $doc->getXmlFragment('xml')->insertElement(0, 'root');
            $root->insertText(0, 'A');
            $root->insertElement(1, 'br');
        }
    ),
    partial_diff_case(
        'php-partial-xml-children-delete-reinsert-after-known-full-struct',
        'xml',
        519,
        static function (YDoc $doc): void {
            $root = $doc->getXmlFragment('xml')->insertElement(0, 'root');
            $root->insertElement(0, 'a');
            $root->insertElement(1, 'b');
            $root->insertElement(2, 'c');
        },
        static function (YDoc $doc): void {
            $root = $doc->getXmlFragment('xml')->insertElement(0, 'root');
            $root->insertElement(0, 'a');
            $root->insertElement(1, 'b');
            $root->insertElement(2, 'c');
            $root->delete(1, 1);
            $root->insertElement(1, 'x');
        }
    ),
    partial_diff_case(
        'php-partial-xml-known-element-text-and-child-replace',
        'xml',
        549,
        static function (YDoc $doc): void {
            $root = $doc->getXmlFragment('xml')->insertElement(0, 'root');
            $root->insertText(0, 'A');
            $root->insertElement(1, 'em')->appendText('B');
            $root->insertText(2, 'T');
        },
        static function (YDoc $doc): void {
            $root = $doc->getXmlFragment('xml')->insertElement(0, 'root');
            $text = $root->insertText(0, 'A');
            $root->insertElement(1, 'em')->appendText('B');
            $root->insertText(2, 'T');
            $text->insert(1, '!');
            $root->delete(1, 1);
            $root->insertElement(1, 'strong')->appendText('C');
        },
        static fn (YDoc $doc): array => [
            'xmlTextDelta' => [
                ['insert' => 'A!'],
            ],
        ]
    ),
    (static function (): array {
        $fixture = concurrent_fixture_case('xml-concurrent-delete-element-format-child');
        $updates = [];
        foreach ($fixture['updatesV1'] as $encodedUpdate) {
            $update = base64_decode((string) $encodedUpdate, true);
            if (! is_string($update)) {
                throw new RuntimeException('Invalid concurrent fixture update.');
            }
            $updates[] = $update;
        }

        $prefix = new YDoc();
        $prefix->getXmlFragment('xml');
        $prefix->applyUpdateV1($updates[2]);
        $prefix->applyUpdateV1($updates[1]);

        $full = new YDoc();
        $full->getXmlFragment('xml');
        $full->applyUpdateV1($updates[2]);
        $full->applyUpdateV1($updates[1]);
        $full->applyUpdateV1($updates[0]);

        return partial_diff_case_with_docs(
            'php-partial-xml-deleted-parent-child-format-diff',
            'xml',
            $prefix,
            $full
        );
    })(),
    partial_diff_case(
        'php-partial-xml-root-delete-reinsert-after-known-full-struct',
        'xml',
        520,
        static function (YDoc $doc): void {
            $xml = $doc->getXmlFragment('xml');
            $xml->insertElement(0, 'a');
            $xml->insertElement(1, 'b');
            $xml->insertElement(2, 'c');
        },
        static function (YDoc $doc): void {
            $xml = $doc->getXmlFragment('xml');
            $xml->insertElement(0, 'a');
            $xml->insertElement(1, 'b');
            $xml->insertElement(2, 'c');
            $xml->delete(1, 1);
            $xml->insertElement(1, 'x');
        }
    ),
    partial_diff_case(
        'php-partial-xml-hook-map-after-known-hook',
        'xml',
        513,
        static function (YDoc $doc): void {
            $doc->getXmlFragment('xml')->insertHook(0, 'mention');
        },
        static function (YDoc $doc): void {
            $hook = $doc->getXmlFragment('xml')->insertHook(0, 'mention');
            $hook->set('role', 'base');
            $hook->set('label', 'Ada');
        },
        static function (YDoc $doc): array {
            $hook = $doc->getXmlFragment('xml')->get(0);

            return ['hookJson' => $hook instanceof Yjs\YXmlHook ? $hook->toJSON() : null];
        }
    ),
    partial_diff_case(
        'php-partial-xml-hook-map-delete-only-after-known-key',
        'xml',
        528,
        static function (YDoc $doc): void {
            $hook = $doc->getXmlFragment('xml')->insertHook(0, 'mention');
            $hook->set('role', 'base');
            $hook->set('label', 'Ada');
        },
        static function (YDoc $doc): void {
            $hook = $doc->getXmlFragment('xml')->insertHook(0, 'mention');
            $hook->set('role', 'base');
            $hook->set('label', 'Ada');
            $hook->delete('label');
        },
        static function (YDoc $doc): array {
            $hook = $doc->getXmlFragment('xml')->get(0);

            return ['hookJson' => $hook instanceof Yjs\YXmlHook ? $hook->toJSON() : null];
        }
    ),
    partial_diff_case(
        'php-partial-xml-hook-map-replace-after-known-key',
        'xml',
        529,
        static function (YDoc $doc): void {
            $hook = $doc->getXmlFragment('xml')->insertHook(0, 'mention');
            $hook->set('role', 'base');
            $hook->set('label', 'Ada');
        },
        static function (YDoc $doc): void {
            $hook = $doc->getXmlFragment('xml')->insertHook(0, 'mention');
            $hook->set('role', 'base');
            $hook->set('label', 'Ada');
            $hook->set('label', 'Grace');
            $hook->set('active', true);
        },
        static function (YDoc $doc): array {
            $hook = $doc->getXmlFragment('xml')->get(0);

            return ['hookJson' => $hook instanceof Yjs\YXmlHook ? $hook->toJSON() : null];
        }
    ),
];

$decodedStructCases = [
    decoded_struct_case('php-raw-gc-struct', [
        [
            'type' => 'GC',
            'id' => ['client' => 601, 'clock' => 0],
            'length' => 3,
        ],
    ]),
    decoded_struct_case('php-raw-skip-struct', [
        [
            'type' => 'Skip',
            'id' => ['client' => 602, 'clock' => 0],
            'length' => 2,
        ],
    ]),
    decoded_struct_case('php-raw-content-deleted-struct', [
        [
            'type' => 'Item',
            'id' => ['client' => 603, 'clock' => 0],
            'length' => 3,
            'origin' => null,
            'rightOrigin' => null,
            'parent' => 'array',
            'parentSub' => null,
            'content' => [
                'type' => 'ContentDeleted',
                'length' => 3,
            ],
        ],
    ], [
        603 => [['clock' => 0, 'length' => 3]],
    ]),
    decoded_struct_case('php-raw-special-number-any-struct', [
        [
            'type' => 'Item',
            'id' => ['client' => 604, 'clock' => 0],
            'length' => 5,
            'origin' => null,
            'rightOrigin' => null,
            'parent' => 'array',
            'parentSub' => null,
            'content' => [
                'type' => 'ContentAny',
                'values' => [
                    Yjs\UndefinedValue::instance(),
                    NAN,
                    INF,
                    -INF,
                    ['nested' => NAN],
                ],
            ],
        ],
    ]) + [
        'contentAnyValues' => [
            ['type' => 'Undefined'],
            ['type' => 'Number', 'value' => 'NaN'],
            ['type' => 'Number', 'value' => 'Infinity'],
            ['type' => 'Number', 'value' => '-Infinity'],
            ['nested' => ['type' => 'Number', 'value' => 'NaN']],
        ],
        'arrayValues' => [
            ['type' => 'Undefined'],
            ['type' => 'Number', 'value' => 'NaN'],
            ['type' => 'Number', 'value' => 'Infinity'],
            ['type' => 'Number', 'value' => '-Infinity'],
            ['nested' => ['type' => 'Number', 'value' => 'NaN']],
        ],
    ],
];

echo json_encode([
    'cases' => $cases,
    'partialDiffCases' => $partialDiffCases,
    'decodedStructCases' => $decodedStructCases,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
