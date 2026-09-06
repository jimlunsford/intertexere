# Intertexere Read-Only Editor Suggestions

## Status

This document is the architecture and implementation contract for milestone 0.3. Milestone 0.3 is implemented.

## Purpose and boundaries

Milestone 0.3 brings deterministic internal-link opportunities into the WordPress Block Editor. It analyzes the current unsaved draft, retrieves a bounded set of eligible destinations from existing local WordPress, content-index, and link-graph data, and presents read-only suggestion cards.

The milestone does not call an AI service, create embeddings, rank semantically, generate prose, insert links, mutate blocks, change post content, publish content, detect orphans, audit the site, or repair links. No suggestion and a relationship without a usable anchor are both valid results.

## Editor integration

Intertexere registers an editor-only script with `enqueue_block_editor_assets` and registers a native `PluginSidebar` through `registerPlugin`. WordPress 7.1 always renders the post editor canvas in an iframe, so the integration uses WordPress data stores and components only. It does not query the editor DOM, inspect the iframe directly, or depend on canvas selectors.

The integration is loaded only for supported Block Editor post screens. If the script fails, the plugin is disabled, or analysis fails, the sidebar is absent or shows a recoverable error while ordinary editing, saving, publishing, and previewing continue normally.

The client observes:

- `core/editor` for current post ID, post type, edited title, edited taxonomy attributes, and unsaved post state;
- `core/block-editor` for the current ordered block tree and block client IDs;
- editor navigation and entity changes so pending requests, results, dismissals, and caches do not cross post boundaries.

The initial interaction is deliberately user-triggered. The sidebar shows an **Analyze draft** action. A successful result is reused from an in-memory cache while its draft hash and analysis state remain current. Any relevant draft change immediately marks displayed results stale and exposes **Refresh suggestions**. Intertexere does not submit a request on every keystroke. Requests are abortable, and a monotonically increasing request token prevents an older response from replacing a newer result.

## Editor source eligibility

Editor-source eligibility is distinct from index and target eligibility.

- A new, draft, pending, private, or published post may be analyzed as the current source when its post type is supported by the configured Intertexere post-type rules, is exposed to the Block Editor and REST API, and the current user can edit that post.
- Existing candidate targets still must satisfy the established 0.1 eligibility contract at response time: published, public, not password protected, supported post type, and not excluded by configuration.
- An unsaved or ineligible editor source is never added to the content index or link graph merely because it was analyzed.
- The current post is excluded by post ID when it has an ID. A new unsaved post has no durable self target.

## Current draft representation

The unsaved Block Editor state is authoritative during editing. The server must never substitute the saved database copy for a newer submitted draft.

The client builds an ordered analysis snapshot from current editor blocks. Each analysis unit contains the block client ID, block name, order, and the current serialized saved-form markup for that block. The server treats the payload as untrusted, validates its shape and limits, reparses it with WordPress block and HTML APIs, and derives visible prose and links without rendering blocks.

### Included content

The initial allowlist covers static text that a reader can see and that can be located within one editor block:

- paragraph and heading text;
- list-item text, with the list container traversed but not duplicated;
- quote inner text and pullquote text;
- verse and preformatted prose;
- table cell text, with locations confined to one cell;
- literal captions from image, gallery child image, audio, and video blocks.

Traversal is affirmative rather than based on a denylist. Only the separately reviewed Core containers `core/group`, `core/columns`, `core/column`, `core/cover`, `core/media-text`, `core/list`, `core/quote`, and `core/gallery` expose their saved inner blocks in editor order. Container text is not counted again when its children are analyzed. Every other parent is opaque, including unknown Core blocks and custom blocks, unless a later reviewed contract explicitly adds it to this allowlist.

### Excluded content

The initial analyzer skips:

- shortcode, legacy widget, HTML, code, embed, navigation, and other executable or unsuitable blocks;
- dynamic or server-rendered output;
- template parts and other external entity content;
- synced pattern or reusable-block content owned by another WordPress entity;
- block attributes that are not literal visible prose, including URLs, alt text, CSS, metadata, and configuration;
- malformed or unsupported blocks that cannot be parsed safely.

Skipped blocks do not fail the whole draft. Intertexere never calls `do_shortcode()`, `render_block()`, front-end content filters, or a dynamic render callback during analysis. Custom blocks are excluded until a separately reviewed, deterministic literal-text contract exists for them.

