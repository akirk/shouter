<?php

declare(strict_types=1);

namespace Yjs;

/**
 * @implements \ArrayAccess<string, mixed>
 * @implements \IteratorAggregate<string, mixed>
 */
final class YMap implements \ArrayAccess, \Countable, \IteratorAggregate
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private readonly YDoc $doc, private readonly string $name, private array $values)
    {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function get(string $key): mixed
    {
        return $this->currentValues()[$key] ?? null;
    }

    public function getSnapshot(string $key, Snapshot $snapshot): mixed
    {
        return $this->doc->createDocFromSnapshot($snapshot)->getMap($this->name)->get($key);
    }

    public function getSharedType(string $key): YNestedArray|YNestedMap|YNestedText|YNestedXmlFragment|YXmlElement|YXmlText|YXmlHook|null
    {
        return $this->doc->sharedTypeInMap($this->name, $key);
    }

    public function getArray(string $key): ?YNestedArray
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof YNestedArray) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YMap value "%s" is not a nested YArray.', $key));
    }

    public function getMap(string $key): ?YNestedMap
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof YNestedMap) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YMap value "%s" is not a nested YMap.', $key));
    }

    public function getText(string $key): ?YNestedText
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof YNestedText) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YMap value "%s" is not a nested YText.', $key));
    }

    public function getXmlElement(string $key): ?YXmlElement
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof YXmlElement) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YMap value "%s" is not a YXmlElement.', $key));
    }

    public function getXmlText(string $key): ?YXmlText
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof YXmlText) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YMap value "%s" is not a YXmlText.', $key));
    }

    public function getXmlHook(string $key): ?YXmlHook
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof YXmlHook) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YMap value "%s" is not a YXmlHook.', $key));
    }

    public function getXmlFragment(string $key): ?YNestedXmlFragment
    {
        $type = $this->getSharedType($key);
        if ($type === null || $type instanceof YNestedXmlFragment) {
            return $type;
        }

        throw new \UnexpectedValueException(sprintf('YMap value "%s" is not a YXmlFragment.', $key));
    }

    public function getSubdoc(string $key): ?YSubdoc
    {
        $value = $this->get($key);
        if ($value === null || $value instanceof YSubdoc) {
            return $value;
        }

        throw new \UnexpectedValueException(sprintf('YMap value "%s" is not a subdoc.', $key));
    }

    public function hasSnapshot(string $key, Snapshot $snapshot): bool
    {
        return $this->doc->createDocFromSnapshot($snapshot)->getMap($this->name)->has($key);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAllSnapshot(Snapshot $snapshot): array
    {
        return $this->doc->createDocFromSnapshot($snapshot)->getMap($this->name)->toArray();
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
            throw new \InvalidArgumentException('YMap offsets must be strings or integers.');
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
        $this->doc->setMapValue($this->name, $key, $value);
        $this->values = $this->doc->getMap($this->name)->toArray();
    }

    /**
     * @param array<string, mixed> $values
     */
    public function setAll(array $values): void
    {
        $this->doc->transact(function () use ($values): void {
            foreach ($values as $key => $value) {
                $this->doc->setMapValue($this->name, (string) $key, $value);
            }
        });
        $this->values = $this->doc->getMap($this->name)->toArray();
    }

    public function setBinary(string $key, string $bytes): void
    {
        $this->doc->setMapBinary($this->name, $key, $bytes);
        $this->values = $this->doc->getMap($this->name)->toArray();
    }

    /**
     * @param array<string, string> $values
     */
    public function setBinaries(array $values): void
    {
        foreach ($values as $bytes) {
            if (! is_string($bytes)) {
                throw new \InvalidArgumentException('YMap setBinaries only supports binary strings.');
            }
        }

        $this->doc->transact(function () use ($values): void {
            foreach ($values as $key => $bytes) {
                $this->doc->setMapBinary($this->name, (string) $key, $bytes);
            }
        });
        $this->values = $this->doc->getMap($this->name)->toArray();
    }

    /**
     * @param array<string, mixed> $opts
     */
    public function setSubdoc(string $key, string $guid, array $opts = []): YSubdoc
    {
        $subdoc = $this->doc->setMapSubdoc($this->name, $key, $guid, $opts);
        $this->values = $this->doc->getMap($this->name)->toArray();

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
            'YMap setSubdocs expects each subdoc to be a GUID string or array with a string guid.'
        );
        $created = [];

        $this->doc->transact(function () use ($specs, &$created): void {
            foreach ($specs as $key => $spec) {
                $created[$key] = $this->doc->setMapSubdoc($this->name, $key, $spec['guid'], $spec['opts']);
            }
        });
        $this->values = $this->doc->getMap($this->name)->toArray();

        return $created;
    }

    public function delete(string $key): void
    {
        $this->doc->deleteMapKey($this->name, $key);
        $this->values = $this->doc->getMap($this->name)->toArray();
    }

    /**
     * @param list<string> $keys
     */
    public function deleteAll(array $keys): void
    {
        $this->doc->transact(function () use ($keys): void {
            foreach ($keys as $key) {
                $this->doc->deleteMapKey($this->name, $key);
            }
        });
        $this->values = $this->doc->getMap($this->name)->toArray();
    }

    public function clear(): void
    {
        $keys = array_keys($this->currentValues());

        $this->doc->transact(function () use ($keys): void {
            foreach ($keys as $key) {
                $this->doc->deleteMapKey($this->name, $key);
            }
        });
        $this->values = $this->doc->getMap($this->name)->toArray();
    }

    public function setArray(string $key): YNestedArray
    {
        $array = $this->doc->setNestedArrayInMap($this->name, $key);
        $this->values = $this->doc->getMap($this->name)->toArray();

        return $array;
    }

    /**
     * @param list<string> $keys
     * @return array<string, YNestedArray>
     */
    public function setArrays(array $keys): array
    {
        $keys = self::normalizeStringList($keys, 'YMap setArrays only supports string keys.');
        $created = [];

        $this->doc->transact(function () use ($keys, &$created): void {
            foreach ($keys as $key) {
                $created[$key] = $this->doc->setNestedArrayInMap($this->name, $key);
            }
        });
        $this->values = $this->doc->getMap($this->name)->toArray();

        return $created;
    }

    public function setMap(string $key): YNestedMap
    {
        $map = $this->doc->setNestedMapInMap($this->name, $key);
        $this->values = $this->doc->getMap($this->name)->toArray();

        return $map;
    }

    /**
     * @param list<string> $keys
     * @return array<string, YNestedMap>
     */
    public function setMaps(array $keys): array
    {
        $keys = self::normalizeStringList($keys, 'YMap setMaps only supports string keys.');
        $created = [];

        $this->doc->transact(function () use ($keys, &$created): void {
            foreach ($keys as $key) {
                $created[$key] = $this->doc->setNestedMapInMap($this->name, $key);
            }
        });
        $this->values = $this->doc->getMap($this->name)->toArray();

        return $created;
    }

    public function setText(string $key): YNestedText
    {
        $text = $this->doc->setNestedTextInMap($this->name, $key);
        $this->values = $this->doc->getMap($this->name)->toArray();

        return $text;
    }

    /**
     * @param list<string> $keys
     * @return array<string, YNestedText>
     */
    public function setTexts(array $keys): array
    {
        $keys = self::normalizeStringList($keys, 'YMap setTexts only supports string keys.');
        $created = [];

        $this->doc->transact(function () use ($keys, &$created): void {
            foreach ($keys as $key) {
                $created[$key] = $this->doc->setNestedTextInMap($this->name, $key);
            }
        });
        $this->values = $this->doc->getMap($this->name)->toArray();

        return $created;
    }

    public function setXmlElement(string $key, string $nodeName): YXmlElement
    {
        $element = $this->doc->setXmlElementInMap($this->name, $key, $nodeName);
        $this->values = $this->doc->getMap($this->name)->toArray();

        return $element;
    }

    /**
     * @param array<string, string> $elements
     * @return array<string, YXmlElement>
     */
    public function setXmlElements(array $elements): array
    {
        self::assertStringMap($elements, 'YMap setXmlElements only supports string node names.');
        $created = [];

        $this->doc->transact(function () use ($elements, &$created): void {
            foreach ($elements as $key => $nodeName) {
                $created[(string) $key] = $this->doc->setXmlElementInMap($this->name, (string) $key, $nodeName);
            }
        });
        $this->values = $this->doc->getMap($this->name)->toArray();

        return $created;
    }

    public function setXmlText(string $key, string $text = ''): YXmlText
    {
        $xmlText = $this->doc->setXmlTextInMap($this->name, $key, $text);
        $this->values = $this->doc->getMap($this->name)->toArray();

        return $xmlText;
    }

    /**
     * @param array<string, string> $texts
     * @return array<string, YXmlText>
     */
    public function setXmlTexts(array $texts): array
    {
        self::assertStringMap($texts, 'YMap setXmlTexts only supports string XML text content.');
        $created = [];

        $this->doc->transact(function () use ($texts, &$created): void {
            foreach ($texts as $key => $text) {
                $created[(string) $key] = $this->doc->setXmlTextInMap($this->name, (string) $key, $text);
            }
        });
        $this->values = $this->doc->getMap($this->name)->toArray();

        return $created;
    }

    public function setXmlHook(string $key, string $hookName): YXmlHook
    {
        $hook = $this->doc->setXmlHookInMap($this->name, $key, $hookName);
        $this->values = $this->doc->getMap($this->name)->toArray();

        return $hook;
    }

    /**
     * @param array<string, string> $hooks
     * @return array<string, YXmlHook>
     */
    public function setXmlHooks(array $hooks): array
    {
        self::assertStringMap($hooks, 'YMap setXmlHooks only supports string hook names.');
        $created = [];

        $this->doc->transact(function () use ($hooks, &$created): void {
            foreach ($hooks as $key => $hookName) {
                $created[(string) $key] = $this->doc->setXmlHookInMap($this->name, (string) $key, $hookName);
            }
        });
        $this->values = $this->doc->getMap($this->name)->toArray();

        return $created;
    }

    public function setXmlFragment(string $key): YNestedXmlFragment
    {
        $fragment = $this->doc->setXmlFragmentInMap($this->name, $key);
        $this->values = $this->doc->getMap($this->name)->toArray();

        return $fragment;
    }

    /**
     * @param list<string> $keys
     * @return array<string, YNestedXmlFragment>
     */
    public function setXmlFragments(array $keys): array
    {
        $keys = self::normalizeStringList($keys, 'YMap setXmlFragments only supports string keys.');
        $created = [];

        $this->doc->transact(function () use ($keys, &$created): void {
            foreach ($keys as $key) {
                $created[$key] = $this->doc->setXmlFragmentInMap($this->name, $key);
            }
        });
        $this->values = $this->doc->getMap($this->name)->toArray();

        return $created;
    }

    /**
     * @param callable(array<string, mixed>, YDoc): void $observer
     */
    public function observe(callable $observer): int
    {
        return $this->doc->observe($this->name, $observer, 'map');
    }

    /**
     * @param callable(array<string, mixed>, YDoc): void $observer
     */
    public function observeOnce(callable $observer): int
    {
        return $this->doc->observeOnce($this->name, $observer, 'map');
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
        return $this->doc->observeSharedTypeDeep($this->name, 'map', function (array $events, YDoc $doc, array $transaction) use ($observer): void {
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
        $this->doc->unobserveSharedTypeDeep($observerId);
    }

    /**
     * @return array<string, mixed>
     */
    private function currentValues(): array
    {
        return $this->doc->mapValue($this->name);
    }

    private static function normalizeOffset(mixed $offset): string
    {
        if (! is_string($offset) && ! is_int($offset)) {
            throw new \InvalidArgumentException('YMap offsets must be strings or integers.');
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
}
