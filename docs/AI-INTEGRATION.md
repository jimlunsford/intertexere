# WordPress-Native AI Integration

## Status

This document defines the planned architecture for milestone 0.4. It is a planning contract, not an implementation record.

Milestones 0.1, 0.2, and 0.3 are complete. Milestone 0.4 is planned and not implemented.

## Goal

0.4 optionally improves the contextual ranking, explanation, and existing-anchor recommendation for the bounded deterministic candidate set produced by 0.3. It uses WordPress-native, provider-agnostic AI infrastructure and remains read-only with respect to all post content.

AI may filter or reorder deterministic candidates. It may not invent a destination, bypass a deterministic exclusion, mutate a block, insert a link, rewrite prose, or become necessary for ordinary editor suggestions.

The authoritative flow is:

1. analyze the current unsaved draft through the 0.3 snapshot contract;
2. retrieve and validate a bounded deterministic candidate set locally;
3. explicitly request AI enhancement for a bounded subset;
4. construct a minimal server-authoritative prompt;
5. invoke the WordPress AI Client through its default provider registry;
6. validate the complete structured response as untrusted input;
7. revalidate draft, analysis, generations, targets, links, and locations;
8. overlay valid AI judgments on the still-available deterministic suggestions.

No AI-enhanced suggestion is a valid result. If enhancement cannot complete safely, the existing deterministic result remains visible and usable.

## Verified WordPress 7.1 boundary

WordPress 7.1 provides `wp_ai_client_prompt()`, which returns `WP_AI_Client_Prompt_Builder` backed by the AI Client default provider registry. The builder supports system instructions, structured JSON responses, per-request HTTP options, text-generation support checks, and result or text generation.

Intertexere will use:

- `wp_ai_client_prompt()` for provider-neutral prompt construction;
- `using_system_instruction()` to isolate product rules from untrusted content;
- `as_json_response()` with a strict response schema;
- `using_request_options()` to set the reviewed timeout;
- `using_max_tokens()` to bound output;
- `is_supported_for_text_generation()` immediately before invocation;
- `generate_text_result()` so the adapter can inspect the structured result where supported.

`wp_supports_ai()` is only an environment and request-level availability signal. WordPress 7.1 runtime verification showed that it can return true while `is_supported_for_text_generation()` returns false because no configured model is available. Intertexere must not equate installed connector registrations, `wp_supports_ai()`, or the presence of a provider plugin with a usable model.

WordPress Connectors owns provider registration and credential configuration. Intertexere does not store provider keys, register provider-specific SDKs, or hard-code a commercial model. A site administrator configures supported providers through WordPress Settings > Connectors. Environment variables and constants remain valid WordPress connector credential sources. Database-stored connector credentials are a WordPress responsibility and, in WordPress 7.1, are masked in the UI but are not encrypted at rest.

WordPress 7.1 does not provide a natural WordPress streaming contract suitable for this milestone. 0.4 uses one bounded non-streaming request. Embeddings are not part of the WordPress 7.1 AI Client contract used here and are outside 0.4.

## Invocation and service architecture

The production architecture will add a reusable server-side AI enhancement service behind a small AI Client adapter. The adapter is the only Intertexere component that invokes `WP_AI_Client_Prompt_Builder`. Tests inject a deterministic fake adapter and never use live providers, credentials, or paid network calls.

The existing editor integration will call a new authenticated custom REST endpoint:

`POST /wp-json/intertexere/v1/editor-suggestions/ai-enhance`

This remains a read-only operation. POST keeps unsaved draft text and candidate identifiers out of a URL. The endpoint reuses the hardened 0.3 authentication, capability, raw-body, decoded-payload, validation, and request-ownership patterns.

0.4 will not expose enhancement as an Ability and will not call `using_abilities()`. WordPress 7.1 Ability REST execution requires a read-only ability to use GET with URL-encoded input, which is unsuitable for an unsaved draft. The model also requires no tools: granting Ability access would add prompt-injection and side-effect surface without helping the constrained ranking task.

The client submits the current canonical 0.3 draft snapshot, current deterministic `analysis_id`, and an ordered requested subset of target post IDs. The client does not submit authoritative target titles, URLs, excerpts, eligibility, or scores.

Before any external request, the server:

