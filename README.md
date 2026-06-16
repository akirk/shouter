# Shouter

[Try in Playground](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fakirk%2Fshouter%2Frefs%2Fheads%2Fmain%2Fblueprint.json)

Shouter is a WordPress/Gutenberg prototype for exploring whether a PHP-only bot can participate in Gutenberg's RTC sync stream and insert a follow-up paragraph that repeats a user's completed paragraph in uppercase, with punctuation replaced by exclamation marks.

For example:

```text
Hello, world?
```

becomes:

```text
HELLO! WORLD!
```

Shouter has a settings page at `Settings -> Shouter` where a WordPress user can be selected as the bot identity. The current implementation is now PHP-only: it does not enqueue an editor script, does not call `editEntityRecord()` from the browser, and does not use a custom browser callback to report typed text.

The current implementation includes a PHP Yjs updateV2 encoder and decoder for Gutenberg paragraph block insertions. It uses `yjs/yjs-php` through Composer for lib0/Yjs primitives and keeps a small Gutenberg-specific update layer for the subset of structs needed here. It can generate a bot-authored update that inserts a `core/paragraph` into an existing `document.blocks` Y.Array and can decode enough incoming updateV2 structure to find paragraph completion patterns.

## How It Works

Shouter attaches to WordPress REST processing for Gutenberg's existing RTC endpoint, `/wp-sync/v1/updates`. It does not register its own public mutation endpoint and it does not run JavaScript in the editor. All observation, Yjs decoding, update generation, and bot submission happen in PHP while Gutenberg continues to use its normal sync route.

The configured bot user is selected in `Settings -> Shouter`. Shouter derives a stable Yjs client ID from that user ID and stores the next client clock for each post in `_shouter_bot_clock`. Before the bot sends awareness or document updates, Shouter temporarily switches the current WordPress user to the configured bot user and then calls `rest_do_request()` against `/wp-sync/v1/updates`. The bot user must be able to `edit_post` for the current post, because the request is still handled by Gutenberg's normal sync permissions.

When an editor polls `/wp-sync/v1/updates`, `shouter_log_wp_sync_requests()` runs on `rest_pre_dispatch`. It logs a compact summary of the sync request and decodes update payloads for debugging. It also sends a throttled bot awareness packet for post rooms. That awareness packet is an ordinary `/wp-sync/v1/updates` request with no document updates; it exists so Gutenberg sees the bot as another collaborator early in the session and keeps the RTC update queue active.

After Gutenberg accepts a sync request, `shouter_respond_to_wp_sync_requests()` runs on `rest_post_dispatch`. It ignores requests from the bot client itself and passes the user-authored update list to `gutenberg_rtc_apply_paragraph_updates()`. That library function decodes the updateV2 payloads, updates the lightweight room-state index stored in `_shouter_room_state`, and returns `Gutenberg_RTC_Completed_Paragraph` events.

The state and detection details live in `includes/gutenberg-rtc-paragraphs.php`. It tracks block Y.Map items, paragraph attributes, `attributes.content` Y.Text items, root `document.content`, text-item origins, and which source paragraphs have already been shouted. Paragraph completion is detected structurally: a new empty top-level `core/paragraph` block whose Yjs origin points at a known non-empty paragraph block. That is the pattern Gutenberg creates when the user finishes a paragraph and creates the following empty paragraph.

Shouter's behavior is now deliberately small: `shouter_insert_after_completed_paragraphs()` receives completed paragraph events, transforms each paragraph with `shouter_shout_text()`, and asks `shouter_emit_bot_paragraph_after()` to insert the result. The shouted text transform uppercases the text and replaces ASCII punctuation with exclamation marks. For example, `Hello, world?` becomes `HELLO! WORLD!`. The inserted paragraph is plain text; formatting from the source paragraph is not preserved.

