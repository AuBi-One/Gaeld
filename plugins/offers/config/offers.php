<?php

return [
    // Offer numbers: {prefix}-{year}-{NNN}, per organisation and year of the offer date.
    'number_prefix' => env('OFFERS_NUMBER_PREFIX', 'OF'),

    // Validity in days when no template sets one.
    'default_validity_days' => 30,
];
