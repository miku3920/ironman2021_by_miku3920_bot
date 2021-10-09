<?php

// git update-index --assume-unchanged config.php
declare(strict_types=1);
set_time_limit(0);
ini_set('date.timezone', 'Asia/Taipei');

define('BOT_TOKEN', '12345678:replace-me-with-real-token');
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('WEBHOOK_URL', 'https://my-site.example.com/secret-path-for-webhooks/');