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
- `ready` source-state rows in the captured active graph generation;
- resolved graph edges identified by durable target post ID;
- unresolved internal URL identities retained by the graph;
- current WordPress post objects loaded and checked in bounded sets before findings are shown.

An index or graph row never overrides current WordPress state. Audit output is disposable and recomputable.

### Eligibility authority and freshness boundary

Schema 2 has exactly two stored graph source-state values: `ready` and `removed`. A `ready` row means the source passed `Eligibility::is_eligible()` when that source was written into the generation and its complete edge set was materialized. A `removed` row is an explicit noncontributing marker written by the incremental refresh or removal lifecycle, and it contributes no edges. During a full graph rebuild, a configured source candidate that fails `Eligibility::is_eligible()` is skipped and may simply be absent from the replacement generation; the rebuild does not require a `removed` marker for every excluded source. An absent graph source row contributes nothing. There is no `current` source-state value.

The active content index follows the same membership principle. A qualifying source has a live, non-tombstone row in the captured active index generation. A source excluded during a full index rebuild may be absent, and an incrementally ineligible source is removed from normal active rows. Index tombstones are replacement-generation concurrency markers, not an alternate durable eligibility state.

`Eligibility::is_eligible()` first checks current configured post type, published status, empty password, and revision or autosave exclusion, then applies `intertexere_is_post_eligible`. That PHP filter may depend on arbitrary runtime state and cannot generally execute inside SQL. The audit therefore does not claim to re-run that filter over every inbound source on every request.

For structural inbound counts, the filter's authoritative decision is its materialized active-generation result. A source may contribute only when all of the following are true:

1. the edge belongs to the captured active graph generation;
2. the matching graph source row in that generation has `source_state = 'ready'`;
3. the source has a live, non-tombstone row in the captured active index generation;
4. a SQL join to the current WordPress posts table still proves the source exists, has a configured eligible post type and published status, has no password, and is not a revision or autosave;
5. the edge is resolved to the target post ID and is not a self-link.

The active index and graph are refreshed after ordinary post saves and deletions. A configured eligibility change schedules both full rebuilds. A third-party change to `intertexere_is_post_eligible` behavior that occurs without a post lifecycle event or configured-settings change cannot automatically change an already materialized generation. The owner must rebuild the index and graph before the audit can reflect that new filter behavior. The audit screen must disclose that its structural counts use materialized eligibility from the named active generations and provide a link to the existing diagnostics and rebuild controls. It must never label these counts as an instantaneous evaluation of arbitrary runtime-filter state.

This freshness boundary is deliberate. Re-running an arbitrary PHP filter over hundreds or thousands of inbound source IDs would conflict with bounded queries, latency, and memory without new persisted eligibility state. No such persistence or schema change is authorized.

## Initial finding categories

### Content-body orphan

An eligible, published, public, non-password-protected post is a **content-body orphan** when it has zero distinct qualifying structural inbound sources under the active-generation eligibility rule above.

Self-links do not count. A `removed` graph source, an absent graph source row, and a source absent from the captured active index never count. Sources currently deleted, trashed, private, password-protected, unsupported, or otherwise invalid under the SQL-verifiable WordPress checks do not count. Unresolved URLs do not count until the established resolver can assign a durable target post ID. Multiple occurrences from one source count as one inbound source.

This classification describes literal links in saved eligible post content only. Intertexere does not inspect navigation menus, templates, template parts, widgets, dynamic rendering, shortcode output, theme chrome, external sites, or browser-rendered pages. The interface must say “No inbound links found in eligible saved content,” not claim that the post has no links anywhere on the rendered site.

### Thin inbound coverage

An eligible post has **thin inbound coverage** when it has exactly one distinct qualifying structural inbound source under the same active-generation rule used for content-body orphans.

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

Generation identity alone is not a complete request snapshot. Incremental saves can replace an active-generation source row and its full edge set without changing either active generation option. A source can remain `ready` while `source_content_hash`, edge existence, target identity, normalized URL, occurrence count, or self-link status changes. Each page is therefore explicitly request-scoped, not a persistent whole-site snapshot.

Initial finding selection is provisional. Immediately before response construction, every page performs a final bounded, set-based reread of the exact derived evidence needed by the rows it is about to display. A mismatch makes the whole page stale; implementation must not return a mixture of initially selected and finally revalidated rows. This final derived-data check is separate from bounded current WordPress-object revalidation, and both checks precede the final active-generation option comparison. The response is current evidence, not a durable lock.

For content-body orphan and thin-inbound pages, the final reread reruns the same saturated zero, one, or two-plus classifier for all bounded displayed target IDs in one prepared SQL statement. It uses the captured graph and index generations, `ready` source rows, qualifying active-index membership, current SQL-verifiable WordPress source fields, and distinct non-self resolved inbound sources. The service compares each final saturated class, and any evidence source IDs that will be displayed, with the class that selected the row. A difference in either direction, including zero to one, zero to two-plus, one to zero, one to two-plus, two-plus to one, or two-plus to zero, makes the page stale. The query retains no more than two evidence IDs per target and never loads the complete inbound list.