The actual mutation is assembled in two layers. `includes/gutenberg-rtc-paragraphs.php` exposes `gutenberg_rtc_build_paragraph_insert()`, which accepts the current paragraph document state, a completed paragraph event, the bot client ID, and the text to insert. It hides the Yjs origins and optional serialized `document.content` insertion. Underneath, `includes/gutenberg-yjs-update-v2.php` writes the updateV2 bytes for the Gutenberg paragraph block map with `name: core/paragraph`, `isValid: true`, `attributes.content` as a Y.Text, `attributes.dropCap: false`, `innerBlocks` as a Y.Array, and a generated Gutenberg `clientId`.

In code, the intended shape is:

```php
$paragraphs = gutenberg_rtc_apply_paragraph_updates( $state, $updates );

foreach ( $paragraphs as $paragraph ) {
	shouter_emit_bot_paragraph_after(
		$post_id,
		$room,
		shouter_shout_text( $paragraph->text() ),
		$paragraph
	);
}
```

When Shouter knows the root `document.content` Y.Text, it also emits a matching serialized block string insertion for the code editor view. That keeps the serialized post content aligned with the visual block tree. If `document.content` has not been observed yet, Shouter can still emit the block-tree update, but code-editor convergence depends on later document state.

The bot update is submitted back through `/wp-sync/v1/updates` as the configured bot user. The same request also carries awareness with the bot cursor positioned at the end of the inserted shouted text. After the request succeeds, Shouter decodes and applies its own bot update back into `_shouter_room_state` and advances `_shouter_bot_clock` by the number of Yjs clock ticks consumed by the generated update.

The implementation intentionally stays narrow. It handles top-level paragraph completion, emits plain paragraph text, and only implements the Yjs updateV2 content types needed for this path. It does not provide a general Yjs runtime, does not handle arbitrary Gutenberg block mutations, and no longer exposes the earlier development REST endpoints.

This started as a Yjs/RTC observability probe. The notes below document what was learned while inspecting Gutenberg's collaboration traffic and then turning the probe into the Shouter behavior.

## Composer and Dist Branches

`composer.json` points Composer at the upstream GitHub repository for `yjs/yjs-php`:

```sh
composer install
```

The package is installed from `https://github.com/Automattic/yjs-php`, because it is not currently available through the default Packagist repository.

The source branch intentionally ignores `vendor/` and `composer.lock`. Deployable branches are built by `.github/workflows/build-dist.yml`, following the same dist-branch approach used by the Cookbook plugin. On every push to a non-`dist/` branch, the workflow runs `composer install --no-dev --optimize-autoloader`, force-adds `vendor/`, and pushes the result to `dist/<branch>`.

`blueprint.json` installs the plugin from `akirk/shouter` at `dist/main`, so Playground receives a build that already contains Composer dependencies and can load `vendor/autoload.php` during plugin bootstrap.

## Findings

Date: 2026-06-16

Log source: `/tmp/wp.demo`

## Current instrumentation

The probe currently observes one primary surface:

- Passive REST traffic to `/wp-sync/v1/updates`.

The passive sync logs show the HTTP polling payload shape and awareness state. In the solo-editor session inspected here, the sync payloads consistently had `update_count: 0`, which means PHP was not receiving document update payloads to decode.

## Observed session

Post room:

```text
postType/post:39424
```

User:

```text
Alex Kirk
user_login: alex
user_id: 1
```

Client ID:

```text
281149976
```

Relevant log window:

```text
2026-06-16 17:19:41 UTC through 2026-06-16 17:20:21 UTC
```

## Completing a paragraph

Completing a paragraph or creating a following empty paragraph showed up as a multi-newline insertion in the text diff.

Example at `2026-06-16 17:19:41 UTC`:

```json
{
  "offset": 5,
  "inserted": "\n\n\n\n",
  "removed": "",
  "before_length": 5,
  "after_length": 9
}
```

Example at `2026-06-16 17:19:43 UTC`:

```json
{
  "offset": 14,
  "inserted": "\n\n\n\n",
  "removed": "",
  "before_length": 14,
  "after_length": 18
}
```

Interpretation:

The current text probe can identify paragraph completion as a structural text boundary. It cannot yet tell whether this was a new paragraph block, a split paragraph block, or another block-level operation without comparing the actual block tree.

## Inserting an image block

The image block insertion test appears to have been triggered through the slash command.

At `2026-06-16 17:19:52 UTC`, the probe logged typed characters:

