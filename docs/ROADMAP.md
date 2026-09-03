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

Status: complete. See [WordPress-Native AI Integration](AI-INTEGRATION.md) and [0.4 Acceptance Criteria](ACCEPTANCE-0.4.md).

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

Status: complete. See [Explicit Link Insertion](LINK-INSERTION.md) and [0.5 Acceptance Criteria](ACCEPTANCE-0.5.md).

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

Architecture direction:

- dedicated read-only server validation immediately before a local editor mutation
- current deterministic candidate membership remains mandatory; AI grants no insertion authority
- current canonical target permalink resolved by WordPress at validation time
- exact anchor text and zero-based occurrence recomputed in the current RichText value
- initial insertion allowlist limited to paragraph, heading, and list-item direct `content` attributes
- one native `core/link` RichText format applied through `updateBlockAttributes()`
- final client request-ownership and editor-state check after server validation
- one destination link per resolved target post ID in the current draft
- no server-side post mutation, automatic save, automatic publish, or special graph write
- no persistent insertion token or data, no prompt expansion, and schema remains version 2

Acceptance direction:

- no link is inserted without explicit user action
- insertion does not rewrite unrelated prose
- inserted links remain functional with Intertexere deactivated
- malformed or stale suggestions fail safely
- published content is not changed by background processing
- normal Undo restores the exact pre-insertion block state
- unsupported or unproved locations remain read-only and fail safely

## 0.6 Site Link Audit

Status: planned and not implemented. See [Site Link Audit](SITE-LINK-AUDIT.md) and [0.6 Acceptance Criteria](ACCEPTANCE-0.6.md).

Goal: extend the same index and graph into a maintenance workflow for existing content.

Initial scope:

- content-body orphans with zero qualifying inbound content sources
- thin inbound coverage with exactly one qualifying inbound source
- unresolved internal URLs and currently unavailable or ineligible resolved targets
- repeated source-to-target link review opportunities
- separately reported self-links
- bounded noncanonical review opportunities only where schema 2 evidence is sufficient
- native administration filters, keyset pagination, and View/Edit actions

Architecture direction:

- reuse exact active index and graph generations without a separate analysis store
- bind every request and cursor to both generation IDs and fail stale on cutover
- bulk revalidate current WordPress source and target authority before display
- compute results on demand with no audit table, option, transient, background run, or schema change
- require `manage_intertexere` for site-wide structural data
- use a server-rendered WordPress-native Tools screen
- make no AI request and do not change editor scoring
- provide no automatic or bulk repair

Acceptance direction:

- classifications are deterministic and accurately limited to literal links in eligible saved content
- unresolved does not automatically mean broken, and valid noncanonical forms are review opportunities
- self-links do not prevent orphan or thin-inbound findings
- pagination and current revalidation are bounded and avoid N+1 queries
- all audit use is read-only with respect to content, editor state, index, graph, settings, and schema

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
