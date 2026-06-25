<?php

declare(strict_types=1);

namespace Yjs\Tests\Compatibility;

use PHPUnit\Framework\TestCase;
use Yjs\Update\DecodedUpdate;
use Yjs\Update\UpdateUtils;
use Yjs\YDoc;
use Yjs\YXmlElement;
use Yjs\YXmlHook;
use Yjs\YXmlText;

final class YjsUpdateUtilsFixtureTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__ . '/../../fixtures/generated/yjs-13.6.31/update-utils.json';

    public function testDecodeUpdateV1MatchesYjsDecodedFixture(): void
    {
        $fixture = $this->loadFixture();
        $merged = base64_decode($fixture['mergedV1'], true);
        $diff = base64_decode($fixture['diffV1'], true);
        $empty = base64_decode($fixture['empty']['mergedV1'], true);
        self::assertIsString($merged);
        self::assertIsString($diff);
        self::assertIsString($empty);

        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['mergedDecodedV1']),
            UpdateUtils::decodeUpdateV1($merged)
        );
        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['diffDecodedV1']),
            UpdateUtils::decodeUpdateV1($diff)
        );
        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['empty']['decodedV1']),
            UpdateUtils::decodeUpdateV1($empty)
        );
    }

    public function testDecodeUpdateV2MatchesYjsDecodedFixture(): void
    {
        $fixture = $this->loadFixture();
        $merged = base64_decode($fixture['mergedV2'], true);
        $diff = base64_decode($fixture['diffV2'], true);
        $empty = base64_decode($fixture['empty']['mergedV2'], true);
        self::assertIsString($merged);
        self::assertIsString($diff);
        self::assertIsString($empty);

        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['mergedDecodedV2']),
            UpdateUtils::decodeUpdateV2($merged)
        );
        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['diffDecodedV2']),
            UpdateUtils::decodeUpdateV2($diff)
        );
        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['empty']['decodedV2']),
            UpdateUtils::decodeUpdateV2($empty)
        );
    }

    public function testUnversionedUpdateUtilitiesMatchYjsV1Fixtures(): void
    {
        $fixture = $this->loadFixture();
        $updates = $this->decodeUpdates($fixture['updatesV1']);
        $targetStateVector = base64_decode($fixture['targetStateVectorV1'], true);
        self::assertIsString($targetStateVector);

        $merged = UpdateUtils::mergeUpdates($updates);
        $diff = UpdateUtils::diffUpdate($merged, $targetStateVector);

        self::assertSame(base64_decode($fixture['mergedV1'], true), $merged);
        self::assertSame(base64_decode($fixture['diffV1'], true), $diff);
        self::assertSame(
            base64_decode($fixture['stateVectorFromMergedV1'], true),
            UpdateUtils::encodeStateVectorFromUpdate($merged)
        );
        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['mergedDecodedV1']),
            UpdateUtils::decodeUpdate($merged)
        );
    }

    public function testUnversionedObfuscateUpdateMatchesYjsV1Fixture(): void
    {
        $fixture = $this->loadFixture()['obfuscate'];
        $update = base64_decode($fixture['updateV1'], true);
        self::assertIsString($update);

        $obfuscated = UpdateUtils::obfuscateUpdate($update);

        self::assertSame(base64_decode($fixture['obfuscatedV1'], true), $obfuscated);
        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['obfuscatedDecodedV1']),
            DecodedUpdate::decodeV1($obfuscated)
        );
    }

    public function testMergeUpdatesV1MatchesYjsDecodedFixture(): void
    {
        $fixture = $this->loadFixture();
        $merged = UpdateUtils::mergeUpdatesV1($this->decodeUpdates($fixture['updatesV1']));

        self::assertSame(base64_decode($fixture['mergedV1'], true), $merged);
        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['mergedDecodedV1']),
            DecodedUpdate::decodeV1($merged)
        );

        $doc = new YDoc();
        $doc->applyUpdateV1($merged);
        self::assertSame($this->sortAssociativeArrays($fixture['json']), $this->sortAssociativeArrays($doc->toJSON()));
    }

    public function testMergeUpdatesV2MatchesYjsDecodedFixture(): void
    {
        $fixture = $this->loadFixture();
        $merged = UpdateUtils::mergeUpdatesV2($this->decodeUpdates($fixture['updatesV2']));

        self::assertSame(base64_decode($fixture['mergedV2'], true), $merged);
        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['mergedDecodedV2']),
            DecodedUpdate::decodeV2($merged)
        );

        $doc = new YDoc();
        $doc->applyUpdateV2($merged);
        self::assertSame($this->sortAssociativeArrays($fixture['json']), $this->sortAssociativeArrays($doc->toJSON()));
    }

    public function testEmptyUpdateUtilsMatchYjsFixtures(): void
    {
        $fixture = $this->loadFixture()['empty'];
        $mergedV1 = UpdateUtils::mergeUpdatesV1([]);
        $mergedV2 = UpdateUtils::mergeUpdatesV2([]);
        $stateVector = base64_decode($fixture['stateVectorV1'], true);
        self::assertIsString($stateVector);

        self::assertSame(base64_decode($fixture['mergedV1'], true), $mergedV1);
        self::assertSame(base64_decode($fixture['mergedV2'], true), $mergedV2);
        self::assertSame(base64_decode($fixture['diffV1'], true), UpdateUtils::diffUpdateV1($mergedV1, $stateVector));
        self::assertSame(base64_decode($fixture['diffV2'], true), UpdateUtils::diffUpdateV2($mergedV2, $stateVector));
        self::assertSame(base64_decode($fixture['convertedV2'], true), UpdateUtils::convertUpdateFormatV1ToV2($mergedV1));
        self::assertSame(base64_decode($fixture['convertedV1'], true), UpdateUtils::convertUpdateFormatV2ToV1($mergedV2));
        self::assertSame(base64_decode($fixture['stateVectorFromMergedV1'], true), UpdateUtils::encodeStateVectorFromUpdateV1($mergedV1));
        self::assertSame(base64_decode($fixture['stateVectorFromMergedV2'], true), UpdateUtils::encodeStateVectorFromUpdateV2($mergedV2));
        self::assertSame($this->normalizeDecodedUpdate($fixture['decodedV1']), DecodedUpdate::decodeV1($mergedV1));
        self::assertSame($this->normalizeDecodedUpdate($fixture['decodedV2']), DecodedUpdate::decodeV2($mergedV2));
    }

    public function testDiffUpdateSlicesFormattedTextStructs(): void
    {
        $full = new YDoc(539);
        $full->getText('content')->insert(0, 'HelloWorld', ['bold' => true]);
        $fullDecoded = DecodedUpdate::decodeV1($full->encodeStateAsUpdateV1());
        $prefixDecoded = $fullDecoded;
        $prefixDecoded['structs'] = [
            $fullDecoded['structs'][0],
            $fullDecoded['structs'][1],
        ];
        $prefixDecoded['structs'][1]['length'] = 5;
        $prefixDecoded['structs'][1]['content']['value'] = 'Hello';

        $prefixV1 = new YDoc();
        $prefixV1->applyUpdateV1(DecodedUpdate::encodeV1($prefixDecoded['structs']));
        $diffV1 = UpdateUtils::diffUpdateV1($full->encodeStateAsUpdateV1(), $prefixV1->encodeStateVector());
        $prefixV1->applyUpdateV1($diffV1);

        self::assertSame(['content' => 'HelloWorld'], $prefixV1->toJSON());
        self::assertSame([
            [
                'insert' => 'HelloWorld',
                'attributes' => ['bold' => true],
            ],
        ], $prefixV1->getText('content')->toDelta());

        $prefixV2 = new YDoc();
        $prefixV2->applyUpdateV2(DecodedUpdate::encodeV2($prefixDecoded['structs']));
        $diffV2 = UpdateUtils::diffUpdateV2($full->encodeStateAsUpdateV2(), $prefixV2->encodeStateVector());
        $prefixV2->applyUpdateV2($diffV2);

        self::assertSame(['content' => 'HelloWorld'], $prefixV2->toJSON());
        self::assertSame([
            [
                'insert' => 'HelloWorld',
                'attributes' => ['bold' => true],
            ],
        ], $prefixV2->getText('content')->toDelta());
    }

    public function testMergeUpdatesV1SkipsOverlappingStructRanges(): void
    {
        $fixture = $this->loadFixture()['overlappingMerge'];
        $merged = UpdateUtils::mergeUpdatesV1($this->decodeUpdates($fixture['updatesV1']));

        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['mergedDecodedV1']),
            DecodedUpdate::decodeV1($merged)
        );

        $doc = new YDoc();
        $doc->applyUpdateV1($merged);
        self::assertSame($fixture['json'], $doc->toJSON());
    }

    public function testMergeUpdatesV2SkipsOverlappingStructRanges(): void
    {
        $fixture = $this->loadFixture()['overlappingMerge'];
        $merged = UpdateUtils::mergeUpdatesV2($this->decodeUpdates($fixture['updatesV2']));

        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['mergedDecodedV2']),
            DecodedUpdate::decodeV2($merged)
        );

        $doc = new YDoc();
        $doc->applyUpdateV2($merged);
        self::assertSame($fixture['json'], $doc->toJSON());
    }

    public function testMergeUpdatesV1SkipsOverlappingDeletedStructRanges(): void
    {
        $fixture = $this->loadFixture()['overlappingDeletedMerge'];
        $merged = UpdateUtils::mergeUpdatesV1($this->decodeUpdates($fixture['updatesV1']));

        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['mergedDecodedV1']),
            DecodedUpdate::decodeV1($merged)
        );

        $doc = new YDoc();
        $doc->applyUpdateV1($merged);
        self::assertSame($fixture['json'], $doc->toJSON());
    }

    public function testMergeUpdatesV2SkipsOverlappingDeletedStructRanges(): void
    {
        $fixture = $this->loadFixture()['overlappingDeletedMerge'];
        $merged = UpdateUtils::mergeUpdatesV2($this->decodeUpdates($fixture['updatesV2']));

        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['mergedDecodedV2']),
            DecodedUpdate::decodeV2($merged)
        );

        $doc = new YDoc();
        $doc->applyUpdateV2($merged);
        self::assertSame($fixture['json'], $doc->toJSON());
    }

    public function testMergeUpdatesV1SkipsOverlappingGcStructRanges(): void
    {
        $fixture = $this->loadFixture()['overlappingGcMerge'];
        $merged = UpdateUtils::mergeUpdatesV1($this->decodeUpdates($fixture['updatesV1']));

        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['mergedDecodedV1']),
            DecodedUpdate::decodeV1($merged)
        );

        $doc = new YDoc();
        $doc->applyUpdateV1($merged);
        self::assertSame($fixture['json'], $doc->toJSON());
    }

    public function testMergeUpdatesV2SkipsOverlappingGcStructRanges(): void
    {
        $fixture = $this->loadFixture()['overlappingGcMerge'];
        $merged = UpdateUtils::mergeUpdatesV2($this->decodeUpdates($fixture['updatesV2']));

        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['mergedDecodedV2']),
            DecodedUpdate::decodeV2($merged)
        );

        $doc = new YDoc();
        $doc->applyUpdateV2($merged);
        self::assertSame($fixture['json'], $doc->toJSON());
    }

    public function testMergeUpdatesV1PreservesRootTextAttributeConflicts(): void
    {
        $fixture = $this->loadFixture()['textAttributeMerge'];
        $merged = UpdateUtils::mergeUpdatesV1($this->decodeUpdates($fixture['updatesV1']));

        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['mergedDecodedV1']),
            DecodedUpdate::decodeV1($merged)
        );

        $doc = new YDoc();
        $text = $doc->getText('content');
        $doc->applyUpdateV1($merged);

        self::assertSame($fixture['json'], $doc->toJSON());
        self::assertSame($fixture['textAttributes'], $text->getAttributes());
    }

    public function testMergeUpdatesV2PreservesRootTextAttributeConflicts(): void
    {
        $fixture = $this->loadFixture()['textAttributeMerge'];
        $merged = UpdateUtils::mergeUpdatesV2($this->decodeUpdates($fixture['updatesV2']));

        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['mergedDecodedV2']),
            DecodedUpdate::decodeV2($merged)
        );

        $doc = new YDoc();
        $text = $doc->getText('content');
        $doc->applyUpdateV2($merged);

        self::assertSame($fixture['json'], $doc->toJSON());
        self::assertSame($fixture['textAttributes'], $text->getAttributes());
    }

    public function testMergeUpdatesV1PreservesThreeWayTextConflicts(): void
    {
        $fixture = $this->loadFixture()['threeWayConflictMerge'];
        $merged = UpdateUtils::mergeUpdatesV1($this->decodeUpdates($fixture['updatesV1']));

        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['mergedDecodedV1']),
            DecodedUpdate::decodeV1($merged)
        );

        $doc = new YDoc();
        $doc->getText('content');
        $doc->applyUpdateV1($merged);
        self::assertSame($fixture['json'], $doc->toJSON());
    }

    public function testMergeUpdatesV2PreservesThreeWayTextConflicts(): void
    {
        $fixture = $this->loadFixture()['threeWayConflictMerge'];
        $merged = UpdateUtils::mergeUpdatesV2($this->decodeUpdates($fixture['updatesV2']));

        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['mergedDecodedV2']),
            DecodedUpdate::decodeV2($merged)
        );

        $doc = new YDoc();
        $doc->getText('content');
        $doc->applyUpdateV2($merged);
        self::assertSame($fixture['json'], $doc->toJSON());
    }

    public function testMergeUpdatesV1PreservesXmlTextDeleteEditSharedAttributeConflict(): void
    {
        $fixture = $this->loadFixture()['xmlTextDeleteEditConflictMerge'];
        $merged = UpdateUtils::mergeUpdatesV1($this->decodeUpdates($fixture['updatesV1']));

        self::assertSame(base64_decode($fixture['mergedV1'], true), $merged);
        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['mergedDecodedV1']),
            DecodedUpdate::decodeV1($merged)
        );

        $doc = new YDoc();
        $doc->getXmlFragment('xml');
        $doc->applyUpdateV1($merged);
        $this->assertXmlTextDeleteEditConflictState($doc, $fixture);
    }

    public function testMergeUpdatesV2PreservesXmlTextDeleteEditSharedAttributeConflict(): void
    {
        $fixture = $this->loadFixture()['xmlTextDeleteEditConflictMerge'];
        $merged = UpdateUtils::mergeUpdatesV2($this->decodeUpdates($fixture['updatesV2']));

        self::assertSame(base64_decode($fixture['mergedV2'], true), $merged);
        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['mergedDecodedV2']),
            DecodedUpdate::decodeV2($merged)
        );

        $doc = new YDoc();
        $doc->getXmlFragment('xml');
        $doc->applyUpdateV2($merged);
        $this->assertXmlTextDeleteEditConflictState($doc, $fixture);
    }

    public function testConvertUpdateFormatV1ToV2PreservesXmlTextDeleteEditSharedAttributeConflict(): void
    {
        $fixture = $this->loadFixture()['xmlTextDeleteEditConflictMerge'];
        $merged = UpdateUtils::mergeUpdatesV1($this->decodeUpdates($fixture['updatesV1']));

        $converted = UpdateUtils::convertUpdateFormatV1ToV2($merged);

        self::assertSame(base64_decode($fixture['convertedV2'], true), $converted);
        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['convertedDecodedV2']),
            DecodedUpdate::decodeV2($converted)
        );

        $doc = new YDoc();
        $doc->getXmlFragment('xml');
        $doc->applyUpdateV2($converted);
        $this->assertXmlTextDeleteEditConflictState($doc, $fixture);
    }

    public function testConvertUpdateFormatV2ToV1PreservesXmlTextDeleteEditSharedAttributeConflict(): void
    {
        $fixture = $this->loadFixture()['xmlTextDeleteEditConflictMerge'];
        $merged = UpdateUtils::mergeUpdatesV2($this->decodeUpdates($fixture['updatesV2']));

        $converted = UpdateUtils::convertUpdateFormatV2ToV1($merged);

        self::assertSame(base64_decode($fixture['convertedV1'], true), $converted);
        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['convertedDecodedV1']),
            DecodedUpdate::decodeV1($converted)
        );

        $doc = new YDoc();
        $doc->getXmlFragment('xml');
        $doc->applyUpdateV1($converted);
        $this->assertXmlTextDeleteEditConflictState($doc, $fixture);
    }

    public function testDiffUpdateV1CompletesThreeWayTextConflictPrefix(): void
    {
        $fixture = $this->loadFixture()['threeWayConflictMerge'];
        $merged = UpdateUtils::mergeUpdatesV1($this->decodeUpdates($fixture['updatesV1']));
        $targetStateVector = base64_decode($fixture['targetStateVectorV1'], true);
        $prefix = base64_decode($fixture['prefixV1'], true);
        self::assertIsString($targetStateVector);
        self::assertIsString($prefix);

        $diff = UpdateUtils::diffUpdateV1($merged, $targetStateVector);

        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['diffDecodedV1']),
            DecodedUpdate::decodeV1($diff)
        );

        $doc = new YDoc();
        $doc->getText('content');
        $doc->applyUpdateV1($prefix);
        $doc->applyUpdateV1($diff);
        self::assertSame($fixture['json'], $doc->toJSON());
    }

    public function testDiffUpdateV2CompletesThreeWayTextConflictPrefix(): void
    {
        $fixture = $this->loadFixture()['threeWayConflictMerge'];
        $merged = UpdateUtils::mergeUpdatesV2($this->decodeUpdates($fixture['updatesV2']));
        $targetStateVector = base64_decode($fixture['targetStateVectorV1'], true);
        $prefix = base64_decode($fixture['prefixV2'], true);
        self::assertIsString($targetStateVector);
        self::assertIsString($prefix);

        $diff = UpdateUtils::diffUpdateV2($merged, $targetStateVector);

        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['diffDecodedV2']),
            DecodedUpdate::decodeV2($diff)
        );

        $doc = new YDoc();
        $doc->getText('content');
        $doc->applyUpdateV2($prefix);
        $doc->applyUpdateV2($diff);
        self::assertSame($fixture['json'], $doc->toJSON());
    }

    public function testDiffUpdateV1SlicesGcContentDeletedFixture(): void
    {
        $fixture = $this->loadFixture()['overlappingGcMerge'];
        $merged = UpdateUtils::mergeUpdatesV1($this->decodeUpdates($fixture['updatesV1']));
        $targetStateVector = base64_decode($fixture['diffTargetStateVectorV1'], true);
        self::assertIsString($targetStateVector);

        $diff = UpdateUtils::diffUpdateV1($merged, $targetStateVector);

        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['diffDecodedV1']),
            DecodedUpdate::decodeV1($diff)
        );
    }

    public function testDiffUpdateV2SlicesGcContentDeletedFixture(): void
    {
        $fixture = $this->loadFixture()['overlappingGcMerge'];
        $merged = UpdateUtils::mergeUpdatesV2($this->decodeUpdates($fixture['updatesV2']));
        $targetStateVector = base64_decode($fixture['diffTargetStateVectorV1'], true);
        self::assertIsString($targetStateVector);

        $diff = UpdateUtils::diffUpdateV2($merged, $targetStateVector);

        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['diffDecodedV2']),
            DecodedUpdate::decodeV2($diff)
        );
    }

    public function testEncodeStateVectorFromUpdatesMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $mergedV1 = UpdateUtils::mergeUpdatesV1($this->decodeUpdates($fixture['updatesV1']));
        $mergedV2 = UpdateUtils::mergeUpdatesV2($this->decodeUpdates($fixture['updatesV2']));

        self::assertSame(
            base64_decode($fixture['stateVectorFromMergedV1'], true),
            UpdateUtils::encodeStateVectorFromUpdateV1($mergedV1)
        );
        self::assertSame(
            base64_decode($fixture['stateVectorFromMergedV2'], true),
            UpdateUtils::encodeStateVectorFromUpdateV2($mergedV2)
        );
    }

    public function testEncodeStateVectorFromGapUpdateMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture()['gapStateVectors'];
        $suffixV1 = base64_decode($fixture['suffixUpdateV1'], true);
        $suffixV2 = base64_decode($fixture['suffixUpdateV2'], true);
        $mergedV1 = base64_decode($fixture['mergedV1'], true);
        $mergedV2 = base64_decode($fixture['mergedV2'], true);
        self::assertIsString($suffixV1);
        self::assertIsString($suffixV2);
        self::assertIsString($mergedV1);
        self::assertIsString($mergedV2);

        self::assertSame(
            base64_decode($fixture['stateVectorFromSuffixV1'], true),
            UpdateUtils::encodeStateVectorFromUpdateV1($suffixV1)
        );
        self::assertSame(
            base64_decode($fixture['stateVectorFromSuffixV2'], true),
            UpdateUtils::encodeStateVectorFromUpdateV2($suffixV2)
        );
        self::assertSame(
            base64_decode($fixture['stateVectorFromMergedV1'], true),
            UpdateUtils::encodeStateVectorFromUpdateV1($mergedV1)
        );
        self::assertSame(
            base64_decode($fixture['stateVectorFromMergedV2'], true),
            UpdateUtils::encodeStateVectorFromUpdateV2($mergedV2)
        );
    }

    public function testDiffUpdateV1MatchesYjsDecodedFixture(): void
    {
        $fixture = $this->loadFixture();
        $updates = $this->decodeUpdates($fixture['updatesV1']);
        $merged = UpdateUtils::mergeUpdatesV1($updates);
        $targetStateVector = base64_decode($fixture['targetStateVectorV1'], true);
        self::assertIsString($targetStateVector);

        $diff = UpdateUtils::diffUpdateV1($merged, $targetStateVector);

        self::assertSame(base64_decode($fixture['diffV1'], true), $diff);
        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['diffDecodedV1']),
            DecodedUpdate::decodeV1($diff)
        );

        $doc = new YDoc();
        $doc->applyUpdateV1($updates[0]);
        $doc->applyUpdateV1($diff);
        self::assertSame($this->sortAssociativeArrays($fixture['json']), $this->sortAssociativeArrays($doc->toJSON()));
    }

    public function testDiffUpdateV2MatchesYjsDecodedFixture(): void
    {
        $fixture = $this->loadFixture();
        $updates = $this->decodeUpdates($fixture['updatesV2']);
        $merged = UpdateUtils::mergeUpdatesV2($updates);
        $targetStateVector = base64_decode($fixture['targetStateVectorV1'], true);
        self::assertIsString($targetStateVector);

        $diff = UpdateUtils::diffUpdateV2($merged, $targetStateVector);

        self::assertSame(base64_decode($fixture['diffV2'], true), $diff);
        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['diffDecodedV2']),
            DecodedUpdate::decodeV2($diff)
        );

        $doc = new YDoc();
        $doc->applyUpdateV2($updates[0]);
        $doc->applyUpdateV2($diff);
        self::assertSame($this->sortAssociativeArrays($fixture['json']), $this->sortAssociativeArrays($doc->toJSON()));
    }

    public function testDiffUpdateV1CanReturnDeleteSetOnlyFixture(): void
    {
        $fixture = $this->loadFixture()['deleteSetOnlyDiff'];
        $update = base64_decode($fixture['updateV1'], true);
        $targetStateVector = base64_decode($fixture['targetStateVectorV1'], true);
        self::assertIsString($update);
        self::assertIsString($targetStateVector);

        $diff = UpdateUtils::diffUpdateV1($update, $targetStateVector);

        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['diffDecodedV1']),
            DecodedUpdate::decodeV1($diff)
        );
    }

    public function testDiffUpdateV2CanReturnDeleteSetOnlyFixture(): void
    {
        $fixture = $this->loadFixture()['deleteSetOnlyDiff'];
        $update = base64_decode($fixture['updateV2'], true);
        $targetStateVector = base64_decode($fixture['targetStateVectorV1'], true);
        self::assertIsString($update);
        self::assertIsString($targetStateVector);

        $diff = UpdateUtils::diffUpdateV2($update, $targetStateVector);

        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['diffDecodedV2']),
            DecodedUpdate::decodeV2($diff)
        );
    }

    public function testConvertUpdateFormatV1ToV2MatchesYjsDecodedFixture(): void
    {
        $fixture = $this->loadFixture();
        $merged = UpdateUtils::mergeUpdatesV1($this->decodeUpdates($fixture['updatesV1']));

        $converted = UpdateUtils::convertUpdateFormatV1ToV2($merged);

        self::assertSame(base64_decode($fixture['convertedV2'], true), $converted);
        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['convertedDecodedV2']),
            DecodedUpdate::decodeV2($converted)
        );

        $doc = new YDoc();
        $doc->applyUpdateV2($converted);
        self::assertSame($this->sortAssociativeArrays($fixture['json']), $this->sortAssociativeArrays($doc->toJSON()));
    }

    public function testConvertUpdateFormatV2ToV1MatchesYjsDecodedFixture(): void
    {
        $fixture = $this->loadFixture();
        $merged = UpdateUtils::mergeUpdatesV2($this->decodeUpdates($fixture['updatesV2']));

        $converted = UpdateUtils::convertUpdateFormatV2ToV1($merged);

        self::assertSame(base64_decode($fixture['convertedV1'], true), $converted);
        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['convertedDecodedV1']),
            DecodedUpdate::decodeV1($converted)
        );

        $doc = new YDoc();
        $doc->applyUpdateV1($converted);
        self::assertSame($this->sortAssociativeArrays($fixture['json']), $this->sortAssociativeArrays($doc->toJSON()));
    }

    public function testDeepMapMergeUpdatesV1PreservesNestedContent(): void
    {
        $fixture = $this->loadFixture()['deepMap'];
        $merged = UpdateUtils::mergeUpdatesV1($this->decodeUpdates($fixture['updatesV1']));

        self::assertSame(base64_decode($fixture['mergedV1'], true), $merged);
        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['mergedDecodedV1']),
            DecodedUpdate::decodeV1($merged)
        );

        $doc = new YDoc();
        $doc->applyUpdateV1($merged);
        $this->assertDeepMapFixtureState($doc, $fixture);
    }

    public function testDeepMapMergeUpdatesV2PreservesNestedContent(): void
    {
        $fixture = $this->loadFixture()['deepMap'];
        $merged = UpdateUtils::mergeUpdatesV2($this->decodeUpdates($fixture['updatesV2']));

        self::assertSame(base64_decode($fixture['mergedV2'], true), $merged);
        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['mergedDecodedV2']),
            DecodedUpdate::decodeV2($merged)
        );

        $doc = new YDoc();
        $doc->applyUpdateV2($merged);
        $this->assertDeepMapFixtureState($doc, $fixture);
    }

    public function testDeepMapDiffUpdateV1CanAppendNestedContentFromPrefix(): void
    {
        $fixture = $this->loadFixture()['deepMap'];
        $merged = UpdateUtils::mergeUpdatesV1($this->decodeUpdates($fixture['updatesV1']));
        $targetStateVector = base64_decode($fixture['targetStateVectorV1'], true);
        self::assertIsString($targetStateVector);

        $diff = UpdateUtils::diffUpdateV1($merged, $targetStateVector);

        self::assertSame(base64_decode($fixture['diffV1'], true), $diff);
        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['diffDecodedV1']),
            DecodedUpdate::decodeV1($diff)
        );

        $prefix = base64_decode($fixture['prefixV1'], true);
        self::assertIsString($prefix);
        $doc = new YDoc();
        $doc->applyUpdateV1($prefix);
        $doc->applyUpdateV1($diff);
        $this->assertDeepMapFixtureState($doc, $fixture);
    }

    public function testDeepMapDiffUpdateV2CanAppendNestedContentFromPrefix(): void
    {
        $fixture = $this->loadFixture()['deepMap'];
        $merged = UpdateUtils::mergeUpdatesV2($this->decodeUpdates($fixture['updatesV2']));
        $targetStateVector = base64_decode($fixture['targetStateVectorV1'], true);
        self::assertIsString($targetStateVector);

        $diff = UpdateUtils::diffUpdateV2($merged, $targetStateVector);

        self::assertSame(base64_decode($fixture['diffV2'], true), $diff);
        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['diffDecodedV2']),
            DecodedUpdate::decodeV2($diff)
        );

        $prefix = base64_decode($fixture['prefixV2'], true);
        self::assertIsString($prefix);
        $doc = new YDoc();
        $doc->applyUpdateV2($prefix);
        $doc->applyUpdateV2($diff);
        $this->assertDeepMapFixtureState($doc, $fixture);
    }

    public function testDeepMapConvertUpdateFormatV1ToV2MatchesYjsDecodedFixture(): void
    {
        $fixture = $this->loadFixture()['deepMap'];
        $merged = UpdateUtils::mergeUpdatesV1($this->decodeUpdates($fixture['updatesV1']));

        $converted = UpdateUtils::convertUpdateFormatV1ToV2($merged);

        self::assertSame(base64_decode($fixture['convertedV2'], true), $converted);
        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['convertedDecodedV2']),
            DecodedUpdate::decodeV2($converted)
        );

        $doc = new YDoc();
        $doc->applyUpdateV2($converted);
        $this->assertDeepMapFixtureState($doc, $fixture);
    }

    public function testDeepMapConvertUpdateFormatV2ToV1MatchesYjsDecodedFixture(): void
    {
        $fixture = $this->loadFixture()['deepMap'];
        $merged = UpdateUtils::mergeUpdatesV2($this->decodeUpdates($fixture['updatesV2']));

        $converted = UpdateUtils::convertUpdateFormatV2ToV1($merged);

        self::assertSame(base64_decode($fixture['convertedV1'], true), $converted);
        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['convertedDecodedV1']),
            DecodedUpdate::decodeV1($converted)
        );

        $doc = new YDoc();
        $doc->applyUpdateV1($converted);
        $this->assertDeepMapFixtureState($doc, $fixture);
    }

    public function testDeepMapPartialTextDiffSlicesNestedStringStructs(): void
    {
        $fixture = $this->loadFixture()['deepMap'];
        $stateVector = base64_decode($fixture['partialTextTargetStateVectorV1'], true);
        self::assertIsString($stateVector);
        $mergedV1 = UpdateUtils::mergeUpdatesV1($this->decodeUpdates($fixture['updatesV1']));
        $mergedV2 = UpdateUtils::mergeUpdatesV2($this->decodeUpdates($fixture['updatesV2']));

        $diffV1 = UpdateUtils::diffUpdateV1($mergedV1, $stateVector);
        $decodedV1 = DecodedUpdate::decodeV1($diffV1);
        self::assertSame($this->normalizeDecodedUpdate($fixture['partialTextDiffDecodedV1']), $decodedV1);
        $this->assertDecodedContainsContentString($decodedV1, ' text!');

        $diffV2 = UpdateUtils::diffUpdateV2($mergedV2, $stateVector);
        $decodedV2 = DecodedUpdate::decodeV2($diffV2);
        self::assertSame($this->normalizeDecodedUpdate($fixture['partialTextDiffDecodedV2']), $decodedV2);
        $this->assertDecodedContainsContentString($decodedV2, ' text!');
    }

    public function testXmlHookMergeUpdatesV1PreservesHookHeldSharedTypes(): void
    {
        $fixture = $this->loadFixture()['xmlHook'];
        $merged = UpdateUtils::mergeUpdatesV1($this->decodeUpdates($fixture['updatesV1']));

        self::assertSame(base64_decode($fixture['mergedV1'], true), $merged);
        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['mergedDecodedV1']),
            DecodedUpdate::decodeV1($merged)
        );

        $doc = new YDoc();
        $doc->applyUpdateV1($merged);
        $this->assertXmlHookUpdateUtilsFixtureState($doc, $fixture);
    }

    public function testXmlHookMergeUpdatesV2PreservesHookHeldSharedTypes(): void
    {
        $fixture = $this->loadFixture()['xmlHook'];
        $merged = UpdateUtils::mergeUpdatesV2($this->decodeUpdates($fixture['updatesV2']));

        self::assertSame(base64_decode($fixture['mergedV2'], true), $merged);
        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['mergedDecodedV2']),
            DecodedUpdate::decodeV2($merged)
        );

        $doc = new YDoc();
        $doc->applyUpdateV2($merged);
        $this->assertXmlHookUpdateUtilsFixtureState($doc, $fixture);
    }

    public function testXmlHookDiffUpdateV1CanAppendHookHeldSharedTypeContentFromPrefix(): void
    {
        $fixture = $this->loadFixture()['xmlHook'];
        $merged = UpdateUtils::mergeUpdatesV1($this->decodeUpdates($fixture['updatesV1']));
        $targetStateVector = base64_decode($fixture['targetStateVectorV1'], true);
        $prefix = base64_decode($fixture['prefixV1'], true);
        self::assertIsString($targetStateVector);
        self::assertIsString($prefix);

        $diff = UpdateUtils::diffUpdateV1($merged, $targetStateVector);

        self::assertSame(base64_decode($fixture['diffV1'], true), $diff);
        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['diffDecodedV1']),
            DecodedUpdate::decodeV1($diff)
        );

        $doc = new YDoc();
        $doc->applyUpdateV1($prefix);
        $doc->applyUpdateV1($diff);
        $this->assertXmlHookUpdateUtilsFixtureState($doc, $fixture);
    }

    public function testXmlHookDiffUpdateV2CanAppendHookHeldSharedTypeContentFromPrefix(): void
    {
        $fixture = $this->loadFixture()['xmlHook'];
        $merged = UpdateUtils::mergeUpdatesV2($this->decodeUpdates($fixture['updatesV2']));
        $targetStateVector = base64_decode($fixture['targetStateVectorV1'], true);
        $prefix = base64_decode($fixture['prefixV2'], true);
        self::assertIsString($targetStateVector);
        self::assertIsString($prefix);

        $diff = UpdateUtils::diffUpdateV2($merged, $targetStateVector);

        self::assertSame(base64_decode($fixture['diffV2'], true), $diff);
        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['diffDecodedV2']),
            DecodedUpdate::decodeV2($diff)
        );

        $doc = new YDoc();
        $doc->applyUpdateV2($prefix);
        $doc->applyUpdateV2($diff);
        $this->assertXmlHookUpdateUtilsFixtureState($doc, $fixture);
    }

    public function testXmlHookConvertUpdateFormatV1ToV2MatchesYjsDecodedFixture(): void
    {
        $fixture = $this->loadFixture()['xmlHook'];
        $merged = UpdateUtils::mergeUpdatesV1($this->decodeUpdates($fixture['updatesV1']));

        $converted = UpdateUtils::convertUpdateFormatV1ToV2($merged);

        self::assertSame(base64_decode($fixture['convertedV2'], true), $converted);
        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['convertedDecodedV2']),
            DecodedUpdate::decodeV2($converted)
        );

        $doc = new YDoc();
        $doc->applyUpdateV2($converted);
        $this->assertXmlHookUpdateUtilsFixtureState($doc, $fixture);
    }

    public function testXmlHookConvertUpdateFormatV2ToV1MatchesYjsDecodedFixture(): void
    {
        $fixture = $this->loadFixture()['xmlHook'];
        $merged = UpdateUtils::mergeUpdatesV2($this->decodeUpdates($fixture['updatesV2']));

        $converted = UpdateUtils::convertUpdateFormatV2ToV1($merged);

        self::assertSame(base64_decode($fixture['convertedV1'], true), $converted);
        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['convertedDecodedV1']),
            DecodedUpdate::decodeV1($converted)
        );

        $doc = new YDoc();
        $doc->applyUpdateV1($converted);
        $this->assertXmlHookUpdateUtilsFixtureState($doc, $fixture);
    }

    public function testXmlElementUpdateUtilsPreserveSharedAttributeTypes(): void
    {
        $fixture = $this->loadFixture()['xmlElement'];
        $targetStateVector = base64_decode($fixture['targetStateVectorV1'], true);
        $prefixV1 = base64_decode($fixture['prefixV1'], true);
        $prefixV2 = base64_decode($fixture['prefixV2'], true);
        self::assertIsString($targetStateVector);
        self::assertIsString($prefixV1);
        self::assertIsString($prefixV2);

        $mergedV1 = UpdateUtils::mergeUpdatesV1($this->decodeUpdates($fixture['updatesV1']));
        self::assertSame(base64_decode($fixture['mergedV1'], true), $mergedV1);
        self::assertSame($this->normalizeDecodedUpdate($fixture['mergedDecodedV1']), DecodedUpdate::decodeV1($mergedV1));
        $mergedV1Doc = new YDoc();
        $mergedV1Doc->applyUpdateV1($mergedV1);
        $this->assertXmlElementUpdateUtilsFixtureState($mergedV1Doc, $fixture);

        $mergedV2 = UpdateUtils::mergeUpdatesV2($this->decodeUpdates($fixture['updatesV2']));
        self::assertSame(base64_decode($fixture['mergedV2'], true), $mergedV2);
        self::assertSame($this->normalizeDecodedUpdate($fixture['mergedDecodedV2']), DecodedUpdate::decodeV2($mergedV2));
        $mergedV2Doc = new YDoc();
        $mergedV2Doc->applyUpdateV2($mergedV2);
        $this->assertXmlElementUpdateUtilsFixtureState($mergedV2Doc, $fixture);

        $diffV1 = UpdateUtils::diffUpdateV1($mergedV1, $targetStateVector);
        self::assertSame(base64_decode($fixture['diffV1'], true), $diffV1);
        self::assertSame($this->normalizeDecodedUpdate($fixture['diffDecodedV1']), DecodedUpdate::decodeV1($diffV1));
        $diffV1Doc = new YDoc();
        $diffV1Doc->applyUpdateV1($prefixV1);
        $diffV1Doc->applyUpdateV1($diffV1);
        $this->assertXmlElementUpdateUtilsFixtureState($diffV1Doc, $fixture);

        $diffV2 = UpdateUtils::diffUpdateV2($mergedV2, $targetStateVector);
        self::assertSame(base64_decode($fixture['diffV2'], true), $diffV2);
        self::assertSame($this->normalizeDecodedUpdate($fixture['diffDecodedV2']), DecodedUpdate::decodeV2($diffV2));
        $diffV2Doc = new YDoc();
        $diffV2Doc->applyUpdateV2($prefixV2);
        $diffV2Doc->applyUpdateV2($diffV2);
        $this->assertXmlElementUpdateUtilsFixtureState($diffV2Doc, $fixture);

        $convertedV2 = UpdateUtils::convertUpdateFormatV1ToV2($mergedV1);
        self::assertSame(base64_decode($fixture['convertedV2'], true), $convertedV2);
        self::assertSame($this->normalizeDecodedUpdate($fixture['convertedDecodedV2']), DecodedUpdate::decodeV2($convertedV2));
        $convertedV2Doc = new YDoc();
        $convertedV2Doc->applyUpdateV2($convertedV2);
        $this->assertXmlElementUpdateUtilsFixtureState($convertedV2Doc, $fixture);

        $convertedV1 = UpdateUtils::convertUpdateFormatV2ToV1($mergedV2);
        self::assertSame(base64_decode($fixture['convertedV1'], true), $convertedV1);
        self::assertSame($this->normalizeDecodedUpdate($fixture['convertedDecodedV1']), DecodedUpdate::decodeV1($convertedV1));
        $convertedV1Doc = new YDoc();
        $convertedV1Doc->applyUpdateV1($convertedV1);
        $this->assertXmlElementUpdateUtilsFixtureState($convertedV1Doc, $fixture);
    }

    public function testXmlTextUpdateUtilsPreserveSharedAttributeTypes(): void
    {
        $fixture = $this->loadFixture()['xmlText'];
        $targetStateVector = base64_decode($fixture['targetStateVectorV1'], true);
        $prefixV1 = base64_decode($fixture['prefixV1'], true);
        $prefixV2 = base64_decode($fixture['prefixV2'], true);
        self::assertIsString($targetStateVector);
        self::assertIsString($prefixV1);
        self::assertIsString($prefixV2);

        $mergedV1 = UpdateUtils::mergeUpdatesV1($this->decodeUpdates($fixture['updatesV1']));
        self::assertSame(base64_decode($fixture['mergedV1'], true), $mergedV1);
        self::assertSame($this->normalizeDecodedUpdate($fixture['mergedDecodedV1']), DecodedUpdate::decodeV1($mergedV1));
        $mergedV1Doc = new YDoc();
        $mergedV1Doc->applyUpdateV1($mergedV1);
        $this->assertXmlTextUpdateUtilsFixtureState($mergedV1Doc, $fixture);

        $mergedV2 = UpdateUtils::mergeUpdatesV2($this->decodeUpdates($fixture['updatesV2']));
        self::assertSame(base64_decode($fixture['mergedV2'], true), $mergedV2);
        self::assertSame($this->normalizeDecodedUpdate($fixture['mergedDecodedV2']), DecodedUpdate::decodeV2($mergedV2));
        $mergedV2Doc = new YDoc();
        $mergedV2Doc->applyUpdateV2($mergedV2);
        $this->assertXmlTextUpdateUtilsFixtureState($mergedV2Doc, $fixture);

        $diffV1 = UpdateUtils::diffUpdateV1($mergedV1, $targetStateVector);
        self::assertSame(base64_decode($fixture['diffV1'], true), $diffV1);
        self::assertSame($this->normalizeDecodedUpdate($fixture['diffDecodedV1']), DecodedUpdate::decodeV1($diffV1));
        $diffV1Doc = new YDoc();
        $diffV1Doc->applyUpdateV1($prefixV1);
        $diffV1Doc->applyUpdateV1($diffV1);
        $this->assertXmlTextUpdateUtilsFixtureState($diffV1Doc, $fixture);

        $diffV2 = UpdateUtils::diffUpdateV2($mergedV2, $targetStateVector);
        self::assertSame(base64_decode($fixture['diffV2'], true), $diffV2);
        self::assertSame($this->normalizeDecodedUpdate($fixture['diffDecodedV2']), DecodedUpdate::decodeV2($diffV2));
        $diffV2Doc = new YDoc();
        $diffV2Doc->applyUpdateV2($prefixV2);
        $diffV2Doc->applyUpdateV2($diffV2);
        $this->assertXmlTextUpdateUtilsFixtureState($diffV2Doc, $fixture);

        $convertedV2 = UpdateUtils::convertUpdateFormatV1ToV2($mergedV1);
        self::assertSame(base64_decode($fixture['convertedV2'], true), $convertedV2);
        self::assertSame($this->normalizeDecodedUpdate($fixture['convertedDecodedV2']), DecodedUpdate::decodeV2($convertedV2));
        $convertedV2Doc = new YDoc();
        $convertedV2Doc->applyUpdateV2($convertedV2);
        $this->assertXmlTextUpdateUtilsFixtureState($convertedV2Doc, $fixture);

        $convertedV1 = UpdateUtils::convertUpdateFormatV2ToV1($mergedV2);
        self::assertSame(base64_decode($fixture['convertedV1'], true), $convertedV1);
        self::assertSame($this->normalizeDecodedUpdate($fixture['convertedDecodedV1']), DecodedUpdate::decodeV1($convertedV1));
        $convertedV1Doc = new YDoc();
        $convertedV1Doc->applyUpdateV1($convertedV1);
        $this->assertXmlTextUpdateUtilsFixtureState($convertedV1Doc, $fixture);
    }

    public function testObfuscateUpdateV1MatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture()['obfuscate'];
        $update = base64_decode($fixture['updateV1'], true);
        self::assertIsString($update);

        $obfuscated = UpdateUtils::obfuscateUpdateV1($update);

        self::assertSame(base64_decode($fixture['obfuscatedV1'], true), $obfuscated);
        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['obfuscatedDecodedV1']),
            DecodedUpdate::decodeV1($obfuscated)
        );
    }

    public function testObfuscateUpdateV2MatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture()['obfuscate'];
        $update = base64_decode($fixture['updateV2'], true);
        self::assertIsString($update);

        $obfuscated = UpdateUtils::obfuscateUpdateV2($update);

        self::assertSame(base64_decode($fixture['obfuscatedV2'], true), $obfuscated);
        self::assertSame(
            $this->normalizeDecodedUpdate($fixture['obfuscatedDecodedV2']),
            DecodedUpdate::decodeV2($obfuscated)
        );
    }

    public function testObfuscateUpdateOptionsMatchYjsFixtures(): void
    {
        $fixture = $this->loadFixture()['obfuscate'];
        $updateV1 = base64_decode($fixture['updateV1'], true);
        $updateV2 = base64_decode($fixture['updateV2'], true);
        self::assertIsString($updateV1);
        self::assertIsString($updateV2);
        self::assertIsArray($fixture['optionVariants']);

        foreach ($fixture['optionVariants'] as $name => $variant) {
            self::assertIsArray($variant, (string) $name);
            self::assertIsArray($variant['options'], (string) $name);

            $obfuscatedV1 = UpdateUtils::obfuscateUpdateV1($updateV1, $variant['options']);
            self::assertSame(base64_decode($variant['obfuscatedV1'], true), $obfuscatedV1, (string) $name);
            self::assertSame(
                $this->normalizeDecodedUpdate($variant['obfuscatedDecodedV1']),
                DecodedUpdate::decodeV1($obfuscatedV1),
                (string) $name
            );

            $obfuscatedV2 = UpdateUtils::obfuscateUpdateV2($updateV2, $variant['options']);
            self::assertSame(base64_decode($variant['obfuscatedV2'], true), $obfuscatedV2, (string) $name);
            self::assertSame(
                $this->normalizeDecodedUpdate($variant['obfuscatedDecodedV2']),
                DecodedUpdate::decodeV2($obfuscatedV2),
                (string) $name
            );
        }
    }

    public function testMergeDeleteSetsMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture()['deleteSetUtils'];
        $deleteSetA = $this->normalizeDeleteSet($fixture['deleteSetA']);
        $deleteSetB = $this->normalizeDeleteSet($fixture['deleteSetB']);

        self::assertSame(
            $this->normalizeDeleteSet($fixture['merged']),
            UpdateUtils::mergeDeleteSets([$deleteSetA, $deleteSetB])
        );
        self::assertSame(
            $this->normalizeDeleteSet($fixture['mergedReverse']),
            UpdateUtils::mergeDeleteSets([$deleteSetB, $deleteSetA])
        );
    }

    public function testEqualDeleteSetsMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture()['deleteSetUtils'];
        $deleteSetA = $this->normalizeDeleteSet($fixture['deleteSetA']);
        $deleteSetB = $this->normalizeDeleteSet($fixture['deleteSetB']);
        $merged = $this->normalizeDeleteSet($fixture['merged']);
        $mergedReverse = $this->normalizeDeleteSet($fixture['mergedReverse']);

        self::assertSame($fixture['equalMergedOrders'], UpdateUtils::equalDeleteSets($merged, $mergedReverse));
        self::assertSame($fixture['equalOriginals'], UpdateUtils::equalDeleteSets($deleteSetA, $deleteSetB));
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
     * @param list<string> $updates
     * @return list<string>
     */
    private function decodeUpdates(array $updates): array
    {
        return array_map(static function (string $update): string {
            $decoded = base64_decode($update, true);
            self::assertIsString($decoded);

            return $decoded;
        }, $updates);
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

        return [
            'structs' => $decoded['structs'],
            'deleteSet' => $deleteSet,
        ];
    }

    /**
     * @param array<string, list<array{clock: int, length: int}>|null> $deleteSet
     * @return array<int, list<array{clock: int, length: int}>>
     */
    private function normalizeDeleteSet(array $deleteSet): array
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
     * @param array<string, mixed> $fixture
     */
    private function assertDeepMapFixtureState(YDoc $doc, array $fixture): void
    {
        self::assertSame(
            $this->sortAssociativeArrays($fixture['json']),
            $this->sortAssociativeArrays($doc->toJSON())
        );

        $root = $doc->getMap('map')->getMap('root');
        self::assertNotNull($root);
        self::assertSame('Base', $root->get('title'));
        self::assertSame([7, 8, 255], $root->get('bytes'));
        self::assertSame(['A', 'B', 'C'], $root->getArray('items')?->toArray());
        self::assertSame(['count' => 3, 'status' => 'ok'], $root->getMap('meta')?->toArray());
        self::assertSame($fixture['bodyDelta'], $root->getText('body')?->toDelta());

        $xml = $root->getXmlFragment('xml');
        self::assertNotNull($xml);
        self::assertSame($fixture['xmlString'], $xml->toString());
        self::assertSame($fixture['xmlLength'], $xml->length());
        self::assertSame($fixture['xmlChildren'], array_map(
            fn (mixed $node): array => $this->summarizeXmlNode($node),
            $xml->toArray()
        ));
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertXmlHookUpdateUtilsFixtureState(YDoc $doc, array $fixture): void
    {
        self::assertSame(
            $this->sortAssociativeArrays($fixture['json']),
            $this->sortAssociativeArrays($doc->toJSON())
        );

        $hook = $doc->getXmlFragment('xml')->get(0);
        self::assertInstanceOf(YXmlHook::class, $hook);
        self::assertSame($this->sortAssociativeArrays($fixture['hookJson']), $this->sortAssociativeArrays($hook->toJSON()));
        self::assertSame($fixture['bodyDelta'], $hook->getText('body')?->toDelta());
        self::assertSame(['A', 'B'], $hook->getArray('items')?->toArray());
        self::assertSame(['role' => 'author'], $hook->getMap('meta')?->toArray());
        self::assertSame($fixture['elementXml'], (string) $hook->getXmlElement('element'));
        self::assertSame($fixture['fragmentXml'], (string) $hook->getXmlFragment('fragment'));
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertXmlElementUpdateUtilsFixtureState(YDoc $doc, array $fixture): void
    {
        self::assertSame(
            $this->sortAssociativeArrays($fixture['json']),
            $this->sortAssociativeArrays($doc->toJSON())
        );

        $element = $doc->getXmlFragment('xml')->get(0);
        self::assertInstanceOf(YXmlElement::class, $element);
        self::assertSame($this->sortAssociativeArrays($fixture['elementAttributes']), $this->sortAssociativeArrays($element->getAttributes()));
        self::assertSame($fixture['bodyDelta'], $element->getText('body')?->toDelta());
        self::assertSame(['A', 'B'], $element->getArray('items')?->toArray());
        self::assertSame(['role' => 'author'], $element->getMap('meta')?->toArray());
        self::assertSame($fixture['inlineXml'], (string) $element->getXmlElement('inline'));
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertXmlTextUpdateUtilsFixtureState(YDoc $doc, array $fixture): void
    {
        self::assertSame(
            $this->sortAssociativeArrays($fixture['json']),
            $this->sortAssociativeArrays($doc->toJSON())
        );

        $text = $doc->getXmlFragment('xml')->get(0);
        self::assertInstanceOf(YXmlText::class, $text);
        self::assertSame($this->sortAssociativeArrays($fixture['textAttributes']), $this->sortAssociativeArrays($text->getAttributes()));
        self::assertSame($fixture['bodyDelta'], $text->getText('body')?->toDelta());
        self::assertSame(['A', 'B'], $text->getArray('items')?->toArray());
        self::assertSame(['role' => 'caption'], $text->getMap('meta')?->toArray());
        self::assertSame($fixture['inlineXml'], (string) $text->getXmlElement('inline'));
        self::assertSame($fixture['labelDelta'], $text->getXmlText('label')?->toDelta());
        self::assertSame($fixture['fragmentXml'], (string) $text->getXmlFragment('fragment'));
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertXmlTextDeleteEditConflictState(YDoc $doc, array $fixture): void
    {
        self::assertSame(
            $this->sortAssociativeArrays($fixture['json']),
            $this->sortAssociativeArrays($doc->toJSON())
        );

        $text = $doc->getXmlFragment('xml')->get(0);
        self::assertInstanceOf(YXmlText::class, $text);
        self::assertSame(
            $this->sortAssociativeArrays($fixture['xmlTextAttributes']),
            $this->sortAssociativeArrays($text->getAttributes())
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

    /**
     * @param array{
     *     structs: list<array<string, mixed>>,
     *     deleteSet: array<int, list<array{clock: int, length: int}>>
     * } $decoded
     */
    private function assertDecodedContainsContentString(array $decoded, string $value): void
    {
        foreach ($decoded['structs'] as $struct) {
            if (($struct['content']['type'] ?? null) === 'ContentString' && ($struct['content']['value'] ?? null) === $value) {
                self::assertTrue(true);

                return;
            }
        }

        self::fail(sprintf('Decoded update does not contain ContentString "%s".', $value));
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
}
