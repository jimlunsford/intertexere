# Site Link Audit

## Status

This document defines the planning architecture for milestone 0.6. Milestones 0.1 through 0.5 are complete. Milestone 0.6 is planned and is not implemented. Implementation must not begin until this planning work receives independent review.

## Goal

0.6 adds a deterministic, read-only WordPress administration view of structural internal-link conditions in saved eligible content. It reuses the active content-index and link-graph generations, verifies findings against current WordPress state, and never changes posts, links, redirects, editor drafts, or publishing state.

The first release is deliberately small. It reports content-body orphans, thin inbound coverage, repeated source-to-target links, self-links, unresolved internal URLs, and resolved targets that are currently unavailable or ineligible. It is not a crawler, SEO grade, repair engine, or recommendation-ranking change.

## Sources of truth and audit entities

The layers remain distinct:

1. Saved WordPress posts, post identity, status, password state, post type, eligibility, and current permalink are authoritative.
2. Active generation-scoped index and graph rows are rebuildable evidence derived from saved WordPress content.
3. An audit page is a request-scoped calculation bound to exact active index and graph generation IDs.
4. Filters, cursors, counts, and displayed status are user-interface state, not durable content or audit authority.

The audit operates on a bounded combination of:

- eligible published source and target posts represented in the active index;
- `current` source-state rows in the active graph generation;
- resolved graph edges identified by durable target post ID;
- unresolved internal URL identities retained by the graph;
- current WordPress post objects loaded and checked in bounded sets before findings are shown.

An index or graph row never overrides current WordPress state. Audit output is disposable and recomputable.

## Initial finding categories

### Content-body orphan

An eligible, published, public, non-password-protected post is a **content-body orphan** when the active graph has zero distinct resolved, non-self inbound edges from other sources that are themselves currently eligible, published, public, and non-password-protected.

Self-links do not count. Links from deleted, trashed, private, password-protected, excluded, unsupported, or otherwise ineligible sources do not count. Unresolved URLs do not count until the established resolver can assign a durable target post ID. Multiple occurrences from one source count as one inbound source.

This classification describes literal links in saved eligible post content only. Intertexere does not inspect navigation menus, templates, template parts, widgets, dynamic rendering, shortcode output, theme chrome, external sites, or browser-rendered pages. The interface must say “No inbound links found in eligible saved content,” not claim that the post has no links anywhere on the rendered site.

### Thin inbound coverage

An eligible post has **thin inbound coverage** when it has exactly one distinct resolved, non-self inbound source under the same current-source rules used for content-body orphans.

Zero inbound sources belongs only to the orphan category. Two or more inbound sources is not thin under 0.6. Outbound count, publication age, word count, AI judgment, and supposed SEO quotas do not alter this rule. The threshold is fixed for the initial release because it is an explainable structural partition, not a claim that every article needs an arbitrary number of links. No new setting is introduced.

0.6 does not identify “low outbound coverage” as a defect. A post may correctly contain no useful outbound link. Graph imbalance, over-linking, cornerstone opportunities, and old-to-new recommendation opportunities remain future analysis unless real use proves a deterministic rule.

### Repeated target links

A resolved active edge with `occurrence_count > 1` is a **repeated target-link review opportunity**: one source contains multiple literal links that resolve to the same target post ID. It is not automatically an error. URL variants that resolve to one durable target identity remain one edge, consistent with 0.2.

For an unresolved identity, an occurrence count greater than one may be reported as a repeated unresolved URL. 0.6 does not claim that repeated anchor text is duplicated because schema 2 does not store per-occurrence anchor text.

### Self-link

A resolved edge whose source and target post IDs match is reported separately as a **self-link review opportunity**. Self-links are excluded from inbound and outbound structural counts and cannot prevent orphan or thin-inbound classification. They are retained because they may be intentional and are not automatically removed or labeled broken.

### Unresolved and unavailable internal links

The audit uses separate, accurate categories:

| Observed condition | 0.6 classification |
| --- | --- |
| Internal normalized URL retained with no durable target post ID | Unresolved internal URL, not automatically broken |
| Resolved target post no longer exists | Unavailable target: deleted or missing |
| Resolved target is trashed | Unavailable target: trashed |
| Resolved target is private or otherwise unpublished | Unavailable target: not published |
| Resolved target is password protected | Unavailable target: password protected |
| Resolved target post type is unsupported, excluded, or no longer configured | Ineligible target |
| Current permalink, supported query form, or retained old slug resolves to a current eligible post | Valid resolved link |
| Fragment-only link | Not an internal graph edge and not audited |
| External host or non-web scheme | Not an internal graph edge and not audited |
| Malformed href rejected by the graph parser | Not represented, so 0.6 does not call it broken |

