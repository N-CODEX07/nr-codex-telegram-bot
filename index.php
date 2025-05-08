<?php

// Load environment variables
$botToken = getenv('BOT_TOKEN') ?: '7711988726:AAGimofS_-3_2zU1xu9e7DQalJ756nj3hKI';
$channelId = getenv('CHANNEL_ID') ?: '@nr_codex';

// Bot configuration
define('BOT_TOKEN', $botToken);
define('CHANNEL_ID', $channelId);
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');

// API endpoints for token generation
$API_ENDPOINTS = [
    'https://akiru-jwt-10.vercel.app/token?uid={Uid}&password={Password}',
    'https://akiru-jwt-9.vercel.app/token?uid={Uid}&password={Password}',
    'https://akiru-jwt-8.vercel.app/token?uid={Uid}&password={Password}',
    'https://akiru-jwt-7.vercel.app/token?uid={Uid}&password={Password}',
    'https://akiru-jwt-6.vercel.app/token?uid={Uid}&password={Password}',
    'https://akiru-jwt-5.vercel.app/token?uid={Uid}&password={Password}',
];

// Error logging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Webhook or polling setup
$update = json_decode(file_get_contents('php://input'), true);

// Handle updates
if (!empty($update)) {
    processUpdate($update);
} else {
    // For debugging on Render
    if (getenv('RENDER')) {
        echo "Bot is running. Waiting for updates...";
    }
}

function processUpdate($update) {
    if (isset($update['message'])) {
        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        $text = isset($message['text']) ? $message['text'] : '';
        $from_id = $message['from']['id'];

        // Handle commands
        if ($text === '/start') {
            sendWelcomeMessage($chat_id);
        } elseif (isset($message['document'])) {
            handleDocument($chat_id, $from_id, $message['document']);
        }
    } elseif (isset($update['callback_query'])) {
        handleCallbackQuery($update['callback_query']);
    }
}

function sendWelcomeMessage($chat_id) {
    $welcome_text = "Welcome to NR CODEX JWT! First, join our official Telegram channel.\n\n"
                  . "NR CODEX JWT:\n"
                  . "🤖 100% COMPLETION JWT Token Fetcher Bot\n\n"
                  . "Send me a JSON file with account credentials in this format:\n\n"
                  . "[\n"
                  . "  {\"uid\": \"1234567890\", \"password\": \"PASSWORD1\"},\n"
                  . "  {\"uid\": \"0987654321\", \"password\": \"PASSWORD2\"}\n"
                  . "]\n\n"
                  . "⚡ Uses all 5 APIs simultaneously (55 concurrent requests)\n"
                  . "🔄 Automatically retries failed accounts (max 10 times)\n"
                  . "💯 Processes valid accounts even if some are invalid";

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => 'Join Channel', 'url' => 'https://t.me/nr_codex'],
                ['text' => 'Check', 'callback_data' => 'check_membership']
            ]
        ]
    ];

    sendMessage($chat_id, $welcome_text, $keyboard);
}

function handleCallbackQuery($callback_query) {
    global $API_ENDPOINTS;
    $chat_id = $callback_query['message']['chat']['id'];
    $user_id = $callback_query['from']['id'];
    $data = $callback_query['data'];

    if ($data === 'check_membership') {
        // Check if user is a member of the channel
        $url = API_URL . "getChatMember?chat_id=" . CHANNEL_ID . "&user_id=$user_id";
        $response = json_decode(file_get_contents($url), true);

        if ($response['ok'] && in_array($response['result']['status'], ['member', 'administrator', 'creator'])) {
            sendMessage($chat_id, "✅ You have joined the channel! Please upload the JSON file with account credentials.");
        } else {
            sendMessage($chat_id, "❌ Please join our channel first: @nr_codex", [
                'inline_keyboard' => [
                    [
                        ['text' => 'Join Channel', 'url' => 'https://t.me/nr_codex'],
                        ['text' => 'Check', 'callback_data' => 'check_membership']
                    ]
                ]
            ]);
        }
    }
}

function handleDocument($chat_id, $user_id, $document) {
    global $API_ENDPOINTS;

    // Verify channel membership
    $url = API_URL . "getChatMember?chat_id=" . CHANNEL_ID . "&user_id=$user_id";
    $response = json_decode(file_get_contents($url), true);

    if (!$response['ok'] || !in_array($response['result']['status'], ['member', 'administrator', 'creator'])) {
        sendMessage($chat_id, "❌ Please join our channel first: @nr_codex", [
            'inline_keyboard' => [
                [
                    ['text' => 'Join Channel', 'url' => 'https://t.me/nr_codex'],
                    ['text' => 'Check', 'callback_data' => 'check_membership']
                ]
            ]
        ]);
        return;
    }

    // Check if the file is a JSON file
    if ($document['mime_type'] !== 'application/json') {
        sendMessage($chat_id, "❌ Please upload a valid JSON file.");
        return;
    }

    // Get file path
    $file_id = $document['file_id'];
    $file_info = json_decode(file_get_contents(API_URL . "getFile?file_id=$file_id"), true);
    if (!$file_info['ok']) {
        sendMessage($chat_id, "❌ Error retrieving file.");
        return;
    }

    $file_path = $file_info['result']['file_path'];
    $file_url = "https://api.telegram.org/file/bot" . BOT_TOKEN . "/$file_path";
    $json_content = file_get_contents($file_url);
    $accounts = json_decode($json_content, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($accounts)) {
        sendMessage($chat_id, "❌ Invalid JSON format. Please check the file and try again.");
        return;
    }

    // Process accounts
    processAccounts($chat_id, $accounts);
}

