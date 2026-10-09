<?php

return [
    // Use a shared store for both the issuance lock and the quota counters.
    'cache_store' => env('INVITATION_CACHE_STORE', 'database'),

    'limits' => [
        'actor_per_minute' => (int) env('INVITATION_ACTOR_PER_MINUTE', 10),
        'actor_per_hour' => (int) env('INVITATION_ACTOR_PER_HOUR', 50),
        'organization_per_hour' => (int) env('INVITATION_ORGANIZATION_PER_HOUR', 100),
        'recipient_per_hour' => (int) env('INVITATION_RECIPIENT_PER_HOUR', 3),
        'recipient_per_day' => (int) env('INVITATION_RECIPIENT_PER_DAY', 5),
        'global_per_minute' => (int) env('INVITATION_GLOBAL_PER_MINUTE', 50),
        'global_per_hour' => (int) env('INVITATION_GLOBAL_PER_HOUR', 500),
    ],
];
