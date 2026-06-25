<?php

declare(strict_types=1);

namespace Yjs\Update;

use Yjs\Lib0\Decoding;
use Yjs\Lib0\Encoding;
use Yjs\UndefinedValue;

final class DecodedUpdate
{
    private const BITS5 = 0b00011111;
    private const BIT6 = 0b00100000;
    private const BIT7 = 0b01000000;
    private const BIT8 = 0b10000000;

    /**
     * @return array{
     *     structs: list<array<string, mixed>>,
     *     deleteSet: array<int, list<array{clock: int, length: int}>>
     * }
     */
    public static function decodeV1(string $update): array
    {
        $decoder = UpdateDecoder::v1($update);
        $structs = self::readStructs($decoder);
        $deleteSet = self::readDeleteSet($decoder);

        if ($decoder->restDecoder->hasContent()) {
            throw new \UnexpectedValueException('V1 update contains trailing bytes.');
        }

        return [
            'structs' => $structs,
            'deleteSet' => $deleteSet,
        ];
    }

    /**
     * @return array{
     *     structs: list<array<string, mixed>>,
     *     deleteSet: array<int, list<array{clock: int, length: int}>>
     * }
     */
    public static function decodeV2(string $update): array
    {
        $decoder = UpdateDecoder::v2($update);
        $structs = self::readStructs($decoder);
        $deleteSet = self::readDeleteSet($decoder);

        if ($decoder->restDecoder->hasContent()) {
            throw new \UnexpectedValueException('V2 update contains trailing bytes.');
        }

        return [
            'structs' => $structs,
            'deleteSet' => $deleteSet,
        ];
    }

    /**
     * @param list<array<string, mixed>> $structs
     * @param array<int, list<array{clock: int, length: int}>> $deleteSet
     * @param array<int, int> $targetStateVector
     */
    public static function encodeV1(array $structs, array $deleteSet = [], array $targetStateVector = []): string
    {
        $structsByClient = self::groupStructsForEncoding($structs, $targetStateVector);
        krsort($structsByClient, SORT_NUMERIC);

        $encoder = new Encoding();
        $encoder->writeVarUint(count($structsByClient));

        foreach ($structsByClient as $client => $clientStructs) {
            $encoder->writeVarUint(count($clientStructs));
            $encoder->writeVarUint((int) $client);
            $encoder->writeVarUint($clientStructs[0]['id']['clock']);

            foreach ($clientStructs as $struct) {
                self::writeStruct($encoder, $struct);
            }
        }

        self::writeDeleteSet($encoder, $deleteSet);

        return $encoder->toString();
    }

