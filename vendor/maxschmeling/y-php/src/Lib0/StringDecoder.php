<?php

declare(strict_types=1);

namespace Yjs\Lib0;

final class StringDecoder
{
    private readonly UintOptRleDecoder $lengthDecoder;
    private string $string;
    private int $position = 0;

    public function __construct(string $bytes)
    {
        $decoder = new Decoding($bytes);
        $this->lengthDecoder = new UintOptRleDecoder($decoder);
        $this->string = $decoder->readVarString();
    }

    public function read(): string
    {
        $length = $this->lengthDecoder->read();
        $result = $this->sliceUtf16CodeUnits($this->string, $this->position, $length);
        $this->position += $length;

        return $result;
    }

    private function sliceUtf16CodeUnits(string $value, int $start, int $length): string
    {
        if ($length === 0 || $value === '') {
            return '';
        }

        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            throw new \UnexpectedValueException('Failed to split UTF-8 string.');
        }

        $result = '';
        $position = 0;
        $end = $start + $length;

        foreach ($characters as $character) {
            $width = $this->utf16CodeUnitLength($character);
            $characterStart = $position;
            $characterEnd = $position + $width;

            if ($characterEnd > $start && $characterStart < $end) {
                $result .= $character;
            }

            $position = $characterEnd;

            if ($position >= $end) {
                break;
            }
        }

        return $result;
    }

    private function utf16CodeUnitLength(string $value): int
    {
        return intdiv(strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }
}
