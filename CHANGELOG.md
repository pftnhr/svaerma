# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden hier dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
und das Projekt folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

### Hinzugefügt
- **Browser-Tests** mit Playwright (`e2e/`, `npm run e2e`) für das JavaScript: Einchecken mit Standort, Umkreis erweitern, Suche, Fehlermeldungen, eigener Ort, verweigerter Standort, Tooltip-Escaping, Karte, Löschen-Dialog, Service Worker offline, Token-Feld. Jeder Test prüft zusätzlich, dass keine CSP-Verstöße auftreten. Läuft in der CI als eigener Job, der Deploy wartet darauf.
- Umgebungsvariable `APP_CONFIG` für einen abweichenden Pfad zur Config (genutzt von den Browser-Tests).

## [0.8.0] - 2026-11-07

Sicherheits- und Korrektheitsupdate. Danke an [@5minpause](https://github.com/5minpause) für das ausführliche Review ([#12](https://github.com/tgigold/svaerma/issues/12)).

**Nach dem Update** müssen sich alle Geräte einmal neu anmelden.

### Sicherheit
- Sessions werden nur noch angelegt, wenn eine Seite sie braucht. PHP räumt `data/sessions` selbst auf, auch wenn der Hoster die Session-GC abgeschaltet hat.
- CI: Actions auf Commit-SHAs gepinnt, `permissions: contents: read`, `composer.lock` eingecheckt.
- HTTP-Client: Weiterleitungen nur per HTTPS, höchstens drei, API-Keys gehen nie an einen anderen Host.
- Zusätzliche Overpass-Endpunkte dürfen nicht auf interne Adressen zeigen.
- `X-Forwarded-Proto`/`X-Forwarded-For` nur noch von vertrauenswürdigen Proxys (neue Option `trusted_proxies`, Standard: private Netze).
- HTTPS-Redirect auf die Domain aus `base_url` statt auf den `Host`-Header.
- CSRF-Token nur noch aus dem POST-Body.
- Session-Cookie mit `__Host-`-Präfix, Löschen mit denselben Cookie-Attributen.
- API: Gültige Tokens funktionieren auch während einer Sperre. Fehlversuche bei API und Login zählen getrennt. `places/nearby` ist pro Token begrenzt (30/min).
- Weitere Härtung (Frontend, Login-Sperre, Sitzungsverwaltung, Setup). Bootstrap, Bootstrap Icons und Leaflet werden jetzt selbst gehostet, die CSP erlaubt keine fremden Skripte mehr.

### Hinzugefügt
- *Einstellungen → Überall abmelden* beendet alle Sitzungen auf allen Geräten.

### Behoben
- API: Nicht-Text-Werte (z. B. Arrays) und ungültiges UTF-8 werden abgelehnt. JSON-Antworten scheitern nicht mehr an ungültigem UTF-8 in alten Daten.
- Zeitpunkte müssen zwischen 2000 und morgen liegen. Achtstellige Zahlen wie `20261029` werden nicht mehr als Unix-Zeit gelesen.
- Öffentlicher Modus: Die Verzögerung zählt ab dem späteren Zeitpunkt von Check-in und Eintrag, nachgetragene Check-ins sind also nicht sofort sichtbar.
- Swarm-Import: Einträge ohne ID bekommen eine stabile ID aus ihrem Inhalt statt `#1`, `#2` und kollidieren nicht mehr zwischen Dateien.
- Photon-Umkreissuche: Orte ohne OSM-Bezug fallen nicht mehr auf einen Treffer zusammen.
- Ortsliste auf die 100 nächsten Orte begrenzt, die Besuchsabfrage läuft in Blöcken.
- Bearbeiten ohne Zeitänderung behält die Sekunden.
- Verlauf: Gruppierung nach Tag folgt der Reihenfolge, auch bei Reisen über Zeitzonen.
- Overpass-Pause erst nach drei Komplettausfällen in fünf Minuten, ein einzelner Timeout sperrt nicht mehr alle.
- Gesamt-Zeitbudget von 25 s für die Umkreissuche inklusive aller Fallbacks.

## [0.7.0] - 2026-10-29

### Hinzugefügt
- **PWA**: Web-App-Manifest (`display: standalone`, Theme-Farbe, Icons in 180/192/512 px und SVG, maskable), Apple-Meta-Tags und Homescreen-Icon. svaerma lässt sich damit auf iPhone und Android als App installieren.
- **Minimaler Service Worker** (`/sw.js`, von PHP mit versioniertem Cache-Namen ausgeliefert): cacht nur die statische App-Hülle, zeigt ohne Netz eine Offline-Seite und räumt bei Updates alte Caches auf. Seiten mit persönlichen Daten, die API und Ortsabfragen werden nie gecacht.
- Tests für Service Worker (öffentlich erreichbar, versioniert), Manifest und Precache-Dateien.

## [0.6.0] - 2026-10-29

### Hinzugefügt
- **Swarm-Import** für die `checkins*.json` aus dem Foursquare-Datenexport: Upload mehrerer Dateien in den Einstellungen, Probelauf mit Bericht (importiert, schon vorhanden, übersprungen mit Grund), Deduplizierung über die Swarm-ID. Die Dateien werden nicht gespeichert. CLI-Pendant: `bin/import-swarm.php [--dry-run]`.
- Migration `004_venue_ref`: Die Foursquare-Venue-ID wird mitgespeichert, damit „Deine Besuche hier“ auch für importierte Orte ohne OSM-Bezug funktioniert.
- Zeitzonen mit festem Offset (z. B. `+05:30`) für Importe, die nur den UTC-Offset kennen. Passt der Offset zur Standard-Zeitzone, wird diese verwendet.
- **Export** aller Check-ins als JSON (`/export.json`) und GeoJSON (`/export.geojson`).
- Deutlich mehr deutsche Kategorienamen in der Ortsliste (z. B. Haltestelle, Herrenhaus, Fensterbau, Kulturzentrum, Ämter, Handwerk, Sport).
- Tests für Importer (Probelauf, Idempotenz, Feldzuordnung, Zeitzonen, Venue-Gruppierung, Share-Token, ungültige Dateien, Listen-Variante, Unix-Zeitstempel) und Export.

## [0.5.1] - 2026-10-29

### Hinzugefügt
- **overpass.ch** als bevorzugter Overpass-Endpunkt: Gehostetes Overpass mit weltweiten Daten, kostenlos bis 10.000 Abfragen pro Monat. Aktiv, sobald in den Einstellungen ein API-Key eingetragen ist, und wird dann als Erstes gefragt. Der Key geht im Header `X-API-Key` mit, nie in der URL, und landet so nicht in Logs oder Fehlermeldungen.
- Im Footer erscheint bei aktivem Key ein Credit für overpass.ch.

## [0.5.0] - 2026-10-29

### Hinzugefügt
- **REST-API** `/api/v1`: Check-ins lesen (Liste mit `limit`/`offset`, einzeln), Check-ins anlegen (JSON oder Formular, Einzelfelder oder Ort-Objekt) und Orte in der Nähe abfragen. Das Ergebnis enthält `choices` mit lesbaren Labels für „Aus Liste auswählen“ in iOS-Kurzbefehlen.
- **API-Tokens** (Migration `003_api_tokens`): in den Einstellungen erzeugen, einmalig anzeigen, widerrufen, mit Anzeige der letzten Nutzung. Gespeichert wird nur der SHA-256-Hash. Falsche Tokens zählen zum Login-Rate-Limit.
- Anleitung für einen iOS-Kurzbefehl „Einchecken“ im README.
- Kernel-Tests für die API: Token-Prüfung, Widerruf, Sperre, Anlegen, Lesen, Validierung, CSRF-Abgrenzung.

### Geändert
- `overpass.osm.jp` ist kein Standard-Fallback mehr, das TLS-Zertifikat des Servers ist abgelaufen. Weitere Endpunkte lassen sich in den Einstellungen eintragen.

## [0.4.1] - 2026-10-29

### Behoben
- Lieferte ein Fallback (z. B. Geoapify) eine leere Liste, wurde Photon gar nicht mehr gefragt. Fällt Overpass aus, werden jetzt **alle** Fallbacks abgefragt und ihre Ergebnisse zusammengeführt. Duplikate fliegen raus: gleiches OSM-Objekt, oder gleicher Name innerhalb von 40 m.
- Gäste landen bei aktivem öffentlichen Modus auf `/` jetzt im Verlauf statt auf der Login-Seite.

### Hinzugefügt
- Weitere Overpass-Endpunkte lassen sich in den Einstellungen eintragen (einer pro Zeile), ohne `config.php` per FTP anzufassen.
- Die OSM-Diagnose zeigt pro Dienst die Anzahl der gefundenen Orte (Testpunkt: Brandenburger Tor, 100 m).
- Neue Routen-Option `guest_redirect`.

## [0.4.0] - 2026-10-29

### Hinzugefügt
- **Öffentlicher Modus** (Einstellungen → Öffentlich, standardmäßig aus): Verlauf, Karte und Detailseiten lassen sich ohne Login zeigen. Einstellbar sind die Anzahl der neuesten Check-ins (oder alle, gilt für Liste und Karte), eine Verzögerung in Stunden (Standard: 2) sowie, ob Notizen und eigene Orte öffentlich sind. Gäste sehen keine Bearbeiten- oder Löschen-Buttons. Detailseiten außerhalb des öffentlichen Fensters liefern 404.
- **Geoapify** als optionaler Fallback für die Umkreissuche, aktiv nur mit API-Key (in den Einstellungen). Die Kette lautet jetzt Overpass → Geoapify → Photon.
- `overpass.osm.jp` als zusätzlicher Overpass-Endpunkt.
- Footer mit der von OpenStreetMap verlangten Attribution (© OpenStreetMap-Mitwirkende, ODbL) sowie Credits für Photon und gegebenenfalls Geoapify.
- Die OSM-Diagnose prüft auch Geoapify, sofern ein Key gesetzt ist.

### Geändert
- Die App heißt jetzt **svaerma** (Navigation, Seitentitel, User-Agent).
- Admin und Einstellungen sind eine Seite unter `/settings`. `/admin` leitet per 301 dorthin um, ebenso der Hinweis auf ausstehende Migrationen.

### Behoben
- Auf dem Desktop zeigten die Karten nur „403 Access blocked“. Grund war `Referrer-Policy: same-origin`: Die OSM-Kachelserver verlangen einen Referer und haben die Anfragen ohne ihn abgelehnt. Jetzt gilt `strict-origin-when-cross-origin`, mitgeschickt wird nur die Domain, kein Pfad.

## [0.3.0] - 2026-10-29

### Hinzugefügt
- **Verlauf** (`/checkins`): chronologische Liste, nach Tagen gruppiert (Ortszeit des Check-ins), 50 pro Seite.
- **Detailseite** (`/checkin/123-cafe-xyz`) mit Karte, Notiz, Link zu OpenStreetMap und allen Besuchen am selben Ort.
- **Saubere URLs**: Maßgeblich ist die ID. Ein falscher oder fehlender Slug leitet per 301 auf die korrekte URL um, auch nach einer Umbenennung.
- **Bearbeiten** von Name, Notiz, Zeitpunkt (Ortszeit) und Zeitzone. Ort und OSM-Verknüpfung bleiben erhalten.
- **Löschen** mit Rückfrage.
- **Karte** (`/map`) mit allen Check-ins (Leaflet per CDN mit SRI, Canvas-Renderer für große Datenmengen).
- Nach dem Einchecken landest du direkt auf der Detailseite.
- Tests für Pagination, Update, Löschen, Slug-Redirects, Check-in-Routen und deutsche Datumsformate.

### Geändert
- Asset-URLs tragen jetzt die App-Version (`?v=0.3.0`), damit Browser nach einem Update nicht mehr alte JS- und CSS-Dateien aus dem Cache verwenden.
- `overpass.private.coffee` ist kein Standard-Fallback mehr: Vom Hoster aus war es nicht erreichbar, und jeder Versuch kostete 12 s. Über `osm.overpass_fallback_urls` lässt es sich weiter eintragen.

## [0.2.2] - 2026-10-29

### Behoben
- Die Umkreissuche funktioniert jetzt auch, wenn Overpass vom Server aus nicht erreichbar ist (live beobachtet: sofortiges HTTP 502 bzw. Timeouts ohne Antwort). In dem Fall springt automatisch die Reverse-Suche von Photon ein. Die Liste ist dann evtl. weniger vollständig, ein Hinweis zeigt das an.
- Overpass wird jetzt per GET statt POST abgefragt, außerdem schickt curl kein `Expect: 100-continue` mehr mit. Beides bringt manche ausgehenden Proxys beim Hoster durcheinander.

### Hinzugefügt
- Ist Overpass komplett ausgefallen, wird es für 5 Minuten übersprungen, statt bei jeder Suche erneut auf den Timeout zu warten.
- Eigener, kürzerer Timeout für Overpass (`osm.overpass_timeout`, 12 s pro Endpunkt) sowie die Config-Option `osm.photon_reverse_url`.
- Fehlermeldungen von OSM-Diensten nennen jetzt die Ziel-IP und den Anfang der Fehlerseite. Daran erkennt man, ob ein Fehler vom Dienst selbst oder von einem Proxy unterwegs kommt.
- **OSM-Verbindung testen** prüft zusätzlich die Photon-Umkreissuche.

### Geändert
- Der allgemeine OSM-Timeout liegt wieder bei 15 s.

## [0.2.1] - 2026-10-29

### Behoben
- „Overpass ist gerade nicht erreichbar“ bei der Umkreissuche. Die Abfrage fragt jetzt nur noch benannte Objekte im Umkreis ab, statt 15 Teilabfragen zu stellen. Das Filtern nach Kategorien passiert in PHP, das ist für Overpass deutlich billiger und läuft seltener in Timeouts oder Rate-Limits.
- Overpass-Antworten mit Laufzeitfehler (HTTP 200 mit `remark`) werden als Fehler erkannt und nicht mehr als leeres Ergebnis gecacht.
- Eine leere Liste in `config.php` (z. B. `overpass_fallback_urls => []`) ersetzt jetzt den Default, statt mit ihm zusammengeführt zu werden.

### Hinzugefügt
- Fallback-Endpunkte für Overpass (`osm.overpass_fallback_urls`, Standard: `overpass.private.coffee`). Sie werden der Reihe nach versucht.
- Button **OSM-Verbindung testen** in `/admin`: prüft jeden Overpass-Endpunkt und die Ortssuche ohne Cache und zeigt Antwortzeit oder Fehlergrund.
- Der Fehlergrund (z. B. `HTTP 504`) erscheint jetzt auch in der Meldung auf der Check-in-Seite.

### Geändert
- Der HTTP-Timeout für OSM-Dienste liegt jetzt bei 25 s statt 15 s, das Overpass-Query-Timeout bei 20 s.

## [0.2.0] - 2026-10-29

### Hinzugefügt
- Check-in-Seite: Standort per Browser-Geolocation, Orte im Umkreis über Overpass (sortiert nach Entfernung), Umkreis erweitern, Namenssuche über Photon oder Nominatim, eigener Ort mit freiem Namen, optionale Notiz.
- „Zuletzt hier am …“ und Besuchsanzahl für OSM-Orte in der Ergebnisliste.
- Datenmodell für Check-ins (Migration `002_checkins`) mit OSM-Referenz, UTC-Zeit und Zeitzone, Slug, Quelle und `external_id` für spätere Importe.
- Cache-Tabelle `osm_cache` mit TTL. User-Agent mit Kontakt-Mail, Nominatim gedrosselt auf 1 Anfrage pro Sekunde, Endpunkte per Config austauschbar.
- Einstellungsseite mit Suchradius (Standard: 150 m).
- Tests für die Overpass-, Photon- und Nominatim-Auswertung, Cache, Slugs und Check-in-Validierung.

### Geändert
- JSON-Anfragen ohne Login bekommen 401 statt einer Weiterleitung, bei ausstehender Migration 503.

## [0.1.0] - 2026-10-29

### Hinzugefügt
- Projektgerüst ohne Framework: Front Controller, Router mit Platzhaltern, Request/Response, eigener PSR-4-Autoloader.
- Konfiguration mit Defaults in `src/Config.php` und `config/config.php` für lokale Werte.
- Web-Setup unter `/setup`, geschützt durch einen Einmal-Code in `data/setup-token.txt`. Sperrt sich nach dem Lauf selbst. CLI-Pendant: `bin/setup.php`.
- Migrator mit Tabelle `schema_migrations`, einer Transaktion pro Migration und automatischem Backup (`VACUUM INTO`) vor jedem Lauf. Ausführbar per Admin-Button oder `bin/migrate.php`.
- Bei ausstehenden Migrationen leitet die App nach `/admin` weiter.
- Login für einen Nutzer mit Passwort-Hash, CSRF-Schutz, Rate-Limiting bei Fehlversuchen und gehärteten Session-Cookies.
- Admin-Seite mit Systeminfos, Migrationsstatus und manuellem Backup.
- Sicherheits-Header (CSP, HSTS, X-Frame-Options), HTTPS-Pflicht, `.htaccess`-Sperren für alle Verzeichnisse außerhalb von `public/` (auch im Fallback-Modus).
- Oberfläche mit Bootstrap 5.3 und Bootstrap Icons per CDN (mit SRI), automatischer Dark Mode.
- GitHub Action: Lint, PHPUnit und PHPStan, danach Deploy per FTPS bei Push auf `main`.
- Tests für Migrator, Router und Auth.

[Unreleased]: https://github.com/tgigold/svaerma/compare/v0.8.0...HEAD
[0.8.0]: https://github.com/tgigold/svaerma/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/tgigold/svaerma/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/tgigold/svaerma/compare/v0.5.1...v0.6.0
[0.5.1]: https://github.com/tgigold/svaerma/compare/v0.5.0...v0.5.1
[0.5.0]: https://github.com/tgigold/svaerma/compare/v0.4.1...v0.5.0
[0.4.1]: https://github.com/tgigold/svaerma/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/tgigold/svaerma/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/tgigold/svaerma/compare/v0.2.2...v0.3.0
[0.2.2]: https://github.com/tgigold/svaerma/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/tgigold/svaerma/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/tgigold/svaerma/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/tgigold/svaerma/releases/tag/v0.1.0
