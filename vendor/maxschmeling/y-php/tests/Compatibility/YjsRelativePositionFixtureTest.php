<?php

declare(strict_types=1);

namespace Yjs\Tests\Compatibility;

use PHPUnit\Framework\TestCase;
use Yjs\RelativePosition;
use Yjs\YDoc;

final class YjsRelativePositionFixtureTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__ . '/../../fixtures/generated/yjs-13.6.31/relative-positions.json';

    public function testEncodeRelativePositionsMatchesYjsFixtures(): void
    {
        foreach ($this->loadFixture()['cases'] as $case) {
            $position = RelativePosition::fromJSON($case['json']);

            self::assertSame(base64_decode($case['encoded'], true), $position->encode(), $case['name']);
        }
    }

    public function testDecodeRelativePositionsMatchesYjsFixtures(): void
    {
        foreach ($this->loadFixture()['cases'] as $case) {
            $encoded = base64_decode($case['encoded'], true);
            self::assertIsString($encoded);

            self::assertSame($case['decodedJson'], RelativePosition::decode($encoded)->toJSON(), $case['name']);
        }
    }

    public function testCompareRelativePositionsMatchesYjsFixtures(): void
    {
        $fixture = $this->loadFixture();
        $root = RelativePosition::fromJSON($fixture['cases'][0]['json']);
        $sameRoot = RelativePosition::fromJSON($fixture['cases'][0]['decodedJson']);
        $differentAssoc = RelativePosition::fromJSON($fixture['cases'][1]['json']);
        $differentKind = RelativePosition::fromJSON($fixture['cases'][2]['json']);

        self::assertSame($fixture['compare']['sameObject'], RelativePosition::compare($root, $root));
        self::assertSame($fixture['compare']['sameJSON'], RelativePosition::compare($root, $sameRoot));
        self::assertSame($fixture['compare']['differentAssoc'], RelativePosition::compare($root, $differentAssoc));
        self::assertSame($fixture['compare']['differentKind'], RelativePosition::compare($root, $differentKind));
        self::assertSame($fixture['compare']['nullLeft'], RelativePosition::compare(null, $root));
        self::assertSame($fixture['compare']['nullBoth'], RelativePosition::compare(null, null));
    }

    public function testNativeRootTypeRelativePositionAPIsMatchYjsFixtures(): void
    {
        foreach ($this->loadFixture()['typeIndexCases'] as $case) {
            $position = $this->nativeRelativePositionForCase($case['name']);

            self::assertSame($case['json'], $position->toJSON(), $case['name']);
            self::assertSame(base64_decode($case['encoded'], true), $position->encode(), $case['name']);
        }
    }

    public function testNativeNestedTypeRelativePositionAPIsMatchYjsFixtures(): void
    {
        foreach ($this->loadFixture()['nestedTypeIndexCases'] as $case) {
            $position = $this->nativeNestedRelativePositionForCase($case['name']);

            self::assertSame($case['json'], $position->toJSON(), $case['name']);
            self::assertSame(base64_decode($case['encoded'], true), $position->encode(), $case['name']);
        }
    }

    public function testNativeRootTypeAbsolutePositionResolutionMatchesYjsFixtures(): void
    {
        foreach ($this->loadFixture()['absoluteCases'] as $case) {
            [$doc, $position] = $this->nativeAbsolutePositionScenario($case['name'], $case['relativeEncoded']);
            $absolute = $doc->absolutePositionFromRelativePosition($position);

            self::assertSame($case['absolute'], $absolute?->toArray(), $case['name']);
        }
    }

    public function testFromJSONDefaultsAssocToZero(): void
    {
        $position = RelativePosition::fromJSON(['tname' => 'content']);

        self::assertSame(['tname' => 'content', 'assoc' => 0], $position->toJSON());
        self::assertSame(0, $position->assoc());
        self::assertSame('content', $position->typeName());
        self::assertSame('content', $position->tname());
    }

    public function testDecodeRelativePositionRejectsTrailingBytes(): void
    {
        $encoded = base64_decode($this->loadFixture()['cases'][0]['encoded'], true);
        self::assertIsString($encoded);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Relative position contains trailing bytes.');

        RelativePosition::decode($encoded . "\x00");
    }

    public function testDecodeRelativePositionRejectsUnsupportedReferenceType(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Unsupported relative position reference type.');

        RelativePosition::decode("\x03");
    }

    public function testEncodeRelativePositionRequiresAReference(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Relative position must reference an item, type name, or type ID.');

        (new RelativePosition())->encode();
    }

    /**
     * @return array{
     *     cases: list<array{name: string, json: array<string, mixed>, encoded: string, decodedJson: array<string, mixed>}>,
     *     compare: array<string, bool>,
     *     typeIndexCases: list<array{name: string, json: array<string, mixed>, encoded: string, decodedJson: array<string, mixed>}>,
     *     nestedTypeIndexCases: list<array{name: string, json: array<string, mixed>, encoded: string, decodedJson: array<string, mixed>}>,
     *     absoluteCases: list<array{name: string, relativeJson: array<string, mixed>, relativeEncoded: string, absolute: array{typeName?: string, typeId?: array{client: int, clock: int}, index: int, assoc: int}|null}>
     * }
     */
    private function loadFixture(): array
    {
        self::assertFileExists(self::FIXTURE_PATH, 'Run `npm run fixtures` before PHPUnit.');

        $decoded = json_decode((string) file_get_contents(self::FIXTURE_PATH), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function nativeRelativePositionForCase(string $name): RelativePosition
    {
        if (str_starts_with($name, 'text-')) {
            $doc = new YDoc(281);
            $text = $doc->getText('content');
            $text->insert(0, 'Hello');

            return match ($name) {
                'text-start-left-assoc' => $text->relativePositionAt(0, -1),
                'text-middle-default-assoc' => $text->relativePositionAt(2),
                'text-end-default-assoc' => $text->relativePositionAt($text->length()),
                'text-end-left-assoc' => $text->relativePositionAt($text->length(), -1),
                default => throw new \InvalidArgumentException('Unknown relative position fixture case.'),
            };
        }

        if (str_starts_with($name, 'array-')) {
            $doc = new YDoc(282);
            $array = $doc->getArray('items');
            $array->insert(0, ['A', 'B', 'C']);

            return match ($name) {
                'array-middle-default-assoc' => $array->relativePositionAt(1),
                'array-end-left-assoc' => $array->relativePositionAt($array->length(), -1),
                default => throw new \InvalidArgumentException('Unknown relative position fixture case.'),
            };
        }

        if (str_starts_with($name, 'xml-')) {
            $doc = new YDoc(283);
            $xml = $doc->getXmlFragment('xml');
            $xml->insertText(0, 'A');
            $xml->insertElement(1, 'p');

            return match ($name) {
                'xml-middle-default-assoc' => $xml->relativePositionAt(1),
                'xml-end-left-assoc' => $xml->relativePositionAt($xml->length(), -1),
                default => throw new \InvalidArgumentException('Unknown relative position fixture case.'),
            };
        }

        throw new \InvalidArgumentException('Unknown relative position fixture case.');
    }

    private function nativeNestedRelativePositionForCase(string $name): RelativePosition
    {
        if ($name === 'nested-text-middle-default-assoc') {
            $doc = new YDoc(301);
            $text = $doc->getArray('items')->insertText(0);
            $text->insert(0, 'Hi');

            return $text->relativePositionAt(1);
        }

        if ($name === 'nested-array-end-left-assoc') {
            $doc = new YDoc(302);
            $array = $doc->getMap('map')->setArray('items');
            $array->insert(0, ['A', 'B']);

            return $array->relativePositionAt($array->length(), -1);
        }

        if ($name === 'map-text-middle-default-assoc') {
            $doc = new YDoc(305);
            $text = $doc->getMap('map')->setText('body');
            $text->insert(0, 'Hi');

            return $text->relativePositionAt(1);
        }

        if ($name === 'xml-text-middle-default-assoc') {
            $doc = new YDoc(303);
            $xmlText = $doc->getXmlFragment('xml')->insertText(0, 'Hi');

            return $xmlText->relativePositionAt(1);
        }

        if ($name === 'xml-element-middle-default-assoc') {
            $doc = new YDoc(304);
            $element = $doc->getXmlFragment('xml')->insertElement(0, 'p');
            $element->insertText(0, 'A');
            $element->insertElement(1, 'span');

            return $element->relativePositionAt(1);
        }

        if ($name === 'nested-xml-fragment-middle-default-assoc') {
            $doc = new YDoc(313);
            $fragment = $doc->getArray('items')->insertXmlFragment(0);
            $fragment->insertText(0, 'A');
            $fragment->insertElement(1, 'p');

            return $fragment->relativePositionAt(1);
        }

        if ($name === 'map-xml-fragment-middle-default-assoc') {
            $doc = new YDoc(314);
            $fragment = $doc->getMap('map')->setXmlFragment('xml');
            $fragment->insertText(0, 'A');
            $fragment->insertElement(1, 'p');

            return $fragment->relativePositionAt(1);
        }

        if ($name === 'xml-hook-text-middle-default-assoc') {
            $doc = new YDoc(317);
            $hook = $doc->getXmlFragment('xml')->insertHook(0, 'mention');
            $text = $hook->setText('body');
            $text->insert(0, 'Hi');

            return $text->relativePositionAt(1);
        }

        if ($name === 'xml-hook-array-end-left-assoc') {
            $doc = new YDoc(318);
            $hook = $doc->getXmlFragment('xml')->insertHook(0, 'mention');
            $array = $hook->setArray('items');
            $array->insert(0, ['A', 'B']);

            return $array->relativePositionAt($array->length(), -1);
        }

        if ($name === 'xml-hook-xml-element-middle-default-assoc') {
            $doc = new YDoc(319);
            $hook = $doc->getXmlFragment('xml')->insertHook(0, 'mention');
            $element = $hook->setXmlElement('element', 'p');
            $element->insertText(0, 'A');
            $element->insertElement(1, 'span');

            return $element->relativePositionAt(1);
        }

        if ($name === 'xml-hook-xml-fragment-middle-default-assoc') {
            $doc = new YDoc(320);
            $hook = $doc->getXmlFragment('xml')->insertHook(0, 'mention');
            $fragment = $hook->setXmlFragment('fragment');
            $fragment->insertText(0, 'A');
            $fragment->insertElement(1, 'p');

            return $fragment->relativePositionAt(1);
        }

        if ($name === 'xml-element-attribute-text-middle-default-assoc') {
            $doc = new YDoc(325);
            $element = $doc->getXmlFragment('xml')->insertElement(0, 'p');
            $text = $element->setText('body');
            $text->insert(0, 'Hi');

            return $text->relativePositionAt(1);
        }

        if ($name === 'xml-element-attribute-xml-element-middle-default-assoc') {
            $doc = new YDoc(326);
            $element = $doc->getXmlFragment('xml')->insertElement(0, 'p');
            $inline = $element->setXmlElement('inline', 'span');
            $inline->insertText(0, 'A');
            $inline->insertElement(1, 'em');

            return $inline->relativePositionAt(1);
        }

        if ($name === 'xml-text-attribute-text-middle-default-assoc') {
            $doc = new YDoc(327);
            $xmlText = $doc->getXmlFragment('xml')->insertText(0, 'Xml');
            $text = $xmlText->setText('body');
            $text->insert(0, 'Hi');

            return $text->relativePositionAt(1);
        }

        if ($name === 'xml-text-attribute-xml-element-middle-default-assoc') {
            $doc = new YDoc(328);
            $xmlText = $doc->getXmlFragment('xml')->insertText(0, 'Xml');
            $inline = $xmlText->setXmlElement('inline', 'span');
            $inline->insertText(0, 'A');
            $inline->insertElement(1, 'em');

            return $inline->relativePositionAt(1);
        }

        throw new \InvalidArgumentException('Unknown nested relative position fixture case.');
    }

    /**
     * @return array{YDoc, RelativePosition}
     */
    private function nativeAbsolutePositionScenario(string $name, string $encodedPosition): array
    {
        $position = RelativePosition::decode((string) base64_decode($encodedPosition, true));

        if (preg_match('/^(text|array|xml|nested-array|xml-element|nested-xml-fragment|map-xml-fragment|xml-hook-text|xml-hook-xml-element|xml-hook-xml-fragment|xml-text-attribute-text|xml-text-attribute-xml-element)-deleted-target-reinsert-assoc-(-?\d+)$/', $name, $matches) === 1) {
            return $this->deletedTargetReinsertAbsoluteScenario($matches[1], (int) $matches[2], $position);
        }

        return match ($name) {
            'text-insert-before-item' => $this->textInsertBeforeAbsoluteScenario($position),
            'text-end-follows-insert' => $this->textEndAbsoluteScenario($position),
            'array-deleted-target' => $this->arrayDeletedTargetAbsoluteScenario($position),
            'xml-insert-before-item' => $this->xmlInsertBeforeAbsoluteScenario($position),
            'xml-hook-insert-before-item' => $this->xmlHookInsertBeforeAbsoluteScenario($position),
            'nested-text-insert-before-item' => $this->nestedTextInsertBeforeAbsoluteScenario($position),
            'nested-array-deleted-target' => $this->nestedArrayDeletedTargetAbsoluteScenario($position),
            'map-text-insert-before-item' => $this->mapTextInsertBeforeAbsoluteScenario($position),
            'xml-text-insert-before-item' => $this->xmlTextInsertBeforeAbsoluteScenario($position),
            'xml-element-insert-before-item' => $this->xmlElementInsertBeforeAbsoluteScenario($position),
            'xml-element-hook-insert-before-item' => $this->xmlElementHookInsertBeforeAbsoluteScenario($position),
            'nested-xml-fragment-insert-before-item' => $this->nestedXmlFragmentInsertBeforeAbsoluteScenario($position),
            'map-xml-fragment-insert-before-item' => $this->mapXmlFragmentInsertBeforeAbsoluteScenario($position),
            'xml-hook-text-insert-before-item' => $this->xmlHookTextInsertBeforeAbsoluteScenario($position),
            'xml-hook-array-deleted-target' => $this->xmlHookArrayDeletedTargetAbsoluteScenario($position),
            'xml-hook-xml-element-insert-before-item' => $this->xmlHookXmlElementInsertBeforeAbsoluteScenario($position),
            'xml-hook-xml-fragment-insert-before-item' => $this->xmlHookXmlFragmentInsertBeforeAbsoluteScenario($position),
            'xml-text-attribute-text-insert-before-item' => $this->xmlTextAttributeTextInsertBeforeAbsoluteScenario($position),
            'xml-text-attribute-xml-element-insert-before-item' => $this->xmlTextAttributeXmlElementInsertBeforeAbsoluteScenario($position),
            'missing-item' => [new YDoc(309), $position],
            'missing-type' => [new YDoc(310), $position],
            default => throw new \InvalidArgumentException('Unknown absolute position fixture case.'),
        };
    }

    /**
     * @return array{YDoc, RelativePosition}
     */
    private function textInsertBeforeAbsoluteScenario(RelativePosition $position): array
    {
        $doc = new YDoc(291);
        $text = $doc->getText('content');
        $text->insert(0, 'Hello');
        $text->insert(0, 'X');

        return [$doc, $position];
    }

    /**
     * @return array{YDoc, RelativePosition}
     */
    private function textEndAbsoluteScenario(RelativePosition $position): array
    {
        $doc = new YDoc(292);
        $text = $doc->getText('content');
        $text->insert(0, 'Hello');
        $text->insert($text->length(), '!');

        return [$doc, $position];
    }

    /**
     * @return array{YDoc, RelativePosition}
     */
    private function arrayDeletedTargetAbsoluteScenario(RelativePosition $position): array
    {
        $doc = new YDoc(293);
        $array = $doc->getArray('items');
        $array->insert(0, ['A', 'B', 'C']);
        $array->delete(1, 1);

        return [$doc, $position];
    }

    /**
     * @return array{YDoc, RelativePosition}
     */
    private function xmlInsertBeforeAbsoluteScenario(RelativePosition $position): array
    {
        $doc = new YDoc(294);
        $xml = $doc->getXmlFragment('xml');
        $xml->insertText(0, 'A');
        $xml->insertElement(1, 'p');
        $xml->insertText(0, 'B');

        return [$doc, $position];
    }

    /**
     * @return array{YDoc, RelativePosition}
     */
    private function xmlHookInsertBeforeAbsoluteScenario(RelativePosition $position): array
    {
        $doc = new YDoc(315);
        $xml = $doc->getXmlFragment('xml');
        $xml->insertText(0, 'A');
        $xml->insertHook(1, 'mention');
        $xml->insertText(1, 'B');

        return [$doc, $position];
    }

    /**
     * @return array{YDoc, RelativePosition}
     */
    private function nestedTextInsertBeforeAbsoluteScenario(RelativePosition $position): array
    {
        $doc = new YDoc(305);
        $text = $doc->getArray('items')->insertText(0);
        $text->insert(0, 'Hi');
        $text->insert(0, 'X');

        return [$doc, $position];
    }

    /**
     * @return array{YDoc, RelativePosition}
     */
    private function nestedArrayDeletedTargetAbsoluteScenario(RelativePosition $position): array
    {
        $doc = new YDoc(306);
        $array = $doc->getMap('map')->setArray('items');
        $array->insert(0, ['A', 'B', 'C']);
        $array->delete(1, 1);

        return [$doc, $position];
    }

    /**
     * @return array{YDoc, RelativePosition}
     */
    private function mapTextInsertBeforeAbsoluteScenario(RelativePosition $position): array
    {
        $doc = new YDoc(311);
        $text = $doc->getMap('map')->setText('body');
        $text->insert(0, 'Hi');
        $text->insert(0, 'X');

        return [$doc, $position];
    }

    /**
     * @return array{YDoc, RelativePosition}
     */
    private function xmlTextInsertBeforeAbsoluteScenario(RelativePosition $position): array
    {
        $doc = new YDoc(307);
        $xmlText = $doc->getXmlFragment('xml')->insertText(0, 'Hi');
        $xmlText->insert(0, 'X');

        return [$doc, $position];
    }

    /**
     * @return array{YDoc, RelativePosition}
     */
    private function xmlElementInsertBeforeAbsoluteScenario(RelativePosition $position): array
    {
        $doc = new YDoc(308);
        $element = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $element->insertText(0, 'A');
        $element->insertElement(1, 'span');
        $element->insertText(0, 'B');

        return [$doc, $position];
    }

    /**
     * @return array{YDoc, RelativePosition}
     */
    private function xmlElementHookInsertBeforeAbsoluteScenario(RelativePosition $position): array
    {
        $doc = new YDoc(316);
        $element = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $element->insertText(0, 'A');
        $element->insertHook(1, 'mention');
        $element->insertText(1, 'B');

        return [$doc, $position];
    }

    /**
     * @return array{YDoc, RelativePosition}
     */
    private function nestedXmlFragmentInsertBeforeAbsoluteScenario(RelativePosition $position): array
    {
        $doc = new YDoc(313);
        $fragment = $doc->getArray('items')->insertXmlFragment(0);
        $fragment->insertText(0, 'A');
        $fragment->insertElement(1, 'p');
        $fragment->insertText(0, 'B');

        return [$doc, $position];
    }

    /**
     * @return array{YDoc, RelativePosition}
     */
    private function mapXmlFragmentInsertBeforeAbsoluteScenario(RelativePosition $position): array
    {
        $doc = new YDoc(314);
        $fragment = $doc->getMap('map')->setXmlFragment('xml');
        $fragment->insertText(0, 'A');
        $fragment->insertElement(1, 'p');
        $fragment->insertText(0, 'B');

        return [$doc, $position];
    }

    /**
     * @return array{YDoc, RelativePosition}
     */
    private function xmlHookTextInsertBeforeAbsoluteScenario(RelativePosition $position): array
    {
        $doc = new YDoc(321);
        $hook = $doc->getXmlFragment('xml')->insertHook(0, 'mention');
        $text = $hook->setText('body');
        $text->insert(0, 'Hi');
        $text->insert(0, 'X');

        return [$doc, $position];
    }

    /**
     * @return array{YDoc, RelativePosition}
     */
    private function xmlHookArrayDeletedTargetAbsoluteScenario(RelativePosition $position): array
    {
        $doc = new YDoc(322);
        $hook = $doc->getXmlFragment('xml')->insertHook(0, 'mention');
        $array = $hook->setArray('items');
        $array->insert(0, ['A', 'B', 'C']);
        $array->delete(1, 1);

        return [$doc, $position];
    }

    /**
     * @return array{YDoc, RelativePosition}
     */
    private function xmlHookXmlElementInsertBeforeAbsoluteScenario(RelativePosition $position): array
    {
        $doc = new YDoc(323);
        $hook = $doc->getXmlFragment('xml')->insertHook(0, 'mention');
        $element = $hook->setXmlElement('element', 'p');
        $element->insertText(0, 'A');
        $element->insertElement(1, 'span');
        $element->insertText(0, 'B');

        return [$doc, $position];
    }

    /**
     * @return array{YDoc, RelativePosition}
     */
    private function xmlHookXmlFragmentInsertBeforeAbsoluteScenario(RelativePosition $position): array
    {
        $doc = new YDoc(324);
        $hook = $doc->getXmlFragment('xml')->insertHook(0, 'mention');
        $fragment = $hook->setXmlFragment('fragment');
        $fragment->insertText(0, 'A');
        $fragment->insertElement(1, 'p');
        $fragment->insertText(0, 'B');

        return [$doc, $position];
    }

    /**
     * @return array{YDoc, RelativePosition}
     */
    private function xmlTextAttributeTextInsertBeforeAbsoluteScenario(RelativePosition $position): array
    {
        $doc = new YDoc(329);
        $xmlText = $doc->getXmlFragment('xml')->insertText(0, 'Xml');
        $text = $xmlText->setText('body');
        $text->insert(0, 'Hi');
        $text->insert(0, 'X');

        return [$doc, $position];
    }

    /**
     * @return array{YDoc, RelativePosition}
     */
    private function xmlTextAttributeXmlElementInsertBeforeAbsoluteScenario(RelativePosition $position): array
    {
        $doc = new YDoc(330);
        $xmlText = $doc->getXmlFragment('xml')->insertText(0, 'Xml');
        $inline = $xmlText->setXmlElement('inline', 'span');
        $inline->insertText(0, 'A');
        $inline->insertElement(1, 'em');
        $inline->insertText(0, 'B');

        return [$doc, $position];
    }

    /**
     * @return array{YDoc, RelativePosition}
     */
    private function deletedTargetReinsertAbsoluteScenario(string $kind, int $assoc, RelativePosition $position): array
    {
        if ($kind === 'text') {
            $doc = new YDoc(431 + $assoc);
            $text = $doc->getText('content');
            $text->insert(0, 'ABC');
            $text->delete(1, 1);
            $text->insert(1, 'X');

            return [$doc, $position];
        }

        if ($kind === 'array') {
            $doc = new YDoc(441 + $assoc);
            $array = $doc->getArray('items');
            $array->insert(0, ['A', 'B', 'C']);
            $array->delete(1, 1);
            $array->insert(1, ['X']);

            return [$doc, $position];
        }

        if ($kind === 'xml') {
            $doc = new YDoc(451 + $assoc);
            $xml = $doc->getXmlFragment('xml');
            $xml->insertElement(0, 'a');
            $xml->insertElement(1, 'b');
            $xml->insertElement(2, 'c');
            $xml->delete(1, 1);
            $xml->insertElement(1, 'x');

            return [$doc, $position];
        }

        if ($kind === 'nested-array') {
            $doc = new YDoc(461 + $assoc);
            $array = $doc->getMap('map')->setArray('items');
            $array->insert(0, ['A', 'B', 'C']);
            $array->delete(1, 1);
            $array->insert(1, ['X']);

            return [$doc, $position];
        }

        if ($kind === 'xml-element') {
            $doc = new YDoc(471 + $assoc);
            $element = $doc->getXmlFragment('xml')->insertElement(0, 'p');
            $element->insertElement(0, 'a');
            $element->insertElement(1, 'b');
            $element->insertElement(2, 'c');
            $element->delete(1, 1);
            $element->insertElement(1, 'x');

            return [$doc, $position];
        }

        if ($kind === 'nested-xml-fragment') {
            $doc = new YDoc(481 + $assoc);
            $fragment = $doc->getArray('items')->insertXmlFragment(0);
            $fragment->insertElement(0, 'a');
            $fragment->insertElement(1, 'b');
            $fragment->insertElement(2, 'c');
            $fragment->delete(1, 1);
            $fragment->insertElement(1, 'x');

            return [$doc, $position];
        }

        if ($kind === 'map-xml-fragment') {
            $doc = new YDoc(491 + $assoc);
            $fragment = $doc->getMap('map')->setXmlFragment('xml');
            $fragment->insertElement(0, 'a');
            $fragment->insertElement(1, 'b');
            $fragment->insertElement(2, 'c');
            $fragment->delete(1, 1);
            $fragment->insertElement(1, 'x');

            return [$doc, $position];
        }

        if ($kind === 'xml-hook-text') {
            $doc = new YDoc(501 + $assoc);
            $hook = $doc->getXmlFragment('xml')->insertHook(0, 'mention');
            $text = $hook->setText('body');
            $text->insert(0, 'ABC');
            $text->delete(1, 1);
            $text->insert(1, 'X');

            return [$doc, $position];
        }

        if ($kind === 'xml-hook-xml-element') {
            $doc = new YDoc(511 + $assoc);
            $hook = $doc->getXmlFragment('xml')->insertHook(0, 'mention');
            $element = $hook->setXmlElement('element', 'p');
            $element->insertElement(0, 'a');
            $element->insertElement(1, 'b');
            $element->insertElement(2, 'c');
            $element->delete(1, 1);
            $element->insertElement(1, 'x');

            return [$doc, $position];
        }

        if ($kind === 'xml-hook-xml-fragment') {
            $doc = new YDoc(521 + $assoc);
            $hook = $doc->getXmlFragment('xml')->insertHook(0, 'mention');
            $fragment = $hook->setXmlFragment('fragment');
            $fragment->insertElement(0, 'a');
            $fragment->insertElement(1, 'b');
            $fragment->insertElement(2, 'c');
            $fragment->delete(1, 1);
            $fragment->insertElement(1, 'x');

            return [$doc, $position];
        }

        if ($kind === 'xml-text-attribute-text') {
            $doc = new YDoc(531 + $assoc);
            $xmlText = $doc->getXmlFragment('xml')->insertText(0, 'Xml');
            $text = $xmlText->setText('body');
            $text->insert(0, 'ABC');
            $text->delete(1, 1);
            $text->insert(1, 'X');

            return [$doc, $position];
        }

        if ($kind === 'xml-text-attribute-xml-element') {
            $doc = new YDoc(541 + $assoc);
            $xmlText = $doc->getXmlFragment('xml')->insertText(0, 'Xml');
            $element = $xmlText->setXmlElement('element', 'p');
            $element->insertElement(0, 'a');
            $element->insertElement(1, 'b');
            $element->insertElement(2, 'c');
            $element->delete(1, 1);
            $element->insertElement(1, 'x');

            return [$doc, $position];
        }

        throw new \InvalidArgumentException('Unknown deleted target reinsert fixture case.');
    }
}
