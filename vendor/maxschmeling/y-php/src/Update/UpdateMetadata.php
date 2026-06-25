<?php

declare(strict_types=1);

namespace Yjs\Update;

final class UpdateMetadata
{
    /**
     * @return array{from: array<int, int>, to: array<int, int>}
     */
    public static function parseV1(string $update): array
    {
        return self::parseDecodedStructs(DecodedUpdate::decodeV1($update)['structs']);
    }

    /**
     * @return array{from: array<int, int>, to: array<int, int>}
     */
    public static function parseV2(string $update): array
    {
        return self::parseDecodedStructs(DecodedUpdate::decodeV2($update)['structs']);
    }

    /**
     * @param list<array<string, mixed>> $structs
     * @return array{from: array<int, int>, to: array<int, int>}
     */
    private static function parseDecodedStructs(array $structs): array
    {
        $from = [];
        $to = [];
        $currentClient = null;
        $currentClock = null;

        foreach ($structs as $struct) {
            $client = $struct['id']['client'];
            $clock = $struct['id']['clock'];

            if ($currentClient !== $client) {
                if ($currentClient !== null && $currentClock !== null) {
                    $to[$currentClient] = $currentClock;
                }
                $from[$client] = $clock;
                $currentClient = $client;
            }

            $currentClock = $clock + $struct['length'];
        }

        if ($currentClient !== null && $currentClock !== null) {
            $to[$currentClient] = $currentClock;
        }

        return [
            'from' => $from,
            'to' => $to,
        ];
    }
}