The resolver never guesses. A deleted target can be called deleted only when durable post-ID evidence exists. An unresolved URL stays unresolved even if a human might infer its destination.

### Canonical and noncanonical URL forms

A valid internal URL is not broken merely because it differs from the current canonical permalink. Relative, query-style, alternate host/scheme forms allowed by the resolver, and retained old slugs may still resolve correctly.

Schema 2 aggregates URL variants into a source-to-target edge and retains only one representative normalized URL. Therefore 0.6 may show a **noncanonical URL review opportunity** only when the stored representative URL itself deterministically resolves to the current target and differs from the current canonical permalink after the established normalization rules. It must not claim that every occurrence is noncanonical or that replacement is required. If mixed URL variants aggregate into one edge, per-occurrence normalization reporting is unavailable. Adding exact occurrence storage or reparsing whole posts for cleanup is deferred and does not justify a schema change in the first audit release.

## Generation and cutover authority

Every audit request captures both `Schema::GENERATION_OPTION` and `Schema::GRAPH_GENERATION_OPTION` before querying. Every index and graph query includes those exact generation IDs. A cursor binds the category, filters, last stable key, index generation, graph generation, and audit contract version.

Immediately before returning a page, the service rereads both active generation options. If either differs, it discards the page and returns a stale result that directs the user to restart. It never combines a replacement generation with an active generation and never changes generation options.

An in-progress rebuild does not by itself block auditing because readers may continue using the complete active generation. A cutover during the request fails stale. A later page carrying an older generation also fails stale.

Incremental saves can update active-generation rows without changing the generation ID. Each page is therefore explicitly request-scoped, not a persistent whole-site snapshot. Findings are selected in bounded SQL statements, then their relevant current WordPress objects and source-state evidence are bulk revalidated immediately before output. If a relevant source or target changes during calculation, that page fails stale or omits a no-longer-valid finding according to the same deterministic rule. The UI must not describe multiple pages as one immutable completed audit run.

## Current WordPress revalidation

Before displaying an actionable finding, the service bulk-loads the bounded source and target IDs and verifies:

- post existence and exact ID;
- current post type;
- current publication status and public visibility;
- empty password;
- configured post-type and status eligibility;
- the `intertexere_is_post_eligible` exclusion filter;
- current canonical permalink where a destination or View action is shown;
- source-state row remains `current` in the captured graph generation.

Relevant source and target authority snapshots are compared again immediately before response construction. Deletion, type change, status change, password change, exclusion change, permalink change where displayed, or generation cutover makes the page stale or removes the finding. Bulk retrieval is mandatory; per-row `get_post()` or resolver calls must not create an N+1 path.

## Execution and pagination

0.6 uses on-demand, server-rendered WordPress admin requests. It does not create a background audit run, completion record, transient, table, cron task, cancellation token, or persistent snapshot.

- The overview calculates bounded category counts from the exact active generations and current eligibility.
- A category page uses stable keyset pagination with a default page size of 20 and a hard maximum of 50.
- Stable keys are post ID for post categories and `(source_post_id, target_identity_hash)` for edge categories.
- Filtering is limited to a strict category, eligible post type, and bounded search term where a query plan remains indexed and testable.
- A second request is simply another read. It supersedes nothing and writes no state.
- Failure leaves WordPress and all Intertexere persistence unchanged and offers a normal retry or restart.

Because there is no durable audit run, start, progress, cancellation, and deactivation cleanup are unnecessary. “Refresh audit” means issue a new read against current active generations. It does not rebuild the index or graph.

## Persistence and schema

No audit table, option, transient, post metadata, taxonomy, or saved result is introduced. Existing active index and graph tables already provide the necessary bounded evidence. Audit results are calculated on demand and are not a second source of truth.

Schema remains version 2. Plugin version remains 0.5.0 throughout planning. A future persisted audit snapshot would require a separately reviewed performance justification, migration, cleanup, supersession, and stale-result contract.

## WordPress admin interface

Add a dedicated **Intertexere Site Link Audit** screen under Tools, adjacent to the existing Intertexere diagnostics page. Use native admin headings, notices, filters, tables, pagination, and View/Edit row actions. The initial tabs are:

