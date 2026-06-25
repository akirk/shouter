<?php

declare(strict_types=1);

namespace Yjs;

final class YSubdoc implements \JsonSerializable
{
    private bool $loaded;

    /**
     * @param array<string, mixed> $opts
     */
    public function __construct(private readonly string $guid, private readonly array $opts = [], private readonly ?YDoc $parent = null)
    {
        $this->loaded = (bool) (($this->opts['shouldLoad'] ?? false) || ($this->opts['autoLoad'] ?? false));
    }

    public function guid(): string
    {
        return $this->guid;
    }

    /**
     * @return array<string, mixed>
     */
    public function opts(): array
    {
        return $this->opts;
    }

    public function meta(): mixed
    {
        return $this->opts['meta'] ?? null;
    }

    public function shouldLoad(): bool
    {
        return $this->loaded;
    }

    public function load(): void
    {
        if ($this->loaded) {
            return;
        }

        if ($this->parent === null) {
            $this->loaded = true;
            return;
        }

        $this->parent->loadSubdoc($this);
    }

    public function markLoaded(): void
    {
        $this->loaded = true;
    }

    /**
     * Yjs renders subdocs as empty JSON objects from parent shared types.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [];
    }
}
