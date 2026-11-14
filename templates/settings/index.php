<?php
/** @var int $radius */
/** @var int $minRadius */
/** @var int $maxRadius */
/** @var array{enabled: bool, limit: string, delay: int, notes: bool, manual: bool} $public */
/** @var string|null $geoapifyHint */
/** @var string|null $overpassChHint */
/** @var string $overpassExtra */
/** @var list<string> $overpassConfigured */
/** @var string $version */
/** @var list<string> $applied */
/** @var list<string> $pending */
/** @var list<array{name: string, size: int}> $backups */
/** @var int $dbSize */
/** @var string $phpVersion */
/** @var string $sqliteVersion */
/** @var string $csrf */
/** @var array{version: string, url: string}|null $update */
/** @var list<array{id: int, name: string, created_at: string, last_used_at: ?string, revoked_at: ?string}>|null $tokens */
/** @var string|null $newToken */
/** @var string $baseUrl */
/** @var string $uploadLimit */
/** @var bool $importReady */
/** @var array<string, array{slug: string, name: string, author: string, version: string, api: ?int, compatible: bool}> $themes */
/** @var string $themeSlug */
/** @var int $themeApi */
$title = 'Einstellungen';
$kb = static fn (int $bytes): string => number_format($bytes / 1024, 0, ',', '.') . ' KB';
?>
<div class="sv-narrow sv-settings">
    <h1>Einstellungen</h1>

    <?php if ($pending !== []): ?>
        <div class="sv-alert sv-alert--warning" role="alert">
            <div>
                <h2 class="sv-card__title"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Ausstehende Migrationen</h2>
                <p>Nach einem Update muss die Datenbank aktualisiert werden. Vorher wird automatisch ein Backup erstellt.</p>
                <ul class="sv-small"><?php foreach ($pending as $m): ?><li><code><?= e($m) ?></code></li><?php endforeach; ?></ul>
                <form method="post" action="/admin/migrate">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <button class="sv-btn sv-btn--primary" type="submit"><i class="bi bi-database-up" aria-hidden="true"></i> Jetzt migrieren</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <form method="post" action="/settings" class="sv-settings">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <section class="sv-card sv-form">
            <h2 class="sv-card__title">Einchecken</h2>
            <div class="sv-field">
                <label for="nearby_radius">Suchradius für Orte in der Nähe</label>
                <div class="sv-inputgroup">
                    <input class="sv-input" type="number" id="nearby_radius" name="nearby_radius" value="<?= e($radius) ?>"
                           min="<?= e($minRadius) ?>" max="<?= e($maxRadius) ?>" step="10" required>
                    <span class="sv-suffix">m</span>
                </div>
                <p class="sv-help">Standard: 150 m. Bei ungenauem GPS wird bis zur Messgenauigkeit gesucht.</p>
            </div>
        </section>

        <section class="sv-card sv-form">
            <h2 class="sv-card__title">Öffentlich</h2>
            <label class="sv-switch-row" for="public_enabled">
                <span>Verlauf, Karte und Detailseiten ohne Login zeigen</span>
                <input class="sv-switch" type="checkbox" role="switch" id="public_enabled" name="public_enabled" value="1"<?= $public['enabled'] ? ' checked' : '' ?>>
            </label>
            <div class="sv-grid-2">
                <div class="sv-field">
                    <label for="public_limit">Anzahl Check-ins</label>
                    <input class="sv-input" type="number" min="1" id="public_limit" name="public_limit" value="<?= e($public['limit']) ?>" placeholder="alle">
                </div>
                <div class="sv-field">
                    <label for="public_delay_hours">Verzögerung</label>
                    <div class="sv-inputgroup">
                        <input class="sv-input" type="number" min="0" max="8760" id="public_delay_hours" name="public_delay_hours" value="<?= e($public['delay']) ?>">
                        <span class="sv-suffix">Std.</span>
                    </div>
                </div>
            </div>
            <p class="sv-help">Die neuesten N, gilt für Verlauf und Karte, leer = alle. Check-ins erscheinen erst nach der Verzögerung öffentlich.</p>
            <div>
                <label class="sv-check"><input type="checkbox" id="public_notes" name="public_notes" value="1"<?= $public['notes'] ? ' checked' : '' ?>> Notizen öffentlich zeigen</label>
                <label class="sv-check"><input type="checkbox" id="public_manual" name="public_manual" value="1"<?= $public['manual'] ? ' checked' : '' ?>> Eigene Orte (ohne OSM-Bezug) öffentlich zeigen</label>
            </div>
        </section>

        <section class="sv-card sv-form">
            <h2 class="sv-card__title">Ortsdienste</h2>
            <div class="sv-field">
                <label for="overpass_ch_key">overpass.ch-API-Key (optional, empfohlen)</label>
                <input class="sv-input" id="overpass_ch_key" name="overpass_ch_key" autocomplete="off" spellcheck="false"
                       placeholder="<?= e($overpassChHint !== null ? 'gesetzt (' . $overpassChHint . ') – leer lassen = unverändert' : 'nicht gesetzt') ?>">
                <p class="sv-help">
                    Gehostetes Overpass mit weltweiten Daten, kostenlos bis 10.000 Abfragen/Monat. Wird als Erstes gefragt.
                    Key unter <a href="https://overpass.ch/" rel="noopener" target="_blank">overpass.ch</a> → API-Schlüssel.
                </p>
                <?php if ($overpassChHint !== null): ?>
                    <label class="sv-check"><input type="checkbox" id="overpass_ch_remove" name="overpass_ch_remove" value="1"> Key entfernen</label>
                <?php endif; ?>
            </div>
            <div class="sv-field">
                <label for="overpass_extra_urls">Weitere Overpass-Endpunkte</label>
                <textarea class="sv-input sv-input--mono" id="overpass_extra_urls" name="overpass_extra_urls" rows="2"
                          spellcheck="false" placeholder="https://example.org/api/interpreter"><?= e($overpassExtra) ?></textarea>
                <p class="sv-help">
                    Einer pro Zeile, werden nach <?= $overpassChHint !== null ? 'overpass.ch und ' : '' ?>diesen versucht:
                    <?php foreach ($overpassConfigured as $i => $url): ?><?= $i > 0 ? ', ' : '' ?><code><?= e(preg_replace('#^https://#', '', $url)) ?></code><?php endforeach; ?>.
                    Danach folgen Geoapify und Photon, deren Ergebnisse zusammengeführt werden.
                </p>
            </div>
            <div class="sv-field">
                <label for="geoapify_key">Geoapify-API-Key (optional)</label>
                <input class="sv-input" id="geoapify_key" name="geoapify_key" autocomplete="off" spellcheck="false"
                       placeholder="<?= e($geoapifyHint !== null ? 'gesetzt (' . $geoapifyHint . ') – leer lassen = unverändert' : 'nicht gesetzt') ?>">
                <p class="sv-help">
                    Fallback für die Umkreissuche, wenn Overpass nicht antwortet. Kostenloser Key mit 3.000 Anfragen/Tag unter
                    <a href="https://myprojects.geoapify.com/" rel="noopener" target="_blank">myprojects.geoapify.com</a>.
                </p>
                <?php if ($geoapifyHint !== null): ?>
                    <label class="sv-check"><input type="checkbox" id="geoapify_remove" name="geoapify_remove" value="1"> Key entfernen</label>
                <?php endif; ?>
            </div>
        </section>

        <section class="sv-card sv-form">
            <h2 class="sv-card__title">Darstellung</h2>
            <div class="sv-field">
                <label for="theme">Design</label>
                <select class="sv-input" id="theme" name="theme">
                    <option value="">Standard</option>
                    <?php foreach ($themes as $t): ?>
                        <option value="<?= e($t['slug']) ?>"<?= $t['slug'] === $themeSlug ? ' selected' : '' ?>>
                            <?= e($t['name']) ?><?= $t['version'] !== '' ? ' ' . e($t['version']) : '' ?><?= $t['author'] !== '' ? ' · ' . e($t['author']) : '' ?><?= $t['compatible'] ? '' : ' (für eine andere Version)' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($themes[$themeSlug]) && !$themes[$themeSlug]['compatible']): ?>
                    <p class="sv-help">
                        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                        Dieses Design ist für Theme-API <?= e($themes[$themeSlug]['api'] ?? '?') ?> geschrieben, svaerma nutzt <?= e($themeApi) ?>. Einzelne Teile können falsch aussehen.
                    </p>
                <?php endif; ?>
                <p class="sv-help">
                    Eigene Designs liegen als Ordner in <code>data/themes/</code>, jeweils mit einer <code>theme.css</code>. Updates überschreiben sie nie.
                </p>
            </div>
        </section>

        <div><button class="sv-btn sv-btn--primary" type="submit">Speichern</button></div>
    </form>

    <?php if ($tokens !== null): ?>
        <section class="sv-card sv-form" id="api">
            <h2 class="sv-card__title">API-Tokens</h2>
            <p class="sv-help">
                Für den iOS-Kurzbefehl und andere Clients. Basis-URL: <code><?= e($baseUrl) ?>/api/v1</code>, Header
                <code>Authorization: Bearer &lt;Token&gt;</code>. Anleitung im README.
            </p>
            <?php if ($newToken !== null): ?>
                <div class="sv-alert sv-alert--success">
                    <div class="sv-field">
                        <label for="new-token">Neuer Token – jetzt kopieren, er wird nicht noch einmal angezeigt:</label>
                        <input class="sv-input sv-input--mono" id="new-token" readonly value="<?= e($newToken) ?>" data-select-on-focus>
                    </div>
                </div>
            <?php endif; ?>
            <form method="post" action="/settings/tokens" class="sv-inline-form">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <input class="sv-input" name="name" maxlength="100" placeholder="Name, z. B. iPhone-Kurzbefehl" required aria-label="Name des Tokens">
                <button class="sv-btn" type="submit"><i class="bi bi-key" aria-hidden="true"></i> Erzeugen</button>
            </form>
            <?php if ($tokens === []): ?>
                <p class="sv-muted sv-mb-0">Noch keine Tokens.</p>
            <?php else: ?>
                <ul class="sv-list">
                    <?php foreach ($tokens as $t): ?>
                        <li>
                            <span class="sv-small<?= $t['revoked_at'] !== null ? ' sv-revoked' : '' ?>">
                                <strong><?= e($t['name']) ?></strong><br>
                                erstellt <?= e(substr($t['created_at'], 0, 10)) ?>,
                                <?= $t['last_used_at'] !== null ? 'zuletzt genutzt ' . e(substr($t['last_used_at'], 0, 16)) . ' UTC' : 'nie genutzt' ?>
                            </span>
                            <?php if ($t['revoked_at'] === null): ?>
                                <form method="post" action="/settings/tokens/<?= e($t['id']) ?>/revoke" data-confirm="Token „<?= e($t['name']) ?>“ widerrufen? Clients damit funktionieren danach nicht mehr.">
                                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                    <button class="sv-btn sv-btn--small sv-btn--accent-outline" type="submit">Widerrufen</button>
                                </form>
                            <?php else: ?>
                                <span class="sv-chip sv-chip--quiet">widerrufen</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="sv-card sv-form" id="import">
        <h2 class="sv-card__title">Import &amp; Export</h2>
        <div class="sv-field">
            <h3>Swarm-Import</h3>
            <?php if (!$importReady): ?>
                <p class="sv-mb-0">Bitte zuerst die ausstehende Migration ausführen.</p>
            <?php else: ?>
                <p class="sv-help">
                    Die <code>checkins*.json</code> aus dem Foursquare-Datenexport auswählen (mehrere möglich, max. <?= e($uploadLimit) ?> je Datei).
                    Bereits importierte Check-ins werden erkannt und übersprungen. Die Dateien werden nicht gespeichert.
                </p>
                <form method="post" action="/import/swarm" enctype="multipart/form-data" class="sv-form">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input class="sv-input" type="file" name="files[]" accept=".json,application/json" multiple required aria-label="Swarm-Exportdateien">
                    <label class="sv-check"><input type="checkbox" id="dry_run" name="dry_run" value="1" checked> Probelauf – nur prüfen, nichts speichern</label>
                    <div><button class="sv-btn" type="submit"><i class="bi bi-upload" aria-hidden="true"></i> Importieren</button></div>
                </form>
            <?php endif; ?>
        </div>
        <div class="sv-field">
            <h3>Export</h3>
            <p class="sv-help">Alle Check-ins mit allen Feldern.</p>
            <div class="sv-actions">
                <a class="sv-btn sv-btn--small" href="/export.json"><i class="bi bi-filetype-json" aria-hidden="true"></i> JSON</a>
                <a class="sv-btn sv-btn--small" href="/export.geojson"><i class="bi bi-globe" aria-hidden="true"></i> GeoJSON</a>
            </div>
        </div>
    </section>

    <section class="sv-card sv-form" id="system">
        <h2 class="sv-card__title">System &amp; Backups</h2>
        <dl class="sv-dl">
            <dt>Version</dt><dd><?= e(App\App::NAME) ?> <?= e($version) ?></dd>
            <?php if ($update !== null): ?>
                <dt>Update</dt>
                <dd><span class="sv-chip"><i class="bi bi-arrow-up-circle" aria-hidden="true"></i> Neue Version <?= e($update['version']) ?></span>
                    <a href="<?= e($update['url']) ?>" rel="noopener" target="_blank">Release Notes</a></dd>
            <?php endif; ?>
            <dt>PHP</dt><dd><?= e($phpVersion) ?></dd>
            <dt>SQLite</dt><dd><?= e($sqliteVersion) ?></dd>
            <dt>Datenbank</dt><dd><?= e($kb($dbSize)) ?></dd>
            <dt>Migrationen</dt><dd><?= e(count($applied)) ?> angewendet</dd>
        </dl>
        <div class="sv-field">
            <h3>Backups</h3>
            <p class="sv-help">Liegen in <code>data/backups/</code>, die letzten 20 bleiben erhalten. Herunterladen per SFTP.</p>
            <form method="post" action="/admin/backup">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button class="sv-btn sv-btn--small" type="submit"><i class="bi bi-archive" aria-hidden="true"></i> Backup erstellen</button>
            </form>
            <?php if ($backups === []): ?>
                <p class="sv-muted sv-mb-0">Noch keine Backups.</p>
            <?php else: ?>
                <ul class="sv-list sv-small">
                    <?php foreach (array_slice($backups, 0, 5) as $b): ?>
                        <li><span><code><?= e($b['name']) ?></code> · <?= e($kb($b['size'])) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <div class="sv-field">
            <h3>OpenStreetMap-Dienste</h3>
            <p class="sv-help">Prüft, ob der Server Overpass, Geoapify und die Ortssuche erreicht.</p>
            <form method="post" action="/admin/osm-test">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button class="sv-btn sv-btn--small" type="submit"><i class="bi bi-broadcast" aria-hidden="true"></i> Verbindung testen</button>
            </form>
        </div>
    </section>

    <div class="sv-settings__logout">
        <form method="post" action="/logout" class="sv-btn--block">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button class="sv-btn sv-btn--accent-outline sv-btn--block sv-btn--large" type="submit"><i class="bi bi-box-arrow-left" aria-hidden="true"></i> Abmelden</button>
        </form>
        <form method="post" action="/settings/logout-all" data-confirm="Wirklich alle Geräte abmelden? API-Tokens bleiben gültig, die widerrufst du oben einzeln.">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button class="sv-linkbutton sv-linkbutton--accent" type="submit">Auf allen Geräten abmelden</button>
        </form>
    </div>
</div>
