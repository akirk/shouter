<?php

declare(strict_types=1);

namespace Yjs\Tests\Compatibility;

use PHPUnit\Framework\TestCase;
use Yjs\UndefinedValue;
use Yjs\Update\DecodedUpdate;
use Yjs\YNestedArray;
use Yjs\YNestedMap;
use Yjs\YNestedText;
use Yjs\YNestedXmlFragment;
use Yjs\YDoc;
use Yjs\YXmlHook;
use Yjs\YXmlElement;
use Yjs\YXmlText;
use Yjs\YSubdoc;

final class YjsMaterializedDocumentFixtureTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__ . '/../../fixtures/generated/yjs-13.6.31/updates-v1.json';

    public function testMaterializedJsonMatchesYjsFixtures(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $update = base64_decode($case['updateV1'], true);
            self::assertIsString($update);

            $doc = new YDoc();
            $doc->applyUpdateV1($update);

            self::assertSame($case['json'], $doc->toJSON(), sprintf('Failed materializing case "%s".', $case['name']));
        }
    }

    public function testSharedTypeAccessorsExposeMaterializedValues(): void
    {
        $cases = $this->casesByName();

        $textDoc = new YDoc();
        $textUpdate = base64_decode($cases['text-format-delete']['updateV1'], true);
        self::assertIsString($textUpdate);
        $textDoc->applyUpdateV1($textUpdate);
        self::assertSame('HelloYjs', $textDoc->getText('content')->toString());
        self::assertSame('HelloYjs', $textDoc->getText('content')->toJSON());

        $arrayDoc = new YDoc();
        $arrayUpdate = base64_decode($cases['array-primitives']['updateV1'], true);
        self::assertIsString($arrayUpdate);
        $arrayDoc->applyUpdateV1($arrayUpdate);
        self::assertSame([1, 'two', true, null, ['nested' => ['x']]], $arrayDoc->getArray('array')->toArray());
        self::assertSame($cases['array-primitives']['json']['array'], $arrayDoc->getArray('array')->toJSON());

        $mapDoc = new YDoc();
        $mapUpdate = base64_decode($cases['map-primitives-delete']['updateV1'], true);
        self::assertIsString($mapUpdate);
        $mapDoc->applyUpdateV1($mapUpdate);
        self::assertSame('Hello', $mapDoc->getMap('map')->get('title'));
        self::assertNull($mapDoc->getMap('map')->get('count'));
        self::assertSame($cases['map-primitives-delete']['json']['map'], $mapDoc->getMap('map')->toJSON());
    }

    public function testSubdocAccessorsExposeMetadataWhileJsonMatchesYjs(): void
    {
        $cases = $this->casesByName();

        $arrayDoc = new YDoc();
        $arrayUpdate = base64_decode($cases['array-subdoc']['updateV1'], true);
        self::assertIsString($arrayUpdate);
        $arrayDoc->applyUpdateV1($arrayUpdate);
        $arraySubdoc = $arrayDoc->getArray('array')->get(0);

        self::assertSame($cases['array-subdoc']['json'], $arrayDoc->toJSON());
        self::assertInstanceOf(YSubdoc::class, $arraySubdoc);
        self::assertSame('array-subdoc', $arraySubdoc->guid());
        self::assertSame(['meta' => ['kind' => 'note']], $arraySubdoc->opts());
        self::assertSame(['kind' => 'note'], $arraySubdoc->meta());
        self::assertFalse($arraySubdoc->shouldLoad());

        $mapDoc = new YDoc();
        $mapUpdate = base64_decode($cases['map-subdoc']['updateV1'], true);
        self::assertIsString($mapUpdate);
        $mapDoc->applyUpdateV1($mapUpdate);
        $mapSubdoc = $mapDoc->getMap('map')->get('subdoc');

        self::assertSame($cases['map-subdoc']['json'], $mapDoc->toJSON());
        self::assertInstanceOf(YSubdoc::class, $mapSubdoc);
        self::assertSame('map-subdoc', $mapSubdoc->guid());
        self::assertSame(['autoLoad' => true], $mapSubdoc->opts());
        self::assertNull($mapSubdoc->meta());
        self::assertTrue($mapSubdoc->shouldLoad());
    }

    public function testUndefinedMapValueRemainsDistinctFromNull(): void
    {
        $case = $this->decodedOnlyCasesByName()['map-undefined-null-content-any'];

        foreach (['updateV1' => false, 'updateV2' => true] as $updateKey => $v2) {
            $update = base64_decode($case[$updateKey], true);
            self::assertIsString($update);

            $doc = new YDoc();
            if ($v2) {
                $doc->applyUpdateV2($update);
            } else {
                $doc->applyUpdateV1($update);
            }

            $map = $doc->getMap('map');
            self::assertTrue($map->has('u'), $updateKey);
            self::assertTrue($map->has('n'), $updateKey);
            self::assertInstanceOf(UndefinedValue::class, $map->get('u'), $updateKey);
            self::assertNull($map->get('n'), $updateKey);
            self::assertInstanceOf(UndefinedValue::class, $map->toArray()['u'], $updateKey);
            self::assertNull($map->toArray()['n'], $updateKey);
        }
    }

    public function testV2SubdocAccessorsExposeMetadataWhileJsonMatchesYjs(): void
    {
        $cases = $this->casesByName();

        $arrayDoc = new YDoc();
        $arrayUpdate = base64_decode($cases['array-subdoc']['updateV2'], true);
        self::assertIsString($arrayUpdate);
        $arrayDoc->applyUpdateV2($arrayUpdate);
        $arraySubdoc = $arrayDoc->getArray('array')->get(0);

        self::assertSame($cases['array-subdoc']['json'], $arrayDoc->toJSON());
        self::assertInstanceOf(YSubdoc::class, $arraySubdoc);
        self::assertSame('array-subdoc', $arraySubdoc->guid());
        self::assertSame(['kind' => 'note'], $arraySubdoc->meta());

        $mapDoc = new YDoc();
        $mapUpdate = base64_decode($cases['map-subdoc']['updateV2'], true);
        self::assertIsString($mapUpdate);
        $mapDoc->applyUpdateV2($mapUpdate);
        $mapSubdoc = $mapDoc->getMap('map')->get('subdoc');

        self::assertSame($cases['map-subdoc']['json'], $mapDoc->toJSON());
        self::assertInstanceOf(YSubdoc::class, $mapSubdoc);
        self::assertSame('map-subdoc', $mapSubdoc->guid());
        self::assertTrue($mapSubdoc->shouldLoad());
    }

    public function testXmlHookSharedTypeAccessorsMatchYjsFixture(): void
    {
        $case = $this->casesByName()['xml-hook-shared-type-values'];

        foreach (['updateV1' => false, 'updateV2' => true] as $updateKey => $v2) {
            $update = base64_decode($case[$updateKey], true);
            self::assertIsString($update);

            $doc = new YDoc();
            if ($v2) {
                $doc->applyUpdateV2($update);
            } else {
                $doc->applyUpdateV1($update);
            }

            $hook = $doc->getXmlFragment('xml')->get(0);
            self::assertInstanceOf(YXmlHook::class, $hook, $updateKey);
            self::assertSame($case['json'], $doc->toJSON(), $updateKey);
            self::assertSame($this->sortAssociativeArrays($case['hookJson']), $this->sortAssociativeArrays($hook->toJSON()), $updateKey);

            $body = $hook->getText('body');
            self::assertInstanceOf(YNestedText::class, $body, $updateKey);
            self::assertSame($case['hookTextDelta'], $body->toDelta(), $updateKey);

            $meta = $hook->getMap('meta');
            self::assertInstanceOf(YNestedMap::class, $meta, $updateKey);
            self::assertSame($case['hookMapJson'], $meta->toJSON(), $updateKey);

            $items = $hook->getArray('items');
            self::assertInstanceOf(YNestedArray::class, $items, $updateKey);
            self::assertSame($case['hookArrayJson'], $items->toJSON(), $updateKey);
        }
    }

    public function testXmlHookXmlSharedTypeAccessorsMatchYjsFixture(): void
    {
        $case = $this->casesByName()['xml-hook-xml-shared-type-values'];

        foreach (['updateV1' => false, 'updateV2' => true] as $updateKey => $v2) {
            $update = base64_decode($case[$updateKey], true);
            self::assertIsString($update);

            $doc = new YDoc();
            if ($v2) {
                $doc->applyUpdateV2($update);
            } else {
                $doc->applyUpdateV1($update);
            }

            $hook = $doc->getXmlFragment('xml')->get(0);
            self::assertInstanceOf(YXmlHook::class, $hook, $updateKey);
            self::assertSame($case['json'], $doc->toJSON(), $updateKey);
            self::assertSame($this->sortAssociativeArrays($case['hookJson']), $this->sortAssociativeArrays($hook->toJSON()), $updateKey);

            $element = $hook->getXmlElement('element');
            self::assertInstanceOf(YXmlElement::class, $element, $updateKey);
            self::assertSame($case['hookElementXml'], (string) $element, $updateKey);

            $text = $hook->getXmlText('text');
            self::assertInstanceOf(YXmlText::class, $text, $updateKey);
            self::assertSame($case['hookTextXml'], (string) $text, $updateKey);

            $nestedHook = $hook->getXmlHook('hook');
            self::assertInstanceOf(YXmlHook::class, $nestedHook, $updateKey);
            self::assertSame($case['hookNestedHookJson'], $nestedHook->toJSON(), $updateKey);

            $fragment = $hook->getXmlFragment('fragment');
            self::assertInstanceOf(YNestedXmlFragment::class, $fragment, $updateKey);
            self::assertSame($case['hookFragmentXml'], (string) $fragment, $updateKey);
        }
    }

    public function testStateVectorAfterFullUpdateMatchesYjsFixtures(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $doc = new YDoc();
            $update = base64_decode($case['updateV1'], true);
            self::assertIsString($update);

            $doc->applyUpdateV1($update);

            self::assertSame(
                base64_decode($case['stateVectorV1'], true),
                $doc->encodeStateVector(),
                sprintf('Failed state vector for case "%s".', $case['name'])
            );
        }
    }

    public function testEncodingFullStateUpdateMatchesYjsDecodedFixtures(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $update = base64_decode($case['updateV1'], true);
            self::assertIsString($update);

            $doc = new YDoc();
            $doc->applyUpdateV1($update);
            $encoded = $doc->encodeStateAsUpdateV1();

            self::assertSame(
                DecodedUpdate::decodeV1($update),
                DecodedUpdate::decodeV1($encoded),
                sprintf('Failed re-encoding full update for case "%s".', $case['name'])
            );
        }
    }

    public function testEncodingFullV2StateUpdateMatchesYjsDecodedFixtures(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $update = base64_decode($case['updateV1'], true);
            self::assertIsString($update);

            $doc = new YDoc();
            $doc->applyUpdateV1($update);
            $encoded = $doc->encodeStateAsUpdateV2();

            self::assertSame(
                DecodedUpdate::decodeV2((string) base64_decode($case['updateV2'], true)),
                DecodedUpdate::decodeV2($encoded),
                sprintf('Failed re-encoding full V2 update for case "%s".', $case['name'])
            );
        }
    }

    public function testPhpEncodedV2FullUpdateCanHydrateFreshPhpDoc(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $update = base64_decode($case['updateV1'], true);
            self::assertIsString($update);

            $source = new YDoc();
            $source->applyUpdateV1($update);

            $target = new YDoc();
            $target->applyUpdateV2($source->encodeStateAsUpdateV2());

            self::assertSame($case['json'], $target->toJSON(), sprintf('Failed hydrating PHP V2 update for case "%s".', $case['name']));
            self::assertSame($source->encodeStateVector(), $target->encodeStateVector());
        }
    }

    public function testApplyingV2FullUpdatesMatchesYjsFixtures(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $update = base64_decode($case['updateV2'], true);
            self::assertIsString($update);

            $doc = new YDoc();
            $doc->applyUpdateV2($update);

            self::assertSame($case['json'], $doc->toJSON(), sprintf('Failed applying V2 case "%s".', $case['name']));
            self::assertSame(base64_decode($case['stateVectorV1'], true), $doc->encodeStateVector());
        }
    }

    /**
     * @return array{cases: list<array<string, mixed>>}
     */
    private function loadFixtures(): array
    {
        self::assertFileExists(self::FIXTURE_PATH, 'Run `npm run fixtures` before PHPUnit.');

        $decoded = json_decode((string) file_get_contents(self::FIXTURE_PATH), true);

        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function casesByName(): array
    {
        $cases = [];

        foreach ($this->loadFixtures()['cases'] as $case) {
            $cases[$case['name']] = $case;
        }

        return $cases;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function decodedOnlyCasesByName(): array
    {
        $cases = [];

        foreach ($this->loadFixtures()['decodedOnlyCases'] as $case) {
            $cases[$case['name']] = $case;
        }

        return $cases;
    }

    private function sortAssociativeArrays(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $sorted = array_map(fn (mixed $nested): mixed => $this->sortAssociativeArrays($nested), $value);
        if (! array_is_list($sorted)) {
            ksort($sorted, SORT_STRING);
        }

        return $sorted;
    }
}
