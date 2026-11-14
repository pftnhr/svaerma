<?php
/** @var int $status */
/** @var string $title */
/** @var string|null $message */
/** @var string|null $debug */
?>
<div class="sv-card sv-center">
    <p class="sv-error-code"><?= e($status) ?></p>
    <h1 class="sv-card__title"><?= e($title) ?></h1>
    <?php if ($message): ?><p class="sv-muted"><?= e($message) ?></p><?php endif; ?>
    <a class="sv-btn" href="/">Zur Startseite</a>
</div>
<?php if ($debug): ?>
    <pre class="sv-mt sv-small"><?= e($debug) ?></pre>
<?php endif; ?>
