<?php

require_once('config.php');

function apiRequestWebhook($method, $parameters) {
    if (!is_string($method)) {
        error_log("Method name must be a string\n");
        return false;
    }

    if (!$parameters) {
        $parameters = array();
    } else if (!is_array($parameters)) {
        error_log("Parameters must be an array\n");
        return false;
    }

    $parameters["method"] = $method;

    $payload = json_encode($parameters);
    header('Content-Type: application/json');
    header('Content-Length: ' . strlen($payload));
    echo $payload;

    return true;
}

function exec_curl_request($handle) {
    $response = curl_exec($handle);

    if ($response === false) {
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        error_log("Curl returned error $errno: $error\n");
        curl_close($handle);
        return false;
    }

    $http_code = intval(curl_getinfo($handle, CURLINFO_HTTP_CODE));
    curl_close($handle);

    if ($http_code >= 500) {
        // do not wat to DDOS server if something goes wrong
        sleep(10);
        return false;
    } else if ($http_code != 200) {
        $response = json_decode($response, true);
        error_log("Request has failed with error {$response['error_code']}: {$response['description']}\n");
        if ($http_code == 401) {
            throw new Exception('Invalid access token provided');
        }
        return false;
    } else {
        $response = json_decode($response, true);
        if (isset($response['description'])) {
            error_log("Request was successful: {$response['description']}\n");
        }
        $response = $response['result'];
    }

    return $response;
}

function apiRequest($method, $parameters) {
    if (!is_string($method)) {
        error_log("Method name must be a string\n");
        return false;
    }

    if (!$parameters) {
        $parameters = array();
    } else if (!is_array($parameters)) {
        error_log("Parameters must be an array\n");
        return false;
    }

    foreach ($parameters as $key => &$val) {
        // encoding to JSON array parameters, for example reply_markup
        if (!is_numeric($val) && !is_string($val)) {
            $val = json_encode($val);
        }
    }
    $url = API_URL . $method . '?' . http_build_query($parameters);

    $handle = curl_init($url);
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($handle, CURLOPT_TIMEOUT, 60);

    return exec_curl_request($handle);
}

function apiRequestJson($method, $parameters) {
    if (!is_string($method)) {
        error_log("Method name must be a string\n");
        return false;
    }

    if (!$parameters) {
        $parameters = array();
    } else if (!is_array($parameters)) {
        error_log("Parameters must be an array\n");
        return false;
    }

    foreach ($parameters as &$val) {
        if (is_array($val)) {
            $val = json_encode($val);
        }
    }

    $parameters["method"] = $method;

    $handle = curl_init(API_URL);
    curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($handle, CURLOPT_TIMEOUT, 60);
    curl_setopt($handle, CURLOPT_POST, true);
    curl_setopt($handle, CURLOPT_POSTFIELDS, $parameters);
    curl_setopt($handle, CURLOPT_HTTPHEADER, ['Content-Type: multipart/form-data']);

    return exec_curl_request($handle);
}

function fixMarkdown($str) {
    return str_replace(
        ['\\', '_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'],
        ['\\\\', '\_', '\*', '\[', '\]', '\(', '\)', '\~', '\`', '\>', '\#', '\+', '\-', '\=', '\|', '\{', '\}', '\.', '\!'],
        $str
    );
}

