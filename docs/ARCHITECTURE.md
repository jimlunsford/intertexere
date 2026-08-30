# Intertexere Architecture

## Status

This document defines the initial architecture for Intertexere.

The repository is the source of truth for the plugin implementation. WordPress remains the source of truth for site content. Derived indexes and link-graph data may be rebuilt from WordPress at any time.

## Product Identity

Intertexere is a contextual internal-linking assistant for WordPress.

Its purpose is to help an editor discover useful internal-link opportunities while writing, understand the existing internal-link graph, and insert approved links without surrendering editorial control.

Intertexere is not an automatic link-insertion engine. AI output is advisory unless a user explicitly approves a content change.

## Platform Baseline

The initial development baseline is WordPress 7.1 or newer.

Intertexere should use WordPress-native APIs where they are appropriate, including:

- the Block Editor and WordPress data APIs for editor integration
- the Abilities API for discrete, discoverable plugin operations
- the WordPress AI Client for provider-agnostic AI requests
- the Connectors API for supported external-service credentials and provider configuration
- standard WordPress REST, capability, nonce, sanitization, escaping, database, scheduling, and plugin APIs

Intertexere must not hard-code OpenAI as the only AI provider.

## Core Data Boundaries

### WordPress content is authoritative

Posts, pages, custom post types, titles, URLs, taxonomies, post status, and article content remain owned by WordPress.

Intertexere must not create a second canonical copy of article content.

### Derived data is rebuildable

Intertexere may maintain derived data for performance and analysis, including:

- normalized searchable text
- headings
- summaries
- topic or concept metadata
- candidate-search metadata
- outbound internal-link edges
- inbound internal-link edges
- orphan or under-linked status
- suggestion history
- dismissed suggestions

Derived data must be considered disposable and rebuildable from WordPress plus Intertexere's own user preferences where applicable.

### Link graph

The internal-link graph is derived from literal links in eligible WordPress content. A resolved relationship uses the WordPress target post ID as its durable identity; the observed URL remains derived evidence rather than the sole identity. Outbound edges are stored by source, and inbound relationships are derived from the same edge records by target post ID so parallel copies cannot drift.

Duplicate occurrences and self-links must be represented deliberately. Full graph rebuilds use stable post-ID keyset traversal, replacement generations, incremental dual writes, and atomic cutover so concurrent content changes cannot be lost or stale edges resurrected.

Existing links remain normal links in post content. Intertexere must not require proprietary shortcodes, blocks, redirect layers, or runtime replacement markup merely to preserve a link it inserted.

The detailed milestone 0.2 contract is in [Internal Link Graph](INTERNAL-LINK-GRAPH.md), with completion requirements in [0.2 Acceptance Criteria](ACCEPTANCE-0.2.md).

## Analysis Pipeline

Intertexere should use a hybrid pipeline rather than sending the entire site to an AI model.

### Stage 1: deterministic candidate retrieval

Local WordPress data narrows the site's content to plausible destinations using signals such as:

- title
- headings
- taxonomy
- excerpt
- normalized content
- local search relevance
- optional semantic metadata or embeddings where supported and justified
- existing link relationships
- exclusion and priority rules

### Stage 2: contextual evaluation

The AI layer receives the current draft plus a constrained candidate set and evaluates:

- contextual relevance
- whether a link would add useful depth
- the best existing anchor phrase or anchor location
- duplicate destination risk
- over-linking risk
- whether no link should be suggested

The AI layer must be allowed to return no suggestion.

### Stage 3: explicit editorial action

The editor reviews a suggestion and may:

- insert it
- dismiss it
- view the target
- suppress the target from future suggestions
- leave the draft unchanged

No suggestion changes post content until the editor explicitly approves it.

## Editor Integration

The initial editor experience should live in the WordPress Block Editor sidebar.

A suggestion should be able to show:

- target article title
- target URL
- proposed anchor text or location
- short reason for the suggestion
- confidence or relevance signal when useful
- Insert
- Dismiss
- View

The editor must remain usable when Intertexere is disabled, misconfigured, rebuilding its index, or unable to reach an AI provider.

Normal WordPress editing and publishing must never depend on Intertexere being available.

## Abilities Boundary

Intertexere should expose narrow abilities rather than one oversized AI operation.

Likely read-only abilities include concepts such as:

- search indexed content
- retrieve link-graph information
- analyze a draft for candidate links
- retrieve candidate destinations

Any ability that changes post content must require appropriate WordPress permissions and an explicit user action.

Ability names, schemas, and exposure rules should be finalized against the implemented WordPress 7.1 APIs before release.

## AI Boundary

AI is used for semantic judgment, not as a replacement for WordPress state or deterministic validation.

AI output must be treated as untrusted input until validated.

The plugin should validate at least:

- target post still exists
- target is eligible for linking
- target URL is internal and canonical
- proposed anchor still exists in the current draft when insertion occurs
- the exact link is not already present where duplication would be undesirable
- the current user has permission to edit the post

AI failure must degrade to no AI suggestions, not editor failure.

## Content Mutation Rules

Intertexere must not silently rewrite prose.

For the initial product:

- link insertion should wrap existing editor text whenever possible
- unrelated wording should not be rewritten to manufacture an anchor
- inserted links should use normal WordPress link markup
- ordinary WordPress undo behavior should remain available where the editor API permits it
- the plugin must not change published content during background indexing

Future automatic or bulk mutation features would require a separate explicit product decision.

## Privacy and External Requests

Intertexere should minimize the content sent to external AI providers.

The default architecture should prefer:

1. local candidate reduction
2. only the draft context needed for the decision
3. only the candidate metadata needed to rank or explain the suggestion

The plugin should not send the entire site archive to an AI provider on every analysis request.

Provider credentials should use WordPress-native connector infrastructure when that infrastructure supports the selected provider and authentication method.

## Rebuild and Recovery

The index and graph must support a full rebuild.

A failed or interrupted rebuild must not damage WordPress content.

If Intertexere's derived tables or metadata are deleted, the plugin should be able to reconstruct them from current WordPress content, except for intentionally persistent user preferences such as dismissals or exclusions if those are stored separately.

## Deactivation and Removal

Deactivating Intertexere must not break links previously inserted into articles.

Removing Intertexere must not make existing article content dependent on plugin code.

Uninstall behavior for derived data and preferences should be deliberate and documented before public release.

## Security Principles

Intertexere must follow normal WordPress security boundaries:

- capability checks before privileged actions
- nonce validation for state-changing editor/admin requests
- validation and sanitization on input
- escaping on output
- prepared database queries
- least-privilege REST and Abilities exposure
- no trust in AI-generated URLs, IDs, HTML, selectors, or anchors

## Initial Development Order

1. Plugin foundation and compatibility checks
2. Content indexing
3. Internal-link graph
4. Read-only editor suggestions
5. AI Client and Connectors integration
6. Explicit one-click link insertion
7. Site-level link audit and maintenance tools

Each stage should be usable and testable before the next stage builds on it.

## Reference Material

Architecture decisions involving current WordPress AI or Abilities behavior should be checked against current official WordPress developer documentation before implementation, especially:

- WordPress 7.1 Field Guide
- Abilities API developer notes
- WordPress AI Client developer notes
- Connectors API developer notes
