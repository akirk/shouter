<?php

declare(strict_types=1);

namespace Yjs;

use Yjs\Update\DecodedUpdate;
use Yjs\Update\UpdateUtils;

final class YDoc
{
    /** @var array<string, mixed> */
    private array $json = [];
    /** @var array<string, mixed> */
    private array $identityJson = [];
    /** @var array<string, 'array'|'map'|'text'|'xml'> */
    private array $rootTypeHints = [];
    /** @var array<string, array<string, mixed>> */
    private array $rootTextAttributesByName = [];
    /** @var array<string, array<string, mixed>> */
    private array $structsById = [];
    /** @var array<int, list<array{clock: int, length: int}>> */
    private array $deleteSet = [];
    /** @var array<string, mixed> */
    private array $nestedJsonById = [];
    /** @var array<string, mixed> */
    private array $nestedIdentityById = [];
    /** @var array<string, array<string, mixed>> */
    private array $nestedTextAttributesById = [];
    /** @var array<string, string> */
    private array $xmlTextById = [];
    /** @var array<string, array<string, mixed>> */
    private array $xmlNodesById = [];
    /** @var array<string, list<array<string, mixed>>> */
    private array $xmlRootChildrenByName = [];
    /** @var array<string, array<string, array{__yjsSharedTypeId?: string, __yjsXmlNodeId?: string}>> */
    private array $xmlAttributeIdentityById = [];
    /** @var array<int, callable(string, self): void> */
    private array $updateObservers = [];
    /** @var array<int, callable(string, self): void> */
    private array $updateV2Observers = [];
    /** @var array<int, callable(array<string, mixed>, self): void> */
    private array $transactionObservers = [];
    /** @var array<int, array{name: string, type: string|null, observer: callable(array<string, mixed>, self): void}> */
    private array $sharedTypeObservers = [];
    /** @var array<int, array{idKey: string, type: string, observer: callable(array<string, mixed>, self): void}> */
    private array $nestedTypeObservers = [];
    /** @var array<int, array{idKey: string, observer: callable(array<string, mixed>, self): void}> */
    private array $xmlNodeObservers = [];
    /** @var array<int, callable(list<array<string, mixed>>, self, array<string, mixed>): void> */
    private array $deepObservers = [];
    /** @var array<int, array{name: string, observer: callable, once: bool}> */
    private array $eventObservers = [];
    /** @var array<string, YSubdoc> */
    private array $subdocsByGuid = [];
    /** @var list<mixed> */
    private array $transactionOriginStack = [];
    /**
     * @var array{
     *     before: array<string, mixed>,
     *     beforeIdentity: array<string, mixed>,
     *     beforeNested: array<string, mixed>,
     *     beforeNestedIdentity: array<string, mixed>,
     *     beforeNestedTextAttributes: array<string, array<string, mixed>>,
     *     beforeNestedTextDeltas: array<string, list<array<string, mixed>>>,
     *     beforeTextDeltas: array<string, list<array<string, mixed>>>,
     *     beforeTextAttributes: array<string, array<string, mixed>>,
     *     beforeXmlTextDeltas: array<string, list<array<string, mixed>>>,
     *     beforeXmlTextValues: array<string, string>,
     *     beforeXmlElementAttributeValues: array<string, array<string, mixed>>,
     *     beforeXmlRootChildren: array<string, list<string>>,
     *     beforeXmlElementChildren: array<string, list<string>>,
     *     beforeXmlElementAttributes: array<string, array<string, mixed>>,
     *     beforeXmlElementAttributeOldValues: array<string, array<string, mixed>>,
     *     beforeStateVector: array<int, int>,
     *     structs: list<array<string, mixed>>,
     *     deleteSet: array<int, list<array{clock: int, length: int}>>,
     *     origin: mixed
     * }|null
     */
    private ?array $activeTransaction = null;
    /**
     * @var list<array{
     *     before: array<string, mixed>,
     *     beforeIdentity: array<string, mixed>,
     *     beforeNested: array<string, mixed>,
     *     beforeNestedIdentity: array<string, mixed>,
     *     beforeNestedTextAttributes: array<string, array<string, mixed>>,
     *     beforeNestedTextDeltas: array<string, list<array<string, mixed>>>,
     *     beforeTextDeltas: array<string, list<array<string, mixed>>>,
     *     beforeTextAttributes: array<string, array<string, mixed>>,
     *     beforeXmlTextDeltas: array<string, list<array<string, mixed>>>,
     *     beforeXmlTextValues: array<string, string>,
     *     beforeXmlElementAttributeValues: array<string, array<string, mixed>>,
     *     beforeXmlRootChildren: array<string, list<string>>,
     *     beforeXmlElementChildren: array<string, list<string>>,
     *     beforeXmlElementAttributes: array<string, array<string, mixed>>,
     *     beforeXmlElementAttributeOldValues: array<string, array<string, mixed>>,
     *     beforeStateVector: array<int, int>,
     *     structs: list<array<string, mixed>>,
     *     deleteSet: array<int, list<array{clock: int, length: int}>>,
     *     origin: mixed
     * }|null>
     */
    private array $pendingTransactions = [];
    private bool $isFlushingTransaction = false;
    private int $transactionDepth = 0;
    private int $nextObserverId = 1;
    private int $clientID;
    private string $guid;

    public function __construct(?int $clientID = null, ?string $guid = null, private readonly bool $gc = false)
    {
        $this->clientID = $clientID ?? random_int(1, 0x7fffffff);
        $this->guid = $guid ?? bin2hex(random_bytes(16));
    }

    public function clientID(): int
    {
        return $this->clientID;
    }

    public function setClientID(int $clientID): void
    {
        if ($this->structsById !== []) {
            throw new \LogicException('YDoc clientID cannot be changed after content has been created or applied.');
        }

        $this->clientID = $clientID;
    }

    public function guid(): string
    {
        return $this->guid;
    }

    public function gc(): bool
    {
        return $this->gc;
    }

    /**
     * @return list<YSubdoc>
     */
    public function getSubdocs(): array
    {
        return array_values($this->subdocsByGuid);
    }

    /**
     * @return list<string>
     */
    public function getSubdocGuids(): array
    {
        return array_keys($this->subdocsByGuid);
    }

    /** @internal */
    public function loadSubdoc(YSubdoc $subdoc): void
    {
        if ($subdoc->shouldLoad()) {
            return;
        }

        if ($this->activeTransaction !== null) {
            $this->activeTransaction['loadedSubdocs'][] = $subdoc;
            $subdoc->markLoaded();
            if (isset($this->subdocsByGuid[$subdoc->guid()])) {
                $this->subdocsByGuid[$subdoc->guid()] = $subdoc;
            }

            return;
        }

        $beforeStateVector = $this->getStateVector();
        $transactionEvent = [
            'origin' => null,
            'local' => true,
            'update' => null,
            'updateV2' => null,
            'beforeStateVector' => $beforeStateVector,
            'afterStateVector' => $beforeStateVector,
            'before' => $this->json,
            'after' => $this->json,
            'beforeTextAttributes' => $this->rootTextAttributeValues(),
            'afterTextAttributes' => $this->rootTextAttributeValues(),
            'beforeNested' => $this->nestedJsonById,
            'afterNested' => $this->nestedJsonById,
            'beforeNestedTextAttributes' => $this->nestedTextAttributeValues(),
            'afterNestedTextAttributes' => $this->nestedTextAttributeValues(),
            'beforeXmlText' => $this->xmlTextValues(),
            'afterXmlText' => $this->xmlTextValues(),
            'beforeXmlAttributes' => $this->xmlElementAttributeValues(),
            'afterXmlAttributes' => $this->xmlElementAttributeValues(),
            'beforeXmlRootChildren' => $this->xmlRootChildIdValues(),
            'afterXmlRootChildren' => $this->xmlRootChildIdValues(),
            'beforeXmlElementChildren' => $this->xmlElementChildIdValues(),
            'afterXmlElementChildren' => $this->xmlElementChildIdValues(),
            'beforeXmlRootSnapshots' => $this->xmlRootChildrenSnapshots(),
            'afterXmlRootSnapshots' => $this->xmlRootChildrenSnapshots(),
            'beforeXmlElementSnapshots' => $this->xmlElementChildrenSnapshots(),
            'afterXmlElementSnapshots' => $this->xmlElementChildrenSnapshots(),
            'changed' => [],
            'changedNestedTypes' => [],
            'changedXmlNodes' => [],
            'changedTypeNames' => [],
            'changedParentTypeNames' => [],
            'deleteSet' => [],
        ];

        $this->notifyEventObservers('beforeAllTransactions', [$this]);
        $this->notifyEventObservers('beforeTransaction', [$transactionEvent, $this]);
        $this->notifyEventObservers('beforeObserverCalls', [$transactionEvent, $this]);
        $this->notifyTransactionObservers($transactionEvent);
        $this->notifySubdocObservers([
            'loaded' => [$subdoc],
            'added' => [],
            'removed' => [],
        ], $transactionEvent);
        $this->notifyEventObservers('afterAllTransactions', [$this, [$transactionEvent]]);

        $subdoc->markLoaded();
        if (isset($this->subdocsByGuid[$subdoc->guid()])) {
            $this->subdocsByGuid[$subdoc->guid()] = $subdoc;
        }
    }

    public function applyUpdateV1(string $update, mixed $origin = null): void
    {
        $decoded = DecodedUpdate::decodeV1($update);
        $this->applyDecodedUpdate($decoded, $update, DecodedUpdate::encodeV2($decoded['structs'], $decoded['deleteSet']), $origin);
    }

    public function applyUpdateV2(string $update, mixed $origin = null): void
    {
        $decoded = DecodedUpdate::decodeV2($update);
        $this->applyDecodedUpdate($decoded, DecodedUpdate::encodeV1($decoded['structs'], $decoded['deleteSet']), $update, $origin);
    }

    public function transact(callable $callback, mixed $origin = null): mixed
    {
        $isOutermost = $this->transactionDepth === 0;
        if ($isOutermost) {
            $this->activeTransaction = [
                'before' => $this->json,
                'beforeIdentity' => $this->identityJson,
                'beforeNested' => $this->nestedJsonById,
                'beforeNestedIdentity' => $this->nestedIdentityById,
                'beforeNestedTextAttributes' => $this->nestedTextAttributeValues(),
                'beforeNestedTextDeltas' => $this->observedNestedTextDeltas(),
                'beforeTextDeltas' => $this->observedTextDeltas(),
                'beforeTextAttributes' => $this->rootTextAttributeValues(),
                'beforeXmlTextDeltas' => $this->observedXmlTextDeltas(),
                'beforeXmlTextValues' => $this->xmlTextValues(),
                'beforeXmlElementAttributeValues' => $this->xmlElementAttributeValues(),
                'beforeXmlRootChildren' => $this->observedXmlRootChildren(),
                'beforeXmlElementChildren' => $this->observedXmlElementChildren(),
                'beforeXmlElementAttributes' => $this->observedXmlElementAttributes(),
                'beforeXmlElementAttributeOldValues' => $this->observedXmlElementAttributeOldValues(),
                'beforeXmlRootChildrenAll' => $this->xmlRootChildIdValues(),
                'beforeXmlElementChildrenAll' => $this->xmlElementChildIdValues(),
                'beforeXmlRootSnapshots' => $this->xmlRootChildrenSnapshots(),
                'beforeXmlElementSnapshots' => $this->xmlElementChildrenSnapshots(),
                'beforeStateVector' => $this->getStateVector(),
                'structs' => [],
                'deleteSet' => [],
                'loadedSubdocs' => [],
                'origin' => $origin,
            ];
            $this->notifyEventObservers('beforeAllTransactions', [$this]);
            $this->notifyEventObservers('beforeTransaction', [$this->startedTransactionEvent($this->activeTransaction), $this]);
        }

        $this->transactionDepth++;
        $this->transactionOriginStack[] = $origin;

        try {
            return $callback($this);
        } finally {
            array_pop($this->transactionOriginStack);
            $this->transactionDepth--;

            if ($isOutermost) {
                $transaction = $this->activeTransaction;
                $this->activeTransaction = null;
                if ($this->isFlushingTransaction) {
                    $this->pendingTransactions[] = $transaction;
                } else {
                    $flushedTransactions = [];
                    $transactionEvent = $this->flushTransaction($transaction);
                    if ($transactionEvent !== null) {
                        $flushedTransactions[] = $transactionEvent;
                    }
                    array_push($flushedTransactions, ...$this->flushPendingTransactions());
                    if ($flushedTransactions !== []) {
                        $this->notifyEventObservers('afterAllTransactions', [$this, $flushedTransactions]);
                    }
                }
            }
        }
    }

    private function atomicMutation(callable $callback): mixed
    {
        if ($this->activeTransaction !== null) {
            return $callback();
        }

        return $this->transact($callback);
    }

    /**
     * @param callable(string, self, mixed): void $observer
     */
    public function observeUpdate(callable $observer): int
    {
        $observerId = $this->nextObserverId++;
        $this->updateObservers[$observerId] = $observer;

        return $observerId;
    }

    /**
     * @param callable(string, self, mixed): void $observer
     */
    public function observeUpdateOnce(callable $observer): int
    {
        $observerId = null;
        $observerId = $this->observeUpdate(function (string $update, self $doc, mixed $origin) use (&$observerId, $observer): void {
            if ($observerId !== null) {
                $this->unobserveUpdate($observerId);
            }

            $observer($update, $doc, $origin);
        });

        return $observerId;
    }

    public function unobserveUpdate(int $observerId): void
    {
        unset($this->updateObservers[$observerId]);
    }

    /**
     * @param callable(string, self, mixed): void $observer
     */
    public function observeUpdateV2(callable $observer): int
    {
        $observerId = $this->nextObserverId++;
        $this->updateV2Observers[$observerId] = $observer;

        return $observerId;
    }

    /**
     * @param callable(string, self, mixed): void $observer
     */
    public function observeUpdateV2Once(callable $observer): int
    {
        $observerId = null;
        $observerId = $this->observeUpdateV2(function (string $update, self $doc, mixed $origin) use (&$observerId, $observer): void {
            if ($observerId !== null) {
                $this->unobserveUpdateV2($observerId);
            }

            $observer($update, $doc, $origin);
        });

        return $observerId;
    }

    public function unobserveUpdateV2(int $observerId): void
    {
        unset($this->updateV2Observers[$observerId]);
    }

    /**
     * @param callable(array<string, mixed>, self): void $observer
     */
    public function observeTransaction(callable $observer): int
    {
        $observerId = $this->nextObserverId++;
        $this->transactionObservers[$observerId] = $observer;

        return $observerId;
    }

    /**
     * @param callable(array<string, mixed>, self): void $observer
     */
    public function observeTransactionOnce(callable $observer): int
    {
        $observerId = null;
        $observerId = $this->observeTransaction(function (array $event, self $doc) use (&$observerId, $observer): void {
            if ($observerId !== null) {
                $this->unobserveTransaction($observerId);
            }

            $observer($event, $doc);
        });

        return $observerId;
    }

