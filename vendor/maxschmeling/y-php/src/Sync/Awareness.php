<?php

declare(strict_types=1);

namespace Yjs\Sync;

final class Awareness
{
    public const OUTDATED_TIMEOUT = 30000;

    /** @var array<int, array{clock: int, state: mixed}> */
    private array $states = [];
    /** @var array<int, array{clock: int, lastUpdated: int}> */
    private array $meta = [];
    /** @var array<int, callable(array<string, mixed>, self, mixed): void> */
    private array $observers = [];
    /** @var array<int, callable(array<string, mixed>, self, mixed): void> */
    private array $updateObservers = [];
    /** @var array<int, array{name: string, observer: callable, once: bool}> */
    private array $eventObservers = [];
    private int $nextObserverId = 1;

    /**
     * @return array<int, mixed>
     */
    public function getStates(): array
    {
        $states = [];

        foreach ($this->states as $clientID => $entry) {
            if ($entry['state'] !== null) {
                $states[$clientID] = $entry['state'];
            }
        }

        ksort($states, SORT_NUMERIC);

        return $states;
    }

    /**
     * @return array<int, array{clock: int, state: mixed}>
     */
    public function getStateEntries(): array
    {
        ksort($this->states, SORT_NUMERIC);

        return $this->states;
    }

    public function getState(int $clientID): mixed
    {
        return $this->states[$clientID]['state'] ?? null;
    }

    public function hasState(int $clientID): bool
    {
        return array_key_exists($clientID, $this->states) && $this->states[$clientID]['state'] !== null;
    }

    /**
     * @return list<int>
     */
    public function activeClientIDs(): array
    {
        $clientIDs = [];

        foreach ($this->states as $clientID => $entry) {
            if ($entry['state'] !== null) {
                $clientIDs[] = $clientID;
            }
        }

        sort($clientIDs, SORT_NUMERIC);

        return $clientIDs;
    }

    /**
     * @return array<int, array{clock: int, lastUpdated: int}>
     */
    public function getMeta(): array
    {
        ksort($this->meta, SORT_NUMERIC);

        return $this->meta;
    }

    /**
     * @return array{clock: int, lastUpdated: int}|null
     */
    public function getClientMeta(int $clientID): ?array
    {
        return $this->meta[$clientID] ?? null;
    }

    public function setLocalState(int $clientID, mixed $state, mixed $origin = null): string
    {
        $now = self::now();
        $hadEntry = array_key_exists($clientID, $this->states);
        $hadState = $hadEntry && $this->states[$clientID]['state'] !== null;
        $previousState = $this->states[$clientID]['state'] ?? null;
        $clock = ($this->meta[$clientID]['clock'] ?? $this->states[$clientID]['clock'] ?? 0) + 1;
        $this->states[$clientID] = [
            'clock' => $clock,
            'state' => $state,
        ];
        $this->meta[$clientID] = [
            'clock' => $clock,
            'lastUpdated' => $now,
        ];

        $update = AwarenessUpdate::encode([
            [
                'clientID' => $clientID,
                'clock' => $clock,
                'state' => $state,
            ],
        ]);
        $changeAdded = $state === null || $hadState ? [] : [$clientID];
        $changeUpdated = $state !== null && $hadState && ! self::statesEqual($previousState, $state) ? [$clientID] : [];
        $changeRemoved = $state === null && ($hadState || $hadEntry) ? [$clientID] : [];
        $this->notifyObservers(
            $changeAdded,
            $changeUpdated,
            $changeRemoved,
            $update,
            $origin
        );
        $this->notifyUpdateObservers(
            $state === null || $hadState ? [] : [$clientID],
            $state !== null && $hadState ? [$clientID] : [],
            $state === null ? [$clientID] : [],
            $update,
            $origin
        );

        return $update;
    }

