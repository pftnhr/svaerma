<?php
/** @var App\Model\Checkin $checkin */
/** @var list<App\Model\Checkin> $visits */
/** @var string $tileUrl */
/** @var string $csrf */
/** @var bool $showNotes */
/** @var bool $loggedIn */
use App\Format;

$title = $checkin->placeName;
$local = $checkin->localTime();
$others = array_values(array_filter($visits, static fn ($v) => $v->id !== $checkin->id));
?>
<div class="sv-narrow">
    <a class="sv-back" href="/checkins"><i class="bi bi-chevron-left" aria-hidden="true"></i> Verlauf</a>

    <h1><?= e($checkin->placeName) ?></h1>
    <p class="sv-meta">
        <?= e(Format::day($local)) ?>, <?= e($local->format('H:i')) ?> Uhr
        <?php if ($checkin->timezone !== ($app->config->get('timezone'))): ?>
            <span class="sv-small">(<?= e($checkin->timezone) ?>)</span>
        <?php endif; ?>
    </p>

    <div class="sv-stack">
        <?php if ($showNotes && $checkin->note !== null): ?>
            <div class="sv-card sv-note"><?= nl2br(e($checkin->note)) ?></div>
        <?php endif; ?>

        <div class="sv-map-small" data-map-point
             data-lat="<?= e($checkin->lat) ?>" data-lon="<?= e($checkin->lon) ?>"
             data-name="<?= e($checkin->placeName) ?>" data-tiles="<?= e($tileUrl) ?>"></div>

        <div class="sv-actions">
            <?php if ($loggedIn): ?>
                <a class="sv-btn sv-btn--small" href="/checkin/<?= e($checkin->id) ?>/edit"><i class="bi bi-pencil" aria-hidden="true"></i> Bearbeiten</a>
            <?php endif; ?>
            <?php if ($checkin->osmUrl() !== null): ?>
                <a class="sv-btn sv-btn--small" href="<?= e($checkin->osmUrl()) ?>" rel="noopener" target="_blank">
                    <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> OpenStreetMap
                </a>
            <?php endif; ?>
            <?php if ($loggedIn): ?>
                <form method="post" action="/checkin/<?= e($checkin->id) ?>/delete" class="sv-actions__end"
                      data-confirm="Check-in „<?= e($checkin->placeName) ?>“ wirklich löschen?">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <button class="sv-btn sv-btn--small sv-btn--accent-outline" type="submit"><i class="bi bi-trash" aria-hidden="true"></i> Löschen</button>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($checkin->osmType !== null || $checkin->venueRef !== null): ?>
            <section>
                <h2><?= $loggedIn ? 'Deine Besuche hier' : 'Besuche hier' ?></h2>
                <?php if ($others === []): ?>
                    <p class="sv-muted sv-mt">Dein erster Check-in an diesem Ort.</p>
                <?php else: ?>
                    <p class="sv-muted"><?= e(count($visits)) ?>× hier eingecheckt.</p>
                    <ul class="sv-list">
                        <?php foreach (array_slice($others, 0, 20) as $v): ?>
                            <li><span><a href="<?= e($v->path()) ?>"><?= e(Format::dateTime($v->localTime())) ?></a><?= $showNotes && $v->note !== null ? ' – ' . e(mb_strimwidth($v->note, 0, 80, '…')) : '' ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($loggedIn): ?>
            <p class="sv-muted sv-small sv-mb-0">
                <?= e(number_format($checkin->lat, 5, ',', '')) ?>, <?= e(number_format($checkin->lon, 5, ',', '')) ?>
                · Quelle: <?= e(['web' => 'Web', 'api' => 'API', 'swarm' => 'Swarm-Import'][$checkin->source] ?? $checkin->source) ?>
            </p>
        <?php endif; ?>
    </div>
</div>
