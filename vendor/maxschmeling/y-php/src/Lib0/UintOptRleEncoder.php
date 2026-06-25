<?php

declare(strict_types=1);

namespace Yjs\Lib0;

final class UintOptRleEncoder
{
    private Encoding $encoder;
    private int $state = 0;
    private int $count = 0;

    public function __construct()
    {
        $this->encoder = new Encoding();
    }

    public function write(int $value): void
    {
        if ($this->state === $value) {
            $this->count++;
            return;
        }

        $this->flush();
        $this->count = 1;
        $this->state = $value;
    }

    public function toString(): string
    {
        $this->flush();

        return $this->encoder->toString();
    }

    private function flush(): void
    {
        if ($this->count === 0) {
            return;
        }

        $this->encoder->writeVarInt($this->count === 1 ? $this->state : -$this->state);

        if ($this->count > 1) {
            $this->encoder->writeVarUint($this->count - 2);
        }
    }
}