    /**
     * @param array<int, mixed> $statesByClientID
     */
    public function setLocalStates(array $statesByClientID, mixed $origin = null): string
    {
        $now = self::now();
        $updates = [];
        $changeAdded = [];
        $changeUpdated = [];
        $changeRemoved = [];
        $updateAdded = [];
        $updateUpdated = [];
        $updateRemoved = [];

        foreach ($statesByClientID as $clientID => $state) {
            $clientID = (int) $clientID;
            $hadEntry = array_key_exists($clientID, $this->states);
            $hadState = $hadEntry && $this->states[$clientID]['state'] !== null;
            $previousState = $this->states[$clientID]['state'] ?? null;
            $clock = ($this->meta[$clientID]['clock'] ?? $this->states[$clientID]['clock'] ?? 0) + 1;
            $this->states[$clientID] = [
                'clock' => $clock,
                'state' => $state,
            ];
            $this->meta[$clientID] = [
                'clock' => $clock,
                'lastUpdated' => $now,
            ];
            $updates[] = [
                'clientID' => $clientID,
                'clock' => $clock,
                'state' => $state,
            ];

            if ($state === null) {
                if ($hadState || $hadEntry) {
                    $changeRemoved[] = $clientID;
                }
                $updateRemoved[] = $clientID;
                continue;
            }

            if (! $hadState) {
                $changeAdded[] = $clientID;
                $updateAdded[] = $clientID;
                continue;
            }

            if (! self::statesEqual($previousState, $state)) {
                $changeUpdated[] = $clientID;
            }
            $updateUpdated[] = $clientID;
        }

        $update = AwarenessUpdate::encode($updates);
        $this->notifyObservers($changeAdded, $changeUpdated, $changeRemoved, $update, $origin);
        $this->notifyUpdateObservers($updateAdded, $updateUpdated, $updateRemoved, $update, $origin);

        return $update;
    }

    public function setLocalStateField(int $clientID, string $field, mixed $value, mixed $origin = null): ?string
    {
        $state = $this->getState($clientID);
        if ($state === null) {
            return null;
        }

        if (! is_array($state)) {
            throw new \UnexpectedValueException('Awareness state fields can only be set on array states.');
        }

        $state[$field] = $value;

        return $this->setLocalState($clientID, $state, $origin);
    }

    /**
     * @param callable(array<string, mixed>, self, mixed): void $observer
     */
    public function observe(callable $observer): int
    {
        $observerId = $this->nextObserverId++;
        $this->observers[$observerId] = $observer;

        return $observerId;
    }

    /**
     * @param callable(array<string, mixed>, self, mixed): void $observer
     */
    public function observeOnce(callable $observer): int
    {
        $observerId = null;
        $observerId = $this->observe(function (array $event, self $awareness, mixed $origin) use (&$observerId, $observer): void {
            if ($observerId !== null) {
                $this->unobserve($observerId);
            }

            $observer($event, $awareness, $origin);
        });

        return $observerId;
    }

    public function unobserve(int $observerId): void
    {
        unset($this->observers[$observerId]);
    }

    /**
     * @param callable(array<string, mixed>, self, mixed): void $observer
     */
    public function observeUpdate(callable $observer): int
    {
        $observerId = $this->nextObserverId++;
        $this->updateObservers[$observerId] = $observer;

        return $observerId;
    }

    /**
     * @param callable(array<string, mixed>, self, mixed): void $observer
     */
    public function observeUpdateOnce(callable $observer): int
    {
        $observerId = null;
        $observerId = $this->observeUpdate(function (array $event, self $awareness, mixed $origin) use (&$observerId, $observer): void {
            if ($observerId !== null) {
                $this->unobserveUpdate($observerId);
            }

            $observer($event, $awareness, $origin);
        });

        return $observerId;
    }

