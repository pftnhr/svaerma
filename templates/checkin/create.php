<?php
/** @var int $radius */
/** @var string $csrf */
/** @var list<non-empty-list<App\Model\Checkin>> $recent */
/** @var int $total */
use App\Format;

$title = 'Einchecken';
?>
<div class="sv-checkin">
    <div class="sv-checkin__main" id="checkin" data-radius="<?= e($radius) ?>" data-max-radius="<?= e(App\Service\Osm\Overpass::MAX_RADIUS) ?>">
        <section class="sv-hero" aria-labelledby="checkin-title">
            <h1 class="sv-hero__title" id="checkin-title">Wo steckst du?</h1>
            <form class="sv-search" id="search-form" role="search">
                <label class="sv-search__field">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" id="search-q" placeholder="Ort suchen …" aria-label="Ort suchen" autocomplete="off" enterkeyhint="search">
                </label>
                <button class="sv-search__submit" type="submit" aria-label="Suchen">
                    <i class="bi bi-arrow-right" aria-hidden="true"></i><span class="sv-search__label" aria-hidden="true">Suchen</span>
                </button>
            </form>
            <div class="sv-status">
                <span class="sv-status__dot" aria-hidden="true"></span>
                <span id="geo-status" role="status">Standort wird ermittelt …</span>
                <span aria-hidden="true">·</span>
                <button type="button" class="sv-linkbutton" id="geo-refresh"><i class="bi bi-crosshair" aria-hidden="true"></i> neu orten</button>
            </div>
        </section>

        <div class="sv-nearby">
            <h2 class="sv-label sv-nearby__head">In der Nähe</h2>
            <div class="sv-nearby__message sv-alert sv-alert--quiet" id="places-message" role="status" hidden></div>
            <div class="sv-places" id="places" aria-live="polite"></div>
            <div class="sv-nearby__actions">
                <button type="button" class="sv-btn" id="widen" hidden>Umkreis erweitern</button>
                <button type="button" class="sv-btn sv-btn--dark" id="manual-toggle" aria-expanded="false" aria-controls="manual">+ Eigener Ort</button>
            </div>
            <div class="sv-nearby__manual sv-card" id="manual" hidden>
                <div class="sv-field">
                    <label for="manual-name">Name des Orts</label>
                    <div class="sv-inline-form">
                        <input class="sv-input" id="manual-name" maxlength="200" placeholder="z. B. Bei Oma im Garten">
                        <button class="sv-btn sv-btn--primary" type="button" id="manual-use">Weiter</button>
                    </div>
                    <p class="sv-help">Gespeichert wird dein aktueller Standort.</p>
                </div>
            </div>
        </div>
    </div>

    <aside class="sv-recent" aria-labelledby="recent-title">
        <div class="sv-recent__head">
            <h2 id="recent-title">Zuletzt</h2>
            <?php if ($total > 0): ?>
                <span class="sv-sticker sv-sticker--inline"><strong><?= e(Format::number($total)) ?></strong><span>Stempel</span></span>
            <?php endif; ?>
        </div>
        <?php if ($recent === []): ?>
            <p class="sv-muted">Noch keine Check-ins.</p>
        <?php endif; ?>
        <?php foreach ($recent as $checkins): ?>
            <h3 class="sv-day"><?= e(Format::shortDay($checkins[0]->localTime())) ?></h3>
            <ul class="sv-entries">
                <?php foreach ($checkins as $c): ?>
                    <li><a class="sv-entry" href="<?= e($c->path()) ?>">
                        <span class="sv-entry__time"><?= e($c->localTime()->format('H:i')) ?></span>
                        <span class="sv-entry__name"><?= e($c->placeName) ?></span>
                    </a></li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
        <?php if ($recent !== []): ?>
            <a class="sv-recent__more" href="/checkins">Ganzer Verlauf →</a>
        <?php endif; ?>
    </aside>
</div>

<dialog class="sv-sheet" id="confirm" aria-labelledby="confirm-title">
    <div class="sv-sheet__head">
        <div>
            <h2 id="confirm-title"></h2>
            <div class="sv-muted sv-small" id="confirm-meta"></div>
        </div>
        <button type="button" class="sv-sheet__close" id="confirm-close" aria-label="Schließen"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <form method="post" action="/checkin" id="checkin-form" class="sv-form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="lat">
        <input type="hidden" name="lon">
        <input type="hidden" name="place_name">
        <input type="hidden" name="osm_type">
        <input type="hidden" name="osm_id">
        <input type="hidden" name="venue_ref">
        <input type="hidden" name="new_place">
        <input type="hidden" name="timezone">
        <label class="sv-visually-hidden" for="note">Notiz</label>
        <textarea class="sv-input" id="note" name="note" rows="2" maxlength="5000" placeholder="Notiz (optional)"></textarea>
        <button class="sv-btn sv-btn--primary sv-btn--block sv-btn--large" type="submit"><i class="bi bi-geo-alt" aria-hidden="true"></i> Einchecken</button>
    </form>
</dialog>
