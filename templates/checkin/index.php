<?php
/** @var list<non-empty-list<App\Model\Checkin>> $days */
/** @var int $page */
/** @var int $pages */
/** @var int $total */
/** @var bool $showNotes */
use App\Format;

$title = 'Verlauf';
?>
<div class="sv-narrow">
    <div class="sv-pagehead">
        <h1>Verlauf</h1>
        <span class="sv-sticker"><strong><?= e(Format::number($total)) ?></strong><span>Stempel</span></span>
    </div>

    <?php if ($days === []): ?>
        <div class="sv-empty">
            <i class="bi bi-geo" aria-hidden="true"></i>
            <p>Noch keine Check-ins.<?php if ($loggedIn): ?> <a href="/">Jetzt einchecken</a><?php endif; ?></p>
        </div>
    <?php endif; ?>

    <?php foreach ($days as $checkins): ?>
        <h2 class="sv-day"><?= e(Format::dayInYear($checkins[0]->localTime())) ?></h2>
        <ul class="sv-entries">
            <?php foreach ($checkins as $c): ?>
                <li><a class="sv-entry" href="<?= e($c->path()) ?>">
                    <span class="sv-entry__time"><?= e($c->localTime()->format('H:i')) ?></span>
                    <span class="sv-entry__name"><?= e($c->placeName) ?></span>
                    <?php if ($showNotes && $c->note !== null): ?>
                        <i class="bi bi-pencil sv-entry__icon" role="img" aria-label="mit Notiz" title="<?= e(mb_strimwidth($c->note, 0, 120, '…')) ?>"></i>
                    <?php endif; ?>
                </a></li>
            <?php endforeach; ?>
        </ul>
    <?php endforeach; ?>

    <?php if ($pages > 1): ?>
        <nav class="sv-pager" aria-label="Seiten">
            <?php if ($page > 1): ?>
                <a class="sv-btn sv-btn--small" href="/checkins?page=<?= e($page - 1) ?>">‹ Neuere</a>
            <?php else: ?>
                <span class="sv-btn sv-btn--small" aria-disabled="true">‹ Neuere</span>
            <?php endif; ?>
            <span class="sv-muted"><?= e($page) ?> / <?= e($pages) ?></span>
            <?php if ($page < $pages): ?>
                <a class="sv-btn sv-btn--small" href="/checkins?page=<?= e($page + 1) ?>">Ältere ›</a>
            <?php else: ?>
                <span class="sv-btn sv-btn--small" aria-disabled="true">Ältere ›</span>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</div>
