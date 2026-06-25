<?php

declare(strict_types=1);

namespace Yjs;

use Yjs\Lib0\Decoding;
use Yjs\Lib0\Encoding;

final class StateVector
{
    /**
     * @param array<int, int> $stateVector
     */
    public static function encode(array $stateVector): string
    {
        krsort($stateVector, SORT_NUMERIC);

        $encoder = new Encoding();
        $encoder->writeVarUint(count($stateVector));

        foreach ($stateVector as $client => $clock) {
            if (! is_int($client) || $client < 0) {
                throw new \InvalidArgumentException('State vector client IDs must be non-negative integers.');
            }

            if ($clock < 0) {
                throw new \InvalidArgumentException('State vector clocks must be non-negative integers.');
            }

            $encoder->writeVarUint($client);
            $encoder->writeVarUint($clock);
        }

        return $encoder->toString();
    }

    /**
     * @return array<int, int>
     */
    public static function decode(string $encoded): array
    {
        $decoder = new Decoding($encoded);
        $length = $decoder->readVarUint();
        $stateVector = [];

        for ($i = 0; $i < $length; $i++) {
            $client = $decoder->readVarUint();
            $clock = $decoder->readVarUint();
            $stateVector[$client] = $clock;
        }

        if ($decoder->hasContent()) {
            throw new \UnexpectedValueException('State vector contains trailing bytes.');
        }

        return $stateVector;
    }
}

