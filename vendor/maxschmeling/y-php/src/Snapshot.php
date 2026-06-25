<?php

declare(strict_types=1);

namespace Yjs;

use Yjs\Lib0\Decoding;
use Yjs\Lib0\Encoding;
use Yjs\Update\DecodedUpdate;
use Yjs\Update\UpdateUtils;

final class Snapshot
{
    /**
     * @param array<int, list<array{clock: int, length: int}>> $deleteSet
     * @param array<int, int> $stateVector
     */
    public function __construct(private array $deleteSet = [], private array $stateVector = [])
    {
        $this->deleteSet = UpdateUtils::mergeDeleteSets([$deleteSet]);
        krsort($stateVector, SORT_NUMERIC);
        $this->stateVector = $stateVector;
    }

    public static function empty(): self
    {
        return new self();
    }

    public static function decodeV1(string $snapshot): self
    {
        $decoder = new Decoding($snapshot);
        $decoded = new self(self::readDeleteSet($decoder, 1), self::readStateVector($decoder));

        if ($decoder->hasContent()) {
            throw new \UnexpectedValueException('Snapshot contains trailing bytes.');
        }

        return $decoded;
    }

    public static function decodeV2(string $snapshot): self
    {
        $decoder = new Decoding($snapshot);
        $decoded = new self(self::readDeleteSet($decoder, 2), self::readStateVector($decoder));

        if ($decoder->hasContent()) {
            throw new \UnexpectedValueException('Snapshot contains trailing bytes.');
        }

        return $decoded;
    }

    public function encodeV1(): string
    {
        $encoder = new Encoding();
        self::writeDeleteSet($encoder, $this->deleteSet, 1);
        self::writeStateVector($encoder, $this->stateVector);

        return $encoder->toString();
    }

    public function encodeV2(): string
    {
        $encoder = new Encoding();
        self::writeDeleteSet($encoder, $this->deleteSet, 2);
        self::writeStateVector($encoder, $this->stateVector);

        return $encoder->toString();
    }

    public function equals(self $other): bool
    {
        return UpdateUtils::equalDeleteSets($this->deleteSet, $other->deleteSet)
            && $this->stateVector === $other->stateVector;
    }

    public function containsUpdateV1(string $update): bool
    {
        return $this->containsDecodedUpdate(DecodedUpdate::decodeV1($update));
    }

    public function containsUpdateV2(string $update): bool
    {
        return $this->containsDecodedUpdate(DecodedUpdate::decodeV2($update));
    }

    /**
     * @return array<int, list<array{clock: int, length: int}>>
     */
    public function deleteSet(): array
    {
        return $this->deleteSet;
    }

    /**
     * @return array<int, int>
     */
    public function stateVector(): array
    {
        return $this->stateVector;
    }

    /**
     * @return array{
     *     deleteSet: array<int, list<array{clock: int, length: int}>>,
     *     stateVector: array<int, int>
     * }
     */
    public function toArray(): array
    {
        return [
            'deleteSet' => $this->deleteSet,
            'stateVector' => $this->stateVector,
        ];
    }

    /**
     * @param array{
     *     structs: list<array<string, mixed>>,
     *     deleteSet: array<int, list<array{clock: int, length: int}>>
     * } $decoded
     */
    private function containsDecodedUpdate(array $decoded): bool
    {
        foreach ($decoded['structs'] as $struct) {
            $client = $struct['id']['client'];
            $clock = $struct['id']['clock'];
            $length = $struct['length'];

            if (($this->stateVector[$client] ?? 0) < $clock + $length) {
                return false;
            }
        }

        $mergedDeleteSet = UpdateUtils::mergeDeleteSets([$this->deleteSet, $decoded['deleteSet']]);

        return UpdateUtils::equalDeleteSets($this->deleteSet, $mergedDeleteSet);
    }

    /**
     * @return array<int, list<array{clock: int, length: int}>>
     */
    private static function readDeleteSet(Decoding $decoder, int $version): array
    {
        $deleteSet = [];
        $numClients = $decoder->readVarUint();

        for ($i = 0; $i < $numClients; $i++) {
            $currentClock = 0;
            $client = $decoder->readVarUint();
            $numberOfDeletes = $decoder->readVarUint();

            for ($deleteIndex = 0; $deleteIndex < $numberOfDeletes; $deleteIndex++) {
                if ($version === 1) {
                    $clock = $decoder->readVarUint();
                    $length = $decoder->readVarUint();
                } else {
                    $currentClock += $decoder->readVarUint();
                    $clock = $currentClock;
                    $length = $decoder->readVarUint() + 1;
                    $currentClock += $length;
                }

                $deleteSet[$client][] = [
                    'clock' => $clock,
                    'length' => $length,
                ];
            }
        }

        return $deleteSet;
    }

    /**
     * @param array<int, list<array{clock: int, length: int}>> $deleteSet
     */
    private static function writeDeleteSet(Encoding $encoder, array $deleteSet, int $version): void
    {
        $deleteSet = UpdateUtils::mergeDeleteSets([$deleteSet]);
        $encoder->writeVarUint(count($deleteSet));

        foreach ($deleteSet as $client => $deletes) {
            $currentClock = 0;
            $encoder->writeVarUint((int) $client);
            $encoder->writeVarUint(count($deletes));

            foreach ($deletes as $delete) {
                if ($version === 1) {
                    $encoder->writeVarUint($delete['clock']);
                    $encoder->writeVarUint($delete['length']);
                    continue;
                }

                $encoder->writeVarUint($delete['clock'] - $currentClock);
                $currentClock = $delete['clock'];
                $encoder->writeVarUint($delete['length'] - 1);
                $currentClock += $delete['length'];
            }
        }
    }

    /**
     * @return array<int, int>
     */
    private static function readStateVector(Decoding $decoder): array
    {
        $length = $decoder->readVarUint();
        $stateVector = [];

        for ($i = 0; $i < $length; $i++) {
            $stateVector[$decoder->readVarUint()] = $decoder->readVarUint();
        }

        krsort($stateVector, SORT_NUMERIC);

        return $stateVector;
    }

    /**
     * @param array<int, int> $stateVector
     */
    private static function writeStateVector(Encoding $encoder, array $stateVector): void
    {
        krsort($stateVector, SORT_NUMERIC);
        $encoder->writeVarUint(count($stateVector));

        foreach ($stateVector as $client => $clock) {
            if (! is_int($client) || $client < 0) {
                throw new \InvalidArgumentException('Snapshot state vector client IDs must be non-negative integers.');
            }

            if ($clock < 0) {
                throw new \InvalidArgumentException('Snapshot state vector clocks must be non-negative integers.');
            }

            $encoder->writeVarUint($client);
            $encoder->writeVarUint($clock);
        }
    }
}
