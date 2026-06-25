<?php

declare(strict_types=1);

namespace Yjs\Tests\Compatibility;

use PHPUnit\Framework\TestCase;
use Yjs\Update\DecodedUpdate;
use Yjs\Update\UpdateUtils;
use Yjs\YDoc;
use Yjs\YSubdoc;
use Yjs\YXmlElement;
use Yjs\YXmlHook;
use Yjs\YXmlText;

final class YjsPartialDiffFixtureTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__ . '/../../fixtures/generated/yjs-13.6.31/partial-diffs-v1.json';

    public function testPartialStructDiffsMatchYjsDecodedFixtures(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $doc = new YDoc();
            $update = base64_decode($case['updateV1'], true);
            $stateVector = base64_decode($case['targetStateVectorV1'], true);
            self::assertIsString($update);
            self::assertIsString($stateVector);
            $doc->applyUpdateV1($update);

            $diff = $doc->encodeStateAsUpdateV1($stateVector);

            self::assertSame(
                $this->normalizeDecodedUpdate($case['expectedDecodedDiff']),
                DecodedUpdate::decodeV1($diff),
                sprintf('Failed partial diff case "%s".', $case['name'])
            );
        }
    }

    public function testPartialStructDiffsMatchYjsEncodedFixtures(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $doc = new YDoc();
            $update = base64_decode($case['updateV1'], true);
            $stateVector = base64_decode($case['targetStateVectorV1'], true);
            $expectedDiff = base64_decode($case['expectedDiffV1'], true);
            self::assertIsString($update);
            self::assertIsString($stateVector);
            self::assertIsString($expectedDiff);
            $doc->applyUpdateV1($update);

            self::assertSame(
                $expectedDiff,
                $doc->encodeStateAsUpdateV1($stateVector),
                sprintf('Failed exact partial diff case "%s".', $case['name'])
            );
        }
    }

    public function testPartialStructV2DiffsMatchYjsDecodedFixtures(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $doc = new YDoc();
            $update = base64_decode($case['updateV2'], true);
            $stateVector = base64_decode($case['targetStateVectorV1'], true);
            self::assertIsString($update);
            self::assertIsString($stateVector);
            $doc->applyUpdateV2($update);

            $diff = $doc->encodeStateAsUpdateV2($stateVector);

            self::assertSame(
                $this->normalizeDecodedUpdate($case['expectedDecodedDiffV2']),
                DecodedUpdate::decodeV2($diff),
                sprintf('Failed partial V2 diff case "%s".', $case['name'])
            );
        }
    }

    public function testPartialStructV2DiffsMatchYjsEncodedFixtures(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $doc = new YDoc();
            $update = base64_decode($case['updateV2'], true);
            $stateVector = base64_decode($case['targetStateVectorV1'], true);
            $expectedDiff = base64_decode($case['expectedDiffV2'], true);
            self::assertIsString($update);
            self::assertIsString($stateVector);
            self::assertIsString($expectedDiff);
            $doc->applyUpdateV2($update);

            self::assertSame(
                $expectedDiff,
                $doc->encodeStateAsUpdateV2($stateVector),
                sprintf('Failed exact partial V2 diff case "%s".', $case['name'])
            );
        }
    }

    public function testPartialStructDiffUpdateV1MatchesYjsDecodedFixtures(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $update = base64_decode($case['updateV1'], true);
            $stateVector = base64_decode($case['targetStateVectorV1'], true);
            self::assertIsString($update);
            self::assertIsString($stateVector);

            $diff = UpdateUtils::diffUpdateV1($update, $stateVector);

            self::assertSame(
                $this->normalizeDecodedUpdate($case['expectedDecodedDiff']),
                DecodedUpdate::decodeV1($diff),
                sprintf('Failed partial diffUpdate case "%s".', $case['name'])
            );
        }
    }

    public function testPartialStructDiffUpdateV1MatchesYjsEncodedFixtures(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $update = base64_decode($case['updateV1'], true);
            $stateVector = base64_decode($case['targetStateVectorV1'], true);
            $expectedDiff = base64_decode($case['expectedDiffV1'], true);
            self::assertIsString($update);
            self::assertIsString($stateVector);
            self::assertIsString($expectedDiff);

            self::assertSame(
                $expectedDiff,
                UpdateUtils::diffUpdateV1($update, $stateVector),
                sprintf('Failed exact partial diffUpdate case "%s".', $case['name'])
            );
        }
    }

    public function testPartialStructDiffUpdateV2MatchesYjsDecodedFixtures(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $update = base64_decode($case['updateV2'], true);
            $stateVector = base64_decode($case['targetStateVectorV1'], true);
            self::assertIsString($update);
            self::assertIsString($stateVector);

            $diff = UpdateUtils::diffUpdateV2($update, $stateVector);

            self::assertSame(
                $this->normalizeDecodedUpdate($case['expectedDecodedDiffV2']),
                DecodedUpdate::decodeV2($diff),
                sprintf('Failed partial diffUpdate V2 case "%s".', $case['name'])
            );
        }
    }

    public function testPartialStructDiffUpdateV2MatchesYjsEncodedFixtures(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $update = base64_decode($case['updateV2'], true);
            $stateVector = base64_decode($case['targetStateVectorV1'], true);
            $expectedDiff = base64_decode($case['expectedDiffV2'], true);
            self::assertIsString($update);
            self::assertIsString($stateVector);
            self::assertIsString($expectedDiff);

            self::assertSame(
                $expectedDiff,
                UpdateUtils::diffUpdateV2($update, $stateVector),
                sprintf('Failed exact partial diffUpdate V2 case "%s".', $case['name'])
            );
        }
    }

    public function testPartialStructDiffsApplyToKnownPrefixes(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            if (! isset($case['prefixUpdateV1'], $case['prefixUpdateV2'], $case['json'])) {
                continue;
            }

            $stateVector = base64_decode($case['targetStateVectorV1'], true);
            self::assertIsString($stateVector);

            $v1Diff = $this->encodeV1DiffFromCase($case, $stateVector);
            $this->assertDiffAppliesToPrefix($case, $case['prefixUpdateV1'], $v1Diff, false);

            $updateV1 = base64_decode($case['updateV1'], true);
            self::assertIsString($updateV1);
            $v1StatelessDiff = UpdateUtils::diffUpdateV1($updateV1, $stateVector);
            $this->assertDiffAppliesToPrefix($case, $case['prefixUpdateV1'], $v1StatelessDiff, false);

            $v2Diff = $this->encodeV2DiffFromCase($case, $stateVector);
            $this->assertDiffAppliesToPrefix($case, $case['prefixUpdateV2'], $v2Diff, true);

            $updateV2 = base64_decode($case['updateV2'], true);
            self::assertIsString($updateV2);
            $v2StatelessDiff = UpdateUtils::diffUpdateV2($updateV2, $stateVector);
            $this->assertDiffAppliesToPrefix($case, $case['prefixUpdateV2'], $v2StatelessDiff, true);
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
     * @param array{structs: list<array<string, mixed>>, deleteSet: array<string, list<array{clock: int, length: int}>|null>} $decoded
     * @return array{
     *     structs: list<array<string, mixed>>,
     *     deleteSet: array<int, list<array{clock: int, length: int}>>
     * }
     */
    private function normalizeDecodedUpdate(array $decoded): array
    {
        $deleteSet = [];

        foreach ($decoded['deleteSet'] as $client => $deletes) {
            if ($deletes !== null) {
                $deleteSet[(int) $client] = $deletes;
            }
        }

        krsort($deleteSet, SORT_NUMERIC);

        return [
            'structs' => $decoded['structs'],
            'deleteSet' => $deleteSet,
        ];
    }

    /**
     * @param array<string, mixed> $case
     */
    private function encodeV1DiffFromCase(array $case, string $stateVector): string
    {
        $doc = new YDoc();
        $this->initializeType($doc, $case['type'] ?? null);
        $update = base64_decode($case['updateV1'], true);
        self::assertIsString($update);
        $doc->applyUpdateV1($update);

        return $doc->encodeStateAsUpdateV1($stateVector);
    }

    /**
     * @param array<string, mixed> $case
     */
    private function encodeV2DiffFromCase(array $case, string $stateVector): string
    {
        $doc = new YDoc();
        $this->initializeType($doc, $case['type'] ?? null);
        $update = base64_decode($case['updateV2'], true);
        self::assertIsString($update);
        $doc->applyUpdateV2($update);

        return $doc->encodeStateAsUpdateV2($stateVector);
    }

    /**
     * @param array<string, mixed> $case
     */
    private function assertDiffAppliesToPrefix(array $case, string $encodedPrefix, string $diff, bool $v2): void
    {
        $doc = new YDoc();
        $this->initializeType($doc, $case['type'] ?? null);
        $prefix = base64_decode($encodedPrefix, true);
        self::assertIsString($prefix);

        if ($v2) {
            $doc->applyUpdateV2($prefix);
            $doc->applyUpdateV2($diff);
        } else {
            $doc->applyUpdateV1($prefix);
            $doc->applyUpdateV1($diff);
        }

        self::assertSame(
            $this->sortAssociativeArrays($case['json']),
            $this->sortAssociativeArrays($doc->toJSON()),
            sprintf('Failed applying %s partial diff case "%s".', $v2 ? 'V2' : 'V1', $case['name'])
        );

        if (isset($case['hookJson'])) {
            $hook = $doc->getXmlFragment('xml')->get(0);
            self::assertInstanceOf(YXmlHook::class, $hook, sprintf('Failed resolving hook for partial diff case "%s".', $case['name']));
            self::assertSame(
                $this->sortAssociativeArrays($case['hookJson']),
                $this->sortAssociativeArrays($hook->toJSON()),
                sprintf('Failed applying %s partial hook JSON case "%s".', $v2 ? 'V2' : 'V1', $case['name'])
            );
        }

        if (isset($case['textAttributes'])) {
            self::assertSame(
                $this->sortAssociativeArrays($case['textAttributes']),
                $this->sortAssociativeArrays($doc->getText('content')->getAttributes()),
                sprintf('Failed applying %s partial text attributes case "%s".', $v2 ? 'V2' : 'V1', $case['name'])
            );
        }

        if (isset($case['nestedTextAttributes'])) {
            $text = $doc->getArray('array')->getText(0);
            self::assertNotNull($text, sprintf('Failed resolving nested text for partial diff case "%s".', $case['name']));
            self::assertSame(
                $this->sortAssociativeArrays($case['nestedTextAttributes']),
                $this->sortAssociativeArrays($text->getAttributes()),
                sprintf('Failed applying %s partial nested text attributes case "%s".', $v2 ? 'V2' : 'V1', $case['name'])
            );
        }

        if (isset($case['mapTextAttributes'])) {
            self::assertIsString($case['mapTextKey'] ?? null);
            $text = $doc->getMap('map')->getText($case['mapTextKey']);
            self::assertNotNull($text, sprintf('Failed resolving map text for partial diff case "%s".', $case['name']));
            self::assertSame(
                $this->sortAssociativeArrays($case['mapTextAttributes']),
                $this->sortAssociativeArrays($text->getAttributes()),
                sprintf('Failed applying %s partial map text attributes case "%s".', $v2 ? 'V2' : 'V1', $case['name'])
            );
        }

        if (isset($case['mapXmlElementAttributes'])) {
            self::assertIsString($case['mapXmlElementKey'] ?? null);
            $element = $doc->getMap('map')->getXmlElement($case['mapXmlElementKey']);
            self::assertNotNull($element, sprintf('Failed resolving map XML element for partial diff case "%s".', $case['name']));
            self::assertSame(
                $this->sortAssociativeArrays($case['mapXmlElementAttributes']),
                $this->sortAssociativeArrays($element->getAttributes()),
                sprintf('Failed applying %s partial map XML element attributes case "%s".', $v2 ? 'V2' : 'V1', $case['name'])
            );
        }

        if (isset($case['mapXmlTextDelta'])) {
            self::assertIsString($case['mapXmlTextKey'] ?? null);
            $text = $doc->getMap('map')->getXmlText($case['mapXmlTextKey']);
            self::assertNotNull($text, sprintf('Failed resolving map XML text for partial diff case "%s".', $case['name']));
            self::assertSame(
                $this->sortAssociativeArrays($case['mapXmlTextDelta']),
                $this->sortAssociativeArrays($text->toDelta()),
                sprintf('Failed applying %s partial map XML text delta case "%s".', $v2 ? 'V2' : 'V1', $case['name'])
            );
        }

        if (isset($case['xmlTextAttributes'])) {
            $xmlText = $doc->getXmlFragment('xml')->get(0);
            self::assertInstanceOf(YXmlText::class, $xmlText, sprintf('Failed resolving XML text for partial diff case "%s".', $case['name']));
            self::assertSame(
                $this->sortAssociativeArrays($case['xmlTextAttributes']),
                $this->sortAssociativeArrays($xmlText->getAttributes()),
                sprintf('Failed applying %s partial XML text attributes case "%s".', $v2 ? 'V2' : 'V1', $case['name'])
            );
        }

        if (isset($case['xmlTextDelta'])) {
            $paragraph = $doc->getXmlFragment('xml')->get(0);
            self::assertInstanceOf(YXmlElement::class, $paragraph, sprintf('Failed resolving XML text parent for partial diff case "%s".', $case['name']));
            $text = $paragraph->get(0);
            self::assertInstanceOf(YXmlText::class, $text, sprintf('Failed resolving XML text for partial diff case "%s".', $case['name']));
            self::assertSame(
                $this->sortAssociativeArrays($case['xmlTextDelta']),
                $this->sortAssociativeArrays($text->toDelta()),
                sprintf('Failed applying %s partial XML text delta case "%s".', $v2 ? 'V2' : 'V1', $case['name'])
            );
        }

        foreach ($case['subdocs'] ?? [] as $subdocCase) {
            self::assertIsArray($subdocCase);
            self::assertIsString($subdocCase['root'] ?? null);
            self::assertIsArray($subdocCase['path'] ?? null);
            self::assertIsString($subdocCase['guid'] ?? null);
            self::assertIsBool($subdocCase['shouldLoad'] ?? null);

            $value = match ($subdocCase['root']) {
                'array' => $doc->getArray('array'),
                'map' => $doc->getMap('map'),
                default => throw new \UnexpectedValueException(sprintf('Unknown partial subdoc root "%s".', $subdocCase['root'])),
            };

            foreach ($subdocCase['path'] as $segment) {
                self::assertTrue(is_int($segment) || is_string($segment));
                $value = $value->get($segment);
            }

            self::assertInstanceOf(YSubdoc::class, $value, sprintf('Failed resolving partial subdoc for case "%s".', $case['name']));
            self::assertSame($subdocCase['guid'], $value->guid(), sprintf('Failed applying %s partial subdoc guid case "%s".', $v2 ? 'V2' : 'V1', $case['name']));
            self::assertSame($subdocCase['meta'] ?? null, $value->meta(), sprintf('Failed applying %s partial subdoc meta case "%s".', $v2 ? 'V2' : 'V1', $case['name']));
            self::assertSame($subdocCase['shouldLoad'], $value->shouldLoad(), sprintf('Failed applying %s partial subdoc shouldLoad case "%s".', $v2 ? 'V2' : 'V1', $case['name']));
        }
    }

    private function initializeType(YDoc $doc, mixed $type): void
    {
        match ($type) {
            'array' => $doc->getArray('array'),
            'map' => $doc->getMap('map'),
            'text' => $doc->getText('content'),
            'xml' => $doc->getXmlFragment('xml'),
            default => null,
        };
    }

    private function sortAssociativeArrays(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->sortAssociativeArrays($item);
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
