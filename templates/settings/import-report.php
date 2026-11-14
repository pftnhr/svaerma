<?php
/** @var array{total: int, imported: int, duplicates: int, skipped: list<array{file: string, id: string, reason: string}>, dry_run: bool} $report */
/** @var list<string> $files */
use App\Format;

$title = 'Swarm-Import';
?>
<div class="sv-narrow sv-stack">
    <div>
        <a class="sv-back" href="/settings?open=import#import"><i class="bi bi-chevron-left" aria-hidden="true"></i> Einstellungen</a>
        <h1>Swarm-Import<?= $report['dry_run'] ? ' – Probelauf' : '' ?></h1>
    </div>

    <div class="sv-alert <?= $report['dry_run'] ? 'sv-alert--info' : 'sv-alert--success' ?>" role="status">
        <div>
            <?php if ($report['dry_run']): ?>
                <strong><?= e(Format::number($report['imported'])) ?></strong> Check-ins würden importiert. Es wurde noch nichts gespeichert.
            <?php else: ?>
                <strong><?= e(Format::number($report['imported'])) ?></strong> Check-ins importiert.
            <?php endif; ?>
        </div>
    </div>

    <dl class="sv-dl">
        <dt>Dateien</dt><dd><?= e(implode(', ', $files)) ?></dd>
        <dt>Einträge gesamt</dt><dd><?= e(Format::number($report['total'])) ?></dd>
        <dt><?= $report['dry_run'] ? 'Würden importiert' : 'Importiert' ?></dt><dd><?= e(Format::number($report['imported'])) ?></dd>
        <dt>Schon vorhanden</dt><dd><?= e(Format::number($report['duplicates'])) ?></dd>
        <dt>Übersprungen</dt><dd><?= e(Format::number(count($report['skipped']))) ?></dd>
    </dl>

    <?php if ($report['skipped'] !== []): ?>
        <section>
            <h2>Übersprungene Einträge</h2>
            <div class="sv-scroll">
                <table class="sv-table">
                    <thead><tr><th>Datei</th><th>Swarm-ID</th><th>Grund</th></tr></thead>
                    <tbody>
                        <?php foreach (array_slice($report['skipped'], 0, 200) as $s): ?>
                            <tr><td><?= e($s['file']) ?></td><td><code><?= e($s['id']) ?></code></td><td><?= e($s['reason']) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($report['skipped']) > 200): ?><p class="sv-muted sv-small">… und <?= e(count($report['skipped']) - 200) ?> weitere.</p><?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($report['dry_run'] && $report['imported'] > 0): ?>
        <p class="sv-mb-0">Passt alles? Dann die Dateien noch einmal auswählen und den Probelauf abwählen.</p>
    <?php endif; ?>
    <div class="sv-actions">
        <a class="sv-btn sv-btn--primary" href="/settings?open=import#import">Zurück</a>
        <?php if (!$report['dry_run'] && $report['imported'] > 0): ?>
            <a class="sv-btn" href="/checkins">Zum Verlauf</a>
        <?php endif; ?>
    </div>
</div>
