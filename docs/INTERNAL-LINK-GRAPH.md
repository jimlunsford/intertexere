# Intertexere Internal Link Graph

## Status

This document defines the proposed architecture contract for milestone 0.2. Implementation begins only after the milestone issue is accepted and a focused implementation branch is created.

## Purpose and boundaries

The graph represents internal links already present in eligible WordPress content. WordPress posts and their saved content remain authoritative. Graph data is derived, disposable, and rebuildable.

Milestone 0.2 is read-only with respect to article content. It does not add AI, embeddings, editor suggestions, link recommendations, link insertion, rewriting, post-content mutation, orphan detection, or ranking.

## Source content

- Analyze the literal saved `post_content` of posts that satisfy the existing eligibility contract.
- Discover anchor elements without executing shortcodes, rendering dynamic blocks, or running front-end filters.
- Prefer WordPress Core's HTML tag processor for anchor traversal, after verifying its WordPress 7.1 behavior during implementation.
- Never write to a post while parsing, refreshing, rebuilding, or clearing graph data.

## URL classification and resolution

Each discovered `href` is classified before any graph row is written.

1. Ignore empty values, fragment-only references, and non-web schemes such as `mailto:`, `tel:`, `javascript:`, and `data:`.
2. Resolve root-relative, protocol-relative, and document-relative references against WordPress and the source post permalink as appropriate.
3. Treat the normalized origins of `home_url()` and `site_url()` as internal. Scheme differences and default ports do not make an otherwise matching WordPress origin external. Other hosts, including subdomains, are external unless a later explicit product decision adds configuration.
4. Remove fragments before target resolution. Preserve meaningful paths and query strings, and do not apply normalization that could merge distinct resources.
5. Resolve query-style WordPress post IDs and WordPress-generated permalinks through verified Core APIs. Use old-slug metadata only when WordPress can resolve it deterministically. Never guess a post identity.
6. Store the WordPress post ID as the durable target identity whenever resolution succeeds. Retain a normalized internal URL with a nullable target ID when an internal URL cannot be resolved.
7. Do not persist external URLs as graph edges. Classification remains independently testable and diagnostics may report aggregate external counts.

The current target permalink is obtained from WordPress by post ID when graph data is read. The observed URL is evidence of what exists in source content, not the canonical identity of a resolved relationship.

## Edge semantics

- A directed edge belongs to one source post and one target identity.
- Multiple occurrences from the same source to the same resolved target are one edge with an `occurrence_count`.
- Different URL forms that resolve to the same target post aggregate into the same edge.
- Duplicate unresolved URLs aggregate only when their conservatively normalized internal URL is identical.
- Self-links are stored and explicitly flagged. They are queryable, but excluded by default from ordinary inbound and outbound degree calculations and from future orphan analysis.
- Outbound edges are stored by source. Inbound relationships are derived by querying the same active edge rows by `target_post_id`; no second inbound copy is maintained.
- A resolved edge whose target is deleted or currently ineligible remains observed evidence, but is not an active inbound relationship. Target existence and eligibility are checked from current WordPress state at read time.

This model preserves enough identity for later orphan detection and ranking without implementing either feature in 0.2.

## Proposed schema

The schema version advances from 1 to 2 through the existing repeatable `dbDelta()` upgrade path. Existing content-index rows remain intact.

### Graph source states

`{$wpdb->prefix}intertexere_link_graph_sources`

| Column | Purpose |
| --- | --- |
| `generation` | Opaque graph generation identifier |
| `source_post_id` | WordPress source post identity |
| `source_content_hash` | Hash of the exact saved content and inputs used for analysis |
| `source_state` | `ready` or `removed`; a removal marker prevents stale rebuild work from resurrecting edges |
| `indexed_at_gmt` | Diagnostic timestamp |

Primary key: `(generation, source_post_id)`.

The source row is required even when a post contains zero internal links. It is also the serialization point for concurrent per-source replacement.

### Graph edges