1. reruns the deterministic 0.3 analyzer from the submitted unsaved draft;
2. verifies the submitted draft hash, analysis ID, active index generation, and active graph generation;
3. requires every requested target ID to occur in the fresh deterministic result;
4. reapplies self, eligibility, visibility, password, duplicate, and already-linked exclusions;
5. obtains current WordPress target metadata and bounded active-index context;
6. stops without an AI call if any ownership or eligibility condition is stale.

This makes the server-produced deterministic result the only candidate authority and prevents a crafted client from asking AI to inspect arbitrary posts.

## Configuration and availability

AI enhancement has two gates:

- a site-wide Intertexere preference, `enable_ai_enhancement`, defaulting to false; and
- an explicit per-analysis editor action.

The preference uses the existing Intertexere settings option. It does not add a table, migration, or schema version. Its administration text must explain that explicitly submitted unsaved draft excerpts and bounded candidate context may leave the site through a WordPress-configured provider.

When the preference is enabled, the service performs the WordPress AI support and configured-model checks. The editor represents these states distinctly:

- deterministic suggestions available, AI disabled;
- deterministic suggestions available, AI unavailable or unconfigured;
- AI enhancement ready;
- AI enhancement running;
- AI-enhanced overlay available;
- AI enhancement failed, deterministic suggestions preserved;
- prior enhancement stale.

An invalid credential, missing capability, unreachable provider, timeout, rate limit, malformed response, disabled WordPress AI environment, or explicit site setting never blocks deterministic analysis, editing, saving, previewing, or publishing.

## Privacy contract

Intertexere makes no external AI request when the sidebar opens, while the user types, or when deterministic analysis runs. The editor must explicitly invoke **Enhance with AI** after deterministic analysis succeeds.

Before that action is available, the UI discloses that the following bounded data may leave the WordPress server through the provider configured in WordPress:

- the current unsaved title;
- selected exact excerpts from supported draft units;
- opaque request-scoped unit identifiers;
- current candidate titles;
- bounded candidate excerpts and selected headings;
- relevant taxonomy labels;
- deterministic signals, scores, and location evidence.

Intertexere does not send the entire site, full candidate article bodies, credentials, arbitrary database metadata, excluded posts, ineligible posts, unrelated editor state, or content outside the deterministic candidate subset.

The configured provider may process or retain data under its own terms. Intertexere must not claim otherwise. Provider selection and credentials remain visible and manageable through WordPress Connectors.

Prompts, responses, unsaved drafts, explanations, and AI rankings remain in the current editor session only. Intertexere does not persist them to tables, options, post meta, transients, logs, or the content index. WordPress or provider-level operational logging remains outside Intertexere's storage contract and must not be represented as Intertexere persistence.

## Bounded prompt payload

One request may submit at most 8 candidates from the 0.3 maximum of 10 suggestions.

The implementation limits are:

| Boundary | Limit |
| --- | ---: |
| Raw REST body | 262,144 bytes before JSON parsing |
| AI candidates | 8 |
| Draft context units | 8 |
| Combined draft context | 12 KiB UTF-8 |
| Per-candidate context | 4 KiB UTF-8 |
| Canonical model payload | 48 KiB UTF-8 |
| Model output | 1,500 tokens and 32 KiB raw response |
| Explanation | 320 Unicode code points and 1,280 UTF-8 bytes |
| Anchor text | 200 Unicode code points within one supplied unit |
| Provider HTTP timeout | 20 seconds |

The prompt uses request-scoped opaque identifiers such as `c1` and `u1`. The server retains their mappings to durable post IDs and draft units. The candidate portion contains only current server-resolved title, bounded active-index excerpt, selected headings and taxonomy labels, deterministic score and contributing signals, plus available local location evidence.

Draft and candidate content are serialized as bounded data. The system instruction says that all supplied article content is untrusted data, never instructions. The model receives no tools, Abilities, web search, file access, arbitrary database access, or method for requesting additional site content.

## Structured AI response

The AI response uses a strict schema equivalent to:

```json
{
  "contract_version": 1,
  "evaluations": [
    {
      "candidate_key": "c1",
      "decision": "keep",
      "rank": 1,
      "reason": "This destination adds useful context about the draft passage.",
      "anchor": {
        "unit_key": "u1",
        "exact_text": "existing phrase in the draft",
        "occurrence": 0
      }
    }
  ]
}
```

`decision` is `keep` or `drop`. A kept item has a unique contiguous rank from 1 through the number kept. A dropped item has a null rank. `anchor` is nullable. The response must contain exactly one evaluation for every submitted candidate key, with no unknown or duplicate keys.