Existing anchor markup is extracted from the submitted unsaved block markup through the same one-decode HTML parser and URL resolver boundary established in 0.2. Resolved target post IDs are the durable identity. Alternate URL forms that resolve to one target count as already linked to that target. Unresolved internal URLs retain their normalized identity. Saved graph edges never override a newer unsaved draft's link state.

## Draft identity and stale analysis

The server computes, and the client independently tracks, a versioned SHA-256 draft hash over canonical JSON containing:

- algorithm version;
- current post ID or zero and post type;
- edited title;
- sorted selected taxonomy IDs by taxonomy;
- ordered supported analysis units with client ID, block name, and submitted markup.

Object keys and taxonomy IDs are sorted before encoding; block and unit order is preserved. Unsupported block data is not included. The hash input is the UTF-8 bytes of the exact ECMAScript `JSON.stringify()` representation: slashes and Unicode are unescaped, U+2028 and U+2029 remain literal UTF-8 characters, JSON syntax characters and controls use normal JSON escapes, the taxonomy map remains an object even when empty, and units remain an ordered array. PHP uses the corresponding JSON flags and structure, and both runtimes must reproduce the shared canonical fixture. The server returns its computed hash and does not trust a client-supplied hash.

An analysis ID combines the draft hash, active content-index generation, active graph generation, and deterministic algorithm version. A result is stale if the current snapshot hash differs, the editor navigates to another post, or the client receives a newer analysis. Stale cards remain visibly marked until refreshed or cleared and cannot acquire a content-changing action in 0.3.

## Candidate retrieval

0.3 adds no persistent schema and does not scan every indexed row during an editor request.

The analyzer extracts at most 64 normalized meaningful terms and at most 8 ordered phrases from the edited title and included prose. Normalization is versioned and deterministic: HTML text is decoded once by the parser, Unicode text is lowercased, WordPress accent removal is applied, punctuation separates terms, terms shorter than three characters and a fixed documented stopword list are removed, and duplicate terms preserve first-seen order.

A bounded candidate pool is the union of:

1. up to 50 IDs from one native WordPress search query built from the strongest title phrase or first meaningful terms;
2. up to 50 eligible IDs sharing the draft's selected category or tag term IDs;
3. up to 50 graph-related IDs, limited to posts that link to the current post or to a resolved destination already linked in the unsaved draft.

The union is deduplicated by post ID and capped at 100 before scoring. Only active-generation content-index rows for those IDs are loaded. Every candidate is revalidated from current WordPress state immediately before response construction. The analyzer never treats a result from an incomplete replacement index or graph generation as active.

Native WordPress search is the extensibility boundary for sites that replace Core search. The implementation must prove the bounded query plan on a large fixture. If that performance boundary cannot be met, implementation stops for review before adding any derived retrieval schema.

## Deterministic scoring and ordering

Scores are nonnegative integers. Identical draft, active generations, WordPress state, and algorithm version produce identical ordering.

| Signal | Points |
| --- | ---: |
| Exact normalized candidate title phrase occurs in draft prose or title | 30 |
| Candidate title token coverage | `floor(40 * shared / candidate_title_terms)`, maximum 40 |
| Candidate heading token coverage | `floor(20 * shared / candidate_heading_terms)`, maximum 20 |
| Shared selected taxonomy term | 15 each, maximum 30 |
| Candidate excerpt term coverage | `floor(15 * shared / candidate_excerpt_terms)`, maximum 15 |
| Candidate normalized-content term coverage | `floor(10 * shared / candidate_content_terms)`, maximum 10 |
| Candidate has an active graph edge to the current saved post | 8 |
| Candidate shares a resolved outbound destination with the unsaved draft | 2 each, maximum 6 |
| Candidate has the same post type as the editor source | 2 |

Repeated terms do not add repeated points. Empty denominators score zero. Graph signals are unavailable for a new post and simply score zero. A candidate must score at least 25. At most 10 suggestions are returned. Results sort by score descending, normalized current target title ascending, then target post ID ascending.

The response includes machine-readable contributing signals and a deterministic human-readable reason selected from the highest-point signal, with a fixed priority order matching the table above. This is lexical and structural relevance, not semantic AI. It must not be described as AI confidence.

## Hard exclusions

A candidate is excluded when any of these conditions holds:

- it is the current post;
- it is deleted, ineligible, non-public, password protected, or excluded by current configuration;
- it is already linked from the unsaved draft through any deterministically resolved URL form;
- it duplicates a target post ID already selected for the response;
- it is represented only by stale or non-active index data;
- it does not meet the minimum score;
- the supported draft text is empty or contains fewer than 20 meaningful characters or three meaningful terms.

Unresolved draft URLs are not guessed into a target identity. A candidate may only be suppressed by an unresolved URL when its current permalink normalizes to exactly the same unresolved identity. No quota forces weak suggestions into the result.

## Location and anchor semantics

Location data is advisory and session scoped. Each suggestion may contain an ordered set of at most eight location candidates. A candidate contains:

- block client ID and block name;
- block text hash;
- an exact visible-text excerpt and surrounding context;
- exact proposed anchor text copied from the current draft substring;
- UTF-8 plaintext start and length within one analysis unit;
- occurrence index when the same text appears more than once;
- draft hash and analysis ID.

Semantic analysis and insertion-location selection are separate. All included text units may contribute to relevance, but deterministic insertion candidates come only from the unchanged insertion allowlist: `core/paragraph`, `core/heading`, and `core/list-item` direct `content` attributes. The server maps those attributes without collapsing spaces, maps `<br>` to a line break, decodes entities, and strips compatible inline markup so the proposed exact text can be found in the browser's RichText plain text. If that exact mapping cannot be established, the unit is not proposed for insertion.

The location finder searches deterministically in this order: exact full destination title, destination-specific title suffix, destination-specific heading phrase, then another sufficiently specific contiguous phrase supported by the indexed title or headings. Common leading terms shared by the source or other bounded destination titles are excluded from fallback phrases. This general rule prevents a series or category prefix from becoming the sole anchor for several unrelated destinations and does not contain site-specific series names.

Algorithm 4 also normalizes indexed heading phrases using the existing lowercase, accent, punctuation, and whitespace normalization. A normalized heading present in more than one relevant destination is shared insertion evidence and is excluded as a direct heading phrase. Each destination counts once, even if its own content repeats the heading. The context includes all eligible, unlinked candidates meeting the existing minimum relevance score in the already-loaded set of at most 100, before the ten-result display cut. This adds no query, sitewide index, or persistence and does not change retrieval or relevance scores.

Overlap fallback uses title terms and only nonshared, destination-specific heading terms, with existing term bounds and shared-leading-title-prefix exclusions. Rejected headings cannot independently reconstruct a boilerplate anchor through overlap fallback. Terms also supported by legitimate target-title evidence retain that independent authority. Full-title and destination-specific suffix matching remain intact. Without specific supported evidence, candidates are empty, `location` is null, and `location_status` is `no-specific-phrase`; the UI keeps View and Dismiss without an Insert Link button or unrelated phrase/context.

The finder evaluates at most eight ordered phrase tiers across the already bounded draft units. For each phrase tier it retains a bounded first-and-last occurrence reservoir, then reserves one response slot for every nonempty phrase tier. The full-title tier starts with its earliest match, while later destination-specific tiers retain their latest bounded match so an early repeated occurrence class cannot hide a later safe occurrence. Remaining capacity is filled deterministically from retained first and last matches and then editor order. One repeated full-title phrase therefore cannot consume all eight response locations before a later destination-specific suffix or heading tier is represented. The response still contains no more than eight candidates, and the client remains final RichText authority. It does not manufacture words or change the deterministic relevance score. A suggestion with no destination-specific supported match remains useful but read-only, with `location_status` distinguishing no specific phrase from a match found only in an unsupported block.

Block client IDs are transient editor locators, not durable content identity. PHP start and length values are diagnostics, not RichText indices. Exact case-sensitive draft text plus zero-based occurrence is the cross-runtime identity. The 0.5 insertion layer evaluates the ordered candidates against current RichText, selects the first currently safe range, reparses the current block, verifies canonical draft and direct-content identities, re-resolves the target, and confirms the selected exact text plus occurrence before any mutation. The 0.3 analysis service itself remains read-only.

## Suggestion response contract

The read-only analysis response has this stable shape, with JSON schemas enforced at the REST boundary:

