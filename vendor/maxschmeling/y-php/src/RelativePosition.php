<?php

declare(strict_types=1);

namespace Yjs;

use Yjs\Lib0\Decoding;
use Yjs\Lib0\Encoding;

final class RelativePosition
{
    /**
     * @param array{client: int, clock: int}|null $type
     * @param array{client: int, clock: int}|null $item
     */
    public function __construct(
        private readonly ?array $type = null,
        private readonly ?string $typeName = null,
        private readonly ?array $item = null,
        private readonly int $assoc = 0
    ) {
        if ($type !== null) {
            self::assertId($type, 'Type');
        }

        if ($item !== null) {
            self::assertId($item, 'Item');
        }
    }

    /**
     * @param array{
     *     type?: array{client: int, clock: int}|null,
     *     tname?: string|null,
     *     item?: array{client: int, clock: int}|null,
     *     assoc?: int|null
     * } $json
     */
    public static function fromJSON(array $json): self
    {
        return new self(
            isset($json['type']) ? self::normalizeId($json['type'], 'Type') : null,
            isset($json['tname']) ? self::normalizeTypeName($json['tname']) : null,
            isset($json['item']) ? self::normalizeId($json['item'], 'Item') : null,
            isset($json['assoc']) ? self::normalizeAssoc($json['assoc']) : 0
        );
    }

    public static function decode(string $encoded): self
    {
        $decoder = new Decoding($encoded);
        $position = self::read($decoder);

        if ($decoder->hasContent()) {
            throw new \UnexpectedValueException('Relative position contains trailing bytes.');
        }

        return $position;
    }

    public static function compare(?self $left, ?self $right): bool
    {
        return $left === $right || ($left !== null && $left->equals($right));
    }

    public function encode(): string
    {
        $encoder = new Encoding();
        $this->write($encoder);

        return $encoder->toString();
    }

    public function equals(?self $other): bool
    {
        return $other !== null
            && $this->type === $other->type
            && $this->typeName === $other->typeName
            && $this->item === $other->item
            && $this->assoc === $other->assoc;
    }

    /**
     * @return array{
     *     type?: array{client: int, clock: int},
     *     tname?: string,
     *     item?: array{client: int, clock: int},
     *     assoc: int
     * }
     */
    public function toJSON(): array
    {
        $json = [];

        if ($this->type !== null) {
            $json['type'] = $this->type;
        }

        if ($this->typeName !== null) {
            $json['tname'] = $this->typeName;
        }

        if ($this->item !== null) {
            $json['item'] = $this->item;
        }

        $json['assoc'] = $this->assoc;

        return $json;
    }

    /**
     * @return array{client: int, clock: int}|null
     */
    public function type(): ?array
    {
        return $this->type;
    }

    public function typeName(): ?string
    {
        return $this->typeName;
    }

    public function tname(): ?string
    {
        return $this->typeName;
    }

    /**
     * @return array{client: int, clock: int}|null
     */
    public function item(): ?array
    {
        return $this->item;
    }

    public function assoc(): int
    {
        return $this->assoc;
    }

    private static function read(Decoding $decoder): self
    {
        $type = null;
        $typeName = null;
        $item = null;

        switch ($decoder->readVarUint()) {
            case 0:
                $item = self::readId($decoder);
                break;
            case 1:
                $typeName = $decoder->readVarString();
                break;
            case 2:
                $type = self::readId($decoder);
                break;
            default:
                throw new \UnexpectedValueException('Unsupported relative position reference type.');
        }

        return new self($type, $typeName, $item, $decoder->readVarInt());
    }

    private function write(Encoding $encoder): void
    {
        if ($this->item !== null) {
            $encoder->writeVarUint(0);
            self::writeId($encoder, $this->item);
        } elseif ($this->typeName !== null) {
            $encoder->writeUint8(1);
            $encoder->writeVarString($this->typeName);
        } elseif ($this->type !== null) {
            $encoder->writeUint8(2);
            self::writeId($encoder, $this->type);
        } else {
            throw new \UnexpectedValueException('Relative position must reference an item, type name, or type ID.');
        }

        $encoder->writeVarInt($this->assoc);
    }

    /**
     * @return array{client: int, clock: int}
     */
    private static function readId(Decoding $decoder): array
    {
        return [
            'client' => $decoder->readVarUint(),
            'clock' => $decoder->readVarUint(),
        ];
    }

    /**
     * @param array{client: int, clock: int} $id
     */
    private static function writeId(Encoding $encoder, array $id): void
    {
        $encoder->writeVarUint($id['client']);
        $encoder->writeVarUint($id['clock']);
    }

    /**
     * @param array{client: int, clock: int} $id
     */
    private static function assertId(array $id, string $label): void
    {
        if (! isset($id['client'], $id['clock']) || ! is_int($id['client']) || ! is_int($id['clock'])) {
            throw new \InvalidArgumentException($label . ' ID must contain integer client and clock values.');
        }

        if ($id['client'] < 0 || $id['clock'] < 0) {
            throw new \InvalidArgumentException($label . ' ID values must be non-negative.');
        }
    }

    /**
     * @return array{client: int, clock: int}
     */
    private static function normalizeId(mixed $id, string $label): array
    {
        if (! is_array($id)) {
            throw new \InvalidArgumentException($label . ' ID must be an array.');
        }

        self::assertId($id, $label);

        return [
            'client' => $id['client'],
            'clock' => $id['clock'],
        ];
    }

    private static function normalizeTypeName(mixed $typeName): string
    {
        if (! is_string($typeName)) {
            throw new \InvalidArgumentException('Relative position type name must be a string.');
        }

        return $typeName;
    }

    private static function normalizeAssoc(mixed $assoc): int
    {
        if (! is_int($assoc)) {
            throw new \InvalidArgumentException('Relative position assoc must be an integer.');
        }

        return $assoc;
    }
}