    /**
     * @param list<array<string, mixed>> $structs
     * @param array<int, list<array{clock: int, length: int}>> $deleteSet
     * @param array<int, int> $targetStateVector
     */
    public static function encodeV2(array $structs, array $deleteSet = [], array $targetStateVector = []): string
    {
        $structsByClient = self::groupStructsForEncoding($structs, $targetStateVector);
        krsort($structsByClient, SORT_NUMERIC);

        $encoder = new UpdateEncoderV2();
        $encoder->restEncoder->writeVarUint(count($structsByClient));

        foreach ($structsByClient as $client => $clientStructs) {
            $encoder->restEncoder->writeVarUint(count($clientStructs));
            $encoder->writeClient((int) $client);
            $encoder->restEncoder->writeVarUint($clientStructs[0]['id']['clock']);

            foreach ($clientStructs as $struct) {
                self::writeStructV2($encoder, $struct);
            }
        }

        self::writeDeleteSetV2($encoder, $deleteSet);

        return $encoder->toString();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function readStructs(UpdateDecoder $decoder): array
    {
        $structs = [];
        $numStateUpdates = $decoder->restDecoder->readVarUint();

        for ($i = 0; $i < $numStateUpdates; $i++) {
            $numberOfStructs = $decoder->restDecoder->readVarUint();
            $client = $decoder->readClient();
            $clock = $decoder->restDecoder->readVarUint();

            for ($structIndex = 0; $structIndex < $numberOfStructs; $structIndex++) {
                $info = $decoder->readInfo();
                $contentRef = $info & self::BITS5;
                $startClock = $clock;

                if ($info === 10) {
                    $length = $decoder->restDecoder->readVarUint();
                    $structs[] = [
                        'type' => 'Skip',
                        'id' => self::id($client, $startClock),
                        'length' => $length,
                    ];
                    $clock += $length;
                    continue;
                }

                if ($contentRef === 0) {
                    $length = $decoder->readLength();
                    $structs[] = [
                        'type' => 'GC',
                        'id' => self::id($client, $startClock),
                        'length' => $length,
                    ];
                    $clock += $length;
                    continue;
                }

                $item = self::readItem($decoder, $info, $client, $startClock);
                $structs[] = $item;
                $clock += $item['length'];
            }
        }

        return $structs;
    }

    /**
     * @param list<array<string, mixed>> $structs
     * @param array<int, int> $targetStateVector
     * @return array<int, list<array<string, mixed>>>
     */
    private static function groupStructsForEncoding(array $structs, array $targetStateVector): array
    {
        usort(
            $structs,
            static fn (array $left, array $right): int => [$left['id']['client'], $left['id']['clock']] <=> [$right['id']['client'], $right['id']['clock']]
        );

        $structs = self::markCopiedParentSubStructs($structs);
        $grouped = [];

        foreach ($structs as $struct) {
            $client = $struct['id']['client'];
            $targetClock = $targetStateVector[$client] ?? 0;
            $structStart = $struct['id']['clock'];
            $structEnd = $structStart + $struct['length'];

            if ($structEnd <= $targetClock) {
                continue;
            }

            if ($targetClock > $structStart && $targetClock < $structEnd) {
                $struct = self::sliceStruct($struct, $targetClock - $structStart);
            }

            $grouped[$client][] = $struct;
        }

        return $grouped;
    }

    /**
     * Yjs keeps the parentSub info bit for map-key items even when the parent
     * and key can be copied from the referenced origin/right-origin.
     *
     * @param list<array<string, mixed>> $structs
     * @return list<array<string, mixed>>
     */
    private static function markCopiedParentSubStructs(array $structs): array
    {
        $structById = [];

        foreach ($structs as $struct) {
            if (($struct['type'] ?? null) === 'Item') {
                $structById[self::idKey($struct['id'])] = $struct;
            }
        }

        foreach ($structs as $index => $struct) {
            if (($struct['type'] ?? null) !== 'Item' || ($struct['parentSub'] ?? null) !== null) {
                continue;
            }

            foreach (['origin', 'rightOrigin'] as $referenceKey) {
                if (! is_array($struct[$referenceKey] ?? null)) {
                    continue;
                }

                $reference = $structById[self::idKey($struct[$referenceKey])] ?? null;
                if (($reference['parentSub'] ?? null) !== null || ($reference['_parentSubMarker'] ?? false)) {
                    $structs[$index]['_parentSubMarker'] = true;
                    break;
                }
            }
        }

        return $structs;
    }

    /**
     * @param array{client: int, clock: int} $id
     */
    private static function idKey(array $id): string
    {
        return $id['client'] . ':' . $id['clock'];
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

        if ($struct['type'] === 'GC' || $struct['type'] === 'Skip') {
            return $sliced;
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
     * @param array<string, mixed> $struct
     */
    private static function writeStruct(Encoding $encoder, array $struct): void
    {
        if ($struct['type'] === 'Skip') {
            $encoder->writeUint8(10);
            $encoder->writeVarUint($struct['length']);
            return;
        }

        if ($struct['type'] === 'GC') {
            $encoder->writeUint8(0);
            $encoder->writeVarUint($struct['length']);
            return;
        }

        $contentRef = self::contentRef($struct['content']);
        $info = $contentRef;

        if (is_array($struct['origin'])) {
            $info |= self::BIT8;
        }

        if (is_array($struct['rightOrigin'])) {
            $info |= self::BIT7;
        }

        $cantCopyParentInfo = ! is_array($struct['origin']) && ! is_array($struct['rightOrigin']);
        if (
            ($cantCopyParentInfo && $struct['parentSub'] !== null)
            || (! $cantCopyParentInfo && ($struct['_parentSubMarker'] ?? false))
        ) {
            $info |= self::BIT6;
        }

        $encoder->writeUint8($info);

        if (is_array($struct['origin'])) {
            self::writeId($encoder, $struct['origin']);
        }

        if (is_array($struct['rightOrigin'])) {
            self::writeId($encoder, $struct['rightOrigin']);
        }

        if ($cantCopyParentInfo) {
            if (is_string($struct['parent'])) {
                $encoder->writeVarUint(1);
                $encoder->writeVarString($struct['parent']);
            } elseif (is_array($struct['parent'])) {
                $encoder->writeVarUint(0);
                self::writeId($encoder, $struct['parent']);
            } else {
                throw new \UnexpectedValueException('Cannot encode item without parent information.');
            }

            if ($struct['parentSub'] !== null) {
                $encoder->writeVarString($struct['parentSub']);
            }
        }

        self::writeContent($encoder, $struct['content']);
    }

    /**
     * @param array<string, mixed> $content
     */
    private static function contentRef(array $content): int
    {
        return match ($content['type']) {
            'ContentDeleted' => 1,
            'ContentJSON' => 2,
            'ContentBinary' => 3,
            'ContentString' => 4,
            'ContentEmbed' => 5,
            'ContentFormat' => 6,
            'ContentType' => 7,
            'ContentAny' => 8,
            'ContentDoc' => 9,
            default => throw new \UnexpectedValueException(sprintf('Cannot encode content type "%s".', $content['type'])),
        };
    }

    /**
     * @param array<string, mixed> $content
     */
    private static function writeContent(Encoding $encoder, array $content): void
    {
        switch ($content['type']) {
            case 'ContentDeleted':
                $encoder->writeVarUint($content['length']);
                break;
            case 'ContentJSON':
                $encoder->writeVarUint(count($content['values']));
                foreach ($content['values'] as $value) {
                    $encoder->writeVarString(self::encodeJsonValue($value));
                }
                break;
            case 'ContentBinary':
                $bytes = base64_decode($content['base64'], true);
                if (! is_string($bytes)) {
                    throw new \UnexpectedValueException('Invalid base64 binary content.');
                }
                $encoder->writeVarUint8Array($bytes);
                break;
            case 'ContentString':
                $encoder->writeVarString($content['value']);
                break;
            case 'ContentEmbed':
                $encoder->writeVarString(self::encodeJsonValue($content['value']));
                break;
            case 'ContentFormat':
                $encoder->writeVarString($content['key']);
                $encoder->writeVarString(self::encodeJsonValue($content['value']));
                break;
            case 'ContentType':
                $encoder->writeVarUint($content['typeRef']);
                if (($content['typeRef'] ?? null) === 3) {
                    $encoder->writeVarString($content['nodeName'] ?? 'UNDEFINED');
                }
                if (($content['typeRef'] ?? null) === 5) {
                    $encoder->writeVarString($content['hookName'] ?? 'UNDEFINED');
                }
                break;
            case 'ContentAny':
                $encoder->writeVarUint(count($content['values']));
                foreach ($content['values'] as $value) {
                    $encoder->writeAny($value);
                }
                break;
            case 'ContentDoc':
                $encoder->writeVarString($content['guid']);
                $encoder->writeAny($content['opts']);
                break;
            default:
                throw new \UnexpectedValueException(sprintf('Cannot encode content type "%s".', $content['type']));
        }
    }

    /**
     * @param array<string, mixed> $struct
     */
    private static function writeStructV2(UpdateEncoderV2 $encoder, array $struct): void
    {
        if ($struct['type'] === 'Skip') {
            $encoder->writeInfo(10);
            $encoder->restEncoder->writeVarUint($struct['length']);
            return;
        }

        if ($struct['type'] === 'GC') {
            $encoder->writeInfo(0);
            $encoder->writeLength($struct['length']);
            return;
        }

        $contentRef = self::contentRef($struct['content']);
        $info = $contentRef;

        if (is_array($struct['origin'])) {
            $info |= self::BIT8;
        }

        if (is_array($struct['rightOrigin'])) {
            $info |= self::BIT7;
        }

        $cantCopyParentInfo = ! is_array($struct['origin']) && ! is_array($struct['rightOrigin']);
        if (
            ($cantCopyParentInfo && $struct['parentSub'] !== null)
            || (! $cantCopyParentInfo && ($struct['_parentSubMarker'] ?? false))
        ) {
            $info |= self::BIT6;
        }

        $encoder->writeInfo($info);

        if (is_array($struct['origin'])) {
            $encoder->writeLeftId($struct['origin']);
        }

        if (is_array($struct['rightOrigin'])) {
            $encoder->writeRightId($struct['rightOrigin']);
        }

        if ($cantCopyParentInfo) {
            if (is_string($struct['parent'])) {
                $encoder->writeParentInfo(true);
                $encoder->writeString($struct['parent']);
            } elseif (is_array($struct['parent'])) {
                $encoder->writeParentInfo(false);
                $encoder->writeLeftId($struct['parent']);
            } else {
                throw new \UnexpectedValueException('Cannot encode item without parent information.');
            }

            if ($struct['parentSub'] !== null) {
                $encoder->writeString($struct['parentSub']);
            }
        }

        self::writeContentV2($encoder, $struct['content']);
    }

    /**
     * @param array<string, mixed> $content
     */
    private static function writeContentV2(UpdateEncoderV2 $encoder, array $content): void
    {
        switch ($content['type']) {
            case 'ContentDeleted':
                $encoder->writeLength($content['length']);
                break;
            case 'ContentJSON':
                $encoder->writeLength(count($content['values']));
                foreach ($content['values'] as $value) {
                    $encoder->writeString(self::encodeJsonValue($value));
                }
                break;
            case 'ContentBinary':
                $bytes = base64_decode($content['base64'], true);
                if (! is_string($bytes)) {
                    throw new \UnexpectedValueException('Invalid base64 binary content.');
                }
                $encoder->writeBuffer($bytes);
                break;
            case 'ContentString':
                $encoder->writeString($content['value']);
                break;
            case 'ContentEmbed':
                $encoder->writeJson($content['value']);
                break;
            case 'ContentFormat':
                $encoder->writeKey($content['key']);
                $encoder->writeJson($content['value']);
                break;
            case 'ContentType':
                $encoder->writeTypeRef($content['typeRef']);
                if (($content['typeRef'] ?? null) === 3) {
                    $encoder->writeKey($content['nodeName'] ?? 'UNDEFINED');
                }
                if (($content['typeRef'] ?? null) === 5) {
                    $encoder->writeKey($content['hookName'] ?? 'UNDEFINED');
                }
                break;
            case 'ContentAny':
                $encoder->writeLength(count($content['values']));
                foreach ($content['values'] as $value) {
                    $encoder->writeAny($value);
                }
                break;
            case 'ContentDoc':
                $encoder->writeString($content['guid']);
                $encoder->writeAny($content['opts']);
                break;
            default:
                throw new \UnexpectedValueException(sprintf('Cannot encode content type "%s".', $content['type']));
        }
    }

    private static function encodeJsonValue(mixed $value): string
    {
        if ($value instanceof UndefinedValue) {
            return 'undefined';
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $json;
    }

    /**
     * @return array<string, mixed>
     */
    private static function readItem(UpdateDecoder $decoder, int $info, int $client, int $clock): array
    {
        $cantCopyParentInfo = ($info & (self::BIT7 | self::BIT8)) === 0;
        $origin = null;
        $rightOrigin = null;
        $parent = null;
        $parentSub = null;

        if (($info & self::BIT8) === self::BIT8) {
            $origin = $decoder->readLeftId();
        }

        if (($info & self::BIT7) === self::BIT7) {
            $rightOrigin = $decoder->readRightId();
        }

        if ($cantCopyParentInfo) {
            if ($decoder->readParentInfo()) {
                $parent = $decoder->readString();
            } else {
                $parent = $decoder->readLeftId();
            }
        }

        if ($cantCopyParentInfo && ($info & self::BIT6) === self::BIT6) {
            $parentSub = $decoder->readString();
        }

        $content = self::readContent($decoder, $info & self::BITS5);

        return [
            'type' => 'Item',
            'id' => self::id($client, $clock),
            'length' => $content['length'],
            'origin' => $origin,
            'rightOrigin' => $rightOrigin,
            'parent' => $parent,
            'parentSub' => $parentSub,
            'content' => $content['content'],
        ];
    }

    /**
     * @return array{length: int, content: array<string, mixed>}
     */
    private static function readContent(UpdateDecoder $decoder, int $contentRef): array
    {
        return match ($contentRef) {
            1 => self::readDeletedContent($decoder),
            2 => self::readJsonContent($decoder),
            3 => self::readBinaryContent($decoder),
            4 => self::readStringContent($decoder),
            5 => self::readEmbedContent($decoder),
            6 => self::readFormatContent($decoder),
            7 => self::readTypeContent($decoder),
            8 => self::readAnyContent($decoder),
            9 => self::readDocContent($decoder),
            default => throw new \UnexpectedValueException(sprintf('Unsupported V1 content ref %d.', $contentRef)),
        };
    }

    /**
     * @return array{length: int, content: array<string, mixed>}
     */
    private static function readDeletedContent(UpdateDecoder $decoder): array
    {
        $length = $decoder->readLength();

        return [
            'length' => $length,
            'content' => [
                'type' => 'ContentDeleted',
                'length' => $length,
            ],
        ];
    }

    /**
     * @return array{length: int, content: array<string, mixed>}
     */
    private static function readJsonContent(UpdateDecoder $decoder): array
    {
        $length = $decoder->readLength();
        $values = [];

        for ($i = 0; $i < $length; $i++) {
            $json = $decoder->readString();
            try {
                $values[] = $json === 'undefined' ? UndefinedValue::instance() : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new \UnexpectedValueException('Unable to decode ContentJSON value.', 0, $exception);
            }
        }

        return [
            'length' => $length,
            'content' => [
                'type' => 'ContentJSON',
                'values' => $values,
            ],
        ];
    }

    /**
     * @return array{length: int, content: array<string, mixed>}
     */
    private static function readBinaryContent(UpdateDecoder $decoder): array
    {
        return [
            'length' => 1,
            'content' => [
                'type' => 'ContentBinary',
                'base64' => base64_encode($decoder->readBuffer()),
            ],
        ];
    }

    /**
     * @return array{length: int, content: array<string, mixed>}
     */
    private static function readStringContent(UpdateDecoder $decoder): array
    {
        $value = $decoder->readString();

        return [
            'length' => self::utf16CodeUnitLength($value),
            'content' => [
                'type' => 'ContentString',
                'value' => $value,
            ],
        ];
    }

    /**
     * @return array{length: int, content: array<string, mixed>}
     */
    private static function readEmbedContent(UpdateDecoder $decoder): array
    {
        return [
            'length' => 1,
            'content' => [
                'type' => 'ContentEmbed',
                'value' => $decoder->readJson(),
            ],
        ];
    }

    /**
     * @return array{length: int, content: array<string, mixed>}
     */
    private static function readFormatContent(UpdateDecoder $decoder): array
    {
        $key = $decoder->readKey();

        return [
            'length' => 1,
            'content' => [
                'type' => 'ContentFormat',
                'key' => $key,
                'value' => $decoder->readJson(),
            ],
        ];
    }

    /**
     * @return array{length: int, content: array<string, mixed>}
     */
    private static function readTypeContent(UpdateDecoder $decoder): array
    {
        $typeRef = $decoder->readTypeRef();
        $typeName = ['YArray', 'YMap', 'YText', 'YXmlElement', 'YXmlFragment', 'YXmlHook', 'YXmlText'][$typeRef] ?? 'Unknown';
        $content = [
            'type' => 'ContentType',
            'typeRef' => $typeRef,
            'typeName' => $typeName,
        ];

        if ($typeRef === 3) {
            $content['nodeName'] = $decoder->readKey();
        }

        if ($typeRef === 5) {
            $content['hookName'] = $decoder->readKey();
        }

        return [
            'length' => 1,
            'content' => $content,
        ];
    }

    /**
     * @return array{length: int, content: array<string, mixed>}
     */
    private static function readAnyContent(UpdateDecoder $decoder): array
    {
        $length = $decoder->readLength();
        $values = [];

        for ($i = 0; $i < $length; $i++) {
            $values[] = $decoder->readAny();
        }

        return [
            'length' => $length,
            'content' => [
                'type' => 'ContentAny',
                'values' => $values,
            ],
        ];
    }

    /**
     * @return array{length: int, content: array<string, mixed>}
     */
    private static function readDocContent(UpdateDecoder $decoder): array
    {
        $guid = $decoder->readString();
        $opts = $decoder->readAny();

        return [
            'length' => 1,
            'content' => [
                'type' => 'ContentDoc',
                'guid' => $guid,
                'opts' => $opts,
            ],
        ];
    }

    /**
     * @return array<int, list<array{clock: int, length: int}>>
     */
    private static function readDeleteSet(UpdateDecoder $decoder): array
    {
        $deleteSet = [];
        $numClients = $decoder->restDecoder->readVarUint();

        for ($i = 0; $i < $numClients; $i++) {
            $decoder->resetDeleteSetCurrentValue();
            $client = $decoder->restDecoder->readVarUint();
            $numberOfDeletes = $decoder->restDecoder->readVarUint();

            for ($deleteIndex = 0; $deleteIndex < $numberOfDeletes; $deleteIndex++) {
                $deleteSet[$client][] = [
                    'clock' => $decoder->readDeleteSetClock(),
                    'length' => $decoder->readDeleteSetLength(),
                ];
            }
        }

        return $deleteSet;
    }

    /**
     * @param array<int, list<array{clock: int, length: int}>> $deleteSet
     */
    private static function writeDeleteSet(Encoding $encoder, array $deleteSet): void
    {
        $deleteSet = self::normalizeDeleteSet($deleteSet);
        $encoder->writeVarUint(count($deleteSet));

        foreach ($deleteSet as $client => $deletes) {
            $encoder->writeVarUint((int) $client);
            $encoder->writeVarUint(count($deletes));

            foreach ($deletes as $delete) {
                $encoder->writeVarUint($delete['clock']);
                $encoder->writeVarUint($delete['length']);
            }
        }
    }

    /**
     * @param array<int, list<array{clock: int, length: int}>> $deleteSet
     */
    private static function writeDeleteSetV2(UpdateEncoderV2 $encoder, array $deleteSet): void
    {
        $deleteSet = self::normalizeDeleteSet($deleteSet);
        $encoder->restEncoder->writeVarUint(count($deleteSet));

        foreach ($deleteSet as $client => $deletes) {
            $encoder->resetDeleteSetCurrentValue();
            $encoder->restEncoder->writeVarUint((int) $client);
            $encoder->restEncoder->writeVarUint(count($deletes));

            foreach ($deletes as $delete) {
                $encoder->writeDeleteSetClock($delete['clock']);
                $encoder->writeDeleteSetLength($delete['length']);
            }
        }
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
     * @return array{client: int, clock: int}
     */
    private static function readId(Decoding $decoder): array
    {
        return self::id($decoder->readVarUint(), $decoder->readVarUint());
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
     * @return array{client: int, clock: int}
     */
    private static function id(int $client, int $clock): array
    {
        return [
            'client' => $client,
            'clock' => $clock,
        ];
    }

    private static function utf16CodeUnitLength(string $value): int
    {
        if ($value === '') {
            return 0;
        }

        return intdiv(strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }

    private static function sliceUtf16CodeUnits(string $value, int $start): string
    {
        if ($value === '') {
            return '';
        }

        if ($start === 0) {
            return $value;
        }

        $utf16 = mb_convert_encoding($value, 'UTF-16LE', 'UTF-8');
        $result = substr($utf16, $start * 2);

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
}
