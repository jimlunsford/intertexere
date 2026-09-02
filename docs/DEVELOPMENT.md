# Intertexere Development Rules

## Purpose

This document defines how Intertexere should be developed, reviewed, tested, and released.

The goal is to keep the project easy to reason about as AI-assisted implementation becomes part of the workflow.

## 1. Repository Is the Implementation Source of Truth

The newest committed repository state is authoritative for current implementation behavior.

Old chats, exported ZIP files, screenshots, or historical plans are supporting evidence only.

Before making a code change, inspect the current repository state first.

## 2. Do Not Build From Old Copies

Do not modify an old downloaded ZIP or stale local copy when the repository contains newer work.

When working through ChatGPT Work, Codex, or another development environment, start from the current repository and current target branch.

## 3. Main Must Stay Known-Good

`main` should represent the current known-good project baseline.

Normal feature work should happen on a focused branch.

Example branch names:

- `feature/content-index`
- `feature/link-graph`
- `feature/editor-suggestions`
- `feature/ai-integration`
- `fix/duplicate-suggestions`
- `docs/architecture-update`

Avoid long-lived branches that accumulate unrelated changes.

## 4. One Focused Change at a Time

A development pass should have a clear reason for existing.

Do not combine unrelated architecture changes, UI redesigns, schema changes, and bug fixes into one review unless they genuinely depend on one another.

Small focused diffs are easier to test, review, revert, and understand.

## 5. Inspect Before Changing Architecture

Before changing any durable boundary in `docs/ARCHITECTURE.md` or `docs/PRODUCT-RULES.md`, identify the conflict explicitly.

If a feature requires changing a settled rule, make that a deliberate architecture decision rather than silently implementing around the document.

## 6. WordPress Compatibility

The initial baseline is WordPress 7.1+.

Before relying on a current or newly introduced WordPress API, verify the behavior against current official WordPress developer documentation and, when necessary, actual source or runtime behavior.

Do not assume an experimental Gutenberg API is a stable WordPress Core contract.

## 7. Security Is Part of Every Feature

Any privileged or state-changing path should be reviewed for:

- capability checks
- nonce or equivalent request protection
- REST or Abilities permission callbacks
- input validation
- sanitization
- output escaping
- SQL preparation
- internal URL validation
- post eligibility
- AI output validation

Do not trust IDs, URLs, HTML, anchors, block selectors, or other state merely because an AI model returned them.

## 8. AI-Assisted Development Must Still Be Verified

AI-generated implementation is not accepted because it looks plausible.

For every meaningful change:

1. inspect the diff
2. verify it matches the requirement
3. run relevant automated checks
4. test the user-visible behavior
5. test likely failure paths
6. check for regressions in existing behavior

The quality standard is the verified result, not who typed the code.

## 9. Tests Follow Risk

Testing depth should match the risk of the change.

At minimum, relevant development should include appropriate combinations of:

- PHP syntax checks
- JavaScript syntax/build checks
- WordPress coding or static-analysis checks when configured
- unit tests
- integration tests
- REST/Abilities permission tests
- database migration tests
- editor interaction tests
- indexing/rebuild tests
- duplicate-link tests
- failure-path tests for unavailable AI providers
- manual Block Editor verification

A passing syntax check does not prove the feature works.

### 0.3 JavaScript and editor test baseline

Milestone 0.3 pins Node.js 22.13.0 and all WordPress, Jest, and Playwright development packages through `package-lock.json`. Use:

- `npm ci` for a reproducible dependency install;
- `npm run lint:js` for WordPress JavaScript lint rules;
- `npm run test:js` for Jest unit and component tests;
- `npm run build` for the committed production editor bundle;
- `npm run test:e2e` for the actual WordPress 7.1 Block Editor Playwright suite.

The PHP integration matrix remains WordPress 7.1 with PHP 7.4, 8.1, and 8.3. The browser suite uses `@wordpress/env` with WordPress 7.1 and Chromium. A mocked DOM test is not a substitute for the browser job.

