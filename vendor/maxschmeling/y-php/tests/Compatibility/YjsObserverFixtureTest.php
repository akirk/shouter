<?php

declare(strict_types=1);

namespace Yjs\Tests\Compatibility;

use PHPUnit\Framework\TestCase;
use Yjs\YDoc;
use Yjs\YSubdoc;

final class YjsObserverFixtureTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__ . '/../../fixtures/generated/yjs-13.6.31/observer-events.json';

    public function testSharedTypeObserverEventsExposeYjsCompatibleChangeSubset(): void
    {
        foreach ($this->loadFixture()['cases'] as $case) {
            $events = [];
            $doc = new YDoc(170);

            match ($case['name']) {
                'text-insert-delete-insert' => $this->captureTextEvent($doc, $events),
                'text-format-range' => $this->captureTextFormatEvent($doc, $events),
                'array-insert-delete-insert' => $this->captureArrayEvent($doc, $events),
                'map-add-key' => $this->captureMapAddEvent($doc, $events),
                'map-update-key' => $this->captureMapUpdateEvent($doc, $events),
                'map-delete-key' => $this->captureMapDeleteEvent($doc, $events),
                'nested-array-insert-delete-insert' => $this->captureNestedArrayEvent($doc, $events),
                'nested-map-update-key' => $this->captureNestedMapEvent($doc, $events),
                'nested-text-attribute-update-key' => $this->captureNestedTextAttributeEvent($doc, $events),
                'nested-xml-fragment-insert-delete-insert' => $this->captureNestedXmlFragmentEvent($doc, $events),
                'xml-fragment-insert-delete-insert' => $this->captureXmlFragmentEvent($doc, $events),
                'xml-fragment-hook-insert-delete' => $this->captureXmlFragmentHookEvent($doc, $events),
                'xml-element-attribute-update' => $this->captureXmlElementAttributeEvent($doc, $events),
                'xml-element-attribute-add-key' => $this->captureXmlElementAttributeAddEvent($doc, $events),
                'xml-element-attribute-update-key' => $this->captureXmlElementAttributeUpdateEvent($doc, $events),
                'xml-element-attribute-delete-key' => $this->captureXmlElementAttributeDeleteEvent($doc, $events),
                'xml-element-child-insert-delete' => $this->captureXmlElementChildEvent($doc, $events),
                'xml-element-child-replace-middle' => $this->captureXmlElementChildReplaceMiddleEvent($doc, $events),
                'xml-element-child-and-text-update' => $this->captureXmlElementChildAndTextUpdateEvent($doc, $events),
                'xml-fragment-delete-middle-range' => $this->captureXmlFragmentDeleteMiddleRangeEvent($doc, $events),
                'xml-fragment-insert-multiple-elements' => $this->captureXmlFragmentInsertMultipleElementsEvent($doc, $events),
                'xml-element-insert-multiple-child-types' => $this->captureXmlElementInsertMultipleChildTypesEvent($doc, $events),
                'xml-element-hook-insert-delete' => $this->captureXmlElementHookEvent($doc, $events),
                'xml-hook-map-update-key' => $this->captureXmlHookMapEvent($doc, $events),
                'xml-hook-map-add-update-delete-keys' => $this->captureXmlHookMapAddUpdateDeleteEvent($doc, $events),
                'xml-text-format-range' => $this->captureXmlTextFormatEvent($doc, $events),
                'xml-text-attribute-update-key' => $this->captureXmlTextAttributeEvent($doc, $events),
                'xml-text-shared-attribute-update-key' => $this->captureXmlTextSharedAttributeEvent($doc, $events),
                default => throw new \UnexpectedValueException(sprintf('Unknown observer fixture "%s".', $case['name'])),
            };

            self::assertCount(1, $events, sprintf('Failed observer event count for "%s".', $case['name']));
            self::assertSame($case['event'], $events[0], sprintf('Failed observer event for "%s".', $case['name']));
        }
    }

    public function testDeepXmlObserverPathsMatchYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc(170);
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $text = $paragraph->insertText(0, 'Hi');
        $events = [];

        $doc->observeDeep(static function (array $deepEvents) use (&$events): void {
            foreach ($deepEvents as $event) {
                if (($event['target'] ?? null) !== 'xml') {
                    continue;
                }

                $events[] = [
                    'targetType' => $event['typeName'],
                    'path' => $event['path'],
                    'changes' => $event['changes'],
                ];
            }
        });

        $doc->transact(static function () use ($paragraph, $text): void {
            $paragraph->setAttribute('class', 'lead');
            $text->insert(2, '!');
        }, 'xml-deep-observer-paths-origin');

        usort($events, static fn (array $left, array $right): int => $left['targetType'] <=> $right['targetType']);

        self::assertSame($fixture['deepXmlPaths'], $events);
    }

    public function testArrayObserveDeepMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc(170);
        $array = $doc->getArray('array');
        $map = $array->insertMap(0);
        $child = $map->setArray('child');
        $events = [];
        $origins = [];

        $observerId = $array->observeDeep(static function (array $deepEvents, mixed $observedArray, array $transaction) use (&$events, &$origins, $array): void {
            self::assertSame($array, $observedArray);
            $origins[] = $transaction['origin'];

            foreach ($deepEvents as $event) {
                $events[] = self::deepSharedTypeEventSubset($event);
            }
        });

        $doc->transact(static function () use ($map, $child): void {
            $map->set('title', 'A');
        }, 'array-deep-observer-paths-origin');

        $array->unobserveDeep($observerId);
        $child->insert(0, ['ignored']);

        usort($events, static fn (array $left, array $right): int => $left['targetType'] <=> $right['targetType']);

        self::assertSame(['array-deep-observer-paths-origin'], $origins);
        self::assertSame($fixture['deepArrayPaths'], $events);
    }

    public function testMapObserveDeepMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc(170);
        $map = $doc->getMap('map');
        $array = $map->setArray('items');
        $child = $array->insertMap(0);
        $events = [];
        $origins = [];

        $observerId = $map->observeDeep(static function (array $deepEvents, mixed $observedMap, array $transaction) use (&$events, &$origins, $map): void {
            self::assertSame($map, $observedMap);
            $origins[] = $transaction['origin'];

            foreach ($deepEvents as $event) {
                $events[] = self::deepSharedTypeEventSubset($event);
            }
        });

        $doc->transact(static function () use ($array, $child): void {
            $child->set('title', 'A');
        }, 'map-deep-observer-paths-origin');

        $map->unobserveDeep($observerId);
        $array->insert(1, ['ignored']);

        usort($events, static fn (array $left, array $right): int => $left['targetType'] <=> $right['targetType']);

        self::assertSame(['map-deep-observer-paths-origin'], $origins);
        self::assertSame($fixture['deepMapPaths'], $events);
    }

    public function testMapNestedTextObserveDeepMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc(170);
        $map = $doc->getMap('map');
        $text = $map->setText('body');
        $text->insert(0, 'Map');
        $events = [];
        $origins = [];

        $observerId = $map->observeDeep(static function (array $deepEvents, mixed $observedMap, array $transaction) use (&$events, &$origins, $map): void {
            self::assertSame($map, $observedMap);
            $origins[] = $transaction['origin'];

            foreach ($deepEvents as $event) {
                $events[] = self::deepSharedTypeEventSubset($event);
            }
        });

        $doc->transact(static function () use ($text): void {
            $text->setAttribute('lang', 'en');
            $text->insert(3, ' text', ['emphasis' => true]);
        }, 'map-nested-text-deep-observer-paths-origin');

        $map->unobserveDeep($observerId);
        $text->insert(8, ' ignored');

        usort($events, static fn (array $left, array $right): int => $left['targetType'] <=> $right['targetType']);

        self::assertSame(['map-nested-text-deep-observer-paths-origin'], $origins);
        self::assertSame($fixture['deepMapNestedTextPaths'], $events);
    }

    public function testMapNestedXmlFragmentObserveDeepMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc(170);
        $map = $doc->getMap('map');
        $fragment = $map->setXmlFragment('xml');
        $text = $fragment->insertText(0, 'A');
        $paragraph = $fragment->insertElement(1, 'p');
        $paragraph->appendText('C');
        $events = [];
        $origins = [];

        $observerId = $map->observeDeep(static function (array $deepEvents, mixed $observedMap, array $transaction) use (&$events, &$origins, $map): void {
            self::assertSame($map, $observedMap);
            $origins[] = $transaction['origin'];

            foreach ($deepEvents as $event) {
                $events[] = self::deepSharedTypeEventSubset($event);
            }
        });

        $doc->transact(static function () use ($fragment, $paragraph, $text): void {
            $text->insert(1, '!');
            $paragraph->setAttribute('class', 'lead');
            $fragment->insertElement(2, 'br');
        }, 'map-nested-xml-fragment-deep-observer-paths-origin');

        $map->unobserveDeep($observerId);
        $fragment->insertText(3, 'ignored');

        usort($events, static function (array $left, array $right): int {
            $typeOrder = $left['targetType'] <=> $right['targetType'];

            return $typeOrder === 0 ? json_encode($left['path']) <=> json_encode($right['path']) : $typeOrder;
        });

        self::assertSame(['map-nested-xml-fragment-deep-observer-paths-origin'], $origins);
        self::assertSame($fixture['deepMapNestedXmlFragmentPaths'], $events);
    }

    public function testMapNestedMapTextObserveDeepMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc(170);
        $map = $doc->getMap('map');
        $root = $map->setMap('root');
        $text = $root->setText('body');
        $text->insert(0, 'Deep');
        $root->set('status', 'draft');
        $events = [];
        $origins = [];

        $observerId = $map->observeDeep(static function (array $deepEvents, mixed $observedMap, array $transaction) use (&$events, &$origins, $map): void {
            self::assertSame($map, $observedMap);
            $origins[] = $transaction['origin'];

            foreach ($deepEvents as $event) {
                $events[] = self::deepSharedTypeEventSubset($event);
            }
        });

        $doc->transact(static function () use ($root, $text): void {
            $root->set('status', 'published');
            $text->setAttribute('lang', 'en');
            $text->insert(4, ' text', ['bold' => true]);
        }, 'map-nested-map-text-deep-observer-paths-origin');

        $map->unobserveDeep($observerId);
        $text->insert(9, ' ignored');

        usort($events, static function (array $left, array $right): int {
            $typeOrder = $left['targetType'] <=> $right['targetType'];

            return $typeOrder === 0 ? json_encode($left['path']) <=> json_encode($right['path']) : $typeOrder;
        });

        self::assertSame(['map-nested-map-text-deep-observer-paths-origin'], $origins);
        self::assertSame($fixture['deepMapNestedMapTextPaths'], $events);
    }

    public function testTextObserveDeepMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc(170);
        $text = $doc->getText('text');
        $events = [];

        $observerId = $text->observeDeep(static function (array $deepEvents, mixed $observedText) use (&$events, $text): void {
            self::assertSame($text, $observedText);

            foreach ($deepEvents as $event) {
                $events[] = self::deepSharedTypeEventSubset($event);
            }
        });

        $doc->transact(static function () use ($text): void {
            $text->insert(0, 'Hi');
        }, 'text-deep-observer-paths-origin');

        $text->unobserveDeep($observerId);
        $text->insert(2, '!');

        self::assertSame($fixture['deepTextPaths'], $events);
    }

    public function testObserverAddedDuringDispatchMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc(170);
        $text = $doc->getText('content');
        $calls = [];

        $text->observe(static function () use (&$calls, $text): void {
            $calls[] = 'first';
            $text->observe(static function () use (&$calls): void {
                $calls[] = 'third';
            });
        });
        $text->observe(static function () use (&$calls): void {
            $calls[] = 'second';
        });

        $text->insert(0, 'A');
        $text->insert(1, 'B');

        self::assertSame($fixture['observerAddedDuringDispatchCalls'], $calls);
    }

    public function testReentrantTextMutationNotificationOrderMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture()['reentrantTextMutation'];
        $doc = new YDoc(179);
        $text = $doc->getText('content');
        $notificationOrder = [];
        $observerEvents = [];

        $text->observe(static function (array $event) use (&$notificationOrder, &$observerEvents, $text): void {
            $notificationOrder[] = 'observe:' . ($event['origin'] ?? 'null');
            $observerEvents[] = [
                'value' => $text->toString(),
                'origin' => $event['origin'],
            ];

            if ($text->toString() === 'A') {
                $text->insert(1, 'B');
            }
        });
        $doc->observeTransaction(static function (array $event) use (&$notificationOrder): void {
            $notificationOrder[] = 'afterTransaction:' . ($event['origin'] ?? 'null');
        });
        $doc->observeUpdate(static function (string $update, YDoc $doc, mixed $origin) use (&$notificationOrder): void {
            $notificationOrder[] = 'update:' . ($origin ?? 'null');
        });

        $doc->transact(static function () use ($text): void {
            $text->insert(0, 'A');
        }, 'reentrant-text-mutation-origin');

        self::assertSame($fixture['json'], $doc->toJSON());
        self::assertSame($fixture['notificationOrderPrefix'], array_slice($notificationOrder, 0, 3));
        self::assertSame($fixture['observerEvents'], $observerEvents);
    }

    public function testDocumentLifecycleOrderMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc(180);
        $text = $doc->getText('content');
        $order = [];
        $originFor = static fn (array $transaction): string => $transaction['origin'] ?? 'null';

        $doc->on('beforeAllTransactions', static function () use (&$order): void {
            $order[] = 'beforeAllTransactions';
        });
        $doc->on('beforeTransaction', static function (array $transaction) use (&$order, $originFor): void {
            $order[] = 'beforeTransaction:' . $originFor($transaction);
        });
        $doc->on('beforeObserverCalls', static function (array $transaction) use (&$order, $originFor): void {
            $order[] = 'beforeObserverCalls:' . $originFor($transaction);
        });
        $text->observe(static function (array $event) use (&$order, $originFor): void {
            $order[] = 'observe:' . $originFor($event);
        });
        $doc->on('afterTransaction', static function (array $transaction) use (&$order, $originFor): void {
            $order[] = 'afterTransaction:' . $originFor($transaction);
        });
        $doc->on('afterTransactionCleanup', static function (array $transaction) use (&$order, $originFor): void {
            $order[] = 'afterTransactionCleanup:' . $originFor($transaction);
        });
        $doc->on('update', static function (string $update, mixed $origin) use (&$order): void {
            $order[] = 'update:' . ($origin ?? 'null');
        });
        $doc->on('updateV2', static function (string $update, mixed $origin) use (&$order): void {
            $order[] = 'updateV2:' . ($origin ?? 'null');
        });
        $doc->on('afterAllTransactions', static function () use (&$order): void {
            $order[] = 'afterAllTransactions';
        });

        $doc->transact(static function () use ($text): void {
            $text->insert(0, 'A');
        }, 'lifecycle-origin');

        self::assertSame($fixture['documentLifecycleOrder'], $order);
    }

    public function testRemoteDocumentLifecycleOrderMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture()['remoteDocumentLifecycleOrder'];
        $source = new YDoc(181);
        $source->getText('content')->insert(0, 'A');
        $update = $source->encodeStateAsUpdateV1();
        $doc = new YDoc(182);
        $text = $doc->getText('content');
        $orders = [
            'firstApply' => [],
            'duplicateApply' => [],
        ];
        $activeOrder = 'firstApply';
        $originFor = static fn (array $transaction): string => $transaction['origin'] ?? 'null';

        $doc->on('beforeAllTransactions', static function () use (&$orders, &$activeOrder): void {
            $orders[$activeOrder][] = 'beforeAllTransactions';
        });
        $doc->on('beforeTransaction', static function (array $transaction) use (&$orders, &$activeOrder, $originFor): void {
            $orders[$activeOrder][] = 'beforeTransaction:' . $originFor($transaction);
        });
        $doc->on('beforeObserverCalls', static function (array $transaction) use (&$orders, &$activeOrder, $originFor): void {
            $orders[$activeOrder][] = 'beforeObserverCalls:' . $originFor($transaction);
        });
        $text->observe(static function (array $event) use (&$orders, &$activeOrder, $originFor): void {
            $orders[$activeOrder][] = 'observe:' . $originFor($event);
        });
        $doc->on('afterTransaction', static function (array $transaction) use (&$orders, &$activeOrder, $originFor): void {
            $orders[$activeOrder][] = 'afterTransaction:' . $originFor($transaction);
        });
        $doc->on('afterTransactionCleanup', static function (array $transaction) use (&$orders, &$activeOrder, $originFor): void {
            $orders[$activeOrder][] = 'afterTransactionCleanup:' . $originFor($transaction);
        });
        $doc->on('update', static function (string $update, mixed $origin) use (&$orders, &$activeOrder): void {
            $orders[$activeOrder][] = 'update:' . ($origin ?? 'null');
        });
        $doc->on('updateV2', static function (string $update, mixed $origin) use (&$orders, &$activeOrder): void {
            $orders[$activeOrder][] = 'updateV2:' . ($origin ?? 'null');
        });
        $doc->on('afterAllTransactions', static function () use (&$orders, &$activeOrder): void {
            $orders[$activeOrder][] = 'afterAllTransactions';
        });

        $doc->applyUpdateV1($update, 'remote-lifecycle-origin');
        $activeOrder = 'duplicateApply';
        $doc->applyUpdateV1($update, 'remote-duplicate-lifecycle-origin');

        self::assertSame($fixture['firstApply'], $orders['firstApply']);
        self::assertSame($fixture['duplicateApply'], $orders['duplicateApply']);
    }

    public function testSubdocEventsMatchYjsFixture(): void
    {
        $fixture = $this->loadFixture()['subdocEvents'];
        $doc = new YDoc(183);
        $array = $doc->getArray('array');
        $map = $doc->getMap('map');
        $events = [];
        $order = [];
        $arrayChild = null;
        $transactionalChild = null;

        $doc->on('update', static function (string $update, mixed $origin) use (&$order): void {
            $order[] = 'update:' . ($origin ?? 'null');
        });
        $doc->on('updateV2', static function (string $update, mixed $origin) use (&$order): void {
            $order[] = 'updateV2:' . ($origin ?? 'null');
        });
        $doc->on('subdocs', function (array $event, YDoc $observedDoc, array $transaction) use (&$events, &$order, $doc): void {
            self::assertSame($doc, $observedDoc);
            $order[] = 'subdocs:' . ($transaction['origin'] ?? 'null');
            $events[] = self::subdocEventSubset($event, $observedDoc, $transaction);
        });
        $doc->on('afterAllTransactions', static function () use (&$order): void {
            $order[] = 'afterAllTransactions';
        });

        $doc->transact(static function () use ($array, $map, &$arrayChild, &$transactionalChild): void {
            $arrayChild = $array->insertSubdoc(0, 'array-child', ['meta' => ['scope' => 'array'], 'shouldLoad' => false]);
            $transactionalChild = $array->insertSubdoc(1, 'transactional-child', ['meta' => ['scope' => 'transactional'], 'shouldLoad' => false]);
            $map->setSubdoc('child', 'map-child', ['meta' => ['scope' => 'map'], 'autoLoad' => true]);
        }, 'subdoc-add-origin');

        self::assertInstanceOf(YSubdoc::class, $arrayChild);
        self::assertInstanceOf(YSubdoc::class, $transactionalChild);
        $arrayChild->load();
        self::assertTrue($arrayChild->shouldLoad());

        $doc->transact(static function () use ($doc, $transactionalChild): void {
            $transactionalChild->load();
            $doc->getText('content')->insert(0, 'L');
        }, 'subdoc-load-transaction-origin');
        self::assertTrue($transactionalChild->shouldLoad());

        $doc->transact(static function () use ($array): void {
            $array->insertSubdoc(1, 'transient-child', ['meta' => ['scope' => 'transient'], 'autoLoad' => true]);
            $array->delete(1, 1);
        }, 'subdoc-add-delete-same-origin');

        $doc->transact(static function () use ($map): void {
            $map->delete('child');
        }, 'subdoc-remove-origin');

        self::assertSame($fixture['order'], $order);
        self::assertSame($fixture['events'], $events);
        self::assertSame($fixture['liveGuids'], $doc->getSubdocGuids());
    }

    public function testRemoteSubdocEventsMatchYjsFixture(): void
    {
        $fixture = $this->loadFixture()['remoteSubdocEvents'];
        $source = new YDoc(184);
        $source->getArray('array')->insertSubdoc(0, 'remote-array-child', ['meta' => ['source' => 'array'], 'autoLoad' => true]);
        $source->getMap('map')->setSubdoc('child', 'remote-map-child', ['meta' => ['source' => 'map'], 'shouldLoad' => false]);
        $update = $source->encodeStateAsUpdateV1();
        $doc = new YDoc(185);
        $doc->getArray('array');
        $doc->getMap('map');
        $events = [
            'firstApply' => [],
            'duplicateApply' => [],
        ];
        $activeEvents = 'firstApply';

        $doc->on('subdocs', function (array $event, YDoc $observedDoc, array $transaction) use (&$events, &$activeEvents, $doc): void {
            self::assertSame($doc, $observedDoc);
            $events[$activeEvents][] = self::subdocEventSubset($event, $observedDoc, $transaction);
        });

        $doc->applyUpdateV1($update, 'remote-subdoc-origin');
        $activeEvents = 'duplicateApply';
        $doc->applyUpdateV1($update, 'remote-subdoc-duplicate-origin');

        self::assertSame($fixture['firstApply'], $events['firstApply']);
        self::assertSame($fixture['duplicateApply'], $events['duplicateApply']);
        self::assertSame($fixture['liveGuids'], $doc->getSubdocGuids());
    }

    public function testRootArrayObserverIgnoresNestedChildContentChangesInSameTransaction(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc(170);
        $array = $doc->getArray('array');
        $map = $array->insertMap(0);
        $child = $map->setArray('child');
        $events = [];

        $array->observe(static function (array $event) use (&$events): void {
            $events[] = self::eventSubset($event);
        });

        $doc->transact(static function () use ($array, $child): void {
            $array->insert(1, ['tail']);
            $child->insert(0, ['child']);
        }, 'array-parent-child-observer-origin');

        self::assertSame([$fixture['rootArrayParentAndChildEvent']], $events);
    }

    public function testRootMapObserverIgnoresNestedChildContentChangesInSameTransaction(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc(170);
        $map = $doc->getMap('map');
        $child = $map->setArray('child');
        $events = [];

        $map->observe(static function (array $event) use (&$events): void {
            $events[] = self::eventSubset($event);
        });

        $doc->transact(static function () use ($map, $child): void {
            $map->set('title', 'parent');
            $child->insert(0, ['child']);
        }, 'map-parent-child-observer-origin');

        self::assertSame([$fixture['rootMapParentAndChildEvent']], $events);
    }

    public function testNestedArrayObserveDeepMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc(170);
        $array = $doc->getArray('array')->insertArray(0);
        $child = $array->insertMap(0);
        $events = [];
        $origins = [];

        $observerId = $array->observeDeep(static function (array $deepEvents, mixed $observedArray, array $transaction) use (&$events, &$origins, $array): void {
            self::assertSame($array, $observedArray);
            $origins[] = $transaction['origin'];

            foreach ($deepEvents as $event) {
                $events[] = self::deepSharedTypeEventSubset($event);
            }
        });

        $doc->transact(static function () use ($array, $child): void {
            $array->insert(1, ['x']);
        }, 'nested-array-deep-observer-paths-origin');

        $array->unobserveDeep($observerId);
        $array->insert(2, ['ignored']);

        usort($events, static fn (array $left, array $right): int => $left['targetType'] <=> $right['targetType']);

        self::assertSame(['nested-array-deep-observer-paths-origin'], $origins);
        self::assertSame($fixture['deepNestedArrayPaths'], $events);
    }

    public function testNestedMapObserveDeepMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc(170);
        $map = $doc->getArray('array')->insertMap(0);
        $child = $map->setArray('child');
        $events = [];
        $origins = [];

        $observerId = $map->observeDeep(static function (array $deepEvents, mixed $observedMap, array $transaction) use (&$events, &$origins, $map): void {
            self::assertSame($map, $observedMap);
            $origins[] = $transaction['origin'];

            foreach ($deepEvents as $event) {
                $events[] = self::deepSharedTypeEventSubset($event);
            }
        });

        $doc->transact(static function () use ($map, $child): void {
            $child->insert(0, ['x']);
        }, 'nested-map-deep-observer-paths-origin');

        $map->unobserveDeep($observerId);
        $child->insert(1, ['ignored']);

        usort($events, static fn (array $left, array $right): int => $left['targetType'] <=> $right['targetType']);

        self::assertSame(['nested-map-deep-observer-paths-origin'], $origins);
        self::assertSame($fixture['deepNestedMapPaths'], $events);
    }

    public function testNestedTextObserveDeepMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc(170);
        $text = $doc->getArray('array')->insertText(0);
        $events = [];

        $observerId = $text->observeDeep(static function (array $deepEvents, mixed $observedText) use (&$events, $text): void {
            self::assertSame($text, $observedText);

            foreach ($deepEvents as $event) {
                $events[] = self::deepSharedTypeEventSubset($event);
            }
        });

        $doc->transact(static function () use ($text): void {
            $text->insert(0, 'Hi');
        }, 'nested-text-deep-observer-paths-origin');

        $text->unobserveDeep($observerId);
        $text->insert(2, '!');

        self::assertSame($fixture['deepNestedTextPaths'], $events);
    }

    public function testNestedTextAttributeObserveDeepMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc(170);
        $text = $doc->getArray('array')->insertText(0);
        $text->insert(0, 'Hello');
        $text->setAttribute('lang', 'en');
        $events = [];

        $observerId = $text->observeDeep(static function (array $deepEvents, mixed $observedText) use (&$events, $text): void {
            self::assertSame($text, $observedText);

            foreach ($deepEvents as $event) {
                $events[] = self::deepSharedTypeEventSubset($event);
            }
        });

        $doc->transact(static function () use ($text): void {
            $text->setAttribute('lang', 'fr');
            $text->setAttribute('mark', ['color' => 'green']);
            $text->removeAttribute('mark');
        }, 'nested-text-attribute-deep-observer-paths-origin');

        $text->unobserveDeep($observerId);
        $text->setAttribute('ignored', true);

        self::assertSame($fixture['deepNestedTextAttributePaths'], $events);
    }

    public function testNestedXmlFragmentObserveDeepMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc(170);
        $fragment = $doc->getArray('array')->insertXmlFragment(0);
        $fragment->insertText(0, 'A');
        $events = [];
        $origins = [];

        $observerId = $fragment->observeDeep(static function (array $deepEvents, mixed $observedFragment, array $transaction) use (&$events, &$origins, $fragment): void {
            self::assertSame($fragment, $observedFragment);
            $origins[] = $transaction['origin'];

            foreach ($deepEvents as $event) {
                $events[] = [
                    'targetType' => $event['typeName'],
                    'path' => $event['path'],
                    'changes' => $event['changes'],
                ];
            }
        });

        $doc->transact(static function () use ($fragment): void {
            $fragment->insertText(1, 'B');
            $fragment->insertElement(2, 'p')->appendText('C');
        }, 'nested-xml-fragment-deep-observer-paths-origin');

        $fragment->unobserveDeep($observerId);
        $fragment->insertText(3, 'ignored');

        usort($events, static fn (array $left, array $right): int => $left['targetType'] <=> $right['targetType']);

        self::assertSame(['nested-xml-fragment-deep-observer-paths-origin'], $origins);
        self::assertSame($fixture['deepNestedXmlFragmentPaths'], $events);
    }

    public function testXmlFragmentObserveDeepMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc(170);
        $xml = $doc->getXmlFragment('xml');
        $paragraph = $xml->insertElement(0, 'p');
        $text = $paragraph->insertText(0, 'Hi');
        $events = [];
        $origins = [];

        $observerId = $xml->observeDeep(static function (array $deepEvents, mixed $fragment, array $transaction) use (&$events, &$origins, $xml): void {
            self::assertSame($xml, $fragment);
            $origins[] = $transaction['origin'];

            foreach ($deepEvents as $event) {
                $events[] = [
                    'targetType' => $event['typeName'],
                    'path' => $event['path'],
                    'changes' => $event['changes'],
                ];
            }
        });

        $doc->transact(static function () use ($paragraph, $text): void {
            $paragraph->setAttribute('class', 'lead');
            $text->insert(2, '!');
        }, 'xml-deep-observer-paths-origin');

        $xml->unobserveDeep($observerId);
        $text->insert(3, '?');

        usort($events, static fn (array $left, array $right): int => $left['targetType'] <=> $right['targetType']);

        self::assertSame(['xml-deep-observer-paths-origin'], $origins);
        self::assertSame($fixture['deepXmlPaths'], $events);
    }

    public function testXmlElementObserveDeepMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc(170);
        $xml = $doc->getXmlFragment('xml');
        $paragraph = $xml->insertElement(0, 'p');
        $text = $paragraph->insertText(0, 'Hi');
        $events = [];
        $origins = [];

        $observerId = $paragraph->observeDeep(static function (array $deepEvents, mixed $element, array $transaction) use (&$events, &$origins, $paragraph): void {
            self::assertSame($paragraph, $element);
            $origins[] = $transaction['origin'];

            foreach ($deepEvents as $event) {
                $events[] = [
                    'targetType' => $event['typeName'],
                    'path' => $event['path'],
                    'changes' => $event['changes'],
                ];
            }
        });

        $doc->transact(static function () use ($paragraph, $text): void {
            $paragraph->setAttribute('class', 'lead');
            $text->insert(2, '!');
        }, 'xml-element-deep-observer-paths-origin');

        $paragraph->unobserveDeep($observerId);
        $text->insert(3, '?');

        usort($events, static fn (array $left, array $right): int => $left['targetType'] <=> $right['targetType']);

        self::assertSame(['xml-element-deep-observer-paths-origin'], $origins);
        self::assertSame($fixture['deepXmlElementPaths'], $events);
    }

    public function testXmlElementSharedAttributeObserveDeepMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc(170);
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $body = $paragraph->setText('body');
        $element = $paragraph->setXmlElement('element', 'span');
        $events = [];
        $origins = [];

        $observerId = $paragraph->observeDeep(static function (array $deepEvents, mixed $element, array $transaction) use (&$events, &$origins, $paragraph): void {
            self::assertSame($paragraph, $element);
            $origins[] = $transaction['origin'];

            foreach ($deepEvents as $event) {
                $events[] = self::deepSharedTypeEventSubset($event);
            }
        });

        $doc->transact(static function () use ($paragraph, $body, $element): void {
            $paragraph->setAttribute('role', 'lead');
            $body->insert(0, 'Hi');
            $element->setAttribute('class', 'lead');
            $element->insertText(0, 'Xml');
        }, 'xml-element-shared-attribute-deep-observer-paths-origin');

        $paragraph->unobserveDeep($observerId);
        $paragraph->setAttribute('role', 'ignored');

        usort($events, static fn (array $left, array $right): int => json_encode($left['path']) <=> json_encode($right['path']) ?: $left['targetType'] <=> $right['targetType']);

        self::assertSame(['xml-element-shared-attribute-deep-observer-paths-origin'], $origins);
        self::assertSame($fixture['deepXmlElementSharedAttributePaths'], $events);
    }

    public function testXmlElementSharedAttributeReplaceObserveDeepMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc(170);
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $body = $paragraph->setText('body');
        $body->insert(0, 'Old');
        $events = [];
        $origins = [];

        $observerId = $paragraph->observeDeep(static function (array $deepEvents, mixed $element, array $transaction) use (&$events, &$origins, $paragraph): void {
            self::assertSame($paragraph, $element);
            $origins[] = $transaction['origin'];

            foreach ($deepEvents as $event) {
                $events[] = self::deepSharedTypeEventSubset($event);
            }
        });

        $doc->transact(static function () use ($paragraph): void {
            $paragraph->setAttribute('body', 'plain');
            $paragraph->setXmlElement('inline', 'span');
        }, 'xml-element-shared-attribute-replace-deep-observer-paths-origin');

        $paragraph->unobserveDeep($observerId);
        $paragraph->setAttribute('body', 'ignored');

        usort($events, static fn (array $left, array $right): int => json_encode($left['path']) <=> json_encode($right['path']) ?: $left['targetType'] <=> $right['targetType']);

        self::assertSame(['xml-element-shared-attribute-replace-deep-observer-paths-origin'], $origins);
        self::assertSame($fixture['deepXmlElementSharedAttributeReplacePaths'], $events);
    }

    public function testXmlElementBulkChildObserveDeepMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc(170);
        $xml = $doc->getXmlFragment('xml');
        $paragraph = $xml->insertElement(0, 'p');
        $text = $paragraph->insertText(0, 'A');
        $events = [];
        $origins = [];

        $observerId = $paragraph->observeDeep(static function (array $deepEvents, mixed $element, array $transaction) use (&$events, &$origins, $paragraph): void {
            self::assertSame($paragraph, $element);
            $origins[] = $transaction['origin'];

            foreach ($deepEvents as $event) {
                $events[] = [
                    'targetType' => $event['typeName'],
                    'path' => $event['path'],
                    'changes' => $event['changes'],
                ];
            }
        });

        $doc->transact(static function () use ($paragraph, $text): void {
            $paragraph->insertElements(1, ['em']);
            $paragraph->insertHooks(2, ['mention']);
            $text->insert(1, '!');
        }, 'xml-element-bulk-child-deep-observer-paths-origin');

        $paragraph->unobserveDeep($observerId);
        $text->insert(2, '?');

        usort($events, static fn (array $left, array $right): int => $left['targetType'] <=> $right['targetType']);

        self::assertSame(['xml-element-bulk-child-deep-observer-paths-origin'], $origins);
        self::assertSame($fixture['deepXmlElementBulkChildPaths'], $events);
    }

    public function testXmlElementReplaceChildObserveDeepMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc(170);
        $xml = $doc->getXmlFragment('xml');
        $paragraph = $xml->insertElement(0, 'p');
        $text = $paragraph->insertText(0, 'A');
        $paragraph->insertElement(1, 'em')->appendText('B');
        $events = [];
        $origins = [];

        $observerId = $paragraph->observeDeep(static function (array $deepEvents, mixed $element, array $transaction) use (&$events, &$origins, $paragraph): void {
            self::assertSame($paragraph, $element);
            $origins[] = $transaction['origin'];

            foreach ($deepEvents as $event) {
                $events[] = [
                    'targetType' => $event['typeName'],
                    'path' => $event['path'],
                    'changes' => $event['changes'],
                ];
            }
        });

        $doc->transact(static function () use ($paragraph, $text): void {
            $text->insert(1, '!');
            $paragraph->delete(1, 1);
            $paragraph->insertElement(1, 'strong')->appendText('C');
        }, 'xml-element-replace-child-deep-observer-paths-origin');

        $paragraph->unobserveDeep($observerId);
        $text->insert(2, '?');

        usort($events, static fn (array $left, array $right): int => $left['targetType'] <=> $right['targetType']);

        self::assertSame(['xml-element-replace-child-deep-observer-paths-origin'], $origins);
        self::assertSame($fixture['deepXmlElementReplaceChildPaths'], $events);
    }

    public function testXmlTextObserveDeepMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc(170);
        $text = $doc->getXmlFragment('xml')->insertText(0, 'Hi');
        $events = [];

        $observerId = $text->observeDeep(static function (array $deepEvents, mixed $observedText) use (&$events, $text): void {
            self::assertSame($text, $observedText);

            foreach ($deepEvents as $event) {
                $events[] = [
                    'targetType' => $event['typeName'],
                    'path' => $event['path'],
                    'changes' => $event['changes'],
                ];
            }
        });

        $doc->transact(static function () use ($text): void {
            $text->insert(2, '!');
        }, 'xml-text-deep-observer-paths-origin');

        $text->unobserveDeep($observerId);
        $text->insert(3, '?');

        self::assertSame($fixture['deepXmlTextPaths'], $events);
    }

    public function testXmlTextSharedAttributeObserveDeepMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc(170);
        $text = $doc->getXmlFragment('xml')->insertText(0, 'Hi');
        $body = $text->setText('body');
        $element = $text->setXmlElement('element', 'span');
        $events = [];
        $origins = [];

        $observerId = $text->observeDeep(static function (array $deepEvents, mixed $observedText, array $transaction) use (&$events, &$origins, $text): void {
            self::assertSame($text, $observedText);
            $origins[] = $transaction['origin'];

            foreach ($deepEvents as $event) {
                $events[] = self::deepSharedTypeEventSubset($event);
            }
        });

        $doc->transact(static function () use ($text, $body, $element): void {
            $text->setAttribute('role', 'lead');
            $body->insert(0, 'Hi');
            $element->setAttribute('class', 'lead');
            $element->insertText(0, 'Xml');
        }, 'xml-text-shared-attribute-deep-observer-paths-origin');

        $text->unobserveDeep($observerId);
        $text->setAttribute('role', 'ignored');

        usort($events, static fn (array $left, array $right): int => json_encode($left['path']) <=> json_encode($right['path']) ?: $left['targetType'] <=> $right['targetType']);

        self::assertSame(['xml-text-shared-attribute-deep-observer-paths-origin'], $origins);
        self::assertSame($fixture['deepXmlTextSharedAttributePaths'], $events);
    }

    public function testXmlTextSharedAttributeReplaceObserveDeepMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc(170);
        $text = $doc->getXmlFragment('xml')->insertText(0, 'Hi');
        $body = $text->setText('body');
        $body->insert(0, 'Old');
        $events = [];
        $origins = [];

        $observerId = $text->observeDeep(static function (array $deepEvents, mixed $observedText, array $transaction) use (&$events, &$origins, $text): void {
            self::assertSame($text, $observedText);
            $origins[] = $transaction['origin'];

            foreach ($deepEvents as $event) {
                $events[] = self::deepSharedTypeEventSubset($event);
            }
        });

        $doc->transact(static function () use ($text): void {
            $text->setAttribute('body', 'plain');
            $text->setXmlElement('inline', 'span');
        }, 'xml-text-shared-attribute-replace-deep-observer-paths-origin');

        $text->unobserveDeep($observerId);
        $text->setAttribute('body', 'ignored');

        usort($events, static fn (array $left, array $right): int => json_encode($left['path']) <=> json_encode($right['path']) ?: $left['targetType'] <=> $right['targetType']);

        self::assertSame(['xml-text-shared-attribute-replace-deep-observer-paths-origin'], $origins);
        self::assertSame($fixture['deepXmlTextSharedAttributeReplacePaths'], $events);
    }

    public function testXmlHookObserveDeepMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture();
        $doc = new YDoc(170);
        $hook = $doc->getXmlFragment('xml')->insertHook(0, 'mention');
        $body = $hook->setText('body');
        $element = $hook->setXmlElement('element', 'p');
        $events = [];

        $observerId = $hook->observeDeep(static function (array $deepEvents, mixed $observedHook) use (&$events, $hook): void {
            self::assertSame($hook, $observedHook);

            foreach ($deepEvents as $event) {
                $events[] = self::deepSharedTypeEventSubset($event);
            }
        });

        $doc->transact(static function () use ($hook, $body, $element): void {
            $hook->set('role', 'lead');
            $body->insert(0, 'Hi');
            $element->setAttribute('class', 'lead');
            $element->insertText(0, 'Xml');
        }, 'xml-hook-deep-observer-paths-origin');

        $hook->unobserveDeep($observerId);
        $hook->set('role', 'ignored');

        usort($events, static fn (array $left, array $right): int => json_encode($left['path']) <=> json_encode($right['path']) ?: $left['targetType'] <=> $right['targetType']);

        self::assertSame($fixture['deepXmlHookPaths'], $events);
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function captureTextEvent(YDoc $doc, array &$events): void
    {
        $text = $doc->getText('content');
        $text->observe(static function (array $event) use (&$events): void {
            $events[] = self::eventSubset($event);
        });

        $doc->transact(static function () use ($text): void {
            $text->insert(0, 'AB');
            $text->delete(1, 1);
            $text->insert(1, 'C');
        }, 'text-insert-delete-insert-origin');
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function captureTextFormatEvent(YDoc $doc, array &$events): void
    {
        $text = $doc->getText('content');
        $text->insert(0, 'Hello');
        $text->observe(static function (array $event) use (&$events): void {
            $events[] = self::eventSubset($event);
        });

        $doc->transact(static function () use ($text): void {
            $text->format(1, 3, ['bold' => true]);
        }, 'text-format-range-origin');
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function captureArrayEvent(YDoc $doc, array &$events): void
    {
        $array = $doc->getArray('array');
        $array->observe(static function (array $event) use (&$events): void {
            $events[] = self::eventSubset($event);
        });

        $doc->transact(static function () use ($array): void {
            $array->insert(0, ['A', 'B']);
            $array->delete(0, 1);
            $array->insert(1, ['C']);
        }, 'array-insert-delete-insert-origin');
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function captureMapAddEvent(YDoc $doc, array &$events): void
    {
        $map = $doc->getMap('map');
        $map->observe(static function (array $event) use (&$events): void {
            $events[] = self::eventSubset($event);
        });

        $doc->transact(static function () use ($map): void {
            $map->set('a', 1);
        }, 'map-add-key-origin');
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function captureMapUpdateEvent(YDoc $doc, array &$events): void
    {
        $map = $doc->getMap('map');
        $map->set('a', 1);
        $map->observe(static function (array $event) use (&$events): void {
            $events[] = self::eventSubset($event);
        });

        $doc->transact(static function () use ($map): void {
            $map->set('a', 2);
        }, 'map-update-key-origin');
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function captureMapDeleteEvent(YDoc $doc, array &$events): void
    {
        $map = $doc->getMap('map');
        $map->set('a', 1);
        $map->observe(static function (array $event) use (&$events): void {
            $events[] = self::eventSubset($event);
        });

        $doc->transact(static function () use ($map): void {
            $map->delete('a');
        }, 'map-delete-key-origin');
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function captureNestedArrayEvent(YDoc $doc, array &$events): void
    {
        $array = $doc->getArray('array');
        $nested = $array->insertArray(0);
        $nested->insert(0, ['A']);
        $nested->observe(static function (array $event) use (&$events): void {
            $events[] = self::eventSubset($event);
        });

        $doc->transact(static function () use ($nested): void {
            $nested->insert(1, ['B', 'D']);
            $nested->delete(1, 1);
            $nested->insert(1, ['C']);
        }, 'nested-array-insert-delete-insert-origin');
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function captureNestedMapEvent(YDoc $doc, array &$events): void
    {
        $map = $doc->getMap('map');
        $nested = $map->setMap('nested');
        $nested->set('a', 1);
        $nested->observe(static function (array $event) use (&$events): void {
            $events[] = self::eventSubset($event);
        });

        $doc->transact(static function () use ($nested): void {
            $nested->set('a', 2);
            $nested->set('b', null);
            $nested->delete('b');
        }, 'nested-map-update-key-origin');
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function captureNestedTextAttributeEvent(YDoc $doc, array &$events): void
    {
        $text = $doc->getArray('array')->insertText(0);
        $text->insert(0, 'Hello');
        $text->setAttribute('lang', 'en');
        $text->observe(static function (array $event) use (&$events): void {
            $events[] = self::eventSubset($event);
        });

        $doc->transact(static function () use ($text): void {
            $text->setAttribute('lang', 'fr');
            $text->setAttribute('mark', ['color' => 'green']);
            $text->removeAttribute('mark');
        }, 'nested-text-attribute-update-key-origin');
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function captureNestedXmlFragmentEvent(YDoc $doc, array &$events): void
    {
        $fragment = $doc->getArray('array')->insertXmlFragment(0);
        $fragment->insertText(0, 'A');
        $fragment->observe(static function (array $event) use (&$events): void {
            $events[] = self::eventSubset($event);
        });

        $doc->transact(static function () use ($fragment): void {
            $fragment->insertText(1, 'B');
            $fragment->delete(1, 1);
            $fragment->insertElement(1, 'p')->appendText('C');
        }, 'nested-xml-fragment-insert-delete-insert-origin');
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function captureXmlFragmentEvent(YDoc $doc, array &$events): void
    {
        $xml = $doc->getXmlFragment('xml');
        $xml->observe(static function (array $event) use (&$events): void {
            $events[] = self::eventSubset($event);
        });

        $doc->transact(static function () use ($xml): void {
            $xml->insertText(0, 'A');
            $xml->delete(0, 1);
            $xml->insertElement(0, 'p')->appendText('B');
        }, 'xml-fragment-insert-delete-insert-origin');
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function captureXmlFragmentHookEvent(YDoc $doc, array &$events): void
    {
        $xml = $doc->getXmlFragment('xml');
        $xml->insertText(0, 'A');
        $xml->observe(static function (array $event) use (&$events): void {
            $events[] = self::eventSubset($event);
        });

        $doc->transact(static function () use ($xml): void {
            $xml->insertHook(1, 'removed');
            $xml->delete(1, 1);
            $xml->insertHook(1, 'kept');
        }, 'xml-fragment-hook-insert-delete-origin');
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function captureXmlElementAttributeEvent(YDoc $doc, array &$events): void
    {
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $paragraph->observe(static function (array $event) use (&$events): void {
            $events[] = self::eventSubset($event);
        });

        $doc->transact(static function () use ($paragraph): void {
            $paragraph->setAttribute('class', 'lead');
            $paragraph->setAttribute('class', 'quiet');
            $paragraph->removeAttribute('class');
        }, 'xml-element-attribute-update-origin');
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function captureXmlElementAttributeAddEvent(YDoc $doc, array &$events): void
    {
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $paragraph->observe(static function (array $event) use (&$events): void {
            $events[] = self::eventSubset($event);
        });

        $doc->transact(static function () use ($paragraph): void {
            $paragraph->setAttribute('class', 'lead');
        }, 'xml-element-attribute-add-key-origin');
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function captureXmlElementAttributeUpdateEvent(YDoc $doc, array &$events): void
    {
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $paragraph->setAttribute('class', 'base');
        $paragraph->observe(static function (array $event) use (&$events): void {
            $events[] = self::eventSubset($event);
        });

        $doc->transact(static function () use ($paragraph): void {
            $paragraph->setAttribute('class', 'lead');
        }, 'xml-element-attribute-update-key-origin');
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function captureXmlElementAttributeDeleteEvent(YDoc $doc, array &$events): void
    {
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $paragraph->setAttribute('class', 'base');
        $paragraph->observe(static function (array $event) use (&$events): void {
            $events[] = self::eventSubset($event);
        });

        $doc->transact(static function () use ($paragraph): void {
            $paragraph->removeAttribute('class');
        }, 'xml-element-attribute-delete-key-origin');
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function captureXmlElementChildEvent(YDoc $doc, array &$events): void
    {
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $paragraph->insertText(0, 'A');
        $paragraph->observe(static function (array $event) use (&$events): void {
            $events[] = self::eventSubset($event);
        });

        $doc->transact(static function () use ($paragraph): void {
            $paragraph->insertText(1, 'B');
            $paragraph->delete(1, 1);
            $paragraph->insertElement(1, 'strong')->appendText('C');
        }, 'xml-element-child-insert-delete-origin');
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function captureXmlElementChildReplaceMiddleEvent(YDoc $doc, array &$events): void
    {
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $paragraph->insertText(0, 'A');
        $paragraph->insertElement(1, 'em')->appendText('B');
        $paragraph->insertText(2, 'C');
        $paragraph->observe(static function (array $event) use (&$events): void {
            $events[] = self::eventSubset($event);
        });

        $doc->transact(static function () use ($paragraph): void {
            $paragraph->delete(1, 1);
            $paragraph->insertElement(1, 'strong')->appendText('D');
        }, 'xml-element-child-replace-middle-origin');
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function captureXmlElementChildAndTextUpdateEvent(YDoc $doc, array &$events): void
    {
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $text = $paragraph->insertText(0, 'A');
        $paragraph->insertElement(1, 'em')->appendText('B');
        $paragraph->observe(static function (array $event) use (&$events): void {
            $events[] = self::eventSubset($event);
        });

        $doc->transact(static function () use ($paragraph, $text): void {
            $text->insert(1, '!');
            $paragraph->delete(1, 1);
            $paragraph->insertElement(1, 'strong')->appendText('C');
        }, 'xml-element-child-and-text-update-origin');
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function captureXmlFragmentDeleteMiddleRangeEvent(YDoc $doc, array &$events): void
    {
        $xml = $doc->getXmlFragment('xml');
        $xml->insertElement(0, 'a');
        $xml->insertElement(1, 'b');
        $xml->insertElement(2, 'c');
        $xml->insertElement(3, 'd');
        $xml->observe(static function (array $event) use (&$events): void {
            $events[] = self::eventSubset($event);
        });

        $doc->transact(static function () use ($xml): void {
            $xml->delete(1, 2);
        }, 'xml-fragment-delete-middle-range-origin');
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function captureXmlFragmentInsertMultipleElementsEvent(YDoc $doc, array &$events): void
    {
        $xml = $doc->getXmlFragment('xml');
        $xml->observe(static function (array $event) use (&$events): void {
            $events[] = self::eventSubset($event);
        });

        $doc->transact(static function () use ($xml): void {
            $xml->insertElements(0, ['a', 'b']);
        }, 'xml-fragment-insert-multiple-elements-origin');
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function captureXmlElementInsertMultipleChildTypesEvent(YDoc $doc, array &$events): void
    {
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $paragraph->observe(static function (array $event) use (&$events): void {
            $events[] = self::eventSubset($event);
        });

        $doc->transact(static function () use ($paragraph): void {
            $paragraph->insert(0, ['A']);
            $paragraph->insertElements(1, ['em']);
            $paragraph->insertHooks(2, ['mention']);
        }, 'xml-element-insert-multiple-child-types-origin');
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function captureXmlElementHookEvent(YDoc $doc, array &$events): void
    {
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $paragraph->insertText(0, 'A');
        $paragraph->observe(static function (array $event) use (&$events): void {
            $events[] = self::eventSubset($event);
        });

        $doc->transact(static function () use ($paragraph): void {
            $paragraph->insertHook(1, 'removed');
            $paragraph->delete(1, 1);
            $paragraph->insertHook(1, 'kept');
        }, 'xml-element-hook-insert-delete-origin');
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function captureXmlHookMapEvent(YDoc $doc, array &$events): void
    {
        $hook = $doc->getXmlFragment('xml')->insertHook(0, 'mention');
        $hook->set('role', 'base');
        $hook->observe(static function (array $event) use (&$events): void {
            $events[] = self::eventSubset($event);
        });

        $doc->transact(static function () use ($hook): void {
            $hook->set('role', 'lead');
            $hook->set('count', 2);
            $hook->delete('count');
        }, 'xml-hook-map-update-key-origin');
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function captureXmlHookMapAddUpdateDeleteEvent(YDoc $doc, array &$events): void
    {
        $hook = $doc->getXmlFragment('xml')->insertHook(0, 'mention');
        $hook->set('role', 'base');
        $hook->set('removeMe', true);
        $hook->observe(static function (array $event) use (&$events): void {
            $events[] = self::eventSubset($event);
        });

        $doc->transact(static function () use ($hook): void {
            $hook->set('role', 'lead');
            $hook->set('active', true);
            $hook->delete('removeMe');
        }, 'xml-hook-map-add-update-delete-keys-origin');
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function captureXmlTextFormatEvent(YDoc $doc, array &$events): void
    {
        $text = $doc->getXmlFragment('xml')->insertText(0, 'Hello');
        $text->observe(static function (array $event) use (&$events): void {
            $events[] = self::eventSubset($event);
        });

        $doc->transact(static function () use ($text): void {
            $text->format(1, 3, ['bold' => true]);
        }, 'xml-text-format-range-origin');
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function captureXmlTextAttributeEvent(YDoc $doc, array &$events): void
    {
        $text = $doc->getXmlFragment('xml')->insertText(0, 'Hello');
        $text->setAttribute('lang', 'en');
        $text->observe(static function (array $event) use (&$events): void {
            $events[] = self::eventSubset($event);
        });

        $doc->transact(static function () use ($text): void {
            $text->setAttribute('lang', 'fr');
            $text->setAttribute('mark', ['color' => 'green']);
            $text->removeAttribute('mark');
        }, 'xml-text-attribute-update-key-origin');
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function captureXmlTextSharedAttributeEvent(YDoc $doc, array &$events): void
    {
        $text = $doc->getXmlFragment('xml')->insertText(0, 'Hello');
        $body = $text->setText('body');
        $body->insert(0, 'Old');
        $text->observe(static function (array $event) use (&$events): void {
            $events[] = self::eventSubset($event);
        });

        $doc->transact(static function () use ($text): void {
            $text->setAttribute('body', 'plain');
            $text->setXmlElement('inline', 'span');
        }, 'xml-text-shared-attribute-update-key-origin');
    }

    /**
     * @return array{path: list<mixed>, changes: array{keys: array<string, mixed>, delta: list<array<string, mixed>>}}
     */
    private static function eventSubset(array $event): array
    {
        return [
            'path' => $event['path'],
            'changes' => $event['changes'],
        ];
    }

    /**
     * @param array{loaded: list<YSubdoc>, added: list<YSubdoc>, removed: list<YSubdoc>} $event
     * @return array{origin: mixed, local: bool, loaded: list<array<string, mixed>>, added: list<array<string, mixed>>, removed: list<array<string, mixed>>, liveGuids: list<string>}
     */
    private static function subdocEventSubset(array $event, YDoc $doc, array $transaction): array
    {
        return [
            'origin' => $transaction['origin'],
            'local' => $transaction['local'],
            'loaded' => array_map(self::subdocSubset(...), $event['loaded']),
            'added' => array_map(self::subdocSubset(...), $event['added']),
            'removed' => array_map(self::subdocSubset(...), $event['removed']),
            'liveGuids' => $doc->getSubdocGuids(),
        ];
    }

    /**
     * @return array{guid: string, meta: mixed, shouldLoad: bool}
     */
    private static function subdocSubset(YSubdoc $subdoc): array
    {
        return [
            'guid' => $subdoc->guid(),
            'meta' => $subdoc->meta(),
            'shouldLoad' => $subdoc->shouldLoad(),
        ];
    }

    private static function deepSharedTypeEventSubset(array $event): array
    {
        $type = $event['typeName'] ?? $event['type'] ?? null;

        return [
            'targetType' => match ($type) {
                'array' => 'YArray',
                'map' => 'YMap',
                'text' => 'YText',
                default => $type,
            },
            'path' => $event['path'],
            'changes' => $event['changes'],
        ];
    }

    /**
     * @return array{cases: list<array<string, mixed>>}
     */
    private function loadFixture(): array
    {
        self::assertFileExists(self::FIXTURE_PATH, 'Run `npm run fixtures` before PHPUnit.');

        $decoded = json_decode((string) file_get_contents(self::FIXTURE_PATH), true);

        self::assertIsArray($decoded);

        return $decoded;
    }
}
