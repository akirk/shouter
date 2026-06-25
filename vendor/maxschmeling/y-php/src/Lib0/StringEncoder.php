<?php

declare(strict_types=1);

namespace Yjs\Lib0;

final class StringEncoder
{
    /** @var list<string> */
    private array $chunks = [];
    private string $current = '';
    private UintOptRleEncoder $lengthEncoder;

    public function __construct()
    {
        $this->lengthEncoder = new UintOptRleEncoder();
    }

    public function write(string $value): void
    {
        $this->current .= $value;

        if (self::utf16CodeUnitLength($this->current) > 19) {
            $this->chunks[] = $this->current;
            $this->current = '';
        }

        $this->lengthEncoder->write(self::utf16CodeUnitLength($value));
    }

    public function toString(): string
    {
        $this->chunks[] = $this->current;
        $this->current = '';

        $encoder = new Encoding();
        $encoder->writeVarString(implode('', $this->chunks));
        $encoder->writeBytes($this->lengthEncoder->toString());

        return $encoder->toString();
    }

    private static function utf16CodeUnitLength(string $value): int
    {
        if ($value === '') {
            return 0;
        }

        return intdiv(strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }
}
