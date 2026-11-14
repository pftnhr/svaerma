# Eigene Designs für svaerma

Ein Design (Theme) ist reines CSS. Es ändert das Aussehen, nie die Funktion. svaerma bringt das Standard-Design „Stempelheft“ mit, ein eigenes Theme überschreibt davon so viel oder so wenig, wie es will.

Theme-API-Version: **1**

## Kurz: ein Theme anlegen

1. Ordner `data/themes/<name>/` auf dem Server anlegen. Der Name besteht nur aus Kleinbuchstaben, Ziffern und `-`, zum Beispiel `nachtschwarm`.
2. Darin eine `theme.css` mit Kopfkommentar anlegen:

   ```css
   /*
    * Name: Mein Design
    * Author: Dein Name
    * Version: 1.0
    * sv-theme-api: 1
    */

   :root {
     --sv-accent: #2B59C3;
     --sv-highlight: #FFE48A;
   }
   ```

3. In svaerma unter *Einstellungen → Darstellung* das Design auswählen und speichern.

Ein vollständiges Beispiel liegt in [`docs/themes/nachtschwarm/`](themes/nachtschwarm/): dunkel, Akzent in Limette, eigene Schrift. Zum Ausprobieren den Ordner nach `data/themes/nachtschwarm/` kopieren.

## Ordner

```
data/themes/<name>/
  theme.css     Pflicht. Wird auf jeder Seite nach dem Standard-CSS geladen.
  map.css       Optional. Nur auf Seiten mit Karte, nach theme.css.
  fonts/        Optional. Schriften als .woff2.
  img/          Optional. Bilder als .png.
```

- `data/` ist gesperrt und wird von Updates und Deployments nie angefasst. Ein Theme überlebt also jedes Update.
- Ausgeliefert werden die Dateien über `/themes/<name>/<datei>`, ohne Login, weil auch die Login-Seite das Theme braucht.
- Erlaubt sind nur `.css`, `.woff2` und `.png`, höchstens eine Ordnerebene tief. Dateinamen bestehen aus Kleinbuchstaben, Ziffern, `-`, `_` und `.`. Alles andere, auch SVG, liefert svaerma nicht aus.
- In der CSS verweist du relativ auf eigene Dateien, zum Beispiel `url("fonts/meine-schrift.woff2")`.
- Fremde Server sind tabu: Die Content Security Policy lässt Stylesheets, Schriften und Bilder nur vom eigenen Server zu. `@import` von Google Fonts oder einem CDN funktioniert deshalb nicht. Schriften liegen im Theme-Ordner.

## Wie das Theme gewinnt

Das Standard-CSS (`public/assets/css/core.css`) liegt komplett in `@layer core`. `theme.css` wird danach **ohne Layer** geladen. Regeln ohne Layer schlagen Regeln in einem Layer immer, egal wie spezifisch die Selektoren sind. Ein Theme muss also nie mit `!important` oder langen Selektoren kämpfen.

Die einzige Ausnahme sind die Leaflet-Regeln am Ende von `core.css` (Popups, Tooltips, Zoom-Knöpfe). Sie stehen ohne Layer, weil auch `leaflet.css` keinen hat. Ein Theme schlägt sie trotzdem, weil es später geladen wird: Es reicht derselbe Selektor.

## Kopfkommentar

| Schlüssel | Bedeutung |
|---|---|
| `Name` | Anzeige in den Einstellungen. Fehlt er, steht dort der Ordnername. |
| `Author` | Optional, wird neben dem Namen angezeigt. |
| `Version` | Optional, Version des Themes. |
| `sv-theme-api` | Version dieser Schnittstelle, für die das Theme geschrieben ist. Aktuell `1`. |

Passt `sv-theme-api` nicht zur App, bleibt das Theme nutzbar. Die Einstellungen zeigen dann einen Hinweis, dass einzelne Teile falsch aussehen können.

## Variablen

Alle Variablen stehen mit Standardwert in `:root` von `core.css`. Ein Theme überschreibt nur, was es braucht. Kommen in einer neuen Version Variablen dazu, haben sie im Core einen Standardwert, alte Themes erben ihn.

### Farben

