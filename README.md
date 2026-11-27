# Maho_Translations

Standalone distribution of the Maho translations admin module. The current module version is `0.3.0`; the `Unreleased` section of the changelog lists the maintenance fixes included in this snapshot.

The repository mirrors the paths the module owns in a Maho installation:

```text
app/code/local/Maho/Translations/
app/etc/modules/Maho_Translations.xml
app/design/adminhtml/default/default/layout/maho_translations.xml
app/design/adminhtml/default/default/template/translations/
app/locale/en_US/Maho_Translations.csv
app/locale/zh_CN/Maho_Translations.csv
lib/MahoCLI/Commands/TranslationsScan.php
tests/Backend/Unit/Translations/
tests/Backend/Integration/Translations/
```

This repository is the standalone distribution. The module is developed in a private Maho workspace and released here as versioned snapshots.

## Installation

Copy `app/` and `lib/` into the root of a Maho installation. `tests/` is optional and needs the Maho test harness.

```bash
composer dump-autoload
./maho migrate
./maho cache:flush
```

Then open `System > Translations` and run a scan. AI drafts also require a configured AI provider; the scan, grid, editing, CSV and placeholder validation features do not call AI.

## Documentation

- `app/code/local/Maho/Translations/README.md`: module guide, usage and limitations.
- `app/code/local/Maho/Translations/CHANGELOG.md`: release notes.
- `app/code/local/Maho/Translations/docs/`: PRD, architecture, API, ADRs and specs.

## License

PHP code is licensed under OSL-3.0. Templates and XML are licensed under AFL-3.0. See `LICENSE.txt`, `LICENSE_AFL.txt` and the SPDX headers in each file.
