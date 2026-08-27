# Intertexere 0.1 Acceptance Criteria

## Milestone

Foundation and Content Index

## Goal

Establish a safe WordPress plugin foundation and a rebuildable local understanding of eligible site content without changing existing article content or making normal WordPress editing depend on Intertexere.

## Required Scope

- plugin bootstrap
- WordPress 7.1+ compatibility handling
- activation and deactivation behavior
- capability and settings foundation
- eligible post type and post status rules
- derived content-index schema
- initial full index build
- incremental index updates when content changes
- post deletion and unpublish handling
- safe full reindex/rebuild operation
- basic diagnostics for index state
- automated tests for the indexing lifecycle

## Acceptance Criteria

### Normal WordPress behavior

- Installing but not activating Intertexere changes nothing about existing site behavior.
- Activating Intertexere does not rewrite existing posts or pages.
- Deactivating Intertexere does not affect existing article content or links.
- Normal WordPress editing and publishing work even if Intertexere has no AI provider configured.

### Compatibility

- The plugin handles its WordPress 7.1+ minimum version deliberately and fails clearly on unsupported versions.
- The plugin does not depend on experimental Gutenberg-only APIs when a stable WordPress Core API is available.

### Index source of truth

- WordPress post content remains authoritative.
- Intertexere's index contains only derived analysis data and identifiers needed to trace that data back to WordPress.
- Clearing the derived index does not delete or modify WordPress posts.

### Eligible content

- Eligibility rules are explicit and testable.
- Draft/private/unpublished behavior is deliberate rather than accidental.
- Unsupported or excluded post types are not silently indexed as normal link targets.

### Initial build

- A full index can be built from existing eligible WordPress content.
- Indexing does not modify the indexed post content.
- Re-running a full build produces a consistent current-state index rather than uncontrolled duplicates.

### Incremental updates

- Publishing eligible content adds or refreshes its derived record.
- Updating eligible content refreshes its derived record.
- Deleting content removes or invalidates its derived record appropriately.
- Changing a post out of an eligible state updates the index appropriately.
- Changing a post back into an eligible state makes it indexable again.

### Rebuild and failure safety

- A full rebuild can be requested deliberately.
- An interrupted or failed rebuild does not damage WordPress content.
- The system can recover to a valid index state after derived index data is cleared.
- Rebuild logic is safe to repeat.

### Security

- Administrative rebuild or settings actions require appropriate capabilities.
- State-changing requests use appropriate WordPress request protection.
- Database operations use WordPress-safe query patterns.
- Indexed content is treated as content data, not executable code.

### Performance direction

- The architecture does not require parsing the entire site on every editor request.
- Expensive full rebuild work is separated from ordinary page rendering and editing paths.
- The schema and update model leave room for incremental processing as the archive grows.

### Tests

At minimum, automated coverage should exercise:

- activation/bootstrap behavior
- initial index creation
- idempotent refresh behavior
- post update
- post deletion
- eligible-to-ineligible status transition
- ineligible-to-eligible status transition
- full rebuild
- derived-data reset and recovery
- permission failure for privileged actions

## Explicitly Out of Scope for 0.1

Do not add these merely because the foundation makes them possible:

- AI provider calls
- embeddings
- editor sidebar suggestions
- link insertion
- site-wide link recommendations
- link graph analysis
- automatic article rewriting
- automatic internal-link mutation

Those belong to later roadmap stages.

## Completion Standard

0.1 is complete when the plugin foundation and content index are reliable enough that the next stage can build the internal-link graph without changing the indexing contract underneath it.
