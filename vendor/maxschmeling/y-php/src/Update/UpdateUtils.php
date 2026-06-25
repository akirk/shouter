<?php

declare(strict_types=1);

namespace Yjs\Update;

use Yjs\StateVector;

final class UpdateUtils
{
    /**
     * @return array{
     *     structs: list<array<string, mixed>>,
     *     deleteSet: array<int, list<array{clock: int, length: int}>>
     * }
     */
    public static function decodeUpdateV1(string $update): array
    {
        return DecodedUpdate::decodeV1($update);
    }

    /**
     * @return array{
     *     structs: list<array<string, mixed>>,
     *     deleteSet: array<int, list<array{clock: int, length: int}>>
     * }
     */
    public static function decodeUpdate(string $update): array
    {
        return self::decodeUpdateV1($update);
    }

    /**
     * @return array{
     *     structs: list<array<string, mixed>>,
     *     deleteSet: array<int, list<array{clock: int, length: int}>>
     * }
     */
    public static function decodeUpdateV2(string $update): array
    {
        return DecodedUpdate::decodeV2($update);
    }

    /**
     * @param list<string> $updates
     */
    public static function mergeUpdatesV1(array $updates): string
    {
        $merged = self::mergeDecodedUpdates(array_map(
            static fn (string $update): array => DecodedUpdate::decodeV1($update),
            $updates
        ));

        return DecodedUpdate::encodeV1($merged['structs'], $merged['deleteSet']);
    }

    /**
     * @param list<string> $updates
     */
    public static function mergeUpdates(array $updates): string
    {
        return self::mergeUpdatesV1($updates);
    }

    /**
     * @param list<string> $updates
     */
    public static function mergeUpdatesV2(array $updates): string
    {
        $merged = self::mergeDecodedUpdates(array_map(
            static fn (string $update): array => DecodedUpdate::decodeV2($update),
            $updates
        ));

        return DecodedUpdate::encodeV2($merged['structs'], $merged['deleteSet']);
    }

    public static function diffUpdateV1(string $update, string $encodedTargetStateVector): string
    {
        $decoded = DecodedUpdate::decodeV1($update);

        return DecodedUpdate::encodeV1(
            $decoded['structs'],
            $decoded['deleteSet'],
            StateVector::decode($encodedTargetStateVector)
        );
    }

    public static function diffUpdate(string $update, string $encodedTargetStateVector): string
    {
        return self::diffUpdateV1($update, $encodedTargetStateVector);
    }

    public static function diffUpdateV2(string $update, string $encodedTargetStateVector): string
    {
        $decoded = DecodedUpdate::decodeV2($update);

        return DecodedUpdate::encodeV2(
            $decoded['structs'],
            $decoded['deleteSet'],
            StateVector::decode($encodedTargetStateVector)
        );
    }

    public static function encodeStateVectorFromUpdateV1(string $update): string
    {
        return StateVector::encode(self::stateVectorFromDecoded(DecodedUpdate::decodeV1($update)));
    }

    public static function encodeStateVectorFromUpdate(string $update): string
    {
        return self::encodeStateVectorFromUpdateV1($update);
    }

    public static function encodeStateVectorFromUpdateV2(string $update): string
    {
        return StateVector::encode(self::stateVectorFromDecoded(DecodedUpdate::decodeV2($update)));
    }

    /**
     * @return array{from: array<int, int>, to: array<int, int>}
     */
    public static function parseUpdateMetaV1(string $update): array
    {
        return UpdateMetadata::parseV1($update);
    }

    /**
     * @return array{from: array<int, int>, to: array<int, int>}
     */
    public static function parseUpdateMeta(string $update): array
    {
        return self::parseUpdateMetaV1($update);
    }

    /**
     * @return array{from: array<int, int>, to: array<int, int>}
     */
    public static function parseUpdateMetaV2(string $update): array
    {
        return UpdateMetadata::parseV2($update);
    }

    public static function convertUpdateFormatV1ToV2(string $update): string
    {
        $decoded = DecodedUpdate::decodeV1($update);

        return DecodedUpdate::encodeV2($decoded['structs'], $decoded['deleteSet']);
    }

    public static function convertUpdateFormatV2ToV1(string $update): string
    {
        $decoded = DecodedUpdate::decodeV2($update);

        return DecodedUpdate::encodeV1($decoded['structs'], $decoded['deleteSet']);
    }

