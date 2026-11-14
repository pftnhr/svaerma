<?php
/** @var string $content */
/** @var string $csrf */
/** @var bool $loggedIn */
/** @var bool $publicNav */
$current = $currentPath ?? '/';
$showNav = $loggedIn || !empty($publicNav);
$home = $loggedIn || empty($publicNav) ? '/' : '/checkins';
$here = static fn (string $path): string => $current === $path ? ' aria-current="page"' : '';
?>
<!doctype html>
<html lang="de">
<head>
<?= $view->partial('partials/head', get_defined_vars()) ?>
</head>
<body class="sv-body<?= $showNav ? ' sv-body--tabbar' : '' ?>">
<a class="sv-skip" href="#main">Zum Inhalt</a>
<header class="sv-header">
    <div class="sv-header__inner">
        <a class="sv-brand" href="<?= e($home) ?>"><?= e(App\App::NAME) ?></a>
        <?php if ($showNav): ?>
            <nav class="sv-topnav" aria-label="Hauptnavigation">
                <?php if ($loggedIn): ?>
                    <a class="sv-topnav__checkin" href="/"<?= $here('/') ?>>
                        <span class="sv-topnav__stamp" aria-hidden="true"><i class="bi bi-geo-alt"></i></span>
                        Einchecken
                    </a>
                <?php endif; ?>
                <a class="sv-topnav__link" href="/checkins"<?= $here('/checkins') ?>><i class="bi bi-list-ul" aria-hidden="true"></i> Verlauf</a>
                <a class="sv-topnav__link" href="/map"<?= $here('/map') ?>><i class="bi bi-map" aria-hidden="true"></i> Karte</a>
            </nav>
        <?php endif; ?>
        <?php if ($loggedIn): ?>
            <a class="sv-gear" href="/settings" aria-label="Einstellungen"<?= $here('/settings') ?>><i class="bi bi-gear" aria-hidden="true"></i></a>
        <?php elseif ($showNav): ?>
            <a class="sv-gear" href="/login" aria-label="Anmelden"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i></a>
        <?php endif; ?>
    </div>
</header>
<main id="main" class="sv-main<?= !empty($fullBleed) ? ' sv-main--full' : '' ?>">
    <?= $view->partial('partials/flash', get_defined_vars()) ?>
    <?= $content ?>
</main>
<?= $view->partial('partials/footer', get_defined_vars()) ?>
<?php if ($showNav): ?>
    <nav class="sv-tabbar" aria-label="Hauptnavigation">
        <a class="sv-tabbar__link" href="/checkins"<?= $here('/checkins') ?>><i class="bi bi-list-ul" aria-hidden="true"></i><span>Verlauf</span></a>
        <?php if ($loggedIn): ?>
            <a class="sv-tabbar__stamp" href="/" aria-label="Einchecken"<?= $here('/') ?>><i class="bi bi-geo-alt" aria-hidden="true"></i></a>
        <?php endif; ?>
        <a class="sv-tabbar__link" href="/map"<?= $here('/map') ?>><i class="bi bi-map" aria-hidden="true"></i><span>Karte</span></a>
    </nav>
<?php endif; ?>
<?= $view->partial('partials/scripts', get_defined_vars()) ?>
</body>
</html>
