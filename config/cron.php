<?php

return [
    'secret_key' => env('CRON_SECRET_KEY', env('API_TOKEN')),
    'email_limit' => (int) env('CRON_EMAIL_LIMIT', 3),
];
