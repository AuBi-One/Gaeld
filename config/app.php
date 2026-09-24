<?php

// Only the keys that differ from the framework's defaults (Laravel merges the rest in).
return [
    // Timezone for dates, "today" and the scheduler: Swiss time unless the deployment says otherwise.
    'timezone' => env('APP_TIMEZONE', 'Europe/Zurich'),
];
