<?php

declare(strict_types=1);

namespace Yjs\Tests\Compatibility;

use PHPUnit\Framework\TestCase;
use Yjs\YDoc;

final class YjsTransactionEventFixtureTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__ . '/../../fixtures/generated/yjs-13.6.31/transaction-events.json';

    public function testLocalTransactionEventMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture()['local'];
        $doc = new YDoc(301);
        $events = [];
        $doc->observeTransaction(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $doc->transact(static function (YDoc $doc): void {
            $text = $doc->getText('content');
            $text->insert(0, 'ABCD');
            $text->delete(1, 2);
        }, 'local-transaction-origin');

        self::assertCount(1, $events);
        $this->assertJsonMatchesFixture($fixture['json'], $doc->toJSON());
        $this->assertTransactionEventMatchesFixture($fixture['event'], $events[0], ['content']);
    }

    public function testRemoteTransactionEventMatchesYjsFixture(): void
    {
        $fixture = $this->loadFixture()['remote'];
        $updateV1 = base64_decode($fixture['updateV1'], true);
        $updateV2 = base64_decode($fixture['updateV2'], true);
        self::assertIsString($updateV1);
        self::assertIsString($updateV2);

        $doc = new YDoc();
        $events = [];
        $doc->observeTransaction(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $doc->applyUpdateV1($updateV1, 'remote-transaction-origin');

        self::assertCount(1, $events);
        $this->assertJsonMatchesFixture($fixture['json'], $doc->toJSON());
        $this->assertTransactionEventMatchesFixture($fixture['event'], $events[0], ['content']);

        $v2Doc = new YDoc();
        $v2Events = [];
        $v2Doc->observeTransaction(static function (array $event) use (&$v2Events): void {
            $v2Events[] = $event;
        });

        $v2Doc->applyUpdateV2($updateV2, 'remote-transaction-origin');

        self::assertCount(1, $v2Events);
        $this->assertJsonMatchesFixture($fixture['json'], $v2Doc->toJSON());
        $this->assertTransactionEventMatchesFixture($fixture['event'], $v2Events[0], ['content']);
    }

    public function testAdditionalLocalTransactionEventsMatchYjsFixtures(): void
    {
        $fixtures = $this->loadFixture()['cases'];
        self::assertIsArray($fixtures);

        foreach ($fixtures as $fixture) {
            self::assertIsArray($fixture);
            self::assertIsString($fixture['name']);

            [$doc, $events, $changed] = match ($fixture['name']) {
                'local-root-mixed' => $this->runLocalRootMixedTransaction(),
                'local-nested-array' => $this->runLocalNestedArrayTransaction(),
                'local-array-new-nested-text' => $this->runLocalArrayNewNestedTextTransaction(),
                'local-xml-parent-chain' => $this->runLocalXmlParentChainTransaction(),
                'local-map-replace-delete' => $this->runLocalMapReplaceDeleteTransaction(),
                'local-xml-text-attribute' => $this->runLocalXmlTextAttributeTransaction(),
                'local-xml-hook-attribute' => $this->runLocalXmlHookAttributeTransaction(),
                'local-xml-element-shared-attribute' => $this->runLocalXmlElementSharedAttributeTransaction(),
                'local-xml-text-shared-attribute' => $this->runLocalXmlTextSharedAttributeTransaction(),
                'local-nested-text-attribute' => $this->runLocalNestedTextAttributeTransaction(),
                'local-map-text' => $this->runLocalMapTextTransaction(),
                default => self::fail('Unhandled transaction fixture: ' . $fixture['name']),
            };

            self::assertCount(1, $events, $fixture['name']);
            $this->assertJsonMatchesFixture($fixture['json'], $doc->toJSON(), $fixture['name']);
            $this->assertTransactionEventMatchesFixture($fixture['event'], $events[0], $changed);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFixture(): array
    {
        self::assertFileExists(self::FIXTURE_PATH, 'Run `npm run fixtures` before PHPUnit.');

        $decoded = json_decode((string) file_get_contents(self::FIXTURE_PATH), true);

        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<string, mixed> $fixture
     * @param array<string, mixed> $event
     * @param list<string> $changed
     */
    private function assertTransactionEventMatchesFixture(array $fixture, array $event, array $changed): void
    {
        self::assertSame($fixture['origin'], $event['origin']);
        self::assertSame($fixture['local'], $event['local']);
        self::assertSame($this->normalizeIntegerMap($fixture['beforeStateVector']), $event['beforeStateVector']);
        self::assertSame($this->normalizeIntegerMap($fixture['afterStateVector']), $event['afterStateVector']);
        self::assertSame($this->normalizeDeleteSetFixture($fixture['deleteSet']), $event['deleteSet']);
        self::assertSame($changed, $event['changed'], $fixture['origin']);
        self::assertSame($fixture['changedTypeNames'], $event['changedTypeNames'], $fixture['origin']);
        self::assertSame($fixture['changedParentTypeNames'], $event['changedParentTypeNames'], $fixture['origin']);
        self::assertIsString($event['update']);
        self::assertIsString($event['updateV2']);
    }

    private function assertJsonMatchesFixture(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::assertSame($this->normalizeJsonObjectOrder($expected), $this->normalizeJsonObjectOrder($actual), $message);
    }

    private function normalizeJsonObjectOrder(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalized = array_map(fn (mixed $item): mixed => $this->normalizeJsonObjectOrder($item), $value);
        if (array_is_list($value)) {
            return $normalized;
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * @return array{0: YDoc, 1: list<array<string, mixed>>, 2: list<string>}
     */
    private function runLocalRootMixedTransaction(): array
    {
        $doc = new YDoc(303);
        $events = [];
        $doc->observeTransaction(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $doc->transact(static function (YDoc $doc): void {
            $doc->getText('content')->insert(0, 'Hi');
            $doc->getArray('array')->insert(0, ['A']);
            $doc->getMap('map')->set('title', 'Doc');
            $doc->getXmlFragment('xml')->insertElement(0, 'p');
        }, 'local-root-mixed-origin');

        return [$doc, $events, ['content', 'array', 'map', 'xml']];
    }

    /**
     * @return array{0: YDoc, 1: list<array<string, mixed>>, 2: list<string>}
     */
    private function runLocalNestedArrayTransaction(): array
    {
        $doc = new YDoc(304);
        $nestedArray = $doc->getArray('array')->insertArray(0);
        $events = [];
        $doc->observeTransaction(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $doc->transact(static function () use ($nestedArray): void {
            $nestedArray->insert(0, ['A', 'B']);
            $nestedArray->delete(0, 1);
        }, 'local-nested-array-origin');

        return [$doc, $events, []];
    }

    /**
     * @return array{0: YDoc, 1: list<array<string, mixed>>, 2: list<string>}
     */
    private function runLocalArrayNewNestedTextTransaction(): array
    {
        $doc = new YDoc(313);
        $array = $doc->getArray('array');
        $events = [];
        $doc->observeTransaction(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $doc->transact(static function () use ($array): void {
            $text = $array->insertText(0);
            $text->insert(0, 'Nested');
            $text->format(0, 6, ['bold' => true]);
        }, 'local-array-new-nested-text-origin');

        return [$doc, $events, ['array']];
    }

    /**
     * @return array{0: YDoc, 1: list<array<string, mixed>>, 2: list<string>}
     */
    private function runLocalXmlParentChainTransaction(): array
    {
        $doc = new YDoc(305);
        $paragraph = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $text = $paragraph->insertText(0, 'Hi');
        $events = [];
        $doc->observeTransaction(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $doc->transact(static function () use ($paragraph, $text): void {
            $paragraph->setAttribute('class', 'lead');
            $text->insert(2, '!');
        }, 'local-xml-parent-chain-origin');

        return [$doc, $events, ['xml']];
    }

    /**
     * @return array{0: YDoc, 1: list<array<string, mixed>>, 2: list<string>}
     */
    private function runLocalMapReplaceDeleteTransaction(): array
    {
        $doc = new YDoc(306);
        $map = $doc->getMap('map');
        $map->set('title', 'Draft');
        $map->set('status', 'ready');
        $events = [];
        $doc->observeTransaction(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $doc->transact(static function () use ($map): void {
            $map->set('title', 'Published');
            $map->delete('status');
            $map->set('flag', true);
        }, 'local-map-replace-delete-origin');

        return [$doc, $events, ['map']];
    }

    /**
     * @return array{0: YDoc, 1: list<array<string, mixed>>, 2: list<string>}
     */
    private function runLocalXmlTextAttributeTransaction(): array
    {
        $doc = new YDoc(307);
        $xmlText = $doc->getXmlFragment('xml')->insertText(0, 'Xml');
        $xmlText->setAttribute('lang', 'en');
        $events = [];
        $doc->observeTransaction(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $doc->transact(static function () use ($xmlText): void {
            $xmlText->setAttribute('lang', 'fr');
            $xmlText->setAttribute('mark', ['color' => 'green']);
        }, 'local-xml-text-attribute-origin');

        return [$doc, $events, []];
    }

    /**
     * @return array{0: YDoc, 1: list<array<string, mixed>>, 2: list<string>}
     */
    private function runLocalXmlHookAttributeTransaction(): array
    {
        $doc = new YDoc(308);
        $hook = $doc->getXmlFragment('xml')->insertHook(0, 'mention');
        $hook->set('role', 'base');
        $hook->set('removeMe', true);
        $events = [];
        $doc->observeTransaction(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $doc->transact(static function () use ($hook): void {
            $hook->set('role', 'lead');
            $hook->set('active', true);
            $hook->delete('removeMe');
        }, 'local-xml-hook-attribute-origin');

        return [$doc, $events, []];
    }

    /**
     * @return array{0: YDoc, 1: list<array<string, mixed>>, 2: list<string>}
     */
    private function runLocalXmlElementSharedAttributeTransaction(): array
    {
        $doc = new YDoc(312);
        $element = $doc->getXmlFragment('xml')->insertElement(0, 'p');
        $element->setAttribute('role', 'base');
        $body = $element->setText('body');
        $inline = $element->setXmlElement('inline', 'span');
        $events = [];
        $doc->observeTransaction(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $doc->transact(static function () use ($element, $body, $inline): void {
            $element->setAttribute('role', 'lead');
            $body->insert(0, 'Body');
            $inline->setAttribute('class', 'lead');
            $inline->insertText(0, 'Inline');
        }, 'local-xml-element-shared-attribute-origin');

        return [$doc, $events, ['xml']];
    }

    /**
     * @return array{0: YDoc, 1: list<array<string, mixed>>, 2: list<string>}
     */
    private function runLocalXmlTextSharedAttributeTransaction(): array
    {
        $doc = new YDoc(314);
        $xmlText = $doc->getXmlFragment('xml')->insertText(0, 'Xml');
        $xmlText->setAttribute('role', 'base');
        $body = $xmlText->setText('body');
        $inline = $xmlText->setXmlElement('inline', 'span');
        $events = [];
        $doc->observeTransaction(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $doc->transact(static function () use ($xmlText, $body, $inline): void {
            $xmlText->setAttribute('role', 'lead');
            $body->insert(0, 'Body');
            $inline->setAttribute('class', 'lead');
            $inline->insertText(0, 'Inline');
        }, 'local-xml-text-shared-attribute-origin');

        return [$doc, $events, []];
    }

    /**
     * @return array{0: YDoc, 1: list<array<string, mixed>>, 2: list<string>}
     */
    private function runLocalNestedTextAttributeTransaction(): array
    {
        $doc = new YDoc(309);
        $text = $doc->getArray('array')->insertText(0);
        $text->insert(0, 'Nested');
        $text->setAttribute('lang', 'en');
        $events = [];
        $doc->observeTransaction(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $doc->transact(static function () use ($text): void {
            $text->setAttribute('lang', 'fr');
            $text->setAttribute('mark', ['color' => 'green']);
            $text->removeAttribute('mark');
        }, 'local-nested-text-attribute-origin');

        return [$doc, $events, []];
    }

    /**
     * @return array{0: YDoc, 1: list<array<string, mixed>>, 2: list<string>}
     */
    private function runLocalMapTextTransaction(): array
    {
        $doc = new YDoc(310);
        $text = $doc->getMap('map')->setText('body');
        $text->insert(0, 'Map');
        $events = [];
        $doc->observeTransaction(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        $doc->transact(static function () use ($text): void {
            $text->setAttribute('lang', 'en');
            $text->insert(3, ' text', ['emphasis' => true]);
        }, 'local-map-text-origin');

        return [$doc, $events, []];
    }

    /**
     * @param array<string, int> $value
     * @return array<int, int>
     */
    private function normalizeIntegerMap(array $value): array
    {
        return array_map('intval', array_combine(array_map('intval', array_keys($value)), $value));
    }

    /**
     * @param array<string, list<array{clock: int, length: int}>> $deleteSet
     * @return array<int, list<array{clock: int, length: int}>>
     */
    private function normalizeDeleteSetFixture(array $deleteSet): array
    {
        $normalized = [];

        foreach ($deleteSet as $client => $ranges) {
            $normalized[(int) $client] = $ranges;
        }

        krsort($normalized, SORT_NUMERIC);

        return $normalized;
    }
}
