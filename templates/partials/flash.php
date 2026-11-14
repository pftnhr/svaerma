<?php /** @var list<array{type: string, message: string}> $flash */ ?>
<?php if (!empty($flash)): ?>
    <div class="sv-flash">
        <?php foreach ($flash as $f): ?>
            <div class="sv-alert sv-alert--<?= e($f['type']) ?>" role="<?= $f['type'] === 'danger' ? 'alert' : 'status' ?>">
                <div><?= e($f['message']) ?></div>
                <button type="button" class="sv-alert__close" data-dismiss aria-label="Schließen"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
