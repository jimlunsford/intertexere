# Explicit Link Insertion

## Status

This document defines the implemented architecture for milestone 0.5. Milestones 0.1 through 0.5 are complete following independent review and merge of PR #14.

## Goal

0.5 lets an editor explicitly approve one current suggestion and insert one ordinary WordPress link around one exact existing anchor occurrence in the unsaved Block Editor draft.

Insertion is explicit, local, narrow, current-state validated, compatible with WordPress undo, and independent of Intertexere after the edit exists. Intertexere does not write the saved post, manufacture anchor prose, rewrite surrounding content, save, publish, or automatically insert links.

## Source contracts

0.5 preserves these established contracts:

- WordPress content is authoritative, while the current unsaved Block Editor state is authoritative for the pending local edit.
- Derived index and graph state is rebuildable and remains based on saved WordPress content until WordPress performs a normal save.
- Target post ID is durable identity. A URL is current WordPress metadata, not durable identity.
- The canonical draft hash, deterministic candidate set, exact location evidence, transport bounds, capability checks, and request ownership from 0.3 remain mandatory.
- AI may filter or rerank only deterministic candidates. AI never grants mutation authority and never supplies a trusted target or permalink.
- Every deterministic or AI location is stale until insertion validation succeeds against current state.
- Schema remains version 2. No suggestion, insertion authorization, editor snapshot, anchor, prompt, response, or mutation is persisted by Intertexere.

## WordPress 7.1 mutation API decision

The editor mutation uses WordPress state APIs, not the iframe DOM:

1. Read the current block with `select( blockEditorStore ).getBlock( clientId )`.
2. Confirm the block name and direct `content` attribute against the 0.5 allowlist.
3. Parse the current RichText content with `create( { html: content } )` from `@wordpress/rich-text`.
4. Recompute the exact occurrence in the current RichText plain-text value and derive its JavaScript UTF-16 start and end indices.
5. Reject link overlap, replacement-object boundaries, malformed content, or any unproved range.
6. Apply `{ type: 'core/link', attributes: { url: currentPermalink } }` with `applyFormat()`.
7. Verify that plain text, replacements, and all non-link formats are unchanged.
8. Return `RichTextData` when the original attribute is RichText data, or serialize with `toHTMLString()` when it is a string.
9. Make one `dispatch( blockEditorStore ).updateBlockAttributes( clientId, { content: nextContent } )` call.

The Core `core/link` format serializes the `url` attribute as `href`. One distinct persistent block-attribute update enters WordPress's normal editor history and dirty-state flow. Intertexere does not call the deprecated private undo-level API and does not keep a separate undo stack.

WordPress 7.1 always renders the editor canvas in an iframe. The sidebar nevertheless reads and updates the editor data stores. It does not query, focus, or modify iframe DOM, use `document.execCommand`, or perform raw HTML string replacement.

## Supported insertion blocks

The first insertion release allows only direct RichText `content` attributes with one unambiguous insertion unit:

| Block | RichText attribute | 0.5 behavior |
| --- | --- | --- |
| `core/paragraph` | `content` | Insert supported |
| `core/heading` | `content` | Insert supported |
| `core/list-item` | `content` | Insert supported |

Quote prose is insertable only when its exact location is a supported paragraph or list-item child block. The parent quote is not mutated.

Pullquote, verse, preformatted, table cells, image or gallery captions, and audio or video captions remain analyzable but are not insertable in 0.5. Pullquote has multiple RichText attributes; tables require a cell path; caption-bearing blocks require separate attribute and entity handling; and verse and preformatted blocks have special whitespace semantics. Their suggestion cards may remain visible but must not show Insert Link. Unknown, custom, dynamic, reusable, and entity-backed locations are also unsupported.

Expanding this allowlist requires runtime proof, unit and browser coverage for the attribute path, and an updated reviewed contract.

## Insertion authority model

A displayed card is evidence, not authorization. Insertion has four authority layers:

1. The current editor snapshot identifies the current source, draft, block, and proposed existing anchor.
2. The deterministic analyzer proves that the target is still a member of the current eligible candidate set. An AI overlay may influence display order only.
3. A dedicated read-only server validation resolves current WordPress source and target state and returns the current canonical target permalink plus normalized insertion evidence.
4. A final synchronous client check proves that the response still owns the action and the exact editor range is unchanged immediately before `updateBlockAttributes()`.

Failure at any layer leaves content unchanged.

## Server validation boundary

0.5 adds the dedicated authenticated POST route:

`/intertexere/v1/editor-suggestions/validate-insertion`

The route delegates to a reusable read-only PHP validation service. POST is required for the bounded unsaved draft payload and does not authorize server-side mutation.

The strict request contains only the current editor snapshot, prior analysis identity, positive deterministic target post ID, source kind, exact anchor evidence, and a bounded list of href values collected from the current unsaved draft for duplicate post-ID resolution. It does not accept a target permalink or eligibility claim from the client or model. Unknown fields are rejected.

