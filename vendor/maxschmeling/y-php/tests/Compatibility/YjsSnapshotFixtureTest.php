<?php

declare(strict_types=1);

namespace Yjs\Tests\Compatibility;

use PHPUnit\Framework\TestCase;
use Yjs\Snapshot;
use Yjs\YArray;
use Yjs\YDoc;
use Yjs\YMap;
use Yjs\YNestedArray;
use Yjs\YNestedMap;
use Yjs\YNestedText;
use Yjs\YNestedXmlFragment;
use Yjs\YText;
use Yjs\YXmlElement;
use Yjs\YXmlFragment;
use Yjs\YXmlText;

final class YjsSnapshotFixtureTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__ . '/../../fixtures/generated/yjs-13.6.31/snapshots.json';

    public function testDecodeSnapshotV1MatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $snapshot = base64_decode($fixture['snapshotV1'], true);
        self::assertIsString($snapshot);

        self::assertSame($this->normalizeSnapshot($fixture['decodedV1']), Snapshot::decodeV1($snapshot)->toArray());
    }

    public function testDecodeSnapshotV2MatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $snapshot = base64_decode($fixture['snapshotV2'], true);
        self::assertIsString($snapshot);

        self::assertSame($this->normalizeSnapshot($fixture['decodedV2']), Snapshot::decodeV2($snapshot)->toArray());
    }

    public function testEncodeSnapshotV1MatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $snapshot = $this->snapshotFromFixture($fixture['decodedV1']);

        self::assertSame(base64_decode($fixture['snapshotV1'], true), $snapshot->encodeV1());
    }

    public function testEncodeSnapshotV2MatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $snapshot = $this->snapshotFromFixture($fixture['decodedV2']);

        self::assertSame(base64_decode($fixture['snapshotV2'], true), $snapshot->encodeV2());
    }

    public function testEmptySnapshotMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $empty = Snapshot::empty();

        self::assertSame(base64_decode($fixture['emptyV1'], true), $empty->encodeV1());
        self::assertSame(base64_decode($fixture['emptyV2'], true), $empty->encodeV2());
        self::assertSame($this->normalizeSnapshot($fixture['emptyDecodedV1']), Snapshot::decodeV1($empty->encodeV1())->toArray());
        self::assertSame($this->normalizeSnapshot($fixture['emptyDecodedV2']), Snapshot::decodeV2($empty->encodeV2())->toArray());
    }

    public function testNativeDocumentSnapshotMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc(280);
        $doc->getText('content')->insert(0, 'ABCDE');
        $doc->getText('content')->delete(1, 2);
        $doc->getMap('map')->set('title', 'Snapshot');

        $snapshot = $doc->snapshot();

        self::assertSame(base64_decode($fixture['snapshotV1'], true), $snapshot->encodeV1());
        self::assertSame(base64_decode($fixture['snapshotV2'], true), $snapshot->encodeV2());
        self::assertSame($this->normalizeSnapshot($fixture['decodedV1']), $snapshot->toArray());
    }

    public function testEqualSnapshotsMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $snapshotV1 = $this->snapshotFromFixture($fixture['decodedV1']);
        $snapshotV2 = $this->snapshotFromFixture($fixture['decodedV2']);
        $altered = new Snapshot($snapshotV1->deleteSet(), [280 => 1]);

        self::assertSame($fixture['equalDecoded'], $snapshotV1->equals($snapshotV2));
        self::assertSame($fixture['equalAltered'], $snapshotV1->equals($altered));
    }

    public function testSnapshotContainsUpdateV1MatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $snapshot = $this->snapshotFromFixture($fixture['decodedV1']);
        $contains = $fixture['contains'];

        $contained = base64_decode($contains['containedUpdateV1'], true);
        $future = base64_decode($contains['futureUpdateV1'], true);
        $extraDelete = base64_decode($contains['extraDeleteUpdateV1'], true);
        self::assertIsString($contained);
        self::assertIsString($future);
        self::assertIsString($extraDelete);

        self::assertSame($contains['contained'], $snapshot->containsUpdateV1($contained));
        self::assertSame($contains['future'], $snapshot->containsUpdateV1($future));
        self::assertSame($contains['extraDelete'], $snapshot->containsUpdateV1($extraDelete));
    }

    public function testSnapshotContainsUpdateV2MatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $snapshot = $this->snapshotFromFixture($fixture['decodedV2']);
        $contains = $fixture['contains'];

        $contained = base64_decode($contains['containedUpdateV2'], true);
        $future = base64_decode($contains['futureUpdateV2'], true);
        $extraDelete = base64_decode($contains['extraDeleteUpdateV2'], true);
        self::assertIsString($contained);
        self::assertIsString($future);
        self::assertIsString($extraDelete);

        self::assertSame($contains['contained'], $snapshot->containsUpdateV2($contained));
        self::assertSame($contains['future'], $snapshot->containsUpdateV2($future));
        self::assertSame($contains['extraDelete'], $snapshot->containsUpdateV2($extraDelete));
    }

    public function testCreateDocFromSnapshotMatchesYjsFixtureAfterV1SourceUpdate(): void
    {
        $fixture = $this->loadFixture()['restore'];
        $source = new YDoc();
        $update = base64_decode($fixture['sourceUpdateV1'], true);
        self::assertIsString($update);
        $source->applyUpdateV1($update);

        $this->assertSnapshotRestoreMatchesFixture($source, $fixture);
    }

    public function testCreateDocFromSnapshotMatchesYjsFixtureAfterV2SourceUpdate(): void
    {
        $fixture = $this->loadFixture()['restore'];
        $source = new YDoc();
        $update = base64_decode($fixture['sourceUpdateV2'], true);
        self::assertIsString($update);
        $source->applyUpdateV2($update);

        $this->assertSnapshotRestoreMatchesFixture($source, $fixture);
    }

    public function testSnapshotReadHelpersMatchYjsFixtureAfterV1SourceUpdate(): void
    {
        $fixture = $this->loadFixture()['reads'];
        $source = new YDoc();
        $update = base64_decode($fixture['sourceUpdateV1'], true);
        self::assertIsString($update);
        $source->applyUpdateV1($update);

        $this->assertSnapshotReadsMatchFixture($source, $fixture);
    }

    public function testSnapshotReadHelpersMatchYjsFixtureAfterV2SourceUpdate(): void
    {
        $fixture = $this->loadFixture()['reads'];
        $source = new YDoc();
        $update = base64_decode($fixture['sourceUpdateV2'], true);
        self::assertIsString($update);
        $source->applyUpdateV2($update);

        $this->assertSnapshotReadsMatchFixture($source, $fixture);
    }

    public function testNestedSnapshotReadHelpersMatchYjsFixture(): void
    {
        $fixture = $this->loadFixture()['reads'];
        [$before, $after, $nestedArray, $nestedMap, $nestedText, $nestedXml, $nestedXmlText, $nestedXmlElement, $nestedXmlElementText, $mapText, $mapXml, $mapXmlText, $mapXmlElement, $mapXmlElementText] = $this->nativeSnapshotReadScenario();

        $this->assertNestedSnapshotReadValuesMatchFixture($fixture['before'], $nestedArray, $nestedMap, $nestedText, $before);
        $this->assertEmbeddedXmlSnapshotReadValuesMatchFixture($fixture['before'], 'nestedXml', $nestedXml, $nestedXmlText, $nestedXmlElement, $nestedXmlElementText, $before);
        $this->assertMapTextSnapshotReadValuesMatchFixture($fixture['before'], $mapText, $before);
        $this->assertEmbeddedXmlSnapshotReadValuesMatchFixture($fixture['before'], 'mapXml', $mapXml, $mapXmlText, $mapXmlElement, $mapXmlElementText, $before);
        $this->assertNestedSnapshotReadValuesMatchFixture($fixture['after'], $nestedArray, $nestedMap, $nestedText, $after);
        $this->assertEmbeddedXmlSnapshotReadValuesMatchFixture($fixture['after'], 'nestedXml', $nestedXml, $nestedXmlText, $nestedXmlElement, $nestedXmlElementText, $after);
        $this->assertMapTextSnapshotReadValuesMatchFixture($fixture['after'], $mapText, $after);
        $this->assertEmbeddedXmlSnapshotReadValuesMatchFixture($fixture['after'], 'mapXml', $mapXml, $mapXmlText, $mapXmlElement, $mapXmlElementText, $after);
    }

    public function testXmlSnapshotReadHelpersMatchYjsFixture(): void
    {
        $fixture = $this->loadFixture()['reads'];
        [$before, $after, $fragment, $paragraph, $text] = $this->nativeXmlSnapshotReadScenario();

        $this->assertXmlSnapshotReadValuesMatchFixture($fixture['before'], $fragment, $paragraph, $text, $before);
        $this->assertXmlSnapshotReadValuesMatchFixture($fixture['after'], $fragment, $paragraph, $text, $after);
    }

    public function testDecodeSnapshotRejectsTrailingBytes(): void
    {
        $fixture = $this->loadFixture();
        $snapshot = base64_decode($fixture['snapshotV1'], true);
        self::assertIsString($snapshot);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Snapshot contains trailing bytes.');

        Snapshot::decodeV1($snapshot . "\x00");
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertSnapshotRestoreMatchesFixture(YDoc $source, array $fixture): void
    {
        $beforeDelete = $this->restoreFromEncodedSnapshot($source, $fixture['beforeDeleteSnapshotV1']);
        $afterDelete = $this->restoreFromEncodedSnapshot($source, $fixture['afterDeleteSnapshotV1']);
        $partial = $this->restoreFromEncodedSnapshot($source, $fixture['partialSnapshotV1']);

        self::assertSame($this->sortAssociativeArrays($fixture['beforeDeleteJson']), $this->sortAssociativeArrays($beforeDelete->toJSON()));
        self::assertSame($this->sortAssociativeArrays($fixture['afterDeleteJson']), $this->sortAssociativeArrays($afterDelete->toJSON()));
        self::assertSame($this->sortAssociativeArrays($fixture['partialJson']), $this->sortAssociativeArrays($partial->toJSON()));
        self::assertSame(base64_decode($fixture['beforeDeleteStateVectorV1'], true), $beforeDelete->encodeStateVector());
        self::assertSame(base64_decode($fixture['afterDeleteStateVectorV1'], true), $afterDelete->encodeStateVector());
        self::assertSame(base64_decode($fixture['partialStateVectorV1'], true), $partial->encodeStateVector());
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertSnapshotReadsMatchFixture(YDoc $source, array $fixture): void
    {
        $before = $this->snapshotFromEncodedV1($fixture['beforeSnapshotV1']);
        $after = $this->snapshotFromEncodedV1($fixture['afterSnapshotV1']);
        $items = $source->getArray('items');
        $meta = $source->getMap('meta');
        $content = $source->getText('content');
        $mapText = $meta->getText('body');
        $mapXml = $meta->getXmlFragment('xmlBody');
        self::assertNotNull($mapText);
        self::assertNotNull($mapXml);
        $mapXmlText = $mapXml->get(0);
        $mapXmlElement = $mapXml->get(1);
        self::assertInstanceOf(YXmlText::class, $mapXmlText);
        self::assertInstanceOf(YXmlElement::class, $mapXmlElement);
        $mapXmlElementText = $mapXmlElement->get(0);
        self::assertInstanceOf(YXmlText::class, $mapXmlElementText);

        $this->assertSnapshotReadValuesMatchFixture($fixture['before'], $items, $meta, $content, $before);
        $this->assertMapTextSnapshotReadValuesMatchFixture($fixture['before'], $mapText, $before);
        $this->assertEmbeddedXmlSnapshotReadValuesMatchFixture($fixture['before'], 'mapXml', $mapXml, $mapXmlText, $mapXmlElement, $mapXmlElementText, $before);
        $this->assertSnapshotReadValuesMatchFixture($fixture['after'], $items, $meta, $content, $after);
        $this->assertMapTextSnapshotReadValuesMatchFixture($fixture['after'], $mapText, $after);
        $this->assertEmbeddedXmlSnapshotReadValuesMatchFixture($fixture['after'], 'mapXml', $mapXml, $mapXmlText, $mapXmlElement, $mapXmlElementText, $after);
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertSnapshotReadValuesMatchFixture(
        array $fixture,
        YArray $items,
        YMap $meta,
        YText $content,
        Snapshot $snapshot
    ): void {
        self::assertSame($this->sortAssociativeArrays($fixture['array']), $this->sortAssociativeArrays($items->toArraySnapshot($snapshot)));
        self::assertSame($this->sortAssociativeArrays($fixture['array']), $this->sortAssociativeArrays($items->toJSONSnapshot($snapshot)));
        self::assertSame($fixture['arrayLength'], $items->lengthSnapshot($snapshot));
        self::assertSame($this->sortAssociativeArrays($fixture['arrayFirst']), $this->sortAssociativeArrays($items->getSnapshot(0, $snapshot)));
        self::assertSame($this->sortAssociativeArrays($fixture['arraySlice']), $this->sortAssociativeArrays($items->sliceSnapshot($snapshot, 1, -1)));
        self::assertSame($this->sortAssociativeArrays($fixture['mapAll']), $this->sortAssociativeArrays($meta->getAllSnapshot($snapshot)));
        self::assertSame($this->sortAssociativeArrays($fixture['mapAll']), $this->sortAssociativeArrays($meta->toJSONSnapshot($snapshot)));
        self::assertSame($fixture['mapSize'], $meta->sizeSnapshot($snapshot));
        self::assertSame($fixture['mapHasCount'], $meta->hasSnapshot('count', $snapshot));
        self::assertSame($fixture['mapHasExtra'], $meta->hasSnapshot('extra', $snapshot));
        self::assertSame($fixture['mapTitle'], $meta->getSnapshot('title', $snapshot));
        self::assertSame($fixture['mapCount'], $meta->getSnapshot('count', $snapshot));
        self::assertSame($fixture['mapExtra'], $meta->getSnapshot('extra', $snapshot));
        self::assertSame($fixture['text'], $content->toStringSnapshot($snapshot));
        self::assertSame($fixture['text'], $content->toJSONSnapshot($snapshot));
        self::assertSame($fixture['textLength'], $content->lengthSnapshot($snapshot));
        self::assertSame($fixture['textSlice'], $content->sliceSnapshot($snapshot, 1, -1));
        self::assertSame($this->sortAssociativeArrays($fixture['textDelta']), $this->sortAssociativeArrays($content->toDeltaSnapshot($snapshot)));
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertNestedSnapshotReadValuesMatchFixture(
        array $fixture,
        YNestedArray $nestedArray,
        YNestedMap $nestedMap,
        YNestedText $nestedText,
        Snapshot $snapshot
    ): void {
        self::assertSame($fixture['nestedArray'], $nestedArray->toArraySnapshot($snapshot));
        self::assertSame($fixture['nestedArray'], $nestedArray->toJSONSnapshot($snapshot));
        self::assertSame($fixture['nestedArrayLength'], $nestedArray->lengthSnapshot($snapshot));
        self::assertSame($fixture['nestedArrayFirst'], $nestedArray->getSnapshot(0, $snapshot));
        self::assertSame($fixture['nestedArraySlice'], $nestedArray->sliceSnapshot($snapshot, 1));
        self::assertSame($this->sortAssociativeArrays($fixture['nestedMapAll']), $this->sortAssociativeArrays($nestedMap->getAllSnapshot($snapshot)));
        self::assertSame($this->sortAssociativeArrays($fixture['nestedMapAll']), $this->sortAssociativeArrays($nestedMap->toJSONSnapshot($snapshot)));
        self::assertSame($fixture['nestedMapSize'], $nestedMap->sizeSnapshot($snapshot));
        self::assertSame($fixture['nestedMapHasName'], $nestedMap->hasSnapshot('name', $snapshot));
        self::assertSame($fixture['nestedMapHasExtra'], $nestedMap->hasSnapshot('extra', $snapshot));
        self::assertSame($fixture['nestedMapName'], $nestedMap->getSnapshot('name', $snapshot));
        self::assertSame($fixture['nestedMapExtra'], $nestedMap->getSnapshot('extra', $snapshot));
        self::assertSame($fixture['nestedText'], $nestedText->toStringSnapshot($snapshot));
        self::assertSame($fixture['nestedText'], $nestedText->toJSONSnapshot($snapshot));
        self::assertSame($fixture['nestedTextLength'], $nestedText->lengthSnapshot($snapshot));
        self::assertSame($fixture['nestedTextSlice'], $nestedText->sliceSnapshot($snapshot, 6));
        self::assertSame($this->sortAssociativeArrays($fixture['nestedTextDelta']), $this->sortAssociativeArrays($nestedText->toDeltaSnapshot($snapshot)));
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertXmlSnapshotReadValuesMatchFixture(
        array $fixture,
        YXmlFragment $fragment,
        YXmlElement $paragraph,
        YXmlText $text,
        Snapshot $snapshot
    ): void {
        self::assertSame($fixture['xml'], $fragment->toStringSnapshot($snapshot));
        self::assertSame($fixture['xml'], $fragment->toJSONSnapshot($snapshot));
        self::assertSame($fixture['xmlLength'], $fragment->lengthSnapshot($snapshot));
        self::assertSame($fixture['xmlFirstChild'], $fragment->firstChildSnapshot($snapshot)?->toString());
        self::assertSame($fixture['xmlFirstChild'], $fragment->getSnapshot(0, $snapshot)?->toString());
        self::assertSame($fixture['xmlSlice'], array_map(static fn (mixed $child): string => $child->toString(), $fragment->sliceSnapshot($snapshot, 0, 1)));
        self::assertSame($fixture['xmlSlice'], array_map(static fn (mixed $child): string => $child->toString(), $fragment->toArraySnapshot($snapshot)));
        self::assertSame($fixture['xmlElement'], $paragraph->toStringSnapshot($snapshot));
        self::assertSame($fixture['xmlElement'], $paragraph->toJSONSnapshot($snapshot));
        self::assertSame($fixture['xmlElementLength'], $paragraph->lengthSnapshot($snapshot));
        self::assertSame($fixture['xmlElementFirstChild'], $paragraph->firstChildSnapshot($snapshot)?->toString());
        self::assertSame($fixture['xmlElementFirstChild'], $paragraph->getSnapshot(0, $snapshot)?->toString());
        self::assertSame($fixture['xmlElementSlice'], array_map(static fn (mixed $child): string => $child->toString(), $paragraph->sliceSnapshot($snapshot, 0, 1)));
        self::assertSame($fixture['xmlElementSlice'], array_map(static fn (mixed $child): string => $child->toString(), $paragraph->toArraySnapshot($snapshot)));
        self::assertSame($this->sortAssociativeArrays($fixture['xmlAttributes']), $this->sortAssociativeArrays($paragraph->getAttributesSnapshot($snapshot)));
        self::assertSame($fixture['xmlClass'], $paragraph->getAttributeSnapshot('class', $snapshot));
        self::assertTrue($paragraph->hasAttributeSnapshot('class', $snapshot));
        self::assertSame($fixture['xmlText'], $text->toStringSnapshot($snapshot));
        self::assertSame($fixture['xmlText'], $text->toJSONSnapshot($snapshot));
        self::assertSame($fixture['xmlTextLength'], $text->lengthSnapshot($snapshot));
        self::assertSame($this->sortAssociativeArrays($fixture['xmlDelta']), $this->sortAssociativeArrays($text->toDeltaSnapshot($snapshot)));
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertEmbeddedXmlSnapshotReadValuesMatchFixture(
        array $fixture,
        string $prefix,
        YNestedXmlFragment $fragment,
        YXmlText $text,
        YXmlElement $element,
        YXmlText $elementText,
        Snapshot $snapshot
    ): void {
        self::assertSame($fixture[$prefix . 'Fragment'], $fragment->toStringSnapshot($snapshot));
        self::assertSame($fixture[$prefix . 'Fragment'], $fragment->toJSONSnapshot($snapshot));
        self::assertSame($fixture[$prefix . 'FragmentLength'], $fragment->lengthSnapshot($snapshot));
        self::assertSame($fixture[$prefix . 'FragmentFirstChild'], $fragment->firstChildSnapshot($snapshot)?->toString());
        self::assertSame($fixture[$prefix . 'FragmentFirstChild'], $fragment->getSnapshot(0, $snapshot)?->toString());
        self::assertSame($fixture[$prefix . 'FragmentArray'], array_map(static fn (mixed $child): string => $child->toString(), $fragment->toArraySnapshot($snapshot)));
        self::assertSame($fixture[$prefix . 'FragmentSlice'], array_map(static fn (mixed $child): string => $child->toString(), $fragment->sliceSnapshot($snapshot, 0, 2)));
        self::assertSame($fixture[$prefix . 'Element'], $element->toStringSnapshot($snapshot));
        self::assertSame($fixture[$prefix . 'Element'], $element->toJSONSnapshot($snapshot));
        self::assertSame($fixture[$prefix . 'ElementLength'], $element->lengthSnapshot($snapshot));
        self::assertSame($fixture[$prefix . 'ElementFirstChild'], $element->firstChildSnapshot($snapshot)?->toString());
        self::assertSame($fixture[$prefix . 'ElementFirstChild'], $element->getSnapshot(0, $snapshot)?->toString());
        self::assertSame($fixture[$prefix . 'ElementSlice'], array_map(static fn (mixed $child): string => $child->toString(), $element->sliceSnapshot($snapshot, 0, 1)));
        self::assertSame($fixture[$prefix . 'ElementSlice'], array_map(static fn (mixed $child): string => $child->toString(), $element->toArraySnapshot($snapshot)));
        self::assertSame($this->sortAssociativeArrays($fixture[$prefix . 'Attributes']), $this->sortAssociativeArrays($element->getAttributesSnapshot($snapshot)));
        self::assertSame($fixture[$prefix . 'Class'], $element->getAttributeSnapshot('class', $snapshot));
        self::assertTrue($element->hasAttributeSnapshot('class', $snapshot));
        self::assertSame($fixture[$prefix . 'Text'], $text->toStringSnapshot($snapshot));
        self::assertSame($fixture[$prefix . 'Text'], $text->toJSONSnapshot($snapshot));
        self::assertSame($fixture[$prefix . 'TextLength'], $text->lengthSnapshot($snapshot));
        self::assertSame($this->sortAssociativeArrays($fixture[$prefix . 'Delta']), $this->sortAssociativeArrays($text->toDeltaSnapshot($snapshot)));
        self::assertSame($fixture[$prefix . 'ElementText'], $elementText->toStringSnapshot($snapshot));
        self::assertSame($fixture[$prefix . 'ElementText'], $elementText->toJSONSnapshot($snapshot));
        self::assertSame($fixture[$prefix . 'ElementTextLength'], $elementText->lengthSnapshot($snapshot));
        self::assertSame($this->sortAssociativeArrays($fixture[$prefix . 'ElementDelta']), $this->sortAssociativeArrays($elementText->toDeltaSnapshot($snapshot)));
    }

    /**
     * @return array{Snapshot, Snapshot, YNestedArray, YNestedMap, YNestedText, YNestedXmlFragment, YXmlText, YXmlElement, YXmlText, YNestedText, YNestedXmlFragment, YXmlText, YXmlElement, YXmlText}
     */
    private function nativeSnapshotReadScenario(): array
    {
        $doc = new YDoc(283, 'snapshot-read-doc');
        $array = $doc->getArray('items');
        $map = $doc->getMap('meta');
        $text = $doc->getText('content');
        $array->insert(0, ['A', 'B', 'C']);
        $map->set('title', 'Before');
        $map->set('count', 1);
        $text->insert(0, 'Hello');
        $text->format(1, 3, ['bold' => true]);
        $nestedArray = $array->insertArray(3);
        $nestedArray->insert(0, ['nA', 'nB']);
        $nestedMap = $array->insertMap(4);
        $nestedMap->set('name', 'NestedBefore');
        $nestedText = $array->insertText(5);
        $nestedText->insert(0, 'NestedText');
        $nestedText->format(0, 6, ['italic' => true]);
        $nestedXml = $array->insertXmlFragment(6);
        $nestedXmlText = $nestedXml->insertText(0, 'NestedXml');
        $nestedXmlElement = $nestedXml->insertElement(1, 'span');
        $nestedXmlElement->setAttribute('class', 'before');
        $nestedXmlElementText = $nestedXmlElement->insertText(0, 'Before');
        $mapText = $map->setText('body');
        $mapText->insert(0, 'MapText');
        $mapText->format(0, 3, ['bold' => true]);
        $mapText->setAttribute('lang', 'en');
        $mapXml = $map->setXmlFragment('xmlBody');
        $mapXmlText = $mapXml->insertText(0, 'MapXml');
        $mapXmlElement = $mapXml->insertElement(1, 'em');
        $mapXmlElement->setAttribute('class', 'before');
        $mapXmlElementText = $mapXmlElement->insertText(0, 'Before');
        $before = $doc->snapshot();

        $array->delete(1, 1);
        $array->insert(2, ['D']);
        $map->set('title', 'After');
        $map->delete('count');
        $map->set('extra', true);
        $mapText->delete(3, 4);
        $mapText->insert(3, ' body', ['italic' => true]);
        $mapText->setAttribute('lang', 'fr');
        $mapText->setAttribute('mark', ['color' => 'green']);
        $mapXmlText->insert(6, '!');
        $mapXmlElement->setAttribute('class', 'after');
        $mapXmlElementText->delete(0, 6);
        $mapXmlElementText->insert(0, 'After');
        $mapXml->insertElement(2, 'hr');
        $text->delete(1, 2);
        $text->insert(1, 'i');
        $nestedArray->delete(0, 1);
        $nestedArray->push(['nC']);
        $nestedMap->set('name', 'NestedAfter');
        $nestedMap->set('extra', 'yes');
        $nestedText->delete(6, 4);
        $nestedText->insert(6, '!');
        $nestedXmlText->insert(9, '!');
        $nestedXmlElement->setAttribute('class', 'after');
        $nestedXmlElementText->delete(0, 6);
        $nestedXmlElementText->insert(0, 'After');
        $nestedXml->insertElement(2, 'br');
        $after = $doc->snapshot();

        return [$before, $after, $nestedArray, $nestedMap, $nestedText, $nestedXml, $nestedXmlText, $nestedXmlElement, $nestedXmlElementText, $mapText, $mapXml, $mapXmlText, $mapXmlElement, $mapXmlElementText];
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertMapTextSnapshotReadValuesMatchFixture(array $fixture, YNestedText $mapText, Snapshot $snapshot): void
    {
        self::assertSame($fixture['mapText'], $mapText->toStringSnapshot($snapshot));
        self::assertSame($fixture['mapText'], $mapText->toJSONSnapshot($snapshot));
        self::assertSame($fixture['mapTextLength'], $mapText->lengthSnapshot($snapshot));
        self::assertSame($fixture['mapTextSlice'], $mapText->sliceSnapshot($snapshot, 3));
        self::assertSame($this->sortAssociativeArrays($fixture['mapTextDelta']), $this->sortAssociativeArrays($mapText->toDeltaSnapshot($snapshot)));
        self::assertSame($this->sortAssociativeArrays($fixture['mapTextAttributes']), $this->sortAssociativeArrays($mapText->getAttributesSnapshot($snapshot)));
        self::assertSame($fixture['mapTextLang'], $mapText->getAttributeSnapshot('lang', $snapshot));
        self::assertSame($this->sortAssociativeArrays($fixture['mapTextMark']), $this->sortAssociativeArrays($mapText->getAttributeSnapshot('mark', $snapshot)));
        self::assertSame($fixture['mapTextHasLang'], $mapText->hasAttributeSnapshot('lang', $snapshot));
        self::assertSame($fixture['mapTextHasMissing'], $mapText->hasAttributeSnapshot('missing', $snapshot));
    }

    /**
     * @return array{Snapshot, Snapshot, YXmlFragment, YXmlElement, YXmlText}
     */
    private function nativeXmlSnapshotReadScenario(): array
    {
        $doc = new YDoc(283, 'snapshot-read-doc');
        $fragment = $doc->getXmlFragment('xml');
        $paragraph = $fragment->insertElement(0, 'p');
        $paragraph->setAttribute('class', 'before');
        $text = $paragraph->insertText(0, 'Xml');
        $body = $paragraph->setText('body');
        $body->insert(0, 'BodyBefore');
        $inline = $paragraph->setXmlElement('inline', 'span');
        $inline->setAttribute('class', 'before');
        $inlineText = $inline->insertText(0, 'InlineBefore');
        $before = $doc->snapshot();

        $paragraph->setAttribute('class', 'after');
        $body->delete(4, 6);
        $body->insert(4, 'After');
        $inline->setAttribute('class', 'after');
        $inlineText->delete(6, 6);
        $inlineText->insert(6, 'After');
        $text->insert(3, '!');
        $text->format(0, 3, ['bold' => true]);
        $after = $doc->snapshot();

        return [$before, $after, $fragment, $paragraph, $text];
    }

    private function restoreFromEncodedSnapshot(YDoc $source, string $encodedSnapshot): YDoc
    {
        return $source->createDocFromSnapshot($this->snapshotFromEncodedV1($encodedSnapshot));
    }

    private function snapshotFromEncodedV1(string $encodedSnapshot): Snapshot
    {
        $snapshot = base64_decode($encodedSnapshot, true);
        self::assertIsString($snapshot);

        return Snapshot::decodeV1($snapshot);
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
     * @return array<string, mixed>
     */
    private function loadFixture(): array
    {
        self::assertFileExists(self::FIXTURE_PATH, 'Run `npm run fixtures` before PHPUnit.');

        $decoded = json_decode((string) file_get_contents(self::FIXTURE_PATH), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array{deleteSet: array<string, list<array{clock: int, length: int}>|null>, stateVector: array<string, int>} $fixture
     */
    private function snapshotFromFixture(array $fixture): Snapshot
    {
        $normalized = $this->normalizeSnapshot($fixture);

        return new Snapshot($normalized['deleteSet'], $normalized['stateVector']);
    }

    /**
     * @param array{deleteSet: array<string, list<array{clock: int, length: int}>|null>, stateVector: array<string, int>} $fixture
     * @return array{
     *     deleteSet: array<int, list<array{clock: int, length: int}>>,
     *     stateVector: array<int, int>
     * }
     */
    private function normalizeSnapshot(array $fixture): array
    {
        $deleteSet = [];
        foreach ($fixture['deleteSet'] as $client => $deletes) {
            if ($deletes !== null) {
                $deleteSet[(int) $client] = $deletes;
            }
        }
        krsort($deleteSet, SORT_NUMERIC);

        $stateVector = [];
        foreach ($fixture['stateVector'] as $client => $clock) {
            $stateVector[(int) $client] = $clock;
        }
        krsort($stateVector, SORT_NUMERIC);

        return [
            'deleteSet' => $deleteSet,
            'stateVector' => $stateVector,
        ];
    }
}
