<?php

declare(strict_types=1);

namespace Yjs\Lib0;

final class RleEncoder
{
    private Encoding $encoder;
    private mixed $state = null;
    private int $count = 0;

    /**
     * @param callable(Encoding, mixed): void $writer
     */
    public function __construct(private readonly mixed $writer)
    {
        $this->encoder = new Encoding();
    }

    public function write(mixed $value): void
    {
        if ($this->count > 0 && $this->state === $value) {
            $this->count++;
            return;
        }

        if ($this->count > 0) {
            $this->encoder->writeVarUint($this->count - 1);
        }

        $this->count = 1;
        ($this->writer)($this->encoder, $value);
        $this->state = $value;
    }

    public function toString(): string
    {
        return $this->encoder->toString();
    }
}
