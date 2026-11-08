# CLAUDE.md

## CodingRegeln (immer einbeziehen)

Lies zu Beginn jeder Arbeit an diesem Projekt die CodingRegeln und halte dich daran:
https://recorder.gigold.me/wiki/CodingRegeln

- Ist die Seite nicht erreichbar, weise **deutlich** darauf hin (nicht stillschweigend weiterarbeiten) und arbeite ersatzweise nach README (Abschnitte „Sicherheit“ und „Ein neues Feature hinzufügen“) und CHANGELOG.
- Widersprechen sich Wiki, README und Code, benenne den Widerspruch, statt still eine Seite zu wählen.

## Arbeitsweise in diesem Repo

- Jede Änderung bekommt im selben Commit einen Eintrag in `CHANGELOG.md` unter „Unreleased“.
- Bereits ausgerollte Migrationen nie ändern, immer eine neue anlegen.
- Tests gehören zu jedem Feature: PHPUnit (`vendor/bin/phpunit`), PHPStan (`vendor/bin/phpstan analyse`), bei JS-Änderungen Browser-Tests (`npm run e2e`).
