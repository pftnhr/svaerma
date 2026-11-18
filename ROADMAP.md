# Roadmap

Geplante Punkte ohne festen Termin. Was umgesetzt ist, wandert in den [CHANGELOG](CHANGELOG.md) und fliegt hier raus.

## PWA und Themes

- **Manifest folgt dem Theme.** `theme_color` und `background_color` stehen fest im statischen `public/manifest.webmanifest` (Tomatenrot und Weiß). Besser: PHP liefert das Manifest aus und liest die Farben aus dem gewählten Theme, zum Beispiel aus zusätzlichen Zeilen im Kopfkommentar der `theme.css` (`theme-color: #0F1012`). Dabei klären: Caching und der Service Worker, der das Manifest vorhält.
- **App-Icon pro Theme.** Ein Theme könnte eigene Icons mitbringen (`icon-192.png`, `icon-512.png`, `apple-touch-icon.png` im Theme-Ordner), die das Manifest dann nutzt. Fallback bleibt das Standard-Icon.
- **Statusleiste auf iOS.** `apple-mobile-web-app-status-bar-style` steht fest auf `default`. Für dunkle Themes und die Nachtfarben prüfen, ob `black-translucent` mit passendem Abstand oben besser aussieht.

## Orte

- **Eigene Orte verwalten.** Eigene Orte (`own_places`) lassen sich bisher nur anlegen. Fehlt: Liste in den Einstellungen mit Umbenennen, Verschieben (Koordinaten) und Löschen. Beim Umbenennen klären, ob `place_name` der vorhandenen Check-ins mitzieht. Beim Löschen klären, was mit dem `venue_ref` der Check-ins passiert.
- **Swarm-Orte in die Umkreisliste.** Importierte Foursquare-Venues ohne OSM-Bezug (`venue_ref = 'foursquare:…'`) gruppieren zwar die Besuche, tauchen aber nicht in der Umkreisliste auf. Idee: beim Import (und einmalig für Bestandsdaten) einen Eintrag in `own_places` mit Herkunft Foursquare anlegen, damit sie wie eigene Orte gefunden werden. Dabei klären: Koordinaten pro Venue (Mittelwert der Check-ins?), Dubletten mit OSM-Orten.
- **Eigene Orte im Export.** Der JSON-Export enthält `venue_ref`, aber nicht die Tabelle `own_places`. Für einen vollständigen Umzug sollte sie mit.
