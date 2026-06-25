<?php

declare(strict_types=1);

namespace Yjs;

/**
 * @implements \ArrayAccess<string, mixed>
 * @implements \IteratorAggregate<string, mixed>
 */
final class YNestedMap implements \ArrayAccess, \Countable, \IteratorAggregate
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private readonly YDoc $doc, private readonly string $idKey, private array $values)
    {
    }

    public function idKey(): string
    {
        return $this->idKey;
    }

    public function get(string $key): mixed
    {
        return $this->currentValues()[$key] ?? null;
    }

    public function getSnapshot(string $key, Snapshot $snapshot): mixed
    {
        return $this->getAllSnapshot($snapshot)[$key] ?? null;
    }

    public function getSharedType(string $key): YNestedArray|self|YNestedText|YNestedXmlFragment|YXmlElement|YXmlText|YXmlHook|null
    {
        return $this->doc->sharedTypeInNestedMap($this->idKey, $key);
    }

    public function getArray(string $key): ?YNestedArray
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof YNestedArray) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YNestedMap value "%s" is not a nested YArray.', $key));
    }

    public function getMap(string $key): ?self
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof self) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YNestedMap value "%s" is not a nested YMap.', $key));
    }

    public function getText(string $key): ?YNestedText
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof YNestedText) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YNestedMap value "%s" is not a nested YText.', $key));
    }

    public function getXmlElement(string $key): ?YXmlElement
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof YXmlElement) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YNestedMap value "%s" is not a YXmlElement.', $key));
    }

    public function getXmlText(string $key): ?YXmlText
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof YXmlText) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YNestedMap value "%s" is not a YXmlText.', $key));
    }

    public function getXmlHook(string $key): ?YXmlHook
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof YXmlHook) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YNestedMap value "%s" is not a YXmlHook.', $key));
    }

    public function getXmlFragment(string $key): ?YNestedXmlFragment
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof YNestedXmlFragment) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YNestedMap value "%s" is not a YXmlFragment.', $key));
    }

    public function getSubdoc(string $key): ?YSubdoc
    {
        $value = $this->get($key);
        if ($value === null || $value instanceof YSubdoc) {
            return $value;
        }

        throw new \UnexpectedValueException(sprintf('YNestedMap value "%s" is not a subdoc.', $key));
    }

    public function hasSnapshot(string $key, Snapshot $snapshot): bool
    {
        return array_key_exists($key, $this->getAllSnapshot($snapshot));
    }

    /**
     * @return array<string, mixed>
     */
    public function getAllSnapshot(Snapshot $snapshot): array
    {
        return $this->doc->createDocFromSnapshot($snapshot)->nestedMapValue($this->idKey);
    }

    /**
     * @return array<string, mixed>
     */
    public function toJSONSnapshot(Snapshot $snapshot): array
    {
        return $this->getAllSnapshot($snapshot);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->currentValues());
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
            throw new \InvalidArgumentException('YNestedMap offsets must be strings or integers.');
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

    public function size(): int
    {
        return count($this->currentValues());
    }

    public function sizeSnapshot(Snapshot $snapshot): int
    {
        return count($this->getAllSnapshot($snapshot));
    }

    public function count(): int
    {
        return $this->size();
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->currentValues());
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->currentValues());
    }

    /**
     * @return list<mixed>
     */
    public function values(): array
    {
        return array_values($this->currentValues());
    }

    /**
     * @return array<string, mixed>
     */
    public function entries(): array
    {
        return $this->currentValues();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->currentValues();
    }

    /**
     * @return array<string, mixed>
     */
    public function toJSON(): array
    {
        return $this->toArray();
    }

    /**
     * @param callable(mixed, string, self): void $callback
     */
    public function forEach(callable $callback): void
    {
        foreach ($this->currentValues() as $key => $value) {
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

        foreach ($this->currentValues() as $key => $value) {
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

        foreach ($this->currentValues() as $key => $value) {
            if ($callback($value, $key, $this)) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    public function set(string $key, mixed $value): void
    {
        $this->doc->setNestedMapValue($this->idKey, $key, $value);
        $this->values = $this->doc->nestedMapValue($this->idKey);
    }

    /**
     * @param array<string, mixed> $values
     */
    public function setAll(array $values): void
    {
        $this->doc->transact(function () use ($values): void {
            foreach ($values as $key => $value) {
                $this->doc->setNestedMapValue($this->idKey, (string) $key, $value);
            }
        });
        $this->values = $this->doc->nestedMapValue($this->idKey);
    }

    public function setBinary(string $key, string $bytes): void
    {
        $this->doc->setNestedMapBinary($this->idKey, $key, $bytes);
        $this->values = $this->doc->nestedMapValue($this->idKey);
    }

    /**
     * @param array<string, string> $values
     */
    public function setBinaries(array $values): void
    {
        foreach ($values as $bytes) {
            if (! is_string($bytes)) {
                throw new \InvalidArgumentException('YNestedMap setBinaries only supports binary strings.');
            }
        }

        $this->doc->transact(function () use ($values): void {
            foreach ($values as $key => $bytes) {
                $this->doc->setNestedMapBinary($this->idKey, (string) $key, $bytes);
            }
        });
        $this->values = $this->doc->nestedMapValue($this->idKey);
    }

    /**
     * @param array<string, mixed> $opts
     */
    public function setSubdoc(string $key, string $guid, array $opts = []): YSubdoc
    {
        $subdoc = $this->doc->setNestedMapSubdoc($this->idKey, $key, $guid, $opts);
        $this->values = $this->doc->nestedMapValue($this->idKey);

        return $subdoc;
    }

    /**
     * @param array<string, string|array{guid: string, opts?: array<string, mixed>}> $subdocs
     * @return array<string, YSubdoc>
     */
    public function setSubdocs(array $subdocs): array
    {
        $specs = self::normalizeSubdocMapSpecs(
            $subdocs,
            'YNestedMap setSubdocs expects each subdoc to be a GUID string or array with a string guid.'
        );
        $created = [];

        $this->doc->transact(function () use ($specs, &$created): void {
            foreach ($specs as $key => $spec) {
                $created[$key] = $this->doc->setNestedMapSubdoc($this->idKey, $key, $spec['guid'], $spec['opts']);
            }
        });
        $this->values = $this->doc->nestedMapValue($this->idKey);

        return $created;
    }

    public function setArray(string $key): YNestedArray
    {
        $array = $this->doc->setNestedArrayInNestedMap($this->idKey, $key);
        $this->values = $this->doc->nestedMapValue($this->idKey);

        return $array;
    }

    /**
     * @param list<string> $keys
     * @return array<string, YNestedArray>
     */
    public function setArrays(array $keys): array
    {
        $keys = self::normalizeStringList($keys, 'YNestedMap setArrays only supports string keys.');
        $created = [];

        $this->doc->transact(function () use ($keys, &$created): void {
            foreach ($keys as $key) {
                $created[$key] = $this->doc->setNestedArrayInNestedMap($this->idKey, $key);
            }
        });
        $this->values = $this->doc->nestedMapValue($this->idKey);

        return $created;
    }

    public function setMap(string $key): self
    {
        $map = $this->doc->setNestedMapInNestedMap($this->idKey, $key);
        $this->values = $this->doc->nestedMapValue($this->idKey);

        return $map;
    }

    /**
     * @param list<string> $keys
     * @return array<string, self>
     */
    public function setMaps(array $keys): array
    {
        $keys = self::normalizeStringList($keys, 'YNestedMap setMaps only supports string keys.');
        $created = [];

        $this->doc->transact(function () use ($keys, &$created): void {
            foreach ($keys as $key) {
                $created[$key] = $this->doc->setNestedMapInNestedMap($this->idKey, $key);
            }
        });
        $this->values = $this->doc->nestedMapValue($this->idKey);

        return $created;
    }

    public function setText(string $key): YNestedText
    {
        $text = $this->doc->setNestedTextInNestedMap($this->idKey, $key);
        $this->values = $this->doc->nestedMapValue($this->idKey);

        return $text;
    }

    /**
     * @param list<string> $keys
     * @return array<string, YNestedText>
     */
    public function setTexts(array $keys): array
    {
        $keys = self::normalizeStringList($keys, 'YNestedMap setTexts only supports string keys.');
        $created = [];

        $this->doc->transact(function () use ($keys, &$created): void {
            foreach ($keys as $key) {
                $created[$key] = $this->doc->setNestedTextInNestedMap($this->idKey, $key);
            }
        });
        $this->values = $this->doc->nestedMapValue($this->idKey);

        return $created;
    }

    public function setXmlElement(string $key, string $nodeName): YXmlElement
    {
        $element = $this->doc->setXmlElementInNestedMap($this->idKey, $key, $nodeName);
        $this->values = $this->doc->nestedMapValue($this->idKey);

        return $element;
    }

    /**
     * @param array<string, string> $elements
     * @return array<string, YXmlElement>
     */
    public function setXmlElements(array $elements): array
    {
        self::assertStringMap($elements, 'YNestedMap setXmlElements only supports string node names.');
        $created = [];

        $this->doc->transact(function () use ($elements, &$created): void {
            foreach ($elements as $key => $nodeName) {
                $created[(string) $key] = $this->doc->setXmlElementInNestedMap($this->idKey, (string) $key, $nodeName);
            }
        });
        $this->values = $this->doc->nestedMapValue($this->idKey);

        return $created;
    }

    public function setXmlText(string $key, string $text = ''): YXmlText
    {
        $xmlText = $this->doc->setXmlTextInNestedMap($this->idKey, $key, $text);
        $this->values = $this->doc->nestedMapValue($this->idKey);

        return $xmlText;
    }

    /**
     * @param array<string, string> $texts
     * @return array<string, YXmlText>
     */
    public function setXmlTexts(array $texts): array
    {
        self::assertStringMap($texts, 'YNestedMap setXmlTexts only supports string XML text content.');
        $created = [];

        $this->doc->transact(function () use ($texts, &$created): void {
            foreach ($texts as $key => $text) {
                $created[(string) $key] = $this->doc->setXmlTextInNestedMap($this->idKey, (string) $key, $text);
            }
        });
        $this->values = $this->doc->nestedMapValue($this->idKey);

        return $created;
    }

    public function setXmlHook(string $key, string $hookName): YXmlHook
    {
        $hook = $this->doc->setXmlHookInNestedMap($this->idKey, $key, $hookName);
        $this->values = $this->doc->nestedMapValue($this->idKey);

        return $hook;
    }

    /**
     * @param array<string, string> $hooks
     * @return array<string, YXmlHook>
     */
    public function setXmlHooks(array $hooks): array
    {
        self::assertStringMap($hooks, 'YNestedMap setXmlHooks only supports string hook names.');
        $created = [];

        $this->doc->transact(function () use ($hooks, &$created): void {
            foreach ($hooks as $key => $hookName) {
                $created[(string) $key] = $this->doc->setXmlHookInNestedMap($this->idKey, (string) $key, $hookName);
            }
        });
        $this->values = $this->doc->nestedMapValue($this->idKey);

        return $created;
    }

    public function setXmlFragment(string $key): YNestedXmlFragment
    {
        $fragment = $this->doc->setXmlFragmentInNestedMap($this->idKey, $key);
        $this->values = $this->doc->nestedMapValue($this->idKey);

        return $fragment;
    }

    /**
     * @param list<string> $keys
     * @return array<string, YNestedXmlFragment>
     */
    public function setXmlFragments(array $keys): array
    {
        $keys = self::normalizeStringList($keys, 'YNestedMap setXmlFragments only supports string keys.');
        $created = [];

        $this->doc->transact(function () use ($keys, &$created): void {
            foreach ($keys as $key) {
                $created[$key] = $this->doc->setXmlFragmentInNestedMap($this->idKey, $key);
            }
        });
        $this->values = $this->doc->nestedMapValue($this->idKey);

        return $created;
    }

    public function delete(string $key): void
    {
        $this->doc->deleteNestedMapKey($this->idKey, $key);
        $this->values = $this->doc->nestedMapValue($this->idKey);
    }

    /**
     * @param list<string> $keys
     */
    public function deleteAll(array $keys): void
    {
        $this->doc->transact(function () use ($keys): void {
            foreach ($keys as $key) {
                $this->doc->deleteNestedMapKey($this->idKey, $key);
            }
        });
        $this->values = $this->doc->nestedMapValue($this->idKey);
    }

    public function clear(): void
    {
        $keys = array_keys($this->currentValues());

        $this->doc->transact(function () use ($keys): void {
            foreach ($keys as $key) {
                $this->doc->deleteNestedMapKey($this->idKey, $key);
            }
        });
        $this->values = $this->doc->nestedMapValue($this->idKey);
    }

    /**
     * @param callable(array<string, mixed>, YDoc): void $observer
     */
    public function observe(callable $observer): int
    {
        return $this->doc->observeNestedType($this->idKey, 'map', $observer);
    }

    /**
     * @param callable(array<string, mixed>, YDoc): void $observer
     */
    public function observeOnce(callable $observer): int
    {
        return $this->doc->observeNestedTypeOnce($this->idKey, 'map', $observer);
    }

    public function unobserve(int $observerId): void
    {
        $this->doc->unobserveNestedType($observerId);
    }

    /**
     * @param callable(list<array<string, mixed>>, self, array<string, mixed>): void $observer
     */
    public function observeDeep(callable $observer): int
    {
        return $this->doc->observeNestedTypeDeep($this->idKey, function (array $events, YDoc $doc, array $transaction) use ($observer): void {
            $observer($events, $this, $transaction);
        });
    }

    /**
     * @param callable(list<array<string, mixed>>, self, array<string, mixed>): void $observer
     */
    public function observeDeepOnce(callable $observer): int
    {
        $observerId = null;
        $observerId = $this->observeDeep(function (array $events, self $map, array $transaction) use (&$observerId, $observer): void {
            if ($observerId !== null) {
                $this->unobserveDeep($observerId);
            }

            $observer($events, $map, $transaction);
        });

        return $observerId;
    }

    public function unobserveDeep(int $observerId): void
    {
        $this->doc->unobserveNestedTypeDeep($observerId);
    }

    /**
     * @return array<string, mixed>
     */
    private function currentValues(): array
    {
        return $this->doc->nestedMapValue($this->idKey);
    }

    /**
     * @param array<string, mixed> $subdocs
     * @return array<string, array{guid: string, opts: array<string, mixed>}>
     */
    private static function normalizeSubdocMapSpecs(array $subdocs, string $message): array
    {
        $specs = [];

        foreach ($subdocs as $key => $subdoc) {
            if (is_string($subdoc)) {
                $specs[(string) $key] = ['guid' => $subdoc, 'opts' => []];
                continue;
            }

            if (! is_array($subdoc) || ! is_string($subdoc['guid'] ?? null)) {
                throw new \InvalidArgumentException($message);
            }

            $opts = $subdoc['opts'] ?? [];
            if (! is_array($opts)) {
                throw new \InvalidArgumentException($message);
            }

            $specs[(string) $key] = ['guid' => $subdoc['guid'], 'opts' => $opts];
        }

        return $specs;
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

    private static function normalizeOffset(mixed $offset): string
    {
        if (! is_string($offset) && ! is_int($offset)) {
            throw new \InvalidArgumentException('YNestedMap offsets must be strings or integers.');
        }

        return (string) $offset;
    }
}
