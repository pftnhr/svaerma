<?php /** @var string $content */ ?>
<!doctype html>
<html lang="de">
<head>
<?= $view->partial('partials/head', get_defined_vars()) ?>
</head>
<body class="sv-body">
<main id="main" class="sv-minimal">
    <a class="sv-brand sv-minimal__brand" href="/"><?= e(App\App::NAME) ?></a>
    <?= $view->partial('partials/flash', get_defined_vars()) ?>
    <?= $content ?>
</main>
<?= $view->partial('partials/footer', get_defined_vars()) ?>
<?= $view->partial('partials/scripts', get_defined_vars()) ?>
</body>
</html>
