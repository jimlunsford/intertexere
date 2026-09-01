# Intertexere Architecture

## Status

This document defines the initial architecture for Intertexere.

The repository is the source of truth for the plugin implementation. WordPress remains the source of truth for site content. Derived indexes and link-graph data may be rebuilt from WordPress at any time.

Milestones 0.1 and 0.2 are complete. Milestone 0.3 is planned and not implemented.

## Product Identity

Intertexere is a contextual internal-linking assistant for WordPress.

Its purpose is to help an editor discover useful internal-link opportunities while writing, understand the existing internal-link graph, and insert approved links without surrendering editorial control.

Intertexere is not an automatic link-insertion engine. AI output is advisory unless a user explicitly approves a content change.

## Platform Baseline

The initial development baseline is WordPress 7.1 or newer.

Intertexere should use WordPress-native APIs where they are appropriate, including:

- the Block Editor and WordPress data APIs for editor integration;
- the Abilities API for discrete, discoverable operations when its transport contract fits the operation;
- custom WordPress REST routes when a native operation needs a different safe transport contract;
- the WordPress AI Client for provider-agnostic AI requests beginning no earlier than 0.4;
- the Connectors API for supported external-service credentials and provider configuration beginning no earlier than 0.4;
- standard WordPress capability, nonce, sanitization, escaping, database, scheduling, and plugin APIs.

Intertexere must not hard-code OpenAI as the only AI provider.

## Core Data Boundaries

### WordPress content is authoritative

Posts, pages, custom post types, titles, URLs, taxonomies, post status, and article content remain owned by WordPress.

Intertexere must not create a second canonical copy of article content. While an editor is open, the submitted unsaved Block Editor state is authoritative for read-only suggestion analysis and must not be replaced by the older saved post.

### Derived data is rebuildable

Intertexere may maintain derived data for performance and analysis, including:

- normalized searchable text;
- headings;
- summaries and topic metadata in later reviewed milestones;
- candidate-search metadata if a later reviewed performance need justifies it;
- outbound internal-link edges;
- inbound relationships derived from those edges;
- orphan or under-linked status in a later milestone;
- intentional user preferences in a later milestone.

Derived data must be considered disposable and rebuildable from WordPress plus intentional Intertexere preferences where applicable. Milestone 0.3 adds no persistent suggestion, dismissal, draft, or retrieval schema.

### Content index

The 0.1 content index is the active local representation used for deterministic retrieval and scoring. It remains generation scoped, derived from eligible WordPress content, incrementally maintained, and safely rebuildable.

### Link graph

The internal-link graph is derived from literal links in eligible WordPress content. A resolved relationship uses the WordPress target post ID as its durable identity; the observed URL remains derived evidence rather than the sole identity. Outbound edges are stored by source, and inbound relationships are derived from the same edge records by target post ID so parallel copies cannot drift.

Duplicate occurrences and self-links are represented deliberately. Full graph rebuilds use stable post-ID keyset traversal, replacement generations, incremental dual writes, and atomic cutover so concurrent content changes cannot be lost or stale edges resurrected.

Existing links remain normal links in post content. Intertexere does not require proprietary shortcodes, blocks, redirect layers, or runtime replacement markup.

The detailed 0.2 contract is in [Internal Link Graph](INTERNAL-LINK-GRAPH.md), with completion requirements in [0.2 Acceptance Criteria](ACCEPTANCE-0.2.md).

### Editor source and target eligibility

Target eligibility retains the 0.1 contract: candidates are current published, public, non-password-protected content in configured eligible post types.

Editor-source eligibility is separate. An editable new or unsaved draft in a supported REST-visible Block Editor post type may be analyzed without becoming an index or graph target. Analysis never promotes a draft into the derived stores.

## Analysis Pipeline

Intertexere uses a staged pipeline rather than sending the entire site to an AI model.

### Stage 1: deterministic candidate retrieval and suggestions

Milestone 0.3 uses only local WordPress data, the active content index, and the active graph to produce read-only suggestions. It performs bounded candidate retrieval, hard exclusions, a documented integer scoring model, stable ordering, and deterministic location selection.

Signals include title and heading overlap, meaningful term overlap, shared taxonomy, post type, and bounded graph relationships. No embeddings or semantic AI are part of this stage. No suggestion is a valid result.

The detailed contract is in [Read-Only Editor Suggestions](EDITOR-SUGGESTIONS.md), with completion requirements in [0.3 Acceptance Criteria](ACCEPTANCE-0.3.md).

### Stage 2: contextual AI evaluation

Beginning no earlier than 0.4, the AI layer may receive the current draft plus the constrained deterministic candidate set and evaluate contextual relevance, useful depth, anchor quality, duplicate risk, and over-linking risk.

The AI layer must be allowed to return no suggestion and cannot invent a destination outside deterministic validation.

### Stage 3: explicit editorial action

Beginning no earlier than 0.5, the editor may explicitly approve a validated suggestion for insertion. The implementation must revalidate current draft location, target identity, eligibility, and duplicate state at action time.

No suggestion changes post content until that separately implemented explicit action.

