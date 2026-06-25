<?php

declare(strict_types=1);

namespace Yjs\Tests;

use PHPUnit\Framework\TestCase;
use Yjs\UndefinedValue;
use Yjs\Update\DecodedUpdate;
use Yjs\YArray;
use Yjs\YNestedArray;
use Yjs\YNestedMap;
use Yjs\YNestedText;
use Yjs\YNestedXmlFragment;
use Yjs\YDoc;
use Yjs\YSubdoc;
use Yjs\YXmlElement;
use Yjs\YXmlFragment;
use Yjs\YXmlHook;
use Yjs\YXmlText;

final class YDocMutationTest extends TestCase
{
    public function testNativeDocExposesClientIdAndGuid(): void
    {
        $doc = new YDoc(42, 'php-doc-guid');

        self::assertSame(42, $doc->clientID());
        self::assertSame('php-doc-guid', $doc->guid());

        $doc->setClientID(43);
        $doc->getText('content')->insert(0, 'A');

        self::assertSame(43, $doc->clientID());
        self::assertSame([43 => 1], $doc->getStateVector());
    }

    public function testNativeDocClientIdCannotChangeAfterContentExists(): void
    {
        $doc = new YDoc(44);
        $doc->getArray('array')->insert(0, ['A']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('YDoc clientID cannot be changed after content has been created or applied.');

        $doc->setClientID(45);
    }

    public function testNativeTextInsertDeleteCanBeEncodedAndApplied(): void
    {
        $doc = new YDoc(100);
        $text = $doc->getText('content');
        $text->insert(0, 'Hello');
        $text->insert(5, ' Yjs');
        $text->delete(5, 1);

        self::assertSame('HelloYjs', $doc->getText('content')->toString());

        $target = new YDoc();
        $target->applyUpdateV1($doc->encodeStateAsUpdateV1());

        self::assertSame(['content' => 'HelloYjs'], $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeTextAttributesCanBeEncodedAndApplied(): void
    {
        $doc = new YDoc(180);
        $text = $doc->getText('content');
        $text->insert(0, 'Text');
        $text->setAttribute('lang', 'en');
        $snapshot = $doc->snapshot();
        $text->setAttributes(['lang' => 'fr', 'mark' => ['color' => 'green'], 'temporary' => true]);
        $text->removeAttributes(['temporary']);

        self::assertSame('Text', $text->toString());
        self::assertSame([['insert' => 'Text']], $text->toDelta());
        self::assertSame(['lang' => 'fr', 'mark' => ['color' => 'green']], $text->getAttributes());
        self::assertSame('fr', $text->getAttribute('lang'));
        self::assertTrue($text->hasAttribute('mark'));
        self::assertFalse($text->hasAttribute('temporary'));
        self::assertSame(['lang' => 'en'], $text->getAttributesSnapshot($snapshot));
        self::assertSame('en', $text->getAttributeSnapshot('lang', $snapshot));
        self::assertTrue($text->hasAttributeSnapshot('lang', $snapshot));
        self::assertFalse($text->hasAttributeSnapshot('mark', $snapshot));

        $target = new YDoc();
        $target->getText('content');
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame($text->getAttributes(), $target->getText('content')->getAttributes());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeTextAttributeOnlyUpdateResolvesAsTextWhenTypeIsKnown(): void
    {
        $doc = new YDoc(181);
        $doc->getText('content')->setAttribute('lang', 'en');

        $target = new YDoc();
        $targetText = $target->getText('content');
        $target->applyUpdateV1($doc->encodeStateAsUpdateV1());

        self::assertSame(['content' => ''], $target->toJSON());
        self::assertSame('', $targetText->toString());
        self::assertSame([], $targetText->toDelta());
        self::assertSame(['lang' => 'en'], $targetText->getAttributes());
    }

    public function testNativeNestedTextAttributesCanBeEncodedAndApplied(): void
    {
        $doc = new YDoc(182);
        $text = $doc->getArray('array')->insertText(0);
        $text->insert(0, 'Nested');
        $text->setAttribute('lang', 'en');
        $snapshot = $doc->snapshot();
        $text->setAttributes(['lang' => 'fr', 'mark' => ['color' => 'green'], 'temporary' => true]);
        $text->removeAttributes(['temporary']);

        self::assertSame('Nested', $text->toString());
        self::assertSame([['insert' => 'Nested']], $text->toDelta());
        self::assertSame(['lang' => 'fr', 'mark' => ['color' => 'green']], $text->getAttributes());
        self::assertSame('fr', $text->getAttribute('lang'));
        self::assertTrue($text->hasAttribute('mark'));
        self::assertFalse($text->hasAttribute('temporary'));
        self::assertSame(['lang' => 'en'], $text->getAttributesSnapshot($snapshot));
        self::assertSame('en', $text->getAttributeSnapshot('lang', $snapshot));
        self::assertTrue($text->hasAttributeSnapshot('lang', $snapshot));
        self::assertFalse($text->hasAttributeSnapshot('mark', $snapshot));

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());
        $targetText = new YNestedText($target, $text->idKey(), '');

        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame($text->getAttributes(), $targetText->getAttributes());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeArrayInsertCanBeEncodedAndApplied(): void
    {
        $doc = new YDoc(101);
        $array = $doc->getArray('array');
        $array->insert(0, [1, 'two']);
        $array->insert(2, [true]);

        $target = new YDoc();
        $target->applyUpdateV1($doc->encodeStateAsUpdateV1());

        self::assertSame(['array' => [1, 'two', true]], $target->toJSON());
    }

    public function testNativeAnySpecialNumbersCanBeEncodedAndApplied(): void
    {
        $doc = new YDoc(102);
        $doc->getArray('array')->insert(0, [NAN, INF, -INF]);
        $doc->getMap('map')->set('nested', ['nan' => NAN]);

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        $array = $target->getArray('array')->toArray();
        self::assertCount(3, $array);
        self::assertIsFloat($array[0]);
        self::assertNan($array[0]);
        self::assertSame(INF, $array[1]);
        self::assertSame(-INF, $array[2]);

        $nested = $target->getMap('map')->get('nested');
        self::assertIsArray($nested);
        self::assertArrayHasKey('nan', $nested);
        self::assertIsFloat($nested['nan']);
        self::assertNan($nested['nan']);
    }

    public function testNativeMapUndefinedValueCanBeEncodedAndApplied(): void
    {
        $doc = new YDoc(103);
        $array = $doc->getArray('array');
        $array->insert(0, [UndefinedValue::instance(), null]);
        $map = $doc->getMap('map');
        $map->set('u', UndefinedValue::instance());
        $map->set('n', null);

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());
        $targetArray = $target->getArray('array')->toArray();
        $targetMap = $target->getMap('map');

        self::assertCount(2, $targetArray);
        self::assertInstanceOf(UndefinedValue::class, $targetArray[0]);
        self::assertNull($targetArray[1]);
        self::assertTrue($targetMap->has('u'));
        self::assertTrue($targetMap->has('n'));
        self::assertInstanceOf(UndefinedValue::class, $targetMap->get('u'));
        self::assertNull($targetMap->get('n'));
    }

    public function testPartialContentAnyDiffPreservesUndefinedValues(): void
    {
        $prefix = new YDoc();
        $prefix->applyUpdateV1(DecodedUpdate::encodeV1([
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
                    'values' => ['A'],
                ],
            ],
        ]));

        $full = new YDoc();
        $full->applyUpdateV1(DecodedUpdate::encodeV1([
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
                    'values' => ['A', UndefinedValue::instance(), null, 'tail'],
                ],
            ],
        ]));

        $targetStateVector = $prefix->encodeStateVector();
        $diffV1 = $full->encodeStateAsUpdateV1($targetStateVector);
        $diffV2 = $full->encodeStateAsUpdateV2($targetStateVector);

        foreach ([DecodedUpdate::decodeV1($diffV1), DecodedUpdate::decodeV2($diffV2)] as $decoded) {
            self::assertSame(1, count($decoded['structs']));
            self::assertSame(['client' => 532, 'clock' => 1], $decoded['structs'][0]['id']);
            self::assertSame(3, $decoded['structs'][0]['length']);
            self::assertSame('ContentAny', $decoded['structs'][0]['content']['type']);
            self::assertSame([UndefinedValue::instance(), null, 'tail'], $decoded['structs'][0]['content']['values']);
        }

        $target = new YDoc();
        $target->applyUpdateV1($prefix->encodeStateAsUpdateV1());
        $target->applyUpdateV1($diffV1);
        $targetArray = $target->getArray('array')->toArray();

        self::assertSame('A', $targetArray[0]);
        self::assertInstanceOf(UndefinedValue::class, $targetArray[1]);
        self::assertNull($targetArray[2]);
        self::assertSame('tail', $targetArray[3]);

        $targetV2 = new YDoc();
        $targetV2->applyUpdateV2($prefix->encodeStateAsUpdateV2());
        $targetV2->applyUpdateV2($diffV2);
        $targetV2Array = $targetV2->getArray('array')->toArray();

