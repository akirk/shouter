<?php

declare(strict_types=1);

namespace Yjs;

/**
 * @implements \ArrayAccess<int, self|YXmlText|YXmlHook|string|null>
 * @implements \IteratorAggregate<int, self|YXmlText|YXmlHook|string>
 */
final class YXmlElement implements \ArrayAccess, \Countable, \IteratorAggregate, \Stringable
{
    public function __construct(private readonly YDoc $doc, private readonly string $idKey, private readonly string $nodeName)
    {
    }

    public function idKey(): string
    {
        return $this->idKey;
    }

    public function nodeName(): string
    {
        return $this->nodeName;
    }

    public function toString(): string
    {
        return $this->doc->xmlElementValue($this->idKey);
    }

    public function toStringSnapshot(Snapshot $snapshot): string
    {
        return $this->doc->createDocFromSnapshot($snapshot)->xmlElementValue($this->idKey);
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
        return $this->doc->xmlElementLength($this->idKey);
    }

    public function lengthSnapshot(Snapshot $snapshot): int
    {
        return $this->snapshotElement($snapshot)->length();
    }

    public function count(): int
    {
        return $this->length();
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->toArray());
    }

    public function get(int $index): self|YXmlText|YXmlHook|string|null
    {
        return $this->doc->xmlElementChild($this->idKey, $index);
    }

    public function getSnapshot(int $index, Snapshot $snapshot): self|YXmlText|YXmlHook|string|null
    {
        return $this->snapshotElement($snapshot)->get($index);
    }

    public function firstChild(): self|YXmlText|YXmlHook|string|null
    {
        return $this->get(0);
    }

    public function firstChildSnapshot(Snapshot $snapshot): self|YXmlText|YXmlHook|string|null
    {
        return $this->getSnapshot(0, $snapshot);
    }

    public function nextSibling(): self|YXmlText|YXmlHook|null
    {
        return $this->doc->xmlNodeNextSibling($this->idKey);
    }

    public function nextSiblingSnapshot(Snapshot $snapshot): self|YXmlText|YXmlHook|null
    {
        return $this->doc->createDocFromSnapshot($snapshot)->xmlNodeNextSibling($this->idKey);
    }

    public function prevSibling(): self|YXmlText|YXmlHook|null
    {
        return $this->doc->xmlNodePreviousSibling($this->idKey);
    }

    public function prevSiblingSnapshot(Snapshot $snapshot): self|YXmlText|YXmlHook|null
    {
        return $this->doc->createDocFromSnapshot($snapshot)->xmlNodePreviousSibling($this->idKey);
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_int($offset) && $this->get($offset) !== null;
    }

    public function offsetGet(mixed $offset): self|YXmlText|YXmlHook|string|null
    {
        return $this->get(self::normalizeOffset($offset));
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $index = $offset === null ? $this->length() : self::normalizeOffset($offset);
        if (! is_string($value)) {
            throw new \InvalidArgumentException('YXmlElement offset assignment only supports string XML text content.');
        }

        if ($index < 0 || $index > $this->length()) {
            throw new \InvalidArgumentException('YXmlElement offset assignment index is out of bounds.');
        }

        $this->doc->transact(function () use ($index, $value): void {
            if ($index < $this->length()) {
                $this->delete($index, 1);
            }

            $this->insertText($index, $value);
        });
    }

    public function offsetUnset(mixed $offset): void
    {
        if (! is_int($offset) || $this->get($offset) === null) {
            return;
        }

        $this->delete($offset, 1);
    }

    /**
     * @return list<self|YXmlText|YXmlHook|string>
     */
    public function toArray(): array
    {
        $children = [];

        for ($index = 0; $index < $this->length(); $index++) {
            $child = $this->get($index);
            if ($child !== null) {
                $children[] = $child;
            }
        }

        return $children;
    }

    /**
     * @return list<self|YXmlText|YXmlHook|string>
     */
    public function toArraySnapshot(Snapshot $snapshot): array
    {
        return $this->snapshotElement($snapshot)->toArray();
    }

    /**
     * @return list<self|YXmlText|YXmlHook|string>
     */
    public function slice(int $start = 0, ?int $end = null): array
    {
        return self::sliceChildren($this->toArray(), $start, $end);
    }

    /**
     * @return list<self|YXmlText|YXmlHook|string>
     */
    public function sliceSnapshot(Snapshot $snapshot, int $start = 0, ?int $end = null): array
    {
        return self::sliceChildren($this->toArraySnapshot($snapshot), $start, $end);
    }

    /**
     * @param list<self|YXmlText|YXmlHook|string> $children
     * @return list<self|YXmlText|YXmlHook|string>
     */
    private static function sliceChildren(array $children, int $start, ?int $end): array
    {
        $count = count($children);
        $normalizedStart = self::normalizeSliceIndex($start, $count);
        $normalizedEnd = $end === null ? $count : self::normalizeSliceIndex($end, $count);

        return array_slice($children, $normalizedStart, max(0, $normalizedEnd - $normalizedStart));
    }

    public function querySelector(string $nodeName): ?self
    {
        return $this->querySelectorAll($nodeName)[0] ?? null;
    }

    /**
     * @param null|callable(self|YXmlText|YXmlHook|string): bool $filter
     * @return list<self|YXmlText|YXmlHook|string>
     */
    public function createTreeWalker(?callable $filter = null): array
    {
        return self::treeWalkerNodes($this->toArray(), $filter);
    }

    /**
     * @return list<self>
     */
    public function querySelectorAll(string $nodeName): array
    {
        $matches = [];
        $query = strtoupper($nodeName);

        foreach ($this->toArray() as $child) {
            if (! $child instanceof self) {
                continue;
            }

            if (strtoupper($child->nodeName()) === $query) {
                $matches[] = $child;
            }

            array_push($matches, ...$child->querySelectorAll($nodeName));
        }

        return $matches;
    }

    /**
     * @param list<self|YXmlText|YXmlHook|string> $children
     * @param null|callable(self|YXmlText|YXmlHook|string): bool $filter
     * @return list<self|YXmlText|YXmlHook|string>
     */
    private static function treeWalkerNodes(array $children, ?callable $filter): array
    {
        $nodes = [];

        foreach ($children as $child) {
            if ($filter === null || $filter($child)) {
                $nodes[] = $child;
            }

            if ($child instanceof self) {
                array_push($nodes, ...self::treeWalkerNodes($child->toArray(), $filter));
            }
        }

        return $nodes;
    }

    /**
     * @param callable(self|YXmlText|YXmlHook|string, int, self): void $callback
     */
    public function forEach(callable $callback): void
    {
        foreach ($this->toArray() as $index => $child) {
            $callback($child, $index, $this);
        }
    }

    /**
     * @template T
     * @param callable(self|YXmlText|YXmlHook|string, int, self): T $callback
     * @return list<T>
     */
    public function map(callable $callback): array
    {
        $mapped = [];

        foreach ($this->toArray() as $index => $child) {
            $mapped[] = $callback($child, $index, $this);
        }

        return $mapped;
    }

    /**
     * @param callable(self|YXmlText|YXmlHook|string, int, self): bool $callback
     * @return list<self|YXmlText|YXmlHook|string>
     */
    public function filter(callable $callback): array
    {
        $filtered = [];

        foreach ($this->toArray() as $index => $child) {
            if ($callback($child, $index, $this)) {
                $filtered[] = $child;
            }
        }

        return $filtered;
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

    public function getSharedType(string $key): YNestedArray|YNestedMap|YNestedText|YNestedXmlFragment|self|YXmlText|YXmlHook|null
    {
        return $this->doc->sharedTypeInXmlElementAttribute($this->idKey, $key);
    }

    public function getArray(string $key): ?YNestedArray
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof YNestedArray) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YXmlElement attribute "%s" is not a nested YArray.', $key));
    }

    public function getMap(string $key): ?YNestedMap
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof YNestedMap) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YXmlElement attribute "%s" is not a nested YMap.', $key));
    }

    public function getText(string $key): ?YNestedText
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof YNestedText) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YXmlElement attribute "%s" is not a nested YText.', $key));
    }

    public function getXmlElement(string $key): ?self
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof self) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YXmlElement attribute "%s" is not a YXmlElement.', $key));
    }

    public function getXmlText(string $key): ?YXmlText
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof YXmlText) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YXmlElement attribute "%s" is not a YXmlText.', $key));
    }

    public function getXmlHook(string $key): ?YXmlHook
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof YXmlHook) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YXmlElement attribute "%s" is not a YXmlHook.', $key));
    }

    public function getXmlFragment(string $key): ?YNestedXmlFragment
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof YNestedXmlFragment) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YXmlElement attribute "%s" is not a YXmlFragment.', $key));
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
        $keys = self::normalizeStringList($keys, 'YXmlElement setArrays only supports string keys.');
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
        $keys = self::normalizeStringList($keys, 'YXmlElement setMaps only supports string keys.');
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
        $keys = self::normalizeStringList($keys, 'YXmlElement setTexts only supports string keys.');
        $created = [];

        $this->doc->transact(function () use ($keys, &$created): void {
            foreach ($keys as $key) {
                $created[$key] = $this->doc->setNestedTextInXmlElementAttribute($this->idKey, $key);
            }
        });

        return $created;
    }

    public function setXmlElement(string $key, string $nodeName): self
    {
        return $this->doc->setXmlElementInXmlElementAttribute($this->idKey, $key, $nodeName);
    }

    /**
     * @param array<string, string> $elements
     * @return array<string, self>
     */
    public function setXmlElements(array $elements): array
    {
        self::assertStringMap($elements, 'YXmlElement setXmlElements only supports string node names.');
        $created = [];

        $this->doc->transact(function () use ($elements, &$created): void {
            foreach ($elements as $key => $nodeName) {
                $created[(string) $key] = $this->doc->setXmlElementInXmlElementAttribute($this->idKey, (string) $key, $nodeName);
            }
        });

        return $created;
    }

    public function setXmlText(string $key, string $text = ''): YXmlText
    {
        return $this->doc->setXmlTextInXmlElementAttribute($this->idKey, $key, $text);
    }

    /**
     * @param array<string, string> $texts
     * @return array<string, YXmlText>
     */
    public function setXmlTexts(array $texts): array
    {
        self::assertStringMap($texts, 'YXmlElement setXmlTexts only supports string XML text content.');
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
        self::assertStringMap($hooks, 'YXmlElement setXmlHooks only supports string hook names.');
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
        $keys = self::normalizeStringList($keys, 'YXmlElement setXmlFragments only supports string keys.');
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

    public function insertText(int $index, string $text): YXmlText
    {
        return $this->doc->insertXmlText($this->idKey, $index, $text);
    }

    public function insertTextAfter(self|YXmlText|YXmlHook|null $referenceNode, string $text): YXmlText
    {
        return $this->insertText($this->indexAfterReferenceNode($referenceNode), $text);
    }

    /**
     * @param list<string> $texts
     * @return list<YXmlText>
     */
    public function insertTextsAfter(self|YXmlText|YXmlHook|null $referenceNode, array $texts): array
    {
        return $this->insertTexts($this->indexAfterReferenceNode($referenceNode), $texts);
    }

    public function appendText(string $text): YXmlText
    {
        return $this->insertText($this->length(), $text);
    }

    public function append(string $text): YXmlText
    {
        return $this->appendText($text);
    }

    public function prependText(string $text): YXmlText
    {
        return $this->insertText(0, $text);
    }

    public function prepend(string $text): YXmlText
    {
        return $this->prependText($text);
    }

    /**
     * @param list<string> $texts
     * @return list<YXmlText>
     */
    public function insertTexts(int $index, array $texts): array
    {
        $count = $this->length();
        if ($index < 0 || $index > $count) {
            throw new \InvalidArgumentException('YXmlElement insert index is out of bounds.');
        }

        foreach ($texts as $text) {
            if (! is_string($text)) {
                throw new \InvalidArgumentException('YXmlElement insertTexts only supports string XML text content.');
            }
        }

        $xmlTexts = [];
        $this->doc->transact(function () use ($index, $texts, &$xmlTexts): void {
            foreach (array_values($texts) as $offset => $text) {
                $xmlTexts[] = $this->insertText($index + $offset, $text);
            }
        });

        return $xmlTexts;
    }

    /**
     * @param list<string> $texts
     * @return list<YXmlText>
     */
    public function appendTexts(array $texts): array
    {
        return $this->insertTexts($this->length(), $texts);
    }

    /**
     * @param list<string> $texts
     * @return list<YXmlText>
     */
    public function prependTexts(array $texts): array
    {
        return $this->insertTexts(0, $texts);
    }

    public function insertElement(int $index, string $nodeName): self
    {
        return $this->doc->insertXmlElementChild($this->idKey, $index, $nodeName);
    }

    public function insertElementAfter(self|YXmlText|YXmlHook|null $referenceNode, string $nodeName): self
    {
        return $this->insertElement($this->indexAfterReferenceNode($referenceNode), $nodeName);
    }

    public function appendElement(string $nodeName): self
    {
        return $this->insertElement($this->length(), $nodeName);
    }

    public function prependElement(string $nodeName): self
    {
        return $this->insertElement(0, $nodeName);
    }

    /**
     * @param list<string> $nodeNames
     * @return list<self>
     */
    public function insertElements(int $index, array $nodeNames): array
    {
        $count = $this->length();
        if ($index < 0 || $index > $count) {
            throw new \InvalidArgumentException('YXmlElement insert index is out of bounds.');
        }

        $elements = [];
        $this->doc->transact(function () use ($index, $nodeNames, &$elements): void {
            foreach (array_values($nodeNames) as $offset => $nodeName) {
                $elements[] = $this->insertElement($index + $offset, $nodeName);
            }
        });

        return $elements;
    }

    /**
     * @param list<string> $nodeNames
     * @return list<self>
     */
    public function appendElements(array $nodeNames): array
    {
        return $this->insertElements($this->length(), $nodeNames);
    }

    /**
     * @param list<string> $nodeNames
     * @return list<self>
     */
    public function prependElements(array $nodeNames): array
    {
        return $this->insertElements(0, $nodeNames);
    }

    public function insertHook(int $index, string $hookName): YXmlHook
    {
        return $this->doc->insertXmlHookChild($this->idKey, $index, $hookName);
    }

    public function insertHookAfter(self|YXmlText|YXmlHook|null $referenceNode, string $hookName): YXmlHook
    {
        return $this->insertHook($this->indexAfterReferenceNode($referenceNode), $hookName);
    }

    public function appendHook(string $hookName): YXmlHook
    {
        return $this->insertHook($this->length(), $hookName);
    }

    public function prependHook(string $hookName): YXmlHook
    {
        return $this->insertHook(0, $hookName);
    }

    /**
     * @param list<string> $hookNames
     * @return list<YXmlHook>
     */
    public function insertHooks(int $index, array $hookNames): array
    {
        $count = $this->length();
        if ($index < 0 || $index > $count) {
            throw new \InvalidArgumentException('YXmlElement insert index is out of bounds.');
        }

        $hooks = [];
        $this->doc->transact(function () use ($index, $hookNames, &$hooks): void {
            foreach (array_values($hookNames) as $offset => $hookName) {
                $hooks[] = $this->insertHook($index + $offset, $hookName);
            }
        });

        return $hooks;
    }

    /**
     * @param list<string> $hookNames
     * @return list<YXmlHook>
     */
    public function appendHooks(array $hookNames): array
    {
        return $this->insertHooks($this->length(), $hookNames);
    }

    /**
     * @param list<string> $hookNames
     * @return list<YXmlHook>
     */
    public function prependHooks(array $hookNames): array
    {
        return $this->insertHooks(0, $hookNames);
    }

    /**
     * @param list<string> $values
     */
    public function insert(int $index, array $values): void
    {
        $count = $this->length();
        if ($index < 0 || $index > $count) {
            throw new \InvalidArgumentException('YXmlElement insert index is out of bounds.');
        }

        $this->doc->transact(function () use ($index, $values): void {
            foreach (array_values($values) as $offset => $value) {
                if (! is_string($value)) {
                    throw new \InvalidArgumentException('YXmlElement insert only supports string XML text content.');
                }

                $this->insertText($index + $offset, $value);
            }
        });
    }

    /**
     * @param list<string> $values
     */
    public function insertAfter(self|YXmlText|YXmlHook|null $referenceNode, array $values): void
    {
        $this->insert($this->indexAfterReferenceNode($referenceNode), $values);
    }

    /**
     * @param list<string> $values
     */
    public function push(array $values): void
    {
        $this->insert($this->length(), $values);
    }

    /**
     * @param list<string> $values
     */
    public function unshift(array $values): void
    {
        $this->insert(0, $values);
    }

    public function pop(): self|YXmlText|YXmlHook|string|null
    {
        return $this->splice(-1, 1)[0] ?? null;
    }

    public function shift(): self|YXmlText|YXmlHook|string|null
    {
        return $this->splice(0, 1)[0] ?? null;
    }

    /**
     * @param list<string> $values
     * @return list<self|YXmlText|YXmlHook|string>
     */
    public function splice(int $index, int $deleteLength = 0, array $values = []): array
    {
        $children = $this->toArray();
        $count = count($children);
        $start = self::normalizeSpliceIndex($index, $count);
        $deleteLength = max(0, min($deleteLength, $count - $start));
        $deleted = array_slice($children, $start, $deleteLength);

        if ($deleteLength === 0 && $values === []) {
            return $deleted;
        }

        $this->doc->transact(function () use ($start, $deleteLength, $values): void {
            if ($deleteLength > 0) {
                $this->delete($start, $deleteLength);
            }

            foreach (array_values($values) as $offset => $value) {
                if (! is_string($value)) {
                    throw new \InvalidArgumentException('YXmlElement splice insertion only supports string XML text content.');
                }

                $this->insertText($start + $offset, $value);
            }
        });

        return $deleted;
    }

    public function delete(int $index, int $length = 1): void
    {
        $this->doc->deleteXmlElementChildren($this->idKey, $index, $length);
    }

    /**
     * @param list<int> $indexes
     */
    public function deleteAll(array $indexes): void
    {
        $indexes = self::normalizeDeleteIndexes($indexes, $this->length(), 'YXmlElement deleteAll only supports valid integer indexes.');

        $this->doc->transact(function () use ($indexes): void {
            foreach ($indexes as $index) {
                $this->doc->deleteXmlElementChildren($this->idKey, $index, 1);
            }
        });
    }

    public function relativePositionAt(int $index, int $assoc = 0): RelativePosition
    {
        return $this->doc->createRelativePositionForTypeId($this->idKey, $index, $assoc, 'array');
    }

    public function clear(): void
    {
        $this->delete(0, $this->length());
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
        $observerId = $this->observeDeep(function (array $events, self $element, array $transaction) use (&$observerId, $observer): void {
            if ($observerId !== null) {
                $this->unobserveDeep($observerId);
            }

            $observer($events, $element, $transaction);
        });

        return $observerId;
    }

    public function unobserveDeep(int $observerId): void
    {
        $this->doc->unobserveXmlNodeDeep($observerId);
    }

    private function snapshotElement(Snapshot $snapshot): self
    {
        return new self($this->doc->createDocFromSnapshot($snapshot), $this->idKey, $this->nodeName);
    }

    private static function normalizeOffset(mixed $offset): int
    {
        if (! is_int($offset)) {
            throw new \InvalidArgumentException('YXmlElement offsets must be integers.');
        }

        return $offset;
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

    private function indexAfterReferenceNode(self|YXmlText|YXmlHook|null $referenceNode): int
    {
        if ($referenceNode === null) {
            return 0;
        }

        $referenceId = $referenceNode->idKey();
        foreach ($this->toArray() as $index => $child) {
            if ($child instanceof self || $child instanceof YXmlText || $child instanceof YXmlHook) {
                if ($child->idKey() === $referenceId) {
                    return $index + 1;
                }
            }
        }

        throw new \InvalidArgumentException('YXmlElement reference node must be a direct child of the element.');
    }
}
