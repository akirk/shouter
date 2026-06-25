<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Yjs\Sync\Awareness;
use Yjs\Sync\AwarenessProtocol;
use Yjs\Sync\AwarenessUpdate;
use Yjs\Sync\SyncProtocol;
use Yjs\UndefinedValue;
use Yjs\Update\DecodedUpdate;
use Yjs\YDoc;

$syncSource = new YDoc(301);
$syncSource->getText('content')->insert(0, 'Protocol');
$syncSource->getText('content')->insert(8, ' check');
$syncPrefix = new YDoc(301);
$syncPrefix->getText('content')->insert(0, 'Protocol');
$syncEmpty = new YDoc();

$textAttributePartialSource = new YDoc(307);
$textAttributePartialText = $textAttributePartialSource->getText('content');
$textAttributePartialText->insert(0, 'Text');
$textAttributePartialText->setAttribute('lang', 'base');
$textAttributePartialText->setAttribute('lang', 'en');
$textAttributePartialText->setAttribute('mark', ['color' => 'green']);
$textAttributePartialPrefix = new YDoc(307);
$textAttributePartialPrefix->getText('content')->insert(0, 'Text');

$nestedTextAttributePartialSource = new YDoc(310);
$nestedTextAttributePartialText = $nestedTextAttributePartialSource->getArray('array')->insertText(0);
$nestedTextAttributePartialText->insert(0, 'Nested');
$nestedTextAttributePartialText->setAttribute('lang', 'base');
$nestedTextAttributePartialText->setAttribute('lang', 'en');
$nestedTextAttributePartialText->setAttribute('mark', ['color' => 'green']);
$nestedTextAttributePartialPrefix = new YDoc(310);
$nestedTextAttributePartialPrefixText = $nestedTextAttributePartialPrefix->getArray('array')->insertText(0);
$nestedTextAttributePartialPrefixText->insert(0, 'Nested');

$mapXmlPartialSource = new YDoc(313);
$mapXmlPartialSource->getMap('map')->setXmlFragment('xml')->appendText('A😀C');
$mapXmlPartialPrefix = new YDoc(313);
$mapXmlPartialPrefix->getMap('map')->setXmlFragment('xml')->appendText('A');

$mapBulkPartialSource = new YDoc(326);
$mapBulkPartialSourceMap = $mapBulkPartialSource->getMap('map');
$mapBulkPartialSourceArrays = $mapBulkPartialSourceMap->setArrays(['items']);
$mapBulkPartialSourceTexts = $mapBulkPartialSourceMap->setTexts(['body']);
$mapBulkPartialSourceMaps = $mapBulkPartialSourceMap->setMaps(['meta']);
$mapBulkPartialSourceMaps['meta']->set('base', 'known');
$mapBulkPartialSourceFragments = $mapBulkPartialSourceMap->setXmlFragments(['xml']);
$mapBulkPartialSourceArrays['items']->push(['A', 'B']);
$mapBulkPartialSourceTexts['body']->insert(0, 'Bulk sync');
$mapBulkPartialSourceMaps['meta']->setAll(['count' => 2, 'ready' => true]);
$mapBulkPartialSourceFragments['xml']->appendText('A');
$mapBulkPartialSourceFragments['xml']->appendElement('strong')->appendText('B');
$mapBulkPartialSourceFragments['xml']->appendText('C');
$mapBulkPartialPrefix = new YDoc(326);
$mapBulkPartialPrefixMap = $mapBulkPartialPrefix->getMap('map');
$mapBulkPartialPrefixMap->setArrays(['items']);
$mapBulkPartialPrefixMap->setTexts(['body']);
$mapBulkPartialPrefixMaps = $mapBulkPartialPrefixMap->setMaps(['meta']);
$mapBulkPartialPrefixMaps['meta']->set('base', 'known');
$mapBulkPartialPrefixMap->setXmlFragments(['xml']);

$mapSubdocPartialSource = new YDoc(327);
$mapSubdocPartialSourceMap = $mapSubdocPartialSource->getMap('map');
$mapSubdocPartialSourceMap->set('known', 'before');
$mapSubdocPartialSourceMap->setSubdoc('child', 'php-sync-map-subdoc-child', [
    'autoLoad' => true,
    'meta' => ['kind' => 'sync-partial-map'],
]);
$mapSubdocPartialPrefix = new YDoc(327);
$mapSubdocPartialPrefix->getMap('map')->set('known', 'before');

$arraySubdocPartialSource = new YDoc(328);
$arraySubdocPartialSourceArray = $arraySubdocPartialSource->getArray('array');
$arraySubdocPartialSourceArray->insert(0, ['known']);
$arraySubdocPartialSourceArray->insertSubdoc(1, 'php-sync-array-subdoc-child', [
    'autoLoad' => true,
    'meta' => ['kind' => 'sync-partial-array'],
]);
$arraySubdocPartialPrefix = new YDoc(328);
$arraySubdocPartialPrefix->getArray('array')->insert(0, ['known']);

$arrayXmlPartialSource = new YDoc(325);
$arrayXmlPartialFragment = $arrayXmlPartialSource->getArray('array')->insertXmlFragment(0);
$arrayXmlPartialFragment->appendText('A');
$arrayXmlPartialParagraph = $arrayXmlPartialFragment->appendElement('p');
$arrayXmlPartialParagraph->appendText('B');
$arrayXmlPartialFragment->appendText('C');
$arrayXmlPartialPrefix = new YDoc(325);
$arrayXmlPartialPrefix->getArray('array')->insertXmlFragment(0)->appendText('A');

$xmlHookSharedPartialSource = new YDoc(330);
$xmlHookSharedPartialSourceHook = $xmlHookSharedPartialSource->getXmlFragment('xml')->insertHook(0, 'mention');
$xmlHookSharedPartialSourceHook->set('known', 'before');
$xmlHookSharedPartialSourceBody = $xmlHookSharedPartialSourceHook->setText('body');
$xmlHookSharedPartialSourceElement = $xmlHookSharedPartialSourceHook->setXmlElement('element', 'p');
$xmlHookSharedPartialSourceFragment = $xmlHookSharedPartialSourceHook->setXmlFragment('fragment');
$xmlHookSharedPartialPrefix = new YDoc(330);
$xmlHookSharedPartialPrefixHook = $xmlHookSharedPartialPrefix->getXmlFragment('xml')->insertHook(0, 'mention');
$xmlHookSharedPartialPrefixHook->set('known', 'before');
$xmlHookSharedPartialPrefixHook->setText('body');
$xmlHookSharedPartialPrefixHook->setXmlElement('element', 'p');
$xmlHookSharedPartialPrefixHook->setXmlFragment('fragment');
$xmlHookSharedPartialSourceBody->insert(0, 'Hook sync');
$xmlHookSharedPartialSourceElement->appendText('Element');
$xmlHookSharedPartialSourceFragment->appendText('Frag');