    public function unobserveTransaction(int $observerId): void
    {
        unset($this->transactionObservers[$observerId]);
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
     * @param callable(array<string, mixed>, self): void $observer
     */
    public function observe(string $name, callable $observer, ?string $type = null): int
    {
        $observerId = $this->nextObserverId++;
        $this->sharedTypeObservers[$observerId] = [
            'name' => $name,
            'type' => $type,
            'observer' => $observer,
        ];

        return $observerId;
    }

    /**
     * @param callable(array<string, mixed>, self): void $observer
     */
    public function observeOnce(string $name, callable $observer, ?string $type = null): int
    {
        $observerId = null;
        $observerId = $this->observe($name, function (array $event, self $doc) use (&$observerId, $observer): void {
            if ($observerId !== null) {
                $this->unobserve($observerId);
            }

            $observer($event, $doc);
        }, $type);

        return $observerId;
    }

    public function unobserve(int $observerId): void
    {
        unset($this->sharedTypeObservers[$observerId]);
    }

    /**
     * @param callable(array<string, mixed>, self): void $observer
     */
    public function observeNestedType(string $idKey, string $type, callable $observer): int
    {
        $observerId = $this->nextObserverId++;
        $this->nestedTypeObservers[$observerId] = [
            'idKey' => $idKey,
            'type' => $type,
            'observer' => $observer,
        ];

        return $observerId;
    }

    /**
     * @param callable(array<string, mixed>, self): void $observer
     */
    public function observeNestedTypeOnce(string $idKey, string $type, callable $observer): int
    {
        $observerId = null;
        $observerId = $this->observeNestedType($idKey, $type, function (array $event, self $doc) use (&$observerId, $observer): void {
            if ($observerId !== null) {
                $this->unobserveNestedType($observerId);
            }

            $observer($event, $doc);
        });

        return $observerId;
    }

    public function unobserveNestedType(int $observerId): void
    {
        unset($this->nestedTypeObservers[$observerId]);
    }

    /**
     * @param callable(list<array<string, mixed>>, self, array<string, mixed>): void $observer
     */
    public function observeSharedTypeDeep(string $name, string $type, callable $observer): int
    {
        return $this->observeDeep(function (array $events, YDoc $doc, array $transaction) use ($name, $type, $observer): void {
            $typeEvents = [];

            foreach ($events as $event) {
                if (($event['target'] ?? null) === 'root' && ($event['name'] ?? null) === $name && ($event['type'] ?? null) === $type) {
                    $event['path'] = [];
                    $typeEvents[] = $event;
                    continue;
                }

                if (($event['target'] ?? null) !== 'nested') {
                    if (($event['target'] ?? null) !== 'xml') {
                        continue;
                    }

                    $location = $this->xmlNodeLocation((string) ($event['idKey'] ?? ''));
                    if ($location === null || $location['root'] !== $name) {
                        continue;
                    }

                    $event['path'] = $location['path'];
                    $typeEvents[] = $event;
                    continue;
                }

                $location = $this->nestedSharedTypeLocation((string) ($event['idKey'] ?? ''));
                if ($location === null || $location['root'] !== $name) {
                    continue;
                }

                $event['path'] = $location['path'];
                $typeEvents[] = $event;
            }

            if ($typeEvents !== []) {
                $observer($typeEvents, $doc, $transaction);
            }
        });
    }

    public function unobserveSharedTypeDeep(int $observerId): void
    {
        $this->unobserveDeep($observerId);
    }

    /**
     * @param callable(list<array<string, mixed>>, self, array<string, mixed>): void $observer
     */
    public function observeNestedTypeDeep(string $idKey, callable $observer): int
    {
        return $this->observeDeep(function (array $events, YDoc $doc, array $transaction) use ($idKey, $observer): void {
            $baseLocation = $this->nestedSharedTypeLocation($idKey);
            $typeEvents = [];

            foreach ($events as $event) {
                if (($event['target'] ?? null) === 'xml') {
                    if ($baseLocation === null) {
                        continue;
                    }

                    $location = $this->xmlNodeLocation((string) ($event['idKey'] ?? ''));
                    if (
                        $location === null
                        || $location['root'] !== $baseLocation['root']
                        || ! self::pathStartsWith($location['path'], $baseLocation['path'])
                    ) {
                        continue;
                    }

                    $event['path'] = array_slice($location['path'], count($baseLocation['path']));
                    $typeEvents[] = $event;
                    continue;
                }

                if (($event['target'] ?? null) !== 'nested') {
                    continue;
                }

                if (($event['idKey'] ?? null) === $idKey) {
                    $event['path'] = [];
                    $typeEvents[] = $event;
                    continue;
                }

                if ($baseLocation === null) {
                    continue;
                }

                $location = $this->nestedSharedTypeLocation((string) ($event['idKey'] ?? ''));
                if (
                    $location === null
                    || $location['root'] !== $baseLocation['root']
                    || ! self::pathStartsWith($location['path'], $baseLocation['path'])
                ) {
                    continue;
                }

                $event['path'] = array_slice($location['path'], count($baseLocation['path']));
                $typeEvents[] = $event;
            }

            if ($typeEvents !== []) {
                $observer($typeEvents, $doc, $transaction);
            }
        });
    }

    public function unobserveNestedTypeDeep(int $observerId): void
    {
        $this->unobserveDeep($observerId);
    }

    /**
     * @param callable(array<string, mixed>, self): void $observer
     */
    public function observeXmlNode(string $idKey, callable $observer): int
    {
        $observerId = $this->nextObserverId++;
        $this->xmlNodeObservers[$observerId] = [
            'idKey' => $idKey,
            'observer' => $observer,
        ];

        return $observerId;
    }

    /**
     * @param callable(array<string, mixed>, self): void $observer
     */
    public function observeXmlNodeOnce(string $idKey, callable $observer): int
    {
        $observerId = null;
        $observerId = $this->observeXmlNode($idKey, function (array $event, self $doc) use (&$observerId, $observer): void {
            if ($observerId !== null) {
                $this->unobserveXmlNode($observerId);
            }

            $observer($event, $doc);
        });

        return $observerId;
    }

    public function unobserveXmlNode(int $observerId): void
    {
        unset($this->xmlNodeObservers[$observerId]);
    }

    /**
     * @param callable(list<array<string, mixed>>, self, array<string, mixed>): void $observer
     */
    public function observeXmlNodeDeep(string $idKey, callable $observer): int
    {
        return $this->observeDeep(function (array $events, YDoc $doc, array $transaction) use ($idKey, $observer): void {
            $baseLocation = $this->xmlNodeLocation($idKey);
            $isVisible = $baseLocation !== null && $this->xmlNodeIsVisible($idKey);
            $basePath = $baseLocation['path'] ?? [];
            $baseRoot = $baseLocation['root'] ?? null;
            $nodeEvents = [];

            foreach ($events as $event) {
                if (($event['target'] ?? null) === 'nested') {
                    if ($baseRoot === null) {
                        continue;
                    }

                    $location = $this->nestedSharedTypeLocation((string) ($event['idKey'] ?? ''));
                    if (
                        $location === null
                        || $location['root'] !== $baseRoot
                        || ! self::pathStartsWith($location['path'], $basePath)
                    ) {
                        continue;
                    }

                    $event['path'] = array_slice($location['path'], count($basePath));
                    $nodeEvents[] = $event;
                    continue;
                }

                if (($event['target'] ?? null) !== 'xml') {
                    continue;
                }

                if (($event['idKey'] ?? null) === $idKey) {
                    $event['path'] = [];
                    $nodeEvents[] = $event;
                    continue;
                }

                if (! $isVisible || $baseRoot === null) {
                    continue;
                }

                $location = $this->xmlNodeLocation((string) ($event['idKey'] ?? ''));
                if (
                    $location === null
                    || $location['root'] !== $baseRoot
                    || ! self::pathStartsWith($location['path'], $basePath)
                ) {
                    continue;
                }

                $event['path'] = array_slice($location['path'], count($basePath));
                $nodeEvents[] = $event;
            }

            if ($nodeEvents !== []) {
                $observer($nodeEvents, $doc, $transaction);
            }
        });
    }

    public function unobserveXmlNodeDeep(int $observerId): void
    {
        $this->unobserveDeep($observerId);
    }

    /**
     * @param callable(list<array<string, mixed>>, self, array<string, mixed>): void $observer
     */
    public function observeDeep(callable $observer): int
    {
        $observerId = $this->nextObserverId++;
        $this->deepObservers[$observerId] = $observer;

        return $observerId;
    }

    /**
     * @param callable(list<array<string, mixed>>, self, array<string, mixed>): void $observer
     */
    public function observeDeepOnce(callable $observer): int
    {
        $observerId = null;
        $observerId = $this->observeDeep(function (array $events, self $doc, array $transaction) use (&$observerId, $observer): void {
            if ($observerId !== null) {
                $this->unobserveDeep($observerId);
            }

            $observer($events, $doc, $transaction);
        });

        return $observerId;
    }

    public function unobserveDeep(int $observerId): void
    {
        unset($this->deepObservers[$observerId]);
    }

    /**
     * @param array{
     *     structs: list<array<string, mixed>>,
     *     deleteSet: array<int, list<array{clock: int, length: int}>>
     * } $decoded
     */
    private function applyDecodedUpdate(array $decoded, ?string $observedUpdate = null, ?string $observedUpdateV2 = null, mixed $origin = null): void
    {
        $before = $this->json;
        $beforeIdentity = $this->identityJson;
        $beforeNested = $this->nestedJsonById;
        $beforeNestedIdentity = $this->nestedIdentityById;
        $beforeNestedTextAttributes = $this->nestedTextAttributeValues();
        $beforeNestedTextDeltas = $this->observedNestedTextDeltas();
        $beforeTextDeltas = $this->observedTextDeltas();
        $beforeTextAttributes = $this->rootTextAttributeValues();
        $beforeXmlTextDeltas = $this->observedXmlTextDeltas();
        $beforeXmlTextValues = $this->xmlTextValues();
        $beforeXmlElementAttributeValues = $this->xmlElementAttributeValues();
        $beforeXmlRootChildren = $this->observedXmlRootChildren();
        $beforeXmlElementChildren = $this->observedXmlElementChildren();
        $beforeXmlElementAttributes = $this->observedXmlElementAttributes();
        $beforeXmlElementAttributeOldValues = $this->observedXmlElementAttributeOldValues();
        $beforeXmlRootChildrenAll = $this->xmlRootChildIdValues();
        $beforeXmlElementChildrenAll = $this->xmlElementChildIdValues();
        $beforeXmlRootSnapshots = $this->xmlRootChildrenSnapshots();
        $beforeXmlElementSnapshots = $this->xmlElementChildrenSnapshots();
        $beforeStateVector = $this->getStateVector();
        $startedRemoteTransactionEvent = $this->startedRemoteTransactionEvent(
            $origin,
            $beforeStateVector,
            $before,
            $beforeTextAttributes,
            $beforeNested,
            $beforeNestedTextAttributes,
            $beforeXmlTextValues,
            $beforeXmlElementAttributeValues,
            $beforeXmlRootChildrenAll,
            $beforeXmlElementChildrenAll,
            $beforeXmlRootSnapshots,
            $beforeXmlElementSnapshots
        );
        $this->notifyEventObservers('beforeAllTransactions', [$this]);
        $this->notifyEventObservers('beforeTransaction', [$startedRemoteTransactionEvent, $this]);
        $effectiveStructs = [];
        $effectiveDeleteSet = [];

        foreach ($decoded['structs'] as $struct) {
            foreach ($this->uncoveredStructSegments($struct) as $segment) {
                $this->structsById[self::idKey($segment['id'])] = $segment;
                $effectiveStructs[] = $segment;
            }
        }

        foreach ($decoded['deleteSet'] as $client => $deletes) {
            foreach ($deletes as $delete) {
                foreach ($this->uncoveredDeleteSegments((int) $client, $delete) as $segment) {
                    $this->deleteSet[$client][] = $segment;
                    $effectiveDeleteSet[$client][] = $segment;
                }
            }
        }

        foreach ($this->mapKeyConflictDeleteSet() as $client => $deletes) {
            foreach ($deletes as $delete) {
                foreach ($this->uncoveredDeleteSegments((int) $client, $delete) as $segment) {
                    $this->deleteSet[$client][] = $segment;
                    $effectiveDeleteSet[$client][] = $segment;
                }
            }
        }

        foreach ($this->deletedParentCascadeDeleteSet() as $client => $deletes) {
            foreach ($deletes as $delete) {
                foreach ($this->uncoveredDeleteSegments((int) $client, $delete) as $segment) {
                    $this->deleteSet[$client][] = $segment;
                    $effectiveDeleteSet[$client][] = $segment;
                }
            }
        }

        if ($effectiveStructs === [] && $effectiveDeleteSet === []) {
            $transactionEvent = $startedRemoteTransactionEvent;
            $transactionEvent['update'] = $observedUpdate;
            $transactionEvent['updateV2'] = $observedUpdateV2;
            $transactionEvent['afterStateVector'] = $this->getStateVector();
            $transactionEvent['after'] = $this->json;
            $transactionEvent['afterTextAttributes'] = $this->rootTextAttributeValues();
            $transactionEvent['afterNested'] = $this->nestedJsonById;
            $transactionEvent['afterNestedTextAttributes'] = $this->nestedTextAttributeValues();
            $transactionEvent['afterXmlText'] = $this->xmlTextValues();
            $transactionEvent['afterXmlAttributes'] = $this->xmlElementAttributeValues();
            $transactionEvent['afterXmlRootChildren'] = $this->xmlRootChildIdValues();
            $transactionEvent['afterXmlElementChildren'] = $this->xmlElementChildIdValues();
            $transactionEvent['afterXmlRootSnapshots'] = $this->xmlRootChildrenSnapshots();
            $transactionEvent['afterXmlElementSnapshots'] = $this->xmlElementChildrenSnapshots();
            $this->notifyEventObservers('beforeObserverCalls', [$transactionEvent, $this]);
            $this->notifyTransactionObservers($transactionEvent);
            $this->notifySubdocObservers($this->subdocChanges([], []), $transactionEvent);
            $this->notifyEventObservers('afterAllTransactions', [$this, [$transactionEvent]]);

            return;
        }

        $subdocChanges = $this->subdocChanges($effectiveStructs, $effectiveDeleteSet);
        $changedNames = $this->topLevelNamesForChange($effectiveStructs, $effectiveDeleteSet);
        $directChangedNames = $this->directTopLevelNamesForChange($effectiveStructs, $effectiveDeleteSet);
        $changedXmlNodeIds = $this->xmlNodeIdsForChange($effectiveStructs, $effectiveDeleteSet);
        $directChangedXmlNodeIds = $this->directXmlNodeIdsForChange($effectiveStructs, $effectiveDeleteSet);
        $changedNestedTypeIds = $this->nestedTypeIdsForChange($effectiveStructs, $effectiveDeleteSet);
        $directChangedNestedTypeIds = $this->directNestedTypeIdsForChange($effectiveStructs, $effectiveDeleteSet);
        $directTransactionChangedNestedTypeIds = $this->filterNewlyCreatedSharedTypeIds($directChangedNestedTypeIds, $effectiveStructs);
        $directTransactionChangedXmlNodeIds = $this->filterNewlyCreatedSharedTypeIds($directChangedXmlNodeIds, $effectiveStructs);
        $this->rematerialize();
        $changedXmlAttributes = $this->xmlAttributeNamesForChange($effectiveStructs, $effectiveDeleteSet);
        $changedNames += $this->changedRootNamesByValue($before, $beforeIdentity);

        if ($before === $this->json && $beforeTextAttributes === $this->rootTextAttributeValues() && $beforeNestedTextAttributes === $this->nestedTextAttributeValues() && $beforeNested === $this->nestedJsonById && $changedNames === [] && $changedXmlNodeIds === [] && $changedNestedTypeIds === []) {
            $transactionEvent = null;
            if ($observedUpdate !== null && $observedUpdateV2 !== null) {
                $transactionEvent = [
                    'origin' => $origin,
                    'local' => false,
                    'update' => $observedUpdate,
                    'updateV2' => $observedUpdateV2,
                    'beforeStateVector' => $beforeStateVector,
                    'afterStateVector' => $this->getStateVector(),
                    'before' => $before,
                    'after' => $this->json,
                    'beforeTextAttributes' => $beforeTextAttributes,
                    'afterTextAttributes' => $this->rootTextAttributeValues(),
                    'beforeNested' => $beforeNested,
                    'afterNested' => $this->nestedJsonById,
                    'beforeNestedTextAttributes' => $beforeNestedTextAttributes,
                    'afterNestedTextAttributes' => $this->nestedTextAttributeValues(),
                    'beforeXmlText' => $beforeXmlTextValues,
                    'afterXmlText' => $this->xmlTextValues(),
                    'beforeXmlAttributes' => $beforeXmlElementAttributeValues,
                    'afterXmlAttributes' => $this->xmlElementAttributeValues(),
                    'changed' => [],
                    'changedNestedTypes' => [],
                    'changedXmlNodes' => [],
                    'changedTypeNames' => [],
                    'changedParentTypeNames' => [],
                    'deleteSet' => UpdateUtils::mergeDeleteSets([$effectiveDeleteSet]),
                ];
                $this->notifyEventObservers('beforeObserverCalls', [$transactionEvent, $this]);
                $this->notifyTransactionObservers($transactionEvent);
            }

            if ($observedUpdate !== null) {
                $this->notifyUpdateObservers($observedUpdate, $origin, $transactionEvent);
            }

            if ($observedUpdateV2 !== null) {
                $this->notifyUpdateV2Observers($observedUpdateV2, $origin, $transactionEvent);
            }

            if ($transactionEvent !== null) {
                $this->notifySubdocObservers($subdocChanges, $transactionEvent);
                $this->notifyEventObservers('afterAllTransactions', [$this, [$transactionEvent]]);
            }

            return;
        }

        $transactionEvent = null;
        if ($observedUpdate !== null && $observedUpdateV2 !== null) {
            $transactionEvent = [
                'origin' => $origin,
                'local' => false,
                'update' => $observedUpdate,
                'updateV2' => $observedUpdateV2,
                'beforeStateVector' => $beforeStateVector,
                'afterStateVector' => $this->getStateVector(),
                'before' => $before,
                'after' => $this->json,
                'beforeTextAttributes' => $beforeTextAttributes,
                'afterTextAttributes' => $this->rootTextAttributeValues(),
                'beforeNested' => $beforeNested,
                'afterNested' => $this->nestedJsonById,
                'beforeNestedTextAttributes' => $beforeNestedTextAttributes,
                'afterNestedTextAttributes' => $this->nestedTextAttributeValues(),
                'beforeXmlText' => $beforeXmlTextValues,
                'afterXmlText' => $this->xmlTextValues(),
                'beforeXmlAttributes' => $beforeXmlElementAttributeValues,
                'afterXmlAttributes' => $this->xmlElementAttributeValues(),
                'beforeXmlRootChildren' => $beforeXmlRootChildrenAll,
                'afterXmlRootChildren' => $this->xmlRootChildIdValues(),
                'beforeXmlElementChildren' => $beforeXmlElementChildrenAll,
                'afterXmlElementChildren' => $this->xmlElementChildIdValues(),
                'beforeXmlRootSnapshots' => $beforeXmlRootSnapshots,
                'afterXmlRootSnapshots' => $this->xmlRootChildrenSnapshots(),
                'beforeXmlElementSnapshots' => $beforeXmlElementSnapshots,
                'afterXmlElementSnapshots' => $this->xmlElementChildrenSnapshots(),
                'changed' => array_values(array_keys($changedNames)),
                'changedNestedTypes' => array_values(array_keys($changedNestedTypeIds)),
                'changedXmlNodes' => array_values(array_keys($changedXmlNodeIds)),
                'changedTypeNames' => $this->transactionChangedTypeNames($directChangedNames, $directTransactionChangedNestedTypeIds, $directTransactionChangedXmlNodeIds),
                'changedParentTypeNames' => $this->transactionChangedParentTypeNames($directChangedNames, $directTransactionChangedNestedTypeIds, $directTransactionChangedXmlNodeIds),
                'deleteSet' => UpdateUtils::mergeDeleteSets([$effectiveDeleteSet]),
            ];
            $this->notifyEventObservers('beforeObserverCalls', [$transactionEvent, $this]);
        }

        $this->notifySharedTypeObservers($before, $beforeIdentity, $observedUpdate, $observedUpdateV2, $origin, $changedNames, $beforeTextDeltas, $beforeTextAttributes, $beforeXmlRootChildren);
        $this->notifyNestedTypeObservers($beforeNested, $beforeNestedIdentity, $beforeNestedTextAttributes, $beforeNestedTextDeltas, $observedUpdate, $observedUpdateV2, $origin, $changedNestedTypeIds);
        $this->notifyXmlNodeObservers($observedUpdate, $observedUpdateV2, $origin, $changedXmlNodeIds, $beforeXmlTextDeltas, $beforeXmlElementChildren, $beforeXmlElementAttributes, $beforeXmlElementAttributeOldValues, $changedXmlAttributes);

        if ($transactionEvent !== null) {
            $this->notifyDeepObservers($this->deepEvents($before, $beforeIdentity, $beforeNested, $beforeNestedIdentity, $beforeNestedTextAttributes, $beforeNestedTextDeltas, $beforeTextDeltas, $beforeTextAttributes, $beforeXmlTextDeltas, $beforeXmlRootChildren, $beforeXmlElementChildren, $beforeXmlElementAttributes, $beforeXmlElementAttributeOldValues, $changedXmlAttributes, $observedUpdate, $observedUpdateV2, $origin, $directChangedNames, $changedNestedTypeIds, $changedXmlNodeIds), $transactionEvent);
            $this->notifyTransactionObservers($transactionEvent);
        }

        if ($observedUpdate !== null) {
            $this->notifyUpdateObservers($observedUpdate, $origin, $transactionEvent);
        }

        if ($observedUpdateV2 !== null) {
            $this->notifyUpdateV2Observers($observedUpdateV2, $origin, $transactionEvent);
        }

        if ($transactionEvent !== null) {
            $this->notifySubdocObservers($subdocChanges, $transactionEvent);
            $this->notifyEventObservers('afterAllTransactions', [$this, [$transactionEvent]]);
        }
    }

    /**
     * @param array{clock: int, length: int} $delete
     * @return list<array{clock: int, length: int}>
     */
    private function uncoveredDeleteSegments(int $client, array $delete): array
    {
        $segments = [
            [
                'clock' => $delete['clock'],
                'end' => $delete['clock'] + $delete['length'],
            ],
        ];

        foreach ($this->normalizedDeleteRangesForClient($client) as $existing) {
            $next = [];
            $existingStart = $existing['clock'];
            $existingEnd = $existing['clock'] + $existing['length'];

            foreach ($segments as $segment) {
                if ($segment['end'] <= $existingStart || $segment['clock'] >= $existingEnd) {
                    $next[] = $segment;
                    continue;
                }

                if ($segment['clock'] < $existingStart) {
                    $next[] = [
                        'clock' => $segment['clock'],
                        'end' => $existingStart,
                    ];
                }

                if ($segment['end'] > $existingEnd) {
                    $next[] = [
                        'clock' => $existingEnd,
                        'end' => $segment['end'],
                    ];
                }
            }

            $segments = $next;
            if ($segments === []) {
                break;
            }
        }

        return array_values(array_map(
            static fn (array $segment): array => [
                'clock' => $segment['clock'],
                'length' => $segment['end'] - $segment['clock'],
            ],
            array_filter($segments, static fn (array $segment): bool => $segment['end'] > $segment['clock'])
        ));
    }

    /**
     * @return list<array{clock: int, length: int}>
     */
    private function normalizedDeleteRangesForClient(int $client): array
    {
        $deletes = $this->deleteSet[$client] ?? [];
        usort($deletes, static fn (array $left, array $right): int => $left['clock'] <=> $right['clock']);
        $normalized = [];

        foreach ($deletes as $delete) {
            if ($delete['length'] <= 0) {
                continue;
            }

            $lastIndex = count($normalized) - 1;
            if ($lastIndex >= 0) {
                $last = $normalized[$lastIndex];
                $lastEnd = $last['clock'] + $last['length'];
                $deleteEnd = $delete['clock'] + $delete['length'];

                if ($delete['clock'] <= $lastEnd) {
                    $normalized[$lastIndex]['length'] = max($lastEnd, $deleteEnd) - $last['clock'];
                    continue;
                }
            }

            $normalized[] = $delete;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $struct
     * @return list<array<string, mixed>>
     */
    private function uncoveredStructSegments(array $struct): array
    {
        $client = $struct['id']['client'];
        $segments = [
            [
                'clock' => $struct['id']['clock'],
                'end' => $struct['id']['clock'] + $struct['length'],
            ],
        ];

        foreach ($this->normalizedStructRangesForClient($client) as $existing) {
            $next = [];
            $existingStart = $existing['clock'];
            $existingEnd = $existing['clock'] + $existing['length'];

            foreach ($segments as $segment) {
                if ($segment['end'] <= $existingStart || $segment['clock'] >= $existingEnd) {
                    $next[] = $segment;
                    continue;
                }

                if ($segment['clock'] < $existingStart) {
                    $next[] = [
                        'clock' => $segment['clock'],
                        'end' => $existingStart,
                    ];
                }

                if ($segment['end'] > $existingEnd) {
                    $next[] = [
                        'clock' => $existingEnd,
                        'end' => $segment['end'],
                    ];
                }
            }

            $segments = $next;
            if ($segments === []) {
                break;
            }
        }

        return array_values(array_map(
            fn (array $segment): array => $this->sliceStructToRange($struct, $segment['clock'], $segment['end']),
            array_filter($segments, static fn (array $segment): bool => $segment['end'] > $segment['clock'])
        ));
    }

    /**
     * @return list<array{clock: int, length: int}>
     */
    private function normalizedStructRangesForClient(int $client): array
    {
        $ranges = [];

        foreach ($this->structsById as $struct) {
            if ($struct['id']['client'] !== $client) {
                continue;
            }

            $ranges[] = [
                'clock' => $struct['id']['clock'],
                'length' => $struct['length'],
            ];
        }

        usort($ranges, static fn (array $left, array $right): int => $left['clock'] <=> $right['clock']);
        $normalized = [];

        foreach ($ranges as $range) {
            if ($range['length'] <= 0) {
                continue;
            }

            $lastIndex = count($normalized) - 1;
            if ($lastIndex >= 0) {
                $last = $normalized[$lastIndex];
                $lastEnd = $last['clock'] + $last['length'];
                $rangeEnd = $range['clock'] + $range['length'];

                if ($range['clock'] <= $lastEnd) {
                    $normalized[$lastIndex]['length'] = max($lastEnd, $rangeEnd) - $last['clock'];
                    continue;
                }
            }

            $normalized[] = $range;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $struct
     * @return array<string, mixed>
     */
    private function sliceStructToRange(array $struct, int $startClock, int $endClock): array
    {
        $structStart = $struct['id']['clock'];
        $structEnd = $structStart + $struct['length'];

        if ($startClock < $structStart || $endClock > $structEnd || $startClock >= $endClock) {
            throw new \InvalidArgumentException('Struct slice range must be inside the struct.');
        }

        if ($startClock === $structStart && $endClock === $structEnd) {
            return $struct;
        }

        $offset = $startClock - $structStart;
        $length = $endClock - $startClock;
        $sliced = $struct;
        $sliced['id'] = [
            'client' => $struct['id']['client'],
            'clock' => $startClock,
        ];
        $sliced['length'] = $length;

        if (($struct['type'] ?? null) === 'Item') {
            if ($offset > 0) {
                $sliced['origin'] = [
                    'client' => $struct['id']['client'],
                    'clock' => $startClock - 1,
                ];
                $sliced['parent'] = null;
                $sliced['parentSub'] = null;
            }

            if ($endClock < $structEnd) {
                $sliced['rightOrigin'] = [
                    'client' => $struct['id']['client'],
                    'clock' => $endClock,
                ];
            }

            $sliced['content'] = $this->sliceContentToRange($struct['content'], $offset, $length);
        }

        return $sliced;
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    private function sliceContentToRange(array $content, int $offset, int $length): array
    {
        $sliced = $content;

        switch ($content['type']) {
            case 'ContentString':
                $sliced['value'] = self::sliceUtf16CodeUnits($content['value'], $offset, $length);
                break;
            case 'ContentAny':
            case 'ContentJSON':
                $sliced['values'] = array_slice($content['values'], $offset, $length);
                break;
            case 'ContentDeleted':
                $sliced['length'] = $length;
                break;
            default:
                if ($offset !== 0 || $length !== 1) {
                    throw new \UnexpectedValueException(sprintf('Cannot slice content type "%s".', $content['type']));
                }
                break;
        }

        return $sliced;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function structsForSnapshot(Snapshot $snapshot): array
    {
        $stateVector = $snapshot->stateVector();
        $structs = [];

        foreach ($this->sortedStructs() as $struct) {
            $client = $struct['id']['client'];
            $snapshotClock = $stateVector[$client] ?? 0;
            $structStart = $struct['id']['clock'];
            $structEnd = $structStart + $struct['length'];

            if ($snapshotClock <= $structStart) {
                continue;
            }

            $structs[] = $this->sliceStructToRange($struct, $structStart, min($structEnd, $snapshotClock));
        }

        return $structs;
    }

    /**
     * @return array<int, int>
     */
    public function getStateVector(): array
    {
        $stateVector = [];
        $rangesByClient = [];

        foreach ($this->structsById as $struct) {
            $client = $struct['id']['client'];
            $rangesByClient[$client][] = [
                'clock' => $struct['id']['clock'],
                'length' => $struct['length'],
            ];
        }

        foreach ($rangesByClient as $client => $ranges) {
            usort($ranges, static fn (array $left, array $right): int => $left['clock'] <=> $right['clock']);
            $clock = 0;

            foreach ($ranges as $range) {
                if ($range['length'] <= 0) {
                    continue;
                }

                $rangeStart = $range['clock'];
                $rangeEnd = $rangeStart + $range['length'];

                if ($rangeEnd <= $clock) {
                    continue;
                }

                if ($rangeStart > $clock) {
                    break;
                }

                $clock = $rangeEnd;
            }

            if ($clock > 0) {
                $stateVector[$client] = $clock;
            }
        }

        krsort($stateVector, SORT_NUMERIC);

        return $stateVector;
    }

    public function encodeStateVector(): string
    {
        return StateVector::encode($this->getStateVector());
    }

    public function snapshot(): Snapshot
    {
        return new Snapshot($this->deleteSet, $this->getStateVector());
    }

    public function createDocFromSnapshot(Snapshot $snapshot, ?int $clientID = null, ?string $guid = null): self
    {
        $doc = new self($clientID, $guid);

        foreach ($this->structsForSnapshot($snapshot) as $struct) {
            $doc->structsById[self::idKey($struct['id'])] = $struct;
        }

        $doc->deleteSet = $snapshot->deleteSet();
        $doc->rematerialize();

        return $doc;
    }

    public function encodeStateAsUpdateV1(?string $encodedTargetStateVector = null): string
    {
        $targetStateVector = $encodedTargetStateVector === null ? [] : StateVector::decode($encodedTargetStateVector);

        return DecodedUpdate::encodeV1($this->structsForEncoding(), $this->deleteSet, $targetStateVector);
    }

    public function encodeStateAsUpdateV2(?string $encodedTargetStateVector = null): string
    {
        $targetStateVector = $encodedTargetStateVector === null ? [] : StateVector::decode($encodedTargetStateVector);

        return DecodedUpdate::encodeV2($this->structsForEncoding(), $this->deleteSet, $targetStateVector);
    }

    /**
     * @return array<string, mixed>
     */
    public function toJSON(): array
    {
        return $this->jsonValue($this->json);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function textDelta(string $name): array
    {
        return $this->textDeltaFromItems($this->collectSequenceItemsForParent($name));
    }

    public function getText(string $name): YText
    {
        $this->registerRootType($name, 'text');

        return new YText($this, $name, (string) ($this->json[$name] ?? ''));
    }

    public function textValue(string $name): string
    {
        if (($this->rootTypeHints[$name] ?? null) === 'text' && ! array_key_exists($name, $this->json)) {
            return '';
        }

        return (string) ($this->json[$name] ?? '');
    }

    public function setTextAttribute(string $name, string $key, mixed $value): void
    {
        $this->registerRootType($name, 'text');

        $this->atomicMutation(function () use ($name, $key, $value): void {
            $previous = $this->findLatestMapStruct($name, $key);
            if ($previous !== null && ! $this->isStructDeleted($previous)) {
                $this->deleteMapStruct($previous);
            }

            $this->addLocalStruct($name, $previous === null ? $key : null, [
                'type' => 'ContentAny',
                'values' => [$value],
            ], $previous['id'] ?? null);
        });
    }

    public function removeTextAttribute(string $name, string $key): void
    {
        $this->registerRootType($name, 'text');

        $struct = $this->findVisibleMapStruct($name, $key);
        if ($struct !== null) {
            $this->deleteMapStruct($struct);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function textAttributes(string $name): array
    {
        $attributes = $this->rootTextAttributesByName[$name] ?? [];
        ksort($attributes, SORT_STRING);

        return $attributes;
    }

    public function textAttribute(string $name, string $key): mixed
    {
        return $this->textAttributes($name)[$key] ?? null;
    }

    public function textHasAttribute(string $name, string $key): bool
    {
        return array_key_exists($key, $this->textAttributes($name));
    }

    public function getArray(string $name): YArray
    {
        $this->registerRootType($name, 'array');

        return new YArray($this, $name, $this->arrayValue($name));
    }

    /**
     * @return list<mixed>
     */
    public function arrayValue(string $name): array
    {
        $value = $this->json[$name] ?? [];

        if (! is_array($value)) {
            throw new \UnexpectedValueException(sprintf('Shared type "%s" is not an array.', $name));
        }

        if (! array_is_list($value)) {
            throw new \UnexpectedValueException(sprintf('Shared type "%s" is not an array.', $name));
        }

        return $value;
    }

    public function sharedTypeInArray(string $name, int $index): YNestedArray|YNestedMap|YNestedText|YNestedXmlFragment|YXmlElement|YXmlText|YXmlHook|null
    {
        $identity = $this->identityJson[$name] ?? $this->arrayValue($name);
        if (! is_array($identity) || ! array_is_list($identity) || ! array_key_exists($index, $identity)) {
            return null;
        }

        $idKey = $this->sharedTypeIdFromIdentityValue($identity[$index]);

        return $idKey === null ? null : $this->nestedSharedType($idKey);
    }

    public function getMap(string $name): YMap
    {
        $this->registerRootType($name, 'map');

        return new YMap($this, $name, $this->mapValue($name));
    }

    /**
     * @return array<string, mixed>
     */
    public function mapValue(string $name): array
    {
        $value = $this->json[$name] ?? [];

        if (! is_array($value)) {
            throw new \UnexpectedValueException(sprintf('Shared type "%s" is not a map.', $name));
        }

        return $value;
    }

    public function sharedTypeInMap(string $name, string $key): YNestedArray|YNestedMap|YNestedText|YNestedXmlFragment|YXmlElement|YXmlText|YXmlHook|null
    {
        $identity = $this->identityJson[$name] ?? $this->mapValue($name);
        if (! is_array($identity) || ! array_key_exists($key, $identity)) {
            return null;
        }

        $idKey = $this->sharedTypeIdFromIdentityValue($identity[$key]);

        return $idKey === null ? null : $this->nestedSharedType($idKey);
    }

    public function getXmlFragment(string $name): YXmlFragment
    {
        $this->registerRootType($name, 'xml');

        return new YXmlFragment($this, $name, (string) ($this->json[$name] ?? ''));
    }

    public function xmlFragmentValue(string $name): string
    {
        return (string) ($this->json[$name] ?? '');
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function insertText(string $name, int $index, string $text, array $attributes = []): void
    {
        if ($index < 0 || $index > $this->sequenceLength($name, 'text')) {
            throw new \InvalidArgumentException('YText insert index is out of bounds.');
        }

        if ($text === '') {
            return;
        }

        $bounds = $this->sequenceBoundsAt($name, $index, 'text');
        $origin = $bounds['origin'];
        $rightOrigin = $bounds['rightOrigin'];

        foreach ($attributes as $key => $value) {
            $struct = $this->addLocalFormatStruct($name, (string) $key, $value, $origin, $rightOrigin);
            $origin = $struct['id'];
            $rightOrigin = null;
        }

        $this->addLocalStruct($name, null, [
            'type' => 'ContentString',
            'value' => $text,
        ], $origin, $rightOrigin);

        if ($attributes !== []) {
            $lastId = [
                'client' => $this->clientID,
                'clock' => ($this->getStateVector()[$this->clientID] ?? 0) - 1,
            ];

            foreach (array_reverse(array_keys($attributes)) as $key) {
                $struct = $this->addLocalFormatStruct($name, (string) $key, null, $lastId, $bounds['rightOrigin']);
                $lastId = $struct['id'];
            }
        }
    }

    public function deleteText(string $name, int $index, int $length): void
    {
        $currentLength = $this->sequenceLength($name, 'text');
        if ($index < 0 || $length < 0 || $index + $length > $currentLength) {
            throw new \InvalidArgumentException('YText delete range is out of bounds.');
        }

        $this->deleteSequenceRange($name, $index, $length);
    }

    public function createRelativePosition(string $name, int $index, int $assoc = 0, string $mode = 'text'): RelativePosition
    {
        if ($mode !== 'text' && $mode !== 'array') {
            throw new \InvalidArgumentException('Relative position mode must be "text" or "array".');
        }

        $length = $this->sequenceLength($name, $mode);
        if ($index < 0 || $index > $length) {
            throw new \InvalidArgumentException(sprintf('Cannot create relative position at index %d in shared type "%s".', $index, $name));
        }

        return $this->relativePositionFromRootSequence($name, $index, $assoc, $mode);
    }

    public function createRelativePositionForTypeId(string $idKey, int $index, int $assoc = 0, string $mode = 'text'): RelativePosition
    {
        if ($mode !== 'text' && $mode !== 'array') {
            throw new \InvalidArgumentException('Relative position mode must be "text" or "array".');
        }

        $length = $this->sequenceLengthForParentId($idKey, $mode);
        if ($index < 0 || $index > $length) {
            throw new \InvalidArgumentException('Cannot create relative position at index ' . $index . ' in nested shared type.');
        }

        return $this->relativePositionFromNestedSequence($idKey, $index, $assoc, $mode);
    }

    public function absolutePositionFromRelativePosition(RelativePosition $position): ?AbsolutePosition
    {
        if ($position->item() !== null) {
            $typeName = $position->typeName();
            if ($typeName !== null) {
                return $this->absolutePositionFromRootItem($typeName, $position->item(), $position->assoc());
            }

            return $this->absolutePositionFromAnyItem($position->item(), $position->assoc());
        }

        if ($position->typeName() !== null) {
            $typeName = $position->typeName();
            $index = $position->assoc() >= 0 ? $this->rootTypeLength($typeName) : 0;

            return new AbsolutePosition($this->rootType($typeName), $typeName, $index, $position->assoc());
        }

        if ($position->type() !== null) {
            $typeId = $position->type();
            $typeIdKey = self::idKey($typeId);
            $type = $this->nestedSequenceType($typeIdKey);
            if ($type === null) {
                return null;
            }

            $mode = $this->nestedSequenceMode($typeIdKey);
            if ($mode === null) {
                return null;
            }

            $index = $position->assoc() >= 0 ? $this->sequenceLengthForParentId($typeIdKey, $mode) : 0;

            return new AbsolutePosition($type, $this->nestedSequenceTypeName($typeIdKey), $index, $position->assoc(), $typeId);
        }

        throw new \UnexpectedValueException('Relative position must reference an item, type name, or type ID.');
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function insertTextEmbed(string $name, int $index, mixed $embed, array $attributes = []): void
    {
        if ($index < 0 || $index > $this->sequenceLength($name, 'text')) {
            throw new \InvalidArgumentException('YText embed insert index is out of bounds.');
        }

        $bounds = $this->sequenceBoundsAt($name, $index, 'text');
        $origin = $bounds['origin'];
        $rightOrigin = $bounds['rightOrigin'];

        foreach ($attributes as $key => $value) {
            $struct = $this->addLocalFormatStruct($name, (string) $key, $value, $origin, $rightOrigin);
            $origin = $struct['id'];
            $rightOrigin = null;
        }

        $struct = $this->addLocalStruct($name, null, [
            'type' => 'ContentEmbed',
            'value' => $embed,
        ], $origin, $rightOrigin);

        if ($attributes !== []) {
            $lastId = $struct['id'];
            foreach (array_reverse(array_keys($attributes)) as $key) {
                $struct = $this->addLocalFormatStruct($name, (string) $key, null, $lastId, $bounds['rightOrigin']);
                $lastId = $struct['id'];
            }
        }
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function formatText(string $name, int $index, int $length, array $attributes): void
    {
        $this->atomicMutation(function () use ($name, $index, $length, $attributes): void {
            $currentLength = $this->sequenceLength($name, 'text');
            if ($index < 0 || $length < 0 || $index + $length > $currentLength) {
                throw new \InvalidArgumentException('YText format range is out of bounds.');
            }

            if ($length === 0 || $attributes === []) {
                return;
            }

            $startBounds = $this->sequenceBoundsAt($name, $index, 'text');
            $endBounds = $this->sequenceBoundsAt($name, $index + $length, 'text');
            $endOrigin = $endBounds['origin'];

            foreach ($attributes as $key => $value) {
                $this->addLocalFormatStruct($name, (string) $key, $value, $startBounds['origin'], $startBounds['rightOrigin']);
                $struct = $this->addLocalFormatStruct($name, (string) $key, null, $endOrigin, $endBounds['rightOrigin']);
                $endOrigin = $struct['id'];
            }
        });
    }

    /**
     * @param list<mixed> $values
     */
    public function insertArray(string $name, int $index, array $values): void
    {
        $current = $this->json[$name] ?? [];
        if (! is_array($current) || ! array_is_list($current)) {
            $current = [];
        }

        if ($index < 0 || $index > count($current)) {
            throw new \InvalidArgumentException('YArray insert index is out of bounds.');
        }

        if ($values === []) {
            return;
        }

        $bounds = $this->sequenceBoundsAt($name, $index, 'array');
        $this->addLocalStruct($name, null, [
            'type' => 'ContentAny',
            'values' => $values,
        ], $bounds['origin'], $bounds['rightOrigin']);
    }

    public function insertArrayBinary(string $name, int $index, string $bytes): void
    {
        $current = $this->json[$name] ?? [];
        if (! is_array($current) || ! array_is_list($current)) {
            $current = [];
        }

        if ($index < 0 || $index > count($current)) {
            throw new \InvalidArgumentException('YArray binary insert index is out of bounds.');
        }

        $bounds = $this->sequenceBoundsAt($name, $index, 'array');
        $this->addLocalStruct($name, null, [
            'type' => 'ContentBinary',
            'base64' => base64_encode($bytes),
        ], $bounds['origin'], $bounds['rightOrigin']);
    }

    /**
     * @param array<string, mixed> $opts
     */
    public function insertArraySubdoc(string $name, int $index, string $guid, array $opts = []): YSubdoc
    {
        $current = $this->json[$name] ?? [];
        if (! is_array($current) || ! array_is_list($current)) {
            $current = [];
        }

        if ($index < 0 || $index > count($current)) {
            throw new \InvalidArgumentException('YArray subdoc insert index is out of bounds.');
        }

        $bounds = $this->sequenceBoundsAt($name, $index, 'array');
        $struct = $this->addLocalStruct($name, null, [
            'type' => 'ContentDoc',
            'guid' => $guid,
            'opts' => $opts,
        ], $bounds['origin'], $bounds['rightOrigin']);

        return $this->contentDocJsonValue($struct['content']);
    }

    public function insertXmlElementInArray(string $name, int $index, string $nodeName): YXmlElement
    {
        if ($nodeName === '') {
            throw new \InvalidArgumentException('YXmlElement node name cannot be empty.');
        }

        $current = $this->json[$name] ?? [];
        if (! is_array($current) || ! array_is_list($current)) {
            $current = [];
        }

        if ($index < 0 || $index > count($current)) {
            throw new \InvalidArgumentException('YArray XML element insert index is out of bounds.');
        }

        $bounds = $this->sequenceBoundsAt($name, $index, 'array');
        $struct = $this->addLocalStruct($name, null, [
            'type' => 'ContentType',
            'typeRef' => 3,
            'typeName' => 'YXmlElement',
            'nodeName' => $nodeName,
        ], $bounds['origin'], $bounds['rightOrigin']);

        return new YXmlElement($this, self::idKey($struct['id']), $nodeName);
    }

    public function insertXmlTextInArray(string $name, int $index, string $text = ''): YXmlText
    {
        $current = $this->json[$name] ?? [];
        if (! is_array($current) || ! array_is_list($current)) {
            $current = [];
        }

        if ($index < 0 || $index > count($current)) {
            throw new \InvalidArgumentException('YArray XML text insert index is out of bounds.');
        }

        $bounds = $this->sequenceBoundsAt($name, $index, 'array');
        $textNode = $this->addLocalStruct($name, null, [
            'type' => 'ContentType',
            'typeRef' => 6,
            'typeName' => 'YXmlText',
        ], $bounds['origin'], $bounds['rightOrigin']);
        $xmlText = new YXmlText($this, self::idKey($textNode['id']), '');
        $xmlText->insert(0, $text);

        return $xmlText;
    }

    public function insertXmlHookInArray(string $name, int $index, string $hookName): YXmlHook
    {
        $current = $this->json[$name] ?? [];
        if (! is_array($current) || ! array_is_list($current)) {
            $current = [];
        }

        if ($index < 0 || $index > count($current)) {
            throw new \InvalidArgumentException('YArray XML hook insert index is out of bounds.');
        }

        $bounds = $this->sequenceBoundsAt($name, $index, 'array');
        $struct = $this->addLocalStruct($name, null, [
            'type' => 'ContentType',
            'typeRef' => 5,
            'typeName' => 'YXmlHook',
            'hookName' => $hookName,
        ], $bounds['origin'], $bounds['rightOrigin']);

        return new YXmlHook($this, self::idKey($struct['id']), $hookName);
    }

    public function insertXmlFragmentInArray(string $name, int $index): YNestedXmlFragment
    {
        $current = $this->json[$name] ?? [];
        if (! is_array($current) || ! array_is_list($current)) {
            $current = [];
        }

        if ($index < 0 || $index > count($current)) {
            throw new \InvalidArgumentException('YArray XML fragment insert index is out of bounds.');
        }

        $bounds = $this->sequenceBoundsAt($name, $index, 'array');
        $struct = $this->addLocalStruct($name, null, [
            'type' => 'ContentType',
            'typeRef' => 4,
            'typeName' => 'YXmlFragment',
        ], $bounds['origin'], $bounds['rightOrigin']);

        return new YNestedXmlFragment($this, self::idKey($struct['id']));
    }

    public function deleteArray(string $name, int $index, int $length): void
    {
        $current = $this->json[$name] ?? [];
        if (! is_array($current) || ! array_is_list($current)) {
            $current = [];
        }

        if ($index < 0 || $length < 0 || $index + $length > count($current)) {
            throw new \InvalidArgumentException('YArray delete range is out of bounds.');
        }

        $this->deleteSequenceRange($name, $index, $length);
    }

    public function setMapValue(string $name, string $key, mixed $value): void
    {
        $this->atomicMutation(function () use ($name, $key, $value): void {
            $previous = $this->findLatestMapStruct($name, $key);
            if ($previous !== null && ! $this->isStructDeleted($previous)) {
                $this->deleteMapStruct($previous);
            }

            $this->addLocalStruct($name, $previous === null ? $key : null, [
                'type' => 'ContentAny',
                'values' => [$value],
            ], $previous['id'] ?? null);
        });
    }

    public function setMapBinary(string $name, string $key, string $bytes): void
    {
        $this->atomicMutation(function () use ($name, $key, $bytes): void {
            $previous = $this->findLatestMapStruct($name, $key);
            if ($previous !== null && ! $this->isStructDeleted($previous)) {
                $this->deleteMapStruct($previous);
            }

            $this->addLocalStruct($name, $previous === null ? $key : null, [
                'type' => 'ContentBinary',
                'base64' => base64_encode($bytes),
            ], $previous['id'] ?? null);
        });
    }

    /**
     * @param array<string, mixed> $opts
     */
    public function setMapSubdoc(string $name, string $key, string $guid, array $opts = []): YSubdoc
    {
        return $this->atomicMutation(function () use ($name, $key, $guid, $opts): YSubdoc {
            $previous = $this->findLatestMapStruct($name, $key);
            if ($previous !== null && ! $this->isStructDeleted($previous)) {
                $this->deleteMapStruct($previous);
            }

            $struct = $this->addLocalStruct($name, $previous === null ? $key : null, [
                'type' => 'ContentDoc',
                'guid' => $guid,
                'opts' => $opts,
            ], $previous['id'] ?? null);

            return $this->contentDocJsonValue($struct['content']);
        });
    }

    public function setXmlElementInMap(string $name, string $key, string $nodeName): YXmlElement
    {
        if ($nodeName === '') {
            throw new \InvalidArgumentException('YXmlElement node name cannot be empty.');
        }

        return $this->atomicMutation(function () use ($name, $key, $nodeName): YXmlElement {
            $previous = $this->findLatestMapStruct($name, $key);
            if ($previous !== null && ! $this->isStructDeleted($previous)) {
                $this->deleteMapStruct($previous);
            }

            $struct = $this->addLocalStruct($name, $previous === null ? $key : null, [
                'type' => 'ContentType',
                'typeRef' => 3,
                'typeName' => 'YXmlElement',
                'nodeName' => $nodeName,
            ], $previous['id'] ?? null);

            return new YXmlElement($this, self::idKey($struct['id']), $nodeName);
        });
    }

    public function setXmlTextInMap(string $name, string $key, string $text = ''): YXmlText
    {
        return $this->atomicMutation(function () use ($name, $key, $text): YXmlText {
            $previous = $this->findLatestMapStruct($name, $key);
            if ($previous !== null && ! $this->isStructDeleted($previous)) {
                $this->deleteMapStruct($previous);
            }

            $struct = $this->addLocalStruct($name, $previous === null ? $key : null, [
                'type' => 'ContentType',
                'typeRef' => 6,
                'typeName' => 'YXmlText',
            ], $previous['id'] ?? null);
            $xmlText = new YXmlText($this, self::idKey($struct['id']), '');
            $xmlText->insert(0, $text);

            return $xmlText;
        });
    }

    public function setXmlHookInMap(string $name, string $key, string $hookName): YXmlHook
    {
        return $this->atomicMutation(function () use ($name, $key, $hookName): YXmlHook {
            $previous = $this->findLatestMapStruct($name, $key);
            if ($previous !== null && ! $this->isStructDeleted($previous)) {
                $this->deleteMapStruct($previous);
            }

            $struct = $this->addLocalStruct($name, $previous === null ? $key : null, [
                'type' => 'ContentType',
                'typeRef' => 5,
                'typeName' => 'YXmlHook',
                'hookName' => $hookName,
            ], $previous['id'] ?? null);

            return new YXmlHook($this, self::idKey($struct['id']), $hookName);
        });
    }

    public function setXmlFragmentInMap(string $name, string $key): YNestedXmlFragment
    {
        return $this->atomicMutation(function () use ($name, $key): YNestedXmlFragment {
            $previous = $this->findLatestMapStruct($name, $key);
            if ($previous !== null && ! $this->isStructDeleted($previous)) {
                $this->deleteMapStruct($previous);
            }

            $struct = $this->addLocalStruct($name, $previous === null ? $key : null, [
                'type' => 'ContentType',
                'typeRef' => 4,
                'typeName' => 'YXmlFragment',
            ], $previous['id'] ?? null);

            return new YNestedXmlFragment($this, self::idKey($struct['id']));
        });
    }

    public function deleteMapKey(string $name, string $key): void
    {
        $struct = $this->findVisibleMapStruct($name, $key);
        if ($struct !== null) {
            $this->deleteMapStruct($struct);
        }
    }

    public function insertXmlElement(string $name, int $index, string $nodeName): YXmlElement
    {
        if ($nodeName === '') {
            throw new \InvalidArgumentException('YXmlElement node name cannot be empty.');
        }

        $bounds = $this->sequenceBoundsAt($name, $index, 'array');
        $struct = $this->addLocalStruct($name, null, [
            'type' => 'ContentType',
            'typeRef' => 3,
            'typeName' => 'YXmlElement',
            'nodeName' => $nodeName,
        ], $bounds['origin'], $bounds['rightOrigin']);

        return new YXmlElement($this, self::idKey($struct['id']), $nodeName);
    }

    public function insertXmlHook(string $name, int $index, string $hookName): YXmlHook
    {
        $bounds = $this->sequenceBoundsAt($name, $index, 'array');
        $struct = $this->addLocalStruct($name, null, [
            'type' => 'ContentType',
            'typeRef' => 5,
            'typeName' => 'YXmlHook',
            'hookName' => $hookName,
        ], $bounds['origin'], $bounds['rightOrigin']);

        return new YXmlHook($this, self::idKey($struct['id']), $hookName);
    }

    public function insertXmlFragmentText(string $name, int $index, string $text): YXmlText
    {
        $bounds = $this->sequenceBoundsAt($name, $index, 'array');
        $textNode = $this->addLocalStruct($name, null, [
            'type' => 'ContentType',
            'typeRef' => 6,
            'typeName' => 'YXmlText',
        ], $bounds['origin'], $bounds['rightOrigin']);
        $xmlText = new YXmlText($this, self::idKey($textNode['id']), '');
        $xmlText->insert(0, $text);

        return $xmlText;
    }

    public function deleteXmlFragmentChildren(string $name, int $index, int $length): void
    {
        $currentLength = $this->sequenceLength($name, 'array');
        if ($index < 0 || $length < 0 || $index + $length > $currentLength) {
            throw new \InvalidArgumentException('YXmlFragment delete range is out of bounds.');
        }

        $this->deleteSequenceRange($name, $index, $length, 'array');
    }

    public function insertNestedArrayInArray(string $name, int $index): YNestedArray
    {
        $bounds = $this->sequenceBoundsAt($name, $index, 'array');
        $struct = $this->addLocalStruct($name, null, [
            'type' => 'ContentType',
            'typeRef' => 0,
            'typeName' => 'YArray',
        ], $bounds['origin'], $bounds['rightOrigin']);

        return new YNestedArray($this, self::idKey($struct['id']), []);
    }

    public function insertNestedMapInArray(string $name, int $index): YNestedMap
    {
        $bounds = $this->sequenceBoundsAt($name, $index, 'array');
        $struct = $this->addLocalStruct($name, null, [
            'type' => 'ContentType',
            'typeRef' => 1,
            'typeName' => 'YMap',
        ], $bounds['origin'], $bounds['rightOrigin']);

        return new YNestedMap($this, self::idKey($struct['id']), []);
    }

    public function insertNestedTextInArray(string $name, int $index): YNestedText
    {
        $bounds = $this->sequenceBoundsAt($name, $index, 'array');
        $struct = $this->addLocalStruct($name, null, [
            'type' => 'ContentType',
            'typeRef' => 2,
            'typeName' => 'YText',
        ], $bounds['origin'], $bounds['rightOrigin']);

        return new YNestedText($this, self::idKey($struct['id']), '');
    }

    public function setNestedArrayInMap(string $name, string $key): YNestedArray
    {
        return $this->atomicMutation(function () use ($name, $key): YNestedArray {
            $previous = $this->findLatestMapStruct($name, $key);
            if ($previous !== null && ! $this->isStructDeleted($previous)) {
                $this->deleteMapStruct($previous);
            }

            $struct = $this->addLocalStruct($name, $previous === null ? $key : null, [
                'type' => 'ContentType',
                'typeRef' => 0,
                'typeName' => 'YArray',
            ], $previous['id'] ?? null);

            return new YNestedArray($this, self::idKey($struct['id']), []);
        });
    }

    public function setNestedMapInMap(string $name, string $key): YNestedMap
    {
        return $this->atomicMutation(function () use ($name, $key): YNestedMap {
            $previous = $this->findLatestMapStruct($name, $key);
            if ($previous !== null && ! $this->isStructDeleted($previous)) {
                $this->deleteMapStruct($previous);
            }

            $struct = $this->addLocalStruct($name, $previous === null ? $key : null, [
                'type' => 'ContentType',
                'typeRef' => 1,
                'typeName' => 'YMap',
            ], $previous['id'] ?? null);

            return new YNestedMap($this, self::idKey($struct['id']), []);
        });
    }

    public function setNestedTextInMap(string $name, string $key): YNestedText
    {
        return $this->atomicMutation(function () use ($name, $key): YNestedText {
            $previous = $this->findLatestMapStruct($name, $key);
            if ($previous !== null && ! $this->isStructDeleted($previous)) {
                $this->deleteMapStruct($previous);
            }

            $struct = $this->addLocalStruct($name, $previous === null ? $key : null, [
                'type' => 'ContentType',
                'typeRef' => 2,
                'typeName' => 'YText',
            ], $previous['id'] ?? null);

            return new YNestedText($this, self::idKey($struct['id']), '');
        });
    }

    public function setXmlElementAttribute(string $elementIdKey, string $key, mixed $value): void
    {
        $this->atomicMutation(function () use ($elementIdKey, $key, $value): void {
            $previous = $this->findLatestXmlAttributeStruct($elementIdKey, $key);
            if ($previous !== null && ! $this->isStructDeleted($previous)) {
                $this->deleteMapStruct($previous);
            }

            $this->addLocalStruct(self::idFromKey($elementIdKey), $previous === null ? $key : null, [
                'type' => 'ContentAny',
                'values' => [$value],
            ], $previous['id'] ?? null);
        });
    }

    public function setNestedArrayInXmlElementAttribute(string $elementIdKey, string $key): YNestedArray
    {
        return $this->atomicMutation(function () use ($elementIdKey, $key): YNestedArray {
            $previous = $this->findLatestXmlAttributeStruct($elementIdKey, $key);
            if ($previous !== null && ! $this->isStructDeleted($previous)) {
                $this->deleteMapStruct($previous);
            }

            $struct = $this->addLocalStruct(self::idFromKey($elementIdKey), $previous === null ? $key : null, [
                'type' => 'ContentType',
                'typeRef' => 0,
                'typeName' => 'YArray',
            ], $previous['id'] ?? null);

            return new YNestedArray($this, self::idKey($struct['id']), []);
        });
    }

    public function setNestedMapInXmlElementAttribute(string $elementIdKey, string $key): YNestedMap
    {
        return $this->atomicMutation(function () use ($elementIdKey, $key): YNestedMap {
            $previous = $this->findLatestXmlAttributeStruct($elementIdKey, $key);
            if ($previous !== null && ! $this->isStructDeleted($previous)) {
                $this->deleteMapStruct($previous);
            }

            $struct = $this->addLocalStruct(self::idFromKey($elementIdKey), $previous === null ? $key : null, [
                'type' => 'ContentType',
                'typeRef' => 1,
                'typeName' => 'YMap',
            ], $previous['id'] ?? null);

            return new YNestedMap($this, self::idKey($struct['id']), []);
        });
    }

    public function setNestedTextInXmlElementAttribute(string $elementIdKey, string $key): YNestedText
    {
        return $this->atomicMutation(function () use ($elementIdKey, $key): YNestedText {
            $previous = $this->findLatestXmlAttributeStruct($elementIdKey, $key);
            if ($previous !== null && ! $this->isStructDeleted($previous)) {
                $this->deleteMapStruct($previous);
            }

            $struct = $this->addLocalStruct(self::idFromKey($elementIdKey), $previous === null ? $key : null, [
                'type' => 'ContentType',
                'typeRef' => 2,
                'typeName' => 'YText',
            ], $previous['id'] ?? null);

            return new YNestedText($this, self::idKey($struct['id']), '');
        });
    }

    public function setXmlElementInXmlElementAttribute(string $elementIdKey, string $key, string $nodeName): YXmlElement
    {
        if ($nodeName === '') {
            throw new \InvalidArgumentException('YXmlElement node name cannot be empty.');
        }

        return $this->atomicMutation(function () use ($elementIdKey, $key, $nodeName): YXmlElement {
            $previous = $this->findLatestXmlAttributeStruct($elementIdKey, $key);
            if ($previous !== null && ! $this->isStructDeleted($previous)) {
                $this->deleteMapStruct($previous);
            }

            $struct = $this->addLocalStruct(self::idFromKey($elementIdKey), $previous === null ? $key : null, [
                'type' => 'ContentType',
                'typeRef' => 3,
                'typeName' => 'YXmlElement',
                'nodeName' => $nodeName,
            ], $previous['id'] ?? null);

            return new YXmlElement($this, self::idKey($struct['id']), $nodeName);
        });
    }

    public function setXmlTextInXmlElementAttribute(string $elementIdKey, string $key, string $text = ''): YXmlText
    {
        return $this->atomicMutation(function () use ($elementIdKey, $key, $text): YXmlText {
            $previous = $this->findLatestXmlAttributeStruct($elementIdKey, $key);
            if ($previous !== null && ! $this->isStructDeleted($previous)) {
                $this->deleteMapStruct($previous);
            }

            $struct = $this->addLocalStruct(self::idFromKey($elementIdKey), $previous === null ? $key : null, [
                'type' => 'ContentType',
                'typeRef' => 6,
                'typeName' => 'YXmlText',
            ], $previous['id'] ?? null);
            $xmlText = new YXmlText($this, self::idKey($struct['id']), '');
            $xmlText->insert(0, $text);

            return $xmlText;
        });
    }

    public function setXmlHookInXmlElementAttribute(string $elementIdKey, string $key, string $hookName): YXmlHook
    {
        return $this->atomicMutation(function () use ($elementIdKey, $key, $hookName): YXmlHook {
            $previous = $this->findLatestXmlAttributeStruct($elementIdKey, $key);
            if ($previous !== null && ! $this->isStructDeleted($previous)) {
                $this->deleteMapStruct($previous);
            }

            $struct = $this->addLocalStruct(self::idFromKey($elementIdKey), $previous === null ? $key : null, [
                'type' => 'ContentType',
                'typeRef' => 5,
                'typeName' => 'YXmlHook',
                'hookName' => $hookName,
            ], $previous['id'] ?? null);

            return new YXmlHook($this, self::idKey($struct['id']), $hookName);
        });
    }

    public function setXmlFragmentInXmlElementAttribute(string $elementIdKey, string $key): YNestedXmlFragment
    {
        return $this->atomicMutation(function () use ($elementIdKey, $key): YNestedXmlFragment {
            $previous = $this->findLatestXmlAttributeStruct($elementIdKey, $key);
            if ($previous !== null && ! $this->isStructDeleted($previous)) {
                $this->deleteMapStruct($previous);
            }

            $struct = $this->addLocalStruct(self::idFromKey($elementIdKey), $previous === null ? $key : null, [
                'type' => 'ContentType',
                'typeRef' => 4,
                'typeName' => 'YXmlFragment',
            ], $previous['id'] ?? null);

            return new YNestedXmlFragment($this, self::idKey($struct['id']));
        });
    }

    public function removeXmlElementAttribute(string $elementIdKey, string $key): void
    {
        $struct = $this->findVisibleXmlAttributeStruct($elementIdKey, $key);
        if ($struct !== null) {
            $this->deleteMapStruct($struct);
        }
    }

    public function insertXmlText(string $elementIdKey, int $index, string $text): YXmlText
    {
        if ($index < 0) {
            throw new \InvalidArgumentException('YXmlText insert index is out of bounds.');
        }

        $bounds = $this->sequenceBoundsAtId($elementIdKey, $index, 'array');
        $textNode = $this->addLocalStruct(self::idFromKey($elementIdKey), null, [
            'type' => 'ContentType',
            'typeRef' => 6,
            'typeName' => 'YXmlText',
        ], $bounds['origin'], $bounds['rightOrigin']);
        $xmlText = new YXmlText($this, self::idKey($textNode['id']), '');
        $xmlText->insert(0, $text);

        return $xmlText;
    }

    public function insertXmlElementChild(string $elementIdKey, int $index, string $nodeName): YXmlElement
    {
        if ($nodeName === '') {
            throw new \InvalidArgumentException('YXmlElement node name cannot be empty.');
        }

        $bounds = $this->sequenceBoundsAtId($elementIdKey, $index, 'array');
        $struct = $this->addLocalStruct(self::idFromKey($elementIdKey), null, [
            'type' => 'ContentType',
            'typeRef' => 3,
            'typeName' => 'YXmlElement',
            'nodeName' => $nodeName,
        ], $bounds['origin'], $bounds['rightOrigin']);

        return new YXmlElement($this, self::idKey($struct['id']), $nodeName);
    }

    public function insertXmlHookChild(string $elementIdKey, int $index, string $hookName): YXmlHook
    {
        $bounds = $this->sequenceBoundsAtId($elementIdKey, $index, 'array');
        $struct = $this->addLocalStruct(self::idFromKey($elementIdKey), null, [
            'type' => 'ContentType',
            'typeRef' => 5,
            'typeName' => 'YXmlHook',
            'hookName' => $hookName,
        ], $bounds['origin'], $bounds['rightOrigin']);

        return new YXmlHook($this, self::idKey($struct['id']), $hookName);
    }

    public function deleteXmlElementChildren(string $elementIdKey, int $index, int $length): void
    {
        $currentLength = $this->sequenceLengthForParentId($elementIdKey, 'array');
        if ($index < 0 || $length < 0 || $index + $length > $currentLength) {
            throw new \InvalidArgumentException('YXmlElement delete range is out of bounds.');
        }

        $this->deleteSequenceRangeForParentId($elementIdKey, $index, $length, 'array');
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function insertXmlTextContent(string $textIdKey, int $index, string $text, array $attributes = []): void
    {
        if ($index < 0) {
            throw new \InvalidArgumentException('YXmlText insert index is out of bounds.');
        }

        if ($text === '') {
            return;
        }

        $bounds = $this->xmlTextBoundsAt($textIdKey, $index);
        $origin = $bounds['origin'];
        $rightOrigin = $bounds['rightOrigin'];

        foreach ($attributes as $key => $value) {
            $struct = $this->addLocalFormatStruct(self::idFromKey($textIdKey), (string) $key, $value, $origin, $rightOrigin);
            $origin = $struct['id'];
            $rightOrigin = null;
        }

        $this->addLocalStruct(self::idFromKey($textIdKey), null, [
            'type' => 'ContentString',
            'value' => $text,
        ], $origin, $rightOrigin);

        if ($attributes !== []) {
            $lastId = [
                'client' => $this->clientID,
                'clock' => ($this->getStateVector()[$this->clientID] ?? 0) - 1,
            ];

            foreach (array_reverse(array_keys($attributes)) as $key) {
                $struct = $this->addLocalFormatStruct(self::idFromKey($textIdKey), (string) $key, null, $lastId, $bounds['rightOrigin']);
                $lastId = $struct['id'];
            }
        }
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function insertXmlTextEmbed(string $textIdKey, int $index, mixed $embed, array $attributes = []): void
    {
        $currentLength = $this->sequenceLengthForParentId($textIdKey, 'text');
        if ($index < 0 || $index > $currentLength) {
            throw new \InvalidArgumentException('YXmlText embed insert index is out of bounds.');
        }

        $bounds = $this->xmlTextBoundsAt($textIdKey, $index);
        $origin = $bounds['origin'];
        $rightOrigin = $bounds['rightOrigin'];

        foreach ($attributes as $key => $value) {
            $struct = $this->addLocalFormatStruct(self::idFromKey($textIdKey), (string) $key, $value, $origin, $rightOrigin);
            $origin = $struct['id'];
            $rightOrigin = null;
        }

        $struct = $this->addLocalStruct(self::idFromKey($textIdKey), null, [
            'type' => 'ContentEmbed',
            'value' => $embed,
        ], $origin, $rightOrigin);

        if ($attributes !== []) {
            $lastId = $struct['id'];
            foreach (array_reverse(array_keys($attributes)) as $key) {
                $struct = $this->addLocalFormatStruct(self::idFromKey($textIdKey), (string) $key, null, $lastId, $bounds['rightOrigin']);
                $lastId = $struct['id'];
            }
        }
    }

    public function deleteXmlTextContent(string $textIdKey, int $index, int $length): void
    {
        $currentLength = $this->sequenceLengthForParentId($textIdKey, 'text');
        if ($index < 0 || $length < 0 || $index + $length > $currentLength) {
            throw new \InvalidArgumentException('YXmlText delete range is out of bounds.');
        }

        $this->deleteSequenceRangeForParentId($textIdKey, $index, $length, 'text');
    }

    public function xmlTextValue(string $textIdKey): string
    {
        return $this->xmlTextById[$textIdKey] ?? '';
    }

    public function xmlTextLength(string $textIdKey): int
    {
        return $this->sequenceLengthForParentId($textIdKey, 'text');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function xmlTextDelta(string $textIdKey): array
    {
        return $this->textDeltaFromItems($this->collectSequenceItemsForParentId(self::idFromKey($textIdKey)));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function formatXmlTextContent(string $textIdKey, int $index, int $length, array $attributes): void
    {
        $this->atomicMutation(function () use ($textIdKey, $index, $length, $attributes): void {
            $currentLength = $this->sequenceLengthForParentId($textIdKey, 'text');
            if ($index < 0 || $length < 0 || $index + $length > $currentLength) {
                throw new \InvalidArgumentException('YXmlText format range is out of bounds.');
            }

            if ($length === 0 || $attributes === []) {
                return;
            }

            $startBounds = $this->sequenceBoundsAtId($textIdKey, $index, 'text');
            $endBounds = $this->sequenceBoundsAtId($textIdKey, $index + $length, 'text');
            $endOrigin = $endBounds['origin'];

            foreach ($attributes as $key => $value) {
                $this->addLocalFormatStruct(self::idFromKey($textIdKey), (string) $key, $value, $startBounds['origin'], $startBounds['rightOrigin']);
                $struct = $this->addLocalFormatStruct(self::idFromKey($textIdKey), (string) $key, null, $endOrigin, $endBounds['rightOrigin']);
                $endOrigin = $struct['id'];
            }
        });
    }

    public function xmlElementValue(string $elementIdKey): string
    {
        if (! isset($this->xmlNodesById[$elementIdKey])) {
            return '';
        }

        return $this->renderXmlNode($this->xmlNodesById, $elementIdKey);
    }

    /**
     * @return array<string, mixed>
     */
    public function xmlElementAttributes(string $elementIdKey): array
    {
        $attributes = $this->xmlNodesById[$elementIdKey]['attributes'] ?? [];
        ksort($attributes, SORT_STRING);

        return $attributes;
    }

    public function xmlElementAttribute(string $elementIdKey, string $key): mixed
    {
        return $this->xmlElementAttributes($elementIdKey)[$key] ?? null;
    }

    public function sharedTypeInXmlElementAttribute(string $elementIdKey, string $key): YNestedArray|YNestedMap|YNestedText|YNestedXmlFragment|YXmlElement|YXmlText|YXmlHook|null
    {
        $identity = $this->xmlAttributeIdentityById[$elementIdKey][$key] ?? null;
        if ($identity === null) {
            return null;
        }

        $nestedIdKey = $this->sharedTypeIdFromIdentityValue($identity);
        if ($nestedIdKey !== null) {
            return $this->nestedSharedType($nestedIdKey);
        }

        $xmlIdKey = $this->xmlTypeIdFromIdentityValue($identity);

        return $xmlIdKey === null ? null : $this->xmlNodeObject($xmlIdKey);
    }

    /**
     * @return array{root: string, path: list<int|string>}|null
     */
    public function sharedTypeLocation(string $idKey): ?array
    {
        return $this->nestedSharedTypeLocation($idKey);
    }

    /**
     * @return array{root: string, path: list<int|string>}|null
     */
    public function xmlTypeLocation(string $idKey): ?array
    {
        return $this->xmlNodeLocation($idKey);
    }

    public function xmlElementHasAttribute(string $elementIdKey, string $key): bool
    {
        return array_key_exists($key, $this->xmlElementAttributes($elementIdKey));
    }

    public function xmlFragmentLength(string $name): int
    {
        return $this->visibleXmlChildCount($this->xmlRootChildrenByName[$name] ?? []);
    }

    public function xmlFragmentChild(string $name, int $index): YXmlElement|YXmlText|YXmlHook|string|null
    {
        $value = $this->visibleXmlChildValueAt($this->xmlRootChildrenByName[$name] ?? [], $index);

        return $this->xmlChildValueObject($value);
    }

    public function xmlElementLength(string $elementIdKey): int
    {
        return $this->visibleXmlChildCount($this->xmlNodesById[$elementIdKey]['children'] ?? []);
    }

    public function xmlElementChild(string $elementIdKey, int $index): YXmlElement|YXmlText|YXmlHook|string|null
    {
        $value = $this->visibleXmlChildValueAt($this->xmlNodesById[$elementIdKey]['children'] ?? [], $index);

        return $this->xmlChildValueObject($value);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function xmlFragmentChildrenSnapshot(string $name): array
    {
        return array_map(
            fn (string $idKey): array => $this->xmlNodeSnapshot($idKey),
            $this->xmlRootChildIds($name)
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function xmlElementChildrenSnapshot(string $elementIdKey): array
    {
        return array_map(
            fn (string $idKey): array => $this->xmlNodeSnapshot($idKey),
            $this->xmlElementChildIds($elementIdKey)
        );
    }

    /**
     * @param list<array<string, mixed>> $children
     */
    public function restoreXmlFragmentChildrenSnapshot(string $name, array $children): void
    {
        $this->deleteXmlFragmentChildren($name, 0, $this->xmlFragmentLength($name));
        foreach (array_values($children) as $index => $child) {
            $this->insertXmlSnapshotIntoRoot($name, $index, $child);
        }
    }

    /**
     * @param list<array<string, mixed>> $children
     */
    public function restoreXmlElementChildrenSnapshot(string $elementIdKey, array $children): void
    {
        $this->deleteXmlElementChildren($elementIdKey, 0, $this->xmlElementLength($elementIdKey));
        foreach (array_values($children) as $index => $child) {
            $this->insertXmlSnapshotIntoElement($elementIdKey, $index, $child);
        }
    }

    public function xmlNodeNextSibling(string $idKey): YXmlElement|YXmlText|YXmlHook|null
    {
        return $this->xmlNodeSibling($idKey, 1);
    }

    public function xmlNodePreviousSibling(string $idKey): YXmlElement|YXmlText|YXmlHook|null
    {
        return $this->xmlNodeSibling($idKey, -1);
    }

    /**
     * @param list<mixed> $values
     */
    public function insertNestedArray(string $idKey, int $index, array $values): void
    {
        $current = $this->nestedArrayValue($idKey);
        if ($index < 0 || $index > count($current)) {
            throw new \InvalidArgumentException('Nested YArray insert index is out of bounds.');
        }

        if ($values === []) {
            return;
        }

        $bounds = $this->sequenceBoundsAtId($idKey, $index, 'array');
        $this->addLocalStruct(self::idFromKey($idKey), null, [
            'type' => 'ContentAny',
            'values' => $values,
        ], $bounds['origin'], $bounds['rightOrigin']);
    }

    public function insertNestedArrayBinary(string $idKey, int $index, string $bytes): void
    {
        $current = $this->nestedArrayValue($idKey);
        if ($index < 0 || $index > count($current)) {
            throw new \InvalidArgumentException('Nested YArray binary insert index is out of bounds.');
        }

        $bounds = $this->sequenceBoundsAtId($idKey, $index, 'array');
        $this->addLocalStruct(self::idFromKey($idKey), null, [
            'type' => 'ContentBinary',
            'base64' => base64_encode($bytes),
        ], $bounds['origin'], $bounds['rightOrigin']);
    }

    /**
     * @param array<string, mixed> $opts
     */
    public function insertNestedArraySubdoc(string $idKey, int $index, string $guid, array $opts = []): YSubdoc
    {
        $current = $this->nestedArrayValue($idKey);
        if ($index < 0 || $index > count($current)) {
            throw new \InvalidArgumentException('Nested YArray subdoc insert index is out of bounds.');
        }

        $bounds = $this->sequenceBoundsAtId($idKey, $index, 'array');
        $struct = $this->addLocalStruct(self::idFromKey($idKey), null, [
            'type' => 'ContentDoc',
            'guid' => $guid,
            'opts' => $opts,
        ], $bounds['origin'], $bounds['rightOrigin']);

        return $this->contentDocJsonValue($struct['content']);
    }

    public function insertNestedArrayInNestedArray(string $idKey, int $index): YNestedArray
    {
        $bounds = $this->sequenceBoundsAtId($idKey, $index, 'array');
        $struct = $this->addLocalStruct(self::idFromKey($idKey), null, [
            'type' => 'ContentType',
            'typeRef' => 0,
            'typeName' => 'YArray',
        ], $bounds['origin'], $bounds['rightOrigin']);

        return new YNestedArray($this, self::idKey($struct['id']), []);
    }

    public function insertNestedMapInNestedArray(string $idKey, int $index): YNestedMap
    {
        $bounds = $this->sequenceBoundsAtId($idKey, $index, 'array');
        $struct = $this->addLocalStruct(self::idFromKey($idKey), null, [
            'type' => 'ContentType',
            'typeRef' => 1,
            'typeName' => 'YMap',
        ], $bounds['origin'], $bounds['rightOrigin']);

        return new YNestedMap($this, self::idKey($struct['id']), []);
    }

    public function insertNestedTextInNestedArray(string $idKey, int $index): YNestedText
    {
        $bounds = $this->sequenceBoundsAtId($idKey, $index, 'array');
        $struct = $this->addLocalStruct(self::idFromKey($idKey), null, [
            'type' => 'ContentType',
            'typeRef' => 2,
            'typeName' => 'YText',
        ], $bounds['origin'], $bounds['rightOrigin']);

        return new YNestedText($this, self::idKey($struct['id']), '');
    }

    public function insertXmlElementInNestedArray(string $idKey, int $index, string $nodeName): YXmlElement
    {
        if ($nodeName === '') {
            throw new \InvalidArgumentException('YXmlElement node name cannot be empty.');
        }

        $current = $this->nestedArrayValue($idKey);
        if ($index < 0 || $index > count($current)) {
            throw new \InvalidArgumentException('Nested YArray XML element insert index is out of bounds.');
        }

        $bounds = $this->sequenceBoundsAtId($idKey, $index, 'array');
        $struct = $this->addLocalStruct(self::idFromKey($idKey), null, [
            'type' => 'ContentType',
            'typeRef' => 3,
            'typeName' => 'YXmlElement',
            'nodeName' => $nodeName,
        ], $bounds['origin'], $bounds['rightOrigin']);

        return new YXmlElement($this, self::idKey($struct['id']), $nodeName);
    }

    public function insertXmlTextInNestedArray(string $idKey, int $index, string $text = ''): YXmlText
    {
        $current = $this->nestedArrayValue($idKey);
        if ($index < 0 || $index > count($current)) {
            throw new \InvalidArgumentException('Nested YArray XML text insert index is out of bounds.');
        }

        $bounds = $this->sequenceBoundsAtId($idKey, $index, 'array');
        $textNode = $this->addLocalStruct(self::idFromKey($idKey), null, [
            'type' => 'ContentType',
            'typeRef' => 6,
            'typeName' => 'YXmlText',
        ], $bounds['origin'], $bounds['rightOrigin']);
        $xmlText = new YXmlText($this, self::idKey($textNode['id']), '');
        $xmlText->insert(0, $text);

        return $xmlText;
    }

    public function insertXmlHookInNestedArray(string $idKey, int $index, string $hookName): YXmlHook
    {
        $current = $this->nestedArrayValue($idKey);
        if ($index < 0 || $index > count($current)) {
            throw new \InvalidArgumentException('Nested YArray XML hook insert index is out of bounds.');
        }

        $bounds = $this->sequenceBoundsAtId($idKey, $index, 'array');
        $struct = $this->addLocalStruct(self::idFromKey($idKey), null, [
            'type' => 'ContentType',
            'typeRef' => 5,
            'typeName' => 'YXmlHook',
            'hookName' => $hookName,
        ], $bounds['origin'], $bounds['rightOrigin']);

        return new YXmlHook($this, self::idKey($struct['id']), $hookName);
    }

    public function insertXmlFragmentInNestedArray(string $idKey, int $index): YNestedXmlFragment
    {
        $current = $this->nestedArrayValue($idKey);
        if ($index < 0 || $index > count($current)) {
            throw new \InvalidArgumentException('Nested YArray XML fragment insert index is out of bounds.');
        }

        $bounds = $this->sequenceBoundsAtId($idKey, $index, 'array');
        $struct = $this->addLocalStruct(self::idFromKey($idKey), null, [
            'type' => 'ContentType',
            'typeRef' => 4,
            'typeName' => 'YXmlFragment',
        ], $bounds['origin'], $bounds['rightOrigin']);

        return new YNestedXmlFragment($this, self::idKey($struct['id']));
    }

    public function deleteNestedArray(string $idKey, int $index, int $length): void
    {
        $current = $this->nestedArrayValue($idKey);
        if ($index < 0 || $length < 0 || $index + $length > count($current)) {
            throw new \InvalidArgumentException('Nested YArray delete range is out of bounds.');
        }

        $this->deleteSequenceRangeForParentId($idKey, $index, $length, 'array');
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function insertNestedText(string $idKey, int $index, string $text, array $attributes = []): void
    {
        $currentLength = $this->sequenceLengthForParentId($idKey, 'text');
        if ($index < 0 || $index > $currentLength) {
            throw new \InvalidArgumentException('Nested YText insert index is out of bounds.');
        }

        if ($text === '') {
            return;
        }

        $bounds = $this->sequenceBoundsAtId($idKey, $index, 'text');
        $origin = $bounds['origin'];
        $rightOrigin = $bounds['rightOrigin'];

        foreach ($attributes as $key => $value) {
            $struct = $this->addLocalFormatStruct(self::idFromKey($idKey), (string) $key, $value, $origin, $rightOrigin);
            $origin = $struct['id'];
            $rightOrigin = null;
        }

        $this->addLocalStruct(self::idFromKey($idKey), null, [
            'type' => 'ContentString',
            'value' => $text,
        ], $origin, $rightOrigin);

        if ($attributes !== []) {
            $lastId = [
                'client' => $this->clientID,
                'clock' => ($this->getStateVector()[$this->clientID] ?? 0) - 1,
            ];

            foreach (array_reverse(array_keys($attributes)) as $key) {
                $struct = $this->addLocalFormatStruct(self::idFromKey($idKey), (string) $key, null, $lastId, $bounds['rightOrigin']);
                $lastId = $struct['id'];
            }
        }
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function insertNestedTextEmbed(string $idKey, int $index, mixed $embed, array $attributes = []): void
    {
        $currentLength = $this->sequenceLengthForParentId($idKey, 'text');
        if ($index < 0 || $index > $currentLength) {
            throw new \InvalidArgumentException('Nested YText embed insert index is out of bounds.');
        }

        $bounds = $this->sequenceBoundsAtId($idKey, $index, 'text');
        $origin = $bounds['origin'];
        $rightOrigin = $bounds['rightOrigin'];

        foreach ($attributes as $key => $value) {
            $struct = $this->addLocalFormatStruct(self::idFromKey($idKey), (string) $key, $value, $origin, $rightOrigin);
            $origin = $struct['id'];
            $rightOrigin = null;
        }

        $struct = $this->addLocalStruct(self::idFromKey($idKey), null, [
            'type' => 'ContentEmbed',
            'value' => $embed,
        ], $origin, $rightOrigin);

        if ($attributes !== []) {
            $lastId = $struct['id'];
            foreach (array_reverse(array_keys($attributes)) as $key) {
                $struct = $this->addLocalFormatStruct(self::idFromKey($idKey), (string) $key, null, $lastId, $bounds['rightOrigin']);
                $lastId = $struct['id'];
            }
        }
    }

    public function deleteNestedText(string $idKey, int $index, int $length): void
    {
        $currentLength = $this->sequenceLengthForParentId($idKey, 'text');
        if ($index < 0 || $length < 0 || $index + $length > $currentLength) {
            throw new \InvalidArgumentException('Nested YText delete range is out of bounds.');
        }

        $this->deleteSequenceRangeForParentId($idKey, $index, $length, 'text');
    }

    public function setNestedMapValue(string $idKey, string $key, mixed $value): void
    {
        $this->atomicMutation(function () use ($idKey, $key, $value): void {
            $previous = $this->findLatestNestedMapStruct($idKey, $key);
            if ($previous !== null && ! $this->isStructDeleted($previous)) {
                $this->deleteMapStruct($previous);
            }

            $this->addLocalStruct(self::idFromKey($idKey), $previous === null ? $key : null, [
                'type' => 'ContentAny',
                'values' => [$value],
            ], $previous['id'] ?? null);
        });
    }

    public function setNestedMapBinary(string $idKey, string $key, string $bytes): void
    {
        $this->atomicMutation(function () use ($idKey, $key, $bytes): void {
            $previous = $this->findLatestNestedMapStruct($idKey, $key);
            if ($previous !== null && ! $this->isStructDeleted($previous)) {
                $this->deleteMapStruct($previous);
            }

            $this->addLocalStruct(self::idFromKey($idKey), $previous === null ? $key : null, [
                'type' => 'ContentBinary',
                'base64' => base64_encode($bytes),
            ], $previous['id'] ?? null);
        });
    }

    /**
     * @param array<string, mixed> $opts
     */
    public function setNestedMapSubdoc(string $idKey, string $key, string $guid, array $opts = []): YSubdoc
    {
        return $this->atomicMutation(function () use ($idKey, $key, $guid, $opts): YSubdoc {
            $previous = $this->findLatestNestedMapStruct($idKey, $key);
            if ($previous !== null && ! $this->isStructDeleted($previous)) {
                $this->deleteMapStruct($previous);
            }

            $struct = $this->addLocalStruct(self::idFromKey($idKey), $previous === null ? $key : null, [
                'type' => 'ContentDoc',
                'guid' => $guid,
                'opts' => $opts,
            ], $previous['id'] ?? null);

            return $this->contentDocJsonValue($struct['content']);
        });
    }

    public function setNestedArrayInNestedMap(string $idKey, string $key): YNestedArray
    {
        return $this->atomicMutation(function () use ($idKey, $key): YNestedArray {
            $previous = $this->findLatestNestedMapStruct($idKey, $key);
            if ($previous !== null && ! $this->isStructDeleted($previous)) {
                $this->deleteMapStruct($previous);
            }

            $struct = $this->addLocalStruct(self::idFromKey($idKey), $previous === null ? $key : null, [
                'type' => 'ContentType',
                'typeRef' => 0,
                'typeName' => 'YArray',
            ], $previous['id'] ?? null);

            return new YNestedArray($this, self::idKey($struct['id']), []);
        });
    }

    public function setNestedMapInNestedMap(string $idKey, string $key): YNestedMap
    {
        return $this->atomicMutation(function () use ($idKey, $key): YNestedMap {
            $previous = $this->findLatestNestedMapStruct($idKey, $key);
            if ($previous !== null && ! $this->isStructDeleted($previous)) {
                $this->deleteMapStruct($previous);
            }

            $struct = $this->addLocalStruct(self::idFromKey($idKey), $previous === null ? $key : null, [
                'type' => 'ContentType',
                'typeRef' => 1,
                'typeName' => 'YMap',
            ], $previous['id'] ?? null);

            return new YNestedMap($this, self::idKey($struct['id']), []);
        });
    }

    public function setNestedTextInNestedMap(string $idKey, string $key): YNestedText
    {
        return $this->atomicMutation(function () use ($idKey, $key): YNestedText {
            $previous = $this->findLatestNestedMapStruct($idKey, $key);
            if ($previous !== null && ! $this->isStructDeleted($previous)) {
                $this->deleteMapStruct($previous);
            }

            $struct = $this->addLocalStruct(self::idFromKey($idKey), $previous === null ? $key : null, [
                'type' => 'ContentType',
                'typeRef' => 2,
                'typeName' => 'YText',
            ], $previous['id'] ?? null);

            return new YNestedText($this, self::idKey($struct['id']), '');
        });
    }

    public function setXmlElementInNestedMap(string $idKey, string $key, string $nodeName): YXmlElement
    {
        if ($nodeName === '') {
            throw new \InvalidArgumentException('YXmlElement node name cannot be empty.');
        }

        return $this->atomicMutation(function () use ($idKey, $key, $nodeName): YXmlElement {
            $previous = $this->findLatestNestedMapStruct($idKey, $key);
            if ($previous !== null && ! $this->isStructDeleted($previous)) {
                $this->deleteMapStruct($previous);
            }

            $struct = $this->addLocalStruct(self::idFromKey($idKey), $previous === null ? $key : null, [
                'type' => 'ContentType',
                'typeRef' => 3,
                'typeName' => 'YXmlElement',
                'nodeName' => $nodeName,
            ], $previous['id'] ?? null);

            return new YXmlElement($this, self::idKey($struct['id']), $nodeName);
        });
    }

    public function setXmlTextInNestedMap(string $idKey, string $key, string $text = ''): YXmlText
    {
        return $this->atomicMutation(function () use ($idKey, $key, $text): YXmlText {
            $previous = $this->findLatestNestedMapStruct($idKey, $key);
            if ($previous !== null && ! $this->isStructDeleted($previous)) {
                $this->deleteMapStruct($previous);
            }

            $struct = $this->addLocalStruct(self::idFromKey($idKey), $previous === null ? $key : null, [
                'type' => 'ContentType',
                'typeRef' => 6,
                'typeName' => 'YXmlText',
            ], $previous['id'] ?? null);
            $xmlText = new YXmlText($this, self::idKey($struct['id']), '');
            $xmlText->insert(0, $text);

            return $xmlText;
        });
    }

    public function setXmlHookInNestedMap(string $idKey, string $key, string $hookName): YXmlHook
    {
        return $this->atomicMutation(function () use ($idKey, $key, $hookName): YXmlHook {
            $previous = $this->findLatestNestedMapStruct($idKey, $key);
            if ($previous !== null && ! $this->isStructDeleted($previous)) {
                $this->deleteMapStruct($previous);
            }

            $struct = $this->addLocalStruct(self::idFromKey($idKey), $previous === null ? $key : null, [
                'type' => 'ContentType',
                'typeRef' => 5,
                'typeName' => 'YXmlHook',
                'hookName' => $hookName,
            ], $previous['id'] ?? null);

            return new YXmlHook($this, self::idKey($struct['id']), $hookName);
        });
    }

    public function setXmlFragmentInNestedMap(string $idKey, string $key): YNestedXmlFragment
    {
        return $this->atomicMutation(function () use ($idKey, $key): YNestedXmlFragment {
            $previous = $this->findLatestNestedMapStruct($idKey, $key);
            if ($previous !== null && ! $this->isStructDeleted($previous)) {
                $this->deleteMapStruct($previous);
            }

            $struct = $this->addLocalStruct(self::idFromKey($idKey), $previous === null ? $key : null, [
                'type' => 'ContentType',
                'typeRef' => 4,
                'typeName' => 'YXmlFragment',
            ], $previous['id'] ?? null);

            return new YNestedXmlFragment($this, self::idKey($struct['id']));
        });
    }

    public function deleteNestedMapKey(string $idKey, string $key): void
    {
        $struct = $this->findVisibleNestedMapStruct($idKey, $key);
        if ($struct !== null) {
            $this->deleteMapStruct($struct);
        }
    }

    /**
     * @return list<mixed>
     */
    public function nestedArrayValue(string $idKey): array
    {
        $value = $this->nestedJsonById[$idKey] ?? [];
        if (! is_array($value) || ! array_is_list($value)) {
            throw new \UnexpectedValueException('Nested shared type is not an array.');
        }

        return $value;
    }

    public function nestedTextValue(string $idKey): string
    {
        return (string) ($this->nestedJsonById[$idKey] ?? '');
    }

    public function sharedTypeInNestedArray(string $idKey, int $index): YNestedArray|YNestedMap|YNestedText|YNestedXmlFragment|YXmlElement|YXmlText|YXmlHook|null
    {
        $identity = $this->nestedIdentityById[$idKey] ?? $this->nestedArrayValue($idKey);
        if (! is_array($identity) || ! array_is_list($identity) || ! array_key_exists($index, $identity)) {
            return null;
        }

        $childIdKey = $this->sharedTypeIdFromIdentityValue($identity[$index]);

        return $childIdKey === null ? null : $this->nestedSharedType($childIdKey);
    }

    public function sharedTypeInNestedMap(string $idKey, string $key): YNestedArray|YNestedMap|YNestedText|YNestedXmlFragment|YXmlElement|YXmlText|YXmlHook|null
    {
        $identity = $this->nestedIdentityById[$idKey] ?? $this->nestedMapValue($idKey);
        if (! is_array($identity) || ! array_key_exists($key, $identity)) {
            return null;
        }

        $childIdKey = $this->sharedTypeIdFromIdentityValue($identity[$key]);

        return $childIdKey === null ? null : $this->nestedSharedType($childIdKey);
    }

    public function nestedSharedType(string $idKey): YNestedArray|YNestedMap|YNestedText|YNestedXmlFragment|YXmlElement|YXmlText|YXmlHook|null
    {
        $struct = $this->structsById[$idKey] ?? null;
        if ($struct === null || $this->isStructDeleted($struct) || ($struct['content']['type'] ?? null) !== 'ContentType') {
            return null;
        }

        return match ($struct['content']['typeName'] ?? null) {
            'YArray' => new YNestedArray($this, $idKey, $this->nestedArrayValue($idKey)),
            'YMap' => new YNestedMap($this, $idKey, $this->nestedMapValue($idKey)),
            'YText' => new YNestedText($this, $idKey, $this->nestedTextValue($idKey)),
            'YXmlElement' => new YXmlElement($this, $idKey, (string) ($struct['content']['nodeName'] ?? 'UNDEFINED')),
            'YXmlFragment' => new YNestedXmlFragment($this, $idKey),
            'YXmlText' => new YXmlText($this, $idKey, $this->xmlTextValue($idKey)),
            'YXmlHook' => new YXmlHook($this, $idKey, (string) ($struct['content']['hookName'] ?? '')),
            default => null,
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function nestedTextDelta(string $idKey): array
    {
        return $this->textDeltaFromItems($this->collectSequenceItemsForParentId(self::idFromKey($idKey)));
    }

    public function setNestedTextAttribute(string $idKey, string $key, mixed $value): void
    {
        $this->atomicMutation(function () use ($idKey, $key, $value): void {
            $previous = $this->findLatestNestedMapStruct($idKey, $key);
            if ($previous !== null && ! $this->isStructDeleted($previous)) {
                $this->deleteMapStruct($previous);
            }

            $this->addLocalStruct(self::idFromKey($idKey), $previous === null ? $key : null, [
                'type' => 'ContentAny',
                'values' => [$value],
            ], $previous['id'] ?? null);
        });
    }

    public function removeNestedTextAttribute(string $idKey, string $key): void
    {
        $struct = $this->findVisibleNestedMapStruct($idKey, $key);
        if ($struct !== null) {
            $this->deleteMapStruct($struct);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function nestedTextAttributes(string $idKey): array
    {
        $attributes = $this->nestedTextAttributesById[$idKey] ?? [];
        ksort($attributes, SORT_STRING);

        return $attributes;
    }

    public function nestedTextAttribute(string $idKey, string $key): mixed
    {
        return $this->nestedTextAttributes($idKey)[$key] ?? null;
    }

    public function nestedTextHasAttribute(string $idKey, string $key): bool
    {
        return array_key_exists($key, $this->nestedTextAttributes($idKey));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function formatNestedText(string $idKey, int $index, int $length, array $attributes): void
    {
        $this->atomicMutation(function () use ($idKey, $index, $length, $attributes): void {
            $currentLength = $this->sequenceLengthForParentId($idKey, 'text');
            if ($index < 0 || $length < 0 || $index + $length > $currentLength) {
                throw new \InvalidArgumentException('Nested YText format range is out of bounds.');
            }

            if ($length === 0 || $attributes === []) {
                return;
            }

            $startBounds = $this->sequenceBoundsAtId($idKey, $index, 'text');
            $endBounds = $this->sequenceBoundsAtId($idKey, $index + $length, 'text');
            $endOrigin = $endBounds['origin'];

            foreach ($attributes as $key => $value) {
                $this->addLocalFormatStruct(self::idFromKey($idKey), (string) $key, $value, $startBounds['origin'], $startBounds['rightOrigin']);
                $struct = $this->addLocalFormatStruct(self::idFromKey($idKey), (string) $key, null, $endOrigin, $endBounds['rightOrigin']);
                $endOrigin = $struct['id'];
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function nestedMapValue(string $idKey): array
    {
        $value = $this->nestedJsonById[$idKey] ?? [];
        if (! is_array($value)) {
            throw new \UnexpectedValueException('Nested shared type is not a map.');
        }

        return $value;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findVisibleMapStruct(string $name, string $key): ?array
    {
        $match = null;

        foreach ($this->sortedStructs() as $struct) {
            if (($struct['type'] ?? null) !== 'Item' || $this->isStructDeleted($struct)) {
                continue;
            }

            if (
                $this->storedStructParent($struct) === $name
                && $this->storedStructParentSub($struct) === $key
            ) {
                $match = $struct;
            }
        }

        return $match;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLatestMapStruct(string $name, string $key): ?array
    {
        $match = null;

        foreach ($this->sortedStructs() as $struct) {
            if (($struct['type'] ?? null) !== 'Item') {
                continue;
            }

            if (
                $this->storedStructParent($struct) === $name
                && $this->storedStructParentSub($struct) === $key
            ) {
                $match = $struct;
            }
        }

        return $match;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findVisibleNestedMapStruct(string $idKey, string $key): ?array
    {
        $match = null;

        foreach ($this->sortedStructs() as $struct) {
            if (($struct['type'] ?? null) !== 'Item' || $this->isStructDeleted($struct)) {
                continue;
            }

            if (
                $this->storedStructParentIdKey($struct) === $idKey
                && $this->storedStructParentSub($struct) === $key
            ) {
                $match = $struct;
            }
        }

        return $match;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLatestNestedMapStruct(string $idKey, string $key): ?array
    {
        $match = null;

        foreach ($this->sortedStructs() as $struct) {
            if (($struct['type'] ?? null) !== 'Item') {
                continue;
            }

            if (
                $this->storedStructParentIdKey($struct) === $idKey
                && $this->storedStructParentSub($struct) === $key
            ) {
                $match = $struct;
            }
        }

        return $match;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findVisibleXmlAttributeStruct(string $elementIdKey, string $key): ?array
    {
        $match = null;

        foreach ($this->sortedStructs() as $struct) {
            if (($struct['type'] ?? null) !== 'Item' || $this->isStructDeleted($struct)) {
                continue;
            }

            if (
                $this->storedStructParentIdKey($struct) === $elementIdKey
                && $this->storedStructParentSub($struct) === $key
            ) {
                $match = $struct;
            }
        }

        return $match;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLatestXmlAttributeStruct(string $elementIdKey, string $key): ?array
    {
        $match = null;

        foreach ($this->sortedStructs() as $struct) {
            if (($struct['type'] ?? null) !== 'Item') {
                continue;
            }

            if (
                $this->storedStructParentIdKey($struct) === $elementIdKey
                && $this->storedStructParentSub($struct) === $key
            ) {
                $match = $struct;
            }
        }

        return $match;
    }

    /**
     * @param array<string, mixed> $struct
     */
    private function deleteMapStruct(array $struct): void
    {
        $before = $this->json;
        $beforeIdentity = $this->identityJson;
        $beforeNested = $this->nestedJsonById;
        $beforeNestedIdentity = $this->nestedIdentityById;
        $beforeNestedTextAttributes = $this->nestedTextAttributeValues();
        $beforeNestedTextDeltas = $this->observedNestedTextDeltas();
        $deleteItem = [
            'clock' => $struct['id']['clock'],
            'length' => $struct['length'],
        ];
        $this->deleteSet[$struct['id']['client']][] = $deleteItem;
        $beforeTextDeltas = $this->observedTextDeltas();
        $beforeTextAttributes = $this->rootTextAttributeValues();
        $this->rematerialize();
        $observedDeleteSet = [$struct['id']['client'] => [$deleteItem]];
        $this->emitLocalChange($before, $beforeIdentity, $beforeNested, $beforeNestedIdentity, [], $observedDeleteSet, $beforeTextDeltas, $beforeTextAttributes, $beforeNestedTextAttributes, $beforeNestedTextDeltas);
    }

    /**
     * @param array{
     *     structs: list<array<string, mixed>>,
     *     deleteSet: array<int, list<array{clock: int, length: int}>>
     * } $decoded
     */
    private function rematerialize(): void
    {
        $this->json = [];
        $this->identityJson = [];
        $this->rootTextAttributesByName = [];
        $this->nestedJsonById = [];
        $this->nestedIdentityById = [];
        $this->nestedTextAttributesById = [];
        $this->xmlTextById = [];
        $this->xmlNodesById = [];
        $this->xmlRootChildrenByName = [];
        $this->xmlAttributeIdentityById = [];

        $this->materialize([
            'structs' => $this->sortedStructs(),
            'deleteSet' => $this->deleteSet,
        ]);
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    private function addLocalStruct(string|array|null $parent, ?string $parentSub, array $content, ?array $origin = null, ?array $rightOrigin = null): array
    {
        $clock = $this->getStateVector()[$this->clientID] ?? 0;
        $struct = [
            'type' => 'Item',
            'id' => [
                'client' => $this->clientID,
                'clock' => $clock,
            ],
            'length' => $this->contentLength($content),
            'origin' => $origin,
            'rightOrigin' => $rightOrigin,
            'parent' => $origin === null && $rightOrigin === null ? $parent : null,
            'parentSub' => $parentSub,
            'content' => $content,
        ];

        $this->structsById[self::idKey($struct['id'])] = $struct;
        $before = $this->json;
        $beforeIdentity = $this->identityJson;
        $beforeNested = $this->nestedJsonById;
        $beforeNestedIdentity = $this->nestedIdentityById;
        $beforeNestedTextAttributes = $this->nestedTextAttributeValues();
        $beforeNestedTextDeltas = $this->observedNestedTextDeltas();
        $beforeTextDeltas = $this->observedTextDeltas();
        $beforeTextAttributes = $this->rootTextAttributeValues();
        $this->rematerialize();
        $this->emitLocalChange($before, $beforeIdentity, $beforeNested, $beforeNestedIdentity, [$struct], [], $beforeTextDeltas, $beforeTextAttributes, $beforeNestedTextAttributes, $beforeNestedTextDeltas);

        return $struct;
    }

    /**
     * @return array<string, mixed>
     */
    private function addLocalFormatStruct(string|array|null $parent, string $key, mixed $value, ?array $origin, ?array $rightOrigin): array
    {
        return $this->addLocalStruct($parent, null, [
            'type' => 'ContentFormat',
            'key' => $key,
            'value' => $value,
        ], $origin, $rightOrigin);
    }

    private function contentLength(array $content): int
    {
        return match ($content['type']) {
            'ContentString' => self::utf16CodeUnitLength($content['value']),
            'ContentAny' => count($content['values']),
            default => 1,
        };
    }

    /**
     * @param array{
     *     structs: list<array<string, mixed>>,
     *     deleteSet: array<int, list<array{clock: int, length: int}>>
     * } $decoded
     */
    private function materialize(array $decoded): void
    {
        $itemParentRanges = [];
        $sequenceItems = [];
        $pending = $decoded['structs'];

        do {
            $progress = false;
            $stillPending = [];

            foreach ($pending as $struct) {
                if (($struct['type'] ?? null) !== 'Item') {
                    $progress = true;
                    continue;
                }

                $parent = $this->resolveParent($struct, $itemParentRanges);
                if ($parent === null && $this->hasParentDependency($struct)) {
                    $stillPending[] = $struct;
                    continue;
                }

                $parentSub = $this->resolveParentSub($struct, $itemParentRanges);
                $itemParentRanges[$struct['id']['client']][] = [
                    'clock' => $struct['id']['clock'],
                    'end' => $struct['id']['clock'] + $struct['length'],
                    'parent' => $parent,
                    'parentSub' => $parentSub,
                ];

                if ($parent === null) {
                    $progress = true;
                    continue;
                }

                $content = $struct['content'];
                $deletedOffsets = $this->deletedOffsets($struct, $decoded['deleteSet']);

                if ($parentSub !== null) {
                    if (($this->rootTypeHints[$parent] ?? null) === 'text') {
                        $this->materializeTextAttributeItem($parent, $parentSub, $content, $deletedOffsets !== []);
                        $progress = true;
                        continue;
                    }

                    $this->materializeMapItem($parent, $parentSub, $struct, $content, $deletedOffsets !== []);
                    $progress = true;
                    continue;
                }

                $this->materializeSequenceItem($parent, $struct, $content, $deletedOffsets, $sequenceItems);
                $progress = true;
            }

            $pending = $stillPending;
        } while ($pending !== [] && $progress);

        $this->flushSequenceItems($sequenceItems);
        $this->materializeXmlTrees($decoded);
        $this->replaceXmlJsonReferences();
        $this->materializeNestedSharedTypes($decoded);
        $this->refreshXmlAttributeSharedTypeValues();
        $this->rerenderXmlRootValues();
    }

    /**
     * @param array<string, mixed> $struct
     * @param array<int, list<array{clock: int, end: int, parent: string|null, parentSub: string|null}>> $itemParentRanges
     */
    private function resolveParent(array $struct, array $itemParentRanges): ?string
    {
        if (is_string($struct['parent'])) {
            return $struct['parent'];
        }

        foreach (['origin', 'rightOrigin'] as $relation) {
            if (is_array($struct[$relation])) {
                $parent = $this->findParentForId($struct[$relation], $itemParentRanges);

                if ($parent !== null) {
                    return $parent;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $struct
     * @param array<int, list<array{clock: int, end: int, parent: string|null, parentSub: string|null}>> $itemParentRanges
     */
    private function resolveParentSub(array $struct, array $itemParentRanges): ?string
    {
        if ($struct['parentSub'] !== null) {
            return $struct['parentSub'];
        }

        foreach (['origin', 'rightOrigin'] as $relation) {
            if (is_array($struct[$relation])) {
                $parentSub = $this->findParentSubForId($struct[$relation], $itemParentRanges);

                if ($parentSub !== null) {
                    return $parentSub;
                }
            }
        }

        return null;
    }

    /**
     * @param array{client: int, clock: int} $id
     * @param array<int, list<array{clock: int, end: int, parent: string|null, parentSub: string|null}>> $itemParentRanges
     */
    private function findParentForId(array $id, array $itemParentRanges): ?string
    {
        foreach ($itemParentRanges[$id['client']] ?? [] as $range) {
            if ($id['clock'] >= $range['clock'] && $id['clock'] < $range['end']) {
                return $range['parent'];
            }
        }

        return null;
    }

    /**
     * @param array{client: int, clock: int} $id
     * @param array<int, list<array{clock: int, end: int, parent: string|null, parentSub: string|null}>> $itemParentRanges
     */
    private function findParentSubForId(array $id, array $itemParentRanges): ?string
    {
        foreach ($itemParentRanges[$id['client']] ?? [] as $range) {
            if ($id['clock'] >= $range['clock'] && $id['clock'] < $range['end']) {
                return $range['parentSub'];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $struct
     * @param array<int, list<array<string, mixed>>> $itemParentRanges
     */
    private function resolveParentIdKey(array $struct, array $itemParentRanges): ?string
    {
        if (is_array($struct['parent'])) {
            return self::idKey($struct['parent']);
        }

        foreach (['origin', 'rightOrigin'] as $relation) {
            if (is_array($struct[$relation])) {
                $parentIdKey = $this->findParentIdKeyForId($struct[$relation], $itemParentRanges);

                if ($parentIdKey !== null) {
                    return $parentIdKey;
                }
            }
        }

        return null;
    }

    /**
     * @param array{client: int, clock: int} $id
     * @param array<int, list<array<string, mixed>>> $itemParentRanges
     */
    private function findParentIdKeyForId(array $id, array $itemParentRanges): ?string
    {
        foreach ($itemParentRanges[$id['client']] ?? [] as $range) {
            if ($id['clock'] >= $range['clock'] && $id['clock'] < $range['end']) {
                return $range['parentIdKey'] ?? null;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $struct
     */
    private function hasParentDependency(array $struct): bool
    {
        return is_array($struct['origin']) || is_array($struct['rightOrigin']);
    }

    /**
     * @param array<string, mixed> $struct
     * @param array<string, true> $seen
     */
    private function storedStructParent(array $struct, array $seen = []): ?string
    {
        if (is_string($struct['parent'])) {
            return $struct['parent'];
        }

        foreach (['origin', 'rightOrigin'] as $relation) {
            if (! is_array($struct[$relation])) {
                continue;
            }

            $key = self::idKey($struct[$relation]);
            $parentStruct = $this->structContainingId($struct[$relation]);
            if ($parentStruct === null) {
                continue;
            }

            $parentKey = self::idKey($parentStruct['id']);
            if (isset($seen[$key]) || isset($seen[$parentKey])) {
                continue;
            }

            $parent = $this->storedStructParent($parentStruct, $seen + [$key => true, $parentKey => true]);
            if ($parent !== null) {
                return $parent;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $struct
     * @param array<string, true> $seen
     */
    private function storedStructParentIdKey(array $struct, array $seen = []): ?string
    {
        if (is_array($struct['parent'])) {
            return self::idKey($struct['parent']);
        }

        foreach (['origin', 'rightOrigin'] as $relation) {
            if (! is_array($struct[$relation])) {
                continue;
            }

            $key = self::idKey($struct[$relation]);
            $parentStruct = $this->structContainingId($struct[$relation]);
            if ($parentStruct === null) {
                continue;
            }

            $parentKey = self::idKey($parentStruct['id']);
            if (isset($seen[$key]) || isset($seen[$parentKey])) {
                continue;
            }

            $parentIdKey = $this->storedStructParentIdKey($parentStruct, $seen + [$key => true, $parentKey => true]);
            if ($parentIdKey !== null) {
                return $parentIdKey;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $struct
     * @param array<string, true> $seen
     */
    private function storedStructParentSub(array $struct, array $seen = []): ?string
    {
        if ($struct['parentSub'] !== null) {
            return $struct['parentSub'];
        }

        foreach (['origin', 'rightOrigin'] as $relation) {
            if (! is_array($struct[$relation])) {
                continue;
            }

            $key = self::idKey($struct[$relation]);
            $parentStruct = $this->structContainingId($struct[$relation]);
            if ($parentStruct === null) {
                continue;
            }

            $parentKey = self::idKey($parentStruct['id']);
            if (isset($seen[$key]) || isset($seen[$parentKey])) {
                continue;
            }

            $parentSub = $this->storedStructParentSub($parentStruct, $seen + [$key => true, $parentKey => true]);
            if ($parentSub !== null) {
                return $parentSub;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $content
     * @param list<int> $deletedOffsets
     * @param array<string, list<array<string, mixed>>> $sequenceItems
     */
    private function materializeSequenceItem(string $parent, array $struct, array $content, array $deletedOffsets, array &$sequenceItems): void
    {
        $rootType = $this->rootTypeHints[$parent] ?? null;
        $parentMode = in_array($rootType, ['array', 'xml'], true) ? 'array' : null;
        $this->insertSequenceEntries($sequenceItems[$parent], $this->sequenceEntriesForContent($struct, $content, $deletedOffsets, $parentMode));
    }

    /**
     * @param array<string, mixed> $struct
     * @param array<string, mixed> $content
     * @param list<int> $deletedOffsets
     * @return list<array<string, mixed>>
     */
    private function sequenceEntriesForContent(array $struct, array $content, array $deletedOffsets, ?string $parentMode = null): array
    {
        if (($content['type'] ?? null) === 'ContentString') {
            return $this->stringSequenceEntries($struct, $content['value'], $deletedOffsets);
        }

        if (($content['type'] ?? null) === 'ContentAny' || ($content['type'] ?? null) === 'ContentJSON') {
            $entries = [];
            foreach ($content['values'] as $offset => $value) {
                $visible = ! in_array($offset, $deletedOffsets, true);
                $entries[] = $this->sequenceEntry($struct, $offset, $value, 'array', $visible, $visible);
            }

            return $entries;
        }

        if (($content['type'] ?? null) === 'ContentEmbed') {
            $visible = ! in_array(0, $deletedOffsets, true);
            $entry = $this->sequenceEntry($struct, 0, $content['value'], 'text', $visible, $visible);
            $entry['embed'] = true;

            return [$entry];
        }

        if (($content['type'] ?? null) === 'ContentBinary') {
            $visible = ! in_array(0, $deletedOffsets, true);

            return [$this->sequenceEntry($struct, 0, $this->binaryJsonValue($content), 'array', $visible, $visible)];
        }

        if (($content['type'] ?? null) === 'ContentDoc') {
            $visible = ! in_array(0, $deletedOffsets, true);

            return [$this->sequenceEntry($struct, 0, $this->contentDocJsonValue($content), 'array', $visible, $visible)];
        }

        if (($content['type'] ?? null) === 'ContentType') {
            $typeName = (string) ($content['typeName'] ?? '');
            $isXmlType = self::isXmlTypeName($typeName);
            $value = $isXmlType && $parentMode === 'array'
                ? $this->xmlIdentityReference(self::idKey($struct['id']))
                : $this->contentTypeJsonValue($content);
            $mode = $isXmlType && $parentMode !== 'array' ? 'text' : 'array';
            $visible = ! in_array(0, $deletedOffsets, true);

            return [$this->sequenceEntry($struct, 0, $value, $mode, $visible, $visible)];
        }

        if (($content['type'] ?? null) === 'ContentDeleted') {
            $entries = [];

            for ($offset = 0; $offset < $content['length']; $offset++) {
                $entry = $this->sequenceEntry($struct, $offset, '', 'array', false, false);
                $entry['contentDeleted'] = true;
                $entries[] = $entry;
            }

            return $entries;
        }

        if (($content['type'] ?? null) === 'ContentFormat') {
            $entry = $this->sequenceEntry($struct, 0, '', 'text', false, false);
            $entry['formatKey'] = $content['key'];
            $entry['formatValue'] = $content['value'];
            $entry['formatDeleted'] = in_array(0, $deletedOffsets, true);

            return [$entry];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $struct
     * @param list<int> $deletedOffsets
     * @return list<array<string, mixed>>
     */
    private function stringSequenceEntries(array $struct, string $value, array $deletedOffsets): array
    {
        $entries = [];
        $offset = 0;

        foreach (self::utf8Characters($value) as $character) {
            $width = self::utf16CodeUnitLength($character);
            $deletedCodeUnits = [];

            for ($i = 0; $i < $width; $i++) {
                if (in_array($offset + $i, $deletedOffsets, true)) {
                    $deletedCodeUnits[$i] = true;
                }
            }

            foreach (self::utf16CodeUnitsForCharacter($character) as $i => $unit) {
                $isDeleted = isset($deletedCodeUnits[$i]);
                $entry = $this->sequenceEntry(
                    $struct,
                    $offset + $i,
                    $isDeleted ? '' : self::utf16UnitsToString([$unit]),
                    'text',
                    ! $isDeleted,
                    ! $isDeleted
                );
                $entry['utf16CodeUnit'] = $unit;
                $entries[] = $entry;
            }

            $offset += $width;
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $struct
     * @return array<string, mixed>
     */
    private function sequenceEntry(array $struct, int $offset, mixed $value, string $mode, bool $visible, bool $countsPosition): array
    {
        $id = [
            'client' => $struct['id']['client'],
            'clock' => $struct['id']['clock'] + $offset,
        ];
        $origin = $offset === 0 ? $struct['origin'] : [
            'client' => $struct['id']['client'],
            'clock' => $struct['id']['clock'] + $offset - 1,
        ];

        return [
            'id' => $id,
            'idKey' => self::idKey($id),
            'origin' => $origin,
            'originKey' => is_array($origin) ? self::idKey($origin) : null,
            'rightOrigin' => $offset === 0 ? $struct['rightOrigin'] : null,
            'rightOriginKey' => $offset === 0 && is_array($struct['rightOrigin']) ? self::idKey($struct['rightOrigin']) : null,
            'value' => $value,
            'mode' => $mode,
            'visible' => $visible,
            'countsPosition' => $countsPosition,
        ];
    }

    /**
     * @param list<array<string, mixed>>|null $items
     * @param array<string, mixed> $entry
     */
    private function insertSequenceEntry(?array &$items, array $entry): void
    {
        $this->insertSequenceEntries($items, [$entry]);
    }

    /**
     * @param list<array<string, mixed>>|null $items
     * @param list<array<string, mixed>> $entries
     */
    private function insertSequenceEntries(?array &$items, array $entries): void
    {
        $items ??= [];

        if ($entries === []) {
            return;
        }

        $entry = $entries[0];
        $leftIndex = $entry['originKey'] === null ? -1 : $this->findSequenceIndexById($items, $entry['originKey'], -1);
        $index = $leftIndex + 1;
        $limit = $entry['rightOriginKey'] === null ? count($items) : $this->findSequenceIndexById($items, $entry['rightOriginKey'], count($items));
        $conflictingItems = [];
        $itemsBeforeOrigin = [];

        while ($index < $limit) {
            $current = $items[$index];
            $itemsBeforeOrigin[$current['idKey']] = true;
            $conflictingItems[$current['idKey']] = true;

            if ($entry['originKey'] === $current['originKey']) {
                if ($current['id']['client'] < $entry['id']['client']) {
                    $leftIndex = $index;
                    $conflictingItems = [];
                } elseif ($entry['rightOriginKey'] === $current['rightOriginKey']) {
                    break;
                }
            } elseif (
                $current['originKey'] !== null
                && isset($itemsBeforeOrigin[$current['originKey']])
            ) {
                if (! isset($conflictingItems[$current['originKey']])) {
                    $leftIndex = $index;
                    $conflictingItems = [];
                }
            } else {
                break;
            }

            $index++;
        }

        array_splice($items, $leftIndex + 1, 0, $entries);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function findSequenceIndexById(array $items, string $idKey, int $default): int
    {
        foreach ($items as $index => $item) {
            if ($item['idKey'] === $idKey) {
                return $index;
            }
        }

        return $default;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $sequenceItems
     */
    private function flushSequenceItems(array $sequenceItems): void
    {
        foreach ($sequenceItems as $parent => $items) {
            $visibleItems = array_values(array_filter($items, static fn (array $item): bool => $item['visible']));

            if ($this->sequenceItemsRepresentArray($items)) {
                $this->json[$parent] = array_map(static fn (array $item): mixed => $item['value'], $visibleItems);
                continue;
            }

            $this->json[$parent] = $this->renderTextItems($items);
        }
    }

    /**
     * @param list<array<string, mixed>> $delta
     * @param array<string, mixed> $attributes
     */
    private function appendDeltaInsert(array &$delta, mixed $insert, array $attributes): void
    {
        if ($insert === '') {
            return;
        }

        ksort($attributes, SORT_STRING);
        $index = count($delta) - 1;
        $attributesKey = $attributes === [] ? null : $attributes;

        if ($index >= 0 && is_string($insert) && is_string($delta[$index]['insert'])) {
            $lastAttributes = $delta[$index]['attributes'] ?? null;
            if ($lastAttributes === $attributesKey) {
                $delta[$index]['insert'] .= $insert;
                return;
            }
        }

        $operation = ['insert' => $insert];
        if ($attributes !== []) {
            $operation['attributes'] = $attributes;
        }

        $delta[] = $operation;
    }

    /**
     * @return array{origin: array{client: int, clock: int}|null, rightOrigin: array{client: int, clock: int}|null}
     */
    private function sequenceBoundsAt(string $parent, int $index, string $mode): array
    {
        $items = $this->collectSequenceItemsForParent($parent);
        $position = 0;
        $previous = null;
        $listLike = $mode === 'array' || array_key_exists($parent, $this->xmlRootChildrenByName);

        foreach ($items as $item) {
            if ($item['mode'] !== $mode) {
                continue;
            }

            if ($item['countsPosition']) {
                if ($position === $index) {
                    return [
                        'origin' => is_array($previous) ? $previous['id'] : null,
                        'rightOrigin' => $item['id'],
                    ];
                }

                $position++;
                $previous = $item;
                continue;
            }

            if ($position <= $index) {
                if (
                    $listLike
                    && $position === $index
                    && ($item['formatKey'] ?? null) === null
                ) {
                    return [
                        'origin' => is_array($previous) ? $previous['id'] : null,
                        'rightOrigin' => $item['id'],
                    ];
                }

                if (
                    $position === $index
                    && ($item['formatKey'] ?? null) !== null
                    && $item['formatValue'] === null
                    && is_array($previous)
                    && $previous['visible']
                    && $previous['countsPosition']
                ) {
                    return [
                        'origin' => is_array($previous) ? $previous['id'] : null,
                        'rightOrigin' => $item['id'],
                    ];
                }

                $previous = $item;
            }
        }

        if ($position !== $index) {
            throw new \InvalidArgumentException(sprintf('Cannot insert at index %d in shared type "%s".', $index, $parent));
        }

        return [
            'origin' => is_array($previous) ? $previous['id'] : null,
            'rightOrigin' => null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectSequenceItemsForParent(string $targetParent): array
    {
        $itemParentRanges = [];
        $sequenceItems = [];
        $pending = $this->sortedStructs();

        do {
            $progress = false;
            $stillPending = [];

            foreach ($pending as $struct) {
                if (($struct['type'] ?? null) !== 'Item') {
                    $progress = true;
                    continue;
                }

                $parent = $this->resolveParent($struct, $itemParentRanges);
                if ($parent === null && $this->hasParentDependency($struct)) {
                    $stillPending[] = $struct;
                    continue;
                }

                $parentSub = $this->resolveParentSub($struct, $itemParentRanges);
                $itemParentRanges[$struct['id']['client']][] = [
                    'clock' => $struct['id']['clock'],
                    'end' => $struct['id']['clock'] + $struct['length'],
                    'parent' => $parent,
                    'parentSub' => $parentSub,
                ];

                if ($parent !== $targetParent || $parentSub !== null) {
                    $progress = true;
                    continue;
                }

                $rootType = $this->rootTypeHints[$targetParent] ?? null;
                $parentMode = in_array($rootType, ['array', 'xml'], true) ? 'array' : null;
                $this->insertSequenceEntries($sequenceItems, $this->sequenceEntriesForContent($struct, $struct['content'], $this->deletedOffsets($struct, $this->deleteSet), $parentMode));
                $progress = true;
            }

            $pending = $stillPending;
        } while ($pending !== [] && $progress);

        return $sequenceItems;
    }

    private function sequenceLength(string $parent, string $mode): int
    {
        $length = 0;

        foreach ($this->collectSequenceItemsForParent($parent) as $item) {
            if ($item['mode'] === $mode && $item['visible'] && $item['countsPosition']) {
                $length++;
            }
        }

        return $length;
    }

    private function relativePositionFromRootSequence(string $parent, int $index, int $assoc, string $mode): RelativePosition
    {
        if ($assoc < 0) {
            if ($index === 0) {
                return new RelativePosition(null, $parent, null, $assoc);
            }

            $index--;
        }

        $position = 0;
        $last = null;

        foreach ($this->collectSequenceItemsForParent($parent) as $item) {
            if ($item['mode'] !== $mode) {
                continue;
            }

            $last = $item;

            if (! $item['visible'] || ! $item['countsPosition']) {
                continue;
            }

            if ($position === $index) {
                return new RelativePosition(null, $parent, $item['id'], $assoc);
            }

            $position++;
        }

        if ($assoc < 0 && is_array($last)) {
            return new RelativePosition(null, $parent, $last['id'], $assoc);
        }

        return new RelativePosition(null, $parent, null, $assoc);
    }

    private function relativePositionFromNestedSequence(string $idKey, int $index, int $assoc, string $mode): RelativePosition
    {
        $type = self::idFromKey($idKey);

        if ($assoc < 0) {
            if ($index === 0) {
                return new RelativePosition($type, null, null, $assoc);
            }

            $index--;
        }

        $position = 0;
        $last = null;

        foreach ($this->collectSequenceItemsForParentId($type) as $item) {
            if ($item['mode'] !== $mode) {
                continue;
            }

            $last = $item;

            if (! $item['visible'] || ! $item['countsPosition']) {
                continue;
            }

            if ($position === $index) {
                return new RelativePosition($type, null, $item['id'], $assoc);
            }

            $position++;
        }

        if ($assoc < 0 && is_array($last)) {
            return new RelativePosition($type, null, $last['id'], $assoc);
        }

        return new RelativePosition($type, null, null, $assoc);
    }

    /**
     * @param array{client: int, clock: int} $id
     */
    private function absolutePositionFromRootItem(string $parent, array $id, int $assoc): ?AbsolutePosition
    {
        $idKey = self::idKey($id);
        $index = 0;

        foreach ($this->collectSequenceItemsForParent($parent) as $item) {
            if ($item['idKey'] === $idKey) {
                if ($item['visible'] && $item['countsPosition'] && $assoc < 0) {
                    $index++;
                }

                return new AbsolutePosition($this->rootType($parent), $parent, $index, $assoc);
            }

            if ($item['visible'] && $item['countsPosition']) {
                $index++;
            }
        }

        return null;
    }

    /**
     * @param array{client: int, clock: int} $id
     */
    private function absolutePositionFromAnyItem(array $id, int $assoc): ?AbsolutePosition
    {
        foreach ($this->rootTypeNames() as $name) {
            $absolute = $this->absolutePositionFromRootItem($name, $id, $assoc);
            if ($absolute !== null) {
                return $absolute;
            }
        }

        foreach ($this->nestedSequenceTypeIds() as $idKey) {
            $absolute = $this->absolutePositionFromNestedItem($idKey, $id, $assoc);
            if ($absolute !== null) {
                return $absolute;
            }
        }

        return null;
    }

    /**
     * @param array{client: int, clock: int} $id
     */
    private function absolutePositionFromNestedItem(string $parentIdKey, array $id, int $assoc): ?AbsolutePosition
    {
        $mode = $this->nestedSequenceMode($parentIdKey);
        $type = $this->nestedSequenceType($parentIdKey);
        if ($mode === null || $type === null) {
            return null;
        }

        $idKey = self::idKey($id);
        $index = 0;

        foreach ($this->collectSequenceItemsForParentId(self::idFromKey($parentIdKey)) as $item) {
            if ($item['mode'] !== $mode) {
                continue;
            }

            if ($item['idKey'] === $idKey) {
                if ($item['visible'] && $item['countsPosition'] && $assoc < 0) {
                    $index++;
                }

                return new AbsolutePosition(
                    $type,
                    $this->nestedSequenceTypeName($parentIdKey),
                    $index,
                    $assoc,
                    self::idFromKey($parentIdKey)
                );
            }

            if ($item['visible'] && $item['countsPosition']) {
                $index++;
            }
        }

        return null;
    }

    private function rootType(string $name): YText|YArray|YXmlFragment
    {
        if (array_key_exists($name, $this->xmlRootChildrenByName)) {
            return $this->getXmlFragment($name);
        }

        $value = $this->json[$name] ?? null;
        if (is_array($value) && array_is_list($value)) {
            return $this->getArray($name);
        }

        return $this->getText($name);
    }

    private function rootTypeLength(string $name): int
    {
        $type = $this->rootType($name);

        return $type->length();
    }

    /**
     * @return list<string>
     */
    private function rootTypeNames(): array
    {
        return array_values(array_unique(array_merge(array_keys($this->json), array_keys($this->xmlRootChildrenByName))));
    }

    /**
     * @return list<string>
     */
    private function nestedSequenceTypeIds(): array
    {
        $ids = [];

        foreach ($this->structsById as $idKey => $struct) {
            if (($struct['type'] ?? null) !== 'Item' || ($struct['content']['type'] ?? null) !== 'ContentType') {
                continue;
            }

            if ($this->nestedSequenceMode($idKey) !== null) {
                $ids[] = $idKey;
            }
        }

        usort($ids, static function (string $left, string $right): int {
            $leftId = self::idFromKey($left);
            $rightId = self::idFromKey($right);

            return [$leftId['client'], $leftId['clock']] <=> [$rightId['client'], $rightId['clock']];
        });

        return $ids;
    }

    private function nestedSequenceMode(string $idKey): ?string
    {
        return match ($this->nestedSequenceTypeName($idKey)) {
            'YArray' => 'array',
            'YText', 'YXmlText' => 'text',
            'YXmlElement', 'YXmlFragment' => 'array',
            default => null,
        };
    }

    private function nestedSequenceTypeName(string $idKey): ?string
    {
        $typeName = $this->structsById[$idKey]['content']['typeName'] ?? null;

        return is_string($typeName) ? $typeName : null;
    }

    private function nestedSequenceType(string $idKey): YNestedText|YNestedArray|YNestedXmlFragment|YXmlText|YXmlElement|null
    {
        return match ($this->nestedSequenceTypeName($idKey)) {
            'YArray' => new YNestedArray($this, $idKey, $this->nestedArrayValue($idKey)),
            'YText' => new YNestedText($this, $idKey, $this->nestedTextValue($idKey)),
            'YXmlFragment' => new YNestedXmlFragment($this, $idKey),
            'YXmlText' => new YXmlText($this, $idKey, $this->xmlTextValue($idKey)),
            'YXmlElement' => new YXmlElement($this, $idKey, (string) ($this->xmlNodesById[$idKey]['nodeName'] ?? 'UNDEFINED')),
            default => null,
        };
    }

    private function sequenceLengthForParentId(string $idKey, string $mode): int
    {
        $length = 0;

        foreach ($this->collectSequenceItemsForParentId(self::idFromKey($idKey)) as $item) {
            if ($item['mode'] === $mode && $item['visible'] && $item['countsPosition']) {
                $length++;
            }
        }

        return $length;
    }

    /**
     * @return array{origin: array{client: int, clock: int}|null, rightOrigin: array{client: int, clock: int}|null}
     */
    private function xmlTextBoundsAt(string $textIdKey, int $index): array
    {
        return $this->sequenceBoundsAtId($textIdKey, $index, 'text');
    }

    /**
     * @return array{origin: array{client: int, clock: int}|null, rightOrigin: array{client: int, clock: int}|null}
     */
    private function sequenceBoundsAtId(string $idKey, int $index, string $mode): array
    {
        $items = $this->collectSequenceItemsForParentId(self::idFromKey($idKey));
        $position = 0;
        $previous = null;
        $typeName = $this->nestedSequenceTypeName($idKey);
        $listLike = $mode === 'array' || $typeName === 'YXmlElement' || $typeName === 'YXmlFragment';

        foreach ($items as $item) {
            if ($item['mode'] !== $mode) {
                continue;
            }

            if ($item['countsPosition']) {
                if ($position === $index) {
                    return [
                        'origin' => is_array($previous) ? $previous['id'] : null,
                        'rightOrigin' => $item['id'],
                    ];
                }

                $position++;
                $previous = $item;
                continue;
            }

            if ($position <= $index) {
                if (
                    $listLike
                    && $position === $index
                    && ($item['formatKey'] ?? null) === null
                ) {
                    return [
                        'origin' => is_array($previous) ? $previous['id'] : null,
                        'rightOrigin' => $item['id'],
                    ];
                }

                if (
                    $position === $index
                    && ($item['formatKey'] ?? null) !== null
                    && $item['formatValue'] === null
                    && is_array($previous)
                    && $previous['visible']
                    && $previous['countsPosition']
                ) {
                    return [
                        'origin' => is_array($previous) ? $previous['id'] : null,
                        'rightOrigin' => $item['id'],
                    ];
                }

                $previous = $item;
            }
        }

        if ($position !== $index) {
            throw new \InvalidArgumentException('Nested shared type insert index is out of bounds.');
        }

        return [
            'origin' => is_array($previous) ? $previous['id'] : null,
            'rightOrigin' => null,
        ];
    }

    /**
     * @param array{client: int, clock: int} $targetParent
     * @return list<array<string, mixed>>
     */
    private function collectSequenceItemsForParentId(array $targetParent): array
    {
        $targetParentKey = self::idKey($targetParent);
        $itemParentRanges = [];
        $sequenceItems = [];
        $pending = $this->sortedStructs();

        do {
            $progress = false;
            $stillPending = [];

            foreach ($pending as $struct) {
                if (($struct['type'] ?? null) !== 'Item') {
                    $progress = true;
                    continue;
                }

                $parentIdKey = $this->resolveParentIdKey($struct, $itemParentRanges);
                if ($parentIdKey === null && $this->hasParentDependency($struct)) {
                    $stillPending[] = $struct;
                    continue;
                }

                $parentSub = $this->resolveParentSub($struct, $itemParentRanges);
                $itemParentRanges[$struct['id']['client']][] = [
                    'clock' => $struct['id']['clock'],
                    'end' => $struct['id']['clock'] + $struct['length'],
                    'parent' => null,
                    'parentIdKey' => $parentIdKey,
                    'parentSub' => $parentSub,
                ];

                if ($parentIdKey !== $targetParentKey || $parentSub !== null) {
                    $progress = true;
                    continue;
                }

                $parentTypeName = $this->nestedSequenceTypeName($targetParentKey);
                $parentMode = in_array($parentTypeName, ['YArray', 'YXmlElement', 'YXmlFragment'], true) ? 'array' : null;
                $this->insertSequenceEntries($sequenceItems, $this->sequenceEntriesForContent($struct, $struct['content'], $this->deletedOffsets($struct, $this->deleteSet), $parentMode));
                $progress = true;
            }

            $pending = $stillPending;
        } while ($pending !== [] && $progress);

        return $sequenceItems;
    }

    /**
     * @param array<string, mixed> $content
     */
    private function materializeMapItem(string $parent, string $key, array $struct, array $content, bool $deleted): void
    {
        $this->json[$parent] ??= [];

        if ($deleted) {
            unset($this->json[$parent][$key]);
            return;
        }

        if (($content['type'] ?? null) === 'ContentAny' || ($content['type'] ?? null) === 'ContentJSON') {
            $this->json[$parent][$key] = $content['values'][0] ?? null;
            return;
        }

        if (($content['type'] ?? null) === 'ContentString') {
            $this->json[$parent][$key] = $content['value'];
            return;
        }

        if (($content['type'] ?? null) === 'ContentBinary') {
            $this->json[$parent][$key] = $this->binaryJsonValue($content);
            return;
        }

        if (($content['type'] ?? null) === 'ContentDoc') {
            $this->json[$parent][$key] = $this->contentDocJsonValue($content);
            return;
        }

        if (($content['type'] ?? null) === 'ContentType') {
            $this->json[$parent][$key] = self::isXmlTypeName((string) ($content['typeName'] ?? ''))
                ? $this->xmlIdentityReference(self::idKey($struct['id']))
                : $this->contentTypeJsonValue($content);
        }
    }

    /**
     * @param array<string, mixed> $content
     */
    private function materializeTextAttributeItem(string $parent, string $key, array $content, bool $deleted): void
    {
        $this->json[$parent] ??= '';

        if ($deleted || ($content['type'] ?? null) === 'ContentDeleted') {
            unset($this->rootTextAttributesByName[$parent][$key]);
            return;
        }

        $this->rootTextAttributesByName[$parent][$key] = $this->mapLikeAttributeValue($content);
    }

    /**
     * @param array<string, mixed> $content
     */
    private function contentTypeJsonValue(array $content): mixed
    {
        return match ($content['typeName'] ?? null) {
            'YText', 'YXmlText' => '',
            'YArray', 'YXmlFragment' => [],
            'YXmlElement' => '<' . strtolower((string) ($content['nodeName'] ?? 'UNDEFINED')) . '></' . strtolower((string) ($content['nodeName'] ?? 'UNDEFINED')) . '>',
            'YMap' => [],
            'YXmlHook' => '[object Object]',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $content
     */
    private function contentDocJsonValue(array $content): YSubdoc
    {
        $opts = $content['opts'] ?? [];
        if (! is_array($opts)) {
            $opts = [];
        }

        return new YSubdoc((string) $content['guid'], $opts, $this);
    }

    /**
     * @param list<array<string, mixed>> $structs
     * @param array<int, list<array{clock: int, length: int}>> $deleteSet
     * @param list<YSubdoc> $loadedSubdocs
     * @return array{loaded: list<YSubdoc>, added: list<YSubdoc>, removed: list<YSubdoc>}
     */
    private function subdocChanges(array $structs, array $deleteSet, array $loadedSubdocs = []): array
    {
        $added = [];
        $loaded = [];
        $removed = [];
        $addedIdKeys = [];
        $loadedGuids = [];

        foreach ($structs as $struct) {
            if (($struct['type'] ?? null) !== 'Item' || ($struct['content']['type'] ?? null) !== 'ContentDoc') {
                continue;
            }

            $idKey = self::idKey($struct['id']);
            $subdoc = $this->contentDocJsonValue($struct['content']);
            if ($this->deletedOffsets($struct, $deleteSet) !== []) {
                $addedIdKeys[$idKey] = true;
                if ($subdoc->shouldLoad()) {
                    $loaded[] = $subdoc;
                    $loadedGuids[$subdoc->guid()] = true;
                }
                continue;
            }

            $addedIdKeys[$idKey] = true;
            $added[] = $subdoc;
            if ($subdoc->shouldLoad()) {
                $loaded[] = $subdoc;
                $loadedGuids[$subdoc->guid()] = true;
            }
        }

        foreach ($loadedSubdocs as $subdoc) {
            if (isset($loadedGuids[$subdoc->guid()])) {
                continue;
            }

            $loaded[] = $subdoc;
            $loadedGuids[$subdoc->guid()] = true;
        }

        foreach ($this->sortedStructs() as $struct) {
            if (($struct['type'] ?? null) !== 'Item' || ($struct['content']['type'] ?? null) !== 'ContentDoc') {
                continue;
            }

            if (isset($addedIdKeys[self::idKey($struct['id'])])) {
                continue;
            }

            if ($this->deletedOffsets($struct, $deleteSet) === []) {
                continue;
            }

            $removed[] = $this->contentDocJsonValue($struct['content']);
        }

        return [
            'loaded' => $loaded,
            'added' => $added,
            'removed' => $removed,
        ];
    }

    private function jsonValue(mixed $value): mixed
    {
        if ($value instanceof YSubdoc) {
            return [];
        }

        if (! is_array($value)) {
            return $value;
        }

        return array_map(fn (mixed $nested): mixed => $this->jsonValue($nested), $value);
    }

    /**
     * @param array<string, mixed> $content
     * @return list<int>
     */
    private function binaryJsonValue(array $content): array
    {
        $bytes = base64_decode((string) $content['base64'], true);
        if (! is_string($bytes)) {
            throw new \UnexpectedValueException('Invalid base64 binary content.');
        }

        return array_values(unpack('C*', $bytes) ?: []);
    }

    /**
     * @param array{
     *     structs: list<array<string, mixed>>,
     *     deleteSet: array<int, list<array{clock: int, length: int}>>
     * } $decoded
     */
    private function materializeNestedSharedTypes(array $decoded): void
    {
        $nodes = [];
        $rootSequences = [];
        $rootMaps = [];
        $ranges = [];
        $pending = $decoded['structs'];

        foreach ($decoded['structs'] as $struct) {
            if (($struct['type'] ?? null) !== 'Item' || $this->isStructDeletedBySet($struct, $decoded['deleteSet'])) {
                continue;
            }

            $content = $struct['content'];
            $typeName = (string) ($content['typeName'] ?? '');
            if (($content['type'] ?? null) === 'ContentType' && self::isNestedSharedTypeName($typeName)) {
                $nodes[self::idKey($struct['id'])] = [
                    'typeName' => $typeName,
                    'sequence' => [],
                    'map' => [],
                ];
            }
        }

        do {
            $progress = false;
            $stillPending = [];

            foreach ($pending as $struct) {
                if (($struct['type'] ?? null) !== 'Item') {
                    $progress = true;
                    continue;
                }

                $container = $this->nestedContainerForStruct($struct, $ranges, $nodes);
                if ($container === null && $this->hasParentDependency($struct)) {
                    $stillPending[] = $struct;
                    continue;
                }

                $parentSub = $this->resolveNestedParentSub($struct, $ranges);
                $ranges[$struct['id']['client']][] = [
                    'clock' => $struct['id']['clock'],
                    'end' => $struct['id']['clock'] + $struct['length'],
                    'container' => $container,
                    'parentSub' => $parentSub,
                ];

                if ($container === null) {
                    $progress = true;
                    continue;
                }

                $deletedOffsets = $this->deletedOffsets($struct, $decoded['deleteSet']);
                if (str_starts_with($container, 'root:')) {
                    $rootName = substr($container, 5);

                    if ($parentSub === null) {
                        $containerTypeName = ($this->rootTypeHints[$rootName] ?? null) === 'array' ? 'YArray' : null;
                        $this->appendNestedSequenceEntries($rootSequences[$rootName], $struct, $deletedOffsets, $nodes, $containerTypeName);
                    } elseif ($deletedOffsets === [] || count($deletedOffsets) < $struct['length']) {
                        $rootMaps[$rootName][$parentSub] = $this->nestedContentValue($struct, $nodes);
                    }

                    $progress = true;
                    continue;
                }

                $nodeKey = substr($container, 5);
                if (! isset($nodes[$nodeKey])) {
                    $progress = true;
                    continue;
                }

                if ($parentSub === null) {
                    $this->appendNestedSequenceEntries($nodes[$nodeKey]['sequence'], $struct, $deletedOffsets, $nodes, $nodes[$nodeKey]['typeName']);
                    $progress = true;
                    continue;
                }

                if ($deletedOffsets !== [] && count($deletedOffsets) >= $struct['length']) {
                    if (($nodes[$nodeKey]['typeName'] ?? null) === 'YText' && $parentSub !== null) {
                        unset($this->nestedTextAttributesById[$nodeKey][$parentSub]);
                    }

                    $progress = true;
                    continue;
                }

                if ($parentSub !== null) {
                    if (($nodes[$nodeKey]['typeName'] ?? null) === 'YText') {
                        $this->nestedTextAttributesById[$nodeKey][$parentSub] = $this->mapLikeAttributeValue($struct['content']);
                        $progress = true;
                        continue;
                    }

                    $nodes[$nodeKey]['map'][$parentSub] = $this->nestedContentValue($struct, $nodes);
                    $progress = true;
                    continue;
                }
            }

            $pending = $stillPending;
        } while ($pending !== [] && $progress);

        foreach ($rootSequences as $rootName => $items) {
            if ($this->sequenceContainsNestedNode($items)) {
                $this->json[$rootName] = $this->renderNestedSequence($items, $nodes);
                $this->identityJson[$rootName] = $this->renderNestedIdentitySequence($items, $nodes);
            }
        }

        foreach ($rootMaps as $rootName => $values) {
            $this->json[$rootName] ??= [];
            $this->identityJson[$rootName] ??= $this->json[$rootName];
            foreach ($values as $key => $value) {
                if (is_array($value) && isset($value['nodeKey'])) {
                    $this->json[$rootName][$key] = $this->renderNestedNode($nodes, $value['nodeKey']);
                    $this->identityJson[$rootName][$key] = $this->nestedIdentityReference($value['nodeKey']);
                }
            }
        }

        foreach (array_keys($nodes) as $nodeKey) {
            $this->nestedJsonById[$nodeKey] = $this->renderNestedNode($nodes, $nodeKey);
            $this->nestedIdentityById[$nodeKey] = $this->renderNestedIdentityNode($nodes, $nodeKey);
        }
    }

    /**
     * @param array<string, mixed> $struct
     * @param array<int, list<array{clock: int, end: int, container: string|null, parentSub: string|null}>> $ranges
     * @param array<string, array<string, mixed>> $nodes
     */
    private function nestedContainerForStruct(array $struct, array $ranges, array $nodes): ?string
    {
        if (is_string($struct['parent'])) {
            return 'root:' . $struct['parent'];
        }

        if (is_array($struct['parent'])) {
            $parentKey = self::idKey($struct['parent']);
            if (isset($nodes[$parentKey])) {
                return 'node:' . $parentKey;
            }

            $parentStruct = $this->structContainingId($struct['parent']);
            if ($parentStruct !== null && self::isXmlTypeName((string) ($parentStruct['content']['typeName'] ?? ''))) {
                return null;
            }

            return $this->findNestedContainerForId($struct['parent'], $ranges);
        }

        foreach (['origin', 'rightOrigin'] as $relation) {
            if (is_array($struct[$relation])) {
                $container = $this->findNestedContainerForId($struct[$relation], $ranges);
                if ($container !== null) {
                    return $container;
                }
            }
        }

        return null;
    }

    /**
     * @param array{client: int, clock: int} $id
     * @return array<string, mixed>|null
     */
    private function structContainingId(array $id): ?array
    {
        $key = self::idKey($id);
        if (isset($this->structsById[$key])) {
            return $this->structsById[$key];
        }

        foreach ($this->structsById as $struct) {
            if (($struct['type'] ?? null) !== 'Item' || $struct['id']['client'] !== $id['client']) {
                continue;
            }

            $clock = $struct['id']['clock'];
            if ($id['clock'] >= $clock && $id['clock'] < $clock + $struct['length']) {
                return $struct;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $struct
     * @param array<int, list<array{clock: int, end: int, container: string|null, parentSub: string|null}>> $ranges
     */
    private function resolveNestedParentSub(array $struct, array $ranges): ?string
    {
        if ($struct['parentSub'] !== null) {
            return $struct['parentSub'];
        }

        foreach (['origin', 'rightOrigin'] as $relation) {
            if (is_array($struct[$relation])) {
                $parentSub = $this->findNestedParentSubForId($struct[$relation], $ranges);

                if ($parentSub !== null) {
                    return $parentSub;
                }
            }
        }

        return null;
    }

    /**
     * @param array{client: int, clock: int} $id
     * @param array<int, list<array{clock: int, end: int, container: string|null, parentSub: string|null}>> $ranges
     */
    private function findNestedContainerForId(array $id, array $ranges): ?string
    {
        foreach ($ranges[$id['client']] ?? [] as $range) {
            if ($id['clock'] >= $range['clock'] && $id['clock'] < $range['end']) {
                return $range['container'];
            }
        }

        return null;
    }

    /**
     * @param array{client: int, clock: int} $id
     * @param array<int, list<array{clock: int, end: int, container: string|null, parentSub: string|null}>> $ranges
     */
    private function findNestedParentSubForId(array $id, array $ranges): ?string
    {
        foreach ($ranges[$id['client']] ?? [] as $range) {
            if ($id['clock'] >= $range['clock'] && $id['clock'] < $range['end']) {
                return $range['parentSub'];
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>>|null $items
     * @param array<string, mixed> $struct
     * @param list<int> $deletedOffsets
     * @param array<string, array<string, mixed>> $nodes
     */
    private function appendNestedSequenceEntries(?array &$items, array $struct, array $deletedOffsets, array $nodes, ?string $containerTypeName): void
    {
        $this->insertSequenceEntries($items, $this->nestedSequenceEntries($struct, $deletedOffsets, $nodes, $containerTypeName));
    }

    /**
     * @param array<string, mixed> $struct
     * @param list<int> $deletedOffsets
     * @param array<string, array<string, mixed>> $nodes
     * @return list<array<string, mixed>>
     */
    private function nestedSequenceEntries(array $struct, array $deletedOffsets, array $nodes, ?string $containerTypeName): array
    {
        $idKey = self::idKey($struct['id']);
        $content = $struct['content'];

        if (($content['type'] ?? null) === 'ContentType' && isset($nodes[$idKey])) {
            $visible = ! in_array(0, $deletedOffsets, true);
            $entry = $this->sequenceEntry($struct, 0, ['nodeKey' => $idKey], $containerTypeName === 'YText' ? 'text' : 'array', $visible, $visible);

            return [$entry];
        }

        if (($content['type'] ?? null) === 'ContentType' && self::isXmlTypeName((string) ($content['typeName'] ?? '')) && $containerTypeName === 'YArray') {
            $visible = ! in_array(0, $deletedOffsets, true);

            return [$this->sequenceEntry($struct, 0, $this->xmlIdentityReference($idKey), 'array', $visible, $visible)];
        }

        return $this->sequenceEntriesForContent($struct, $content, $deletedOffsets);
    }

    /**
     * @param array<string, mixed> $struct
     * @param array<string, array<string, mixed>> $nodes
     */
    private function nestedContentValue(array $struct, array $nodes): mixed
    {
        $content = $struct['content'];
        $idKey = self::idKey($struct['id']);

        if (($content['type'] ?? null) === 'ContentType' && isset($nodes[$idKey])) {
            return ['nodeKey' => $idKey];
        }

        if (($content['type'] ?? null) === 'ContentType' && self::isXmlTypeName((string) ($content['typeName'] ?? ''))) {
            return $this->xmlIdentityReference($idKey);
        }

        if (($content['type'] ?? null) === 'ContentAny' || ($content['type'] ?? null) === 'ContentJSON') {
            return $content['values'][0] ?? null;
        }

        if (($content['type'] ?? null) === 'ContentString') {
            return $content['value'];
        }

        if (($content['type'] ?? null) === 'ContentBinary') {
            return $this->binaryJsonValue($content);
        }

        if (($content['type'] ?? null) === 'ContentDoc') {
            return $this->contentDocJsonValue($content);
        }

        if (($content['type'] ?? null) === 'ContentType') {
            return $this->contentTypeJsonValue($content);
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function sequenceContainsNestedNode(array $items): bool
    {
        foreach ($items as $item) {
            if (! $item['visible'] || ! is_array($item['value'])) {
                continue;
            }

            if (isset($item['value']['nodeKey']) || $this->xmlTypeIdFromIdentityValue($item['value']) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, array<string, mixed>> $nodes
     */
    private function renderNestedSequence(array $items, array $nodes): mixed
    {
        $visibleItems = array_values(array_filter($items, static fn (array $item): bool => $item['visible']));

        if ($this->sequenceItemsRepresentArray($items)) {
            return array_map(fn (array $item): mixed => $this->renderNestedValue($nodes, $item['value']), $visibleItems);
        }

        return $this->renderTextItems($items);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function sequenceItemsRepresentArray(array $items): bool
    {
        $hasArrayItem = false;

        foreach ($items as $item) {
            if (($item['formatKey'] ?? null) !== null || ($item['contentDeleted'] ?? false)) {
                continue;
            }

            if ($item['mode'] !== 'array') {
                return false;
            }

            $hasArrayItem = true;
        }

        return $hasArrayItem;
    }

    /**
     * @param array<string, array<string, mixed>> $nodes
     */
    private function renderNestedValue(array $nodes, mixed $value): mixed
    {
        if (is_array($value) && isset($value['nodeKey'])) {
            return $this->renderNestedNode($nodes, $value['nodeKey']);
        }

        $xmlNodeKey = $this->xmlTypeIdFromIdentityValue($value);
        if ($xmlNodeKey !== null) {
            return $this->xmlNodeJsonValue($xmlNodeKey);
        }

        return $value;
    }

    /**
     * @param array<string, array<string, mixed>> $nodes
     */
    private function renderNestedIdentityValue(array $nodes, mixed $value): mixed
    {
        if (is_array($value) && isset($value['nodeKey'])) {
            return $this->nestedIdentityReference($value['nodeKey']);
        }

        $xmlNodeKey = $this->xmlTypeIdFromIdentityValue($value);
        if ($xmlNodeKey !== null) {
            return $this->nestedIdentityReference($xmlNodeKey);
        }

        return $value;
    }

    /**
     * @param array<string, array<string, mixed>> $nodes
     */
    private function renderNestedNode(array $nodes, string $nodeKey): mixed
    {
        $node = $nodes[$nodeKey];

        return match ($node['typeName']) {
            'YArray' => $node['sequence'] === [] ? [] : $this->renderNestedSequence($node['sequence'], $nodes),
            'YMap' => $this->renderNestedMap($node['map'], $nodes),
            'YText' => $node['sequence'] === [] ? '' : $this->renderNestedSequence($node['sequence'], $nodes),
            default => null,
        };
    }

    /**
     * @param array<string, array<string, mixed>> $nodes
     */
    private function renderNestedIdentityNode(array $nodes, string $nodeKey): mixed
    {
        $node = $nodes[$nodeKey];

        return match ($node['typeName']) {
            'YArray' => $node['sequence'] === [] ? [] : $this->renderNestedIdentitySequence($node['sequence'], $nodes),
            'YMap' => $this->renderNestedIdentityMap($node['map'], $nodes),
            'YText' => $node['sequence'] === [] ? '' : $this->renderNestedSequence($node['sequence'], $nodes),
            default => null,
        };
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, array<string, mixed>> $nodes
     */
    private function renderNestedIdentitySequence(array $items, array $nodes): mixed
    {
        $visibleItems = array_values(array_filter($items, static fn (array $item): bool => $item['visible']));

        if ($this->sequenceItemsRepresentArray($items)) {
            return array_map(fn (array $item): mixed => $this->renderNestedIdentityValue($nodes, $item['value']), $visibleItems);
        }

        return $this->renderTextItems($items);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, array<string, mixed>> $nodes
     * @return array<string, mixed>
     */
    private function renderNestedIdentityMap(array $values, array $nodes): array
    {
        $rendered = [];

        foreach ($values as $key => $value) {
            $rendered[$key] = $this->renderNestedIdentityValue($nodes, $value);
        }

        ksort($rendered, SORT_STRING);

        return $rendered;
    }

    /**
     * @return array{__yjsSharedTypeId: string}
     */
    private function nestedIdentityReference(string $nodeKey): array
    {
        return ['__yjsSharedTypeId' => $nodeKey];
    }

    /**
     * @return array{__yjsXmlNodeId: string}
     */
    private function xmlIdentityReference(string $nodeKey): array
    {
        return ['__yjsXmlNodeId' => $nodeKey];
    }

    private function sharedTypeIdFromIdentityValue(mixed $value): ?string
    {
        return is_array($value) && is_string($value['__yjsSharedTypeId'] ?? null)
            ? $value['__yjsSharedTypeId']
            : null;
    }

    private function xmlTypeIdFromIdentityValue(mixed $value): ?string
    {
        return is_array($value) && is_string($value['__yjsXmlNodeId'] ?? null)
            ? $value['__yjsXmlNodeId']
            : null;
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, array<string, mixed>> $nodes
     * @return array<string, mixed>
     */
    private function renderNestedMap(array $values, array $nodes): array
    {
        $rendered = [];

        foreach ($values as $key => $value) {
            $rendered[$key] = $this->renderNestedValue($nodes, $value);
        }

        return $rendered;
    }

    private static function isNestedSharedTypeName(string $typeName): bool
    {
        return in_array($typeName, ['YArray', 'YMap', 'YText'], true);
    }

    /**
     * @param array{
     *     structs: list<array<string, mixed>>,
     *     deleteSet: array<int, list<array{clock: int, length: int}>>
     * } $decoded
     */
    private function materializeXmlTrees(array $decoded): void
    {
        $nodes = [];
        $rootChildren = [];

        foreach ($decoded['structs'] as $struct) {
            if (($struct['type'] ?? null) !== 'Item') {
                continue;
            }

            $content = $struct['content'];
            if (($content['type'] ?? null) === 'ContentType' && self::isXmlTypeName((string) ($content['typeName'] ?? ''))) {
                $idKey = self::idKey($struct['id']);
                $nodes[$idKey] = [
                    'typeName' => $content['typeName'],
                    'nodeName' => $content['nodeName'] ?? null,
                    'hookName' => $content['hookName'] ?? null,
                    'attributes' => [],
                    'children' => [],
                    'text' => '',
                    'textItems' => [],
                ];
            }
        }

        $xmlParentRanges = [];
        $pending = $decoded['structs'];

        do {
            $progress = false;
            $stillPending = [];

            foreach ($pending as $struct) {
                if (($struct['type'] ?? null) !== 'Item') {
                    $progress = true;
                    continue;
                }

                $content = $struct['content'];
                $idKey = self::idKey($struct['id']);
                $parent = $this->resolveXmlParent($struct, $xmlParentRanges);
                $parentRoot = $parent['root'];
                $parentKey = $parent['idKey'];

                if ($parentRoot === null && $parentKey === null && $this->hasParentDependency($struct)) {
                    $stillPending[] = $struct;
                    continue;
                }

                $parentSub = $this->resolveXmlParentSub($struct, $xmlParentRanges);
                $xmlParentRanges[$struct['id']['client']][] = [
                    'clock' => $struct['id']['clock'],
                    'end' => $struct['id']['clock'] + $struct['length'],
                    'parentRoot' => $parentRoot,
                    'parentIdKey' => $parentKey,
                    'parentSub' => $parentSub,
                ];

                $deletedOffsets = $this->deletedOffsets($struct, $decoded['deleteSet']);
                $isFullyDeleted = $deletedOffsets !== [] && count($deletedOffsets) >= $struct['length'];

                if (isset($nodes[$idKey]) && $parentSub === null) {
                    $rootHint = $parentRoot === null ? null : ($this->rootTypeHints[$parentRoot] ?? null);
                    if ($parentRoot !== null && $parentSub === null && ($rootHint === null || $rootHint === 'xml')) {
                        $this->insertSequenceEntry(
                            $rootChildren[$parentRoot],
                            $this->sequenceEntry($struct, 0, $idKey, 'array', ! $isFullyDeleted, true)
                        );
                        $progress = true;
                        continue;
                    }

                    if ($parentKey !== null && isset($nodes[$parentKey])) {
                        $this->insertSequenceEntry(
                            $nodes[$parentKey]['children'],
                            $this->sequenceEntry($struct, 0, $idKey, 'array', ! $isFullyDeleted, true)
                        );
                        $progress = true;
                        continue;
                    }
                }

                if ($parentSub === null && in_array(($content['type'] ?? null), ['ContentAny', 'ContentJSON', 'ContentDeleted'], true)) {
                    if ($parentRoot !== null && ($this->rootTypeHints[$parentRoot] ?? null) === 'xml') {
                        $this->insertSequenceEntries(
                            $rootChildren[$parentRoot],
                            $this->sequenceEntriesForContent($struct, $content, $deletedOffsets, 'array')
                        );
                        $progress = true;
                        continue;
                    }

                    if ($parentKey !== null && isset($nodes[$parentKey])) {
                        $this->insertSequenceEntries(
                            $nodes[$parentKey]['children'],
                            $this->sequenceEntriesForContent($struct, $content, $deletedOffsets, 'array')
                        );
                        $progress = true;
                        continue;
                    }
                }

                if (
                    $parentKey !== null
                    && isset($nodes[$parentKey])
                    && in_array(($content['type'] ?? null), ['ContentString', 'ContentFormat', 'ContentEmbed'], true)
                ) {
                    $this->insertSequenceEntries($nodes[$parentKey]['textItems'], $this->sequenceEntriesForContent($struct, $content, $deletedOffsets));
                    $progress = true;
                    continue;
                }

                if ($isFullyDeleted) {
                    $progress = true;
                    continue;
                }

                if ($parentKey === null || ! isset($nodes[$parentKey])) {
                    $progress = true;
                    continue;
                }

                if ($parentSub !== null) {
                    if ($deletedOffsets !== []) {
                        unset($nodes[$parentKey]['attributes'][$parentSub]);
                        unset($this->xmlAttributeIdentityById[$parentKey][$parentSub]);
                        $progress = true;
                        continue;
                    }

                    if (($content['type'] ?? null) === 'ContentType') {
                        $attributeIdKey = self::idKey($struct['id']);
                        $this->xmlAttributeIdentityById[$parentKey][$parentSub] = self::isXmlTypeName((string) ($content['typeName'] ?? ''))
                            ? $this->xmlIdentityReference($attributeIdKey)
                            : $this->nestedIdentityReference($attributeIdKey);
                    } else {
                        unset($this->xmlAttributeIdentityById[$parentKey][$parentSub]);
                    }

                    $nodes[$parentKey]['attributes'][$parentSub] = $this->xmlAttributeValue($content);
                    $progress = true;
                    continue;
                }

                $progress = true;
            }

            $pending = $stillPending;
        } while ($pending !== [] && $progress);

        foreach ($nodes as $nodeKey => &$node) {
            if (($node['typeName'] ?? null) === 'YXmlText') {
                $node['text'] = $this->renderXmlTextItems($node['textItems']);
                $this->xmlTextById[$nodeKey] = (string) $node['text'];
            }
        }
        unset($node);

        $this->xmlNodesById = $nodes;
        $this->xmlRootChildrenByName = $rootChildren;

        foreach ($rootChildren as $rootName => $children) {
            $xml = '';
            foreach ($children as $child) {
                if (! $child['visible']) {
                    continue;
                }
                $childIdKey = is_string($child['value']) ? $child['value'] : null;
                $xml .= $childIdKey !== null && isset($nodes[$childIdKey])
                    ? $this->renderXmlNode($nodes, $childIdKey)
                    : $this->xmlScalarChildValue($child['value']);
            }
            $this->json[$rootName] = $xml;
        }
    }

    private function replaceXmlJsonReferences(): void
    {
        foreach ($this->json as $name => $value) {
            if (! $this->valueContainsXmlReference($value)) {
                continue;
            }

            $this->json[$name] = $this->replaceXmlReferencesInValue($value, false);
            $this->identityJson[$name] = $this->replaceXmlReferencesInValue($value, true);
        }
    }

    private function refreshXmlAttributeSharedTypeValues(): void
    {
        foreach ($this->xmlAttributeIdentityById as $parentIdKey => $attributes) {
            if (! isset($this->xmlNodesById[$parentIdKey])) {
                continue;
            }

            foreach ($attributes as $key => $identity) {
                $nestedIdKey = $this->sharedTypeIdFromIdentityValue($identity);
                if ($nestedIdKey !== null && array_key_exists($nestedIdKey, $this->nestedJsonById)) {
                    $this->xmlNodesById[$parentIdKey]['attributes'][$key] = $this->nestedJsonById[$nestedIdKey];
                    continue;
                }

                $xmlIdKey = $this->xmlTypeIdFromIdentityValue($identity);
                if ($xmlIdKey !== null && isset($this->xmlNodesById[$xmlIdKey])) {
                    $this->xmlNodesById[$parentIdKey]['attributes'][$key] = $this->xmlNodeJsonValue($xmlIdKey);
                }
            }
        }
    }

    private function rerenderXmlRootValues(): void
    {
        foreach ($this->xmlRootChildrenByName as $rootName => $children) {
            $xml = '';

            foreach ($children as $child) {
                if (! $child['visible']) {
                    continue;
                }

                $childIdKey = is_string($child['value']) ? $child['value'] : null;
                $xml .= $childIdKey !== null && isset($this->xmlNodesById[$childIdKey])
                    ? $this->renderXmlNode($this->xmlNodesById, $childIdKey)
                    : $this->xmlScalarChildValue($child['value']);
            }

            $this->json[$rootName] = $xml;
        }
    }

    private function valueContainsXmlReference(mixed $value): bool
    {
        if ($this->xmlTypeIdFromIdentityValue($value) !== null) {
            return true;
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $nested) {
            if ($this->valueContainsXmlReference($nested)) {
                return true;
            }
        }

        return false;
    }

    private function replaceXmlReferencesInValue(mixed $value, bool $identity): mixed
    {
        $nodeKey = $this->xmlTypeIdFromIdentityValue($value);
        if ($nodeKey !== null) {
            return $identity ? $this->nestedIdentityReference($nodeKey) : $this->xmlNodeJsonValue($nodeKey);
        }

        if (! is_array($value)) {
            return $value;
        }

        $replaced = [];
        foreach ($value as $key => $nested) {
            $replaced[$key] = $this->replaceXmlReferencesInValue($nested, $identity);
        }

        return $replaced;
    }

    private function xmlNodeJsonValue(string $nodeKey): mixed
    {
        $node = $this->xmlNodesById[$nodeKey] ?? null;
        if ($node === null) {
            return null;
        }

        if (($node['typeName'] ?? null) === 'YXmlHook') {
            $attributes = $node['attributes'] ?? [];
            ksort($attributes, SORT_STRING);

            return $attributes;
        }

        return $this->renderXmlNode($this->xmlNodesById, $nodeKey);
    }

    /**
     * @param array<string, mixed> $content
     */
    private function xmlAttributeValue(array $content): mixed
    {
        return $this->mapLikeAttributeValue($content);
    }

    /**
     * @param array<string, mixed> $content
     */
    private function mapLikeAttributeValue(array $content): mixed
    {
        if (($content['type'] ?? null) === 'ContentAny' || ($content['type'] ?? null) === 'ContentJSON') {
            return $content['values'][0] ?? null;
        }

        if (($content['type'] ?? null) === 'ContentString') {
            return $content['value'];
        }

        if (($content['type'] ?? null) === 'ContentType') {
            return $this->contentTypeJsonValue($content);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $struct
     * @param array<int, list<array<string, mixed>>> $xmlParentRanges
     * @return array{root: string|null, idKey: string|null}
     */
    private function resolveXmlParent(array $struct, array $xmlParentRanges): array
    {
        if (is_string($struct['parent'])) {
            return [
                'root' => $struct['parent'],
                'idKey' => null,
            ];
        }

        if (is_array($struct['parent'])) {
            return [
                'root' => null,
                'idKey' => self::idKey($struct['parent']),
            ];
        }

        foreach (['origin', 'rightOrigin'] as $relation) {
            if (is_array($struct[$relation])) {
                $parent = $this->findXmlParentForId($struct[$relation], $xmlParentRanges);

                if ($parent !== null) {
                    return $parent;
                }
            }
        }

        return [
            'root' => null,
            'idKey' => null,
        ];
    }

    /**
     * @param array<string, mixed> $struct
     * @param array<int, list<array<string, mixed>>> $xmlParentRanges
     */
    private function resolveXmlParentSub(array $struct, array $xmlParentRanges): ?string
    {
        if ($struct['parentSub'] !== null) {
            return $struct['parentSub'];
        }

        foreach (['origin', 'rightOrigin'] as $relation) {
            if (! is_array($struct[$relation])) {
                continue;
            }

            $parentSub = $this->findXmlParentSubForId($struct[$relation], $xmlParentRanges);
            if ($parentSub !== null) {
                return $parentSub;
            }
        }

        return null;
    }

    /**
     * @param array{client: int, clock: int} $id
     * @param array<int, list<array<string, mixed>>> $xmlParentRanges
     * @return array{root: string|null, idKey: string|null}|null
     */
    private function findXmlParentForId(array $id, array $xmlParentRanges): ?array
    {
        foreach ($xmlParentRanges[$id['client']] ?? [] as $range) {
            if ($id['clock'] >= $range['clock'] && $id['clock'] < $range['end']) {
                return [
                    'root' => $range['parentRoot'] ?? null,
                    'idKey' => $range['parentIdKey'] ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * @param array{client: int, clock: int} $id
     * @param array<int, list<array<string, mixed>>> $xmlParentRanges
     */
    private function findXmlParentSubForId(array $id, array $xmlParentRanges): ?string
    {
        foreach ($xmlParentRanges[$id['client']] ?? [] as $range) {
            if ($id['clock'] >= $range['clock'] && $id['clock'] < $range['end']) {
                return $range['parentSub'] ?? null;
            }
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $nodes
     */
    private function renderXmlNode(array $nodes, string $key): string
    {
        $node = $nodes[$key];

        if (($node['typeName'] ?? null) === 'YXmlText') {
            return (string) $node['text'];
        }

        if (($node['typeName'] ?? null) === 'YXmlHook') {
            return '[object Object]';
        }

        if (($node['typeName'] ?? null) === 'YXmlFragment') {
            return $this->renderXmlChildren($nodes, $node['children']);
        }

        $attributes = $node['attributes'];
        ksort($attributes, SORT_STRING);
        $attributeString = '';
        foreach ($attributes as $name => $value) {
            $attributeString .= ' ' . $name . '="' . $this->xmlAttributeStringValue($key, (string) $name, $value) . '"';
        }

        $nodeName = strtolower((string) ($node['nodeName'] ?? 'UNDEFINED'));

        return '<' . $nodeName . $attributeString . '>' . $this->renderXmlChildren($nodes, $node['children']) . '</' . $nodeName . '>';
    }

    private function xmlAttributeStringValue(string $parentIdKey, string $name, mixed $value): string
    {
        $identity = $this->xmlAttributeIdentityById[$parentIdKey][$name] ?? null;

        $nestedIdKey = $this->sharedTypeIdFromIdentityValue($identity);
        if ($nestedIdKey !== null) {
            return match ($this->nestedSequenceTypeName($nestedIdKey)) {
                'YArray', 'YMap' => '[object Object]',
                default => $this->stringifyXmlAttributeValue($value),
            };
        }

        $xmlIdKey = $this->xmlTypeIdFromIdentityValue($identity);
        if ($xmlIdKey !== null && (($this->xmlNodesById[$xmlIdKey]['typeName'] ?? null) === 'YXmlHook')) {
            return '[object Object]';
        }

        return $this->stringifyXmlAttributeValue($value);
    }

    private function stringifyXmlAttributeValue(mixed $value): string
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                return '[object Object]';
            }

            return implode(',', array_map(fn (mixed $item): string => $this->stringifyXmlAttributeValue($item), $value));
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * @param array<string, array<string, mixed>> $nodes
     * @param list<array<string, mixed>> $children
     */
    private function renderXmlChildren(array $nodes, array $children): string
    {
        $xml = '';
        foreach ($children as $child) {
            if (! $child['visible']) {
                continue;
            }

            $childIdKey = is_string($child['value']) ? $child['value'] : null;
            $xml .= $childIdKey !== null && isset($nodes[$childIdKey])
                ? $this->renderXmlNode($nodes, $childIdKey)
                : $this->xmlScalarChildValue($child['value']);
        }

        return $xml;
    }

    private function xmlScalarChildValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    private function xmlChildValueObject(mixed $value): YXmlElement|YXmlText|YXmlHook|string|null
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && isset($this->xmlNodesById[$value])) {
            return $this->xmlNodeObject($value);
        }

        $rendered = $this->xmlScalarChildValue($value);

        return $rendered === '' && $value !== '' ? null : $rendered;
    }

    /**
     * @param list<array<string, mixed>> $children
     */
    private function visibleXmlChildCount(array $children): int
    {
        return count(array_filter($children, static fn (array $child): bool => $child['visible']));
    }

    /**
     * @param list<array<string, mixed>> $children
     */
    private function visibleXmlChildValueAt(array $children, int $index): mixed
    {
        if ($index < 0) {
            return null;
        }

        $position = 0;
        foreach ($children as $child) {
            if (! $child['visible']) {
                continue;
            }

            if ($position === $index) {
                return $child['value'];
            }

            $position++;
        }

        return null;
    }

    private function xmlNodeSibling(string $idKey, int $direction): YXmlElement|YXmlText|YXmlHook|null
    {
        foreach ($this->xmlRootChildrenByName as $children) {
            $sibling = $this->visibleXmlSiblingInChildren($children, $idKey, $direction);
            if ($sibling !== null) {
                return $this->xmlNodeObject($sibling);
            }
        }

        foreach ($this->xmlNodesById as $node) {
            $sibling = $this->visibleXmlSiblingInChildren($node['children'] ?? [], $idKey, $direction);
            if ($sibling !== null) {
                return $this->xmlNodeObject($sibling);
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $children
     */
    private function visibleXmlSiblingInChildren(array $children, string $idKey, int $direction): ?string
    {
        $visible = [];

        foreach ($children as $child) {
            if ($child['visible']) {
                $visible[] = (string) $child['value'];
            }
        }

        $index = array_search($idKey, $visible, true);
        if ($index === false) {
            return null;
        }

        return $visible[$index + $direction] ?? null;
    }

    private function xmlNodeObject(string $idKey): YNestedXmlFragment|YXmlElement|YXmlText|YXmlHook|null
    {
        $node = $this->xmlNodesById[$idKey] ?? null;
        if ($node === null) {
            return null;
        }

        return match ($node['typeName'] ?? null) {
            'YXmlElement' => new YXmlElement($this, $idKey, (string) ($node['nodeName'] ?? 'UNDEFINED')),
            'YXmlFragment' => new YNestedXmlFragment($this, $idKey),
            'YXmlText' => new YXmlText($this, $idKey, (string) ($node['text'] ?? '')),
            'YXmlHook' => new YXmlHook($this, $idKey, (string) ($node['hookName'] ?? '')),
            default => null,
        };
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function renderXmlTextItems(array $items): string
    {
        $xml = '';

        foreach ($this->textDeltaFromItems($items) as $operation) {
            $value = $this->xmlTextInsertString($operation['insert'] ?? '');
            $attributes = $operation['attributes'] ?? [];

            foreach (array_reverse(array_keys($attributes)) as $key) {
                $attributeString = $this->xmlTextFormatAttributeString($attributes[$key]);
                $value = '<' . $key . $attributeString . '>' . $value . '</' . $key . '>';
            }

            $xml .= $value;
        }

        return $xml;
    }

    private function xmlTextInsertString(mixed $insert): string
    {
        if (is_string($insert)) {
            return $insert;
        }

        if (is_int($insert) || is_float($insert)) {
            return (string) $insert;
        }

        if (is_bool($insert)) {
            return $insert ? 'true' : 'false';
        }

        if ($insert === null) {
            return 'null';
        }

        return '[object Object]';
    }

    private function xmlTextFormatAttributeString(mixed $value): string
    {
        if ($value === true || $value === null) {
            return '';
        }

        if (is_array($value)) {
            $attributes = $value;
        } elseif (is_string($value)) {
            $attributes = [];
            foreach (self::utf8Characters($value) as $index => $character) {
                $attributes[(string) $index] = $character;
            }
        } else {
            return '';
        }

        ksort($attributes, SORT_STRING);
        $attributeString = '';
        foreach ($attributes as $name => $attributeValue) {
            $attributeString .= ' ' . $name . '="' . $attributeValue . '"';
        }

        return $attributeString;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function renderTextItems(array $items): string
    {
        $text = '';
        $units = [];

        foreach ($items as $item) {
            if (! $item['visible'] || $item['mode'] !== 'text') {
                continue;
            }

            if (isset($item['utf16CodeUnit'])) {
                $units[] = $item['utf16CodeUnit'];
                continue;
            }

            if ($units !== []) {
                $text .= self::utf16UnitsToString($units);
                $units = [];
            }

            if (! ($item['embed'] ?? false) && ! is_array($item['value'])) {
                $text .= (string) $item['value'];
            }
        }

        if ($units !== []) {
            $text .= self::utf16UnitsToString($units);
        }

        return $text;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function textDeltaFromItems(array $items): array
    {
        $delta = [];
        $attributes = [];
        $pendingUnits = [];
        $pendingAttributes = [];
        $flushPendingUnits = function () use (&$delta, &$pendingUnits, &$pendingAttributes): void {
            if ($pendingUnits === []) {
                return;
            }

            $this->appendDeltaInsert($delta, self::utf16UnitsToString($pendingUnits), $pendingAttributes);
            $pendingUnits = [];
        };

        foreach ($items as $item) {
            if (($item['formatKey'] ?? null) !== null) {
                if ($item['formatDeleted']) {
                    continue;
                }

                $flushPendingUnits();

                if ($item['formatValue'] === null) {
                    unset($attributes[$item['formatKey']]);
                } else {
                    $attributes[$item['formatKey']] = $item['formatValue'];
                }
                continue;
            }

            if (! $item['visible'] || $item['mode'] !== 'text') {
                continue;
            }

            if (isset($item['utf16CodeUnit'])) {
                $sortedAttributes = $attributes;
                ksort($sortedAttributes, SORT_STRING);

                if ($pendingUnits !== [] && $pendingAttributes !== $sortedAttributes) {
                    $flushPendingUnits();
                }

                $pendingAttributes = $sortedAttributes;
                $pendingUnits[] = $item['utf16CodeUnit'];
                continue;
            }

            $flushPendingUnits();
            $this->appendDeltaInsert($delta, $item['value'], $attributes);
        }

        $flushPendingUnits();

        return $delta;
    }

    /**
     * @param array<string, mixed> $struct
     * @param array<int, list<array{clock: int, length: int}>> $deleteSet
     */
    private function isStructDeletedBySet(array $struct, array $deleteSet): bool
    {
        return $this->deletedOffsets($struct, $deleteSet) !== [];
    }

    private static function isXmlTypeName(string $typeName): bool
    {
        return str_starts_with($typeName, 'YXml');
    }

    /**
     * @param array<string, mixed> $struct
     * @param array<int, list<array{clock: int, length: int}>> $deleteSet
     * @return list<int>
     */
    private function deletedOffsets(array $struct, array $deleteSet): array
    {
        $client = $struct['id']['client'];
        $clock = $struct['id']['clock'];
        $length = $struct['length'];
        $deletedOffsets = [];

        foreach ($deleteSet[$client] ?? [] as $deleteItem) {
            $deleteStart = $deleteItem['clock'];
            $deleteEnd = $deleteStart + $deleteItem['length'];
            $structEnd = $clock + $length;
            $overlapStart = max($clock, $deleteStart);
            $overlapEnd = min($structEnd, $deleteEnd);

            for ($deletedClock = $overlapStart; $deletedClock < $overlapEnd; $deletedClock++) {
                $deletedOffsets[] = $deletedClock - $clock;
            }
        }

        return $deletedOffsets;
    }

    private function isStructDeleted(array $struct): bool
    {
        return $this->deletedOffsets($struct, $this->deleteSet) !== [];
    }

    /**
     * @return array<int, list<array{clock: int, length: int}>>
     */
    private function mapKeyConflictDeleteSet(): array
    {
        $groups = [];

        foreach ($this->sortedStructs() as $struct) {
            if (($struct['type'] ?? null) !== 'Item' || $this->isStructDeleted($struct)) {
                continue;
            }

            $groupKey = $this->mapLikeConflictGroupKey($struct);
            if ($groupKey === null) {
                continue;
            }

            $groups[$groupKey][] = $struct;
        }

        $deleteSet = [];

        foreach ($groups as $structs) {
            if (count($structs) <= 1) {
                continue;
            }

            array_pop($structs);

            foreach ($structs as $struct) {
                $deleteSet[$struct['id']['client']][] = [
                    'clock' => $struct['id']['clock'],
                    'length' => $struct['length'],
                ];
            }
        }

        return UpdateUtils::mergeDeleteSets([$deleteSet]);
    }

    /**
     * @param array<string, mixed> $struct
     */
    private function mapLikeConflictGroupKey(array $struct): ?string
    {
        $parentSub = $this->storedStructParentSub($struct);
        if ($parentSub === null) {
            return null;
        }

        $parentIdKey = $this->storedStructParentIdKey($struct);
        if ($parentIdKey !== null) {
            $typeName = $this->contentTypeNameForIdKey($parentIdKey);
            if (! in_array($typeName, ['YMap', 'YText', 'YXmlElement', 'YXmlHook', 'YXmlText'], true)) {
                return null;
            }

            return 'id:' . $parentIdKey . ':' . $parentSub;
        }

        $parent = $this->storedStructParent($struct);
        if ($parent === null) {
            return null;
        }

        return 'root:' . $parent . ':' . $parentSub;
    }

    private function contentTypeNameForIdKey(string $idKey): ?string
    {
        $struct = $this->structsById[$idKey] ?? null;
        if (($struct['type'] ?? null) !== 'Item' || ($struct['content']['type'] ?? null) !== 'ContentType') {
            return null;
        }

        $typeName = $struct['content']['typeName'] ?? null;

        return is_string($typeName) ? $typeName : null;
    }

    /**
     * @return array<int, list<array{clock: int, length: int}>>
     */
    private function deletedParentCascadeDeleteSet(): array
    {
        $deleteSet = [];

        foreach ($this->sortedStructs() as $struct) {
            if (($struct['type'] ?? null) !== 'Item' || $this->isStructDeleted($struct)) {
                continue;
            }

            if (! $this->hasDeletedSharedTypeAncestor($struct)) {
                continue;
            }

            $deleteSet[$struct['id']['client']][] = [
                'clock' => $struct['id']['clock'],
                'length' => $struct['length'],
            ];
        }

        return UpdateUtils::mergeDeleteSets([$deleteSet]);
    }

    /**
     * @param array<string, mixed> $struct
     * @param array<string, true> $seen
     */
    private function hasDeletedSharedTypeAncestor(array $struct, array $seen = []): bool
    {
        $parentIdKey = $this->storedStructParentIdKey($struct);
        if ($parentIdKey === null || isset($seen[$parentIdKey])) {
            return false;
        }

        $parentStruct = $this->structsById[$parentIdKey] ?? null;
        if ($parentStruct === null || ($parentStruct['content']['type'] ?? null) !== 'ContentType') {
            return false;
        }

        if ($this->isStructDeleted($parentStruct)) {
            return true;
        }

        return $this->hasDeletedSharedTypeAncestor($parentStruct, $seen + [$parentIdKey => true]);
    }

    private function deleteSequenceRange(string $name, int $index, int $length, ?string $mode = null): void
    {
        if ($length <= 0) {
            return;
        }

        $mode ??= is_array($this->json[$name] ?? null) ? 'array' : 'text';
        $rangeStart = $index;
        $rangeEnd = $index + $length;
        $position = 0;
        $observedDeleteSet = [];
        $before = $this->json;
        $beforeIdentity = $this->identityJson;
        $beforeNested = $this->nestedJsonById;
        $beforeNestedIdentity = $this->nestedIdentityById;
        $beforeTextDeltas = $this->observedTextDeltas();
        $beforeTextAttributes = $this->rootTextAttributeValues();
        $beforeNestedTextAttributes = $this->nestedTextAttributeValues();
        $beforeNestedTextDeltas = $this->observedNestedTextDeltas();

        foreach ($this->collectSequenceItemsForParent($name) as $item) {
            if ($item['mode'] !== $mode || ! $item['countsPosition']) {
                continue;
            }

            if ($position >= $rangeStart && $position < $rangeEnd) {
                $deleteItem = [
                    'clock' => $item['id']['clock'],
                    'length' => 1,
                ];
                $this->deleteSet[$item['id']['client']][] = $deleteItem;
                $observedDeleteSet[$item['id']['client']][] = $deleteItem;
            }

            $position++;
        }

        if ($observedDeleteSet !== []) {
            $this->rematerialize();
            $this->emitLocalChange($before, $beforeIdentity, $beforeNested, $beforeNestedIdentity, [], $observedDeleteSet, $beforeTextDeltas, $beforeTextAttributes, $beforeNestedTextAttributes, $beforeNestedTextDeltas);
        }
    }

    private function deleteSequenceRangeForParentId(string $idKey, int $index, int $length, string $mode): void
    {
        if ($length <= 0) {
            return;
        }

        $rangeStart = $index;
        $rangeEnd = $index + $length;
        $position = 0;
        $observedDeleteSet = [];
        $before = $this->json;
        $beforeIdentity = $this->identityJson;
        $beforeNested = $this->nestedJsonById;
        $beforeNestedIdentity = $this->nestedIdentityById;
        $beforeTextDeltas = $this->observedTextDeltas();
        $beforeTextAttributes = $this->rootTextAttributeValues();
        $beforeNestedTextAttributes = $this->nestedTextAttributeValues();
        $beforeNestedTextDeltas = $this->observedNestedTextDeltas();

        foreach ($this->collectSequenceItemsForParentId(self::idFromKey($idKey)) as $item) {
            if ($item['mode'] !== $mode || ! $item['countsPosition']) {
                continue;
            }

            if ($position >= $rangeStart && $position < $rangeEnd) {
                $deleteItem = [
                    'clock' => $item['id']['clock'],
                    'length' => 1,
                ];
                $this->deleteSet[$item['id']['client']][] = $deleteItem;
                $observedDeleteSet[$item['id']['client']][] = $deleteItem;
            }

            $position++;
        }

        if ($observedDeleteSet !== []) {
            $this->rematerialize();
            $this->emitLocalChange($before, $beforeIdentity, $beforeNested, $beforeNestedIdentity, [], $observedDeleteSet, $beforeTextDeltas, $beforeTextAttributes, $beforeNestedTextAttributes, $beforeNestedTextDeltas);
        }
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $beforeIdentity
     * @param array<string, mixed> $beforeNested
     * @param array<string, mixed> $beforeNestedIdentity
     * @param list<array<string, mixed>> $structs
     * @param array<int, list<array{clock: int, length: int}>> $deleteSet
     * @param array<string, list<array<string, mixed>>> $beforeTextDeltas
     * @param array<string, array<string, mixed>> $beforeTextAttributes
     * @param array<string, array<string, mixed>> $beforeNestedTextAttributes
     * @param array<string, list<array<string, mixed>>> $beforeNestedTextDeltas
     */
    private function emitLocalChange(array $before, array $beforeIdentity, array $beforeNested, array $beforeNestedIdentity, array $structs, array $deleteSet, array $beforeTextDeltas = [], array $beforeTextAttributes = [], array $beforeNestedTextAttributes = [], array $beforeNestedTextDeltas = []): void
    {
        if ($structs === [] && $deleteSet === []) {
            return;
        }

        if ($this->activeTransaction !== null) {
            foreach ($structs as $struct) {
                $this->activeTransaction['structs'][] = $struct;
            }

            foreach ($deleteSet as $client => $deletes) {
                foreach ($deletes as $delete) {
                    $this->activeTransaction['deleteSet'][$client][] = $delete;
                }
            }

            return;
        }

        $update = DecodedUpdate::encodeV1($structs, $deleteSet);
        $updateV2 = DecodedUpdate::encodeV2($structs, $deleteSet);
        $origin = $this->currentTransactionOrigin();
        $changedNames = $this->topLevelNamesForChange($structs, $deleteSet);
        $directChangedNames = $this->directTopLevelNamesForChange($structs, $deleteSet);
        $changedNestedTypeIds = $this->nestedTypeIdsForChange($structs, $deleteSet);
        $directChangedNestedTypeIds = $this->directNestedTypeIdsForChange($structs, $deleteSet);
        $changedXmlNodeIds = $this->xmlNodeIdsForChange($structs, $deleteSet);
        $directChangedXmlNodeIds = $this->directXmlNodeIdsForChange($structs, $deleteSet);
        $changedXmlAttributes = $this->xmlAttributeNamesForChange($structs, $deleteSet);
        $directTransactionChangedNestedTypeIds = $this->filterNewlyCreatedSharedTypeIds($directChangedNestedTypeIds, $structs);
        $directTransactionChangedXmlNodeIds = $this->filterNewlyCreatedSharedTypeIds($directChangedXmlNodeIds, $structs);
        $changedNames += $this->changedRootNamesByValue($before, $beforeIdentity);
        $this->notifySharedTypeObservers($before, $beforeIdentity, $update, $updateV2, $origin, $changedNames, $beforeTextDeltas, $beforeTextAttributes, []);
        $this->notifyNestedTypeObservers($beforeNested, $beforeNestedIdentity, $beforeNestedTextAttributes, $beforeNestedTextDeltas, $update, $updateV2, $origin, $changedNestedTypeIds);
        $this->notifyXmlNodeObservers($update, $updateV2, $origin, $changedXmlNodeIds, [], [], [], [], $changedXmlAttributes);
        $transactionEvent = [
            'origin' => $origin,
            'local' => true,
            'update' => $update,
            'updateV2' => $updateV2,
            'beforeStateVector' => $this->stateVectorBeforeLocalStructs($structs),
            'afterStateVector' => $this->getStateVector(),
            'before' => $before,
            'after' => $this->json,
            'beforeTextAttributes' => $beforeTextAttributes,
            'afterTextAttributes' => $this->rootTextAttributeValues(),
            'beforeNested' => $beforeNested,
            'afterNested' => $this->nestedJsonById,
            'beforeNestedTextAttributes' => $beforeNestedTextAttributes,
            'afterNestedTextAttributes' => $this->nestedTextAttributeValues(),
            'changed' => array_values(array_keys($changedNames)),
            'changedNestedTypes' => array_values(array_keys($changedNestedTypeIds)),
            'changedXmlNodes' => array_values(array_keys($changedXmlNodeIds)),
            'changedTypeNames' => $this->transactionChangedTypeNames($directChangedNames, $directTransactionChangedNestedTypeIds, $directTransactionChangedXmlNodeIds),
            'changedParentTypeNames' => $this->transactionChangedParentTypeNames($directChangedNames, $directTransactionChangedNestedTypeIds, $directTransactionChangedXmlNodeIds),
            'deleteSet' => UpdateUtils::mergeDeleteSets([$deleteSet]),
        ];
        $this->notifyDeepObservers($this->deepEvents($before, $beforeIdentity, $beforeNested, $beforeNestedIdentity, $beforeNestedTextAttributes, $beforeNestedTextDeltas, $beforeTextDeltas, $beforeTextAttributes, [], [], [], [], [], $changedXmlAttributes, $update, $updateV2, $origin, $directChangedNames, $changedNestedTypeIds, $changedXmlNodeIds), $transactionEvent);
        $this->notifyTransactionObservers($transactionEvent);
        $this->notifyUpdateObservers($update, $origin, $transactionEvent);
        $this->notifyUpdateV2Observers($updateV2, $origin, $transactionEvent);
        $this->notifySubdocObservers($this->subdocChanges($structs, $deleteSet), $transactionEvent);
    }

    /**
     * @param array{
     *     before: array<string, mixed>,
     *     beforeIdentity: array<string, mixed>,
     *     beforeNested: array<string, mixed>,
     *     beforeNestedIdentity: array<string, mixed>,
     *     beforeNestedTextAttributes: array<string, array<string, mixed>>,
     *     beforeNestedTextDeltas: array<string, list<array<string, mixed>>>,
     *     beforeTextDeltas: array<string, list<array<string, mixed>>>,
     *     beforeTextAttributes: array<string, array<string, mixed>>,
     *     beforeXmlTextDeltas: array<string, list<array<string, mixed>>>,
     *     beforeXmlTextValues: array<string, string>,
     *     beforeXmlElementAttributeValues: array<string, array<string, mixed>>,
     *     beforeXmlRootChildren: array<string, list<string>>,
     *     beforeXmlElementChildren: array<string, list<string>>,
     *     beforeXmlElementAttributes: array<string, array<string, mixed>>,
     *     beforeXmlElementAttributeOldValues: array<string, array<string, mixed>>,
     *     beforeStateVector: array<int, int>,
     *     structs: list<array<string, mixed>>,
     *     deleteSet: array<int, list<array{clock: int, length: int}>>,
     *     origin: mixed
     * }|null $transaction
     */
    private function flushTransaction(?array $transaction): ?array
    {
        if ($transaction === null || ($transaction['structs'] === [] && $transaction['deleteSet'] === [] && ($transaction['loadedSubdocs'] ?? []) === [])) {
            return null;
        }

        $wasFlushing = $this->isFlushingTransaction;
        $this->isFlushingTransaction = true;
        $transactionEvent = null;

        try {
            $hasContent = $transaction['structs'] !== [] || $transaction['deleteSet'] !== [];
            $update = $hasContent ? DecodedUpdate::encodeV1($transaction['structs'], $transaction['deleteSet']) : null;
            $updateV2 = $hasContent ? DecodedUpdate::encodeV2($transaction['structs'], $transaction['deleteSet']) : null;
            $changedNames = $this->topLevelNamesForChange($transaction['structs'], $transaction['deleteSet']);
            $directChangedNames = $this->directTopLevelNamesForChange($transaction['structs'], $transaction['deleteSet']);
            $changedNestedTypeIds = $this->nestedTypeIdsForChange($transaction['structs'], $transaction['deleteSet']);
            $directChangedNestedTypeIds = $this->directNestedTypeIdsForChange($transaction['structs'], $transaction['deleteSet']);
            $changedXmlNodeIds = $this->xmlNodeIdsForChange($transaction['structs'], $transaction['deleteSet']);
            $directChangedXmlNodeIds = $this->directXmlNodeIdsForChange($transaction['structs'], $transaction['deleteSet']);
            $changedXmlAttributes = $this->xmlAttributeNamesForChange($transaction['structs'], $transaction['deleteSet']);
            $directTransactionChangedNestedTypeIds = $this->filterNewlyCreatedSharedTypeIds($directChangedNestedTypeIds, $transaction['structs']);
            $directTransactionChangedXmlNodeIds = $this->filterNewlyCreatedSharedTypeIds($directChangedXmlNodeIds, $transaction['structs']);
            $changedNames += $this->changedRootNamesByValue($transaction['before'], $transaction['beforeIdentity']);
            $transactionEvent = [
                'origin' => $transaction['origin'],
                'local' => true,
                'update' => $update,
                'updateV2' => $updateV2,
                'beforeStateVector' => $transaction['beforeStateVector'],
                'afterStateVector' => $this->getStateVector(),
                'before' => $transaction['before'],
                'after' => $this->json,
                'beforeTextAttributes' => $transaction['beforeTextAttributes'],
                'afterTextAttributes' => $this->rootTextAttributeValues(),
                'beforeNested' => $transaction['beforeNested'],
                'afterNested' => $this->nestedJsonById,
                'beforeNestedTextAttributes' => $transaction['beforeNestedTextAttributes'],
                'afterNestedTextAttributes' => $this->nestedTextAttributeValues(),
                'beforeXmlText' => $transaction['beforeXmlTextValues'],
                'afterXmlText' => $this->xmlTextValues(),
                'beforeXmlAttributes' => $transaction['beforeXmlElementAttributeValues'],
                'afterXmlAttributes' => $this->xmlElementAttributeValues(),
                'beforeXmlRootChildren' => $transaction['beforeXmlRootChildrenAll'],
                'afterXmlRootChildren' => $this->xmlRootChildIdValues(),
                'beforeXmlElementChildren' => $transaction['beforeXmlElementChildrenAll'],
                'afterXmlElementChildren' => $this->xmlElementChildIdValues(),
                'beforeXmlRootSnapshots' => $transaction['beforeXmlRootSnapshots'],
                'afterXmlRootSnapshots' => $this->xmlRootChildrenSnapshots(),
                'beforeXmlElementSnapshots' => $transaction['beforeXmlElementSnapshots'],
                'afterXmlElementSnapshots' => $this->xmlElementChildrenSnapshots(),
                'changed' => array_values(array_keys($changedNames)),
                'changedNestedTypes' => array_values(array_keys($changedNestedTypeIds)),
                'changedXmlNodes' => array_values(array_keys($changedXmlNodeIds)),
                'changedTypeNames' => $this->transactionChangedTypeNames($directChangedNames, $directTransactionChangedNestedTypeIds, $directTransactionChangedXmlNodeIds),
                'changedParentTypeNames' => $this->transactionChangedParentTypeNames($directChangedNames, $directTransactionChangedNestedTypeIds, $directTransactionChangedXmlNodeIds),
                'deleteSet' => UpdateUtils::mergeDeleteSets([$transaction['deleteSet']]),
            ];
            $this->notifyEventObservers('beforeObserverCalls', [$transactionEvent, $this]);
            $this->notifySharedTypeObservers(
                $transaction['before'],
                $transaction['beforeIdentity'],
                $update,
                $updateV2,
                $transaction['origin'],
                $changedNames,
                $transaction['beforeTextDeltas'],
                $transaction['beforeTextAttributes'],
                $transaction['beforeXmlRootChildren']
            );
            $this->notifyNestedTypeObservers(
                $transaction['beforeNested'],
                $transaction['beforeNestedIdentity'],
                $transaction['beforeNestedTextAttributes'],
                $transaction['beforeNestedTextDeltas'],
                $update,
                $updateV2,
                $transaction['origin'],
                $changedNestedTypeIds
            );
            $this->notifyXmlNodeObservers(
                $update,
                $updateV2,
                $transaction['origin'],
                $changedXmlNodeIds,
                $transaction['beforeXmlTextDeltas'],
                $transaction['beforeXmlElementChildren'],
                $transaction['beforeXmlElementAttributes'],
                $transaction['beforeXmlElementAttributeOldValues'],
                $changedXmlAttributes
            );
            $this->notifyDeepObservers($this->deepEvents($transaction['before'], $transaction['beforeIdentity'], $transaction['beforeNested'], $transaction['beforeNestedIdentity'], $transaction['beforeNestedTextAttributes'], $transaction['beforeNestedTextDeltas'], $transaction['beforeTextDeltas'], $transaction['beforeTextAttributes'], $transaction['beforeXmlTextDeltas'], $transaction['beforeXmlRootChildren'], $transaction['beforeXmlElementChildren'], $transaction['beforeXmlElementAttributes'], $transaction['beforeXmlElementAttributeOldValues'], $changedXmlAttributes, $update, $updateV2, $transaction['origin'], $directChangedNames, $changedNestedTypeIds, $changedXmlNodeIds), $transactionEvent);
            $this->notifyTransactionObservers($transactionEvent);
            if ($update !== null) {
                $this->notifyUpdateObservers($update, $transaction['origin'], $transactionEvent);
            }
            if ($updateV2 !== null) {
                $this->notifyUpdateV2Observers($updateV2, $transaction['origin'], $transactionEvent);
            }
            $this->notifySubdocObservers($this->subdocChanges($transaction['structs'], $transaction['deleteSet'], $transaction['loadedSubdocs'] ?? []), $transactionEvent);
        } finally {
            $this->isFlushingTransaction = $wasFlushing;
        }

        return $transactionEvent;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function flushPendingTransactions(): array
    {
        $flushedTransactions = [];

        while ($this->pendingTransactions !== []) {
            $transaction = array_shift($this->pendingTransactions);
            $transactionEvent = $this->flushTransaction($transaction);
            if ($transactionEvent !== null) {
                $flushedTransactions[] = $transactionEvent;
            }
        }

        return $flushedTransactions;
    }

    private function notifyUpdateObservers(string $update, mixed $origin, ?array $transactionEvent = null): void
    {
        $this->dispatchDynamicObservers($this->updateObservers, function (callable $observer) use ($update, $origin): void {
            $observer($update, $this, $origin);
        });
        $this->notifyEventObservers('update', [$update, $origin, $this, $transactionEvent]);
    }

    private function notifyUpdateV2Observers(string $update, mixed $origin, ?array $transactionEvent = null): void
    {
        $this->dispatchDynamicObservers($this->updateV2Observers, function (callable $observer) use ($update, $origin): void {
            $observer($update, $this, $origin);
        });
        $this->notifyEventObservers('updateV2', [$update, $origin, $this, $transactionEvent]);
    }

    /**
     * @param array<string, mixed> $event
     */
    private function notifyTransactionObservers(array $event): void
    {
        $this->dispatchDynamicObservers($this->transactionObservers, function (callable $observer) use ($event): void {
            $observer($event, $this);
        });
        $this->notifyEventObservers('afterTransaction', [$event, $this]);
        $this->notifyEventObservers('transaction', [$event, $this]);
        $this->notifyEventObservers('afterTransactionCleanup', [$event, $this]);
    }

    /**
     * @param array{loaded: list<YSubdoc>, added: list<YSubdoc>, removed: list<YSubdoc>} $changes
     * @param array<string, mixed> $transactionEvent
     */
    private function notifySubdocObservers(array $changes, array $transactionEvent): void
    {
        if ($changes['loaded'] === [] && $changes['added'] === [] && $changes['removed'] === []) {
            return;
        }

        foreach ($changes['removed'] as $subdoc) {
            unset($this->subdocsByGuid[$subdoc->guid()]);
        }

        foreach ($changes['added'] as $subdoc) {
            $this->subdocsByGuid[$subdoc->guid()] = $subdoc;
        }

        $this->notifyEventObservers('subdocs', [$changes, $this, $transactionEvent]);

        if ($changes['removed'] === []) {
            return;
        }

        $destroyTransactionEvent = $transactionEvent;
        $destroyTransactionEvent['origin'] = null;
        $destroyTransactionEvent['local'] = true;
        $destroyTransactionEvent['update'] = null;
        $destroyTransactionEvent['updateV2'] = null;
        $destroyTransactionEvent['changed'] = [];
        $destroyTransactionEvent['changedNestedTypes'] = [];
        $destroyTransactionEvent['changedXmlNodes'] = [];
        $destroyTransactionEvent['changedTypeNames'] = [];
        $destroyTransactionEvent['changedParentTypeNames'] = [];
        $destroyTransactionEvent['deleteSet'] = [];
        $destroyChanges = [
            'loaded' => [],
            'added' => [],
            'removed' => $changes['removed'],
        ];

        $this->notifyEventObservers('beforeObserverCalls', [$destroyTransactionEvent, $this]);
        $this->notifyTransactionObservers($destroyTransactionEvent);
        $this->notifyEventObservers('subdocs', [$destroyChanges, $this, $destroyTransactionEvent]);
    }

    /**
     * @param list<array<string, mixed>> $events
     * @param array<string, mixed> $transactionEvent
     */
    private function notifyDeepObservers(array $events, array $transactionEvent): void
    {
        if ($events === []) {
            return;
        }

        $this->dispatchDynamicObservers($this->deepObservers, function (callable $observer) use ($events, $transactionEvent): void {
            $observer($events, $this, $transactionEvent);
        });
    }

    /**
     * @param array<int, mixed> $observers
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
     * @param list<mixed> $arguments
     */
    private function notifyEventObservers(string $eventName, array $arguments): void
    {
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

                $entry['observer'](...$arguments);
                $dispatchedInPass = true;
            }
        } while ($dispatchedInPass);
    }

    /**
     * @param array<string, mixed> $transaction
     * @return array<string, mixed>
     */
    private function startedTransactionEvent(array $transaction): array
    {
        return [
            'origin' => $transaction['origin'],
            'local' => true,
            'update' => null,
            'updateV2' => null,
            'beforeStateVector' => $transaction['beforeStateVector'],
            'afterStateVector' => $transaction['beforeStateVector'],
            'before' => $transaction['before'],
            'after' => $transaction['before'],
            'beforeTextAttributes' => $transaction['beforeTextAttributes'],
            'afterTextAttributes' => $transaction['beforeTextAttributes'],
            'beforeNested' => $transaction['beforeNested'],
            'afterNested' => $transaction['beforeNested'],
            'beforeNestedTextAttributes' => $transaction['beforeNestedTextAttributes'],
            'afterNestedTextAttributes' => $transaction['beforeNestedTextAttributes'],
            'beforeXmlText' => $transaction['beforeXmlTextValues'],
            'afterXmlText' => $transaction['beforeXmlTextValues'],
            'beforeXmlAttributes' => $transaction['beforeXmlElementAttributeValues'],
            'afterXmlAttributes' => $transaction['beforeXmlElementAttributeValues'],
            'beforeXmlRootChildren' => $transaction['beforeXmlRootChildrenAll'],
            'afterXmlRootChildren' => $transaction['beforeXmlRootChildrenAll'],
            'beforeXmlElementChildren' => $transaction['beforeXmlElementChildrenAll'],
            'afterXmlElementChildren' => $transaction['beforeXmlElementChildrenAll'],
            'beforeXmlRootSnapshots' => $transaction['beforeXmlRootSnapshots'],
            'afterXmlRootSnapshots' => $transaction['beforeXmlRootSnapshots'],
            'beforeXmlElementSnapshots' => $transaction['beforeXmlElementSnapshots'],
            'afterXmlElementSnapshots' => $transaction['beforeXmlElementSnapshots'],
            'changed' => [],
            'changedNestedTypes' => [],
            'changedXmlNodes' => [],
            'changedTypeNames' => [],
            'changedParentTypeNames' => [],
            'deleteSet' => [],
        ];
    }

    /**
     * @param array<int, int> $beforeStateVector
     * @param array<string, mixed> $before
     * @param array<string, array<string, mixed>> $beforeTextAttributes
     * @param array<string, mixed> $beforeNested
     * @param array<string, array<string, mixed>> $beforeNestedTextAttributes
     * @param array<string, string> $beforeXmlTextValues
     * @param array<string, array<string, mixed>> $beforeXmlElementAttributeValues
     * @param array<string, list<string>> $beforeXmlRootChildrenAll
     * @param array<string, list<string>> $beforeXmlElementChildrenAll
     * @param array<string, list<array<string, mixed>>> $beforeXmlRootSnapshots
     * @param array<string, list<array<string, mixed>>> $beforeXmlElementSnapshots
     * @return array<string, mixed>
     */
    private function startedRemoteTransactionEvent(
        mixed $origin,
        array $beforeStateVector,
        array $before,
        array $beforeTextAttributes,
        array $beforeNested,
        array $beforeNestedTextAttributes,
        array $beforeXmlTextValues,
        array $beforeXmlElementAttributeValues,
        array $beforeXmlRootChildrenAll,
        array $beforeXmlElementChildrenAll,
        array $beforeXmlRootSnapshots,
        array $beforeXmlElementSnapshots
    ): array {
        return [
            'origin' => $origin,
            'local' => false,
            'update' => null,
            'updateV2' => null,
            'beforeStateVector' => $beforeStateVector,
            'afterStateVector' => $beforeStateVector,
            'before' => $before,
            'after' => $before,
            'beforeTextAttributes' => $beforeTextAttributes,
            'afterTextAttributes' => $beforeTextAttributes,
            'beforeNested' => $beforeNested,
            'afterNested' => $beforeNested,
            'beforeNestedTextAttributes' => $beforeNestedTextAttributes,
            'afterNestedTextAttributes' => $beforeNestedTextAttributes,
            'beforeXmlText' => $beforeXmlTextValues,
            'afterXmlText' => $beforeXmlTextValues,
            'beforeXmlAttributes' => $beforeXmlElementAttributeValues,
            'afterXmlAttributes' => $beforeXmlElementAttributeValues,
            'beforeXmlRootChildren' => $beforeXmlRootChildrenAll,
            'afterXmlRootChildren' => $beforeXmlRootChildrenAll,
            'beforeXmlElementChildren' => $beforeXmlElementChildrenAll,
            'afterXmlElementChildren' => $beforeXmlElementChildrenAll,
            'beforeXmlRootSnapshots' => $beforeXmlRootSnapshots,
            'afterXmlRootSnapshots' => $beforeXmlRootSnapshots,
            'beforeXmlElementSnapshots' => $beforeXmlElementSnapshots,
            'afterXmlElementSnapshots' => $beforeXmlElementSnapshots,
            'changed' => [],
            'changedNestedTypes' => [],
            'changedXmlNodes' => [],
            'changedTypeNames' => [],
            'changedParentTypeNames' => [],
            'deleteSet' => [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $structs
     * @return array<int, int>
     */
    private function stateVectorBeforeLocalStructs(array $structs): array
    {
        $stateVector = $this->getStateVector();

        foreach ($structs as $struct) {
            $client = $struct['id']['client'];
            $clock = $struct['id']['clock'];

            if (! isset($stateVector[$client]) || $clock < $stateVector[$client]) {
                $stateVector[$client] = $clock;
            }
        }

        foreach ($stateVector as $client => $clock) {
            if ($clock <= 0) {
                unset($stateVector[$client]);
            }
        }

        krsort($stateVector, SORT_NUMERIC);

        return $stateVector;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, true> $changedNames
     * @param array<string, list<array<string, mixed>>> $beforeTextDeltas
     * @param array<string, array<string, mixed>> $beforeTextAttributes
     * @param array<string, list<string>> $beforeXmlRootChildren
     */
    private function notifySharedTypeObservers(array $before, array $beforeIdentity, ?string $update, ?string $updateV2, mixed $origin, array $changedNames = [], array $beforeTextDeltas = [], array $beforeTextAttributes = [], array $beforeXmlRootChildren = []): void
    {
        if ($this->sharedTypeObservers === []) {
            return;
        }

        $this->dispatchDynamicObservers($this->sharedTypeObservers, function (array $registration) use ($before, $beforeIdentity, $update, $updateV2, $origin, $changedNames, $beforeTextDeltas, $beforeTextAttributes, $beforeXmlRootChildren): void {
            $name = $registration['name'];
            $type = $registration['type'];
            $hadOldValue = array_key_exists($name, $before);
            $hasNewValue = array_key_exists($name, $this->json);
            $oldValue = $hadOldValue ? $before[$name] : null;
            $newValue = $hasNewValue ? $this->json[$name] : null;

            if ($hadOldValue === $hasNewValue && $oldValue === $newValue && ! isset($changedNames[$name])) {
                return;
            }

            $registration['observer']([
                'name' => $name,
                'oldValue' => $oldValue,
                'newValue' => $newValue,
                'oldExists' => $hadOldValue,
                'newExists' => $hasNewValue,
                'path' => [],
                'changes' => $this->sharedTypeChanges(
                    $name,
                    $type,
                    $hadOldValue,
                    $oldValue,
                    $hadOldValue && array_key_exists($name, $beforeIdentity) ? $beforeIdentity[$name] : $oldValue,
                    $hasNewValue,
                    $newValue,
                    $hasNewValue && array_key_exists($name, $this->identityJson) ? $this->identityJson[$name] : $newValue,
                    $beforeTextDeltas,
                    $beforeTextAttributes,
                    $beforeXmlRootChildren
                ),
                'update' => $update,
                'updateV2' => $updateV2,
                'origin' => $origin,
            ], $this);
        });
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $beforeNested
     * @param array<string, array<string, mixed>> $beforeNestedTextAttributes
     * @param array<string, list<array<string, mixed>>> $beforeNestedTextDeltas
     * @param array<string, list<array<string, mixed>>> $beforeTextDeltas
     * @param array<string, array<string, mixed>> $beforeTextAttributes
     * @param array<string, list<array<string, mixed>>> $beforeXmlTextDeltas
     * @param array<string, list<string>> $beforeXmlRootChildren
     * @param array<string, list<string>> $beforeXmlElementChildren
     * @param array<string, array<string, mixed>> $beforeXmlElementAttributes
     * @param array<string, array<string, mixed>> $beforeXmlElementAttributeOldValues
     * @param array<string, array<string, true>> $changedXmlAttributes
     * @param array<string, true> $changedNames
     * @param array<string, true> $changedNestedIds
     * @param array<string, true> $changedXmlIds
     * @return list<array<string, mixed>>
     */
    private function deepEvents(
        array $before,
        array $beforeIdentity,
        array $beforeNested,
        array $beforeNestedIdentity,
        array $beforeNestedTextAttributes,
        array $beforeNestedTextDeltas,
        array $beforeTextDeltas,
        array $beforeTextAttributes,
        array $beforeXmlTextDeltas,
        array $beforeXmlRootChildren,
        array $beforeXmlElementChildren,
        array $beforeXmlElementAttributes,
        array $beforeXmlElementAttributeOldValues,
        array $changedXmlAttributes,
        ?string $update,
        ?string $updateV2,
        mixed $origin,
        array $changedNames,
        array $changedNestedIds,
        array $changedXmlIds
    ): array {
        if ($this->deepObservers === []) {
            return [];
        }

        $events = [];

        foreach (array_keys($changedNames) as $name) {
            $hadOldValue = array_key_exists($name, $before);
            $hasNewValue = array_key_exists($name, $this->json);
            $oldValue = $hadOldValue ? $before[$name] : null;
            $newValue = $hasNewValue ? $this->json[$name] : null;
            $type = $this->rootSharedTypeKind($name, $oldValue, $newValue);

            $events[] = [
                'target' => 'root',
                'name' => $name,
                'type' => $type,
                'oldValue' => $oldValue,
                'newValue' => $newValue,
                'oldExists' => $hadOldValue,
                'newExists' => $hasNewValue,
                'path' => [],
                'changes' => $this->sharedTypeChanges(
                    $name,
                    $type,
                    $hadOldValue,
                    $oldValue,
                    $hadOldValue && array_key_exists($name, $beforeIdentity) ? $beforeIdentity[$name] : $oldValue,
                    $hasNewValue,
                    $newValue,
                    $hasNewValue && array_key_exists($name, $this->identityJson) ? $this->identityJson[$name] : $newValue,
                    $beforeTextDeltas,
                    $beforeTextAttributes,
                    $beforeXmlRootChildren
                ),
                'update' => $update,
                'updateV2' => $updateV2,
                'origin' => $origin,
            ];
        }

        foreach (array_keys($changedNestedIds) as $idKey) {
            $hadOldValue = array_key_exists($idKey, $beforeNested);
            $hasNewValue = array_key_exists($idKey, $this->nestedJsonById);
            $oldValue = $hadOldValue ? $beforeNested[$idKey] : null;
            $newValue = $hasNewValue ? $this->nestedJsonById[$idKey] : null;
            $type = self::sharedTypeKindFromYjsName($this->nestedSequenceTypeName($idKey));
            $changes = $this->sharedTypeChanges(
                $idKey,
                $type,
                $hadOldValue,
                $oldValue,
                $hadOldValue && array_key_exists($idKey, $beforeNestedIdentity) ? $beforeNestedIdentity[$idKey] : $oldValue,
                $hasNewValue,
                $newValue,
                $hasNewValue && array_key_exists($idKey, $this->nestedIdentityById) ? $this->nestedIdentityById[$idKey] : $newValue,
                $beforeNestedTextDeltas,
                $beforeNestedTextAttributes,
                [],
                $this->nestedTextAttributes($idKey),
                $type === 'text' && isset($beforeNestedTextDeltas[$idKey]) ? $this->nestedTextDelta($idKey) : null
            );

            if (self::sharedTypeChangesAreEmpty($changes)) {
                continue;
            }

            $events[] = [
                'target' => 'nested',
                'idKey' => $idKey,
                'type' => $type,
                'oldValue' => $oldValue,
                'newValue' => $newValue,
                'oldExists' => $hadOldValue,
                'newExists' => $hasNewValue,
                'path' => [],
                'changes' => $changes,
                'update' => $update,
                'updateV2' => $updateV2,
                'origin' => $origin,
            ];
        }

        foreach (array_keys($changedXmlIds) as $idKey) {
            $node = $this->xmlNodesById[$idKey] ?? null;
            $exists = $node !== null && $this->xmlNodeIsVisible($idKey);
            $changes = $this->xmlNodeChanges($idKey, $beforeXmlTextDeltas, $beforeXmlElementChildren, $beforeXmlElementAttributes, $beforeXmlElementAttributeOldValues, $changedXmlAttributes);

            if (self::xmlNodeChangesAreEmpty($changes)) {
                continue;
            }

            $events[] = [
                'target' => 'xml',
                'idKey' => $idKey,
                'exists' => $exists,
                'typeName' => $node['typeName'] ?? null,
                'value' => $exists ? $this->renderXmlNode($this->xmlNodesById, $idKey) : null,
                'path' => $exists ? $this->xmlNodePath($idKey) : [],
                'changes' => $changes,
                'update' => $update,
                'updateV2' => $updateV2,
                'origin' => $origin,
            ];
        }

        return $events;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $beforeTextDeltas
     * @param array<string, list<string>> $beforeXmlRootChildren
     */
    private function sharedTypeChanges(string $name, ?string $type, bool $hadOldValue, mixed $oldValue, mixed $oldComparableValue, bool $hasNewValue, mixed $newValue, mixed $newComparableValue, array $beforeTextDeltas = [], array $beforeTextAttributes = [], array $beforeXmlRootChildren = [], ?array $currentTextAttributes = null, ?array $currentTextDelta = null): array
    {
        return [
            'keys' => match ($type) {
                'map' => $this->mapEventChanges($hadOldValue && is_array($oldComparableValue) ? $oldComparableValue : [], $hasNewValue && is_array($newComparableValue) ? $newComparableValue : [], $hadOldValue && is_array($oldValue) ? $oldValue : []),
                'text' => $this->mapEventChanges($beforeTextAttributes[$name] ?? [], $currentTextAttributes ?? $this->textAttributes($name), $beforeTextAttributes[$name] ?? []),
                default => [],
            },
            'delta' => match ($type) {
                'text' => isset($beforeTextDeltas[$name])
                    ? $this->textDeltaEventDelta($beforeTextDeltas[$name], $currentTextDelta ?? $this->textDelta($name))
                    : $this->textEventDelta($hadOldValue ? (string) $oldValue : '', $hasNewValue ? (string) $newValue : ''),
                'array' => $this->arrayEventDelta($hadOldValue && is_array($oldComparableValue) ? $oldComparableValue : [], $hasNewValue && is_array($newComparableValue) ? $newComparableValue : [], $hasNewValue && is_array($newValue) ? $newValue : []),
                'xml' => array_key_exists($name, $beforeXmlRootChildren) || array_key_exists($name, $this->xmlRootChildrenByName)
                    ? $this->xmlRootChildEventDelta($beforeXmlRootChildren[$name] ?? [], $this->xmlRootChildIds($name))
                    : $this->xmlEventDelta($hadOldValue ? (string) $oldValue : '', $hasNewValue ? (string) $newValue : ''),
                default => [],
            },
        ];
    }

    /**
     * @param array{keys: array<string, mixed>, delta: list<array<string, mixed>>} $changes
     */
    private static function sharedTypeChangesAreEmpty(array $changes): bool
    {
        return $changes['keys'] === [] && $changes['delta'] === [];
    }

    private function rootSharedTypeKind(string $name, mixed $oldValue, mixed $newValue): ?string
    {
        if (isset($this->rootTypeHints[$name])) {
            return $this->rootTypeHints[$name];
        }

        if (array_key_exists($name, $this->xmlRootChildrenByName)) {
            return 'xml';
        }

        foreach ($this->structsById as $struct) {
            if (($struct['type'] ?? null) !== 'Item' || $this->storedStructParent($struct) !== $name) {
                continue;
            }

            if ($this->storedStructParentSub($struct) !== null) {
                return 'map';
            }

            $contentType = $struct['content']['type'] ?? null;
            if (in_array($contentType, ['ContentString', 'ContentEmbed', 'ContentFormat'], true)) {
                return 'text';
            }

            if ($contentType === 'ContentType') {
                $kind = self::sharedTypeKindFromYjsName($struct['content']['typeName'] ?? null);
                if ($kind !== null) {
                    return $kind === 'xml' ? 'xml' : 'array';
                }
            }
        }

        $value = $newValue ?? $oldValue;
        if (is_array($value)) {
            return array_is_list($value) ? 'array' : 'map';
        }

        return is_string($value) ? 'text' : null;
    }

    private function registerRootType(string $name, string $type): void
    {
        $existing = $this->rootTypeHints[$name] ?? null;
        if ($existing === $type) {
            return;
        }

        if ($existing !== null) {
            throw new \LogicException(sprintf('Shared type "%s" has already been defined as %s.', $name, $existing));
        }

        $this->rootTypeHints[$name] = $type;
        if ($this->structsById !== []) {
            $this->rematerialize();
        }
    }

    private static function sharedTypeKindFromYjsName(mixed $typeName): ?string
    {
        return match ($typeName) {
            'YArray' => 'array',
            'YMap' => 'map',
            'YText' => 'text',
            'YXmlElement', 'YXmlFragment', 'YXmlHook', 'YXmlText' => 'xml',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $oldValue
     * @param array<string, mixed> $newValue
     * @return array<string, array<string, mixed>>
     */
    private function mapEventChanges(array $oldValue, array $newValue, array $oldRenderedValue): array
    {
        $changes = [];

        foreach (array_keys($oldValue + $newValue) as $key) {
            $hadOld = array_key_exists($key, $oldValue);
            $hasNew = array_key_exists($key, $newValue);

            if (! $hadOld && $hasNew) {
                $changes[$key] = ['action' => 'add'];
                continue;
            }

            if ($hadOld && ! $hasNew) {
                $changes[$key] = [
                    'action' => 'delete',
                    'oldValue' => $oldValue[$key],
                ];
                continue;
            }

            if ($oldValue[$key] !== $newValue[$key]) {
                $changes[$key] = [
                    'action' => 'update',
                    'oldValue' => $oldRenderedValue[$key] ?? $oldValue[$key],
                ];
            }
        }

        return $changes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function textEventDelta(string $oldValue, string $newValue): array
    {
        return $this->sequenceEventDelta(self::utf16StringToUnits($oldValue), self::utf16StringToUnits($newValue), true);
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function observedTextDeltas(): array
    {
        $deltas = [];

        foreach ($this->sharedTypeObservers as $registration) {
            if ($registration['type'] !== 'text') {
                continue;
            }

            $name = $registration['name'];
            $deltas[$name] = $this->textDelta($name);
        }

        return $deltas;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function observedNestedTextDeltas(): array
    {
        $deltas = [];
        $idKeys = $this->deepObservers !== []
            ? array_keys($this->nestedJsonById)
            : array_map(static fn (array $registration): string => $registration['idKey'], $this->nestedTypeObservers);

        foreach (array_values(array_unique($idKeys)) as $idKey) {
            $struct = $this->structsById[$idKey] ?? null;
            if (($struct['content']['typeName'] ?? null) !== 'YText') {
                continue;
            }

            $deltas[$idKey] = $this->nestedTextDelta($idKey);
        }

        ksort($deltas, SORT_STRING);

        return $deltas;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rootTextAttributeValues(): array
    {
        $attributes = [];

        foreach ($this->rootTextAttributesByName as $name => $values) {
            $attributes[$name] = $values;
            ksort($attributes[$name], SORT_STRING);
        }

        ksort($attributes, SORT_STRING);

        return $attributes;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function nestedTextAttributeValues(): array
    {
        $attributes = [];

        foreach ($this->nestedTextAttributesById as $idKey => $values) {
            $attributes[$idKey] = $values;
            ksort($attributes[$idKey], SORT_STRING);
        }

        ksort($attributes, SORT_STRING);

        return $attributes;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function observedXmlTextDeltas(): array
    {
        $deltas = [];
        $idKeys = $this->deepObservers !== []
            ? array_keys($this->xmlNodesById)
            : array_map(static fn (array $registration): string => $registration['idKey'], $this->xmlNodeObservers);

        foreach ($idKeys as $idKey) {
            if (($this->xmlNodesById[$idKey]['typeName'] ?? null) !== 'YXmlText') {
                continue;
            }

            $deltas[$idKey] = $this->xmlTextDelta($idKey);
        }

        return $deltas;
    }

    /**
     * @return array<string, string>
     */
    private function xmlTextValues(): array
    {
        $values = [];

        foreach ($this->xmlNodesById as $idKey => $node) {
            if (($node['typeName'] ?? null) === 'YXmlText') {
                $values[$idKey] = $this->xmlTextValue($idKey);
            }
        }

        ksort($values, SORT_STRING);

        return $values;
    }

    /**
     * @return array<string, list<string>>
     */
    private function observedXmlRootChildren(): array
    {
        $children = [];

        foreach (array_keys($this->xmlRootChildrenByName) as $name) {
            $children[$name] = $this->xmlRootChildIds($name);
        }

        return $children;
    }

    /**
     * @return array<string, list<string>>
     */
    private function observedXmlElementChildren(): array
    {
        $children = [];
        $idKeys = $this->deepObservers !== []
            ? array_keys($this->xmlNodesById)
            : array_map(static fn (array $registration): string => $registration['idKey'], $this->xmlNodeObservers);

        foreach ($idKeys as $idKey) {
            if (! in_array($this->xmlNodesById[$idKey]['typeName'] ?? null, ['YXmlElement', 'YXmlFragment'], true)) {
                continue;
            }

            $children[$idKey] = $this->xmlElementChildIds($idKey);
        }

        return $children;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function observedXmlElementAttributes(): array
    {
        $attributes = [];
        $idKeys = $this->deepObservers !== []
            ? array_keys($this->xmlNodesById)
            : array_map(static fn (array $registration): string => $registration['idKey'], $this->xmlNodeObservers);

        foreach ($idKeys as $idKey) {
            if (! in_array($this->xmlNodesById[$idKey]['typeName'] ?? null, ['YXmlElement', 'YXmlHook', 'YXmlText'], true)) {
                continue;
            }

            $attributes[$idKey] = $this->xmlElementAttributes($idKey);
        }

        return $attributes;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function xmlElementAttributeValues(): array
    {
        $attributes = [];

        foreach ($this->xmlNodesById as $idKey => $node) {
            if (in_array($node['typeName'] ?? null, ['YXmlElement', 'YXmlHook', 'YXmlText'], true)) {
                $attributes[$idKey] = $this->xmlElementAttributes($idKey);
            }
        }

        ksort($attributes, SORT_STRING);

        return $attributes;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function observedXmlElementAttributeOldValues(): array
    {
        $oldValues = [];
        $idKeys = $this->deepObservers !== []
            ? array_keys($this->xmlNodesById)
            : array_map(static fn (array $registration): string => $registration['idKey'], $this->xmlNodeObservers);

        foreach ($idKeys as $idKey) {
            if (! in_array($this->xmlNodesById[$idKey]['typeName'] ?? null, ['YXmlElement', 'YXmlHook', 'YXmlText'], true)) {
                continue;
            }

            $oldValues[$idKey] = [];
            foreach ($this->xmlElementAttributes($idKey) as $key => $value) {
                $identity = $this->xmlAttributeIdentityById[$idKey][$key] ?? null;
                $oldValues[$idKey][$key] = $this->xmlAttributeEventOldValue($identity, $value);
            }
        }

        return $oldValues;
    }

    private function xmlAttributeEventOldValue(mixed $identity, mixed $value): mixed
    {
        $nestedIdKey = $this->sharedTypeIdFromIdentityValue($identity);
        if ($nestedIdKey !== null) {
            return $this->emptyNestedSharedTypeEventValue($nestedIdKey, $value);
        }

        $xmlIdKey = $this->xmlTypeIdFromIdentityValue($identity);
        if ($xmlIdKey !== null) {
            return $this->emptyXmlNodeEventValue($xmlIdKey, $value);
        }

        return $value;
    }

    private function emptyNestedSharedTypeEventValue(string $idKey, mixed $fallback): mixed
    {
        $content = $this->structsById[$idKey]['content'] ?? [];
        $typeName = is_array($content) ? ($content['typeName'] ?? null) : null;

        return match ($typeName) {
            'YText', 'YXmlText', 'YXmlFragment' => '',
            'YArray', 'YMap' => [],
            'YXmlElement' => '<' . strtolower((string) ($content['nodeName'] ?? 'UNDEFINED')) . '></' . strtolower((string) ($content['nodeName'] ?? 'UNDEFINED')) . '>',
            'YXmlHook' => '[object Object]',
            default => $fallback,
        };
    }

    private function emptyXmlNodeEventValue(string $idKey, mixed $fallback): mixed
    {
        $node = $this->xmlNodesById[$idKey] ?? null;
        if ($node === null) {
            return $fallback;
        }

        return match ($node['typeName'] ?? null) {
            'YXmlText', 'YXmlFragment' => '',
            'YXmlElement' => '<' . strtolower((string) ($node['nodeName'] ?? 'UNDEFINED')) . '></' . strtolower((string) ($node['nodeName'] ?? 'UNDEFINED')) . '>',
            'YXmlHook' => '[object Object]',
            default => $fallback,
        };
    }

    /**
     * @return array<string, list<string>>
     */
    private function xmlRootChildIdValues(): array
    {
        $children = [];

        foreach (array_keys($this->xmlRootChildrenByName) as $name) {
            $children[$name] = $this->xmlRootChildIds($name);
        }

        ksort($children, SORT_STRING);

        return $children;
    }

    /**
     * @return array<string, list<string>>
     */
    private function xmlElementChildIdValues(): array
    {
        $children = [];

        foreach ($this->xmlNodesById as $idKey => $node) {
            if (in_array($node['typeName'] ?? null, ['YXmlElement', 'YXmlFragment'], true)) {
                $children[$idKey] = $this->xmlElementChildIds($idKey);
            }
        }

        ksort($children, SORT_STRING);

        return $children;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function xmlRootChildrenSnapshots(): array
    {
        $snapshots = [];

        foreach (array_keys($this->xmlRootChildrenByName) as $name) {
            $snapshots[$name] = array_map(
                fn (string $idKey): array => $this->xmlNodeSnapshot($idKey),
                $this->xmlRootChildIds($name)
            );
        }

        ksort($snapshots, SORT_STRING);

        return $snapshots;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function xmlElementChildrenSnapshots(): array
    {
        $snapshots = [];

        foreach ($this->xmlNodesById as $idKey => $node) {
            if (in_array($node['typeName'] ?? null, ['YXmlElement', 'YXmlFragment'], true)) {
                $snapshots[$idKey] = array_map(
                    fn (string $childIdKey): array => $this->xmlNodeSnapshot($childIdKey),
                    $this->xmlElementChildIds($idKey)
                );
            }
        }

        ksort($snapshots, SORT_STRING);

        return $snapshots;
    }

    /**
     * @return array<string, mixed>
     */
    private function xmlNodeSnapshot(string $idKey): array
    {
        $node = $this->xmlNodesById[$idKey] ?? null;
        $typeName = $node['typeName'] ?? null;

        return match ($typeName) {
            'YXmlElement' => [
                'type' => 'element',
                'nodeName' => $node['nodeName'] ?? 'UNDEFINED',
                'attributes' => $this->xmlElementAttributes($idKey),
                'children' => array_map(
                    fn (string $childIdKey): array => $this->xmlNodeSnapshot($childIdKey),
                    $this->xmlElementChildIds($idKey)
                ),
            ],
            'YXmlFragment' => [
                'type' => 'fragment',
                'children' => array_map(
                    fn (string $childIdKey): array => $this->xmlNodeSnapshot($childIdKey),
                    $this->xmlElementChildIds($idKey)
                ),
            ],
            'YXmlText' => [
                'type' => 'text',
                'delta' => $this->xmlTextDelta($idKey),
            ],
            'YXmlHook' => [
                'type' => 'hook',
                'hookName' => $node['hookName'] ?? '',
                'attributes' => $this->xmlElementAttributes($idKey),
            ],
            default => [
                'type' => 'text',
                'delta' => [],
            ],
        };
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function insertXmlSnapshotIntoRoot(string $name, int $index, array $snapshot): void
    {
        $this->populateXmlSnapshotNode(
            $snapshot,
            match ($snapshot['type'] ?? null) {
                'element' => $this->insertXmlElement($name, $index, (string) ($snapshot['nodeName'] ?? 'UNDEFINED')),
                'hook' => $this->insertXmlHook($name, $index, (string) ($snapshot['hookName'] ?? '')),
                default => $this->insertXmlFragmentText($name, $index, ''),
            }
        );
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function insertXmlSnapshotIntoElement(string $elementIdKey, int $index, array $snapshot): void
    {
        $this->populateXmlSnapshotNode(
            $snapshot,
            match ($snapshot['type'] ?? null) {
                'element' => $this->insertXmlElementChild($elementIdKey, $index, (string) ($snapshot['nodeName'] ?? 'UNDEFINED')),
                'hook' => $this->insertXmlHookChild($elementIdKey, $index, (string) ($snapshot['hookName'] ?? '')),
                default => $this->insertXmlText($elementIdKey, $index, ''),
            }
        );
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function populateXmlSnapshotNode(array $snapshot, YXmlElement|YXmlText|YXmlHook $node): void
    {
        if ($node instanceof YXmlText) {
            $delta = isset($snapshot['delta']) && is_array($snapshot['delta']) ? $snapshot['delta'] : [];
            if ($delta !== []) {
                $node->applyDelta($delta);
            }

            return;
        }

        $attributes = isset($snapshot['attributes']) && is_array($snapshot['attributes']) ? $snapshot['attributes'] : [];
        foreach ($attributes as $key => $value) {
            $this->setXmlElementAttribute($node->idKey(), (string) $key, $value);
        }

        if ($node instanceof YXmlElement) {
            $children = isset($snapshot['children']) && is_array($snapshot['children']) ? array_values($snapshot['children']) : [];
            foreach ($children as $index => $child) {
                if (is_array($child)) {
                    $this->insertXmlSnapshotIntoElement($node->idKey(), $index, $child);
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function xmlElementChildValues(string $idKey): array
    {
        return array_map(fn (string $childIdKey): string => $this->renderXmlNode($this->xmlNodesById, $childIdKey), $this->xmlElementChildIds($idKey));
    }

    /**
     * @return list<string>
     */
    private function xmlRootChildIds(string $name): array
    {
        $values = [];

        foreach ($this->xmlRootChildrenByName[$name] ?? [] as $child) {
            if (! ($child['visible'] ?? false)) {
                continue;
            }

            $childIdKey = (string) $child['value'];
            if (isset($this->xmlNodesById[$childIdKey])) {
                $values[] = $childIdKey;
            }
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private function xmlElementChildIds(string $idKey): array
    {
        $values = [];

        foreach ($this->xmlNodesById[$idKey]['children'] ?? [] as $child) {
            if (! ($child['visible'] ?? false)) {
                continue;
            }

            $childIdKey = (string) $child['value'];
            if (isset($this->xmlNodesById[$childIdKey])) {
                $values[] = $childIdKey;
            }
        }

        return $values;
    }

    /**
     * @param list<array<string, mixed>> $oldDelta
     * @param list<array<string, mixed>> $newDelta
     * @return list<array<string, mixed>>
     */
    private function textDeltaEventDelta(array $oldDelta, array $newDelta): array
    {
        if ($oldDelta === $newDelta) {
            return [];
        }

        $oldUnits = $this->textDeltaUnits($oldDelta);
        $newUnits = $this->textDeltaUnits($newDelta);
        $oldCount = count($oldUnits);
        $newCount = count($newUnits);
        $prefix = 0;

        while ($prefix < $oldCount && $prefix < $newCount && self::textDeltaUnitsEqual($oldUnits[$prefix], $newUnits[$prefix])) {
            $prefix++;
        }

        $suffix = 0;
        while (
            $suffix + $prefix < $oldCount
            && $suffix + $prefix < $newCount
            && self::textDeltaUnitsEqual($oldUnits[$oldCount - $suffix - 1], $newUnits[$newCount - $suffix - 1])
        ) {
            $suffix++;
        }

        $oldMiddle = array_slice($oldUnits, $prefix, $oldCount - $prefix - $suffix);
        $newMiddle = array_slice($newUnits, $prefix, $newCount - $prefix - $suffix);
        $delta = [];

        if ($prefix > 0) {
            $this->appendRetainOperation($delta, $prefix);
        }

        if (
            count($oldMiddle) === count($newMiddle)
            && $oldMiddle !== []
            && self::textDeltaUnitContentListEquals($oldMiddle, $newMiddle)
        ) {
            $pendingAttributes = null;
            $pendingLength = 0;
            $flushPendingRetain = function () use (&$delta, &$pendingAttributes, &$pendingLength): void {
                if ($pendingLength === 0) {
                    return;
                }

                $this->appendRetainOperation($delta, $pendingLength, $pendingAttributes ?? []);
                $pendingAttributes = null;
                $pendingLength = 0;
            };

            foreach ($oldMiddle as $index => $oldUnit) {
                $attributes = self::attributeChange($oldUnit['attributes'], $newMiddle[$index]['attributes']);
                if ($attributes === []) {
                    $flushPendingRetain();
                    $this->appendRetainOperation($delta, 1);
                    continue;
                }

                if ($pendingAttributes !== null && $pendingAttributes !== $attributes) {
                    $flushPendingRetain();
                }

                $pendingAttributes = $attributes;
                $pendingLength++;
            }

            $flushPendingRetain();

            return $delta;
        }

        if ($oldMiddle !== []) {
            $delta[] = ['delete' => count($oldMiddle)];
        }

        $this->appendTextDeltaInserts($delta, $newMiddle);

        return $delta;
    }

    /**
     * @param list<array<string, mixed>> $delta
     * @return list<array{insert: mixed, text: bool, attributes: array<string, mixed>}>
     */
    private function textDeltaUnits(array $delta): array
    {
        $units = [];

        foreach ($delta as $operation) {
            if (! array_key_exists('insert', $operation)) {
                continue;
            }

            $attributes = isset($operation['attributes']) && is_array($operation['attributes']) ? $operation['attributes'] : [];
            ksort($attributes, SORT_STRING);
            $insert = $operation['insert'];

            if (is_string($insert)) {
                foreach (self::utf16StringToUnits($insert) as $unit) {
                    $units[] = [
                        'insert' => $unit,
                        'text' => true,
                        'attributes' => $attributes,
                    ];
                }
                continue;
            }

            $units[] = [
                'insert' => $insert,
                'text' => false,
                'attributes' => $attributes,
            ];
        }

        return $units;
    }

    /**
     * @param list<array<string, mixed>> $delta
     * @param array{insert: mixed, text: bool, attributes: array<string, mixed>} $unit
     */
    private function appendTextDeltaInsert(array &$delta, array $unit): void
    {
        $lastIndex = count($delta) - 1;

        if (
            $unit['text']
            && $lastIndex >= 0
            && array_key_exists('insert', $delta[$lastIndex])
            && is_string($delta[$lastIndex]['insert'])
            && (($delta[$lastIndex]['attributes'] ?? []) === $unit['attributes'])
        ) {
            $delta[$lastIndex]['insert'] .= self::utf16UnitsToString([$unit['insert']]);
            return;
        }

        $operation = [
            'insert' => $unit['text'] ? self::utf16UnitsToString([$unit['insert']]) : $unit['insert'],
        ];

        if ($unit['attributes'] !== []) {
            $operation['attributes'] = $unit['attributes'];
        }

        $delta[] = $operation;
    }

    /**
     * @param list<array<string, mixed>> $delta
     * @param list<array{insert: mixed, text: bool, attributes: array<string, mixed>}> $units
     */
    private function appendTextDeltaInserts(array &$delta, array $units): void
    {
        foreach ($units as $unit) {
            $this->appendTextDeltaInsert($delta, $unit);
        }
    }

    /**
     * @param list<array<string, mixed>> $delta
     * @param array<string, mixed> $attributes
     */
    private function appendRetainOperation(array &$delta, int $length, array $attributes = []): void
    {
        if ($length <= 0) {
            return;
        }

        $operation = ['retain' => $length];
        if ($attributes !== []) {
            $operation['attributes'] = $attributes;
        }

        $lastIndex = count($delta) - 1;
        if (
            $lastIndex >= 0
            && array_key_exists('retain', $delta[$lastIndex])
            && (($delta[$lastIndex]['attributes'] ?? []) === ($operation['attributes'] ?? []))
        ) {
            $delta[$lastIndex]['retain'] += $length;
            return;
        }

        $delta[] = $operation;
    }

    /**
     * @param array{insert: mixed, text: bool, attributes: array<string, mixed>} $left
     * @param array{insert: mixed, text: bool, attributes: array<string, mixed>} $right
     */
    private static function textDeltaUnitsEqual(array $left, array $right): bool
    {
        return self::textDeltaUnitContentEquals($left, $right) && $left['attributes'] === $right['attributes'];
    }

    /**
     * @param array{insert: mixed, text: bool, attributes: array<string, mixed>} $left
     * @param array{insert: mixed, text: bool, attributes: array<string, mixed>} $right
     */
    private static function textDeltaUnitContentEquals(array $left, array $right): bool
    {
        return $left['text'] === $right['text'] && $left['insert'] === $right['insert'];
    }

    /**
     * @param list<array{insert: mixed, text: bool, attributes: array<string, mixed>}> $left
     * @param list<array{insert: mixed, text: bool, attributes: array<string, mixed>}> $right
     */
    private static function textDeltaUnitContentListEquals(array $left, array $right): bool
    {
        foreach ($left as $index => $unit) {
            if (! isset($right[$index]) || ! self::textDeltaUnitContentEquals($unit, $right[$index])) {
                return false;
            }
        }

        return count($left) === count($right);
    }

    /**
     * @param array<string, mixed> $oldAttributes
     * @param array<string, mixed> $newAttributes
     * @return array<string, mixed>
     */
    private static function attributeChange(array $oldAttributes, array $newAttributes): array
    {
        $change = [];

        foreach (array_keys($oldAttributes + $newAttributes) as $key) {
            $hadOld = array_key_exists($key, $oldAttributes);
            $hasNew = array_key_exists($key, $newAttributes);

            if ($hadOld && ! $hasNew) {
                $change[$key] = null;
                continue;
            }

            if (! $hadOld && $hasNew) {
                $change[$key] = $newAttributes[$key];
                continue;
            }

            if ($oldAttributes[$key] !== $newAttributes[$key]) {
                $change[$key] = $newAttributes[$key];
            }
        }

        ksort($change, SORT_STRING);

        return $change;
    }

    /**
     * @param list<mixed> $oldValue
     * @param list<mixed> $newValue
     * @return list<array<string, mixed>>
     */
    private function arrayEventDelta(array $oldValue, array $newValue, array $newRenderedValue): array
    {
        if ($oldValue === $newValue) {
            return [];
        }

        $oldCount = count($oldValue);
        $newCount = count($newValue);
        $prefix = 0;

        while ($prefix < $oldCount && $prefix < $newCount && $oldValue[$prefix] === $newValue[$prefix]) {
            $prefix++;
        }

        $suffix = 0;
        while (
            $suffix + $prefix < $oldCount
            && $suffix + $prefix < $newCount
            && $oldValue[$oldCount - $suffix - 1] === $newValue[$newCount - $suffix - 1]
        ) {
            $suffix++;
        }

        $delta = [];
        if ($prefix > 0) {
            $delta[] = ['retain' => $prefix];
        }

        $deleteLength = $oldCount - $prefix - $suffix;
        if ($deleteLength > 0) {
            $delta[] = ['delete' => $deleteLength];
        }

        $insertValues = array_slice($newRenderedValue, $prefix, $newCount - $prefix - $suffix);
        if ($insertValues !== []) {
            $delta[] = ['insert' => $insertValues];
        }

        return $delta;
    }

    /**
     * @param list<string> $oldChildIds
     * @param list<string> $newChildIds
     * @return list<array<string, mixed>>
     */
    private function xmlRootChildEventDelta(array $oldChildIds, array $newChildIds): array
    {
        return $this->xmlChildEventDelta($oldChildIds, $newChildIds);
    }

    /**
     * @param list<string> $oldChildIds
     * @param list<string> $newChildIds
     * @return list<array<string, mixed>>
     */
    private function xmlElementChildEventDelta(array $oldChildIds, array $newChildIds): array
    {
        return $this->xmlChildEventDelta($oldChildIds, $newChildIds);
    }

    /**
     * @param list<string> $oldChildIds
     * @param list<string> $newChildIds
     * @return list<array<string, mixed>>
     */
    private function xmlChildEventDelta(array $oldChildIds, array $newChildIds): array
    {
        $delta = $this->sequenceEventDelta($oldChildIds, $newChildIds, false, true);

        foreach ($delta as $index => $operation) {
            if (! isset($operation['insert']) || ! is_array($operation['insert'])) {
                continue;
            }

            $delta[$index]['insert'] = array_map(
                fn (string $childIdKey): string => $this->renderXmlNode($this->xmlNodesById, $childIdKey),
                $operation['insert']
            );
        }

        return $delta;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function xmlEventDelta(string $oldValue, string $newValue): array
    {
        if ($oldValue === $newValue) {
            return [];
        }

        $delta = [];
        if ($oldValue !== '') {
            $delta[] = ['delete' => 1];
        }
        if ($newValue !== '') {
            $delta[] = ['insert' => [$newValue]];
        }

        return $delta;
    }

    /**
     * @param list<mixed> $oldValue
     * @param list<mixed> $newValue
     * @return list<array<string, mixed>>
     */
    private function sequenceEventDelta(array $oldValue, array $newValue, bool $text, bool $insertBeforeDelete = false): array
    {
        if ($oldValue === $newValue) {
            return [];
        }

        $oldCount = count($oldValue);
        $newCount = count($newValue);
        $prefix = 0;

        while ($prefix < $oldCount && $prefix < $newCount && $oldValue[$prefix] === $newValue[$prefix]) {
            $prefix++;
        }

        $suffix = 0;
        while (
            $suffix + $prefix < $oldCount
            && $suffix + $prefix < $newCount
            && $oldValue[$oldCount - $suffix - 1] === $newValue[$newCount - $suffix - 1]
        ) {
            $suffix++;
        }

        $delta = [];
        if ($prefix > 0) {
            $delta[] = ['retain' => $prefix];
        }

        $deleteLength = $oldCount - $prefix - $suffix;
        if ($deleteLength > 0 && ! $insertBeforeDelete) {
            $delta[] = ['delete' => $deleteLength];
        }

        $insertValues = array_slice($newValue, $prefix, $newCount - $prefix - $suffix);
        if ($insertValues !== []) {
            $delta[] = [
                'insert' => $text ? self::utf16UnitsToString($insertValues) : $insertValues,
            ];
        }
        if ($deleteLength > 0 && $insertBeforeDelete) {
            $delta[] = ['delete' => $deleteLength];
        }

        return $delta;
    }

    /**
     * @param array<string, mixed> $beforeNested
     * @param array<string, array<string, mixed>> $beforeNestedTextAttributes
     * @param array<string, true> $changedIds
     */
    private function notifyNestedTypeObservers(array $beforeNested, array $beforeNestedIdentity, array $beforeNestedTextAttributes, array $beforeNestedTextDeltas, ?string $update, ?string $updateV2, mixed $origin, array $changedIds): void
    {
        if ($this->nestedTypeObservers === [] || $changedIds === []) {
            return;
        }

        $this->dispatchDynamicObservers($this->nestedTypeObservers, function (array $registration) use ($beforeNested, $beforeNestedIdentity, $beforeNestedTextAttributes, $beforeNestedTextDeltas, $update, $updateV2, $origin, $changedIds): void {
            $idKey = $registration['idKey'];
            if (! isset($changedIds[$idKey])) {
                return;
            }

            $hadOldValue = array_key_exists($idKey, $beforeNested);
            $hasNewValue = array_key_exists($idKey, $this->nestedJsonById);
            $oldValue = $hadOldValue ? $beforeNested[$idKey] : null;
            $newValue = $hasNewValue ? $this->nestedJsonById[$idKey] : null;

            $registration['observer']([
                'idKey' => $idKey,
                'oldValue' => $oldValue,
                'newValue' => $newValue,
                'oldExists' => $hadOldValue,
                'newExists' => $hasNewValue,
                'path' => [],
                'changes' => $this->sharedTypeChanges(
                    $idKey,
                    $registration['type'],
                    $hadOldValue,
                    $oldValue,
                    $hadOldValue && array_key_exists($idKey, $beforeNestedIdentity) ? $beforeNestedIdentity[$idKey] : $oldValue,
                    $hasNewValue,
                    $newValue,
                    $hasNewValue && array_key_exists($idKey, $this->nestedIdentityById) ? $this->nestedIdentityById[$idKey] : $newValue,
                    $beforeNestedTextDeltas,
                    $beforeNestedTextAttributes,
                    [],
                    $this->nestedTextAttributes($idKey),
                    $registration['type'] === 'text' && isset($beforeNestedTextDeltas[$idKey]) ? $this->nestedTextDelta($idKey) : null
                ),
                'update' => $update,
                'updateV2' => $updateV2,
                'origin' => $origin,
            ], $this);
        });
    }

    /**
     * @param array<string, true> $changedIds
     * @param array<string, list<array<string, mixed>>> $beforeXmlTextDeltas
     * @param array<string, list<string>> $beforeXmlElementChildren
     * @param array<string, array<string, mixed>> $beforeXmlElementAttributes
     * @param array<string, array<string, mixed>> $beforeXmlElementAttributeOldValues
     * @param array<string, array<string, true>> $changedXmlAttributes
     */
    private function notifyXmlNodeObservers(
        ?string $update,
        ?string $updateV2,
        mixed $origin,
        array $changedIds,
        array $beforeXmlTextDeltas = [],
        array $beforeXmlElementChildren = [],
        array $beforeXmlElementAttributes = [],
        array $beforeXmlElementAttributeOldValues = [],
        array $changedXmlAttributes = []
    ): void
    {
        if ($this->xmlNodeObservers === [] || $changedIds === []) {
            return;
        }

        $this->dispatchDynamicObservers($this->xmlNodeObservers, function (array $registration) use ($update, $updateV2, $origin, $changedIds, $beforeXmlTextDeltas, $beforeXmlElementChildren, $beforeXmlElementAttributes, $beforeXmlElementAttributeOldValues, $changedXmlAttributes): void {
            $idKey = $registration['idKey'];
            if (! isset($changedIds[$idKey])) {
                return;
            }

            $node = $this->xmlNodesById[$idKey] ?? null;
            $exists = $node !== null && $this->xmlNodeIsVisible($idKey);
            $registration['observer']([
                'idKey' => $idKey,
                'exists' => $exists,
                'typeName' => $node['typeName'] ?? null,
                'value' => $exists ? $this->renderXmlNode($this->xmlNodesById, $idKey) : null,
                'path' => [],
                'changes' => $this->xmlNodeChanges($idKey, $beforeXmlTextDeltas, $beforeXmlElementChildren, $beforeXmlElementAttributes, $beforeXmlElementAttributeOldValues, $changedXmlAttributes),
                'update' => $update,
                'updateV2' => $updateV2,
                'origin' => $origin,
            ], $this);
        });
    }

    /**
     * @param array<string, list<array<string, mixed>>> $beforeXmlTextDeltas
     * @param array<string, list<string>> $beforeXmlElementChildren
     * @param array<string, array<string, mixed>> $beforeXmlElementAttributes
     * @param array<string, array<string, mixed>> $beforeXmlElementAttributeOldValues
     * @param array<string, array<string, true>> $changedXmlAttributes
     * @return array{keys: array<string, mixed>, delta: list<array<string, mixed>>, attributesChanged?: list<string>}
     */
    private function xmlNodeChanges(string $idKey, array $beforeXmlTextDeltas, array $beforeXmlElementChildren, array $beforeXmlElementAttributes, array $beforeXmlElementAttributeOldValues, array $changedXmlAttributes): array
    {
        $node = $this->xmlNodesById[$idKey] ?? null;
        $typeName = $node['typeName'] ?? null;
        $delta = match ($typeName) {
            'YXmlText' => isset($beforeXmlTextDeltas[$idKey])
                ? $this->textDeltaEventDelta($beforeXmlTextDeltas[$idKey], $this->xmlTextDelta($idKey))
                : [],
            'YXmlElement', 'YXmlFragment' => isset($beforeXmlElementChildren[$idKey])
                ? $this->xmlElementChildEventDelta($beforeXmlElementChildren[$idKey], $this->xmlElementChildIds($idKey))
                : [],
            default => [],
        };
        $changes = [
            'keys' => in_array($typeName, ['YXmlElement', 'YXmlHook', 'YXmlText'], true) && isset($beforeXmlElementAttributes[$idKey], $changedXmlAttributes[$idKey])
                ? $this->xmlAttributeEventKeys($beforeXmlElementAttributes[$idKey], $this->xmlElementAttributes($idKey), $beforeXmlElementAttributeOldValues[$idKey] ?? $beforeXmlElementAttributes[$idKey], $changedXmlAttributes[$idKey])
                : [],
            'delta' => $delta,
        ];

        if ($typeName === 'YXmlElement' && isset($changedXmlAttributes[$idKey])) {
            $changedAttributes = array_keys($changedXmlAttributes[$idKey]);
            sort($changedAttributes, SORT_STRING);

            if ($changedAttributes !== []) {
                $changes['attributesChanged'] = $changedAttributes;
            }
        }

        return $changes;
    }

    /**
     * @param array{keys: array<string, mixed>, delta: list<array<string, mixed>>, attributesChanged?: list<string>} $changes
     */
    private static function xmlNodeChangesAreEmpty(array $changes): bool
    {
        return $changes['keys'] === []
            && $changes['delta'] === []
            && ($changes['attributesChanged'] ?? []) === [];
    }

    /**
     * @param array<string, mixed> $beforeAttributes
     * @param array<string, mixed> $currentAttributes
     * @param array<string, mixed> $beforeAttributeOldValues
     * @param array<string, true> $changedAttributes
     * @return array<string, array<string, mixed>>
     */
    private function xmlAttributeEventKeys(array $beforeAttributes, array $currentAttributes, array $beforeAttributeOldValues, array $changedAttributes): array
    {
        $keys = [];

        foreach (array_keys($changedAttributes) as $key) {
            $hadBefore = array_key_exists($key, $beforeAttributes);
            $hasCurrent = array_key_exists($key, $currentAttributes);

            if (! $hadBefore && $hasCurrent) {
                $keys[$key] = ['action' => 'add'];
                continue;
            }

            if ($hadBefore && ! $hasCurrent) {
                $keys[$key] = [
                    'action' => 'delete',
                    'oldValue' => $beforeAttributeOldValues[$key] ?? $beforeAttributes[$key],
                ];
                continue;
            }

            if ($hadBefore && $hasCurrent && $beforeAttributes[$key] !== $currentAttributes[$key]) {
                $keys[$key] = [
                    'action' => 'update',
                    'oldValue' => $beforeAttributeOldValues[$key] ?? $beforeAttributes[$key],
                ];
            }
        }

        return $keys;
    }

    private function xmlNodeIsVisible(string $idKey): bool
    {
        if ($this->xmlNodeLocation($idKey) !== null) {
            return true;
        }

        foreach ($this->xmlRootChildrenByName as $children) {
            foreach ($children as $child) {
                if (! $child['visible']) {
                    continue;
                }

                if ($this->visibleXmlTreeContains((string) $child['value'], $idKey)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    private function xmlNodePath(string $targetIdKey): array
    {
        return $this->xmlNodeLocation($targetIdKey)['path'] ?? [];
    }

    /**
     * @return array{root: string, path: list<int|string>}|null
     */
    private function xmlNodeLocation(string $targetIdKey, array $seen = []): ?array
    {
        if (isset($seen[$targetIdKey]) || ! isset($this->structsById[$targetIdKey])) {
            return null;
        }

        foreach ($this->xmlRootChildrenByName as $name => $children) {
            $path = $this->xmlNodePathInChildren($children, $targetIdKey);
            if ($path !== null) {
                return [
                    'root' => $name,
                    'path' => $path,
                ];
            }
        }

        $struct = $this->structsById[$targetIdKey];
        $parentSub = $this->storedStructParentSub($struct);
        $parentRoot = $this->storedStructParent($struct);
        if ($parentRoot !== null) {
            $segment = $parentSub ?? $this->nestedSharedTypeIndexInRootSequence($parentRoot, $targetIdKey);
            if ($segment === null) {
                return null;
            }

            return [
                'root' => $parentRoot,
                'path' => [$segment],
            ];
        }

        $parentIdKey = $this->storedStructParentIdKey($struct);
        if ($parentIdKey === null) {
            return null;
        }

        if (isset($this->xmlNodesById[$parentIdKey])) {
            $parentLocation = $this->xmlNodeLocation($parentIdKey, $seen + [$targetIdKey => true]);
            $segment = $parentSub ?? $this->xmlNodeIndexInParent($parentIdKey, $targetIdKey);
        } else {
            $parentLocation = $this->nestedSharedTypeLocation($parentIdKey);
            $segment = $parentSub ?? $this->nestedSharedTypeIndexInNestedSequence($parentIdKey, $targetIdKey);
        }

        if ($parentLocation === null || $segment === null) {
            return null;
        }

        $parentLocation['path'][] = $segment;

        return $parentLocation;
    }

    private function xmlNodeIndexInParent(string $parentIdKey, string $targetIdKey): ?int
    {
        $index = 0;

        foreach ($this->xmlNodesById[$parentIdKey]['children'] ?? [] as $child) {
            if (! ($child['visible'] ?? false)) {
                continue;
            }

            $childIdKey = (string) $child['value'];
            if ($childIdKey === $targetIdKey) {
                return $index;
            }

            $index++;
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function xmlRootFragmentPath(string $targetIdKey): array
    {
        foreach ($this->xmlRootChildrenByName as $children) {
            $path = $this->xmlNodePathInChildren($children, $targetIdKey);
            if ($path !== null) {
                return $path;
            }
        }

        return [];
    }

    /**
     * @param list<array<string, mixed>> $children
     * @return list<int>|null
     */
    private function xmlNodePathInChildren(array $children, string $targetIdKey): ?array
    {
        $index = 0;

        foreach ($children as $child) {
            if (! $child['visible']) {
                continue;
            }

            $childIdKey = (string) $child['value'];
            if ($childIdKey === $targetIdKey) {
                return [$index];
            }

            $nested = $this->xmlNodePathInChildren($this->xmlNodesById[$childIdKey]['children'] ?? [], $targetIdKey);
            if ($nested !== null) {
                return array_merge([$index], $nested);
            }

            $index++;
        }

        return null;
    }

    /**
     * @return array{root: string, path: list<int|string>}|null
     */
    private function nestedSharedTypeLocation(string $idKey): ?array
    {
        if (! isset($this->structsById[$idKey])) {
            return null;
        }

        return $this->nestedSharedTypeLocationForStruct($this->structsById[$idKey]);
    }

    /**
     * @param array<string, mixed> $struct
     * @param array<string, true> $seen
     * @return array{root: string, path: list<int|string>}|null
     */
    private function nestedSharedTypeLocationForStruct(array $struct, array $seen = []): ?array
    {
        $structKey = self::idKey($struct['id']);
        if (isset($seen[$structKey])) {
            return null;
        }

        $parentSub = $this->storedStructParentSub($struct);
        $parent = $this->immediateNestedSharedTypeParent($struct, $seen + [$structKey => true]);
        if ($parent === null) {
            return null;
        }

        $segment = $parentSub ?? (
            isset($parent['root'])
                ? $this->nestedSharedTypeIndexInRootSequence($parent['root'], $structKey)
                : $this->nestedSharedTypeIndexInNestedSequence($parent['idKey'], $structKey)
        );

        if ($segment === null) {
            return null;
        }

        if (isset($parent['root'])) {
            return [
                'root' => $parent['root'],
                'path' => [$segment],
            ];
        }

        $parentLocation = $this->nestedSharedTypeLocationForStruct(
            $this->structsById[$parent['idKey']],
            $seen + [$structKey => true]
        );
        if ($parentLocation === null) {
            return null;
        }

        $parentLocation['path'][] = $segment;

        return $parentLocation;
    }

    /**
     * @param array<string, mixed> $struct
     * @param array<string, true> $seen
     * @return array{root: string}|array{idKey: string}|null
     */
    private function immediateNestedSharedTypeParent(array $struct, array $seen): ?array
    {
        if (is_string($struct['parent'])) {
            return ['root' => $struct['parent']];
        }

        if (is_array($struct['parent'])) {
            $idKey = self::idKey($struct['parent']);

            return isset($this->structsById[$idKey]) ? ['idKey' => $idKey] : null;
        }

        foreach (['origin', 'rightOrigin'] as $relation) {
            if (! is_array($struct[$relation])) {
                continue;
            }

            $parentStruct = $this->structContainingId($struct[$relation]);
            if ($parentStruct === null) {
                continue;
            }

            $parentKey = self::idKey($parentStruct['id']);
            if (isset($seen[$parentKey])) {
                continue;
            }

            $parent = $this->immediateNestedSharedTypeParent($parentStruct, $seen + [$parentKey => true]);
            if ($parent !== null) {
                return $parent;
            }
        }

        return null;
    }

    private function nestedSharedTypeIndexInRootSequence(string $name, string $idKey): ?int
    {
        $index = 0;

        foreach ($this->collectSequenceItemsForParent($name) as $item) {
            if (! $item['countsPosition']) {
                continue;
            }

            if ($item['idKey'] === $idKey) {
                return $item['visible'] ? $index : null;
            }

            if ($item['visible']) {
                $index++;
            }
        }

        return null;
    }

    private function nestedSharedTypeIndexInNestedSequence(string $parentIdKey, string $idKey): ?int
    {
        $index = 0;

        foreach ($this->collectSequenceItemsForParentId(self::idFromKey($parentIdKey)) as $item) {
            if (! $item['countsPosition']) {
                continue;
            }

            if ($item['idKey'] === $idKey) {
                return $item['visible'] ? $index : null;
            }

            if ($item['visible']) {
                $index++;
            }
        }

        return null;
    }

    /**
     * @param array<string, true> $changedIds
     * @param list<array<string, mixed>> $structs
     * @return array<string, true>
     */
    private function filterNewlyCreatedSharedTypeIds(array $changedIds, array $structs): array
    {
        if ($changedIds === []) {
            return [];
        }

        foreach ($structs as $struct) {
            if (
                ($struct['type'] ?? null) !== 'Item'
                || ($struct['content']['type'] ?? null) !== 'ContentType'
            ) {
                continue;
            }

            unset($changedIds[self::idKey($struct['id'])]);
        }

        return $changedIds;
    }

    /**
     * @param array<string, true> $changedNames
     * @param array<string, true> $changedNestedIds
     * @param array<string, true> $changedXmlIds
     * @return list<string>
     */
    private function transactionChangedTypeNames(array $changedNames, array $changedNestedIds, array $changedXmlIds): array
    {
        $typeNames = [];

        foreach (array_keys($changedNames) as $name) {
            $typeName = $this->rootSharedTypeName($name);
            if ($typeName !== null) {
                $typeNames[] = $typeName;
            }
        }

        foreach (array_keys($changedNestedIds) as $idKey) {
            $typeName = $this->nestedSequenceTypeName($idKey);
            if ($typeName !== null) {
                $typeNames[] = $typeName;
            }
        }

        foreach (array_keys($changedXmlIds) as $idKey) {
            $typeName = $this->xmlNodesById[$idKey]['typeName'] ?? null;
            if (is_string($typeName)) {
                $typeNames[] = $typeName;
            }
        }

        sort($typeNames, SORT_STRING);

        return $typeNames;
    }

    /**
     * @param array<string, true> $changedNames
     * @param array<string, true> $changedNestedIds
     * @param array<string, true> $changedXmlIds
     * @return list<string>
     */
    private function transactionChangedParentTypeNames(array $changedNames, array $changedNestedIds, array $changedXmlIds): array
    {
        $typeNamesByInstance = [];

        foreach (array_keys($changedNames) as $name) {
            $typeName = $this->rootSharedTypeName($name);
            if ($typeName !== null) {
                $typeNamesByInstance['root:' . $name] = $typeName;
            }
        }

        foreach (array_keys($changedNestedIds) as $idKey) {
            $typeNamesByInstance += $this->nestedSharedTypeParentTypeNameEntries($idKey);
        }

        foreach (array_keys($changedXmlIds) as $idKey) {
            $typeNamesByInstance += $this->xmlNodeParentTypeNameEntries($idKey);
        }

        $typeNames = array_values($typeNamesByInstance);
        sort($typeNames, SORT_STRING);

        return $typeNames;
    }

    private function rootSharedTypeName(string $name): ?string
    {
        if (array_key_exists($name, $this->xmlRootChildrenByName)) {
            return 'YXmlFragment';
        }

        return self::sharedTypeNameFromKind($this->rootSharedTypeKind($name, $this->json[$name] ?? null, $this->json[$name] ?? null));
    }

    private static function sharedTypeNameFromKind(?string $kind): ?string
    {
        return match ($kind) {
            'array' => 'YArray',
            'map' => 'YMap',
            'text' => 'YText',
            'xml' => 'YXmlFragment',
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    private function nestedSharedTypeParentTypeNameEntries(string $idKey): array
    {
        $typeName = $this->nestedSequenceTypeName($idKey);
        $typeNames = is_string($typeName) ? ['nested:' . $idKey => $typeName] : [];
        $struct = $this->structsById[$idKey] ?? null;
        if ($struct === null) {
            return $typeNames;
        }

        $parent = $this->immediateNestedSharedTypeParent($struct, []);
        if (isset($parent['root'])) {
            $rootTypeName = $this->rootSharedTypeName($parent['root']);
            if ($rootTypeName !== null) {
                $typeNames['root:' . $parent['root']] = $rootTypeName;
            }

            return $typeNames;
        }

        if (isset($parent['idKey'])) {
            $typeNames += isset($this->xmlNodesById[$parent['idKey']])
                ? $this->xmlNodeParentTypeNameEntries($parent['idKey'])
                : $this->nestedSharedTypeParentTypeNameEntries($parent['idKey']);
        }

        return $typeNames;
    }

    /**
     * @return array<string, string>
     */
    private function xmlNodeParentTypeNameEntries(string $idKey): array
    {
        $typeName = $this->xmlNodesById[$idKey]['typeName'] ?? null;
        $typeNames = is_string($typeName) ? ['xml:' . $idKey => $typeName] : [];
        $parent = $this->xmlNodeParentReference($idKey);

        if (isset($parent['root'])) {
            $typeNames['root:' . $parent['root']] = 'YXmlFragment';

            return $typeNames;
        }

        if (isset($parent['idKey'])) {
            $typeNames += $this->xmlNodeParentTypeNameEntries($parent['idKey']);

            return $typeNames;
        }

        $struct = $this->structsById[$idKey] ?? null;
        if ($struct === null) {
            return $typeNames;
        }

        $parentRoot = $this->storedStructParent($struct);
        if ($parentRoot !== null) {
            $rootTypeName = $this->rootSharedTypeName($parentRoot);
            if ($rootTypeName !== null) {
                $typeNames['root:' . $parentRoot] = $rootTypeName;
            }

            return $typeNames;
        }

        $parentIdKey = $this->storedStructParentIdKey($struct);
        if ($parentIdKey === null) {
            return $typeNames;
        }

        if (isset($this->xmlNodesById[$parentIdKey])) {
            $typeNames += $this->xmlNodeParentTypeNameEntries($parentIdKey);

            return $typeNames;
        }

        $typeNames += $this->nestedSharedTypeParentTypeNameEntries($parentIdKey);

        return $typeNames;
    }

    /**
     * @return array{root: string}|array{idKey: string}|null
     */
    private function xmlNodeParentReference(string $targetIdKey): ?array
    {
        foreach ($this->xmlRootChildrenByName as $name => $children) {
            $parent = $this->xmlNodeParentReferenceInChildren($children, $targetIdKey, ['root' => $name]);
            if ($parent !== null) {
                return $parent;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $children
     * @param array{root: string}|array{idKey: string} $parent
     * @return array{root: string}|array{idKey: string}|null
     */
    private function xmlNodeParentReferenceInChildren(array $children, string $targetIdKey, array $parent): ?array
    {
        foreach ($children as $child) {
            $childIdKey = (string) $child['value'];
            if ($childIdKey === $targetIdKey) {
                return $parent;
            }

            if (! isset($this->xmlNodesById[$childIdKey])) {
                continue;
            }

            $nested = $this->xmlNodeParentReferenceInChildren(
                $this->xmlNodesById[$childIdKey]['children'] ?? [],
                $targetIdKey,
                ['idKey' => $childIdKey]
            );
            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
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

        foreach ($prefix as $index => $value) {
            if (($path[$index] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    private function visibleXmlTreeContains(string $currentIdKey, string $targetIdKey): bool
    {
        if ($currentIdKey === $targetIdKey) {
            return true;
        }

        foreach ($this->xmlNodesById[$currentIdKey]['children'] ?? [] as $child) {
            if (! $child['visible']) {
                continue;
            }

            if ($this->visibleXmlTreeContains((string) $child['value'], $targetIdKey)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $structs
     * @param array<int, list<array{clock: int, length: int}>> $deleteSet
     * @return array<string, true>
     */
    private function topLevelNamesForChange(array $structs, array $deleteSet): array
    {
        $names = [];

        foreach ($structs as $struct) {
            if (($struct['type'] ?? null) !== 'Item') {
                continue;
            }

            $parent = $this->storedStructParent($struct);
            if ($parent !== null) {
                $names[$parent] = true;
            }
        }

        foreach ($deleteSet as $client => $deletes) {
            foreach ($deletes as $delete) {
                foreach ($this->structsById as $struct) {
                    if (($struct['type'] ?? null) !== 'Item' || $struct['id']['client'] !== $client) {
                        continue;
                    }

                    $structStart = $struct['id']['clock'];
                    $structEnd = $structStart + $struct['length'];
                    $deleteStart = $delete['clock'];
                    $deleteEnd = $deleteStart + $delete['length'];

                    if ($deleteStart >= $structEnd || $deleteEnd <= $structStart) {
                        continue;
                    }

                    $parent = $this->storedStructParent($struct);
                    if ($parent !== null) {
                        $names[$parent] = true;
                    }
                }
            }
        }

        return $names;
    }

    /**
     * @param list<array<string, mixed>> $structs
     * @param array<int, list<array{clock: int, length: int}>> $deleteSet
     * @return array<string, true>
     */
    private function directTopLevelNamesForChange(array $structs, array $deleteSet): array
    {
        $names = [];

        $record = function (array $struct) use (&$names): void {
            if (($struct['type'] ?? null) !== 'Item') {
                return;
            }

            $parent = $this->immediateNestedSharedTypeParent($struct, []);
            if (isset($parent['root'])) {
                $names[$parent['root']] = true;
            }
        };

        foreach ($structs as $struct) {
            $record($struct);
        }

        foreach ($deleteSet as $client => $deletes) {
            foreach ($deletes as $delete) {
                foreach ($this->structsById as $struct) {
                    if (($struct['type'] ?? null) !== 'Item' || $struct['id']['client'] !== $client) {
                        continue;
                    }

                    $structStart = $struct['id']['clock'];
                    $structEnd = $structStart + $struct['length'];
                    $deleteStart = $delete['clock'];
                    $deleteEnd = $deleteStart + $delete['length'];

                    if ($deleteStart >= $structEnd || $deleteEnd <= $structStart) {
                        continue;
                    }

                    $record($struct);
                }
            }
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $before
     * @return array<string, true>
     */
    private function changedRootNamesByValue(array $before, array $beforeIdentity): array
    {
        $names = [];

        foreach (array_keys($before + $this->json) as $name) {
            $oldComparableValue = array_key_exists($name, $beforeIdentity) ? $beforeIdentity[$name] : ($before[$name] ?? null);
            $newComparableValue = array_key_exists($name, $this->identityJson) ? $this->identityJson[$name] : ($this->json[$name] ?? null);

            if ($oldComparableValue !== $newComparableValue || array_key_exists($name, $before) !== array_key_exists($name, $this->json)) {
                $names[$name] = true;
            }
        }

        return $names;
    }

    /**
     * @param list<array<string, mixed>> $structs
     * @param array<int, list<array{clock: int, length: int}>> $deleteSet
     * @return array<string, true>
     */
    private function nestedTypeIdsForChange(array $structs, array $deleteSet): array
    {
        $ids = [];

        foreach ($structs as $struct) {
            if (($struct['type'] ?? null) !== 'Item') {
                continue;
            }

            $parentIdKey = $this->storedStructParentIdKey($struct);
            if ($parentIdKey !== null && ! isset($this->xmlNodesById[$parentIdKey])) {
                $ids[$parentIdKey] = true;
            }

            if (
                ($struct['content']['type'] ?? null) === 'ContentType'
                && self::isNestedSharedTypeName((string) ($struct['content']['typeName'] ?? ''))
            ) {
                $ids[self::idKey($struct['id'])] = true;
            }
        }

        foreach ($deleteSet as $client => $deletes) {
            foreach ($deletes as $delete) {
                foreach ($this->structsById as $struct) {
                    if (($struct['type'] ?? null) !== 'Item' || $struct['id']['client'] !== $client) {
                        continue;
                    }

                    $structStart = $struct['id']['clock'];
                    $structEnd = $structStart + $struct['length'];
                    $deleteStart = $delete['clock'];
                    $deleteEnd = $deleteStart + $delete['length'];

                    if ($deleteStart >= $structEnd || $deleteEnd <= $structStart) {
                        continue;
                    }

                    $parentIdKey = $this->storedStructParentIdKey($struct);
                    if ($parentIdKey !== null && ! isset($this->xmlNodesById[$parentIdKey])) {
                        $ids[$parentIdKey] = true;
                    }

                    if (
                        ($struct['content']['type'] ?? null) === 'ContentType'
                        && self::isNestedSharedTypeName((string) ($struct['content']['typeName'] ?? ''))
                    ) {
                        $ids[self::idKey($struct['id'])] = true;
                    }
                }
            }
        }

        return $ids;
    }

    /**
     * @param list<array<string, mixed>> $structs
     * @param array<int, list<array{clock: int, length: int}>> $deleteSet
     * @return array<string, true>
     */
    private function directNestedTypeIdsForChange(array $structs, array $deleteSet): array
    {
        $ids = [];

        $record = function (array $struct) use (&$ids): void {
            if (($struct['type'] ?? null) !== 'Item') {
                return;
            }

            $parentIdKey = $this->storedStructParentIdKey($struct);
            if ($parentIdKey !== null && ! isset($this->xmlNodesById[$parentIdKey])) {
                $ids[$parentIdKey] = true;
            }
        };

        foreach ($structs as $struct) {
            $record($struct);
        }

        foreach ($deleteSet as $client => $deletes) {
            foreach ($deletes as $delete) {
                foreach ($this->structsById as $struct) {
                    if (($struct['type'] ?? null) !== 'Item' || $struct['id']['client'] !== $client) {
                        continue;
                    }

                    $structStart = $struct['id']['clock'];
                    $structEnd = $structStart + $struct['length'];
                    $deleteStart = $delete['clock'];
                    $deleteEnd = $deleteStart + $delete['length'];

                    if ($deleteStart >= $structEnd || $deleteEnd <= $structStart) {
                        continue;
                    }

                    $record($struct);
                }
            }
        }

        return $ids;
    }

    /**
     * @param list<array<string, mixed>> $structs
     * @param array<int, list<array{clock: int, length: int}>> $deleteSet
     * @return array<string, true>
     */
    private function xmlNodeIdsForChange(array $structs, array $deleteSet): array
    {
        $ids = [];

        foreach ($structs as $struct) {
            if (($struct['type'] ?? null) !== 'Item') {
                continue;
            }

            $parentIdKey = $this->storedStructParentIdKey($struct);
            if ($parentIdKey !== null && isset($this->xmlNodesById[$parentIdKey])) {
                $ids[$parentIdKey] = true;
            }

            if (
                ($struct['content']['type'] ?? null) === 'ContentType'
                && self::isXmlTypeName((string) ($struct['content']['typeName'] ?? ''))
            ) {
                $idKey = self::idKey($struct['id']);
                if (isset($this->xmlNodesById[$idKey])) {
                    $ids[$idKey] = true;
                }
            }
        }

        foreach ($deleteSet as $client => $deletes) {
            foreach ($deletes as $delete) {
                foreach ($this->structsById as $struct) {
                    if (($struct['type'] ?? null) !== 'Item' || $struct['id']['client'] !== $client) {
                        continue;
                    }

                    $structStart = $struct['id']['clock'];
                    $structEnd = $structStart + $struct['length'];
                    $deleteStart = $delete['clock'];
                    $deleteEnd = $deleteStart + $delete['length'];

                    if ($deleteStart >= $structEnd || $deleteEnd <= $structStart) {
                        continue;
                    }

                    $parentIdKey = $this->storedStructParentIdKey($struct);
                    if ($parentIdKey !== null && isset($this->xmlNodesById[$parentIdKey])) {
                        $ids[$parentIdKey] = true;
                    }

                    if (
                        ($struct['content']['type'] ?? null) === 'ContentType'
                        && self::isXmlTypeName((string) ($struct['content']['typeName'] ?? ''))
                    ) {
                        $idKey = self::idKey($struct['id']);
                        if (isset($this->xmlNodesById[$idKey])) {
                            $ids[$idKey] = true;
                        }
                    }
                }
            }
        }

        return $ids;
    }

    /**
     * @param list<array<string, mixed>> $structs
     * @param array<int, list<array{clock: int, length: int}>> $deleteSet
     * @return array<string, true>
     */
    private function directXmlNodeIdsForChange(array $structs, array $deleteSet): array
    {
        $ids = [];

        $record = function (array $struct) use (&$ids): void {
            if (($struct['type'] ?? null) !== 'Item') {
                return;
            }

            $parentIdKey = $this->storedStructParentIdKey($struct);
            if ($parentIdKey !== null && isset($this->xmlNodesById[$parentIdKey])) {
                $ids[$parentIdKey] = true;
            }
        };

        foreach ($structs as $struct) {
            $record($struct);
        }

        foreach ($deleteSet as $client => $deletes) {
            foreach ($deletes as $delete) {
                foreach ($this->structsById as $struct) {
                    if (($struct['type'] ?? null) !== 'Item' || $struct['id']['client'] !== $client) {
                        continue;
                    }

                    $structStart = $struct['id']['clock'];
                    $structEnd = $structStart + $struct['length'];
                    $deleteStart = $delete['clock'];
                    $deleteEnd = $deleteStart + $delete['length'];

                    if ($deleteStart >= $structEnd || $deleteEnd <= $structStart) {
                        continue;
                    }

                    $record($struct);
                }
            }
        }

        return $ids;
    }

    /**
     * @param list<array<string, mixed>> $structs
     * @param array<int, list<array{clock: int, length: int}>> $deleteSet
     * @return array<string, array<string, true>>
     */
    private function xmlAttributeNamesForChange(array $structs, array $deleteSet): array
    {
        $attributes = [];

        $record = function (array $struct) use (&$attributes): void {
            if (($struct['type'] ?? null) !== 'Item') {
                return;
            }

            $parentIdKey = $this->storedStructParentIdKey($struct);
            $parentSub = $this->storedStructParentSub($struct);
            if ($parentIdKey === null || $parentSub === null || ! isset($this->xmlNodesById[$parentIdKey])) {
                return;
            }

            if (! in_array($this->xmlNodesById[$parentIdKey]['typeName'] ?? null, ['YXmlElement', 'YXmlHook', 'YXmlText'], true)) {
                return;
            }

            $attributes[$parentIdKey][$parentSub] = true;
        };

        foreach ($structs as $struct) {
            $record($struct);
        }

        foreach ($deleteSet as $client => $deletes) {
            foreach ($deletes as $delete) {
                foreach ($this->structsById as $struct) {
                    if (($struct['type'] ?? null) !== 'Item' || $struct['id']['client'] !== $client) {
                        continue;
                    }

                    $structStart = $struct['id']['clock'];
                    $structEnd = $structStart + $struct['length'];
                    $deleteStart = $delete['clock'];
                    $deleteEnd = $deleteStart + $delete['length'];

                    if ($deleteStart >= $structEnd || $deleteEnd <= $structStart) {
                        continue;
                    }

                    $record($struct);
                }
            }
        }

        return $attributes;
    }

    private function currentTransactionOrigin(): mixed
    {
        if ($this->transactionOriginStack === []) {
            return null;
        }

        return $this->transactionOriginStack[array_key_last($this->transactionOriginStack)];
    }

    /**
     * @param list<int> $deletedOffsets
     */
    private function removeDeletedUtf8Characters(string $value, array $deletedOffsets): string
    {
        if ($deletedOffsets === [] || $value === '') {
            return $value;
        }

        $result = '';
        $offset = 0;

        foreach (self::utf8Characters($value) as $character) {
            $width = self::utf16CodeUnitLength($character);
            $isDeleted = false;

            for ($i = 0; $i < $width; $i++) {
                if (in_array($offset + $i, $deletedOffsets, true)) {
                    $isDeleted = true;
                    break;
                }
            }

            if (! $isDeleted) {
                $result .= $character;
            }

            $offset += $width;
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private static function utf8Characters(string $value): array
    {
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            throw new \UnexpectedValueException('Failed to split UTF-8 string.');
        }

        return $characters;
    }

    /**
     * @param array{client: int, clock: int} $left
     * @param array{client: int, clock: int} $right
     */
    private static function compareIds(array $left, array $right): int
    {
        return [$left['client'], $left['clock']] <=> [$right['client'], $right['clock']];
    }

    private static function utf16CodeUnitLength(string $value): int
    {
        if ($value === '') {
            return 0;
        }

        return intdiv(strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }

    private static function sliceUtf16CodeUnits(string $value, int $offset, int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        return self::utf16UnitsToString(array_slice(self::utf16StringToUnits($value), $offset, $length));
    }

    /**
     * @return list<int>
     */
    private static function utf16StringToUnits(string $value): array
    {
        $units = [];
        foreach (self::utf8Characters($value) as $character) {
            foreach (self::utf16CodeUnitsForCharacter($character) as $unit) {
                $units[] = $unit;
            }
        }

        return $units;
    }

    /**
     * @return list<int>
     */
    private static function utf16CodeUnitsForCharacter(string $value): array
    {
        $codepoint = mb_ord($value, 'UTF-8');
        if ($codepoint === false) {
            throw new \UnexpectedValueException('Failed to read UTF-8 codepoint.');
        }

        if ($codepoint <= 0xffff) {
            return [$codepoint];
        }

        $codepoint -= 0x10000;

        return [
            0xd800 + intdiv($codepoint, 0x400),
            0xdc00 + ($codepoint % 0x400),
        ];
    }

    /**
     * @param list<int> $units
     */
    private static function utf16UnitsToString(array $units): string
    {
        $text = '';
        $count = count($units);

        for ($i = 0; $i < $count; $i++) {
            $unit = $units[$i];

            if ($unit >= 0xd800 && $unit <= 0xdbff) {
                $next = $units[$i + 1] ?? null;
                if (is_int($next) && $next >= 0xdc00 && $next <= 0xdfff) {
                    $codepoint = 0x10000 + (($unit - 0xd800) * 0x400) + ($next - 0xdc00);
                    $text .= mb_chr($codepoint, 'UTF-8');
                    $i++;
                    continue;
                }

                $text .= "\u{FFFD}";
                continue;
            }

            if ($unit >= 0xdc00 && $unit <= 0xdfff) {
                $text .= "\u{FFFD}";
                continue;
            }

            $text .= mb_chr($unit, 'UTF-8');
        }

        return $text;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function structsForEncoding(): array
    {
        if (! $this->gc) {
            return $this->sortedStructs();
        }

        $structs = [];
        foreach ($this->sortedStructs() as $struct) {
            array_push($structs, ...$this->gcStructSegments($struct));
        }

        return $structs;
    }

    /**
     * @param array<string, mixed> $struct
     * @return list<array<string, mixed>>
     */
    private function gcStructSegments(array $struct): array
    {
        if (($struct['type'] ?? null) !== 'Item') {
            return [$struct];
        }

        $deletedOffsets = array_fill_keys($this->deletedOffsets($struct, $this->deleteSet), true);
        if ($deletedOffsets === []) {
            return [$struct];
        }

        $segments = [];
        $length = $struct['length'];
        $offset = 0;

        while ($offset < $length) {
            $deleted = isset($deletedOffsets[$offset]);
            $segmentLength = 1;

            while (
                $offset + $segmentLength < $length
                && isset($deletedOffsets[$offset + $segmentLength]) === $deleted
            ) {
                $segmentLength++;
            }

            $segments[] = $this->gcStructSegment($struct, $offset, $segmentLength, $deleted);
            $offset += $segmentLength;
        }

        return $segments;
    }

    /**
     * @param array<string, mixed> $struct
     * @return array<string, mixed>
     */
    private function gcStructSegment(array $struct, int $offset, int $length, bool $deleted): array
    {
        $segment = $struct;
        $segment['id'] = [
            'client' => $struct['id']['client'],
            'clock' => $struct['id']['clock'] + $offset,
        ];
        $segment['length'] = $length;
        $segment['origin'] = $offset === 0 ? $struct['origin'] : [
            'client' => $struct['id']['client'],
            'clock' => $struct['id']['clock'] + $offset - 1,
        ];
        $segment['rightOrigin'] = $offset + $length === $struct['length'] ? $struct['rightOrigin'] : null;
        $segment['parent'] = $offset === 0 ? $struct['parent'] : null;
        $segment['parentSub'] = $offset === 0 ? $struct['parentSub'] : null;
        $segment['content'] = $deleted
            ? ['type' => 'ContentDeleted', 'length' => $length]
            : $this->contentSegment($struct['content'], $offset, $length);

        return $segment;
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    private function contentSegment(array $content, int $offset, int $length): array
    {
        $contentLength = $this->contentLength($content);
        if ($offset === 0 && $length === $contentLength) {
            return $content;
        }

        $segment = $content;

        switch ($content['type']) {
            case 'ContentString':
                $segment['value'] = self::sliceUtf16CodeUnits((string) $content['value'], $offset, $length);
                break;
            case 'ContentAny':
            case 'ContentJSON':
                $segment['values'] = array_slice($content['values'], $offset, $length);
                break;
            case 'ContentDeleted':
                $segment['length'] = $length;
                break;
            default:
                throw new \UnexpectedValueException(sprintf('Cannot split content type "%s" for GC encoding.', (string) $content['type']));
        }

        return $segment;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sortedStructs(): array
    {
        $structs = array_values($this->structsById);

        usort(
            $structs,
            static fn (array $left, array $right): int => [$left['id']['client'], $left['id']['clock']] <=> [$right['id']['client'], $right['id']['clock']]
        );

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
     * @return array{client: int, clock: int}
     */
    private static function idFromKey(string $key): array
    {
        [$client, $clock] = array_map('intval', explode(':', $key, 2));

        return [
            'client' => $client,
            'clock' => $clock,
        ];
    }
}
