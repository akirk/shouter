<?php

declare(strict_types=1);

namespace Yjs\Update;

use Yjs\Lib0\Decoding;
use Yjs\Lib0\IntDiffOptRleDecoder;
use Yjs\Lib0\RleDecoder;
use Yjs\Lib0\StringDecoder;
use Yjs\Lib0\UintOptRleDecoder;

final class UpdateDecoder
{
    private int $deleteSetCurrentValue = 0;
    /** @var list<string> */
    private array $keys = [];
    private ?IntDiffOptRleDecoder $keyClockDecoder = null;
    private ?UintOptRleDecoder $clientDecoder = null;
    private ?IntDiffOptRleDecoder $leftClockDecoder = null;
    private ?IntDiffOptRleDecoder $rightClockDecoder = null;
    private ?RleDecoder $infoDecoder = null;
    private ?StringDecoder $stringDecoder = null;
    private ?RleDecoder $parentInfoDecoder = null;
    private ?UintOptRleDecoder $typeRefDecoder = null;
    private ?UintOptRleDecoder $lengthDecoder = null;

    private function __construct(public readonly Decoding $restDecoder, private readonly int $version)
    {
    }

    public static function v1(string $update): self
    {
        return new self(new Decoding($update), 1);
    }

    public static function v2(string $update): self
    {
        $decoder = new Decoding($update);
        $decoder->readVarUint();
        $self = new self($decoder, 2);
        $self->keyClockDecoder = new IntDiffOptRleDecoder(new Decoding($decoder->readVarUint8Array()));
        $self->clientDecoder = new UintOptRleDecoder(new Decoding($decoder->readVarUint8Array()));
        $self->leftClockDecoder = new IntDiffOptRleDecoder(new Decoding($decoder->readVarUint8Array()));
        $self->rightClockDecoder = new IntDiffOptRleDecoder(new Decoding($decoder->readVarUint8Array()));
        $self->infoDecoder = new RleDecoder(new Decoding($decoder->readVarUint8Array()), static fn (Decoding $d): int => $d->readUint8());
        $self->stringDecoder = new StringDecoder($decoder->readVarUint8Array());
        $self->parentInfoDecoder = new RleDecoder(new Decoding($decoder->readVarUint8Array()), static fn (Decoding $d): int => $d->readUint8());
        $self->typeRefDecoder = new UintOptRleDecoder(new Decoding($decoder->readVarUint8Array()));
        $self->lengthDecoder = new UintOptRleDecoder(new Decoding($decoder->readVarUint8Array()));

        return $self;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function readClient(): int
    {
        return $this->version === 1 ? $this->restDecoder->readVarUint() : $this->requireDecoder($this->clientDecoder)->read();
    }

    /**
     * @return array{client: int, clock: int}
     */
    public function readLeftId(): array
    {
        if ($this->version === 1) {
            return ['client' => $this->restDecoder->readVarUint(), 'clock' => $this->restDecoder->readVarUint()];
        }

        return [
            'client' => $this->requireDecoder($this->clientDecoder)->read(),
            'clock' => $this->requireDecoder($this->leftClockDecoder)->read(),
        ];
    }

    /**
     * @return array{client: int, clock: int}
     */
    public function readRightId(): array
    {
        if ($this->version === 1) {
            return ['client' => $this->restDecoder->readVarUint(), 'clock' => $this->restDecoder->readVarUint()];
        }

        return [
            'client' => $this->requireDecoder($this->clientDecoder)->read(),
            'clock' => $this->requireDecoder($this->rightClockDecoder)->read(),
        ];
    }

    public function readInfo(): int
    {
        return $this->version === 1 ? $this->restDecoder->readUint8() : $this->requireDecoder($this->infoDecoder)->read();
    }

    public function readString(): string
    {
        return $this->version === 1 ? $this->restDecoder->readVarString() : $this->requireDecoder($this->stringDecoder)->read();
    }

    public function readParentInfo(): bool
    {
        return $this->version === 1 ? $this->restDecoder->readVarUint() === 1 : $this->requireDecoder($this->parentInfoDecoder)->read() === 1;
    }

    public function readTypeRef(): int
    {
        return $this->version === 1 ? $this->restDecoder->readVarUint() : $this->requireDecoder($this->typeRefDecoder)->read();
    }

    public function readLength(): int
    {
        return $this->version === 1 ? $this->restDecoder->readVarUint() : $this->requireDecoder($this->lengthDecoder)->read();
    }

    public function readAny(): mixed
    {
        return $this->restDecoder->readAny();
    }

    public function readBuffer(): string
    {
        return $this->restDecoder->readVarUint8Array();
    }

    public function readJson(): mixed
    {
        if ($this->version !== 1) {
            return $this->restDecoder->readAny();
        }

        try {
            return json_decode($this->restDecoder->readVarString(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \UnexpectedValueException('Unable to decode JSON value.', 0, $exception);
        }
    }

    public function readKey(): string
    {
        if ($this->version === 1) {
            return $this->restDecoder->readVarString();
        }

        $keyClock = $this->requireDecoder($this->keyClockDecoder)->read();
        if ($keyClock < count($this->keys)) {
            return $this->keys[$keyClock];
        }

        $key = $this->readString();
        $this->keys[] = $key;

        return $key;
    }

    public function resetDeleteSetCurrentValue(): void
    {
        $this->deleteSetCurrentValue = 0;
    }

    public function readDeleteSetClock(): int
    {
        if ($this->version === 1) {
            return $this->restDecoder->readVarUint();
        }

        $this->deleteSetCurrentValue += $this->restDecoder->readVarUint();

        return $this->deleteSetCurrentValue;
    }

    public function readDeleteSetLength(): int
    {
        if ($this->version === 1) {
            return $this->restDecoder->readVarUint();
        }

        $diff = $this->restDecoder->readVarUint() + 1;
        $this->deleteSetCurrentValue += $diff;

        return $diff;
    }

    /**
     * @template T
     * @param T|null $decoder
     * @return T
     */
    private function requireDecoder(mixed $decoder): mixed
    {
        if ($decoder === null) {
            throw new \LogicException('Decoder stream is not initialized.');
        }

        return $decoder;
    }
}