| Variable | Hell | Dunkel | Wofür |
|---|---|---|---|
| `--sv-bg` | `#FFFFFF` | `#16110E` | Seitenhintergrund |
| `--sv-surface` | `#FFFFFF` | `#201914` | Karten, Felder, Dialoge |
| `--sv-ink` | `#1C1410` | `#F4ECE6` | Text und Rahmen |
| `--sv-muted` | `#5A4E46` | `#B9ACA3` | Hilfstexte (Kontrast ≥ 4,5:1 halten) |
| `--sv-placeholder` | `#6E625A` | `#9C8F86` | Platzhalter in Feldern |
| `--sv-line` | `#E6DDD6` | `#3B302A` | gestrichelte Trenner |
| `--sv-paper` | `#FFFFFF` | `#FFFFFF` | heller Text auf dunklen Flächen (Suchknopf, Schalterknopf) |
| `--sv-accent` | `#D93A16` | `#FF6B42` | Hauptfarbe: Knöpfe, Uhrzeiten, Stempel |
| `--sv-on-accent` | `#FFFFFF` | `#16110E` | Text auf der Akzentfarbe |
| `--sv-highlight` | `#FFD23F` | `#F2C230` | Textmarker: Suchblock, Tage, Sticker, Chips |
| `--sv-on-highlight` | `#1C1410` | `#16110E` | Text und Rahmen auf dem Textmarker |
| `--sv-link` | `#B32E10` | `#FF9474` | Links |
| `--sv-link-hover` | `var(--sv-ink)` | | Links beim Überfahren |
| `--sv-tint-1` … `--sv-tint-3` | `#FFE48A`, `#FFD3C7`, `#D6E2FF` | dunkle Töne | Hintergrund der Symbole in den Einstellungen |
| `--sv-success`, `--sv-success-bg` | | | Erfolgsmeldungen |
| `--sv-danger`, `--sv-danger-bg` | | | Fehlermeldungen |
| `--sv-warning-bg`, `--sv-info-bg` | | | Hinweise |
| `--sv-backdrop` | | | Abdunklung hinter Dialogen |
| `--sv-focus-color` | `var(--sv-accent)` | | Fokusrahmen |
| `--sv-stamp-color` | `var(--sv-accent)` | | Entfernungsstempel |

### Navigation

| Variable | Standard | Wofür |
|---|---|---|
| `--sv-nav-bg` | `var(--sv-ink)` | Leiste unten (Handy) |
| `--sv-nav-fg` | `#D9CFC8` | Symbole und Beschriftung darin |
| `--sv-nav-active` | `var(--sv-highlight)` | aktive Seite |
| `--sv-theme-color` | `var(--sv-accent)` | Farbe der Browserleiste (`theme-color`, setzt `theme.js`) |

### Schrift

| Variable | Standard | Wofür |
|---|---|---|
| `--sv-font-head` | `"Bricolage Grotesque", system-ui, …` | Überschriften, Ortsnamen |
| `--sv-font-body` | `"Bricolage Grotesque", system-ui, …` | Fließtext |
| `--sv-font-mono` | `ui-monospace, …` | Code, Tokens |
| `--sv-head-weight` | `650` | Gewicht der Überschriften |
| `--sv-head-opsz` | `24` | optische Größe (nur bei Schriften mit `opsz`-Achse) |
| `--sv-brand-weight` | `800` | Schriftzug „svaerma“ |

### Formen

| Variable | Standard | Wofür |
|---|---|---|
| `--sv-border-width` | `2px` | Rahmenstärke, auch für gestrichelte Trenner |
| `--sv-border` | `2px solid var(--sv-ink)` | alle Rahmen |
| `--sv-radius-s` / `-m` / `-l` / `-xl` | `10px` / `12px` / `16px` / `20px` | Ecken: Tooltips / Felder und Knöpfe / Karten / Suchblock und Leiste |
| `--sv-content-width` | `1200px` | maximale Breite der Seite |
| `--sv-text-width` | `760px` | maximale Breite von Verlauf, Detail und Einstellungen |

### Karte

