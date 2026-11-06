<?php
/** @var list<non-empty-list<App\Model\Checkin>> $days */
/** @var int $page */
/** @var int $pages */
/** @var int $total */
/** @var bool $showNotes */
use App\Format;

$title = 'Verlauf';
?>
<div class="d-flex align-items-baseline justify-content-between mb-3">
    <h1 class="h3 mb-0">Verlauf</h1>
    <span class="text-body-secondary small"><?= e(Format::number($total)) ?> Check-ins</span>
</div>

<?php if ($days === []): ?>
    <div class="text-center py-5 text-body-secondary">
        <i class="bi bi-geo display-5"></i>
        <p class="mt-3">Noch keine Check-ins.<?php if ($loggedIn): ?> <a href="/">Jetzt einchecken</a><?php endif; ?></p>
    </div>
<?php endif; ?>

<?php foreach ($days as $checkins): ?>
    <h2 class="h6 text-body-secondary mt-4 mb-2"><?= e(Format::day($checkins[0]->localTime())) ?></h2>
    <div class="list-group mb-2">
        <?php foreach ($checkins as $c): ?>
            <a class="list-group-item list-group-item-action d-flex gap-3 align-items-start" href="<?= e($c->path()) ?>">
                <span class="text-body-secondary small checkin-time"><?= e($c->localTime()->format('H:i')) ?></span>
                <span class="flex-grow-1 min-w-0">
                    <span class="d-block fw-semibold text-truncate"><?= e($c->placeName) ?></span>
                    <?php if ($showNotes && $c->note !== null): ?>
                        <span class="d-block small text-body-secondary text-truncate"><?= e($c->note) ?></span>
                    <?php endif; ?>
                </span>
                <?php if ($loggedIn && $c->osmType === null): ?>
                    <i class="bi bi-pencil-square text-body-tertiary" title="Eigener Ort"></i>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>

<?php if ($pages > 1): ?>
    <nav class="mt-4" aria-label="Seiten">
        <ul class="pagination justify-content-center">
            <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>">
                <a class="page-link" href="/checkins?page=<?= e($page - 1) ?>" aria-label="Neuere">‹ Neuere</a>
            </li>
            <li class="page-item disabled"><span class="page-link"><?= e($page) ?> / <?= e($pages) ?></span></li>
            <li class="page-item<?= $page >= $pages ? ' disabled' : '' ?>">
                <a class="page-link" href="/checkins?page=<?= e($page + 1) ?>" aria-label="Ältere">Ältere ›</a>
            </li>
        </ul>
    </nav>
<?php endif; ?>