function processAccounts($chat_id, $accounts) {
    global $API_ENDPOINTS;
    $start_time = microtime(true);
    $total_accounts = count($accounts);
    $successful = 0;
    $failed = 0;
    $invalid = 0;
    $tokens = [];
    $max_retries = 10;
    $concurrent_requests = 55;

    // Send initial processing message
    $progress_message = sendMessage($chat_id, "⏳ Processing... ▰▰▰▰▰▰▰▰▰▱");

    // Split accounts into chunks for concurrent processing
    $chunks = array_chunk($accounts, $concurrent_requests);
    $mh = curl_multi_init();
    $curl_handles = [];

    foreach ($chunks as $chunk_index => $chunk) {
        // Update progress
        $progress = round(($chunk_index / count($chunks)) * 100);
        $progress_bar = str_repeat("■", floor($progress / 10)) . str_repeat("□", 10 - floor($progress / 10));
        editMessage($chat_id, $progress_message['result']['message_id'], "⏳ Processing... $progress_bar $progress%");

        foreach ($chunk as $account) {
            if (!isset($account['uid']) || !isset($account['password'])) {
                $invalid++;
                continue;
            }

            $uid = $account['uid'];
            $password = $account['password'];
            $retry_count = 0;
            $success = false;

            while ($retry_count < $max_retries && !$success) {
                // Select a random API endpoint
                $api_url = $API_ENDPOINTS[array_rand($API_ENDPOINTS)];
                $api_url = str_replace(['{Uid}', '{Password}'], [$uid, $password], $api_url);

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $api_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_multi_add_handle($mh, $ch);
                $curl_handles[] = $ch;

                // Execute requests in parallel
                do {
                    curl_multi_exec($mh, $running);
                    curl_multi_select($mh);
                } while ($running > 0);

                foreach ($curl_handles as $index => $ch) {
                    $response = curl_multi_getcontent($ch);
                    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);
                    unset($curl_handles[$index]);

                    if ($http_code === 200) {
                        $data = json_decode($response, true);
                        if (isset($data['token'])) {
                            $tokens[] = ['token' => $data['token']];
                            $successful++;
                            $success = true;
                        } else {
                            $retry_count++;
                        }
                    } else {
                        $retry_count++;
                    }
                }
            }

            if (!$success) {
                $failed++;
            }
        }
    }

    curl_multi_close($mh);

    // Calculate processing time
    $end_time = microtime(true);
    $processing_time = round($end_time - $start_time, 2);

    // Update final progress
    editMessage($chat_id, $progress_message['result']['message_id'], "✅ Processing completed!\n\n"
        . "📑 JWT TOKEN\n"
        . "Count: $total_accounts\n"
        . "Successful: $successful\n"
        . "Failed: $failed\n"
        . "Invalid: $invalid\n"
        . "Time: {$processing_time}s\n"
        . "API use: " . count($API_ENDPOINTS));

    // Save tokens to JSON file
    $output_file = 'tokens_' . time() . '.json';
    file_put_contents($output_file, json_encode($tokens, JSON_PRETTY_PRINT));

    // Send the output file
    $result = sendDocument($chat_id, $output_file);
    if (!$result['ok']) {
        sendMessage($chat_id, "✅ Processing completed but there was an error sending results. Please try again.");
    }

    // Clean up
    unlink($output_file);
}

function sendMessage($chat_id, $text, $reply_markup = null) {
    $url = API_URL . "sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    if ($reply_markup) {
        $data['reply_markup'] = json_encode($reply_markup);
    }
    return json_decode(postRequest($url, $data), true);
}

function editMessage($chat_id, $message_id, $text) {
    $url = API_URL . "editMessageText";
    $data = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    return json_decode(postRequest($url, $data), true);
}

function sendDocument($chat_id, $file_path) {
    $url = API_URL . "sendDocument";
    $data = [
        'chat_id' => $chat_id,
        'document' => new CURLFile($file_path)
    ];
    return json_decode(postRequest($url, $data), true);
}

function postRequest($url, $data) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}