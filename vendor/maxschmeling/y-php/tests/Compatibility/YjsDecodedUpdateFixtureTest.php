<?php

declare(strict_types=1);

namespace Yjs\Tests\Compatibility;

use PHPUnit\Framework\TestCase;
use Yjs\UndefinedValue;
use Yjs\Update\DecodedUpdate;
use Yjs\Update\UpdateMetadata;

final class YjsDecodedUpdateFixtureTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__ . '/../../fixtures/generated/yjs-13.6.31/updates-v1.json';

    public function testDecodeV1UpdateSummariesMatchYjsFixtures(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $update = base64_decode($case['updateV1'], true);

            self::assertIsString($update);
            self::assertSame(
                $this->normalizeDecodedUpdate($case['decoded']),
                DecodedUpdate::decodeV1($update),
                sprintf('Failed decoding case "%s".', $case['name'])
            );
        }
    }

    public function testParseV1MetadataMatchesAllYjsUpdateFixtures(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $update = base64_decode($case['updateV1'], true);

            self::assertIsString($update);
            self::assertSame(
                $this->normalizeIntegerMapFixture($case['meta']),
                UpdateMetadata::parseV1($update),
                sprintf('Failed parsing metadata for case "%s".', $case['name'])
            );
        }
    }

    public function testParseV2MetadataMatchesAllYjsUpdateFixtures(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $update = base64_decode($case['updateV2'], true);

            self::assertIsString($update);
            self::assertSame(
                $this->normalizeIntegerMapFixture($case['metaV2']),
                UpdateMetadata::parseV2($update),
                sprintf('Failed parsing V2 metadata for case "%s".', $case['name'])
            );
        }
    }

    public function testDecodeV2UpdateSummariesMatchYjsFixtures(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $update = base64_decode($case['updateV2'], true);

            self::assertIsString($update);
            self::assertSame(
                $this->normalizeDecodedUpdate($case['decoded']),
                DecodedUpdate::decodeV2($update),
                sprintf('Failed decoding V2 case "%s".', $case['name'])
            );
        }
    }

    public function testDecodeV1DecodedOnlyUpdateSummariesMatchYjsFixtures(): void
    {
        foreach ($this->loadFixtures()['decodedOnlyCases'] as $case) {
            $update = base64_decode($case['updateV1'], true);

            self::assertIsString($update);
            self::assertSame(
                $this->normalizeDecodedUpdate($case['decodedV1']),
                $this->normalizeSpecialNumberValues(DecodedUpdate::decodeV1($update)),
                sprintf('Failed decoding decoded-only case "%s".', $case['name'])
            );
        }
    }

    public function testDecodeV2DecodedOnlyUpdateSummariesMatchYjsFixtures(): void
    {
        foreach ($this->loadFixtures()['decodedOnlyCases'] as $case) {
            $update = base64_decode($case['updateV2'], true);

            self::assertIsString($update);
            self::assertSame(
                $this->normalizeDecodedUpdate($case['decodedV2']),
                $this->normalizeSpecialNumberValues(DecodedUpdate::decodeV2($update)),
                sprintf('Failed decoding decoded-only V2 case "%s".', $case['name'])
            );
        }
    }

    public function testEncodeContentJsonUsesYjsCompatibleUtf8Json(): void
    {
        $decoded = $this->contentJsonDecodedUpdate(['Zoë/😀']);

        $update = DecodedUpdate::encodeV1($decoded['structs'], $decoded['deleteSet']);

        self::assertStringContainsString('"Zoë/😀"', $update);
        self::assertSame($decoded, DecodedUpdate::decodeV1($update));
    }

    public function testDecodeContentJsonRejectsInvalidJson(): void
    {
        $decoded = $this->contentJsonDecodedUpdate(['ok']);
        $update = DecodedUpdate::encodeV1($decoded['structs'], $decoded['deleteSet']);
        $corrupted = str_replace('"ok"', '{"x"', $update);

        self::assertNotSame($update, $corrupted);
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Unable to decode ContentJSON value.');

        DecodedUpdate::decodeV1($corrupted);
    }

    public function testEncodeEmbedAndFormatUseYjsCompatibleUtf8Json(): void
    {
        $decoded = [
            'structs' => [
                $this->decodedItem(77, 0, 'content', [
                    'type' => 'ContentEmbed',
                    'value' => ['image' => 'Zoë/😀'],
                ]),
                $this->decodedItem(77, 1, null, [
                    'type' => 'ContentFormat',
                    'key' => 'mark',
                    'value' => ['href' => 'https://example.com/Zoë'],
                ], ['client' => 77, 'clock' => 0]),
            ],
            'deleteSet' => [],
        ];

        $update = DecodedUpdate::encodeV1($decoded['structs'], $decoded['deleteSet']);

        self::assertStringContainsString('{"image":"Zoë/😀"}', $update);
        self::assertStringContainsString('{"href":"https://example.com/Zoë"}', $update);
        self::assertSame($decoded, DecodedUpdate::decodeV1($update));
    }

    public function testDecodeContentEmbedRejectsInvalidJson(): void
    {
        $decoded = [
            'structs' => [
                $this->decodedItem(77, 0, 'content', [
                    'type' => 'ContentEmbed',
                    'value' => 'ok',
                ]),
            ],
            'deleteSet' => [],
        ];
        $update = DecodedUpdate::encodeV1($decoded['structs'], $decoded['deleteSet']);
        $corrupted = str_replace('"ok"', '{"x"', $update);

        self::assertNotSame($update, $corrupted);
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Unable to decode JSON value.');

        DecodedUpdate::decodeV1($corrupted);
    }

    /**
     * @return array{cases: list<array<string, mixed>>}
     */
    private function loadFixtures(): array
    {
        self::assertFileExists(self::FIXTURE_PATH, 'Run `npm run fixtures` before PHPUnit.');

        $decoded = json_decode((string) file_get_contents(self::FIXTURE_PATH), true);

        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array{structs: list<array<string, mixed>>, deleteSet: array<string, list<array{clock: int, length: int}>|null>} $decoded
     * @return array{
     *     structs: list<array<string, mixed>>,
     *     deleteSet: array<int, list<array{clock: int, length: int}>>
     * }
     */
    private function normalizeDecodedUpdate(array $decoded): array
    {
        return [
            'structs' => $decoded['structs'],
            'deleteSet' => $this->normalizeDeleteSetFixture($decoded['deleteSet']),
        ];
    }

    /**
     * @param array<string, list<array{clock: int, length: int}>|null> $fixture
     * @return array<int, list<array{clock: int, length: int}>>
     */
    private function normalizeDeleteSetFixture(array $fixture): array
    {
        $deleteSet = [];

        foreach ($fixture as $client => $deletes) {
            if ($deletes !== null) {
                $deleteSet[(int) $client] = $deletes;
            }
        }

        return $deleteSet;
    }

    /**
     * @param array{from: array<string, int>, to: array<string, int>} $fixture
     * @return array{from: array<int, int>, to: array<int, int>}
     */
    private function normalizeIntegerMapFixture(array $fixture): array
    {
        return [
            'from' => $this->normalizeIntegerMap($fixture['from']),
            'to' => $this->normalizeIntegerMap($fixture['to']),
        ];
    }

    /**
     * @param array<string, int> $map
     * @return array<int, int>
     */
    private function normalizeIntegerMap(array $map): array
    {
        $normalized = [];

        foreach ($map as $key => $value) {
            $normalized[(int) $key] = $value;
        }

        return $normalized;
    }

    private function normalizeSpecialNumberValues(mixed $value): mixed
    {
        if ($value instanceof UndefinedValue) {
            return [
                'type' => 'Undefined',
            ];
        }

        if (is_float($value) && ! is_finite($value)) {
            if (is_nan($value)) {
                return [
                    'type' => 'Number',
                    'value' => 'NaN',
                ];
            }

            return [
                'type' => 'Number',
                'value' => $value === INF ? 'Infinity' : '-Infinity',
            ];
        }

        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];
        foreach ($value as $key => $nested) {
            $normalized[$key] = $this->normalizeSpecialNumberValues($nested);
        }

        return $normalized;
    }

    /**
     * @param list<mixed> $values
     * @return array{
     *     structs: list<array<string, mixed>>,
     *     deleteSet: array<int, list<array{clock: int, length: int}>>
     * }
     */
    private function contentJsonDecodedUpdate(array $values): array
    {
        return [
            'structs' => [
                $this->decodedItem(77, 0, 'array', [
                    'type' => 'ContentJSON',
                    'values' => $values,
                ], length: count($values)),
            ],
            'deleteSet' => [],
        ];
    }

    /**
     * @param array<string, mixed> $content
     * @param array{client: int, clock: int}|null $origin
     * @return array<string, mixed>
     */
    private function decodedItem(int $client, int $clock, ?string $parent, array $content, ?array $origin = null, int $length = 1): array
    {
        return [
            'type' => 'Item',
            'id' => ['client' => $client, 'clock' => $clock],
            'length' => $length,
            'origin' => $origin,
            'rightOrigin' => null,
            'parent' => $parent,
            'parentSub' => null,
            'content' => $content,
        ];
    }
}
