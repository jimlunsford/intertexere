# Intertexere Roadmap

## Status

Intertexere is in private initial development.

This roadmap is intentionally milestone-oriented. Scope should change when real testing shows that a different order produces a stronger product.

## 0.1 Foundation and Content Index

Status: complete. See [0.1 Acceptance Criteria](ACCEPTANCE-0.1.md).

Goal: establish a safe WordPress plugin foundation and a rebuildable local understanding of eligible site content.

Scope:

- plugin bootstrap
- WordPress 7.1+ compatibility guard
- activation/deactivation behavior
- capability and settings foundation
- determine eligible post types and statuses
- index schema for derived content data
- initial full index build
- incremental indexing on content changes
- safe reindex/rebuild operation
- basic diagnostics for index state
- automated tests for index creation and update behavior

Acceptance direction:

- WordPress works normally with Intertexere disabled
- activating the plugin does not change existing post content
- the index can be built from current WordPress content
- updating, deleting, unpublishing, or changing an indexed post updates derived state correctly
- clearing derived index data and rebuilding reproduces the expected current state

## 0.2 Internal Link Graph

Status: complete. See [Internal Link Graph](INTERNAL-LINK-GRAPH.md) and [0.2 Acceptance Criteria](ACCEPTANCE-0.2.md).

Goal: understand the actual internal links already present across eligible site content.

Scope:

- parse literal saved WordPress post content
- identify internal links and distinguish external links
- normalize absolute, relative, and WordPress-generated target URLs
- map links to durable target post IDs when possible
- retain unresolved internal URL evidence without guessing identity
- record outbound edges and derive inbound relationships
- handle duplicate occurrences and self-links deliberately
- update graph state when sources or targets change eligibility
- handle permalink and slug changes through post identity
- rebuild the graph safely under concurrent content changes

Acceptance direction:

- existing ordinary links are detected without modifying content
- duplicate occurrences and self-links have explicit, testable semantics
- slug or permalink changes do not stale resolved post-ID relationships
- external links are not treated as internal graph edges
- graph state is incrementally correct and safely rebuildable
- no orphan detection, ranking, recommendations, AI, editor UI, or content mutation is introduced

## 0.3 Read-Only Editor Suggestions

Status: complete. See [Read-Only Editor Suggestions](EDITOR-SUGGESTIONS.md) and [0.3 Acceptance Criteria](ACCEPTANCE-0.3.md).

Goal: bring useful internal-link opportunities into the Block Editor without changing the draft.

Scope:

- editor sidebar
- current draft inspection
- deterministic candidate retrieval
- candidate exclusion rules
- suggestion cards
- destination title and URL
- proposed anchor or location
- reason for suggestion
- dismiss and view actions
- no content mutation

Architecture direction:

- native `PluginSidebar` registered through supported Block Editor APIs
- current unsaved Block Editor state is authoritative
- explicit Analyze and Refresh actions rather than analysis on every keystroke
- bounded deterministic retrieval from WordPress, the active content index, and the active link graph
- stable published scoring, threshold, exclusions, and ordering
- session-only dismissals
- authenticated read-only analysis through a custom REST POST endpoint
- no schema change and no persistent suggestion store

Acceptance direction:

- opening the editor remains fast
- suggestions do not change post content
- already-linked destinations can be filtered or identified
- weak candidates can be omitted
- no suggestion is a valid result

## 0.4 WordPress-Native AI Integration

Status: planned and not implemented. See [WordPress-Native AI Integration](AI-INTEGRATION.md) and [0.4 Acceptance Criteria](ACCEPTANCE-0.4.md).

Goal: use WordPress' provider-agnostic AI infrastructure for contextual ranking and explanation.

Scope:

- WordPress AI Client integration
- Connectors-based provider configuration where supported
- constrained candidate payloads
- structured AI response schema
- semantic relevance evaluation
- anchor recommendation
- malformed-output handling
- timeout/rate-limit/provider-error behavior
- privacy review of prompt payloads

Architecture direction:

- deterministic 0.3 analysis remains authoritative and independently usable
- AI is disabled by default and explicitly user-triggered
- server-side invocation through `wp_ai_client_prompt()` and the WordPress default provider registry
- provider and credential configuration remains in WordPress Connectors
- at most eight server-validated deterministic candidates enter one bounded structured request
- strict candidate-key, rank, explanation, anchor, stale-state, and current-target validation
- filtering and reranking remain separate from the deterministic score
- existing sidebar states are extended without adding an insertion control
- session-only AI output, no persistent AI data, and no schema change
- no Abilities or tools are exposed to the model

Acceptance direction:

- normal editing works with no AI provider configured
- normal editing works when an AI provider fails
- AI cannot invent an eligible target without deterministic validation
- only constrained draft context and candidate data are sent externally
- provider-specific behavior does not become the product's core contract
- no external request occurs without an explicit current-editor action
- malformed or stale AI output cannot replace deterministic results
- schema remains version 2 and post content remains unchanged

## 0.5 Explicit Link Insertion

Goal: allow the editor to approve a suggestion and insert a normal WordPress link safely.

Scope:

- explicit Insert action
- validate target at insertion time
- validate proposed anchor against current draft state
- safe Block Editor mutation
- duplicate protection
- permission checks
- stale suggestion handling
- undo-compatible editor behavior where supported

Acceptance direction:

- no link is inserted without explicit user action
- insertion does not rewrite unrelated prose
- inserted links remain functional with Intertexere deactivated
- malformed or stale suggestions fail safely
- published content is not changed by background processing

## 0.6 Site Link Audit

Goal: extend the same index and graph into a maintenance workflow for existing content.

Possible scope:

- orphaned content
- low inbound-link coverage
- low outbound-link coverage
- broken internal links
- old posts that should link to newer posts
- cornerstone-content opportunities
- dismissed and excluded targets
- filtered audit views

This milestone should reuse the established index, graph, and suggestion engine rather than create a separate analysis subsystem.

## Later Product Questions

These are intentionally not part of the initial contract:

- bulk approval workflows
- embeddings as a persistent local or external index
- multisite support
- WooCommerce or non-editor content surfaces
- automatic link insertion
- automatic prose rewriting for anchor optimization
- cloud-hosted Intertexere services
- paid tiers
- WordPress.org public release

Each requires a deliberate product decision after the private plugin proves useful on a real site.