```text
/
i
m
a
g
e
```

Immediately after, at `2026-06-16 17:19:53 UTC`, Gutenberg removed the slash command text:

```json
{
  "offset": 17,
  "inserted": "",
  "removed": "/image",
  "before_length": 24,
  "after_length": 18
}
```

At `2026-06-16 17:19:56 UTC`, the awareness state changed to a whole-block selection:

```json
{
  "selection": {
    "type": "whole-block",
    "blockPosition": {
      "type": {
        "client": 281149976,
        "clock": 53
      },
      "item": {
        "client": 281149976,
        "clock": 142
      },
      "assoc": 0
    }
  }
}
```

Interpretation:

The logs show the slash command lifecycle: `/image` was typed, then removed when Gutenberg accepted/replaced it. The awareness state then moved to a whole-block selection, which is consistent with a block-level insertion or replacement.

The current text diff does not expose the actual `core/image` block, because an empty image block contributes little or no plain text.

## Important limitation

The passive `/wp-sync/v1/updates` logs still showed:

```json
{
  "update_count": 0
}
```

That means the PHP side did not receive Yjs document updates during this single-user session. This matches Gutenberg's behavior where update queues are paused until collaboration conditions are met.

## Intended behavior: Shouter

The plugin has evolved from a passive probe toward a PHP-side RTC responder named Shouter.

Target behavior:

- Detect a completed paragraph from Gutenberg's RTC document updates.
- Read the paragraph immediately before the new empty paragraph.
- Emit a bot-authored Yjs updateV2 that inserts a new `core/paragraph` before the empty paragraph.
- Transform the inserted text by uppercasing it and replacing ASCII punctuation with exclamation marks.

Example:

```text
Hello, world?
```

becomes:

```text
HELLO! WORLD!
```

The punctuation replacement currently targets ASCII punctuation:

```js
/[!"#$%&'()*+,./:;<=>?@[\\\]^_`{|}~-]/g
```

This does not currently replace all Unicode punctuation, such as curly quotes, em dashes, or non-Latin punctuation marks.

## Earlier mutation paths

The first mutation prototype inserted blocks directly through the block editor store:

```js
wp.data.dispatch( 'core/block-editor' ).insertBlocks( ... )
```

That worked locally but did not clearly express the RTC/CRDT path.

The next prototype used the synced entity path instead:

```js
wp.data.dispatch( 'core' ).editEntityRecord(
  'postType',
  postType,
  postId,
  {
    blocks: nextBlocks,
    content: wp.blocks.serialize( nextBlocks )
  },
  { isCached: false }
);
```

This mattered because Gutenberg's `core-data` `editEntityRecord()` calls `getSyncManager()?.update(...)` when the entity has `syncConfig`. That applies the edit to the local Yjs document through Gutenberg's CRDT merge logic.

For the bot itself to insert the shouted paragraph as another collaborator, PHP must generate a valid Gutenberg/Yjs updateV2 payload for the post CRDT document. The sync server stores and forwards update bytes, but it does not create or validate the semantic Yjs document mutation.

Shouter now consumes two narrow PHP libraries for this path:

- `includes/gutenberg-yjs-update-v2.php` uses `yjs/yjs-php` for lib0/Yjs primitives and implements the Gutenberg-specific updateV2 stream layout needed for `Y.Map`, `Y.Array`, `Y.Text`, `ContentType`, `ContentAny`, and `ContentString`.
- `gutenberg_yjs_decode_update_v2()` decodes incoming updateV2 structs into PHP arrays with item IDs, origins, parent keys, map keys, and content values.
- `gutenberg_yjs_encode_paragraph_insert_after_update_v2()` emits a paragraph block insertion after a known existing block item ID, optionally bounded by a right-origin item ID so the bot paragraph lands between the completed paragraph and the following empty paragraph.
- `includes/gutenberg-rtc-paragraphs.php` turns update entries into `Gutenberg_RTC_Completed_Paragraph` events and builds paragraph insertion updates from those events.
- `shouter_respond_to_wp_sync_requests()` runs after Gutenberg accepts `/wp-sync/v1/updates`, asks the paragraph library for completed paragraph events, and emits the shouted paragraph as the configured bot user.
- Shouter submits a throttled bot awareness packet through `/wp-sync/v1/updates` before Gutenberg handles a post-room sync request, so the bot can appear as a collaborator as soon as the editor joins.
- `shouter_emit_bot_paragraph_after()` submits that update to `/wp-sync/v1/updates` as the configured bot user and reports the bot cursor at the end of the inserted text via awareness.
- Bot paragraph updates now mirror Gutenberg's paragraph map shape more closely by including `isValid: true` and `attributes.dropCap: false`.
- When the root `document.content` Y.Text is known, Shouter can include a matching serialized paragraph string item so the code editor content can converge with the visual block tree.
- Incoming paragraph text is reconstructed from Yjs item origins instead of request order, so middle insertions or out-of-order text item delivery are less likely to produce scrambled shouted text.

The encoder has been verified against official Yjs for a canonical single-paragraph document: Yjs JS can apply the PHP-generated update and read `document.blocks[0]` as a `core/paragraph` containing the expected text. Earlier tests also verified the intended right-origin-bounded update shape for turning an existing `[hello, ""]` blocks array into `[hello, HELLO, ""]`.

The WordPress REST path has also been verified with a synthetic `/wp-sync/v1/updates` request. The request submitted two user-authored updateV2 payloads (`hello`, then a following empty paragraph). Shouter decoded those updates, emitted a second `/wp-sync/v1/updates` request as the configured bot user, and the logged bot update decoded as a paragraph containing `HELLO` with the expected left and right Yjs origins.

Live Gutenberg logs on post `39460` also show the PHP-only path emitting bot-authored RTC updates. One early insert exposed a stale room-state/reconstruction bug and produced scrambled text (`HOW ARE OYYOU!`). After adding schema-versioned room-state reset, origin-based text reconstruction, and root `document.content` insertion, a later bot update decoded as a 10-struct paragraph insert containing `GOOD!`, `isValid: true`, `attributes.dropCap: false`, a matching `document.content` string insert, and bot awareness with the cursor at the end of the inserted text.

The main remaining verification caveat is live documents with old Shouter room history. Existing posts that already contain pre-fix `_shouter_room_state` data or bot clock history can still show artifacts from earlier experiments. A fresh post, or a post whose Shouter room state has been rebuilt from current Gutenberg updates, is the cleanest live validation target.

## Current scope and edge cases

The responder is intentionally narrow:

- Only top-level paragraphs are handled.
- Nested paragraphs inside groups, columns, quotes, list items, etc. are ignored.
- Empty completed paragraphs are ignored.
- It derives the source text from `block.attributes.content` rendered through `DOMParser`, so formatting is stripped.
- The inserted paragraph is plain text only.
- The plugin no longer enqueues editor JavaScript. Mutation work happens by generating and submitting RTC payloads from PHP.
- Automatic paragraph-completion detection depends on receiving Gutenberg updateV2 payloads. In solo-editor polling, Gutenberg may send only awareness payloads with `update_count: 0`.
- The configured bot user must be able to `edit_post` for the room's post. A bot with only the `author` role can edit its own posts but cannot emit RTC awareness or updates for another user's posts.

There is also a likely reactivation detail after renaming the main plugin file to `shouter.php`: WordPress tracks active plugins by plugin basename, so an install that had `gutenberg-yjs-probe/gutenberg-yjs-probe.php` active may need `gutenberg-yjs-probe/shouter.php` activated.

## Next verification step

The next clean validation should use a fresh Gutenberg post that the configured bot user can edit:

- Open the post editor and confirm Shouter emits bot awareness immediately through `/wp-sync/v1/updates`.
- Type a paragraph and press Enter to create the following empty paragraph.
- Confirm the log shows a user-authored empty paragraph update followed by `bot-rtc-auto-insert` with a bot-authored update.
- Confirm the decoded bot update includes the shouted paragraph text, `isValid: true`, `attributes.dropCap: false`, and a matching `document.content` insert.
- Confirm both the visual editor and code editor represent the same inserted paragraph.
