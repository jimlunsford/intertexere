# 0.5 Acceptance Criteria: Explicit Link Insertion

## Status

The implementation contract was proven on `feature/0.5-link-insertion`: the full WordPress 7.1 PHP, JavaScript, build, Playwright, and performance matrix passed, independent production-diff review approved exact head `cd1a8948065c77dab498485ec829bbb3ab6549f8`, and PR #14 merged without tree differences.

## Completion rule

0.5 is complete. Every criterion below was proven on `feature/0.5-link-insertion`, the production diff received independent review, and the exact reviewed head passed the full continuous-integration matrix before merge.

## Product and authority

- [x] The user must click Insert Link for every insertion.
- [x] A displayed deterministic or AI suggestion is never sufficient mutation authority.
- [x] The target remains a current deterministic candidate and is identified by WordPress post ID.
- [x] AI can neither introduce a destination nor bypass deterministic, server, or local validation.
- [x] A stale deterministic result or AI overlay cannot mutate content.
- [x] Failure at any point leaves content unchanged.

## WordPress 7.1 editor mutation

- [x] Production code locates the current block with the `core/block-editor` data store.
- [x] Production code uses `@wordpress/rich-text` `create()`, `applyFormat()`, and supported serialization.
- [x] Production code updates one direct `content` attribute with `updateBlockAttributes()`.
- [x] No direct iframe DOM manipulation, DOM scraping, `document.execCommand`, or raw HTML string replacement exists.
- [x] One insertion creates one normal WordPress editor history change.
- [x] Undo restores the exact prior state and Redo restores the link where Core supports it.
- [x] WordPress marks the draft dirty through normal editor behavior.

## Supported locations

- [x] `core/paragraph`, `core/heading`, and `core/list-item` direct `content` attributes are the complete 0.5 allowlist.
- [x] Quote prose is insertable only through an allowed child block.
- [x] Pullquote, verse, preformatted, table, caption-bearing, unknown, custom, dynamic, reusable, and entity-backed locations do not show Insert Link.
- [x] Unsupported locations may remain visible as read-only suggestions.

## Exact anchor identity

- [x] Current post ID, post type, canonical draft identity, target ID, block client ID, block name, direct attribute identity, exact text, occurrence, and request token bind the action.
- [x] Exact text and occurrence are recomputed at insertion time.
- [x] Multiple identical phrases use the explicitly validated zero-based occurrence.
- [x] PHP offsets are not treated as JavaScript RichText indices.
- [x] Unicode, emoji, entities, and punctuation resolve the correct UTF-16 RichText range.
- [x] Intertexere never relocates or guesses a changed anchor.
- [x] An AI unit key is mapped through server-held deterministic evidence and is never trusted directly.

## Server validation

- [x] A dedicated read-only validation service and authenticated custom REST POST route are used.
- [x] Cookie authentication, REST nonce, current source edit capability, and supported source post type are enforced.
- [x] The exact route and method have a raw-body limit before Core JSON parsing.
- [x] JSON schemas are strict and reject unknown fields.
- [x] Draft, unit, anchor, candidate, and response sizes remain bounded.
- [x] The canonical draft hash is reconstructed server-side.
- [x] Current deterministic analysis and candidate membership are revalidated.
- [x] Self-targeting is rejected.
- [x] Target existence, published status, password state, post type, configured exclusions, and eligibility are resolved from WordPress.
- [x] The current canonical target permalink comes from WordPress, never the client or model.
- [x] A pre-validation and post-validation target snapshot detects material target races.
- [x] Validation creates no post, revision, autosave, index, graph, option, transient, or schema mutation.

## Final client race check

- [x] A unique request token owns each insertion attempt.
- [x] A newer attempt, Analyze, Refresh, AI enhancement, draft change, navigation, post-type change, teardown, or unmount invalidates the request.
- [x] Validation requests are aborted where possible.
- [x] A late response cannot mutate newer state.
- [x] After the response, the client rechecks post identity, snapshot identity, block existence and name, attribute identity, exact occurrence, formatting boundary, and request ownership immediately before mutation.
- [x] Any change during validation prevents mutation.

## Formatting and links

- [x] The result is a normal Core link with only the current canonical `href` added by default.
- [x] No Intertexere class, hidden ID, `data-*` attribute, tracking parameter, script behavior, new-window target, or sponsored or nofollow relation is added.
- [x] Bold, italic, code, strikethrough, underline where registered, text color, highlight, mixed inline formats, entities, Unicode, emoji, and punctuation remain unchanged.
- [x] The operation verifies that text, replacements, and non-link formats are unchanged.
- [x] A range crossing a block, attribute, replacement object, malformed boundary, or unproved serialization invariant fails safely.
- [x] An exact range already linked to the same target is a no-op.
- [x] Full or partial overlap with another link fails.
- [x] Nested links cannot be created.

## Duplicate destination

- [x] The initial contract allows at most one link from the current draft to a resolved target post ID.
- [x] Duplicate state is recomputed from the current unsaved draft immediately before insertion.
- [x] Alternate absolute, relative, query-style, and supported old-slug forms resolving to the same target are duplicates.
- [x] Raw URL equality is not the primary identity check.
- [x] Repeated clicks cannot insert another link.

## Save, publish, and lifecycle