The server:

1. authenticates the cookie session and REST nonce;
2. verifies the current source post, supported source post type, and edit capability;
3. enforces the route-specific raw-body limit before Core JSON parsing and all established snapshot bounds;
4. reconstructs the canonical draft and hash;
5. reruns deterministic analysis against current active index and graph generations;
6. verifies the prior analysis identity where applicable and current candidate membership;
7. resolves the target by post ID and rejects self-targeting, deletion, non-published status, password protection, unsupported or excluded post type, and any current ineligibility;
8. resolves the current canonical permalink from WordPress;
9. resolves every internal link in the current unsaved draft and rejects an existing link to the same target post ID, including alternate URL forms;
10. verifies the supported block, exact existing text, and occurrence against the canonical snapshot;
11. compares a server-authoritative target snapshot before and after validation so a material target race returns a stale error;
12. returns a bounded validation response and performs no write.

The target snapshot includes at least post ID, title, canonical permalink, post type, publication status, password state, and current eligibility. A change during validation is stale even when the target remains broadly eligible.

The response is request-scoped evidence, not a bearer token and not persisted. It includes a validation-contract version, current analysis and draft identity, target post ID, current canonical permalink, normalized block and anchor identity, and server-computed content identities needed for the final local check.

## Client validation and mutation boundary

Before starting validation, the client captures:

- current post ID and post type;
- canonical snapshot identity;
- target post ID;
- block client ID and block name;
- direct RichText attribute identity;
- exact anchor text and occurrence;
- a unique request token and abort controller.

After the server response, and immediately before mutation, the client repeats all locally observable checks. The response must still own the request; editor navigation and post identity must be unchanged; the block must still exist with the same name and content identity; the exact occurrence and range must still match; the range must not overlap a link or replacement boundary; and the current snapshot identity must match the validated one.

Analyze, Refresh, AI enhancement, a newer insertion attempt, draft changes, navigation, post-type changes, sidebar teardown, and component unmount invalidate insertion ownership and abort network validation where possible. A late response can never mutate newer state.

The interval between server validation and local mutation cannot be eliminated. The second local check makes any intervening editor change fail closed. Server metadata cannot change between response and local mutation without a theoretical residual race, so the operation uses the freshly resolved server permalink immediately and makes no claim of a durable lock. This request-scoped design is proportionate because the mutation remains a reversible local draft edit and WordPress revalidates normal save permissions later.

## Exact anchor and occurrence identity

Existing 0.3 and 0.4 fields are session evidence and hints. None is trusted without recomputation.

The final insertion identity binds:

- current post ID and post type;
- current canonical draft identity;
- deterministic target post ID;
- block client ID and block name;
- direct `content` attribute identity;
- exact case-sensitive anchor text;
- zero-based, non-overlapping occurrence within the current RichText plain text;
- current range and formatting compatibility;
- insertion request token.

Server offsets are not used directly as RichText indices. PHP canonical text positions count Unicode characters, while JavaScript RichText positions use UTF-16 code units. Exact text plus occurrence is the cross-runtime identity. The browser recomputes the JavaScript range in the current `RichTextValue.text`, including for Unicode and emoji.

If the same phrase appears more than once, the explicitly validated occurrence is used. Intertexere never falls back to the first match, relocates to a similar phrase, or guesses a replacement range. If one exact occurrence cannot be proved, insertion fails.

AI anchor output remains untrusted. The server maps an opaque AI unit key back to server-held deterministic location evidence without adding block IDs, client IDs, target URLs, or permalinks to the model prompt. The exact anchor then passes the same insertion validation as a deterministic anchor.

## Formatting preservation

`applyFormat()` adds the Core link format to the exact RichText range while preserving compatible formats, including bold, italic, code, strikethrough, underline where registered, text color, highlight, and mixed inline formatting. Entity, Unicode, emoji, and punctuation text must survive unchanged.

Crossing compatible non-link formats within the one direct RichText attribute is allowed only when post-application invariants prove that text, replacements, and every non-link format are unchanged. An anchor that crosses a block or attribute boundary, overlaps a replacement object, produces malformed serialization, or cannot satisfy those invariants fails safely.

## Existing links and duplicate destinations

Nested anchors are forbidden.

- Exact range already linked to the same resolved target: report already linked and do not mutate.
- Any full or partial overlap with a link to another target: report an overlap and do not mutate.
- A range spanning more than one existing link: do not mutate.
- Any other link in the current draft resolving to the target post ID: report duplicate destination and do not mutate.

The initial product contract permits at most one link from a source draft to a resolved target post ID. Alternate current, relative, query-style, and supported old-slug URL forms cannot bypass this rule. Durable resolved post ID is primary; raw URL equality is not.

## Permalink and markup contract