For edge categories, one final prepared SQL statement rereads every bounded displayed edge by `(generation, source_post_id, target_identity_hash)` and joins the required source row in the same captured graph generation, qualifying active-index membership in the captured index generation, and current SQL-verifiable WordPress source fields. It compares all fields material to the category, including source post ID, target identity hash, target post ID, normalized URL, occurrence count, self-link flag, source state, and `source_content_hash` where available. A missing edge, replacement, changed classification, changed count or URL evidence, changed self-link status, or source that no longer joins as `ready` makes the page stale. No per-edge query is allowed.

Request-scoped keyset pagination does not promise that a finding created concurrently outside the selected page appears in the response. It does promise that every finding actually returned still satisfies its category at the final derived-evidence boundary. A later refresh can reveal newly created findings. The UI must not describe multiple pages as one immutable completed audit run.

## Current WordPress revalidation

Before displaying an actionable finding, the service bulk-loads only the posts shown on that bounded page and verifies:

- post existence and exact ID;
- current post type;
- current publication status and public visibility;
- empty password;
- configured post-type and status eligibility;
- `Eligibility::is_eligible()` for each displayed source or target, including `intertexere_is_post_eligible`;
- current canonical permalink where a destination or View action is shown;
- any displayed source's row remains `ready` in the captured graph generation.

Relevant displayed source and target authority snapshots are compared again immediately before response construction. Deletion, type change, status change, password change, permalink change where displayed, source-state replacement, derived edge replacement, or generation cutover makes the page stale. If a fresh runtime-filter result for a displayed structurally eligible post disagrees with its materialized index or graph membership, the page is stale and directs the administrator to rebuild; it does not silently apply a different eligibility definition than the overview. Bulk retrieval is mandatory; per-row `get_post()` or resolver calls must not create an N+1 path.

The audit does not bulk-load or call `Eligibility::is_eligible()` for every source that contributes only to an orphan or thin-inbound count, or for every target included in an overview aggregate. Those posts are governed by materialized source state or active-index membership plus SQL-verifiable current WordPress fields. Full runtime-filter evaluation is limited to the bounded posts displayed on a page and acts as a stale-generation detector, not a second classification rule.

## Bounded zero, one, or two-plus classification

Orphan and thin-inbound classification needs only a saturated cardinality: zero, one, or two-plus. It never needs a complete PHP list of inbound sources.

For each bounded target page, one set-based prepared SQL operation must classify all candidate target IDs. It joins edges to graph source rows, active-index rows, and current WordPress source rows under the exact qualifying-source rule. The query must use `target_lookup (generation, target_post_id, source_post_id)` and `generation_state (generation, source_state)`, and must stop retaining evidence after the second distinct qualifying source for a target. Acceptable implementations include two indexed existence probes per target inside one set-based statement or another query plan that proves the same saturated result. It must not issue one query per target.

No contributing source IDs are loaded into PHP for count classification beyond, at most, the two bounded evidence IDs per target needed by tests or an accessible reason. At page size 20 that is at most 40 IDs; at the hard page size 50 it is at most 100 IDs. There is no runtime-filter scan cursor and no bound-exhausted or inconclusive state because arbitrary current filter execution is not part of request-time count authority. The database result is exact for the captured materialized generations plus current SQL-verifiable WordPress state.

A target with 500 historical inbound source IDs remains bounded. After a full rebuild materializes runtime-filter exclusions, excluded candidates have no qualifying `ready` source row or edge contribution in the captured graph generation and no qualifying active-index row in the captured index generation. They may be absent; a `removed` graph marker is only one valid explicit noncontributing state produced by incremental lifecycle behavior. The set-based query seeks qualifying `ready` edges and saturates at two. If 499 are excluded and one remains, the result is thin. If all 500 are excluded, the result is orphan. If the filter changed after materialization and no rebuild or source refresh occurred, the audit continues to report the prior active-generation result with the required freshness disclosure until rebuild.

## Execution and pagination

0.6 uses on-demand, server-rendered WordPress admin requests. It does not create a background audit run, completion record, transient, table, cron task, cancellation token, or persistent snapshot.

- The overview shows exact active-generation category counts using the same qualifying-source SQL semantics as category pages. One prepared, set-based aggregate statement returns every overview category count from one database statement snapshot, so an incremental same-generation write cannot be observed by only some categories. It includes current SQL-verifiable WordPress fields but does not claim a fresh execution of arbitrary runtime eligibility filters.
- A category page uses stable keyset pagination with a default page size of 20 and a hard maximum of 50.
- Stable keys are post ID for post categories and `(source_post_id, target_identity_hash)` for edge categories.
- Filtering is limited to a strict category, eligible post type, and bounded search term where a query plan remains indexed and testable.
- A second request is simply another read. It supersedes nothing and writes no state.
- Failure leaves WordPress and all Intertexere persistence unchanged and offers a normal retry or restart.

