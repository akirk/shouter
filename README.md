# Shouter

[Try in Playground](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fraw.githubusercontent.com%2Fakirk%2Fshouter%2Frefs%2Fheads%2Fmain%2Fblueprint.json)

Shouter is a WordPress/Gutenberg prototype for exploring whether a PHP-only bot can participate in Gutenberg's RTC sync stream and edit a user's completed paragraph in place.

For example:

```text
Hello, world?
```

becomes:

```text
Hello, WORLD?
```

Shouter has a settings page at `Settings -> Shouter` where a WordPress user can be selected as the bot identity and the behavior can be switched. In the default replacement mode, the bot uppercases the last word of a completed paragraph and reports a remote text selection over that uppercase word, so Gutenberg can render the change as highlighted by the bot. The alternate mode keeps the older behavior of inserting a shouted follow-up paragraph.

The current implementation is PHP-only: it does not enqueue an editor script, does not call `editEntityRecord()` from the browser, and does not use a custom browser callback to report typed text.

The current implementation includes a PHP Yjs updateV2 encoder and decoder for Gutenberg paragraph text changes. It uses `maxschmeling/y-php` through Composer for lib0/Yjs primitives and keeps a small Gutenberg-specific update layer for the subset of structs needed here. It can generate a bot-authored update that replaces text in an existing paragraph `Y.Text` and can decode enough incoming updateV2 structure to find paragraph completion patterns.

## How It Works

Shouter attaches to WordPress REST processing for Gutenberg's existing RTC endpoint, `/wp-sync/v1/updates`. It does not register its own public mutation endpoint and it does not run JavaScript in the editor. All observation, Yjs decoding, update generation, and bot submission happen in PHP while Gutenberg continues to use its normal sync route.

The configured bot user is selected in `Settings -> Shouter`. Shouter derives a stable Yjs client ID from that user ID and stores the next client clock for each post in `_shouter_bot_clock`. Before the bot sends awareness or document updates, Shouter temporarily switches the current WordPress user to the configured bot user and then calls `rest_do_request()` against `/wp-sync/v1/updates`. The bot user must be able to `edit_post` for the current post, because the request is still handled by Gutenberg's normal sync permissions.

When an editor polls `/wp-sync/v1/updates`, `shouter_log_wp_sync_requests()` runs on `rest_pre_dispatch`. It logs a compact summary of the sync request and decodes update payloads for debugging. It also sends a throttled bot awareness packet for post rooms. That awareness packet is an ordinary `/wp-sync/v1/updates` request with no document updates; it exists so Gutenberg sees the bot as another collaborator early in the session and keeps the RTC update queue active.

After Gutenberg accepts a sync request, `shouter_respond_to_wp_sync_requests()` runs on `rest_post_dispatch`. It ignores requests from the bot client itself and passes the user-authored update list to `gutenberg_rtc_apply_paragraph_updates()`. That library function decodes the updateV2 payloads, updates the lightweight room-state index stored in `_shouter_room_state`, and returns `Gutenberg_RTC_Completed_Paragraph` events.

The state and detection details live in `includes/gutenberg-rtc-paragraphs.php`. It tracks block Y.Map items, paragraph attributes, `attributes.content` Y.Text items, root `document.content`, text-item origins, and which source paragraphs have already been processed. Paragraph completion is detected structurally: a new empty top-level `core/paragraph` block whose Yjs origin points at a known non-empty paragraph block. That is the pattern Gutenberg creates when the user finishes a paragraph and creates the following empty paragraph.

Shouter's behavior is deliberately small and selected by the `shouter_behavior` option. In `replace_last_word` mode, `shouter_replace_last_word_in_completed_paragraphs()` receives completed paragraph events, finds the final word in the completed paragraph, uppercases only that word, and asks `shouter_emit_bot_last_word_replacement()` to submit the edit as the configured bot user. For example, `Hello, world?` becomes `Hello, WORLD?`. In `insert_shouted_paragraph` mode, the same completed paragraph event causes the bot to insert a new paragraph containing the shouted version of the completed paragraph.

The actual mutation is assembled in two layers. `includes/gutenberg-rtc-paragraphs.php` exposes `gutenberg_rtc_build_last_word_replacement()`, which accepts the current paragraph document state, a completed paragraph event, the bot client ID, and the replacement word. It maps the paragraph's final word to concrete Yjs character IDs. Underneath, `includes/gutenberg-yjs-update-v2.php` writes one inserted string item plus a delete set for the old lowercase word.

In code, the intended shape is:

```php
$paragraphs = gutenberg_rtc_apply_paragraph_updates( $state, $updates );

foreach ( $paragraphs as $paragraph ) {
	$word = shouter_get_last_word( $paragraph->text() );
	shouter_emit_bot_last_word_replacement(
		$post_id,
		$room,
		shouter_uppercase_text( $word ),
		$paragraph
	);
}
```

