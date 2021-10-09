<?php

define('WEBHOOK_URL', 'https://my-site.example.com/secret-path-for-webhooks/');

// if run from console, set or delete webhook
apiRequest('setWebhook', array('url' => isset($argv[1]) && $argv[1] == 'delete' ? '' : WEBHOOK_URL));