Because there is no durable audit run, start, progress, cancellation, and deactivation cleanup are unnecessary. “Refresh audit” means issue a new read against current active generations. It does not rebuild the index or graph.

Overview counts must be labeled “Active-generation findings” and identify or link to the captured generation diagnostics. The overview aggregate must execute as one coherent read statement under the database engine's statement-level consistency or read-lock semantics; Intertexere adds no write lock or persistent snapshot. The service immediately compares both active generation options after that statement. A statement failure, unsupported coherence guarantee, or generation cutover returns stale or unavailable instead of counts. Same-generation writes committed before the statement may be included and writes not visible to that statement are excluded from every category consistently. Counts and category pages must call the same query service and cannot use different eligibility definitions.

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
- initial orphan/thin classification for one page uses one set-based database operation, and final saturated revalidation adds exactly one set-based operation for all displayed target IDs;
- initial edge selection is followed by exactly one set-based final reread for all displayed edge keys;
- neither final reread returns more than two contributing evidence IDs per target, issues a per-target or per-edge query, or materializes a complete inbound-source list;
- keyset page two scans only rows needed by the indexed cursor plan and does not use an unbounded offset;
- overview category counts come from one coherent aggregate read statement, not a sequence of independently timed count queries;
- all budgets include generation capture, final derived-evidence revalidation, current WordPress-object revalidation, and final cutover checks.

Tests must record query count, elapsed time, peak memory delta, rows returned or examined where the database makes that observable, and cursor behavior. A dedicated high-degree fixture has at least 2,000 inbound edges to one target. It proves count saturation at two in both initial and final classification, bounded PHP evidence, and no request-time `Eligibility::is_eligible()` loop across contributing sources. The same fixture includes a race hook after initial classification where an incremental save replaces a `ready` source with another `ready` row and changes its edges inside the active generations; the final saturated reread must catch the resulting class change. Separate rebuild fixtures apply `intertexere_is_post_eligible` exclusions so 2,000 becomes two-plus, exactly one, and zero under materialized generation semantics. Performance failure must lead to a bounded query-plan correction, not a persistent audit cache or silent schema change.

## Concurrency and failure behavior

The test suite must exercise:

- audit while index or graph replacement rebuild is running, using only active generations;
- index and graph cutover during an audit request, each failing stale;
- orphan classification changing from zero to one or two-plus through a same-generation incremental source save;
- thin classification changing from one to zero or two-plus through same-generation edge removal or addition;
- two-plus classification changing to one or zero through same-generation edge removal;
- `ready` to `ready` source replacement with a changed `source_content_hash` and edge set, proving source-state checks alone are insufficient;
- source or target deletion, status, password, type, permalink, and eligibility changes during calculation;
- a `ready` source row being atomically replaced by `removed` during calculation;
- repeated-target occurrence count changing from two to one during calculation;
- an unresolved edge disappearing or becoming resolved during calculation;
- a self-link disappearing during calculation;
- representative noncanonical URL evidence changing during calculation;
- a same-generation incremental write between overview category calculations, proving the one-statement overview cannot expose mixed-state exact counts;
- runtime-filter behavior changing after materialization, with the prior active-generation result retained and clearly disclosed until rebuild;
- two concurrent reads without shared mutable audit state;
- database or service failure with safe error output;
- plugin deactivation, which requires no audit cleanup because no audit state exists.

Race hooks must sit at the real boundary between initial selection and final derived-evidence revalidation and must provide test observability only, never an alternate production decision path. Every failure performs zero post, editor, index, graph, setting, transient, schema, redirect, AI, save, autosave, revision, or publication mutation.

## Relationship to editor suggestions and repairs

0.6 does not change editor candidate retrieval, scoring, AI ranking, or insertion authority. An orphan or thin-inbound finding does not automatically become a suggestion boost. Possible future links from audit rows back to a filtered editor workflow require separate planning.

The initial audit is read-only. View and Edit navigate to ordinary WordPress surfaces but do not pre-authorize or execute a repair. There is no automatic or bulk replacement, deletion, redirect, anchor rewrite, insertion, save, or publish operation.

## Deactivation and exclusions

Disabling Intertexere removes the audit UI and processing. It does not alter posts or existing links. Existing index and graph data remains derived under the established lifecycle.

0.6 excludes external crawling, rendered-front-end crawling, navigation or template analysis, AI audit explanations, embeddings, vector storage, external SEO APIs, automatic repairs, redirects, bulk mutation, multisite, WooCommerce-specific rules, cloud synchronization, and automatic publishing.

## Implementation gate

Implementation will use the proposed `feature/0.6-site-link-audit` branch only after this planning work receives independent review. It must pass [0.6 Acceptance Criteria](ACCEPTANCE-0.6.md), the complete 0.1 through 0.5 regression suite, full production-diff review, and the WordPress 7.1/PHP matrix before version 0.6.0 may be assigned.
