<?php

declare(strict_types=1);

namespace Yjs\Sync;

use Yjs\Lib0\Decoding;
use Yjs\Lib0\Encoding;
use Yjs\YDoc;

final class SyncProtocol
{
    public const MESSAGE_SYNC_STEP_1 = 0;
    public const MESSAGE_SYNC_STEP_2 = 1;
    public const MESSAGE_UPDATE = 2;

    public static function writeMessage(int $type, string $payload): string
    {
        $encoder = new Encoding();
        $encoder->writeVarUint($type);
        $encoder->writeVarUint8Array($payload);

        return $encoder->toString();
    }

    public static function writeSyncStep1(YDoc $doc): string
    {
        return self::writeMessage(self::MESSAGE_SYNC_STEP_1, $doc->encodeStateVector());
    }

    public static function writeSyncStep2(YDoc $doc, ?string $encodedStateVector = null): string
    {
        return self::writeMessage(self::MESSAGE_SYNC_STEP_2, $doc->encodeStateAsUpdateV1($encodedStateVector));
    }

    public static function writeSyncStep2V2(YDoc $doc, ?string $encodedStateVector = null): string
    {
        return self::writeMessage(self::MESSAGE_SYNC_STEP_2, $doc->encodeStateAsUpdateV2($encodedStateVector));
    }

    public static function writeUpdate(string $update): string
    {
        return self::writeMessage(self::MESSAGE_UPDATE, $update);
    }

    public static function writeUpdateV2(string $update): string
    {
        return self::writeUpdate($update);
    }

    public static function readSyncStep1(string $message): string
    {
        return self::readExpectedMessage($message, self::MESSAGE_SYNC_STEP_1, 'sync step 1');
    }

    public static function readSyncStep2(string $message): string
    {
        return self::readExpectedMessage($message, self::MESSAGE_SYNC_STEP_2, 'sync step 2');
    }

    public static function readSyncStep2V2(string $message): string
    {
        return self::readExpectedMessage($message, self::MESSAGE_SYNC_STEP_2, 'sync step 2 V2');
    }

    public static function readUpdate(string $message): string
    {
        return self::readExpectedMessage($message, self::MESSAGE_UPDATE, 'sync update');
    }

    public static function readUpdateV2(string $message): string
    {
        return self::readExpectedMessage($message, self::MESSAGE_UPDATE, 'sync update V2');
    }

    public static function writeReplyToSyncStep1(YDoc $doc, string $syncStep1Message): string
    {
        return self::writeSyncStep2($doc, self::readSyncStep1($syncStep1Message));
    }

    public static function writeReplyToSyncStep1V2(YDoc $doc, string $syncStep1Message): string
    {
        return self::writeSyncStep2V2($doc, self::readSyncStep1($syncStep1Message));
    }

    public static function applySyncStep2(YDoc $doc, string $syncStep2Message, mixed $origin = null): void
    {
        $doc->applyUpdateV1(self::readSyncStep2($syncStep2Message), $origin);
    }

    public static function applySyncStep2V2(YDoc $doc, string $syncStep2Message, mixed $origin = null): void
    {
        $doc->applyUpdateV2(self::readSyncStep2V2($syncStep2Message), $origin);
    }

    public static function applyUpdate(YDoc $doc, string $updateMessage, mixed $origin = null): void
    {
        $doc->applyUpdateV1(self::readUpdate($updateMessage), $origin);
    }

    public static function applyUpdateV2(YDoc $doc, string $updateMessage, mixed $origin = null): void
    {
        $doc->applyUpdateV2(self::readUpdateV2($updateMessage), $origin);
    }

    /**
     * @param callable(string, YDoc, mixed): void $send
     */
    public static function observeUpdateMessages(YDoc $doc, callable $send): int
    {
        return $doc->observeUpdate(static function (string $update, YDoc $doc, mixed $origin) use ($send): void {
            $send(self::writeUpdate($update), $doc, $origin);
        });
    }

    /**
     * @param callable(string, YDoc, mixed): void $send
     */
    public static function observeUpdateMessagesOnce(YDoc $doc, callable $send): int
    {
        return $doc->observeUpdateOnce(static function (string $update, YDoc $doc, mixed $origin) use ($send): void {
            $send(self::writeUpdate($update), $doc, $origin);
        });
    }

    public static function unobserveUpdateMessages(YDoc $doc, int $observerId): void
    {
        $doc->unobserveUpdate($observerId);
    }

    /**
     * @param callable(string, YDoc, mixed): void $send
     */
    public static function observeUpdateV2Messages(YDoc $doc, callable $send): int
    {
        return $doc->observeUpdateV2(static function (string $update, YDoc $doc, mixed $origin) use ($send): void {
            $send(self::writeUpdateV2($update), $doc, $origin);
        });
    }

    /**
     * @param callable(string, YDoc, mixed): void $send
     */
    public static function observeUpdateV2MessagesOnce(YDoc $doc, callable $send): int
    {
        return $doc->observeUpdateV2Once(static function (string $update, YDoc $doc, mixed $origin) use ($send): void {
            $send(self::writeUpdateV2($update), $doc, $origin);
        });
    }

    public static function unobserveUpdateV2Messages(YDoc $doc, int $observerId): void
    {
        $doc->unobserveUpdateV2($observerId);
    }

    /**
     * @return array{type: int, payload: string}
     */
    public static function readMessage(string $message): array
    {
        $decoder = new Decoding($message);
        $type = $decoder->readVarUint();
        $payload = $decoder->readVarUint8Array();

        if ($decoder->hasContent()) {
            throw new \UnexpectedValueException('Sync message contains trailing bytes.');
        }

        return [
            'type' => $type,
            'payload' => $payload,
        ];
    }

    private static function readExpectedMessage(string $message, int $expectedType, string $label): string
    {
        $decoded = self::readMessage($message);
        if ($decoded['type'] !== $expectedType) {
            throw new \UnexpectedValueException(sprintf(
                'Expected %s message type %d, received %d.',
                $label,
                $expectedType,
                $decoded['type']
            ));
        }

        return $decoded['payload'];
    }

    public static function handleMessage(YDoc $doc, string $message, mixed $origin = null): ?string
    {
        $decoded = self::readMessage($message);

        return match ($decoded['type']) {
            self::MESSAGE_SYNC_STEP_1 => self::writeSyncStep2($doc, $decoded['payload']),
            self::MESSAGE_SYNC_STEP_2,
            self::MESSAGE_UPDATE => self::applyUpdatePayload($doc, $decoded['payload'], $origin),
            default => throw new \UnexpectedValueException(sprintf('Unknown sync message type %d.', $decoded['type'])),
        };
    }

    public static function handleMessageV2(YDoc $doc, string $message, mixed $origin = null): ?string
    {
        $decoded = self::readMessage($message);

        return match ($decoded['type']) {
            self::MESSAGE_SYNC_STEP_1 => self::writeSyncStep2V2($doc, $decoded['payload']),
            self::MESSAGE_SYNC_STEP_2,
            self::MESSAGE_UPDATE => self::applyUpdateV2Payload($doc, $decoded['payload'], $origin),
            default => throw new \UnexpectedValueException(sprintf('Unknown sync message type %d.', $decoded['type'])),
        };
    }

    private static function applyUpdatePayload(YDoc $doc, string $update, mixed $origin): ?string
    {
        $doc->applyUpdateV1($update, $origin);

        return null;
    }

    private static function applyUpdateV2Payload(YDoc $doc, string $update, mixed $origin): ?string
    {
        $doc->applyUpdateV2($update, $origin);

        return null;
    }
}