| Variable | Standard | Wofür |
|---|---|---|
| `--sv-map-tile-filter` | `none` (dunkel: `invert(1) hue-rotate(180deg) brightness(0.95) contrast(0.9)`) | CSS-Filter auf den Kartenkacheln |
| `--sv-marker-fill` | `var(--sv-accent)` | Füllung der Marker |
| `--sv-marker-stroke` | `#FFFFFF` (dunkel: `#16110E`) | Rand der Marker |

Die Übersichtskarte zeichnet ihre Marker auf einen Canvas, damit sie auch mit Tausenden Punkten flüssig bleibt. CSS kann Canvas-Marker nicht direkt stylen: Ihre Farben kommen deshalb nur aus den beiden Marker-Variablen, die `map.js` beim Laden liest.

Die **Quelle** der Kartenkacheln kann ein Theme nicht ändern. Sie steht in `config/config.php` (`osm.tile_url`), weil die Content Security Policy nur diesen einen Kachel-Server zulässt. Ein Theme ändert nur den Look der Kacheln über `--sv-map-tile-filter`.

## Hell und dunkel

`core.css` setzt die dunklen Werte in `@media (prefers-color-scheme: dark)`. Für ein Theme heißt das:

- **Nur `:root { … }`, ohne Media-Query:** Die Werte gelten in beiden Modi, weil Regeln ohne Layer gewinnen. So bleibt „Nachtschwarm“ immer dunkel.
- **Eigene Nachtfarben:** Die dunklen Werte selbst in `@media (prefers-color-scheme: dark) { :root { … } }` schreiben.
- **Nur hell:** Alle Farben in `:root` setzen und `color-scheme: light;` dazu.

## Stabile Klassen

Diese Klassen sind Teil der Theme-API und bleiben innerhalb einer API-Version stabil. Alles andere im Markup kann sich ändern.

| Bereich | Klassen |
|---|---|
| Gerüst | `sv-body`, `sv-header`, `sv-brand`, `sv-topnav`, `sv-topnav__link`, `sv-topnav__checkin`, `sv-gear`, `sv-main`, `sv-footer`, `sv-tabbar`, `sv-tabbar__link`, `sv-tabbar__stamp` |
| Bausteine | `sv-btn` (`--primary`, `--dark`, `--accent-outline`, `--small`, `--large`, `--block`), `sv-linkbutton`, `sv-card`, `sv-label`, `sv-day`, `sv-chip`, `sv-sticker`, `sv-stamp`, `sv-alert` (`--success`, `--danger`, `--warning`, `--info`, `--quiet`), `sv-sheet` |
| Formulare | `sv-field`, `sv-input`, `sv-inputgroup`, `sv-suffix`, `sv-help`, `sv-check`, `sv-switch`, `sv-switch-row` |
| Check-in | `sv-hero`, `sv-search`, `sv-status`, `sv-place`, `sv-place__name`, `sv-place__meta`, `sv-recent` |
| Verlauf | `sv-entries`, `sv-entry`, `sv-entry__time`, `sv-entry__name`, `sv-pager` |
| Einstellungen | `sv-accordion`, `sv-acc`, `sv-acc__head`, `sv-acc__icon`, `sv-acc__title`, `sv-acc__status`, `sv-acc__body` |
| Karte | `sv-map-full`, `sv-map-small`, `sv-marker` (nur Detailseite), dazu die Leaflet-Klassen `leaflet-popup-content-wrapper`, `leaflet-popup-tip`, `leaflet-tooltip`, `leaflet-bar`, `leaflet-control-attribution` |

Der Kartenhinweis (© OpenStreetMap-Mitwirkende) und die Fußzeile mit den Diensten müssen sichtbar bleiben. Ein Theme darf sie gestalten, aber nicht ausblenden.

## Änderungen an der Schnittstelle

- Neue Variablen und Klassen kommen mit Standardwert dazu, ohne dass sich die API-Version ändert.
- Wird eine Variable umbenannt, bleibt der alte Name als Übergang erhalten (`--sv-neu: var(--sv-alt, <Standard>)`). Das steht im CHANGELOG.
- Erst wenn etwas wegfällt oder sich grundlegend ändert, steigt `sv-theme-api`.
