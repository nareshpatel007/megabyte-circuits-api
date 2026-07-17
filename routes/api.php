<?php

use Illuminate\Support\Facades\Route;

// Load separated routes
require base_path('routes/api/cron.php');
require base_path('routes/api/frontend.php');
require base_path('routes/api/admin.php');