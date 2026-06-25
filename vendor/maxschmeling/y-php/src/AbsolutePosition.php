<?php

declare(strict_types=1);

namespace Yjs;

final class AbsolutePosition
{
    /**
     * @param array{client: int, clock: int}|null $typeId
     */
    public function __construct(
        private readonly YText|YArray|YXmlFragment|YNestedText|YNestedArray|YNestedXmlFragment|YXmlText|YXmlElement $type,
        private readonly ?string $typeName,
        private readonly int $index,
        private readonly int $assoc = 0,
        private readonly ?array $typeId = null
    ) {
        if ($index < 0) {
            throw new \InvalidArgumentException('Absolute position index must be non-negative.');
        }
    }

    public function type(): YText|YArray|YXmlFragment|YNestedText|YNestedArray|YNestedXmlFragment|YXmlText|YXmlElement
    {
        return $this->type;
    }

    public function typeName(): ?string
    {
        return $this->typeName;
    }

    /**
     * @return array{client: int, clock: int}|null
     */
    public function typeId(): ?array
    {
        return $this->typeId;
    }

    public function index(): int
    {
        return $this->index;
    }

    public function assoc(): int
    {
        return $this->assoc;
    }

    /**
     * @return array{typeName?: string, typeId?: array{client: int, clock: int}, index: int, assoc: int}
     */
    public function toArray(): array
    {
        $position = [
            'index' => $this->index,
            'assoc' => $this->assoc,
        ];

        if ($this->typeName !== null) {
            $position = ['typeName' => $this->typeName] + $position;
        }

        if ($this->typeId !== null) {
            $position = ['typeId' => $this->typeId] + $position;
        }

        return $position;
    }
}
