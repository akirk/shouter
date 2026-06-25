<?php

declare(strict_types=1);

namespace Yjs\Tests\Compatibility;

use PHPUnit\Framework\TestCase;
use Yjs\Lib0\Decoding;
use Yjs\Lib0\Encoding;
use Yjs\UndefinedValue;

final class YjsLib0FixtureTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__ . '/../../fixtures/generated/yjs-13.6.31/lib0-encoding.json';

    public function testVarUintEncodingMatchesYjsFixtures(): void
    {
        $fixtures = $this->loadFixtures();

        foreach ($fixtures['varUint'] as $fixture) {
            $encoder = new Encoding();
            $encoder->writeVarUint($fixture['value']);

            self::assertSame(base64_decode($fixture['base64'], true), $encoder->toString());

            $decoder = new Decoding($encoder->toString());
            self::assertSame($fixture['value'], $decoder->readVarUint());
        }
    }

    public function testVarStringEncodingMatchesYjsFixtures(): void
    {
        $fixtures = $this->loadFixtures();

        foreach ($fixtures['varString'] as $fixture) {
            $encoder = new Encoding();
            $encoder->writeVarString($fixture['value']);

            self::assertSame(base64_decode($fixture['base64'], true), $encoder->toString());

            $decoder = new Decoding($encoder->toString());
            self::assertSame($fixture['value'], $decoder->readVarString());
        }
    }

    public function testVarUint8ArrayEncodingMatchesYjsFixtures(): void
    {
        $fixtures = $this->loadFixtures();

        foreach ($fixtures['varUint8Array'] as $fixture) {
            $bytes = base64_decode($fixture['value'], true);
            self::assertIsString($bytes);

            $encoder = new Encoding();
            $encoder->writeVarUint8Array($bytes);

            self::assertSame(base64_decode($fixture['base64'], true), $encoder->toString());

            $decoder = new Decoding($encoder->toString());
            self::assertSame($bytes, $decoder->readVarUint8Array());
        }
    }

    public function testAnyEncodingMatchesYjsFixtures(): void
    {
        $fixtures = $this->loadFixtures();

        foreach ($fixtures['any'] as $fixture) {
            if ($this->containsDecodeOnlyAnyValue($fixture['value'])) {
                continue;
            }

            $encoder = new Encoding();
            $encoder->writeAny($this->fixtureAnyValueToPhp($fixture['value']));

            self::assertSame(base64_decode($fixture['base64'], true), $encoder->toString());
        }
    }

    public function testAnyDecodingMatchesYjsFixtures(): void
    {
        $fixtures = $this->loadFixtures();

        foreach ($fixtures['any'] as $fixture) {
            $bytes = base64_decode($fixture['base64'], true);
            self::assertIsString($bytes);

            $decoder = new Decoding($bytes);
            $decoded = $decoder->readAny();

            self::assertEquals($fixture['value'], $this->normalizeDecodedAny($decoded, $fixture['value']));
            self::assertFalse($decoder->hasContent());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFixtures(): array
    {
        self::assertFileExists(self::FIXTURE_PATH, 'Run `npm run fixtures` before PHPUnit.');

        $decoded = json_decode((string) file_get_contents(self::FIXTURE_PATH), true);

        self::assertIsArray($decoded);

        return $decoded;
    }

    private function normalizeDecodedAny(mixed $decoded, mixed $expected): mixed
    {
        if (is_array($expected) && ($expected['type'] ?? null) === 'Undefined') {
            self::assertInstanceOf(UndefinedValue::class, $decoded);

            return [
                'type' => 'Undefined',
            ];
        }

        if (is_array($expected) && ($expected['type'] ?? null) === 'Uint8Array') {
            self::assertIsString($decoded);

            return [
                'type' => 'Uint8Array',
                'base64' => base64_encode($decoded),
            ];
        }

        if (is_array($expected) && ($expected['type'] ?? null) === 'BigInt') {
            self::assertIsInt($decoded);

            return [
                'type' => 'BigInt',
                'value' => (string) $decoded,
            ];
        }

        if (is_array($expected) && ($expected['type'] ?? null) === 'Number') {
            self::assertIsFloat($decoded);

            if (is_nan($decoded)) {
                return [
                    'type' => 'Number',
                    'value' => 'NaN',
                ];
            }

            return [
                'type' => 'Number',
                'value' => $decoded === INF ? 'Infinity' : '-Infinity',
            ];
        }

        if (is_array($decoded)) {
            $normalized = [];

            foreach ($decoded as $key => $value) {
                $normalized[$key] = $this->normalizeDecodedAny($value, is_array($expected) && array_key_exists($key, $expected) ? $expected[$key] : null);
            }

            return $normalized;
        }

        return $decoded;
    }

    private function containsDecodeOnlyAnyValue(mixed $value): bool
    {
        if (is_array($value) && in_array($value['type'] ?? null, ['BigInt', 'Uint8Array'], true)) {
            return true;
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $nested) {
            if ($this->containsDecodeOnlyAnyValue($nested)) {
                return true;
            }
        }

        return false;
    }

    private function fixtureAnyValueToPhp(mixed $value): mixed
    {
        if (is_array($value) && ($value['type'] ?? null) === 'Undefined') {
            return UndefinedValue::instance();
        }

        if (is_array($value) && ($value['type'] ?? null) === 'Number') {
            return match ($value['value']) {
                'NaN' => NAN,
                'Infinity' => INF,
                '-Infinity' => -INF,
                default => throw new \UnexpectedValueException(sprintf('Unknown number marker "%s".', (string) $value['value'])),
            };
        }

        if (! is_array($value)) {
            return $value;
        }

        $converted = [];
        foreach ($value as $key => $nested) {
            $converted[$key] = $this->fixtureAnyValueToPhp($nested);
        }

        return $converted;
    }
}
