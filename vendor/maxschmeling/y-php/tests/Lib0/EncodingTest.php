<?php

declare(strict_types=1);

namespace Yjs\Tests\Lib0;

use PHPUnit\Framework\TestCase;
use Yjs\Lib0\Decoding;
use Yjs\Lib0\Encoding;
use Yjs\Lib0\IntDiffOptRleDecoder;
use Yjs\Lib0\IntDiffOptRleEncoder;

final class EncodingTest extends TestCase
{
    public function testVarUintRoundTrips(): void
    {
        foreach ([0, 1, 42, 127, 128, 129, 255, 256, 16383, 16384, 65535, 65536, 2147483647] as $value) {
            $encoder = new Encoding();
            $encoder->writeVarUint($value);

            $decoder = new Decoding($encoder->toString());

            self::assertSame($value, $decoder->readVarUint());
            self::assertFalse($decoder->hasContent());
        }
    }

    public function testVarStringRoundTrips(): void
    {
        foreach (['', 'a', 'hello', 'collaboration', "multi\nline"] as $value) {
            $encoder = new Encoding();
            $encoder->writeVarString($value);

            $decoder = new Decoding($encoder->toString());

            self::assertSame($value, $decoder->readVarString());
            self::assertFalse($decoder->hasContent());
        }
    }

    public function testIntDiffOptRleRoundTripsNegativeOddDiffs(): void
    {
        $values = [0, 1, 2, 0, 1, 3, 2, 1, 4, 3, 2, 2, 2, 1];
        $encoder = new IntDiffOptRleEncoder();

        foreach ($values as $value) {
            $encoder->write($value);
        }

        $decoder = new IntDiffOptRleDecoder(new Decoding($encoder->toString()));
        $decoded = [];
        foreach ($values as $_) {
            $decoded[] = $decoder->read();
        }

        self::assertSame($values, $decoded);
    }
}
