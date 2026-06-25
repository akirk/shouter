<?php

declare(strict_types=1);

namespace Yjs\Tests\Compatibility;

use PHPUnit\Framework\TestCase;
use Yjs\PermanentUserData;
use Yjs\YDoc;

final class YjsPermanentUserDataFixtureTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__ . '/../../fixtures/generated/yjs-13.6.31/permanent-user-data.json';

    public function testPermanentUserDataCapturesLocalDeleteSetsLikeYjsFixture(): void
    {
        $fixture = $this->loadFixture()['user'];
        $doc = new YDoc($fixture['clientID']);
        $userData = new PermanentUserData($doc);

        $userData->setUserMapping($doc, $fixture['clientID'], $fixture['description']);
        $doc->getText('content')->insert(0, 'ABCDE');
        $doc->getText('content')->delete(1, 2);

        self::assertSame($fixture['clientLookup'], $userData->getUserByClientId($fixture['clientID']));
        self::assertSame(
            $fixture['encodedDeleteSets'],
            array_map(static fn (string $bytes): string => base64_encode($bytes), $userData->encodedDeleteSets($fixture['description']))
        );

        foreach ($fixture['deletedLookups'] as $lookup) {
            self::assertSame($lookup['user'], $userData->getUserByDeletedId($lookup['id']));
        }
    }

    public function testPermanentUserDataHydratesPersistedYjsV1Store(): void
    {
        $fixture = $this->loadFixture()['user'];
        $doc = new YDoc();
        $update = base64_decode($fixture['updateV1'], true);
        self::assertIsString($update);
        $doc->applyUpdateV1($update);

        $userData = new PermanentUserData($doc);

        $this->assertHydratedFixture($fixture, $userData);
    }

    public function testPermanentUserDataHydratesPersistedYjsV2Store(): void
    {
        $fixture = $this->loadFixture()['user'];
        $doc = new YDoc();
        $update = base64_decode($fixture['updateV2'], true);
        self::assertIsString($update);
        $doc->applyUpdateV2($update);

        $userData = new PermanentUserData($doc);

        $this->assertHydratedFixture($fixture, $userData);
    }

    public function testPermanentUserDataHydratesPersistedPhpNativeStore(): void
    {
        $fixture = $this->loadFixture()['user'];
        $doc = new YDoc($fixture['clientID']);
        $capturingUserData = new PermanentUserData($doc);
        $capturingUserData->setUserMapping($doc, $fixture['clientID'], $fixture['description']);
        $doc->getText('content')->insert(0, 'ABCDE');
        $doc->getText('content')->delete(1, 2);

        $hydratedUserData = new PermanentUserData($doc);

        $this->assertHydratedFixture($fixture, $hydratedUserData);
    }

    public function testPermanentUserDataFilterCanSkipDeleteSetCapture(): void
    {
        $fixture = $this->loadFixture()['filtered'];
        $doc = new YDoc($fixture['clientID']);
        $userData = new PermanentUserData($doc);

        $userData->setUserMapping(
            $doc,
            $fixture['clientID'],
            $fixture['description'],
            static fn (array $event, array $deleteSet): bool => false
        );
        $doc->getText('content')->insert(0, 'AB');
        $doc->getText('content')->delete(0, 1);

        self::assertSame($fixture['clientLookup'], $userData->getUserByClientId($fixture['clientID']));
        self::assertSame($fixture['encodedDeleteSets'], $userData->encodedDeleteSets($fixture['description']));
        self::assertSame($fixture['deletedLookup'], $userData->getUserByDeletedId(['client' => 312, 'clock' => 5]));
    }

    public function testPermanentUserDataRejectsTrailingDeleteSetBytes(): void
    {
        $fixture = $this->loadFixture()['user'];
        $encoded = base64_decode($fixture['encodedDeleteSets'][0], true);
        self::assertIsString($encoded);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Permanent user data delete set contains trailing bytes.');

        PermanentUserData::decodeDeleteSet($encoded . "\x00");
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
     * @param array<string, mixed> $fixture
     */
    private function assertHydratedFixture(array $fixture, PermanentUserData $userData): void
    {
        self::assertSame($fixture['clientLookup'], $userData->getUserByClientId($fixture['clientID']));
        self::assertSame(
            $fixture['encodedDeleteSets'],
            array_map(static fn (string $bytes): string => base64_encode($bytes), $userData->encodedDeleteSets($fixture['description']))
        );

        foreach ($fixture['deletedLookups'] as $lookup) {
            self::assertSame($lookup['user'], $userData->getUserByDeletedId($lookup['id']));
        }
    }
}