$xmlElementSharedPartialSource = new YDoc(331);
$xmlElementSharedPartialSourceElement = $xmlElementSharedPartialSource->getXmlFragment('xml')->insertElement(0, 'p');
$xmlElementSharedPartialSourceBody = $xmlElementSharedPartialSourceElement->setText('body');
$xmlElementSharedPartialSourceItems = $xmlElementSharedPartialSourceElement->setArray('items');
$xmlElementSharedPartialSourceInline = $xmlElementSharedPartialSourceElement->setXmlElement('element', 'span');
$xmlElementSharedPartialSourceText = $xmlElementSharedPartialSourceElement->setXmlText('text');
$xmlElementSharedPartialSourceFragment = $xmlElementSharedPartialSourceElement->setXmlFragment('fragment');
$xmlElementSharedPartialPrefix = new YDoc(331);
$xmlElementSharedPartialPrefixElement = $xmlElementSharedPartialPrefix->getXmlFragment('xml')->insertElement(0, 'p');
$xmlElementSharedPartialPrefixElement->setText('body');
$xmlElementSharedPartialPrefixElement->setArray('items');
$xmlElementSharedPartialPrefixElement->setXmlElement('element', 'span');
$xmlElementSharedPartialPrefixElement->setXmlText('text');
$xmlElementSharedPartialPrefixElement->setXmlFragment('fragment');
$xmlElementSharedPartialSourceBody->insert(0, 'Element sync');
$xmlElementSharedPartialSourceItems->insert(0, ['A', 'B']);
$xmlElementSharedPartialSourceInline->appendText('Inline');
$xmlElementSharedPartialSourceText->insert(0, 'Xml text');
$xmlElementSharedPartialSourceFragment->appendText('Frag');

$xmlTextSharedPartialSource = new YDoc(332);
$xmlTextSharedPartialSourceText = $xmlTextSharedPartialSource->getXmlFragment('xml')->insertText(0, 'Xml');
$xmlTextSharedPartialSourceBody = $xmlTextSharedPartialSourceText->setText('body');
$xmlTextSharedPartialSourceItems = $xmlTextSharedPartialSourceText->setArray('items');
$xmlTextSharedPartialSourceInline = $xmlTextSharedPartialSourceText->setXmlElement('element', 'span');
$xmlTextSharedPartialSourceTextAttribute = $xmlTextSharedPartialSourceText->setXmlText('text');
$xmlTextSharedPartialSourceFragment = $xmlTextSharedPartialSourceText->setXmlFragment('fragment');
$xmlTextSharedPartialPrefix = new YDoc(332);
$xmlTextSharedPartialPrefixText = $xmlTextSharedPartialPrefix->getXmlFragment('xml')->insertText(0, 'Xml');
$xmlTextSharedPartialPrefixText->setText('body');
$xmlTextSharedPartialPrefixText->setArray('items');
$xmlTextSharedPartialPrefixText->setXmlElement('element', 'span');
$xmlTextSharedPartialPrefixText->setXmlText('text');
$xmlTextSharedPartialPrefixText->setXmlFragment('fragment');
$xmlTextSharedPartialSourceBody->insert(0, 'Text sync');
$xmlTextSharedPartialSourceItems->insert(0, ['A', 'B']);
$xmlTextSharedPartialSourceInline->appendText('Inline');
$xmlTextSharedPartialSourceTextAttribute->insert(0, 'Xml text');
$xmlTextSharedPartialSourceFragment->appendText('Frag');

$xmlReplacePartialSource = new YDoc(329);
$xmlReplacePartialRoot = $xmlReplacePartialSource->getXmlFragment('xml')->insertElement(0, 'root');
$xmlReplacePartialText = $xmlReplacePartialRoot->insertText(0, 'A');
$xmlReplacePartialRoot->insertElement(1, 'em')->appendText('B');
$xmlReplacePartialRoot->insertText(2, 'T');
$xmlReplacePartialText->insert(1, '!');
$xmlReplacePartialRoot->delete(1, 1);
$xmlReplacePartialRoot->insertElement(1, 'strong')->appendText('C');
$xmlReplacePartialPrefix = new YDoc(329);
$xmlReplacePartialPrefixRoot = $xmlReplacePartialPrefix->getXmlFragment('xml')->insertElement(0, 'root');
$xmlReplacePartialPrefixRoot->insertText(0, 'A');
$xmlReplacePartialPrefixRoot->insertElement(1, 'em')->appendText('B');
$xmlReplacePartialPrefixRoot->insertText(2, 'T');

$threeWayConflictBase = new YDoc(321);
$threeWayConflictBase->getText('content')->insert(0, 'XY');
$threeWayConflictBaseUpdate = $threeWayConflictBase->encodeStateAsUpdateV1();
$threeWayConflictBaseStateVector = $threeWayConflictBase->encodeStateVector();
$threeWayConflictReplica = static function (int $clientId, string $value) use ($threeWayConflictBaseUpdate): YDoc {
    $doc = new YDoc($clientId);
    $doc->getText('content');
    $doc->applyUpdateV1($threeWayConflictBaseUpdate);
    $doc->getText('content')->insert(1, $value);

    return $doc;
};
$threeWayConflictFirst = $threeWayConflictReplica(1, 'A');
$threeWayConflictSecond = $threeWayConflictReplica(2, 'B');
$threeWayConflictThird = $threeWayConflictReplica(3, 'C');
$threeWayConflictFirstUpdate = $threeWayConflictFirst->encodeStateAsUpdateV1($threeWayConflictBaseStateVector);
$threeWayConflictSecondUpdate = $threeWayConflictSecond->encodeStateAsUpdateV1($threeWayConflictBaseStateVector);
$threeWayConflictThirdUpdate = $threeWayConflictThird->encodeStateAsUpdateV1($threeWayConflictBaseStateVector);
$threeWayConflictSource = new YDoc();
$threeWayConflictSource->getText('content');
$threeWayConflictSource->applyUpdateV1($threeWayConflictThirdUpdate);
$threeWayConflictSource->applyUpdateV1($threeWayConflictSecondUpdate);
$threeWayConflictSource->applyUpdateV1($threeWayConflictFirstUpdate);
$threeWayConflictSource->applyUpdateV1($threeWayConflictBaseUpdate);
$threeWayConflictPrefix = new YDoc();
$threeWayConflictPrefix->getText('content');
$threeWayConflictPrefix->applyUpdateV1($threeWayConflictBaseUpdate);
$threeWayConflictPrefix->applyUpdateV1($threeWayConflictFirstUpdate);

