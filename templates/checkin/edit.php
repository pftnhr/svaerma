<?php
/** @var App\Model\Checkin $checkin */
/** @var array<string, string> $input */
/** @var list<string> $errors */
/** @var list<string> $timezones */
/** @var string $csrf */
$title = 'Bearbeiten';
?>
<div class="sv-narrow">
    <a class="sv-back" href="<?= e($checkin->path()) ?>"><i class="bi bi-chevron-left" aria-hidden="true"></i> Zurück</a>
    <h1 class="sv-pagehead">Check-in bearbeiten</h1>

    <?php if ($errors !== []): ?>
        <div class="sv-alert sv-alert--danger sv-flash" role="alert"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <form method="post" action="/checkin/<?= e($checkin->id) ?>/edit" class="sv-card sv-form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="sv-field">
            <label for="place_name">Ort</label>
            <input class="sv-input" id="place_name" name="place_name" required maxlength="200" value="<?= e($input['place_name']) ?>">
            <?php if ($checkin->osmType !== null): ?>
                <p class="sv-help">Die Verknüpfung mit OpenStreetMap bleibt erhalten.</p>
            <?php endif; ?>
        </div>
        <div class="sv-grid-2">
            <div class="sv-field">
                <label for="checked_in_at">Zeitpunkt (Ortszeit)</label>
                <input class="sv-input" type="datetime-local" id="checked_in_at" name="checked_in_at" required value="<?= e($input['checked_in_at']) ?>">
            </div>
            <div class="sv-field">
                <label for="timezone">Zeitzone</label>
                <input class="sv-input" id="timezone" name="timezone" list="timezones" required value="<?= e($input['timezone']) ?>">
                <datalist id="timezones">
                    <?php foreach ($timezones as $tz): ?><option value="<?= e($tz) ?>"></option><?php endforeach; ?>
                </datalist>
            </div>
        </div>
        <div class="sv-field">
            <label for="note">Notiz</label>
            <textarea class="sv-input" id="note" name="note" rows="4" maxlength="5000"><?= e($input['note']) ?></textarea>
        </div>
        <div class="sv-actions">
            <button class="sv-btn sv-btn--primary" type="submit">Speichern</button>
            <a class="sv-btn" href="<?= e($checkin->path()) ?>">Abbrechen</a>
        </div>
    </form>
</div>