    public function unobserveUpdate(int $observerId): void
    {
        unset($this->updateObservers[$observerId]);
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
     * @param list<int>|null $clientIDs
     */
    public function encodeUpdate(?array $clientIDs = null): string
    {
        $updates = [];
        $clientIDs ??= array_keys($this->states);

        foreach ($clientIDs as $clientID) {
            if (! isset($this->states[$clientID])) {
                continue;
            }

            $updates[] = [
                'clientID' => $clientID,
                'clock' => $this->states[$clientID]['clock'],
                'state' => $this->states[$clientID]['state'],
            ];
        }

        return AwarenessUpdate::encode($updates);
    }

    /**
     * @return list<int> Client IDs whose awareness state changed.
     */
    public function applyUpdate(string $update, mixed $origin = null): array
    {
        $now = self::now();
        $changed = [];
        $added = [];
        $updated = [];
        $removed = [];
        $updateAdded = [];
        $updateUpdated = [];
        $updateRemoved = [];

        foreach (AwarenessUpdate::decode($update) as $entry) {
            $clientID = $entry['clientID'];
            $currentClock = $this->meta[$clientID]['clock'] ?? $this->states[$clientID]['clock'] ?? 0;
            $hadState = isset($this->states[$clientID]) && $this->states[$clientID]['state'] !== null;
            $previousState = $this->states[$clientID]['state'] ?? null;

            $isSameClockRemoval = $entry['clock'] === $currentClock && $entry['state'] === null && $hadState;
            if ($entry['clock'] < $currentClock || ($entry['clock'] === $currentClock && ! $isSameClockRemoval)) {
                continue;
            }

            if ($entry['state'] === null) {
                unset($this->states[$clientID]);
            } else {
                $this->states[$clientID] = [
                    'clock' => $entry['clock'],
                    'state' => $entry['state'],
                ];
            }
            $this->meta[$clientID] = [
                'clock' => $entry['clock'],
                'lastUpdated' => $now,
            ];

            if ($entry['state'] === null) {
                if ($hadState) {
                    $removed[] = $clientID;
                    $updateRemoved[] = $clientID;
                }
            } elseif ($hadState) {
                $updateUpdated[] = $clientID;
                if (! self::statesEqual($previousState, $entry['state'])) {
                    $updated[] = $clientID;
                }
            } else {
                $added[] = $clientID;
                $updateAdded[] = $clientID;
            }
        }

        $this->notifyObservers($added, $updated, $removed, $update, $origin);
        $this->notifyUpdateObservers($updateAdded, $updateUpdated, $updateRemoved, $update, $origin);
        $changed = array_values(array_unique([...$added, ...$updated, ...$removed]));

        return $changed;
    }

    /**
     * @param list<int> $clientIDs
     */
    public function removeStates(array $clientIDs, mixed $origin = null): string
    {
        $now = self::now();
        $updates = [];
        $removed = [];
        $updateRemoved = [];

        foreach ($clientIDs as $clientID) {
            if (! isset($this->states[$clientID]) || $this->states[$clientID]['state'] === null) {
                continue;
            }

            $clock = ($this->meta[$clientID]['clock'] ?? $this->states[$clientID]['clock'] ?? 0) + 1;
            unset($this->states[$clientID]);
            $this->meta[$clientID] = [
                'clock' => $clock,
                'lastUpdated' => $now,
            ];
            $updates[] = [
                'clientID' => $clientID,
                'clock' => $clock,
                'state' => null,
            ];
            $removed[] = $clientID;
            $updateRemoved[] = $clientID;
        }

        $update = AwarenessUpdate::encode($updates);
        $this->notifyObservers([], [], $removed, $update, $origin);
        $this->notifyUpdateObservers([], [], $updateRemoved, $update, $origin);

        return $update;
    }

    public function clear(mixed $origin = null): string
    {
        return $this->removeStates($this->activeClientIDs(), $origin);
    }

    /**
     * @param list<int> $clientIDs
     * @return list<int> Active client IDs whose last-updated timestamp was refreshed.
     */
    public function touchStates(array $clientIDs, ?int $now = null): array
    {
        $now ??= self::now();
        $touched = [];

        foreach ($clientIDs as $clientID) {
            if (($this->states[$clientID]['state'] ?? null) === null || ! isset($this->meta[$clientID])) {
                continue;
            }

            $this->meta[$clientID]['lastUpdated'] = $now;
            $touched[] = $clientID;
        }

        return $touched;
    }

    /**
     * @return list<int> Active client IDs older than the configured timeout.
     */
    public function outdatedClientIDs(int $now, int $timeout = self::OUTDATED_TIMEOUT): array
    {
        $outdated = [];

        foreach ($this->meta as $clientID => $meta) {
            if (($this->states[$clientID]['state'] ?? null) === null) {
                continue;
            }

            if ($now - $meta['lastUpdated'] >= $timeout) {
                $outdated[] = $clientID;
            }
        }

        return $outdated;
    }

    /**
     * @return list<int> Client IDs that were removed as stale.
     */
    public function removeOutdatedStates(int $now, int $timeout = self::OUTDATED_TIMEOUT, mixed $origin = 'timeout'): array
    {
        $remove = $this->outdatedClientIDs($now, $timeout);

        if ($remove === []) {
            return [];
        }

        $updates = [];
        foreach ($remove as $clientID) {
            $clock = $this->meta[$clientID]['clock'] ?? $this->states[$clientID]['clock'] ?? 0;
            unset($this->states[$clientID]);
            $updates[] = [
                'clientID' => $clientID,
                'clock' => $clock,
                'state' => null,
            ];
        }

        $update = AwarenessUpdate::encode($updates);
        $this->notifyObservers([], [], $remove, $update, $origin);
        $this->notifyUpdateObservers([], [], $remove, $update, $origin);

        return $remove;
    }

    /**
     * @param list<int> $added
     * @param list<int> $updated
     * @param list<int> $removed
     */
    private function notifyObservers(array $added, array $updated, array $removed, string $update, mixed $origin): void
    {
        $this->notify($this->observers, $added, $updated, $removed, $update, $origin);
        $this->notifyEventObservers('change', $added, $updated, $removed, $update, $origin);
    }

    /**
     * @param list<int> $added
     * @param list<int> $updated
     * @param list<int> $removed
     */
    private function notifyUpdateObservers(array $added, array $updated, array $removed, string $update, mixed $origin): void
    {
        $this->notify($this->updateObservers, $added, $updated, $removed, $update, $origin);
        $this->notifyEventObservers('update', $added, $updated, $removed, $update, $origin);
    }

    /**
     * @param array<int, callable(array<string, mixed>, self, mixed): void> $observers
     * @param list<int> $added
     * @param list<int> $updated
     * @param list<int> $removed
     */
    private function notify(array &$observers, array $added, array $updated, array $removed, string $update, mixed $origin): void
    {
        if ($added === [] && $updated === [] && $removed === []) {
            return;
        }

        $event = [
            'added' => $added,
            'updated' => $updated,
            'removed' => $removed,
            'changed' => array_values(array_unique([...$added, ...$updated, ...$removed])),
            'update' => $update,
            'origin' => $origin,
        ];

        $this->dispatchDynamicObservers($observers, function (callable $observer) use ($event, $origin): void {
            $observer($event, $this, $origin);
        });
    }

    /**
     * @param array<int, callable(array<string, mixed>, self, mixed): void> $observers
     */
    private function dispatchDynamicObservers(array &$observers, callable $dispatch): void
    {
        $dispatched = [];

        do {
            $dispatchedInPass = false;

            foreach ($observers as $observerId => $observer) {
                if (isset($dispatched[$observerId])) {
                    continue;
                }

                if (! array_key_exists($observerId, $observers)) {
                    continue;
                }

                $dispatched[$observerId] = true;
                $dispatch($observer);
                $dispatchedInPass = true;
            }
        } while ($dispatchedInPass);
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
     * @param list<int> $added
     * @param list<int> $updated
     * @param list<int> $removed
     */
    private function notifyEventObservers(string $eventName, array $added, array $updated, array $removed, string $update, mixed $origin): void
    {
        if ($added === [] && $updated === [] && $removed === []) {
            return;
        }

        $event = [
            'added' => $added,
            'updated' => $updated,
            'removed' => $removed,
            'changed' => array_values(array_unique([...$added, ...$updated, ...$removed])),
            'update' => $update,
            'origin' => $origin,
        ];

        $dispatched = [];

        do {
            $dispatchedInPass = false;

            foreach ($this->eventObservers as $observerId => $entry) {
                if (isset($dispatched[$observerId]) || $entry['name'] !== $eventName) {
                    continue;
                }

                if (! array_key_exists($observerId, $this->eventObservers)) {
                    continue;
                }

                $dispatched[$observerId] = true;
                if ($entry['once']) {
                    unset($this->eventObservers[$observerId]);
                }

                $entry['observer']($event, $this, $origin);
                $dispatchedInPass = true;
            }
        } while ($dispatchedInPass);
    }

    private static function statesEqual(mixed $left, mixed $right): bool
    {
        return self::normalizeState($left) === self::normalizeState($right);
    }

    private static function normalizeState(mixed $state): mixed
    {
        if (! is_array($state)) {
            return $state;
        }

        foreach ($state as $key => $value) {
            $state[$key] = self::normalizeState($value);
        }

        if (! array_is_list($state)) {
            ksort($state);
        }

        return $state;
    }

    private static function now(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