The bot update is submitted back through `/wp-sync/v1/updates` as the configured bot user. In replacement mode, the same request also carries awareness with a `selection-in-one-block` range over the replacement word. The range points at the existing paragraph `attributes.content` Y.Text type, starts at the bot's inserted uppercase text item, and ends before the following original item when punctuation is present. In insert mode, the bot awareness remains a cursor at the end of the inserted paragraph. After the request succeeds, Shouter decodes and applies its own bot update back into `_shouter_room_state` and advances `_shouter_bot_clock` by the number of Yjs clock ticks consumed by the bot update.

The implementation intentionally stays narrow. It handles top-level paragraph completion, edits plain paragraph text, and only implements the Yjs updateV2 content types needed for this path. It does not provide a general Yjs runtime, does not handle arbitrary Gutenberg block mutations, and no longer exposes the earlier development REST endpoints.

This started as a Yjs/RTC observability probe. The notes below document what was learned while inspecting Gutenberg's collaboration traffic and then turning the probe into the Shouter behavior.

## Composer and Dist Branches

`composer.json` points Composer at the upstream GitHub repository for `maxschmeling/y-php`:

```sh
composer install
```

The package is installed from `https://github.com/maxschmeling/y-php`, because it is not currently available through the default Packagist repository.

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
- Emit a bot-authored Yjs updateV2 that replaces the previous paragraph's final word.
- Transform only that final word by uppercasing it.

Example:

```text
Hello, world?
```

becomes:

```text
Hello, WORLD?
```

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

For the bot itself to edit the paragraph as another collaborator, PHP must generate a valid Gutenberg/Yjs updateV2 payload for the post CRDT document. The sync server stores and forwards update bytes, but it does not create or validate the semantic Yjs document mutation.

Shouter now consumes two narrow PHP libraries for this path:

- `includes/gutenberg-yjs-update-v2.php` uses `maxschmeling/y-php` for lib0/Yjs primitives and implements the Gutenberg-specific updateV2 stream layout needed for `Y.Map`, `Y.Array`, `Y.Text`, `ContentType`, `ContentAny`, and `ContentString`.
- `gutenberg_yjs_decode_update_v2()` decodes incoming updateV2 structs into PHP arrays with item IDs, origins, parent keys, map keys, and content values.
- `gutenberg_yjs_encode_text_replacement_update_v2()` emits one inserted string item plus a delete set for the original text range.
- `includes/gutenberg-rtc-paragraphs.php` turns update entries into `Gutenberg_RTC_Completed_Paragraph` events and builds last-word replacement updates from those events.
- `shouter_respond_to_wp_sync_requests()` runs after Gutenberg accepts `/wp-sync/v1/updates`, asks the paragraph library for completed paragraph events, and emits the last-word replacement as the configured bot user.
- Shouter submits a throttled bot awareness packet through `/wp-sync/v1/updates` before Gutenberg handles a post-room sync request, so the bot can appear as a collaborator as soon as the editor joins.
- `shouter_emit_bot_last_word_replacement()` submits that update to `/wp-sync/v1/updates` as the configured bot user and reports a bot text selection over the replacement word via awareness.
- Incoming paragraph text is reconstructed from Yjs item origins instead of request order, so middle insertions or out-of-order text item delivery are less likely to produce scrambled paragraph text.

The encoder has been verified against official Yjs for a canonical single-paragraph document: Yjs JS can apply the PHP-generated replacement update and read `document.blocks[0].attributes.content` as `hello WORLD`.

The main remaining verification caveat is live documents with old Shouter room history. Existing posts that already contain pre-fix `_shouter_room_state` data or bot clock history can still show artifacts from earlier experiments. A fresh post, or a post whose Shouter room state has been rebuilt from current Gutenberg updates, is the cleanest live validation target.

## Current scope and edge cases

The responder is intentionally narrow:

- Only top-level paragraphs are handled.
- Nested paragraphs inside groups, columns, quotes, list items, etc. are ignored.
- Empty completed paragraphs are ignored.
- It derives the source text from `block.attributes.content` rendered through `DOMParser`, so formatting is stripped.
- Only the final word-like run of letters, numbers, or underscores is uppercased.
- The plugin no longer enqueues editor JavaScript. Mutation work happens by generating and submitting RTC payloads from PHP.
- Automatic paragraph-completion detection depends on receiving Gutenberg updateV2 payloads. In solo-editor polling, Gutenberg may send only awareness payloads with `update_count: 0`.
- The configured bot user must be able to `edit_post` for the room's post. A bot with only the `author` role can edit its own posts but cannot emit RTC awareness or updates for another user's posts.

There is also a likely reactivation detail after renaming the main plugin file to `shouter.php`: WordPress tracks active plugins by plugin basename, so an install that had `gutenberg-yjs-probe/gutenberg-yjs-probe.php` active may need `gutenberg-yjs-probe/shouter.php` activated.

## Next verification step

The next clean validation should use a fresh Gutenberg post that the configured bot user can edit:

- Open the post editor and confirm Shouter emits bot awareness immediately through `/wp-sync/v1/updates`.
- Type a paragraph and press Enter to create the following empty paragraph.
- Confirm the log shows a user-authored empty paragraph update followed by `bot-rtc-auto-replace-last-word` with a bot-authored update.
- Confirm the previous paragraph's final word is uppercase in the visual editor.
