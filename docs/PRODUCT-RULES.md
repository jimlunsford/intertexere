# Intertexere Product Rules

## Purpose

These rules define the durable product boundaries for Intertexere.

They exist so future feature work, AI-assisted development, refactors, and editor improvements do not accidentally change the product into something different.

## 1. Product Identity

Intertexere is a contextual internal-linking assistant for WordPress.

Its primary job is to help a site owner or editor discover useful internal links, understand the site's internal-link structure, and insert approved links efficiently while writing or maintaining content.

## 2. Editorial Control Is Required

Intertexere is advisory by default.

The plugin may analyze, rank, explain, and recommend links automatically.

It must not silently alter article content.

A content-changing action requires an explicit user decision unless a future product decision intentionally introduces a separate bulk or automatic mode.

## 3. No Forced Link Quotas

Intertexere must not treat a target number of links as proof that an article is optimized.

The system must be allowed to determine that:

- no useful internal link exists
- one link is enough
- a candidate is technically related but contextually weak
- adding another link would create clutter or duplication

Quality of relationship matters more than a green score or arbitrary count.

## 4. Links Must Remain Native WordPress Content

A link inserted by Intertexere should remain a normal WordPress link after the plugin is disabled or removed.

Do not require:

- proprietary shortcodes
- runtime redirects
- special front-end JavaScript
- custom rendering services
- plugin-specific blocks merely to preserve the link

Intertexere may use editor UI of its own, but the saved link should remain ordinary content whenever possible.

## 5. WordPress Is the Content Source of Truth

Intertexere may index content, but it does not become the canonical content store.

If Intertexere's derived index and graph are destroyed, they should be rebuildable from WordPress.

Do not create parallel editable copies of posts that can drift from WordPress content.

## 6. AI Is a Tool, Not the Authority

AI may help determine semantic relevance, anchor quality, contextual fit, and ranking.

AI output does not override:

- WordPress permissions
- canonical URLs
- current post content
- target eligibility
- deterministic duplicate checks
- user exclusions
- explicit editor decisions

AI output must be validated before it can affect state.

## 7. Provider Independence

Intertexere must not be designed as an OpenAI-only plugin.

Use the WordPress AI Client and Connectors infrastructure when appropriate so supported providers can be selected through WordPress rather than hard-coded into Intertexere.

Provider-specific enhancements are acceptable only when the core product still has a provider-independent path.

## 8. Failure Must Be Graceful

If AI is unavailable, credentials are missing, an external provider errors, or the index is rebuilding:

- WordPress editing must still work
- publishing must still work
- existing links must still work
- the site front end must still work

Intertexere should fail as an unavailable assistant, not as a broken editor.

## 9. Local Work Before External Work

Use deterministic local processing before AI whenever it improves speed, privacy, cost, or reliability.

Examples include:

- discovering eligible posts
- parsing current internal links
- determining whether a destination is already linked
- filtering excluded content
- title and taxonomy matching
- graph lookups
- narrowing candidate sets

AI should perform the judgment that benefits from AI, not work WordPress can perform more reliably itself.

## 10. Minimize Content Sent Externally

Do not send the full site archive to an external model when a smaller candidate set will solve the problem.

External prompts should contain only the content and metadata reasonably needed for the requested analysis.

## 11. Suggestions Need a Reason

A useful suggestion should explain enough for the editor to judge it.

Where practical, show:

- destination
- proposed anchor or location
- why the destination is relevant

Do not hide the recommendation behind an unexplained score.

## 12. The Editor Decides the Anchor

Intertexere may recommend anchor text.

The initial product should prefer existing words already present in the draft rather than rewriting prose to create idealized SEO anchors.

Do not turn natural writing into keyword-shaped copy merely to make an internal link easier to insert.

## 13. Existing Content Must Not Be Damaged by Analysis

Indexing, graph rebuilding, audits, candidate generation, and AI analysis are read-only with respect to post content.

A background job must never rewrite posts merely because it discovered a link opportunity.

## 14. Site-Level Maintenance Is a Separate Surface

Intertexere may eventually provide site-level tools for:

- orphaned content
- weak inbound-link coverage
- weak outbound-link coverage
- stale destinations
- broken internal links
- opportunities for older posts to link to newer posts

These tools should reuse the same content and graph foundation rather than creating a separate analysis system.

## 15. No SEO Theater

Intertexere should not manufacture urgency, warnings, red scores, or optimization grades simply to create engagement.

A warning should represent a real condition.

An opportunity should represent a plausible improvement.

A site does not become unhealthy merely because every article does not satisfy a plugin-defined link count.

## 16. Suggestions Should Respect Site Intent

Future settings may allow an owner to:

- exclude post types
- exclude specific posts or pages
- prioritize cornerstone content
- suppress individual destinations
- dismiss specific suggestions
- define content that should not be used as link targets

Intertexere should respect these decisions consistently across editor and maintenance workflows.

## 17. Accessibility and WordPress-Native UX Matter

Intertexere should feel like it belongs inside WordPress.

Use WordPress editor and admin patterns rather than building an isolated custom application inside wp-admin.

Controls must remain keyboard usable, screen-reader understandable, and clear without relying only on color.

## 18. Performance Is a Product Requirement

Intertexere must not make normal editing feel slow simply because a site has a large archive.

Expensive indexing and graph work should be incremental or background-capable where appropriate.

Draft analysis should work against a constrained candidate set.

Do not repeatedly parse the entire site on every editor request.

## 19. A Rebuild Must Be Safe

The user must be able to rebuild Intertexere's derived index and graph without modifying article content.

An interrupted rebuild should be recoverable.

## 20. Public Product Decisions Must Be Deliberate

The following would require an explicit product decision rather than being introduced incidentally:

- fully automatic link insertion
- automatic rewriting of prose to create anchors
- external indexing as the only source of analysis
- mandatory dependence on one AI provider
- proprietary saved link markup
- automatic published-content mutation during background jobs
- paid-service dependency for basic plugin operation
- cloud-hosted Intertexere account requirements
- broad site-wide bulk changes without review

## Practical Rule

When uncertain, prefer the design that gives the editor useful intelligence while leaving WordPress content portable, understandable, and under the editor's control.
