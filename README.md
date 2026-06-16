# Shouter

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

The current implementation includes a minimal PHP Yjs updateV2 encoder and decoder for Gutenberg paragraph block insertions. It can generate a bot-authored update that inserts a `core/paragraph` into an existing `document.blocks` Y.Array and can decode enough incoming updateV2 structure to find paragraph completion patterns.

This started as a Yjs/RTC observability probe. The notes below document what was learned while inspecting Gutenberg's collaboration traffic and then turning the probe into the Shouter behavior.

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

Shouter now consumes a narrow, plugin-neutral PHP encoder library for this path:

- `includes/gutenberg-yjs-update-v2.php` implements the lib0/Yjs updateV2 streams needed for `Y.Map`, `Y.Array`, `Y.Text`, `ContentType`, `ContentAny`, and `ContentString`.
- `gutenberg_yjs_decode_update_v2()` decodes incoming updateV2 structs into PHP arrays with item IDs, origins, parent keys, map keys, and content values.
- `gutenberg_yjs_encode_paragraph_insert_after_update_v2()` emits a paragraph block insertion after a known existing block item ID, optionally bounded by a right-origin item ID so the bot paragraph lands between the completed paragraph and the following empty paragraph.
- `shouter_respond_to_wp_sync_requests()` runs after Gutenberg accepts `/wp-sync/v1/updates`, updates a lightweight PHP room-state index, detects a new empty paragraph after a known non-empty paragraph, and emits the shouted paragraph as the configured bot user.
- Shouter submits a throttled bot awareness packet through `/wp-sync/v1/updates` before Gutenberg handles a post-room sync request, so the bot can appear as a collaborator as soon as the editor joins.
- `shouter_emit_bot_paragraph_after()` submits that update to `/wp-sync/v1/updates` as the configured bot user and reports the bot cursor at the end of the inserted text via awareness.
- Bot paragraph updates now mirror Gutenberg's paragraph map shape more closely by including `isValid: true` and `attributes.dropCap: false`.
- When the root `document.content` Y.Text is known, Shouter can include a matching serialized paragraph string item so the code editor content can converge with the visual block tree.
- Incoming paragraph text is reconstructed from Yjs item origins instead of request order, so middle insertions or out-of-order text item delivery are less likely to produce scrambled shouted text.
- `/wp-json/shouter/v1/insert-after` is a development endpoint for exercising that PHP-only RTC emission when the caller knows `left_origin_client` and `left_origin_clock`.

The encoder has been verified byte-for-byte against official Yjs for a canonical single-paragraph document, and official Yjs can apply the PHP-generated right-origin-bounded update to turn an existing `[hello, ""]` blocks array into `[hello, HELLO, ""]`.

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
