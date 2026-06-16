# Shouter

Shouter is a WordPress/Gutenberg prototype that listens for completed top-level paragraph blocks in the editor and immediately inserts a follow-up paragraph that repeats the user's paragraph in uppercase, with punctuation replaced by exclamation marks.

For example:

```text
Hello, world?
```

becomes:

```text
HELLO! WORLD!
```

The inserted paragraph is applied through Gutenberg's synced entity path using `core.editEntityRecord()`, so the change is routed into Gutenberg's local CRDT document rather than being a direct DOM or block-editor-only mutation.

This started as a Yjs/RTC observability probe. The notes below document what was learned while inspecting Gutenberg's collaboration traffic and then turning the probe into the Shouter behavior.

## Findings

Date: 2026-06-16

Log source: `/tmp/wp.demo`

## Current instrumentation

The probe currently observes two different surfaces:

- Passive REST traffic to `/wp-sync/v1/updates`.
- An editor-side text diff posted to `/wp-json/shouter/v1/typed`.

The passive sync logs show the HTTP polling payload shape and awareness state. In the solo-editor session inspected here, the sync payloads consistently had `update_count: 0`, which means PHP was not receiving document update payloads to decode.

The editor-side text diff sees the serialized block tree converted to plain text. This is useful for typed characters and rough structural changes, but it does not fully expose block objects or block attributes.

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

## Prototype behavior: Shouter

The plugin has evolved from a passive probe into a small editor-side responder named Shouter.

Current behavior:

- Detect a completed paragraph using the observed text boundary `"\n\n\n\n"`.
- Confirm the selected block is the new empty top-level `core/paragraph`.
- Read the paragraph immediately before that empty paragraph.
- Insert a new `core/paragraph` before the empty paragraph.
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

## Mutation path

The first mutation prototype inserted blocks directly through the block editor store:

```js
wp.data.dispatch( 'core/block-editor' ).insertBlocks( ... )
```

That worked locally but did not clearly express the RTC/CRDT path.

The current version uses the synced entity path instead:

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

This matters because Gutenberg's `core-data` `editEntityRecord()` calls `getSyncManager()?.update(...)` when the entity has `syncConfig`. That applies the edit to the local Yjs document through Gutenberg's CRDT merge logic.

This is still not a separate collaborator. It runs inside the current editor session and current user identity. A distinct assistant/collaborator identity would require a separate RTC participant, such as a Node or Rust sidecar joining `/wp-sync/v1/updates`.

## Current scope and edge cases

The responder is intentionally narrow:

- Only top-level paragraphs are handled.
- Nested paragraphs inside groups, columns, quotes, list items, etc. are ignored.
- Empty completed paragraphs are ignored.
- It derives the source text from `block.attributes.content` rendered through `DOMParser`, so formatting is stripped.
- The inserted paragraph is plain text only.
- The editor script is still inline and enqueued from PHP, which is convenient for prototyping but should become a real asset file if the plugin grows.

There is also a likely reactivation detail after renaming the main plugin file to `shouter.php`: WordPress tracks active plugins by plugin basename, so an install that had `gutenberg-yjs-probe/gutenberg-yjs-probe.php` active may need `gutenberg-yjs-probe/shouter.php` activated.

## Next instrumentation step

To dissect canonical block tasks, the editor probe should log block-level diffs in addition to text diffs.

For each editor state change, capture:

- Block order.
- Block `clientId`.
- Block `name`, for example `core/paragraph` or `core/image`.
- Block `attributes`.
- Inner block structure.
- Serialized block markup from `wp.blocks.serialize( blocks )`.

Expected result for image insertion:

The next probe should show a new or replaced block with:

```json
{
  "name": "core/image",
  "attributes": {}
}
```

or, after media selection:

```json
{
  "name": "core/image",
  "attributes": {
    "id": 123,
    "url": "...",
    "alt": "..."
  }
}
```

This would give the PHP log enough information to distinguish text edits, paragraph splits, slash-command replacement, block insertion, and later image attribute updates.
