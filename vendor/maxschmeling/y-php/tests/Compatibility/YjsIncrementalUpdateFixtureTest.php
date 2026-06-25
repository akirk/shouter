<?php

declare(strict_types=1);

namespace Yjs\Tests\Compatibility;

use PHPUnit\Framework\TestCase;
use Yjs\YDoc;

final class YjsIncrementalUpdateFixtureTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__ . '/../../fixtures/generated/yjs-13.6.31/incremental-v1.json';

    public function testApplyingIncrementalV1UpdatesMatchesYjsFixtures(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $doc = new YDoc();

            foreach ($case['updatesV1'] as $encodedUpdate) {
                $update = base64_decode($encodedUpdate, true);
                self::assertIsString($update);
                $doc->applyUpdateV1($update);
            }

            self::assertSame($case['json'], $doc->toJSON(), sprintf('Failed applying incremental case "%s".', $case['name']));
        }
    }

    public function testApplyingIncrementalV2UpdatesMatchesYjsFixtures(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $doc = new YDoc();

            foreach ($case['updatesV2'] as $encodedUpdate) {
                $update = base64_decode($encodedUpdate, true);
                self::assertIsString($update);
                $doc->applyUpdateV2($update);
            }

            self::assertSame($case['json'], $doc->toJSON(), sprintf('Failed applying incremental V2 case "%s".', $case['name']));
        }
    }

    public function testApplyingFullUpdateMatchesIncrementalUpdates(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $doc = new YDoc();
            $update = base64_decode($case['finalUpdateV1'], true);
            self::assertIsString($update);

            $doc->applyUpdateV1($update);

            self::assertSame($case['json'], $doc->toJSON(), sprintf('Failed applying full update for case "%s".', $case['name']));
        }
    }

    public function testApplyingFullV2UpdateMatchesIncrementalUpdates(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $doc = new YDoc();
            $update = base64_decode($case['finalUpdateV2'], true);
            self::assertIsString($update);

            $doc->applyUpdateV2($update);

            self::assertSame($case['json'], $doc->toJSON(), sprintf('Failed applying full V2 update for case "%s".', $case['name']));
        }
    }

    public function testStateVectorAfterIncrementalUpdatesMatchesYjsFixtures(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $doc = new YDoc();

            foreach ($case['updatesV1'] as $encodedUpdate) {
                $update = base64_decode($encodedUpdate, true);
                self::assertIsString($update);
                $doc->applyUpdateV1($update);
            }

            self::assertSame(
                base64_decode($case['finalStateVectorV1'], true),
                $doc->encodeStateVector(),
                sprintf('Failed state vector for case "%s".', $case['name'])
            );
        }
    }

    public function testStateVectorAfterIncrementalV2UpdatesMatchesYjsFixtures(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $doc = new YDoc();

            foreach ($case['updatesV2'] as $encodedUpdate) {
                $update = base64_decode($encodedUpdate, true);
                self::assertIsString($update);
                $doc->applyUpdateV2($update);
            }

            self::assertSame(
                base64_decode($case['finalStateVectorV1'], true),
                $doc->encodeStateVector(),
                sprintf('Failed V2 state vector for case "%s".', $case['name'])
            );
        }
    }

    public function testEncodingStateAfterIncrementalUpdatesMatchesYjsState(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $doc = new YDoc();

            foreach ($case['updatesV1'] as $encodedUpdate) {
                $update = base64_decode($encodedUpdate, true);
                self::assertIsString($update);
                $doc->applyUpdateV1($update);
            }

            $encoded = $doc->encodeStateAsUpdateV1();
            $target = new YDoc();
            $target->applyUpdateV1($encoded);

            self::assertSame($case['json'], $target->toJSON(), sprintf('Failed re-encoding incremental case "%s".', $case['name']));
            self::assertSame(base64_decode($case['finalStateVectorV1'], true), $target->encodeStateVector());
        }
    }

    public function testEncodingV2StateAfterIncrementalUpdatesMatchesYjsState(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $doc = new YDoc();

            foreach ($case['updatesV2'] as $encodedUpdate) {
                $update = base64_decode($encodedUpdate, true);
                self::assertIsString($update);
                $doc->applyUpdateV2($update);
            }

            $encoded = $doc->encodeStateAsUpdateV2();
            $target = new YDoc();
            $target->applyUpdateV2($encoded);

            self::assertSame($case['json'], $target->toJSON(), sprintf('Failed re-encoding incremental V2 case "%s".', $case['name']));
            self::assertSame(base64_decode($case['finalStateVectorV1'], true), $target->encodeStateVector());
        }
    }

    public function testEncodedStateUpdateCanHydrateFreshPhpDoc(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $source = new YDoc();

            foreach ($case['updatesV1'] as $encodedUpdate) {
                $update = base64_decode($encodedUpdate, true);
                self::assertIsString($update);
                $source->applyUpdateV1($update);
            }

            $target = new YDoc();
            $target->applyUpdateV1($source->encodeStateAsUpdateV1());

            self::assertSame($case['json'], $target->toJSON(), sprintf('Failed hydrating encoded update for case "%s".', $case['name']));
        }
    }

    public function testEncodedV2StateUpdateCanHydrateFreshPhpDoc(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $source = new YDoc();

            foreach ($case['updatesV2'] as $encodedUpdate) {
                $update = base64_decode($encodedUpdate, true);
                self::assertIsString($update);
                $source->applyUpdateV2($update);
            }

            $target = new YDoc();
            $target->applyUpdateV2($source->encodeStateAsUpdateV2());

            self::assertSame($case['json'], $target->toJSON(), sprintf('Failed hydrating encoded V2 update for case "%s".', $case['name']));
        }
    }

    public function testStateVectorDiffUpdateHydratesPartiallySyncedPhpDoc(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            if (count($case['updatesV1']) < 2) {
                continue;
            }

            $source = new YDoc();
            $target = new YDoc();

            $firstUpdate = base64_decode($case['updatesV1'][0], true);
            self::assertIsString($firstUpdate);
            $target->applyUpdateV1($firstUpdate);

            foreach ($case['updatesV1'] as $encodedUpdate) {
                $update = base64_decode($encodedUpdate, true);
                self::assertIsString($update);
                $source->applyUpdateV1($update);
            }

            $diff = $source->encodeStateAsUpdateV1($target->encodeStateVector());
            $target->applyUpdateV1($diff);

            self::assertSame($case['json'], $target->toJSON(), sprintf('Failed diff sync for case "%s".', $case['name']));
            self::assertSame($source->encodeStateVector(), $target->encodeStateVector(), sprintf('Failed diff state vector for case "%s".', $case['name']));
        }
    }

    public function testStateVectorV2DiffUpdateHydratesPartiallySyncedPhpDoc(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            if (count($case['updatesV2']) < 2) {
                continue;
            }

            $source = new YDoc();
            $target = new YDoc();

            $firstUpdate = base64_decode($case['updatesV2'][0], true);
            self::assertIsString($firstUpdate);
            $target->applyUpdateV2($firstUpdate);

            foreach ($case['updatesV2'] as $encodedUpdate) {
                $update = base64_decode($encodedUpdate, true);
                self::assertIsString($update);
                $source->applyUpdateV2($update);
            }

            $diff = $source->encodeStateAsUpdateV2($target->encodeStateVector());
            $target->applyUpdateV2($diff);

            self::assertSame($case['json'], $target->toJSON(), sprintf('Failed V2 diff sync for case "%s".', $case['name']));
            self::assertSame($source->encodeStateVector(), $target->encodeStateVector(), sprintf('Failed V2 diff state vector for case "%s".', $case['name']));
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
