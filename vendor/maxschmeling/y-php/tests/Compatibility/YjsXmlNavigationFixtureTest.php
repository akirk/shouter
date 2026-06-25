<?php

declare(strict_types=1);

namespace Yjs\Tests\Compatibility;

use PHPUnit\Framework\TestCase;
use Yjs\YDoc;
use Yjs\YXmlElement;
use Yjs\YXmlHook;
use Yjs\YXmlText;

final class YjsXmlNavigationFixtureTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__ . '/../../fixtures/generated/yjs-13.6.31/xml-navigation.json';
    private const INSERT_AFTER_FIXTURE_PATH = __DIR__ . '/../../fixtures/generated/yjs-13.6.31/xml-insert-after.json';
    private const TEXT_ATTRIBUTES_FIXTURE_PATH = __DIR__ . '/../../fixtures/generated/yjs-13.6.31/xml-text-attributes.json';

    public function testXmlNavigationMatchesYjsFixtureAfterV1Update(): void
    {
        $doc = new YDoc();
        $update = base64_decode($this->loadFixture()['updateV1'], true);
        self::assertIsString($update);

        $doc->applyUpdateV1($update);

        $this->assertNavigationMatchesFixture($doc);
    }

    public function testXmlNavigationMatchesYjsFixtureAfterV2Update(): void
    {
        $doc = new YDoc();
        $update = base64_decode($this->loadFixture()['updateV2'], true);
        self::assertIsString($update);

        $doc->applyUpdateV2($update);

        $this->assertNavigationMatchesFixture($doc);
    }

    public function testXmlInsertAfterMatchesYjsFixtureAfterV1Update(): void
    {
        $doc = new YDoc();
        $update = base64_decode($this->loadInsertAfterFixture()['updateV1'], true);
        self::assertIsString($update);

        $doc->applyUpdateV1($update);

        $this->assertInsertAfterMatchesFixture($doc);
    }

    public function testXmlInsertAfterMatchesYjsFixtureAfterV2Update(): void
    {
        $doc = new YDoc();
        $update = base64_decode($this->loadInsertAfterFixture()['updateV2'], true);
        self::assertIsString($update);

        $doc->applyUpdateV2($update);

        $this->assertInsertAfterMatchesFixture($doc);
    }

    public function testXmlTextAttributesMatchYjsFixtureAfterV1Update(): void
    {
        $doc = new YDoc();
        $update = base64_decode($this->loadTextAttributesFixture()['updateV1'], true);
        self::assertIsString($update);

        $doc->applyUpdateV1($update);

        $this->assertTextAttributesMatchFixture($doc);
    }

    public function testXmlTextAttributesMatchYjsFixtureAfterV2Update(): void
    {
        $doc = new YDoc();
        $update = base64_decode($this->loadTextAttributesFixture()['updateV2'], true);
        self::assertIsString($update);

        $doc->applyUpdateV2($update);

        $this->assertTextAttributesMatchFixture($doc);
    }

    private function assertNavigationMatchesFixture(YDoc $doc): void
    {
        $fixture = $this->loadFixture();
        $xml = $doc->getXmlFragment('xml');
        $article = $xml->querySelector('article');

        self::assertInstanceOf(YXmlElement::class, $article);
        self::assertInstanceOf(YXmlText::class, $xml->firstChild());
        self::assertCount(5, $xml->firstChild());
        self::assertSame($fixture['json'], $doc->toJSON());
        self::assertSame($fixture['firstChild'], $this->nodeSummary($xml->firstChild()));
        self::assertSame($fixture['sliceFromOne'], array_map($this->nodeSummary(...), $xml->slice(1)));
        self::assertSame($fixture['sliceNegativeOne'], array_map($this->nodeSummary(...), $xml->slice(-1)));
        self::assertSame($fixture['queryArticle'], $this->nodeSummary($article));
        self::assertSame($fixture['queryUppercaseArticle'], $this->nodeSummary($xml->querySelector('ARTICLE')));
        self::assertSame($fixture['queryStrong'], $this->nodeSummary($xml->querySelector('strong')));
        self::assertNull($xml->querySelector('missing'));
        self::assertSame($fixture['queryAllStrong'], array_map($this->nodeSummary(...), $xml->querySelectorAll('strong')));
        self::assertSame($fixture['queryAllUppercaseStrong'], array_map($this->nodeSummary(...), $xml->querySelectorAll('STRONG')));
        self::assertSame($fixture['treeWalker'], array_map($this->nodeSummary(...), $xml->createTreeWalker()));
        self::assertSame(
            $fixture['treeWalkerElements'],
            array_map(
                $this->nodeSummary(...),
                $xml->createTreeWalker(static fn (YXmlElement|YXmlText|YXmlHook $node): bool => $node instanceof YXmlElement)
            )
        );
        self::assertSame($fixture['fragmentJson'], $xml->toJSON());
        self::assertSame($fixture['articleFirstChild'], $this->nodeSummary($article->firstChild()));
        self::assertSame($fixture['articleSlice'], array_map($this->nodeSummary(...), $article->slice()));
        self::assertNull($article->querySelector('article'));
        self::assertSame($fixture['articleQueryStrong'], $this->nodeSummary($article->querySelector('strong')));
        self::assertSame($fixture['articleQueryUppercaseStrong'], $this->nodeSummary($article->querySelector('STRONG')));
        self::assertSame($fixture['articleQueryAllStrong'], array_map($this->nodeSummary(...), $article->querySelectorAll('strong')));
        self::assertSame($fixture['articleQueryAllUppercaseStrong'], array_map($this->nodeSummary(...), $article->querySelectorAll('STRONG')));
        self::assertSame($fixture['articleTreeWalker'], array_map($this->nodeSummary(...), $article->createTreeWalker()));
        self::assertSame(
            $fixture['articleTreeWalkerElements'],
            array_map(
                $this->nodeSummary(...),
                $article->createTreeWalker(static fn (YXmlElement|YXmlText|YXmlHook $node): bool => $node instanceof YXmlElement)
            )
        );
        self::assertSame($fixture['hook'], $this->nodeSummary($xml->get(3)));
    }

    private function assertInsertAfterMatchesFixture(YDoc $doc): void
    {
        $fixture = $this->loadInsertAfterFixture();
        $xml = $doc->getXmlFragment('xml');
        $article = $xml->querySelector('article');

        self::assertInstanceOf(YXmlElement::class, $article);
        self::assertSame($fixture['json'], $doc->toJSON());
        self::assertSame($fixture['fragmentJson'], $xml->toJSON());
        self::assertSame($fixture['fragmentChildren'], array_map($this->nodeSummary(...), $xml->slice()));
        self::assertSame($fixture['articleChildren'], array_map($this->nodeSummary(...), $article->slice()));
        self::assertSame($fixture['treeWalker'], array_map($this->nodeSummary(...), $xml->createTreeWalker()));
        self::assertSame($fixture['articleTreeWalker'], array_map($this->nodeSummary(...), $article->createTreeWalker()));
    }

    private function assertTextAttributesMatchFixture(YDoc $doc): void
    {
        $fixture = $this->loadTextAttributesFixture();
        $xmlText = $doc->getXmlFragment('xml')->get(0);

        self::assertInstanceOf(YXmlText::class, $xmlText);
        self::assertSame($fixture['json'], $doc->toJSON());
        self::assertSame($fixture['text'], $xmlText->toString());
        self::assertSame($fixture['delta'], $xmlText->toDelta());
        self::assertSame($fixture['attributes'], $xmlText->getAttributes());
        self::assertSame($fixture['lang'], $xmlText->getAttribute('lang'));
        self::assertFalse($xmlText->hasAttribute('temporary'));
        self::assertSame($fixture['hasTemporary'], $xmlText->hasAttribute('temporary'));
    }

    /**
     * @return array{type: string, nodeName: string|null, string: string, json: mixed}
     */
    private function nodeSummary(YXmlElement|YXmlText|YXmlHook|null $node): array
    {
        self::assertNotNull($node);

        return [
            'type' => (new \ReflectionClass($node))->getShortName(),
            'nodeName' => $node instanceof YXmlElement ? $node->nodeName() : null,
            'string' => $node->toString(),
            'json' => $node->toJSON(),
        ];
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
     * @return array<string, mixed>
     */
    private function loadInsertAfterFixture(): array
    {
        self::assertFileExists(self::INSERT_AFTER_FIXTURE_PATH, 'Run `npm run fixtures` before PHPUnit.');

        $decoded = json_decode((string) file_get_contents(self::INSERT_AFTER_FIXTURE_PATH), true);

        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadTextAttributesFixture(): array
    {
        self::assertFileExists(self::TEXT_ATTRIBUTES_FIXTURE_PATH, 'Run `npm run fixtures` before PHPUnit.');

        $decoded = json_decode((string) file_get_contents(self::TEXT_ATTRIBUTES_FIXTURE_PATH), true);

        self::assertIsArray($decoded);

        return $decoded;
    }
}
