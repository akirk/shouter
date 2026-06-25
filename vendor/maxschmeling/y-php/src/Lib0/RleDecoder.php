<?php

declare(strict_types=1);

namespace Yjs\Lib0;

final class RleDecoder
{
    private mixed $state = null;
    private int $count = 0;

    /**
     * @param callable(Decoding): mixed $reader
     */
    public function __construct(private readonly Decoding $decoder, private readonly mixed $reader)
    {
    }

    public function read(): mixed
    {
        if ($this->count === 0) {
            $this->state = ($this->reader)($this->decoder);
            $this->count = $this->decoder->hasContent() ? $this->decoder->readVarUint() + 1 : -1;
        }

        $this->count--;

        return $this->state;
    }
}

