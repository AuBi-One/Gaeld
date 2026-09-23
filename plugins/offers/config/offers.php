<?php

return [
    // Offer numbers: {prefix}-{year}-{NNN}, per organisation and year of the offer date.
    'number_prefix' => env('OFFERS_NUMBER_PREFIX', 'OF'),

    // Validity of a new offer in months, until the organisation sets its own.
    'default_validity_months' => 2,
];
