<?php

declare(strict_types=1);

namespace Yjs;

use Yjs\Update\DecodedUpdate;

final class UndoManager
{
    private const STACK_ITEM_ADDED = 'stack-item-added';
    private const STACK_ITEM_POPPED = 'stack-item-popped';
    private const STACK_CLEARED = 'stack-cleared';
    private const STACK_ITEM_UPDATED = 'stack-item-updated';

    /** @var list<string> */
    private array $scope;
    /** @var list<mixed> */
    private array $trackedOrigins;
    /** @var list<array<string, mixed>> */
    private array $undoStack = [];
    /** @var list<array<string, mixed>> */
    private array $redoStack = [];
    /** @var array<int, callable(array<string, mixed>, self): void> */
    private array $stackObservers = [];
    /** @var array<int, array{name: string, observer: callable, once: bool}> */
    private array $eventObservers = [];
    private int $observerId;
    private int $nextObserverId = 1;
    private bool $forceNewStackItem = false;
    private bool $applyingStackItem = false;
    private bool $destroyed = false;
    /** @var null|callable(array<string, mixed>): bool */
    private $captureTransaction;
    /** @var null|callable(array<string, mixed>): bool */
    private $deleteFilter;

    /**
     * @param string|list<string> $scope Root shared type name, or an empty list to track all root changes.
     * @param list<mixed>|null $trackedOrigins Origins to track. Defaults to Yjs-compatible `[null]`.
     * @param null|callable(array<string, mixed>): bool $captureTransaction Return false to skip capturing a transaction.
     * @param null|callable(array<string, mixed>): bool $deleteFilter Return false to keep inserted items during undo.
     */
    public function __construct(
        private readonly YDoc $doc,
        string|array $scope = [],
        ?array $trackedOrigins = null,
        private readonly int $captureTimeout = 500,
        ?callable $captureTransaction = null,
        ?callable $deleteFilter = null,
        private readonly bool $ignoreRemoteMapChanges = false
    )
    {
        $this->scope = is_string($scope) ? [$scope] : array_values($scope);
        $this->trackedOrigins = $trackedOrigins ?? [null];
        $this->addTrackedOrigin($this);
        $this->captureTransaction = $captureTransaction;
        $this->deleteFilter = $deleteFilter;
        $this->observerId = $doc->observeTransaction(function (array $event): void {
            $this->captureTransaction($event);
        });
    }

    public function canUndo(): bool
    {
        return $this->undoStack !== [];
    }

    public function canRedo(): bool
    {
        return $this->redoStack !== [];
    }

    public function undo(): bool
    {
        $item = array_pop($this->undoStack);
        if ($item === null) {
            return false;
        }

        if (! $this->shouldApplyUndoStackItem($item)) {
            return false;
        }

        $beforeUndo = $this->scopedStateForStackItem($item);
        $transactionEvent = null;
        $observerId = $this->doc->observeTransaction(static function (array $event) use (&$transactionEvent): void {
            $transactionEvent = $event;
        });
        $this->applyingStackItem = true;
        try {
            $this->doc->transact(function () use ($item): void {
                $this->restoreState($item['before'], $item['rootNames'], $item['after']);
                $this->restoreTextAttributeState($item['beforeTextAttributes'], $item['textAttributeNames']);
                $this->restoreNestedState($item['beforeNested'], $item['nestedIds'], $item['afterNested']);
                $this->restoreNestedTextAttributeState($item['beforeNestedTextAttributes'], $item['nestedTextAttributeIds']);
                $this->restoreXmlRootChildrenState($item['beforeXmlRootChildren'], $item['xmlRootNames']);
                $this->restoreXmlElementChildrenState($item['beforeXmlElementChildren'], $item['xmlElementChildIds']);
                $this->restoreXmlTextState($item['beforeXmlText'], $item['xmlTextIds'], $item['afterXmlText']);
                $this->restoreXmlAttributeState($item['beforeXmlAttributes'], $item['xmlAttributeIds']);
            }, $this);
        } finally {
            $this->applyingStackItem = false;
            $this->doc->unobserveTransaction($observerId);
        }
        if ($beforeUndo === $this->scopedStateForStackItem($item)) {
            return false;
        }

        if (! $this->destroyed) {
            $stackMeta = ['emitterOrigin' => $this] + $this->stackTransactionMeta($transactionEvent);
            $this->redoStack[] = $item;
            $this->notifyStackObservers(self::STACK_ITEM_ADDED, 'redo', $item, stackMeta: $stackMeta);
            $this->notifyStackObservers(self::STACK_ITEM_POPPED, 'undo', $item, stackMeta: $stackMeta);
        }

        return true;
    }

    public function redo(): bool
    {
        $item = array_pop($this->redoStack);
        if ($item === null) {
            return false;
        }

        $transactionEvent = null;
        $observerId = $this->doc->observeTransaction(static function (array $event) use (&$transactionEvent): void {
            $transactionEvent = $event;
        });
        $this->applyingStackItem = true;
        try {
            $this->doc->transact(function () use ($item): void {
                $this->restoreState($item['after'], $item['rootNames'], $item['before']);
                $this->restoreTextAttributeState($item['afterTextAttributes'], $item['textAttributeNames']);
                $this->restoreNestedState($item['afterNested'], $item['nestedIds'], $item['beforeNested']);
                $this->restoreNestedTextAttributeState($item['afterNestedTextAttributes'], $item['nestedTextAttributeIds']);
                $this->restoreXmlRootChildrenState($item['afterXmlRootChildren'], $item['xmlRootNames']);
                $this->restoreXmlElementChildrenState($item['afterXmlElementChildren'], $item['xmlElementChildIds']);
                $this->restoreXmlTextState($item['afterXmlText'], $item['xmlTextIds'], $item['beforeXmlText']);
                $this->restoreXmlAttributeState($item['afterXmlAttributes'], $item['xmlAttributeIds']);
            }, $this);
        } finally {
            $this->applyingStackItem = false;
            $this->doc->unobserveTransaction($observerId);
        }
        if (! $this->destroyed) {
            $stackMeta = ['emitterOrigin' => $this] + $this->stackTransactionMeta($transactionEvent);
            $this->undoStack[] = $item;
            $this->notifyStackObservers(self::STACK_ITEM_ADDED, 'undo', $item, stackMeta: $stackMeta);
            $this->notifyStackObservers(self::STACK_ITEM_POPPED, 'redo', $item, stackMeta: $stackMeta);
        }

        return true;
    }

