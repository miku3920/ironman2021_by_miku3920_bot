<?php

require_once('config.php');

// if run from console, set or delete webhook
$handle = curl_init(API_URL . '?method=setWebhook&url=' . WEBHOOK_URL);
curl_setopt_array($handle, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 60
]);
echo curl_exec($handle);
curl_close($handle);