function processMessage($message) {
    $message_id = $message['message_id'];
    $chat_id = $message['chat']['id'];

    if (isset($message['text'])) {
        $text = $message['text'];

        if ($message['chat']['type'] === 'group' or $message['chat']['type'] === 'supergroup') {
            if (strpos($text, "!msg ") === 0) {
                $welcome_text = explode(' ', $text, 2)[1];

                $chat_title = fixMarkdown($message['chat']['title']);
                $user_id = $message['from']['id'];
                $name = fixMarkdown(($message['from']['first_name'] ?? '') . ($message['from']['last_name'] ?? ''));
                $username = fixMarkdown(isset($message['from']['username']) ? '(@' . $message['from']['username'] . ')' : '');

                $response = apiRequestJson("sendMessage", [
                    'chat_id' => $chat_id,
                    'text' => "設定成功！\n\n" . str_replace(
                        ['{chat_title}', '{user_id}', '{name}', '{username}'],
                        [$chat_title, $user_id, $name, $username],
                        $welcome_text
                    ),
                    'parse_mode' => 'MarkdownV2',
                    'disable_web_page_preview' => true,
                    'reply_to_message_id' => $message_id,
                    'allow_sending_without_reply' => true,
                ]);
                if ($response) {
                    if (!is_dir('./data')) {
                        mkdir('./data');
                    }
                    if (!is_dir('./data/' . $chat_id)) {
                        mkdir('./data/' . $chat_id);
                    }
                    file_put_contents('./data/' . $chat_id . '/welcome.txt', $welcome_text, LOCK_EX);
                } else {
                    apiRequestJson("sendMessage", [
                        'chat_id' => $chat_id,
                        'text' => "設定失敗！請將半形符號加上反斜線",
                        'reply_to_message_id' => $message_id,
                        'allow_sending_without_reply' => true,
                    ]);
                }
            }
        }

        if (strpos($text, "!md ") === 0) {
            $md = explode(' ', $text, 2)[1];
            apiRequestJson("sendMessage", [
                'chat_id' => $chat_id,
                'text' => $md,
                'parse_mode' => 'MarkdownV2',
                'reply_to_message_id' => $message_id,
                'allow_sending_without_reply' => true,
            ]);
        }
    } elseif (isset($message['new_chat_members'])) {
        foreach ($message['new_chat_members'] as $new_member) {
            if (!$new_member['is_bot']) {
                $chat_title = fixMarkdown($message['chat']['title']);
                $user_id = $new_member['id'];
                $name = fixMarkdown(($new_member['first_name'] ?? '') . ($new_member['last_name'] ?? ''));
                $username = fixMarkdown(isset($new_member['username']) ? '(@' . $new_member['username'] . ')' : '');

                if (file_exists('./data/' . $chat_id . '/welcome.txt')) {
                    $welcome_text = file_get_contents('./data/' . $chat_id . '/welcome.txt');
                } else {
                    $welcome_text = <<<HEREDOC
                        歡迎 [{name}](tg://user?id={user_id}){username} 來到 {chat_title}

                        中文化：http\://t\.me\/setlanguage\/plain\-zh\-tw
                        更多群組：https\:\/\/tgtw\.cc
                        HEREDOC;
                }

                apiRequestJson("sendMessage", [
                    'chat_id' => $chat_id,
                    'text' => str_replace(
                        ['{chat_title}', '{user_id}', '{name}', '{username}'],
                        [$chat_title, $user_id, $name, $username],
                        $welcome_text
                    ),
                    'parse_mode' => 'MarkdownV2',
                    'disable_web_page_preview' => true,
                    'reply_to_message_id' => $message_id,
                    'allow_sending_without_reply' => true,
                ]);
            }
        }
    }
}

function processInlineQuery($inline_query) {
    if ($inline_query['query'] === '') {
        apiRequestJson('answerInlineQuery', [
            'inline_query_id' => $inline_query['id'],
            'results' => [],
            'cache_time' => 172800,
            'switch_pm_text' => '查看說明',
            'switch_pm_parameter' => 'help_hide'
        ]);
    } else {
        $text = $inline_query['query'];
        preg_match_all('/(?<!\\\\)\*(.*)(?<!\\\\)\*/U', $text, $match);
        $search = $match[0];
        $text_hide_all = preg_replace('/./u', "\u{2588}", $text);
        $text_hash = hash('sha3-256', $text);
        $results = [
            [
                'type' => 'article',
                'id' => 'hide_all',
                'title' => '隱藏所有文字',
                'description' => $text_hide_all,
                'input_message_content' => [
                    'message_text' => $text_hide_all
                ],
                'reply_markup' => [
                    'inline_keyboard' => [[[
                        'text' => '顯示訊息',
                        'callback_data' => $text_hash
                    ]]]
                ]
            ]
        ];
        if ($search !== []) {
            $replace = str_replace('\*', '*', $match[1]);
            $replace_hide = preg_replace('/./u', "\u{2588}", $replace);
            $text_hide = str_replace('\*', '*', str_replace($search, $replace_hide, $text));
            if ($text_hide !== '') {
                $text_fix = str_replace('\*', '*', str_replace($match[0], $match[1], $text));
                $text_fix_hash = hash('sha3-256', $text_fix);
                array_unshift($results, [
                    'type' => 'article',
                    'id' => 'hide',
                    'title' => '隱藏部份文字',
                    'description' => $text_hide,
                    'input_message_content' => [
                        'message_text' => $text_hide
                    ],
                    'reply_markup' => [
                        'inline_keyboard' => [[[
                            'text' => '顯示訊息',
                            'callback_data' => $text_fix_hash
                        ]]]
                    ]
                ]);
            }
        }

        apiRequestJson('answerInlineQuery', [
            'inline_query_id' => $inline_query['id'],
            'results' => $results,
            'cache_time' => 172800
        ]);
    }
}

function processCallbackQuery($callback_query) {
    $data = $callback_query['data'];
    $user_id = $callback_query['from']['id'];

    if (strpos($data, "channel_post ") === 0) {
        $msg_id = $callback_query['message']['message_id'];
        $chat_id = $callback_query['message']['chat']['id'];

        $option = explode(' ', $data, 2)[1];
        $path = "./data/$chat_id/$msg_id.json";
        if (file_exists($path)) {
            $fp = fopen($path, 'r+');
            if (flock($fp, LOCK_EX)) {
                $vote = json_decode(fgets($fp), true);
                if ($vote[$user_id] === $option) {
                    $vote[$user_id] = '';
                    apiRequestJson('answerCallbackQuery', [
                        'callback_query_id' => $callback_query['id'],
                        'text' => '收回 ' . $option
                    ]);
                } else {
                    $vote[$user_id] = $option;
                    apiRequestJson('answerCallbackQuery', [
                        'callback_query_id' => $callback_query['id'],
                        'text' => $option
                    ]);
                }
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($vote));
                flock($fp, LOCK_UN);
                fclose($fp);
            }
        } else {
            if (!is_dir('./data')) {
                mkdir('./data');
            }
            if (!is_dir('./data/' . $chat_id)) {
                mkdir('./data/' . $chat_id);
            }
            $vote = [$user_id => $option];
            apiRequestJson('answerCallbackQuery', [
                'callback_query_id' => $callback_query['id'],
                'text' => $option
            ]);
            file_put_contents($path, json_encode($vote), LOCK_EX);
        }
        $count_like = 0;
        $count_neutral = 0;
        $count_unlike = 0;
        foreach ($vote as $val) {
            $count_like += $val === '👍';
            $count_neutral += $val === '➖';
            $count_unlike += $val === '👎';
        }
        $count_like = $count_like === 0 ? '' : ' ' . $count_like;
        $count_neutral = $count_neutral === 0 ? '' : ' ' . $count_neutral;
        $count_unlike = $count_unlike === 0 ? '' : ' ' . $count_unlike;
        apiRequestJson('editMessageReplyMarkup', [
            'chat_id' => $chat_id,
            'message_id' => $msg_id,
            'reply_markup' => [
                'inline_keyboard' => [[
                    [
                        'text' => '👍' . $count_like,
                        'callback_data' => 'channel_post 👍'
                    ], [
                        'text' => '➖' . $count_neutral,
                        'callback_data' => 'channel_post ➖'
                    ], [
                        'text' => '👎' . $count_unlike,
                        'callback_data' => 'channel_post 👎'
                    ],
                ]]
            ]
        ]);
    } elseif (file_exists('./inline_hash/' . $data . '.txt')) {
        apiRequestJson('answerCallbackQuery', [
            'callback_query_id' => $callback_query['id'],
            'text' => file_get_contents('./inline_hash/' . $data . '.txt'),
            'show_alert' => true,
            'cache_time' => 172800
        ]);
    } else {
        apiRequestJson('answerCallbackQuery', [
            'callback_query_id' => $callback_query['id'],
            'text' => '找不到資料',
            'cache_time' => 172800
        ]);
    }
}