    public function stopCapturing(): void
    {
        $this->forceNewStackItem = true;
    }

    public function clear(bool $clearUndoStack = true, bool $clearRedoStack = true): void
    {
        $undoStackCleared = $clearUndoStack && $this->undoStack !== [];
        $redoStackCleared = $clearRedoStack && $this->redoStack !== [];
        if ($clearUndoStack) {
            $this->undoStack = [];
        }
        if ($clearRedoStack) {
            $this->redoStack = [];
        }
        $this->forceNewStackItem = false;
        if ($undoStackCleared || $redoStackCleared) {
            $this->notifyStackObservers(self::STACK_CLEARED, stackMeta: [
                'undoStackCleared' => $clearUndoStack,
                'redoStackCleared' => $clearRedoStack,
            ]);
        }
    }

    public function addTrackedOrigin(mixed $origin): void
    {
        if (! in_array($origin, $this->trackedOrigins, true)) {
            $this->trackedOrigins[] = $origin;
        }
    }

    /**
     * @param string|list<string> $scope Root shared type names or nested shared type IDs to add.
     */
    public function addToScope(string|array $scope): void
    {
        foreach (is_string($scope) ? [$scope] : $scope as $name) {
            if (! is_string($name) || in_array($name, $this->scope, true)) {
                continue;
            }

            $this->scope[] = $name;
        }
    }

    public function removeTrackedOrigin(mixed $origin): void
    {
        $this->trackedOrigins = array_values(array_filter(
            $this->trackedOrigins,
            static fn (mixed $trackedOrigin): bool => $trackedOrigin !== $origin
        ));
    }

    public function deleteTrackedOrigin(mixed $origin): void
    {
        $this->removeTrackedOrigin($origin);
    }

    /**
     * @return list<mixed>
     */
    public function trackedOrigins(): array
    {
        return array_values(array_filter(
            $this->trackedOrigins,
            fn (mixed $origin): bool => $origin !== $this
        ));
    }

    public function destroy(): void
    {
        if ($this->destroyed) {
            return;
        }

        $this->doc->unobserveTransaction($this->observerId);
        $this->stackObservers = [];
        $this->eventObservers = [];
        $this->destroyed = true;
    }

    /**
     * @param callable(array<string, mixed>, self): void $observer
     */
    public function observeStack(callable $observer): int
    {
        $observerId = $this->nextObserverId++;
        $this->stackObservers[$observerId] = $observer;

        return $observerId;
    }

    public function unobserveStack(int $observerId): void
    {
        unset($this->stackObservers[$observerId]);
    }

    /**
     * @param callable(mixed ...$arguments): void $observer
     */
    public function on(string $eventName, callable $observer): int
    {
        return $this->addEventObserver($eventName, $observer, false);
    }

    /**
     * @param callable(mixed ...$arguments): void $observer
     */
    public function once(string $eventName, callable $observer): int
    {
        return $this->addEventObserver($eventName, $observer, true);
    }

    /**
     * @param int|callable(mixed ...$arguments): void $observer
     */
    public function off(string $eventName, int|callable $observer): void
    {
        if (is_int($observer)) {
            if (($this->eventObservers[$observer]['name'] ?? null) === $eventName) {
                unset($this->eventObservers[$observer]);
            }

            return;
        }

        foreach ($this->eventObservers as $observerId => $entry) {
            if ($entry['name'] === $eventName && $entry['observer'] === $observer) {
                unset($this->eventObservers[$observerId]);
            }
        }
    }

