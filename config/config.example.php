<?php

/*
 * Copy to config/config.php (setup does this for you) and adjust.
 * Every key is optional – missing keys fall back to the defaults in src/Config.php.
 */

return [
    // 'prod' hides error details, 'dev' shows them and allows plain HTTP.
    'env' => 'prod',

    // Public base URL without trailing slash, used for absolute links (API, export).
    'base_url' => 'https://checkin.gigold.me',

    // Contact address sent in the User-Agent to Overpass/Photon/Nominatim (fair use).
    'contact_email' => 'you@example.com',

    // Default timezone for display and for check-ins without a timezone.
    'timezone' => 'Europe/Berlin',

    // Daily check for a newer release on GitHub, shown under Settings → System.
    // 'update_check' => false,

    // Reverse proxies (IPs or CIDR ranges) whose X-Forwarded-Proto/X-Forwarded-For headers
    // are trusted. Default: private networks (proxy on the same host or LAN). [] trusts none.
    // 'trusted_proxies' => ['127.0.0.1', '10.0.0.0/8'],

    // OpenStreetMap endpoints – swap for your own instances if you like.
    'osm' => [
        'overpass_url' => 'https://overpass-api.de/api/interpreter',
        'overpass_fallback_urls' => [], // more can also be added in the app settings
        'overpass_ch_url' => 'https://api.overpass.ch/v1/interpreter', // used with the key from the settings
        'photon_url' => 'https://photon.komoot.io/api/',
        'photon_reverse_url' => 'https://photon.komoot.io/reverse', // nearby fallback when Overpass fails
        'geoapify_url' => 'https://api.geoapify.com/v2/places',     // used only with an API key (settings)
        'nominatim_url' => 'https://nominatim.openstreetmap.org/search',
        'search_provider' => 'photon', // 'photon' or 'nominatim'
        'tile_url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
        'cache_ttl' => 86400, // seconds
        'timeout' => 15,      // HTTP timeout in seconds
        'overpass_timeout' => 12, // per Overpass endpoint, then fallback
    ],
];
