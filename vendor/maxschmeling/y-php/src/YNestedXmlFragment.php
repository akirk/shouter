<?php

declare(strict_types=1);

namespace Yjs;

/**
 * @implements \ArrayAccess<int, YXmlElement|YXmlText|YXmlHook|string|null>
 * @implements \IteratorAggregate<int, YXmlElement|YXmlText|YXmlHook|string>
 */
final class YNestedXmlFragment implements \ArrayAccess, \Countable, \IteratorAggregate, \Stringable
{
    public function __construct(private readonly YDoc $doc, private readonly string $idKey)
    {
    }

    public function idKey(): string
    {
        return $this->idKey;
    }

    public function toString(): string
    {
        return $this->doc->xmlElementValue($this->idKey);
    }

    public function toStringSnapshot(Snapshot $snapshot): string
    {
        return (string) new self($this->doc->createDocFromSnapshot($snapshot), $this->idKey);
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
        return $this->snapshotFragment($snapshot)->length();
    }

    public function count(): int
    {
        return $this->length();
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->toArray());
    }

    public function get(int $index): YXmlElement|YXmlText|YXmlHook|string|null
    {
        return $this->doc->xmlElementChild($this->idKey, $index);
    }

    public function getSnapshot(int $index, Snapshot $snapshot): YXmlElement|YXmlText|YXmlHook|string|null
    {
        return $this->snapshotFragment($snapshot)->get($index);
    }

    public function firstChild(): YXmlElement|YXmlText|YXmlHook|string|null
    {
        return $this->get(0);
    }

    public function firstChildSnapshot(Snapshot $snapshot): YXmlElement|YXmlText|YXmlHook|string|null
    {
        return $this->getSnapshot(0, $snapshot);
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_int($offset) && $this->get($offset) !== null;
    }

    public function offsetGet(mixed $offset): YXmlElement|YXmlText|YXmlHook|string|null
    {
        return $this->get(self::normalizeOffset($offset));
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $index = $offset === null ? $this->length() : self::normalizeOffset($offset);
        if (! is_string($value)) {
            throw new \InvalidArgumentException('YNestedXmlFragment offset assignment only supports string XML text content.');
        }

        if ($index < 0 || $index > $this->length()) {
            throw new \InvalidArgumentException('YNestedXmlFragment offset assignment index is out of bounds.');
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
     * @return list<YXmlElement|YXmlText|YXmlHook|string>
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
     * @return list<YXmlElement|YXmlText|YXmlHook|string>
     */
    public function toArraySnapshot(Snapshot $snapshot): array
    {
        return $this->snapshotFragment($snapshot)->toArray();
    }

    /**
     * @return list<YXmlElement|YXmlText|YXmlHook|string>
     */
    public function slice(int $start = 0, ?int $end = null): array
    {
        return self::sliceChildren($this->toArray(), $start, $end);
    }

    /**
     * @return list<YXmlElement|YXmlText|YXmlHook|string>
     */
    public function sliceSnapshot(Snapshot $snapshot, int $start = 0, ?int $end = null): array
    {
        return self::sliceChildren($this->toArraySnapshot($snapshot), $start, $end);
    }

    public function querySelector(string $nodeName): ?YXmlElement
    {
        return $this->querySelectorAll($nodeName)[0] ?? null;
    }

    /**
     * @param null|callable(YXmlElement|YXmlText|YXmlHook|string): bool $filter
     * @return list<YXmlElement|YXmlText|YXmlHook|string>
     */
    public function createTreeWalker(?callable $filter = null): array
    {
        return self::treeWalkerNodes($this->toArray(), $filter);
    }

    /**
     * @return list<YXmlElement>
     */
    public function querySelectorAll(string $nodeName): array
    {
        $matches = [];
        $query = strtoupper($nodeName);

        foreach ($this->toArray() as $child) {
            if (! $child instanceof YXmlElement) {
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
     * @param callable(YXmlElement|YXmlText|YXmlHook|string, int, self): void $callback
     */
    public function forEach(callable $callback): void
    {
        foreach ($this->toArray() as $index => $child) {
            $callback($child, $index, $this);
        }
    }

    /**
     * @template T
     * @param callable(YXmlElement|YXmlText|YXmlHook|string, int, self): T $callback
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
     * @param callable(YXmlElement|YXmlText|YXmlHook|string, int, self): bool $callback
     * @return list<YXmlElement|YXmlText|YXmlHook|string>
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

    public function insertElement(int $index, string $nodeName): YXmlElement
    {
        return $this->doc->insertXmlElementChild($this->idKey, $index, $nodeName);
    }

    public function insertElementAfter(YXmlElement|YXmlText|YXmlHook|null $referenceNode, string $nodeName): YXmlElement
    {
        return $this->insertElement($this->indexAfterReferenceNode($referenceNode), $nodeName);
    }

    public function appendElement(string $nodeName): YXmlElement
    {
        return $this->insertElement($this->length(), $nodeName);
    }

    public function prependElement(string $nodeName): YXmlElement
    {
        return $this->insertElement(0, $nodeName);
    }

    /**
     * @param list<string> $nodeNames
     * @return list<YXmlElement>
     */
    public function insertElements(int $index, array $nodeNames): array
    {
        $count = $this->length();
        if ($index < 0 || $index > $count) {
            throw new \InvalidArgumentException('YNestedXmlFragment insert index is out of bounds.');
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
     * @return list<YXmlElement>
     */
    public function appendElements(array $nodeNames): array
    {
        return $this->insertElements($this->length(), $nodeNames);
    }

    /**
     * @param list<string> $nodeNames
     * @return list<YXmlElement>
     */
    public function prependElements(array $nodeNames): array
    {
        return $this->insertElements(0, $nodeNames);
    }

    public function insertHook(int $index, string $hookName): YXmlHook
    {
        return $this->doc->insertXmlHookChild($this->idKey, $index, $hookName);
    }

    public function insertHookAfter(YXmlElement|YXmlText|YXmlHook|null $referenceNode, string $hookName): YXmlHook
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
            throw new \InvalidArgumentException('YNestedXmlFragment insert index is out of bounds.');
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

    public function insertText(int $index, string $text): YXmlText
    {
        return $this->doc->insertXmlText($this->idKey, $index, $text);
    }

    public function insertTextAfter(YXmlElement|YXmlText|YXmlHook|null $referenceNode, string $text): YXmlText
    {
        return $this->insertText($this->indexAfterReferenceNode($referenceNode), $text);
    }

    /**
     * @param list<string> $texts
     * @return list<YXmlText>
     */
    public function insertTextsAfter(YXmlElement|YXmlText|YXmlHook|null $referenceNode, array $texts): array
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
            throw new \InvalidArgumentException('YNestedXmlFragment insert index is out of bounds.');
        }

        foreach ($texts as $text) {
            if (! is_string($text)) {
                throw new \InvalidArgumentException('YNestedXmlFragment insertTexts only supports string XML text content.');
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

    /**
     * @param list<string> $values
     */
    public function insert(int $index, array $values): void
    {
        $count = $this->length();
        if ($index < 0 || $index > $count) {
            throw new \InvalidArgumentException('YNestedXmlFragment insert index is out of bounds.');
        }

        $this->doc->transact(function () use ($index, $values): void {
            foreach (array_values($values) as $offset => $value) {
                if (! is_string($value)) {
                    throw new \InvalidArgumentException('YNestedXmlFragment insert only supports string XML text content.');
                }

                $this->insertText($index + $offset, $value);
            }
        });
    }

    /**
     * @param list<string> $values
     */
    public function insertAfter(YXmlElement|YXmlText|YXmlHook|null $referenceNode, array $values): void
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

    public function pop(): YXmlElement|YXmlText|YXmlHook|string|null
    {
        return $this->splice(-1, 1)[0] ?? null;
    }

    public function shift(): YXmlElement|YXmlText|YXmlHook|string|null
    {
        return $this->splice(0, 1)[0] ?? null;
    }

    /**
     * @param list<string> $values
     * @return list<YXmlElement|YXmlText|YXmlHook|string>
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
                    throw new \InvalidArgumentException('YNestedXmlFragment splice insertion only supports string XML text content.');
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
        $observerId = $this->observeDeep(function (array $events, self $fragment, array $transaction) use (&$observerId, $observer): void {
            if ($observerId !== null) {
                $this->unobserveDeep($observerId);
            }

            $observer($events, $fragment, $transaction);
        });

        return $observerId;
    }

    public function unobserveDeep(int $observerId): void
    {
        $this->doc->unobserveXmlNodeDeep($observerId);
    }

    private function snapshotFragment(Snapshot $snapshot): self
    {
        return new self($this->doc->createDocFromSnapshot($snapshot), $this->idKey);
    }

    /**
     * @param list<YXmlElement|YXmlText|YXmlHook|string> $children
     * @return list<YXmlElement|YXmlText|YXmlHook|string>
     */
    private static function sliceChildren(array $children, int $start, ?int $end): array
    {
        $count = count($children);
        $normalizedStart = self::normalizeSliceIndex($start, $count);
        $normalizedEnd = $end === null ? $count : self::normalizeSliceIndex($end, $count);

        return array_slice($children, $normalizedStart, max(0, $normalizedEnd - $normalizedStart));
    }

    /**
     * @param list<YXmlElement|YXmlText|YXmlHook|string> $children
     * @param null|callable(YXmlElement|YXmlText|YXmlHook|string): bool $filter
     * @return list<YXmlElement|YXmlText|YXmlHook|string>
     */
    private static function treeWalkerNodes(array $children, ?callable $filter): array
    {
        $nodes = [];

        foreach ($children as $child) {
            if ($filter === null || $filter($child)) {
                $nodes[] = $child;
            }

            if ($child instanceof YXmlElement) {
                array_push($nodes, ...self::treeWalkerNodes($child->toArray(), $filter));
            }
        }

        return $nodes;
    }

    private static function normalizeOffset(mixed $offset): int
    {
        if (! is_int($offset)) {
            throw new \InvalidArgumentException('YNestedXmlFragment offsets must be integers.');
        }

        return $offset;
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

    private function indexAfterReferenceNode(YXmlElement|YXmlText|YXmlHook|null $referenceNode): int
    {
        if ($referenceNode === null) {
            return 0;
        }

        $referenceId = $referenceNode->idKey();
        foreach ($this->toArray() as $index => $child) {
            if ($child instanceof YXmlElement || $child instanceof YXmlText || $child instanceof YXmlHook) {
                if ($child->idKey() === $referenceId) {
                    return $index + 1;
                }
            }
        }

        throw new \InvalidArgumentException('YNestedXmlFragment reference node must be a direct child of the fragment.');
    }
}
