# Milestone 0.4 Acceptance Criteria

## Status

This document records the completion contract implemented by milestone 0.4, WordPress-Native AI Integration.

## Scope gate

0.4 is complete only when WordPress-native AI can optionally filter, rerank, explain, and recommend an exact existing anchor for the current bounded 0.3 deterministic candidate set while deterministic suggestions remain independently usable and post content remains unchanged.

The branch must contain no link insertion, Insert Link control, block mutation, post-content mutation, automatic link creation, automatic publishing, site audit, orphan or under-linked detection, broken-link repair, bulk workflow, persistent learning, vector database, embedding, vendor-specific credential store, arbitrary AI-selected destination, or persistent prompt, response, draft, explanation, or score store.

Schema remains version 2.

## Acceptance criteria

### WordPress-native provider boundary

- Production AI calls use `wp_ai_client_prompt()` and the WordPress AI Client default provider registry.
- Intertexere contains no vendor SDK, provider-specific key handling, hard-coded commercial model, or AI secret.
- Provider registration and credentials remain owned by WordPress Connectors.
- The implementation checks the configured prompt builder's text-generation support before invocation and does not treat `wp_supports_ai()` or a registered connector as proof of a usable model.
- Structured JSON response support, request options, output limits, and the reviewed timeout are applied through the WordPress 7.1 AI Client.
- No Ability or AI tool is exposed to the model in 0.4.
- CI and browser tests use a deterministic fake at the adapter boundary and make no live paid provider call.

### Deterministic authority

- The 0.3 deterministic analyzer continues to work when AI is disabled, unavailable, unconfigured, slow, rate-limited, malformed, or failing.
- AI enhancement starts only from a current completed deterministic analysis.
- The server reruns and revalidates deterministic analysis from the submitted unsaved draft before making an AI call.
- Every requested target belongs to the fresh deterministic candidate set.
- AI cannot add, substitute, or request an arbitrary destination.
- Deterministic score and signals remain unchanged and separately represented.
- No AI result is a valid outcome, including when deterministic suggestions exist.

### Explicit use and privacy

- AI enhancement is disabled by default through an intentional existing-settings preference.
- A user must explicitly invoke **Enhance with AI** for each current analysis that is not served from the permitted identical session cache.
- Opening the sidebar, typing, autosave, deterministic Analyze, and deterministic Refresh make no external AI request.
- The UI discloses before invocation that unsaved title and bounded draft and candidate context may leave the site through the WordPress-configured provider.
- The prompt excludes the entire site, full candidate bodies, credentials, unrelated private editor state, arbitrary metadata, excluded posts, and ineligible posts.
- Prompt payload tests assert the exact categories and size bounds of externally sent data.
- Intertexere persists no prompt, AI response, draft text, explanation, or AI score.

### Request and prompt bounds

- The enhancement endpoint is an authenticated custom REST POST route and uses the actual raw body length before Core JSON parsing.
- The raw body cannot exceed 262,144 bytes, regardless of `Content-Length`.
- Existing nonce, cookie authentication, source-edit capability, post type, unknown-field, decoded-payload, unit-count, and per-unit protections remain enforced.
- At most 8 deterministic candidates and 8 draft context units are sent to AI.
- Combined draft context, per-candidate context, canonical model payload, output, explanation, anchor, and token limits match `AI-INTEGRATION.md`.
- Oversized or malformed input fails before provider invocation.
- Provider output exceeding the response boundary is rejected without replacing deterministic results.

### Prompt injection boundary

- The system instruction defines submitted draft and candidate content as untrusted data, not instructions.
- Content is serialized separately from the system instruction.
- The model receives no Abilities, tools, web search, file access, or arbitrary site retrieval mechanism.
- Draft or candidate text that asks the model to ignore restrictions cannot expand the candidate set or expose additional site data.
- AI output is treated as untrusted input at every boundary.

### Structured response and ranking

- The response schema has a version, request-scoped candidate keys, keep/drop decisions, unique contiguous ranks for kept candidates, bounded explanations, and nullable anchor evidence.
- Exactly one evaluation is required for each submitted candidate, with no unknown, missing, or duplicate keys.
- Structural, key, rank, type, size, or schema failure rejects the complete enhancement.
- AI filters and reranks only. It does not overwrite or combine with the deterministic score through an undocumented formula.
- The UI does not label AI rank or judgment as calibrated confidence.
- Current target title, permalink, post type, status, and eligibility are resolved from WordPress after AI output validation.

### Explanations and anchors

- Explanations are advisory, escaped, and within the documented character and byte limits.
- Explanations are based only on submitted draft and candidate context and do not assert hidden facts or guaranteed SEO outcomes.
- Anchor recommendations select exact text already present at the claimed occurrence in one submitted supported draft unit.
- The model cannot supply trusted offsets. The server computes them after exact-match validation.
- Anchors do not cross block, unit, table-cell, caption, or markup boundaries.
- Rewritten, nonexistent, ambiguous, or stale anchor text is discarded to null without manufacturing prose.
- A valid candidate judgment can be displayed without an anchor.

### Current-state and race validation

- The enhancement request is bound to post identity, canonical draft hash, deterministic analysis ID, index generation, graph generation, candidate set, request token, and AI contract and prompt versions.
- Editing the draft while AI runs makes the pending result stale.
- A newer deterministic analysis or AI request owns the editor state over an older response.
- Navigation or post-type transition rejects the prior post's response and clears its session state.
- A target deleted, unpublished, password protected, excluded, or made otherwise ineligible before response construction is absent from enhanced results.
- A destination linked in the current unsaved draft before response construction is absent from enhanced results.
- Current title and permalink changes are reflected from WordPress.
- A late provider result cannot overwrite newer deterministic or editor state even if the provider call could not be cancelled.

