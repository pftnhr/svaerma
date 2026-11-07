<?php /** @var bool|null $geoapifyActive */ ?>
<?php /** @var bool|null $overpassChActive */ ?>
<footer class="border-top py-3 small text-body-secondary site-footer">
    <div class="container d-flex flex-wrap justify-content-between align-items-end">
        <div>
            <div>
                Kartendaten © <a class="link-secondary" href="https://www.openstreetmap.org/copyright" rel="noopener" target="_blank">OpenStreetMap-Mitwirkende</a>,
                verfügbar unter der <a class="link-secondary" href="https://opendatacommons.org/licenses/odbl/" rel="noopener" target="_blank">ODbL</a>
            </div>
            <div>
                <?php if (!empty($overpassChActive)): ?>
                    Orte: <a class="link-secondary" href="https://overpass.ch/" rel="noopener" target="_blank">overpass.ch</a> ·
                <?php endif; ?>
                Suche: <a class="link-secondary" href="https://photon.komoot.io" rel="noopener" target="_blank">Photon</a> von komoot
                <?php if (!empty($geoapifyActive)): ?>
                    · <a class="link-secondary" href="https://www.geoapify.com/" rel="noopener" target="_blank">Powered by Geoapify</a>
                <?php endif; ?>
            </div>
        </div>
        <div>
            by <a class="link-secondary" href="https://github.com/<?= e(App\App::REPOSITORY) ?>" rel="noopener" target="_blank"><?= e(App\App::NAME) ?></a>
        </div>
    </div>
</footer>