## Editor Integration

Milestone 0.3 uses a native WordPress Block Editor `PluginSidebar` registered with `registerPlugin`. Editor assets load through `enqueue_block_editor_assets`. WordPress 7.1's iframe-based canvas is treated as an implementation boundary: Intertexere uses `core/editor` and `core/block-editor` state rather than scraping or manipulating the iframe DOM.

The 0.3 sidebar shows:

- current target title and URL;
- proposed existing anchor text or location when one can be located safely;
- a deterministic reason and relevance score;
- already-linked status;
- View and session-only Dismiss actions;
- stale, loading, unavailable, error, and no-suggestion states.

There is no Insert action in 0.3. Analysis is explicitly user-triggered. Relevant edits mark prior results stale, and late asynchronous results cannot replace a newer draft's state.

The editor remains usable when Intertexere is disabled, rebuilding, unavailable, or failing. Normal WordPress editing and publishing never depend on Intertexere.

## Server and Abilities Boundary

Intertexere exposes narrow services rather than one oversized operation.

WordPress 7.1 requires read-only Abilities REST executions to use GET with URL-encoded input. That transport is unsuitable for an unsaved draft. Milestone 0.3 therefore exposes its reusable read-only PHP analyzer through an authenticated custom REST POST route with strict schemas, payload bounds, nonce handling, and post-edit capability checks. POST is a transport choice and does not authorize mutation.

Future read-only Abilities may expose small-input operations such as indexed search or graph retrieval. A future Ability wrapper around draft analysis requires a fresh WordPress API review and must not duplicate the analysis engine.

Any future operation that changes post content must require appropriate WordPress permissions and an explicit user action.

## Suggestion Identity and Storage

0.3 suggestions are response objects, not persistent records. Their identity binds a server-computed canonical draft hash to active index and graph generations and an algorithm version.

Target post ID is durable identity. Current target title, permalink, post type, status, and eligibility are resolved from WordPress when the response is constructed. Editor block client IDs, excerpts, anchors, offsets, and hashes are session-scoped evidence and must be treated as stale before any future insertion.

Dismissal in 0.3 is session-only. Persistent feedback, recommendation learning, and site-wide preferences require a later product decision.

## AI Boundary

AI begins no earlier than 0.4 and is used for contextual judgment, not as a replacement for WordPress state or deterministic validation.

AI output must be treated as untrusted input. The plugin must validate target existence and eligibility, current internal URL, current draft anchor, duplicate state, and user permission at any future mutation boundary.

AI failure must degrade to deterministic or no suggestions, not editor failure.

## Content Mutation Rules

Intertexere must not silently rewrite prose.

- 0.1 indexing, 0.2 graph analysis, and 0.3 suggestions are read-only with respect to post content.
- Link insertion begins no earlier than 0.5 and requires explicit approval.
- Any inserted link must use normal WordPress link markup and preserve ordinary undo behavior where the editor API permits it.
- Background analysis must never change published or draft content.
- Future automatic or bulk mutation requires a separate explicit product decision.

## Privacy and External Requests

Milestone 0.3 makes no external requests and does not persist unsaved draft text in Intertexere tables, options, or transients.

Future AI work should minimize external content through local candidate reduction and only the context and candidate metadata needed for the reviewed task. Provider credentials should use WordPress-native connector infrastructure where supported.

## Rebuild and Recovery

The index and graph support full rebuilds. Readers use only their stable active generations while replacements are built. 0.3 suggestion analysis reads these active generations and cannot weaken their incremental or cutover guarantees.

A failed or interrupted rebuild must not damage WordPress content. If derived tables are deleted, Intertexere can reconstruct them from current WordPress content. 0.3 has no additional persistent schema to recover.

## Deactivation and Removal

Deactivating Intertexere removes its processing and editor integration without changing content or breaking ordinary links.

Removing Intertexere must not make existing article content dependent on plugin code. Uninstall behavior for derived data and preferences remains a deliberate decision before public release.

## Security Principles

Intertexere follows normal WordPress security boundaries:

- capability checks before privileged or post-specific operations;
- REST authentication and nonce validation;
- strict validation and bounded input;
- escaping on output;
- prepared database queries;
- least-privilege REST and Abilities exposure;
- no trust in AI-generated or client-submitted URLs, IDs, HTML, selectors, anchors, hashes, or metadata;
- no executable block, shortcode, or front-end rendering during literal draft analysis.

## Initial Development Order

1. Plugin foundation and compatibility checks, complete in 0.1
2. Content indexing, complete in 0.1
3. Internal-link graph, complete in 0.2
4. Read-only deterministic editor suggestions, planned for 0.3
5. AI Client and Connectors integration, planned for 0.4
6. Explicit one-click link insertion, planned for 0.5
7. Site-level link audit and maintenance tools, planned for 0.6

Each stage must remain usable and testable without pulling later behavior forward.

## Reference Material

Current WordPress API decisions are recorded in [WordPress Reference Notes](WORDPRESS-REFERENCES.md). Implementation must re-check the official WordPress 7.1 documentation and actual runtime behavior before depending on details not fully specified there.
