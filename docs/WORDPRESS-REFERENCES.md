# WordPress Reference Notes

## Purpose

This file records the official WordPress developer references that informed Intertexere's initial architecture.

These links are references, not substitutes for verifying current runtime behavior during implementation.

## WordPress 7.1

- WordPress 7.1 Field Guide: https://make.wordpress.org/core/2026/08/05/wordpress-7-1-field-guide/
- WordPress 7.1 roadmap: https://make.wordpress.org/core/2026/06/19/roadmap-to-7-1/
- Iframed editor changes in WordPress 7.1: https://make.wordpress.org/core/2026/08/03/iframed-editor-changes-in-wordpress-7-1/

WordPress 7.1 always uses an iframe for the post editor canvas. Intertexere's 0.3 integration therefore uses editor data stores and native SlotFill components and does not query or manipulate the canvas DOM.

## Block Editor

- Enqueueing assets in the editor: https://developer.wordpress.org/block-editor/how-to-guides/enqueueing-assets-in-the-editor/
- `registerPlugin`: https://developer.wordpress.org/block-editor/reference-guides/packages/packages-plugins/
- `PluginSidebar`: https://developer.wordpress.org/block-editor/reference-guides/slotfills/plugin-sidebar/
- `core/editor` data: https://developer.wordpress.org/block-editor/reference-guides/data/data-core-editor/
- `core/block-editor` data: https://developer.wordpress.org/block-editor/reference-guides/data/data-core-block-editor/
- `@wordpress/scripts`: https://developer.wordpress.org/block-editor/reference-guides/packages/packages-scripts/
- `@wordpress/e2e-test-utils-playwright`: https://developer.wordpress.org/block-editor/reference-guides/packages/packages-e2e-test-utils-playwright/
- `@wordpress/env`: https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/
- Nested blocks and `InnerBlocks`: https://developer.wordpress.org/block-editor/how-to-guides/block-tutorial/nested-blocks-inner-blocks/
- Static and dynamic block rendering: https://developer.wordpress.org/block-editor/getting-started/fundamentals/static-dynamic-rendering/
- Core Group block: https://developer.wordpress.org/block-editor/reference-guides/core-blocks/core-blocks-design/core-block-group/
- Core Query block: https://developer.wordpress.org/block-editor/reference-guides/core-blocks/core-blocks-theme/core-block-query/

Editor UI scripts belong on `enqueue_block_editor_assets`. Current unsaved post attributes come from `core/editor`, and the ordered block tree and client IDs come from `core/block-editor`. `InnerBlocks` alone does not establish that a parent is safe for literal analysis: custom blocks can nest arbitrary children, and dynamic or controller blocks can expose editor descendants whose front-end identity comes from another query or entity. The WordPress 7.1 implementation therefore traverses only the reviewed Core structural allowlist recorded in `EDITOR-SUGGESTIONS.md`; synced blocks, template parts, navigation, Query, unknown Core blocks, and custom parents remain opaque.

The 0.3 implementation pins the current WordPress build and Playwright tooling, builds a dependency manifest for Core-provided packages, and verifies the integration against an actual WordPress 7.1 iframe editor. The production code uses editor data stores and native SlotFill APIs only. It does not query the canvas DOM or manipulate the iframe.

## REST dispatch ordering

- `WP_REST_Server::dispatch()`: https://developer.wordpress.org/reference/classes/wp_rest_server/dispatch/
- `rest_pre_dispatch`: https://developer.wordpress.org/reference/hooks/rest_pre_dispatch/
- `WP_REST_Request::has_valid_params()`: https://developer.wordpress.org/reference/classes/wp_rest_request/has_valid_params/

WordPress 7.1 applies `rest_pre_dispatch` before route matching and before `has_valid_params()`. `has_valid_params()` begins by calling `parse_json_params()`, so a raw encoded-body limit placed only in a route permission callback is too late to prevent JSON parsing. Intertexere registers its exact-method-and-route transport guard at `rest_pre_dispatch` priority 5. An oversized malformed request returning Intertexere's 413 response instead of Core's `rest_invalid_json` response is the integration proof that the guard ran before JSON validation.

## Abilities API

- Abilities API handbook: https://developer.wordpress.org/apis/abilities-api/
- Abilities PHP reference: https://developer.wordpress.org/apis/abilities-api/php-reference/
- Abilities REST endpoints: https://developer.wordpress.org/apis/abilities-api/rest-api-endpoints/
- Abilities API improvements in WordPress 7.1: https://make.wordpress.org/core/2026/07/31/abilities-api-improvements-in-wordpress-7-1/
- JSON Schema preparation for client compatibility in WordPress 7.1: https://make.wordpress.org/core/2026/07/31/json-schema-preparation-for-client-compatibility-in-wordpress-7-1/
- Client-side Abilities API: https://make.wordpress.org/core/2026/03/24/client-side-abilities-api-in-wordpress-7-0/
- Public exposure flag for abilities in WordPress 7.1: https://make.wordpress.org/core/2026/08/04/a-unified-public-exposure-flag-for-abilities-in-wordpress-7-1/