```json
{
  "contract_version": 2,
  "algorithm_version": 4,
  "analysis_id": "sha256-value",
  "draft_hash": "sha256-value",
  "index_generation": "generation-id",
  "graph_generation": "generation-id",
  "suggestions": [
    {
      "target_post_id": 123,
      "target_title": "Current WordPress title",
      "target_permalink": "https://example.test/current-url/",
      "target_post_type": "post",
      "score": 72,
      "reason": {
        "code": "title-overlap",
        "label": "The draft uses terms from this article's title.",
        "signals": ["title-overlap", "taxonomy-overlap"]
      },
      "already_linked": false,
      "location": {
        "block_client_id": "client-id",
        "block_name": "core/paragraph",
        "block_text_hash": "sha256-value",
        "excerpt": "Exact text from the current draft",
        "anchor_text": "exact existing phrase",
        "start": 10,
        "length": 21,
        "occurrence": 0
      },
      "location_candidates": [
        {
          "block_client_id": "client-id",
          "block_name": "core/paragraph",
          "block_text_hash": "sha256-value",
          "excerpt": "Exact text from the current draft",
          "anchor_text": "exact existing phrase",
          "start": 10,
          "length": 21,
          "occurrence": 0
        }
      ],
      "location_status": "candidates"
    }
  ],
  "excluded_already_linked_count": 0,
  "limits": {
    "candidate_count": 34,
    "truncated": false,
    "max_location_candidates": 8
  }
}
```

Current title, permalink, post type, publication state, and eligibility are resolved from WordPress when the response is built. They are not persisted as a second canonical suggestion record. `already_linked` is false for every returned card because already-linked targets are hard exclusions; the field makes the contract explicit and supports defensive UI behavior. The response reports their aggregate exclusion count without exposing a separate recommendation history.

Contract version 2 adds bounded `location_candidates` and `location_status`. `location` remains the first ordered candidate for compatibility with the existing advisory AI context and is null when the candidate list is empty. Algorithm version 4 binds draft hashes, analysis IDs, and session caches to the shared-heading-aware selector while preserving the algorithm-3 stratified occurrence reservoir. The client rejects a response with a different contract or algorithm version.

## Server boundary and security

The analysis engine is a reusable read-only PHP service. 0.3 exposes it through `POST /wp-json/intertexere/v1/editor-suggestions` rather than an Ability.

WordPress 7.1's Abilities REST controller requires abilities annotated `readonly: true` to execute with GET and places input in a URL-encoded query parameter. An unsaved draft payload should not be placed in a URL. Marking analysis non-readonly merely to obtain POST would misstate its contract. A future WordPress API change may justify a reviewed Ability wrapper around the same service; 0.3 does not add one.

The REST route requires:

- same-origin cookie authentication and the standard WordPress REST nonce from `@wordpress/api-fetch`;
- `edit_post` permission for an existing source or `edit_posts` for a new source of the requested supported post type;
- an allowed, REST-visible Block Editor post type;
- strict object schemas with unknown fields rejected;
- integer post IDs, a matching post type, bounded strings, valid block names, and valid taxonomy IDs;
- a 256 KiB encoded request limit measured from the actual raw POST body before JSON parsing, at most 500 analysis units, and at most 16 KiB per unit;
- current eligibility checks for every returned target;
- standard `WP_Error` responses with non-sensitive messages;
- escaped text rendering in React and validated current permalinks for the View action.

The endpoint does not save a post, update metadata, write graph or index rows, execute blocks, or call an external service. POST is used only to keep an analysis payload out of the request URL.

`Content-Length` is not trusted as the transport limit because it is advisory. A priority-5 `rest_pre_dispatch` filter, registered during plugin boot and scoped exactly to `POST /intertexere/v1/editor-suggestions`, measures the body stored by `WP_REST_Request`. WordPress Core applies this filter before route matching and before `WP_REST_Request::has_valid_params()` calls `parse_json_params()`. The filter returns the existing 413 error when the body exceeds 262,144 bytes, so Core never parses a transport-rejected body. Permission-callback, endpoint-callback, decoded-payload, unit-count, and per-unit checks remain in place as defense in depth. Unrelated REST routes retain normal Core dispatch and JSON-validation behavior.

## Performance and caching

