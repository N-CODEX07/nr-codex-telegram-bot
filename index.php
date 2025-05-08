<?php

// Bot configuration
define('BOT_TOKEN', '7711988726:AAGimofS_-3_2zU1xu9e7DQalJ756nj3hKI');
define('CHANNEL_USERNAME', '@nr_codes');
define('API_BASE_URLS', [
    'https://akiru-jwt-5.vercel.app/token?uid={Uid}&password={Password}',
    'https://akiru-jwt-6.vercel.app/token?uid={Uid}&password={Password}',
    'https://akiru-jwt-7.vercel.app/token?uid={Uid}&password={Password}',
    'https://akiru-jwt-8.vercel.app/token?uid={Uid}&password={Password}',
    'https://akiru-jwt-9.vercel.app/token?uid={Uid}&password={Password}',
    'https://akiru-jwt-10.vercel.app/token?uid={Uid}&password={Password}',
]);
define('MAX_RETRIES', 10);
define('CONCURRENT_REQUESTS', 55);
define('TEMP_DIR', sys_get_temp_dir() . '/jwt_bot/');

// Ensure temp directory exists
if (!file_exists(TEMP_DIR)) {
    mkdir(TEMP_DIR, 0777, true);
}

// Set webhook or use polling
$update = json_decode(file_get_contents('php://input'), true);

// Telegram API request function
function sendTelegramRequest($method, $params = []) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/$method";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);
    return json_decode($result, true);
}

// Send message
function sendMessage($chat_id, $text, $reply_markup = null) {
    $params = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML',
    ];
    if ($reply_markup) {
        $params['reply_markup'] = json_encode($reply_markup);
    }
    return sendTelegramRequest('sendMessage', $params);
}

// Send document
function sendDocument($chat_id, $file_path, $caption = '') {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendDocument";
    $post_fields = [
        'chat_id' => $chat_id,
        'caption' => $caption,
        'document' => new CURLFile($file_path),
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);
    return json_decode($result, true);
}

// Edit message
function editMessage($chat_id, $message_id, $text, $reply_markup = null) {
    $params = [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text,
        'parse_mode' => 'HTML',
    ];
    if ($reply_markup) {
        $params['reply_markup'] = json_encode($reply_markup);
    }
    return sendTelegramRequest('editMessageText', $params);
}

// Check if user is a member of the channel
function isChannelMember($chat_id) {
    $params = [
        'chat_id' => CHANNEL_USERNAME,
        'user_id' => $chat_id,
    ];
    $result = sendTelegramRequest('getChatMember', $params);
    return isset($result['result']) && in_array($result['result']['status'], ['member', 'administrator', 'creator']);
}

// Make API request to fetch JWT token
function fetchJwtToken($uid, $password, $api_url) {
    $url = str_replace(['{Uid}', '{Password}'], [urlencode($uid), urlencode($password)], $api_url);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['response' => $response, 'http_code' => $http_code];
}

// Process credentials with retries
function processCredential($credential, &$results, &$failed_count, &$invalid_count) {
    $uid = $credential['uid'] ?? '';
    $password = $credential['password'] ?? '';
    if (empty($uid) || empty($password)) {
        $invalid_count++;
        return;
    }

    $attempts = 0;
    $success = false;
    while ($attempts < MAX_RETRIES && !$success) {
        $api_url = API_BASE_URLS[array_rand(API_BASE_URLS)];
        $result = fetchJwtToken($uid, $password, $api_url);
        $attempts++;

        if ($result['http_code'] == 200) {
            $data = json_decode($result['response'], true);
            if (isset($data['token'])) {
                $results[] = ['token' => $data['token']];
                $success = true;
            } else {
                $invalid_count++;
                break;
            }
        } else {
            if ($attempts == MAX_RETRIES) {
                $failed_count++;
            }
        }
    }
}

