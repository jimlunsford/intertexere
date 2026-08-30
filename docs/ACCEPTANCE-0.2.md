# Intertexere 0.2 Acceptance Criteria

## Milestone

Internal Link Graph

## Goal

Derive an accurate, incrementally maintained, safely rebuildable representation of internal links already present in eligible WordPress content without modifying article content.

## Required scope

- parse literal saved content for existing anchor links
- distinguish internal from external links
- support absolute, root-relative, protocol-relative, and document-relative internal URLs
- resolve internal URLs to WordPress post IDs where Core can do so deterministically
- retain unresolved internal URL evidence without guessing a post identity
- store outbound relationships by source and derive inbound relationships by target
- define duplicate and self-link behavior
- update source edges after edits
- remove source edges after deletion or loss of eligibility
- invalidate relationships to deleted or ineligible targets
- handle permalink and slug changes through durable target post identity
- perform generation-based, concurrency-safe full graph rebuilds
- expose basic graph and rebuild diagnostics
- migrate the derived schema repeatably from 0.1

## Acceptance criteria

### Source of truth and content safety

- WordPress saved post content and post identity remain authoritative.
- Parsing, incremental refresh, reset, and rebuild do not change post content bytes.
- Graph tables contain only derived, rebuildable data.
- No shortcode execution, dynamic rendering, or front-end filter is required to discover literal anchor markup.

### URL classification and resolution

- Internal absolute URLs and root-relative URLs are discovered.
- Protocol-relative and document-relative links are resolved deliberately.
- Current WordPress permalinks and supported query-style post URLs resolve to post IDs.
- Fragments do not create separate target identities.
- Meaningful query strings are not discarded in a way that merges distinct unresolved resources.
- External hosts and non-web schemes are not stored as internal graph edges.
- An internal URL that cannot be mapped deterministically is retained as unresolved and is never assigned a guessed post ID.

### Graph semantics

- Each source and resolved target pair has one edge with a deliberate occurrence count.
- URL variants from one source that resolve to the same post aggregate to one edge.
- Self-links are retained and flagged, and are excluded by default from normal degree calculations.
- Outbound relationships can be retrieved by source post.
- Inbound relationships are derived from the same edge records by target post ID.
- A target title or URL shown by graph consumers comes from current WordPress state, not a stale stored canonical copy.

### Incremental correctness

- Publishing an eligible source creates its current edge set.
- Updating a source atomically replaces its old edge set, including replacement with zero links.
- Deleting or making a source ineligible removes its active outbound relationships.
- Making a source eligible again makes its current relationships indexable.
- Deleting or making a target ineligible prevents its observed edges from counting as active inbound relationships.
- A target permalink or slug change does not invalidate already resolved post-ID relationships.

### Rebuild and concurrency safety

- A deliberate full rebuild creates a replacement generation while readers continue using the prior active generation.
- Source traversal uses stable post-ID keyset batching and exercises more sources than one batch.
- Deleting or unpublishing an already traversed source cannot cause a later source to be skipped.
- Publishing or updating link content during a rebuild is reflected in whichever generation becomes active.
- Deleting or making a source ineligible during a rebuild prevents stale edges from surviving cutover.
- Incremental replacement with zero edges prevents stale rebuild work from resurrecting old edges.
- A target deletion or eligibility transition during rebuild cannot make a stale relationship active after cutover.
- A second rebuild request is coalesced safely and incremental writes continue reaching every generation that may become active.
- An interrupted or failed rebuild preserves the previous valid active generation.
- Repeating a rebuild produces a current-state graph without uncontrolled duplicates.
- Clearing graph-derived data and rebuilding recovers the expected graph without changing WordPress content.

### Compatibility, security, and performance

- WordPress 7.1+ remains the minimum supported baseline and implementation-only Core APIs are verified against current official documentation and runtime behavior.
- Administrative graph rebuild and reset actions require the existing appropriate capability and request protection.
- Inputs are validated, output is escaped, and SQL uses prepared WordPress-safe patterns.
- Expensive graph work is batchable and does not parse the entire archive during ordinary page rendering or editor requests.
- WordPress editing, publishing, front-end rendering, and existing links work normally when Intertexere is disabled.

## Automated test plan

The WordPress integration suite must add focused coverage for:

1. repeatable schema upgrade from version 1 to version 2 while preserving 0.1 index data and WordPress posts
2. anchor parsing without content mutation or shortcode execution
3. internal and external classification across absolute, root-relative, protocol-relative, document-relative, fragment, query, and non-web URL forms
4. current permalink and query-style post-ID resolution, plus deliberately unresolved internal URLs
5. duplicate aggregation across repeated and alternate URLs that resolve to one target
6. self-link storage, flagging, and default degree exclusion
7. outbound and derived inbound reads from one authoritative edge set
8. source edit replacement, including an edit from links to zero links
9. source deletion, unpublish, re-eligibility, and configured eligibility changes
10. target deletion or ineligibility invalidation without a source edit
11. target slug and permalink changes using durable post-ID identity, including verified old-slug behavior and no-guess fallback
12. repeatable rebuild, derived-data reset and recovery, interrupted rebuild, and previous-generation preservation
13. a dataset larger than the rebuild batch size proving stable keyset traversal when an already traversed source is deleted or unpublished
14. real in-progress content mutations proving that added, changed, removed, and zero-edge source states survive cutover correctly
15. a second in-progress rebuild request proving state coalescing and continued incremental dual writes
16. capability and request-protection failures for privileged graph actions
17. post-content byte equality before and after parsing, rebuild, reset, and deactivation
18. the complete existing 0.1 acceptance and regression suite

The continuous-integration matrix remains WordPress 7.1 on PHP 7.4, 8.1, and 8.3.

## Explicitly out of scope for 0.2

- AI provider calls or AI-generated analysis
- embeddings or semantic indexes
- editor sidebar suggestions
- automatic or manual link recommendations
- link insertion
- anchor rewriting
- any post-content mutation
- orphan or under-linked content detection
- suggestion ranking or scoring
- broken-link repair or redirects
- site-wide audit UI

## Documentation decisions

- Add `docs/INTERNAL-LINK-GRAPH.md` as the detailed graph, URL-resolution, schema, and concurrency contract.
- Add this acceptance document for milestone 0.2.
- Update `docs/ARCHITECTURE.md` to make durable post-ID target identity, outbound-authoritative/inbound-derived storage, and safe generation cutover explicit.
- Update `docs/ROADMAP.md` to reserve orphan detection for the later site-audit milestone rather than implementing it in 0.2.
- Update `README.md` to link the new design and acceptance documents and reflect that 0.1 is complete and 0.2 is planned.
- No change to `docs/PRODUCT-RULES.md` or `docs/DEVELOPMENT.md` is required. This design implements their existing source-of-truth, read-only analysis, rebuild, security, and testing rules.

## Completion standard

0.2 is complete when the active graph accurately represents current literal internal links from eligible WordPress sources under incremental edits and rebuild races, remains fully derived from WordPress, and passes the WordPress 7.1/PHP matrix without adding any later-milestone behavior.

