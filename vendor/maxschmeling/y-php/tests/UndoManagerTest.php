<?php

declare(strict_types=1);

namespace Yjs\Tests;

use PHPUnit\Framework\TestCase;
use Yjs\UndoManager;
use Yjs\YNestedText;
use Yjs\YNestedXmlFragment;
use Yjs\YDoc;
use Yjs\YXmlElement;
use Yjs\YXmlHook;
use Yjs\YXmlText;

final class UndoManagerTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__ . '/../fixtures/generated/yjs-13.6.31/undo-manager.json';

    public function testUndoRedoRestoresRootTextArrayAndMapTransaction(): void
    {
        $doc = new YDoc(701);
        $doc->getText('content')->insert(0, 'Hello');
        $doc->getArray('array')->insert(0, ['A']);
        $doc->getMap('map')->set('title', 'Draft');
        $manager = new UndoManager($doc, ['content', 'array', 'map'], ['edit-origin']);
        $updates = [];
        $doc->observeUpdate(static function (string $update) use (&$updates): void {
            $updates[] = $update;
        });

        $doc->transact(static function (YDoc $doc): void {
            $doc->getText('content')->insert(5, '!');
            $doc->getArray('array')->push(['B']);
            $doc->getMap('map')->set('title', 'Published');
            $doc->getMap('map')->set('status', 'ready');
        }, 'edit-origin');

        self::assertTrue($manager->canUndo());
        self::assertFalse($manager->canRedo());
        $this->assertSameJson([
            'content' => 'Hello!',
            'array' => ['A', 'B'],
            'map' => ['title' => 'Published', 'status' => 'ready'],
        ], $doc->toJSON());

        self::assertTrue($manager->undo());
        $this->assertSameJson([
            'content' => 'Hello',
            'array' => ['A'],
            'map' => ['title' => 'Draft'],
        ], $doc->toJSON());
        self::assertFalse($manager->canUndo());
        self::assertTrue($manager->canRedo());

        self::assertTrue($manager->redo());
        $this->assertSameJson([
            'content' => 'Hello!',
            'array' => ['A', 'B'],
            'map' => ['title' => 'Published', 'status' => 'ready'],
        ], $doc->toJSON());
        self::assertTrue($manager->canUndo());
        self::assertFalse($manager->canRedo());
        self::assertGreaterThanOrEqual(3, count($updates));
    }

    public function testUndoManagerTracksOnlyScopedRootTypes(): void
    {
        $doc = new YDoc(702);
        $doc->getText('content')->insert(0, 'A');
        $doc->getMap('map')->set('title', 'Draft');
        $manager = new UndoManager($doc, ['content'], ['mixed-edit']);

        $doc->transact(static function (YDoc $doc): void {
            $doc->getText('content')->insert(1, 'B');
            $doc->getMap('map')->set('title', 'Published');
        }, 'mixed-edit');

        self::assertTrue($manager->undo());
        $this->assertSameJson([
            'content' => 'A',
            'map' => ['title' => 'Published'],
        ], $doc->toJSON());
    }

    public function testNewTrackedEditClearsRedoStack(): void
    {
        $doc = new YDoc(703);
        $doc->getText('content')->insert(0, 'A');
        $manager = new UndoManager($doc, 'content');

        $doc->getText('content')->insert(1, 'B');
        self::assertTrue($manager->undo());
        self::assertTrue($manager->canRedo());

        $doc->getText('content')->insert(1, 'C');

        self::assertFalse($manager->canRedo());
        self::assertSame('AC', $doc->getText('content')->toString());
    }

    public function testRemoteUpdatesAndUntrackedOriginsAreNotCaptured(): void
    {
        $source = new YDoc(704);
        $source->getText('content')->insert(0, 'Remote');

        $target = new YDoc(705);
        $manager = new UndoManager($target, 'content', ['local-origin']);
        $target->applyUpdateV1($source->encodeStateAsUpdateV1(), 'remote-origin');
        $target->getText('content')->insert(6, ' ignored');

        self::assertFalse($manager->canUndo());

        $target->transact(static function (YDoc $doc): void {
            $doc->getText('content')->insert(6, ' local');
        }, 'local-origin');

        self::assertTrue($manager->canUndo());
        self::assertTrue($manager->undo());
        self::assertSame('Remote ignored', $target->getText('content')->toString());
    }

    public function testDefaultTrackedOriginsMatchYjsNullOriginBehavior(): void
    {
        $doc = new YDoc(713);
        $text = $doc->getText('content');
        $text->insert(0, 'A');
        $manager = new UndoManager($doc, 'content');

        $doc->transact(static function () use ($text): void {
            $text->insert(1, 'B');
        }, 'named-origin');

        self::assertFalse($manager->canUndo());
        self::assertSame('AB', $text->toString());

        $text->insert(2, 'C');

        self::assertTrue($manager->canUndo());
        self::assertTrue($manager->undo());
        self::assertSame('AB', $text->toString());
    }

    public function testTrackedOriginDefaultsMatchYjsFixture(): void
    {
        foreach ($this->loadUndoManagerFixture()['originCases'] as $case) {
            $doc = new YDoc(306);
            $text = $doc->getText('content');
            $text->insert(0, 'A');
            $manager = match ($case['name']) {
                'default-null-origin', 'default-named-origin' => new UndoManager($doc, 'content'),
                'tracked-named-origin' => new UndoManager($doc, 'content', ['named-origin']),
                'tracked-empty-origin-set' => new UndoManager($doc, 'content', []),
                default => throw new \UnexpectedValueException(sprintf('Unknown undo fixture "%s".', $case['name'])),
            };
            $origin = match ($case['name']) {
                'default-null-origin', 'tracked-empty-origin-set' => null,
                'default-named-origin', 'tracked-named-origin' => 'named-origin',
                default => throw new \UnexpectedValueException(sprintf('Unknown undo fixture "%s".', $case['name'])),
            };

            $doc->transact(static function () use ($text): void {
                $text->insert(1, 'B');
            }, $origin);

            self::assertSame($case['canUndo'], $manager->canUndo(), $case['name']);
            self::assertSame($case['undoStackLength'], count($manager->undoStack()), $case['name']);
            self::assertSame($case['text'], $text->toString(), $case['name']);
        }
    }

    public function testTrackedOriginClassNameMatchesYjsConstructorFixture(): void
    {
        $case = $this->loadUndoManagerFixture()['constructorTrackedOrigin'];
        $doc = new YDoc(311);
        $text = $doc->getText('content');
        $text->insert(0, 'A');
        $origin = new UndoTrackedOrigin();
        $manager = new UndoManager($doc, 'content', [UndoTrackedOrigin::class]);

        $doc->transact(static function () use ($text): void {
            $text->insert(1, 'B');
        }, $origin);

        self::assertSame($case['text'], $text->toString());
        self::assertSame($case['canUndo'], $manager->canUndo());
        self::assertSame($case['undoStackLength'], count($manager->undoStack()));
    }

    public function testUndoManagerInstanceOriginIsTrackedLikeYjsFixture(): void
    {
        $case = $this->loadUndoManagerFixture()['selfTrackedOrigin'];
        $doc = new YDoc(312);
        $text = $doc->getText('content');
        $text->insert(0, 'A');
        $manager = new UndoManager($doc, 'content');

        $doc->transact(static function () use ($text): void {
            $text->insert(1, 'B');
        }, $manager);

        self::assertSame($case['text'], $text->toString());
        self::assertSame($case['canUndo'], $manager->canUndo());
        self::assertSame($case['undoStackLength'], count($manager->undoStack()));
        self::assertTrue($manager->undo());
        self::assertSame('A', $text->toString());
    }

    public function testCaptureTransactionFilterMatchesYjsFixture(): void
    {
        $case = $this->loadUndoManagerFixture()['captureTransaction'];
        $doc = new YDoc(307);
        $text = $doc->getText('content');
        $text->insert(0, 'A');
        $manager = new UndoManager(
            $doc,
            'content',
            ['skip-origin', 'keep-origin'],
            captureTransaction: static fn (array $event): bool => ($event['origin'] ?? null) !== 'skip-origin'
        );

        $doc->transact(static function () use ($text): void {
            $text->insert(1, 'B');
        }, 'skip-origin');
        $doc->transact(static function () use ($text): void {
            $text->insert(2, 'C');
        }, 'keep-origin');

        self::assertSame($case['textBeforeUndo'], $text->toString());
        self::assertSame($case['canUndo'], $manager->canUndo());
        self::assertSame($case['undoStackLength'], count($manager->undoStack()));

        self::assertTrue($manager->undo());
        self::assertSame($case['textAfterUndo'], $text->toString());
    }

    public function testDeleteFilterKeepsInsertedTextLikeYjsFixture(): void
    {
        $case = $this->loadUndoManagerFixture()['deleteFilter'];
        $doc = new YDoc(312);
        $text = $doc->getText('content');
        $text->insert(0, 'A');
        $manager = new UndoManager(
            $doc,
            'content',
            deleteFilter: static fn (array $item): bool => ($item['content']['value'] ?? null) !== 'B'
        );

        $text->insert(1, 'B');

        self::assertSame($case['canUndoBeforeUndo'], $manager->canUndo());
        self::assertSame($case['undoStackLengthBeforeUndo'], count($manager->undoStack()));
        self::assertSame($case['undoReturnedStackItem'], $manager->undo());
        self::assertSame($case['textAfterUndo'], $text->toString());
        self::assertSame($case['canUndoAfterUndo'], $manager->canUndo());
        self::assertSame($case['canRedoAfterUndo'], $manager->canRedo());
        self::assertSame($case['undoStackLengthAfterUndo'], count($manager->undoStack()));
        self::assertSame($case['redoStackLengthAfterUndo'], count($manager->redoStack()));
    }

    public function testRemoteMapConflictsRespectIgnoreRemoteMapChangesFixture(): void
    {
        foreach ($this->loadUndoManagerFixture()['remoteMapConflicts'] as $case) {
            $local = new YDoc(320);
            $map = $local->getMap('map');
            $map->set('title', 'A');
            $remote = new YDoc(321);
            $remote->applyUpdateV1($local->encodeStateAsUpdateV1());
            $manager = new UndoManager(
                $local,
                'map',
                captureTimeout: 0,
                ignoreRemoteMapChanges: $case['ignoreRemoteMapChanges']
            );

            $map->set('title', 'B');
            $remote->getMap('map')->set('title', 'Remote');
            $local->applyUpdateV1($remote->encodeStateAsUpdateV1($local->encodeStateVector()), 'remote-origin');

            self::assertSame($case['beforeUndo'], $local->toJSON(), $case['name']);
            self::assertSame($case['undoReturnedStackItem'], $manager->undo(), $case['name']);
            self::assertSame($case['afterUndo'], $local->toJSON(), $case['name']);
            self::assertSame($case['canUndoAfterUndo'], $manager->canUndo(), $case['name']);
            self::assertSame($case['canRedoAfterUndo'], $manager->canRedo(), $case['name']);
            self::assertSame($case['undoStackLengthAfterUndo'], count($manager->undoStack()), $case['name']);
            self::assertSame($case['redoStackLengthAfterUndo'], count($manager->redoStack()), $case['name']);
        }
    }

    public function testAddToScopeMatchesYjsFixture(): void
    {
        $case = $this->loadUndoManagerFixture()['addToScope'];
        $doc = new YDoc(313);
        $text = $doc->getText('content');
        $map = $doc->getMap('map');
        $text->insert(0, 'A');
        $map->set('title', 'Draft');
        $manager = new UndoManager($doc, 'content', captureTimeout: 0);

        $manager->addToScope('map');
        $map->set('title', 'Published');

        self::assertSame($case['canUndo'], $manager->canUndo());
        self::assertSame($case['undoStackLength'], count($manager->undoStack()));
        self::assertSame($case['undoReturnedStackItem'], $manager->undo());
        $this->assertSameJson($case['afterUndo'], $doc->toJSON());
        self::assertSame($case['canRedoAfterUndo'], $manager->canRedo());
        self::assertSame($case['redoStackLengthAfterUndo'], count($manager->redoStack()));
    }

    public function testUndoRedoRootTextAttributeOnlyChangesMatchYjsFixture(): void
    {
        $case = $this->loadUndoManagerFixture()['textAttributes']['attributeOnly'];
        $doc = new YDoc(316);
        $text = $doc->getText('content');
        $text->insert(0, 'Text');
        $text->setAttribute('lang', 'en');
        $manager = new UndoManager($doc, 'content', ['text-attribute-edit'], captureTimeout: 0);
        $events = [];
        $transactionEvents = [];
        $manager->on('stack-item-added', static function (array $event) use (&$events): void {
            $events[] = ['type' => 'stack-item-added', 'stack' => $event['type']];
        });
        $manager->on('stack-item-popped', static function (array $event) use (&$events): void {
            $events[] = ['type' => 'stack-item-popped', 'stack' => $event['type']];
        });
        $doc->observeTransaction(static function (array $event) use (&$transactionEvents): void {
            if (($event['origin'] ?? null) !== 'text-attribute-edit') {
                return;
            }

            $transactionEvents[] = [
                'beforeTextAttributes' => $event['beforeTextAttributes'],
                'afterTextAttributes' => $event['afterTextAttributes'],
            ];
        });

        $doc->transact(static function () use ($text): void {
            $text->setAttribute('lang', 'fr');
            $text->setAttribute('mark', ['color' => 'green']);
        }, 'text-attribute-edit');

        self::assertSame($case['beforeUndo'], [
            'json' => $doc->toJSON(),
            'text' => $text->toString(),
            'attributes' => $text->getAttributes(),
            'canUndo' => $manager->canUndo(),
            'canRedo' => $manager->canRedo(),
            'undoStackLength' => count($manager->undoStack()),
            'redoStackLength' => count($manager->redoStack()),
        ]);
        self::assertSame([
            [
                'beforeTextAttributes' => ['content' => ['lang' => 'en']],
                'afterTextAttributes' => ['content' => ['lang' => 'fr', 'mark' => ['color' => 'green']]],
            ],
        ], $transactionEvents);

        $undoResult = $manager->undo();
        self::assertSame($case['afterUndo'], [
            'undoReturnedStackItem' => $undoResult,
            'json' => $doc->toJSON(),
            'text' => $text->toString(),
            'attributes' => $text->getAttributes(),
            'canUndo' => $manager->canUndo(),
            'canRedo' => $manager->canRedo(),
            'undoStackLength' => count($manager->undoStack()),
            'redoStackLength' => count($manager->redoStack()),
        ]);

        $redoResult = $manager->redo();
        self::assertSame($case['afterRedo'], [
            'redoReturnedStackItem' => $redoResult,
            'json' => $doc->toJSON(),
            'text' => $text->toString(),
            'attributes' => $text->getAttributes(),
            'canUndo' => $manager->canUndo(),
            'canRedo' => $manager->canRedo(),
            'undoStackLength' => count($manager->undoStack()),
            'redoStackLength' => count($manager->redoStack()),
            'events' => $events,
        ]);
    }

    public function testUndoRedoRootTextContentAndAttributesMatchYjsFixture(): void
    {
        $case = $this->loadUndoManagerFixture()['textAttributes']['contentAndAttributes'];
        $doc = new YDoc(317);
        $text = $doc->getText('content');
        $text->insert(0, 'Text');
        $text->setAttribute('lang', 'en');
        $manager = new UndoManager($doc, 'content', ['text-content-and-attribute-edit'], captureTimeout: 0);
        $events = [];
        $manager->on('stack-item-added', static function (array $event) use (&$events): void {
            $events[] = ['type' => 'stack-item-added', 'stack' => $event['type']];
        });
        $manager->on('stack-item-popped', static function (array $event) use (&$events): void {
            $events[] = ['type' => 'stack-item-popped', 'stack' => $event['type']];
        });

        $doc->transact(static function () use ($text): void {
            $text->insert(4, '!');
            $text->setAttribute('lang', 'fr');
            $text->setAttribute('mark', ['color' => 'green']);
        }, 'text-content-and-attribute-edit');

        self::assertSame($case['beforeUndo'], [
            'json' => $doc->toJSON(),
            'text' => $text->toString(),
            'attributes' => $text->getAttributes(),
            'canUndo' => $manager->canUndo(),
            'canRedo' => $manager->canRedo(),
            'undoStackLength' => count($manager->undoStack()),
            'redoStackLength' => count($manager->redoStack()),
        ]);

        $undoResult = $manager->undo();
        self::assertSame($case['afterUndo'], [
            'undoReturnedStackItem' => $undoResult,
            'json' => $doc->toJSON(),
            'text' => $text->toString(),
            'attributes' => $text->getAttributes(),
            'canUndo' => $manager->canUndo(),
            'canRedo' => $manager->canRedo(),
            'undoStackLength' => count($manager->undoStack()),
            'redoStackLength' => count($manager->redoStack()),
        ]);

        $redoResult = $manager->redo();
        self::assertSame($case['afterRedo'], [
            'redoReturnedStackItem' => $redoResult,
            'json' => $doc->toJSON(),
            'text' => $text->toString(),
            'attributes' => $text->getAttributes(),
            'canUndo' => $manager->canUndo(),
            'canRedo' => $manager->canRedo(),
            'undoStackLength' => count($manager->undoStack()),
            'redoStackLength' => count($manager->redoStack()),
            'events' => $events,
        ]);
    }

    public function testUndoRedoNestedTextAttributeOnlyChangesMatchYjsFixture(): void
    {
        $case = $this->loadUndoManagerFixture()['textAttributes']['nestedAttributeOnly'];
        $doc = new YDoc(318);
        $text = $doc->getArray('array')->insertText(0);
        $text->insert(0, 'Nested');
        $text->setAttribute('lang', 'en');
        $manager = new UndoManager($doc, $text->idKey(), ['nested-text-attribute-edit'], captureTimeout: 0);
        $events = [];
        $transactionEvents = [];
        $manager->on('stack-item-added', static function (array $event) use (&$events): void {
            $events[] = ['type' => 'stack-item-added', 'stack' => $event['type']];
        });
        $manager->on('stack-item-popped', static function (array $event) use (&$events): void {
            $events[] = ['type' => 'stack-item-popped', 'stack' => $event['type']];
        });
        $doc->observeTransaction(static function (array $event) use (&$transactionEvents, $text): void {
            if (($event['origin'] ?? null) !== 'nested-text-attribute-edit') {
                return;
            }

            $transactionEvents[] = [
                'beforeNestedTextAttributes' => $event['beforeNestedTextAttributes'][$text->idKey()] ?? [],
                'afterNestedTextAttributes' => $event['afterNestedTextAttributes'][$text->idKey()] ?? [],
            ];
        });

        $doc->transact(static function () use ($text): void {
            $text->setAttribute('lang', 'fr');
            $text->setAttribute('mark', ['color' => 'green']);
        }, 'nested-text-attribute-edit');

        self::assertSame($case['beforeUndo'], [
            'json' => $doc->toJSON(),
            'text' => $text->toString(),
            'attributes' => $text->getAttributes(),
            'canUndo' => $manager->canUndo(),
            'canRedo' => $manager->canRedo(),
            'undoStackLength' => count($manager->undoStack()),
            'redoStackLength' => count($manager->redoStack()),
        ]);
        self::assertSame([
            [
                'beforeNestedTextAttributes' => ['lang' => 'en'],
                'afterNestedTextAttributes' => ['lang' => 'fr', 'mark' => ['color' => 'green']],
            ],
        ], $transactionEvents);

        $undoResult = $manager->undo();
        self::assertSame($case['afterUndo'], [
            'undoReturnedStackItem' => $undoResult,
            'json' => $doc->toJSON(),
            'text' => $text->toString(),
            'attributes' => $text->getAttributes(),
            'canUndo' => $manager->canUndo(),
            'canRedo' => $manager->canRedo(),
            'undoStackLength' => count($manager->undoStack()),
            'redoStackLength' => count($manager->redoStack()),
        ]);

        $redoResult = $manager->redo();
        self::assertSame($case['afterRedo'], [
            'redoReturnedStackItem' => $redoResult,
            'json' => $doc->toJSON(),
            'text' => $text->toString(),
            'attributes' => $text->getAttributes(),
            'canUndo' => $manager->canUndo(),
            'canRedo' => $manager->canRedo(),
            'undoStackLength' => count($manager->undoStack()),
            'redoStackLength' => count($manager->redoStack()),
            'events' => $events,
        ]);
    }

    public function testUndoRedoNestedTextContentAndAttributesMatchYjsFixture(): void
    {
        $case = $this->loadUndoManagerFixture()['textAttributes']['nestedContentAndAttributes'];
        $doc = new YDoc(319);
        $text = $doc->getArray('array')->insertText(0);
        $text->insert(0, 'Nested');
        $text->setAttribute('lang', 'en');
        $manager = new UndoManager($doc, $text->idKey(), ['nested-text-content-and-attribute-edit'], captureTimeout: 0);
        $events = [];
        $manager->on('stack-item-added', static function (array $event) use (&$events): void {
            $events[] = ['type' => 'stack-item-added', 'stack' => $event['type']];
        });
        $manager->on('stack-item-popped', static function (array $event) use (&$events): void {
            $events[] = ['type' => 'stack-item-popped', 'stack' => $event['type']];
        });

        $doc->transact(static function () use ($text): void {
            $text->insert(6, '!');
            $text->setAttribute('lang', 'fr');
            $text->setAttribute('mark', ['color' => 'green']);
        }, 'nested-text-content-and-attribute-edit');

        self::assertSame($case['beforeUndo'], [
            'json' => $doc->toJSON(),
            'text' => $text->toString(),
            'attributes' => $text->getAttributes(),
            'canUndo' => $manager->canUndo(),
            'canRedo' => $manager->canRedo(),
            'undoStackLength' => count($manager->undoStack()),
            'redoStackLength' => count($manager->redoStack()),
        ]);

        $undoResult = $manager->undo();
        self::assertSame($case['afterUndo'], [
            'undoReturnedStackItem' => $undoResult,
            'json' => $doc->toJSON(),
            'text' => $text->toString(),
            'attributes' => $text->getAttributes(),
            'canUndo' => $manager->canUndo(),
            'canRedo' => $manager->canRedo(),
            'undoStackLength' => count($manager->undoStack()),
            'redoStackLength' => count($manager->redoStack()),
        ]);

        $redoResult = $manager->redo();
        self::assertSame($case['afterRedo'], [
            'redoReturnedStackItem' => $redoResult,
            'json' => $doc->toJSON(),
            'text' => $text->toString(),
            'attributes' => $text->getAttributes(),
            'canUndo' => $manager->canUndo(),
            'canRedo' => $manager->canRedo(),
            'undoStackLength' => count($manager->undoStack()),
            'redoStackLength' => count($manager->redoStack()),
            'events' => $events,
        ]);
    }

    public function testDynamicTrackedOriginsMatchYjsFixture(): void
    {
        $case = $this->loadUndoManagerFixture()['dynamicTrackedOrigin'];
        $doc = new YDoc(308);
        $text = $doc->getText('content');
        $text->insert(0, 'A');
        $manager = new UndoManager($doc, 'content', []);

        self::assertSame([], $manager->trackedOrigins());
        $manager->addTrackedOrigin('tracked-origin');
        $manager->addTrackedOrigin('tracked-origin');
        self::assertSame(['tracked-origin'], $manager->trackedOrigins());

        $doc->transact(static function () use ($text): void {
            $text->insert(1, 'B');
        }, 'tracked-origin');
        $manager->removeTrackedOrigin('tracked-origin');
        self::assertSame([], $manager->trackedOrigins());

        $doc->transact(static function () use ($text): void {
            $text->insert(2, 'C');
        }, 'tracked-origin');

        self::assertSame($case['textBeforeUndo'], $text->toString());
        self::assertSame($case['canUndo'], $manager->canUndo());
        self::assertSame($case['undoStackLength'], count($manager->undoStack()));

        self::assertTrue($manager->undo());
        self::assertSame($case['textAfterUndo'], $text->toString());
    }

    public function testDestroyStopsTrackingTransactions(): void
    {
        $doc = new YDoc(706);
        $doc->getArray('array')->insert(0, ['A']);
        $manager = new UndoManager($doc, 'array');

        $manager->destroy();
        $doc->getArray('array')->push(['B']);

        self::assertFalse($manager->canUndo());
        self::assertFalse($manager->undo());
        self::assertSame(['A', 'B'], $doc->getArray('array')->toArray());
    }

    public function testUndoRedoCanTargetNestedSharedTypeIds(): void
    {
        $doc = new YDoc(707);
        $root = $doc->getArray('array');
        $nestedArray = $root->insertArray(0);
        $nestedMap = $root->insertMap(1);
        $nestedText = $root->insertText(2);
        $nestedArray->insert(0, ['A']);
        $nestedMap->set('title', 'Draft');
        $nestedText->insert(0, 'Hi');
        $manager = new UndoManager($doc, [$nestedArray->idKey(), $nestedMap->idKey(), $nestedText->idKey()], ['nested-edit']);
        $transactionEvents = [];
        $doc->observeTransaction(static function (array $event) use (&$transactionEvents): void {
            $transactionEvents[] = $event;
        });

        $doc->transact(static function () use ($nestedArray, $nestedMap, $nestedText): void {
            $nestedArray->push(['B']);
            $nestedMap->set('title', 'Published');
            $nestedMap->set('status', 'ready');
            $nestedText->insert(2, '!');
        }, 'nested-edit');

        self::assertTrue($manager->undo());
        self::assertSame(['A'], $nestedArray->toArray());
        self::assertSame(['title' => 'Draft'], $nestedMap->toArray());
        self::assertSame('Hi', $nestedText->toString());

        self::assertTrue($manager->redo());
        self::assertSame(['A', 'B'], $nestedArray->toArray());
        $this->assertSameJson(['title' => 'Published', 'status' => 'ready'], $nestedMap->toArray());
        self::assertSame('Hi!', $nestedText->toString());

        self::assertSame(['A'], $transactionEvents[0]['beforeNested'][$nestedArray->idKey()]);
        self::assertSame(['A', 'B'], $transactionEvents[0]['afterNested'][$nestedArray->idKey()]);
        self::assertSame('Hi', $transactionEvents[0]['beforeNested'][$nestedText->idKey()]);
        self::assertSame('Hi!', $transactionEvents[0]['afterNested'][$nestedText->idKey()]);
    }

    public function testUndoRedoCanTargetXmlTextNodeIds(): void
    {
        $doc = new YDoc(715);
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $xmlText = $paragraph->insertText(0, 'Hi');
        $paragraph->appendElement('br');
        $manager = new UndoManager($doc, $xmlText->idKey(), ['xml-text-edit'], captureTimeout: 0);
        $events = [];
        $doc->observeTransaction(static function (array $event) use (&$events): void {
            $events[] = [
                'beforeXmlText' => $event['beforeXmlText'],
                'afterXmlText' => $event['afterXmlText'],
                'changedXmlNodes' => $event['changedXmlNodes'],
            ];
        });

        $doc->transact(static function () use ($xmlText): void {
            $xmlText->insert(2, '!');
        }, 'xml-text-edit');

        self::assertSame(['xml' => '<p>Hi!<br></br></p>'], $doc->toJSON());
        self::assertTrue($manager->undo());
        self::assertSame(['xml' => '<p>Hi<br></br></p>'], $doc->toJSON());
        self::assertInstanceOf(YXmlText::class, $paragraph->firstChild());
        self::assertTrue($manager->redo());
        self::assertSame(['xml' => '<p>Hi!<br></br></p>'], $doc->toJSON());

        self::assertSame('Hi', $events[0]['beforeXmlText'][$xmlText->idKey()]);
        self::assertSame('Hi!', $events[0]['afterXmlText'][$xmlText->idKey()]);
        self::assertContains($xmlText->idKey(), $events[0]['changedXmlNodes']);
    }

    public function testUndoRedoCanTargetXmlTextAttributeNodeIds(): void
    {
        $doc = new YDoc(731);
        $xmlText = $doc->getXmlFragment('xml')->insertText(0, 'Xml');
        $manager = new UndoManager($doc, $xmlText->idKey(), ['xml-text-attribute-edit'], captureTimeout: 0);

        $doc->transact(static function () use ($xmlText): void {
            $xmlText->setAttribute('lang', 'en');
            $xmlText->setAttribute('mark', true);
        }, 'xml-text-attribute-edit');

        self::assertSame(['lang' => 'en', 'mark' => true], $xmlText->getAttributes());
        self::assertTrue($manager->undo());
        self::assertSame([], $xmlText->getAttributes());
        self::assertTrue($manager->redo());
        self::assertSame(['lang' => 'en', 'mark' => true], $xmlText->getAttributes());
    }

    public function testUndoRedoCanTargetXmlElementAttributeNodeIds(): void
    {
        $doc = new YDoc(716);
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $paragraph->setAttribute('class', 'draft');
        $paragraph->appendText('Hi');
        $manager = new UndoManager($doc, $paragraph->idKey(), ['xml-attribute-edit'], captureTimeout: 0);
        $events = [];
        $doc->observeTransaction(static function (array $event) use (&$events): void {
            $events[] = [
                'beforeXmlAttributes' => $event['beforeXmlAttributes'],
                'afterXmlAttributes' => $event['afterXmlAttributes'],
                'changedXmlNodes' => $event['changedXmlNodes'],
            ];
        });

        $doc->transact(static function () use ($paragraph): void {
            $paragraph->setAttribute('class', 'published');
            $paragraph->setAttribute('data-id', '7');
        }, 'xml-attribute-edit');

        self::assertSame(['xml' => '<p class="published" data-id="7">Hi</p>'], $doc->toJSON());
        self::assertTrue($manager->undo());
        self::assertSame(['xml' => '<p class="draft">Hi</p>'], $doc->toJSON());
        self::assertTrue($manager->redo());
        self::assertSame(['xml' => '<p class="published" data-id="7">Hi</p>'], $doc->toJSON());

        self::assertSame(['class' => 'draft'], $events[0]['beforeXmlAttributes'][$paragraph->idKey()]);
        self::assertSame(['class' => 'published', 'data-id' => '7'], $events[0]['afterXmlAttributes'][$paragraph->idKey()]);
        self::assertContains($paragraph->idKey(), $events[0]['changedXmlNodes']);
    }

    public function testUndoRedoCanTargetXmlHookAttributeNodeIds(): void
    {
        $doc = new YDoc(717);
        $hook = $doc->getXmlFragment('xml')->insertHook(0, 'mention');
        $hook->set('label', 'Ada');
        $manager = new UndoManager($doc, $hook->idKey(), ['xml-hook-edit'], captureTimeout: 0);
        $events = [];
        $doc->observeTransaction(static function (array $event) use (&$events): void {
            $events[] = [
                'beforeXmlAttributes' => $event['beforeXmlAttributes'],
                'afterXmlAttributes' => $event['afterXmlAttributes'],
                'changedXmlNodes' => $event['changedXmlNodes'],
            ];
        });

        $doc->transact(static function () use ($hook): void {
            $hook->set('label', 'Grace');
            $hook->set('active', true);
        }, 'xml-hook-edit');

        self::assertInstanceOf(YXmlHook::class, $doc->getXmlFragment('xml')->get(0));
        self::assertSame(['active' => true, 'label' => 'Grace'], $hook->toArray());
        self::assertTrue($manager->undo());
        self::assertSame(['label' => 'Ada'], $hook->toArray());
        self::assertTrue($manager->canRedo());
        self::assertTrue($manager->redo());
        self::assertSame(['active' => true, 'label' => 'Grace'], $hook->toArray());

        self::assertSame(['label' => 'Ada'], $events[0]['beforeXmlAttributes'][$hook->idKey()]);
        self::assertSame(['active' => true, 'label' => 'Grace'], $events[0]['afterXmlAttributes'][$hook->idKey()]);
        self::assertContains($hook->idKey(), $events[0]['changedXmlNodes']);
    }

    public function testUndoRedoPreservesXmlElementSharedAttributeTypes(): void
    {
        $doc = new YDoc(726);
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $body = $paragraph->setText('body');
        $inline = $paragraph->setXmlElement('element', 'span');
        $manager = new UndoManager($doc, $paragraph->idKey(), ['xml-element-shared-attribute-edit'], captureTimeout: 0);

        $doc->transact(static function () use ($paragraph, $body, $inline): void {
            $paragraph->setAttribute('role', 'lead');
            $body->insert(0, 'Hi');
            $inline->setAttribute('class', 'lead');
            $inline->appendText('Xml');
        }, 'xml-element-shared-attribute-edit');

        self::assertSame([
            'body' => 'Hi',
            'element' => '<span class="lead">Xml</span>',
            'role' => 'lead',
        ], $paragraph->getAttributes());
        self::assertInstanceOf(YNestedText::class, $paragraph->getSharedType('body'));
        self::assertInstanceOf(YXmlElement::class, $paragraph->getSharedType('element'));

        self::assertTrue($manager->undo());
        self::assertSame([
            'body' => '',
            'element' => '<span></span>',
        ], $paragraph->getAttributes());
        self::assertInstanceOf(YNestedText::class, $paragraph->getSharedType('body'));
        self::assertInstanceOf(YXmlElement::class, $paragraph->getSharedType('element'));

        self::assertTrue($manager->redo());
        self::assertSame([
            'body' => 'Hi',
            'element' => '<span class="lead">Xml</span>',
            'role' => 'lead',
        ], $paragraph->getAttributes());
        self::assertInstanceOf(YNestedText::class, $paragraph->getSharedType('body'));
        self::assertInstanceOf(YXmlElement::class, $paragraph->getSharedType('element'));
    }

    public function testUndoRedoPreservesXmlTextSharedAttributeTypes(): void
    {
        $doc = new YDoc(727);
        $xmlText = $doc->getXmlFragment('xml')->insertText(0, 'Xml');
        $body = $xmlText->setText('body');
        $inline = $xmlText->setXmlElement('element', 'span');
        $label = $xmlText->setXmlText('label');
        $manager = new UndoManager($doc, $xmlText->idKey(), ['xml-text-shared-attribute-edit'], captureTimeout: 0);

        $doc->transact(static function () use ($xmlText, $body, $inline, $label): void {
            $xmlText->setAttribute('role', 'lead');
            $body->insert(0, 'Hi');
            $inline->setAttribute('class', 'lead');
            $inline->appendText('Xml');
            $label->insert(0, 'Label');
        }, 'xml-text-shared-attribute-edit');

        self::assertSame([
            'body' => 'Hi',
            'element' => '<span class="lead">Xml</span>',
            'label' => 'Label',
            'role' => 'lead',
        ], $xmlText->getAttributes());
        self::assertInstanceOf(YNestedText::class, $xmlText->getSharedType('body'));
        self::assertInstanceOf(YXmlElement::class, $xmlText->getSharedType('element'));
        self::assertInstanceOf(YXmlText::class, $xmlText->getSharedType('label'));

        self::assertTrue($manager->undo());
        self::assertSame([
            'body' => '',
            'element' => '<span></span>',
            'label' => '',
        ], $xmlText->getAttributes());
        self::assertInstanceOf(YNestedText::class, $xmlText->getSharedType('body'));
        self::assertInstanceOf(YXmlElement::class, $xmlText->getSharedType('element'));
        self::assertInstanceOf(YXmlText::class, $xmlText->getSharedType('label'));

        self::assertTrue($manager->redo());
        self::assertSame([
            'body' => 'Hi',
            'element' => '<span class="lead">Xml</span>',
            'label' => 'Label',
            'role' => 'lead',
        ], $xmlText->getAttributes());
        self::assertInstanceOf(YNestedText::class, $xmlText->getSharedType('body'));
        self::assertInstanceOf(YXmlElement::class, $xmlText->getSharedType('element'));
        self::assertInstanceOf(YXmlText::class, $xmlText->getSharedType('label'));
    }

    public function testUndoRedoCanTargetXmlRootChildStructure(): void
    {
        $doc = new YDoc(718);
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $paragraph->appendText('Hi');
        $manager = new UndoManager($doc, 'xml', ['xml-root-edit'], captureTimeout: 0);
        $events = [];
        $doc->observeTransaction(static function (array $event) use (&$events): void {
            $events[] = [
                'beforeXmlRootChildren' => $event['beforeXmlRootChildren'],
                'afterXmlRootChildren' => $event['afterXmlRootChildren'],
            ];
        });

        $doc->transact(static function () use ($doc): void {
            $doc->getXmlFragment('xml')->appendElement('br');
        }, 'xml-root-edit');

        self::assertSame(['xml' => '<p>Hi</p><br></br>'], $doc->toJSON());
        self::assertTrue($manager->undo());
        self::assertSame(['xml' => '<p>Hi</p>'], $doc->toJSON());
        self::assertTrue($manager->redo());
        self::assertSame(['xml' => '<p>Hi</p><br></br>'], $doc->toJSON());
        self::assertCount(1, $events[0]['beforeXmlRootChildren']['xml']);
        self::assertCount(2, $events[0]['afterXmlRootChildren']['xml']);
    }

    public function testUndoRedoCanRestoreBulkXmlRootChildren(): void
    {
        $doc = new YDoc(729);
        $fragment = $doc->getXmlFragment('xml');
        $fragment->appendText('lead');
        $manager = new UndoManager($doc, 'xml', ['xml-bulk-root-edit'], captureTimeout: 0);

        $doc->transact(static function () use ($fragment): void {
            $fragment->insertElements(1, ['section', 'aside']);
            $fragment->appendHooks(['mention', 'marker']);
        }, 'xml-bulk-root-edit');

        self::assertSame('lead<section></section><aside></aside>[object Object][object Object]', $fragment->toString());
        self::assertTrue($manager->undo());
        self::assertSame(['xml' => 'lead'], $doc->toJSON());
        self::assertTrue($manager->redo());
        self::assertSame(['xml' => 'lead<section></section><aside></aside>[object Object][object Object]'], $doc->toJSON());
        self::assertSame(5, $fragment->length());

        $hook = $fragment->get(3);
        self::assertInstanceOf(YXmlHook::class, $hook);
        self::assertSame('mention', $hook->hookName());
    }

    public function testUndoRedoCanTargetXmlElementChildStructure(): void
    {
        $doc = new YDoc(719);
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $paragraph->appendText('Hi');
        $manager = new UndoManager($doc, $paragraph->idKey(), ['xml-element-children-edit'], captureTimeout: 0);

        $doc->transact(static function () use ($paragraph): void {
            $strong = $paragraph->appendElement('strong');
            $text = $strong->appendText('Bold');
            $text->format(0, 4, ['bold' => true]);
            $hook = $paragraph->appendHook('mention');
            $hook->set('label', 'Ada');
        }, 'xml-element-children-edit');

        self::assertSame(['xml' => '<p>Hi<strong><bold>Bold</bold></strong>[object Object]</p>'], $doc->toJSON());
        self::assertTrue($manager->undo());
        self::assertSame(['xml' => '<p>Hi</p>'], $doc->toJSON());
        self::assertTrue($manager->redo());
        self::assertSame(['xml' => '<p>Hi<strong><bold>Bold</bold></strong>[object Object]</p>'], $doc->toJSON());

        $redoParagraph = $doc->getXmlFragment('xml')->get(0);
        self::assertInstanceOf(YXmlElement::class, $redoParagraph);
        $redoHook = $redoParagraph->get(2);
        self::assertInstanceOf(YXmlHook::class, $redoHook);
        self::assertSame(['label' => 'Ada'], $redoHook->toArray());
    }

    public function testUndoRedoMatchesYjsForXmlElementDeletedMixedChildren(): void
    {
        $fixture = $this->loadUndoManagerFixture()['xmlFragments']['elementDeleteMixedChildren'];
        $doc = new YDoc(325);
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $paragraph->appendText('Lead');
        $strong = $paragraph->appendElement('strong');
        $strong->setAttribute('data-id', 's1');
        $strongText = $strong->appendText('Bold');
        $strongText->format(0, 4, ['bold' => true]);
        $hook = $paragraph->appendHook('mention');
        $hook->set('label', 'Ada');
        $hook->set('kind', 'user');
        $paragraph->appendText('Tail');
        $manager = new UndoManager($doc, $paragraph->idKey(), ['xml-element-delete-mixed-children-edit'], captureTimeout: 0);

        $doc->transact(static function () use ($paragraph): void {
            $paragraph->delete(1, 2);
        }, 'xml-element-delete-mixed-children-edit');

        $this->assertSameJson($fixture['beforeUndo'], $this->xmlElementUndoState($doc, $paragraph, $manager));
        $undoResult = $manager->undo();
        $this->assertSameJson($fixture['afterUndo'], [
            'undoReturnedStackItem' => $undoResult,
            ...$this->xmlElementUndoState($doc, $paragraph, $manager),
        ]);
        $redoResult = $manager->redo();
        $this->assertSameJson($fixture['afterRedo'], [
            'redoReturnedStackItem' => $redoResult,
            ...$this->xmlElementUndoState($doc, $paragraph, $manager),
        ]);

        self::assertSame(['xml' => '<p>LeadTail</p>'], $doc->toJSON());
    }

    public function testUndoRedoMatchesYjsForNestedXmlFragmentInArray(): void
    {
        $fixture = $this->loadUndoManagerFixture()['xmlFragments']['nestedInArray'];
        $doc = new YDoc(323);
        $array = $doc->getArray('array');
        $fragment = $array->insertXmlFragment(0);
        $paragraph = $fragment->appendElement('p');
        $paragraph->appendText('Lead');
        $manager = new UndoManager($doc, $fragment->idKey(), ['nested-xml-fragment-array-edit'], captureTimeout: 0);
        $events = [];
        $manager->on('stack-item-added', static function (array $event) use (&$events): void {
            $events[] = ['type' => 'stack-item-added', 'stack' => $event['type']];
        });
        $manager->on('stack-item-popped', static function (array $event) use (&$events): void {
            $events[] = ['type' => 'stack-item-popped', 'stack' => $event['type']];
        });

        $doc->transact(static function () use ($fragment): void {
            $strong = $fragment->appendElement('strong');
            $strong->appendText('Bold');
            $hook = $fragment->appendHook('mention');
            $hook->set('label', 'Ada');
        }, 'nested-xml-fragment-array-edit');

        $this->assertSameJson($fixture['beforeUndo'], $this->nestedXmlFragmentUndoState($doc, $fragment, $manager));
        $undoResult = $manager->undo();
        $this->assertSameJson($fixture['afterUndo'], [
            'undoReturnedStackItem' => $undoResult,
            ...$this->nestedXmlFragmentUndoState($doc, $fragment, $manager),
        ]);
        $redoResult = $manager->redo();
        $this->assertSameJson($fixture['afterRedo'], [
            'redoReturnedStackItem' => $redoResult,
            ...$this->nestedXmlFragmentUndoState($doc, $fragment, $manager),
            'events' => $events,
        ]);

        self::assertInstanceOf(YXmlHook::class, $fragment->get(2));
    }

    public function testUndoRedoMatchesYjsForNestedXmlFragmentInMap(): void
    {
        $fixture = $this->loadUndoManagerFixture()['xmlFragments']['nestedInMap'];
        $doc = new YDoc(324);
        $map = $doc->getMap('map');
        $fragment = $map->setXmlFragment('xml');
        $paragraph = $fragment->appendElement('p');
        $paragraph->appendText('Base');
        $manager = new UndoManager($doc, $fragment->idKey(), ['nested-xml-fragment-map-edit'], captureTimeout: 0);

        $doc->transact(static function () use ($fragment): void {
            $fragment->delete(0, 1);
            $paragraph = $fragment->appendElement('p');
            $paragraph->appendText('Changed');
            $fragment->appendElement('aside');
        }, 'nested-xml-fragment-map-edit');

        $this->assertSameJson($fixture['beforeUndo'], $this->nestedXmlFragmentUndoState($doc, $fragment, $manager));
        $undoResult = $manager->undo();
        $this->assertSameJson($fixture['afterUndo'], [
            'undoReturnedStackItem' => $undoResult,
            ...$this->nestedXmlFragmentUndoState($doc, $fragment, $manager),
        ]);
        $redoResult = $manager->redo();
        $this->assertSameJson($fixture['afterRedo'], [
            'redoReturnedStackItem' => $redoResult,
            ...$this->nestedXmlFragmentUndoState($doc, $fragment, $manager),
        ]);

        self::assertSame('<p>Changed</p><aside></aside>', $fragment->toString());
    }

    public function testNestedUndoScopeDoesNotRestoreParentRootArray(): void
    {
        $doc = new YDoc(708);
        $root = $doc->getArray('array');
        $nested = $root->insertArray(0);
        $nested->insert(0, ['A']);
        $manager = new UndoManager($doc, $nested->idKey(), ['nested-and-root-edit']);

        $doc->transact(static function () use ($root, $nested): void {
            $nested->push(['B']);
            $root->push(['root-change']);
        }, 'nested-and-root-edit');

        self::assertTrue($manager->undo());
        self::assertSame(['A'], $nested->toArray());
        self::assertSame([['A'], 'root-change'], $root->toArray());
    }

    public function testAdjacentTransactionsMergeIntoSingleStackItemByDefault(): void
    {
        $doc = new YDoc(709);
        $doc->getText('content')->insert(0, 'A');
        $manager = new UndoManager($doc, 'content');

        $doc->getText('content')->insert(1, 'B');
        $doc->getText('content')->insert(2, 'C');

        self::assertCount(1, $manager->undoStack());
        self::assertTrue($manager->undo());
        self::assertSame('A', $doc->getText('content')->toString());
        self::assertTrue($manager->redo());
        self::assertSame('ABC', $doc->getText('content')->toString());
    }

    public function testStopCapturingSeparatesAdjacentTransactions(): void
    {
        $fixture = $this->loadUndoManagerFixture()['stopCapturing'];
        $doc = new YDoc(710);
        $doc->getText('content')->insert(0, 'A');
        $manager = new UndoManager($doc, 'content');

        $doc->getText('content')->insert(1, 'B');
        $manager->stopCapturing();
        $doc->getText('content')->insert(2, 'C');

        self::assertSame($fixture['beforeUndo'], [
            'text' => $doc->getText('content')->toString(),
            'canUndo' => $manager->canUndo(),
            'canRedo' => $manager->canRedo(),
            'undoStackLength' => count($manager->undoStack()),
            'redoStackLength' => count($manager->redoStack()),
        ]);

        $firstUndoResult = $manager->undo();
        self::assertSame($fixture['afterFirstUndo'], [
            'undoReturnedStackItem' => $firstUndoResult,
            'text' => $doc->getText('content')->toString(),
            'canUndo' => $manager->canUndo(),
            'canRedo' => $manager->canRedo(),
            'undoStackLength' => count($manager->undoStack()),
            'redoStackLength' => count($manager->redoStack()),
        ]);

        $secondUndoResult = $manager->undo();
        self::assertSame($fixture['afterSecondUndo'], [
            'undoReturnedStackItem' => $secondUndoResult,
            'text' => $doc->getText('content')->toString(),
            'canUndo' => $manager->canUndo(),
            'canRedo' => $manager->canRedo(),
            'undoStackLength' => count($manager->undoStack()),
            'redoStackLength' => count($manager->redoStack()),
        ]);
    }

    public function testCaptureTimeoutZeroSeparatesAdjacentTransactions(): void
    {
        $doc = new YDoc(711);
        $doc->getText('content')->insert(0, 'A');
        $manager = new UndoManager($doc, 'content', null, 0);

        $doc->getText('content')->insert(1, 'B');
        $doc->getText('content')->insert(2, 'C');

        self::assertCount(2, $manager->undoStack());
        self::assertTrue($manager->undo());
        self::assertSame('AB', $doc->getText('content')->toString());
    }

    public function testStackObserversReceiveAddPopAndClearEvents(): void
    {
        $fixtureEvents = $this->loadUndoManagerFixture()['stackEvents'];
        $doc = new YDoc(712);
        $doc->getText('content')->insert(0, 'A');
        $manager = new UndoManager($doc, 'content');
        $events = [];
        $manager->observeStack(static function (array $event) use (&$events): void {
            $events[] = [
                'type' => $event['type'],
                'stack' => $event['stack'],
                'merged' => $event['merged'],
                'changedParentTypeNames' => $event['changedParentTypeNames'] ?? [],
            ];
        });

        $doc->getText('content')->insert(1, 'B');
        $doc->getText('content')->insert(2, 'C');
        $manager->undo();
        $manager->clear();

        self::assertSame(array_map(
            static fn (array $event): array => [
                'type' => $event['type'],
                'stack' => $event['stack'] ?? null,
                'merged' => $event['type'] === 'stack-item-updated',
                'changedParentTypeNames' => $event['changedParentTypeNames'] ?? [],
            ],
            $fixtureEvents
        ), $events);
    }

    public function testEventEmitterAliasesMatchYjsFixture(): void
    {
        $fixtureEvents = $this->loadUndoManagerFixture()['eventEmitter'];
        $doc = new YDoc(716);
        $text = $doc->getText('content');
        $text->insert(0, 'A');
        $manager = new UndoManager($doc, 'content', captureTimeout: 0);
        $events = [];
        $added = static function (array $event) use (&$events): void {
            $origin = $event['origin'] ?? null;
            $events[] = [
                'listener' => 'on-added',
                'stack' => $event['type'],
                'origin' => $origin === null ? null : (new \ReflectionClass($origin))->getShortName(),
                'changedParentTypeNames' => $event['changedParentTypeNames'],
                'changedParentTypes' => $event['changedParentTypes'],
            ];
        };
        $manager->on('stack-item-added', $added);
        $manager->once('stack-item-popped', static function (array $event) use (&$events): void {
            $origin = $event['origin'] ?? null;
            $events[] = [
                'listener' => 'once-popped',
                'stack' => $event['type'],
                'origin' => $origin === null ? null : (new \ReflectionClass($origin))->getShortName(),
                'changedParentTypeNames' => $event['changedParentTypeNames'],
                'changedParentTypes' => $event['changedParentTypes'],
            ];
        });
        $manager->once('custom', static function (string $left, string $right) use (&$events): void {
            $events[] = ['listener' => 'custom-once', 'left' => $left, 'right' => $right];
        });

        $text->insert(1, 'B');
        $manager->off('stack-item-added', $added);
        $text->insert(2, 'C');
        $manager->undo();
        $manager->undo();
        $manager->emit('custom', ['left', 'right']);
        $manager->emit('custom', ['ignored', 'ignored']);

        self::assertSame($fixtureEvents, $events);
    }

    public function testEventEmitterListenerAddedDuringDispatchMatchesYjsFixture(): void
    {
        $fixtureCalls = $this->loadUndoManagerFixture()['listenerAddedDuringDispatch'];
        $doc = new YDoc(717);
        $text = $doc->getText('content');
        $manager = new UndoManager($doc, 'content', captureTimeout: 0);
        $calls = [];

        $manager->on('stack-item-added', static function () use (&$calls, $manager): void {
            $calls[] = 'first';
            $manager->on('stack-item-added', static function () use (&$calls): void {
                $calls[] = 'third';
            });
        });
        $manager->on('stack-item-added', static function () use (&$calls): void {
            $calls[] = 'second';
        });

        $text->insert(0, 'A');
        $text->insert(1, 'B');

        self::assertSame($fixtureCalls, $calls);
    }

    public function testStackObserverAddedDuringDispatchMatchesYjsEmitterFixture(): void
    {
        $fixtureCalls = $this->loadUndoManagerFixture()['listenerAddedDuringDispatch'];
        $doc = new YDoc(718);
        $text = $doc->getText('content');
        $manager = new UndoManager($doc, 'content', captureTimeout: 0);
        $calls = [];

        $manager->observeStack(static function () use (&$calls, $manager): void {
            $calls[] = 'first';
            $manager->observeStack(static function () use (&$calls): void {
                $calls[] = 'third';
            });
        });
        $manager->observeStack(static function () use (&$calls): void {
            $calls[] = 'second';
        });

        $text->insert(0, 'A');
        $text->insert(1, 'B');

        self::assertSame($fixtureCalls, $calls);
    }

    public function testClearCanTargetUndoAndRedoStacksLikeYjsFixture(): void
    {
        $fixture = $this->loadUndoManagerFixture()['clearOptions'];
        $doc = new YDoc(714);
        $text = $doc->getText('content');
        $text->insert(0, 'A');
        $manager = new UndoManager($doc, 'content', captureTimeout: 0);
        $events = [];
        $manager->observeStack(static function (array $event) use (&$events, $manager): void {
            if ($event['type'] !== 'stack-cleared') {
                return;
            }

            $events[] = [
                'undoStackCleared' => $event['undoStackCleared'],
                'redoStackCleared' => $event['redoStackCleared'],
                'canUndo' => $manager->canUndo(),
                'canRedo' => $manager->canRedo(),
            ];
        });

        $text->insert(1, 'B');
        $text->insert(2, 'C');
        self::assertTrue($manager->undo());

        $manager->clear(false, true);
        self::assertSame($fixture['afterRedoOnlyClear'], [
            'canUndo' => $manager->canUndo(),
            'canRedo' => $manager->canRedo(),
            'undoStackLength' => count($manager->undoStack()),
            'redoStackLength' => count($manager->redoStack()),
        ]);

        $manager->clear(true, false);
        self::assertSame($fixture['afterAllClear'], [
            'canUndo' => $manager->canUndo(),
            'canRedo' => $manager->canRedo(),
            'undoStackLength' => count($manager->undoStack()),
            'redoStackLength' => count($manager->redoStack()),
        ]);
        self::assertSame($fixture['events'], $events);
    }

    public function testDestroyDetachesCaptureWithoutClearingStacksLikeYjsFixture(): void
    {
        $fixture = $this->loadUndoManagerFixture()['destroy'];
        $doc = new YDoc(715);
        $text = $doc->getText('content');
        $text->insert(0, 'A');
        $manager = new UndoManager($doc, 'content', captureTimeout: 0);
        $events = [];
        $manager->observeStack(static function (array $event) use (&$events): void {
            if (in_array($event['type'], ['stack-item-popped', 'stack-cleared'], true)) {
                $events[] = [
                    'type' => $event['type'],
                    'stack' => $event['stack'],
                ];
            }
        });

        $text->insert(1, 'B');
        self::assertSame($fixture['beforeDestroy'], [
            'canUndo' => $manager->canUndo(),
            'canRedo' => $manager->canRedo(),
            'undoStackLength' => count($manager->undoStack()),
            'redoStackLength' => count($manager->redoStack()),
            'text' => $text->toString(),
        ]);

        $manager->destroy();
        self::assertSame($fixture['afterDestroy'], [
            'canUndo' => $manager->canUndo(),
            'canRedo' => $manager->canRedo(),
            'undoStackLength' => count($manager->undoStack()),
            'redoStackLength' => count($manager->redoStack()),
            'text' => $text->toString(),
        ]);

        $text->insert(2, 'C');
        self::assertSame($fixture['afterFutureEdit'], [
            'canUndo' => $manager->canUndo(),
            'canRedo' => $manager->canRedo(),
            'undoStackLength' => count($manager->undoStack()),
            'redoStackLength' => count($manager->redoStack()),
            'text' => $text->toString(),
        ]);

        $undoResult = $manager->undo();
        self::assertSame($fixture['afterUndo'], [
            'undoReturnedStackItem' => $undoResult,
            'canUndo' => $manager->canUndo(),
            'canRedo' => $manager->canRedo(),
            'undoStackLength' => count($manager->undoStack()),
            'redoStackLength' => count($manager->redoStack()),
            'text' => $text->toString(),
            'events' => $events,
        ]);
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     */
    private function assertSameJson(array $expected, array $actual): void
    {
        self::assertSame($this->sortAssociativeArrays($expected), $this->sortAssociativeArrays($actual));
    }

    /**
     * @return array<string, mixed>
     */
    private function nestedXmlFragmentUndoState(YDoc $doc, YNestedXmlFragment $fragment, UndoManager $manager): array
    {
        return [
            'json' => $doc->toJSON(),
            'fragment' => $fragment->toString(),
            'length' => $fragment->length(),
            'canUndo' => $manager->canUndo(),
            'canRedo' => $manager->canRedo(),
            'undoStackLength' => count($manager->undoStack()),
            'redoStackLength' => count($manager->redoStack()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function xmlElementUndoState(YDoc $doc, YXmlElement $element, UndoManager $manager): array
    {
        return [
            'json' => $doc->toJSON(),
            'element' => $element->toString(),
            'length' => $element->length(),
            'children' => array_map(fn (mixed $child): array => $this->xmlChildState($child), $element->toArray()),
            'canUndo' => $manager->canUndo(),
            'canRedo' => $manager->canRedo(),
            'undoStackLength' => count($manager->undoStack()),
            'redoStackLength' => count($manager->redoStack()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function xmlChildState(mixed $child): array
    {
        if ($child instanceof YXmlElement) {
            return [
                'type' => 'element',
                'nodeName' => $child->nodeName(),
                'attributes' => $child->getAttributes(),
                'string' => $child->toString(),
                'children' => array_map(fn (mixed $nestedChild): array => $this->xmlChildState($nestedChild), $child->toArray()),
            ];
        }

        if ($child instanceof YXmlText) {
            return [
                'type' => 'text',
                'string' => $child->toString(),
                'delta' => $child->toDelta(),
            ];
        }

        if ($child instanceof YXmlHook) {
            return [
                'type' => 'hook',
                'hookName' => $child->hookName(),
                'attributes' => $child->toArray(),
                'string' => $child->toString(),
            ];
        }

        return [
            'type' => 'text',
            'string' => (string) $child,
        ];
    }

    /**
     * @return array{
     *     originCases: list<array{name: string, canUndo: bool, undoStackLength: int, text: string}>,
     *     constructorTrackedOrigin: array{name: string, canUndo: bool, undoStackLength: int, text: string},
     *     selfTrackedOrigin: array{name: string, canUndo: bool, undoStackLength: int, text: string},
     *     captureTransaction: array{name: string, canUndo: bool, undoStackLength: int, textBeforeUndo: string, textAfterUndo: string},
     *     deleteFilter: array{name: string, canUndoBeforeUndo: bool, undoStackLengthBeforeUndo: int, undoReturnedStackItem: bool, textAfterUndo: string, canUndoAfterUndo: bool, canRedoAfterUndo: bool, undoStackLengthAfterUndo: int, redoStackLengthAfterUndo: int},
     *     remoteMapConflicts: list<array{name: string, ignoreRemoteMapChanges: bool, beforeUndo: array<string, mixed>, afterUndo: array<string, mixed>, undoReturnedStackItem: bool, canUndoAfterUndo: bool, canRedoAfterUndo: bool, undoStackLengthAfterUndo: int, redoStackLengthAfterUndo: int}>,
     *     addToScope: array{name: string, canUndo: bool, undoStackLength: int, undoReturnedStackItem: bool, afterUndo: array<string, mixed>, canRedoAfterUndo: bool, redoStackLengthAfterUndo: int},
     *     textAttributes: array{attributeOnly: array{name: string, beforeUndo: array<string, mixed>, afterUndo: array<string, mixed>, afterRedo: array<string, mixed>}, contentAndAttributes: array{name: string, beforeUndo: array<string, mixed>, afterUndo: array<string, mixed>, afterRedo: array<string, mixed>}, nestedAttributeOnly: array{name: string, beforeUndo: array<string, mixed>, afterUndo: array<string, mixed>, afterRedo: array<string, mixed>}, nestedContentAndAttributes: array{name: string, beforeUndo: array<string, mixed>, afterUndo: array<string, mixed>, afterRedo: array<string, mixed>}},
     *     xmlFragments: array{nestedInArray: array{name: string, beforeUndo: array<string, mixed>, afterUndo: array<string, mixed>, afterRedo: array<string, mixed>}, nestedInMap: array{name: string, beforeUndo: array<string, mixed>, afterUndo: array<string, mixed>, afterRedo: array<string, mixed>}, elementDeleteMixedChildren: array{name: string, beforeUndo: array<string, mixed>, afterUndo: array<string, mixed>, afterRedo: array<string, mixed>}},
     *     dynamicTrackedOrigin: array{name: string, canUndo: bool, undoStackLength: int, textBeforeUndo: string, textAfterUndo: string},
     *     stackEvents: list<array<string, mixed>>,
     *     clearOptions: array{events: list<array<string, mixed>>, afterRedoOnlyClear: array<string, mixed>, afterAllClear: array<string, mixed>},
     *     destroy: array{beforeDestroy: array<string, mixed>, afterDestroy: array<string, mixed>, afterFutureEdit: array<string, mixed>, afterUndo: array<string, mixed>}
     * }
     */
    private function loadUndoManagerFixture(): array
    {
        self::assertFileExists(self::FIXTURE_PATH, 'Run `npm run fixtures` before PHPUnit.');

        $decoded = json_decode((string) file_get_contents(self::FIXTURE_PATH), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function sortAssociativeArrays(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalized = array_map(fn (mixed $nested): mixed => $this->sortAssociativeArrays($nested), $value);
        if (array_is_list($normalized)) {
            return $normalized;
        }

        ksort($normalized);

        return $normalized;
    }
}

final class UndoTrackedOrigin
{
}
