<?php /** @var bool|null $geoapifyActive */ ?>
<?php /** @var bool|null $overpassChActive */ ?>
<footer class="sv-footer">
    <div class="sv-footer__inner">
        <p>
            Kartendaten © <a href="https://www.openstreetmap.org/copyright" rel="noopener" target="_blank">OpenStreetMap-Mitwirkende</a>
            (<a href="https://opendatacommons.org/licenses/odbl/" rel="noopener" target="_blank">ODbL</a>)
            <?php if (!empty($overpassChActive)): ?>
                · Orte: <a href="https://overpass.ch/" rel="noopener" target="_blank">overpass.ch</a>
            <?php endif; ?>
            · Suche: <a href="https://photon.komoot.io" rel="noopener" target="_blank">Photon</a> von komoot
            <?php if (!empty($geoapifyActive)): ?>
                · <a href="https://www.geoapify.com/" rel="noopener" target="_blank">Powered by Geoapify</a>
            <?php endif; ?>
        </p>
        <p>
            by <a href="https://github.com/<?= e(App\App::REPOSITORY) ?>" rel="noopener" target="_blank"><?= e(App\App::NAME) ?></a>
        </p>
    </div>
</footer>