function processChosenInlineResult($chosen_inline_result) {
    $text = $chosen_inline_result['query'];
    if ($chosen_inline_result['result_id'] === 'hide_all') {
        $text_hash = hash('sha3-256', $text);
        if (!is_dir('./inline_hash')) {
            mkdir('./inline_hash');
        }
        file_put_contents('./inline_hash/' . $text_hash . '.txt', $text, LOCK_EX);
    } elseif ($chosen_inline_result['result_id'] === 'hide') {
        preg_match_all('/(?<!\\\\)\*(.*)(?<!\\\\)\*/U', $text, $match);
        $text_fix = str_replace('\*', '*', str_replace($match[0], $match[1], $text));
        $text_fix_hash = hash('sha3-256', $text_fix);
        if (!is_dir('./inline_hash')) {
            mkdir('./inline_hash');
        }
        file_put_contents('./inline_hash/' . $text_fix_hash . '.txt', $text_fix, LOCK_EX);
    }
}

function processChannelPost($channel_post) {
    apiRequestJson('editMessageReplyMarkup', [
        'chat_id' => $channel_post['chat']['id'],
        'message_id' => $channel_post['message_id'],
        'reply_markup' => [
            'inline_keyboard' => [[
                [
                    'text' => '👍',
                    'callback_data' => 'channel_post 👍'
                ], [
                    'text' => '➖',
                    'callback_data' => 'channel_post ➖'
                ], [
                    'text' => '👎',
                    'callback_data' => 'channel_post 👎'
                ],
            ]]
        ]
    ]);
}

function processUpdate($update) {
    if (isset($update['message'])) {
        processMessage($update['message']);
    } elseif (isset($update['inline_query'])) {
        processInlineQuery($update['inline_query']);
    } elseif (isset($update['callback_query'])) {
        processCallbackQuery($update['callback_query']);
    } elseif (isset($update['chosen_inline_result'])) {
        processChosenInlineResult($update['chosen_inline_result']);
    } elseif (isset($update['channel_post'])) {
        processChannelPost($update['channel_post']);
    }
}

if (php_sapi_name() == 'cli') {
    $update_id = 0;
    while (true) {
        $response = apiRequest("getUpdates", array('offset' => $update_id, 'timeout' => 600));
        foreach ($response as $update) {
            processUpdate($update);
            print_r($update);
        }
        $latest = count($response) - 1;
        if ($latest !== -1) {
            $update_id = $response[$latest]['update_id'] + 1;
        }
    }
}

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) {
    // receive wrong update, must not happen
    exit;
}

processUpdate($update);
