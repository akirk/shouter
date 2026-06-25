<?php

declare(strict_types=1);

namespace Yjs\Tests\Compatibility;

use PHPUnit\Framework\TestCase;
use Yjs\Update\DecodedUpdate;
use Yjs\YNestedText;
use Yjs\YDoc;
use Yjs\YSubdoc;
use Yjs\YXmlElement;
use Yjs\YXmlHook;
use Yjs\YXmlText;

final class YjsConcurrentUpdateFixtureTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__ . '/../../fixtures/generated/yjs-13.6.31/concurrent-v1.json';

    public function testApplyingConcurrentV1UpdatesMatchesYjsFixtures(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $doc = new YDoc();
            $this->applyRootTypeHints($doc, $case);

            foreach ($case['updatesV1'] as $encodedUpdate) {
                $update = base64_decode($encodedUpdate, true);
                self::assertIsString($update);
                $doc->applyUpdateV1($update);
            }

            self::assertSame(
                $this->sortAssociativeArrays($case['json']),
                $this->sortAssociativeArrays($doc->toJSON()),
                sprintf('Failed applying concurrent case "%s".', $case['name'])
            );
            $this->assertOptionalHookJson($case, $doc, sprintf('Failed applying concurrent hook state case "%s".', $case['name']));
            $this->assertOptionalTextAttributes($case, $doc, sprintf('Failed applying concurrent text attributes case "%s".', $case['name']));
            $this->assertOptionalNestedTextAttributes($case, $doc, sprintf('Failed applying concurrent nested text attributes case "%s".', $case['name']));
            $this->assertOptionalMapTextAttributes($case, $doc, sprintf('Failed applying concurrent map text attributes case "%s".', $case['name']));
            $this->assertOptionalMapTextDelta($case, $doc, sprintf('Failed applying concurrent map text delta case "%s".', $case['name']));
            $this->assertOptionalMapXmlFragment($case, $doc, sprintf('Failed applying concurrent map XML fragment case "%s".', $case['name']));
            $this->assertOptionalMapSubdoc($case, $doc, sprintf('Failed applying concurrent map subdoc case "%s".', $case['name']));
            $this->assertOptionalArraySubdoc($case, $doc, sprintf('Failed applying concurrent array subdoc case "%s".', $case['name']));
            $this->assertOptionalXmlTextDelta($case, $doc, sprintf('Failed applying concurrent XML text delta case "%s".', $case['name']));
            $this->assertOptionalRootXmlFragment($case, $doc, sprintf('Failed applying concurrent root XML fragment case "%s".', $case['name']));
            $this->assertOptionalXmlElementAttributes($case, $doc, sprintf('Failed applying concurrent XML element attributes case "%s".', $case['name']));
            $this->assertOptionalRootXmlTextAttributes($case, $doc, sprintf('Failed applying concurrent root XML text attributes case "%s".', $case['name']));
        }
    }

    public function testApplyingConcurrentV2UpdatesMatchesYjsFixtures(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $doc = new YDoc();
            $this->applyRootTypeHints($doc, $case);

            foreach ($case['updatesV2'] as $encodedUpdate) {
                $update = base64_decode($encodedUpdate, true);
                self::assertIsString($update);
                $doc->applyUpdateV2($update);
            }

            self::assertSame(
                $this->sortAssociativeArrays($case['json']),
                $this->sortAssociativeArrays($doc->toJSON()),
                sprintf('Failed applying concurrent V2 case "%s".', $case['name'])
            );
            $this->assertOptionalHookJson($case, $doc, sprintf('Failed applying concurrent V2 hook state case "%s".', $case['name']));
            $this->assertOptionalTextAttributes($case, $doc, sprintf('Failed applying concurrent V2 text attributes case "%s".', $case['name']));
            $this->assertOptionalNestedTextAttributes($case, $doc, sprintf('Failed applying concurrent V2 nested text attributes case "%s".', $case['name']));
            $this->assertOptionalMapTextAttributes($case, $doc, sprintf('Failed applying concurrent V2 map text attributes case "%s".', $case['name']));
            $this->assertOptionalMapTextDelta($case, $doc, sprintf('Failed applying concurrent V2 map text delta case "%s".', $case['name']));
            $this->assertOptionalMapXmlFragment($case, $doc, sprintf('Failed applying concurrent V2 map XML fragment case "%s".', $case['name']));
            $this->assertOptionalMapSubdoc($case, $doc, sprintf('Failed applying concurrent V2 map subdoc case "%s".', $case['name']));
            $this->assertOptionalArraySubdoc($case, $doc, sprintf('Failed applying concurrent V2 array subdoc case "%s".', $case['name']));
            $this->assertOptionalXmlTextDelta($case, $doc, sprintf('Failed applying concurrent V2 XML text delta case "%s".', $case['name']));
            $this->assertOptionalRootXmlFragment($case, $doc, sprintf('Failed applying concurrent V2 root XML fragment case "%s".', $case['name']));
            $this->assertOptionalXmlElementAttributes($case, $doc, sprintf('Failed applying concurrent V2 XML element attributes case "%s".', $case['name']));
            $this->assertOptionalRootXmlTextAttributes($case, $doc, sprintf('Failed applying concurrent V2 root XML text attributes case "%s".', $case['name']));
        }
    }

    public function testConcurrentV1UpdatesConvergeAcrossArrivalOrders(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            foreach ($this->permutations($case['updatesV1']) as $updates) {
                $doc = new YDoc();
                $this->applyRootTypeHints($doc, $case);

                foreach ($updates as $encodedUpdate) {
                    $update = base64_decode($encodedUpdate, true);
                    self::assertIsString($update);
                    $doc->applyUpdateV1($update);
                }

                self::assertSame(
                    $this->sortAssociativeArrays($case['json']),
                    $this->sortAssociativeArrays($doc->toJSON()),
                    sprintf('Failed V1 convergence case "%s".', $case['name'])
                );
                $this->assertOptionalHookJson($case, $doc, sprintf('Failed V1 hook convergence case "%s".', $case['name']));
                $this->assertOptionalTextAttributes($case, $doc, sprintf('Failed V1 text attribute convergence case "%s".', $case['name']));
                $this->assertOptionalNestedTextAttributes($case, $doc, sprintf('Failed V1 nested text attribute convergence case "%s".', $case['name']));
                $this->assertOptionalMapTextAttributes($case, $doc, sprintf('Failed V1 map text attribute convergence case "%s".', $case['name']));
                $this->assertOptionalMapTextDelta($case, $doc, sprintf('Failed V1 map text delta convergence case "%s".', $case['name']));
                $this->assertOptionalMapXmlFragment($case, $doc, sprintf('Failed V1 map XML fragment convergence case "%s".', $case['name']));
                $this->assertOptionalMapSubdoc($case, $doc, sprintf('Failed V1 map subdoc convergence case "%s".', $case['name']));
                $this->assertOptionalArraySubdoc($case, $doc, sprintf('Failed V1 array subdoc convergence case "%s".', $case['name']));
                $this->assertOptionalXmlTextDelta($case, $doc, sprintf('Failed V1 XML text delta convergence case "%s".', $case['name']));
                $this->assertOptionalRootXmlFragment($case, $doc, sprintf('Failed V1 root XML fragment convergence case "%s".', $case['name']));
                $this->assertOptionalXmlElementAttributes($case, $doc, sprintf('Failed V1 XML element attribute convergence case "%s".', $case['name']));
                $this->assertOptionalRootXmlTextAttributes($case, $doc, sprintf('Failed V1 root XML text attribute convergence case "%s".', $case['name']));
            }
        }
    }

    public function testConcurrentV2UpdatesConvergeAcrossArrivalOrders(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            foreach ($this->permutations($case['updatesV2']) as $updates) {
                $doc = new YDoc();
                $this->applyRootTypeHints($doc, $case);

                foreach ($updates as $encodedUpdate) {
                    $update = base64_decode($encodedUpdate, true);
                    self::assertIsString($update);
                    $doc->applyUpdateV2($update);
                }

                self::assertSame(
                    $this->sortAssociativeArrays($case['json']),
                    $this->sortAssociativeArrays($doc->toJSON()),
                    sprintf('Failed V2 convergence case "%s".', $case['name'])
                );
                $this->assertOptionalHookJson($case, $doc, sprintf('Failed V2 hook convergence case "%s".', $case['name']));
                $this->assertOptionalTextAttributes($case, $doc, sprintf('Failed V2 text attribute convergence case "%s".', $case['name']));
                $this->assertOptionalNestedTextAttributes($case, $doc, sprintf('Failed V2 nested text attribute convergence case "%s".', $case['name']));
                $this->assertOptionalMapTextAttributes($case, $doc, sprintf('Failed V2 map text attribute convergence case "%s".', $case['name']));
                $this->assertOptionalMapTextDelta($case, $doc, sprintf('Failed V2 map text delta convergence case "%s".', $case['name']));
                $this->assertOptionalMapXmlFragment($case, $doc, sprintf('Failed V2 map XML fragment convergence case "%s".', $case['name']));
                $this->assertOptionalMapSubdoc($case, $doc, sprintf('Failed V2 map subdoc convergence case "%s".', $case['name']));
                $this->assertOptionalArraySubdoc($case, $doc, sprintf('Failed V2 array subdoc convergence case "%s".', $case['name']));
                $this->assertOptionalXmlTextDelta($case, $doc, sprintf('Failed V2 XML text delta convergence case "%s".', $case['name']));
                $this->assertOptionalRootXmlFragment($case, $doc, sprintf('Failed V2 root XML fragment convergence case "%s".', $case['name']));
                $this->assertOptionalXmlElementAttributes($case, $doc, sprintf('Failed V2 XML element attribute convergence case "%s".', $case['name']));
                $this->assertOptionalRootXmlTextAttributes($case, $doc, sprintf('Failed V2 root XML text attribute convergence case "%s".', $case['name']));
            }
        }
    }

    public function testConcurrentV1MergedStateMatchesYjsFixtures(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $doc = new YDoc();
            $this->applyRootTypeHints($doc, $case);

            foreach ($case['updatesV1'] as $encodedUpdate) {
                $update = base64_decode($encodedUpdate, true);
                self::assertIsString($update);
                $doc->applyUpdateV1($update);
            }

            $stateVector = base64_decode($case['stateVectorV1'], true);
            self::assertIsString($stateVector);
            self::assertSame(
                $stateVector,
                $doc->encodeStateVector(),
                sprintf('Failed V1 merged state vector case "%s".', $case['name'])
            );
            self::assertSame(
                $this->normalizeDeleteSetFixture($case['decodedMergedV1']['deleteSet']),
                DecodedUpdate::decodeV1($doc->encodeStateAsUpdateV1())['deleteSet'],
                sprintf('Failed V1 merged delete set case "%s".', $case['name'])
            );
        }
    }

    public function testConcurrentV2MergedStateMatchesYjsFixtures(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $doc = new YDoc();
            $this->applyRootTypeHints($doc, $case);

            foreach ($case['updatesV2'] as $encodedUpdate) {
                $update = base64_decode($encodedUpdate, true);
                self::assertIsString($update);
                $doc->applyUpdateV2($update);
            }

            $stateVector = base64_decode($case['stateVectorV1'], true);
            self::assertIsString($stateVector);
            self::assertSame(
                $stateVector,
                $doc->encodeStateVector(),
                sprintf('Failed V2 merged state vector case "%s".', $case['name'])
            );
            self::assertSame(
                $this->normalizeDeleteSetFixture($case['decodedMergedV2']['deleteSet']),
                DecodedUpdate::decodeV2($doc->encodeStateAsUpdateV2())['deleteSet'],
                sprintf('Failed V2 merged delete set case "%s".', $case['name'])
            );
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
     * @param list<string> $values
     * @return list<list<string>>
     */
    private function permutations(array $values): array
    {
        if (count($values) <= 1) {
            return [$values];
        }

        $permutations = [];

        foreach ($values as $index => $value) {
            $remaining = $values;
            array_splice($remaining, $index, 1);

            foreach ($this->permutations(array_values($remaining)) as $permutation) {
                array_unshift($permutation, $value);
                $permutations[] = $permutation;
            }
        }

        return $permutations;
    }

    private function sortAssociativeArrays(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalized = array_map(fn (mixed $nested): mixed => $this->sortAssociativeArrays($nested), $value);

        if (array_is_list($normalized)) {
            return $normalized;
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $case
     */
    private function applyRootTypeHints(YDoc $doc, array $case): void
    {
        foreach ($case['rootTypes'] ?? [] as $name => $type) {
            match ($type) {
                'array' => $doc->getArray((string) $name),
                'map' => $doc->getMap((string) $name),
                'text' => $doc->getText((string) $name),
                'xml' => $doc->getXmlFragment((string) $name),
                default => null,
            };
        }
    }

    /**
     * @param array<string, list<array{clock: int, length: int}>|null> $deleteSet
     * @return array<int, list<array{clock: int, length: int}>>
     */
    private function normalizeDeleteSetFixture(array $deleteSet): array
    {
        $normalized = [];

        foreach ($deleteSet as $client => $deletes) {
            if ($deletes !== null) {
                $normalized[(int) $client] = $deletes;
            }
        }

        krsort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $case
     */
    private function assertOptionalHookJson(array $case, YDoc $doc, string $message): void
    {
        if (! array_key_exists('hookJson', $case)) {
            return;
        }

        $hook = $doc->getXmlFragment('xml')->get(0);
        self::assertInstanceOf(YXmlHook::class, $hook, $message);
        self::assertSame(
            $this->sortAssociativeArrays($case['hookJson']),
            $this->sortAssociativeArrays($hook->toJSON()),
            $message
        );
    }

    /**
     * @param array<string, mixed> $case
     */
    private function assertOptionalTextAttributes(array $case, YDoc $doc, string $message): void
    {
        if (! array_key_exists('textAttributes', $case)) {
            return;
        }

        self::assertSame(
            $this->sortAssociativeArrays($case['textAttributes']),
            $this->sortAssociativeArrays($doc->getText('content')->getAttributes()),
            $message
        );
    }

    /**
     * @param array<string, mixed> $case
     */
    private function assertOptionalNestedTextAttributes(array $case, YDoc $doc, string $message): void
    {
        if (! array_key_exists('nestedTextAttributes', $case)) {
            return;
        }

        self::assertIsString($case['nestedTextIdKey'], $message);
        $text = new YNestedText($doc, $case['nestedTextIdKey'], '');

        self::assertSame(
            $this->sortAssociativeArrays($case['nestedTextAttributes']),
            $this->sortAssociativeArrays($text->getAttributes()),
            $message
        );
    }

    /**
     * @param array<string, mixed> $case
     */
    private function assertOptionalMapTextAttributes(array $case, YDoc $doc, string $message): void
    {
        if (! array_key_exists('mapTextAttributes', $case)) {
            return;
        }

        self::assertIsString($case['mapTextKey'], $message);
        $text = $doc->getMap('map')->getText($case['mapTextKey']);
        self::assertNotNull($text, $message);

        self::assertSame(
            $this->sortAssociativeArrays($case['mapTextAttributes']),
            $this->sortAssociativeArrays($text->getAttributes()),
            $message
        );
    }

    /**
     * @param array<string, mixed> $case
     */
    private function assertOptionalMapTextDelta(array $case, YDoc $doc, string $message): void
    {
        if (! array_key_exists('mapTextDelta', $case)) {
            return;
        }

        self::assertIsString($case['mapTextKey'], $message);
        $text = $doc->getMap('map')->getText($case['mapTextKey']);
        self::assertNotNull($text, $message);

        self::assertSame(
            $this->sortAssociativeArrays($case['mapTextDelta']),
            $this->sortAssociativeArrays($text->toDelta()),
            $message
        );
    }

    /**
     * @param array<string, mixed> $case
     */
    private function assertOptionalMapXmlFragment(array $case, YDoc $doc, string $message): void
    {
        if (! array_key_exists('mapXmlString', $case)) {
            return;
        }

        self::assertIsString($case['mapXmlKey'], $message);
        $fragment = $doc->getMap('map')->getXmlFragment($case['mapXmlKey']);
        self::assertNotNull($fragment, $message);

        self::assertSame($case['mapXmlString'], $fragment->toString(), $message);
        self::assertSame($case['mapXmlChildren'], array_map(
            fn (mixed $node): array => $this->summarizeXmlNode($node),
            $fragment->toArray()
        ), $message);
    }

    /**
     * @param array<string, mixed> $case
     */
    private function assertOptionalMapSubdoc(array $case, YDoc $doc, string $message): void
    {
        if (! array_key_exists('mapSubdocGuid', $case)) {
            return;
        }

        self::assertIsString($case['mapSubdocKey'], $message);
        $subdoc = $doc->getMap('map')->get($case['mapSubdocKey']);
        self::assertInstanceOf(YSubdoc::class, $subdoc, $message);

        self::assertSame($case['mapSubdocGuid'], $subdoc->guid(), $message);
        self::assertSame($case['mapSubdocMeta'], $subdoc->meta(), $message);
        self::assertSame($case['mapSubdocShouldLoad'], $subdoc->shouldLoad(), $message);
    }

    /**
     * @param array<string, mixed> $case
     */
    private function assertOptionalArraySubdoc(array $case, YDoc $doc, string $message): void
    {
        if (! array_key_exists('arraySubdocGuid', $case)) {
            return;
        }

        self::assertIsInt($case['arraySubdocIndex'], $message);
        $subdoc = $doc->getArray('array')->get($case['arraySubdocIndex']);
        self::assertInstanceOf(YSubdoc::class, $subdoc, $message);

        self::assertSame($case['arraySubdocGuid'], $subdoc->guid(), $message);
        self::assertSame($case['arraySubdocMeta'], $subdoc->meta(), $message);
        self::assertSame($case['arraySubdocShouldLoad'], $subdoc->shouldLoad(), $message);
    }

    /**
     * @param array<string, mixed> $case
     */
    private function assertOptionalXmlTextDelta(array $case, YDoc $doc, string $message): void
    {
        if (! array_key_exists('xmlTextDelta', $case)) {
            return;
        }

        $paragraph = $doc->getXmlFragment('xml')->get(0);
        self::assertInstanceOf(YXmlElement::class, $paragraph, $message);
        $text = $paragraph->get(0);
        self::assertInstanceOf(YXmlText::class, $text, $message);

        self::assertSame(
            $this->sortAssociativeArrays($case['xmlTextDelta']),
            $this->sortAssociativeArrays($text->toDelta()),
            $message
        );
    }

    /**
     * @param array<string, mixed> $case
     */
    private function assertOptionalRootXmlFragment(array $case, YDoc $doc, string $message): void
    {
        if (! array_key_exists('xmlString', $case)) {
            return;
        }

        $fragment = $doc->getXmlFragment('xml');

        self::assertSame($case['xmlString'], $fragment->toString(), $message);
        self::assertSame($case['xmlChildren'], array_map(
            fn (mixed $node): array => $this->summarizeXmlNode($node),
            $fragment->toArray()
        ), $message);
    }

    /**
     * @param array<string, mixed> $case
     */
    private function assertOptionalXmlElementAttributes(array $case, YDoc $doc, string $message): void
    {
        if (! array_key_exists('elementAttributes', $case)) {
            return;
        }

        $element = $doc->getXmlFragment('xml')->get(0);
        self::assertInstanceOf(YXmlElement::class, $element, $message);

        self::assertSame(
            $this->sortAssociativeArrays($case['elementAttributes']),
            $this->sortAssociativeArrays($element->getAttributes()),
            $message
        );
    }

    /**
     * @param array<string, mixed> $case
     */
    private function assertOptionalRootXmlTextAttributes(array $case, YDoc $doc, string $message): void
    {
        if (! array_key_exists('xmlTextAttributes', $case)) {
            return;
        }

        $text = $doc->getXmlFragment('xml')->get(0);
        self::assertInstanceOf(YXmlText::class, $text, $message);

        self::assertSame(
            $this->sortAssociativeArrays($case['xmlTextAttributes']),
            $this->sortAssociativeArrays($text->getAttributes()),
            $message
        );
    }

    private function summarizeXmlNode(YXmlElement|YXmlText|YXmlHook|string $node): array
    {
        return [
            'type' => match (true) {
                $node instanceof YXmlElement => 'YXmlElement',
                $node instanceof YXmlText => 'YXmlText',
                $node instanceof YXmlHook => 'YXmlHook',
                default => 'String',
            },
            'nodeName' => $node instanceof YXmlElement ? $node->nodeName() : null,
            'string' => (string) $node,
            'json' => $node instanceof YXmlElement || $node instanceof YXmlText || $node instanceof YXmlHook
                ? $node->toJSON()
                : $node,
        ];
    }
}
