# 0.6 Acceptance Criteria: Site Link Audit

## Status

This is the planning and future implementation contract for milestone 0.6. The milestone is planned and not implemented. Planning leaves plugin version 0.5.0 and schema version 2 unchanged.

## Scope gate

0.6 is complete only when an authorized administrator can inspect deterministic, current, generation-consistent site link findings without Intertexere changing any content or invoking AI.

The implementation must contain no automatic repair, link insertion, replacement, deletion, redirect, anchor rewriting, bulk content mutation, automatic save or publish, external or rendered crawler, AI audit request, embedding, vector database, multisite behavior, WooCommerce-specific rule, cloud synchronization, external SEO API, persistent audit result, or editor-scoring change.

## Source of truth and classifications

- [ ] Saved WordPress posts and current WordPress identity, status, visibility, password, post type, eligibility, and permalink remain authoritative.
- [ ] Index and graph rows are treated as active-generation, rebuildable evidence only.
- [ ] Audit output is request-scoped and never stored as a second source of truth.
- [ ] Content-body orphan means zero distinct eligible, published, public, non-password-protected, non-self inbound sources in literal saved eligible content.
- [ ] The UI accurately states that navigation, templates, theme chrome, widgets, dynamic output, and external links are not observed.
- [ ] Thin inbound coverage means exactly one qualifying distinct inbound source. It is separate from zero-inbound orphans.
- [ ] No arbitrary outbound quota, age rule, AI score, or SEO grade changes the orphan or thin-inbound definition.
- [ ] Self-links are separately reported and excluded from structural inbound and outbound counts.
- [ ] A resolved `occurrence_count > 1` is a repeated-target review opportunity, not an automatic error.
- [ ] Repeated unresolved identities may be shown accurately, but repeated anchor text is not claimed from schema 2 evidence.

## Unresolved, unavailable, and canonical URL behavior

- [ ] An internal URL with no durable post ID is “unresolved,” not automatically “broken.”
- [ ] Resolved missing, trashed, unpublished, private, password-protected, and ineligible targets receive distinct current-state reasons.
- [ ] Current canonical permalinks, supported query URLs, and retained old-slug forms that resolve to an eligible post remain valid.
- [ ] Fragment-only, external, non-web, and parser-rejected malformed hrefs are not misreported as graph findings.
- [ ] Destination identity is never guessed.
- [ ] A noncanonical but valid URL is a review opportunity, not a broken link.
- [ ] Noncanonical reporting is limited to the representative URL evidence schema 2 can prove and never claims all aggregated occurrences use that form.
- [ ] No URL is automatically rewritten.

## Generation consistency and current revalidation

- [ ] Every request captures exact active index and graph generation IDs and includes them in every derived-data query.
- [ ] Every cursor binds category, filters, stable key, both generations, and audit contract version.
- [ ] Index cutover before response returns a stale result and no findings.
- [ ] Graph cutover before response returns a stale result and no findings.
- [ ] In-progress replacement generations are never read before cutover.
- [ ] A later page cannot continue with generation IDs that are no longer active.
- [ ] Current source and target objects are bulk loaded and checked for existence, post type, status, visibility, password, configured eligibility, exclusion filters, and current permalink where displayed.
- [ ] Current graph source-state evidence remains `current` for displayed sources.
- [ ] Relevant authority is rechecked immediately before response construction.
- [ ] Source or target changes during calculation fail stale or suppress the invalid finding without returning stale state as current.
- [ ] Current revalidation introduces no N+1 post, permalink, eligibility, or resolver path.

## Execution, pagination, and persistence

- [ ] Audit results are computed on demand through authenticated native wp-admin requests.
- [ ] The default result size is 20 and the hard maximum is 50.
- [ ] Post categories paginate by stable post-ID keyset.
- [ ] Edge categories paginate by stable `(source_post_id, target_identity_hash)` keyset.
- [ ] No unbounded offset pagination or entire-site PHP materialization is used.
- [ ] Strict allowlists bound category, post type, search, page size, generation, and cursor input.
- [ ] Two simultaneous audit reads share no mutable audit-run state.
- [ ] Refresh starts a new current read and does not rebuild index or graph.
- [ ] No audit table, option, transient, metadata, taxonomy, cron event, token, or persisted snapshot is introduced.
- [ ] Schema remains version 2.

## Admin UI, permissions, and accessibility

- [ ] A dedicated Site Link Audit screen appears under Tools using WordPress-native admin patterns.
- [ ] Only users with `manage_intertexere` may view site-wide audit data.
- [ ] A user who can edit an individual post but lacks `manage_intertexere` cannot access the audit.
- [ ] View and Edit actions are shown only when WordPress grants the corresponding current post capability.
- [ ] Tabs cover content-body orphans, thin inbound coverage, unresolved and unavailable links, repeated targets, and self-links.
- [ ] Noncanonical opportunities appear only if the evidence rule is satisfied.
- [ ] Rows show a concise deterministic reason and current source or target details without an SEO score.
- [ ] Loading or processing, empty, unavailable, stale, and error states are explicit.
- [ ] Tables or lists have correct semantics, headings and row actions have meaningful accessible labels, filters and pagination are keyboard usable, and status is not conveyed by color alone.
- [ ] The initial interface is server-rendered unless implementation provides a separately reviewed need for JavaScript.
- [ ] No audit screen contains automatic or bulk mutation controls.

## Security, privacy, and AI

