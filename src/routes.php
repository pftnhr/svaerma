<?php

declare(strict_types=1);

use App\Controller\AdminController;
use App\Controller\ApiController;
use App\Controller\AuthController;
use App\Controller\CheckinController;
use App\Controller\PlaceController;
use App\Controller\PwaController;
use App\Controller\SettingsController;
use App\Controller\SetupController;
use App\Controller\TransferController;
use App\Http\Router;

/*
 * All routes. Options:
 *   public            => true  no login required
 *   guest             => true  readable without login when public mode is on (settings)
 *   guest_redirect    => path  where visitors go instead of the login when public mode is on
 *   setup             => true  only reachable before setup is complete
 *   api               => true  bearer token instead of session/CSRF (see App::dispatchApi())
 *   ignore_migrations => true  reachable while migrations are pending
 */
return static function (Router $r): void {
    $r->get('/setup', [SetupController::class, 'form'], ['setup' => true]);
    $r->post('/setup', [SetupController::class, 'submit'], ['setup' => true]);

    $r->get('/sw.js', [PwaController::class, 'serviceWorker'], ['public' => true, 'ignore_migrations' => true]);

    $r->get('/login', [AuthController::class, 'form'], ['public' => true]);
    $r->post('/login', [AuthController::class, 'login'], ['public' => true]);
    $r->post('/logout', [AuthController::class, 'logout'], ['ignore_migrations' => true]);

    $r->get('/', [CheckinController::class, 'create'], ['guest_redirect' => '/checkins']);
    $r->post('/checkin', [CheckinController::class, 'store']);
    $r->get('/checkins', [CheckinController::class, 'index'], ['guest' => true]);
    $r->get('/checkin/{id:\d+}{slug:(?:-[^/]*)?}', [CheckinController::class, 'show'], ['guest' => true]);
    $r->get('/checkin/{id:\d+}/edit', [CheckinController::class, 'edit']);
    $r->post('/checkin/{id:\d+}/edit', [CheckinController::class, 'update']);
    $r->post('/checkin/{id:\d+}/delete', [CheckinController::class, 'delete']);
    $r->get('/map', [CheckinController::class, 'map'], ['guest' => true]);
    $r->get('/map/data', [CheckinController::class, 'mapData'], ['guest' => true]);
    $r->get('/places/nearby', [PlaceController::class, 'nearby']);
    $r->get('/places/search', [PlaceController::class, 'search']);

    $r->get('/settings', [SettingsController::class, 'index'], ['ignore_migrations' => true]);
    $r->post('/settings', [SettingsController::class, 'save']);
    $r->post('/settings/tokens', [SettingsController::class, 'createToken']);
    $r->post('/import/swarm', [TransferController::class, 'import']);
    $r->get('/export.json', [TransferController::class, 'exportJson']);
    $r->get('/export.geojson', [TransferController::class, 'exportGeoJson']);
    $r->post('/settings/tokens/{id:\d+}/revoke', [SettingsController::class, 'revokeToken']);
    $r->post('/settings/logout-all', [SettingsController::class, 'logoutAll'], ['ignore_migrations' => true]);

    $r->get('/admin', [AdminController::class, 'index'], ['ignore_migrations' => true]);
    $r->post('/admin/migrate', [AdminController::class, 'migrate'], ['ignore_migrations' => true]);
    $r->post('/admin/backup', [AdminController::class, 'backup'], ['ignore_migrations' => true]);
    $r->post('/admin/osm-test', [AdminController::class, 'osmTest']);

    // REST API v1 – bearer token from the settings page
    $r->get('/api/v1/checkins', [ApiController::class, 'listCheckins'], ['api' => true]);
    $r->post('/api/v1/checkins', [ApiController::class, 'createCheckin'], ['api' => true]);
    $r->get('/api/v1/checkins/{id:\d+}', [ApiController::class, 'showCheckin'], ['api' => true]);
    $r->get('/api/v1/places/nearby', [ApiController::class, 'nearbyPlaces'], ['api' => true]);
};
