<?php

declare(strict_types=1);

namespace Yjs\Tests\Compatibility;

use PHPUnit\Framework\TestCase;
use Yjs\Sync\SyncProtocol;
use Yjs\Update\DecodedUpdate;
use Yjs\YDoc;
use Yjs\YNestedText;
use Yjs\YSubdoc;
use Yjs\YXmlText;

final class YjsSyncProtocolFixtureTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__ . '/../../fixtures/generated/yjs-13.6.31/sync-protocol.json';

    public function testWriteSyncStep1MatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = $this->fixtureDoc($fixture);

        self::assertSame(base64_decode($fixture['syncStep1'], true), SyncProtocol::writeSyncStep1($doc));
    }

    public function testWriteMessageMatchesYjsSyncStep1Fixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = $this->fixtureDoc($fixture);

        self::assertSame(
            base64_decode($fixture['syncStep1'], true),
            SyncProtocol::writeMessage(SyncProtocol::MESSAGE_SYNC_STEP_1, $doc->encodeStateVector())
        );
    }

    public function testWriteSyncStep2ForEmptyRemoteMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = $this->fixtureDoc($fixture);

        self::assertSame(base64_decode($fixture['syncStep2ForEmpty'], true), SyncProtocol::writeSyncStep2($doc, "\x00"));
    }

    public function testWriteReplyToSyncStep1MatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = $this->fixtureDoc($fixture);
        $remote = new YDoc();

        self::assertSame(
            base64_decode($fixture['syncStep2ForEmpty'], true),
            SyncProtocol::writeReplyToSyncStep1($doc, SyncProtocol::writeSyncStep1($remote))
        );
    }

    public function testWriteSyncStep2V2ForEmptyRemoteMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = $this->fixtureDoc($fixture);

        self::assertSame(base64_decode($fixture['syncStep2V2ForEmpty'], true), SyncProtocol::writeSyncStep2V2($doc, "\x00"));
    }

    public function testWriteReplyToSyncStep1V2MatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = $this->fixtureDoc($fixture);
        $remote = new YDoc();

        self::assertSame(
            base64_decode($fixture['syncStep2V2ForEmpty'], true),
            SyncProtocol::writeReplyToSyncStep1V2($doc, SyncProtocol::writeSyncStep1($remote))
        );
    }

    public function testWriteSyncStep2ForPartialRemoteMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = $this->fixtureDoc($fixture);
        $partialStateVector = $this->partialRemoteStateVector();

        self::assertSame(base64_decode($fixture['syncStep2ForPartial'], true), SyncProtocol::writeSyncStep2($doc, $partialStateVector));
    }

    public function testWriteSyncStep2V2ForPartialRemoteMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = $this->fixtureDoc($fixture);
        $partialStateVector = $this->partialRemoteStateVector();

        self::assertSame(base64_decode($fixture['syncStep2V2ForPartial'], true), SyncProtocol::writeSyncStep2V2($doc, $partialStateVector));
    }

    public function testPartialSubdocSyncStep2MatchesYjsFixtureAndAppliesMetadata(): void
    {
        $fixture = $this->loadFixture()['subdocPartial'];
        $source = $this->subdocSyncSource();
        $target = $this->subdocSyncPrefix();
        $expectedStep2 = base64_decode($fixture['syncStep2ForPrefix'], true);
        self::assertIsString($expectedStep2);

        $step2 = SyncProtocol::writeSyncStep2($source, $target->encodeStateVector());

        self::assertSame($expectedStep2, $step2);
        self::assertNull(SyncProtocol::handleMessage($target, $step2));
        self::assertSame($fixture['expectedJson'], $target->toJSON());
        self::assertSame(base64_decode($fixture['sourceStateVectorV1'], true), $target->encodeStateVector());
        $this->assertExpectedArraySubdocs($fixture['expectedArraySubdocs'], $target);
    }

    public function testPartialSubdocSyncStep2V2MatchesYjsFixtureAndAppliesMetadata(): void
    {
        $fixture = $this->loadFixture()['subdocPartial'];
        $source = $this->subdocSyncSource();
        $target = $this->subdocSyncPrefix();
        $expectedStep2 = base64_decode($fixture['syncStep2V2ForPrefix'], true);
        self::assertIsString($expectedStep2);

        $step2 = SyncProtocol::writeSyncStep2V2($source, $target->encodeStateVector());

        self::assertSame($expectedStep2, $step2);
        self::assertNull(SyncProtocol::handleMessageV2($target, $step2));
        self::assertSame($fixture['expectedJson'], $target->toJSON());
        self::assertSame(base64_decode($fixture['sourceStateVectorV1'], true), $target->encodeStateVector());
        $this->assertExpectedArraySubdocs($fixture['expectedArraySubdocs'], $target);
    }

    public function testApplyYjsFixtureSyncStep2Message(): void
    {
        $fixture = $this->loadFixture();
        $target = new YDoc();
        $message = base64_decode($fixture['syncStep2ForEmpty'], true);
        self::assertIsString($message);

        SyncProtocol::applySyncStep2($target, $message, 'yjs-fixture-sync-step2');

        self::assertSame($fixture['docJson'], $target->toJSON());
        self::assertSame(base64_decode($fixture['stateVectorV1'], true), $target->encodeStateVector());
    }

    public function testApplyYjsFixtureSyncStep2V2Message(): void
    {
        $fixture = $this->loadFixture();
        $target = new YDoc();
        $message = base64_decode($fixture['syncStep2V2ForEmpty'], true);
        self::assertIsString($message);

        SyncProtocol::applySyncStep2V2($target, $message, 'yjs-fixture-sync-step2-v2');

        self::assertSame($fixture['docJson'], $target->toJSON());
        self::assertSame(base64_decode($fixture['stateVectorV1'], true), $target->encodeStateVector());
    }

    public function testWriteSyncStep2CanSendDeleteSetOnlyUpdate(): void
    {
        $fixture = $this->loadFixture();
        $source = new YDoc(24);
        $source->getText('content')->insert(0, 'ABCD');
        $source->getText('content')->delete(1, 2);
        $remote = new YDoc(24);
        $remote->getText('content')->insert(0, 'ABCD');

        self::assertSame(
            base64_decode($fixture['syncStep2DeleteSetOnly'], true),
            SyncProtocol::writeSyncStep2($source, $remote->encodeStateVector())
        );
    }

    public function testWriteSyncStep2V2CanSendDeleteSetOnlyUpdate(): void
    {
        $fixture = $this->loadFixture();
        $source = new YDoc(24);
        $source->getText('content')->insert(0, 'ABCD');
        $source->getText('content')->delete(1, 2);
        $remote = new YDoc(24);
        $remote->getText('content')->insert(0, 'ABCD');

        self::assertSame(
            base64_decode($fixture['syncStep2V2DeleteSetOnly'], true),
            SyncProtocol::writeSyncStep2V2($source, $remote->encodeStateVector())
        );
    }

    public function testWriteUpdateMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $update = base64_decode($fixture['updateV1'], true);
        self::assertIsString($update);

        self::assertSame(base64_decode($fixture['updateMessage'], true), SyncProtocol::writeUpdate($update));
    }

    public function testWriteUpdateV2MatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $update = base64_decode($fixture['updateV2'], true);
        self::assertIsString($update);

        self::assertSame(base64_decode($fixture['updateV2Message'], true), SyncProtocol::writeUpdateV2($update));
    }

    public function testObserveUpdateMessagesWrapsLocalUpdatesUntilUnobserved(): void
    {
        $doc = new YDoc(25);
        $messages = [];
        $observerId = SyncProtocol::observeUpdateMessages($doc, static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $doc->getText('content')->insert(0, 'A');
        SyncProtocol::unobserveUpdateMessages($doc, $observerId);
        $doc->getText('content')->insert(1, 'B');

        self::assertCount(1, $messages);
        $decoded = SyncProtocol::readMessage($messages[0]);
        self::assertSame(SyncProtocol::MESSAGE_UPDATE, $decoded['type']);

        $target = new YDoc();
        self::assertNull(SyncProtocol::handleMessage($target, $messages[0]));
        self::assertSame(['content' => 'A'], $target->toJSON());
    }

    public function testObservedUpdateMessagesExposeDocAndOrigin(): void
    {
        $doc = new YDoc(27);
        $events = [];
        SyncProtocol::observeUpdateMessages($doc, static function (string $message, YDoc $observedDoc, mixed $origin) use (&$events, $doc): void {
            $events[] = [
                'type' => SyncProtocol::readMessage($message)['type'],
                'sameDoc' => $observedDoc === $doc,
                'origin' => $origin,
            ];
        });

        $doc->transact(static function () use ($doc): void {
            $doc->getText('content')->insert(0, 'A');
        }, 'sync-origin');

        self::assertSame([
            [
                'type' => SyncProtocol::MESSAGE_UPDATE,
                'sameDoc' => true,
                'origin' => 'sync-origin',
            ],
        ], $events);
    }

    public function testObserveUpdateMessagesOnceWrapsOneLocalUpdate(): void
    {
        $doc = new YDoc(29);
        $events = [];
        SyncProtocol::observeUpdateMessagesOnce($doc, static function (string $message, YDoc $observedDoc, mixed $origin) use (&$events, $doc): void {
            $events[] = [
                'message' => $message,
                'sameDoc' => $observedDoc === $doc,
                'origin' => $origin,
            ];
        });

        $doc->transact(static function (YDoc $doc): void {
            $doc->getText('content')->insert(0, 'A');
        }, 'sync-once-origin');
        $doc->getText('content')->insert(1, 'B');

        self::assertCount(1, $events);
        self::assertTrue($events[0]['sameDoc']);
        self::assertSame('sync-once-origin', $events[0]['origin']);
        self::assertSame(SyncProtocol::MESSAGE_UPDATE, SyncProtocol::readMessage($events[0]['message'])['type']);

        $target = new YDoc();
        self::assertNull(SyncProtocol::handleMessage($target, $events[0]['message']));
        self::assertSame(['content' => 'A'], $target->toJSON());
    }

    public function testObserveUpdateV2MessagesWrapsLocalUpdatesUntilUnobserved(): void
    {
        $doc = new YDoc(26);
        $messages = [];
        $observerId = SyncProtocol::observeUpdateV2Messages($doc, static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $doc->getText('content')->insert(0, 'A');
        SyncProtocol::unobserveUpdateV2Messages($doc, $observerId);
        $doc->getText('content')->insert(1, 'B');

        self::assertCount(1, $messages);
        $decoded = SyncProtocol::readMessage($messages[0]);
        self::assertSame(SyncProtocol::MESSAGE_UPDATE, $decoded['type']);

        $target = new YDoc();
        self::assertNull(SyncProtocol::handleMessageV2($target, $messages[0]));
        self::assertSame(['content' => 'A'], $target->toJSON());
    }

    public function testObservedUpdateV2MessagesExposeDocAndOrigin(): void
    {
        $doc = new YDoc(28);
        $events = [];
        SyncProtocol::observeUpdateV2Messages($doc, static function (string $message, YDoc $observedDoc, mixed $origin) use (&$events, $doc): void {
            $events[] = [
                'type' => SyncProtocol::readMessage($message)['type'],
                'sameDoc' => $observedDoc === $doc,
                'origin' => $origin,
            ];
        });

        $doc->transact(static function () use ($doc): void {
            $doc->getText('content')->insert(0, 'A');
        }, 'sync-v2-origin');

        self::assertSame([
            [
                'type' => SyncProtocol::MESSAGE_UPDATE,
                'sameDoc' => true,
                'origin' => 'sync-v2-origin',
            ],
        ], $events);
    }

    public function testObserveUpdateV2MessagesOnceWrapsOneLocalUpdate(): void
    {
        $doc = new YDoc(30);
        $events = [];
        SyncProtocol::observeUpdateV2MessagesOnce($doc, static function (string $message, YDoc $observedDoc, mixed $origin) use (&$events, $doc): void {
            $events[] = [
                'message' => $message,
                'sameDoc' => $observedDoc === $doc,
                'origin' => $origin,
            ];
        });

        $doc->transact(static function (YDoc $doc): void {
            $doc->getText('content')->insert(0, 'V');
        }, 'sync-v2-once-origin');
        $doc->getText('content')->insert(1, '2');

        self::assertCount(1, $events);
        self::assertTrue($events[0]['sameDoc']);
        self::assertSame('sync-v2-once-origin', $events[0]['origin']);
        self::assertSame(SyncProtocol::MESSAGE_UPDATE, SyncProtocol::readMessage($events[0]['message'])['type']);

        $target = new YDoc();
        self::assertNull(SyncProtocol::handleMessageV2($target, $events[0]['message']));
        self::assertSame(['content' => 'V'], $target->toJSON());
    }

    public function testReadSyncMessageRejectsTrailingBytes(): void
    {
        $fixture = $this->loadFixture();
        $message = base64_decode($fixture['syncStep1'], true);
        self::assertIsString($message);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Sync message contains trailing bytes.');

        SyncProtocol::readMessage($message . "\x00");
    }

    public function testTypedSyncMessageReadersReturnPayloads(): void
    {
        $fixture = $this->loadFixture();
        $syncStep1 = base64_decode($fixture['syncStep1'], true);
        $syncStep2 = base64_decode($fixture['syncStep2ForEmpty'], true);
        $syncStep2V2 = base64_decode($fixture['syncStep2V2ForEmpty'], true);
        $updateMessage = base64_decode($fixture['updateMessage'], true);
        $updateV2Message = base64_decode($fixture['updateV2Message'], true);
        $update = base64_decode($fixture['updateV1'], true);
        $updateV2 = base64_decode($fixture['updateV2'], true);
        self::assertIsString($syncStep1);
        self::assertIsString($syncStep2);
        self::assertIsString($syncStep2V2);
        self::assertIsString($updateMessage);
        self::assertIsString($updateV2Message);
        self::assertIsString($update);
        self::assertIsString($updateV2);

        self::assertSame(base64_decode($fixture['stateVectorV1'], true), SyncProtocol::readSyncStep1($syncStep1));
        self::assertSame($update, SyncProtocol::readSyncStep2($syncStep2));
        self::assertSame($updateV2, SyncProtocol::readSyncStep2V2($syncStep2V2));
        self::assertSame($update, SyncProtocol::readUpdate($updateMessage));
        self::assertSame($updateV2, SyncProtocol::readUpdateV2($updateV2Message));
    }

    public function testTypedSyncMessageReaderRejectsWrongMessageType(): void
    {
        $fixture = $this->loadFixture();
        $message = base64_decode($fixture['syncStep1'], true);
        self::assertIsString($message);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Expected sync update message type 2, received 0.');

        SyncProtocol::readUpdate($message);
    }

    public function testTypedV2SyncMessageReaderRejectsWrongMessageType(): void
    {
        $fixture = $this->loadFixture();
        $message = base64_decode($fixture['syncStep1'], true);
        self::assertIsString($message);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Expected sync update V2 message type 2, received 0.');

        SyncProtocol::readUpdateV2($message);
    }

    public function testHandleSyncMessageRejectsUnknownMessageType(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Unknown sync message type 9.');

        SyncProtocol::handleMessage(new YDoc(), "\x09\x00");
    }

    public function testHandleSyncStep1ReturnsSyncStep2(): void
    {
        $fixture = $this->loadFixture();
        $doc = $this->fixtureDoc($fixture);
        $message = base64_decode($fixture['syncStep1'], true);
        self::assertIsString($message);

        $response = SyncProtocol::handleMessage($doc, $message);

        self::assertIsString($response);
        self::assertSame(base64_decode($fixture['syncStep2ForCurrent'], true), $response);
        self::assertSame(SyncProtocol::MESSAGE_SYNC_STEP_2, SyncProtocol::readMessage($response)['type']);
    }

    public function testHandleSyncStep1V2ReturnsYjsFixtureSyncStep2(): void
    {
        $fixture = $this->loadFixture();
        $doc = $this->fixtureDoc($fixture);
        $message = base64_decode($fixture['syncStep1'], true);
        self::assertIsString($message);

        $response = SyncProtocol::handleMessageV2($doc, $message);

        self::assertIsString($response);
        self::assertSame(base64_decode($fixture['syncStep2V2ForCurrent'], true), $response);
        self::assertSame(SyncProtocol::MESSAGE_SYNC_STEP_2, SyncProtocol::readMessage($response)['type']);
    }

    public function testHandleUpdateAppliesPayload(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc();
        $message = base64_decode($fixture['updateMessage'], true);
        self::assertIsString($message);

        self::assertNull(SyncProtocol::handleMessage($doc, $message));
        self::assertSame($fixture['docJson'], $doc->toJSON());
    }

    public function testApplyUpdateMessageAppliesPayload(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc();
        $message = base64_decode($fixture['updateMessage'], true);
        self::assertIsString($message);

        SyncProtocol::applyUpdate($doc, $message);

        self::assertSame($fixture['docJson'], $doc->toJSON());
    }

    public function testApplyUpdateV2MessageAppliesPayload(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc();
        $message = base64_decode($fixture['updateV2Message'], true);
        self::assertIsString($message);

        SyncProtocol::applyUpdateV2($doc, $message);

        self::assertSame($fixture['docJson'], $doc->toJSON());
    }

    public function testHandleUpdateCanApplyRawGcPayload(): void
    {
        $source = new YDoc();
        $source->applyUpdateV1($this->rawGcUpdateV1());

        $target = new YDoc();

        self::assertNull(SyncProtocol::handleMessage($target, SyncProtocol::writeUpdate($this->rawGcUpdateV1())));
        self::assertSame($source->toJSON(), $target->toJSON());
        self::assertSame($source->encodeStateVector(), $target->encodeStateVector());
    }

    public function testHandleUpdateV2CanApplyRawGcPayload(): void
    {
        $source = new YDoc();
        $source->applyUpdateV2($this->rawGcUpdateV2());

        $target = new YDoc();

        self::assertNull(SyncProtocol::handleMessageV2($target, SyncProtocol::writeUpdateV2($this->rawGcUpdateV2())));
        self::assertSame($source->toJSON(), $target->toJSON());
        self::assertSame($source->encodeStateVector(), $target->encodeStateVector());
    }

    public function testHandleUpdatePassesOriginToDocumentObservers(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc();
        $message = base64_decode($fixture['updateMessage'], true);
        self::assertIsString($message);
        $origins = [];
        $doc->observeUpdate(static function (string $update, YDoc $doc, mixed $origin) use (&$origins): void {
            $origins[] = $origin;
        });

        self::assertNull(SyncProtocol::handleMessage($doc, $message, 'sync-origin'));

        self::assertSame(['sync-origin'], $origins);
    }

    public function testHandleUpdateV2PassesOriginToDocumentObservers(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc();
        $message = base64_decode($fixture['updateV2Message'], true);
        self::assertIsString($message);
        $origins = [];
        $doc->observeUpdateV2(static function (string $update, YDoc $doc, mixed $origin) use (&$origins): void {
            $origins[] = $origin;
        });

        self::assertNull(SyncProtocol::handleMessageV2($doc, $message, 'sync-v2-origin'));

        self::assertSame(['sync-v2-origin'], $origins);
        self::assertSame($fixture['docJson'], $doc->toJSON());
    }

    public function testHandleSyncStep2CompletesHandshakeForEmptyDoc(): void
    {
        $fixture = $this->loadFixture();
        $source = $this->fixtureDoc($fixture);
        $target = new YDoc();

        $step1 = SyncProtocol::writeSyncStep1($target);
        $step2 = SyncProtocol::handleMessage($source, $step1);
        self::assertIsString($step2);

        self::assertNull(SyncProtocol::handleMessage($target, $step2));
        self::assertSame($source->toJSON(), $target->toJSON());
        self::assertSame($source->encodeStateVector(), $target->encodeStateVector());
    }

    public function testReplyAndApplySyncStep2CompletesHandshakeForEmptyDoc(): void
    {
        $fixture = $this->loadFixture();
        $source = $this->fixtureDoc($fixture);
        $target = new YDoc();

        SyncProtocol::applySyncStep2(
            $target,
            SyncProtocol::writeReplyToSyncStep1($source, SyncProtocol::writeSyncStep1($target)),
            'sync-origin'
        );

        self::assertSame($source->toJSON(), $target->toJSON());
        self::assertSame($source->encodeStateVector(), $target->encodeStateVector());
    }

    public function testHandleSyncStep2V2CompletesHandshakeForEmptyDoc(): void
    {
        $fixture = $this->loadFixture();
        $source = $this->fixtureDoc($fixture);
        $target = new YDoc();

        $step1 = SyncProtocol::writeSyncStep1($target);
        $step2 = SyncProtocol::handleMessageV2($source, $step1);
        self::assertIsString($step2);

        self::assertNull(SyncProtocol::handleMessageV2($target, $step2));
        self::assertSame($source->toJSON(), $target->toJSON());
        self::assertSame($source->encodeStateVector(), $target->encodeStateVector());
    }

    public function testReplyAndApplySyncStep2V2CompletesHandshakeForEmptyDoc(): void
    {
        $fixture = $this->loadFixture();
        $source = $this->fixtureDoc($fixture);
        $target = new YDoc();

        SyncProtocol::applySyncStep2V2(
            $target,
            SyncProtocol::writeReplyToSyncStep1V2($source, SyncProtocol::writeSyncStep1($target)),
            'sync-v2-origin'
        );

        self::assertSame($source->toJSON(), $target->toJSON());
        self::assertSame($source->encodeStateVector(), $target->encodeStateVector());
    }

    public function testHandleSyncStep2CompletesHandshakeForPartiallySyncedDoc(): void
    {
        $source = new YDoc(20);
        $sourceUpdates = [];
        $source->observeUpdate(static function (string $update) use (&$sourceUpdates): void {
            $sourceUpdates[] = $update;
        });
        $source->getText('content')->insert(0, 'S');
        $source->getText('content')->insert(1, 'ync');

        $target = new YDoc();
        $target->applyUpdateV1($sourceUpdates[0]);

        $step1 = SyncProtocol::writeSyncStep1($target);
        self::assertSame($target->encodeStateVector(), SyncProtocol::readMessage($step1)['payload']);

        $step2 = SyncProtocol::handleMessage($source, $step1);
        self::assertIsString($step2);

        self::assertNull(SyncProtocol::handleMessage($target, $step2));
        self::assertSame($source->toJSON(), $target->toJSON());
        self::assertSame($source->encodeStateVector(), $target->encodeStateVector());
    }

    public function testHandleSyncStep2V2CompletesHandshakeForPartiallySyncedDoc(): void
    {
        $source = new YDoc(20);
        $sourceUpdates = [];
        $source->observeUpdate(static function (string $update) use (&$sourceUpdates): void {
            $sourceUpdates[] = $update;
        });
        $source->getText('content')->insert(0, 'S');
        $source->getText('content')->insert(1, 'ync');

        $target = new YDoc();
        $target->applyUpdateV1($sourceUpdates[0]);

        $step1 = SyncProtocol::writeSyncStep1($target);
        self::assertSame($target->encodeStateVector(), SyncProtocol::readMessage($step1)['payload']);

        $step2 = SyncProtocol::handleMessageV2($source, $step1);
        self::assertIsString($step2);

        self::assertNull(SyncProtocol::handleMessageV2($target, $step2));
        self::assertSame($source->toJSON(), $target->toJSON());
        self::assertSame($source->encodeStateVector(), $target->encodeStateVector());
    }

    public function testHandleSyncStep2AppliesDeleteSetOnlyPayload(): void
    {
        $source = new YDoc(24);
        $source->getText('content')->insert(0, 'ABCD');
        $source->getText('content')->delete(1, 2);

        $target = new YDoc(24);
        $target->getText('content')->insert(0, 'ABCD');

        $step2 = SyncProtocol::writeSyncStep2($source, $target->encodeStateVector());

        self::assertNull(SyncProtocol::handleMessage($target, $step2));
        self::assertSame($source->toJSON(), $target->toJSON());
        self::assertSame($source->encodeStateVector(), $target->encodeStateVector());
    }

    public function testHandleSyncStep2V2AppliesDeleteSetOnlyPayload(): void
    {
        $source = new YDoc(24);
        $source->getText('content')->insert(0, 'ABCD');
        $source->getText('content')->delete(1, 2);

        $target = new YDoc(24);
        $target->getText('content')->insert(0, 'ABCD');

        $step2 = SyncProtocol::writeSyncStep2V2($source, $target->encodeStateVector());

        self::assertNull(SyncProtocol::handleMessageV2($target, $step2));
        self::assertSame($source->toJSON(), $target->toJSON());
        self::assertSame($source->encodeStateVector(), $target->encodeStateVector());
    }

    public function testHandleSyncStep2AppliesGcPartialDiffPayload(): void
    {
        $source = new YDoc(27, gc: true);
        $source->getText('content')->insert(0, 'ABCD');
        $source->getText('content')->delete(1, 2);

        $target = new YDoc(27);
        $target->getText('content')->insert(0, 'AB');

        $step2 = SyncProtocol::writeSyncStep2($source, $target->encodeStateVector());
        $decoded = DecodedUpdate::decodeV1(SyncProtocol::readSyncStep2($step2));

        self::assertSame('ContentDeleted', $decoded['structs'][0]['content']['type']);
        self::assertSame(['client' => 27, 'clock' => 2], $decoded['structs'][0]['id']);
        self::assertSame(1, $decoded['structs'][0]['content']['length']);

        self::assertNull(SyncProtocol::handleMessage($target, $step2));
        self::assertSame($source->toJSON(), $target->toJSON());
        self::assertSame($source->encodeStateVector(), $target->encodeStateVector());
    }

    public function testHandleSyncStep2V2AppliesGcPartialDiffPayload(): void
    {
        $source = new YDoc(28, gc: true);
        $source->getText('content')->insert(0, 'ABCD');
        $source->getText('content')->delete(1, 2);

        $target = new YDoc(28);
        $target->getText('content')->insert(0, 'AB');

        $step2 = SyncProtocol::writeSyncStep2V2($source, $target->encodeStateVector());
        $decoded = DecodedUpdate::decodeV2(SyncProtocol::readSyncStep2V2($step2));

        self::assertSame('ContentDeleted', $decoded['structs'][0]['content']['type']);
        self::assertSame(['client' => 28, 'clock' => 2], $decoded['structs'][0]['id']);
        self::assertSame(1, $decoded['structs'][0]['content']['length']);

        self::assertNull(SyncProtocol::handleMessageV2($target, $step2));
        self::assertSame($source->toJSON(), $target->toJSON());
        self::assertSame($source->encodeStateVector(), $target->encodeStateVector());
    }

    public function testHandleSyncStep2AppliesNestedTextAttributePartialPayload(): void
    {
        $source = new YDoc(310);
        $sourceText = $source->getArray('array')->insertText(0);
        $sourceText->insert(0, 'Nested');
        $sourceText->setAttribute('lang', 'base');
        $sourceText->setAttribute('lang', 'en');
        $sourceText->setAttribute('mark', ['color' => 'green']);

        $target = new YDoc(310);
        $target->getArray('array')->insertText(0)->insert(0, 'Nested');

        $step2 = SyncProtocol::writeSyncStep2($source, $target->encodeStateVector());

        self::assertNull(SyncProtocol::handleMessage($target, $step2));
        self::assertSame($source->toJSON(), $target->toJSON());
        self::assertSame($source->encodeStateVector(), $target->encodeStateVector());
        self::assertSame($sourceText->getAttributes(), (new YNestedText($target, $sourceText->idKey(), ''))->getAttributes());
    }

    public function testHandleSyncStep2V2AppliesNestedTextAttributePartialPayload(): void
    {
        $source = new YDoc(311);
        $sourceText = $source->getArray('array')->insertText(0);
        $sourceText->insert(0, 'Nested');
        $sourceText->setAttribute('lang', 'base');
        $sourceText->setAttribute('lang', 'en');
        $sourceText->setAttribute('mark', ['color' => 'green']);

        $target = new YDoc(311);
        $target->getArray('array')->insertText(0)->insert(0, 'Nested');

        $step2 = SyncProtocol::writeSyncStep2V2($source, $target->encodeStateVector());

        self::assertNull(SyncProtocol::handleMessageV2($target, $step2));
        self::assertSame($source->toJSON(), $target->toJSON());
        self::assertSame($source->encodeStateVector(), $target->encodeStateVector());
        self::assertSame($sourceText->getAttributes(), (new YNestedText($target, $sourceText->idKey(), ''))->getAttributes());
    }

    public function testHandleSyncStep2AppliesXmlTextSharedAttributePartialPayload(): void
    {
        [$source, $target, $sourceText] = $this->xmlTextSharedAttributePartialDocs(333);

        $step2 = SyncProtocol::writeSyncStep2($source, $target->encodeStateVector());

        self::assertNull(SyncProtocol::handleMessage($target, $step2));
        self::assertSame($source->toJSON(), $target->toJSON());
        self::assertSame($source->encodeStateVector(), $target->encodeStateVector());
        $targetText = $target->getXmlFragment('xml')->get(0);
        self::assertInstanceOf(YXmlText::class, $targetText);
        self::assertSame($sourceText->getAttributes(), $targetText->getAttributes());
    }

    public function testHandleSyncStep2V2AppliesXmlTextSharedAttributePartialPayload(): void
    {
        [$source, $target, $sourceText] = $this->xmlTextSharedAttributePartialDocs(334);

        $step2 = SyncProtocol::writeSyncStep2V2($source, $target->encodeStateVector());

        self::assertNull(SyncProtocol::handleMessageV2($target, $step2));
        self::assertSame($source->toJSON(), $target->toJSON());
        self::assertSame($source->encodeStateVector(), $target->encodeStateVector());
        $targetText = $target->getXmlFragment('xml')->get(0);
        self::assertInstanceOf(YXmlText::class, $targetText);
        self::assertSame($sourceText->getAttributes(), $targetText->getAttributes());
    }

    public function testObservedNativeUpdatesCanBeSentAsSyncMessages(): void
    {
        $source = new YDoc(120);
        $target = new YDoc();
        $messages = [];
        SyncProtocol::observeUpdateMessages($source, static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $source->getText('content')->insert(0, 'Sync');
        $source->getText('content')->insert(4, ' message');
        $source->getText('content')->delete(4, 1);

        self::assertCount(3, $messages);

        foreach ($messages as $message) {
            self::assertSame(SyncProtocol::MESSAGE_UPDATE, SyncProtocol::readMessage($message)['type']);
            self::assertNull(SyncProtocol::handleMessage($target, $message));
        }

        self::assertSame($source->toJSON(), $target->toJSON());
        self::assertSame($source->encodeStateVector(), $target->encodeStateVector());
    }

    public function testObservedNativeV2UpdatesCanBeSentAsSyncMessages(): void
    {
        $source = new YDoc(122);
        $target = new YDoc();
        $messages = [];
        SyncProtocol::observeUpdateV2Messages($source, static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $source->getText('content')->insert(0, 'Sync');
        $source->getText('content')->insert(4, ' message');
        $source->getText('content')->delete(4, 1);

        self::assertCount(3, $messages);

        foreach ($messages as $message) {
            self::assertSame(SyncProtocol::MESSAGE_UPDATE, SyncProtocol::readMessage($message)['type']);
            self::assertNull(SyncProtocol::handleMessageV2($target, $message));
        }

        self::assertSame($source->toJSON(), $target->toJSON());
        self::assertSame($source->encodeStateVector(), $target->encodeStateVector());
    }

    public function testObservedRichNativeUpdatesCanBeSentAsSyncMessages(): void
    {
        $source = new YDoc(126);
        $target = new YDoc();
        $messages = [];
        $observerId = SyncProtocol::observeUpdateMessages($source, static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $nestedText = $source->getArray('array')->insertText(0);
        $nestedText->insert(0, 'Nested');
        $nestedText->setAttribute('lang', 'en');
        $mapText = $source->getMap('map')->setText('body');
        $mapText->insert(0, 'Map');
        $mapText->format(0, 3, ['bold' => true]);
        $mapText->insert(3, ' text', ['italic' => true]);
        $mapText->setAttribute('lang', 'en');
        $deepMap = $source->getMap('map')->setMap('deep');
        $deepMap->setArray('items')->insert(0, ['A', 'B']);
        $deepText = $deepMap->setText('body');
        $deepText->insert(0, 'Deep sync');
        $deepText->format(0, 4, ['strong' => true]);
        $deepMap->setBinary('bytes', "\x05\x06");
        $paragraph = $source->getXmlFragment('xml')->insertElement(0, 'p');
        $paragraph->setAttribute('class', 'lead');
        $paragraph->insertText(0, 'Hi')->insert(2, '!');
        SyncProtocol::unobserveUpdateMessages($source, $observerId);
        $nestedText->setAttribute('ignored', true);
        $mapText->setAttribute('ignored', true);
        $deepText->setAttribute('ignored', true);

        self::assertCount(20, $messages);

        foreach ($messages as $message) {
            self::assertSame(SyncProtocol::MESSAGE_UPDATE, SyncProtocol::readMessage($message)['type']);
            self::assertNull(SyncProtocol::handleMessage($target, $message));
        }

        self::assertSame([
            'map' => [
                'body' => 'Map text',
                'deep' => [
                    'items' => ['A', 'B'],
                    'body' => 'Deep sync',
                    'bytes' => [5, 6],
                ],
            ],
            'array' => ['Nested'],
            'xml' => '<p class="lead">Hi!</p>',
        ], $target->toJSON());
        self::assertSame(['lang' => 'en'], $target->getArray('array')->getText(0)?->getAttributes());
        self::assertSame(['lang' => 'en'], $target->getMap('map')->getText('body')?->getAttributes());
        self::assertSame([
            ['insert' => 'Deep', 'attributes' => ['strong' => true]],
            ['insert' => ' sync'],
        ], $target->getMap('map')->getMap('deep')?->getText('body')?->toDelta());
    }

    public function testObservedRichNativeV2UpdatesCanBeSentAsSyncMessages(): void
    {
        $source = new YDoc(127);
        $target = new YDoc();
        $messages = [];
        $observerId = SyncProtocol::observeUpdateV2Messages($source, static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $nestedText = $source->getArray('array')->insertText(0);
        $nestedText->insert(0, 'Nested');
        $nestedText->setAttribute('lang', 'en');
        $mapText = $source->getMap('map')->setText('body');
        $mapText->insert(0, 'Map');
        $mapText->format(0, 3, ['bold' => true]);
        $mapText->insert(3, ' text', ['italic' => true]);
        $mapText->setAttribute('lang', 'en');
        $deepMap = $source->getMap('map')->setMap('deep');
        $deepMap->setArray('items')->insert(0, ['A', 'B']);
        $deepText = $deepMap->setText('body');
        $deepText->insert(0, 'Deep sync');
        $deepText->format(0, 4, ['strong' => true]);
        $deepMap->setBinary('bytes', "\x05\x06");
        $paragraph = $source->getXmlFragment('xml')->insertElement(0, 'p');
        $paragraph->setAttribute('class', 'lead');
        $paragraph->insertText(0, 'Hi')->insert(2, '!');
        SyncProtocol::unobserveUpdateV2Messages($source, $observerId);
        $nestedText->setAttribute('ignored', true);
        $mapText->setAttribute('ignored', true);
        $deepText->setAttribute('ignored', true);

        self::assertCount(20, $messages);

        foreach ($messages as $message) {
            self::assertSame(SyncProtocol::MESSAGE_UPDATE, SyncProtocol::readMessage($message)['type']);
            self::assertNull(SyncProtocol::handleMessageV2($target, $message));
        }

        self::assertSame([
            'map' => [
                'body' => 'Map text',
                'deep' => [
                    'items' => ['A', 'B'],
                    'body' => 'Deep sync',
                    'bytes' => [5, 6],
                ],
            ],
            'array' => ['Nested'],
            'xml' => '<p class="lead">Hi!</p>',
        ], $target->toJSON());
        self::assertSame(['lang' => 'en'], $target->getArray('array')->getText(0)?->getAttributes());
        self::assertSame(['lang' => 'en'], $target->getMap('map')->getText('body')?->getAttributes());
        self::assertSame([
            ['insert' => 'Deep', 'attributes' => ['strong' => true]],
            ['insert' => ' sync'],
        ], $target->getMap('map')->getMap('deep')?->getText('body')?->toDelta());
    }

    public function testObservedSyncUpdateMessagesExposeDocumentAndOrigin(): void
    {
        $source = new YDoc(124);
        $events = [];
        SyncProtocol::observeUpdateMessages($source, static function (string $message, YDoc $doc, mixed $origin) use (&$events, $source): void {
            $events[] = [
                'type' => SyncProtocol::readMessage($message)['type'],
                'sameDoc' => $doc === $source,
                'origin' => $origin,
            ];
        });

        $source->transact(static function (YDoc $doc): void {
            $doc->getText('content')->insert(0, 'A');
        }, 'sync-message-origin');

        self::assertSame([
            [
                'type' => SyncProtocol::MESSAGE_UPDATE,
                'sameDoc' => true,
                'origin' => 'sync-message-origin',
            ],
        ], $events);
    }

    public function testObservedSyncUpdateV2MessagesExposeDocumentAndOrigin(): void
    {
        $source = new YDoc(125);
        $events = [];
        SyncProtocol::observeUpdateV2Messages($source, static function (string $message, YDoc $doc, mixed $origin) use (&$events, $source): void {
            $events[] = [
                'type' => SyncProtocol::readMessage($message)['type'],
                'sameDoc' => $doc === $source,
                'origin' => $origin,
            ];
        });

        $source->transact(static function (YDoc $doc): void {
            $doc->getText('content')->insert(0, 'A');
        }, 'sync-v2-message-origin');

        self::assertSame([
            [
                'type' => SyncProtocol::MESSAGE_UPDATE,
                'sameDoc' => true,
                'origin' => 'sync-v2-message-origin',
            ],
        ], $events);
    }

    public function testCanUnobserveSyncUpdateMessages(): void
    {
        $source = new YDoc(121);
        $messages = [];
        $observerId = SyncProtocol::observeUpdateMessages($source, static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $source->getText('content')->insert(0, 'A');
        SyncProtocol::unobserveUpdateMessages($source, $observerId);
        $source->getText('content')->insert(1, 'B');

        self::assertCount(1, $messages);
        self::assertSame(SyncProtocol::MESSAGE_UPDATE, SyncProtocol::readMessage($messages[0])['type']);
    }

    public function testCanUnobserveSyncUpdateV2Messages(): void
    {
        $source = new YDoc(123);
        $messages = [];
        $observerId = SyncProtocol::observeUpdateV2Messages($source, static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $source->getText('content')->insert(0, 'A');
        SyncProtocol::unobserveUpdateV2Messages($source, $observerId);
        $source->getText('content')->insert(1, 'B');

        self::assertCount(1, $messages);
        self::assertSame(SyncProtocol::MESSAGE_UPDATE, SyncProtocol::readMessage($messages[0])['type']);
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
    private function fixtureDoc(array $fixture): YDoc
    {
        $doc = new YDoc();
        $update = base64_decode($fixture['updateV1'], true);
        self::assertIsString($update);
        $doc->applyUpdateV1($update);

        return $doc;
    }

    private function partialRemoteStateVector(): string
    {
        $partialRemote = new YDoc(22);
        $partialRemote->getText('content')->insert(0, 'S');

        return $partialRemote->encodeStateVector();
    }

    private function rawGcUpdateV1(): string
    {
        return DecodedUpdate::encodeV1($this->rawGcStructs());
    }

    private function rawGcUpdateV2(): string
    {
        return DecodedUpdate::encodeV2($this->rawGcStructs());
    }

    private function subdocSyncSource(): YDoc
    {
        $doc = $this->subdocSyncPrefix();
        $doc->getArray('array')->insertSubdoc(1, 'sync-subdoc-child', [
            'autoLoad' => true,
            'meta' => ['kind' => 'sync-subdoc'],
        ]);

        return $doc;
    }

    private function subdocSyncPrefix(): YDoc
    {
        $doc = new YDoc(32);
        $doc->getArray('array')->insert(0, ['known']);

        return $doc;
    }

    /**
     * @return array{0: YDoc, 1: YDoc, 2: YXmlText}
     */
    private function xmlTextSharedAttributePartialDocs(int $clientId): array
    {
        $source = new YDoc($clientId);
        $sourceText = $source->getXmlFragment('xml')->insertText(0, 'Xml');
        $sourceBody = $sourceText->setText('body');
        $sourceItems = $sourceText->setArray('items');
        $sourceElement = $sourceText->setXmlElement('element', 'span');
        $sourceXmlText = $sourceText->setXmlText('text');
        $sourceFragment = $sourceText->setXmlFragment('fragment');

        $target = new YDoc($clientId);
        $targetText = $target->getXmlFragment('xml')->insertText(0, 'Xml');
        $targetText->setText('body');
        $targetText->setArray('items');
        $targetText->setXmlElement('element', 'span');
        $targetText->setXmlText('text');
        $targetText->setXmlFragment('fragment');

        $sourceBody->insert(0, 'Text sync');
        $sourceItems->insert(0, ['A', 'B']);
        $sourceElement->appendText('Inline');
        $sourceXmlText->insert(0, 'Xml text');
        $sourceFragment->appendText('Frag');

        return [$source, $target, $sourceText];
    }

    /**
     * @param list<array{index: int, guid: string, meta: mixed, shouldLoad: bool}> $expectedSubdocs
     */
    private function assertExpectedArraySubdocs(array $expectedSubdocs, YDoc $doc): void
    {
        foreach ($expectedSubdocs as $expectedSubdoc) {
            $subdoc = $doc->getArray('array')->get($expectedSubdoc['index']);
            self::assertInstanceOf(YSubdoc::class, $subdoc);
            self::assertSame($expectedSubdoc['guid'], $subdoc->guid());
            self::assertSame($expectedSubdoc['meta'], $subdoc->meta());
            self::assertSame($expectedSubdoc['shouldLoad'], $subdoc->shouldLoad());
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rawGcStructs(): array
    {
        return [
            [
                'type' => 'GC',
                'id' => ['client' => 245, 'clock' => 0],
                'length' => 3,
            ],
        ];
    }
}
