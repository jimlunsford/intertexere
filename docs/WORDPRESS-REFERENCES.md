# WordPress Reference Notes

## Purpose

This file records the official WordPress developer references that informed Intertexere's initial architecture.

These links are references, not substitutes for verifying current runtime behavior during implementation.

## WordPress 7.1

- WordPress 7.1 Field Guide: https://make.wordpress.org/core/2026/08/05/wordpress-7-1-field-guide/
- WordPress 7.1 roadmap: https://make.wordpress.org/core/2026/06/19/roadmap-to-7-1/

## Abilities API

- Abilities API improvements in WordPress 7.1: https://make.wordpress.org/core/2026/07/31/abilities-api-improvements-in-wordpress-7-1/
- JSON Schema preparation for client compatibility in WordPress 7.1: https://make.wordpress.org/core/2026/07/31/json-schema-preparation-for-client-compatibility-in-wordpress-7-1/

## AI Client

- Introducing the AI Client in WordPress 7.0: https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/
- What's coming to the AI Client in WordPress 7.1: https://make.wordpress.org/ai/2026/06/19/whats-coming-to-the-ai-client-in-wp-7-1/

## Connectors API

- Introducing the Connectors API in WordPress 7.0: https://make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/

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