### Availability and error handling

- Site-disabled, WordPress-disabled, no-provider, no-compatible-model, invalid-credential, timeout, network, rate-limit, upstream, token-limit, malformed-output, and validation-failure paths have explicit safe states.
- Every failure preserves deterministic suggestions and ordinary editor behavior.
- No background retry consumes provider quota without a new explicit user action.
- User-facing errors contain no credentials, raw prompts, private provider payloads, stack traces, or provider-specific secrets.
- Only one current enhancement request may own a session result.

### Editor experience

- AI enhancement appears inside the existing Intertexere sidebar rather than a competing suggestion system.
- **Analyze draft**, **Refresh suggestions**, **View**, and session-only **Dismiss** remain available as applicable.
- The UI distinguishes deterministic, disabled, unavailable, ready, running, enhanced, failed-with-fallback, and stale states.
- A user can return to deterministic ordering after valid AI filtering or reranking.
- There is no Insert Link or other content-changing control.
- JavaScript failure or AI service failure does not interfere with editing, saving, previewing, or publishing.

### Persistence and mutation

- Schema version remains 2 and no migration is introduced.
- The only new stored value is the intentional AI enable preference in the existing settings option.
- No database table, post meta, transient, index row, graph row, revision, autosave, post, block, taxonomy, prompt, response, explanation, or AI score is written by enhancement.
- Deactivating Intertexere leaves ordinary content, links, editing, and publishing behavior intact.

### Performance and cost controls

- Each provider request is explicitly triggered, bounded to 8 candidates, and limited to one active owner.
- The provider HTTP timeout is 20 seconds and no automatic retry occurs.
- The 125-post fixture retains the 0.3 deterministic limit of 12 queries and 3 seconds.
- AI preparation and post-response validation add at most 8 database queries and 500 milliseconds of provider-independent local work.
- The entire provider-independent enhancement endpoint uses at most 20 queries and 3.5 seconds in the test environment.
- Test output reports deterministic time, candidate count, prompt construction time, prompt bytes, response validation time, and total provider-independent queries.
- Provider latency is reported separately and is not represented as local performance.

## Automated test plan

### PHP and WordPress integration

Tests cover:

1. site preference disabled and enabled;
2. WordPress AI support disabled;
3. connector registered but no compatible configured model;
4. compatible fake provider success;
5. timeout, network, invalid-credential, rate-limit, upstream, and token-limit errors;
6. malformed, oversized, and schema-invalid provider output;
7. unknown, missing, and duplicate candidate keys;
8. invalid, duplicate, non-contiguous, and out-of-range ranks;
9. crafted candidate IDs outside the deterministic set;
10. strict candidate, unit, context, payload, token, output, explanation, and anchor limits;
11. stale draft hash, analysis ID, index generation, and graph generation;
12. target deletion, eligibility, visibility, password, permalink, and title races;
13. an already-linked transition during enhancement;
14. exact anchor text and occurrence validation;
15. prompt privacy and prompt-injection separation;
16. no provider call without an explicit enhancement endpoint request;
17. no prompt, response, draft, explanation, or score persistence;
18. no post content, block, metadata, term, index, or graph mutation;
19. route authentication, nonce, capability, raw-body, decoded-payload, and malformed-request boundaries;
20. bounded queries and provider-independent local latency;
21. complete 0.1, 0.2, and 0.3 regression suites on WordPress 7.1 and PHP 7.4, 8.1, and 8.3.

### JavaScript

Jest tests cover:

1. deterministic state before AI invocation;
2. explicit trigger and privacy disclosure;
3. disabled, unavailable, loading, enhanced, failed, stale, and no-enhanced-result states;
4. deterministic fallback on every failure;
5. AI filtering and ordering with deterministic score preserved;
6. safe explanation and anchor rendering;
7. draft edit, deterministic refresh, and second request while AI runs;
8. stale and late response rejection;
9. navigation and post-identity cleanup;
10. session cache keying and invalidation;
11. request abort and one-owner behavior;
12. no insertion or block-mutation controls.

### WordPress 7.1 browser E2E

Playwright uses the real iframe editor and a deterministic test adapter to prove:

1. deterministic suggestions work with no model configured;
2. no AI request occurs without the explicit action;
3. a valid fake enhancement appears in the existing sidebar;
4. provider failure preserves deterministic suggestions;
5. draft edits while AI runs make the result stale;
6. navigation rejects the old response;
7. save, preview, and publish remain ordinary;
8. the draft and saved post content are unchanged by enhancement;
9. no Insert Link control exists.

## Required verification before merge

- PHP syntax validation passes.
- The complete WordPress 7.1 integration suite passes on PHP 7.4, 8.1, and 8.3.
- JavaScript lint and every Jest suite pass.
- A clean reproducible production build matches committed assets.
- The WordPress 7.1 Playwright editor suite passes.
- The bounded performance fixture reports the required local measurements.
- The full diff is reviewed against `README.md`, every `/docs` contract, and the 0.4 implementation issue.
- No 0.5 or 0.6 behavior, content mutation, new schema, or persistent AI data enters the branch.
- No live AI credential or external paid request is required in CI.
- Independent review confirms deterministic authority, privacy, prompt-injection containment, response validation, request ownership, and graceful fallback before merge.

## Explicit exclusions

0.4 does not implement link insertion, an Insert Link button, RichText or block mutation, post-content mutation, automatic links, automatic publishing, audits, orphan detection, under-linked detection, broken-link repair, bulk workflows, persistent recommendation learning, vector databases, embeddings, vendor-specific key management, arbitrary AI-selected destinations, or persistent AI response storage.
