<?php

declare(strict_types=1);

namespace Yjs;

final class YXmlText implements \Countable, \Stringable
{
    public function __construct(private readonly YDoc $doc, private readonly string $idKey, private string $value)
    {
    }

    public function idKey(): string
    {
        return $this->idKey;
    }

    public function toString(): string
    {
        return $this->doc->xmlTextValue($this->idKey);
    }

    public function toStringSnapshot(Snapshot $snapshot): string
    {
        return $this->doc->createDocFromSnapshot($snapshot)->xmlTextValue($this->idKey);
    }

    public function toJSON(): string
    {
        return $this->toString();
    }

    public function toJSONSnapshot(Snapshot $snapshot): string
    {
        return $this->toStringSnapshot($snapshot);
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function length(): int
    {
        return $this->doc->xmlTextLength($this->idKey);
    }

    public function lengthSnapshot(Snapshot $snapshot): int
    {
        return $this->doc->createDocFromSnapshot($snapshot)->xmlTextLength($this->idKey);
    }

    public function count(): int
    {
        return $this->length();
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->doc->setXmlElementAttribute($this->idKey, $key, $value);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function setAttributes(array $attributes): void
    {
        $this->doc->transact(function () use ($attributes): void {
            foreach ($attributes as $key => $value) {
                $this->doc->setXmlElementAttribute($this->idKey, (string) $key, $value);
            }
        });
    }

    public function getAttribute(string $key): mixed
    {
        return $this->doc->xmlElementAttribute($this->idKey, $key);
    }

    public function getSharedType(string $key): YNestedArray|YNestedMap|YNestedText|YNestedXmlFragment|YXmlElement|self|YXmlHook|null
    {
        return $this->doc->sharedTypeInXmlElementAttribute($this->idKey, $key);
    }

    public function getArray(string $key): ?YNestedArray
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof YNestedArray) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YXmlText attribute "%s" is not a nested YArray.', $key));
    }