    /**
     * @param list<array<int, list<array{clock: int, length: int}>>> $deleteSets
     * @return array<int, list<array{clock: int, length: int}>>
     */
    public static function mergeDeleteSets(array $deleteSets): array
    {
        $merged = [];

        foreach ($deleteSets as $deleteSet) {
            foreach ($deleteSet as $client => $deletes) {
                foreach ($deletes as $delete) {
                    $merged[(int) $client][] = $delete;
                }
            }
        }

        return self::normalizeDeleteSet($merged);
    }

    /**
     * @param array<int, list<array{clock: int, length: int}>> $left
     * @param array<int, list<array{clock: int, length: int}>> $right
     */
    public static function equalDeleteSets(array $left, array $right): bool
    {
        return self::normalizeDeleteSet($left) === self::normalizeDeleteSet($right);
    }

    /**
     * @param array{formatting?: bool, subdocs?: bool, yxml?: bool} $options
     */
    public static function obfuscateUpdateV1(string $update, array $options = []): string
    {
        $decoded = DecodedUpdate::decodeV1($update);
        $obfuscated = self::obfuscateDecodedUpdate($decoded, $options);

        return DecodedUpdate::encodeV1($obfuscated['structs'], $obfuscated['deleteSet']);
    }

    /**
     * @param array{formatting?: bool, subdocs?: bool, yxml?: bool} $options
     */
    public static function obfuscateUpdate(string $update, array $options = []): string
    {
        return self::obfuscateUpdateV1($update, $options);
    }

    /**
     * @param array{formatting?: bool, subdocs?: bool, yxml?: bool} $options
     */
    public static function obfuscateUpdateV2(string $update, array $options = []): string
    {
        $decoded = DecodedUpdate::decodeV2($update);
        $obfuscated = self::obfuscateDecodedUpdate($decoded, $options);

        return DecodedUpdate::encodeV2($obfuscated['structs'], $obfuscated['deleteSet']);
    }

    /**
     * @param list<array{
     *     structs: list<array<string, mixed>>,
     *     deleteSet: array<int, list<array{clock: int, length: int}>>
     * }> $updates
     * @return array{
     *     structs: list<array<string, mixed>>,
     *     deleteSet: array<int, list<array{clock: int, length: int}>>
     * }
     */
    private static function mergeDecodedUpdates(array $updates): array
    {
        $structs = [];
        $deleteSet = [];

        foreach ($updates as $update) {
            foreach ($update['structs'] as $struct) {
                $structs[] = $struct;
            }

            foreach ($update['deleteSet'] as $client => $deletes) {
                foreach ($deletes as $delete) {
                    $deleteSet[$client][] = $delete;
                }
            }
        }

        return [
            'structs' => self::normalizeStructRanges($structs),
            'deleteSet' => self::normalizeDeleteSet($deleteSet),
        ];
    }

