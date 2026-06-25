<?php

declare(strict_types=1);

namespace Yjs\Tests\Compatibility;

use PHPUnit\Framework\TestCase;
use Yjs\YDoc;

final class YjsTextDeltaFixtureTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__ . '/../../fixtures/generated/yjs-13.6.31/text-deltas.json';

    public function testTextDeltasMatchYjsFixturesAfterV1Updates(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $doc = new YDoc();
            $update = base64_decode($case['updateV1'], true);
            self::assertIsString($update);

            $doc->applyUpdateV1($update);

            self::assertSame($case['delta'], $doc->getText('content')->toDelta(), sprintf('Failed V1 delta case "%s".', $case['name']));
            self::assertSame($case['json'], $doc->toJSON(), sprintf('Failed V1 JSON case "%s".', $case['name']));
        }
    }

    public function testTextDeltasMatchYjsFixturesAfterV2Updates(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $doc = new YDoc();
            $update = base64_decode($case['updateV2'], true);
            self::assertIsString($update);

            $doc->applyUpdateV2($update);

            self::assertSame($case['delta'], $doc->getText('content')->toDelta(), sprintf('Failed V2 delta case "%s".', $case['name']));
            self::assertSame($case['json'], $doc->toJSON(), sprintf('Failed V2 JSON case "%s".', $case['name']));
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
}