    /**
     * @param list<mixed> $arguments
     */
    public function emit(string $eventName, array $arguments = []): void
    {
        foreach ($this->eventObservers as $observerId => $entry) {
            if ($entry['name'] !== $eventName) {
                continue;
            }

            if ($entry['once']) {
                unset($this->eventObservers[$observerId]);
            }

            ($entry['observer'])(...$arguments);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function undoStack(): array
    {
        return $this->undoStack;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function redoStack(): array
    {
        return $this->redoStack;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function captureTransaction(array $event): void
    {
        if (($event['local'] ?? false) !== true || $this->applyingStackItem) {
            return;
        }

        if (! $this->originIsTracked($event['origin'] ?? null)) {
            return;
        }

        if ($this->captureTransaction !== null && ($this->captureTransaction)($event) === false) {
            return;
        }

        $changed = isset($event['changed']) && is_array($event['changed']) ? $event['changed'] : [];
        $changedNested = isset($event['changedNestedTypes']) && is_array($event['changedNestedTypes']) ? $event['changedNestedTypes'] : [];
        $changedXmlNodes = isset($event['changedXmlNodes']) && is_array($event['changedXmlNodes']) ? $event['changedXmlNodes'] : [];
        $changedRootNames = $this->scope === []
            ? array_values(array_filter($changed, 'is_string'))
            : array_values(array_intersect($this->scope, array_filter($changed, 'is_string')));
        $xmlRootNames = $this->changedXmlRootNames($changedRootNames, $event);
        $rootNames = array_values(array_diff($changedRootNames, $xmlRootNames));
        $scopeLocations = $this->scope === [] ? [] : $this->scopeLocations($event);
        $nestedIds = $this->scope === []
            ? array_values(array_filter($changedNested, 'is_string'))
            : $this->scopedNestedIds(array_values(array_filter($changedNested, 'is_string')), $scopeLocations);
        $changedXmlNodeIds = $this->scope === []
            ? array_values(array_filter($changedXmlNodes, 'is_string'))
            : $this->scopedXmlNodeIds(array_values(array_filter($changedXmlNodes, 'is_string')), $scopeLocations);
        $xmlElementChildIds = $this->changedXmlElementChildIds($changedXmlNodeIds, $event);
        $xmlTextIds = $this->xmlTextIdsOutsideElementChildRestores(
            $this->scope === []
                ? array_values(array_diff($changedXmlNodeIds, $xmlElementChildIds))
                : $changedXmlNodeIds,
            $xmlElementChildIds
        );
        $xmlAttributeIds = $changedXmlNodeIds;

        if ($rootNames === [] && $nestedIds === [] && $xmlRootNames === [] && $xmlElementChildIds === [] && $xmlTextIds === [] && $xmlAttributeIds === []) {
            return;
        }

        $before = $this->selectState($event['before'] ?? [], $rootNames);
        $after = $this->selectState($event['after'] ?? [], $rootNames);
        $beforeTextAttributes = $this->selectTextAttributeState($event['beforeTextAttributes'] ?? [], $rootNames);
        $afterTextAttributes = $this->selectTextAttributeState($event['afterTextAttributes'] ?? [], $rootNames);
        $textAttributeNames = $this->changedTextAttributeNames($beforeTextAttributes, $afterTextAttributes);
        $beforeNested = $this->selectState($event['beforeNested'] ?? [], $nestedIds);
        $afterNested = $this->selectState($event['afterNested'] ?? [], $nestedIds);
        $beforeNestedTextAttributes = $this->selectTextAttributeState($event['beforeNestedTextAttributes'] ?? [], $nestedIds);
        $afterNestedTextAttributes = $this->selectTextAttributeState($event['afterNestedTextAttributes'] ?? [], $nestedIds);
        $nestedTextAttributeIds = $this->changedTextAttributeNames($beforeNestedTextAttributes, $afterNestedTextAttributes);
        $beforeXmlRootChildren = $this->selectListState($event['beforeXmlRootSnapshots'] ?? [], $xmlRootNames);
        $afterXmlRootChildren = $this->selectListState($event['afterXmlRootSnapshots'] ?? [], $xmlRootNames);
        $beforeXmlElementChildren = $this->selectListState($event['beforeXmlElementSnapshots'] ?? [], $xmlElementChildIds);
        $afterXmlElementChildren = $this->selectListState($event['afterXmlElementSnapshots'] ?? [], $xmlElementChildIds);
        $beforeXmlText = $this->selectStringState($event['beforeXmlText'] ?? [], $xmlTextIds);
        $afterXmlText = $this->selectStringState($event['afterXmlText'] ?? [], $xmlTextIds);
        $beforeXmlAttributes = $this->selectMapState($event['beforeXmlAttributes'] ?? [], $xmlAttributeIds);
        $afterXmlAttributes = $this->selectMapState($event['afterXmlAttributes'] ?? [], $xmlAttributeIds);
        if (
            $before === $after
            && $beforeTextAttributes === $afterTextAttributes
            && $beforeNested === $afterNested
            && $beforeNestedTextAttributes === $afterNestedTextAttributes
            && $beforeXmlRootChildren === $afterXmlRootChildren
            && $beforeXmlElementChildren === $afterXmlElementChildren
            && $beforeXmlText === $afterXmlText
            && $beforeXmlAttributes === $afterXmlAttributes
        ) {
            return;
        }

        $now = microtime(true);
        $item = [
            'rootNames' => $rootNames,
            'nestedIds' => $nestedIds,
            'xmlRootNames' => $xmlRootNames,
            'xmlElementChildIds' => $xmlElementChildIds,
            'xmlTextIds' => $xmlTextIds,
            'xmlAttributeIds' => $xmlAttributeIds,
            'textAttributeNames' => $textAttributeNames,
            'nestedTextAttributeIds' => $nestedTextAttributeIds,
            'before' => $before,
            'after' => $after,
            'beforeTextAttributes' => $this->selectTextAttributeState($beforeTextAttributes, $textAttributeNames),
            'afterTextAttributes' => $this->selectTextAttributeState($afterTextAttributes, $textAttributeNames),
            'beforeNested' => $beforeNested,
            'afterNested' => $afterNested,
            'beforeNestedTextAttributes' => $this->selectTextAttributeState($beforeNestedTextAttributes, $nestedTextAttributeIds),
            'afterNestedTextAttributes' => $this->selectTextAttributeState($afterNestedTextAttributes, $nestedTextAttributeIds),
            'beforeXmlRootChildren' => $beforeXmlRootChildren,
            'afterXmlRootChildren' => $afterXmlRootChildren,
            'beforeXmlElementChildren' => $beforeXmlElementChildren,
            'afterXmlElementChildren' => $afterXmlElementChildren,
            'beforeXmlText' => $beforeXmlText,
            'afterXmlText' => $afterXmlText,
            'beforeXmlAttributes' => $beforeXmlAttributes,
            'afterXmlAttributes' => $afterXmlAttributes,
            'insertedStructs' => $this->insertedStructsFromTransaction($event),
            'origin' => $event['origin'] ?? null,
            'createdAt' => $now,
        ];
        $merged = false;
        $lastIndex = count($this->undoStack) - 1;

        if ($lastIndex >= 0 && $this->shouldMergeWithPreviousStackItem($now)) {
            $this->undoStack[$lastIndex] = $this->mergeStackItems($this->undoStack[$lastIndex], $item, $now);
            $item = $this->undoStack[$lastIndex];
            $merged = true;
        } else {
            $this->undoStack[] = $item;
        }

        $this->forceNewStackItem = false;
        $this->redoStack = [];
        $this->notifyStackObservers(
            $merged ? self::STACK_ITEM_UPDATED : self::STACK_ITEM_ADDED,
            'undo',
            $item,
            $merged,
            $this->stackTransactionMeta($event)
        );
    }

    private function shouldMergeWithPreviousStackItem(float $now): bool
    {
        if ($this->forceNewStackItem || $this->captureTimeout <= 0) {
            return false;
        }

        $previous = $this->undoStack[array_key_last($this->undoStack)] ?? null;
        if ($previous === null) {
            return false;
        }

        return (($now - $previous['createdAt']) * 1000) < $this->captureTimeout;
    }

    private function originIsTracked(mixed $origin): bool
    {
        if (in_array($origin, $this->trackedOrigins, true)) {
            return true;
        }

        return is_object($origin) && in_array($origin::class, $this->trackedOrigins, true);
    }

    /**
     * @param list<string> $changedRootNames
     * @param array<string, mixed> $event
     * @return list<string>
     */
    private function changedXmlRootNames(array $changedRootNames, array $event): array
    {
        $before = isset($event['beforeXmlRootChildren']) && is_array($event['beforeXmlRootChildren']) ? $event['beforeXmlRootChildren'] : [];
        $after = isset($event['afterXmlRootChildren']) && is_array($event['afterXmlRootChildren']) ? $event['afterXmlRootChildren'] : [];
        $names = [];

        foreach ($changedRootNames as $name) {
            if (array_key_exists($name, $before) && array_key_exists($name, $after) && $before[$name] !== $after[$name]) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param list<string> $changedXmlNodeIds
     * @param array<string, mixed> $event
     * @return list<string>
     */
    private function changedXmlElementChildIds(array $changedXmlNodeIds, array $event): array
    {
        $before = isset($event['beforeXmlElementChildren']) && is_array($event['beforeXmlElementChildren']) ? $event['beforeXmlElementChildren'] : [];
        $after = isset($event['afterXmlElementChildren']) && is_array($event['afterXmlElementChildren']) ? $event['afterXmlElementChildren'] : [];
        $idKeys = [];

        foreach ($changedXmlNodeIds as $idKey) {
            if (array_key_exists($idKey, $before) && array_key_exists($idKey, $after) && $before[$idKey] !== $after[$idKey]) {
                $idKeys[] = $idKey;
            }
        }

        return $idKeys;
    }

    /**
     * @return list<array{root: string, path: list<int|string>}>
     */
    private function scopeLocations(array $event): array
    {
        $locations = [];
        $xmlRoots = array_unique([
            ...array_keys(is_array($event['beforeXmlRootSnapshots'] ?? null) ? $event['beforeXmlRootSnapshots'] : []),
            ...array_keys(is_array($event['afterXmlRootSnapshots'] ?? null) ? $event['afterXmlRootSnapshots'] : []),
        ]);

        foreach ($this->scope as $scope) {
            if (! is_string($scope)) {
                continue;
            }

            if (in_array($scope, $xmlRoots, true)) {
                $locations[] = ['root' => $scope, 'path' => []];
                continue;
            }

            $location = $this->doc->xmlTypeLocation($scope) ?? $this->doc->sharedTypeLocation($scope);
            if ($location !== null) {
                $locations[] = $location;
            }
        }

        return $locations;
    }

    /**
     * @param list<string> $idKeys
     * @param list<array{root: string, path: list<int|string>}> $scopeLocations
     * @return list<string>
     */
    private function scopedNestedIds(array $idKeys, array $scopeLocations): array
    {
        return array_values(array_filter($idKeys, function (string $idKey) use ($scopeLocations): bool {
            return in_array($idKey, $this->scope, true)
                || $this->locationIsInScope($this->doc->sharedTypeLocation($idKey), $scopeLocations);
        }));
    }

    /**
     * @param list<string> $idKeys
     * @param list<array{root: string, path: list<int|string>}> $scopeLocations
     * @return list<string>
     */
    private function scopedXmlNodeIds(array $idKeys, array $scopeLocations): array
    {
        return array_values(array_filter($idKeys, function (string $idKey) use ($scopeLocations): bool {
            return in_array($idKey, $this->scope, true)
                || $this->locationIsInScope($this->doc->xmlTypeLocation($idKey), $scopeLocations);
        }));
    }

    /**
     * @param list<string> $idKeys
     * @param list<string> $xmlElementChildIds
     * @return list<string>
     */
    private function xmlTextIdsOutsideElementChildRestores(array $idKeys, array $xmlElementChildIds): array
    {
        $ancestorLocations = [];
        foreach ($xmlElementChildIds as $idKey) {
            $location = $this->doc->xmlTypeLocation($idKey);
            if ($location !== null) {
                $ancestorLocations[$idKey] = $location;
            }
        }

        return array_values(array_filter($idKeys, function (string $idKey) use ($ancestorLocations): bool {
            $location = $this->doc->xmlTypeLocation($idKey);
            if ($location === null) {
                return true;
            }

            foreach ($ancestorLocations as $ancestorId => $ancestorLocation) {
                if ($idKey !== $ancestorId && $location['root'] === $ancestorLocation['root'] && self::pathStartsWith($location['path'], $ancestorLocation['path'])) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @param array{root: string, path: list<int|string>}|null $location
     * @param list<array{root: string, path: list<int|string>}> $scopeLocations
     */
    private function locationIsInScope(?array $location, array $scopeLocations): bool
    {
        if ($location === null) {
            return false;
        }

        foreach ($scopeLocations as $scopeLocation) {
            if ($location['root'] === $scopeLocation['root'] && self::pathStartsWith($location['path'], $scopeLocation['path'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<int|string> $path
     * @param list<int|string> $prefix
     */
    private static function pathStartsWith(array $path, array $prefix): bool
    {
        if (count($prefix) > count($path)) {
            return false;
        }

        foreach ($prefix as $index => $segment) {
            if (($path[$index] ?? null) !== $segment) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $next
     * @return array<string, mixed>
     */
    private function mergeStackItems(array $previous, array $next, float $now): array
    {
        return [
            'rootNames' => $this->mergeNames($previous['rootNames'], $next['rootNames']),
            'nestedIds' => $this->mergeNames($previous['nestedIds'], $next['nestedIds']),
            'xmlRootNames' => $this->mergeNames($previous['xmlRootNames'], $next['xmlRootNames']),
            'xmlElementChildIds' => $this->mergeNames($previous['xmlElementChildIds'], $next['xmlElementChildIds']),
            'xmlTextIds' => $this->mergeNames($previous['xmlTextIds'], $next['xmlTextIds']),
            'xmlAttributeIds' => $this->mergeNames($previous['xmlAttributeIds'], $next['xmlAttributeIds']),
            'textAttributeNames' => $this->mergeNames($previous['textAttributeNames'], $next['textAttributeNames']),
            'nestedTextAttributeIds' => $this->mergeNames($previous['nestedTextAttributeIds'], $next['nestedTextAttributeIds']),
            'before' => $previous['before'] + $next['before'],
            'after' => array_replace($previous['after'], $next['after']),
            'beforeTextAttributes' => $previous['beforeTextAttributes'] + $next['beforeTextAttributes'],
            'afterTextAttributes' => array_replace($previous['afterTextAttributes'], $next['afterTextAttributes']),
            'beforeNested' => $previous['beforeNested'] + $next['beforeNested'],
            'afterNested' => array_replace($previous['afterNested'], $next['afterNested']),
            'beforeNestedTextAttributes' => $previous['beforeNestedTextAttributes'] + $next['beforeNestedTextAttributes'],
            'afterNestedTextAttributes' => array_replace($previous['afterNestedTextAttributes'], $next['afterNestedTextAttributes']),
            'beforeXmlRootChildren' => $previous['beforeXmlRootChildren'] + $next['beforeXmlRootChildren'],
            'afterXmlRootChildren' => array_replace($previous['afterXmlRootChildren'], $next['afterXmlRootChildren']),
            'beforeXmlElementChildren' => $previous['beforeXmlElementChildren'] + $next['beforeXmlElementChildren'],
            'afterXmlElementChildren' => array_replace($previous['afterXmlElementChildren'], $next['afterXmlElementChildren']),
            'beforeXmlText' => $previous['beforeXmlText'] + $next['beforeXmlText'],
            'afterXmlText' => array_replace($previous['afterXmlText'], $next['afterXmlText']),
            'beforeXmlAttributes' => $previous['beforeXmlAttributes'] + $next['beforeXmlAttributes'],
            'afterXmlAttributes' => array_replace($previous['afterXmlAttributes'], $next['afterXmlAttributes']),
            'insertedStructs' => [...$previous['insertedStructs'], ...$next['insertedStructs']],
            'origin' => $next['origin'],
            'createdAt' => $now,
        ];
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     * @return list<string>
     */
    private function mergeNames(array $left, array $right): array
    {
        return array_values(array_unique([...$left, ...$right], SORT_STRING));
    }

    /**
     * @param array<string, mixed> $stackItem
     * @return array{json: array<string, mixed>, xmlRootChildren: array<string, list<array<string, mixed>>>, xmlElementChildren: array<string, list<array<string, mixed>>>, xmlText: array<string, string>, xmlAttributes: array<string, array<string, mixed>>}
     */
    private function scopedStateForStackItem(array $stackItem): array
    {
        $xmlRootChildren = [];
        foreach ($stackItem['xmlRootNames'] as $name) {
            $xmlRootChildren[$name] = $this->doc->xmlFragmentChildrenSnapshot($name);
        }

        $xmlElementChildren = [];
        foreach ($stackItem['xmlElementChildIds'] as $idKey) {
            $xmlElementChildren[$idKey] = $this->doc->xmlElementChildrenSnapshot($idKey);
        }

        $xmlText = [];
        foreach ($stackItem['xmlTextIds'] as $idKey) {
            $xmlText[$idKey] = $this->doc->xmlTextValue($idKey);
        }

        $xmlAttributes = [];
        foreach ($stackItem['xmlAttributeIds'] as $idKey) {
            $xmlAttributes[$idKey] = $this->doc->xmlElementAttributes($idKey);
        }

        $textAttributes = [];
        foreach ($stackItem['textAttributeNames'] as $name) {
            $textAttributes[$name] = $this->doc->textAttributes($name);
        }

        $nestedTextAttributes = [];
        foreach ($stackItem['nestedTextAttributeIds'] as $idKey) {
            $nestedTextAttributes[$idKey] = $this->doc->nestedTextAttributes($idKey);
        }

        return [
            'json' => $this->doc->toJSON(),
            'textAttributes' => $textAttributes,
            'nestedTextAttributes' => $nestedTextAttributes,
            'xmlRootChildren' => $xmlRootChildren,
            'xmlElementChildren' => $xmlElementChildren,
            'xmlText' => $xmlText,
            'xmlAttributes' => $xmlAttributes,
        ];
    }

    /**
     * @param array{rootNames: list<string>, nestedIds: list<string>, before: array<string, mixed>, after: array<string, mixed>, beforeNested: array<string, mixed>, afterNested: array<string, mixed>, insertedStructs: list<array<string, mixed>>, origin: mixed, createdAt: float}|null $stackItem
     * @param array<string, mixed> $stackMeta
     */
    private function notifyStackObservers(string $type, ?string $stack = null, ?array $stackItem = null, bool $merged = false, array $stackMeta = []): void
    {
        if ($this->stackObservers === [] && $this->eventObservers === []) {
            return;
        }

        $event = [
            'type' => $type,
            'stack' => $stack,
            'stackItem' => $stackItem,
            'merged' => $merged,
        ] + $stackMeta;

        foreach ($this->stackObservers as $observer) {
            $observer($event, $this);
        }

        $this->emit($type, [$this->stackEmitterEvent($event)]);
    }

    /**
     * @param array<string, mixed>|null $transactionEvent
     * @return array{changedParentTypeNames: list<string>}
     */
    private function stackTransactionMeta(?array $transactionEvent): array
    {
        $changedParentTypeNames = isset($transactionEvent['changedParentTypeNames']) && is_array($transactionEvent['changedParentTypeNames'])
            ? array_values(array_filter($transactionEvent['changedParentTypeNames'], 'is_string'))
            : [];

        sort($changedParentTypeNames, SORT_STRING);

        return ['changedParentTypeNames' => $changedParentTypeNames];
    }

    /**
     * @param callable(mixed ...$arguments): void $observer
     */
    private function addEventObserver(string $eventName, callable $observer, bool $once): int
    {
        $observerId = $this->nextObserverId++;
        $this->eventObservers[$observerId] = [
            'name' => $eventName,
            'observer' => $observer,
            'once' => $once,
        ];

        return $observerId;
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function stackEmitterEvent(array $event): array
    {
        if ($event['type'] === self::STACK_CLEARED) {
            return [
                'undoStackCleared' => $event['undoStackCleared'] ?? false,
                'redoStackCleared' => $event['redoStackCleared'] ?? false,
            ];
        }

        return [
            'stackItem' => $event['stackItem'],
            'origin' => $event['emitterOrigin'] ?? (is_array($event['stackItem'] ?? null) ? ($event['stackItem']['origin'] ?? null) : null),
            'type' => $event['stack'],
            'changedParentTypes' => $event['changedParentTypeNames'] ?? [],
            'changedParentTypeNames' => $event['changedParentTypeNames'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $event
     * @return list<array<string, mixed>>
     */
    private function insertedStructsFromTransaction(array $event): array
    {
        if (! is_string($event['update'] ?? null)) {
            return [];
        }

        try {
            return DecodedUpdate::decodeV1($event['update'])['structs'];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array{rootNames: list<string>, nestedIds: list<string>, before: array<string, mixed>, after: array<string, mixed>, beforeNested: array<string, mixed>, afterNested: array<string, mixed>, insertedStructs: list<array<string, mixed>>, origin: mixed, createdAt: float} $stackItem
     */
    private function shouldApplyUndoStackItem(array $stackItem): bool
    {
        if ($this->deleteFilter === null) {
            return true;
        }

        $insertedItemsInScope = array_values(array_filter(
            $stackItem['insertedStructs'],
            fn (array $struct): bool => ($struct['type'] ?? null) === 'Item'
                && $this->structIsInScope($struct, $stackItem['rootNames'], $stackItem['nestedIds'], $stackItem['xmlRootNames'], $stackItem['xmlTextIds'], $stackItem['xmlAttributeIds'], $stackItem['textAttributeNames'])
        ));
        if ($insertedItemsInScope === []) {
            return true;
        }

        foreach ($insertedItemsInScope as $struct) {
            if (($this->deleteFilter)($struct)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $struct
     * @param list<string> $rootNames
     * @param list<string> $nestedIds
     * @param list<string> $xmlRootNames
     * @param list<string> $xmlTextIds
     * @param list<string> $xmlAttributeIds
     * @param list<string> $textAttributeNames
     */
    private function structIsInScope(array $struct, array $rootNames, array $nestedIds, array $xmlRootNames, array $xmlTextIds, array $xmlAttributeIds, array $textAttributeNames): bool
    {
        $parent = $struct['parent'] ?? null;
        if (is_string($parent) && in_array($parent, $rootNames, true)) {
            return true;
        }

        if (is_string($parent) && in_array($parent, $textAttributeNames, true)) {
            return true;
        }

        if (is_string($parent) && in_array($parent, $xmlRootNames, true)) {
            return true;
        }

        if (is_array($parent) && in_array(self::idKey($parent), $nestedIds, true)) {
            return true;
        }

        if (is_array($parent) && in_array(self::idKey($parent), $xmlTextIds, true)) {
            return true;
        }

        if (is_array($parent) && in_array(self::idKey($parent), $xmlAttributeIds, true)) {
            return true;
        }

        if ($parent === null && ($rootNames !== [] || $nestedIds !== [] || $xmlRootNames !== [] || $xmlTextIds !== [] || $xmlAttributeIds !== [] || $textAttributeNames !== [])) {
            return true;
        }

        return false;
    }

    /**
     * @param array{client: int, clock: int} $id
     */
    private static function idKey(array $id): string
    {
        return $id['client'] . ':' . $id['clock'];
    }

    /**
     * @param array<string, mixed> $state
     * @param list<string> $names
     * @return array<string, mixed>
     */
    private function selectState(array $state, array $names): array
    {
        $selected = [];

        foreach ($names as $name) {
            if (array_key_exists($name, $state)) {
                $selected[$name] = $state[$name];
            }
        }

        return $selected;
    }

    /**
     * @param array<string, mixed> $state
     * @param list<string> $names
     * @return array<string, string>
     */
    private function selectStringState(array $state, array $names): array
    {
        $selected = [];

        foreach ($names as $name) {
            if (array_key_exists($name, $state) && is_string($state[$name])) {
                $selected[$name] = $state[$name];
            }
        }

        return $selected;
    }

    /**
     * @param array<string, mixed> $state
     * @param list<string> $names
     * @return array<string, array<string, mixed>>
     */
    private function selectMapState(array $state, array $names): array
    {
        $selected = [];

        foreach ($names as $name) {
            if (array_key_exists($name, $state) && is_array($state[$name]) && ! array_is_list($state[$name])) {
                $selected[$name] = $state[$name];
            }
        }

        return $selected;
    }

    /**
     * @param array<string, mixed> $state
     * @param list<string> $names
     * @return array<string, array<string, mixed>>
     */
    private function selectTextAttributeState(array $state, array $names): array
    {
        $selected = [];

        foreach ($names as $name) {
            $value = $state[$name] ?? [];
            if (! is_array($value) || array_is_list($value)) {
                $value = [];
            }

            ksort($value, SORT_STRING);
            $selected[$name] = $value;
        }

        ksort($selected, SORT_STRING);

        return $selected;
    }

    /**
     * @param array<string, array<string, mixed>> $before
     * @param array<string, array<string, mixed>> $after
     * @return list<string>
     */
    private function changedTextAttributeNames(array $before, array $after): array
    {
        $names = [];

        foreach (array_keys($before + $after) as $name) {
            if (($before[$name] ?? []) !== ($after[$name] ?? [])) {
                $names[] = (string) $name;
            }
        }

        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * @param array<string, mixed> $state
     * @param list<string> $names
     * @return array<string, list<array<string, mixed>>>
     */
    private function selectListState(array $state, array $names): array
    {
        $selected = [];

        foreach ($names as $name) {
            if (array_key_exists($name, $state) && is_array($state[$name]) && array_is_list($state[$name])) {
                $selected[$name] = $state[$name];
            }
        }

        return $selected;
    }

    /**
     * @param array<string, mixed> $target
     * @param list<string> $names
     * @param array<string, mixed>|null $source
     */
    private function restoreState(array $target, array $names, ?array $source = null): void
    {
        $current = $this->doc->toJSON();
        foreach ($names as $name) {
            $targetExists = array_key_exists($name, $target);
            $currentExists = array_key_exists($name, $current);

            if (! $targetExists && ! $currentExists) {
                continue;
            }

            $targetValue = $target[$name] ?? null;
            $currentValue = $current[$name] ?? null;
            $sourceValue = $source[$name] ?? null;

            if ($targetExists && $currentExists && $targetValue === $currentValue) {
                continue;
            }

            if (! $targetExists) {
                $this->clearRootValue((string) $name, $currentValue);
                continue;
            }

            $this->restoreRootValue((string) $name, $targetValue, $currentValue, $sourceValue);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $target
     * @param list<string> $names
     */
    private function restoreTextAttributeState(array $target, array $names): void
    {
        foreach ($names as $name) {
            $targetValue = $target[$name] ?? [];
            $currentValue = $this->doc->textAttributes($name);

            if ($targetValue === $currentValue) {
                continue;
            }

            foreach (array_keys($currentValue) as $key) {
                if (! array_key_exists($key, $targetValue)) {
                    $this->doc->removeTextAttribute($name, (string) $key);
                }
            }

            foreach ($targetValue as $key => $value) {
                if (! array_key_exists($key, $currentValue) || $currentValue[$key] !== $value) {
                    $this->doc->setTextAttribute($name, (string) $key, $value);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $target
     * @param list<string> $idKeys
     */
    private function restoreNestedState(array $target, array $idKeys, ?array $source = null): void
    {
        foreach ($idKeys as $idKey) {
            $targetExists = array_key_exists($idKey, $target);
            $currentValue = $this->currentNestedValue($idKey);

            if (! $targetExists) {
                $this->clearNestedValue($idKey, $currentValue);
                continue;
            }

            $targetValue = $target[$idKey];
            if ($targetValue === $currentValue) {
                continue;
            }

            $this->restoreNestedValue($idKey, $targetValue, $currentValue, $source[$idKey] ?? null);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $target
     * @param list<string> $idKeys
     */
    private function restoreNestedTextAttributeState(array $target, array $idKeys): void
    {
        foreach ($idKeys as $idKey) {
            $targetValue = $target[$idKey] ?? [];
            $currentValue = $this->doc->nestedTextAttributes($idKey);

            if ($targetValue === $currentValue) {
                continue;
            }

            foreach (array_keys($currentValue) as $key) {
                if (! array_key_exists($key, $targetValue)) {
                    $this->doc->removeNestedTextAttribute($idKey, (string) $key);
                }
            }

            foreach ($targetValue as $key => $value) {
                if (! array_key_exists($key, $currentValue) || $currentValue[$key] !== $value) {
                    $this->doc->setNestedTextAttribute($idKey, (string) $key, $value);
                }
            }
        }
    }

    /**
     * @param array<string, list<array<string, mixed>>> $target
     * @param list<string> $names
     */
    private function restoreXmlRootChildrenState(array $target, array $names): void
    {
        foreach ($names as $name) {
            if (array_key_exists($name, $target)) {
                $this->doc->restoreXmlFragmentChildrenSnapshot($name, $target[$name]);
            }
        }
    }

    /**
     * @param array<string, list<array<string, mixed>>> $target
     * @param list<string> $idKeys
     */
    private function restoreXmlElementChildrenState(array $target, array $idKeys): void
    {
        foreach ($idKeys as $idKey) {
            if (array_key_exists($idKey, $target)) {
                $this->doc->restoreXmlElementChildrenSnapshot($idKey, $target[$idKey]);
            }
        }
    }

    /**
     * @param array<string, string> $target
     * @param list<string> $idKeys
     * @param array<string, string>|null $source
     */
    private function restoreXmlTextState(array $target, array $idKeys, ?array $source = null): void
    {
        foreach ($idKeys as $idKey) {
            $targetValue = $target[$idKey] ?? '';
            $currentValue = $this->doc->xmlTextValue($idKey);

            if ($targetValue === $currentValue) {
                continue;
            }

            $this->restoreXmlTextValue($idKey, $targetValue, $source[$idKey] ?? $currentValue);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $target
     * @param list<string> $idKeys
     */
    private function restoreXmlAttributeState(array $target, array $idKeys): void
    {
        foreach ($idKeys as $idKey) {
            $targetValue = $target[$idKey] ?? [];
            $currentValue = $this->doc->xmlElementAttributes($idKey);

            if ($targetValue === $currentValue) {
                continue;
            }

            foreach (array_keys($currentValue) as $key) {
                if (! array_key_exists($key, $targetValue)) {
                    $this->doc->removeXmlElementAttribute($idKey, (string) $key);
                }
            }

            foreach ($targetValue as $key => $value) {
                if ($this->doc->sharedTypeInXmlElementAttribute($idKey, (string) $key) !== null) {
                    continue;
                }

                if (! array_key_exists($key, $currentValue) || $currentValue[$key] !== $value) {
                    $this->doc->setXmlElementAttribute($idKey, (string) $key, $value);
                }
            }
        }
    }

    private function clearRootValue(string $name, mixed $currentValue): void
    {
        if (is_string($currentValue)) {
            $this->doc->getText($name)->clear();
            return;
        }

        if (is_array($currentValue) && array_is_list($currentValue)) {
            $this->doc->getArray($name)->clear();
            return;
        }

        if (is_array($currentValue)) {
            $this->doc->getMap($name)->clear();
        }
    }

    private function restoreRootValue(string $name, mixed $value, mixed $currentValue, mixed $sourceValue = null): void
    {
        if (is_string($value)) {
            if (is_string($sourceValue) && is_string($currentValue)) {
                $this->restoreRootTextDiff($name, $value, $sourceValue);
                return;
            }

            $text = $this->doc->getText($name);
            $text->clear();
            if ($value !== '') {
                $text->insert(0, $value);
            }

            return;
        }

        if (is_array($value) && array_is_list($value)) {
            if ($value === [] && is_array($currentValue) && ! array_is_list($currentValue)) {
                $this->doc->getMap($name)->clear();
                return;
            }

            $array = $this->doc->getArray($name);
            $array->clear();
            if ($value !== []) {
                $array->insert(0, $value);
            }

            return;
        }

        if (is_array($value)) {
            if (is_array($sourceValue) && is_array($currentValue) && ! array_is_list($currentValue)) {
                $value = $this->preserveRemoteMapChanges($value, $sourceValue, $currentValue);
            }
            if ($value === $currentValue) {
                return;
            }

            $map = $this->doc->getMap($name);
            $map->clear();
            foreach ($value as $key => $nestedValue) {
                $map->set((string) $key, $nestedValue);
            }

            return;
        }

        $map = $this->doc->getMap($name);
        $map->clear();
        $map->set('value', $value);
    }

    private function currentNestedValue(string $idKey): mixed
    {
        try {
            return $this->doc->nestedArrayValue($idKey);
        } catch (\UnexpectedValueException) {
        }

        try {
            return $this->doc->nestedMapValue($idKey);
        } catch (\UnexpectedValueException) {
        }

        return $this->doc->nestedTextValue($idKey);
    }

    private function clearNestedValue(string $idKey, mixed $currentValue): void
    {
        if (is_string($currentValue)) {
            $this->doc->deleteNestedText($idKey, 0, self::utf16CodeUnitLength($currentValue));
            return;
        }

        if (is_array($currentValue) && array_is_list($currentValue)) {
            $this->doc->deleteNestedArray($idKey, 0, count($currentValue));
            return;
        }

        if (is_array($currentValue)) {
            foreach (array_keys($currentValue) as $key) {
                $this->doc->deleteNestedMapKey($idKey, (string) $key);
            }
        }
    }

    private function restoreNestedValue(string $idKey, mixed $value, mixed $currentValue, mixed $sourceValue = null): void
    {
        if (is_string($value)) {
            $this->doc->deleteNestedText($idKey, 0, self::utf16CodeUnitLength((string) $currentValue));
            if ($value !== '') {
                $this->doc->insertNestedText($idKey, 0, $value);
            }

            return;
        }

        if (is_array($value) && array_is_list($value)) {
            if ($value === [] && is_array($currentValue) && ! array_is_list($currentValue)) {
                $this->clearNestedValue($idKey, $currentValue);
                return;
            }

            $this->doc->deleteNestedArray($idKey, 0, is_array($currentValue) && array_is_list($currentValue) ? count($currentValue) : 0);
            if ($value !== []) {
                $this->doc->insertNestedArray($idKey, 0, $value);
            }

            return;
        }

        if (is_array($value)) {
            if (is_array($sourceValue) && is_array($currentValue) && ! array_is_list($currentValue)) {
                $value = $this->preserveRemoteMapChanges($value, $sourceValue, $currentValue);
            }
            if ($value === $currentValue) {
                return;
            }

            $this->clearNestedValue($idKey, $currentValue);
            foreach ($value as $key => $nestedValue) {
                $this->doc->setNestedMapValue($idKey, (string) $key, $nestedValue);
            }
        }
    }

    /**
     * @param array<string, mixed> $targetValue
     * @param array<string, mixed> $sourceValue
     * @param array<string, mixed> $currentValue
     * @return array<string, mixed>
     */
    private function preserveRemoteMapChanges(array $targetValue, array $sourceValue, array $currentValue): array
    {
        if ($this->ignoreRemoteMapChanges) {
            return $targetValue;
        }

        foreach ($currentValue as $key => $currentNestedValue) {
            if (array_key_exists($key, $sourceValue) && $sourceValue[$key] !== $currentNestedValue) {
                $targetValue[$key] = $currentNestedValue;
            }
        }

        return $targetValue;
    }

    private static function utf16CodeUnitLength(string $value): int
    {
        if ($value === '') {
            return 0;
        }

        return intdiv(strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }

    private function restoreRootTextDiff(string $name, string $targetValue, string $sourceValue): void
    {
        $diff = self::stringDiff($targetValue, $sourceValue);
        $text = $this->doc->getText($name);
        $deleteLength = self::utf16CodeUnitLength($diff['sourceMiddle']);

        if ($deleteLength > 0) {
            $text->delete($diff['index'], $deleteLength);
        }

        if ($diff['targetMiddle'] !== '') {
            $text->insert($diff['index'], $diff['targetMiddle']);
        }
    }

    private function restoreXmlTextValue(string $idKey, string $targetValue, string $sourceValue): void
    {
        $diff = self::stringDiff($targetValue, $sourceValue);
        $deleteLength = self::utf16CodeUnitLength($diff['sourceMiddle']);

        if ($deleteLength > 0) {
            $this->doc->deleteXmlTextContent($idKey, $diff['index'], $deleteLength);
        }

        if ($diff['targetMiddle'] !== '') {
            $this->doc->insertXmlTextContent($idKey, $diff['index'], $diff['targetMiddle']);
        }
    }

    /**
     * @return array{index: int, targetMiddle: string, sourceMiddle: string}
     */
    private static function stringDiff(string $targetValue, string $sourceValue): array
    {
        $targetChars = self::unicodeCharacters($targetValue);
        $sourceChars = self::unicodeCharacters($sourceValue);
        $targetLength = count($targetChars);
        $sourceLength = count($sourceChars);
        $prefix = 0;

        while ($prefix < $targetLength && $prefix < $sourceLength && $targetChars[$prefix] === $sourceChars[$prefix]) {
            $prefix++;
        }

        $suffix = 0;
        while (
            $suffix < ($targetLength - $prefix)
            && $suffix < ($sourceLength - $prefix)
            && $targetChars[$targetLength - $suffix - 1] === $sourceChars[$sourceLength - $suffix - 1]
        ) {
            $suffix++;
        }

        return [
            'index' => self::utf16CodeUnitLength(implode('', array_slice($sourceChars, 0, $prefix))),
            'targetMiddle' => implode('', array_slice($targetChars, $prefix, $targetLength - $prefix - $suffix)),
            'sourceMiddle' => implode('', array_slice($sourceChars, $prefix, $sourceLength - $prefix - $suffix)),
        ];
    }

    /**
     * @return list<string>
     */
    private static function unicodeCharacters(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            throw new \UnexpectedValueException('Unable to split string into Unicode characters.');
        }

        return $characters;
    }
}
