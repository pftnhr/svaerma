<?php
/** @var bool|null $leaflet */
/** @var list<string>|null $scripts paths below public/assets/js */
?>
<script src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"
        integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"></script>
<?php if (!empty($leaflet)): ?>
<script src="<?= e(asset('vendor/leaflet/leaflet.js')) ?>"
        integrity="sha384-cxOPjt7s7Iz04uaHJceBmS+qpjv2JkIHNVcuOrM+YHwZOmJGBXI00mdUXEq65HTH"></script>
<?php endif; ?>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php foreach ($scripts ?? [] as $script): ?>
<script src="<?= e(asset('js/' . $script)) ?>"></script>
<?php endforeach; ?>
