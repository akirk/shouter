<?php

declare(strict_types=1);

namespace Yjs\Tests\Compatibility;

use PHPUnit\Framework\TestCase;
use Yjs\StateVector;
use Yjs\YDoc;

final class YjsStateVectorFixtureTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__ . '/../../fixtures/generated/yjs-13.6.31/document-updates.json';

    public function testDecodeStateVectorFromYjsDocumentFixture(): void
    {
        $fixtures = $this->loadFixtures();
        $stateVector = base64_decode($fixtures['stateVectorV1'], true);

        self::assertIsString($stateVector);
        self::assertSame([1 => 11], StateVector::decode($stateVector));
    }

    public function testEncodeStateVectorMatchesYjsDocumentFixture(): void
    {
        $fixtures = $this->loadFixtures();
        $stateVector = base64_decode($fixtures['stateVectorV1'], true);

        self::assertIsString($stateVector);
        self::assertSame($stateVector, StateVector::encode([1 => 11]));
    }

    public function testPendingV1DiffDoesNotAdvanceStateVectorPastGap(): void
    {
        $fixture = $this->loadFixtures()['pendingGap'];
        $doc = new YDoc();
        $suffix = base64_decode($fixture['suffixUpdateV1'], true);
        $prefix = base64_decode($fixture['prefixUpdateV1'], true);
        self::assertIsString($suffix);
        self::assertIsString($prefix);

        $doc->applyUpdateV1($suffix);

        self::assertSame(base64_decode($fixture['stateVectorAfterSuffixV1'], true), $doc->encodeStateVector());

        $doc->applyUpdateV1($prefix);

        self::assertSame($fixture['json'], $doc->toJSON());
        self::assertSame(base64_decode($fixture['stateVectorAfterPrefixV1'], true), $doc->encodeStateVector());
    }

    public function testPendingV2DiffDoesNotAdvanceStateVectorPastGap(): void
    {
        $fixture = $this->loadFixtures()['pendingGap'];
        $doc = new YDoc();
        $suffix = base64_decode($fixture['suffixUpdateV2'], true);
        $prefix = base64_decode($fixture['prefixUpdateV2'], true);
        self::assertIsString($suffix);
        self::assertIsString($prefix);

        $doc->applyUpdateV2($suffix);

        self::assertSame(base64_decode($fixture['stateVectorAfterSuffixV1'], true), $doc->encodeStateVector());

        $doc->applyUpdateV2($prefix);

        self::assertSame($fixture['json'], $doc->toJSON());
        self::assertSame(base64_decode($fixture['stateVectorAfterPrefixV1'], true), $doc->encodeStateVector());
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
}