- [ ] Native admin requests require an authenticated session and `manage_intertexere`.
- [ ] Query input is strictly validated, SQL is prepared, and all output and action URLs are escaped.
- [ ] Any later state-changing admin request requires a nonce and remains outside this read-only contract.
- [ ] No audit REST route is added without a separately specified cookie, nonce, capability, strict-schema, unknown-field, bounded-payload, and raw-transport contract.
- [ ] Audit content, graph data, titles, URLs, and findings remain local to WordPress.
- [ ] Auditing makes zero WordPress AI Client or external-provider requests.
- [ ] The 0.4 prompt, provider-neutrality contract, and session-only AI storage remain unchanged.
- [ ] 0.3 deterministic scoring and 0.5 insertion authority remain unchanged.

## Content and derived-state safety

- [ ] Viewing, filtering, paginating, refreshing, failing, or deactivating during an audit changes zero post content bytes.
- [ ] The audit creates zero revisions and autosaves and does not save or publish a post.
- [ ] The audit changes zero editor blocks or editor state.
- [ ] The audit changes zero index rows, graph rows, active generation options, settings, transients, metadata, taxonomies, or schema state.
- [ ] No `wp_update_post()` or server-side content mutation path is used.
- [ ] Disabling Intertexere does not alter ordinary links or saved content.

## PHP integration tests

At minimum, tests must prove:

1. exact content-body orphan classification and its content-only limitation;
2. exact thin-inbound classification for one distinct qualifying source;
3. self-link retention, separate reporting, and exclusion from counts;
4. duplicate target occurrence reporting without declaring every duplicate wrong;
5. unresolved internal URL classification without guessed identity;
6. supported old-slug and query-style URL resolution;
7. current canonical, relative, and valid noncanonical URL treatment;
8. deleted, trashed, private, password-protected, unsupported, excluded, and otherwise ineligible targets;
9. ineligible sources not contributing inbound authority;
10. current source and target eligibility and permalink revalidation;
11. stable active-generation reads while a replacement rebuild runs;
12. index cutover and graph cutover races returning stale;
13. post save during calculation;
14. source deletion and target deletion during calculation;
15. status, password, post-type, permalink, and eligibility races;
16. bounded keyset pagination with no missing or duplicate findings;
17. invalid category, cursor, generation, page size, post type, and search input;
18. `manage_intertexere` success and failure;
19. View/Edit capability filtering;
20. database/service failure and two concurrent read requests;
21. zero content, revision, autosave, editor, AI, index, graph, option, transient, metadata, taxonomy, and schema mutation;
22. complete 0.1 through 0.5 regression coverage.

The matrix remains WordPress 7.1 on PHP 7.4, 8.1, and 8.3, plus PHP syntax validation.

## JavaScript and browser tests

If implementation adds audit JavaScript, Jest must cover loading, results, empty, error, stale, filters, keyset pagination, accessibility announcements, request ownership, and absence of mutation controls. If the planned server-rendered interface uses no audit JavaScript, document that decision and retain the complete existing Jest suite.

WordPress 7.1 Playwright must prove:

- an authorized administrator opens the audit screen;
- an unauthorized role cannot access it;
- known fixtures appear in the correct tabs with accurate language;
- filters and pagination work with keyboard access;
- View and Edit actions navigate correctly and respect capability;
- stale/unavailable and empty states render safely;
- opening and using the audit leaves database post content unchanged;
- no save, publish, AI, or repair request occurs;
- complete 0.3, 0.4, and 0.5 editor behavior remains green.

## Performance acceptance

Run on all three supported PHP versions:

- [ ] At least 125 eligible posts and 500 edges include known orphan, thin, repeated, unresolved, unavailable, self, and noncanonical cases.
- [ ] At least 1,000 eligible posts and 4,000 edges prove bounded pagination.
- [ ] Overview uses no more than 12 queries and completes in less than 1.0 second.
- [ ] A 20-row page including current revalidation uses no more than 12 queries and completes in less than 750 ms.
- [ ] A 50-row page uses no more than 14 queries, completes in less than 1.0 second, and adds less than 32 MiB peak memory.
- [ ] Page two uses an indexed keyset plan and no unbounded offset.
- [ ] Query logs prove no per-finding post, permalink, eligibility, or URL-resolution queries.
- [ ] Results record queries, elapsed time, memory delta, result or examined rows where observable, and cursor behavior.
- [ ] Existing 0.3, 0.4, and 0.5 performance fixtures remain within their accepted budgets.

## Documentation and version gate

- [ ] `README.md`, `ARCHITECTURE.md`, `ROADMAP.md`, `DEVELOPMENT.md`, `EDITOR-SUGGESTIONS.md`, `AI-INTEGRATION.md`, `LINK-INSERTION.md`, and `WORDPRESS-REFERENCES.md` remain consistent with the implemented audit.
- [ ] The implementation branch is `feature/0.6-site-link-audit` and is created only after planning receives independent review.
- [ ] Planning does not change production code, build artifacts, package dependencies, plugin version, or schema.
- [ ] Plugin version changes from 0.5.0 to 0.6.0 only after the complete implementation acceptance contract passes.
- [ ] The full production diff receives independent review before merge.

## Explicit exclusions

- [ ] No automatic or bulk repair, link insertion, replacement, deletion, redirect, anchor rewrite, save, update, publish, or content mutation.
- [ ] No external or rendered-front-end crawler, navigation/template audit, external SEO API, AI audit, embedding, vector database, cloud sync, multisite, or WooCommerce-specific behavior.
- [ ] No editor ranking boost or suggestion integration is introduced implicitly.
- [ ] No schema migration or persistent audit state is introduced.

## Completion standard

0.6 is complete only when every checkbox is satisfied on the implementation branch, all existing and new CI jobs are green, the production diff is independently approved, and the accepted implementation alone advances the public plugin version to 0.6.0. Planning approval by itself does not implement the milestone.
