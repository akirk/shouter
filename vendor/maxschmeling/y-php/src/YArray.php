<?php

declare(strict_types=1);

namespace Yjs;

/**
 * @implements \ArrayAccess<int, mixed>
 * @implements \IteratorAggregate<int, mixed>
 */
final class YArray implements \ArrayAccess, \Countable, \IteratorAggregate
{
    /**
     * @param list<mixed> $values
     */
    public function __construct(private readonly YDoc $doc, private readonly string $name, private array $values)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<mixed>
     */
    public function toArray(): array
    {
        return $this->currentValues();
    }

    /**
     * @return list<mixed>
     */
    public function toArraySnapshot(Snapshot $snapshot): array
    {
        return $this->doc->createDocFromSnapshot($snapshot)->getArray($this->name)->toArray();
    }

    /**
     * @return list<mixed>
     */
    public function toJSON(): array
    {
        return $this->toArray();
    }

    /**
     * @return list<mixed>
     */
    public function toJSONSnapshot(Snapshot $snapshot): array
    {
        return $this->toArraySnapshot($snapshot);
    }

    public function length(): int
    {
        return count($this->currentValues());
    }

    public function lengthSnapshot(Snapshot $snapshot): int
    {
        return count($this->toArraySnapshot($snapshot));
    }

