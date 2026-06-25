<?php

declare(strict_types=1);

namespace Yjs\Tests\Compatibility;

use PHPUnit\Framework\TestCase;
use Yjs\YDoc;

final class YjsTextAttributesFixtureTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__ . '/../../fixtures/generated/yjs-13.6.31/text-attributes.json';

    public function testTextAttributesMatchYjsFixtureAfterV1Update(): void
    {
        $doc = new YDoc();
        $doc->getText('content');
        $update = base64_decode($this->loadFixture()['withContent']['updateV1'], true);
        self::assertIsString($update);

        $doc->applyUpdateV1($update);

        $this->assertTextAttributesMatchFixture($doc, $this->loadFixture()['withContent']);
    }

    public function testTextAttributesMatchYjsFixtureAfterV2Update(): void
    {
        $doc = new YDoc();
        $doc->getText('content');
        $update = base64_decode($this->loadFixture()['withContent']['updateV2'], true);
        self::assertIsString($update);

        $doc->applyUpdateV2($update);

        $this->assertTextAttributesMatchFixture($doc, $this->loadFixture()['withContent']);
    }

    public function testAttributeOnlyTextAttributesMatchYjsFixtureAfterV1Update(): void
    {
        $doc = new YDoc();
        $doc->getText('content');
        $update = base64_decode($this->loadFixture()['attributeOnly']['updateV1'], true);
        self::assertIsString($update);

        $doc->applyUpdateV1($update);

        $this->assertTextAttributesMatchFixture($doc, $this->loadFixture()['attributeOnly'], 'jsonAfterGetText');
    }

    public function testAttributeOnlyTextAttributesMatchYjsFixtureAfterV2Update(): void
    {
        $doc = new YDoc();
        $doc->getText('content');
        $update = base64_decode($this->loadFixture()['attributeOnly']['updateV2'], true);
        self::assertIsString($update);

        $doc->applyUpdateV2($update);

        $this->assertTextAttributesMatchFixture($doc, $this->loadFixture()['attributeOnly'], 'jsonAfterGetText');
    }

    /**
     * @param array<string, mixed> $fixture
     */
    private function assertTextAttributesMatchFixture(YDoc $doc, array $fixture, string $jsonKey = 'json'): void
    {
        $text = $doc->getText('content');

        self::assertSame($fixture[$jsonKey], $doc->toJSON());
        self::assertSame($fixture['text'], $text->toString());
        self::assertSame($fixture['delta'], $text->toDelta());
        self::assertSame($fixture['attributes'], $text->getAttributes());
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
}