        self::assertSame('A', $targetV2Array[0]);
        self::assertInstanceOf(UndefinedValue::class, $targetV2Array[1]);
        self::assertNull($targetV2Array[2]);
        self::assertSame('tail', $targetV2Array[3]);
    }

    public function testPartialContentJsonDiffPreservesUndefinedValues(): void
    {
        $prefix = new YDoc();
        $prefix->applyUpdateV1(DecodedUpdate::encodeV1([
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
                    'values' => ['A'],
                ],
            ],
        ]));

        $full = new YDoc();
        $full->applyUpdateV1(DecodedUpdate::encodeV1([
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
                    'values' => ['A', UndefinedValue::instance(), null, 'tail'],
                ],
            ],
        ]));

        $targetStateVector = $prefix->encodeStateVector();
        $diffV1 = $full->encodeStateAsUpdateV1($targetStateVector);
        $diffV2 = $full->encodeStateAsUpdateV2($targetStateVector);

        foreach ([DecodedUpdate::decodeV1($diffV1), DecodedUpdate::decodeV2($diffV2)] as $decoded) {
            self::assertSame(1, count($decoded['structs']));
            self::assertSame(['client' => 533, 'clock' => 1], $decoded['structs'][0]['id']);
            self::assertSame(3, $decoded['structs'][0]['length']);
            self::assertSame('ContentJSON', $decoded['structs'][0]['content']['type']);
            self::assertSame([UndefinedValue::instance(), null, 'tail'], $decoded['structs'][0]['content']['values']);
        }

        $target = new YDoc();
        $target->applyUpdateV1($prefix->encodeStateAsUpdateV1());
        $target->applyUpdateV1($diffV1);
        $targetArray = $target->getArray('array')->toArray();

        self::assertSame('A', $targetArray[0]);
        self::assertInstanceOf(UndefinedValue::class, $targetArray[1]);
        self::assertNull($targetArray[2]);
        self::assertSame('tail', $targetArray[3]);

        $targetV2 = new YDoc();
        $targetV2->applyUpdateV2($prefix->encodeStateAsUpdateV2());
        $targetV2->applyUpdateV2($diffV2);
        $targetV2Array = $targetV2->getArray('array')->toArray();

        self::assertSame('A', $targetV2Array[0]);
        self::assertInstanceOf(UndefinedValue::class, $targetV2Array[1]);
        self::assertNull($targetV2Array[2]);
        self::assertSame('tail', $targetV2Array[3]);
    }

    public function testNativeSharedTypeReadHelpersTrackMutations(): void
    {
        $doc = new YDoc(125);

        $array = $doc->getArray('array');
        self::assertSame('array', $array->name());
        $array->push(['A']);
        $array->push(['B']);
        self::assertSame(2, $array->length());
        self::assertCount(2, $array);
        self::assertSame('B', $array->get(1));
        self::assertSame(['A', 'B'], iterator_to_array($array));
        self::assertSame(['A', 'B'], $array->slice());
        self::assertSame(['B'], $array->slice(-1));
        self::assertSame(['A'], $array->slice(0, -1));
        self::assertSame(['0:A', '1:B'], $array->map(static fn (mixed $value, int $index): string => $index . ':' . $value));
        self::assertSame(['B'], $array->filter(static fn (mixed $value, int $index): bool => $index === 1 && $value === 'B'));
        $arrayVisited = [];
        $array->forEach(static function (mixed $value, int $index) use (&$arrayVisited): void {
            $arrayVisited[] = [$index, $value];
        });
        self::assertSame([[0, 'A'], [1, 'B']], $arrayVisited);

        $map = $doc->getMap('map');
        self::assertSame('map', $map->name());
        $map->set('present', null);
        $map->set('count', 2);
        self::assertTrue($map->has('present'));
        self::assertCount(2, $map);
        self::assertSame(['present' => null, 'count' => 2], iterator_to_array($map));
        self::assertSame(['present', 'count'], $map->keys());
        self::assertSame([null, 2], $map->values());
        self::assertSame(['present' => null, 'count' => 2], $map->entries());
        $mapVisited = [];
        $map->forEach(static function (mixed $value, string $key) use (&$mapVisited): void {
            $mapVisited[$key] = $value;
        });
        self::assertSame(['present' => null, 'count' => 2], $mapVisited);

        $text = $doc->getText('content');
        self::assertSame('content', $text->name());
        $text->insert(0, 'A😀B');
        self::assertSame(4, $text->length());
        self::assertCount(4, $text);
        self::assertSame('A😀B', (string) $text);

        $nestedArray = $array->insertArray(2);
        $nestedArray->push([1, 2]);
        self::assertSame(2, $nestedArray->length());
        self::assertCount(2, $nestedArray);
        self::assertSame(2, $nestedArray->get(1));
        self::assertSame([1, 2], iterator_to_array($nestedArray));
        self::assertSame([2], $nestedArray->slice(1));
        self::assertSame(['0=1', '1=2'], $nestedArray->map(static fn (mixed $value, int $index): string => $index . '=' . $value));
        self::assertSame([2], $nestedArray->filter(static fn (mixed $value): bool => $value === 2));
        $nestedArrayVisited = [];
        $nestedArray->forEach(static function (mixed $value, int $index) use (&$nestedArrayVisited): void {
            $nestedArrayVisited[] = [$index, $value];
        });
        self::assertSame([[0, 1], [1, 2]], $nestedArrayVisited);

        $nestedMap = $map->setMap('nested');
        $nestedMap->set('ok', true);
        self::assertTrue($nestedMap->has('ok'));
        self::assertCount(1, $nestedMap);
        self::assertSame(['ok' => true], iterator_to_array($nestedMap));
        self::assertSame(['ok' => true], $nestedMap->entries());
        $nestedMapVisited = [];
        $nestedMap->forEach(static function (mixed $value, string $key) use (&$nestedMapVisited): void {
            $nestedMapVisited[$key] = $value;
        });
        self::assertSame(['ok' => true], $nestedMapVisited);

        $nestedText = $array->insertText(3);
        $nestedText->insert(0, '😀');
        self::assertSame(2, $nestedText->length());
        self::assertCount(2, $nestedText);
        self::assertSame('😀', (string) $nestedText);
    }

    public function testNativeSharedTypeWrappersReadFreshDocumentState(): void
    {
        $doc = new YDoc(149);

        $text = $doc->getText('content');
        $sameText = $doc->getText('content');
        $text->insert(0, 'A');
        self::assertSame('A', $sameText->toString());
        $sameText->insert(1, 'B');
        self::assertSame('AB', $text->toString());
        self::assertSame(2, $text->length());

        $array = $doc->getArray('array');
        $sameArray = $doc->getArray('array');
        $array->push(['A']);
        self::assertSame(['A'], $sameArray->toArray());
        $sameArray->push(['B']);
        self::assertSame(['A', 'B'], $array->toArray());
        self::assertSame('B', $array->get(1));
        self::assertSame(['B'], $array->slice(1));
        self::assertSame(['A', 'B'], iterator_to_array($sameArray));

        $map = $doc->getMap('map');
        $sameMap = $doc->getMap('map');
        $map->set('first', 'A');
        self::assertTrue($sameMap->has('first'));
        self::assertSame('A', $sameMap->get('first'));
        $sameMap->set('second', 'B');
        self::assertSame(['first' => 'A', 'second' => 'B'], $map->toArray());
        self::assertSame(['first', 'second'], $map->keys());
        self::assertSame(['A', 'B'], $sameMap->values());

        $sameArray->clear();
        $sameMap->clear();

        self::assertSame([], $array->toArray());
        self::assertSame([], $map->toArray());
    }

    public function testNativeArrayConvenienceMutationApis(): void
    {
        $doc = new YDoc(127);
        $array = $doc->getArray('array');
        $array->push(['B']);
        $array->unshift(['A']);
        $array->push(['C']);
        self::assertSame(['A', 'B', 'C'], $array->toArray());
        self::assertSame('C', $array->pop());
        $array->push(['C']);
        self::assertSame('A', $array->shift());
        $array->unshift(['A']);

        $nested = $array->insertArray(3);
        $nested->push([2]);
        $nested->unshift([1]);
        self::assertSame([1, 2], $nested->toArray());
        self::assertSame(2, $nested->pop());
        $nested->push([2]);
        self::assertSame(1, $nested->shift());
        $nested->unshift([1]);

        $nested->clear();
        self::assertNull($nested->pop());
        self::assertNull($nested->shift());
        $nested->push(['reset']);
        $array->delete(1);

        self::assertSame(['A', 'C', ['reset']], $array->toArray());

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame(['array' => ['A', 'C', ['reset']]], $target->toJSON());
    }

    public function testNativeArrayDeleteAllUsesOriginalIndexes(): void
    {
        $doc = new YDoc(173);
        $array = $doc->getArray('array');
        $array->insert(0, ['A', 'B', 'C', 'D', 'E']);
        $array->deleteAll([1, 3, 1]);

        $nested = $array->insertArray(3);
        $nested->insert(0, [1, 2, 3, 4, 5]);
        $nested->deleteAll([0, 2, 4]);

        self::assertSame(['A', 'C', 'E', [2, 4]], $array->toArray());
        self::assertSame([2, 4], $nested->toArray());

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame(['array' => ['A', 'C', 'E', [2, 4]]], $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeArrayDeleteAllRejectsInvalidIndexes(): void
    {
        $doc = new YDoc(173);
        $array = $doc->getArray('array');
        $array->insert(0, ['A']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('YArray deleteAll only supports valid integer indexes.');

        $array->deleteAll([1]);
    }

    public function testNativeXmlDeleteAllUsesOriginalIndexes(): void
    {
        $doc = new YDoc(174);
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

        self::assertSame('A<section><strong>y</strong>[object Object]</section>E', $xml->toString());
        self::assertSame('<section><strong>y</strong>[object Object]</section>', $section->toString());

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame(['xml' => 'A<section><strong>y</strong>[object Object]</section>E'], $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeXmlDeleteAllRejectsInvalidIndexes(): void
    {
        $doc = new YDoc(174);
        $xml = $doc->getXmlFragment('xml');
        $xml->appendText('A');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('YXmlFragment deleteAll only supports valid integer indexes.');

        $xml->deleteAll([1]);
    }

    public function testNativeCollectionDeletesDefaultToSingleItem(): void
    {
        $doc = new YDoc(172);
        $array = $doc->getArray('array');
        $array->insert(0, ['A', 'B', 'C']);
        $array->delete(1);

        $nested = $array->insertArray(2);
        $nested->insert(0, [1, 2, 3]);
        $nested->delete(1);

        $xml = $doc->getXmlFragment('xml');
        $xml->appendText('A');
        $paragraph = $xml->appendElement('p');
        $xml->appendText('B');
        $xml->delete(2);

        $paragraph->appendText('x');
        $paragraph->appendElement('strong')->appendText('y');
        $paragraph->appendText('z');
        $paragraph->delete(1);

        self::assertSame([
            'array' => ['A', 'C', [1, 3]],
            'xml' => 'A<p>xz</p>',
        ], $doc->toJSON());

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeTextDeletesDefaultToSingleCodeUnit(): void
    {
        $doc = new YDoc(174);
        $text = $doc->getText('content');
        $text->insert(0, 'ABC');
        $text->delete(1);

        $array = $doc->getArray('array');
        $nestedText = $array->insertText(0);
        $nestedText->insert(0, 'XYZ');
        $nestedText->delete(1);

        $xmlText = $doc->getXmlFragment('xml')->insertText(0, 'abc');
        $xmlText->delete(1);

        self::assertSame([
            'content' => 'AC',
            'array' => ['XZ'],
            'xml' => 'ac',
        ], $doc->toJSON());

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeArrayPopAndShiftNotifyDeleteDeltas(): void
    {
        $doc = new YDoc(169);
        $array = $doc->getArray('array');
        $array->insert(0, ['A', 'B', 'C']);
        $rootEvents = [];
        $observerId = $array->observe(static function (array $event) use (&$rootEvents): void {
            $rootEvents[] = $event;
        });

        self::assertSame('C', $array->pop());
        self::assertSame('A', $array->shift());
        $array->unobserve($observerId);

        $nested = $array->insertArray(1);
        $nested->insert(0, [1, 2, 3]);
        $nestedEvents = [];
        $nested->observe(static function (array $event) use (&$nestedEvents): void {
            $nestedEvents[] = $event;
        });

        self::assertSame(3, $nested->pop());
        self::assertSame(1, $nested->shift());

        self::assertCount(2, $rootEvents);
        self::assertSame([['retain' => 2], ['delete' => 1]], $rootEvents[0]['changes']['delta']);
        self::assertSame([['delete' => 1]], $rootEvents[1]['changes']['delta']);
        self::assertSame(['B'], $rootEvents[1]['newValue']);

        self::assertCount(2, $nestedEvents);
        self::assertSame([['retain' => 2], ['delete' => 1]], $nestedEvents[0]['changes']['delta']);
        self::assertSame([['delete' => 1]], $nestedEvents[1]['changes']['delta']);
        self::assertSame([2], $nestedEvents[1]['newValue']);

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame(['array' => ['B', [2]]], $target->toJSON());
    }

    public function testNativeArraySpliceMutationApis(): void
    {
        $doc = new YDoc(168);
        $array = $doc->getArray('array');
        $array->insert(0, ['A', 'B', 'C', 'D']);

        self::assertSame(['B', 'C'], $array->splice(1, 2, ['X', 'Y']));
        self::assertSame(['A', 'X', 'Y', 'D'], $array->toArray());
        self::assertSame(['D'], $array->splice(-1, 10, ['Z']));
        self::assertSame(['A', 'X', 'Y', 'Z'], $array->toArray());
        self::assertSame([], $array->splice(99, 1, ['tail']));
        self::assertSame(['A', 'X', 'Y', 'Z', 'tail'], $array->toArray());

        $nested = $array->insertArray(5);
        $nested->insert(0, [1, 2, 3, 4]);
        self::assertSame([2, 3], $nested->splice(1, 2, ['nested']));
        self::assertSame([1, 'nested', 4], $nested->toArray());

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame(['array' => ['A', 'X', 'Y', 'Z', 'tail', [1, 'nested', 4]]], $target->toJSON());
    }

    public function testNativeArraySetAndReplaceMutationApis(): void
    {
        $doc = new YDoc(169);
        $array = $doc->getArray('array');
        $array->insert(0, ['A', 'B', 'C']);

        $array->set(1, 'Beta');
        self::assertSame('C', $array->replace(2, 'Gamma'));
        self::assertSame(['A', 'Beta', 'Gamma'], $array->toArray());

        $nested = $array->insertArray(3);
        $nested->insert(0, [1, 2, 3]);
        $nested->set(0, 'one');
        self::assertSame(2, $nested->replace(1, 'two'));
        self::assertSame(['one', 'two', 3], $nested->toArray());

        foreach ([
            [static fn () => $array->set(99, 'nope'), 'YArray set index is out of bounds.'],
            [static fn () => $array->replace(-1, 'nope'), 'YArray replace index is out of bounds.'],
            [static fn () => $nested->set(99, 'nope'), 'YNestedArray set index is out of bounds.'],
            [static fn () => $nested->replace(-1, 'nope'), 'YNestedArray replace index is out of bounds.'],
        ] as [$operation, $message]) {
            try {
                $operation();
                self::fail('Expected array replacement bounds check to throw.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame(['array' => ['A', 'Beta', 'Gamma', ['one', 'two', 3]]], $target->toJSON());
    }

    public function testNativeMapConvenienceMutationApis(): void
    {
        $doc = new YDoc(128);
        $map = $doc->getMap('map');
        $map->setAll(['a' => 1, 'b' => 2]);
        self::assertSame(2, $map->size());
        self::assertSame(['a' => 'a:1', 'b' => 'b:2'], $map->map(
            static fn (mixed $value, string $key): string => sprintf('%s:%s', $key, $value)
        ));
        self::assertSame(['b' => 2], $map->filter(
            static fn (mixed $value): bool => $value > 1
        ));
        $map->deleteAll(['a']);
        self::assertSame(['b' => 2], $map->entries());

        $map->clear();
        self::assertSame(0, $map->size());
        self::assertSame([], $map->entries());

        $nested = $map->setMap('nested');
        $nested->setAll(['temp' => false, 'count' => 1]);
        self::assertSame(2, $nested->size());
        self::assertSame(['temp' => 'temp:no', 'count' => 'count:yes'], $nested->map(
            static fn (mixed $value, string $key): string => sprintf('%s:%s', $key, $value ? 'yes' : 'no')
        ));
        self::assertSame(['count' => 1], $nested->filter(
            static fn (mixed $value): bool => is_int($value)
        ));
        $nested->deleteAll(['temp']);
        self::assertSame(['count' => 1], $nested->entries());

        $nested->clear();
        $nested->setAll(['ok' => true]);
        $map->setAll(['after' => 'clear']);

        $target = new YDoc();
        $target->applyUpdateV1($doc->encodeStateAsUpdateV1());

        self::assertSame([
            'map' => [
                'nested' => ['ok' => true],
                'after' => 'clear',
            ],
        ], $target->toJSON());
    }

    public function testNativeArrayAccessMutationApisCanBeEncodedAndApplied(): void
    {
        $doc = new YDoc(150);
        $array = $doc->getArray('array');
        $array[] = 'A';
        $array[] = 'B';
        $array[1] = 'C';
        $array[2] = 'D';
        unset($array[0]);
        self::assertFalse(isset($array[2]));
        self::assertSame('C', $array[0]);
        self::assertSame(['C', 'D'], $array->toArray());

        $nestedArray = $array->insertArray(2);
        $nestedArray[] = 1;
        $nestedArray[] = 3;
        $nestedArray[1] = 2;
        unset($nestedArray[0]);
        self::assertSame([2], $nestedArray->toArray());

        $map = $doc->getMap('map');
        $map['title'] = 'Draft';
        $map['count'] = 1;
        $map['title'] = 'Published';
        $map['present'] = null;
        unset($map['count']);
        self::assertTrue(isset($map['present']));
        self::assertSame('Published', $map['title']);

        $nestedMap = $map->setMap('nested');
        $nestedMap['flag'] = false;
        $nestedMap['flag'] = true;
        $nestedMap['remove'] = 'gone';
        unset($nestedMap['remove']);
        self::assertTrue($nestedMap['flag']);
        self::assertFalse(isset($nestedMap['remove']));

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame([
            'map' => [
                'title' => 'Published',
                'present' => null,
                'nested' => ['flag' => true],
            ],
            'array' => ['C', 'D', [2]],
        ], $target->toJSON());
    }

    public function testNativeAppendPrependMutationApisCanBeEncodedAndApplied(): void
    {
        $doc = new YDoc(165);
        $items = $doc->getArray('items');
        $items->append('B');
        $items->prepend('A');
        $leadingMap = $items->prependMap();
        $leadingMap->set('side', 'start');
        $nestedArray = $items->appendArray();
        $nestedArray->append(2);
        $nestedArray->prepend(1);
        $nestedArray->prependMap()->set('before', true);
        $nestedArray->appendText()->append('deep');
        $nestedArray->appendMap()->set('after', true);
        $nestedArray->prependArray()->append('inner-head');
        $nestedArray->prependText()->append('inner-text');
        $nestedText = $items->appendText();
        $nestedText->append('Nested');
        $nestedText->prepend('>');
        $trailingMap = $items->appendMap();
        $trailingMap->set('side', 'end');
        $leadingArray = $items->prependArray();
        $leadingArray->append('head');
        $leadingText = $items->prependText();
        $leadingText->append('lead');

        $text = $doc->getText('content');
        $text->append('B');
        $text->prepend('A');
        $text->append('C', ['bold' => true]);

        $xmlText = $doc->getXmlFragment('xml')->insertText(0, '');
        $xmlText->append('Xml');
        $xmlText->prepend('>');

        self::assertSame([
            'lead',
            ['head'],
            ['side' => 'start'],
            'A',
            'B',
            ['inner-text', ['inner-head'], ['before' => true], 1, 2, 'deep', ['after' => true]],
            '>Nested',
            ['side' => 'end'],
        ], $items->toArray());
        self::assertSame('ABC', $text->toString());
        self::assertSame([
            ['insert' => 'AB'],
            ['insert' => 'C', 'attributes' => ['bold' => true]],
        ], $text->toDelta());
        self::assertSame('>Xml', $xmlText->toString());

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeArrayAndMapCanInsertBinaryContent(): void
    {
        $doc = new YDoc(123);
        $array = $doc->getArray('array');
        $array->insert(0, ['before']);
        $array->insertBinary(1, "\x01\x02\xff");
        $array->prependBinary("\x00");
        $array->appendBinary("\x7f");
        $map = $doc->getMap('map');
        $map->setBinary('bytes', "\x00\x7f\xff");

        self::assertSame([[0], 'before', [1, 2, 255], [127]], $doc->toJSON()['array']);
        self::assertSame(['bytes' => [0, 127, 255]], $doc->toJSON()['map']);

        $decoded = DecodedUpdate::decodeV1($doc->encodeStateAsUpdateV1());
        $binaryStructs = array_values(array_filter(
            $decoded['structs'],
            static fn (array $struct): bool => ($struct['content']['type'] ?? null) === 'ContentBinary'
        ));
        self::assertCount(4, $binaryStructs);

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeArraysCanBulkInsertBinaryContent(): void
    {
        $doc = new YDoc(194);
        $array = $doc->getArray('array');
        $array->insert(0, ['middle']);
        $array->prependBinaries(["\x00", "\x01"]);
        $array->insertBinaries(2, ["\x02\x03", "\xff"]);
        $array->appendBinaries(["\x7f"]);

        $nested = $array->appendArray();
        $nested->appendBinaries(["\x10"]);
        $nested->prependBinaries(["\x11", "\x12"]);
        $nested->insertBinaries(1, ["\x13"]);

        self::assertSame([
            [0],
            [1],
            [2, 3],
            [255],
            'middle',
            [127],
            [[17], [19], [18], [16]],
        ], $doc->toJSON()['array']);

        $decoded = DecodedUpdate::decodeV1($doc->encodeStateAsUpdateV1());
        $binaryStructs = array_values(array_filter(
            $decoded['structs'],
            static fn (array $struct): bool => ($struct['content']['type'] ?? null) === 'ContentBinary'
        ));
        self::assertCount(9, $binaryStructs);

        $target = new YDoc();
        $target->getArray('array');
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeArrayBulkBinaryInsertionsRejectInvalidValuesBeforeMutating(): void
    {
        $doc = new YDoc(195);
        $array = $doc->getArray('array');
        $array->appendBinary("\x01");

        try {
            $array->appendBinaries(["\x02", 3]);
            self::fail('Expected invalid root array bulk binary insertion to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('YArray insertBinaries only supports binary strings.', $exception->getMessage());
        }

        self::assertSame([[1]], $array->toJSON());

        $nested = $array->appendArray();
        $nested->appendBinary("\x03");

        try {
            $nested->insertBinaries(1, ["\x04", null]);
            self::fail('Expected invalid nested array bulk binary insertion to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('YNestedArray insertBinaries only supports binary strings.', $exception->getMessage());
        }

        self::assertSame([[1], [[3]]], $array->toJSON());
    }

    public function testNativeArrayAndMapCanInsertSubdocs(): void
    {
        $doc = new YDoc(146);
        $array = $doc->getArray('array');
        $arraySubdoc = $array->insertSubdoc(0, 'php-array-subdoc', ['meta' => ['kind' => 'array-note']]);
        $prependedSubdoc = $array->prependSubdoc('php-prepended-array-subdoc', ['meta' => ['position' => 'first']]);
        $appendedSubdoc = $array->appendSubdoc('php-appended-array-subdoc', ['meta' => ['position' => 'last']]);
        $mapSubdoc = $doc->getMap('map')->setSubdoc('child', 'php-map-subdoc', ['autoLoad' => true]);

        self::assertInstanceOf(YSubdoc::class, $arraySubdoc);
        self::assertSame('php-array-subdoc', $arraySubdoc->guid());
        self::assertSame(['kind' => 'array-note'], $arraySubdoc->meta());
        self::assertSame('php-prepended-array-subdoc', $prependedSubdoc->guid());
        self::assertSame(['position' => 'first'], $prependedSubdoc->meta());
        self::assertSame('php-appended-array-subdoc', $appendedSubdoc->guid());
        self::assertSame(['position' => 'last'], $appendedSubdoc->meta());
        self::assertInstanceOf(YSubdoc::class, $array->get(0));
        self::assertSame('php-prepended-array-subdoc', $array->get(0)->guid());
        self::assertSame('php-array-subdoc', $array->get(1)->guid());
        self::assertSame('php-appended-array-subdoc', $array->get(2)->guid());
        self::assertSame('php-prepended-array-subdoc', $array->getSubdoc(0)->guid());
        self::assertSame('php-array-subdoc', $array->getSubdoc(1)->guid());
        self::assertSame('php-appended-array-subdoc', $array->getSubdoc(2)->guid());
        self::assertNull($array->getSubdoc(99));
        self::assertSame('php-map-subdoc', $mapSubdoc->guid());
        self::assertTrue($mapSubdoc->shouldLoad());
        self::assertSame('php-map-subdoc', $doc->getMap('map')->get('child')->guid());
        self::assertSame('php-map-subdoc', $doc->getMap('map')->getSubdoc('child')->guid());
        self::assertNull($doc->getMap('map')->getSubdoc('missing'));
        self::assertSame([[], [], []], $doc->toJSON()['array']);
        self::assertSame(['child' => []], $doc->toJSON()['map']);

        $decoded = DecodedUpdate::decodeV1($doc->encodeStateAsUpdateV1());
        $subdocStructs = array_values(array_filter(
            $decoded['structs'],
            static fn (array $struct): bool => ($struct['content']['type'] ?? null) === 'ContentDoc'
        ));
        self::assertCount(4, $subdocStructs);

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame('php-prepended-array-subdoc', $target->getArray('array')->get(0)->guid());
        self::assertSame('php-array-subdoc', $target->getArray('array')->get(1)->guid());
        self::assertSame('php-appended-array-subdoc', $target->getArray('array')->get(2)->guid());
        self::assertSame('php-map-subdoc', $target->getMap('map')->get('child')->guid());
        self::assertSame('php-prepended-array-subdoc', $target->getArray('array')->getSubdoc(0)->guid());
        self::assertSame('php-map-subdoc', $target->getMap('map')->getSubdoc('child')->guid());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeSubdocAccessorsRejectNonSubdocValues(): void
    {
        $doc = new YDoc(148);
        $array = $doc->getArray('array');
        $array->insert(0, ['value']);
        $map = $doc->getMap('map');
        $map->set('value', 'text');
        $nestedArray = $array->insertArray(1);
        $nestedArray->insert(0, ['nested']);
        $nestedMap = $map->setMap('nested');
        $nestedMap->set('value', 'nested');

        foreach ([
            static fn () => $array->getSubdoc(0),
            static fn () => $map->getSubdoc('value'),
            static fn () => $nestedArray->getSubdoc(0),
            static fn () => $nestedMap->getSubdoc('value'),
        ] as $readSubdoc) {
            try {
                $readSubdoc();
                self::fail('Expected non-subdoc accessor read to fail.');
            } catch (\UnexpectedValueException $exception) {
                self::assertStringContainsString('is not a subdoc.', $exception->getMessage());
            }
        }
    }

    public function testNativeArraysCanBulkInsertSubdocs(): void
    {
        $doc = new YDoc(196);
        $array = $doc->getArray('array');
        $array->insert(0, ['middle']);
        $prepended = $array->prependSubdocs([
            'php-bulk-first',
            ['guid' => 'php-bulk-second', 'opts' => ['meta' => ['position' => 'second']]],
        ]);
        $inserted = $array->insertSubdocs(2, [
            ['guid' => 'php-bulk-inserted', 'opts' => ['autoLoad' => true]],
        ]);
        $appended = $array->appendSubdocs([
            ['guid' => 'php-bulk-last', 'opts' => ['meta' => ['position' => 'last']]],
        ]);

        $nested = $array->appendArray();
        $nestedSubdocs = $nested->appendSubdocs([
            'php-nested-bulk-first',
            ['guid' => 'php-nested-bulk-second', 'opts' => ['meta' => ['nested' => true]]],
        ]);

        self::assertContainsOnlyInstancesOf(YSubdoc::class, $prepended);
        self::assertContainsOnlyInstancesOf(YSubdoc::class, $inserted);
        self::assertContainsOnlyInstancesOf(YSubdoc::class, $appended);
        self::assertContainsOnlyInstancesOf(YSubdoc::class, $nestedSubdocs);
        self::assertSame('php-bulk-first', $prepended[0]->guid());
        self::assertSame(['position' => 'second'], $prepended[1]->meta());
        self::assertTrue($inserted[0]->shouldLoad());
        self::assertSame(['position' => 'last'], $appended[0]->meta());
        self::assertSame(['nested' => true], $nestedSubdocs[1]->meta());
        self::assertSame([[], [], [], 'middle', [], [[], []]], $doc->toJSON()['array']);

        $target = new YDoc();
        $target->getArray('array');
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());
        $targetArray = $target->getArray('array');
        $targetNested = $targetArray->getArray(5);

        self::assertNotNull($targetNested);
        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame('php-bulk-first', $targetArray->get(0)->guid());
        self::assertSame(['position' => 'second'], $targetArray->get(1)->meta());
        self::assertTrue($targetArray->get(2)->shouldLoad());
        self::assertSame('php-nested-bulk-second', $targetNested->get(1)->guid());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeArrayBulkSubdocInsertionsRejectInvalidSpecsBeforeMutating(): void
    {
        $doc = new YDoc(197);
        $array = $doc->getArray('array');
        $array->appendSubdoc('php-existing-subdoc');

        try {
            $array->appendSubdocs([
                ['guid' => 'php-valid-subdoc'],
                ['opts' => ['missing' => 'guid']],
            ]);
            self::fail('Expected invalid root array bulk subdoc insertion to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('YArray insertSubdocs expects each subdoc to be a GUID string or array with a string guid.', $exception->getMessage());
        }

        self::assertSame([[]], $doc->toJSON()['array']);

        $nested = $array->appendArray();
        $nested->appendSubdoc('php-existing-nested-subdoc');

        try {
            $nested->appendSubdocs([
                'php-valid-nested-subdoc',
                ['guid' => 'php-invalid-opts', 'opts' => 'not-array'],
            ]);
            self::fail('Expected invalid nested array bulk subdoc insertion to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('YNestedArray insertSubdocs expects each subdoc to be a GUID string or array with a string guid.', $exception->getMessage());
        }

        self::assertSame([[], [[]]], $doc->toJSON()['array']);
    }

    public function testNativeArrayBulkSubdocInsertionsEmitBatchedObserverEvents(): void
    {
        $doc = new YDoc(204);
        $array = $doc->getArray('array');
        $nested = $array->appendArray();
        $rootEvents = [];
        $nestedEvents = [];
        $deepEvents = [];
        $transactions = [];

        $array->observe(static function (array $event) use (&$rootEvents): void {
            $rootEvents[] = $event;
        });
        $nested->observe(static function (array $event) use (&$nestedEvents): void {
            $nestedEvents[] = $event;
        });
        $doc->observeDeep(static function (array $events, YDoc $doc, array $transaction) use (&$deepEvents, &$transactions): void {
            $deepEvents[] = $events;
            $transactions[] = $transaction;
        });

        $doc->transact(static function () use ($array, $nested): void {
            $array->prependSubdocs([
                'php-root-observer-subdoc',
                ['guid' => 'php-root-loaded-subdoc', 'opts' => ['autoLoad' => true]],
            ]);
            $nested->appendSubdocs([
                ['guid' => 'php-nested-observer-subdoc', 'opts' => ['meta' => ['scope' => 'nested']]],
                'php-nested-tail-subdoc',
            ]);
        }, 'array-bulk-subdoc-observer');

        self::assertCount(1, $rootEvents);
        self::assertSame('array-bulk-subdoc-observer', $rootEvents[0]['origin']);
        self::assertIsString($rootEvents[0]['update']);
        self::assertIsString($rootEvents[0]['updateV2']);
        self::assertNotSame('', $rootEvents[0]['update']);
        self::assertNotSame('', $rootEvents[0]['updateV2']);
        self::assertSame([], $rootEvents[0]['changes']['keys']);
        self::assertSame([[]], $rootEvents[0]['oldValue']);
        self::assertCount(3, $rootEvents[0]['newValue']);
        self::assertInstanceOf(YSubdoc::class, $rootEvents[0]['newValue'][0]);
        self::assertSame('php-root-observer-subdoc', $rootEvents[0]['newValue'][0]->guid());

        $rootInserted = $rootEvents[0]['changes']['delta'][0]['insert'];
        self::assertContainsOnlyInstancesOf(YSubdoc::class, $rootInserted);
        self::assertSame('php-root-observer-subdoc', $rootInserted[0]->guid());
        self::assertSame('php-root-loaded-subdoc', $rootInserted[1]->guid());
        self::assertTrue($rootInserted[1]->shouldLoad());

        self::assertCount(1, $nestedEvents);
        self::assertSame('array-bulk-subdoc-observer', $nestedEvents[0]['origin']);
        self::assertSame($nested->idKey(), $nestedEvents[0]['idKey']);
        self::assertSame([], $nestedEvents[0]['oldValue']);

        $nestedInserted = $nestedEvents[0]['changes']['delta'][0]['insert'];
        self::assertContainsOnlyInstancesOf(YSubdoc::class, $nestedInserted);
        self::assertSame('php-nested-observer-subdoc', $nestedInserted[0]->guid());
        self::assertSame(['scope' => 'nested'], $nestedInserted[0]->meta());
        self::assertSame('php-nested-tail-subdoc', $nestedInserted[1]->guid());

        self::assertCount(1, $deepEvents);
        self::assertCount(1, $transactions);
        self::assertSame('array-bulk-subdoc-observer', $transactions[0]['origin']);
        self::assertSame(['array'], $transactions[0]['changed']);
        self::assertSame([$nested->idKey()], $transactions[0]['changedNestedTypes']);

        $rootArrayEvent = $this->findDeepEvent($deepEvents[0], 'root', 'array');
        self::assertSame($rootEvents[0]['changes']['delta'], $rootArrayEvent['changes']['delta']);

        $nestedArrayEvent = $this->findDeepEvent($deepEvents[0], 'nested', $nested->idKey());
        self::assertSame($nestedEvents[0]['changes']['delta'], $nestedArrayEvent['changes']['delta']);
    }

    public function testNativeMapsCanBulkSetBinaryAndSubdocContent(): void
    {
        $doc = new YDoc(198);
        $map = $doc->getMap('map');
        $map->set('title', 'Draft');
        $map->setBinaries([
            'bytesA' => "\x00\x7f",
            'bytesB' => "\xff",
        ]);
        $subdocs = $map->setSubdocs([
            'first' => 'php-map-bulk-first',
            'second' => ['guid' => 'php-map-bulk-second', 'opts' => ['autoLoad' => true]],
        ]);

        $nested = $map->setMap('nested');
        $nested->setBinaries([
            'nestedBytesA' => "\x10\x20",
            'nestedBytesB' => "\x30",
        ]);
        $nestedSubdocs = $nested->setSubdocs([
            'nestedFirst' => 'php-nested-map-bulk-first',
            'nestedSecond' => ['guid' => 'php-nested-map-bulk-second', 'opts' => ['meta' => ['scope' => 'nested']]],
        ]);

        self::assertContainsOnlyInstancesOf(YSubdoc::class, $subdocs);
        self::assertContainsOnlyInstancesOf(YSubdoc::class, $nestedSubdocs);
        self::assertSame('php-map-bulk-first', $subdocs['first']->guid());
        self::assertTrue($subdocs['second']->shouldLoad());
        self::assertSame('php-nested-map-bulk-first', $nestedSubdocs['nestedFirst']->guid());
        self::assertSame(['scope' => 'nested'], $nestedSubdocs['nestedSecond']->meta());

        $json = $doc->toJSON()['map'];
        self::assertSame('Draft', $json['title']);
        self::assertSame([0, 127], $json['bytesA']);
        self::assertSame([255], $json['bytesB']);
        self::assertSame([], $json['first']);
        self::assertSame([], $json['second']);
        self::assertSame([16, 32], $json['nested']['nestedBytesA']);
        self::assertSame([48], $json['nested']['nestedBytesB']);
        self::assertSame([], $json['nested']['nestedFirst']);
        self::assertSame([], $json['nested']['nestedSecond']);

        $target = new YDoc();
        $target->getMap('map');
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());
        $targetMap = $target->getMap('map');
        $targetNested = $targetMap->getMap('nested');

        self::assertNotNull($targetNested);
        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame('php-map-bulk-first', $targetMap->get('first')->guid());
        self::assertTrue($targetMap->get('second')->shouldLoad());
        self::assertSame('php-nested-map-bulk-first', $targetNested->get('nestedFirst')->guid());
        self::assertSame(['scope' => 'nested'], $targetNested->get('nestedSecond')->meta());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeMapBulkTypedSettersRejectInvalidValuesBeforeMutating(): void
    {
        $doc = new YDoc(199);
        $map = $doc->getMap('map');
        $map->setBinary('existingBytes', "\x01");
        $map->setSubdoc('existingSubdoc', 'php-existing-map-subdoc');

        try {
            $map->setBinaries([
                'validBytes' => "\x02",
                'invalidBytes' => 3,
            ]);
            self::fail('Expected invalid root map bulk binary setter to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('YMap setBinaries only supports binary strings.', $exception->getMessage());
        }

        try {
            $map->setSubdocs([
                'validSubdoc' => 'php-valid-map-subdoc',
                'invalidSubdoc' => ['opts' => ['missing' => 'guid']],
            ]);
            self::fail('Expected invalid root map bulk subdoc setter to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('YMap setSubdocs expects each subdoc to be a GUID string or array with a string guid.', $exception->getMessage());
        }

        self::assertSame([
            'existingBytes' => [1],
            'existingSubdoc' => [],
        ], $doc->toJSON()['map']);

        $nested = $map->setMap('nested');
        $nested->setBinary('existingNestedBytes', "\x03");
        $nested->setSubdoc('existingNestedSubdoc', 'php-existing-nested-map-subdoc');

        try {
            $nested->setBinaries([
                'validNestedBytes' => "\x04",
                'invalidNestedBytes' => null,
            ]);
            self::fail('Expected invalid nested map bulk binary setter to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('YNestedMap setBinaries only supports binary strings.', $exception->getMessage());
        }

        try {
            $nested->setSubdocs([
                'validNestedSubdoc' => 'php-valid-nested-map-subdoc',
                'invalidNestedSubdoc' => ['guid' => 'php-invalid-nested-map-subdoc', 'opts' => 'not-array'],
            ]);
            self::fail('Expected invalid nested map bulk subdoc setter to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('YNestedMap setSubdocs expects each subdoc to be a GUID string or array with a string guid.', $exception->getMessage());
        }

        self::assertSame([
            'existingNestedBytes' => [3],
            'existingNestedSubdoc' => [],
        ], $doc->toJSON()['map']['nested']);
    }

    public function testContentJsonUpdatesMaterializeAndReencode(): void
    {
        $structs = [
            [
                'type' => 'Item',
                'id' => ['client' => 126, 'clock' => 0],
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
                'id' => ['client' => 126, 'clock' => 2],
                'length' => 1,
                'origin' => null,
                'rightOrigin' => null,
                'parent' => 'map',
                'parentSub' => 'settings',
                'content' => [
                    'type' => 'ContentJSON',
                    'values' => [
                        ['enabled' => true],
                    ],
                ],
            ],
        ];

        $doc = new YDoc();
        $doc->applyUpdateV2(DecodedUpdate::encodeV2($structs));

        self::assertSame([
            ['legacy' => true],
            null,
        ], $doc->toJSON()['array']);
        self::assertSame(['settings' => ['enabled' => true]], $doc->toJSON()['map']);

        $target = new YDoc();
        $target->applyUpdateV1($doc->encodeStateAsUpdateV1());

        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeTextCanInsertAtStartAndMiddle(): void
    {
        $doc = new YDoc(106);
        $text = $doc->getText('content');
        $text->insert(0, 'HY');
        $text->insert(1, 'i');
        $text->insert(0, 'Say ');

        self::assertSame('Say HiY', $text->toString());

        $target = new YDoc();
        $target->applyUpdateV1($doc->encodeStateAsUpdateV1());

        self::assertSame(['content' => 'Say HiY'], $target->toJSON());
    }

    public function testNativeTextCanInsertAfterUtf16WideCharacter(): void
    {
        $doc = new YDoc(107);
        $text = $doc->getText('content');
        $text->insert(0, 'A😀C');
        $text->insert(3, 'B');

        self::assertSame('A😀BC', $text->toString());

        $target = new YDoc();
        $target->applyUpdateV1($doc->encodeStateAsUpdateV1());

        self::assertSame(['content' => 'A😀BC'], $target->toJSON());
    }

    public function testNativeTextCanInsertAndApplyFormatting(): void
    {
        $doc = new YDoc(121);
        $text = $doc->getText('content');
        $text->insert(0, 'Hi', ['bold' => true]);
        $text->insert(2, ' there');
        $text->format(3, 5, ['italic' => true]);

        self::assertSame('Hi there', $text->toString());
        self::assertSame([
            [
                'insert' => 'Hi ',
                'attributes' => ['bold' => true],
            ],
            [
                'insert' => 'there',
                'attributes' => ['bold' => true, 'italic' => true],
            ],
        ], $text->toDelta());

        $decoded = DecodedUpdate::decodeV1($doc->encodeStateAsUpdateV1());
        $formatStructs = array_values(array_filter(
            $decoded['structs'],
            static fn (array $struct): bool => ($struct['content']['type'] ?? null) === 'ContentFormat'
        ));

        self::assertCount(4, $formatStructs);
        self::assertSame(['bold', 'bold', 'italic', 'italic'], array_map(
            static fn (array $struct): string => $struct['content']['key'],
            $formatStructs
        ));

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame(['content' => 'Hi there'], $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeTextExplicitInsertAttributesClearOmittedActiveFormats(): void
    {
        $doc = new YDoc(180);
        $text = $doc->getText('content');
        $text->insert(0, 'MapText');
        $text->format(0, 3, ['bold' => true]);
        $text->delete(3, 4);
        $text->insert(3, ' body', ['italic' => true]);

        self::assertSame([
            ['insert' => 'Map', 'attributes' => ['bold' => true]],
            ['insert' => ' body', 'attributes' => ['italic' => true]],
        ], $text->toDelta());
    }

    public function testNativeTextCanInsertEmbedAndExposeDelta(): void
    {
        $doc = new YDoc(122);
        $text = $doc->getText('content');
        $text->insert(0, 'A');
        $text->insertEmbed(1, ['image' => 'cat.png'], ['alt' => 'Cat']);
        $text->insert(2, 'B');

        self::assertSame('AB', $text->toString());
        self::assertSame([
            ['insert' => 'A'],
            [
                'insert' => ['image' => 'cat.png'],
                'attributes' => ['alt' => 'Cat'],
            ],
            [
                'insert' => 'B',
                'attributes' => ['alt' => 'Cat'],
            ],
        ], $text->toDelta());

        $text->delete(1, 1);

        self::assertSame('AB', $text->toString());
        self::assertSame([
            ['insert' => 'A'],
            [
                'insert' => 'B',
                'attributes' => ['alt' => 'Cat'],
            ],
        ], $text->toDelta());
    }

    public function testNativeTextCanApplyDelta(): void
    {
        $doc = new YDoc(139);
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

        self::assertSame('Hello Yjs', $text->toString());
        self::assertSame([
            ['insert' => 'Hello', 'attributes' => ['bold' => true]],
            ['insert' => ' '],
            ['insert' => 'Yjs', 'attributes' => ['italic' => true]],
            ['insert' => ['image' => 'cat.png'], 'attributes' => ['alt' => 'Cat']],
        ], $text->toDelta());

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeTextWrappersCanBeClearedAndEncoded(): void
    {
        $doc = new YDoc(145);
        $rootText = $doc->getText('content');
        $nestedText = $doc->getArray('array')->insertText(0);
        $xmlText = $doc->getXmlFragment('xml')->insertElement(0, 'p')->insertText(0, '');

        $rootText->insert(0, 'Root');
        $nestedText->insert(0, 'Nested');
        $xmlText->insert(0, 'Xml');

        $rootText->clear();
        $nestedText->clear();
        $xmlText->clear();

        self::assertSame('', $rootText->toString());
        self::assertSame('', $nestedText->toString());
        self::assertSame('', $xmlText->toString());
        self::assertSame([
            'array' => [''],
            'xml' => '<p></p>',
            'content' => '',
        ], $doc->toJSON());

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeTextWrappersCanSpliceByUtf16Indexes(): void
    {
        $doc = new YDoc(176);

        $rootText = $doc->getText('content');
        $rootText->insert(0, 'A😀BC');
        self::assertSame('😀', $rootText->splice(1, 2, 'x', ['bold' => true]));
        self::assertSame('C', $rootText->splice(-1, 1, 'Z'));
        self::assertSame('', $rootText->splice(99, 1, '!'));

        $nestedText = $doc->getArray('array')->insertText(0);
        $nestedText->insert(0, 'N😀ST');
        self::assertSame('😀', $nestedText->splice(1, 2, 'y', ['italic' => true]));
        self::assertSame('T', $nestedText->splice(-1, 1, '?'));

        $xmlText = $doc->getXmlFragment('xml')->insertText(0, 'Z😀WX');
        self::assertSame('😀', $xmlText->splice(1, 2, 'q', ['mark' => true]));
        self::assertSame('X', $xmlText->splice(-1, 1, '.'));

        self::assertSame([
            'content' => 'AxBZ!',
            'array' => ['NyS?'],
            'xml' => 'Z<mark>q</mark>W.',
        ], $doc->toJSON());
        self::assertSame([
            ['insert' => 'A'],
            ['insert' => 'x', 'attributes' => ['bold' => true]],
            ['insert' => 'BZ!'],
        ], $rootText->toDelta());
        self::assertSame([
            ['insert' => 'N'],
            ['insert' => 'y', 'attributes' => ['italic' => true]],
            ['insert' => 'S?'],
        ], $nestedText->toDelta());
        self::assertSame([
            ['insert' => 'Z'],
            ['insert' => 'q', 'attributes' => ['mark' => true]],
            ['insert' => 'W.'],
        ], $xmlText->toDelta());

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeTextWrappersCanSliceByUtf16Indexes(): void
    {
        $doc = new YDoc(146);
        $rootText = $doc->getText('content');
        $nestedText = $doc->getArray('array')->insertText(0);
        $xmlText = $doc->getXmlFragment('xml')->insertText(0, '');

        $rootText->insert(0, 'A😀BC');
        $nestedText->insert(0, 'N😀ST');
        $xmlText->insert(0, 'Z😀WX');
        $snapshot = $doc->snapshot();
        $xmlText->delete(3, 1);

        self::assertSame('A😀BC', $rootText->slice());
        self::assertSame('😀', $rootText->slice(1, 3));
        self::assertSame('BC', $rootText->slice(3));
        self::assertSame('C', $rootText->slice(-1));
        self::assertSame('N😀', $nestedText->slice(0, 3));
        self::assertSame('ST', $nestedText->slice(3));
        self::assertSame('Z😀X', $xmlText->slice());
        self::assertSame('😀', $xmlText->slice(1, 3));
        self::assertSame('X', $xmlText->slice(-1));
        self::assertSame('WX', $xmlText->sliceSnapshot($snapshot, 3));

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());
        $targetNestedText = $target->getArray('array')->get(0);
        $targetXmlText = $target->getXmlFragment('xml')->get(0);
        self::assertInstanceOf(YXmlText::class, $targetXmlText);
        self::assertSame('😀', $target->getText('content')->slice(1, 3));
        self::assertSame('ST', (new YNestedText($target, $nestedText->idKey(), (string) $targetNestedText))->slice(3));
        self::assertSame('😀X', $targetXmlText->slice(1));
    }

    public function testNativeArrayCanInsertAtStartAndMiddle(): void
    {
        $doc = new YDoc(108);
        $array = $doc->getArray('array');
        $array->insert(0, ['A', 'C']);
        $array->insert(1, ['B']);
        $array->insert(0, ['start']);

        self::assertSame(['start', 'A', 'B', 'C'], $array->toArray());

        $target = new YDoc();
        $target->applyUpdateV1($doc->encodeStateAsUpdateV1());

        self::assertSame(['array' => ['start', 'A', 'B', 'C']], $target->toJSON());
    }

    public function testNativeArrayDeleteCanBeEncodedAndApplied(): void
    {
        $doc = new YDoc(109);
        $array = $doc->getArray('array');
        $array->insert(0, ['A', 'B', 'C', 'D']);
        $array->delete(1, 2);

        self::assertSame(['A', 'D'], $array->toArray());

        $target = new YDoc();
        $target->applyUpdateV1($doc->encodeStateAsUpdateV1());

        self::assertSame(['array' => ['A', 'D']], $target->toJSON());
    }

    public function testNativeArrayDeleteEmitsValidIncrementalUpdate(): void
    {
        $doc = new YDoc(110);
        $updates = [];
        $doc->observeUpdate(static function (string $update) use (&$updates): void {
            $updates[] = $update;
        });

        $array = $doc->getArray('array');
        $array->insert(0, ['A', 'B', 'C']);
        $array->delete(1, 1);

        self::assertCount(2, $updates);

        $target = new YDoc();
        foreach ($updates as $update) {
            $target->applyUpdateV1($update);
        }

        self::assertSame(['array' => ['A', 'C']], $target->toJSON());
    }

    public function testNativeMapSetDeleteCanBeEncodedAndApplied(): void
    {
        $doc = new YDoc(102);
        $map = $doc->getMap('map');
        $map->set('title', 'Hello');
        $map->set('count', 3);
        $map->delete('count');

        $target = new YDoc();
        $target->applyUpdateV1($doc->encodeStateAsUpdateV1());

        self::assertSame(['map' => ['title' => 'Hello']], $target->toJSON());
    }

    public function testNativeMapSetReplacesExistingNullValue(): void
    {
        $doc = new YDoc(113);
        $map = $doc->getMap('map');
        $map->set('title', null);
        $map->set('title', 'Hello');

        $target = new YDoc();
        $target->applyUpdateV1($doc->encodeStateAsUpdateV1());

        self::assertSame(['map' => ['title' => 'Hello']], $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeXmlElementAttributeAndTextCanBeEncodedAndApplied(): void
    {
        $doc = new YDoc(114);
        $fragment = $doc->getXmlFragment('xml');
        $paragraph = $fragment->insertElement(0, 'p');
        $paragraph->setAttributes(['class' => 'lead', 'data-temp' => 'remove-me']);
        $paragraph->removeAttributes(['data-temp']);
        $text = $paragraph->insertText(0, 'Hello');
        $text->insert(5, ' XML');
        $text->delete(5, 1);
        $text->insert(5, ' ');

        self::assertSame('<p class="lead">Hello XML</p>', $doc->getXmlFragment('xml')->toString());
        self::assertNotSame('', $paragraph->idKey());
        self::assertNotSame('', $text->idKey());
        self::assertSame('Hello XML', $text->toString());
        self::assertSame('<p class="lead">Hello XML</p>', (string) $doc->getXmlFragment('xml'));
        self::assertSame('<p class="lead">Hello XML</p>', (string) $paragraph);
        self::assertSame('Hello XML', (string) $text);

        $target = new YDoc();
        $target->applyUpdateV1($doc->encodeStateAsUpdateV1());

        self::assertSame(['xml' => '<p class="lead">Hello XML</p>'], $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeXmlElementAttributeReplacementCanBeEncodedAndApplied(): void
    {
        $doc = new YDoc(123);
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $paragraph->setAttribute('class', 'draft');
        $paragraph->setAttribute('class', 'published');

        self::assertSame('<p class="published"></p>', $doc->getXmlFragment('xml')->toString());

        $target = new YDoc();
        $target->applyUpdateV1($doc->encodeStateAsUpdateV1());

        self::assertSame(['xml' => '<p class="published"></p>'], $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeXmlTextFormattingAndElementAccessorsCanBeEncodedAndApplied(): void
    {
        $doc = new YDoc(131);
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $paragraph->setAttribute('class', 'lead');
        $text = $paragraph->insertText(0, 'Hello');
        $text->format(1, 3, ['bold' => true]);
        $text->insert(5, '!', ['italic' => true]);
        $text->delete(5, 1);
        $text->insert(5, '?');
        $text->insert(6, '😀');

        self::assertSame(['class' => 'lead'], $paragraph->getAttributes());
        self::assertTrue($paragraph->hasAttribute('class'));
        self::assertSame('lead', $paragraph->getAttribute('class'));
        self::assertSame(8, $text->length());
        self::assertCount(8, $text);
        self::assertSame('H<bold>ell</bold>o?😀', $text->toString());
        self::assertSame([
            ['insert' => 'H'],
            ['insert' => 'ell', 'attributes' => ['bold' => true]],
            ['insert' => 'o?😀'],
        ], $text->toDelta());
        self::assertSame('<p class="lead">H<bold>ell</bold>o?😀</p>', $paragraph->toString());
        self::assertSame('<p class="lead">H<bold>ell</bold>o?😀</p>', $doc->getXmlFragment('xml')->toString());

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame(['xml' => '<p class="lead">H<bold>ell</bold>o?😀</p>'], $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeXmlTextExplicitInsertAttributesClearOmittedActiveFormats(): void
    {
        $doc = new YDoc(181);
        $text = $doc->getXmlFragment('xml')->insertElement(0, 'p')->insertText(0, 'MapText');
        $text->format(0, 3, ['bold' => true]);
        $text->delete(3, 4);
        $text->insert(3, ' body', ['italic' => true]);

        self::assertSame([
            ['insert' => 'Map', 'attributes' => ['bold' => true]],
            ['insert' => ' body', 'attributes' => ['italic' => true]],
        ], $text->toDelta());
    }

    public function testNativeXmlTextAttributesCanBeEncodedAndApplied(): void
    {
        $doc = new YDoc(179);
        $xmlText = $doc->getXmlFragment('xml')->insertText(0, 'Xml');
        $xmlText->setAttribute('lang', 'en');
        $snapshot = $doc->snapshot();
        $xmlText->setAttributes(['lang' => 'fr', 'mark' => ['color' => 'blue'], 'temporary' => true]);
        $xmlText->removeAttributes(['temporary']);

        self::assertSame('Xml', $xmlText->toString());
        self::assertSame([['insert' => 'Xml']], $xmlText->toDelta());
        self::assertSame(['lang' => 'fr', 'mark' => ['color' => 'blue']], $xmlText->getAttributes());
        self::assertSame('fr', $xmlText->getAttribute('lang'));
        self::assertTrue($xmlText->hasAttribute('mark'));
        self::assertFalse($xmlText->hasAttribute('temporary'));
        self::assertSame(['lang' => 'en'], $xmlText->getAttributesSnapshot($snapshot));
        self::assertSame('en', $xmlText->getAttributeSnapshot('lang', $snapshot));
        self::assertTrue($xmlText->hasAttributeSnapshot('lang', $snapshot));
        self::assertFalse($xmlText->hasAttributeSnapshot('mark', $snapshot));

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());
        $targetXmlText = $target->getXmlFragment('xml')->get(0);

        self::assertInstanceOf(YXmlText::class, $targetXmlText);
        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame($xmlText->getAttributes(), $targetXmlText->getAttributes());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeXmlTextSharedTypeAttributeApisCanBeEncodedAndApplied(): void
    {
        $source = new YDoc(334);
        $xmlText = $source->getXmlFragment('xml')->insertText(0, 'Xml');
        $xmlText->setAttribute('body', 'draft');
        $body = $xmlText->setText('body');
        $body->insert(0, 'Text body');
        $items = $xmlText->setArray('items');
        $items->insert(0, ['A', 'B']);
        $meta = $xmlText->setMap('meta');
        $meta->set('role', 'caption');
        $inline = $xmlText->setXmlElement('inline', 'span');
        $inline->appendText('Inline');
        $label = $xmlText->setXmlText('label', 'Xml label');
        $hook = $xmlText->setXmlHook('hook', 'note');
        $hook->set('ok', true);
        $fragment = $xmlText->setXmlFragment('fragment');
        $fragment->appendText('Frag');

        self::assertSame([
            'body' => 'Text body',
            'fragment' => 'Frag',
            'hook' => ['ok' => true],
            'inline' => '<span>Inline</span>',
            'items' => ['A', 'B'],
            'label' => 'Xml label',
            'meta' => ['role' => 'caption'],
        ], $xmlText->getAttributes());
        self::assertSame([['insert' => 'Text body']], $xmlText->getText('body')?->toDelta());
        self::assertSame(['A', 'B'], $xmlText->getArray('items')?->toArray());
        self::assertSame(['role' => 'caption'], $xmlText->getMap('meta')?->toArray());
        self::assertSame('<span>Inline</span>', (string) $xmlText->getXmlElement('inline'));
        self::assertSame('Xml label', (string) $label);
        self::assertSame('Xml label', (string) $xmlText->getXmlText('label'));
        self::assertSame(['ok' => true], $xmlText->getXmlHook('hook')?->toJSON());
        self::assertSame('Frag', (string) $xmlText->getXmlFragment('fragment'));

        $target = new YDoc();
        $target->applyUpdateV2($source->encodeStateAsUpdateV2());
        $targetXmlText = $target->getXmlFragment('xml')->get(0);

        self::assertInstanceOf(YXmlText::class, $targetXmlText);
        self::assertSame($xmlText->getAttributes(), $targetXmlText->getAttributes());
        self::assertSame([['insert' => 'Text body']], $targetXmlText->getText('body')?->toDelta());
        self::assertSame(['A', 'B'], $targetXmlText->getArray('items')?->toArray());
        self::assertSame(['role' => 'caption'], $targetXmlText->getMap('meta')?->toArray());
        self::assertSame('<span>Inline</span>', (string) $targetXmlText->getXmlElement('inline'));
        self::assertSame('Xml label', (string) $targetXmlText->getXmlText('label'));
        self::assertSame(['ok' => true], $targetXmlText->getXmlHook('hook')?->toJSON());
        self::assertSame('Frag', (string) $targetXmlText->getXmlFragment('fragment'));
        self::assertSame($source->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeXmlTextBulkSharedTypeSettersRejectInvalidValuesBeforeMutating(): void
    {
        $doc = new YDoc(335);
        $xmlText = $doc->getXmlFragment('xml')->insertText(0, 'Xml');
        $xmlText->setText('existing')->insert(0, 'A');

        try {
            $xmlText->setArrays(['valid', false]);
            self::fail('Expected invalid XML text bulk array setter to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('YXmlText setArrays only supports string keys.', $exception->getMessage());
        }

        try {
            $xmlText->setXmlElements(['validElement' => 'span', 'invalidElement' => 3]);
            self::fail('Expected invalid XML text bulk XML element setter to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('YXmlText setXmlElements only supports string node names.', $exception->getMessage());
        }

        try {
            $xmlText->setXmlTexts(['validText' => 'copy', 'invalidText' => null]);
            self::fail('Expected invalid XML text bulk XML text setter to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('YXmlText setXmlTexts only supports string XML text content.', $exception->getMessage());
        }

        try {
            $xmlText->setXmlHooks(['validHook' => 'note', 'invalidHook' => null]);
            self::fail('Expected invalid XML text bulk XML hook setter to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('YXmlText setXmlHooks only supports string hook names.', $exception->getMessage());
        }

        try {
            $xmlText->setXmlFragments(['validFragment', null]);
            self::fail('Expected invalid XML text bulk XML fragment setter to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('YXmlText setXmlFragments only supports string keys.', $exception->getMessage());
        }

        self::assertSame(['existing' => 'A'], $xmlText->getAttributes());
    }

    public function testNativeXmlWrappersRenderFreshDocumentState(): void
    {
        $doc = new YDoc(148);
        $fragment = $doc->getXmlFragment('xml');
        self::assertSame('xml', $fragment->name());
        $paragraph = $fragment->insertElement(0, 'p');
        $text = $paragraph->insertText(0, 'A');

        $paragraph->setAttribute('class', 'live');
        $text->insert(1, 'B');

        self::assertSame('<p class="live">AB</p>', $fragment->toString());
        self::assertSame('AB', $text->toString());

        $text->delete(0, 1);
        self::assertSame('<p class="live">B</p>', (string) $fragment);
        self::assertSame('B', (string) $text);
    }

    public function testNativeXmlTextSupportsEmbedsAndDeltas(): void
    {
        $doc = new YDoc(134);
        $text = $doc->getXmlFragment('xml')->insertText(0, '');
        $text->insertEmbed(0, ['image' => 'cat.png'], ['alt' => 'Cat']);

        self::assertSame('<alt 0="C" 1="a" 2="t">[object Object]</alt>', $text->toString());
        self::assertSame([
            ['insert' => ['image' => 'cat.png'], 'attributes' => ['alt' => 'Cat']],
        ], $text->toDelta());
        self::assertSame('<alt 0="C" 1="a" 2="t">[object Object]</alt>', $doc->getXmlFragment('xml')->toString());

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame(['xml' => '<alt 0="C" 1="a" 2="t">[object Object]</alt>'], $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeXmlTextCanApplyDelta(): void
    {
        $doc = new YDoc(141);
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

        self::assertSame('Hello<italic> </italic><bold>Yjs</bold>L', $text->toString());
        self::assertSame([
            ['insert' => 'Hello'],
            ['insert' => ' ', 'attributes' => ['italic' => true]],
            ['insert' => 'Yjs', 'attributes' => ['bold' => true]],
            ['insert' => 'L'],
        ], $text->toDelta());
        self::assertSame(
            '<p>Hello<italic> </italic><bold>Yjs</bold>L</p>',
            $doc->getXmlFragment('xml')->toString()
        );

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeXmlElementObserversReceiveLocalEvents(): void
    {
        $doc = new YDoc(135);
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $events = [];
        $observerId = $paragraph->observe(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $doc->transact(static function () use ($paragraph): void {
            $paragraph->setAttribute('class', 'lead');
            $paragraph->insertText(0, 'Hi');
        }, 'xml-local');

        self::assertCount(1, $events);
        self::assertSame($paragraph->idKey(), $events[0]['idKey']);
        self::assertSame('xml-local', $events[0]['origin']);
        self::assertTrue($events[0]['exists']);
        self::assertSame('YXmlElement', $events[0]['typeName']);
        self::assertSame('<p class="lead">Hi</p>', $events[0]['value']);
        self::assertSame(['class'], $events[0]['changes']['attributesChanged']);
        self::assertIsString($events[0]['update']);
        self::assertIsString($events[0]['updateV2']);

        $paragraph->unobserve($observerId);
        $paragraph->setAttribute('class', 'quiet');

        self::assertCount(1, $events);
    }

    public function testNativeXmlTextObserversReceiveLocalEvents(): void
    {
        $doc = new YDoc(136);
        $text = $doc->getXmlFragment('xml')->insertElement(0, 'p')->insertText(0, 'Hi');
        $events = [];
        $text->observe(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $doc->transact(static function () use ($text): void {
            $text->format(0, 2, ['bold' => true]);
            $text->setAttribute('lang', 'en');
            $text->insert(2, '!');
            $text->delete(2, 1);
            $text->insert(2, '?');
        }, 'xml-text-local');

        self::assertCount(1, $events);
        self::assertSame($text->idKey(), $events[0]['idKey']);
        self::assertSame('xml-text-local', $events[0]['origin']);
        self::assertTrue($events[0]['exists']);
        self::assertSame('YXmlText', $events[0]['typeName']);
        self::assertSame('<bold>Hi</bold>?', $events[0]['value']);
        self::assertArrayNotHasKey('attributesChanged', $events[0]['changes']);
        self::assertSame(['lang' => ['action' => 'add']], $events[0]['changes']['keys']);
        self::assertIsString($events[0]['update']);
        self::assertIsString($events[0]['updateV2']);
    }

    public function testNativeXmlNodeObserversReceiveRemoteOrigins(): void
    {
        $source = new YDoc(137);
        $updates = [];
        $source->observeUpdate(static function (string $update) use (&$updates): void {
            $updates[] = $update;
        });
        $updatesV2 = [];
        $source->observeUpdateV2(static function (string $update) use (&$updatesV2): void {
            $updatesV2[] = $update;
        });
        $paragraph = $source->getXmlFragment('xml')->insertElement(0, 'p');
        $paragraph->insertText(0, 'Hi');

        $target = new YDoc();
        $target->applyUpdateV1($source->encodeStateAsUpdateV1());
        $targetParagraph = $target->getXmlFragment('xml')->get(0);
        self::assertInstanceOf(YXmlElement::class, $targetParagraph);

        $events = [];
        $targetParagraph->observe(static function (array $event) use (&$events): void {
            $events[] = $event;
        });
        $deepEvents = [];
        $transactions = [];
        $target->observeDeep(static function (array $events, YDoc $doc, array $transaction) use (&$deepEvents, &$transactions): void {
            $deepEvents[] = $events;
            $transactions[] = $transaction;
        });

        $updates = [];
        $updatesV2 = [];
        $paragraph->setAttribute('class', 'remote');
        self::assertCount(1, $updates);
        self::assertCount(1, $updatesV2);

        $target->applyUpdateV1($updates[0], 'remote-origin');

        self::assertCount(1, $events);
        self::assertSame('remote-origin', $events[0]['origin']);
        self::assertSame($updates[0], $events[0]['update']);
        self::assertIsString($events[0]['updateV2']);
        self::assertNotSame('', $events[0]['updateV2']);
        self::assertSame('<p class="remote">Hi</p>', $events[0]['value']);
        self::assertSame(['class' => ['action' => 'add']], $events[0]['changes']['keys']);
        self::assertSame(['class'], $events[0]['changes']['attributesChanged']);
        self::assertSame([], $events[0]['changes']['delta']);

        self::assertCount(1, $deepEvents);
        self::assertSame('remote-origin', $transactions[0]['origin']);
        self::assertFalse($transactions[0]['local']);
        self::assertSame([$targetParagraph->idKey()], $transactions[0]['changedXmlNodes']);
        $deepEvent = $this->findDeepEvent($deepEvents[0], 'xml', $targetParagraph->idKey());
        self::assertSame(['class' => ['action' => 'add']], $deepEvent['changes']['keys']);
        self::assertSame(['class'], $deepEvent['changes']['attributesChanged']);

        $events = [];
        $deepEvents = [];
        $transactions = [];
        $updates = [];
        $updatesV2 = [];
        $paragraph->removeAttribute('class');
        self::assertCount(1, $updates);
        self::assertCount(1, $updatesV2);

        $target->applyUpdateV2($updatesV2[0], 'remote-v2-origin');

        self::assertCount(1, $events);
        self::assertSame('remote-v2-origin', $events[0]['origin']);
        self::assertIsString($events[0]['update']);
        self::assertNotSame('', $events[0]['update']);
        self::assertSame($updatesV2[0], $events[0]['updateV2']);
        self::assertSame('<p>Hi</p>', $events[0]['value']);
        self::assertSame(['class' => ['action' => 'delete', 'oldValue' => 'remote']], $events[0]['changes']['keys']);
        self::assertSame(['class'], $events[0]['changes']['attributesChanged']);
        self::assertSame([], $events[0]['changes']['delta']);

        self::assertCount(1, $deepEvents);
        self::assertSame('remote-v2-origin', $transactions[0]['origin']);
        self::assertFalse($transactions[0]['local']);
        self::assertSame([$targetParagraph->idKey()], $transactions[0]['changedXmlNodes']);
        $deepEvent = $this->findDeepEvent($deepEvents[0], 'xml', $targetParagraph->idKey());
        self::assertSame(['class' => ['action' => 'delete', 'oldValue' => 'remote']], $deepEvent['changes']['keys']);
        self::assertSame(['class'], $deepEvent['changes']['attributesChanged']);
    }

    public function testNativeXmlNodeObserversReportDeletedNodesAsMissing(): void
    {
        $doc = new YDoc(138);
        $fragment = $doc->getXmlFragment('xml');
        $paragraph = $fragment->insertElement(0, 'p');
        $paragraph->insertText(0, 'Remove me');
        $events = [];
        $paragraph->observe(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $fragment->delete(0, 1);

        self::assertCount(1, $events);
        self::assertFalse($events[0]['exists']);
        self::assertNull($events[0]['value']);
        self::assertSame(['xml' => ''], $doc->toJSON());
    }

    public function testNativeXmlNestedChildrenRespectInsertIndexesAndDeletes(): void
    {
        $doc = new YDoc(124);
        $fragment = $doc->getXmlFragment('xml');
        $fragment->insertText(0, 'tail');
        $article = $fragment->insertElement(0, 'article');
        $article->insertText(0, 'B');
        $strong = $article->insertElement(0, 'strong');
        $strong->insertText(0, 'A');
        $article->insertText(2, 'C');
        $article->delete(1, 1);
        $fragment->delete(1, 1);

        self::assertSame('<article><strong>A</strong>C</article>', $doc->getXmlFragment('xml')->toString());
        self::assertSame(1, $doc->getXmlFragment('xml')->length());
        self::assertInstanceOf(YXmlElement::class, $doc->getXmlFragment('xml')->get(0));
        self::assertNull($doc->getXmlFragment('xml')->get(1));
        self::assertSame('article', $doc->getXmlFragment('xml')->get(0)?->nodeName());
        self::assertSame(2, $article->length());
        self::assertSame('<strong>A</strong>', $article->get(0)?->toString());
        self::assertInstanceOf(YXmlText::class, $article->get(1));
        self::assertSame('C', $article->get(1)?->toString());

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame(['xml' => '<article><strong>A</strong>C</article>'], $target->toJSON());
        self::assertSame(1, $target->getXmlFragment('xml')->length());
        self::assertSame('article', $target->getXmlFragment('xml')->get(0)?->nodeName());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeXmlInsertAfterMutationApisCanBeEncodedAndApplied(): void
    {
        $doc = new YDoc(178);
        $fragment = $doc->getXmlFragment('xml');
        $fragment->insertAfter(null, ['lead']);
        $lead = $fragment->get(0);
        self::assertInstanceOf(YXmlText::class, $lead);

        $article = $fragment->insertElementAfter($lead, 'article');
        $tail = $fragment->insertTextAfter($article, 'tail');
        $fragment->insertHookAfter($tail, 'mention');
        $strong = $article->insertElementAfter(null, 'strong');
        $strong->appendText('B');
        $article->insertAfter($strong, ['middle']);
        $middle = $article->get(1);
        self::assertInstanceOf(YXmlText::class, $middle);
        $article->insertHookAfter($middle, 'note');
        $note = $article->get(2);
        self::assertInstanceOf(YXmlHook::class, $note);
        $article->insertTextAfter($note, 'end');

        self::assertSame(
            'lead<article><strong>B</strong>middle[object Object]end</article>tail[object Object]',
            $fragment->toString()
        );
        self::assertSame(['Yjs\YXmlText', 'article', 'Yjs\YXmlText', 'mention'], $fragment->map(
            static fn (YXmlElement|YXmlText|YXmlHook $child): string => $child instanceof YXmlElement ? $child->nodeName() : ($child instanceof YXmlHook ? $child->hookName() : $child::class)
        ));
        self::assertSame(['strong', 'Yjs\YXmlText', 'note', 'Yjs\YXmlText'], $article->map(
            static fn (YXmlElement|YXmlText|YXmlHook $child): string => $child instanceof YXmlElement ? $child->nodeName() : ($child instanceof YXmlHook ? $child->hookName() : $child::class)
        ));

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());
        $targetArticle = $target->getXmlFragment('xml')->get(1);

        self::assertInstanceOf(YXmlElement::class, $targetArticle);
        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('YXmlFragment reference node must be a direct child of the fragment.');

        $fragment->insertTextAfter($strong, 'wrong-parent');
    }

    public function testNativeXmlBulkTextMutationApisCanBeEncodedAndApplied(): void
    {
        $doc = new YDoc(182);
        $fragment = $doc->getXmlFragment('xml');
        $prepended = $fragment->prependTexts(['B', 'C']);
        $inserted = $fragment->insertTexts(1, ['x', 'y']);
        $appended = $fragment->appendTexts(['tail']);
        $paragraph = $fragment->appendElement('p');
        $paragraph->appendTexts(['A', 'C']);
        $paragraph->insertTexts(1, ['B']);
        $paragraph->prependTexts(['0']);

        $mapFragment = $doc->getMap('map')->setXmlFragment('xml');
        $mapFragment->appendTexts(['M', 'P']);
        $mapFragment->insertTexts(1, ['N', 'O']);
        $mapFragment->prependTexts(['L']);

        self::assertCount(2, $prepended);
        self::assertContainsOnlyInstancesOf(YXmlText::class, $prepended);
        self::assertSame('B', $prepended[0]->toString());
        self::assertSame('C', $prepended[1]->toString());
        self::assertCount(2, $inserted);
        self::assertContainsOnlyInstancesOf(YXmlText::class, $inserted);
        self::assertSame('x', $inserted[0]->toString());
        self::assertSame('y', $inserted[1]->toString());
        self::assertCount(1, $appended);
        self::assertContainsOnlyInstancesOf(YXmlText::class, $appended);
        self::assertSame('tail', $appended[0]->toString());
        self::assertSame('BxyCtail<p>0ABC</p>', $fragment->toString());
        self::assertSame('<p>0ABC</p>', $paragraph->toString());
        self::assertSame('LMNOP', $mapFragment->toString());

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());
        $targetMapFragment = $target->getMap('map')->getXmlFragment('xml');

        self::assertNotNull($targetMapFragment);
        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame('BxyCtail<p>0ABC</p>', $target->getXmlFragment('xml')->toString());
        self::assertSame('LMNOP', $targetMapFragment->toString());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeXmlSingleTextAppendPrependApisCanBeEncodedAndApplied(): void
    {
        $doc = new YDoc(186);
        $fragment = $doc->getXmlFragment('xml');
        $rootTail = $fragment->append('B');
        $rootLead = $fragment->prepend('A');
        $section = $fragment->appendElement('section');
        $sectionTail = $section->append('Y');
        $sectionLead = $section->prepend('X');

        $nested = $doc->getMap('map')->setXmlFragment('nested');
        $nestedTail = $nested->append('N');
        $nestedLead = $nested->prepend('M');

        self::assertInstanceOf(YXmlText::class, $rootTail);
        self::assertInstanceOf(YXmlText::class, $rootLead);
        self::assertInstanceOf(YXmlText::class, $sectionTail);
        self::assertInstanceOf(YXmlText::class, $sectionLead);
        self::assertInstanceOf(YXmlText::class, $nestedTail);
        self::assertInstanceOf(YXmlText::class, $nestedLead);
        self::assertSame('A', $rootLead->toString());
        self::assertSame('B', $rootTail->toString());
        self::assertSame('X', $sectionLead->toString());
        self::assertSame('Y', $sectionTail->toString());
        self::assertSame('M', $nestedLead->toString());
        self::assertSame('N', $nestedTail->toString());
        self::assertSame('AB<section>XY</section>', $fragment->toString());
        self::assertSame('MN', $nested->toString());

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame([
            'map' => ['nested' => 'MN'],
            'xml' => 'AB<section>XY</section>',
        ], $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeXmlBulkTextInsertAfterMutationApisCanBeEncodedAndApplied(): void
    {
        $doc = new YDoc(184);
        $fragment = $doc->getXmlFragment('xml');
        $lead = $fragment->appendText('lead');
        $inserted = $fragment->insertTextsAfter($lead, ['A', 'B']);
        $paragraph = $fragment->appendElement('p');
        $paragraph->appendText('P');
        $paragraph->insertTextsAfter(null, ['0', '1']);
        $middle = $paragraph->get(1);
        self::assertInstanceOf(YXmlText::class, $middle);
        $paragraph->insertTextsAfter($middle, ['2', '3']);

        $mapFragment = $doc->getMap('map')->setXmlFragment('xml');
        $start = $mapFragment->appendText('M');
        $mapFragment->insertTextsAfter($start, ['N', 'O']);

        self::assertCount(2, $inserted);
        self::assertContainsOnlyInstancesOf(YXmlText::class, $inserted);
        self::assertSame('A', $inserted[0]->toString());
        self::assertSame('B', $inserted[1]->toString());
        self::assertSame('leadAB<p>0123P</p>', $fragment->toString());
        self::assertSame('<p>0123P</p>', $paragraph->toString());
        self::assertSame('MNO', $mapFragment->toString());

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());
        $targetMapFragment = $target->getMap('map')->getXmlFragment('xml');

        self::assertNotNull($targetMapFragment);
        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame('leadAB<p>0123P</p>', $target->getXmlFragment('xml')->toString());
        self::assertSame('MNO', $targetMapFragment->toString());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('YXmlFragment reference node must be a direct child of the fragment.');

        $fragment->insertTextsAfter($paragraph->get(0), ['wrong-parent']);
    }

    public function testNativeXmlBulkTextMutationApisRejectInvalidValuesBeforeMutating(): void
    {
        $doc = new YDoc(183);
        $fragment = $doc->getXmlFragment('xml');
        $fragment->appendText('A');

        try {
            $fragment->insertTexts(1, ['B', 1, 'C']);
            self::fail('Expected invalid root XML bulk text insertion to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('YXmlFragment insertTexts only supports string XML text content.', $exception->getMessage());
        }

        self::assertSame('A', $fragment->toString());

        $paragraph = $fragment->appendElement('p');
        $paragraph->appendText('P');

        try {
            $paragraph->appendTexts(['Q', false]);
            self::fail('Expected invalid XML element bulk text insertion to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('YXmlElement insertTexts only supports string XML text content.', $exception->getMessage());
        }

        self::assertSame('<p>P</p>', $paragraph->toString());

        $mapFragment = $doc->getMap('map')->setXmlFragment('xml');
        $mapFragment->appendText('M');

        try {
            $mapFragment->prependTexts([null, 'N']);
            self::fail('Expected invalid nested XML fragment bulk text insertion to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('YNestedXmlFragment insertTexts only supports string XML text content.', $exception->getMessage());
        }

        self::assertSame('M', $mapFragment->toString());
    }

    public function testNativeXmlChildReadHelpersTrackMutations(): void
    {
        $doc = new YDoc(141);
        $fragment = $doc->getXmlFragment('xml');
        $fragment->insertText(0, 'intro');
        $article = $fragment->insertElement(1, 'article');
        $fragment->insertHook(2, 'mention');
        $article->insertText(0, 'A');
        $article->insertElement(1, 'strong')->insertText(0, 'B');
        $article->insertHook(2, 'note');
        $article->delete(0, 1);

        self::assertCount(3, $fragment->toArray());
        self::assertCount(3, $fragment);
        self::assertSame(['Yjs\YXmlText', 'article', 'mention'], $fragment->map(
            static fn (YXmlElement|YXmlText|YXmlHook $child): string => $child instanceof YXmlElement ? $child->nodeName() : ($child instanceof YXmlHook ? $child->hookName() : $child::class)
        ));
        self::assertSame(['article'], array_map(
            static fn (YXmlElement|YXmlText|YXmlHook $child): string => $child instanceof YXmlElement ? $child->nodeName() : $child::class,
            $fragment->filter(static fn (YXmlElement|YXmlText|YXmlHook $child): bool => $child instanceof YXmlElement)
        ));
        self::assertSame(['Yjs\YXmlText', 'article', 'mention'], array_map(
            static fn (YXmlElement|YXmlText|YXmlHook $child): string => $child instanceof YXmlElement ? $child->nodeName() : ($child instanceof YXmlHook ? $child->hookName() : $child::class),
            iterator_to_array($fragment)
        ));
        $fragmentVisited = [];
        $fragment->forEach(static function (YXmlElement|YXmlText|YXmlHook $child, int $index) use (&$fragmentVisited): void {
            $fragmentVisited[] = [$index, $child instanceof YXmlElement ? $child->nodeName() : $child::class];
        });
        self::assertSame([[0, YXmlText::class], [1, 'article'], [2, YXmlHook::class]], $fragmentVisited);

        self::assertSame(['strong', 'note'], $article->map(
            static fn (YXmlElement|YXmlText|YXmlHook $child): string => $child instanceof YXmlElement ? $child->nodeName() : ($child instanceof YXmlHook ? $child->hookName() : $child::class)
        ));
        self::assertSame(['note'], array_map(
            static fn (YXmlElement|YXmlText|YXmlHook $child): string => $child instanceof YXmlHook ? $child->hookName() : $child::class,
            $article->filter(static fn (YXmlElement|YXmlText|YXmlHook $child): bool => $child instanceof YXmlHook)
        ));
        self::assertCount(2, $article);
        self::assertSame(['strong', 'note'], array_map(
            static fn (YXmlElement|YXmlText|YXmlHook $child): string => $child instanceof YXmlElement ? $child->nodeName() : ($child instanceof YXmlHook ? $child->hookName() : $child::class),
            iterator_to_array($article)
        ));
        $elementVisited = [];
        $article->forEach(static function (YXmlElement|YXmlText|YXmlHook $child, int $index) use (&$elementVisited): void {
            $elementVisited[] = [$index, $child instanceof YXmlElement ? $child->nodeName() : $child::class];
        });
        self::assertSame([[0, 'strong'], [1, YXmlHook::class]], $elementVisited);

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());
        $targetArticle = $target->getXmlFragment('xml')->get(1);

        self::assertInstanceOf(YXmlElement::class, $targetArticle);
        self::assertSame(['Yjs\YXmlText', 'article', 'mention'], $target->getXmlFragment('xml')->map(
            static fn (YXmlElement|YXmlText|YXmlHook $child): string => $child instanceof YXmlElement ? $child->nodeName() : ($child instanceof YXmlHook ? $child->hookName() : $child::class)
        ));
        self::assertSame(['strong', 'note'], $targetArticle->map(
            static fn (YXmlElement|YXmlText|YXmlHook $child): string => $child instanceof YXmlElement ? $child->nodeName() : ($child instanceof YXmlHook ? $child->hookName() : $child::class)
        ));
    }

    public function testNativeXmlArrayAccessMutationApisCanBeEncodedAndApplied(): void
    {
        $doc = new YDoc(151);
        $fragment = $doc->getXmlFragment('xml');
        $fragment[] = 'intro';
        $article = $fragment->appendElement('article');
        $fragment->appendHook('mention');
        self::assertInstanceOf(YXmlText::class, $fragment[0]);
        self::assertInstanceOf(YXmlElement::class, $fragment[1]);
        self::assertInstanceOf(YXmlHook::class, $fragment[2]);

        $article[] = 'A';
        $article->appendElement('strong')->appendText('B');
        $article->appendHook('note');
        $article[0] = 'Lead';
        unset($article[2]);
        self::assertFalse(isset($article[2]));
        self::assertSame('<article>Lead<strong>B</strong></article>', $article->toString());

        $fragment[0] = 'start';
        unset($fragment[2]);
        $fragment->appendText('tail');
        self::assertFalse(isset($fragment[3]));
        self::assertSame('start', $fragment[0]?->toString());

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame([
            'xml' => 'start<article>Lead<strong>B</strong></article>tail',
        ], $target->toJSON());
        self::assertSame(3, $target->getXmlFragment('xml')->length());
        self::assertSame('article', $target->getXmlFragment('xml')->get(1)?->nodeName());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());

        $fragment->clear();
        self::assertSame(['xml' => ''], $doc->toJSON());
    }

    public function testNativeXmlSpliceMutationApisCanBeEncodedAndApplied(): void
    {
        $doc = new YDoc(167);
        $fragment = $doc->getXmlFragment('xml');
        $fragment->appendText('A');
        $section = $fragment->appendElement('section');
        $fragment->appendHook('mention');
        $fragment->appendText('tail');

        $section->appendText('B');
        $section->appendElement('em')->appendText('D');
        $section->appendHook('inside');

        $removedElementChildren = $section->splice(1, 1, ['M', 'N']);
        self::assertCount(1, $removedElementChildren);
        self::assertInstanceOf(YXmlElement::class, $removedElementChildren[0]);
        self::assertSame('em', $removedElementChildren[0]->nodeName());
        self::assertSame('<section>BMN[object Object]</section>', $section->toString());

        $removedFragmentChildren = $fragment->splice(-2, 1, ['mid']);
        self::assertCount(1, $removedFragmentChildren);
        self::assertInstanceOf(YXmlHook::class, $removedFragmentChildren[0]);
        self::assertSame('mention', $removedFragmentChildren[0]->hookName());

        self::assertSame([], $fragment->splice(99, 1, ['end']));
        self::assertSame('A<section>BMN[object Object]</section>midtailend', $fragment->toString());
        self::assertSame(5, $fragment->length());
        self::assertSame(4, $section->length());

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame(['xml' => 'A<section>BMN[object Object]</section>midtailend'], $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeXmlPopAndShiftMutationApisCanBeEncodedAndApplied(): void
    {
        $doc = new YDoc(170);
        $fragment = $doc->getXmlFragment('xml');
        $fragment->appendText('A');
        $section = $fragment->appendElement('section');
        $fragment->appendHook('mention');
        $section->appendText('B');
        $section->appendElement('strong')->appendText('C');
        $section->appendHook('note');

        $removedFragmentTail = $fragment->pop();
        $removedFragmentHead = $fragment->shift();
        $removedSectionTail = $section->pop();
        $removedSectionHead = $section->shift();

        self::assertInstanceOf(YXmlHook::class, $removedFragmentTail);
        self::assertSame('mention', $removedFragmentTail->hookName());
        self::assertInstanceOf(YXmlText::class, $removedFragmentHead);
        self::assertSame('A', $removedFragmentHead->toString());
        self::assertInstanceOf(YXmlHook::class, $removedSectionTail);
        self::assertSame('note', $removedSectionTail->hookName());
        self::assertInstanceOf(YXmlText::class, $removedSectionHead);
        self::assertSame('B', $removedSectionHead->toString());
        self::assertSame('<section><strong>C</strong></section>', $section->toString());
        self::assertSame(['xml' => '<section><strong>C</strong></section>'], $doc->toJSON());

        $empty = $fragment->appendElement('empty');
        self::assertNull($empty->pop());
        self::assertNull($empty->shift());

        $fragment->clear();
        self::assertNull($fragment->pop());
        self::assertNull($fragment->shift());

        $fragment->appendElement('final')->appendText('ok');

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame(['xml' => '<final>ok</final>'], $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeXmlPopAndShiftNotifyDeleteDeltas(): void
    {
        $doc = new YDoc(171);
        $fragment = $doc->getXmlFragment('xml');
        $fragment->appendText('A');
        $section = $fragment->appendElement('section');
        $fragment->appendHook('mention');
        $fragmentEvents = [];
        $fragmentObserver = $fragment->observe(static function (array $event) use (&$fragmentEvents): void {
            $fragmentEvents[] = $event;
        });

        $fragment->pop();
        $fragment->shift();
        $fragment->unobserve($fragmentObserver);

        $section->appendText('B');
        $section->appendElement('strong')->appendText('C');
        $section->appendHook('note');
        $elementEvents = [];
        $section->observe(static function (array $event) use (&$elementEvents): void {
            $elementEvents[] = $event;
        });

        $section->pop();
        $section->shift();

        self::assertCount(2, $fragmentEvents);
        self::assertSame([['retain' => 2], ['delete' => 1]], $fragmentEvents[0]['changes']['delta']);
        self::assertSame([['delete' => 1]], $fragmentEvents[1]['changes']['delta']);
        self::assertSame('<section></section>', $fragmentEvents[1]['newValue']);

        self::assertCount(2, $elementEvents);
        self::assertSame([['retain' => 2], ['delete' => 1]], $elementEvents[0]['changes']['delta']);
        self::assertSame([['delete' => 1]], $elementEvents[1]['changes']['delta']);

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame(['xml' => '<section><strong>C</strong></section>'], $target->toJSON());
    }

    public function testNativeXmlGenericTextInsertApisCanBeEncodedAndApplied(): void
    {
        $doc = new YDoc(168);
        $fragment = $doc->getXmlFragment('xml');
        $fragment->insert(0, ['B', 'C']);
        $fragment->unshift(['A']);
        $fragment->push(['D']);
        $section = $fragment->appendElement('section');
        $section->insert(0, ['2', '3']);
        $section->unshift(['1']);
        $section->push(['4']);

        self::assertSame('ABCD<section>1234</section>', $fragment->toString());
        self::assertSame(5, $fragment->length());
        self::assertSame(4, $section->length());

        $target = new YDoc();
        $target->applyUpdateV1($doc->encodeStateAsUpdateV1());

        self::assertSame(['xml' => 'ABCD<section>1234</section>'], $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeXmlPrependChildMutationApisCanBeEncodedAndApplied(): void
    {
        $doc = new YDoc(166);
        $fragment = $doc->getXmlFragment('xml');
        $section = $fragment->appendElement('section');
        $section->appendText('B');
        $section->prependText('A');
        $section->appendElement('strong')->appendText('C');
        $section->prependElement('em')->appendText('D');
        $section->appendHook('after');
        $section->prependHook('before');

        $fragment->appendText('tail');
        $fragment->prependText('lead');
        $fragment->appendElement('footer')->appendText('F');
        $fragment->prependElement('header')->appendText('H');
        $fragment->appendHook('end');
        $fragment->prependHook('start');

        self::assertSame(
            '[object Object]<header>H</header>lead<section>[object Object]<em>D</em>AB<strong>C</strong>[object Object]</section>tail<footer>F</footer>[object Object]',
            $fragment->toString()
        );
        self::assertSame('<section>[object Object]<em>D</em>AB<strong>C</strong>[object Object]</section>', $section->toJSON());
        self::assertSame(7, $fragment->length());
        self::assertSame(6, $section->length());
        self::assertInstanceOf(YXmlHook::class, $fragment->firstChild());
        self::assertSame('start', $fragment->firstChild()?->hookName());
        self::assertInstanceOf(YXmlHook::class, $section->firstChild());
        self::assertSame('before', $section->firstChild()?->hookName());

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeXmlBulkChildMutationApisCanBeEncodedAndApplied(): void
    {
        $doc = new YDoc(173);
        $fragment = $doc->getXmlFragment('xml');
        $fragment->appendText('tail');
        $fragmentElements = $fragment->prependElements(['header', 'main']);
        $fragmentHooks = $fragment->appendHooks(['mention', 'marker']);
        $section = $fragmentElements[1];

        $section->appendText('B');
        $sectionElements = $section->prependElements(['em', 'strong']);
        $sectionHooks = $section->appendHooks(['note', 'flag']);

        self::assertCount(2, $fragmentElements);
        self::assertCount(2, $fragmentHooks);
        self::assertSame('header', $fragmentElements[0]->nodeName());
        self::assertSame('main', $section->nodeName());
        self::assertSame('mention', $fragmentHooks[0]->hookName());
        self::assertSame('marker', $fragmentHooks[1]->hookName());
        self::assertSame('em', $sectionElements[0]->nodeName());
        self::assertSame('strong', $sectionElements[1]->nodeName());
        self::assertSame('note', $sectionHooks[0]->hookName());
        self::assertSame('flag', $sectionHooks[1]->hookName());
        self::assertSame(
            '<header></header><main><em></em><strong></strong>B[object Object][object Object]</main>tail[object Object][object Object]',
            $fragment->toString()
        );
        self::assertSame(5, $fragment->length());
        self::assertSame(5, $section->length());

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeXmlHooksCanBeEncodedAndApplied(): void
    {
        $doc = new YDoc(126);
        $fragment = $doc->getXmlFragment('xml');
        $fragmentHook = $fragment->insertHook(0, 'mention');
        $paragraph = $fragment->insertElement(1, 'p');
        $elementHook = $paragraph->insertHook(0, 'inner');

        self::assertSame('mention', $fragmentHook->hookName());
        self::assertSame('inner', $elementHook->hookName());
        self::assertSame('[object Object]<p>[object Object]</p>', $doc->getXmlFragment('xml')->toString());
        self::assertSame(2, $doc->getXmlFragment('xml')->length());
        self::assertInstanceOf(YXmlHook::class, $doc->getXmlFragment('xml')->get(0));
        self::assertSame('mention', $doc->getXmlFragment('xml')->get(0)?->hookName());
        self::assertInstanceOf(YXmlElement::class, $doc->getXmlFragment('xml')->get(1));
        self::assertSame(1, $paragraph->length());
        self::assertInstanceOf(YXmlHook::class, $paragraph->get(0));
        self::assertSame('inner', $paragraph->get(0)?->hookName());

        $target = new YDoc();
        $target->applyUpdateV1($doc->encodeStateAsUpdateV1());

        self::assertSame(['xml' => '[object Object]<p>[object Object]</p>'], $target->toJSON());
        self::assertSame(2, $target->getXmlFragment('xml')->length());
        self::assertSame('mention', $target->getXmlFragment('xml')->get(0)?->hookName());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeXmlHooksExposeMapLikeState(): void
    {
        $doc = new YDoc(157);
        $hook = $doc->getXmlFragment('xml')->insertHook(0, 'mention');
        $hook->setAttributes(['id' => 7, 'label' => 'Ada', 'active' => true, 'temporary' => 'drop']);
        $snapshot = $doc->snapshot();
        $events = [];
        $hook->observe(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $hook->setAttribute('label', 'Grace');

        self::assertCount(1, $events);
        self::assertSame(['label' => ['action' => 'update', 'oldValue' => 'Ada']], $events[0]['changes']['keys']);
        self::assertIsString($events[0]['update']);
        self::assertIsString($events[0]['updateV2']);

        $events = [];
        $hook->removeAttributes(['active', 'temporary']);

        self::assertCount(1, $events);
        self::assertSame([
            'active' => ['action' => 'delete', 'oldValue' => true],
            'temporary' => ['action' => 'delete', 'oldValue' => 'drop'],
        ], $events[0]['changes']['keys']);
        self::assertIsString($events[0]['update']);
        self::assertIsString($events[0]['updateV2']);

        self::assertTrue($hook->has('id'));
        self::assertTrue($hook->hasAttribute('id'));
        self::assertFalse($hook->has('active'));
        self::assertFalse($hook->hasAttribute('active'));
        self::assertSame(7, $hook->get('id'));
        self::assertSame(7, $hook->getAttribute('id'));
        self::assertSame('Grace', $hook->get('label'));
        self::assertSame('Grace', $hook->getAttribute('label'));
        self::assertSame(['id' => 7, 'label' => 'Grace'], $hook->getAttributes());
        self::assertSame(['id' => 7, 'label' => 'Grace'], $hook->toJSON());
        self::assertSame(2, $hook->size());
        self::assertTrue($hook->hasSnapshot('active', $snapshot));
        self::assertTrue($hook->hasAttributeSnapshot('active', $snapshot));
        self::assertSame('Ada', $hook->getSnapshot('label', $snapshot));
        self::assertSame('Ada', $hook->getAttributeSnapshot('label', $snapshot));
        self::assertSame(4, $hook->sizeSnapshot($snapshot));
        self::assertSame(['active' => true, 'id' => 7, 'label' => 'Ada', 'temporary' => 'drop'], $hook->getAllSnapshot($snapshot));
        self::assertSame(['active' => true, 'id' => 7, 'label' => 'Ada', 'temporary' => 'drop'], $hook->getAttributesSnapshot($snapshot));
        self::assertSame(['active' => true, 'id' => 7, 'label' => 'Ada', 'temporary' => 'drop'], $hook->toArraySnapshot($snapshot));
        self::assertSame(['active' => true, 'id' => 7, 'label' => 'Ada', 'temporary' => 'drop'], $hook->toJSONSnapshot($snapshot));

        $target = new YDoc();
        $target->applyUpdateV1($doc->encodeStateAsUpdateV1());
        $targetHook = $target->getXmlFragment('xml')->get(0);
        self::assertInstanceOf(YXmlHook::class, $targetHook);
        self::assertSame($hook->toJSON(), $targetHook->toJSON());
        self::assertSame($hook->getAttributes(), $targetHook->getAttributes());

        $v2Target = new YDoc();
        $v2Target->applyUpdateV2($doc->encodeStateAsUpdateV2());
        $v2TargetHook = $v2Target->getXmlFragment('xml')->get(0);
        self::assertInstanceOf(YXmlHook::class, $v2TargetHook);
        self::assertSame($hook->toJSON(), $v2TargetHook->toJSON());
        self::assertSame($hook->getAttributes(), $v2TargetHook->getAttributes());
    }

    public function testNativeXmlHooksExposeCollectionConvenienceApis(): void
    {
        $doc = new YDoc(158);
        $hook = $doc->getXmlFragment('xml')->insertHook(0, 'mention');
        $hook['id'] = 7;
        $hook['label'] = 'Ada';
        $hook['active'] = true;
        unset($hook['active']);

        self::assertTrue(isset($hook['id']));
        self::assertFalse(isset($hook['active']));
        self::assertSame(7, $hook['id']);
        self::assertCount(2, $hook);
        self::assertSame(['id', 'label'], $hook->keys());
        self::assertSame([7, 'Ada'], $hook->values());
        self::assertSame(['id' => 7, 'label' => 'Ada'], $hook->entries());
        self::assertSame(['id' => 7, 'label' => 'Ada'], iterator_to_array($hook));

        $visited = [];
        $hook->forEach(static function (mixed $value, string $key) use (&$visited): void {
            $visited[$key] = $value;
        });
        self::assertSame(['id' => 7, 'label' => 'Ada'], $visited);
        self::assertSame(['id' => 'id:7', 'label' => 'label:Ada'], $hook->map(
            static fn (mixed $value, string $key): string => sprintf('%s:%s', $key, $value)
        ));
        self::assertSame(['id' => 7], $hook->filter(
            static fn (mixed $value): bool => is_int($value)
        ));

        $hook->clear();

        self::assertSame([], $hook->toJSON());
        self::assertCount(0, $hook);

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());
        $targetHook = $target->getXmlFragment('xml')->get(0);
        self::assertInstanceOf(YXmlHook::class, $targetHook);
        self::assertSame([], $targetHook->toJSON());
    }

    public function testNativeArrayAndMapCanCreateXmlSharedTypes(): void
    {
        $doc = new YDoc(187);
        $array = $doc->getArray('array');
        $paragraph = $array->insertXmlElement(0, 'p');
        $paragraph->insertText(0, 'Hi');
        $xmlText = $array->appendXmlText('Text');
        $hook = $array->appendXmlHook('mention');
        $hook->set('id', 1);

        $map = $doc->getMap('map');
        $section = $map->setXmlElement('xml', 'section');
        $section->insertText(0, 'Map');
        $mapText = $map->setXmlText('text', 'Map text');
        $mapHook = $map->setXmlHook('hook', 'note');
        $mapHook->set('ok', true);

        self::assertSame([
            'map' => [
                'xml' => '<section>Map</section>',
                'text' => 'Map text',
                'hook' => ['ok' => true],
            ],
            'array' => ['<p>Hi</p>', 'Text', ['id' => 1]],
        ], $doc->toJSON());
        self::assertSame('Text', $xmlText->toString());
        self::assertSame('Map text', $mapText->toString());
        self::assertInstanceOf(YXmlElement::class, $array->getXmlElement(0));
        self::assertInstanceOf(YXmlText::class, $array->getXmlText(1));
        self::assertInstanceOf(YXmlHook::class, $array->getXmlHook(2));
        self::assertInstanceOf(YXmlElement::class, $map->getXmlElement('xml'));
        self::assertInstanceOf(YXmlText::class, $map->getXmlText('text'));
        self::assertInstanceOf(YXmlHook::class, $map->getXmlHook('hook'));

        $target = new YDoc();
        $target->getArray('array');
        $target->getMap('map');
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertInstanceOf(YXmlElement::class, $target->getArray('array')->getXmlElement(0));
        self::assertInstanceOf(YXmlText::class, $target->getArray('array')->getXmlText(1));
        self::assertInstanceOf(YXmlHook::class, $target->getArray('array')->getXmlHook(2));
        self::assertInstanceOf(YXmlElement::class, $target->getMap('map')->getXmlElement('xml'));
        self::assertInstanceOf(YXmlText::class, $target->getMap('map')->getXmlText('text'));
        self::assertInstanceOf(YXmlHook::class, $target->getMap('map')->getXmlHook('hook'));
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeNestedArrayAndMapCanCreateXmlSharedTypes(): void
    {
        $doc = new YDoc(188);
        $root = $doc->getArray('array');
        $nested = $root->insertArray(0);
        $paragraph = $nested->insertXmlElement(0, 'p');
        $paragraph->insertText(0, 'Nested');
        $nestedText = $nested->appendXmlText('Tail');
        $nestedHook = $nested->appendXmlHook('note');
        $nestedHook->set('ok', true);

        $map = $root->insertMap(1);
        $section = $map->setXmlElement('xml', 'section');
        $section->insertText(0, 'Map');
        $mapText = $map->setXmlText('text', 'Map text');
        $mapHook = $map->setXmlHook('hook', 'flag');
        $mapHook->set('id', 7);

        self::assertSame([
            'array' => [
                ['<p>Nested</p>', 'Tail', ['ok' => true]],
                [
                    'xml' => '<section>Map</section>',
                    'text' => 'Map text',
                    'hook' => ['id' => 7],
                ],
            ],
        ], $doc->toJSON());
        self::assertSame('Tail', $nestedText->toString());
        self::assertSame('Map text', $mapText->toString());
        self::assertInstanceOf(YXmlElement::class, $nested->getXmlElement(0));
        self::assertInstanceOf(YXmlText::class, $nested->getXmlText(1));
        self::assertInstanceOf(YXmlHook::class, $nested->getXmlHook(2));
        self::assertInstanceOf(YXmlElement::class, $map->getXmlElement('xml'));
        self::assertInstanceOf(YXmlText::class, $map->getXmlText('text'));
        self::assertInstanceOf(YXmlHook::class, $map->getXmlHook('hook'));

        $target = new YDoc();
        $target->getArray('array');
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());
        $targetRoot = $target->getArray('array');
        $targetNested = $targetRoot->getArray(0);
        $targetMap = $targetRoot->getMap(1);

        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertInstanceOf(YNestedArray::class, $targetNested);
        self::assertInstanceOf(YNestedMap::class, $targetMap);
        self::assertInstanceOf(YXmlElement::class, $targetNested->getXmlElement(0));
        self::assertInstanceOf(YXmlText::class, $targetNested->getXmlText(1));
        self::assertInstanceOf(YXmlHook::class, $targetNested->getXmlHook(2));
        self::assertInstanceOf(YXmlElement::class, $targetMap->getXmlElement('xml'));
        self::assertInstanceOf(YXmlText::class, $targetMap->getXmlText('text'));
        self::assertInstanceOf(YXmlHook::class, $targetMap->getXmlHook('hook'));
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeCollectionsCanCreateXmlFragmentSharedTypes(): void
    {
        $doc = new YDoc(189);
        $array = $doc->getArray('array');
        $fragment = $array->insertXmlFragment(0);
        $paragraph = $fragment->appendElement('p');
        $paragraph->insertText(0, 'Array');
        $fragment->appendText(' tail');

        $map = $doc->getMap('map');
        $mapFragment = $map->setXmlFragment('xml');
        $section = $mapFragment->appendElement('section');
        $section->insertText(0, 'Map');

        $nested = $array->appendArray();
        $nestedFragment = $nested->insertXmlFragment(0);
        $nestedFragment->push(['Nested']);
        $nestedFragment->appendElement('br');

        $nestedMap = $array->appendMap();
        $nestedMapFragment = $nestedMap->setXmlFragment('xml');
        $nestedMapFragment->appendText('Nested map');
        $hook = $nestedMapFragment->appendHook('note');
        $hook->set('ok', true);

        self::assertSame([
            'map' => [
                'xml' => '<section>Map</section>',
            ],
            'array' => [
                '<p>Array</p> tail',
                ['Nested<br></br>'],
                ['xml' => 'Nested map[object Object]'],
            ],
        ], $doc->toJSON());
        self::assertSame('<p>Array</p> tail', $fragment->toJSON());
        self::assertSame('Nested<br></br>', $nestedFragment->toJSON());
        self::assertSame('Nested map[object Object]', $nestedMapFragment->toString());
        self::assertInstanceOf(YNestedXmlFragment::class, $array->getXmlFragment(0));
        self::assertInstanceOf(YNestedXmlFragment::class, $map->getXmlFragment('xml'));
        self::assertInstanceOf(YNestedXmlFragment::class, $nested->getXmlFragment(0));
        self::assertInstanceOf(YNestedXmlFragment::class, $nestedMap->getXmlFragment('xml'));
        self::assertSame(['p', YXmlText::class], $fragment->map(
            static fn (YXmlElement|YXmlText|YXmlHook $child): string => $child instanceof YXmlElement ? $child->nodeName() : $child::class
        ));
        self::assertSame(2, $fragment->length());
        self::assertSame('<p>Array</p>', $fragment->firstChild()?->toString());

        $events = [];
        $fragment->observeDeep(static function (array $deepEvents) use (&$events): void {
            $events[] = $deepEvents;
        });

        $paragraphText = $paragraph->get(0);
        self::assertInstanceOf(YXmlText::class, $paragraphText);
        $paragraphText->insert(5, '!');

        self::assertCount(1, $events);
        self::assertSame('xml', $events[0][0]['target']);
        self::assertSame('YXmlText', $events[0][0]['typeName']);
        self::assertSame([0, 0], $events[0][0]['path']);
        self::assertSame('<p>Array!</p> tail', $fragment->toString());

        $snapshot = $doc->snapshot();
        self::assertSame('<p>Array!</p> tail', $fragment->toStringSnapshot($snapshot));
        self::assertSame(2, $fragment->lengthSnapshot($snapshot));

        $absolute = $doc->absolutePositionFromRelativePosition($fragment->relativePositionAt(1));
        self::assertInstanceOf(YNestedXmlFragment::class, $absolute?->type());
        self::assertSame(1, $absolute?->index());

        $target = new YDoc();
        $target->getArray('array');
        $target->getMap('map');
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());
        $targetArray = $target->getArray('array');
        $targetNested = $targetArray->getArray(1);
        $targetNestedMap = $targetArray->getMap(2);

        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertInstanceOf(YNestedXmlFragment::class, $targetArray->getXmlFragment(0));
        self::assertInstanceOf(YNestedXmlFragment::class, $target->getMap('map')->getXmlFragment('xml'));
        self::assertInstanceOf(YNestedXmlFragment::class, $targetNested?->getXmlFragment(0));
        self::assertInstanceOf(YNestedXmlFragment::class, $targetNestedMap?->getXmlFragment('xml'));
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeArraysCanBulkInsertXmlSharedTypes(): void
    {
        $doc = new YDoc(190);
        $array = $doc->getArray('array');
        $elements = $array->prependXmlElements(['header', 'main']);
        $texts = $array->insertXmlTexts(1, ['A', 'B']);
        $hooks = $array->appendXmlHooks(['mention', 'marker']);
        $fragments = $array->insertXmlFragments(3, 2);
        $fragments[0]->appendText('Nested');
        $fragments[1]->appendElement('aside');

        $nested = $array->appendArray();
        $nestedElements = $nested->appendXmlElements(['section', 'aside']);
        $nestedTexts = $nested->prependXmlTexts(['N']);
        $nestedHooks = $nested->appendXmlHooks(['note']);
        $nestedFragments = $nested->insertXmlFragments(2, 1);
        $nestedFragments[0]->appendText('Inner');

        self::assertContainsOnlyInstancesOf(YXmlElement::class, $elements);
        self::assertContainsOnlyInstancesOf(YXmlText::class, $texts);
        self::assertContainsOnlyInstancesOf(YXmlHook::class, $hooks);
        self::assertContainsOnlyInstancesOf(YNestedXmlFragment::class, $fragments);
        self::assertContainsOnlyInstancesOf(YXmlElement::class, $nestedElements);
        self::assertContainsOnlyInstancesOf(YXmlText::class, $nestedTexts);
        self::assertContainsOnlyInstancesOf(YXmlHook::class, $nestedHooks);
        self::assertContainsOnlyInstancesOf(YNestedXmlFragment::class, $nestedFragments);
        self::assertSame([
            '<header></header>',
            'A',
            'B',
            'Nested',
            '<aside></aside>',
            '<main></main>',
            [],
            [],
            ['N', '<section></section>', 'Inner', '<aside></aside>', []],
        ], $doc->toJSON()['array']);
        self::assertSame('header', $array->getXmlElement(0)?->nodeName());
        self::assertSame('A', $array->getXmlText(1)?->toString());
        self::assertSame('mention', $array->getXmlHook(6)?->hookName());
        self::assertSame('Nested', $array->getXmlFragment(3)?->toString());
        self::assertSame('Inner', $nested->getXmlFragment(2)?->toString());

        $target = new YDoc();
        $target->getArray('array');
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());
        $targetArray = $target->getArray('array');
        $targetNested = $targetArray->getArray(8);

        self::assertNotNull($targetNested);
        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame('Nested', $targetArray->getXmlFragment(3)?->toString());
        self::assertSame('Inner', $targetNested->getXmlFragment(2)?->toString());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeArrayBulkXmlInsertionsRejectInvalidValuesBeforeMutating(): void
    {
        $doc = new YDoc(191);
        $array = $doc->getArray('array');
        $array->appendXmlText('A');

        try {
            $array->insertXmlTexts(1, ['B', 1, 'C']);
            self::fail('Expected invalid root array XML text bulk insertion to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('YArray insertXmlTexts only supports string XML text content.', $exception->getMessage());
        }

        self::assertSame(['A'], $array->toJSON());

        $nested = $array->appendArray();
        $nested->appendXmlElement('p');

        try {
            $nested->appendXmlHooks(['note', false]);
            self::fail('Expected invalid nested array XML hook bulk insertion to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('YNestedArray insertXmlHooks only supports string hook names.', $exception->getMessage());
        }

        self::assertSame(['A', ['<p></p>']], $array->toJSON());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('YArray XML fragment insertion count must be non-negative.');

        $array->appendXmlFragments(-1);
    }

    public function testNativeArraysCanBulkInsertNestedSharedTypes(): void
    {
        $doc = new YDoc(192);
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

        self::assertContainsOnlyInstancesOf(YNestedArray::class, $arrays);
        self::assertContainsOnlyInstancesOf(YNestedText::class, $texts);
        self::assertContainsOnlyInstancesOf(YNestedMap::class, $maps);
        self::assertContainsOnlyInstancesOf(YNestedText::class, $nestedTexts);
        self::assertContainsOnlyInstancesOf(YNestedMap::class, $nestedMaps);
        self::assertContainsOnlyInstancesOf(YNestedArray::class, $nestedArrays);
        self::assertSame([
            'array' => [
                ['A0'],
                'T0',
                'T1',
                ['N0', ['inner'], 'A1', ['kind' => 'nested-map']],
                ['title' => 'M0'],
                ['title' => 'M1'],
            ],
        ], $doc->toJSON());
        self::assertSame('T0', $array->getText(1)?->toString());
        self::assertSame('M1', $array->getMap(5)?->get('title'));
        self::assertSame('inner', $nested->getArray(1)?->get(0));

        $target = new YDoc();
        $target->getArray('array');
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());
        $targetArray = $target->getArray('array');
        $targetNested = $targetArray->getArray(3);

        self::assertNotNull($targetNested);
        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame('T1', $targetArray->getText(2)?->toString());
        self::assertSame('nested-map', $targetNested->getMap(3)?->get('kind'));
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeArrayBulkSharedTypeInsertionsRejectInvalidCounts(): void
    {
        $doc = new YDoc(193);
        $array = $doc->getArray('array');
        $array->appendText()->insert(0, 'A');

        try {
            $array->insertMaps(99, 1);
            self::fail('Expected root array bulk map insertion out of bounds to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('YArray insert index is out of bounds.', $exception->getMessage());
        }

        self::assertSame(['A'], $array->toJSON());

        $nested = $array->appendArray();
        $nested->appendText()->insert(0, 'N');

        try {
            $nested->appendArrays(-1);
            self::fail('Expected nested array bulk array insertion with negative count to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('YNestedArray nested array insertion count must be non-negative.', $exception->getMessage());
        }

        self::assertSame(['A', ['N']], $array->toJSON());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('YArray nested text insertion count must be non-negative.');

        $array->appendTexts(-1);
    }

    public function testNativeMapsCanBulkSetNestedSharedTypes(): void
    {
        $doc = new YDoc(200);
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

        self::assertContainsOnlyInstancesOf(YNestedArray::class, $arrays);
        self::assertContainsOnlyInstancesOf(YNestedText::class, $texts);
        self::assertContainsOnlyInstancesOf(YNestedMap::class, $maps);
        self::assertContainsOnlyInstancesOf(YNestedArray::class, $nestedArrays);
        self::assertContainsOnlyInstancesOf(YNestedText::class, $nestedTexts);
        self::assertContainsOnlyInstancesOf(YNestedMap::class, $nestedMaps);
        self::assertSame([
            'itemsA' => ['A0'],
            'itemsB' => ['B0'],
            'titleA' => 'T0',
            'titleB' => 'T1',
            'metaA' => ['kind' => 'A'],
            'metaB' => [
                'kind' => 'B',
                'nestedItems' => ['inner'],
                'nestedTitle' => 'Nested',
                'nestedMeta' => ['depth' => 2],
            ],
        ], $doc->toJSON()['map']);

        $target = new YDoc();
        $target->getMap('map');
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());
        $targetMap = $target->getMap('map');
        $targetMeta = $targetMap->getMap('metaB');

        self::assertNotNull($targetMeta);
        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame('T1', $targetMap->getText('titleB')?->toString());
        self::assertSame('inner', $targetMeta->getArray('nestedItems')?->get(0));
        self::assertSame(2, $targetMeta->getMap('nestedMeta')?->get('depth'));
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeMapsCanBulkSetXmlSharedTypes(): void
    {
        $doc = new YDoc(201);
        $map = $doc->getMap('map');
        $elements = $map->setXmlElements(['header' => 'header', 'main' => 'main']);
        $texts = $map->setXmlTexts(['lead' => 'Hello', 'tail' => 'Bye']);
        $hooks = $map->setXmlHooks(['mention' => 'mention']);
        $fragments = $map->setXmlFragments(['body', 'aside']);
        $fragments['body']->appendText('Body');
        $fragments['aside']->appendElement('aside');

        $nested = $map->setMap('nested');
        $nestedElements = $nested->setXmlElements(['section' => 'section']);
        $nestedTexts = $nested->setXmlTexts(['copy' => 'Nested']);
        $nestedHooks = $nested->setXmlHooks(['note' => 'note']);
        $nestedFragments = $nested->setXmlFragments(['content']);
        $nestedFragments['content']->appendText('Inner');

        self::assertContainsOnlyInstancesOf(YXmlElement::class, $elements);
        self::assertContainsOnlyInstancesOf(YXmlText::class, $texts);
        self::assertContainsOnlyInstancesOf(YXmlHook::class, $hooks);
        self::assertContainsOnlyInstancesOf(YNestedXmlFragment::class, $fragments);
        self::assertContainsOnlyInstancesOf(YXmlElement::class, $nestedElements);
        self::assertContainsOnlyInstancesOf(YXmlText::class, $nestedTexts);
        self::assertContainsOnlyInstancesOf(YXmlHook::class, $nestedHooks);
        self::assertContainsOnlyInstancesOf(YNestedXmlFragment::class, $nestedFragments);
        self::assertSame('header', $elements['header']->nodeName());
        self::assertSame('Hello', $texts['lead']->toString());
        self::assertSame('mention', $hooks['mention']->hookName());
        self::assertSame('Body', $fragments['body']->toString());
        self::assertSame('Inner', $nestedFragments['content']->toString());

        $json = $doc->toJSON()['map'];
        self::assertSame('<header></header>', $json['header']);
        self::assertSame('<main></main>', $json['main']);
        self::assertSame('Hello', $json['lead']);
        self::assertSame('Bye', $json['tail']);
        self::assertSame([], $json['mention']);
        self::assertSame('Body', $json['body']);
        self::assertSame('<aside></aside>', $json['aside']);
        self::assertSame('<section></section>', $json['nested']['section']);
        self::assertSame('Nested', $json['nested']['copy']);
        self::assertSame([], $json['nested']['note']);
        self::assertSame('Inner', $json['nested']['content']);

        $target = new YDoc();
        $target->getMap('map');
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());
        $targetMap = $target->getMap('map');
        $targetNested = $targetMap->getMap('nested');

        self::assertNotNull($targetNested);
        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame('Body', $targetMap->getXmlFragment('body')?->toString());
        self::assertSame('Inner', $targetNested->getXmlFragment('content')?->toString());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeMapBulkSharedTypeSettersRejectInvalidValuesBeforeMutating(): void
    {
        $doc = new YDoc(202);
        $map = $doc->getMap('map');
        $map->setText('existing')->insert(0, 'A');

        try {
            $map->setArrays(['valid', 3]);
            self::fail('Expected invalid root map bulk array setter to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('YMap setArrays only supports string keys.', $exception->getMessage());
        }

        try {
            $map->setXmlTexts(['validXmlText' => 'B', 'invalidXmlText' => 1]);
            self::fail('Expected invalid root map bulk XML text setter to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('YMap setXmlTexts only supports string XML text content.', $exception->getMessage());
        }

        self::assertSame(['existing' => 'A'], $doc->toJSON()['map']);

        $nested = $map->setMap('nested');
        $nested->setText('existingNested')->insert(0, 'N');

        try {
            $nested->setMaps(['validNested', false]);
            self::fail('Expected invalid nested map bulk map setter to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('YNestedMap setMaps only supports string keys.', $exception->getMessage());
        }

        try {
            $nested->setXmlHooks(['validHook' => 'note', 'invalidHook' => null]);
            self::fail('Expected invalid nested map bulk XML hook setter to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('YNestedMap setXmlHooks only supports string hook names.', $exception->getMessage());
        }

        self::assertSame(['existingNested' => 'N'], $doc->toJSON()['map']['nested']);
    }

    public function testNativeMapBulkSettersEmitBatchedObserverEvents(): void
    {
        $doc = new YDoc(203);
        $map = $doc->getMap('map');
        $mapEvents = [];
        $deepEvents = [];
        $transactions = [];
        $text = null;

        $map->observe(static function (array $event) use (&$mapEvents): void {
            $mapEvents[] = $event;
        });
        $doc->observeDeep(static function (array $events, YDoc $doc, array $transaction) use (&$deepEvents, &$transactions): void {
            $deepEvents[] = $events;
            $transactions[] = $transaction;
        });

        $doc->transact(static function () use ($map, &$text): void {
            $map->setBinaries(['bytes' => "\x01\x02"]);
            $map->setSubdocs(['child' => 'php-map-observer-subdoc']);
            $texts = $map->setTexts(['body']);
            $text = $texts['body'];
            $text->insert(0, 'Observed');
        }, 'map-bulk-observer');

        self::assertInstanceOf(YNestedText::class, $text);
        self::assertCount(1, $mapEvents);
        self::assertSame('map-bulk-observer', $mapEvents[0]['origin']);
        self::assertSame([
            'bytes' => ['action' => 'add'],
            'child' => ['action' => 'add'],
            'body' => ['action' => 'add'],
        ], $mapEvents[0]['changes']['keys']);
        self::assertSame([1, 2], $mapEvents[0]['newValue']['bytes']);
        self::assertInstanceOf(YSubdoc::class, $mapEvents[0]['newValue']['child']);
        self::assertSame('php-map-observer-subdoc', $mapEvents[0]['newValue']['child']->guid());
        self::assertSame('Observed', $mapEvents[0]['newValue']['body']);

        self::assertCount(1, $deepEvents);
        self::assertCount(1, $transactions);
        self::assertSame('map-bulk-observer', $transactions[0]['origin']);
        self::assertSame(['map'], $transactions[0]['changed']);
        self::assertSame([$text->idKey()], $transactions[0]['changedNestedTypes']);

        $rootMapEvent = $this->findDeepEvent($deepEvents[0], 'root', 'map');
        self::assertSame('map', $rootMapEvent['type']);
        self::assertSame($mapEvents[0]['changes']['keys'], $rootMapEvent['changes']['keys']);

        $nestedTextEvent = $this->findDeepEvent($deepEvents[0], 'nested', $text->idKey());
        self::assertSame('text', $nestedTextEvent['type']);
        self::assertSame([['insert' => 'Observed']], $nestedTextEvent['changes']['delta']);
    }

    public function testRemoteXmlScalarChildrenAreExposedByNativeXmlApis(): void
    {
        $decoded = [
            'structs' => [
                [
                    'type' => 'Item',
                    'id' => ['client' => 99, 'clock' => 0],
                    'length' => 1,
                    'origin' => null,
                    'rightOrigin' => null,
                    'parent' => 'array',
                    'parentSub' => null,
                    'content' => ['type' => 'ContentType', 'typeRef' => 4, 'typeName' => 'YXmlFragment'],
                ],
                [
                    'type' => 'Item',
                    'id' => ['client' => 99, 'clock' => 1],
                    'length' => 1,
                    'origin' => null,
                    'rightOrigin' => null,
                    'parent' => ['client' => 99, 'clock' => 0],
                    'parentSub' => null,
                    'content' => ['type' => 'ContentAny', 'values' => ['lead']],
                ],
                [
                    'type' => 'Item',
                    'id' => ['client' => 99, 'clock' => 2],
                    'length' => 1,
                    'origin' => ['client' => 99, 'clock' => 1],
                    'rightOrigin' => null,
                    'parent' => null,
                    'parentSub' => null,
                    'content' => ['type' => 'ContentType', 'typeRef' => 3, 'typeName' => 'YXmlElement', 'nodeName' => 'p'],
                ],
                [
                    'type' => 'Item',
                    'id' => ['client' => 99, 'clock' => 3],
                    'length' => 1,
                    'origin' => null,
                    'rightOrigin' => null,
                    'parent' => ['client' => 99, 'clock' => 2],
                    'parentSub' => null,
                    'content' => ['type' => 'ContentAny', 'values' => ['inside']],
                ],
            ],
            'deleteSet' => [],
        ];

        foreach ([DecodedUpdate::encodeV1($decoded['structs']), DecodedUpdate::encodeV2($decoded['structs'])] as $index => $update) {
            $doc = new YDoc();
            $array = $doc->getArray('array');
            $index === 0 ? $doc->applyUpdateV1($update) : $doc->applyUpdateV2($update);

            $fragment = $array->getXmlFragment(0);
            self::assertInstanceOf(YNestedXmlFragment::class, $fragment);
            self::assertSame(['array' => ['lead<p>inside</p>']], $doc->toJSON());
            self::assertSame('lead', $fragment->get(0));
            self::assertSame('lead', $fragment[0]);
            self::assertSame(['lead', '<p>inside</p>'], array_map(
                static fn (YXmlElement|YXmlText|YXmlHook|string $child): string => $child instanceof YXmlElement ? $child->toString() : (string) $child,
                $fragment->toArray()
            ));

            $paragraph = $fragment->get(1);
            self::assertInstanceOf(YXmlElement::class, $paragraph);
            self::assertSame('inside', $paragraph->get(0));
            self::assertSame('inside', $paragraph[0]);
        }
    }

    public function testRemoteRootXmlScalarChildrenDoNotBreakInsertAfterReferenceApis(): void
    {
        $decoded = [
            'structs' => [
                [
                    'type' => 'Item',
                    'id' => ['client' => 101, 'clock' => 0],
                    'length' => 1,
                    'origin' => null,
                    'rightOrigin' => null,
                    'parent' => 'xml',
                    'parentSub' => null,
                    'content' => ['type' => 'ContentAny', 'values' => ['lead']],
                ],
                [
                    'type' => 'Item',
                    'id' => ['client' => 101, 'clock' => 1],
                    'length' => 1,
                    'origin' => ['client' => 101, 'clock' => 0],
                    'rightOrigin' => null,
                    'parent' => null,
                    'parentSub' => null,
                    'content' => ['type' => 'ContentType', 'typeRef' => 3, 'typeName' => 'YXmlElement', 'nodeName' => 'p'],
                ],
                [
                    'type' => 'Item',
                    'id' => ['client' => 101, 'clock' => 2],
                    'length' => 1,
                    'origin' => null,
                    'rightOrigin' => null,
                    'parent' => ['client' => 101, 'clock' => 1],
                    'parentSub' => null,
                    'content' => ['type' => 'ContentAny', 'values' => ['inside']],
                ],
                [
                    'type' => 'Item',
                    'id' => ['client' => 101, 'clock' => 3],
                    'length' => 1,
                    'origin' => ['client' => 101, 'clock' => 2],
                    'rightOrigin' => null,
                    'parent' => null,
                    'parentSub' => null,
                    'content' => ['type' => 'ContentType', 'typeRef' => 3, 'typeName' => 'YXmlElement', 'nodeName' => 'em'],
                ],
            ],
            'deleteSet' => [],
        ];

        foreach ([DecodedUpdate::encodeV1($decoded['structs']), DecodedUpdate::encodeV2($decoded['structs'])] as $index => $update) {
            $doc = new YDoc();
            $fragment = $doc->getXmlFragment('xml');
            $index === 0 ? $doc->applyUpdateV1($update) : $doc->applyUpdateV2($update);

            $paragraph = $fragment->get(1);
            self::assertInstanceOf(YXmlElement::class, $paragraph);
            $emphasis = $paragraph->get(1);
            self::assertInstanceOf(YXmlElement::class, $emphasis);

            $paragraph->insertTextAfter($emphasis, 'inner-tail');
            $fragment->insertTextAfter($paragraph, 'outer-tail');

            self::assertSame(['xml' => 'lead<p>inside<em></em>inner-tail</p>outer-tail'], $doc->toJSON());
        }
    }

    public function testRemoteNestedXmlScalarChildrenDoNotBreakInsertAfterReferenceApis(): void
    {
        $decoded = [
            'structs' => [
                [
                    'type' => 'Item',
                    'id' => ['client' => 102, 'clock' => 0],
                    'length' => 1,
                    'origin' => null,
                    'rightOrigin' => null,
                    'parent' => 'array',
                    'parentSub' => null,
                    'content' => ['type' => 'ContentType', 'typeRef' => 4, 'typeName' => 'YXmlFragment'],
                ],
                [
                    'type' => 'Item',
                    'id' => ['client' => 102, 'clock' => 1],
                    'length' => 1,
                    'origin' => null,
                    'rightOrigin' => null,
                    'parent' => ['client' => 102, 'clock' => 0],
                    'parentSub' => null,
                    'content' => ['type' => 'ContentAny', 'values' => ['lead']],
                ],
                [
                    'type' => 'Item',
                    'id' => ['client' => 102, 'clock' => 2],
                    'length' => 1,
                    'origin' => ['client' => 102, 'clock' => 1],
                    'rightOrigin' => null,
                    'parent' => null,
                    'parentSub' => null,
                    'content' => ['type' => 'ContentType', 'typeRef' => 3, 'typeName' => 'YXmlElement', 'nodeName' => 'p'],
                ],
                [
                    'type' => 'Item',
                    'id' => ['client' => 102, 'clock' => 3],
                    'length' => 1,
                    'origin' => null,
                    'rightOrigin' => null,
                    'parent' => ['client' => 102, 'clock' => 2],
                    'parentSub' => null,
                    'content' => ['type' => 'ContentAny', 'values' => ['inside']],
                ],
                [
                    'type' => 'Item',
                    'id' => ['client' => 102, 'clock' => 4],
                    'length' => 1,
                    'origin' => ['client' => 102, 'clock' => 3],
                    'rightOrigin' => null,
                    'parent' => null,
                    'parentSub' => null,
                    'content' => ['type' => 'ContentType', 'typeRef' => 3, 'typeName' => 'YXmlElement', 'nodeName' => 'em'],
                ],
            ],
            'deleteSet' => [],
        ];

        foreach ([DecodedUpdate::encodeV1($decoded['structs']), DecodedUpdate::encodeV2($decoded['structs'])] as $index => $update) {
            $doc = new YDoc();
            $array = $doc->getArray('array');
            $index === 0 ? $doc->applyUpdateV1($update) : $doc->applyUpdateV2($update);

            $fragment = $array->getXmlFragment(0);
            self::assertInstanceOf(YNestedXmlFragment::class, $fragment);
            $paragraph = $fragment->get(1);
            self::assertInstanceOf(YXmlElement::class, $paragraph);
            $emphasis = $paragraph->get(1);
            self::assertInstanceOf(YXmlElement::class, $emphasis);

            $paragraph->insertTextAfter($emphasis, 'inner-tail');
            $fragment->insertTextAfter($paragraph, 'outer-tail');

            self::assertSame(['array' => ['lead<p>inside<em></em>inner-tail</p>outer-tail']], $doc->toJSON());
        }
    }

    public function testNativeXmlNodesExposeVisibleSiblings(): void
    {
        $doc = new YDoc(156);
        $fragment = $doc->getXmlFragment('xml');
        $lead = $fragment->insertText(0, 'lead');
        $hook = $fragment->insertHook(1, 'marker');
        $article = $fragment->insertElement(2, 'article');
        $aside = $fragment->insertElement(3, 'aside');
        $articleText = $article->insertText(0, 'body');
        $articleHook = $article->insertHook(1, 'inline');
        $strong = $article->insertElement(2, 'strong');
        $snapshot = $doc->snapshot();

        self::assertSame($hook->idKey(), $lead->nextSibling()?->idKey());
        self::assertSame($lead->idKey(), $hook->prevSibling()?->idKey());
        self::assertSame($article->idKey(), $hook->nextSibling()?->idKey());
        self::assertSame($hook->idKey(), $article->prevSibling()?->idKey());
        self::assertSame($aside->idKey(), $article->nextSibling()?->idKey());
        self::assertNull($aside->nextSibling());
        self::assertSame($articleHook->idKey(), $articleText->nextSibling()?->idKey());
        self::assertSame($articleText->idKey(), $articleHook->prevSibling()?->idKey());
        self::assertSame($strong->idKey(), $articleHook->nextSibling()?->idKey());
        self::assertSame($articleHook->idKey(), $strong->prevSibling()?->idKey());

        $fragment->delete(1, 1);
        $article->delete(1, 1);

        self::assertSame($article->idKey(), $lead->nextSibling()?->idKey());
        self::assertSame($lead->idKey(), $article->prevSibling()?->idKey());
        self::assertSame($strong->idKey(), $articleText->nextSibling()?->idKey());
        self::assertSame($articleText->idKey(), $strong->prevSibling()?->idKey());
        self::assertSame($hook->idKey(), $lead->nextSiblingSnapshot($snapshot)?->idKey());
        self::assertSame($hook->idKey(), $article->prevSiblingSnapshot($snapshot)?->idKey());
        self::assertSame($articleHook->idKey(), $articleText->nextSiblingSnapshot($snapshot)?->idKey());
        self::assertSame($articleText->idKey(), $articleHook->prevSiblingSnapshot($snapshot)?->idKey());
        self::assertSame($strong->idKey(), $articleHook->nextSiblingSnapshot($snapshot)?->idKey());
        self::assertSame($articleHook->idKey(), $strong->prevSiblingSnapshot($snapshot)?->idKey());

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());
        $targetLead = $target->getXmlFragment('xml')->get(0);
        $targetArticle = $target->getXmlFragment('xml')->get(1);
        self::assertInstanceOf(YXmlText::class, $targetLead);
        self::assertInstanceOf(YXmlElement::class, $targetArticle);
        self::assertSame($targetArticle->idKey(), $targetLead->nextSibling()?->idKey());
        self::assertSame($targetLead->idKey(), $targetArticle->prevSibling()?->idKey());
    }

    public function testNativeArrayCanCreateAndMutateNestedSharedTypes(): void
    {
        $doc = new YDoc(118);
        $array = $doc->getArray('array');
        $nestedArray = $array->insertArray(0);
        $nestedMap = $array->insertMap(1);
        $nestedText = $array->insertText(2);

        $nestedArray->insert(0, [1, 3]);
        $nestedArray->insert(1, [2]);
        $nestedMap->set('title', 'Nested');
        $nestedText->insert(0, 'Hi');

        self::assertSame(['array' => [[1, 2, 3], ['title' => 'Nested'], 'Hi']], $doc->toJSON());
        self::assertSame([1, 2, 3], $nestedArray->toJSON());
        self::assertSame(['title' => 'Nested'], $nestedMap->toJSON());
        self::assertSame('Hi', $nestedText->toJSON());

        $target = new YDoc();
        $target->applyUpdateV1($doc->encodeStateAsUpdateV1());

        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeMapCanCreateAndMutateNestedSharedTypes(): void
    {
        $doc = new YDoc(119);
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

        self::assertSame([
            'map' => [
                'items' => ['B', 'C'],
                'meta' => ['title' => 'Nested'],
                'body' => 'Nestedtext',
            ],
        ], $doc->toJSON());
        self::assertSame(['B', 'C'], $items->toJSON());
        self::assertSame(['title' => 'Nested'], $meta->toJSON());
        self::assertSame('Nestedtext', $body->toJSON());

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeNestedMapCanCreateAndMutateDeepContent(): void
    {
        $doc = new YDoc(185);
        $root = $doc->getMap('map')->setMap('root');
        $items = $root->setArray('items');
        $meta = $root->setMap('meta');
        $body = $root->setText('body');

        $root->setAll(['title' => 'Draft', 'temp' => true]);
        $root->deleteAll(['temp']);
        $root->setBinary('bytes', "\x00\x7f\xff");
        $child = $root->setSubdoc('child', 'php-deep-nested-map-subdoc', ['meta' => ['scope' => 'nested-map']]);
        $items->push(['B']);
        $items->unshift(['A']);
        $items->insertBinary(1, "\x01\x02");
        $itemMap = $items->appendMap();
        $itemMap->set('kind', 'inline');
        $meta->setAll(['count' => 2, 'remove' => false]);
        $meta->delete('remove');
        $meta->setText('summary')->insert(0, 'Meta');
        $body->insert(0, 'Deep map');
        $body->format(0, 4, ['bold' => true]);

        self::assertSame('php-deep-nested-map-subdoc', $child->guid());
        self::assertSame([
            'map' => [
                'root' => [
                    'items' => ['A', [1, 2], 'B', ['kind' => 'inline']],
                    'meta' => ['count' => 2, 'summary' => 'Meta'],
                    'body' => 'Deep map',
                    'title' => 'Draft',
                    'bytes' => [0, 127, 255],
                    'child' => [],
                ],
            ],
        ], $doc->toJSON());
        self::assertSame([
            ['insert' => 'Deep', 'attributes' => ['bold' => true]],
            ['insert' => ' map'],
        ], $body->toDelta());

        $target = new YDoc();
        $target->applyUpdateV1($doc->encodeStateAsUpdateV1());
        $targetRoot = $target->getMap('map')->getMap('root');

        self::assertInstanceOf(YNestedMap::class, $targetRoot);
        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame($body->toDelta(), $targetRoot->getText('body')?->toDelta());
        self::assertInstanceOf(YSubdoc::class, $targetRoot->get('child'));
        self::assertSame('php-deep-nested-map-subdoc', $targetRoot->get('child')->guid());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testMapTextExplicitInsertAttributesClearOmittedActiveFormats(): void
    {
        $doc = new YDoc(183);
        $text = $doc->getMap('map')->setText('body');
        $text->insert(0, 'MapText');
        $text->format(0, 3, ['bold' => true]);
        $text->delete(3, 4);
        $text->insert(3, ' body', ['italic' => true]);

        self::assertSame([
            ['insert' => 'Map', 'attributes' => ['bold' => true]],
            ['insert' => ' body', 'attributes' => ['italic' => true]],
        ], $text->toDelta());
    }

    public function testMapTextCanApplyDelta(): void
    {
        $doc = new YDoc(184);
        $text = $doc->getMap('map')->setText('body');
        $text->insert(0, 'MapText');
        $text->applyDelta([
            ['retain' => 3, 'attributes' => ['bold' => true]],
            ['delete' => 1],
            ['insert' => ' body', 'attributes' => ['italic' => true]],
            ['insert' => ['mention' => 'Ada'], 'attributes' => ['role' => 'author']],
        ]);

        self::assertSame('Map bodyext', $text->toString());
        self::assertSame([
            ['insert' => 'Map', 'attributes' => ['bold' => true]],
            ['insert' => ' body', 'attributes' => ['italic' => true]],
            ['insert' => ['mention' => 'Ada'], 'attributes' => ['role' => 'author']],
            ['insert' => 'ext'],
        ], $text->toDelta());
        self::assertSame(['map' => ['body' => 'Map bodyext']], $doc->toJSON());

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());
        $targetText = $target->getMap('map')->getText('body');

        self::assertNotNull($targetText);
        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame($text->toDelta(), $targetText->toDelta());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNestedSharedTypeHandlesCanBeRecoveredFromContainers(): void
    {
        $doc = new YDoc(130);
        $array = $doc->getArray('array');
        $array->insertArray(0);
        $array->insertMap(1);
        $array->insertText(2);
        $array->append('scalar');

        $rootArray = $array->getArray(0);
        $rootMap = $array->getMap(1);
        $rootText = $array->getText(2);

        self::assertInstanceOf(YNestedArray::class, $rootArray);
        self::assertInstanceOf(YNestedMap::class, $rootMap);
        self::assertInstanceOf(YNestedText::class, $rootText);
        self::assertNull($array->getSharedType(3));
        self::assertNull($array->getText(3));

        $rootArray->append('A');
        $nestedText = $rootArray->insertText(1);
        $rootArray->getText(1)?->insert(0, 'Nested');
        $rootArray->getText(1)?->setAttribute('lang', 'en');
        $rootMap->set('title', 'Recovered');
        $rootMap->setArray('items');
        $rootMap->getArray('items')?->append('M');
        $rootText->insert(0, 'Root');
        $rootText->setAttribute('role', 'body');

        $map = $doc->getMap('map');
        $map->setText('body');
        $map->getText('body')?->insert(0, 'Map text');
        $map->getText('body')?->setAttribute('kind', 'summary');

        self::assertSame([
            'map' => [
                'body' => 'Map text',
            ],
            'array' => [
                ['A', 'Nested'],
                ['title' => 'Recovered', 'items' => ['M']],
                'Root',
                'scalar',
            ],
        ], $doc->toJSON());
        self::assertSame(['lang' => 'en'], $nestedText->getAttributes());
        self::assertSame(['role' => 'body'], $rootText->getAttributes());

        try {
            $array->getText(0);
            self::fail('Expected a type mismatch exception.');
        } catch (\UnexpectedValueException $exception) {
            self::assertSame('YArray item at index 0 is not a nested YText.', $exception->getMessage());
        }

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());
        $targetArray = $target->getArray('array');
        $targetRootArray = $targetArray->getArray(0);
        $targetRootMap = $targetArray->getMap(1);
        $targetRootText = $targetArray->getText(2);
        $targetMapText = $target->getMap('map')->getText('body');

        self::assertInstanceOf(YNestedArray::class, $targetRootArray);
        self::assertInstanceOf(YNestedMap::class, $targetRootMap);
        self::assertInstanceOf(YNestedText::class, $targetRootText);
        self::assertInstanceOf(YNestedText::class, $targetMapText);
        self::assertSame(['lang' => 'en'], $targetRootArray->getText(1)?->getAttributes());
        self::assertSame(['role' => 'body'], $targetRootText->getAttributes());
        self::assertSame(['kind' => 'summary'], $targetMapText->getAttributes());

        $targetRootArray->getText(1)?->insert(6, ' text');
        $targetRootMap->getArray('items')?->append('N');
        $targetRootText->setAttribute('role', 'heading');
        $targetMapText->insert(8, ' updated');

        self::assertSame([
            'map' => [
                'body' => 'Map text updated',
            ],
            'array' => [
                ['A', 'Nested text'],
                ['title' => 'Recovered', 'items' => ['M', 'N']],
                'Root',
                'scalar',
            ],
        ], $target->toJSON());
        self::assertSame(['role' => 'heading'], $targetRootText->getAttributes());
    }

    public function testNestedSharedTypeObserversReceiveLocalEvents(): void
    {
        $doc = new YDoc(150);
        $root = $doc->getArray('array');
        $nestedArray = $root->insertArray(0);
        $nestedMap = $root->insertMap(1);
        $nestedText = $root->insertText(2);
        $arrayEvents = [];
        $mapEvents = [];
        $textEvents = [];
        $transactionEvents = [];

        $arrayObserver = $nestedArray->observe(static function (array $event) use (&$arrayEvents): void {
            $arrayEvents[] = $event;
        });
        $mapObserver = $nestedMap->observe(static function (array $event) use (&$mapEvents): void {
            $mapEvents[] = $event;
        });
        $textObserver = $nestedText->observe(static function (array $event) use (&$textEvents): void {
            $textEvents[] = $event;
        });
        $doc->observeTransaction(static function (array $event) use (&$transactionEvents): void {
            $transactionEvents[] = $event;
        });
        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        $doc->transact(static function () use ($nestedArray, $nestedMap, $nestedText): void {
            $nestedArray->insert(0, ['A', 'B']);
            $nestedArray->delete(0, 1);
            $nestedMap->set('title', 'draft');
            $nestedMap->set('title', 'published');
            $nestedText->insert(0, 'AB');
            $nestedText->delete(1, 1);
            $nestedText->insert(1, 'C');
        }, 'nested-local');

        self::assertCount(1, $arrayEvents);
        self::assertSame('nested-local', $arrayEvents[0]['origin']);
        self::assertSame([], $arrayEvents[0]['oldValue']);
        self::assertSame(['B'], $arrayEvents[0]['newValue']);
        self::assertSame([['insert' => ['B']]], $arrayEvents[0]['changes']['delta']);
        self::assertSame([], $arrayEvents[0]['changes']['keys']);
        self::assertIsString($arrayEvents[0]['update']);
        self::assertIsString($arrayEvents[0]['updateV2']);

        self::assertCount(1, $mapEvents);
        self::assertSame('nested-local', $mapEvents[0]['origin']);
        self::assertSame([], $mapEvents[0]['oldValue']);
        self::assertSame(['title' => 'published'], $mapEvents[0]['newValue']);
        self::assertSame(['title' => ['action' => 'add']], $mapEvents[0]['changes']['keys']);
        self::assertSame([], $mapEvents[0]['changes']['delta']);

        self::assertCount(1, $textEvents);
        self::assertSame('nested-local', $textEvents[0]['origin']);
        self::assertSame('', $textEvents[0]['oldValue']);
        self::assertSame('AC', $textEvents[0]['newValue']);
        self::assertSame([['insert' => 'AC']], $textEvents[0]['changes']['delta']);
        self::assertSame([], $textEvents[0]['changes']['keys']);
        self::assertCount(1, $transactionEvents);
        self::assertEqualsCanonicalizing([
            $arrayEvents[0]['idKey'],
            $mapEvents[0]['idKey'],
            $textEvents[0]['idKey'],
        ], $transactionEvents[0]['changedNestedTypes']);

        $target->applyUpdateV2($arrayEvents[0]['updateV2']);
        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());

        $nestedArray->unobserve($arrayObserver);
        $nestedMap->unobserve($mapObserver);
        $nestedText->unobserve($textObserver);

        $nestedArray->push(['ignored']);
        $nestedMap->set('ignored', true);
        $nestedText->insert(2, '!');

        self::assertCount(1, $arrayEvents);
        self::assertCount(1, $mapEvents);
        self::assertCount(1, $textEvents);
    }

    public function testNestedSharedTypeObserversReceiveRemoteEvents(): void
    {
        $source = new YDoc(151);
        $root = $source->getArray('array');
        $sourceArray = $root->insertArray(0);
        $sourceMap = $root->insertMap(1);
        $sourceText = $root->insertText(2);

        $target = new YDoc();
        $target->applyUpdateV1($source->encodeStateAsUpdateV1());
        $targetArray = new YNestedArray($target, $sourceArray->idKey(), $target->nestedArrayValue($sourceArray->idKey()));
        $targetMap = new YNestedMap($target, $sourceMap->idKey(), $target->nestedMapValue($sourceMap->idKey()));
        $targetText = new YNestedText($target, $sourceText->idKey(), $target->nestedTextValue($sourceText->idKey()));

        $arrayEvents = [];
        $mapEvents = [];
        $textEvents = [];
        $updates = [];

        $targetArray->observe(static function (array $event) use (&$arrayEvents): void {
            $arrayEvents[] = $event;
        });
        $targetMap->observe(static function (array $event) use (&$mapEvents): void {
            $mapEvents[] = $event;
        });
        $targetText->observe(static function (array $event) use (&$textEvents): void {
            $textEvents[] = $event;
        });
        $source->observeUpdate(static function (string $update) use (&$updates): void {
            $updates[] = $update;
        });

        $source->transact(static function () use ($sourceArray, $sourceMap, $sourceText): void {
            $sourceArray->push(['A']);
            $sourceMap->set('title', 'Remote');
            $sourceText->insert(0, 'Hi');
        }, 'source-local');

        self::assertCount(1, $updates);
        $target->applyUpdateV1($updates[0], 'remote-origin');

        self::assertCount(1, $arrayEvents);
        self::assertSame($sourceArray->idKey(), $arrayEvents[0]['idKey']);
        self::assertSame('remote-origin', $arrayEvents[0]['origin']);
        self::assertSame([], $arrayEvents[0]['oldValue']);
        self::assertSame(['A'], $arrayEvents[0]['newValue']);
        self::assertSame([['insert' => ['A']]], $arrayEvents[0]['changes']['delta']);
        self::assertSame([], $arrayEvents[0]['changes']['keys']);
        self::assertSame($updates[0], $arrayEvents[0]['update']);
        self::assertIsString($arrayEvents[0]['updateV2']);

        self::assertCount(1, $mapEvents);
        self::assertSame($sourceMap->idKey(), $mapEvents[0]['idKey']);
        self::assertSame('remote-origin', $mapEvents[0]['origin']);
        self::assertSame([], $mapEvents[0]['oldValue']);
        self::assertSame(['title' => 'Remote'], $mapEvents[0]['newValue']);
        self::assertSame(['title' => ['action' => 'add']], $mapEvents[0]['changes']['keys']);
        self::assertSame([], $mapEvents[0]['changes']['delta']);

        self::assertCount(1, $textEvents);
        self::assertSame($sourceText->idKey(), $textEvents[0]['idKey']);
        self::assertSame('remote-origin', $textEvents[0]['origin']);
        self::assertSame('', $textEvents[0]['oldValue']);
        self::assertSame('Hi', $textEvents[0]['newValue']);
        self::assertSame([['insert' => 'Hi']], $textEvents[0]['changes']['delta']);
        self::assertSame([], $textEvents[0]['changes']['keys']);

        self::assertSame($source->toJSON(), $target->toJSON());
        self::assertSame($source->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNestedTextSupportsFormattingAndDeltas(): void
    {
        $doc = new YDoc(132);
        $text = $doc->getArray('array')->insertText(0);
        $text->insert(0, 'Hello');
        $text->format(1, 3, ['bold' => true]);
        $text->insert(5, '!', ['italic' => true]);
        $text->delete(5, 1);
        $text->insert(5, '?');

        self::assertSame('Hello?', $text->toString());
        self::assertSame([
            ['insert' => 'H'],
            ['insert' => 'ell', 'attributes' => ['bold' => true]],
            ['insert' => 'o?'],
        ], $text->toDelta());
        self::assertSame(['array' => ['Hello?']], $doc->toJSON());

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNestedTextExplicitInsertAttributesClearOmittedActiveFormats(): void
    {
        $doc = new YDoc(182);
        $text = $doc->getArray('array')->insertText(0);
        $text->insert(0, 'MapText');
        $text->format(0, 3, ['bold' => true]);
        $text->delete(3, 4);
        $text->insert(3, ' body', ['italic' => true]);

        self::assertSame([
            ['insert' => 'Map', 'attributes' => ['bold' => true]],
            ['insert' => ' body', 'attributes' => ['italic' => true]],
        ], $text->toDelta());
    }

    public function testNestedTextSupportsEmbedsAndDeltas(): void
    {
        $doc = new YDoc(133);
        $text = $doc->getArray('array')->insertText(0);
        $text->insert(0, 'A');
        $text->insertEmbed(1, ['image' => 'cat.png'], ['alt' => 'Cat']);
        $text->insert(2, 'B');

        self::assertSame('AB', $text->toString());
        self::assertSame([
            ['insert' => 'A'],
            ['insert' => ['image' => 'cat.png'], 'attributes' => ['alt' => 'Cat']],
            ['insert' => 'B', 'attributes' => ['alt' => 'Cat']],
        ], $text->toDelta());
        self::assertSame(['array' => ['AB']], $doc->toJSON());

        $target = new YDoc();
        $target->applyUpdateV1($doc->encodeStateAsUpdateV1());

        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNestedTextCanApplyDelta(): void
    {
        $doc = new YDoc(140);
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

        self::assertSame('A😀BC', $text->toString());
        self::assertSame([
            ['insert' => 'A', 'attributes' => ['bold' => true]],
            ['insert' => '😀BC'],
        ], $text->toDelta());

        $target = new YDoc();
        $target->applyUpdateV1($doc->encodeStateAsUpdateV1());

        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNestedSharedTypesCanCreateNestedChildrenAndBinaryContent(): void
    {
        $doc = new YDoc(127);
        $root = $doc->getArray('array');
        $outerArray = $root->insertArray(0);
        $outerMap = $root->insertMap(1);

        $outerArray->insert(0, ['start']);
        $childArray = $outerArray->insertArray(1);
        $childMap = $outerArray->insertMap(2);
        $childText = $outerArray->insertText(3);
        $outerArray->insertBinary(4, "\x00\xff");
        $outerArray->prependBinary("\x01");
        $outerArray->appendBinary("\x02");
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

        self::assertSame([
            'array' => [
                [
                    [1],
                    'start',
                    [1, 2],
                    ['ok' => true],
                    'nested',
                    [0, 255],
                    [2],
                ],
                [
                    'items' => ['A'],
                    'meta' => ['count' => 2],
                    'body' => 'Map text',
                    'bytes' => [1, 2],
                ],
            ],
        ], $doc->toJSON());

        $decoded = DecodedUpdate::decodeV1($doc->encodeStateAsUpdateV1());
        $binaryStructs = array_values(array_filter(
            $decoded['structs'],
            static fn (array $struct): bool => ($struct['content']['type'] ?? null) === 'ContentBinary'
        ));
        self::assertCount(4, $binaryStructs);

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());

        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testRawGcUpdateCanBeAppliedAndReencoded(): void
    {
        $update = DecodedUpdate::encodeV1([
            [
                'type' => 'GC',
                'id' => ['client' => 245, 'clock' => 0],
                'length' => 3,
            ],
        ]);
        $doc = new YDoc();
        $doc->applyUpdateV1($update);

        self::assertSame([], $doc->toJSON());
        $decoded = DecodedUpdate::decodeV1($doc->encodeStateAsUpdateV1());
        self::assertSame([
            [
                'type' => 'GC',
                'id' => ['client' => 245, 'clock' => 0],
                'length' => 3,
            ],
        ], $decoded['structs']);
        self::assertSame([], $decoded['deleteSet']);

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());
        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testGcDocEncodesDeletedTextContentAsContentDeleted(): void
    {
        $doc = new YDoc(21, gc: true);
        $text = $doc->getText('content');
        $text->insert(0, 'ABCD');
        $text->delete(1, 2);

        $expected = [
            [
                'type' => 'Item',
                'id' => ['client' => 21, 'clock' => 0],
                'length' => 1,
                'origin' => null,
                'rightOrigin' => null,
                'parent' => 'content',
                'parentSub' => null,
                'content' => ['type' => 'ContentString', 'value' => 'A'],
            ],
            [
                'type' => 'Item',
                'id' => ['client' => 21, 'clock' => 1],
                'length' => 2,
                'origin' => ['client' => 21, 'clock' => 0],
                'rightOrigin' => null,
                'parent' => null,
                'parentSub' => null,
                'content' => ['type' => 'ContentDeleted', 'length' => 2],
            ],
            [
                'type' => 'Item',
                'id' => ['client' => 21, 'clock' => 3],
                'length' => 1,
                'origin' => ['client' => 21, 'clock' => 2],
                'rightOrigin' => null,
                'parent' => null,
                'parentSub' => null,
                'content' => ['type' => 'ContentString', 'value' => 'D'],
            ],
        ];

        self::assertTrue($doc->gc());
        self::assertSame(['content' => 'AD'], $doc->toJSON());
        self::assertSame($expected, DecodedUpdate::decodeV1($doc->encodeStateAsUpdateV1())['structs']);
        self::assertSame($expected, DecodedUpdate::decodeV2($doc->encodeStateAsUpdateV2())['structs']);

        $target = new YDoc();
        $target->applyUpdateV2($doc->encodeStateAsUpdateV2());
        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testGcDocEncodesDeletedArrayContentAsContentDeleted(): void
    {
        $doc = new YDoc(22, gc: true);
        $array = $doc->getArray('array');
        $array->insert(0, ['A', 'B', 'C', 'D']);
        $array->delete(1, 2);

        $expected = [
            [
                'type' => 'Item',
                'id' => ['client' => 22, 'clock' => 0],
                'length' => 1,
                'origin' => null,
                'rightOrigin' => null,
                'parent' => 'array',
                'parentSub' => null,
                'content' => ['type' => 'ContentAny', 'values' => ['A']],
            ],
            [
                'type' => 'Item',
                'id' => ['client' => 22, 'clock' => 1],
                'length' => 2,
                'origin' => ['client' => 22, 'clock' => 0],
                'rightOrigin' => null,
                'parent' => null,
                'parentSub' => null,
                'content' => ['type' => 'ContentDeleted', 'length' => 2],
            ],
            [
                'type' => 'Item',
                'id' => ['client' => 22, 'clock' => 3],
                'length' => 1,
                'origin' => ['client' => 22, 'clock' => 2],
                'rightOrigin' => null,
                'parent' => null,
                'parentSub' => null,
                'content' => ['type' => 'ContentAny', 'values' => ['D']],
            ],
        ];

        self::assertSame(['array' => ['A', 'D']], $doc->toJSON());
        self::assertSame($expected, DecodedUpdate::decodeV1($doc->encodeStateAsUpdateV1())['structs']);
        self::assertSame($expected, DecodedUpdate::decodeV2($doc->encodeStateAsUpdateV2())['structs']);
    }

    public function testGcDocEncodesDeletedMapContentAsContentDeleted(): void
    {
        $doc = new YDoc(23, gc: true);
        $map = $doc->getMap('map');
        $map->set('title', 'Draft');
        $map->set('title', 'Published');

        $decoded = DecodedUpdate::decodeV1($doc->encodeStateAsUpdateV1());

        self::assertSame(['map' => ['title' => 'Published']], $doc->toJSON());
        self::assertSame('ContentDeleted', $decoded['structs'][0]['content']['type']);
        self::assertSame('map', $decoded['structs'][0]['parent']);
        self::assertSame('title', $decoded['structs'][0]['parentSub']);
        self::assertSame('ContentAny', $decoded['structs'][1]['content']['type']);
        self::assertSame(['Published'], $decoded['structs'][1]['content']['values']);
        self::assertSame($decoded['structs'], DecodedUpdate::decodeV2($doc->encodeStateAsUpdateV2())['structs']);
    }

    public function testGcDocEncodesDeletedXmlAttributeContentAsContentDeleted(): void
    {
        $doc = new YDoc(24, gc: true);
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $paragraph->setAttribute('class', 'draft');
        $paragraph->setAttribute('class', 'published');

        $decoded = DecodedUpdate::decodeV1($doc->encodeStateAsUpdateV1());

        self::assertSame(['xml' => '<p class="published"></p>'], $doc->toJSON());
        self::assertSame('YXmlElement', $decoded['structs'][0]['content']['typeName']);
        self::assertSame('ContentDeleted', $decoded['structs'][1]['content']['type']);
        self::assertSame(['client' => 24, 'clock' => 0], $decoded['structs'][1]['parent']);
        self::assertSame('class', $decoded['structs'][1]['parentSub']);
        self::assertSame('ContentAny', $decoded['structs'][2]['content']['type']);
        self::assertSame(['published'], $decoded['structs'][2]['content']['values']);
        self::assertSame($decoded['structs'], DecodedUpdate::decodeV2($doc->encodeStateAsUpdateV2())['structs']);
    }

    public function testGcDocEncodesDeletedXmlElementContentAsContentDeleted(): void
    {
        $doc = new YDoc(25, gc: true);
        $xml = $doc->getXmlFragment('xml');
        $xml->insertElement(0, 'p');
        $xml->insertElement(1, 'q');
        $xml->delete(0, 1);

        $decoded = DecodedUpdate::decodeV1($doc->encodeStateAsUpdateV1());

        self::assertSame(['xml' => '<q></q>'], $doc->toJSON());
        self::assertSame('ContentDeleted', $decoded['structs'][0]['content']['type']);
        self::assertSame('xml', $decoded['structs'][0]['parent']);
        self::assertSame('ContentType', $decoded['structs'][1]['content']['type']);
        self::assertSame('YXmlElement', $decoded['structs'][1]['content']['typeName']);
        self::assertSame('q', $decoded['structs'][1]['content']['nodeName']);
        self::assertSame($decoded['structs'], DecodedUpdate::decodeV2($doc->encodeStateAsUpdateV2())['structs']);
    }

    public function testGcDocEncodesDeletedNestedTextContentAsContentDeleted(): void
    {
        $doc = new YDoc(26, gc: true);
        $text = $doc->getArray('array')->insertText(0);
        $text->insert(0, 'ABCD');
        $text->delete(1, 2);

        $expected = [
            [
                'type' => 'Item',
                'id' => ['client' => 26, 'clock' => 0],
                'length' => 1,
                'origin' => null,
                'rightOrigin' => null,
                'parent' => 'array',
                'parentSub' => null,
                'content' => ['type' => 'ContentType', 'typeRef' => 2, 'typeName' => 'YText'],
            ],
            [
                'type' => 'Item',
                'id' => ['client' => 26, 'clock' => 1],
                'length' => 1,
                'origin' => null,
                'rightOrigin' => null,
                'parent' => ['client' => 26, 'clock' => 0],
                'parentSub' => null,
                'content' => ['type' => 'ContentString', 'value' => 'A'],
            ],
            [
                'type' => 'Item',
                'id' => ['client' => 26, 'clock' => 2],
                'length' => 2,
                'origin' => ['client' => 26, 'clock' => 1],
                'rightOrigin' => null,
                'parent' => null,
                'parentSub' => null,
                'content' => ['type' => 'ContentDeleted', 'length' => 2],
            ],
            [
                'type' => 'Item',
                'id' => ['client' => 26, 'clock' => 4],
                'length' => 1,
                'origin' => ['client' => 26, 'clock' => 3],
                'rightOrigin' => null,
                'parent' => null,
                'parentSub' => null,
                'content' => ['type' => 'ContentString', 'value' => 'D'],
            ],
        ];

        self::assertSame(['array' => ['AD']], $doc->toJSON());
        self::assertSame($expected, DecodedUpdate::decodeV1($doc->encodeStateAsUpdateV1())['structs']);
        self::assertSame($expected, DecodedUpdate::decodeV2($doc->encodeStateAsUpdateV2())['structs']);
    }

    public function testGcDocEncodesDeletedNestedArrayContentAsContentDeleted(): void
    {
        $doc = new YDoc(27, gc: true);
        $array = $doc->getArray('array')->insertArray(0);
        $array->insert(0, ['A', 'B', 'C', 'D']);
        $array->delete(1, 2);

        $expected = [
            [
                'type' => 'Item',
                'id' => ['client' => 27, 'clock' => 0],
                'length' => 1,
                'origin' => null,
                'rightOrigin' => null,
                'parent' => 'array',
                'parentSub' => null,
                'content' => ['type' => 'ContentType', 'typeRef' => 0, 'typeName' => 'YArray'],
            ],
            [
                'type' => 'Item',
                'id' => ['client' => 27, 'clock' => 1],
                'length' => 1,
                'origin' => null,
                'rightOrigin' => null,
                'parent' => ['client' => 27, 'clock' => 0],
                'parentSub' => null,
                'content' => ['type' => 'ContentAny', 'values' => ['A']],
            ],
            [
                'type' => 'Item',
                'id' => ['client' => 27, 'clock' => 2],
                'length' => 2,
                'origin' => ['client' => 27, 'clock' => 1],
                'rightOrigin' => null,
                'parent' => null,
                'parentSub' => null,
                'content' => ['type' => 'ContentDeleted', 'length' => 2],
            ],
            [
                'type' => 'Item',
                'id' => ['client' => 27, 'clock' => 4],
                'length' => 1,
                'origin' => ['client' => 27, 'clock' => 3],
                'rightOrigin' => null,
                'parent' => null,
                'parentSub' => null,
                'content' => ['type' => 'ContentAny', 'values' => ['D']],
            ],
        ];

        self::assertSame(['array' => [['A', 'D']]], $doc->toJSON());
        self::assertSame($expected, DecodedUpdate::decodeV1($doc->encodeStateAsUpdateV1())['structs']);
        self::assertSame($expected, DecodedUpdate::decodeV2($doc->encodeStateAsUpdateV2())['structs']);
    }

    public function testGcDocEncodesDeletedNestedMapContentAsContentDeleted(): void
    {
        $doc = new YDoc(28, gc: true);
        $map = $doc->getArray('array')->insertMap(0);
        $map->set('title', 'Draft');
        $map->set('title', 'Published');

        $decoded = DecodedUpdate::decodeV1($doc->encodeStateAsUpdateV1());

        self::assertSame(['array' => [['title' => 'Published']]], $doc->toJSON());
        self::assertSame('YMap', $decoded['structs'][0]['content']['typeName']);
        self::assertSame('ContentDeleted', $decoded['structs'][1]['content']['type']);
        self::assertSame(['client' => 28, 'clock' => 0], $decoded['structs'][1]['parent']);
        self::assertSame('title', $decoded['structs'][1]['parentSub']);
        self::assertSame('ContentAny', $decoded['structs'][2]['content']['type']);
        self::assertSame(['Published'], $decoded['structs'][2]['content']['values']);
        self::assertSame($decoded['structs'], DecodedUpdate::decodeV2($doc->encodeStateAsUpdateV2())['structs']);
    }

    public function testNestedSharedTypesCanCreateSubdocs(): void
    {
        $doc = new YDoc(147);
        $root = $doc->getArray('array');
        $outerArray = $root->insertArray(0);
        $outerMap = $root->insertMap(1);

        $arraySubdoc = $outerArray->insertSubdoc(0, 'php-nested-array-subdoc', ['meta' => ['nested' => true]]);
        $prependedSubdoc = $outerArray->prependSubdoc('php-prepended-nested-array-subdoc', ['meta' => ['position' => 'first']]);
        $appendedSubdoc = $outerArray->appendSubdoc('php-appended-nested-array-subdoc', ['meta' => ['position' => 'last']]);
        $mapSubdoc = $outerMap->setSubdoc('child', 'php-nested-map-subdoc', ['autoLoad' => true]);

        self::assertSame('php-nested-array-subdoc', $arraySubdoc->guid());
        self::assertSame(['nested' => true], $arraySubdoc->meta());
        self::assertSame('php-prepended-nested-array-subdoc', $prependedSubdoc->guid());
        self::assertSame(['position' => 'first'], $prependedSubdoc->meta());
        self::assertSame('php-appended-nested-array-subdoc', $appendedSubdoc->guid());
        self::assertSame(['position' => 'last'], $appendedSubdoc->meta());
        self::assertSame('php-nested-map-subdoc', $mapSubdoc->guid());
        self::assertTrue($mapSubdoc->shouldLoad());
        self::assertSame('php-prepended-nested-array-subdoc', $outerArray->get(0)->guid());
        self::assertSame('php-nested-array-subdoc', $outerArray->get(1)->guid());
        self::assertSame('php-appended-nested-array-subdoc', $outerArray->get(2)->guid());
        self::assertSame('php-nested-map-subdoc', $outerMap->get('child')->guid());
        self::assertSame('php-prepended-nested-array-subdoc', $outerArray->getSubdoc(0)->guid());
        self::assertSame('php-nested-array-subdoc', $outerArray->getSubdoc(1)->guid());
        self::assertSame('php-appended-nested-array-subdoc', $outerArray->getSubdoc(2)->guid());
        self::assertNull($outerArray->getSubdoc(99));
        self::assertSame('php-nested-map-subdoc', $outerMap->getSubdoc('child')->guid());
        self::assertNull($outerMap->getSubdoc('missing'));
        self::assertSame(['array' => [[[], [], []], ['child' => []]]], $doc->toJSON());

        $target = new YDoc();
        $target->applyUpdateV1($doc->encodeStateAsUpdateV1());

        self::assertSame($doc->toJSON(), $target->toJSON());
        $targetNestedArray = $target->getArray('array')->get(0);
        $targetNestedMap = $target->getArray('array')->get(1);
        self::assertIsArray($targetNestedArray);
        self::assertIsArray($targetNestedMap);
        self::assertInstanceOf(YSubdoc::class, $targetNestedArray[0]);
        self::assertInstanceOf(YSubdoc::class, $targetNestedArray[1]);
        self::assertInstanceOf(YSubdoc::class, $targetNestedArray[2]);
        self::assertInstanceOf(YSubdoc::class, $targetNestedMap['child']);
        self::assertSame('php-prepended-nested-array-subdoc', $targetNestedArray[0]->guid());
        self::assertSame('php-nested-array-subdoc', $targetNestedArray[1]->guid());
        self::assertSame('php-appended-nested-array-subdoc', $targetNestedArray[2]->guid());
        self::assertSame('php-nested-map-subdoc', $targetNestedMap['child']->guid());
        self::assertSame('php-prepended-nested-array-subdoc', $target->getArray('array')->getArray(0)?->getSubdoc(0)?->guid());
        self::assertSame('php-nested-map-subdoc', $target->getArray('array')->getMap(1)?->getSubdoc('child')?->guid());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeMutationsNotifyValidIncrementalUpdates(): void
    {
        $doc = new YDoc(103);
        $updates = [];
        $doc->observeUpdate(static function (string $update) use (&$updates): void {
            $updates[] = $update;
        });

        $text = $doc->getText('content');
        $text->insert(0, 'Hello');
        $text->insert(5, ' Yjs');
        $text->delete(5, 1);

        self::assertCount(3, $updates);

        $target = new YDoc();
        foreach ($updates as $update) {
            $target->applyUpdateV1($update);
        }

        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testNativeMutationsNotifyValidIncrementalV2Updates(): void
    {
        $doc = new YDoc(111);
        $updates = [];
        $doc->observeUpdateV2(static function (string $update) use (&$updates): void {
            $updates[] = $update;
        });

        $array = $doc->getArray('array');
        $array->insert(0, ['A', 'C']);
        $array->insert(1, ['B']);
        $array->delete(0, 1);

        self::assertCount(3, $updates);

        $target = new YDoc();
        foreach ($updates as $update) {
            $target->applyUpdateV2($update);
        }

        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());
    }

    public function testCanUnobserveNativeUpdates(): void
    {
        $doc = new YDoc(104);
        $updates = [];
        $observerId = $doc->observeUpdate(static function (string $update) use (&$updates): void {
            $updates[] = $update;
        });

        $doc->getArray('array')->insert(0, [1]);
        $doc->unobserveUpdate($observerId);
        $doc->getArray('array')->insert(1, [2]);

        self::assertCount(1, $updates);
    }

    public function testDocumentEventAliasesExposeYjsStyleStreams(): void
    {
        $doc = new YDoc(631);
        $updates = [];
        $v2Updates = [];
        $transactions = [];
        $transactionAliasOrigins = [];
        $removed = [];
        $custom = [];

        $doc->on('update', static function (string $update, mixed $origin, YDoc $observedDoc, ?array $transaction) use (&$updates, $doc): void {
            self::assertSame($doc, $observedDoc);
            self::assertIsArray($transaction);
            $updates[] = [
                'update' => $update,
                'origin' => $origin,
                'local' => $transaction['local'],
                'changed' => $transaction['changed'],
            ];
        });
        $doc->once('updateV2', static function (string $update, mixed $origin, YDoc $observedDoc, ?array $transaction) use (&$v2Updates, $doc): void {
            self::assertSame($doc, $observedDoc);
            self::assertIsArray($transaction);
            $v2Updates[] = [
                'update' => $update,
                'origin' => $origin,
                'local' => $transaction['local'],
            ];
        });
        $doc->on('afterTransaction', static function (array $transaction, YDoc $observedDoc) use (&$transactions, $doc): void {
            self::assertSame($doc, $observedDoc);
            $transactions[] = $transaction;
        });
        $doc->on('transaction', static function (array $transaction) use (&$transactionAliasOrigins): void {
            $transactionAliasOrigins[] = $transaction['origin'];
        });

        $removedCallback = static function () use (&$removed): void {
            $removed[] = 'callback';
        };
        $doc->on('update', $removedCallback);
        $doc->off('update', $removedCallback);
        $removedId = $doc->on('update', static function () use (&$removed): void {
            $removed[] = 'id';
        });
        $doc->off('update', $removedId);

        $customId = $doc->on('custom', static function (string $label, int $count) use (&$custom): void {
            $custom[] = [$label, $count];
        });

        $doc->emit('custom', ['manual', 2]);
        $doc->off('custom', $customId);
        $doc->emit('custom', ['ignored', 1]);

        $doc->transact(static function (YDoc $doc): void {
            $doc->getText('content')->insert(0, 'A');
        }, 'first-alias-origin');
        $doc->transact(static function (YDoc $doc): void {
            $doc->getText('content')->insert(1, 'B');
        }, 'second-alias-origin');

        self::assertSame([['manual', 2]], $custom);
        self::assertSame([], $removed);
        self::assertCount(2, $updates);
        self::assertSame(['first-alias-origin', 'second-alias-origin'], array_column($updates, 'origin'));
        self::assertSame([true, true], array_column($updates, 'local'));
        self::assertSame([['content'], ['content']], array_column($updates, 'changed'));
        self::assertCount(1, $v2Updates);
        self::assertSame('first-alias-origin', $v2Updates[0]['origin']);
        self::assertTrue($v2Updates[0]['local']);
        self::assertCount(2, $transactions);
        self::assertSame(['first-alias-origin', 'second-alias-origin'], array_column($transactions, 'origin'));
        self::assertSame(['first-alias-origin', 'second-alias-origin'], $transactionAliasOrigins);

        $target = new YDoc();
        $remoteUpdates = [];
        $target->on('update', static function (string $update, mixed $origin, YDoc $observedDoc, ?array $transaction) use (&$remoteUpdates, $target): void {
            self::assertSame($target, $observedDoc);
            self::assertIsArray($transaction);
            $remoteUpdates[] = [
                'update' => $update,
                'origin' => $origin,
                'local' => $transaction['local'],
                'changed' => $transaction['changed'],
            ];
        });

        $target->applyUpdateV1($doc->encodeStateAsUpdateV1(), 'remote-alias-origin');

        self::assertCount(1, $remoteUpdates);
        self::assertSame('remote-alias-origin', $remoteUpdates[0]['origin']);
        self::assertFalse($remoteUpdates[0]['local']);
        self::assertSame(['content'], $remoteUpdates[0]['changed']);
        self::assertSame($doc->toJSON(), $target->toJSON());
    }

    public function testNativeTransactionOriginIsPassedToObservers(): void
    {
        $doc = new YDoc(127);
        $origins = [];
        $v2Origins = [];
        $updates = [];
        $v2Updates = [];
        $events = [];
        $doc->observeUpdate(static function (string $update, YDoc $doc, mixed $origin) use (&$origins, &$updates): void {
            $origins[] = $origin;
            $updates[] = $update;
        });
        $doc->observeUpdateV2(static function (string $update, YDoc $doc, mixed $origin) use (&$v2Origins, &$v2Updates): void {
            $v2Origins[] = $origin;
            $v2Updates[] = $update;
        });
        $doc->getText('content')->observe(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $result = $doc->transact(static function (YDoc $doc): string {
            $doc->getText('content')->insert(0, 'A');
            $doc->getText('content')->insert(1, 'B');

            return 'done';
        }, 'local-origin');

        self::assertSame('done', $result);
        self::assertSame(['local-origin'], $origins);
        self::assertSame(['local-origin'], $v2Origins);
        self::assertCount(1, $updates);
        self::assertCount(1, $v2Updates);
        self::assertCount(1, $events);
        self::assertSame('local-origin', $events[0]['origin']);
        self::assertFalse($events[0]['oldExists']);
        self::assertTrue($events[0]['newExists']);
        self::assertNull($events[0]['oldValue']);
        self::assertSame('AB', $events[0]['newValue']);
        self::assertSame($updates[0], $events[0]['update']);
        self::assertSame($v2Updates[0], $events[0]['updateV2']);

        $target = new YDoc();
        $target->applyUpdateV1($updates[0]);
        self::assertSame($doc->toJSON(), $target->toJSON());
        self::assertSame($doc->encodeStateVector(), $target->encodeStateVector());

        $v2Target = new YDoc();
        $v2Target->applyUpdateV2($v2Updates[0]);
        self::assertSame($doc->toJSON(), $v2Target->toJSON());
        self::assertSame($doc->encodeStateVector(), $v2Target->encodeStateVector());
    }

    public function testNativeTransactionBatchesStructsAndDeletesIntoSingleUpdate(): void
    {
        $doc = new YDoc(130);
        $updates = [];
        $v2Updates = [];
        $events = [];
        $transactionEvents = [];
        $doc->observeUpdate(static function (string $update, YDoc $doc, mixed $origin) use (&$updates): void {
            $updates[] = [$update, $origin];
        });
        $doc->observeUpdateV2(static function (string $update, YDoc $doc, mixed $origin) use (&$v2Updates): void {
            $v2Updates[] = [$update, $origin];
        });
        $doc->getArray('array')->observe(static function (array $event) use (&$events): void {
            $events[] = $event;
        });
        $doc->observeTransaction(static function (array $event) use (&$transactionEvents): void {
            $transactionEvents[] = $event;
        });

        $doc->transact(static function (YDoc $doc): void {
            $array = $doc->getArray('array');
            $array->insert(0, ['A', 'B', 'C']);
            $array->delete(1, 1);
        }, 'batch-origin');

        self::assertSame(['array' => ['A', 'C']], $doc->toJSON());
        self::assertCount(1, $updates);
        self::assertSame('batch-origin', $updates[0][1]);
        self::assertCount(1, $v2Updates);
        self::assertSame('batch-origin', $v2Updates[0][1]);
        self::assertCount(1, $events);
        self::assertSame(['A', 'C'], $events[0]['newValue']);
        self::assertSame($updates[0][0], $events[0]['update']);
        self::assertSame($v2Updates[0][0], $events[0]['updateV2']);
        self::assertCount(1, $transactionEvents);
        self::assertSame([
            130 => [
                ['clock' => 1, 'length' => 1],
            ],
        ], $transactionEvents[0]['deleteSet']);

        $target = new YDoc();
        $target->applyUpdateV1($updates[0][0]);
        self::assertSame($doc->toJSON(), $target->toJSON());

        $v2Target = new YDoc();
        $v2Target->applyUpdateV2($v2Updates[0][0]);
        self::assertSame($doc->toJSON(), $v2Target->toJSON());
    }

    public function testNativeTransactionObserversReceiveBatchedLocalEvent(): void
    {
        $doc = new YDoc(142);
        $events = [];
        $doc->observeTransaction(static function (array $event, YDoc $doc) use (&$events): void {
            $events[] = $event;
        });

        $doc->transact(static function (YDoc $doc): void {
            $doc->getText('content')->insert(0, 'A');
            $doc->getText('content')->insert(1, 'B');
        }, 'transaction-origin');

        self::assertCount(1, $events);
        self::assertSame('transaction-origin', $events[0]['origin']);
        self::assertTrue($events[0]['local']);
        self::assertSame([], $events[0]['beforeStateVector']);
        self::assertSame([142 => 2], $events[0]['afterStateVector']);
        self::assertSame([], $events[0]['before']);
        self::assertSame(['content' => 'AB'], $events[0]['after']);
        self::assertSame(['content'], $events[0]['changed']);
        self::assertSame([], $events[0]['changedXmlNodes']);
        self::assertSame([], $events[0]['deleteSet']);

        $target = new YDoc();
        $target->applyUpdateV1($events[0]['update']);
        self::assertSame($doc->toJSON(), $target->toJSON());

        $v2Target = new YDoc();
        $v2Target->applyUpdateV2($events[0]['updateV2']);
        self::assertSame($doc->toJSON(), $v2Target->toJSON());
    }

    public function testNativeTextAttributeObserversReceiveKeyChanges(): void
    {
        $doc = new YDoc(182);
        $text = $doc->getText('content');
        $events = [];
        $text->observe(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $doc->transact(static function () use ($text): void {
            $text->setAttribute('lang', 'en');
            $text->insert(0, 'Hi');
        }, 'text-attribute-origin');

        self::assertCount(1, $events);
        self::assertSame('text-attribute-origin', $events[0]['origin']);
        self::assertSame(['content' => 'Hi'], $doc->toJSON());
        self::assertSame(['lang' => ['action' => 'add']], $events[0]['changes']['keys']);
        self::assertSame([['insert' => 'Hi']], $events[0]['changes']['delta']);

        $doc->transact(static function () use ($text): void {
            $text->removeAttribute('lang');
        }, 'text-attribute-delete');

        self::assertCount(2, $events);
        self::assertSame(['lang' => ['action' => 'delete', 'oldValue' => 'en']], $events[1]['changes']['keys']);
        self::assertSame([], $events[1]['changes']['delta']);
    }

    public function testNativeTransactionObserversReceiveDirectMutationStateVectors(): void
    {
        $doc = new YDoc(143);
        $events = [];
        $doc->observeTransaction(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $doc->getText('content')->insert(0, 'A');
        $doc->getText('content')->insert(1, 'B');

        self::assertCount(2, $events);
        self::assertSame([], $events[0]['beforeStateVector']);
        self::assertSame([143 => 1], $events[0]['afterStateVector']);
        self::assertSame([143 => 1], $events[1]['beforeStateVector']);
        self::assertSame([143 => 2], $events[1]['afterStateVector']);
        self::assertSame(['content' => 'A'], $events[1]['before']);
        self::assertSame(['content' => 'AB'], $events[1]['after']);
    }

    public function testRemoteApplyNotifiesTransactionObservers(): void
    {
        $source = new YDoc(144);
        $source->getMap('map')->set('title', 'Hello');
        $update = $source->encodeStateAsUpdateV1();

        $target = new YDoc();
        $events = [];
        $target->observeTransaction(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $target->applyUpdateV1($update, 'remote-origin');

        self::assertCount(1, $events);
        self::assertSame('remote-origin', $events[0]['origin']);
        self::assertFalse($events[0]['local']);
        self::assertSame([], $events[0]['beforeStateVector']);
        self::assertSame([144 => 1], $events[0]['afterStateVector']);
        self::assertSame([], $events[0]['before']);
        self::assertSame(['map' => ['title' => 'Hello']], $events[0]['after']);
        self::assertSame(['map'], $events[0]['changed']);
        self::assertSame($update, $events[0]['update']);
        self::assertSame([], $events[0]['deleteSet']);

        $v2Target = new YDoc();
        $v2Target->applyUpdateV2($events[0]['updateV2']);
        self::assertSame($target->toJSON(), $v2Target->toJSON());
    }

    public function testRemoteDeleteOnlyDiffNotifiesTransactionDeleteSet(): void
    {
        $source = new YDoc(166);
        $source->getArray('array')->insert(0, ['A', 'B', 'C']);
        $target = new YDoc();
        $target->applyUpdateV1($source->encodeStateAsUpdateV1());
        $source->getArray('array')->delete(1, 1);
        $update = $source->encodeStateAsUpdateV1($target->encodeStateVector());
        $events = [];
        $target->observeTransaction(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $target->applyUpdateV1($update, 'remote-delete');

        self::assertCount(1, $events);
        self::assertFalse($events[0]['local']);
        self::assertSame('remote-delete', $events[0]['origin']);
        self::assertSame(['array' => ['A', 'C']], $events[0]['after']);
        self::assertSame([
            166 => [
                ['clock' => 1, 'length' => 1],
            ],
        ], $events[0]['deleteSet']);
        self::assertSame($source->toJSON(), $target->toJSON());
    }

    public function testPartialTextDiffCanStartAtUtf16SurrogatePair(): void
    {
        $prefix = new YDoc(521);
        $prefix->getText('content')->insert(0, 'A');

        $source = new YDoc(521);
        $source->getText('content')->insert(0, 'A😀C');

        $diff = $source->encodeStateAsUpdateV1($prefix->encodeStateVector());

        self::assertSame([
            'structs' => [
                [
                    'type' => 'Item',
                    'id' => ['client' => 521, 'clock' => 1],
                    'length' => 3,
                    'origin' => ['client' => 521, 'clock' => 0],
                    'rightOrigin' => null,
                    'parent' => null,
                    'parentSub' => null,
                    'content' => [
                        'type' => 'ContentString',
                        'value' => '😀C',
                    ],
                ],
            ],
            'deleteSet' => [],
        ], DecodedUpdate::decodeV1($diff));

        $target = new YDoc();
        $target->applyUpdateV1($prefix->encodeStateAsUpdateV1());
        $target->applyUpdateV1($diff);

        self::assertSame($source->toJSON(), $target->toJSON());
        self::assertSame($source->encodeStateVector(), $target->encodeStateVector());
    }

    public function testPartialXmlTextDiffCanStartAtUtf16SurrogatePair(): void
    {
        $prefix = new YDoc(522);
        $prefix->getXmlFragment('xml')->insertElement(0, 'p')->insertText(0, 'A');

        $source = new YDoc(522);
        $source->getXmlFragment('xml')->insertElement(0, 'p')->insertText(0, 'A😀C');

        $diff = $source->encodeStateAsUpdateV1($prefix->encodeStateVector());

        self::assertSame([
            'structs' => [
                [
                    'type' => 'Item',
                    'id' => ['client' => 522, 'clock' => 3],
                    'length' => 3,
                    'origin' => ['client' => 522, 'clock' => 2],
                    'rightOrigin' => null,
                    'parent' => null,
                    'parentSub' => null,
                    'content' => [
                        'type' => 'ContentString',
                        'value' => '😀C',
                    ],
                ],
            ],
            'deleteSet' => [],
        ], DecodedUpdate::decodeV1($diff));

        $target = new YDoc();
        $target->applyUpdateV1($prefix->encodeStateAsUpdateV1());
        $target->applyUpdateV1($diff);

        self::assertSame(['xml' => '<p>A😀C</p>'], $target->toJSON());
        self::assertSame($source->encodeStateVector(), $target->encodeStateVector());
    }

    public function testPartialGcTextDiffCanStartInsideDeletedContent(): void
    {
        $prefix = new YDoc(523);
        $prefix->getText('content')->insert(0, 'AB');

        $source = new YDoc(523, gc: true);
        $source->getText('content')->insert(0, 'ABCD');
        $source->getText('content')->delete(1, 2);

        $diff = $source->encodeStateAsUpdateV1($prefix->encodeStateVector());

        self::assertSame([
            'structs' => [
                [
                    'type' => 'Item',
                    'id' => ['client' => 523, 'clock' => 2],
                    'length' => 1,
                    'origin' => ['client' => 523, 'clock' => 1],
                    'rightOrigin' => null,
                    'parent' => null,
                    'parentSub' => null,
                    'content' => ['type' => 'ContentDeleted', 'length' => 1],
                ],
                [
                    'type' => 'Item',
                    'id' => ['client' => 523, 'clock' => 3],
                    'length' => 1,
                    'origin' => ['client' => 523, 'clock' => 2],
                    'rightOrigin' => null,
                    'parent' => null,
                    'parentSub' => null,
                    'content' => ['type' => 'ContentString', 'value' => 'D'],
                ],
            ],
            'deleteSet' => [
                523 => [
                    ['clock' => 1, 'length' => 2],
                ],
            ],
        ], DecodedUpdate::decodeV1($diff));
        self::assertSame(DecodedUpdate::decodeV1($diff), DecodedUpdate::decodeV2($source->encodeStateAsUpdateV2($prefix->encodeStateVector())));

        $target = new YDoc();
        $target->applyUpdateV1($prefix->encodeStateAsUpdateV1());
        $target->applyUpdateV1($diff);

        self::assertSame($source->toJSON(), $target->toJSON());
        self::assertSame($source->encodeStateVector(), $target->encodeStateVector());
    }

    public function testPartialNestedMapDiffCanAppendDeepSharedContent(): void
    {
        $prefix = new YDoc(535);
        $prefix->getMap('map')->setMap('root');

        $source = new YDoc(535);
        $root = $source->getMap('map')->setMap('root');
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

        $targetStateVector = $prefix->encodeStateVector();
        $diffV1 = $source->encodeStateAsUpdateV1($targetStateVector);
        $diffV2 = $source->encodeStateAsUpdateV2($targetStateVector);

        self::assertSame(DecodedUpdate::decodeV1($diffV1), DecodedUpdate::decodeV2($diffV2));

        $target = new YDoc();
        $target->applyUpdateV2($prefix->encodeStateAsUpdateV2());
        $target->applyUpdateV2($diffV2);
        $targetRoot = $target->getMap('map')->getMap('root');

        self::assertInstanceOf(YNestedMap::class, $targetRoot);
        self::assertSame($source->toJSON(), $target->toJSON());
        self::assertSame([
            ['insert' => 'Diff', 'attributes' => ['bold' => true]],
            ['insert' => ' map'],
        ], $targetRoot->getText('body')?->toDelta());
        self::assertSame($source->encodeStateVector(), $target->encodeStateVector());
    }

    public function testPartialGcNestedArrayDiffCanStartInsideDeletedContent(): void
    {
        $prefix = new YDoc(524);
        $prefixArray = $prefix->getArray('array')->insertArray(0);
        $prefixArray->insert(0, ['A', 'B']);

        $source = new YDoc(524, gc: true);
        $sourceArray = $source->getArray('array')->insertArray(0);
        $sourceArray->insert(0, ['A', 'B', 'C', 'D']);
        $sourceArray->delete(1, 2);

        $diff = $source->encodeStateAsUpdateV2($prefix->encodeStateVector());
        $decoded = DecodedUpdate::decodeV2($diff);

        self::assertSame('ContentDeleted', $decoded['structs'][0]['content']['type']);
        self::assertSame(['client' => 524, 'clock' => 3], $decoded['structs'][0]['id']);
        self::assertSame(1, $decoded['structs'][0]['content']['length']);
        self::assertSame('ContentAny', $decoded['structs'][1]['content']['type']);
        self::assertSame(['D'], $decoded['structs'][1]['content']['values']);
        self::assertSame([
            524 => [
                ['clock' => 2, 'length' => 2],
            ],
        ], $decoded['deleteSet']);
        self::assertSame(DecodedUpdate::decodeV1($source->encodeStateAsUpdateV1($prefix->encodeStateVector())), $decoded);

        $target = new YDoc();
        $target->applyUpdateV2($prefix->encodeStateAsUpdateV2());
        $target->applyUpdateV2($diff);

        self::assertSame($source->toJSON(), $target->toJSON());
        self::assertSame($source->encodeStateVector(), $target->encodeStateVector());
    }

    public function testOutOfOrderRemoteDeleteOnlyUpdateIsObserved(): void
    {
        $source = new YDoc(167);
        $source->getText('content')->insert(0, 'ABCD');
        $baseUpdate = $source->encodeStateAsUpdateV1();
        $known = new YDoc(167);
        $known->getText('content')->insert(0, 'ABCD');
        $source->getText('content')->delete(1, 2);
        $deleteOnlyUpdate = $source->encodeStateAsUpdateV1($known->encodeStateVector());

        self::assertSame(['content' => 'AD'], $source->toJSON());

        $relay = new YDoc();
        $updates = [];
        $transactions = [];
        $relay->observeUpdate(static function (string $update) use (&$updates): void {
            $updates[] = $update;
        });
        $relay->observeTransaction(static function (array $event) use (&$transactions): void {
            $transactions[] = $event;
        });

        $relay->applyUpdateV1($deleteOnlyUpdate, 'delete-before-base');

        self::assertCount(1, $updates);
        self::assertCount(1, $transactions);
        self::assertSame('delete-before-base', $transactions[0]['origin']);
        self::assertSame([], $transactions[0]['changed']);
        self::assertSame([
            167 => [
                ['clock' => 1, 'length' => 2],
            ],
        ], $transactions[0]['deleteSet']);
        self::assertSame([], $relay->toJSON());

        $relay->applyUpdateV1($baseUpdate, 'base-after-delete');

        self::assertCount(2, $updates);
        self::assertSame($source->toJSON(), $relay->toJSON());

        $target = new YDoc();
        foreach ($updates as $update) {
            $target->applyUpdateV1($update);
        }

        self::assertSame($source->toJSON(), $target->toJSON());
        self::assertSame($source->encodeStateVector(), $target->encodeStateVector());
    }

    public function testCanUnobserveTransactionObserver(): void
    {
        $doc = new YDoc(145);
        $events = [];
        $observerId = $doc->observeTransaction(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $doc->getArray('array')->insert(0, ['A']);
        $doc->unobserveTransaction($observerId);
        $doc->getArray('array')->insert(1, ['B']);

        self::assertCount(1, $events);
        self::assertSame(['array' => ['A']], $events[0]['after']);
    }

    public function testDocumentObserverRemovalsDuringDispatchSkipRemovedObserver(): void
    {
        $doc = new YDoc(188);
        $updates = [];
        $transactions = [];
        $mapEvents = [];

        $updateObserverToRemove = null;
        $doc->observeUpdate(static function () use (&$updates, &$updateObserverToRemove, $doc): void {
            $updates[] = 'first';
            if ($updateObserverToRemove !== null) {
                $doc->unobserveUpdate($updateObserverToRemove);
            }
        });
        $updateObserverToRemove = $doc->observeUpdate(static function () use (&$updates): void {
            $updates[] = 'removed';
        });

        $transactionObserverToRemove = null;
        $doc->observeTransaction(static function () use (&$transactions, &$transactionObserverToRemove, $doc): void {
            $transactions[] = 'first';
            if ($transactionObserverToRemove !== null) {
                $doc->unobserveTransaction($transactionObserverToRemove);
            }
        });
        $transactionObserverToRemove = $doc->observeTransaction(static function () use (&$transactions): void {
            $transactions[] = 'removed';
        });

        $map = $doc->getMap('map');
        $mapObserverToRemove = null;
        $map->observe(static function () use (&$mapEvents, &$mapObserverToRemove, $doc): void {
            $mapEvents[] = 'first';
            if ($mapObserverToRemove !== null) {
                $doc->unobserve($mapObserverToRemove);
            }
        });
        $mapObserverToRemove = $map->observe(static function () use (&$mapEvents): void {
            $mapEvents[] = 'removed';
        });

        $map->set('title', 'Hello');

        self::assertSame(['first'], $updates);
        self::assertSame(['first'], $transactions);
        self::assertSame(['first'], $mapEvents);
    }

    public function testDocumentOneShotObserversFireOnce(): void
    {
        $doc = new YDoc(170);
        $updates = [];
        $v2Updates = [];
        $transactions = [];
        $deepEvents = [];

        $doc->observeUpdateOnce(static function (string $update, YDoc $doc, mixed $origin) use (&$updates): void {
            $updates[] = ['update' => $update, 'origin' => $origin];
        });
        $doc->observeUpdateV2Once(static function (string $update, YDoc $doc, mixed $origin) use (&$v2Updates): void {
            $v2Updates[] = ['update' => $update, 'origin' => $origin];
        });
        $doc->observeTransactionOnce(static function (array $event, YDoc $doc) use (&$transactions): void {
            $transactions[] = $event;
        });
        $doc->observeDeepOnce(static function (array $events, YDoc $doc, array $transaction) use (&$deepEvents): void {
            $deepEvents[] = ['events' => $events, 'origin' => $transaction['origin']];
        });

        $doc->transact(static function (YDoc $doc): void {
            $doc->getMap('map')->set('title', 'First');
        }, 'first-origin');
        $doc->transact(static function (YDoc $doc): void {
            $doc->getMap('map')->set('subtitle', 'Second');
        }, 'second-origin');

        self::assertCount(1, $updates);
        self::assertIsString($updates[0]['update']);
        self::assertSame('first-origin', $updates[0]['origin']);
        self::assertCount(1, $v2Updates);
        self::assertIsString($v2Updates[0]['update']);
        self::assertSame('first-origin', $v2Updates[0]['origin']);
        self::assertCount(1, $transactions);
        self::assertSame('first-origin', $transactions[0]['origin']);
        self::assertCount(1, $deepEvents);
        self::assertSame('first-origin', $deepEvents[0]['origin']);
        self::assertSame('map', $deepEvents[0]['events'][0]['name']);
    }

    public function testSharedTypeOneShotObserversFireOnce(): void
    {
        $doc = new YDoc(171);
        $text = $doc->getText('content');
        $textEvents = [];
        $text->observeOnce(static function (array $event) use (&$textEvents): void {
            $textEvents[] = $event;
        });

        $text->insert(0, 'A');
        $text->insert(1, 'B');

        self::assertCount(1, $textEvents);
        self::assertSame('A', $textEvents[0]['newValue']);

        $array = $doc->getArray('array');
        $nestedMap = $array->appendMap();
        $arrayDeepEvents = [];
        $array->observeDeepOnce(static function (array $events, YArray $array, array $transaction) use (&$arrayDeepEvents): void {
            $arrayDeepEvents[] = $events;
        });

        $nestedMap->set('first', true);
        $nestedMap->set('second', true);

        self::assertCount(1, $arrayDeepEvents);
        self::assertSame(['first' => true], $arrayDeepEvents[0][0]['newValue']);

        $nestedEvents = [];
        $nestedMap->observeOnce(static function (array $event) use (&$nestedEvents): void {
            $nestedEvents[] = $event;
        });

        $nestedMap->set('third', true);
        $nestedMap->set('fourth', true);

        self::assertCount(1, $nestedEvents);
        self::assertSame(['first' => true, 'second' => true, 'third' => true], $nestedEvents[0]['newValue']);
    }

    public function testXmlOneShotObserversFireOnce(): void
    {
        $doc = new YDoc(172);
        $xml = $doc->getXmlFragment('xml');
        $paragraph = $xml->appendElement('p');
        $xmlText = $paragraph->appendText('Seed');
        $hook = $xml->appendHook('mention');
        $rootEvents = [];
        $fragmentEvents = [];
        $elementEvents = [];
        $elementDeepEvents = [];
        $xmlTextEvents = [];
        $xmlTextDeepEvents = [];
        $hookEvents = [];
        $hookDeepEvents = [];

        $xml->observeOnce(static function (array $event) use (&$rootEvents): void {
            $rootEvents[] = $event;
        });
        $xml->observeDeepOnce(static function (array $events, YXmlFragment $fragment, array $transaction) use (&$fragmentEvents): void {
            $fragmentEvents[] = $events;
        });
        $paragraph->observeOnce(static function (array $event) use (&$elementEvents): void {
            $elementEvents[] = $event;
        });
        $paragraph->observeDeepOnce(static function (array $events, YXmlElement $element, array $transaction) use (&$elementDeepEvents): void {
            $elementDeepEvents[] = $events;
        });
        $xmlText->observeOnce(static function (array $event) use (&$xmlTextEvents): void {
            $xmlTextEvents[] = $event;
        });
        $xmlText->observeDeepOnce(static function (array $events, YXmlText $text, array $transaction) use (&$xmlTextDeepEvents): void {
            $xmlTextDeepEvents[] = $events;
        });
        $hook->observeOnce(static function (array $event) use (&$hookEvents): void {
            $hookEvents[] = $event;
        });
        $hook->observeDeepOnce(static function (array $events, YXmlHook $hook, array $transaction) use (&$hookDeepEvents): void {
            $hookDeepEvents[] = $events;
        });

        $xml->appendText('Root');
        $xml->appendText('Ignored');
        $paragraph->setAttribute('class', 'lead');
        $paragraph->setAttribute('data-next', 'ignored');
        $paragraph->appendText('A');
        $paragraph->appendText('B');
        $xmlText->insert(4, '!');
        $xmlText->insert(5, '?');
        $hook->set('label', 'Ada');
        $hook->set('ignored', true);

        self::assertCount(1, $rootEvents);
        self::assertSame([], $rootEvents[0]['path']);
        self::assertCount(1, $fragmentEvents);
        self::assertCount(1, $elementEvents);
        self::assertSame(['class' => ['action' => 'add']], $elementEvents[0]['changes']['keys']);
        self::assertCount(1, $elementDeepEvents);
        self::assertSame(['class' => ['action' => 'add']], $elementDeepEvents[0][0]['changes']['keys']);
        self::assertCount(1, $xmlTextEvents);
        self::assertSame([['retain' => 4], ['insert' => '!']], $xmlTextEvents[0]['changes']['delta']);
        self::assertCount(1, $xmlTextDeepEvents);
        self::assertSame([['retain' => 4], ['insert' => '!']], $xmlTextDeepEvents[0][0]['changes']['delta']);
        self::assertCount(1, $hookEvents);
        self::assertSame(['label' => ['action' => 'add']], $hookEvents[0]['changes']['keys']);
        self::assertCount(1, $hookDeepEvents);
        self::assertSame(['label' => ['action' => 'add']], $hookDeepEvents[0][0]['changes']['keys']);
    }

    public function testRemoteApplyOriginIsPassedToObservers(): void
    {
        $source = new YDoc(128);
        $source->getMap('map')->set('title', 'Hello');
        $update = $source->encodeStateAsUpdateV1();

        $target = new YDoc();
        $origins = [];
        $events = [];
        $target->observeUpdate(static function (string $update, YDoc $doc, mixed $origin) use (&$origins): void {
            $origins[] = $origin;
        });
        $target->getMap('map')->observe(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $target->applyUpdateV1($update, 'remote-origin');

        self::assertSame(['remote-origin'], $origins);
        self::assertCount(1, $events);
        self::assertSame('remote-origin', $events[0]['origin']);
    }

    public function testRemoteV2ApplyOriginIsPassedToObservers(): void
    {
        $source = new YDoc(129);
        $source->getText('content')->insert(0, 'Hello');
        $update = $source->encodeStateAsUpdateV2();

        $target = new YDoc();
        $origins = [];
        $target->observeUpdateV2(static function (string $update, YDoc $doc, mixed $origin) use (&$origins): void {
            $origins[] = $origin;
        });

        $target->applyUpdateV2($update, 'remote-v2-origin');

        self::assertSame(['remote-v2-origin'], $origins);
    }

    public function testSharedTypeObserverReceivesNativeMutationEvent(): void
    {
        $doc = new YDoc(115);
        $events = [];
        $doc->getText('content')->observe(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $doc->getText('content')->insert(0, 'Hello');

        self::assertCount(1, $events);
        self::assertSame('content', $events[0]['name']);
        self::assertFalse($events[0]['oldExists']);
        self::assertTrue($events[0]['newExists']);
        self::assertNull($events[0]['oldValue']);
        self::assertSame('Hello', $events[0]['newValue']);
        self::assertIsString($events[0]['update']);
        self::assertIsString($events[0]['updateV2']);
    }

    public function testSharedTypeObserverReceivesRemoteUpdateEvent(): void
    {
        $source = new YDoc(116);
        $source->getArray('array')->insert(0, ['A', 'B']);
        $update = $source->encodeStateAsUpdateV1();

        $target = new YDoc();
        $events = [];
        $target->getArray('array')->observe(static function (array $event) use (&$events): void {
            $events[] = $event;
        });
        $target->applyUpdateV1($update);

        self::assertCount(1, $events);
        self::assertSame(['A', 'B'], $events[0]['newValue']);
        self::assertSame($update, $events[0]['update']);
        self::assertIsString($events[0]['updateV2']);
    }

    public function testSharedTypeObserverReceivesFormattingEventWhenJsonValueIsUnchanged(): void
    {
        $doc = new YDoc(121);
        $doc->getText('content')->insert(0, 'Hello');
        $events = [];
        $doc->getText('content')->observe(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $doc->getText('content')->format(1, 3, ['bold' => true]);

        self::assertCount(1, $events);
        self::assertSame('Hello', $events[0]['oldValue']);
        self::assertSame('Hello', $events[0]['newValue']);
        self::assertIsString($events[0]['update']);
        self::assertIsString($events[0]['updateV2']);
        self::assertSame([
            ['retain' => 1],
            ['retain' => 3, 'attributes' => ['bold' => true]],
        ], $events[0]['changes']['delta']);
    }

    public function testSharedTypeObserverReceivesRemoteFormattingDelta(): void
    {
        $source = new YDoc(161);
        $sourceText = $source->getText('content');
        $sourceText->insert(0, 'Hello');
        $target = new YDoc();
        $target->applyUpdateV1($source->encodeStateAsUpdateV1());
        $updates = [];
        $source->observeUpdate(static function (string $update) use (&$updates): void {
            $updates[] = $update;
        });
        $events = [];
        $target->getText('content')->observe(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $sourceText->format(1, 3, ['bold' => true]);
        self::assertCount(1, $updates);
        $target->applyUpdateV1($updates[0], 'remote-format');

        self::assertCount(1, $events);
        self::assertSame('remote-format', $events[0]['origin']);
        self::assertSame([
            ['retain' => 1],
            ['retain' => 3, 'attributes' => ['bold' => true]],
        ], $events[0]['changes']['delta']);
        self::assertSame($source->toJSON(), $target->toJSON());
        self::assertSame($source->encodeStateVector(), $target->encodeStateVector());
    }

    public function testSharedTypeObserverReceivesSameValueMapReplacementEvent(): void
    {
        $doc = new YDoc(122);
        $doc->getMap('map')->set('title', 'Hello');
        $events = [];
        $doc->getMap('map')->observe(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $doc->getMap('map')->set('title', 'Hello');

        self::assertCount(1, $events);
        self::assertSame(['title' => 'Hello'], $events[0]['oldValue']);
        self::assertSame(['title' => 'Hello'], $events[0]['newValue']);
    }

    public function testCanUnobserveSharedTypeObserver(): void
    {
        $doc = new YDoc(117);
        $events = [];
        $observerId = $doc->getMap('map')->observe(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $doc->getMap('map')->set('title', 'Hello');
        $doc->unobserve($observerId);
        $doc->getMap('map')->set('subtitle', 'Hidden');

        self::assertCount(1, $events);
    }

    public function testApplyingRemoteUpdateNotifiesObserver(): void
    {
        $source = new YDoc(105);
        $source->getMap('map')->set('title', 'Hello');
        $update = $source->encodeStateAsUpdateV1();

        $target = new YDoc();
        $observed = [];
        $target->observeUpdate(static function (string $update) use (&$observed): void {
            $observed[] = $update;
        });
        $target->applyUpdateV1($update);

        self::assertSame([$update], $observed);
        self::assertSame($source->toJSON(), $target->toJSON());
    }

    public function testApplyingDuplicateRemoteUpdateDoesNotNotifyObservers(): void
    {
        $source = new YDoc(124);
        $source->getText('content')->insert(0, 'Hello');
        $update = $source->encodeStateAsUpdateV1();

        $target = new YDoc();
        $updates = [];
        $events = [];
        $target->observeUpdate(static function (string $update) use (&$updates): void {
            $updates[] = $update;
        });
        $target->getText('content')->observe(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $target->applyUpdateV1($update);
        $target->applyUpdateV1($update);

        self::assertCount(1, $updates);
        self::assertCount(1, $events);
        self::assertSame($source->toJSON(), $target->toJSON());
    }

    public function testApplyingOverlappingRemoteDiffAfterFullUpdateDoesNotDuplicateContent(): void
    {
        $full = DecodedUpdate::encodeV1([
            [
                'type' => 'Item',
                'id' => ['client' => 126, 'clock' => 0],
                'length' => 4,
                'origin' => null,
                'rightOrigin' => null,
                'parent' => 'content',
                'parentSub' => null,
                'content' => ['type' => 'ContentString', 'value' => 'ABCD'],
            ],
        ]);
        $diff = DecodedUpdate::encodeV1([
            [
                'type' => 'Item',
                'id' => ['client' => 126, 'clock' => 2],
                'length' => 2,
                'origin' => ['client' => 126, 'clock' => 1],
                'rightOrigin' => null,
                'parent' => null,
                'parentSub' => null,
                'content' => ['type' => 'ContentString', 'value' => 'CD'],
            ],
        ]);
        $target = new YDoc();
        $updates = [];
        $events = [];
        $target->observeUpdate(static function (string $update) use (&$updates): void {
            $updates[] = $update;
        });
        $target->getText('content')->observe(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $target->applyUpdateV1($full);
        $target->applyUpdateV1($diff);

        self::assertSame(['content' => 'ABCD'], $target->toJSON());
        self::assertCount(1, $updates);
        self::assertCount(1, $events);
    }

    public function testApplyingFullRemoteUpdateAfterOverlappingDiffStoresOnlyMissingContent(): void
    {
        $diff = DecodedUpdate::encodeV1([
            [
                'type' => 'Item',
                'id' => ['client' => 127, 'clock' => 2],
                'length' => 2,
                'origin' => ['client' => 127, 'clock' => 1],
                'rightOrigin' => null,
                'parent' => null,
                'parentSub' => null,
                'content' => ['type' => 'ContentString', 'value' => 'CD'],
            ],
        ]);
        $full = DecodedUpdate::encodeV1([
            [
                'type' => 'Item',
                'id' => ['client' => 127, 'clock' => 0],
                'length' => 4,
                'origin' => null,
                'rightOrigin' => null,
                'parent' => 'content',
                'parentSub' => null,
                'content' => ['type' => 'ContentString', 'value' => 'ABCD'],
            ],
        ]);
        $target = new YDoc();

        $target->applyUpdateV1($diff);
        $target->applyUpdateV1($full);

        self::assertSame(['content' => 'ABCD'], $target->toJSON());
        self::assertSame([127 => 4], $target->getStateVector());
    }

    public function testPendingRemoteDiffDoesNotAdvanceStateVectorPastGap(): void
    {
        $diff = DecodedUpdate::encodeV1([
            [
                'type' => 'Item',
                'id' => ['client' => 128, 'clock' => 2],
                'length' => 2,
                'origin' => ['client' => 128, 'clock' => 1],
                'rightOrigin' => null,
                'parent' => null,
                'parentSub' => null,
                'content' => ['type' => 'ContentString', 'value' => 'CD'],
            ],
        ]);
        $target = new YDoc();

        $target->applyUpdateV1($diff);

        self::assertSame([], $target->toJSON());
        self::assertSame([], $target->getStateVector());
        self::assertSame("\x00", $target->encodeStateVector());
    }

    public function testPendingRemoteDiffNotifiesUpdateObserversBeforeIntegrated(): void
    {
        $diff = DecodedUpdate::encodeV1([
            [
                'type' => 'Item',
                'id' => ['client' => 130, 'clock' => 2],
                'length' => 2,
                'origin' => ['client' => 130, 'clock' => 1],
                'rightOrigin' => null,
                'parent' => null,
                'parentSub' => null,
                'content' => ['type' => 'ContentString', 'value' => 'CD'],
            ],
        ]);
        $target = new YDoc();
        $updates = [];
        $events = [];
        $target->observeUpdate(static function (string $update) use (&$updates): void {
            $updates[] = $update;
        });
        $target->getText('content')->observe(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $target->applyUpdateV1($diff);

        self::assertSame([$diff], $updates);
        self::assertSame([], $events);
    }

    public function testPendingRemoteV2DiffNotifiesUpdateObserversBeforeIntegrated(): void
    {
        $diff = DecodedUpdate::encodeV2([
            [
                'type' => 'Item',
                'id' => ['client' => 132, 'clock' => 2],
                'length' => 2,
                'origin' => ['client' => 132, 'clock' => 1],
                'rightOrigin' => null,
                'parent' => null,
                'parentSub' => null,
                'content' => ['type' => 'ContentString', 'value' => 'CD'],
            ],
        ]);
        $target = new YDoc();
        $updates = [];
        $events = [];
        $target->observeUpdateV2(static function (string $update) use (&$updates): void {
            $updates[] = $update;
        });
        $target->getText('content')->observe(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $target->applyUpdateV2($diff);

        self::assertSame([$diff], $updates);
        self::assertSame([], $events);
    }

    public function testPendingRemoteDiffAdvancesStateVectorAfterMissingPrefixArrives(): void
    {
        $diff = DecodedUpdate::encodeV1([
            [
                'type' => 'Item',
                'id' => ['client' => 129, 'clock' => 2],
                'length' => 2,
                'origin' => ['client' => 129, 'clock' => 1],
                'rightOrigin' => null,
                'parent' => null,
                'parentSub' => null,
                'content' => ['type' => 'ContentString', 'value' => 'CD'],
            ],
        ]);
        $prefix = DecodedUpdate::encodeV1([
            [
                'type' => 'Item',
                'id' => ['client' => 129, 'clock' => 0],
                'length' => 2,
                'origin' => null,
                'rightOrigin' => null,
                'parent' => 'content',
                'parentSub' => null,
                'content' => ['type' => 'ContentString', 'value' => 'AB'],
            ],
        ]);
        $target = new YDoc();

        $target->applyUpdateV1($diff);
        $target->applyUpdateV1($prefix);

        self::assertSame(['content' => 'ABCD'], $target->toJSON());
        self::assertSame([129 => 4], $target->getStateVector());
    }

    public function testApplyingRemoteV2UpdateNotifiesV2Observer(): void
    {
        $source = new YDoc(112);
        $source->getText('content')->insert(0, 'Hello');
        $update = $source->encodeStateAsUpdateV2();

        $target = new YDoc();
        $observed = [];
        $target->observeUpdateV2(static function (string $update) use (&$observed): void {
            $observed[] = $update;
        });
        $target->applyUpdateV2($update);

        self::assertSame([$update], $observed);
        self::assertSame($source->toJSON(), $target->toJSON());
    }

    public function testApplyingDuplicateRemoteV2UpdateDoesNotNotifyObservers(): void
    {
        $source = new YDoc(125);
        $source->getArray('array')->insert(0, ['A', 'B']);
        $source->getArray('array')->delete(0, 1);
        $update = $source->encodeStateAsUpdateV2();

        $target = new YDoc();
        $updates = [];
        $events = [];
        $target->observeUpdateV2(static function (string $update) use (&$updates): void {
            $updates[] = $update;
        });
        $target->getArray('array')->observe(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $target->applyUpdateV2($update);
        $target->applyUpdateV2($update);

        self::assertCount(1, $updates);
        self::assertCount(1, $events);
        self::assertSame($source->toJSON(), $target->toJSON());
    }

    public function testObserveDeepReceivesBatchedRootNestedAndXmlEvents(): void
    {
        $doc = new YDoc(300);
        $array = $doc->getArray('items');
        $nestedText = $array->insertText(0);
        $xmlText = $doc->getXmlFragment('xml')->insertText(0, 'Seed');
        $events = [];
        $transactions = [];

        $observerId = $doc->observeDeep(static function (array $deepEvents, YDoc $doc, array $transaction) use (&$events, &$transactions): void {
            $events[] = $deepEvents;
            $transactions[] = $transaction;
        });

        $doc->transact(static function () use ($doc, $array, $nestedText, $xmlText): void {
            $doc->getText('content')->insert(0, 'Hi');
            $array->push(['value']);
            $nestedText->insert(0, 'Nested');
            $xmlText->insert(4, '!');
        }, 'deep-local');

        self::assertCount(1, $events);
        self::assertCount(1, $transactions);
        self::assertSame('deep-local', $transactions[0]['origin']);
        self::assertTrue($transactions[0]['local']);
        self::assertSame(['content', 'items', 'xml'], $transactions[0]['changed']);
        self::assertSame([$nestedText->idKey()], $transactions[0]['changedNestedTypes']);
        self::assertSame([$xmlText->idKey()], $transactions[0]['changedXmlNodes']);

        $eventKeys = array_map(static fn (array $event): string => $event['target'] . ':' . ($event['name'] ?? $event['idKey']), $events[0]);
        sort($eventKeys);
        self::assertSame([
            'nested:' . $nestedText->idKey(),
            'root:content',
            'root:items',
            'xml:' . $xmlText->idKey(),
        ], $eventKeys);

        $rootTextEvent = $this->findDeepEvent($events[0], 'root', 'content');
        self::assertSame('text', $rootTextEvent['type']);
        self::assertSame([['insert' => 'Hi']], $rootTextEvent['changes']['delta']);
        self::assertSame('deep-local', $rootTextEvent['origin']);

        $nestedTextEvent = $this->findDeepEvent($events[0], 'nested', $nestedText->idKey());
        self::assertSame('text', $nestedTextEvent['type']);
        self::assertSame('Nested', $nestedTextEvent['newValue']);

        $xmlTextEvent = $this->findDeepEvent($events[0], 'xml', $xmlText->idKey());
        self::assertSame('YXmlText', $xmlTextEvent['typeName']);
        self::assertSame([['retain' => 4], ['insert' => '!']], $xmlTextEvent['changes']['delta']);

        $doc->unobserveDeep($observerId);
        $doc->getText('content')->insert(2, '!');

        self::assertCount(1, $events);
    }

    public function testObserveDeepReportsXmlNodeChangeDetailsWithoutDirectXmlObservers(): void
    {
        $doc = new YDoc(302);
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $text = $paragraph->insertText(0, 'Hi');
        $events = [];

        $doc->observeDeep(static function (array $deepEvents) use (&$events): void {
            $events[] = $deepEvents;
        });

        $doc->transact(static function () use ($paragraph, $text): void {
            $paragraph->setAttribute('class', 'lead');
            $text->insert(2, '!');
        }, 'deep-xml-local');

        self::assertCount(1, $events);

        $paragraphEvent = $this->findDeepEvent($events[0], 'xml', $paragraph->idKey());
        self::assertSame('YXmlElement', $paragraphEvent['typeName']);
        self::assertSame(['class' => ['action' => 'add']], $paragraphEvent['changes']['keys']);
        self::assertSame(['class'], $paragraphEvent['changes']['attributesChanged']);

        $textEvent = $this->findDeepEvent($events[0], 'xml', $text->idKey());
        self::assertSame('YXmlText', $textEvent['typeName']);
        self::assertSame([
            ['retain' => 2],
            ['insert' => '!'],
        ], $textEvent['changes']['delta']);
        self::assertSame('deep-xml-local', $textEvent['origin']);
    }

    public function testCollectionDeepObserversReceiveXmlChildEvents(): void
    {
        $doc = new YDoc(304);
        $array = $doc->getArray('array');
        $paragraph = $array->insertXmlElement(0, 'p');
        $arrayEvents = [];
        $arrayTransactions = [];

        $array->observeDeep(static function (array $events, YArray $array, array $transaction) use (&$arrayEvents, &$arrayTransactions): void {
            $arrayEvents[] = $events;
            $arrayTransactions[] = $transaction;
        });

        $text = $paragraph->insertText(0, 'Hi');
        $text->insert(2, '!');

        self::assertCount(2, $arrayEvents);
        self::assertSame([$text->idKey()], $arrayTransactions[0]['changedXmlNodes']);
        self::assertSame(['YArray', 'YXmlElement', 'YXmlText'], $arrayTransactions[0]['changedParentTypeNames']);
        self::assertSame('xml', $arrayEvents[0][0]['target']);
        self::assertSame('YXmlText', $arrayEvents[0][0]['typeName']);
        self::assertSame([0, 0], $arrayEvents[0][0]['path']);
        self::assertSame([['insert' => 'Hi']], $arrayEvents[0][0]['changes']['delta']);
        self::assertSame([['retain' => 2], ['insert' => '!']], $arrayEvents[1][0]['changes']['delta']);

        $rootMap = $doc->getMap('map');
        $nestedMap = $rootMap->setMap('nested');
        $section = $nestedMap->setXmlElement('xml', 'section');
        $nestedMapEvents = [];

        $nestedMap->observeDeep(static function (array $events) use (&$nestedMapEvents): void {
            $nestedMapEvents[] = $events;
        });

        $section->setAttribute('class', 'lead');

        self::assertCount(1, $nestedMapEvents);
        self::assertSame('xml', $nestedMapEvents[0][0]['target']);
        self::assertSame('YXmlElement', $nestedMapEvents[0][0]['typeName']);
        self::assertSame(['xml'], $nestedMapEvents[0][0]['path']);
        self::assertSame(['class' => ['action' => 'add']], $nestedMapEvents[0][0]['changes']['keys']);
    }

    public function testObserveDeepReceivesRemoteUpdateEvents(): void
    {
        $source = new YDoc(301);
        $source->getText('content')->insert(0, 'Remote');
        $update = $source->encodeStateAsUpdateV2();
        $target = new YDoc();
        $events = [];
        $transactions = [];

        $target->observeDeep(static function (array $deepEvents, YDoc $doc, array $transaction) use (&$events, &$transactions): void {
            $events[] = $deepEvents;
            $transactions[] = $transaction;
        });

        $target->applyUpdateV2($update, 'deep-remote');

        self::assertCount(1, $events);
        self::assertCount(1, $transactions);
        self::assertSame('deep-remote', $transactions[0]['origin']);
        self::assertFalse($transactions[0]['local']);
        self::assertSame($update, $transactions[0]['updateV2']);

        $event = $this->findDeepEvent($events[0], 'root', 'content');
        self::assertSame('text', $event['type']);
        self::assertSame('Remote', $event['newValue']);
        self::assertSame([['insert' => 'Remote']], $event['changes']['delta']);
    }

    public function testObserveDeepReportsRemoteXmlNodeChangeDetails(): void
    {
        $source = new YDoc(303);
        $paragraph = $source->getXmlFragment('xml')->insertElement(0, 'p');
        $text = $paragraph->insertText(0, 'Hi');
        $target = new YDoc();
        $target->applyUpdateV2($source->encodeStateAsUpdateV2());
        $events = [];

        $target->observeDeep(static function (array $deepEvents) use (&$events): void {
            $events[] = $deepEvents;
        });

        $paragraph->setAttribute('class', 'remote');
        $text->insert(2, '!');
        $target->applyUpdateV2($source->encodeStateAsUpdateV2($target->encodeStateVector()), 'deep-xml-remote');

        self::assertCount(1, $events);

        $paragraphEvent = $this->findDeepEvent($events[0], 'xml', $paragraph->idKey());
        self::assertSame(['class' => ['action' => 'add']], $paragraphEvent['changes']['keys']);
        self::assertSame(['class'], $paragraphEvent['changes']['attributesChanged']);

        $textEvent = $this->findDeepEvent($events[0], 'xml', $text->idKey());
        self::assertSame([
            ['retain' => 2],
            ['insert' => '!'],
        ], $textEvent['changes']['delta']);
        self::assertSame('deep-xml-remote', $textEvent['origin']);
    }

    public function testNativeXmlElementSharedTypeMutationApisCanBeEncodedAndApplied(): void
    {
        $source = new YDoc(308);
        $paragraph = $source->getXmlFragment('xml')->insertElement(0, 'p');
        $paragraph->setAttribute('body', 'draft');
        $body = $paragraph->setText('body');
        $body->insert(0, 'Element text');
        $meta = $paragraph->setMap('meta');
        $meta->set('role', 'lead');
        $items = $paragraph->setArray('items');
        $items->insert(0, ['A', 'B']);

        self::assertSame([
            'body' => 'Element text',
            'items' => ['A', 'B'],
            'meta' => ['role' => 'lead'],
        ], $paragraph->getAttributes());
        self::assertSame([['insert' => 'Element text']], $paragraph->getText('body')?->toDelta());
        self::assertSame(['role' => 'lead'], $paragraph->getMap('meta')?->toJSON());
        self::assertSame(['A', 'B'], $paragraph->getArray('items')?->toJSON());

        $target = new YDoc();
        $target->applyUpdateV2($source->encodeStateAsUpdateV2());
        $targetParagraph = $target->getXmlFragment('xml')->get(0);

        self::assertInstanceOf(YXmlElement::class, $targetParagraph);
        self::assertSame($paragraph->getAttributes(), $targetParagraph->getAttributes());
        self::assertSame([['insert' => 'Element text']], $targetParagraph->getText('body')?->toDelta());
        self::assertSame(['role' => 'lead'], $targetParagraph->getMap('meta')?->toJSON());
        self::assertSame(['A', 'B'], $targetParagraph->getArray('items')?->toJSON());
    }

    public function testNativeXmlElementXmlSharedTypeMutationApisCanBeEncodedAndApplied(): void
    {
        $source = new YDoc(309);
        $paragraph = $source->getXmlFragment('xml')->insertElement(0, 'p');
        $element = $paragraph->setXmlElement('element', 'span');
        $element->appendText('Element');
        $xmlText = $paragraph->setXmlText('text', 'Xml text');
        $hook = $paragraph->setXmlHook('hook', 'note');
        $hook->set('ok', true);
        $fragment = $paragraph->setXmlFragment('fragment');
        $fragment->appendText('Frag');

        self::assertSame([
            'element' => '<span>Element</span>',
            'fragment' => 'Frag',
            'hook' => ['ok' => true],
            'text' => 'Xml text',
        ], $paragraph->getAttributes());
        self::assertSame('<span>Element</span>', (string) $paragraph->getXmlElement('element'));
        self::assertSame('Xml text', (string) $xmlText);
        self::assertSame('Xml text', (string) $paragraph->getXmlText('text'));
        self::assertSame(['ok' => true], $paragraph->getXmlHook('hook')?->toJSON());
        self::assertSame('Frag', (string) $paragraph->getXmlFragment('fragment'));

        $target = new YDoc();
        $target->applyUpdateV2($source->encodeStateAsUpdateV2());
        $targetParagraph = $target->getXmlFragment('xml')->get(0);

        self::assertInstanceOf(YXmlElement::class, $targetParagraph);
        self::assertSame($paragraph->getAttributes(), $targetParagraph->getAttributes());
        self::assertSame('<span>Element</span>', (string) $targetParagraph->getXmlElement('element'));
        self::assertSame('Xml text', (string) $targetParagraph->getXmlText('text'));
        self::assertSame(['ok' => true], $targetParagraph->getXmlHook('hook')?->toJSON());
        self::assertSame('Frag', (string) $targetParagraph->getXmlFragment('fragment'));
    }

    public function testNativeXmlElementBulkSharedTypeMutationApisCanBeEncodedAndApplied(): void
    {
        $source = new YDoc(310);
        $paragraph = $source->getXmlFragment('xml')->insertElement(0, 'p');
        $arrays = $paragraph->setArrays(['items']);
        $maps = $paragraph->setMaps(['meta']);
        $texts = $paragraph->setTexts(['body']);
        $elements = $paragraph->setXmlElements(['element' => 'span']);
        $xmlTexts = $paragraph->setXmlTexts(['text' => 'Xml text']);
        $hooks = $paragraph->setXmlHooks(['hook' => 'note']);
        $fragments = $paragraph->setXmlFragments(['fragment']);

        $arrays['items']->insert(0, ['A', 'B']);
        $maps['meta']->set('role', 'lead');
        $texts['body']->insert(0, 'Element text');
        $elements['element']->appendText('Element');
        $hooks['hook']->set('ok', true);
        $fragments['fragment']->appendText('Frag');

        self::assertSame([
            'body' => 'Element text',
            'element' => '<span>Element</span>',
            'fragment' => 'Frag',
            'hook' => ['ok' => true],
            'items' => ['A', 'B'],
            'meta' => ['role' => 'lead'],
            'text' => 'Xml text',
        ], $paragraph->getAttributes());
        self::assertSame(['A', 'B'], $paragraph->getArray('items')?->toJSON());
        self::assertSame(['role' => 'lead'], $paragraph->getMap('meta')?->toJSON());
        self::assertSame([['insert' => 'Element text']], $paragraph->getText('body')?->toDelta());
        self::assertSame('<span>Element</span>', (string) $paragraph->getXmlElement('element'));
        self::assertSame('Xml text', (string) $xmlTexts['text']);
        self::assertSame('Xml text', (string) $paragraph->getXmlText('text'));
        self::assertSame(['ok' => true], $paragraph->getXmlHook('hook')?->toJSON());
        self::assertSame('Frag', (string) $paragraph->getXmlFragment('fragment'));

        $target = new YDoc();
        $target->applyUpdateV2($source->encodeStateAsUpdateV2());
        $targetParagraph = $target->getXmlFragment('xml')->get(0);

        self::assertInstanceOf(YXmlElement::class, $targetParagraph);
        self::assertSame($paragraph->getAttributes(), $targetParagraph->getAttributes());
        self::assertSame(['A', 'B'], $targetParagraph->getArray('items')?->toJSON());
        self::assertSame(['role' => 'lead'], $targetParagraph->getMap('meta')?->toJSON());
        self::assertSame([['insert' => 'Element text']], $targetParagraph->getText('body')?->toDelta());
        self::assertSame('<span>Element</span>', (string) $targetParagraph->getXmlElement('element'));
        self::assertSame('Xml text', (string) $targetParagraph->getXmlText('text'));
        self::assertSame(['ok' => true], $targetParagraph->getXmlHook('hook')?->toJSON());
        self::assertSame('Frag', (string) $targetParagraph->getXmlFragment('fragment'));
    }

    public function testNativeXmlElementBulkSharedTypeSettersRejectInvalidValuesBeforeMutating(): void
    {
        $doc = new YDoc(311);
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $paragraph->setText('existing')->insert(0, 'A');

        try {
            $paragraph->setArrays(['valid', false]);
            self::fail('Expected invalid XML element bulk array setter to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('YXmlElement setArrays only supports string keys.', $exception->getMessage());
        }

        try {
            $paragraph->setXmlHooks(['validHook' => 'note', 'invalidHook' => null]);
            self::fail('Expected invalid XML element bulk XML hook setter to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('YXmlElement setXmlHooks only supports string hook names.', $exception->getMessage());
        }

        self::assertSame(['existing' => 'A'], $paragraph->getAttributes());
    }

    public function testNativeXmlHookSharedTypeMutationApisCanBeEncodedAndApplied(): void
    {
        $source = new YDoc(304);
        $hook = $source->getXmlFragment('xml')->insertHook(0, 'mention');
        $hook->set('body', 'draft');
        $body = $hook->setText('body');
        $body->insert(0, 'Hook text');
        $meta = $hook->setMap('meta');
        $meta->set('role', 'author');
        $items = $hook->setArray('items');
        $items->insert(0, ['A', 'B']);

        self::assertSame([
            'body' => 'Hook text',
            'items' => ['A', 'B'],
            'meta' => ['role' => 'author'],
        ], $hook->toJSON());
        self::assertSame([['insert' => 'Hook text']], $hook->getText('body')?->toDelta());
        self::assertSame(['role' => 'author'], $hook->getMap('meta')?->toJSON());
        self::assertSame(['A', 'B'], $hook->getArray('items')?->toJSON());

        $target = new YDoc();
        $target->applyUpdateV2($source->encodeStateAsUpdateV2());
        $targetHook = $target->getXmlFragment('xml')->get(0);

        self::assertInstanceOf(YXmlHook::class, $targetHook);
        self::assertSame($hook->toJSON(), $targetHook->toJSON());
        self::assertSame([['insert' => 'Hook text']], $targetHook->getText('body')?->toDelta());
        self::assertSame(['role' => 'author'], $targetHook->getMap('meta')?->toJSON());
        self::assertSame(['A', 'B'], $targetHook->getArray('items')?->toJSON());
    }

    public function testNativeXmlHookXmlSharedTypeMutationApisCanBeEncodedAndApplied(): void
    {
        $source = new YDoc(305);
        $hook = $source->getXmlFragment('xml')->insertHook(0, 'mention');
        $element = $hook->setXmlElement('element', 'p');
        $element->appendText('Element');
        $xmlText = $hook->setXmlText('text', 'Xml text');
        $nestedHook = $hook->setXmlHook('hook', 'note');
        $nestedHook->set('ok', true);
        $fragment = $hook->setXmlFragment('fragment');
        $fragment->appendText('Frag');

        self::assertSame([
            'element' => '<p>Element</p>',
            'fragment' => 'Frag',
            'hook' => ['ok' => true],
            'text' => 'Xml text',
        ], $hook->toJSON());
        self::assertSame('<p>Element</p>', (string) $hook->getXmlElement('element'));
        self::assertSame('Xml text', (string) $xmlText);
        self::assertSame('Xml text', (string) $hook->getXmlText('text'));
        self::assertSame(['ok' => true], $hook->getXmlHook('hook')?->toJSON());
        self::assertSame('Frag', (string) $hook->getXmlFragment('fragment'));

        $target = new YDoc();
        $target->applyUpdateV2($source->encodeStateAsUpdateV2());
        $targetHook = $target->getXmlFragment('xml')->get(0);

        self::assertInstanceOf(YXmlHook::class, $targetHook);
        self::assertSame($hook->toJSON(), $targetHook->toJSON());
        self::assertSame('<p>Element</p>', (string) $targetHook->getXmlElement('element'));
        self::assertSame('Xml text', (string) $targetHook->getXmlText('text'));
        self::assertSame(['ok' => true], $targetHook->getXmlHook('hook')?->toJSON());
        self::assertSame('Frag', (string) $targetHook->getXmlFragment('fragment'));
    }

    public function testNativeXmlHookBulkSharedTypeMutationApisCanBeEncodedAndApplied(): void
    {
        $source = new YDoc(306);
        $hook = $source->getXmlFragment('xml')->insertHook(0, 'mention');
        $arrays = $hook->setArrays(['items']);
        $maps = $hook->setMaps(['meta']);
        $texts = $hook->setTexts(['body']);
        $elements = $hook->setXmlElements(['element' => 'p']);
        $xmlTexts = $hook->setXmlTexts(['text' => 'Xml text']);
        $hooks = $hook->setXmlHooks(['hook' => 'note']);
        $fragments = $hook->setXmlFragments(['fragment']);

        $arrays['items']->insert(0, ['A', 'B']);
        $maps['meta']->set('role', 'author');
        $texts['body']->insert(0, 'Hook text');
        $elements['element']->appendText('Element');
        $hooks['hook']->set('ok', true);
        $fragments['fragment']->appendText('Frag');

        self::assertSame([
            'body' => 'Hook text',
            'element' => '<p>Element</p>',
            'fragment' => 'Frag',
            'hook' => ['ok' => true],
            'items' => ['A', 'B'],
            'meta' => ['role' => 'author'],
            'text' => 'Xml text',
        ], $hook->toJSON());
        self::assertSame(['A', 'B'], $hook->getArray('items')?->toJSON());
        self::assertSame(['role' => 'author'], $hook->getMap('meta')?->toJSON());
        self::assertSame([['insert' => 'Hook text']], $hook->getText('body')?->toDelta());
        self::assertSame('<p>Element</p>', (string) $hook->getXmlElement('element'));
        self::assertSame('Xml text', (string) $xmlTexts['text']);
        self::assertSame('Xml text', (string) $hook->getXmlText('text'));
        self::assertSame(['ok' => true], $hook->getXmlHook('hook')?->toJSON());
        self::assertSame('Frag', (string) $hook->getXmlFragment('fragment'));

        $target = new YDoc();
        $target->applyUpdateV2($source->encodeStateAsUpdateV2());
        $targetHook = $target->getXmlFragment('xml')->get(0);

        self::assertInstanceOf(YXmlHook::class, $targetHook);
        self::assertSame($hook->toJSON(), $targetHook->toJSON());
        self::assertSame(['A', 'B'], $targetHook->getArray('items')?->toJSON());
        self::assertSame(['role' => 'author'], $targetHook->getMap('meta')?->toJSON());
        self::assertSame([['insert' => 'Hook text']], $targetHook->getText('body')?->toDelta());
        self::assertSame('<p>Element</p>', (string) $targetHook->getXmlElement('element'));
        self::assertSame('Xml text', (string) $targetHook->getXmlText('text'));
        self::assertSame(['ok' => true], $targetHook->getXmlHook('hook')?->toJSON());
        self::assertSame('Frag', (string) $targetHook->getXmlFragment('fragment'));
    }

    public function testNativeXmlHookBulkSharedTypeSettersRejectInvalidValuesBeforeMutating(): void
    {
        $doc = new YDoc(307);
        $hook = $doc->getXmlFragment('xml')->insertHook(0, 'mention');
        $hook->setText('existing')->insert(0, 'A');

        try {
            $hook->setArrays(['valid', false]);
            self::fail('Expected invalid XML hook bulk array setter to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('YXmlHook setArrays only supports string keys.', $exception->getMessage());
        }

        try {
            $hook->setXmlHooks(['validHook' => 'note', 'invalidHook' => null]);
            self::fail('Expected invalid XML hook bulk XML hook setter to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('YXmlHook setXmlHooks only supports string hook names.', $exception->getMessage());
        }

        self::assertSame(['existing' => 'A'], $hook->toJSON());
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private function findDeepEvent(array $events, string $target, string $key): array
    {
        foreach ($events as $event) {
            if ($event['target'] === $target && (($event['name'] ?? $event['idKey'] ?? null) === $key)) {
                return $event;
            }
        }

        self::fail(sprintf('Deep event "%s:%s" was not emitted.', $target, $key));
    }
}