$xmlTextConflictBase = new YDoc(333);
$xmlTextConflictBase->getXmlFragment('xml')->insertElement(0, 'p')->insertText(0, 'XY');
$xmlTextConflictBaseUpdate = $xmlTextConflictBase->encodeStateAsUpdateV1();
$xmlTextConflictBaseStateVector = $xmlTextConflictBase->encodeStateVector();
$xmlTextConflictReplica = static function (int $clientId, string $value) use ($xmlTextConflictBaseUpdate): YDoc {
    $doc = new YDoc($clientId);
    $doc->getXmlFragment('xml');
    $doc->applyUpdateV1($xmlTextConflictBaseUpdate);
    $doc->getXmlFragment('xml')->get(0)->get(0)->insert(1, $value);

    return $doc;
};
$xmlTextConflictFirst = $xmlTextConflictReplica(334, 'A');
$xmlTextConflictSecond = $xmlTextConflictReplica(335, 'B');
$xmlTextConflictDelete = new YDoc(336);
$xmlTextConflictDelete->getXmlFragment('xml');
$xmlTextConflictDelete->applyUpdateV1($xmlTextConflictBaseUpdate);
$xmlTextConflictDelete->getXmlFragment('xml')->get(0)->get(0)->delete(0, 1);
$xmlTextConflictFirstUpdate = $xmlTextConflictFirst->encodeStateAsUpdateV1($xmlTextConflictBaseStateVector);
$xmlTextConflictSecondUpdate = $xmlTextConflictSecond->encodeStateAsUpdateV1($xmlTextConflictBaseStateVector);
$xmlTextConflictDeleteUpdate = $xmlTextConflictDelete->encodeStateAsUpdateV1($xmlTextConflictBaseStateVector);
$xmlTextConflictSource = new YDoc();
$xmlTextConflictSource->getXmlFragment('xml');
$xmlTextConflictSource->applyUpdateV1($xmlTextConflictDeleteUpdate);
$xmlTextConflictSource->applyUpdateV1($xmlTextConflictSecondUpdate);
$xmlTextConflictSource->applyUpdateV1($xmlTextConflictFirstUpdate);
$xmlTextConflictSource->applyUpdateV1($xmlTextConflictBaseUpdate);
$xmlTextConflictSourceText = $xmlTextConflictSource->getXmlFragment('xml')->get(0)->get(0);
$xmlTextConflictPrefix = new YDoc();
$xmlTextConflictPrefix->getXmlFragment('xml');
$xmlTextConflictPrefix->applyUpdateV1($xmlTextConflictBaseUpdate);
$xmlTextConflictPrefix->applyUpdateV1($xmlTextConflictFirstUpdate);

$syncUpdateV1 = $syncSource->encodeStateAsUpdateV1();
$syncUpdateV2 = $syncSource->encodeStateAsUpdateV2();

$observedSyncSource = new YDoc(305);
$observedSyncMessages = [];
$observedSyncObserver = SyncProtocol::observeUpdateMessages($observedSyncSource, static function (string $message) use (&$observedSyncMessages): void {
    $observedSyncMessages[] = base64_encode($message);
});
$observedSyncSource->getText('content')->insert(0, 'A');
$observedSyncSource->getText('content')->insert(1, 'B');
SyncProtocol::unobserveUpdateMessages($observedSyncSource, $observedSyncObserver);
$observedSyncSource->getText('content')->insert(2, 'ignored');

$observedSyncV2Source = new YDoc(306);
$observedSyncV2Messages = [];
$observedSyncV2Observer = SyncProtocol::observeUpdateV2Messages($observedSyncV2Source, static function (string $message) use (&$observedSyncV2Messages): void {
    $observedSyncV2Messages[] = base64_encode($message);
});
$observedSyncV2Source->getText('content')->insert(0, 'V');
$observedSyncV2Source->getText('content')->insert(1, '2');
SyncProtocol::unobserveUpdateV2Messages($observedSyncV2Source, $observedSyncV2Observer);
$observedSyncV2Source->getText('content')->insert(2, 'ignored');

$observedRichSyncSource = new YDoc(312);
$observedRichSyncMessages = [];
$observedRichSyncV2Messages = [];
$observedRichSyncObserver = SyncProtocol::observeUpdateMessages($observedRichSyncSource, static function (string $message) use (&$observedRichSyncMessages): void {
    $observedRichSyncMessages[] = base64_encode($message);
});
$observedRichSyncV2Observer = SyncProtocol::observeUpdateV2Messages($observedRichSyncSource, static function (string $message) use (&$observedRichSyncV2Messages): void {
    $observedRichSyncV2Messages[] = base64_encode($message);
});
$observedRichNestedText = $observedRichSyncSource->getArray('array')->insertText(0);
$observedRichNestedText->insert(0, 'Nested');
$observedRichNestedText->setAttribute('lang', 'en');
$observedRichArrayFragment = $observedRichSyncSource->getArray('array')->insertXmlFragment(1);
$observedRichArrayFragment->appendText('Array ');
$observedRichArrayFragmentParagraph = $observedRichArrayFragment->appendElement('p');
$observedRichArrayFragmentParagraph->insertText(0, 'XML');
$observedRichMapText = $observedRichSyncSource->getMap('map')->setText('body');
$observedRichMapText->insert(0, 'Map');
$observedRichMapText->format(0, 3, ['bold' => true]);
$observedRichMapText->insert(3, ' text', ['italic' => true]);
$observedRichMapText->setAttribute('lang', 'en');
$observedRichMapFragment = $observedRichSyncSource->getMap('map')->setXmlFragment('xml');
$observedRichMapFragment->appendText('Map ');
$observedRichMapFragmentParagraph = $observedRichMapFragment->appendElement('p');
$observedRichMapFragmentParagraph->insertText(0, 'XML');
$observedRichDeepMap = $observedRichSyncSource->getMap('map')->setMap('deep');
$observedRichDeepItems = $observedRichDeepMap->setArray('items');
$observedRichDeepItems->insert(0, ['A', 'B']);
$observedRichDeepText = $observedRichDeepMap->setText('body');
$observedRichDeepText->insert(0, 'Deep sync');
$observedRichDeepText->format(0, 4, ['strong' => true]);
$observedRichDeepMap->setBinary('bytes', "\x05\x06");
$observedRichParagraph = $observedRichSyncSource->getXmlFragment('xml')->insertElement(0, 'p');
$observedRichParagraph->setAttribute('class', 'lead');
$observedRichParagraphText = $observedRichParagraph->insertText(0, 'Hi');
$observedRichParagraphText->insert(2, '!');
$observedRichExpectedJson = $observedRichSyncSource->toJSON();
$observedRichExpectedNestedTextAttributes = $observedRichNestedText->getAttributes();
$observedRichExpectedMapTextAttributes = $observedRichMapText->getAttributes();
$observedRichExpectedDeepMapTextDelta = $observedRichDeepText->toDelta();
SyncProtocol::unobserveUpdateMessages($observedRichSyncSource, $observedRichSyncObserver);
SyncProtocol::unobserveUpdateV2Messages($observedRichSyncSource, $observedRichSyncV2Observer);
$observedRichNestedText->setAttribute('ignored', true);
$observedRichMapText->setAttribute('ignored', true);
$observedRichDeepText->setAttribute('ignored', true);

