<?php

declare(strict_types=1);

namespace Yjs\Lib0;

final class IntDiffOptRleDecoder
{
    private int $state = 0;
    private int $count = 0;
    private int $diff = 0;

    public function __construct(private readonly Decoding $decoder)
    {
    }

    public function read(): int
    {
        if ($this->count === 0) {
            $diff = $this->decoder->readVarInt();
            $hasCount = ($diff & 1) !== 0;
            $this->diff = (int) floor($diff / 2);
            $this->count = $hasCount ? $this->decoder->readVarUint() + 2 : 1;
        }

        $this->state += $this->diff;
        $this->count--;

        return $this->state;
    }
}