    /**
     * @param array{
     *     structs: list<array<string, mixed>>,
     *     deleteSet: array<int, list<array{clock: int, length: int}>>
     * } $decoded
     * @param array{formatting?: bool, subdocs?: bool, yxml?: bool} $options
     * @return array{
     *     structs: list<array<string, mixed>>,
     *     deleteSet: array<int, list<array{clock: int, length: int}>>
     * }
     */
    private static function obfuscateDecodedUpdate(array $decoded, array $options): array
    {
        $formatting = $options['formatting'] ?? true;
        $subdocs = $options['subdocs'] ?? true;
        $yxml = $options['yxml'] ?? true;
        $index = 0;
        $mapKeyCache = [];
        $nodeNameCache = [];
        $formattingKeyCache = [];
        $formattingValueCache = [
            self::obfuscationCacheKey(null) => null,
        ];
        $structs = [];

        foreach ($decoded['structs'] as $struct) {
            if (($struct['type'] ?? null) !== 'Item') {
                $structs[] = $struct;
                continue;
            }

            $content = $struct['content'];
            switch ($content['type'] ?? null) {
                case 'ContentDeleted':
                    break;
                case 'ContentType':
                    if ($yxml && ($content['typeName'] ?? null) === 'YXmlElement' && isset($content['nodeName'])) {
                        $content['nodeName'] = self::cacheObfuscatedValue($nodeNameCache, (string) $content['nodeName'], 'node-' . $index);
                    }
                    if ($yxml && ($content['typeName'] ?? null) === 'YXmlHook' && isset($content['hookName'])) {
                        $content['hookName'] = self::cacheObfuscatedValue($nodeNameCache, (string) $content['hookName'], 'hook-' . $index);
                    }
                    break;
                case 'ContentAny':
                    $content['values'] = array_fill(0, count($content['values']), $index);
                    break;
                case 'ContentBinary':
                    $content['base64'] = base64_encode(chr($index & 0xff));
                    break;
                case 'ContentDoc':
                    if ($subdocs) {
                        $content['guid'] = (string) $index;
                        $content['opts'] = new \stdClass();
                    }
                    break;
                case 'ContentEmbed':
                    $content['value'] = new \stdClass();
                    break;
                case 'ContentFormat':
                    if ($formatting) {
                        $content['key'] = self::cacheObfuscatedValue($formattingKeyCache, (string) $content['key'], (string) $index);
                        $valueKey = self::obfuscationCacheKey($content['value'] ?? null);
                        if (! array_key_exists($valueKey, $formattingValueCache)) {
                            $formattingValueCache[$valueKey] = ['i' => $index];
                        }
                        $content['value'] = $formattingValueCache[$valueKey];
                    }
                    break;
                case 'ContentJSON':
                    $content['values'] = array_fill(0, count($content['values']), $index);
                    break;
                case 'ContentString':
                    $content['value'] = str_repeat((string) ($index % 10), self::utf16CodeUnitLength($content['value']));
                    break;
                default:
                    throw new \UnexpectedValueException(sprintf('Cannot obfuscate content type "%s".', (string) ($content['type'] ?? '')));
            }

            $struct['content'] = $content;
            if (($struct['parentSub'] ?? null) !== null && $struct['parentSub'] !== '') {
                $struct['parentSub'] = self::cacheObfuscatedValue($mapKeyCache, (string) $struct['parentSub'], (string) $index);
            }
            $structs[] = $struct;
            $index++;
        }

        return [
            'structs' => $structs,
            'deleteSet' => $decoded['deleteSet'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $structs
     * @return list<array<string, mixed>>
     */
    private static function normalizeStructRanges(array $structs): array
    {
        usort(
            $structs,
            static fn (array $left, array $right): int => [$left['id']['client'], $left['id']['clock'], $right['length']] <=> [$right['id']['client'], $right['id']['clock'], $left['length']]
        );

        $normalized = [];
        $coveredUntilByClient = [];

        foreach ($structs as $struct) {
            $client = $struct['id']['client'];
            $start = $struct['id']['clock'];
            $end = $start + $struct['length'];
            $coveredUntil = $coveredUntilByClient[$client] ?? 0;

            if ($end <= $coveredUntil) {
                continue;
            }

            if ($start < $coveredUntil) {
                $struct = self::sliceStruct($struct, $coveredUntil - $start);
                $start = $struct['id']['clock'];
                $end = $start + $struct['length'];
            }

            $normalized[] = $struct;
            $coveredUntilByClient[$client] = $end;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $struct
     * @return array<string, mixed>
     */
    private static function sliceStruct(array $struct, int $diff): array
    {
        if ($diff <= 0 || $diff >= $struct['length']) {
            throw new \InvalidArgumentException('Struct slice diff must be inside the struct.');
        }

        $sliced = $struct;
        $sliced['id'] = [
            'client' => $struct['id']['client'],
            'clock' => $struct['id']['clock'] + $diff,
        ];
        $sliced['length'] = $struct['length'] - $diff;

        if ($struct['type'] === 'Item') {
            $sliced['origin'] = [
                'client' => $struct['id']['client'],
                'clock' => $struct['id']['clock'] + $diff - 1,
            ];
            $sliced['content'] = self::sliceContent($struct['content'], $diff);
        }

        return $sliced;
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    private static function sliceContent(array $content, int $diff): array
    {
        $sliced = $content;

        switch ($content['type']) {
            case 'ContentString':
                $sliced['value'] = self::sliceUtf16CodeUnits($content['value'], $diff);
                break;
            case 'ContentAny':
            case 'ContentJSON':
                $sliced['values'] = array_slice($content['values'], $diff);
                break;
            case 'ContentDeleted':
                $sliced['length'] = $content['length'] - $diff;
                break;
            default:
                throw new \UnexpectedValueException(sprintf('Cannot slice content type "%s".', $content['type']));
        }

        return $sliced;
    }

    /**
     * @param array{
     *     structs: list<array<string, mixed>>,
     *     deleteSet: array<int, list<array{clock: int, length: int}>>
     * } $decoded
     * @return array<int, int>
     */
    private static function stateVectorFromDecoded(array $decoded): array
    {
        $rangesByClient = [];

        foreach ($decoded['structs'] as $struct) {
            $client = $struct['id']['client'];
            $rangesByClient[$client][] = [
                'clock' => $struct['id']['clock'],
                'length' => $struct['length'],
            ];
        }

        $stateVector = [];
        foreach ($rangesByClient as $client => $ranges) {
            usort($ranges, static fn (array $left, array $right): int => $left['clock'] <=> $right['clock']);

            $clock = 0;
            foreach ($ranges as $range) {
                $start = $range['clock'];
                $end = $range['clock'] + $range['length'];

                if ($start > $clock) {
                    break;
                }

                $clock = max($clock, $end);
            }

            if ($clock > 0) {
                $stateVector[$client] = $clock;
            }
        }

        return $stateVector;
    }

    /**
     * @param array<int, list<array{clock: int, length: int}>> $deleteSet
     * @return array<int, list<array{clock: int, length: int}>>
     */
    private static function normalizeDeleteSet(array $deleteSet): array
    {
        krsort($deleteSet, SORT_NUMERIC);
        $normalized = [];

        foreach ($deleteSet as $client => $deletes) {
            usort($deletes, static fn (array $left, array $right): int => $left['clock'] <=> $right['clock']);

            foreach ($deletes as $delete) {
                if ($delete['length'] <= 0) {
                    continue;
                }

                $lastIndex = isset($normalized[$client]) ? count($normalized[$client]) - 1 : -1;
                if ($lastIndex >= 0) {
                    $last = $normalized[$client][$lastIndex];
                    $lastEnd = $last['clock'] + $last['length'];
                    $deleteEnd = $delete['clock'] + $delete['length'];

                    if ($delete['clock'] <= $lastEnd) {
                        $normalized[$client][$lastIndex]['length'] = max($lastEnd, $deleteEnd) - $last['clock'];
                        continue;
                    }
                }

                $normalized[$client][] = $delete;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $cache
     */
    private static function cacheObfuscatedValue(array &$cache, string $key, mixed $value): mixed
    {
        if (! array_key_exists($key, $cache)) {
            $cache[$key] = $value;
        }

        return $cache[$key];
    }

    private static function obfuscationCacheKey(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return 'bool:' . ($value ? '1' : '0');
        }

        if (is_int($value)) {
            return 'int:' . $value;
        }

        if (is_float($value)) {
            return 'float:' . serialize($value);
        }

        if (is_string($value)) {
            return 'string:' . $value;
        }

        return 'json:' . json_encode($value, JSON_THROW_ON_ERROR);
    }

    private static function sliceUtf16CodeUnits(string $value, int $offset): string
    {
        if ($value === '') {
            return '';
        }

        if ($offset === 0) {
            return $value;
        }

        $utf16 = mb_convert_encoding($value, 'UTF-16LE', 'UTF-8');
        $result = substr($utf16, $offset * 2);

        return self::utf16CodeUnitsToString($result);
    }

    private static function utf16CodeUnitsToString(string $utf16): string
    {
        $result = '';
        $length = strlen($utf16);

        for ($offset = 0; $offset + 1 < $length; $offset += 2) {
            $unit = unpack('v', substr($utf16, $offset, 2))[1];

            if ($unit >= 0xD800 && $unit <= 0xDBFF) {
                if ($offset + 3 < $length) {
                    $next = unpack('v', substr($utf16, $offset + 2, 2))[1];
                    if ($next >= 0xDC00 && $next <= 0xDFFF) {
                        $codePoint = 0x10000 + (($unit - 0xD800) << 10) + ($next - 0xDC00);
                        $result .= mb_chr($codePoint, 'UTF-8');
                        $offset += 2;
                        continue;
                    }
                }

                $result .= "\u{FFFD}";
                continue;
            }

            if ($unit >= 0xDC00 && $unit <= 0xDFFF) {
                $result .= "\u{FFFD}";
                continue;
            }

            $result .= mb_chr($unit, 'UTF-8');
        }

        return $result;
    }

    private static function utf16CodeUnitLength(string $value): int
    {
        if ($value === '') {
            return 0;
        }

        return intdiv(strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }
}