### 0.4 AI test boundary

Milestone 0.4 production code must call the WordPress AI Client through a narrow injectable adapter. Unit, integration, and browser tests use a deterministic fake adapter and must not require a live provider, paid network call, connector credential, or GitHub Actions secret.

Tests must keep provider latency separate from local preparation and validation measurements. They must exercise unavailable, unconfigured, timeout, rate-limit, malformed-output, stale-request, prompt-injection, privacy, and no-mutation paths while retaining the complete 0.1 through 0.3 regression suites.

No implementation test may weaken the product contract by bypassing server-side deterministic candidate validation. A fake provider simulates only the WordPress AI Client boundary; it does not replace the current draft analyzer, candidate authority, target validation, or editor request-ownership behavior.

## 10. Content Mutation Requires Stronger Testing

Any change that can modify post content must test at least:

- expected insertion
- no insertion without explicit approval
- stale draft handling
- changed or missing proposed anchor
- duplicate destination behavior
- undo behavior where applicable
- permissions
- malformed AI response
- missing target post
- deactivated-plugin behavior after insertion

## 11. Derived Data Must Be Rebuildable

Any index or link-graph schema should be tested for:

- initial build
- incremental update
- post update
- post deletion
- URL or slug change
- post status change
- full rebuild
- interrupted rebuild
- recovery after derived data is cleared

## 12. Database Changes Need Explicit Migration Handling

Do not change persistent schema casually.

When a release requires a schema change:

- version it deliberately
- make the upgrade path repeatable and safe
- avoid destructive migration unless clearly necessary
- test upgrade from the prior supported schema
- document the migration

## 13. Do Not Couple Normal WordPress Editing to AI Availability

Testing must confirm that WordPress can still edit and publish normally when:

- no AI provider is configured
- credentials are invalid
- the provider times out
- the provider rate-limits
- the provider returns malformed output
- Intertexere indexing is incomplete

## 14. Privacy Review for Prompt Changes

Any change that increases the amount of content sent externally should be reviewed intentionally.

Before expanding a prompt payload, ask:

- Is the additional content necessary?
- Can local candidate reduction solve part of the problem?
- Can a smaller excerpt work?
- Does the user understand which external provider receives the content?

## 15. Documentation Moves With Behavior

Update documentation in the same development pass when behavior changes a documented contract.

Do not leave architecture or product rules knowingly stale after a deliberate change.

## 16. Commit Messages

Use short, descriptive commit messages that state what changed.

Examples:

- `Add content index schema`
- `Track internal link edges`
- `Add draft suggestion sidebar`
- `Validate AI link targets before insertion`

Avoid meaningless messages such as `update`, `changes`, or `fix stuff`.

## 17. Pull Request Review

A meaningful pull request should explain:

- what problem it solves
- what changed
- what was intentionally not changed
- what tests were run
- any migration or compatibility impact
- known limitations or follow-up work

Before merging, review the diff against the requirement, architecture, and product rules.

## 18. Releases Must Be Reproducible

A public WordPress plugin ZIP should come from a known repository commit or tag.

Do not manually edit a release ZIP after it has been generated.

If a packaged release is wrong, fix the repository and generate another release.

## 19. Versioning

Until a separate release policy is adopted, use semantic versioning in the form:

`MAJOR.MINOR.PATCH`

During initial private development, the project may remain in the `0.x` line.

Do not reuse an already distributed version number for materially different code.

## 20. Current Workflow

The preferred project workflow is:

1. define or refine the requirement
2. inspect the current repository
3. create a focused branch
4. implement the change
5. run automated checks
6. test actual WordPress behavior
7. inspect the diff
8. open/review the pull request
9. merge only when the branch is a stronger baseline
10. tag/package deliberate releases

## Default Principle

Build quickly, but keep the repository understandable enough that any future development session can reconstruct what Intertexere is, why it works that way, and what changed without depending on an old chat transcript.