    public function getMap(string $key): ?YNestedMap
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof YNestedMap) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YXmlText attribute "%s" is not a nested YMap.', $key));
    }

    public function getText(string $key): ?YNestedText
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof YNestedText) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YXmlText attribute "%s" is not a nested YText.', $key));
    }

    public function getXmlElement(string $key): ?YXmlElement
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof YXmlElement) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YXmlText attribute "%s" is not a YXmlElement.', $key));
    }

    public function getXmlText(string $key): ?self
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof self) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YXmlText attribute "%s" is not a YXmlText.', $key));
    }

    public function getXmlHook(string $key): ?YXmlHook
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof YXmlHook) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YXmlText attribute "%s" is not a YXmlHook.', $key));
    }

    public function getXmlFragment(string $key): ?YNestedXmlFragment
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof YNestedXmlFragment) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YXmlText attribute "%s" is not a YXmlFragment.', $key));
    }

    public function setArray(string $key): YNestedArray
    {
        return $this->doc->setNestedArrayInXmlElementAttribute($this->idKey, $key);
    }

    /**
     * @param list<string> $keys
     * @return array<string, YNestedArray>
     */
    public function setArrays(array $keys): array
    {
        $keys = self::normalizeStringList($keys, 'YXmlText setArrays only supports string keys.');
        $created = [];

        $this->doc->transact(function () use ($keys, &$created): void {
            foreach ($keys as $key) {
                $created[$key] = $this->doc->setNestedArrayInXmlElementAttribute($this->idKey, $key);
            }
        });

        return $created;
    }

    public function setMap(string $key): YNestedMap
    {
        return $this->doc->setNestedMapInXmlElementAttribute($this->idKey, $key);
    }

    /**
     * @param list<string> $keys
     * @return array<string, YNestedMap>
     */
    public function setMaps(array $keys): array
    {
        $keys = self::normalizeStringList($keys, 'YXmlText setMaps only supports string keys.');
        $created = [];

        $this->doc->transact(function () use ($keys, &$created): void {
            foreach ($keys as $key) {
                $created[$key] = $this->doc->setNestedMapInXmlElementAttribute($this->idKey, $key);
            }
        });

        return $created;
    }

    public function setText(string $key): YNestedText
    {
        return $this->doc->setNestedTextInXmlElementAttribute($this->idKey, $key);
    }

    /**
     * @param list<string> $keys
     * @return array<string, YNestedText>
     */
    public function setTexts(array $keys): array
    {
        $keys = self::normalizeStringList($keys, 'YXmlText setTexts only supports string keys.');
        $created = [];

        $this->doc->transact(function () use ($keys, &$created): void {
            foreach ($keys as $key) {
                $created[$key] = $this->doc->setNestedTextInXmlElementAttribute($this->idKey, $key);
            }
        });

        return $created;
    }

    public function setXmlElement(string $key, string $nodeName): YXmlElement
    {
        return $this->doc->setXmlElementInXmlElementAttribute($this->idKey, $key, $nodeName);
    }

    /**
     * @param array<string, string> $elements
     * @return array<string, YXmlElement>
     */
    public function setXmlElements(array $elements): array
    {
        self::assertStringMap($elements, 'YXmlText setXmlElements only supports string node names.');
        $created = [];

        $this->doc->transact(function () use ($elements, &$created): void {
            foreach ($elements as $key => $nodeName) {
                $created[(string) $key] = $this->doc->setXmlElementInXmlElementAttribute($this->idKey, (string) $key, $nodeName);
            }
        });

        return $created;
    }

    public function setXmlText(string $key, string $text = ''): self
    {
        return $this->doc->setXmlTextInXmlElementAttribute($this->idKey, $key, $text);
    }

    /**
     * @param array<string, string> $texts
     * @return array<string, self>
     */
    public function setXmlTexts(array $texts): array
    {
        self::assertStringMap($texts, 'YXmlText setXmlTexts only supports string XML text content.');
        $created = [];

        $this->doc->transact(function () use ($texts, &$created): void {
            foreach ($texts as $key => $text) {
                $created[(string) $key] = $this->doc->setXmlTextInXmlElementAttribute($this->idKey, (string) $key, $text);
            }
        });

        return $created;
    }

    public function setXmlHook(string $key, string $hookName): YXmlHook
    {
        return $this->doc->setXmlHookInXmlElementAttribute($this->idKey, $key, $hookName);
    }

    /**
     * @param array<string, string> $hooks
     * @return array<string, YXmlHook>
     */
    public function setXmlHooks(array $hooks): array
    {
        self::assertStringMap($hooks, 'YXmlText setXmlHooks only supports string hook names.');
        $created = [];

        $this->doc->transact(function () use ($hooks, &$created): void {
            foreach ($hooks as $key => $hookName) {
                $created[(string) $key] = $this->doc->setXmlHookInXmlElementAttribute($this->idKey, (string) $key, $hookName);
            }
        });

        return $created;
    }

    public function setXmlFragment(string $key): YNestedXmlFragment
    {
        return $this->doc->setXmlFragmentInXmlElementAttribute($this->idKey, $key);
    }

    /**
     * @param list<string> $keys
     * @return array<string, YNestedXmlFragment>
     */
    public function setXmlFragments(array $keys): array
    {
        $keys = self::normalizeStringList($keys, 'YXmlText setXmlFragments only supports string keys.');
        $created = [];

        $this->doc->transact(function () use ($keys, &$created): void {
            foreach ($keys as $key) {
                $created[$key] = $this->doc->setXmlFragmentInXmlElementAttribute($this->idKey, $key);
            }
        });

        return $created;
    }

    public function getAttributeSnapshot(string $key, Snapshot $snapshot): mixed
    {
        return $this->doc->createDocFromSnapshot($snapshot)->xmlElementAttribute($this->idKey, $key);
    }

    public function hasAttribute(string $key): bool
    {
        return $this->doc->xmlElementHasAttribute($this->idKey, $key);
    }

    public function hasAttributeSnapshot(string $key, Snapshot $snapshot): bool
    {
        return $this->doc->createDocFromSnapshot($snapshot)->xmlElementHasAttribute($this->idKey, $key);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->doc->xmlElementAttributes($this->idKey);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributesSnapshot(Snapshot $snapshot): array
    {
        return $this->doc->createDocFromSnapshot($snapshot)->xmlElementAttributes($this->idKey);
    }

    public function removeAttribute(string $key): void
    {
        $this->doc->removeXmlElementAttribute($this->idKey, $key);
    }

    /**
     * @param list<string> $keys
     */
    public function removeAttributes(array $keys): void
    {
        $this->doc->transact(function () use ($keys): void {
            foreach ($keys as $key) {
                $this->doc->removeXmlElementAttribute($this->idKey, $key);
            }
        });
    }

    public function nextSibling(): YXmlElement|self|YXmlHook|null
    {
        return $this->doc->xmlNodeNextSibling($this->idKey);
    }

    public function nextSiblingSnapshot(Snapshot $snapshot): YXmlElement|self|YXmlHook|null
    {
        return $this->doc->createDocFromSnapshot($snapshot)->xmlNodeNextSibling($this->idKey);
    }

    public function prevSibling(): YXmlElement|self|YXmlHook|null
    {
        return $this->doc->xmlNodePreviousSibling($this->idKey);
    }

    public function prevSiblingSnapshot(Snapshot $snapshot): YXmlElement|self|YXmlHook|null
    {
        return $this->doc->createDocFromSnapshot($snapshot)->xmlNodePreviousSibling($this->idKey);
    }

    public function slice(int $start = 0, ?int $end = null): string
    {
        return self::sliceStringByUtf16CodeUnits($this->toString(), $start, $end);
    }

    public function sliceSnapshot(Snapshot $snapshot, int $start = 0, ?int $end = null): string
    {
        return self::sliceStringByUtf16CodeUnits($this->toStringSnapshot($snapshot), $start, $end);
    }

    private static function sliceStringByUtf16CodeUnits(string $value, int $start, ?int $end): string
    {
        $length = self::utf16CodeUnitLength($value);
        $normalizedStart = self::normalizeSliceIndex($start, $length);
        $normalizedEnd = $end === null ? $length : self::normalizeSliceIndex($end, $length);

        return self::sliceUtf16CodeUnits($value, $normalizedStart, max(0, $normalizedEnd - $normalizedStart));
    }

    /**
     * @param list<array<string, mixed>> $delta
     * @param array{sanitize?: bool} $options
     */
    public function applyDelta(array $delta, array $options = []): void
    {
        $sanitize = $options['sanitize'] ?? true;

        $this->doc->transact(function () use ($delta, $sanitize): void {
            $index = 0;
            $lastOperation = count($delta) - 1;

            foreach ($delta as $operationIndex => $operation) {
                $attributes = isset($operation['attributes']) && is_array($operation['attributes']) ? $operation['attributes'] : [];

                if (array_key_exists('insert', $operation)) {
                    $clearAttributes = $this->formatAttributesToClear($index, $attributes);
                    if (is_string($operation['insert'])) {
                        $insert = $this->normalizedDeltaTextInsert(
                            $operation['insert'],
                            $sanitize,
                            $operationIndex === $lastOperation,
                            $index
                        );
                        if ($insert !== '') {
                            $this->insert($index, $insert, $attributes);
                            $length = self::utf16CodeUnitLength($insert);
                            if ($clearAttributes !== []) {
                                $this->format($index, $length, $clearAttributes);
                            }
                            $index += $length;
                        }
                    } else {
                        $this->insertEmbed($index, $operation['insert'], $attributes);
                        if ($clearAttributes !== []) {
                            $this->format($index, 1, $clearAttributes);
                        }
                        $index++;
                    }
                    continue;
                }

                if (array_key_exists('retain', $operation)) {
                    $length = (int) $operation['retain'];
                    if ($length > 0 && $attributes !== []) {
                        $this->format($index, $length, $attributes);
                    }
                    $index += $length;
                    continue;
                }

                if (array_key_exists('delete', $operation)) {
                    $this->delete($index, (int) $operation['delete']);
                    continue;
                }

                throw new \InvalidArgumentException('Delta operation must contain insert, retain, or delete.');
            }
        });
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function insert(int $index, string $text, array $attributes = []): void
    {
        $clearAttributes = $attributes === [] ? [] : $this->formatAttributesToClear($index, $attributes);

        $this->doc->transact(function () use ($index, $text, $attributes, $clearAttributes): void {
            $this->doc->insertXmlTextContent($this->idKey, $index, $text, $attributes);
            if ($text !== '' && $clearAttributes !== []) {
                $this->doc->formatXmlTextContent($this->idKey, $index, self::utf16CodeUnitLength($text), $clearAttributes);
            }
        });

        $this->value = $this->doc->xmlTextValue($this->idKey);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function append(string $text, array $attributes = []): void
    {
        $this->insert($this->length(), $text, $attributes);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function prepend(string $text, array $attributes = []): void
    {
        $this->insert(0, $text, $attributes);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function insertEmbed(int $index, mixed $embed, array $attributes = []): void
    {
        $clearAttributes = $attributes === [] ? [] : $this->formatAttributesToClear($index, $attributes);

        $this->doc->transact(function () use ($index, $embed, $attributes, $clearAttributes): void {
            $this->doc->insertXmlTextEmbed($this->idKey, $index, $embed, $attributes);
            if ($clearAttributes !== []) {
                $this->doc->formatXmlTextContent($this->idKey, $index, 1, $clearAttributes);
            }
        });

        $this->value = $this->doc->xmlTextValue($this->idKey);
    }

    public function delete(int $index, int $length = 1): void
    {
        $this->doc->deleteXmlTextContent($this->idKey, $index, $length);
        $this->value = $this->doc->xmlTextValue($this->idKey);
    }

    public function clear(): void
    {
        $this->delete(0, $this->length());
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function splice(int $index, int $deleteLength = 0, string $text = '', array $attributes = []): string
    {
        $current = $this->plainText();
        $count = $this->length();
        $start = self::normalizeSpliceIndex($index, $count);
        $deleteLength = max(0, min($deleteLength, $count - $start));
        $deleted = self::sliceUtf16CodeUnits($current, $start, $deleteLength);

        if ($deleteLength === 0 && $text === '') {
            return $deleted;
        }

        $this->doc->transact(function () use ($start, $deleteLength, $text, $attributes): void {
            if ($deleteLength > 0) {
                $this->delete($start, $deleteLength);
            }

            if ($text !== '') {
                $this->insert($start, $text, $attributes);
            }
        });

        $this->value = $this->doc->xmlTextValue($this->idKey);

        return $deleted;
    }

    private function plainText(): string
    {
        $text = '';

        foreach ($this->toDelta() as $operation) {
            if (is_string($operation['insert'] ?? null)) {
                $text .= $operation['insert'];
            }
        }

        return $text;
    }

    public function relativePositionAt(int $index, int $assoc = 0): RelativePosition
    {
        return $this->doc->createRelativePositionForTypeId($this->idKey, $index, $assoc, 'text');
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function format(int $index, int $length, array $attributes): void
    {
        $this->doc->formatXmlTextContent($this->idKey, $index, $length, $attributes);
        $this->value = $this->doc->xmlTextValue($this->idKey);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toDelta(): array
    {
        return $this->doc->xmlTextDelta($this->idKey);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toDeltaSnapshot(Snapshot $snapshot): array
    {
        return $this->doc->createDocFromSnapshot($snapshot)->xmlTextDelta($this->idKey);
    }

    /**
     * @param callable(array<string, mixed>, YDoc): void $observer
     */
    public function observe(callable $observer): int
    {
        return $this->doc->observeXmlNode($this->idKey, $observer);
    }

    /**
     * @param callable(array<string, mixed>, YDoc): void $observer
     */
    public function observeOnce(callable $observer): int
    {
        return $this->doc->observeXmlNodeOnce($this->idKey, $observer);
    }

    public function unobserve(int $observerId): void
    {
        $this->doc->unobserveXmlNode($observerId);
    }

    /**
     * @param callable(list<array<string, mixed>>, self, array<string, mixed>): void $observer
     */
    public function observeDeep(callable $observer): int
    {
        return $this->doc->observeXmlNodeDeep($this->idKey, function (array $events, YDoc $doc, array $transaction) use ($observer): void {
            $observer($events, $this, $transaction);
        });
    }

    /**
     * @param callable(list<array<string, mixed>>, self, array<string, mixed>): void $observer
     */
    public function observeDeepOnce(callable $observer): int
    {
        $observerId = null;
        $observerId = $this->observeDeep(function (array $events, self $text, array $transaction) use (&$observerId, $observer): void {
            if ($observerId !== null) {
                $this->unobserveDeep($observerId);
            }

            $observer($events, $text, $transaction);
        });

        return $observerId;
    }

    public function unobserveDeep(int $observerId): void
    {
        $this->doc->unobserveXmlNodeDeep($observerId);
    }

    /**
     * @param list<mixed> $values
     * @return list<string>
     */
    private static function normalizeStringList(array $values, string $message): array
    {
        $strings = [];

        foreach (array_values($values) as $value) {
            if (! is_string($value)) {
                throw new \InvalidArgumentException($message);
            }

            $strings[] = $value;
        }

        return $strings;
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function assertStringMap(array $values, string $message): void
    {
        foreach ($values as $value) {
            if (! is_string($value)) {
                throw new \InvalidArgumentException($message);
            }
        }
    }

    private static function utf16CodeUnitLength(string $value): int
    {
        if ($value === '') {
            return 0;
        }

        return intdiv(strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }

    private static function normalizeSliceIndex(int $index, int $count): int
    {
        if ($index < 0) {
            return max(0, $count + $index);
        }

        return min($index, $count);
    }

    private static function normalizeSpliceIndex(int $index, int $count): int
    {
        if ($index < 0) {
            return max(0, $count + $index);
        }

        return min($index, $count);
    }

    private static function sliceUtf16CodeUnits(string $value, int $offset, int $length): string
    {
        if ($length === 0 || $value === '') {
            return '';
        }

        $utf16 = mb_convert_encoding($value, 'UTF-16LE', 'UTF-8');
        $slice = substr($utf16, $offset * 2, $length * 2);

        if ($slice === '') {
            return '';
        }

        return mb_convert_encoding($slice, 'UTF-8', 'UTF-16LE');
    }

    private function normalizedDeltaTextInsert(string $insert, bool $sanitize, bool $isLastOperation, int $index): string
    {
        if (!$sanitize && $isLastOperation && $index === $this->length() && str_ends_with($insert, "\n")) {
            return substr($insert, 0, -1);
        }

        return $insert;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, null>
     */
    private function formatAttributesToClear(int $index, array $attributes): array
    {
        $clear = [];

        foreach ($this->activeAttributesBefore($index) as $key => $value) {
            if (! array_key_exists($key, $attributes)) {
                $clear[$key] = null;
            }
        }

        return $clear;
    }

    /**
     * @return array<string, mixed>
     */
    private function activeAttributesBefore(int $index): array
    {
        if ($index <= 0) {
            return [];
        }

        $position = 0;
        foreach ($this->toDelta() as $operation) {
            if (! array_key_exists('insert', $operation)) {
                continue;
            }

            $length = is_string($operation['insert']) ? self::utf16CodeUnitLength($operation['insert']) : 1;
            if ($index <= $position + $length) {
                return isset($operation['attributes']) && is_array($operation['attributes']) ? $operation['attributes'] : [];
            }

            $position += $length;
        }

        return [];
    }
}
