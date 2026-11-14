<?php
/** @var string $csrf */
/** @var string|null $error */
$title = 'Anmelden';
?>
<div class="sv-card">
    <?php if (!empty($error)): ?>
        <div class="sv-alert sv-alert--danger sv-flash" role="alert"><div><?= e($error) ?></div></div>
    <?php endif; ?>
    <form method="post" action="/login" class="sv-form">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <div class="sv-field">
            <label for="password">Passwort</label>
            <input class="sv-input" type="password" id="password" name="password" required autofocus autocomplete="current-password">
        </div>
        <button class="sv-btn sv-btn--primary sv-btn--block sv-btn--large" type="submit"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Anmelden</button>
    </form>
</div>