- Content-body orphans
- Thin inbound coverage
- Unresolved and unavailable
- Repeated target links
- Self-links
- Noncanonical review opportunities, only where current schema evidence is sufficient

Each row shows the source post, target when known, finding type, concise deterministic reason, relevant count or observed URL, current permalink where applicable, and properly labeled View and Edit actions. Empty, unavailable, stale, and error states are explicit. Severity is not expressed by color alone, and the screen has no score, urgency theater, bulk repair, or content-mutation control.

The first release should be server-rendered. JavaScript is unnecessary unless implementation proves a specific accessibility or interaction need. If JavaScript is added, it may enhance filters or announcements but cannot become required for authority or introduce mutation.

## Permissions and transport

Viewing any site-wide audit data requires the existing `manage_intertexere` capability. That capability is granted to administrators on activation and is deliberately stronger than the ability to edit one post. View/Edit destination links remain subject to WordPress's own post capabilities and are omitted or disabled when the viewer lacks them.

Read-only audit pages use native authenticated wp-admin GET requests with strict allowlisted query parameters, escaped output, and prepared SQL. No custom REST route is required for the planned server-rendered release. Any later POST action must use `admin-post.php`, `manage_intertexere`, a nonce, strict input, and safe redirect handling. Any later REST design must separately specify cookie authentication, REST nonce, capability, strict schema, unknown-field rejection, payload bounds, and a pre-JSON raw-body guard where applicable.

## Privacy and AI

The audit runs locally against WordPress and Intertexere's derived tables. No audit data, graph, content, URL, title, or finding is sent to an AI provider or another external service. 0.6 makes zero AI requests, adds no provider requirement, and does not alter the 0.3 deterministic score or the 0.4 prompt.

## Performance contract

Implementation must prove the following on WordPress 7.1 for PHP 7.4, 8.1, and 8.3:

- representative fixture: at least 125 eligible posts and 500 graph edges, including known cases in every category;
- larger pagination fixture: at least 1,000 eligible posts and 4,000 graph edges;
- overview: no more than 12 database queries and less than 1.0 second;
- one result page of 20, including current WordPress revalidation: no more than 12 database queries and less than 750 ms;
- maximum page of 50: no more than 14 database queries, less than 1.0 second, and less than 32 MiB incremental peak memory;
- no per-finding post, permalink, eligibility, or URL-resolution query pattern;
- keyset page two scans only rows needed by the indexed cursor plan and does not use an unbounded offset;
- all budgets include generation capture and final cutover checks.

Tests must record query count, elapsed time, peak memory delta, rows returned or examined where the database makes that observable, and cursor behavior. Performance failure must lead to a bounded query-plan correction, not a persistent audit cache or silent schema change.

## Concurrency and failure behavior

The test suite must exercise:

- audit while index or graph replacement rebuild is running, using only active generations;
- index and graph cutover during an audit request, each failing stale;
- a post save during calculation, with no stale finding returned as current;
- source or target deletion, status, password, type, permalink, and eligibility changes during calculation;
- two concurrent reads without shared mutable audit state;
- database or service failure with safe error output;
- plugin deactivation, which requires no audit cleanup because no audit state exists.

Every failure performs zero post, editor, index, graph, setting, transient, schema, redirect, AI, save, autosave, revision, or publication mutation.

## Relationship to editor suggestions and repairs

0.6 does not change editor candidate retrieval, scoring, AI ranking, or insertion authority. An orphan or thin-inbound finding does not automatically become a suggestion boost. Possible future links from audit rows back to a filtered editor workflow require separate planning.

The initial audit is read-only. View and Edit navigate to ordinary WordPress surfaces but do not pre-authorize or execute a repair. There is no automatic or bulk replacement, deletion, redirect, anchor rewrite, insertion, save, or publish operation.

## Deactivation and exclusions

Disabling Intertexere removes the audit UI and processing. It does not alter posts or existing links. Existing index and graph data remains derived under the established lifecycle.

0.6 excludes external crawling, rendered-front-end crawling, navigation or template analysis, AI audit explanations, embeddings, vector storage, external SEO APIs, automatic repairs, redirects, bulk mutation, multisite, WooCommerce-specific rules, cloud synchronization, and automatic publishing.

## Implementation gate

Implementation will use the proposed `feature/0.6-site-link-audit` branch only after this planning work receives independent review. It must pass [0.6 Acceptance Criteria](ACCEPTANCE-0.6.md), the complete 0.1 through 0.5 regression suite, full production-diff review, and the WordPress 7.1/PHP matrix before version 0.6.0 may be assigned.
