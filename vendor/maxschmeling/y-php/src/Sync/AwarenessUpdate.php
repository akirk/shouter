<?php

declare(strict_types=1);

namespace Yjs\Sync;

use Yjs\Lib0\Decoding;
use Yjs\Lib0\Encoding;
use Yjs\UndefinedValue;

final class AwarenessUpdate
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * @param list<array{clientID: int, clock: int, state: mixed}> $updates
     */
    public static function encode(array $updates): string
    {
        $encoder = new Encoding();
        $encoder->writeVarUint(count($updates));

        foreach ($updates as $update) {
            $encoder->writeVarUint($update['clientID']);
            $encoder->writeVarUint($update['clock']);
            [$omit, $state] = self::normalizeJsonValue($update['state'], 'root');
            if ($omit) {
                throw new \UnexpectedValueException('Unable to encode awareness state as JSON.');
            }

            if (is_array($state) && $state === []) {
                $state = new \stdClass();
            }

            try {
                $encoder->writeVarString(json_encode($state, self::JSON_FLAGS | JSON_THROW_ON_ERROR));
            } catch (\JsonException $exception) {
                throw new \UnexpectedValueException('Unable to encode awareness state as JSON.', 0, $exception);
            }
        }

        return $encoder->toString();
    }

    /**
     * @return list<array{clientID: int, clock: int, state: mixed}>
     */
    public static function decode(string $update): array
    {
        $decoder = new Decoding($update);
        $length = $decoder->readVarUint();
        $updates = [];

        for ($i = 0; $i < $length; $i++) {
            $clientID = $decoder->readVarUint();
            $clock = $decoder->readVarUint();
            $state = $decoder->readVarString();

            try {
                $decodedState = json_decode($state, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new \UnexpectedValueException('Unable to decode awareness state JSON.', 0, $exception);
            }

            $updates[] = [
                'clientID' => $clientID,
                'clock' => $clock,
                'state' => $decodedState,
            ];
        }

        if ($decoder->hasContent()) {
            throw new \UnexpectedValueException('Awareness update contains trailing bytes.');
        }

        return $updates;
    }

    public static function modify(string $update, callable $modify): string
    {
        $updates = self::decode($update);

        foreach ($updates as &$entry) {
            $entry['state'] = $modify($entry['state'], $entry['clientID'], $entry['clock']);
        }
        unset($entry);

        return self::encode($updates);
    }

    /**
     * Match JSON.stringify for values PHP can otherwise represent differently:
     * undefined object fields are omitted, undefined array items become null.
     *
     * @return array{0: bool, 1: mixed}
     */
    private static function normalizeJsonValue(mixed $value, string $context): array
    {
        if ($value instanceof UndefinedValue) {
            return [$context !== 'list', null];
        }

        if ($value instanceof \stdClass) {
            $normalized = [];

            foreach (get_object_vars($value) as $key => $nested) {
                [$omit, $normalizedNested] = self::normalizeJsonValue($nested, 'object');
                if (! $omit) {
                    $normalized[$key] = $normalizedNested;
                }
            }

            return [false, $normalized === [] ? new \stdClass() : $normalized];
        }

        if (! is_array($value)) {
            if (is_float($value) && ! is_finite($value)) {
                return [false, null];
            }

            return [false, $value];
        }

        if (array_is_list($value)) {
            $normalized = [];

            foreach ($value as $nested) {
                [, $normalizedNested] = self::normalizeJsonValue($nested, 'list');
                $normalized[] = $normalizedNested;
            }

            return [false, $normalized];
        }

        $normalized = [];

        foreach ($value as $key => $nested) {
            [$omit, $normalizedNested] = self::normalizeJsonValue($nested, 'object');
            if (! $omit) {
                $normalized[$key] = $normalizedNested;
            }
        }

        return [false, $normalized === [] ? new \stdClass() : $normalized];
    }
}