// Handle incoming updates
if ($update) {
    $chat_id = $update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? null;
    $message = $update['message'] ?? null;
    $callback_query = $update['callback_query'] ?? null;

    if (!$chat_id) {
        exit;
    }

    // Handle /start command
    if ($message && isset($message['text']) && $message['text'] == '/start') {
        $welcome_text = "Welcome to <b>NR CODEX JWT</b>! First, join our official Telegram channel:\n\n";
        $reply_markup = [
            'inline_keyboard' => [
                [
                    ['text' => 'Join Channel', 'url' => 'https://t.me/nr_codes'],
                    ['text' => 'Check', 'callback_data' => 'check_membership'],
                ],
            ],
        ];
        sendMessage($chat_id, $welcome_text, $reply_markup);
    }

    // Handle callback query (Check button)
    if ($callback_query && $callback_query['data'] == 'check_membership') {
        $message_id = $callback_query['message']['message_id'];
        if (isChannelMember($chat_id)) {
            $info_text = "<b>NR CODEX JWT</b>:\n🤖 100% COMPLETION JWT Token Fetcher Bot\n\n" .
                         "Send me a JSON file with account credentials in this format:\n\n" .
                         "<code>[\n  {\"uid\": \"1234567890\", \"password\": \"PASSWORD1\"},\n  {\"uid\": \"0987654321\", \"password\": \"PASSWORD2\"}\n]</code>\n\n" .
                         "⚡ Uses all 5 APIs simultaneously (55 concurrent requests)\n" .
                         "🔄 Automatically retries failed accounts (max 10 times)\n" .
                         "💯 Processes valid accounts even if some are invalid";
            editMessage($chat_id, $message_id, $info_text);
        } else {
            editMessage($chat_id, $message_id, "Please join our channel first!", [
                'inline_keyboard' => [
                    [
                        ['text' => 'Join Channel', 'url' => 'https://t.me/nr_codes'],
                        ['text' => 'Check', 'callback_data' => 'check_membership'],
                    ],
                ],
            ]);
        }
    }

    // Handle JSON file upload
    if ($message && isset($message['document']) && $message['document']['mime_type'] == 'application/json') {
        if (!isChannelMember($chat_id)) {
            sendMessage($chat_id, "Please join our channel first!", [
                'inline_keyboard' => [
                    [
                        ['text' => 'Join Channel', 'url' => 'https://t.me/nr_codes'],
                        ['text' => 'Check', 'callback_data' => 'check_membership'],
                    ],
                ],
            ]);
            exit;
        }

        // Download JSON file
        $file_id = $message['document']['file_id'];
        $file = sendTelegramRequest('getFile', ['file_id' => $file_id]);
        $file_path = $file['result']['file_path'];
        $file_url = "https://api.telegram.org/file/bot" . BOT_TOKEN . "/$file_path";
        $local_file = TEMP_DIR . $message['document']['file_name'];
        file_put_contents($local_file, file_get_contents($file_url));

        // Parse JSON
        $json_content = file_get_contents($local_file);
        $credentials = json_decode($json_content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($credentials)) {
            sendMessage($chat_id, "Invalid JSON format! Please send a valid JSON file.");
            unlink($local_file);
            exit;
        }

        // Start processing
        $start_time = microtime(true);
        $total_count = count($credentials);
        $results = [];
        $failed_count = 0;
        $invalid_count = 0;

        // Send initial processing message
        $progress_message = sendMessage($chat_id, "⏳ Processing... ▰▱▱▱▱▱▱▱▱▱ 0%");
        $message_id = $progress_message['result']['message_id'];

        // Process credentials in chunks for concurrency
        $chunks = array_chunk($credentials, CONCURRENT_REQUESTS);
        $progress = 0;
        $total_processed = 0;

        foreach ($chunks as $chunk) {
            $mh = curl_multi_init();
            $handles = [];

            foreach ($chunk as $credential) {
                $ch = curl_init();
                $api_url = API_BASE_URLS[array_rand(API_BASE_URLS)];
                $url = str_replace(['{Uid}', '{Password}'], [urlencode($credential['uid'] ?? ''), urlencode($credential['password'] ?? '')], $api_url);
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_multi_add_handle($mh, $ch);
                $handles[] = $ch;
            }

            // Execute concurrent requests
            do {
                curl_multi_exec($mh, $running);
                curl_multi_select($mh);
            } while ($running > 0);

            // Process responses
            foreach ($handles as $index => $ch) {
                $result = curl_multi_getcontent($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $credential = $chunk[$index];

                if ($http_code == 200) {
                    $data = json_decode($result, true);
                    if (isset($data['token'])) {
                        $results[] = ['token' => $data['token']];
                    } else {
                        $invalid_count++;
                    }
                } else {
                    // Retry logic for failed requests
                    processCredential($credential, $results, $failed_count, $invalid_count);
                }

                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }

            curl_multi_close($mh);

            // Update progress
            $total_processed += count($chunk);
            $progress = ($total_processed / $total_count) * 100;
            $bar = str_repeat('▰', floor($progress / 10)) . str_repeat('▱', 10 - floor($progress / 10));
            editMessage($chat_id, $message_id, "⏳ Processing... $bar " . number_format($progress, 2) . "%");
        }

        // Calculate processing time
        $processing_time = microtime(true) - $start_time;

        // Prepare summary
        $successful_count = count($results);
        $summary = "📑 <b>JWT TOKEN</b>\n" .
                   "Count: $total_count\n" .
                   "Successful: $successful_count\n" .
                   "Failed: $failed_count\n" .
                   "Invalid: $invalid_count\n" .
                   "Time: " . number_format($processing_time, 2) . "s\n" .
                   "API use: " . count(API_BASE_URLS);

        // Save results to JSON file
        $output_file = TEMP_DIR . "jwt_results_" . time() . ".json";
        file_put_contents($output_file, json_encode($results, JSON_PRETTY_PRINT));

        // Send summary and file
        editMessage($chat_id, $message_id, $summary);
        $send_result = sendDocument($chat_id, $output_file, "✅ Processing completed! Here are your JWT tokens.");

        if (!$send_result['ok']) {
            sendMessage($chat_id, "✅ Processing completed but there was an error sending results. Please try again.");
        }

        // Clean up
        unlink($local_file);
        unlink($output_file);
    }
}

?>
