<?php
/** @var string $tileUrl */
$title = 'Karte';
?>
<h1 class="sv-visually-hidden">Karte</h1>
<div class="sv-map-full" id="map-all" data-src="/map/data" data-tiles="<?= e($tileUrl) ?>">
    <div class="sv-map-loading">Karte wird geladen …</div>
</div>