The inserted `href` is the target's canonical WordPress permalink returned by the insertion validation performed immediately before mutation. The client does not use a URL from the displayed suggestion, an earlier analysis, or AI output.

The result is ordinary WordPress markup equivalent to:

`<a href="current-permalink">existing anchor text</a>`

0.5 adds no Intertexere class, `data-*` attribute, hidden target ID, tracking parameter, script behavior, `target="_blank"`, `rel="nofollow"`, or `rel="sponsored"`. Any unrelated format markup already present remains intact. A saved link remains functional when Intertexere is disabled or removed.

## Undo, save, and publish behavior

One successful Insert Link action makes one normal editor-state update. Standard WordPress Undo restores the exact prior block state, and Redo restores the link where supported by Core. Intertexere has no private history stack.

Insertion changes only the unsaved editor draft and marks the editor dirty through normal WordPress behavior. Intertexere does not call Save, Update, Publish, autosave, revision APIs, `wp_update_post()`, or any server content-mutation endpoint. WordPress may later autosave through its normal lifecycle, and the user may save or publish using ordinary editor controls.

Before a normal WordPress save, the persistent index and graph continue to describe saved content. After the user saves, the existing `wp_after_insert_post` lifecycle updates index and graph state. 0.5 adds no special graph write and no duplicate indexing path.

## Deterministic and AI UI behavior

Insert Link is available only for a current deterministic suggestion with a supported, exact, insertable anchor. An AI-kept suggestion may expose the same action only after the same deterministic and insertion checks. AI rank, explanation, and anchor choice grant no authority.

An AI-dropped suggestion has no action while hidden by the enhanced view. Switching back to deterministic results restores only actions justified by current deterministic evidence. A stale AI overlay is discarded. Suggestions without an exact safe anchor or in unsupported blocks remain visible without Insert Link.

The existing sidebar gains one explicit accessible `Button` with clear states: ready, validating, inserted, stale, duplicate or already linked, unsupported, target unavailable, and validation error. Loading and disabled state must be programmatically exposed, status changes announced with WordPress notice or accessibility patterns, and keyboard activation supported. No iframe focus hack is used.

After success, the card reports the linked state and prevents repeated insertion. The changed draft invalidates current deterministic and AI session results. Intertexere does not automatically rerun Analyze or make an AI request.

## Security and transport

Insertion validation requires:

- same-origin cookie authentication and a valid REST nonce;
- current edit capability for the source;
- supported current source post type;
- strict JSON schemas with unknown-field rejection;
- an exact route-and-method raw-body guard before Core JSON parsing;
- the existing bounded draft, unit, term, candidate, and response limits;
- a bounded exact anchor and positive target ID;
- server-resolved target identity, eligibility, publication, password, exclusion, and permalink state;
- safe product-level errors without leaking content or provider data.

The server trusts neither client nor model URLs, target metadata, eligibility claims, HTML, hashes, locations, or analysis age. Validation exposes no Ability or model tool and performs no external provider request.

## Failure contract

Every failure leaves content byte-for-byte unchanged. Fail closed for stale draft, stale block, stale anchor, target deletion or unpublishing, password protection, exclusion, duplicate target, already-linked range, link overlap, unsupported block, malformed RichText, timeout, REST failure, permission loss, navigation, teardown, or any editor change during validation.

There is no silent relocation, retry-driven insertion, automatic refresh, or fallback server write.

## Performance contract

Insertion validation is a bounded interactive operation with no external request. On the established 125-post WordPress fixture:

- the complete validation request must remain at or below 16 database queries and 3.25 seconds;
- insertion-specific work beyond the deterministic analysis rerun must remain at or below 4 database queries and 250 milliseconds;
- client RichText range preparation and mutation must complete within 100 milliseconds for an allowed block at the established per-unit payload bound.

Tests record the measurements. Exceeding a bound requires query-plan review, not an unreviewed schema or persistent cache.

## Versioning and persistence

Planning alone did not change the public plugin version. The accepted 0.5 implementation moves the plugin from 0.4.0 to 0.5.0 only on the final acceptance head.

Schema remains version 2. 0.5 requires no migration, table, transient, option, insertion token, pending mutation, stored anchor, suggestion record, or editor snapshot.

## Explicit exclusions

0.5 does not include automatic or bulk insertion, prose generation or rewriting, replacement anchor text, automatic save or publish, site audit UI, orphan or under-linked detection, broken-link repair, redirects, link removal, persistent recommendation learning, embeddings, vector storage, multisite, WooCommerce-specific behavior, or cloud services. Site auditing remains 0.6.

## Independent review gate

Implementation used `feature/0.5-link-insertion`, passed the complete three-layer WordPress 7.1 and PHP matrix, and received full-diff independent review before merge. Milestone 0.6 consumes only normally saved graph state and does not expand insertion authority. See [Site Link Audit](SITE-LINK-AUDIT.md).