The WordPress 7.1 Abilities REST controller requires a read-only ability to execute with GET and puts input in a URL-encoded query parameter. The 0.3 unsaved-draft payload is therefore sent to a narrow authenticated custom REST POST route backed by a reusable read-only PHP service. Intertexere will not mark a read-only analysis as mutating merely to force Ability execution through POST. A later Ability wrapper requires a fresh API review.

## AI Client

- Introducing the AI Client in WordPress 7.0: https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/
- What's coming to the AI Client in WordPress 7.1: https://make.wordpress.org/ai/2026/06/19/whats-coming-to-the-ai-client-in-wp-7-1/
- `WP_AI_Client_Prompt_Builder`: https://developer.wordpress.org/reference/classes/wp_ai_client_prompt_builder/
- AI Client builder error mapping: https://developer.wordpress.org/reference/classes/wp_ai_client_prompt_builder/exception_to_wp_error/
- WordPress 7.1 AI Client bootstrap source: https://github.com/WordPress/wordpress-develop/blob/7.1/src/wp-includes/ai-client.php
- WordPress 7.1 prompt builder source: https://github.com/WordPress/wordpress-develop/blob/7.1/src/wp-includes/ai-client/class-wp-ai-client-prompt-builder.php

`wp_ai_client_prompt()` returns a WordPress prompt builder backed by the AI Client default provider registry. For 0.4, the relevant verified methods are `using_system_instruction()`, `using_max_tokens()`, `using_request_options()`, `as_json_response()`, `is_supported_for_text_generation()`, and `generate_text_result()`.

The builder defaults to a 30-second request timeout and accepts per-prompt request options. The 0.4 plan sets a 20-second provider timeout for the explicit interactive action. WordPress maps prevention, invalid arguments, token limits, network failures, client errors including HTTP 429, and upstream errors to `WP_Error`; Intertexere uses safe product-level failure states and does not depend on provider-specific messages.

Actual WordPress 7.1 runtime verification with no configured AI provider returned `wp_supports_ai() === true` while `is_supported_for_text_generation() === false`, and generation failed because no model supported text generation for the prompt. With `WP_AI_SUPPORT` disabled, `wp_supports_ai()` and builder support were false and generation returned `prompt_prevented`. Therefore `wp_supports_ai()` is a preliminary environment check, not configured-model detection. The fully constructed builder support check is authoritative immediately before invocation.

WordPress 7.1 does not expose a natural streaming path used by this milestone. 0.4 uses one bounded non-streaming structured response. Embeddings did not land as part of the WordPress 7.1 AI contract and are outside the milestone.

## Connectors API

- Introducing the Connectors API in WordPress 7.0: https://make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/
- WordPress 7.1 connector registry source: https://github.com/WordPress/wordpress-develop/blob/7.1/src/wp-includes/class-wp-connector-registry.php

WordPress Connectors provides Settings > Connectors, provider registration, credential fields, and connector discovery functions. The WordPress AI Client default registry discovers compatible provider plugins through that infrastructure. A registered connector is not proof that credentials are valid or that a compatible model is configured.

Connector credential resolution supports WordPress-owned environment variable, PHP constant, and database sources. Database credentials are masked in the WordPress 7.1 settings UI but are not encrypted at rest. Intertexere does not store, mirror, log, or expose provider credentials and does not add provider-specific key controls.

### 0.4 Abilities decision

The 0.4 enhancement operation is not registered as an Ability, and the model is not given `using_abilities()` tools. The editor payload contains unsaved draft content, so the WordPress 7.1 read-only Ability GET transport remains unsuitable. The constrained evaluation also needs no tools. Avoiding model-accessible Abilities reduces prompt-injection and unintended side-effect surface while preserving the reusable PHP service boundary.

## Internal Link Graph

- HTML Tag Processor: https://developer.wordpress.org/reference/classes/wp_html_tag_processor/
- `WP_HTML_Tag_Processor::next_tag()`: https://developer.wordpress.org/reference/classes/wp_html_tag_processor/next_tag/
- `WP_HTML_Tag_Processor::get_attribute()`: https://developer.wordpress.org/reference/classes/wp_html_tag_processor/get_attribute/
- `url_to_postid()`: https://developer.wordpress.org/reference/functions/url_to_postid/
- `wp_old_slug_redirect()`: https://developer.wordpress.org/reference/functions/wp_old_slug_redirect/
- `_find_post_by_old_slug()`: https://developer.wordpress.org/reference/functions/_find_post_by_old_slug/

In WordPress 7.1, `WP_HTML_Tag_Processor::get_attribute()` returns the value produced by Core's decoded-attribute path, including one character-reference decoding pass. Intertexere passes that value directly into URL resolution and does not entity-decode it again. Integration coverage verifies the complete saved-content-to-edge path, including deliberately double-escaped character-reference text.

The 0.2 implementation also verifies query-style post IDs, current permalinks, relative-reference normalization, fragments, query preservation, and old-slug behavior in the WordPress 7.1 integration matrix. Old-slug resolution is accepted only for one exact non-hierarchical path candidate. Ambiguous or unsupported obsolete URLs remain unresolved.

## Development Rule

Before implementation depends on a current WordPress API detail, re-check the current official documentation and actual API behavior. Intertexere should not preserve a stale assumption merely because it appears in an older planning document.
