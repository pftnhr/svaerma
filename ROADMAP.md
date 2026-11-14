# Roadmap

Geplante Punkte ohne festen Termin. Was umgesetzt ist, wandert in den [CHANGELOG](CHANGELOG.md) und fliegt hier raus.

## PWA und Themes

- **Manifest folgt dem Theme.** `theme_color` und `background_color` stehen fest im statischen `public/manifest.webmanifest` (Tomatenrot und Weiß). Besser: PHP liefert das Manifest aus und liest die Farben aus dem gewählten Theme, zum Beispiel aus zusätzlichen Zeilen im Kopfkommentar der `theme.css` (`theme-color: #0F1012`). Dabei klären: Caching und der Service Worker, der das Manifest vorhält.
- **App-Icon pro Theme.** Ein Theme könnte eigene Icons mitbringen (`icon-192.png`, `icon-512.png`, `apple-touch-icon.png` im Theme-Ordner), die das Manifest dann nutzt. Fallback bleibt das Standard-Icon.
- **Statusleiste auf iOS.** `apple-mobile-web-app-status-bar-style` steht fest auf `default`. Für dunkle Themes und die Nachtfarben prüfen, ob `black-translucent` mit passendem Abstand oben besser aussieht.