The model does not return authoritative post IDs, titles, permalinks, HTML, or insertion instructions. The server maps candidate keys back to submitted deterministic target IDs and resolves all current target metadata from WordPress.

AI does not produce a field labeled confidence. The 0.3 deterministic integer score remains unchanged and separately visible. AI supplies an advisory keep/drop decision and contextual rank. There is no undocumented formula that combines dissimilar scores.

## Response validation

Every AI response is untrusted. Structural failure, unknown or duplicate keys, missing candidates, invalid decisions, non-contiguous ranks, excessive size, or malformed JSON rejects the complete enhancement. The deterministic result remains available.

After structural validation, the server rechecks:

- the submitted post identity and current user's edit capability;
- the canonical draft hash and deterministic analysis ID;
- active index and graph generations;
- candidate membership in the current deterministic result;
- target existence, publication, public visibility, password state, post type, and configured eligibility;
- self-target and duplicate-target exclusions;
- existing links in the submitted unsaved draft;
- current target title and permalink from WordPress;
- explanation content and length;
- anchor location and exact text.

An anchor is accepted only when its exact text exists at the specified occurrence inside one supplied supported draft unit. It cannot span units, blocks, table cells, captions, or markup boundaries. The server computes offsets from the authoritative submitted unit after validation and does not trust model-provided offsets. Rewritten, nonexistent, ambiguous, or stale anchor text is discarded and returned as null; a separately valid candidate judgment may remain. The model never manufactures replacement prose.

The client performs its own ownership check before applying a response. Post ID, request token, draft hash, analysis ID, and editor navigation identity must still match. Server-side acceptance does not authorize a stale browser response to update the current sidebar.

## Ranking and explanations

AI can filter and rerank only the supplied deterministic candidates. Kept candidates are shown in AI rank order while retaining their deterministic score and signals. Dropped candidates do not become deleted site state or persistent preferences. The UI can return to the deterministic ordering without another analysis request.

An explanation is advisory, concise, and limited to the relationship supported by submitted draft and candidate context. It may not claim SEO outcomes, assert facts absent from supplied content, pressure insertion, rewrite the draft, or describe hidden destination content. Rendering uses ordinary escaped React text.

It is valid for AI to drop every candidate. The editor then reports that AI found no contextual enhancement while keeping the underlying deterministic result accessible.

## Editor interaction and request ownership

The existing sidebar remains the only suggestion surface. It preserves **Analyze draft**, **Refresh suggestions**, **View**, and session-only **Dismiss**. When site configuration and capability checks allow it, a completed current deterministic result adds **Enhance with AI**.

There is no Insert Link action.

Only one enhancement request is active in an editor session. A new deterministic analysis, draft edit, post navigation, second enhancement request, or sidebar teardown aborts the client request where possible and invalidates its ownership token. The server may be unable to cancel an in-flight provider call, so every late result is ignored unless its post identity, draft hash, analysis ID, generation identity, and request token remain current.

An in-memory session cache may reuse one valid AI response for the same analysis ID, ordered candidate set, and AI contract and prompt versions. Navigation, draft change, deterministic refresh, generation change, or configuration change invalidates it. There is no persistent cache and no automatic retry.

## Security boundary

The endpoint requires same-origin cookie authentication, WordPress REST nonce protection, edit capability for the current source, supported post type validation, strict input schemas, and current target validation. Unknown fields are rejected.

The route-specific `rest_pre_dispatch` transport guard must measure the actual raw request body before Core JSON parsing. `Content-Length` is not authoritative. The service keeps decoded size, draft unit, per-unit, candidate, context, output, and explanation limits as defense in depth.

Provider errors are mapped to non-sensitive UI states. Intertexere must not expose connector credentials, raw provider responses, full prompts, stack traces, or private provider error payloads. WordPress 7.1 error classes cover prevention, invalid arguments, token exhaustion, network failure, client errors including rate limits, and upstream server errors. Product behavior depends on safe categories rather than provider-specific wording.

## Cost and request control

- AI is disabled by default at the Intertexere site preference.
- Every AI request requires an explicit editor action.
- No request runs on load, typing, autosave, deterministic refresh, or a timer.
- At most 8 candidates and the documented prompt limits apply.
- The provider timeout is 20 seconds.
- There are no background retries.
- Only one current request may own the editor result.
- Session reuse is limited to an identical current analysis and candidate set.
- Intertexere does not persist provider responses for reuse.

