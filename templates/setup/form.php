<?php
/** @var list<string> $problems */
/** @var list<string> $errors */
/** @var array<string, string> $input */
/** @var int $minLength */
/** @var string $csrf */
$title = 'Einrichtung';
?>
<div class="sv-card">
    <h1 class="sv-card__title">Einrichtung</h1>

    <?php if ($problems !== []): ?>
        <div class="sv-alert sv-alert--danger" role="alert">
            <div>
                <p>Bevor es losgeht, muss Folgendes behoben werden:</p>
                <ul><?php foreach ($problems as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul>
            </div>
        </div>
    <?php else: ?>
        <p class="sv-muted sv-small">
            Zum Schutz vor Fremden brauchst du einen Einmal-Code. Er liegt auf dem Server in
            <code>data/setup-token.txt</code> – lade die Datei per SFTP herunter oder öffne sie im SFTP-Programm.
        </p>

        <?php if ($errors !== []): ?>
            <div class="sv-alert sv-alert--danger sv-flash" role="alert">
                <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>

        <form method="post" action="/setup" class="sv-form" novalidate>
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <div class="sv-field">
                <label for="token">Setup-Code</label>
                <input class="sv-input sv-token" id="token" name="token" required autocomplete="off" autocapitalize="off">
            </div>
            <div class="sv-field">
                <label for="password">Passwort</label>
                <input class="sv-input" type="password" id="password" name="password" required
                       minlength="<?= e($minLength) ?>" autocomplete="new-password">
                <p class="sv-help">Mindestens <?= e($minLength) ?> Zeichen.</p>
            </div>
            <div class="sv-field">
                <label for="password_confirm">Passwort wiederholen</label>
                <input class="sv-input" type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password">
            </div>
            <div class="sv-field">
                <label for="contact_email">Kontakt-Mail</label>
                <input class="sv-input" type="email" id="contact_email" name="contact_email" required
                       value="<?= e($input['contact_email']) ?>">
                <p class="sv-help">Wird im User-Agent an Overpass, Photon und Nominatim mitgeschickt (Fair-Use-Regeln).</p>
            </div>
            <div class="sv-field">
                <label for="base_url">Adresse der App</label>
                <input class="sv-input" type="url" id="base_url" name="base_url" required value="<?= e($input['base_url']) ?>">
            </div>
            <div class="sv-field">
                <label for="timezone">Zeitzone</label>
                <input class="sv-input" id="timezone" name="timezone" required value="<?= e($input['timezone']) ?>">
            </div>
            <button class="sv-btn sv-btn--primary sv-btn--block sv-btn--large" type="submit">Einrichten</button>
        </form>
    <?php endif; ?>
</div>