`{$wpdb->prefix}intertexere_link_edges`

| Column | Purpose |
| --- | --- |
| `generation` | Graph generation identifier |
| `source_post_id` | WordPress source post identity |
| `target_identity_hash` | Stable hash of `post:{ID}` when resolved, otherwise the normalized internal URL |
| `target_post_id` | Durable WordPress target identity, nullable when unresolved |
| `normalized_url` | Normalized observed internal URL used for evidence and unresolved identity |
| `occurrence_count` | Number of matching anchor occurrences in the source |
| `is_self` | Explicit resolved self-link flag |
| `indexed_at_gmt` | Diagnostic timestamp |

Primary key: `(generation, source_post_id, target_identity_hash)`.

Indexes support `(generation, source_post_id)` outbound reads and `(generation, target_post_id, source_post_id)` inbound reads. No foreign keys are required because WordPress posts can change independently and the graph is derived.

### State

Graph-specific options record the active generation, rebuild state, lock, replacement generation, stable traversal cursor, and coalesced rerun request. Graph state is separate from content-index generation state so either subsystem can recover without corrupting the other.

## Incremental refresh

- Reuse the established WordPress content lifecycle hooks and eligibility rules.
- An eligible source save atomically replaces that source's marker and complete edge set.
- A source deletion or transition to ineligible writes a `removed` marker and removes its edges.
- Replacement is atomic even when the new source has zero edges.
- Target deletion, eligibility changes, and permalink changes take effect immediately at read time through target post identity and current eligibility. Source refresh and full rebuild continue to reconcile observed URLs.
- Settings changes that alter eligibility request both the content-index rebuild and the graph rebuild without making either table authoritative for the other.

## Safe rebuild and concurrency

Graph rebuilds use immutable generation identifiers and an atomic active-generation cutover.

- Readers use only the active graph generation.
- A replacement generation is populated with stable ascending post-ID keyset traversal, never mutable offset pagination.
- The existing active generation remains available until the replacement completes.
- Incremental source changes write to the active generation and any running replacement generation.
- Per-source replacement uses a transaction and the source-state row as a serialization point. Rebuild work claims a source only if no newer incremental marker exists. An incremental write that races with rebuild work therefore wins, including a `removed` marker and a zero-edge result.
- Rebuild state publication, incremental generation selection, and cutover are synchronized so there is no interval in which a content event can miss the generation that may become active.
- A second rebuild request does not overwrite running state. It sets one coalesced rerun marker. After the current attempt reaches a valid cutover or failure boundary, one fresh rebuild is scheduled from current WordPress state.
- A failed or interrupted rebuild leaves the prior active generation untouched and recoverable. Incomplete generations can be discarded only after they are no longer candidates for activation.
- Cleanup is generation-scoped. It never truncates a table shared with the active generation.

The implementation must exercise real mutations between rebuild batches, using more eligible sources than the configured batch size.

## Read model

The graph service will provide narrow internal operations for:

- active outbound relationships for a source post
- active inbound relationships for a target post
- unresolved internal URLs observed in a source
- duplicate occurrence and self-link metadata
- graph generation and rebuild diagnostics

These operations are data access only. Milestone 0.2 adds no editor surface, recommendations, scores, orphan reports, or content-changing ability.

## Recovery and deactivation

- Clearing graph-derived rows cannot delete or modify WordPress posts.
- A full rebuild can reproduce the graph from current eligible saved content.
- Deactivation stops Intertexere processing without changing existing article links or normal WordPress rendering.
- The graph does not require proprietary markup, redirect layers, or runtime front-end replacement.

## Known resolution boundary

A link keeps durable identity after resolution because the edge stores the target post ID. If source content still contains an obsolete URL during a later clean rebuild, resolution depends on aliases WordPress itself can determine, such as retained old-slug metadata. When WordPress cannot deterministically map that URL to a post, Intertexere must preserve it as an unresolved internal URL rather than infer an identity. This is deliberate correctness behavior, not automatic broken-link repair.

