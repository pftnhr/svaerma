<?php

declare(strict_types=1);

// Places created by the user ("+ Eigener Ort"), e.g. an office that no map service knows.
// Check-ins refer to them via checkins.venue_ref = 'own:<id>', so repeated visits are grouped.
return static function (PDO $pdo): void {
    $pdo->exec('CREATE TABLE own_places (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        lat REAL NOT NULL,
        lon REAL NOT NULL,
        created_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE INDEX idx_own_places_lat ON own_places (lat)');
};
