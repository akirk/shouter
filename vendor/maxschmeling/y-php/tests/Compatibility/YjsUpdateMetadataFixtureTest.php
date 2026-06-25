<?php

declare(strict_types=1);

namespace Yjs\Tests\Compatibility;

use PHPUnit\Framework\TestCase;
use Yjs\Update\UpdateMetadata;
use Yjs\Update\UpdateUtils;

final class YjsUpdateMetadataFixtureTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__ . '/../../fixtures/generated/yjs-13.6.31/document-updates.json';

    public function testParseV1UpdateMetadataMatchesYjsFixture(): void
    {
        $fixtures = $this->loadFixtures();
        $update = base64_decode($fixtures['updateV1'], true);

        self::assertIsString($update);
        self::assertSame(
            $this->normalizeIntegerMapFixture($fixtures['updateV1Meta']),
            UpdateMetadata::parseV1($update)
        );
    }

    public function testParseV2UpdateMetadataMatchesYjsFixture(): void
    {
        $fixtures = $this->loadFixtures();
        $update = base64_decode($fixtures['updateV2'], true);

        self::assertIsString($update);
        self::assertSame(
            $this->normalizeIntegerMapFixture($fixtures['updateV2Meta']),
            UpdateMetadata::parseV2($update)
        );
    }

    public function testUpdateUtilsParseV1UpdateMetadataMatchesYjsFixture(): void
    {
        $fixtures = $this->loadFixtures();
        $update = base64_decode($fixtures['updateV1'], true);

        self::assertIsString($update);
        self::assertSame(
            $this->normalizeIntegerMapFixture($fixtures['updateV1Meta']),
            UpdateUtils::parseUpdateMetaV1($update)
        );
    }

    public function testUpdateUtilsParseUpdateMetaAliasMatchesYjsV1Fixture(): void
    {
        $fixtures = $this->loadFixtures();
        $update = base64_decode($fixtures['updateV1'], true);

        self::assertIsString($update);
        self::assertSame(
            $this->normalizeIntegerMapFixture($fixtures['updateV1Meta']),
            UpdateUtils::parseUpdateMeta($update)
        );
    }

    public function testUpdateUtilsParseV2UpdateMetadataMatchesYjsFixture(): void
    {
        $fixtures = $this->loadFixtures();
        $update = base64_decode($fixtures['updateV2'], true);

        self::assertIsString($update);
        self::assertSame(
            $this->normalizeIntegerMapFixture($fixtures['updateV2Meta']),
            UpdateUtils::parseUpdateMetaV2($update)
        );
    }

    public function testParsePendingGapUpdateMetadataMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixtures()['pendingGap'];
        $suffixV1 = base64_decode($fixture['suffixUpdateV1'], true);
        $suffixV2 = base64_decode($fixture['suffixUpdateV2'], true);
        $prefixV1 = base64_decode($fixture['prefixUpdateV1'], true);
        $prefixV2 = base64_decode($fixture['prefixUpdateV2'], true);
        self::assertIsString($suffixV1);
        self::assertIsString($suffixV2);
        self::assertIsString($prefixV1);
        self::assertIsString($prefixV2);

        self::assertSame($this->normalizeIntegerMapFixture($fixture['suffixMetaV1']), UpdateMetadata::parseV1($suffixV1));
        self::assertSame($this->normalizeIntegerMapFixture($fixture['suffixMetaV2']), UpdateMetadata::parseV2($suffixV2));
        self::assertSame($this->normalizeIntegerMapFixture($fixture['prefixMetaV1']), UpdateMetadata::parseV1($prefixV1));
        self::assertSame($this->normalizeIntegerMapFixture($fixture['prefixMetaV2']), UpdateMetadata::parseV2($prefixV2));
        self::assertSame($this->normalizeIntegerMapFixture($fixture['suffixMetaV1']), UpdateUtils::parseUpdateMetaV1($suffixV1));
        self::assertSame($this->normalizeIntegerMapFixture($fixture['suffixMetaV2']), UpdateUtils::parseUpdateMetaV2($suffixV2));
        self::assertSame($this->normalizeIntegerMapFixture($fixture['prefixMetaV1']), UpdateUtils::parseUpdateMetaV1($prefixV1));
        self::assertSame($this->normalizeIntegerMapFixture($fixture['prefixMetaV2']), UpdateUtils::parseUpdateMetaV2($prefixV2));
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFixtures(): array
    {
        self::assertFileExists(self::FIXTURE_PATH, 'Run `npm run fixtures` before PHPUnit.');

        $decoded = json_decode((string) file_get_contents(self::FIXTURE_PATH), true);

        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array{from: array<string, int>, to: array<string, int>} $fixture
     * @return array{from: array<int, int>, to: array<int, int>}
     */
    private function normalizeIntegerMapFixture(array $fixture): array
    {
        return [
            'from' => array_map('intval', array_combine(array_map('intval', array_keys($fixture['from'])), $fixture['from'])),
            'to' => array_map('intval', array_combine(array_map('intval', array_keys($fixture['to'])), $fixture['to'])),
        ];
    }
}