- No request runs on editor load or every keystroke.
- The client keeps only a per-editor-session in-memory cache keyed by draft hash, post identity, algorithm version, and the active generations returned by the server.
- An explicit Analyze or Refresh always reaches the server before results are treated as current. A cached generation identifier cannot prove that incremental index data, graph data, target eligibility, or current target metadata stayed unchanged, so the session cache never bypasses explicit revalidation.
- The initial server implementation adds no persistent draft cache. A request-local object cache may deduplicate reads, but unsaved draft text is not written to options, transients, index rows, or graph rows.
- Payload, token, query, candidate, scoring, and result counts are bounded as described above.
- Only candidate IDs selected by bounded retrieval are loaded from the active content-index generation.
- If the active index does not exist, analysis returns an unavailable state. If an index or graph replacement is running, readers continue using the stable active generation and the response identifies it. Graph absence disables graph points but not lexical retrieval.
- Aborted browser requests may continue briefly on the server, but their results are ignored by request token and draft hash.
- Large or unsupported drafts fail with a clear limit or no-analyzable-text result. The saved database copy is never used as a fallback.

## Editor UI and dismissal

The sidebar has restrained states: ready, analyzing, results, no suggestions, stale, unavailable, and error. Suggestion cards show the current destination title and URL, deterministic reason, score labeled as deterministic relevance, the first currently insertable proposed location when available, and a clear **Not linked from this draft** state. A read-only card distinguishes no destination-specific phrase, unsupported block, existing link, another-link overlap, RichText replacement overlap, changed phrase or block, unsafe RichText mapping, and an unknown unavailable fallback. Those explanations never grant insertion authority.

Actions are:

- **View**, which opens the current target permalink for inspection;
- **Dismiss**, which hides that target only for the current analysis in the current editor session;
- **Analyze draft** or **Refresh suggestions**.

The 0.3 analyzer exposes no mutation endpoint. In 0.5, its current exact location evidence may make an Insert Link action eligible, but a dedicated server validation and a final synchronous client check are still mandatory. Session dismissals are keyed by analysis ID and target post ID, reset on navigation or fresh analysis, and are never persisted.

## Relationship to other milestones

- 0.1 remains the only content-index store. 0.3 reads its active generation and adds no parallel content copy.
- 0.2 remains the only authoritative derived edge store. 0.3 reads active graph relationships and unsaved draft links without weakening rebuild concurrency.
- 0.4 implements optional filtering, reranking, explanation, and exact-existing-anchor selection for the bounded deterministic candidate set through WordPress-native AI. Deterministic results remain authoritative and independently usable. See [WordPress-Native AI Integration](AI-INTEGRATION.md).
- 0.5 implements explicit insertion only after current deterministic candidate, target, draft, exact occurrence, duplicate, and RichText boundary validation. Every 0.3 location remains a hint until revalidated. The mutation allowlist is paragraph, heading, and list-item direct `content` attributes; other analyzable locations remain read-only. See [Explicit Link Insertion](LINK-INSERTION.md).
- 0.6 implements a separate read-only admin audit over active index and graph evidence. It does not change 0.3 retrieval, scoring, ordering, exclusions, or suggestion authority. Audit findings do not silently boost editor candidates. See [Site Link Audit](SITE-LINK-AUDIT.md).

## Known planning boundary

Core WordPress search is intentionally used as the initial bounded lexical-retrieval adapter because schema version 2 has no inverted text index. The 0.3 implementation must measure the exact query plan and editor latency with a large fixture. A new persistent retrieval table, FULLTEXT index, external search dependency, or whole-index synchronous scan is not authorized by this plan. If the defined bounds cannot meet acceptance criteria, that is an architecture review point rather than permission to change schema silently.

## Implemented build and verification boundary

The 0.3 editor source is built with Node.js 22.13.0 and exact development-package versions recorded in `package.json` and `package-lock.json`. The committed production bundle is generated by `@wordpress/scripts` and includes its WordPress dependency manifest plus left-to-right and right-to-left styles.

The implementation retains schema version 2. It adds no migration, suggestion table, draft table, dismissal table, transient cache, or persistent retrieval store. PHP integration tests enforce the 256 KiB payload, 500-unit, 16 KiB unit, 64-term, 8-phrase, 100-candidate, 10-result, 8-location, and score-25 boundaries. The large-fixture test records request query count, total latency, location-selection latency, response bytes, and the maximum location count, and fails if the request exceeds 12 database queries or 3 seconds in the WordPress test environment.
