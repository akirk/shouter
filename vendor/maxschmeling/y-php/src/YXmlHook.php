<?php

declare(strict_types=1);

namespace Yjs;

/**
 * @implements \ArrayAccess<string, mixed>
 * @implements \IteratorAggregate<string, mixed>
 */
final class YXmlHook implements \ArrayAccess, \Countable, \IteratorAggregate
{
    public function __construct(private readonly YDoc $doc, private readonly string $idKey, private readonly string $hookName)
    {
    }

    public function idKey(): string
    {
        return $this->idKey;
    }

    public function hookName(): string
    {
        return $this->hookName;
    }

    public function set(string $key, mixed $value): void
    {
        $this->doc->setXmlElementAttribute($this->idKey, $key, $value);
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->set($key, $value);
    }

    /**
     * @param array<string, mixed> $values
     */
    public function setAll(array $values): void
    {
        $this->doc->transact(function () use ($values): void {
            foreach ($values as $key => $value) {
                $this->doc->setXmlElementAttribute($this->idKey, (string) $key, $value);
            }
        });
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function setAttributes(array $attributes): void
    {
        $this->setAll($attributes);
    }

    public function get(string $key): mixed
    {
        return $this->doc->xmlElementAttribute($this->idKey, $key);
    }

    public function getAttribute(string $key): mixed
    {
        return $this->get($key);
    }

    public function getSharedType(string $key): YNestedArray|YNestedMap|YNestedText|YNestedXmlFragment|YXmlElement|YXmlText|self|null
    {
        return $this->doc->sharedTypeInXmlElementAttribute($this->idKey, $key);
    }

    public function getArray(string $key): ?YNestedArray
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof YNestedArray) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YXmlHook value "%s" is not a nested YArray.', $key));
    }

    public function getMap(string $key): ?YNestedMap
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof YNestedMap) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YXmlHook value "%s" is not a nested YMap.', $key));
    }

    public function getText(string $key): ?YNestedText
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof YNestedText) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YXmlHook value "%s" is not a nested YText.', $key));
    }

    public function getXmlElement(string $key): ?YXmlElement
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof YXmlElement) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YXmlHook value "%s" is not a YXmlElement.', $key));
    }

    public function getXmlText(string $key): ?YXmlText
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof YXmlText) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YXmlHook value "%s" is not a YXmlText.', $key));
    }

    public function getXmlHook(string $key): ?self
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof self) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YXmlHook value "%s" is not a YXmlHook.', $key));
    }

    public function getXmlFragment(string $key): ?YNestedXmlFragment
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof YNestedXmlFragment) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YXmlHook value "%s" is not a YXmlFragment.', $key));
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
        $keys = self::normalizeStringList($keys, 'YXmlHook setArrays only supports string keys.');
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
        $keys = self::normalizeStringList($keys, 'YXmlHook setMaps only supports string keys.');
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
        $keys = self::normalizeStringList($keys, 'YXmlHook setTexts only supports string keys.');
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
        self::assertStringMap($elements, 'YXmlHook setXmlElements only supports string node names.');
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
        self::assertStringMap($texts, 'YXmlHook setXmlTexts only supports string XML text content.');
        $created = [];

        $this->doc->transact(function () use ($texts, &$created): void {
            foreach ($texts as $key => $text) {
                $created[(string) $key] = $this->doc->setXmlTextInXmlElementAttribute($this->idKey, (string) $key, $text);
            }
        });

        return $created;
    }

    public function setXmlHook(string $key, string $hookName): self
    {
        return $this->doc->setXmlHookInXmlElementAttribute($this->idKey, $key, $hookName);
    }

    /**
     * @param array<string, string> $hooks
     * @return array<string, self>
     */
    public function setXmlHooks(array $hooks): array
    {
        self::assertStringMap($hooks, 'YXmlHook setXmlHooks only supports string hook names.');
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
        $keys = self::normalizeStringList($keys, 'YXmlHook setXmlFragments only supports string keys.');
        $created = [];

        $this->doc->transact(function () use ($keys, &$created): void {
            foreach ($keys as $key) {
                $created[$key] = $this->doc->setXmlFragmentInXmlElementAttribute($this->idKey, $key);
            }
        });

        return $created;
    }

    public function offsetExists(mixed $offset): bool
    {
        return $offset !== null && $this->has(self::normalizeOffset($offset));
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get(self::normalizeOffset($offset));
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            throw new \InvalidArgumentException('YXmlHook offsets must be strings or integers.');
        }

        $this->set(self::normalizeOffset($offset), $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        if ($offset === null) {
            return;
        }

        $this->delete(self::normalizeOffset($offset));
    }

    public function getSnapshot(string $key, Snapshot $snapshot): mixed
    {
        return $this->doc->createDocFromSnapshot($snapshot)->xmlElementAttribute($this->idKey, $key);
    }

    public function getAttributeSnapshot(string $key, Snapshot $snapshot): mixed
    {
        return $this->getSnapshot($key, $snapshot);
    }

    public function has(string $key): bool
    {
        return $this->doc->xmlElementHasAttribute($this->idKey, $key);
    }

    public function hasAttribute(string $key): bool
    {
        return $this->has($key);
    }

    public function hasSnapshot(string $key, Snapshot $snapshot): bool
    {
        return $this->doc->createDocFromSnapshot($snapshot)->xmlElementHasAttribute($this->idKey, $key);
    }

    public function hasAttributeSnapshot(string $key, Snapshot $snapshot): bool
    {
        return $this->hasSnapshot($key, $snapshot);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAllSnapshot(Snapshot $snapshot): array
    {
        return $this->doc->createDocFromSnapshot($snapshot)->xmlElementAttributes($this->idKey);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributesSnapshot(Snapshot $snapshot): array
    {
        return $this->getAllSnapshot($snapshot);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->doc->xmlElementAttributes($this->idKey);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArraySnapshot(Snapshot $snapshot): array
    {
        return $this->getAllSnapshot($snapshot);
    }

    public function size(): int
    {
        return count($this->toArray());
    }

    public function sizeSnapshot(Snapshot $snapshot): int
    {
        return count($this->getAllSnapshot($snapshot));
    }

    public function delete(string $key): void
    {
        $this->doc->removeXmlElementAttribute($this->idKey, $key);
    }

    public function removeAttribute(string $key): void
    {
        $this->delete($key);
    }

    /**
     * @param list<string> $keys
     */
    public function deleteAll(array $keys): void
    {
        $this->doc->transact(function () use ($keys): void {
            foreach ($keys as $key) {
                $this->doc->removeXmlElementAttribute($this->idKey, $key);
            }
        });
    }

    /**
     * @param list<string> $keys
     */
    public function removeAttributes(array $keys): void
    {
        $this->deleteAll($keys);
    }

    public function clear(): void
    {
        $keys = array_keys($this->toArray());

        $this->doc->transact(function () use ($keys): void {
            foreach ($keys as $key) {
                $this->delete($key);
            }
        });
    }

    public function count(): int
    {
        return $this->size();
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->toArray());
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->toArray());
    }

    /**
     * @return list<mixed>
     */
    public function values(): array
    {
        return array_values($this->toArray());
    }

    /**
     * @return array<string, mixed>
     */
    public function entries(): array
    {
        return $this->toArray();
    }

    /**
     * @param callable(mixed, string, self): void $callback
     */
    public function forEach(callable $callback): void
    {
        foreach ($this->toArray() as $key => $value) {
            $callback($value, $key, $this);
        }
    }

    /**
     * @template T
     * @param callable(mixed, string, self): T $callback
     * @return array<string, T>
     */
    public function map(callable $callback): array
    {
        $mapped = [];

        foreach ($this->toArray() as $key => $value) {
            $mapped[$key] = $callback($value, $key, $this);
        }

        return $mapped;
    }

    /**
     * @param callable(mixed, string, self): bool $callback
     * @return array<string, mixed>
     */
    public function filter(callable $callback): array
    {
        $filtered = [];

        foreach ($this->toArray() as $key => $value) {
            if ($callback($value, $key, $this)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
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
        $observerId = $this->observeDeep(function (array $events, self $hook, array $transaction) use (&$observerId, $observer): void {
            if ($observerId !== null) {
                $this->unobserveDeep($observerId);
            }

            $observer($events, $hook, $transaction);
        });

        return $observerId;
    }

    public function unobserveDeep(int $observerId): void
    {
        $this->doc->unobserveXmlNodeDeep($observerId);
    }

    public function nextSibling(): YXmlElement|YXmlText|self|null
    {
        return $this->doc->xmlNodeNextSibling($this->idKey);
    }

    public function nextSiblingSnapshot(Snapshot $snapshot): YXmlElement|YXmlText|self|null
    {
        return $this->doc->createDocFromSnapshot($snapshot)->xmlNodeNextSibling($this->idKey);
    }

    public function prevSibling(): YXmlElement|YXmlText|self|null
    {
        return $this->doc->xmlNodePreviousSibling($this->idKey);
    }

    public function prevSiblingSnapshot(Snapshot $snapshot): YXmlElement|YXmlText|self|null
    {
        return $this->doc->createDocFromSnapshot($snapshot)->xmlNodePreviousSibling($this->idKey);
    }

    public function toString(): string
    {
        return '[object Object]';
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * @return array<string, mixed>
     */
    public function toJSON(): array
    {
        return $this->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function toJSONSnapshot(Snapshot $snapshot): array
    {
        return $this->getAllSnapshot($snapshot);
    }

    private static function normalizeOffset(mixed $offset): string
    {
        if (! is_string($offset) && ! is_int($offset)) {
            throw new \InvalidArgumentException('YXmlHook offsets must be strings or integers.');
        }

        return (string) $offset;
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
}
