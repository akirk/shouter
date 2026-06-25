<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yjs\YDoc;
use Yjs\YXmlElement;

/**
 * @return array<string, mixed>
 */
function snapshot(YDoc $doc): array
{
    return [
        'json' => $doc->toJSON(),
        'contentDelta' => $doc->getText('content')->toDelta(),
        'stateVector' => base64_encode($doc->encodeStateVector()),
    ];
}

function printSnapshot(string $label, YDoc $doc): void
{
    echo $label . ":\n";
    echo json_encode(snapshot($doc), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";
}

function normalized(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    foreach ($value as $key => $item) {
        $value[$key] = normalized($item);
    }

    if (! array_is_list($value)) {
        ksort($value);
    }

    return $value;
}

$base = new YDoc(1);
$base->getText('content')->insert(0, 'Hello');
$base->getArray('blocks')->push(['base']);
$base->getMap('meta')->set('title', 'Proof of life');
$paragraph = $base->getXmlFragment('xml')->insertElement(0, 'p');
$paragraph->insertText(0, 'Base XML');

$baseUpdate = $base->encodeStateAsUpdateV2();
$baseStateVector = $base->encodeStateVector();

$alice = new YDoc(2);
$bob = new YDoc(3);
$alice->applyUpdateV2($baseUpdate);
$bob->applyUpdateV2($baseUpdate);

$alice->getText('content')->insert(5, ' from Alice', ['author' => 'alice']);
$alice->getArray('blocks')->push(['alice-card']);
$alice->getMap('meta')->set('alice', ['cursor' => 5]);
$aliceParagraph = $alice->getXmlFragment('xml')->get(0);
if (! $aliceParagraph instanceof YXmlElement) {
    throw new RuntimeException('Expected Alice XML paragraph.');
}
$aliceParagraph->setAttribute('data-alice', 'yes');

$bob->getText('content')->insert(5, ' from Bob', ['author' => 'bob']);
$bob->getArray('blocks')->push(['bob-card']);
$bob->getMap('meta')->set('bob', ['cursor' => 5]);
$bobParagraph = $bob->getXmlFragment('xml')->get(0);
if (! $bobParagraph instanceof YXmlElement) {
    throw new RuntimeException('Expected Bob XML paragraph.');
}
$bobParagraph->appendText(' + Bob XML');

$aliceLocalUpdate = $alice->encodeStateAsUpdateV2($baseStateVector);
$bobLocalUpdate = $bob->encodeStateAsUpdateV2($baseStateVector);

echo "Yjs PHP proof of life: concurrent merge\n\n";
printSnapshot('Base document', $base);
printSnapshot('Alice before receiving Bob update', $alice);
printSnapshot('Bob before receiving Alice update', $bob);

$alice->applyUpdateV2($bobLocalUpdate, 'from-bob');
$bob->applyUpdateV2($aliceLocalUpdate, 'from-alice');

$aliceSnapshot = snapshot($alice);
$bobSnapshot = snapshot($bob);

if (normalized($aliceSnapshot['json']) !== normalized($bobSnapshot['json'])) {
    fwrite(STDERR, "Alice and Bob diverged after exchanging updates.\n");
    fwrite(STDERR, json_encode(['alice' => $aliceSnapshot, 'bob' => $bobSnapshot], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    exit(1);
}

if ($alice->encodeStateVector() !== $bob->encodeStateVector()) {
    fwrite(STDERR, "Alice and Bob state vectors diverged after exchanging updates.\n");
    exit(1);
}

printSnapshot('Alice after merge', $alice);
printSnapshot('Bob after merge', $bob);

echo "Merge result: OK\n";
echo sprintf("Alice local V2 update bytes: %d\n", strlen($aliceLocalUpdate));
echo sprintf("Bob local V2 update bytes: %d\n", strlen($bobLocalUpdate));