These controls bound cost without pretending that Intertexere controls provider pricing or quotas.

## Performance contract

Provider latency is measured separately from local work.

The 125-post performance fixture must continue to satisfy the 0.3 deterministic limit of at most 12 database queries and 3 seconds. For the AI endpoint, the same deterministic rerun remains bounded, while prompt preparation and post-response validation may add at most 8 database queries and 500 milliseconds of provider-independent local work. The entire provider-independent endpoint must remain at or below 20 database queries and 3.5 seconds in the WordPress test environment.

Performance reporting records deterministic retrieval time, candidate count, prompt construction time, prompt bytes, provider-independent response validation time, and total local query count. No acceptance threshold is placed on provider completion below the explicit 20-second timeout.

## Testing architecture

### PHP and service tests

Tests use an injected deterministic AI adapter and cover:

- site setting disabled and enabled;
- `wp_supports_ai()` false;
- connector present but no compatible configured model;
- provider available, timeout, network error, rate limit, upstream error, and invalid credentials;
- malformed, oversized, and schema-invalid response;
- strict structured output validation;
- unknown, missing, and duplicate candidate keys;
- duplicate or invalid ranks;
- deterministic candidate subset enforcement;
- target eligibility, deletion, permalink, and already-linked races;
- stale draft hash, analysis ID, index generation, and graph generation;
- current title and permalink resolution;
- exact anchor occurrence validation and null-anchor downgrade;
- request, draft, candidate, prompt, output, and explanation limits;
- prompt privacy assertions and instruction/data separation;
- no external request without an explicit enhancement call;
- no Intertexere persistence of prompts, responses, drafts, explanations, or scores;
- no post, block, metadata, taxonomy, index, or graph mutation;
- bounded provider-independent queries and latency;
- the complete 0.1, 0.2, and 0.3 regression suites.

### JavaScript tests

Jest unit and component tests cover:

- unchanged deterministic UI before AI invocation;
- explicit enhancement trigger and privacy disclosure;
- disabled, unavailable, ready, loading, enhanced, failed, and stale states;
- deterministic fallback on every failure;
- AI filtering and ranking without overwriting deterministic scores;
- anchor and explanation rendering as escaped text;
- draft edit and deterministic refresh while AI runs;
- late response and navigation rejection;
- session-only cache reuse and invalidation;
- request abort and one-owner behavior;
- no insertion or content-changing control.

### WordPress browser tests

Playwright runs against the actual WordPress 7.1 iframe editor using a deterministic test adapter, not a live provider. It proves:

- deterministic suggestions work with no configured model;
- AI enhancement requires explicit invocation;
- a valid fake enhancement appears in the existing sidebar;
- provider failure preserves deterministic suggestions;
- editing while AI runs makes the result stale;
- navigation discards the prior response;
- save and publish behavior remain ordinary;
- enhancement never changes block or post content;
- no Insert Link control exists.

The CI matrix remains WordPress 7.1 with PHP 7.4, 8.1, and 8.3, PHP syntax validation, JavaScript lint and Jest, reproducible production build, and the WordPress 7.1 Playwright editor suite. CI contains no AI secrets and makes no paid external AI call.

## Persistence and schema

0.4 requires no persistent derived schema and does not change schema version 2. The only planned stored value is the intentional site-wide enable preference in the existing settings option. It contains no draft, prompt, response, score, explanation, provider credential, or candidate content.

If implementation reveals a need for persistent AI data or another table, work stops for a separately reviewed architecture and privacy decision. It is not authorized by this plan.

## Relationship to later milestones

0.4 produces read-only, session-scoped advisory output. Its structured anchor evidence is designed so 0.5 can independently revalidate an explicitly approved location, but 0.4 performs no insertion and grants no mutation authority.

0.4 does not implement site audits, orphan or under-linked detection, broken-link repair, bulk workflows, persistent feedback, embeddings, vector search, or machine-learning preference storage. AI cannot select destinations outside the 0.3 deterministic candidate set.

## Known planning limitations

Provider quality, latency, cost, retention, and model availability vary outside Intertexere's control. Structured response support is still validated at runtime because provider plugins and configured models may differ. Intertexere can guarantee its input bounds, validation, fallback, and non-mutation behavior, but it cannot guarantee that a provider will produce a useful enhancement.

The exact WordPress 7.1 AI Client method and result shapes must be rechecked during implementation against the pinned runtime and Core source. Any conflict with this contract must be documented and reviewed before architecture changes.
