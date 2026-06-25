<?php

declare(strict_types=1);

namespace Yjs\Tests\Compatibility;

use PHPUnit\Framework\TestCase;
use Yjs\Lib0\Encoding;
use Yjs\Sync\Awareness;
use Yjs\Sync\AwarenessProtocol;
use Yjs\Sync\AwarenessUpdate;
use Yjs\UndefinedValue;

final class YjsAwarenessFixtureTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__ . '/../../fixtures/generated/yjs-13.6.31/awareness.json';

    public function testDecodeAwarenessUpdateMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $update = base64_decode($fixture['update'], true);
        self::assertIsString($update);

        self::assertSame($fixture['decodedUpdate'], AwarenessUpdate::decode($update));
    }

    public function testEncodeAwarenessUpdateMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();

        self::assertSame(base64_decode($fixture['update'], true), AwarenessUpdate::encode($fixture['decodedUpdate']));
    }

    public function testEncodeAwarenessMessageMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $update = base64_decode($fixture['update'], true);
        self::assertIsString($update);

        self::assertSame(base64_decode($fixture['message'], true), AwarenessProtocol::writeUpdate($update));
    }

    public function testWriteAwarenessMessageMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $update = base64_decode($fixture['update'], true);
        self::assertIsString($update);

        self::assertSame(
            base64_decode($fixture['message'], true),
            AwarenessProtocol::writeMessage(AwarenessProtocol::MESSAGE_AWARENESS, $update)
        );
    }

    public function testWriteAwarenessQueryMatchesFixture(): void
    {
        $fixture = $this->loadFixture();
        $message = base64_decode($fixture['queryMessage'], true);
        self::assertIsString($message);

        self::assertSame($message, AwarenessProtocol::writeQuery());
        self::assertSame([
            'type' => AwarenessProtocol::MESSAGE_QUERY_AWARENESS,
            'payload' => '',
        ], AwarenessProtocol::readMessage($message));
        AwarenessProtocol::readQuery($message);
        $this->addToAssertionCount(1);
    }

    public function testAwarenessQueryReplyWritesCurrentState(): void
    {
        $fixture = $this->loadFixture();
        $query = base64_decode($fixture['queryMessage'], true);
        self::assertIsString($query);
        $awareness = new Awareness();
        $awareness->setLocalState(77, ['user' => ['name' => 'Ada']]);

        $reply = AwarenessProtocol::writeReplyToQuery($awareness, $query, [77]);

        self::assertSame(AwarenessProtocol::writeState($awareness, [77]), $reply);
        self::assertSame([
            [
                'clientID' => 77,
                'clock' => 1,
                'state' => ['user' => ['name' => 'Ada']],
            ],
        ], AwarenessUpdate::decode(AwarenessProtocol::readUpdate($reply)));
    }

    public function testHandleAwarenessQueryReturnsStateReply(): void
    {
        $fixture = $this->loadFixture();
        $query = base64_decode($fixture['queryMessage'], true);
        self::assertIsString($query);
        $awareness = new Awareness();
        $awareness->setLocalState(77, ['user' => ['name' => 'Ada']]);

        $reply = AwarenessProtocol::handleMessageWithReply($awareness, $query, clientIDs: [77]);

        self::assertSame(AwarenessProtocol::writeState($awareness, [77]), $reply);
        self::assertSame([
            [
                'clientID' => 77,
                'clock' => 1,
                'state' => ['user' => ['name' => 'Ada']],
            ],
        ], AwarenessUpdate::decode(AwarenessProtocol::readUpdate((string) $reply)));
    }

    public function testHandleAwarenessUpdateWithReplyAppliesStateAndReturnsNull(): void
    {
        $fixture = $this->loadFixture();
        $message = base64_decode($fixture['message'], true);
        self::assertIsString($message);
        $target = new Awareness();
        $events = [];
        $target->observe(static function (array $event, Awareness $awareness, mixed $origin) use (&$events): void {
            $events[] = [
                'origin' => $origin,
                'added' => $event['added'],
                'updated' => $event['updated'],
                'removed' => $event['removed'],
            ];
        });

        self::assertNull(AwarenessProtocol::handleMessageWithReply($target, $message, 'remote-awareness'));

        self::assertSame($fixture['decodedUpdate'][0]['state'], $target->getState(77));
        self::assertSame([
            [
                'origin' => 'remote-awareness',
                'added' => [77],
                'updated' => [],
                'removed' => [],
            ],
        ], $events);
    }

    public function testAwarenessHasStateTracksProtocolUpdatesAndRemovals(): void
    {
        $fixture = $this->loadFixture();
        $message = base64_decode($fixture['message'], true);
        self::assertIsString($message);
        $target = new Awareness();

        self::assertFalse($target->hasState(77));
        AwarenessProtocol::handleMessage($target, $message, 'remote-awareness');

        self::assertTrue($target->hasState(77));
        self::assertSame($fixture['decodedUpdate'][0]['state'], $target->getState(77));

        AwarenessProtocol::handleMessage($target, AwarenessProtocol::writeRemoveStates($target, [77], 'remote-remove'));

        self::assertFalse($target->hasState(77));
        self::assertNull($target->getState(77));
    }

    public function testReadAwarenessQueryRejectsPayload(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Awareness query message payload must be empty.');

        AwarenessProtocol::readQuery(AwarenessProtocol::writeMessage(AwarenessProtocol::MESSAGE_QUERY_AWARENESS, 'unexpected'));
    }

    public function testReadAwarenessMessageMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $message = base64_decode($fixture['message'], true);
        $update = base64_decode($fixture['update'], true);
        self::assertIsString($message);
        self::assertIsString($update);

        self::assertSame([
            'type' => AwarenessProtocol::MESSAGE_AWARENESS,
            'payload' => $update,
        ], AwarenessProtocol::readMessage($message));
    }

    public function testReadAwarenessUpdateMessageReturnsPayload(): void
    {
        $fixture = $this->loadFixture();
        $message = base64_decode($fixture['message'], true);
        $update = base64_decode($fixture['update'], true);
        self::assertIsString($message);
        self::assertIsString($update);

        self::assertSame($update, AwarenessProtocol::readUpdate($message));
    }

    public function testWriteAwarenessStateCanFilterClientIds(): void
    {
        $fixture = $this->loadFixture();
        $awareness = new Awareness();
        $awareness->setLocalState(77, ['user' => ['name' => 'Ada']]);
        $awareness->applyUpdate((string) base64_decode($fixture['multiSubsetUpdate'], true));

        self::assertSame(
            AwarenessProtocol::writeUpdate((string) base64_decode($fixture['multiSubsetUpdate'], true)),
            AwarenessProtocol::writeState($awareness, [78])
        );
    }

    public function testWriteRemoveAwarenessStatesMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $awareness = new Awareness();
        $awareness->applyUpdate((string) base64_decode($fixture['update'], true));
        $events = [];
        $awareness->observe(static function (array $event, Awareness $awareness, mixed $origin) use (&$events): void {
            $events[] = [
                'origin' => $origin,
                'removed' => $event['removed'],
            ];
        });

        $message = AwarenessProtocol::writeRemoveStates($awareness, [77], 'disconnect-origin');

        self::assertSame(base64_decode($fixture['removeMessage'], true), $message);
        self::assertSame([['origin' => 'disconnect-origin', 'removed' => [77]]], $events);
        self::assertNull($awareness->getState(77));
        self::assertSame(2, $awareness->getClientMeta(77)['clock']);
    }

    public function testObserveAwarenessUpdateMessagesWrapsUpdatesUntilUnobserved(): void
    {
        $awareness = new Awareness();
        $messages = [];
        $observerId = AwarenessProtocol::observeUpdateMessages($awareness, static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $awareness->setLocalState(77, ['user' => ['name' => 'Ada']]);
        AwarenessProtocol::unobserveUpdateMessages($awareness, $observerId);
        $awareness->setLocalStateField(77, 'cursor', ['anchor' => 1, 'head' => 1]);

        self::assertCount(1, $messages);
        $decoded = AwarenessProtocol::readMessage($messages[0]);
        self::assertSame(AwarenessProtocol::MESSAGE_AWARENESS, $decoded['type']);
        self::assertSame([
            [
                'clientID' => 77,
                'clock' => 1,
                'state' => ['user' => ['name' => 'Ada']],
            ],
        ], AwarenessUpdate::decode($decoded['payload']));

        $target = new Awareness();
        self::assertSame([77], AwarenessProtocol::handleMessage($target, $messages[0]));
        self::assertSame(['user' => ['name' => 'Ada']], $target->getState(77));
    }

    public function testReadAwarenessMessageRejectsTrailingBytes(): void
    {
        $fixture = $this->loadFixture();
        $message = base64_decode($fixture['message'], true);
        self::assertIsString($message);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Awareness message contains trailing bytes.');

        AwarenessProtocol::readMessage($message . "\x00");
    }

    public function testHandleAwarenessMessageRejectsUnknownMessageType(): void
    {
        $unknownMessage = AwarenessUpdate::encode([
            [
                'clientID' => 77,
                'clock' => 1,
                'state' => null,
            ],
        ]);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Unknown awareness message type 9.');

        AwarenessProtocol::handleMessage(new Awareness(), "\x09" . chr(strlen($unknownMessage)) . $unknownMessage);
    }

    public function testReadAwarenessUpdateRejectsWrongMessageType(): void
    {
        $unknownMessage = AwarenessUpdate::encode([
            [
                'clientID' => 77,
                'clock' => 1,
                'state' => null,
            ],
        ]);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Expected awareness message type 1, received 9.');

        AwarenessProtocol::readUpdate("\x09" . chr(strlen($unknownMessage)) . $unknownMessage);
    }

    public function testDecodeAwarenessUpdateRejectsInvalidJsonState(): void
    {
        $encoder = new Encoding();
        $encoder->writeVarUint(1);
        $encoder->writeVarUint(77);
        $encoder->writeVarUint(1);
        $encoder->writeVarString('{"user":');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Unable to decode awareness state JSON.');

        AwarenessUpdate::decode($encoder->toString());
    }

    public function testDecodeAwarenessRemovalMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $update = base64_decode($fixture['removeUpdate'], true);
        self::assertIsString($update);

        self::assertSame($fixture['decodedRemoveUpdate'], AwarenessUpdate::decode($update));
    }

    public function testDecodeSameClockAwarenessRemovalMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $update = base64_decode($fixture['sameClockRemoveUpdate'], true);
        self::assertIsString($update);

        self::assertSame($fixture['decodedSameClockRemoveUpdate'], AwarenessUpdate::decode($update));
    }

    public function testEncodeAwarenessUndefinedStateMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $state = [
            'user' => [
                'name' => 'Ada',
                'badge' => UndefinedValue::instance(),
            ],
            'items' => [
                UndefinedValue::instance(),
                null,
                [
                    'hidden' => UndefinedValue::instance(),
                    'visible' => true,
                ],
            ],
        ];

        $update = AwarenessUpdate::encode([
            [
                'clientID' => 81,
                'clock' => 1,
                'state' => $state,
            ],
        ]);

        self::assertSame(base64_decode($fixture['undefinedUpdate'], true), $update);
        self::assertSame($fixture['decodedUndefinedUpdate'], AwarenessUpdate::decode($update));
    }

    public function testNativeAwarenessUndefinedStateMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $awareness = new Awareness();

        $update = $awareness->setLocalState(81, [
            'user' => [
                'name' => 'Ada',
                'badge' => UndefinedValue::instance(),
            ],
            'items' => [
                UndefinedValue::instance(),
                null,
                [
                    'hidden' => UndefinedValue::instance(),
                    'visible' => true,
                ],
            ],
        ]);

        self::assertSame(base64_decode($fixture['undefinedUpdate'], true), $update);
        self::assertSame($fixture['decodedUndefinedUpdate'], AwarenessUpdate::decode($awareness->encodeUpdate([81])));
    }

    public function testEncodeAwarenessSpecialNumberStateMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $state = [
            'metrics' => [
                'nan' => NAN,
                'positiveInfinity' => INF,
                'negativeInfinity' => -INF,
                'finite' => 1.5,
            ],
            'list' => [NAN, INF, -INF, 3],
        ];

        $update = AwarenessUpdate::encode([
            [
                'clientID' => 82,
                'clock' => 1,
                'state' => $state,
            ],
        ]);

        self::assertSame(base64_decode($fixture['specialNumberUpdate'], true), $update);
        self::assertSame($fixture['decodedSpecialNumberUpdate'], AwarenessUpdate::decode($update));
    }

    public function testNativeAwarenessSpecialNumberStateMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $awareness = new Awareness();

        $update = $awareness->setLocalState(82, [
            'metrics' => [
                'nan' => NAN,
                'positiveInfinity' => INF,
                'negativeInfinity' => -INF,
                'finite' => 1.5,
            ],
            'list' => [NAN, INF, -INF, 3],
        ]);

        self::assertSame(base64_decode($fixture['specialNumberUpdate'], true), $update);
        self::assertSame($fixture['decodedSpecialNumberUpdate'], AwarenessUpdate::decode($awareness->encodeUpdate([82])));
    }

    public function testEncodeAwarenessRootUndefinedStateIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Unable to encode awareness state as JSON.');

        AwarenessUpdate::encode([
            [
                'clientID' => 81,
                'clock' => 1,
                'state' => UndefinedValue::instance(),
            ],
        ]);
    }

    public function testDecodeFieldAwarenessUpdateMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $update = base64_decode($fixture['fieldUpdate'], true);
        self::assertIsString($update);

        self::assertSame($fixture['decodedFieldUpdate'], AwarenessUpdate::decode($update));
    }

    public function testDecodeMultiClientAwarenessUpdateMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $update = base64_decode($fixture['multiUpdate'], true);
        self::assertIsString($update);

        self::assertSame($fixture['decodedMultiUpdate'], AwarenessUpdate::decode($update));
    }

    public function testDecodeMultiClientSubsetAwarenessUpdateMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $update = base64_decode($fixture['multiSubsetUpdate'], true);
        self::assertIsString($update);

        self::assertSame($fixture['decodedMultiSubsetUpdate'], AwarenessUpdate::decode($update));
    }

    public function testDecodeRichAwarenessUpdateMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $update = base64_decode($fixture['richUpdate'], true);
        self::assertIsString($update);

        self::assertSame($fixture['decodedRichUpdate'], AwarenessUpdate::decode($update));
        self::assertSame($update, AwarenessUpdate::encode($fixture['decodedRichUpdate']));
    }

    public function testDecodeRichAwarenessRemovalMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $update = base64_decode($fixture['richRemoveUpdate'], true);
        self::assertIsString($update);

        self::assertSame($fixture['decodedRichRemoveUpdate'], AwarenessUpdate::decode($update));
        self::assertSame($update, AwarenessUpdate::encode($fixture['decodedRichRemoveUpdate']));
    }

    public function testModifyAwarenessUpdateCanRewriteState(): void
    {
        $fixture = $this->loadFixture();
        $update = base64_decode($fixture['update'], true);
        $expectedModified = base64_decode($fixture['modifiedUpdate'], true);
        self::assertIsString($update);
        self::assertIsString($expectedModified);

        $modify = static fn (?array $state): ?array => $state === null ? null : ['user' => ['name' => $state['user']['name']]];
        $modified = AwarenessUpdate::modify(
            $update,
            $modify
        );

        self::assertSame($expectedModified, $modified);
        self::assertSame($expectedModified, AwarenessProtocol::modifyUpdate($update, $modify));
        self::assertSame(
            [
                [
                    'clientID' => 77,
                    'clock' => 1,
                    'state' => ['user' => ['name' => 'Ada']],
                ],
            ],
            AwarenessUpdate::decode($modified)
        );
    }

    public function testNativeAwarenessStateCanEncodeFixtureUpdate(): void
    {
        $fixture = $this->loadFixture();
        $awareness = new Awareness();

        $update = $awareness->setLocalState(77, [
            'user' => ['name' => 'Ada'],
            'cursor' => ['anchor' => 3, 'head' => 7],
        ]);

        self::assertSame(base64_decode($fixture['update'], true), $update);
        self::assertSame($fixture['decodedUpdate'], AwarenessUpdate::decode($awareness->encodeUpdate([77])));
        self::assertSame($fixture['decodedUpdate'][0]['state'], $awareness->getState(77));
        self::assertSame($fixture['states'], $awareness->getStates());
        self::assertSame([
            77 => [
                'clock' => 1,
                'state' => $fixture['decodedUpdate'][0]['state'],
            ],
        ], $awareness->getStateEntries());
        self::assertSame(1, $awareness->getClientMeta(77)['clock']);
        self::assertIsInt($awareness->getClientMeta(77)['lastUpdated']);
    }

    public function testNativeAwarenessStateFieldCanEncodeFixtureUpdate(): void
    {
        $fixture = $this->loadFixture();
        $awareness = new Awareness();

        $awareness->setLocalState(77, [
            'user' => ['name' => 'Ada'],
        ]);
        $update = $awareness->setLocalStateField(77, 'cursor', ['anchor' => 1, 'head' => 4]);

        self::assertSame(base64_decode($fixture['fieldUpdate'], true), $update);
        self::assertSame($fixture['decodedFieldUpdate'], AwarenessUpdate::decode($awareness->encodeUpdate([77])));
        self::assertSame($fixture['decodedFieldUpdate'][0]['state'], $awareness->getState(77));
    }

    public function testNativeAwarenessCanEncodeMultiClientFixtureUpdate(): void
    {
        $fixture = $this->loadFixture();
        $awareness = new Awareness();
        $awareness->setLocalState(77, ['user' => ['name' => 'Ada']]);
        $awareness->applyUpdate((string) base64_decode($fixture['multiSubsetUpdate'], true));

        self::assertSame(base64_decode($fixture['multiUpdate'], true), $awareness->encodeUpdate([77, 78]));
        self::assertSame(base64_decode($fixture['multiSubsetUpdate'], true), $awareness->encodeUpdate([78]));
        self::assertSame($fixture['decodedMultiUpdate'], AwarenessUpdate::decode($awareness->encodeUpdate([77, 78])));
        self::assertSame($fixture['multiStates'], $awareness->getStates());
    }

    public function testNativeAwarenessCanSetMultipleLocalStatesInOneUpdate(): void
    {
        $awareness = new Awareness();
        $events = [];
        $updateEvents = [];
        $awareness->observe(static function (array $event, Awareness $awareness, mixed $origin) use (&$events): void {
            $events[] = [
                'added' => $event['added'],
                'updated' => $event['updated'],
                'removed' => $event['removed'],
                'origin' => $origin,
                'decoded' => AwarenessUpdate::decode($event['update']),
            ];
        });
        $awareness->observeUpdate(static function (array $event, Awareness $awareness, mixed $origin) use (&$updateEvents): void {
            $updateEvents[] = [
                'added' => $event['added'],
                'updated' => $event['updated'],
                'removed' => $event['removed'],
                'origin' => $origin,
                'decoded' => AwarenessUpdate::decode($event['update']),
            ];
        });

        $update = $awareness->setLocalStates([
            77 => ['user' => ['name' => 'Ada']],
            78 => ['user' => ['name' => 'Grace']],
        ], 'batch-add');

        $decoded = [
            [
                'clientID' => 77,
                'clock' => 1,
                'state' => ['user' => ['name' => 'Ada']],
            ],
            [
                'clientID' => 78,
                'clock' => 1,
                'state' => ['user' => ['name' => 'Grace']],
            ],
        ];
        self::assertSame($decoded, AwarenessUpdate::decode($update));
        self::assertSame([
            77 => ['user' => ['name' => 'Ada']],
            78 => ['user' => ['name' => 'Grace']],
        ], $awareness->getStates());
        self::assertSame([[
            'added' => [77, 78],
            'updated' => [],
            'removed' => [],
            'origin' => 'batch-add',
            'decoded' => $decoded,
        ]], $events);
        self::assertSame($events, $updateEvents);

        $update = $awareness->setLocalStates([
            77 => ['user' => ['name' => 'Ada'], 'cursor' => ['anchor' => 1, 'head' => 2]],
            78 => null,
        ], 'batch-update-remove');

        self::assertSame([
            [
                'clientID' => 77,
                'clock' => 2,
                'state' => ['user' => ['name' => 'Ada'], 'cursor' => ['anchor' => 1, 'head' => 2]],
            ],
            [
                'clientID' => 78,
                'clock' => 2,
                'state' => null,
            ],
        ], AwarenessUpdate::decode($update));
        self::assertSame([
            77 => ['user' => ['name' => 'Ada'], 'cursor' => ['anchor' => 1, 'head' => 2]],
        ], $awareness->getStates());
        self::assertSame([
            'added' => [],
            'updated' => [77],
            'removed' => [78],
            'origin' => 'batch-update-remove',
        ], array_intersect_key($events[1], array_flip(['added', 'updated', 'removed', 'origin'])));
        self::assertSame([
            'added' => [],
            'updated' => [77],
            'removed' => [78],
            'origin' => 'batch-update-remove',
        ], array_intersect_key($updateEvents[1], array_flip(['added', 'updated', 'removed', 'origin'])));
        self::assertSame(2, $awareness->getClientMeta(77)['clock']);
        self::assertSame(2, $awareness->getClientMeta(78)['clock']);
    }

    public function testNativeAwarenessSupportsYjsStyleEventAliases(): void
    {
        $awareness = new Awareness();
        $events = [];

        $changeObserver = static function (array $event, Awareness $observedAwareness, mixed $origin) use (&$events, $awareness): void {
            $events[] = [
                'type' => 'change',
                'sameAwareness' => $observedAwareness === $awareness,
                'added' => $event['added'],
                'updated' => $event['updated'],
                'removed' => $event['removed'],
                'origin' => $origin,
            ];
        };
        $updateObserver = static function (array $event, Awareness $observedAwareness, mixed $origin) use (&$events, $awareness): void {
            $events[] = [
                'type' => 'update',
                'sameAwareness' => $observedAwareness === $awareness,
                'added' => $event['added'],
                'updated' => $event['updated'],
                'removed' => $event['removed'],
                'origin' => $origin,
            ];
        };
        $onceChangeObserver = static function (array $event, Awareness $observedAwareness, mixed $origin) use (&$events, $awareness): void {
            $events[] = [
                'type' => 'once-change',
                'sameAwareness' => $observedAwareness === $awareness,
                'added' => $event['added'],
                'updated' => $event['updated'],
                'removed' => $event['removed'],
                'origin' => $origin,
            ];
        };
        $onceUpdateObserver = static function (array $event, Awareness $observedAwareness, mixed $origin) use (&$events, $awareness): void {
            $events[] = [
                'type' => 'once-update',
                'sameAwareness' => $observedAwareness === $awareness,
                'added' => $event['added'],
                'updated' => $event['updated'],
                'removed' => $event['removed'],
                'origin' => $origin,
            ];
        };

        $awareness->on('change', $changeObserver);
        $updateObserverId = $awareness->on('update', $updateObserver);
        $awareness->once('change', $onceChangeObserver);
        $awareness->once('update', $onceUpdateObserver);

        $awareness->setLocalState(77, ['user' => ['name' => 'Ada']], 'first');
        $awareness->setLocalState(77, ['user' => ['name' => 'Ada']], 'same-state-clock-refresh');
        $awareness->off('change', $changeObserver);
        $awareness->off('update', $updateObserverId);
        $awareness->setLocalState(77, ['user' => ['name' => 'Grace']], 'after-off');

        self::assertSame([
            [
                'type' => 'change',
                'sameAwareness' => true,
                'added' => [77],
                'updated' => [],
                'removed' => [],
                'origin' => 'first',
            ],
            [
                'type' => 'once-change',
                'sameAwareness' => true,
                'added' => [77],
                'updated' => [],
                'removed' => [],
                'origin' => 'first',
            ],
            [
                'type' => 'update',
                'sameAwareness' => true,
                'added' => [77],
                'updated' => [],
                'removed' => [],
                'origin' => 'first',
            ],
            [
                'type' => 'once-update',
                'sameAwareness' => true,
                'added' => [77],
                'updated' => [],
                'removed' => [],
                'origin' => 'first',
            ],
            [
                'type' => 'update',
                'sameAwareness' => true,
                'added' => [],
                'updated' => [77],
                'removed' => [],
                'origin' => 'same-state-clock-refresh',
            ],
        ], $events);
    }

    public function testNativeAwarenessCanEmitCustomEventAliases(): void
    {
        $awareness = new Awareness();
        $events = [];
        $observer = static function (string $value) use (&$events): void {
            $events[] = $value;
        };

        $awareness->on('custom', $observer);
        $awareness->once('custom', static function (string $value) use (&$events): void {
            $events[] = 'once-' . $value;
        });

        $awareness->emit('custom', ['first']);
        $awareness->emit('custom', ['second']);
        $awareness->off('custom', $observer);
        $awareness->emit('custom', ['ignored']);

        self::assertSame(['first', 'once-first', 'second'], $events);
    }

    public function testNativeAwarenessAppliesNewerUpdatesOnly(): void
    {
        $fixture = $this->loadFixture();
        $awareness = new Awareness();
        $update = base64_decode($fixture['update'], true);
        self::assertIsString($update);

        self::assertSame([77], $awareness->applyUpdate($update));
        self::assertSame([], $awareness->applyUpdate($update));

        $stale = AwarenessUpdate::encode([
            [
                'clientID' => 77,
                'clock' => 1,
                'state' => ['user' => ['name' => 'Grace']],
            ],
        ]);

        self::assertSame([], $awareness->applyUpdate($stale));
        self::assertSame('Ada', $awareness->getState(77)['user']['name']);
    }

    public function testNativeAwarenessIgnoresUnknownClockZeroStateUpdate(): void
    {
        $awareness = new Awareness();
        $events = [];
        $updateEvents = [];
        $awareness->observe(static function (array $event) use (&$events): void {
            $events[] = $event;
        });
        $awareness->observeUpdate(static function (array $event) use (&$updateEvents): void {
            $updateEvents[] = $event;
        });
        $update = AwarenessUpdate::encode([
            [
                'clientID' => 77,
                'clock' => 0,
                'state' => ['user' => ['name' => 'Ada']],
            ],
        ]);

        self::assertSame([], $awareness->applyUpdate($update, 'clock-zero-state'));
        self::assertSame([], $awareness->getStates());
        self::assertNull($awareness->getState(77));
        self::assertNull($awareness->getClientMeta(77));
        self::assertSame([], $events);
        self::assertSame([], $updateEvents);
    }

    public function testNativeAwarenessIgnoresUnknownClockZeroRemovalUpdate(): void
    {
        $awareness = new Awareness();
        $events = [];
        $updateEvents = [];
        $awareness->observe(static function (array $event) use (&$events): void {
            $events[] = $event;
        });
        $awareness->observeUpdate(static function (array $event) use (&$updateEvents): void {
            $updateEvents[] = $event;
        });
        $update = AwarenessUpdate::encode([
            [
                'clientID' => 77,
                'clock' => 0,
                'state' => null,
            ],
        ]);

        self::assertSame([], $awareness->applyUpdate($update, 'clock-zero-remove'));
        self::assertSame([], $awareness->getStates());
        self::assertNull($awareness->getState(77));
        self::assertNull($awareness->getClientMeta(77));
        self::assertSame([], $events);
        self::assertSame([], $updateEvents);
    }

    public function testNativeAwarenessAppliesMultiClientUpdate(): void
    {
        $fixture = $this->loadFixture();
        $awareness = new Awareness();
        $update = base64_decode($fixture['multiUpdate'], true);
        self::assertIsString($update);

        self::assertSame([77, 78], $awareness->applyUpdate($update));
        self::assertSame($fixture['decodedMultiUpdate'][0]['state'], $awareness->getState(77));
        self::assertSame($fixture['decodedMultiUpdate'][1]['state'], $awareness->getState(78));
        self::assertSame(1, $awareness->getClientMeta(77)['clock']);
        self::assertSame(1, $awareness->getClientMeta(78)['clock']);
    }

    public function testNativeAwarenessAppliesRichMultiClientMessageAndRemoval(): void
    {
        $fixture = $this->loadFixture();
        $awareness = new Awareness();
        $message = base64_decode($fixture['richMessage'], true);
        $removeMessage = base64_decode($fixture['richRemoveMessage'], true);
        self::assertIsString($message);
        self::assertIsString($removeMessage);

        self::assertSame(
            [
                'type' => AwarenessProtocol::MESSAGE_AWARENESS,
                'payload' => base64_decode($fixture['richUpdate'], true),
            ],
            AwarenessProtocol::readMessage($message)
        );
        self::assertSame([79, 80], AwarenessProtocol::handleMessage($awareness, $message, 'rich-origin'));
        self::assertSame($fixture['richStates'], $awareness->getStates());
        self::assertSame($fixture['decodedRichUpdate'][0]['state'], $awareness->getState(79));
        self::assertSame($fixture['decodedRichUpdate'][1]['state'], $awareness->getState(80));

        self::assertSame([79], AwarenessProtocol::handleMessage($awareness, $removeMessage, 'rich-remove-origin'));
        self::assertNull($awareness->getState(79));
        self::assertSame($fixture['decodedRichUpdate'][1]['state'], $awareness->getState(80));
        self::assertSame(2, $awareness->getClientMeta(79)['clock']);
    }

    public function testNativeAwarenessAppliesSameClockRemovalForActiveRemoteState(): void
    {
        $fixture = $this->loadFixture();
        $awareness = new Awareness();
        $update = base64_decode($fixture['update'], true);
        $removeUpdate = base64_decode($fixture['sameClockRemoveUpdate'], true);
        self::assertIsString($update);
        self::assertIsString($removeUpdate);

        self::assertSame([77], $awareness->applyUpdate($update));
        self::assertSame([77], $awareness->applyUpdate($removeUpdate));
        self::assertNull($awareness->getState(77));
    }

    public function testNativeAwarenessDoesNotResurrectRemovedStateFromStaleClock(): void
    {
        $fixture = $this->loadFixture();
        $awareness = new Awareness();
        $events = [];
        $awareness->observe(static function (array $event, Awareness $awareness, mixed $origin) use (&$events): void {
            $events[] = [
                'type' => 'change',
                'event' => [
                    'added' => $event['added'],
                    'updated' => $event['updated'],
                    'removed' => $event['removed'],
                ],
                'origin' => $origin,
            ];
        });
        $awareness->observeUpdate(static function (array $event, Awareness $awareness, mixed $origin) use (&$events): void {
            $events[] = [
                'type' => 'update',
                'event' => [
                    'added' => $event['added'],
                    'updated' => $event['updated'],
                    'removed' => $event['removed'],
                ],
                'origin' => $origin,
            ];
        });

        self::assertSame([77], $awareness->applyUpdate(AwarenessUpdate::encode([
            [
                'clientID' => 77,
                'clock' => 2,
                'state' => ['user' => ['name' => 'Ada']],
            ],
        ]), 'remote-add-clock-2'));
        self::assertSame([77], $awareness->applyUpdate(AwarenessUpdate::encode([
            [
                'clientID' => 77,
                'clock' => 3,
                'state' => null,
            ],
        ]), 'remote-remove-clock-3'));
        self::assertSame([], $awareness->applyUpdate(AwarenessUpdate::encode([
            [
                'clientID' => 77,
                'clock' => 2,
                'state' => ['user' => ['name' => 'Grace']],
            ],
        ]), 'remote-stale-clock-2-state'));
        self::assertSame([], $awareness->applyUpdate(AwarenessUpdate::encode([
            [
                'clientID' => 77,
                'clock' => 3,
                'state' => ['user' => ['name' => 'Lin']],
            ],
        ]), 'remote-same-clock-state-after-remove'));
        self::assertSame([], $awareness->applyUpdate(AwarenessUpdate::encode([
            [
                'clientID' => 77,
                'clock' => 3,
                'state' => null,
            ],
        ]), 'remote-duplicate-remove'));

        self::assertSame($fixture['staleAfterRemoveSequence'], $events);
        self::assertFalse($fixture['staleAfterRemoveHasClient77']);
        self::assertArrayNotHasKey(77, $awareness->getStates());
        self::assertNull($awareness->getState(77));
        self::assertSame(3, $awareness->getClientMeta(77)['clock']);
    }

    public function testNativeAwarenessRemoveStateMatchesFixture(): void
    {
        $fixture = $this->loadFixture();
        $awareness = new Awareness();
        $awareness->applyUpdate((string) base64_decode($fixture['update'], true));

        $removeUpdate = $awareness->removeStates([77]);

        self::assertSame(base64_decode($fixture['removeUpdate'], true), $removeUpdate);
        self::assertNull($awareness->getState(77));
        self::assertSame($fixture['removedStates'], $awareness->getStates());
        self::assertSame(2, $awareness->getClientMeta(77)['clock']);
    }

    public function testNativeAwarenessIgnoresUnknownRemoveStates(): void
    {
        $awareness = new Awareness();
        $events = [];
        $updateEvents = [];
        $awareness->observe(static function (array $event) use (&$events): void {
            $events[] = $event;
        });
        $awareness->observeUpdate(static function (array $event) use (&$updateEvents): void {
            $updateEvents[] = $event;
        });

        $removeUpdate = $awareness->removeStates([99], 'unknown-remove');

        self::assertSame([], AwarenessUpdate::decode($removeUpdate));
        self::assertSame([], $awareness->getStates());
        self::assertNull($awareness->getClientMeta(99));
        self::assertSame([], $events);
        self::assertSame([], $updateEvents);
    }

    public function testNativeAwarenessRemoveStatesSkipsUnknownClients(): void
    {
        $awareness = new Awareness();
        $awareness->setLocalState(77, ['user' => ['name' => 'Ada']]);

        $removeUpdate = $awareness->removeStates([77, 99], 'mixed-remove');

        self::assertSame([
            [
                'clientID' => 77,
                'clock' => 2,
                'state' => null,
            ],
        ], AwarenessUpdate::decode($removeUpdate));
        self::assertSame([], $awareness->getStates());
        self::assertSame(2, $awareness->getClientMeta(77)['clock']);
        self::assertNull($awareness->getClientMeta(99));
    }

    public function testNativeAwarenessCanInspectAndClearActiveStates(): void
    {
        $awareness = new Awareness();
        $events = [];
        $updateEvents = [];
        $awareness->observe(static function (array $event, Awareness $awareness, mixed $origin) use (&$events): void {
            $events[] = [
                'event' => [
                    'added' => $event['added'],
                    'updated' => $event['updated'],
                    'removed' => $event['removed'],
                ],
                'origin' => $origin,
            ];
        });
        $awareness->observeUpdate(static function (array $event, Awareness $awareness, mixed $origin) use (&$updateEvents): void {
            $updateEvents[] = [
                'event' => [
                    'added' => $event['added'],
                    'updated' => $event['updated'],
                    'removed' => $event['removed'],
                ],
                'origin' => $origin,
                'decoded' => AwarenessUpdate::decode($event['update']),
            ];
        });

        $awareness->setLocalState(78, ['user' => ['name' => 'Grace']], 'add-78');
        $awareness->setLocalState(77, ['user' => ['name' => 'Ada']], 'add-77');
        $awareness->removeStates([78], 'remove-78');

        self::assertSame([77], $awareness->activeClientIDs());
        $clearUpdate = $awareness->clear('clear-origin');

        self::assertSame([], $awareness->activeClientIDs());
        self::assertNull($awareness->getState(77));
        self::assertNull($awareness->getState(78));
        self::assertSame([
            ['clientID' => 77, 'clock' => 2, 'state' => null],
        ], AwarenessUpdate::decode($clearUpdate));
        self::assertSame([
            ['event' => ['added' => [78], 'updated' => [], 'removed' => []], 'origin' => 'add-78'],
            ['event' => ['added' => [77], 'updated' => [], 'removed' => []], 'origin' => 'add-77'],
            ['event' => ['added' => [], 'updated' => [], 'removed' => [78]], 'origin' => 'remove-78'],
            ['event' => ['added' => [], 'updated' => [], 'removed' => [77]], 'origin' => 'clear-origin'],
        ], $events);
        self::assertSame([
            [
                'event' => ['added' => [78], 'updated' => [], 'removed' => []],
                'origin' => 'add-78',
                'decoded' => [['clientID' => 78, 'clock' => 1, 'state' => ['user' => ['name' => 'Grace']]]],
            ],
            [
                'event' => ['added' => [77], 'updated' => [], 'removed' => []],
                'origin' => 'add-77',
                'decoded' => [['clientID' => 77, 'clock' => 1, 'state' => ['user' => ['name' => 'Ada']]]],
            ],
            [
                'event' => ['added' => [], 'updated' => [], 'removed' => [78]],
                'origin' => 'remove-78',
                'decoded' => [['clientID' => 78, 'clock' => 2, 'state' => null]],
            ],
            [
                'event' => ['added' => [], 'updated' => [], 'removed' => [77]],
                'origin' => 'clear-origin',
                'decoded' => [['clientID' => 77, 'clock' => 2, 'state' => null]],
            ],
        ], $updateEvents);
    }

    public function testNativeAwarenessCanRemoveOutdatedStates(): void
    {
        $awareness = new Awareness();
        $events = [];
        $updateEvents = [];
        $awareness->observe(static function (array $event, Awareness $awareness, mixed $origin) use (&$events): void {
            $events[] = [
                'event' => [
                    'added' => $event['added'],
                    'updated' => $event['updated'],
                    'removed' => $event['removed'],
                ],
                'origin' => $origin,
            ];
        });
        $awareness->observeUpdate(static function (array $event, Awareness $awareness, mixed $origin) use (&$updateEvents): void {
            $updateEvents[] = [
                'event' => [
                    'added' => $event['added'],
                    'updated' => $event['updated'],
                    'removed' => $event['removed'],
                ],
                'origin' => $origin,
                'decoded' => AwarenessUpdate::decode($event['update']),
            ];
        });

        $awareness->applyUpdate(AwarenessUpdate::encode([
            [
                'clientID' => 77,
                'clock' => 1,
                'state' => ['user' => ['name' => 'Ada']],
            ],
            [
                'clientID' => 78,
                'clock' => 1,
                'state' => ['user' => ['name' => 'Grace']],
            ],
        ]), 'remote-add');
        $meta = $awareness->getMeta();

        self::assertSame([], $awareness->removeOutdatedStates($meta[77]['lastUpdated'] + Awareness::OUTDATED_TIMEOUT - 1));
        self::assertSame([77, 78], $awareness->removeOutdatedStates($meta[77]['lastUpdated'] + Awareness::OUTDATED_TIMEOUT, origin: 'timeout-origin'));
        self::assertNull($awareness->getState(77));
        self::assertNull($awareness->getState(78));
        self::assertSame(1, $awareness->getClientMeta(77)['clock']);
        self::assertSame(1, $awareness->getClientMeta(78)['clock']);
        self::assertSame([], $awareness->removeOutdatedStates($meta[77]['lastUpdated'] + (Awareness::OUTDATED_TIMEOUT * 2)));
        self::assertSame([
            [
                'event' => ['added' => [77, 78], 'updated' => [], 'removed' => []],
                'origin' => 'remote-add',
            ],
            [
                'event' => ['added' => [], 'updated' => [], 'removed' => [77, 78]],
                'origin' => 'timeout-origin',
            ],
        ], $events);
        self::assertSame([
            [
                'event' => ['added' => [77, 78], 'updated' => [], 'removed' => []],
                'origin' => 'remote-add',
                'decoded' => [
                    ['clientID' => 77, 'clock' => 1, 'state' => ['user' => ['name' => 'Ada']]],
                    ['clientID' => 78, 'clock' => 1, 'state' => ['user' => ['name' => 'Grace']]],
                ],
            ],
            [
                'event' => ['added' => [], 'updated' => [], 'removed' => [77, 78]],
                'origin' => 'timeout-origin',
                'decoded' => [
                    ['clientID' => 77, 'clock' => 1, 'state' => null],
                    ['clientID' => 78, 'clock' => 1, 'state' => null],
                ],
            ],
        ], $updateEvents);
    }

    public function testNativeAwarenessCanInspectAndTouchOutdatedStates(): void
    {
        $awareness = new Awareness();
        $events = [];
        $updateEvents = [];
        $awareness->observe(static function (array $event) use (&$events): void {
            $events[] = $event;
        });
        $awareness->observeUpdate(static function (array $event) use (&$updateEvents): void {
            $updateEvents[] = $event;
        });

        $awareness->applyUpdate(AwarenessUpdate::encode([
            [
                'clientID' => 77,
                'clock' => 1,
                'state' => ['user' => ['name' => 'Ada']],
            ],
            [
                'clientID' => 78,
                'clock' => 1,
                'state' => ['user' => ['name' => 'Grace']],
            ],
        ]), 'remote-add');
        $meta = $awareness->getMeta();
        $baseTime = $meta[77]['lastUpdated'];

        self::assertSame([77, 78], $awareness->outdatedClientIDs($baseTime + Awareness::OUTDATED_TIMEOUT));
        self::assertSame([78], $awareness->touchStates([78, 99], $baseTime + Awareness::OUTDATED_TIMEOUT));
        self::assertSame([77], $awareness->outdatedClientIDs($baseTime + Awareness::OUTDATED_TIMEOUT));
        self::assertSame([77], $awareness->removeOutdatedStates($baseTime + Awareness::OUTDATED_TIMEOUT, origin: 'timeout-origin'));
        self::assertNull($awareness->getState(77));
        self::assertSame(['user' => ['name' => 'Grace']], $awareness->getState(78));
        self::assertSame($baseTime + Awareness::OUTDATED_TIMEOUT, $awareness->getClientMeta(78)['lastUpdated']);

        self::assertCount(2, $events);
        self::assertSame(['added' => [77, 78], 'updated' => [], 'removed' => []], [
            'added' => $events[0]['added'],
            'updated' => $events[0]['updated'],
            'removed' => $events[0]['removed'],
        ]);
        self::assertSame(['added' => [], 'updated' => [], 'removed' => [77]], [
            'added' => $events[1]['added'],
            'updated' => $events[1]['updated'],
            'removed' => $events[1]['removed'],
        ]);
        self::assertCount(2, $updateEvents);
    }

    public function testHandleAwarenessMessageAppliesPayload(): void
    {
        $fixture = $this->loadFixture();
        $awareness = new Awareness();
        $message = base64_decode($fixture['message'], true);
        self::assertIsString($message);

        self::assertSame([77], AwarenessProtocol::handleMessage($awareness, $message));
        self::assertSame($fixture['decodedUpdate'][0]['state'], $awareness->getState(77));

        $removeMessage = base64_decode($fixture['removeMessage'], true);
        self::assertIsString($removeMessage);
        self::assertSame([77], AwarenessProtocol::handleMessage($awareness, $removeMessage));
        self::assertNull($awareness->getState(77));
    }

    public function testApplyAwarenessUpdateMessageAppliesPayload(): void
    {
        $fixture = $this->loadFixture();
        $awareness = new Awareness();
        $message = base64_decode($fixture['message'], true);
        self::assertIsString($message);

        self::assertSame([77], AwarenessProtocol::applyUpdate($awareness, $message, 'awareness-origin'));

        self::assertSame($fixture['decodedUpdate'][0]['state'], $awareness->getState(77));
    }

    public function testHandleAwarenessMessagePassesOriginToObservers(): void
    {
        $fixture = $this->loadFixture();
        $awareness = new Awareness();
        $message = base64_decode($fixture['message'], true);
        self::assertIsString($message);
        $origins = [];
        $awareness->observe(static function (array $event, Awareness $awareness, mixed $origin) use (&$origins): void {
            $origins[] = [$event['origin'], $origin];
        });

        self::assertSame([77], AwarenessProtocol::handleMessage($awareness, $message, 'awareness-origin'));

        self::assertSame([['awareness-origin', 'awareness-origin']], $origins);
    }

    public function testObservedAwarenessUpdatesCanBeSentAsMessages(): void
    {
        $source = new Awareness();
        $target = new Awareness();
        $messages = [];
        AwarenessProtocol::observeUpdateMessages($source, static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $source->setLocalState(77, ['user' => ['name' => 'Ada']]);
        $source->setLocalState(77, ['user' => ['name' => 'Ada']]);
        $source->removeStates([77]);

        self::assertCount(3, $messages);
        foreach ($messages as $message) {
            self::assertSame(AwarenessProtocol::MESSAGE_AWARENESS, AwarenessProtocol::readMessage($message)['type']);
            AwarenessProtocol::handleMessage($target, $message);
        }

        self::assertNull($target->getState(77));
        self::assertSame($source->getStates(), $target->getStates());
    }

    public function testObservedAwarenessUpdateMessagesExposeAwarenessOriginAndEvent(): void
    {
        $source = new Awareness();
        $events = [];
        AwarenessProtocol::observeUpdateMessages($source, static function (string $message, Awareness $awareness, mixed $origin, array $event) use (&$events, $source): void {
            $events[] = [
                'type' => AwarenessProtocol::readMessage($message)['type'],
                'sameAwareness' => $awareness === $source,
                'origin' => $origin,
                'added' => $event['added'],
                'updated' => $event['updated'],
                'removed' => $event['removed'],
            ];
        });

        $source->setLocalState(77, ['user' => ['name' => 'Ada']], 'awareness-message-origin');

        self::assertSame([
            [
                'type' => AwarenessProtocol::MESSAGE_AWARENESS,
                'sameAwareness' => true,
                'origin' => 'awareness-message-origin',
                'added' => [77],
                'updated' => [],
                'removed' => [],
            ],
        ], $events);
    }

    public function testObservedAwarenessUpdateMessagesIncludeTimeoutRemovals(): void
    {
        $source = new Awareness();
        $target = new Awareness();
        $messages = [];
        $events = [];

        $source->setLocalState(92, ['user' => ['name' => 'Quinn']]);
        $source->setLocalState(93, ['user' => ['name' => 'Rhea']]);
        AwarenessProtocol::handleMessage($target, AwarenessProtocol::writeState($source));

        AwarenessProtocol::observeUpdateMessages($source, static function (string $message, Awareness $awareness, mixed $origin, array $event) use (&$messages, &$events, $source): void {
            $messages[] = $message;
            $events[] = [
                'sameAwareness' => $awareness === $source,
                'origin' => $origin,
                'added' => $event['added'],
                'updated' => $event['updated'],
                'removed' => $event['removed'],
            ];
        });

        $meta = $source->getMeta();
        $timeoutAt = max(array_column($meta, 'lastUpdated')) + Awareness::OUTDATED_TIMEOUT;
        self::assertSame([92, 93], $source->removeOutdatedStates($timeoutAt, origin: 'timeout-origin'));

        self::assertCount(1, $messages);
        self::assertSame([
            [
                'sameAwareness' => true,
                'origin' => 'timeout-origin',
                'added' => [],
                'updated' => [],
                'removed' => [92, 93],
            ],
        ], $events);

        self::assertSame([92, 93], AwarenessProtocol::handleMessage($target, $messages[0]));
        self::assertNull($target->getState(92));
        self::assertNull($target->getState(93));
        self::assertSame($source->getStates(), $target->getStates());
    }

    public function testCanUnobserveAwarenessUpdateMessages(): void
    {
        $awareness = new Awareness();
        $messages = [];
        $observerId = AwarenessProtocol::observeUpdateMessages($awareness, static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $awareness->setLocalState(77, ['user' => ['name' => 'Ada']]);
        AwarenessProtocol::unobserveUpdateMessages($awareness, $observerId);
        $awareness->setLocalState(77, ['user' => ['name' => 'Grace']]);

        self::assertCount(1, $messages);
        self::assertSame(AwarenessProtocol::MESSAGE_AWARENESS, AwarenessProtocol::readMessage($messages[0])['type']);
    }

    public function testOneShotAwarenessObserversFireOnce(): void
    {
        $awareness = new Awareness();
        $changes = [];
        $updates = [];
        $messages = [];

        $awareness->observeOnce(static function (array $event, Awareness $awareness, mixed $origin) use (&$changes): void {
            $changes[] = [
                'origin' => $origin,
                'added' => $event['added'],
                'updated' => $event['updated'],
                'removed' => $event['removed'],
            ];
        });
        $awareness->observeUpdateOnce(static function (array $event, Awareness $awareness, mixed $origin) use (&$updates): void {
            $updates[] = [
                'origin' => $origin,
                'added' => $event['added'],
                'updated' => $event['updated'],
                'removed' => $event['removed'],
            ];
        });
        AwarenessProtocol::observeUpdateMessagesOnce($awareness, static function (string $message, Awareness $observedAwareness, mixed $origin, array $event) use (&$messages, $awareness): void {
            $messages[] = [
                'message' => $message,
                'sameAwareness' => $observedAwareness === $awareness,
                'origin' => $origin,
                'added' => $event['added'],
                'updated' => $event['updated'],
                'removed' => $event['removed'],
            ];
        });

        $awareness->setLocalState(77, ['user' => ['name' => 'Ada']], 'first-awareness-origin');
        $awareness->setLocalStateField(77, 'cursor', ['anchor' => 1, 'head' => 1], 'second-awareness-origin');

        self::assertSame([
            [
                'origin' => 'first-awareness-origin',
                'added' => [77],
                'updated' => [],
                'removed' => [],
            ],
        ], $changes);
        self::assertSame($changes, $updates);
        self::assertCount(1, $messages);
        self::assertTrue($messages[0]['sameAwareness']);
        self::assertSame('first-awareness-origin', $messages[0]['origin']);
        self::assertSame([77], $messages[0]['added']);
        self::assertSame(AwarenessProtocol::MESSAGE_AWARENESS, AwarenessProtocol::readMessage($messages[0]['message'])['type']);
    }

    public function testAwarenessObserverAdditionsDuringDispatchReceiveCurrentEvent(): void
    {
        $awareness = new Awareness();
        $changes = [];
        $updates = [];
        $messages = [];

        $awareness->observe(function (array $event) use ($awareness, &$changes): void {
            $changes[] = 'first:' . implode(',', $event['added']);
            $awareness->observe(static function (array $event) use (&$changes): void {
                $changes[] = 'second:' . implode(',', $event['added']);
            });
        });
        $awareness->observeUpdate(function (array $event) use ($awareness, &$updates): void {
            $updates[] = 'first:' . implode(',', $event['added']);
            $awareness->observeUpdate(static function (array $event) use (&$updates): void {
                $updates[] = 'second:' . implode(',', $event['added']);
            });
        });
        AwarenessProtocol::observeUpdateMessages($awareness, function (string $message) use ($awareness, &$messages): void {
            $messages[] = 'first:' . AwarenessProtocol::readMessage($message)['type'];
            AwarenessProtocol::observeUpdateMessages($awareness, static function (string $message) use (&$messages): void {
                $messages[] = 'second:' . AwarenessProtocol::readMessage($message)['type'];
            });
        });

        $awareness->setLocalState(77, ['user' => ['name' => 'Ada']]);

        self::assertSame(['first:77', 'second:77'], $changes);
        self::assertSame(['first:77', 'second:77'], $updates);
        self::assertSame([
            'first:' . AwarenessProtocol::MESSAGE_AWARENESS,
            'second:' . AwarenessProtocol::MESSAGE_AWARENESS,
        ], $messages);
    }

    public function testAwarenessObserverRemovalsDuringDispatchSkipRemovedObserver(): void
    {
        $awareness = new Awareness();
        $changes = [];
        $updates = [];
        $messages = [];

        $changeObserverToRemove = null;
        $awareness->observe(static function () use (&$changes, &$changeObserverToRemove, $awareness): void {
            $changes[] = 'first';
            if ($changeObserverToRemove !== null) {
                $awareness->unobserve($changeObserverToRemove);
            }
        });
        $changeObserverToRemove = $awareness->observe(static function () use (&$changes): void {
            $changes[] = 'removed';
        });

        $updateObserverToRemove = null;
        $awareness->observeUpdate(static function () use (&$updates, &$updateObserverToRemove, $awareness): void {
            $updates[] = 'first';
            if ($updateObserverToRemove !== null) {
                $awareness->unobserveUpdate($updateObserverToRemove);
            }
        });
        $updateObserverToRemove = $awareness->observeUpdate(static function () use (&$updates): void {
            $updates[] = 'removed';
        });

        $messageObserverToRemove = null;
        AwarenessProtocol::observeUpdateMessages($awareness, static function () use (&$messages, &$messageObserverToRemove, $awareness): void {
            $messages[] = 'first';
            if ($messageObserverToRemove !== null) {
                AwarenessProtocol::unobserveUpdateMessages($awareness, $messageObserverToRemove);
            }
        });
        $messageObserverToRemove = AwarenessProtocol::observeUpdateMessages($awareness, static function () use (&$messages): void {
            $messages[] = 'removed';
        });

        $awareness->setLocalState(77, ['user' => ['name' => 'Ada']]);

        self::assertSame(['first'], $changes);
        self::assertSame(['first'], $updates);
        self::assertSame(['first'], $messages);
    }

    public function testNativeAwarenessChangeAndUpdateObserversMatchRemoteYjsEvents(): void
    {
        $fixture = $this->loadFixture();
        $awareness = new Awareness();
        $events = [];
        $awareness->observe(static function (array $event, Awareness $awareness, mixed $origin) use (&$events): void {
            $events[] = [
                'type' => 'change',
                'event' => [
                    'added' => $event['added'],
                    'updated' => $event['updated'],
                    'removed' => $event['removed'],
                ],
                'origin' => $origin,
            ];
        });
        $awareness->observeUpdate(static function (array $event, Awareness $awareness, mixed $origin) use (&$events): void {
            $events[] = [
                'type' => 'update',
                'event' => [
                    'added' => $event['added'],
                    'updated' => $event['updated'],
                    'removed' => $event['removed'],
                ],
                'origin' => $origin,
            ];
        });

        $awareness->applyUpdate(AwarenessUpdate::encode([
            [
                'clientID' => 77,
                'clock' => 1,
                'state' => ['user' => ['name' => 'Ada']],
            ],
        ]), 'remote-add');
        $awareness->applyUpdate(AwarenessUpdate::encode([
            [
                'clientID' => 77,
                'clock' => 1,
                'state' => ['user' => ['name' => 'Ada']],
            ],
        ]), 'remote-same-clock');
        self::assertSame([], $awareness->applyUpdate(AwarenessUpdate::encode([
            [
                'clientID' => 77,
                'clock' => 2,
                'state' => ['user' => ['name' => 'Ada']],
            ],
        ]), 'remote-newer-same-state'));
        $awareness->applyUpdate(AwarenessUpdate::encode([
            [
                'clientID' => 77,
                'clock' => 3,
                'state' => ['user' => ['name' => 'Grace']],
            ],
        ]), 'remote-newer-different-state');
        $awareness->applyUpdate(AwarenessUpdate::encode([
            [
                'clientID' => 77,
                'clock' => 3,
                'state' => null,
            ],
        ]), 'remote-same-clock-remove');

        self::assertSame($fixture['remoteEventSequence'], $events);
    }

    public function testNativeAwarenessObserversReceiveAddedUpdatedAndRemovedEvents(): void
    {
        $awareness = new Awareness();
        $events = [];
        $awareness->observe(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $addUpdate = $awareness->setLocalState(77, ['user' => ['name' => 'Ada']]);
        $updateUpdate = $awareness->setLocalState(77, ['user' => ['name' => 'Grace']]);
        $removeUpdate = $awareness->removeStates([77]);

        self::assertCount(3, $events);
        self::assertSame([77], $events[0]['added']);
        self::assertSame([], $events[0]['updated']);
        self::assertSame([], $events[0]['removed']);
        self::assertSame($addUpdate, $events[0]['update']);

        self::assertSame([], $events[1]['added']);
        self::assertSame([77], $events[1]['updated']);
        self::assertSame([], $events[1]['removed']);
        self::assertSame($updateUpdate, $events[1]['update']);

        self::assertSame([], $events[2]['added']);
        self::assertSame([], $events[2]['updated']);
        self::assertSame([77], $events[2]['removed']);
        self::assertSame([77], $events[2]['changed']);
        self::assertSame($removeUpdate, $events[2]['update']);
    }

    public function testNativeAwarenessObserversReceiveLocalOrigins(): void
    {
        $awareness = new Awareness();
        $events = [];
        $awareness->observe(static function (array $event, Awareness $awareness, mixed $origin) use (&$events): void {
            $events[] = [$event['origin'], $origin];
        });

        $awareness->setLocalState(77, ['user' => ['name' => 'Ada']], 'local-origin');
        $awareness->setLocalStateField(77, 'cursor', ['anchor' => 1, 'head' => 1], 'field-origin');
        $awareness->removeStates([77], 'remove-origin');

        self::assertSame([
            ['local-origin', 'local-origin'],
            ['field-origin', 'field-origin'],
            ['remove-origin', 'remove-origin'],
        ], $events);
    }

    public function testNativeAwarenessObserverIgnoresStaleUpdates(): void
    {
        $awareness = new Awareness();
        $events = [];
        $update = $awareness->setLocalState(77, ['user' => ['name' => 'Ada']]);

        $remote = new Awareness();
        $remote->observe(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        self::assertSame([77], $remote->applyUpdate($update));
        self::assertSame([], $remote->applyUpdate($update));
        self::assertCount(1, $events);
    }

    public function testCanUnobserveNativeAwarenessUpdateObserver(): void
    {
        $awareness = new Awareness();
        $events = [];
        $observerId = $awareness->observeUpdate(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $awareness->setLocalState(77, ['user' => ['name' => 'Ada']]);
        $awareness->unobserveUpdate($observerId);
        $awareness->setLocalState(77, ['user' => ['name' => 'Ada']]);

        self::assertCount(1, $events);
    }

    public function testCanUnobserveNativeAwarenessObserver(): void
    {
        $awareness = new Awareness();
        $events = [];
        $observerId = $awareness->observe(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $awareness->setLocalState(77, ['user' => ['name' => 'Ada']]);
        $awareness->unobserve($observerId);
        $awareness->setLocalState(78, ['user' => ['name' => 'Grace']]);

        self::assertCount(1, $events);
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
}
