# Milestone 0.3 Acceptance Criteria

## Status

This document defines the completion contract for milestone 0.3, Read-Only Editor Suggestions. The milestone is planned and not implemented.

## Scope gate

0.3 is complete only when it surfaces deterministic local suggestions in the WordPress 7.1 Block Editor without changing post content.

The branch must contain no AI or external-provider calls, embeddings, vector database, semantic ranking, generated explanations, generated anchor text, link insertion, block mutation, content rewriting, automatic publishing, orphan or under-linked detection, site-wide audit, broken-link repair, redirects, bulk workflow, automatic links, or persistent machine-learning feedback.

## Acceptance criteria

### Native editor integration

- The integration registers through supported WordPress editor APIs and appears in a native editor sidebar.
- The implementation works with the WordPress 7.1 iframe-based post editor without DOM scraping or direct iframe manipulation.
- Only supported Block Editor post screens load the integration.
- Editor navigation clears post-specific results, dismissals, caches, and pending request ownership.
- JavaScript failure, server failure, plugin deactivation, or an unavailable analysis subsystem does not interfere with normal editing, saving, previewing, or publishing.

### Unsaved draft authority

- Analysis uses the current unsaved editor title, taxonomy selections, blocks, text, and links submitted by the editor.
- A newer unsaved draft is never silently replaced by saved database content.
- Supported paragraphs, headings, list items, quote text, pullquotes, verse, preformatted text, table cells, and literal media captions are represented in editor order.
- Container traversal does not duplicate child text.
- Dynamic blocks, shortcode output, raw executable content, template parts, synced or reusable external entities, unsupported blocks, and malformed units are not rendered or executed.
- Existing links are recognized from the submitted unsaved markup using the established one-decode URL semantics.
- Alternate URL forms that deterministically resolve to one post ID are treated as one already-linked destination.
- No analysis path writes post content, post metadata, terms, index rows, graph rows, revisions, or autosaves.

### Draft identity and asynchronous correctness

- The server computes the documented canonical draft hash instead of trusting a client hash.
- The analysis ID includes the draft hash, active index generation, active graph generation, and algorithm version.
- A relevant draft change marks displayed results stale before a replacement response arrives.
- An aborted or late response for an older draft or post cannot replace current results.
- New post, post navigation, and post-type transitions are handled deliberately.

### Candidate retrieval and scoring

- Retrieval uses only bounded WordPress search, taxonomy, active content-index, and active link-graph data as specified in `EDITOR-SUGGESTIONS.md`.
- Ordinary requests do not enumerate or load every content-index row.
- Candidate pool, token, phrase, payload, and result limits are enforced.
- The published scoring formula, threshold, caps, and tie breakers produce stable ordering for identical inputs and state.
- Tests exercise every scoring signal and its cap without describing lexical relevance as AI confidence.
- Current WordPress title, permalink, post type, status, visibility, password state, and configured eligibility are checked at response construction.
- Incomplete replacement generations are not read as active.
- No suggestion is returned when no candidate meets the threshold.

### Exclusion and identity rules

- The current post is excluded.
- Deleted, ineligible, non-public, password-protected, excluded, and stale targets are excluded.
- A target already linked in the unsaved draft is excluded even if saved graph state differs.
- Duplicate destinations are suppressed by durable post ID.
- An unresolved URL never causes a guessed target identity.
- An exact unresolved current-permalink identity may suppress that same destination, but unrelated query identities remain distinct.
- Empty and extremely short supported drafts return no suggestions.

### Location and suggestion contract

- A proposed anchor is exact text already present within one supported analysis unit.
- An anchor never spans block, cell, caption, or incompatible markup boundaries.
- Duplicate-text locations use deterministic occurrence semantics.
- A relationship may be returned with null anchor and offset fields.
- Every location carries block identity, block text hash, draft hash, and analysis ID sufficient to detect staleness.
- The response conforms to the documented versioned suggestion schema.
- Target canonical metadata is obtained from current WordPress state and is not persisted as a second source of truth.

### UI behavior

- Analysis is user-triggered, and ordinary typing does not issue a request on every keystroke.
- The sidebar renders ready, loading, results, no-suggestion, stale, unavailable, and error states.
- Cards distinguish destination, deterministic reason, draft location or anchor, and already-linked state.
- View opens the current validated target permalink.
- Dismiss affects only the current analysis in the current editor session and creates no persistent preference.
- No Insert Link or other content-changing control exists.
- User-controlled and post-derived values are rendered safely.

### API security and failure behavior

- The endpoint is a read-only service exposed through authenticated REST POST for payload transport.
- Same-origin cookie authentication and a valid WordPress REST nonce are required.
- Existing sources require `edit_post`; new sources require the applicable edit capability for a supported post type.
- Post ID, post type, taxonomy, block, markup, and request sizes are validated against strict schemas and documented limits.
- Unknown fields, malformed JSON, oversized drafts, excessive units, invalid post IDs, mismatched post types, and unauthorized requests fail with appropriate non-sensitive errors.
- Server errors and unavailable index state do not change content or break the editor.
- No external network request occurs during analysis.

### Performance, rebuilds, and recovery

