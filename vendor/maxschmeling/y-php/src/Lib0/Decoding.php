<?php

declare(strict_types=1);

namespace Yjs\Lib0;

use Yjs\UndefinedValue;

final class Decoding
{
    private int $position = 0;

    public function __construct(private readonly string $buffer)
    {
    }

    public function readUint8(): int
    {
        if ($this->position >= strlen($this->buffer)) {
            throw new \UnderflowException('Cannot read past the end of the buffer.');
        }

        return ord($this->buffer[$this->position++]);
    }

    public function readVarUint(): int
    {
        $num = 0;
        $multiplier = 1;

        do {
            $byte = $this->readUint8();
            $num += ($byte & 0x7f) * $multiplier;
            $multiplier *= 0x80;
        } while ($byte >= 0x80);

        return $num;
    }

    public function readVarInt(): int
    {
        $byte = $this->readUint8();
        $num = $byte & 0x3f;
        $multiplier = 0x40;
        $sign = ($byte & 0x40) > 0 ? -1 : 1;

        if (($byte & 0x80) === 0) {
            return $sign * $num;
        }

        do {
            $byte = $this->readUint8();
            $num += ($byte & 0x7f) * $multiplier;
            $multiplier *= 0x80;
        } while ($byte >= 0x80);

        return $sign * $num;
    }

    public function readVarString(): string
    {
        return $this->readBytes($this->readVarUint());
    }

    public function readVarUint8Array(): string
    {
        return $this->readBytes($this->readVarUint());
    }

    public function readAny(): mixed
    {
        return match (127 - $this->readUint8()) {
            0 => UndefinedValue::instance(),
            1 => null,
            2 => $this->readVarInt(),
            3 => $this->readFloat32(),
            4 => $this->readFloat64(),
            5 => $this->readBigInt64(),
            6 => false,
            7 => true,
            8 => $this->readVarString(),
            9 => $this->readAnyObject(),
            10 => $this->readAnyArray(),
            11 => $this->readVarUint8Array(),
            default => throw new \UnexpectedValueException('Unsupported lib0 any value type.'),
        };
    }

    public function readBytes(int $length): string
    {
        if ($length < 0) {
            throw new \InvalidArgumentException('Length must be non-negative.');
        }

        if ($this->position + $length > strlen($this->buffer)) {
            throw new \UnderflowException('Cannot read past the end of the buffer.');
        }

        $bytes = substr($this->buffer, $this->position, $length);
        $this->position += $length;

        return $bytes;
    }

    public function hasContent(): bool
    {
        return $this->position < strlen($this->buffer);
    }

    /**
     * @return array<string, mixed>
     */
    private function readAnyObject(): array
    {
        $length = $this->readVarUint();
        $object = [];

        for ($i = 0; $i < $length; $i++) {
            $object[$this->readVarString()] = $this->readAny();
        }

        return $object;
    }

    /**
     * @return array<int, mixed>
     */
    private function readAnyArray(): array
    {
        $length = $this->readVarUint();
        $array = [];

        for ($i = 0; $i < $length; $i++) {
            $array[] = $this->readAny();
        }

        return $array;
    }

    private function readFloat32(): float
    {
        $bytes = $this->readBytes(4);
        $value = unpack('G', $bytes);

        if ($value === false) {
            throw new \UnexpectedValueException('Failed to decode float32.');
        }

        return $value[1];
    }

    private function readFloat64(): float
    {
        $bytes = $this->readBytes(8);
        $value = unpack('E', $bytes);

        if ($value === false) {
            throw new \UnexpectedValueException('Failed to decode float64.');
        }

        return $value[1];
    }

    private function readBigInt64(): int
    {
        $parts = unpack('Nhigh/Nlow', $this->readBytes(8));

        if ($parts === false) {
            throw new \UnexpectedValueException('Failed to decode bigint64.');
        }

        $high = $parts['high'];
        $low = $parts['low'];
        $signedHigh = $high >= 0x80000000 ? $high - 0x100000000 : $high;

        return ($signedHigh << 32) | $low;
    }
}