- [x] Insertion changes only the current editor draft.
- [x] Intertexere does not call Save, Update, Publish, autosave, revision APIs, or `wp_update_post()`.
- [x] Browser tests distinguish dirty editor state from saved database content.
- [x] A later normal WordPress save persists the ordinary link.
- [x] Before save, persistent index and graph state remain based on saved content.
- [x] After save, existing WordPress post-save hooks update index and graph without a special 0.5 write.
- [x] A saved link remains functional when Intertexere is disabled.

## Sidebar and accessibility

- [x] Insert Link extends the existing sidebar and appears only for a currently insertable exact anchor.
- [x] Ready, validating, inserted, stale, duplicate or already linked, unsupported, target unavailable, and error states are unambiguous.
- [x] The control supports keyboard activation and has a clear accessible name.
- [x] Loading and disabled states are exposed programmatically.
- [x] Success and failure status is announced with WordPress accessibility patterns.
- [x] No iframe focus hack is used.
- [x] Success invalidates current deterministic and AI session results without automatically rerunning analysis or AI.

## PHP and WordPress integration tests

- [x] Current source capability and supported post type.
- [x] Target existence, current eligibility, status, password, post type, exclusions, and current permalink.
- [x] Self-target rejection.
- [x] Duplicate target and already-linked rejection.
- [x] Alternate URL forms resolving to the same target.
- [x] Stale draft hash and analysis identity.
- [x] Malformed request, unknown fields, raw-body limit, and bounded anchor.
- [x] Target title, permalink, status, password, post-type, eligibility, and deletion races where material.
- [x] Zero post-content mutation, revision, autosave, index mutation, and graph mutation.
- [x] At most 16 total queries and 3.25 seconds on the established 125-post fixture.
- [x] At most 4 insertion-specific queries and 250 milliseconds beyond deterministic reanalysis.
- [x] Complete 0.1 through 0.4 PHP regression suite on WordPress 7.1 with PHP 7.4, 8.1, and 8.3.

## JavaScript tests

- [x] Insert control eligibility and the complete block allowlist.
- [x] No action without an exact safe anchor.
- [x] Request ownership, abort, navigation, teardown, and stale local state.
- [x] Exact occurrence when text repeats.
- [x] UTF-16 mapping for Unicode and emoji.
- [x] RichText formatting application and serialization.
- [x] Preservation of every reviewed inline format and text representation.
- [x] Existing-link overlap and same-target duplicate rejection.
- [x] Block disappearance, name change, client ID change, and attribute change.
- [x] Successful one-dispatch insertion and repeated-click protection.
- [x] Post-insertion stale session state.
- [x] No automatic save, publish, autosave, analysis, or AI request.
- [x] Accessible loading and status behavior.
- [x] Client preparation and mutation remain within 100 milliseconds at the per-unit bound.

## WordPress 7.1 Playwright tests

- [x] The actual iframe-based editor requires an explicit click.
- [x] A deterministic suggestion inserts one normal internal link.
- [x] An AI-kept suggestion passes the same validation before insertion.
- [x] Generated markup uses the current canonical permalink and ordinary anchor markup.
- [x] Surrounding text is semantically unchanged except for the anchor markup.
- [x] Surrounding inline formatting is preserved.
- [x] The draft becomes dirty but is not automatically saved or published.
- [x] Undo removes the link and Redo restores it where supported.
- [x] A normal WordPress save persists the link.
- [x] Reload after save shows the ordinary link without Intertexere dependency.
- [x] Duplicate insertion is prevented.
- [x] A manual edit while validation is delayed prevents mutation.
- [x] Navigation while validation is delayed prevents mutation.
- [x] Unsupported and stale anchors do not mutate content.
- [x] Disabling Intertexere after save leaves the link intact.
- [x] Complete 0.3 and 0.4 editor regression suite remains green.

## Regression, privacy, and persistence

- [x] Indexing, graph correctness, deterministic suggestions, optional AI enhancement, AI-disabled fallback, provider neutrality, prompt privacy, canonical hashing, transport protections, current-target races, and request ownership remain green.
- [x] No prompt payload expansion is required for insertion.
- [x] No provider request occurs during validation or insertion.
- [x] No persistent insertion or AI data is introduced.
- [x] Schema remains version 2.
- [x] Plugin version changes to 0.5.0 only with the accepted implementation, not with planning.

## Explicit exclusions

- [x] No automatic or bulk insertion.
- [x] No prose rewriting, replacement anchor generation, or AI-authored prose.
- [x] No automatic save, update, publish, or server-side content write.
- [x] No audit, orphan, under-linked, broken-link, redirect, or link-removal workflow.
- [x] No persistent recommendation learning, embeddings, vector database, multisite, WooCommerce-specific behavior, or cloud service.

## Review and delivery

- [x] Implementation uses `feature/0.5-link-insertion`.
- [x] The production and test diff is reviewed against `docs/LINK-INSERTION.md` and this checklist.
- [x] PHP syntax, all PHP matrix jobs, JavaScript lint, all Jest tests, reproducible build, complete WordPress 7.1 Playwright suite, and performance fixtures pass on the exact reviewed head.
- [ ] Independent review covers correctness, security, privacy, provider neutrality, prompt injection, stale requests, current-target races, exact occurrence mapping, formatting preservation, undo, and zero server-side content mutation.
- [x] The implementation PR is not merged before independent approval.