- No analysis runs automatically at editor load.
- Candidate retrieval remains bounded on a large eligible-content fixture and meets the implementation PR's documented latency budget.
- Client caching is session-only and keyed to current analysis identity.
- Unsaved draft text is not stored in options, transients, content-index rows, or graph rows.
- Analysis during index or graph rebuild reads only the stable active generation.
- A missing graph degrades by omitting graph signals; a missing active index returns a clear unavailable state.
- Clearing and rebuilding 0.1 and 0.2 derived data restores suggestion inputs without a 0.3 persistent-data migration.
- Deactivating Intertexere removes its UI and endpoint behavior without affecting saved content.

## Automated test plan

### PHP unit and WordPress integration tests

1. **Draft payload validation:** valid new and existing sources, strict field rejection, post-type mismatch, malformed blocks, invalid taxonomies, and every size/count limit.
2. **Capability and request protection:** logged-out, missing/invalid nonce, wrong user, `edit_post`, and new-post post-type capability cases against the real REST route.
3. **Literal draft parsing:** all included blocks, nested containers, list and quote traversal, table cells, captions, malformed units, and no duplicate container text.
4. **Execution safety:** shortcodes, dynamic render callbacks, raw HTML, synced patterns, and template parts prove no rendering or callback execution.
5. **Unsaved link identity:** absolute, relative, query-style, alternate permalink, fragments, one-decode attributes, unresolved query distinctions, duplicates, and links that differ from saved graph state.
6. **Draft hashes:** canonical ordering, taxonomy sorting, relevant changes, ignored unsupported data, server recomputation, post identity, and algorithm-version changes.
7. **Candidate sources:** bounded native search, taxonomy, inbound graph, shared-target graph, union deduplication, pool cap, and active-generation reads.
8. **Scoring formula:** every signal, denominator-zero behavior, caps, repeated terms, minimum threshold, maximum result count, and exact tie breakers.
9. **Hard exclusions:** self, already linked, duplicate target, deleted, draft, private, password protected, configured exclusion, stale index row, and exact unresolved identity.
10. **No-suggestion behavior:** empty, short, unsupported-only, low-score, no-index, and no-graph cases.
11. **Current metadata:** target title and permalink changes after indexing, target deletion or eligibility change between retrieval and response, and durable target identity.
12. **Location semantics:** exact single-unit anchors, markup boundaries, repeated text occurrence, safe null anchors, excerpts, offsets, and hashes.
13. **REST contract and errors:** input/output schema, content type, standard error codes, safe messages, and no external calls.
14. **Read-only proof:** snapshots of posts, revisions, autosaves, terms, options, index tables, and graph tables before and after success and all failure paths.
15. **Rebuild states:** live active generations remain readable while replacement generations exist; generation changes alter analysis identity; incomplete data never becomes a suggestion source.
16. **Large fixtures:** content count large enough to prove only bounded IDs and rows are scored, plus a draft at each payload boundary and recorded query count/latency budget.
17. **Plugin-disabled behavior:** editor and REST integration disappear while WordPress content and ordinary links remain usable.
18. **Regression:** retain and run the complete 0.1 and 0.2 PHP suites on WordPress 7.1 with PHP 7.4, 8.1, and 8.3, including PHP syntax validation.

### JavaScript unit and component tests

Use the WordPress-supported `@wordpress/scripts` Jest environment and WordPress package mocks only at package boundaries. Tests cover:

- ordered snapshot creation and supported/unsupported block selection;
- canonical client hash inputs and change detection;
- request aborting, request tokens, late-response rejection, and post navigation reset;
- session cache and dismissal keys;
- ready, loading, results, empty, stale, unavailable, and error rendering;
- safe text rendering and View behavior;
- absence of an insertion or mutation dispatch path;
- payload and client-side limit handling.

### WordPress editor end-to-end tests

Use `@wordpress/e2e-test-utils-playwright` with an actual WordPress 7.1 environment and the built plugin. Do not replace this layer with a mocked DOM harness. Tests cover:

- sidebar registration and operation in the iframe-based editor;
- analysis of unsaved blocks and links before saving;
- manual trigger behavior and no request on each keystroke;
- stale marking and late-response ordering under controlled delayed responses;
- post navigation cleanup;
- Dismiss and View actions;
- loading, no-suggestion, unauthorized, unavailable, and server-error states;
- saved post content byte-for-byte unchanged after every sidebar action;
- normal save and publish behavior with the plugin active and normal editing with it disabled.

The implementation branch adds a production asset build, JavaScript lint/unit job, and one WordPress 7.1 browser job while retaining the existing PHP matrix. The Node LTS and WordPress package versions must be pinned and documented when implementation begins.

## Required implementation review

Before a 0.3 implementation PR is opened:

1. run PHP syntax validation and the full WordPress 7.1/PHP 7.4, 8.1, and 8.3 matrix;
2. run JavaScript lint, unit, component, and WordPress 7.1 editor end-to-end tests;
3. inspect the complete diff against README, every document in `docs`, and the implementation issue;
4. verify each acceptance item above individually;
5. inspect all calls and dispatches for post, block, term, index, graph, option, and network mutation;
6. verify no 0.4, 0.5, or 0.6 behavior entered the branch;
7. report test and assertion totals, browser-test totals, matrix results, changed files, commits, performance measurements, and known limitations.
