<?php

return [
    // Driving distances. Provider: 'ors' (openrouteservice.org, HeiGIT,
    // Germany; free API key), 'osrm' (an OSRM server URL, e.g. self-hosted) or
    // 'none' (km entered by hand). Only coordinates are sent.
    'routing' => [
        'provider' => env('EXPENSE_CLAIMS_ROUTING', 'ors'),
        'ors_key' => env('EXPENSE_CLAIMS_ORS_KEY', ''),
        'osrm_url' => env('EXPENSE_CLAIMS_OSRM_URL', ''),
    ],
];
