<?php

declare(strict_types=1);

namespace Yjs\Update;

use Yjs\Lib0\Encoding;
use Yjs\Lib0\IntDiffOptRleEncoder;
use Yjs\Lib0\RleEncoder;
use Yjs\Lib0\StringEncoder;
use Yjs\Lib0\UintOptRleEncoder;

final class UpdateEncoderV2
{
    public readonly Encoding $restEncoder;
    private IntDiffOptRleEncoder $keyClockEncoder;
    private UintOptRleEncoder $clientEncoder;
    private IntDiffOptRleEncoder $leftClockEncoder;
    private IntDiffOptRleEncoder $rightClockEncoder;
    private RleEncoder $infoEncoder;
    private StringEncoder $stringEncoder;
    private RleEncoder $parentInfoEncoder;
    private UintOptRleEncoder $typeRefEncoder;
    private UintOptRleEncoder $lengthEncoder;
    /** @var array<string, int> */
    private array $keyMap = [];
    private int $keyClock = 0;
    private int $deleteSetCurrentValue = 0;

    public function __construct()
    {
        $this->restEncoder = new Encoding();
        $this->keyClockEncoder = new IntDiffOptRleEncoder();
        $this->clientEncoder = new UintOptRleEncoder();
        $this->leftClockEncoder = new IntDiffOptRleEncoder();
        $this->rightClockEncoder = new IntDiffOptRleEncoder();
        $this->infoEncoder = new RleEncoder(static function (Encoding $encoder, int $value): void {
            $encoder->writeUint8($value);
        });
        $this->stringEncoder = new StringEncoder();
        $this->parentInfoEncoder = new RleEncoder(static function (Encoding $encoder, int $value): void {
            $encoder->writeUint8($value);
        });
        $this->typeRefEncoder = new UintOptRleEncoder();
        $this->lengthEncoder = new UintOptRleEncoder();
    }

    /**
     * @param array{client: int, clock: int} $id
     */
    public function writeLeftId(array $id): void
    {
        $this->clientEncoder->write($id['client']);
        $this->leftClockEncoder->write($id['clock']);
    }

    /**
     * @param array{client: int, clock: int} $id
     */
    public function writeRightId(array $id): void
    {
        $this->clientEncoder->write($id['client']);
        $this->rightClockEncoder->write($id['clock']);
    }

    public function writeClient(int $client): void
    {
        $this->clientEncoder->write($client);
    }

    public function writeInfo(int $info): void
    {
        $this->infoEncoder->write($info);
    }

    public function writeString(string $value): void
    {
        $this->stringEncoder->write($value);
    }

    public function writeParentInfo(bool $isYKey): void
    {
        $this->parentInfoEncoder->write($isYKey ? 1 : 0);
    }

    public function writeTypeRef(int $typeRef): void
    {
        $this->typeRefEncoder->write($typeRef);
    }

    public function writeLength(int $length): void
    {
        $this->lengthEncoder->write($length);
    }

    public function writeAny(mixed $value): void
    {
        $this->restEncoder->writeAny($value);
    }

    public function writeBuffer(string $bytes): void
    {
        $this->restEncoder->writeVarUint8Array($bytes);
    }

    public function writeJson(mixed $value): void
    {
        $this->restEncoder->writeAny($value);
    }

    public function writeKey(string $key): void
    {
        $clock = $this->keyMap[$key] ?? null;

        if ($clock === null) {
            $this->keyClockEncoder->write($this->keyClock++);
            $this->stringEncoder->write($key);
            return;
        }

        $this->keyClockEncoder->write($clock);
    }

    public function resetDeleteSetCurrentValue(): void
    {
        $this->deleteSetCurrentValue = 0;
    }

    public function writeDeleteSetClock(int $clock): void
    {
        $diff = $clock - $this->deleteSetCurrentValue;
        $this->deleteSetCurrentValue = $clock;
        $this->restEncoder->writeVarUint($diff);
    }

    public function writeDeleteSetLength(int $length): void
    {
        if ($length <= 0) {
            throw new \InvalidArgumentException('Delete-set length must be positive.');
        }

        $this->restEncoder->writeVarUint($length - 1);
        $this->deleteSetCurrentValue += $length;
    }

    public function toString(): string
    {
        $encoder = new Encoding();
        $encoder->writeVarUint(0);
        $encoder->writeVarUint8Array($this->keyClockEncoder->toString());
        $encoder->writeVarUint8Array($this->clientEncoder->toString());
        $encoder->writeVarUint8Array($this->leftClockEncoder->toString());
        $encoder->writeVarUint8Array($this->rightClockEncoder->toString());
        $encoder->writeVarUint8Array($this->infoEncoder->toString());
        $encoder->writeVarUint8Array($this->stringEncoder->toString());
        $encoder->writeVarUint8Array($this->parentInfoEncoder->toString());
        $encoder->writeVarUint8Array($this->typeRefEncoder->toString());
        $encoder->writeVarUint8Array($this->lengthEncoder->toString());
        $encoder->writeBytes($this->restEncoder->toString());

        return $encoder->toString();
    }
}