    public function count(): int
    {
        return $this->length();
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->currentValues());
    }

    public function get(int $index): mixed
    {
        return $this->currentValues()[$index] ?? null;
    }

    public function getSnapshot(int $index, Snapshot $snapshot): mixed
    {
        return $this->toArraySnapshot($snapshot)[$index] ?? null;
    }

    public function getSharedType(int $index): YNestedArray|YNestedMap|YNestedText|YNestedXmlFragment|YXmlElement|YXmlText|YXmlHook|null
    {
        return $this->doc->sharedTypeInArray($this->name, $index);
    }

    public function getArray(int $index): ?YNestedArray
    {
        $type = $this->getSharedType($index);
        if ($type === null || $type instanceof YNestedArray) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YArray item at index %d is not a nested YArray.', $index));
    }

    public function getMap(int $index): ?YNestedMap
    {
        $type = $this->getSharedType($index);
        if ($type === null || $type instanceof YNestedMap) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YArray item at index %d is not a nested YMap.', $index));
    }

    public function getText(int $index): ?YNestedText
    {
        $type = $this->getSharedType($index);
        if ($type === null || $type instanceof YNestedText) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YArray item at index %d is not a nested YText.', $index));
    }

    public function getXmlElement(int $index): ?YXmlElement
    {
        $type = $this->getSharedType($index);
        if ($type === null || $type instanceof YXmlElement) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YArray item at index %d is not a YXmlElement.', $index));
    }

    public function getXmlText(int $index): ?YXmlText
    {
        $type = $this->getSharedType($index);
        if ($type === null || $type instanceof YXmlText) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YArray item at index %d is not a YXmlText.', $index));
    }

    public function getXmlHook(int $index): ?YXmlHook
    {
        $type = $this->getSharedType($index);
        if ($type === null || $type instanceof YXmlHook) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YArray item at index %d is not a YXmlHook.', $index));
    }

    public function getXmlFragment(int $index): ?YNestedXmlFragment
    {
        $type = $this->getSharedType($index);
        if ($type === null || $type instanceof YNestedXmlFragment) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YArray item at index %d is not a YXmlFragment.', $index));
    }

    public function getSubdoc(int $index): ?YSubdoc
    {
        $value = $this->get($index);
        if ($value === null || $value instanceof YSubdoc) {
            return $value;
        }

        throw new \UnexpectedValueException(sprintf('YArray item at index %d is not a subdoc.', $index));
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_int($offset) && array_key_exists($offset, $this->currentValues());
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get(self::normalizeOffset($offset));
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->push([$value]);

            return;
        }

        $index = self::normalizeOffset($offset);
        $count = count($this->currentValues());
        if ($index < 0 || $index > $count) {
            throw new \InvalidArgumentException('YArray offset assignment index is out of bounds.');
        }

        $this->doc->transact(function () use ($index, $count, $value): void {
            if ($index < $count) {
                $this->delete($index, 1);
            }

            $this->insert($index, [$value]);
        });
    }

    public function offsetUnset(mixed $offset): void
    {
        if (! is_int($offset) || ! array_key_exists($offset, $this->currentValues())) {
            return;
        }

        $this->delete($offset, 1);
    }

    /**
     * @return list<mixed>
     */
    public function slice(int $start = 0, ?int $end = null): array
    {
        $values = $this->currentValues();
        return self::sliceValues($values, $start, $end);
    }

    /**
     * @return list<mixed>
     */
    public function sliceSnapshot(Snapshot $snapshot, int $start = 0, ?int $end = null): array
    {
        return self::sliceValues($this->toArraySnapshot($snapshot), $start, $end);
    }

    /**
     * @param list<mixed> $values
     * @return list<mixed>
     */
    private static function sliceValues(array $values, int $start, ?int $end): array
    {
        $count = count($values);
        $normalizedStart = self::normalizeSliceIndex($start, $count);
        $normalizedEnd = $end === null ? $count : self::normalizeSliceIndex($end, $count);

        return array_slice($values, $normalizedStart, max(0, $normalizedEnd - $normalizedStart));
    }

    /**
     * @param callable(mixed, int, self): void $callback
     */
    public function forEach(callable $callback): void
    {
        foreach ($this->currentValues() as $index => $value) {
            $callback($value, $index, $this);
        }
    }

    /**
     * @template T
     * @param callable(mixed, int, self): T $callback
     * @return list<T>
     */
    public function map(callable $callback): array
    {
        $mapped = [];

        foreach ($this->currentValues() as $index => $value) {
            $mapped[] = $callback($value, $index, $this);
        }

        return $mapped;
    }

    /**
     * @param callable(mixed, int, self): bool $callback
     * @return list<mixed>
     */
    public function filter(callable $callback): array
    {
        $filtered = [];

        foreach ($this->currentValues() as $index => $value) {
            if ($callback($value, $index, $this)) {
                $filtered[] = $value;
            }
        }

        return $filtered;
    }

    public function set(int $index, mixed $value): void
    {
        $count = count($this->currentValues());
        if ($index < 0 || $index >= $count) {
            throw new \InvalidArgumentException('YArray set index is out of bounds.');
        }

        $this->splice($index, 1, [$value]);
    }

    public function replace(int $index, mixed $value): mixed
    {
        $count = count($this->currentValues());
        if ($index < 0 || $index >= $count) {
            throw new \InvalidArgumentException('YArray replace index is out of bounds.');
        }

        return $this->splice($index, 1, [$value])[0] ?? null;
    }

    /**
     * @param list<mixed> $values
     */
    public function insert(int $index, array $values): void
    {
        $this->doc->insertArray($this->name, $index, $values);
        $this->values = $this->doc->getArray($this->name)->toArray();
    }

    /**
     * @param list<mixed> $values
     */
    public function push(array $values): void
    {
        $this->insert(count($this->currentValues()), $values);
    }

    public function append(mixed $value): void
    {
        $this->push([$value]);
    }

    /**
     * @param list<mixed> $values
     */
    public function unshift(array $values): void
    {
        $this->insert(0, $values);
    }

    public function prepend(mixed $value): void
    {
        $this->unshift([$value]);
    }

    public function pop(): mixed
    {
        return $this->splice(-1, 1)[0] ?? null;
    }

    public function shift(): mixed
    {
        return $this->splice(0, 1)[0] ?? null;
    }

    public function clear(): void
    {
        $this->delete(0, count($this->currentValues()));
    }

    /**
     * @param list<mixed> $values
     * @return list<mixed>
     */
    public function splice(int $index, int $deleteLength = 0, array $values = []): array
    {
        $current = $this->currentValues();
        $count = count($current);
        $start = self::normalizeSpliceIndex($index, $count);
        $deleteLength = max(0, min($deleteLength, $count - $start));
        $deleted = array_slice($current, $start, $deleteLength);

        if ($deleteLength === 0 && $values === []) {
            return $deleted;
        }

        $this->doc->transact(function () use ($start, $deleteLength, $values): void {
            if ($deleteLength > 0) {
                $this->delete($start, $deleteLength);
            }

            if ($values !== []) {
                $this->insert($start, $values);
            }
        });

        $this->values = $this->doc->getArray($this->name)->toArray();

        return $deleted;
    }

    public function insertBinary(int $index, string $bytes): void
    {
        $this->doc->insertArrayBinary($this->name, $index, $bytes);
        $this->values = $this->doc->getArray($this->name)->toArray();
    }

    public function appendBinary(string $bytes): void
    {
        $this->insertBinary($this->length(), $bytes);
    }

    public function prependBinary(string $bytes): void
    {
        $this->insertBinary(0, $bytes);
    }

    /**
     * @param list<string> $binaries
     */
    public function insertBinaries(int $index, array $binaries): void
    {
        $this->assertInsertRange($index);
        foreach ($binaries as $bytes) {
            if (! is_string($bytes)) {
                throw new \InvalidArgumentException('YArray insertBinaries only supports binary strings.');
            }
        }

        $this->doc->transact(function () use ($index, $binaries): void {
            foreach (array_values($binaries) as $offset => $bytes) {
                $this->doc->insertArrayBinary($this->name, $index + $offset, $bytes);
            }
        });
        $this->values = $this->doc->getArray($this->name)->toArray();
    }

    /**
     * @param list<string> $binaries
     */
    public function appendBinaries(array $binaries): void
    {
        $this->insertBinaries($this->length(), $binaries);
    }

    /**
     * @param list<string> $binaries
     */
    public function prependBinaries(array $binaries): void
    {
        $this->insertBinaries(0, $binaries);
    }

    /**
     * @param array<string, mixed> $opts
     */
    public function insertSubdoc(int $index, string $guid, array $opts = []): YSubdoc
    {
        $subdoc = $this->doc->insertArraySubdoc($this->name, $index, $guid, $opts);
        $this->values = $this->doc->getArray($this->name)->toArray();

        return $subdoc;
    }

    /**
     * @param array<string, mixed> $opts
     */
    public function appendSubdoc(string $guid, array $opts = []): YSubdoc
    {
        return $this->insertSubdoc($this->length(), $guid, $opts);
    }

    /**
     * @param array<string, mixed> $opts
     */
    public function prependSubdoc(string $guid, array $opts = []): YSubdoc
    {
        return $this->insertSubdoc(0, $guid, $opts);
    }

    /**
     * @param list<string|array{guid: string, opts?: array<string, mixed>}> $subdocs
     * @return list<YSubdoc>
     */
    public function insertSubdocs(int $index, array $subdocs): array
    {
        $this->assertInsertRange($index);
        $specs = self::normalizeSubdocSpecs($subdocs, 'YArray insertSubdocs expects each subdoc to be a GUID string or array with a string guid.');
        $inserted = [];

        $this->doc->transact(function () use ($index, $specs, &$inserted): void {
            foreach ($specs as $offset => $spec) {
                $inserted[] = $this->doc->insertArraySubdoc($this->name, $index + $offset, $spec['guid'], $spec['opts']);
            }
        });
        $this->values = $this->doc->getArray($this->name)->toArray();

        return $inserted;
    }

    /**
     * @param list<string|array{guid: string, opts?: array<string, mixed>}> $subdocs
     * @return list<YSubdoc>
     */
    public function appendSubdocs(array $subdocs): array
    {
        return $this->insertSubdocs($this->length(), $subdocs);
    }

    /**
     * @param list<string|array{guid: string, opts?: array<string, mixed>}> $subdocs
     * @return list<YSubdoc>
     */
    public function prependSubdocs(array $subdocs): array
    {
        return $this->insertSubdocs(0, $subdocs);
    }

    public function insertXmlElement(int $index, string $nodeName): YXmlElement
    {
        $element = $this->doc->insertXmlElementInArray($this->name, $index, $nodeName);
        $this->values = $this->doc->getArray($this->name)->toArray();

        return $element;
    }

    public function appendXmlElement(string $nodeName): YXmlElement
    {
        return $this->insertXmlElement($this->length(), $nodeName);
    }

    public function prependXmlElement(string $nodeName): YXmlElement
    {
        return $this->insertXmlElement(0, $nodeName);
    }

    /**
     * @param list<string> $nodeNames
     * @return list<YXmlElement>
     */
    public function insertXmlElements(int $index, array $nodeNames): array
    {
        $this->assertInsertRange($index);
        foreach ($nodeNames as $nodeName) {
            if (! is_string($nodeName)) {
                throw new \InvalidArgumentException('YArray insertXmlElements only supports string node names.');
            }
        }

        $elements = [];

        $this->doc->transact(function () use ($index, $nodeNames, &$elements): void {
            foreach (array_values($nodeNames) as $offset => $nodeName) {
                $elements[] = $this->doc->insertXmlElementInArray($this->name, $index + $offset, $nodeName);
            }
        });
        $this->values = $this->doc->getArray($this->name)->toArray();

        return $elements;
    }

    /**
     * @param list<string> $nodeNames
     * @return list<YXmlElement>
     */
    public function appendXmlElements(array $nodeNames): array
    {
        return $this->insertXmlElements($this->length(), $nodeNames);
    }

    /**
     * @param list<string> $nodeNames
     * @return list<YXmlElement>
     */
    public function prependXmlElements(array $nodeNames): array
    {
        return $this->insertXmlElements(0, $nodeNames);
    }

    public function insertXmlText(int $index, string $text = ''): YXmlText
    {
        $xmlText = $this->doc->insertXmlTextInArray($this->name, $index, $text);
        $this->values = $this->doc->getArray($this->name)->toArray();

        return $xmlText;
    }

    public function appendXmlText(string $text = ''): YXmlText
    {
        return $this->insertXmlText($this->length(), $text);
    }

    public function prependXmlText(string $text = ''): YXmlText
    {
        return $this->insertXmlText(0, $text);
    }

    /**
     * @param list<string> $texts
     * @return list<YXmlText>
     */
    public function insertXmlTexts(int $index, array $texts): array
    {
        $this->assertInsertRange($index);
        foreach ($texts as $text) {
            if (! is_string($text)) {
                throw new \InvalidArgumentException('YArray insertXmlTexts only supports string XML text content.');
            }
        }

        $xmlTexts = [];

        $this->doc->transact(function () use ($index, $texts, &$xmlTexts): void {
            foreach (array_values($texts) as $offset => $text) {
                $xmlTexts[] = $this->doc->insertXmlTextInArray($this->name, $index + $offset, $text);
            }
        });
        $this->values = $this->doc->getArray($this->name)->toArray();

        return $xmlTexts;
    }

    /**
     * @param list<string> $texts
     * @return list<YXmlText>
     */
    public function appendXmlTexts(array $texts): array
    {
        return $this->insertXmlTexts($this->length(), $texts);
    }

    /**
     * @param list<string> $texts
     * @return list<YXmlText>
     */
    public function prependXmlTexts(array $texts): array
    {
        return $this->insertXmlTexts(0, $texts);
    }

    public function insertXmlHook(int $index, string $hookName): YXmlHook
    {
        $hook = $this->doc->insertXmlHookInArray($this->name, $index, $hookName);
        $this->values = $this->doc->getArray($this->name)->toArray();

        return $hook;
    }

    public function appendXmlHook(string $hookName): YXmlHook
    {
        return $this->insertXmlHook($this->length(), $hookName);
    }

    public function prependXmlHook(string $hookName): YXmlHook
    {
        return $this->insertXmlHook(0, $hookName);
    }

    /**
     * @param list<string> $hookNames
     * @return list<YXmlHook>
     */
    public function insertXmlHooks(int $index, array $hookNames): array
    {
        $this->assertInsertRange($index);
        foreach ($hookNames as $hookName) {
            if (! is_string($hookName)) {
                throw new \InvalidArgumentException('YArray insertXmlHooks only supports string hook names.');
            }
        }

        $hooks = [];

        $this->doc->transact(function () use ($index, $hookNames, &$hooks): void {
            foreach (array_values($hookNames) as $offset => $hookName) {
                $hooks[] = $this->doc->insertXmlHookInArray($this->name, $index + $offset, $hookName);
            }
        });
        $this->values = $this->doc->getArray($this->name)->toArray();

        return $hooks;
    }

    /**
     * @param list<string> $hookNames
     * @return list<YXmlHook>
     */
    public function appendXmlHooks(array $hookNames): array
    {
        return $this->insertXmlHooks($this->length(), $hookNames);
    }

    /**
     * @param list<string> $hookNames
     * @return list<YXmlHook>
     */
    public function prependXmlHooks(array $hookNames): array
    {
        return $this->insertXmlHooks(0, $hookNames);
    }

    public function insertXmlFragment(int $index): YNestedXmlFragment
    {
        $fragment = $this->doc->insertXmlFragmentInArray($this->name, $index);
        $this->values = $this->doc->getArray($this->name)->toArray();

        return $fragment;
    }

    public function appendXmlFragment(): YNestedXmlFragment
    {
        return $this->insertXmlFragment($this->length());
    }

    public function prependXmlFragment(): YNestedXmlFragment
    {
        return $this->insertXmlFragment(0);
    }

    /**
     * @return list<YNestedXmlFragment>
     */
    public function insertXmlFragments(int $index, int $count): array
    {
        $this->assertInsertRange($index);
        if ($count < 0) {
            throw new \InvalidArgumentException('YArray XML fragment insertion count must be non-negative.');
        }

        $fragments = [];
        $this->doc->transact(function () use ($index, $count, &$fragments): void {
            for ($offset = 0; $offset < $count; $offset++) {
                $fragments[] = $this->doc->insertXmlFragmentInArray($this->name, $index + $offset);
            }
        });
        $this->values = $this->doc->getArray($this->name)->toArray();

        return $fragments;
    }

    /**
     * @return list<YNestedXmlFragment>
     */
    public function appendXmlFragments(int $count): array
    {
        return $this->insertXmlFragments($this->length(), $count);
    }

    /**
     * @return list<YNestedXmlFragment>
     */
    public function prependXmlFragments(int $count): array
    {
        return $this->insertXmlFragments(0, $count);
    }

    public function delete(int $index, int $length = 1): void
    {
        $this->doc->deleteArray($this->name, $index, $length);
        $this->values = $this->doc->getArray($this->name)->toArray();
    }

    /**
     * @param list<int> $indexes
     */
    public function deleteAll(array $indexes): void
    {
        $indexes = self::normalizeDeleteIndexes($indexes, $this->length(), 'YArray deleteAll only supports valid integer indexes.');

        $this->doc->transact(function () use ($indexes): void {
            foreach ($indexes as $index) {
                $this->doc->deleteArray($this->name, $index, 1);
            }
        });
        $this->values = $this->doc->getArray($this->name)->toArray();
    }

    public function relativePositionAt(int $index, int $assoc = 0): RelativePosition
    {
        return $this->doc->createRelativePosition($this->name, $index, $assoc, 'array');
    }

    public function insertArray(int $index): YNestedArray
    {
        $array = $this->doc->insertNestedArrayInArray($this->name, $index);
        $this->values = $this->doc->getArray($this->name)->toArray();

        return $array;
    }

    public function appendArray(): YNestedArray
    {
        return $this->insertArray($this->length());
    }

    public function prependArray(): YNestedArray
    {
        return $this->insertArray(0);
    }

    /**
     * @return list<YNestedArray>
     */
    public function insertArrays(int $index, int $count): array
    {
        $this->assertInsertRange($index);
        $this->assertNonNegativeInsertCount($count, 'YArray nested array insertion count must be non-negative.');

        $arrays = [];
        $this->doc->transact(function () use ($index, $count, &$arrays): void {
            for ($offset = 0; $offset < $count; $offset++) {
                $arrays[] = $this->doc->insertNestedArrayInArray($this->name, $index + $offset);
            }
        });
        $this->values = $this->doc->getArray($this->name)->toArray();

        return $arrays;
    }

    /**
     * @return list<YNestedArray>
     */
    public function appendArrays(int $count): array
    {
        return $this->insertArrays($this->length(), $count);
    }

    /**
     * @return list<YNestedArray>
     */
    public function prependArrays(int $count): array
    {
        return $this->insertArrays(0, $count);
    }

    public function insertMap(int $index): YNestedMap
    {
        $map = $this->doc->insertNestedMapInArray($this->name, $index);
        $this->values = $this->doc->getArray($this->name)->toArray();

        return $map;
    }

    public function appendMap(): YNestedMap
    {
        return $this->insertMap($this->length());
    }

    public function prependMap(): YNestedMap
    {
        return $this->insertMap(0);
    }

    /**
     * @return list<YNestedMap>
     */
    public function insertMaps(int $index, int $count): array
    {
        $this->assertInsertRange($index);
        $this->assertNonNegativeInsertCount($count, 'YArray nested map insertion count must be non-negative.');

        $maps = [];
        $this->doc->transact(function () use ($index, $count, &$maps): void {
            for ($offset = 0; $offset < $count; $offset++) {
                $maps[] = $this->doc->insertNestedMapInArray($this->name, $index + $offset);
            }
        });
        $this->values = $this->doc->getArray($this->name)->toArray();

        return $maps;
    }

    /**
     * @return list<YNestedMap>
     */
    public function appendMaps(int $count): array
    {
        return $this->insertMaps($this->length(), $count);
    }

    /**
     * @return list<YNestedMap>
     */
    public function prependMaps(int $count): array
    {
        return $this->insertMaps(0, $count);
    }

    public function insertText(int $index): YNestedText
    {
        $text = $this->doc->insertNestedTextInArray($this->name, $index);
        $this->values = $this->doc->getArray($this->name)->toArray();

        return $text;
    }

    public function appendText(): YNestedText
    {
        return $this->insertText($this->length());
    }

    public function prependText(): YNestedText
    {
        return $this->insertText(0);
    }

    /**
     * @return list<YNestedText>
     */
    public function insertTexts(int $index, int $count): array
    {
        $this->assertInsertRange($index);
        $this->assertNonNegativeInsertCount($count, 'YArray nested text insertion count must be non-negative.');

        $texts = [];
        $this->doc->transact(function () use ($index, $count, &$texts): void {
            for ($offset = 0; $offset < $count; $offset++) {
                $texts[] = $this->doc->insertNestedTextInArray($this->name, $index + $offset);
            }
        });
        $this->values = $this->doc->getArray($this->name)->toArray();

        return $texts;
    }

    /**
     * @return list<YNestedText>
     */
    public function appendTexts(int $count): array
    {
        return $this->insertTexts($this->length(), $count);
    }

    /**
     * @return list<YNestedText>
     */
    public function prependTexts(int $count): array
    {
        return $this->insertTexts(0, $count);
    }

    /**
     * @param callable(array<string, mixed>, YDoc): void $observer
     */
    public function observe(callable $observer): int
    {
        return $this->doc->observe($this->name, $observer, 'array');
    }

    /**
     * @param callable(array<string, mixed>, YDoc): void $observer
     */
    public function observeOnce(callable $observer): int
    {
        return $this->doc->observeOnce($this->name, $observer, 'array');
    }

    public function unobserve(int $observerId): void
    {
        $this->doc->unobserve($observerId);
    }

    /**
     * @param callable(list<array<string, mixed>>, self, array<string, mixed>): void $observer
     */
    public function observeDeep(callable $observer): int
    {
        return $this->doc->observeSharedTypeDeep($this->name, 'array', function (array $events, YDoc $doc, array $transaction) use ($observer): void {
            $observer($events, $this, $transaction);
        });
    }

    /**
     * @param callable(list<array<string, mixed>>, self, array<string, mixed>): void $observer
     */
    public function observeDeepOnce(callable $observer): int
    {
        $observerId = null;
        $observerId = $this->observeDeep(function (array $events, self $array, array $transaction) use (&$observerId, $observer): void {
            if ($observerId !== null) {
                $this->unobserveDeep($observerId);
            }

            $observer($events, $array, $transaction);
        });

        return $observerId;
    }

    public function unobserveDeep(int $observerId): void
    {
        $this->doc->unobserveSharedTypeDeep($observerId);
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

    private static function normalizeOffset(mixed $offset): int
    {
        if (! is_int($offset)) {
            throw new \InvalidArgumentException('YArray offsets must be integers.');
        }

        return $offset;
    }

    /**
     * @param list<int> $indexes
     * @return list<int>
     */
    private static function normalizeDeleteIndexes(array $indexes, int $length, string $message): array
    {
        $normalized = [];

        foreach ($indexes as $index) {
            if (! is_int($index) || $index < 0 || $index >= $length) {
                throw new \InvalidArgumentException($message);
            }

            $normalized[$index] = $index;
        }

        rsort($normalized, SORT_NUMERIC);

        return array_values($normalized);
    }

    /**
     * @return list<mixed>
     */
    private function currentValues(): array
    {
        return $this->doc->arrayValue($this->name);
    }

    private function assertInsertRange(int $index): void
    {
        if ($index < 0 || $index > $this->length()) {
            throw new \InvalidArgumentException('YArray insert index is out of bounds.');
        }
    }

    private function assertNonNegativeInsertCount(int $count, string $message): void
    {
        if ($count < 0) {
            throw new \InvalidArgumentException($message);
        }
    }

    /**
     * @param list<mixed> $subdocs
     * @return list<array{guid: string, opts: array<string, mixed>}>
     */
    private static function normalizeSubdocSpecs(array $subdocs, string $message): array
    {
        $specs = [];

        foreach (array_values($subdocs) as $subdoc) {
            if (is_string($subdoc)) {
                $specs[] = ['guid' => $subdoc, 'opts' => []];
                continue;
            }

            if (! is_array($subdoc) || ! is_string($subdoc['guid'] ?? null)) {
                throw new \InvalidArgumentException($message);
            }

            $opts = $subdoc['opts'] ?? [];
            if (! is_array($opts)) {
                throw new \InvalidArgumentException($message);
            }

            $specs[] = ['guid' => $subdoc['guid'], 'opts' => $opts];
        }

        return $specs;
    }
}
