# Changelog

All notable changes to `Maho_Translations` are recorded here.

## Unreleased

### Added

- Added a Simplified Chinese language pack for the translations admin interface.

### Fixed

- Translation grid pagination now reloads the full admin page instead of the unstyled grid endpoint.
- The translation scan now keeps the current locale, store, status, module, area and search filters after it completes.

## 0.3.0（2026-11-22）

### Added

- Long source strings and long translations stored in `translation_long`, with mutual exclusion from `core_translate`.
- `core_resource/translate` resource merge so long translations load at runtime and are included in the translation cache.
- Long text support in the translation grid, edit page, CSV import/export, override export and AI drafts.
- `Long` status filter for sources longer than 255 characters.
- Regression tests for long storage, runtime merge, CSV round trip, grid status and AI long drafts.
- Module tests: 82 passing tests, 339 assertions on SQLite, MySQL 8.0 and PostgreSQL 18.7.

### Fixed

- Clear Override now submits a POST through the edit form instead of a GET to the delete route.

### Changed

- `Maho_Translations` now requires version `0.3.0`.

## 0.2.0（2026-11-22）

### Added

- AI-assisted translation drafts using `Maho_Ai`, with sync generation for up to three strings and queued generation for larger batches.
- AI Draft column, Review action, Generate missing button, and batch approve/reject actions in the translation grid.
- AI Draft tab on the translation edit page for editing, approving and rejecting drafts.
- `translations_ai_reconcile` cron that applies completed AI task responses and marks failed drafts.
- Regression tests for draft generation, approval, rejection, validation, batch review and async reconciliation.
- Module tests: 73 passing tests, 291 assertions on SQLite, MySQL 8.0 and PostgreSQL 18.7.

### Changed

- `Maho_Translations` now requires `Maho_Ai` and version `0.2.0`.

## 0.1.1（2026-11-22）

### Changed

- Split `Maho_Translations_Model_Scanner::scan()` into smaller methods for scan setup, index writing, row building and failure handling. Behavior is unchanged.
- Added regression tests for stale scan recovery and failed scan rollback.

## 0.1.0（2026-11-22）

### Added

- Initial PRD, architecture, ADRs and feature spec for the admin translations manager.
- Source scanner that indexes language pack CSV files and PHP/PHTML/XML translation calls.
- Admin translation grid with locale, store, status, module, area and text filters.
- Translation editing through `core_translate`, including store overrides, clearing overrides and translation cache invalidation.
- Left-side Translation, Context and Usage tabs on the translation edit page, with all tab fields submitting through one form.
- Strict placeholder validation for printf tokens, template directives and HTML tags.
- CSV import and export for effective, missing and override translations.
- Missing and unused translation views.
- System > Translations menu and per-action ACL resources.
- `translations:scan` CLI command with `--details`.
- Module tests: 59 passing tests, 201 assertions.
- SQLite, MySQL 8.0 and PostgreSQL 18.7 verification, including admin grid rendering, edit tabs and ACL checks.

### Fixed

- Escaped source strings in the edit page header to remove a stored XSS risk.
- Stopped the scanner from indexing comments, interpolated strings and concatenated strings.
- Stripped UTF-8 BOM from language pack CSV files before indexing.
- Moved translation validation into the model layer so CLI and future callers cannot bypass it.
- Added an export form so locale, store and export scope are selectable from the admin.
- Isolated integration tests to the `zz_ZZ` test locale.
- Passed the current locale and store to the import and scan flows.
- Widened the source module list column to TEXT.
- Fell back to the source when a language pack translation is empty.
- Preserved submitted translations after validation errors.
- Sanitized formula prefixes in exported CSV and restored them on import.
- Marked conflicting language pack translations in the grid and edit form.
- Validated the uploaded CSV MIME type and actual file size.
- Kept the first seen timestamp across rescans.
- Hid scan, import, export, save and clear buttons from users without the matching permission.
- Fixed the empty-state notice after a failed scan when an index already exists.