$rawGcStructs = [
    [
        'type' => 'GC',
        'id' => ['client' => 245, 'clock' => 0],
        'length' => 3,
    ],
];
$rawGcUpdateV1 = DecodedUpdate::encodeV1($rawGcStructs);
$rawGcUpdateV2 = DecodedUpdate::encodeV2($rawGcStructs);
$rawGcSource = new YDoc();
$rawGcSource->applyUpdateV1($rawGcUpdateV1);

$deleteSource = new YDoc(302);
$deleteSource->getText('content')->insert(0, 'ABCD');
$deleteSource->getText('content')->delete(1, 2);
$deletePrefix = new YDoc(302);
$deletePrefix->getText('content')->insert(0, 'ABCD');

$gcPartialSource = new YDoc(304, gc: true);
$gcPartialSource->getText('content')->insert(0, 'ABCD');
$gcPartialSource->getText('content')->delete(1, 2);
$gcPartialPrefix = new YDoc(304);
$gcPartialPrefix->getText('content')->insert(0, 'AB');

$reinsertSource = new YDoc(303);
$reinsertSource->getArray('array')->insert(0, ['A', 'B', 'C']);
$reinsertRoot = $reinsertSource->getXmlFragment('xml')->insertElement(0, 'root');
$reinsertRoot->insertElement(0, 'a');
$reinsertRoot->insertElement(1, 'b');
$reinsertRoot->insertElement(2, 'c');
$reinsertSource->getArray('array')->delete(1, 1);
$reinsertSource->getArray('array')->insert(1, ['X']);
$reinsertRoot->delete(1, 1);
$reinsertRoot->insertElement(1, 'x');

$reinsertPrefix = new YDoc(303);
$reinsertPrefix->getArray('array')->insert(0, ['A', 'B', 'C']);
$reinsertPrefixRoot = $reinsertPrefix->getXmlFragment('xml')->insertElement(0, 'root');
$reinsertPrefixRoot->insertElement(0, 'a');
$reinsertPrefixRoot->insertElement(1, 'b');
$reinsertPrefixRoot->insertElement(2, 'c');

$awareness = new Awareness();
$awareness->setLocalState(77, ['user' => ['name' => 'Ada']]);
$awareness->setLocalStateField(77, 'cursor', ['anchor' => 2, 'head' => 5]);
$awarenessQueryMessage = AwarenessProtocol::writeQuery();
$awarenessStateMessage = AwarenessProtocol::writeState($awareness, [77]);
$awarenessQueryReplyMessage = AwarenessProtocol::writeReplyToQuery($awareness, $awarenessQueryMessage, [77]);
$awarenessHandledQueryReplyMessage = AwarenessProtocol::handleMessageWithReply($awareness, $awarenessQueryMessage, null, [77]);
$awarenessRemoveMessage = AwarenessProtocol::writeRemoveStates($awareness, [77], 'php-disconnect');

$undefinedAwareness = new Awareness();
$undefinedAwareness->setLocalState(81, [
    'user' => [
        'name' => 'Ada',
        'badge' => UndefinedValue::instance(),
    ],
    'items' => [
        UndefinedValue::instance(),
        null,
        [
            'hidden' => UndefinedValue::instance(),
            'visible' => true,
        ],
    ],
]);
$undefinedAwarenessMessage = AwarenessProtocol::writeState($undefinedAwareness, [81]);

$specialNumberAwareness = new Awareness();
$specialNumberAwareness->setLocalState(82, [
    'metrics' => [
        'nan' => NAN,
        'positiveInfinity' => INF,
        'negativeInfinity' => -INF,
        'finite' => 1.5,
    ],
    'list' => [NAN, INF, -INF, 3],
]);
$specialNumberAwarenessMessage = AwarenessProtocol::writeState($specialNumberAwareness, [82]);

$clearAwareness = new Awareness();
$clearAwareness->setLocalState(90, ['user' => ['name' => 'Lin']]);
$clearAwareness->setLocalState(91, ['user' => ['name' => 'Mira']]);
$awarenessClearStateMessage = AwarenessProtocol::writeState($clearAwareness);
$awarenessClearMessage = AwarenessProtocol::writeUpdate($clearAwareness->clear('php-awareness-clear'));

$batchAwareness = new Awareness();
$batchAwarenessStateMessage = AwarenessProtocol::writeUpdate($batchAwareness->setLocalStates([
    94 => ['user' => ['name' => 'Noor'], 'cursor' => ['anchor' => 4, 'head' => 8]],
    95 => ['user' => ['name' => 'Ola']],
], 'php-awareness-batch'));

$filteredAwareness = new Awareness();
$filteredAwareness->setLocalState(96, ['user' => ['name' => 'Pia']]);
$filteredAwareness->setLocalState(97, ['user' => ['name' => 'Remy']]);
$filteredAwareness->setLocalState(98, null);
$filteredAwarenessStateMessage = AwarenessProtocol::writeState($filteredAwareness, [96, 98, 404]);
$filteredAwarenessInitialMessage = AwarenessProtocol::writeState($filteredAwareness, [96, 97]);
$filteredAwarenessRemoveMessage = AwarenessProtocol::writeRemoveStates($filteredAwareness, [97, 404], 'php-awareness-filtered-remove');

$observedAwareness = new Awareness();
$observedAwarenessMessages = [];
$observedAwarenessObserver = AwarenessProtocol::observeUpdateMessages($observedAwareness, static function (string $message) use (&$observedAwarenessMessages): void {
    $observedAwarenessMessages[] = base64_encode($message);
});
$observedAwareness->setLocalState(88, ['user' => ['name' => 'Grace']]);
$observedAwareness->setLocalStateField(88, 'cursor', ['anchor' => 1, 'head' => 1]);
$observedAwareness->removeStates([88], 'php-awareness-remove');
AwarenessProtocol::unobserveUpdateMessages($observedAwareness, $observedAwarenessObserver);
$observedAwareness->setLocalState(89, ['user' => ['name' => 'Ignored']]);

