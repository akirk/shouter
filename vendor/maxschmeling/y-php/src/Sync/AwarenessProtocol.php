<?php

declare(strict_types=1);

namespace Yjs\Sync;

use Yjs\Lib0\Decoding;
use Yjs\Lib0\Encoding;

final class AwarenessProtocol
{
    public const MESSAGE_QUERY_AWARENESS = 0;
    public const MESSAGE_AWARENESS = 1;

    public static function writeMessage(int $type, string $payload): string
    {
        $encoder = new Encoding();
        $encoder->writeVarUint($type);
        $encoder->writeVarUint8Array($payload);

        return $encoder->toString();
    }

    public static function writeUpdate(string $update): string
    {
        return self::writeMessage(self::MESSAGE_AWARENESS, $update);
    }

    public static function writeQuery(): string
    {
        return self::writeMessage(self::MESSAGE_QUERY_AWARENESS, '');
    }

    public static function modifyUpdate(string $update, callable $modify): string
    {
        return AwarenessUpdate::modify($update, $modify);
    }

    /**
     * @param list<int>|null $clientIDs
     */
    public static function writeState(Awareness $awareness, ?array $clientIDs = null): string
    {
        return self::writeUpdate($awareness->encodeUpdate($clientIDs));
    }

    /**
     * @param list<int> $clientIDs
     */
    public static function writeRemoveStates(Awareness $awareness, array $clientIDs, mixed $origin = null): string
    {
        return self::writeUpdate($awareness->removeStates($clientIDs, $origin));
    }

    /**
     * @param callable(string, Awareness, mixed, array<string, mixed>): void $send
     */
    public static function observeUpdateMessages(Awareness $awareness, callable $send): int
    {
        return $awareness->observeUpdate(static function (array $event, Awareness $awareness, mixed $origin) use ($send): void {
            $send(self::writeUpdate($event['update']), $awareness, $origin, $event);
        });
    }

    /**
     * @param callable(string, Awareness, mixed, array<string, mixed>): void $send
     */
    public static function observeUpdateMessagesOnce(Awareness $awareness, callable $send): int
    {
        return $awareness->observeUpdateOnce(static function (array $event, Awareness $awareness, mixed $origin) use ($send): void {
            $send(self::writeUpdate($event['update']), $awareness, $origin, $event);
        });
    }

    public static function unobserveUpdateMessages(Awareness $awareness, int $observerId): void
    {
        $awareness->unobserveUpdate($observerId);
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
            throw new \UnexpectedValueException('Awareness message contains trailing bytes.');
        }

        return [
            'type' => $type,
            'payload' => $payload,
        ];
    }

    public static function readUpdate(string $message): string
    {
        $decoded = self::readMessage($message);
        if ($decoded['type'] !== self::MESSAGE_AWARENESS) {
            throw new \UnexpectedValueException(sprintf('Expected awareness message type 1, received %d.', $decoded['type']));
        }

        return $decoded['payload'];
    }

    public static function readQuery(string $message): void
    {
        $decoded = self::readMessage($message);
        if ($decoded['type'] !== self::MESSAGE_QUERY_AWARENESS) {
            throw new \UnexpectedValueException(sprintf('Expected awareness query message type 0, received %d.', $decoded['type']));
        }

        if ($decoded['payload'] !== '') {
            throw new \UnexpectedValueException('Awareness query message payload must be empty.');
        }
    }

    /**
     * @param list<int>|null $clientIDs
     */
    public static function writeReplyToQuery(Awareness $awareness, string $queryMessage, ?array $clientIDs = null): string
    {
        self::readQuery($queryMessage);

        return self::writeState($awareness, $clientIDs);
    }

    /**
     * @return list<int> Client IDs whose awareness state changed.
     */
    public static function applyUpdate(Awareness $awareness, string $message, mixed $origin = null): array
    {
        return $awareness->applyUpdate(self::readUpdate($message), $origin);
    }

    /**
     * @return list<int> Client IDs whose awareness state changed.
     */
    public static function handleMessage(Awareness $awareness, string $message, mixed $origin = null): array
    {
        $decoded = self::readMessage($message);
        if ($decoded['type'] !== self::MESSAGE_AWARENESS) {
            throw new \UnexpectedValueException(sprintf('Unknown awareness message type %d.', $decoded['type']));
        }

        return $awareness->applyUpdate($decoded['payload'], $origin);
    }

    /**
     * Applies awareness update messages and returns a state reply for query messages.
     *
     * @param list<int>|null $clientIDs
     */
    public static function handleMessageWithReply(Awareness $awareness, string $message, mixed $origin = null, ?array $clientIDs = null): ?string
    {
        $decoded = self::readMessage($message);

        return match ($decoded['type']) {
            self::MESSAGE_QUERY_AWARENESS => self::writeReplyToDecodedQuery($awareness, $decoded['payload'], $clientIDs),
            self::MESSAGE_AWARENESS => self::applyUpdatePayload($awareness, $decoded['payload'], $origin),
            default => throw new \UnexpectedValueException(sprintf('Unknown awareness message type %d.', $decoded['type'])),
        };
    }

    /**
     * @param list<int>|null $clientIDs
     */
    private static function writeReplyToDecodedQuery(Awareness $awareness, string $payload, ?array $clientIDs): string
    {
        if ($payload !== '') {
            throw new \UnexpectedValueException('Awareness query message payload must be empty.');
        }

        return self::writeState($awareness, $clientIDs);
    }

    private static function applyUpdatePayload(Awareness $awareness, string $update, mixed $origin): ?string
    {
        $awareness->applyUpdate($update, $origin);

        return null;
    }
}
