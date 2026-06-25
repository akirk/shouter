<?php

declare(strict_types=1);

namespace Yjs\Lib0;

final class UintOptRleDecoder
{
    private int $state = 0;
    private int $count = 0;

    public function __construct(private readonly Decoding $decoder)
    {
    }

    public function read(): int
    {
        if ($this->count === 0) {
            $this->state = $this->decoder->readVarInt();
            $this->count = 1;

            if ($this->state < 0) {
                $this->state = -$this->state;
                $this->count = $this->decoder->readVarUint() + 2;
            }
        }

        $this->count--;

        return $this->state;
    }
}