$timeoutAwareness = new Awareness();
$timeoutAwareness->setLocalState(92, ['user' => ['name' => 'Quinn']]);
$timeoutAwareness->setLocalState(93, ['user' => ['name' => 'Rhea']]);
$timeoutAwarenessStateMessage = AwarenessProtocol::writeState($timeoutAwareness);
$timeoutAwarenessMessages = [];
$timeoutAwarenessEvents = [];
$timeoutAwarenessObserver = AwarenessProtocol::observeUpdateMessages($timeoutAwareness, static function (string $message, Awareness $awareness, mixed $origin, array $event) use (&$timeoutAwarenessMessages, &$timeoutAwarenessEvents): void {
    $timeoutAwarenessMessages[] = base64_encode($message);
    $timeoutAwarenessEvents[] = [
        'origin' => $origin,
        'added' => $event['added'],
        'updated' => $event['updated'],
        'removed' => $event['removed'],
    ];
});
$timeoutAwarenessMeta = $timeoutAwareness->getMeta();
$timeoutAt = max(array_column($timeoutAwarenessMeta, 'lastUpdated')) + Awareness::OUTDATED_TIMEOUT;
$timeoutAwarenessRemoved = $timeoutAwareness->removeOutdatedStates($timeoutAt, origin: 'php-awareness-timeout');
AwarenessProtocol::unobserveUpdateMessages($timeoutAwareness, $timeoutAwarenessObserver);

$staleAwarenessMessages = [
    AwarenessProtocol::writeUpdate(AwarenessUpdate::encode([
        [
            'clientID' => 77,
            'clock' => 2,
            'state' => ['user' => ['name' => 'Ada']],
        ],
    ])),
    AwarenessProtocol::writeUpdate(AwarenessUpdate::encode([
        [
            'clientID' => 77,
            'clock' => 3,
            'state' => null,
        ],
    ])),
    AwarenessProtocol::writeUpdate(AwarenessUpdate::encode([
        [
            'clientID' => 77,
            'clock' => 2,
            'state' => ['user' => ['name' => 'Grace']],
        ],
    ])),
    AwarenessProtocol::writeUpdate(AwarenessUpdate::encode([
        [
            'clientID' => 77,
            'clock' => 3,
            'state' => ['user' => ['name' => 'Lin']],
        ],
    ])),
    AwarenessProtocol::writeUpdate(AwarenessUpdate::encode([
        [
            'clientID' => 77,
            'clock' => 3,
            'state' => null,
        ],
    ])),
];

$sameClockRemoveAwarenessMessages = [
    AwarenessProtocol::writeUpdate(AwarenessUpdate::encode([
        [
            'clientID' => 83,
            'clock' => 5,
            'state' => ['user' => ['name' => 'Same']],
        ],
    ])),
    AwarenessProtocol::writeUpdate(AwarenessUpdate::encode([
        [
            'clientID' => 83,
            'clock' => 5,
            'state' => null,
        ],
    ])),
    AwarenessProtocol::writeUpdate(AwarenessUpdate::encode([
        [
            'clientID' => 83,
            'clock' => 5,
            'state' => null,
        ],
    ])),
];

