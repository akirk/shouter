<?php

declare(strict_types=1);

namespace Yjs\Lib0;

use Yjs\UndefinedValue;

final class Encoding
{
    private const BITS31 = 0x7fffffff;

    private string $buffer = '';

    public function writeUint8(int $value): void
    {
        if ($value < 0 || $value > 0xff) {
            throw new \InvalidArgumentException('Uint8 value must be between 0 and 255.');
        }

        $this->buffer .= chr($value);
    }

    public function writeVarUint(int $value): void
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('VarUint value must be non-negative.');
        }

        while ($value > 0x7f) {
            $this->writeUint8(($value & 0x7f) | 0x80);
            $value = intdiv($value, 0x80);
        }

        $this->writeUint8($value);
    }

    public function writeVarInt(int $value): void
    {
        $isNegative = $value < 0;
        $absolute = abs($value);
        $byte = $absolute & 0x3f;

        if ($absolute > 0x3f) {
            $byte |= 0x80;
        }

        if ($isNegative) {
            $byte |= 0x40;
        }

        $this->writeUint8($byte);
        $absolute = intdiv($absolute, 0x40);

        while ($absolute > 0) {
            $byte = $absolute & 0x7f;
            $absolute = intdiv($absolute, 0x80);

            if ($absolute > 0) {
                $byte |= 0x80;
            }

            $this->writeUint8($byte);
        }
    }

    public function writeVarString(string $value): void
    {
        $bytes = $value;
        $this->writeVarUint(strlen($bytes));
        $this->writeBytes($bytes);
    }

    public function writeVarUint8Array(string $bytes): void
    {
        $this->writeVarUint(strlen($bytes));
        $this->writeBytes($bytes);
    }

    public function writeAny(mixed $value): void
    {
        if ($value instanceof UndefinedValue) {
            $this->writeUint8(127);
            return;
        }

        if (is_string($value)) {
            $this->writeUint8(119);
            $this->writeVarString($value);
            return;
        }

        if (is_int($value) && abs($value) <= self::BITS31) {
            $this->writeUint8(125);
            $this->writeVarInt($value);
            return;
        }

        if ((is_int($value) || is_float($value)) && $this->isIntegerNumber($value)) {
            $integer = (int) $value;

            if (abs($integer) <= self::BITS31) {
                $this->writeUint8(125);
                $this->writeVarInt($integer);
                return;
            }
        }

        if (is_int($value) || is_float($value)) {
            if ($this->isFloat32($value)) {
                $this->writeUint8(124);
                $this->writeBytes(pack('G', (float) $value));
                return;
            }

            $this->writeUint8(123);
            $this->writeBytes(pack('E', (float) $value));
            return;
        }

        if (is_bool($value)) {
            $this->writeUint8($value ? 120 : 121);
            return;
        }

        if ($value === null) {
            $this->writeUint8(126);
            return;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                $this->writeUint8(117);
                $this->writeVarUint(count($value));

                foreach ($value as $item) {
                    $this->writeAny($item);
                }
                return;
            }

            $this->writeUint8(118);
            $this->writeVarUint(count($value));

            foreach ($value as $key => $item) {
                $this->writeVarString((string) $key);
                $this->writeAny($item);
            }
            return;
        }

        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            $this->writeUint8(118);
            $this->writeVarUint(count($properties));

            foreach ($properties as $key => $item) {
                $this->writeVarString((string) $key);
                $this->writeAny($item);
            }
            return;
        }

        $this->writeUint8(127);
    }

    private function isIntegerNumber(int|float $value): bool
    {
        if (!is_finite((float) $value)) {
            return false;
        }

        return floor((float) $value) === (float) $value;
    }

    private function isFloat32(int|float $value): bool
    {
        $float = (float) $value;

        if (is_nan($float)) {
            return false;
        }

        $decoded = unpack('G', pack('G', $float));

        if ($decoded === false) {
            throw new \UnexpectedValueException('Failed to test float32 encoding.');
        }

        return $decoded[1] === $float;
    }

    public function writeBytes(string $bytes): void
    {
        $this->buffer .= $bytes;
    }

    public function toString(): string
    {
        return $this->buffer;
    }
}
