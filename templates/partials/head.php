<?php
/** @var string|null $title */
/** @var array{slug: string, css: string, map: ?string}|null $theme */
$theme ??= null;
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#0d6efd">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="icon" href="/assets/icons/icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?= e(App\App::NAME) ?>">
<title><?= e(isset($title) ? $title . ' · ' . App\App::NAME : App\App::NAME) ?></title>
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-5.3.8/bootstrap.min.css')) ?>"
      integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons-1.13.2/bootstrap-icons.min.css')) ?>"
      integrity="sha384-nWN3KqaaVcbEsCjLSV+2jMjh62Iny/+mN3srM8TZeM8N6aj7eDl5wwYggjAJ4/sJ">
<?php if (!empty($leaflet)): ?>
<link rel="stylesheet" href="<?= e(asset('vendor/leaflet-1.9.4/leaflet.css')) ?>"
      integrity="sha384-sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H">
<?php endif; ?>
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
<?php if ($theme !== null): ?>
<link rel="stylesheet" href="<?= e($theme['css']) ?>">
<?php if (!empty($leaflet) && $theme['map'] !== null): ?>
<link rel="stylesheet" href="<?= e($theme['map']) ?>">
<?php endif; ?>
<?php endif; ?>
<script src="<?= e(asset('js/theme.js')) ?>"></script>
