# 0.5 Acceptance Criteria: Explicit Link Insertion

## Status

This is the approved acceptance plan for milestone 0.5. It does not mark insertion implemented.

## Completion rule

0.5 is complete only when every criterion below is proven on `feature/0.5-link-insertion`, the production diff has received independent review, and the reviewed head passes the full continuous-integration matrix before merge.

## Product and authority

- [ ] The user must click Insert Link for every insertion.
- [ ] A displayed deterministic or AI suggestion is never sufficient mutation authority.
- [ ] The target remains a current deterministic candidate and is identified by WordPress post ID.
- [ ] AI can neither introduce a destination nor bypass deterministic, server, or local validation.
- [ ] A stale deterministic result or AI overlay cannot mutate content.
- [ ] Failure at any point leaves content unchanged.

## WordPress 7.1 editor mutation

- [ ] Production code locates the current block with the `core/block-editor` data store.
- [ ] Production code uses `@wordpress/rich-text` `create()`, `applyFormat()`, and supported serialization.
- [ ] Production code updates one direct `content` attribute with `updateBlockAttributes()`.
- [ ] No direct iframe DOM manipulation, DOM scraping, `document.execCommand`, or raw HTML string replacement exists.
- [ ] One insertion creates one normal WordPress editor history change.
- [ ] Undo restores the exact prior state and Redo restores the link where Core supports it.
- [ ] WordPress marks the draft dirty through normal editor behavior.

## Supported locations

- [ ] `core/paragraph`, `core/heading`, and `core/list-item` direct `content` attributes are the complete 0.5 allowlist.
- [ ] Quote prose is insertable only through an allowed child block.
- [ ] Pullquote, verse, preformatted, table, caption-bearing, unknown, custom, dynamic, reusable, and entity-backed locations do not show Insert Link.
- [ ] Unsupported locations may remain visible as read-only suggestions.

## Exact anchor identity

- [ ] Current post ID, post type, canonical draft identity, target ID, block client ID, block name, direct attribute identity, exact text, occurrence, and request token bind the action.
- [ ] Exact text and occurrence are recomputed at insertion time.
- [ ] Multiple identical phrases use the explicitly validated zero-based occurrence.
- [ ] PHP offsets are not treated as JavaScript RichText indices.
- [ ] Unicode, emoji, entities, and punctuation resolve the correct UTF-16 RichText range.
- [ ] Intertexere never relocates or guesses a changed anchor.
- [ ] An AI unit key is mapped through server-held deterministic evidence and is never trusted directly.

## Server validation

- [ ] A dedicated read-only validation service and authenticated custom REST POST route are used.
- [ ] Cookie authentication, REST nonce, current source edit capability, and supported source post type are enforced.
- [ ] The exact route and method have a raw-body limit before Core JSON parsing.
- [ ] JSON schemas are strict and reject unknown fields.
- [ ] Draft, unit, anchor, candidate, and response sizes remain bounded.
- [ ] The canonical draft hash is reconstructed server-side.
- [ ] Current deterministic analysis and candidate membership are revalidated.
- [ ] Self-targeting is rejected.
- [ ] Target existence, published status, password state, post type, configured exclusions, and eligibility are resolved from WordPress.
- [ ] The current canonical target permalink comes from WordPress, never the client or model.
- [ ] A pre-validation and post-validation target snapshot detects material target races.
- [ ] Validation creates no post, revision, autosave, index, graph, option, transient, or schema mutation.

## Final client race check

- [ ] A unique request token owns each insertion attempt.
- [ ] A newer attempt, Analyze, Refresh, AI enhancement, draft change, navigation, post-type change, teardown, or unmount invalidates the request.
- [ ] Validation requests are aborted where possible.
- [ ] A late response cannot mutate newer state.
- [ ] After the response, the client rechecks post identity, snapshot identity, block existence and name, attribute identity, exact occurrence, formatting boundary, and request ownership immediately before mutation.
- [ ] Any change during validation prevents mutation.

## Formatting and links

- [ ] The result is a normal Core link with only the current canonical `href` added by default.
- [ ] No Intertexere class, hidden ID, `data-*` attribute, tracking parameter, script behavior, new-window target, or sponsored or nofollow relation is added.
- [ ] Bold, italic, code, strikethrough, underline where registered, text color, highlight, mixed inline formats, entities, Unicode, emoji, and punctuation remain unchanged.
- [ ] The operation verifies that text, replacements, and non-link formats are unchanged.
- [ ] A range crossing a block, attribute, replacement object, malformed boundary, or unproved serialization invariant fails safely.
- [ ] An exact range already linked to the same target is a no-op.
- [ ] Full or partial overlap with another link fails.
- [ ] Nested links cannot be created.

## Duplicate destination

- [ ] The initial contract allows at most one link from the current draft to a resolved target post ID.
- [ ] Duplicate state is recomputed from the current unsaved draft immediately before insertion.
- [ ] Alternate absolute, relative, query-style, and supported old-slug forms resolving to the same target are duplicates.
- [ ] Raw URL equality is not the primary identity check.
- [ ] Repeated clicks cannot insert another link.

## Save, publish, and lifecycle

