<?php

declare(strict_types=1);

namespace Yjs\Tests\Compatibility;

use PHPUnit\Framework\TestCase;
use Yjs\YNestedText;
use Yjs\YDoc;
use Yjs\YText;
use Yjs\YXmlText;

final class YjsApplyDeltaFixtureTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__ . '/../../fixtures/generated/yjs-13.6.31/apply-delta.json';

    public function testApplyDeltaOptionsMatchYjsFixtures(): void
    {
        foreach ($this->loadFixtures()['cases'] as $case) {
            $doc = new YDoc();
            $text = $this->createTargetText($doc, $case);

            if ($case['seed'] !== null) {
                $text->insert(0, $case['seed']);
            }
            if ($case['seedDelta'] !== null) {
                $text->applyDelta($case['seedDelta']);
            }

            if ($case['options'] === null) {
                $text->applyDelta($case['delta']);
            } else {
                $text->applyDelta($case['delta'], $case['options']);
            }

            self::assertSame($case['expectedString'], $text->toString(), sprintf('Failed string case "%s".', $case['name']));
            self::assertSame($case['expectedDelta'], $text->toDelta(), sprintf('Failed delta case "%s".', $case['name']));
            if ($case['name'] !== 'root-sanitize-false-empty-newline') {
                self::assertSame($case['expectedJson'], $doc->toJSON(), sprintf('Failed JSON case "%s".', $case['name']));
            }
        }
    }

    /**
     * @param array{target: string} $case
     */
    private function createTargetText(YDoc $doc, array $case): YText|YNestedText|YXmlText
    {
        return match ($case['target']) {
            'root' => $doc->getText('content'),
            'nested' => $doc->getArray('items')->insertText(0),
            'map' => $doc->getMap('map')->setText('body'),
            'xml' => $doc->getXmlFragment('xml')->insertText(0, ''),
            default => throw new \InvalidArgumentException(sprintf('Unknown applyDelta target "%s".', $case['target'])),
        };
    }

    /**
     * @return array{cases: list<array<string, mixed>>}
     */
    private function loadFixtures(): array
    {
        self::assertFileExists(self::FIXTURE_PATH, 'Run `npm run fixtures` before PHPUnit.');

        $decoded = json_decode((string) file_get_contents(self::FIXTURE_PATH), true);

        self::assertIsArray($decoded);

        return $decoded;
    }
}
