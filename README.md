# Intertexere

**Contextual internal linking assistant for WordPress.**

Intertexere helps WordPress editors discover useful internal-link opportunities while writing, understand the site's existing internal-link graph, and insert approved links without surrendering editorial control.

The project is currently in private initial development.

## Core Principles

- WordPress remains the source of truth for site content.
- Intertexere stores only rebuildable analysis data and intentional plugin preferences.
- AI suggestions are advisory.
- Post content is not changed without explicit editor approval.
- Inserted links remain normal WordPress links.
- AI provider support should use WordPress-native provider-agnostic infrastructure where practical.
- AI or indexing failures must not break normal WordPress editing or publishing.
- Useful contextual relationships matter more than arbitrary internal-link quotas.

## Initial Platform Baseline

- WordPress 7.1+
- Block Editor
- WordPress Abilities API
- WordPress AI Client
- WordPress Connectors API

Specific API usage is verified against the pinned WordPress 7.1 runtime and Core source before production use.

## Development Status

Milestones 0.1 through 0.4 are complete. Version 0.4.0 adds an optional, explicitly invoked, WordPress-native AI overlay while retaining deterministic 0.3 suggestions as the authority. Milestone 0.5, explicit link insertion, is fully planned and not implemented.

The initial roadmap is:

1. plugin foundation and content indexing
2. internal-link graph
3. read-only editor suggestions
4. WordPress-native AI integration
5. explicit link insertion
6. site-level internal-link auditing

## Project Documentation

- [Architecture](docs/ARCHITECTURE.md)
- [Product Rules](docs/PRODUCT-RULES.md)
- [Development Rules](docs/DEVELOPMENT.md)
- [Roadmap](docs/ROADMAP.md)
- [Internal Link Graph design](docs/INTERNAL-LINK-GRAPH.md)
- [Read-Only Editor Suggestions design](docs/EDITOR-SUGGESTIONS.md)
- [WordPress-Native AI Integration](docs/AI-INTEGRATION.md)
- [Explicit Link Insertion plan](docs/LINK-INSERTION.md)
- [0.1 Acceptance Criteria](docs/ACCEPTANCE-0.1.md)
- [0.2 Acceptance Criteria](docs/ACCEPTANCE-0.2.md)
- [0.3 Acceptance Criteria](docs/ACCEPTANCE-0.3.md)
- [0.4 Acceptance Criteria](docs/ACCEPTANCE-0.4.md)
- [0.5 Acceptance Criteria](docs/ACCEPTANCE-0.5.md)

## Name

*Intertexere* is Latin for interweaving or intertwining, reflecting the plugin's purpose of connecting related content across a WordPress site.