- [ ] Insertion changes only the current editor draft.
- [ ] Intertexere does not call Save, Update, Publish, autosave, revision APIs, or `wp_update_post()`.
- [ ] Browser tests distinguish dirty editor state from saved database content.
- [ ] A later normal WordPress save persists the ordinary link.
- [ ] Before save, persistent index and graph state remain based on saved content.
- [ ] After save, existing WordPress post-save hooks update index and graph without a special 0.5 write.
- [ ] A saved link remains functional when Intertexere is disabled.

## Sidebar and accessibility

- [ ] Insert Link extends the existing sidebar and appears only for a currently insertable exact anchor.
- [ ] Ready, validating, inserted, stale, duplicate or already linked, unsupported, target unavailable, and error states are unambiguous.
- [ ] The control supports keyboard activation and has a clear accessible name.
- [ ] Loading and disabled states are exposed programmatically.
- [ ] Success and failure status is announced with WordPress accessibility patterns.
- [ ] No iframe focus hack is used.
- [ ] Success invalidates current deterministic and AI session results without automatically rerunning analysis or AI.

## PHP and WordPress integration tests

- [ ] Current source capability and supported post type.
- [ ] Target existence, current eligibility, status, password, post type, exclusions, and current permalink.
- [ ] Self-target rejection.
- [ ] Duplicate target and already-linked rejection.
- [ ] Alternate URL forms resolving to the same target.
- [ ] Stale draft hash and analysis identity.
- [ ] Malformed request, unknown fields, raw-body limit, and bounded anchor.
- [ ] Target title, permalink, status, password, post-type, eligibility, and deletion races where material.
- [ ] Zero post-content mutation, revision, autosave, index mutation, and graph mutation.
- [ ] At most 16 total queries and 3.25 seconds on the established 125-post fixture.
- [ ] At most 4 insertion-specific queries and 250 milliseconds beyond deterministic reanalysis.
- [ ] Complete 0.1 through 0.4 PHP regression suite on WordPress 7.1 with PHP 7.4, 8.1, and 8.3.

## JavaScript tests

- [ ] Insert control eligibility and the complete block allowlist.
- [ ] No action without an exact safe anchor.
- [ ] Request ownership, abort, navigation, teardown, and stale local state.
- [ ] Exact occurrence when text repeats.
- [ ] UTF-16 mapping for Unicode and emoji.
- [ ] RichText formatting application and serialization.
- [ ] Preservation of every reviewed inline format and text representation.
- [ ] Existing-link overlap and same-target duplicate rejection.
- [ ] Block disappearance, name change, client ID change, and attribute change.
- [ ] Successful one-dispatch insertion and repeated-click protection.
- [ ] Post-insertion stale session state.
- [ ] No automatic save, publish, autosave, analysis, or AI request.
- [ ] Accessible loading and status behavior.
- [ ] Client preparation and mutation remain within 100 milliseconds at the per-unit bound.

## WordPress 7.1 Playwright tests

- [ ] The actual iframe-based editor requires an explicit click.
- [ ] A deterministic suggestion inserts one normal internal link.
- [ ] An AI-kept suggestion passes the same validation before insertion.
- [ ] Generated markup uses the current canonical permalink and ordinary anchor markup.
- [ ] Surrounding text is semantically unchanged except for the anchor markup.
- [ ] Surrounding inline formatting is preserved.
- [ ] The draft becomes dirty but is not automatically saved or published.
- [ ] Undo removes the link and Redo restores it where supported.
- [ ] A normal WordPress save persists the link.
- [ ] Reload after save shows the ordinary link without Intertexere dependency.
- [ ] Duplicate insertion is prevented.
- [ ] A manual edit while validation is delayed prevents mutation.
- [ ] Navigation while validation is delayed prevents mutation.
- [ ] Unsupported and stale anchors do not mutate content.
- [ ] Disabling Intertexere after save leaves the link intact.
- [ ] Complete 0.3 and 0.4 editor regression suite remains green.

## Regression, privacy, and persistence

- [ ] Indexing, graph correctness, deterministic suggestions, optional AI enhancement, AI-disabled fallback, provider neutrality, prompt privacy, canonical hashing, transport protections, current-target races, and request ownership remain green.
- [ ] No prompt payload expansion is required for insertion.
- [ ] No provider request occurs during validation or insertion.
- [ ] No persistent insertion or AI data is introduced.
- [ ] Schema remains version 2.
- [ ] Plugin version changes to 0.5.0 only with the accepted implementation, not with planning.

## Explicit exclusions

- [ ] No automatic or bulk insertion.
- [ ] No prose rewriting, replacement anchor generation, or AI-authored prose.
- [ ] No automatic save, update, publish, or server-side content write.
- [ ] No audit, orphan, under-linked, broken-link, redirect, or link-removal workflow.
- [ ] No persistent recommendation learning, embeddings, vector database, multisite, WooCommerce-specific behavior, or cloud service.

## Review and delivery

- [ ] Implementation uses `feature/0.5-link-insertion`.
- [ ] The production and test diff is reviewed against `docs/LINK-INSERTION.md` and this checklist.
- [ ] PHP syntax, all PHP matrix jobs, JavaScript lint, all Jest tests, reproducible build, complete WordPress 7.1 Playwright suite, and performance fixtures pass on the exact reviewed head.
- [ ] Independent review covers correctness, security, privacy, provider neutrality, prompt injection, stale requests, current-target races, exact occurrence mapping, formatting preservation, undo, and zero server-side content mutation.
- [ ] The implementation PR is not merged before independent approval.