echo json_encode([
    'sync' => [
        'expectedJson' => $syncSource->toJSON(),
        'stateVectorV1' => base64_encode($syncSource->encodeStateVector()),
        'syncStep1FromEmpty' => base64_encode(SyncProtocol::writeSyncStep1($syncEmpty)),
        'syncStep2ForEmpty' => base64_encode(SyncProtocol::writeSyncStep2($syncSource, $syncEmpty->encodeStateVector())),
        'syncStep2V2ForEmpty' => base64_encode(SyncProtocol::writeSyncStep2V2($syncSource, $syncEmpty->encodeStateVector())),
        'handledSyncStep2ForEmpty' => base64_encode((string) SyncProtocol::handleMessage($syncSource, SyncProtocol::writeSyncStep1($syncEmpty))),
        'handledSyncStep2V2ForEmpty' => base64_encode((string) SyncProtocol::handleMessageV2($syncSource, SyncProtocol::writeSyncStep1($syncEmpty))),
        'updateMessage' => base64_encode(SyncProtocol::writeUpdate($syncUpdateV1)),
        'updateV2Message' => base64_encode(SyncProtocol::writeUpdateV2($syncUpdateV2)),
        'observedUpdateMessages' => [
            'expectedJson' => ['content' => 'AB'],
            'messages' => $observedSyncMessages,
        ],
        'observedUpdateV2Messages' => [
            'expectedJson' => ['content' => 'V2'],
            'messages' => $observedSyncV2Messages,
        ],
        'observedRichUpdateMessages' => [
            'expectedJson' => $observedRichExpectedJson,
            'expectedNestedTextAttributes' => $observedRichExpectedNestedTextAttributes,
            'expectedMapTextKey' => 'body',
            'expectedMapTextAttributes' => $observedRichExpectedMapTextAttributes,
            'expectedDeepMapKey' => 'deep',
            'expectedDeepMapTextKey' => 'body',
            'expectedDeepMapTextDelta' => $observedRichExpectedDeepMapTextDelta,
            'expectedArrayXmlFragments' => [
                ['index' => 1, 'xml' => 'Array <p>XML</p>'],
            ],
            'expectedMapXmlFragments' => [
                ['key' => 'xml', 'xml' => 'Map <p>XML</p>'],
            ],
            'messages' => $observedRichSyncMessages,
            'messagesV2' => $observedRichSyncV2Messages,
        ],
        'partial' => [
            'prefixJson' => $syncPrefix->toJSON(),
            'prefixStateVectorV1' => base64_encode($syncPrefix->encodeStateVector()),
            'prefixUpdateV1' => base64_encode($syncPrefix->encodeStateAsUpdateV1()),
            'prefixUpdateV2' => base64_encode($syncPrefix->encodeStateAsUpdateV2()),
            'syncStep1FromPrefix' => base64_encode(SyncProtocol::writeSyncStep1($syncPrefix)),
            'syncStep2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2($syncSource, $syncPrefix->encodeStateVector())),
            'syncStep2V2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2V2($syncSource, $syncPrefix->encodeStateVector())),
            'handledSyncStep2ForPrefix' => base64_encode((string) SyncProtocol::handleMessage($syncSource, SyncProtocol::writeSyncStep1($syncPrefix))),
            'handledSyncStep2V2ForPrefix' => base64_encode((string) SyncProtocol::handleMessageV2($syncSource, SyncProtocol::writeSyncStep1($syncPrefix))),
        ],
        'textAttributesPartial' => [
            'expectedJson' => $textAttributePartialSource->toJSON(),
            'expectedTextAttributes' => $textAttributePartialText->getAttributes(),
            'sourceStateVectorV1' => base64_encode($textAttributePartialSource->encodeStateVector()),
            'prefixJson' => $textAttributePartialPrefix->toJSON(),
            'prefixTextAttributes' => $textAttributePartialPrefix->getText('content')->getAttributes(),
            'prefixStateVectorV1' => base64_encode($textAttributePartialPrefix->encodeStateVector()),
            'prefixUpdateV1' => base64_encode($textAttributePartialPrefix->encodeStateAsUpdateV1()),
            'prefixUpdateV2' => base64_encode($textAttributePartialPrefix->encodeStateAsUpdateV2()),
            'syncStep2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2($textAttributePartialSource, $textAttributePartialPrefix->encodeStateVector())),
            'syncStep2V2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2V2($textAttributePartialSource, $textAttributePartialPrefix->encodeStateVector())),
        ],
        'nestedTextAttributesPartial' => [
            'expectedJson' => $nestedTextAttributePartialSource->toJSON(),
            'expectedTextAttributes' => $nestedTextAttributePartialText->getAttributes(),
            'sourceStateVectorV1' => base64_encode($nestedTextAttributePartialSource->encodeStateVector()),
            'prefixJson' => $nestedTextAttributePartialPrefix->toJSON(),
            'prefixTextAttributes' => $nestedTextAttributePartialPrefixText->getAttributes(),
            'prefixStateVectorV1' => base64_encode($nestedTextAttributePartialPrefix->encodeStateVector()),
            'prefixUpdateV1' => base64_encode($nestedTextAttributePartialPrefix->encodeStateAsUpdateV1()),
            'prefixUpdateV2' => base64_encode($nestedTextAttributePartialPrefix->encodeStateAsUpdateV2()),
            'syncStep2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2($nestedTextAttributePartialSource, $nestedTextAttributePartialPrefix->encodeStateVector())),
            'syncStep2V2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2V2($nestedTextAttributePartialSource, $nestedTextAttributePartialPrefix->encodeStateVector())),
        ],
        'mapXmlPartial' => [
            'expectedJson' => $mapXmlPartialSource->toJSON(),
            'expectedMapXmlFragments' => [
                ['key' => 'xml', 'xml' => 'A😀C'],
            ],
            'sourceStateVectorV1' => base64_encode($mapXmlPartialSource->encodeStateVector()),
            'prefixJson' => $mapXmlPartialPrefix->toJSON(),
            'prefixMapXmlFragments' => [
                ['key' => 'xml', 'xml' => 'A'],
            ],
            'prefixStateVectorV1' => base64_encode($mapXmlPartialPrefix->encodeStateVector()),
            'prefixUpdateV1' => base64_encode($mapXmlPartialPrefix->encodeStateAsUpdateV1()),
            'prefixUpdateV2' => base64_encode($mapXmlPartialPrefix->encodeStateAsUpdateV2()),
            'syncStep2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2($mapXmlPartialSource, $mapXmlPartialPrefix->encodeStateVector())),
            'syncStep2V2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2V2($mapXmlPartialSource, $mapXmlPartialPrefix->encodeStateVector())),
        ],
        'mapBulkPartial' => [
            'expectedJson' => $mapBulkPartialSource->toJSON(),
            'expectedMapXmlFragments' => [
                ['key' => 'xml', 'xml' => 'A<strong>B</strong>C'],
            ],
            'sourceStateVectorV1' => base64_encode($mapBulkPartialSource->encodeStateVector()),
            'prefixJson' => $mapBulkPartialPrefix->toJSON(),
            'prefixMapXmlFragments' => [
                ['key' => 'xml', 'xml' => ''],
            ],
            'prefixStateVectorV1' => base64_encode($mapBulkPartialPrefix->encodeStateVector()),
            'prefixUpdateV1' => base64_encode($mapBulkPartialPrefix->encodeStateAsUpdateV1()),
            'prefixUpdateV2' => base64_encode($mapBulkPartialPrefix->encodeStateAsUpdateV2()),
            'syncStep2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2($mapBulkPartialSource, $mapBulkPartialPrefix->encodeStateVector())),
            'syncStep2V2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2V2($mapBulkPartialSource, $mapBulkPartialPrefix->encodeStateVector())),
        ],
        'mapSubdocPartial' => [
            'expectedJson' => $mapSubdocPartialSource->toJSON(),
            'expectedMapSubdocs' => [
                [
                    'key' => 'child',
                    'guid' => 'php-sync-map-subdoc-child',
                    'meta' => ['kind' => 'sync-partial-map'],
                    'shouldLoad' => true,
                ],
            ],
            'sourceStateVectorV1' => base64_encode($mapSubdocPartialSource->encodeStateVector()),
            'prefixJson' => $mapSubdocPartialPrefix->toJSON(),
            'prefixStateVectorV1' => base64_encode($mapSubdocPartialPrefix->encodeStateVector()),
            'prefixUpdateV1' => base64_encode($mapSubdocPartialPrefix->encodeStateAsUpdateV1()),
            'prefixUpdateV2' => base64_encode($mapSubdocPartialPrefix->encodeStateAsUpdateV2()),
            'syncStep2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2($mapSubdocPartialSource, $mapSubdocPartialPrefix->encodeStateVector())),
            'syncStep2V2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2V2($mapSubdocPartialSource, $mapSubdocPartialPrefix->encodeStateVector())),
        ],
        'arraySubdocPartial' => [
            'expectedJson' => $arraySubdocPartialSource->toJSON(),
            'expectedArraySubdocs' => [
                [
                    'index' => 1,
                    'guid' => 'php-sync-array-subdoc-child',
                    'meta' => ['kind' => 'sync-partial-array'],
                    'shouldLoad' => true,
                ],
            ],
            'sourceStateVectorV1' => base64_encode($arraySubdocPartialSource->encodeStateVector()),
            'prefixJson' => $arraySubdocPartialPrefix->toJSON(),
            'prefixStateVectorV1' => base64_encode($arraySubdocPartialPrefix->encodeStateVector()),
            'prefixUpdateV1' => base64_encode($arraySubdocPartialPrefix->encodeStateAsUpdateV1()),
            'prefixUpdateV2' => base64_encode($arraySubdocPartialPrefix->encodeStateAsUpdateV2()),
            'syncStep2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2($arraySubdocPartialSource, $arraySubdocPartialPrefix->encodeStateVector())),
            'syncStep2V2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2V2($arraySubdocPartialSource, $arraySubdocPartialPrefix->encodeStateVector())),
        ],
        'arrayXmlPartial' => [
            'expectedJson' => $arrayXmlPartialSource->toJSON(),
            'expectedArrayXmlFragments' => [
                ['index' => 0, 'xml' => 'A<p>B</p>C'],
            ],
            'sourceStateVectorV1' => base64_encode($arrayXmlPartialSource->encodeStateVector()),
            'prefixJson' => $arrayXmlPartialPrefix->toJSON(),
            'prefixArrayXmlFragments' => [
                ['index' => 0, 'xml' => 'A'],
            ],
            'prefixStateVectorV1' => base64_encode($arrayXmlPartialPrefix->encodeStateVector()),
            'prefixUpdateV1' => base64_encode($arrayXmlPartialPrefix->encodeStateAsUpdateV1()),
            'prefixUpdateV2' => base64_encode($arrayXmlPartialPrefix->encodeStateAsUpdateV2()),
            'syncStep2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2($arrayXmlPartialSource, $arrayXmlPartialPrefix->encodeStateVector())),
            'syncStep2V2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2V2($arrayXmlPartialSource, $arrayXmlPartialPrefix->encodeStateVector())),
        ],
        'xmlHookSharedPartial' => [
            'expectedJson' => $xmlHookSharedPartialSource->toJSON(),
            'expectedHookJson' => $xmlHookSharedPartialSourceHook->toJSON(),
            'sourceStateVectorV1' => base64_encode($xmlHookSharedPartialSource->encodeStateVector()),
            'prefixJson' => $xmlHookSharedPartialPrefix->toJSON(),
            'prefixHookJson' => $xmlHookSharedPartialPrefixHook->toJSON(),
            'prefixStateVectorV1' => base64_encode($xmlHookSharedPartialPrefix->encodeStateVector()),
            'prefixUpdateV1' => base64_encode($xmlHookSharedPartialPrefix->encodeStateAsUpdateV1()),
            'prefixUpdateV2' => base64_encode($xmlHookSharedPartialPrefix->encodeStateAsUpdateV2()),
            'syncStep2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2($xmlHookSharedPartialSource, $xmlHookSharedPartialPrefix->encodeStateVector())),
            'syncStep2V2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2V2($xmlHookSharedPartialSource, $xmlHookSharedPartialPrefix->encodeStateVector())),
        ],
        'xmlElementSharedPartial' => [
            'expectedJson' => $xmlElementSharedPartialSource->toJSON(),
            'expectedElementAttributes' => $xmlElementSharedPartialSourceElement->getAttributes(),
            'sourceStateVectorV1' => base64_encode($xmlElementSharedPartialSource->encodeStateVector()),
            'prefixJson' => $xmlElementSharedPartialPrefix->toJSON(),
            'prefixElementAttributes' => $xmlElementSharedPartialPrefixElement->getAttributes(),
            'prefixStateVectorV1' => base64_encode($xmlElementSharedPartialPrefix->encodeStateVector()),
            'prefixUpdateV1' => base64_encode($xmlElementSharedPartialPrefix->encodeStateAsUpdateV1()),
            'prefixUpdateV2' => base64_encode($xmlElementSharedPartialPrefix->encodeStateAsUpdateV2()),
            'syncStep2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2($xmlElementSharedPartialSource, $xmlElementSharedPartialPrefix->encodeStateVector())),
            'syncStep2V2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2V2($xmlElementSharedPartialSource, $xmlElementSharedPartialPrefix->encodeStateVector())),
        ],
        'xmlTextSharedPartial' => [
            'expectedJson' => $xmlTextSharedPartialSource->toJSON(),
            'expectedTextAttributes' => $xmlTextSharedPartialSourceText->getAttributes(),
            'sourceStateVectorV1' => base64_encode($xmlTextSharedPartialSource->encodeStateVector()),
            'prefixJson' => $xmlTextSharedPartialPrefix->toJSON(),
            'prefixTextAttributes' => $xmlTextSharedPartialPrefixText->getAttributes(),
            'prefixStateVectorV1' => base64_encode($xmlTextSharedPartialPrefix->encodeStateVector()),
            'prefixUpdateV1' => base64_encode($xmlTextSharedPartialPrefix->encodeStateAsUpdateV1()),
            'prefixUpdateV2' => base64_encode($xmlTextSharedPartialPrefix->encodeStateAsUpdateV2()),
            'syncStep2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2($xmlTextSharedPartialSource, $xmlTextSharedPartialPrefix->encodeStateVector())),
            'syncStep2V2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2V2($xmlTextSharedPartialSource, $xmlTextSharedPartialPrefix->encodeStateVector())),
        ],
        'xmlReplacePartial' => [
            'expectedJson' => $xmlReplacePartialSource->toJSON(),
            'sourceStateVectorV1' => base64_encode($xmlReplacePartialSource->encodeStateVector()),
            'prefixJson' => $xmlReplacePartialPrefix->toJSON(),
            'prefixStateVectorV1' => base64_encode($xmlReplacePartialPrefix->encodeStateVector()),
            'prefixUpdateV1' => base64_encode($xmlReplacePartialPrefix->encodeStateAsUpdateV1()),
            'prefixUpdateV2' => base64_encode($xmlReplacePartialPrefix->encodeStateAsUpdateV2()),
            'syncStep1FromPrefix' => base64_encode(SyncProtocol::writeSyncStep1($xmlReplacePartialPrefix)),
            'syncStep2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2($xmlReplacePartialSource, $xmlReplacePartialPrefix->encodeStateVector())),
            'syncStep2V2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2V2($xmlReplacePartialSource, $xmlReplacePartialPrefix->encodeStateVector())),
            'handledSyncStep2ForPrefix' => base64_encode((string) SyncProtocol::handleMessage($xmlReplacePartialSource, SyncProtocol::writeSyncStep1($xmlReplacePartialPrefix))),
            'handledSyncStep2V2ForPrefix' => base64_encode((string) SyncProtocol::handleMessageV2($xmlReplacePartialSource, SyncProtocol::writeSyncStep1($xmlReplacePartialPrefix))),
        ],
        'threeWayConflictPartial' => [
            'expectedJson' => $threeWayConflictSource->toJSON(),
            'sourceStateVectorV1' => base64_encode($threeWayConflictSource->encodeStateVector()),
            'prefixJson' => $threeWayConflictPrefix->toJSON(),
            'prefixStateVectorV1' => base64_encode($threeWayConflictPrefix->encodeStateVector()),
            'prefixUpdateV1' => base64_encode($threeWayConflictPrefix->encodeStateAsUpdateV1()),
            'prefixUpdateV2' => base64_encode($threeWayConflictPrefix->encodeStateAsUpdateV2()),
            'syncStep2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2($threeWayConflictSource, $threeWayConflictPrefix->encodeStateVector())),
            'syncStep2V2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2V2($threeWayConflictSource, $threeWayConflictPrefix->encodeStateVector())),
        ],
        'xmlTextConflictPartial' => [
            'expectedJson' => $xmlTextConflictSource->toJSON(),
            'expectedXmlTextDelta' => $xmlTextConflictSourceText->toDelta(),
            'sourceStateVectorV1' => base64_encode($xmlTextConflictSource->encodeStateVector()),
            'prefixJson' => $xmlTextConflictPrefix->toJSON(),
            'prefixStateVectorV1' => base64_encode($xmlTextConflictPrefix->encodeStateVector()),
            'prefixUpdateV1' => base64_encode($xmlTextConflictPrefix->encodeStateAsUpdateV1()),
            'prefixUpdateV2' => base64_encode($xmlTextConflictPrefix->encodeStateAsUpdateV2()),
            'syncStep2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2($xmlTextConflictSource, $xmlTextConflictPrefix->encodeStateVector())),
            'syncStep2V2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2V2($xmlTextConflictSource, $xmlTextConflictPrefix->encodeStateVector())),
        ],
        'deleteOnly' => [
            'expectedJson' => $deleteSource->toJSON(),
            'prefixJson' => $deletePrefix->toJSON(),
            'prefixStateVectorV1' => base64_encode($deletePrefix->encodeStateVector()),
            'prefixUpdateV1' => base64_encode($deletePrefix->encodeStateAsUpdateV1()),
            'prefixUpdateV2' => base64_encode($deletePrefix->encodeStateAsUpdateV2()),
            'syncStep2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2($deleteSource, $deletePrefix->encodeStateVector())),
            'syncStep2V2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2V2($deleteSource, $deletePrefix->encodeStateVector())),
        ],
        'gcPartial' => [
            'expectedJson' => $gcPartialSource->toJSON(),
            'prefixJson' => $gcPartialPrefix->toJSON(),
            'prefixStateVectorV1' => base64_encode($gcPartialPrefix->encodeStateVector()),
            'sourceStateVectorV1' => base64_encode($gcPartialSource->encodeStateVector()),
            'prefixUpdateV1' => base64_encode($gcPartialPrefix->encodeStateAsUpdateV1()),
            'prefixUpdateV2' => base64_encode($gcPartialPrefix->encodeStateAsUpdateV2()),
            'syncStep2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2($gcPartialSource, $gcPartialPrefix->encodeStateVector())),
            'syncStep2V2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2V2($gcPartialSource, $gcPartialPrefix->encodeStateVector())),
        ],
        'deleteReinsert' => [
            'expectedJson' => $reinsertSource->toJSON(),
            'prefixJson' => $reinsertPrefix->toJSON(),
            'prefixStateVectorV1' => base64_encode($reinsertPrefix->encodeStateVector()),
            'sourceStateVectorV1' => base64_encode($reinsertSource->encodeStateVector()),
            'prefixUpdateV1' => base64_encode($reinsertPrefix->encodeStateAsUpdateV1()),
            'prefixUpdateV2' => base64_encode($reinsertPrefix->encodeStateAsUpdateV2()),
            'syncStep2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2($reinsertSource, $reinsertPrefix->encodeStateVector())),
            'syncStep2V2ForPrefix' => base64_encode(SyncProtocol::writeSyncStep2V2($reinsertSource, $reinsertPrefix->encodeStateVector())),
        ],
        'rawGc' => [
            'expectedJson' => $rawGcSource->toJSON(),
            'stateVectorV1' => base64_encode($rawGcSource->encodeStateVector()),
            'updateV1' => base64_encode($rawGcUpdateV1),
            'updateV2' => base64_encode($rawGcUpdateV2),
            'updateMessage' => base64_encode(SyncProtocol::writeUpdate($rawGcUpdateV1)),
            'updateV2Message' => base64_encode(SyncProtocol::writeUpdateV2($rawGcUpdateV2)),
            'syncStep2ForEmpty' => base64_encode(SyncProtocol::writeSyncStep2($rawGcSource, $syncEmpty->encodeStateVector())),
            'syncStep2V2ForEmpty' => base64_encode(SyncProtocol::writeSyncStep2V2($rawGcSource, $syncEmpty->encodeStateVector())),
        ],
    ],
    'awareness' => [
        'expectedState' => ['user' => ['name' => 'Ada'], 'cursor' => ['anchor' => 2, 'head' => 5]],
        'queryMessage' => base64_encode($awarenessQueryMessage),
        'queryReplyMessage' => base64_encode($awarenessQueryReplyMessage),
        'handledQueryReplyMessage' => base64_encode((string) $awarenessHandledQueryReplyMessage),
        'stateMessage' => base64_encode($awarenessStateMessage),
        'removeMessage' => base64_encode($awarenessRemoveMessage),
        'undefined' => [
            'stateMessage' => base64_encode($undefinedAwarenessMessage),
            'expectedState' => [
                'user' => ['name' => 'Ada'],
                'items' => [
                    null,
                    null,
                    ['visible' => true],
                ],
            ],
        ],
        'specialNumber' => [
            'stateMessage' => base64_encode($specialNumberAwarenessMessage),
            'expectedState' => [
                'metrics' => [
                    'nan' => null,
                    'positiveInfinity' => null,
                    'negativeInfinity' => null,
                    'finite' => 1.5,
                ],
                'list' => [null, null, null, 3],
            ],
        ],
        'clearStateMessage' => base64_encode($awarenessClearStateMessage),
        'clearMessage' => base64_encode($awarenessClearMessage),
        'batch' => [
            'stateMessage' => base64_encode($batchAwarenessStateMessage),
            'expectedStates' => [
                94 => ['user' => ['name' => 'Noor'], 'cursor' => ['anchor' => 4, 'head' => 8]],
                95 => ['user' => ['name' => 'Ola']],
            ],
        ],
        'filtered' => [
            'stateMessage' => base64_encode($filteredAwarenessStateMessage),
            'initialMessage' => base64_encode($filteredAwarenessInitialMessage),
            'removeMessage' => base64_encode($filteredAwarenessRemoveMessage),
            'expectedState' => ['user' => ['name' => 'Pia']],
            'expectedInitialStates' => [
                96 => ['user' => ['name' => 'Pia']],
                97 => ['user' => ['name' => 'Remy']],
            ],
        ],
        'observedMessages' => $observedAwarenessMessages,
        'observedExpectedStates' => [
            ['user' => ['name' => 'Grace']],
            ['user' => ['name' => 'Grace'], 'cursor' => ['anchor' => 1, 'head' => 1]],
            null,
        ],
        'timeout' => [
            'stateMessage' => base64_encode($timeoutAwarenessStateMessage),
            'observedMessages' => $timeoutAwarenessMessages,
            'removed' => $timeoutAwarenessRemoved,
            'events' => $timeoutAwarenessEvents,
            'expectedInitialStates' => [
                92 => ['user' => ['name' => 'Quinn']],
                93 => ['user' => ['name' => 'Rhea']],
            ],
        ],
        'staleAfterRemove' => [
            'messages' => array_map(static fn (string $message): string => base64_encode($message), $staleAwarenessMessages),
            'expectedStates' => [
                ['user' => ['name' => 'Ada']],
                null,
                null,
                null,
                null,
            ],
        ],
        'sameClockRemove' => [
            'messages' => array_map(static fn (string $message): string => base64_encode($message), $sameClockRemoveAwarenessMessages),
            'expectedStates' => [
                ['user' => ['name' => 'Same']],
                null,
                null,
            ],
        ],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
