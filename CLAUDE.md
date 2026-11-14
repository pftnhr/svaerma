# CLAUDE.md

## Coding-Regeln (immer einbeziehen)

Für dieses Projekt gelten die Coding-Regeln aus dem Repo [tgigold/coding-regeln](https://github.com/tgigold/coding-regeln), lokal unter `~/Websites/coding_regeln/`. Lies vor der Arbeit die passende Themen-Datei aus dem Wegweiser in deren `README.md`.

- Ist die lokale Kopie nicht vorhanden, lies die Regeln aus dem GitHub-Repo.
- Widersprechen sich Regeln, README und Code, benenne den Widerspruch, statt still eine Seite zu wählen.

## Arbeitsweise in diesem Repo

- Jede Änderung bekommt im selben Commit einen Eintrag in `CHANGELOG.md` unter „Unreleased“.
- Bereits ausgerollte Migrationen nie ändern, immer eine neue anlegen.
- Tests gehören zu jedem Feature: PHPUnit (`vendor/bin/phpunit`), PHPStan (`vendor/bin/phpstan analyse`), bei JS-Änderungen Browser-Tests (`npm run e2e`).
