<?php

// Bot configuration
define('BOT_TOKEN', '7711988726:AAGimofS_-3_2zU1xu9e7DQalJ756nj3hKI');
define('CHANNEL_USERNAME', '@nr_codex');
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

// Simple lock file to prevent concurrent processing per user
function acquireLock($chat_id) {
    $lock_file = TEMP_DIR . "lock_$chat_id";
    if (file_exists($lock_file) && (time() - filemtime($lock_file)) < 300) {
        return false; // Lock exists and is recent (5 minutes)
    }
    file_put_contents($lock_file, time());
    return true;
}

function releaseLock($chat_id) {
    $lock_file = TEMP_DIR . "lock_$chat_id";
    if (file_exists($lock_file)) {
        unlink($lock_file);
    }
}

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
        'parse_mode' => 'Markdown',
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
        'parse_mode' => 'Markdown',
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
$update = json_decode(file_get_contents('php://input'), true);
if ($update) {
    $chat_id = $update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? null;
    $message = $update['message'] ?? null;
    $callback_query = $update['callback_query'] ?? null;
    $user = $update['message']['from'] ?? $update['callback_query']['from'] ?? null;
    $username = $user['username'] ?? $user['first_name'] ?? 'User';

    if (!$chat_id) {
        exit;
    }

    // Handle /start command
    if ($message && isset($message['text']) && $message['text'] == '/start') {
        $welcome_text = "👋 *Hey $username!* Welcome to *NR CODEX JWT*! 🚀\n\n" .
                        "I'm your go-to bot for generating JWT tokens for Free Fire guest IDs. To get started, please join our official Telegram channel for updates and support:\n\n" .
                        "📢 *[@nr_codex](https://t.me/nr_codex)*\n\n" .
                        "Click below to join and verify your membership! 😊";
        $reply_markup = [
            'inline_keyboard' => [
                [
                    ['text' => 'Join Channel 🌟', 'url' => 'https://t.me/nr_codex'],
                    ['text' => 'Verify ✅', 'callback_data' => 'check_membership'],
                ],
            ],
        ];
        sendMessage($chat_id, $welcome_text, $reply_markup);
    }

    // Handle /help command
    if ($message && isset($message['text']) && $message['text'] == '/help') {
        $help_text = "ℹ️ *Need help, $username?*\n\n" .
                     "I'm the *NR CODEX JWT* bot, here to generate JWT tokens for Free Fire guest IDs! 🎮\n\n" .
                     "*How to use me:*\n" .
                     "1. Join our channel: *[@nr_codex](https://t.me/nr_codex)* (click /start to verify).\n" .
                     "2. Send a JSON file with your credentials in this format:\n" .
                     "```json\n" .
                     "[\n  {\"uid\": \"1234567890\", \"password\": \"PASSWORD1\"},\n  {\"uid\": \"0987654321\", \"password\": \"PASSWORD2\"}\n]\n" .
                     "```\n" .
                     "3. Wait for me to process and send back your JWT tokens! ⚡\n\n" .
                     "*Features:*\n" .
                     "⚡ Uses 5 APIs for super-fast processing\n" .
                     "🔄 Retries failed attempts up to 10 times\n" .
                     "💯 Handles invalid credentials gracefully\n\n" .
                     "Stuck? Join *[@nr_codex](https://t.me/nr_codex)* for support or try /start again! 😄";
        sendMessage($chat_id, $help_text);
    }

    // Handle callback query (Verify button)
    if ($callback_query && $callback_query['data'] == 'check_membership') {
        $message_id = $callback_query['message']['message_id'];
        if (isChannelMember($chat_id)) {
            $info_text = "🎉 *Awesome, $username!* You're a member of *[@nr_codex](https://t.me/nr_codex)*! 🙌\n\n" .
                         "*NR CODEX JWT Bot* is ready to roll! 🚀\n" .
                         "Send me a JSON file with your Free Fire guest ID credentials in this format:\n\n" .
                         "```json\n" .
                         "[\n  {\"uid\": \"1234567890\", \"password\": \"PASSWORD1\"},\n  {\"uid\": \"0987654321\", \"password\": \"PASSWORD2\"}\n]\n" .
                         "```\n\n" .
                         "*What I’ll do:*\n" .
                         "⚡ Process up to 55 accounts at once using 5 APIs\n" .
                         "🔄 Retry failed accounts up to 10 times\n" .
                         "📄 Send you a single JSON file with all your JWT tokens\n\n" .
                         "Need help? Use /help or ask in *[@nr_codex](https://t.me/nr_codex)*! 😊";
            editMessage($chat_id, $message_id, $info_text);
        } else {
            $error_text = "😕 *Oops, $username!* You haven’t joined *[@nr_codex](https://t.me/nr_codex)* yet.\n\n" .
                          "Please join our channel to use the bot. It’s where we share updates and support! 📢\n\n" .
                          "Click below to join and try again! 👇";
            editMessage($chat_id, $message_id, $error_text, [
                'inline_keyboard' => [
                    [
                        ['text' => 'Join Channel 🌟', 'url' => 'https://t.me/nr_codex'],
                        ['text' => 'Verify ✅', 'callback_data' => 'check_membership'],
                    ],
                ],
            ]);
        }
    }

    // Handle JSON file upload
    if ($message && isset($message['document']) && $message['document']['mime_type'] == 'application/json') {
        if (!isChannelMember($chat_id)) {
            sendMessage($chat_id, "😕 *Sorry, $username!* You need to join *[@nr_codex](https://t.me/nr_codex)* first.\n\n" .
                                 "Click below to join and unlock the bot! 👇", [
                'inline_keyboard' => [
                    [
                        ['text' => 'Join Channel 🌟', 'url' => 'https://t.me/nr_codex'],
                        ['text' => 'Verify ✅', 'callback_data' => 'check_membership'],
                    ],
                ],
            ]);
            exit;
        }

        // Check for existing processing lock
        if (!acquireLock($chat_id)) {
            sendMessage($chat_id, "⏳ *Hold on, $username!* I’m still processing your previous request.\n\n" .
                                 "Please wait a minute and try again. If this persists, contact *[@nr_codex](https://t.me/nr_codex)* for help! 😊");
            exit;
        }

        // Download JSON file
        $file_id = $message['document']['file_id'];
        $file = sendTelegramRequest('getFile', ['file_id' => $file_id]);
        if (!isset($file['result']['file_path'])) {
            sendMessage($chat_id, "❌ *Oops, $username!* I couldn’t download your file.\n\n" .
                                 "Please try uploading it again. If the issue continues, check with *[@nr_codex](https://t.me/nr_codex)*! 😔");
            releaseLock($chat_id);
            exit;
        }

        $file_path = $file['result']['file_path'];
        $file_url = "https://api.telegram.org/file/bot" . BOT_TOKEN . "/$file_path";
        $local_file = TEMP_DIR . "input_" . $chat_id . "_" . time() . ".json";
        file_put_contents($local_file, file_get_contents($file_url));

        // Parse JSON
        $json_content = file_get_contents($local_file);
        $credentials = json_decode($json_content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($credentials)) {
            sendMessage($chat_id, "❌ *Invalid JSON, $username!* Your file doesn’t match the required format.\n\n" .
                                 "Please use this format:\n" .
                                 "```json\n" .
                                 "[\n  {\"uid\": \"1234567890\", \"password\": \"PASSWORD1\"},\n  {\"uid\": \"0987654321\", \"password\": \"PASSWORD2\"}\n]\n" .
                                 "```\n\n" .
                                 "Check your file and try again. Need help? See /help or ask in *[@nr_codex](https://t.me/nr_codex)*! 😊");
            unlink($local_file);
            releaseLock($chat_id);
            exit;
        }

        // Start processing
        $start_time = microtime(true);
        $total_count = count($credentials);
        $results = [];
        $failed_count = 0;
        $invalid_count = 0;

        // Send initial processing message
        $progress_message = sendMessage($chat_id, "⏳ *Working on it, $username!* Processing your $total_count accounts... ▰▱▱▱▱▱▱▱▱▱ 0%");
        $message_id = $progress_message['result']['message_id'];

        // Process credentials in chunks for concurrency
        $chunks = array_chunk($credentials, CONCURRENT_REQUESTS);
        $progress = 0;
        $total_processed = 0;
        $progress_messages = [
            "🔥 *Blazing through, $username!* Fetching tokens... ",
            "⚡ *Almost there, $username!* Processing your accounts... ",
            "🚀 *Speeding up, $username!* Generating tokens... ",
        ];

        foreach ($chunks as $chunk_index => $chunk) {
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
            $message_variation = $progress_messages[$chunk_index % count($progress_messages)];
            editMessage($chat_id, $message_id, "$message_variation ▰ $bar " . number_format($progress, 2) . "%");
        }

        // Calculate processing time
        $processing_time = microtime(true) - $start_time;

        // Prepare summary
        $successful_count = count($results);
        $summary = "🎉 *Done, $username!* Your JWT tokens are ready! 🚀\n\n" .
                   "📑 *JWT Token Results*\n" .
                   "🔢 Total Accounts: $total_count\n" .
                   "✅ Successful: $successful_count\n" .
                   "❌ Failed: $failed_count\n" .
                   "⚠️ Invalid: $invalid_count\n" .
                   "⏱️ Time Taken: " . number_format($processing_time, 2) . "s\n" .
                   "🌐 APIs Used: " . count(API_BASE_URLS) . "\n\n" .
                   "Your tokens are in the file below! 📄\n" .
                   "Need more? Upload another JSON or use /help for guidance! 😊";

        // Save results to a single JSON file
        $output_file = TEMP_DIR . "jwt_results_" . $chat_id . "_" . time() . ".json";
        file_put_contents($output_file, json_encode($results, JSON_PRETTY_PRINT));

        // Send summary and file
        editMessage($chat_id, $message_id, $summary);
        $send_result = sendDocument($chat_id, $output_file, "🎮 Your JWT tokens are here, $username! Enjoy! 😄");

        // Clean up immediately
        if (file_exists($local_file)) {
            unlink($local_file);
        }
        if (file_exists($output_file)) {
            unlink($output_file);
        }
        releaseLock($chat_id);

        if (!$send_result['ok']) {
            sendMessage($chat_id, "❌ *Oops, $username!* I processed your tokens, but couldn’t send the file. 😔\n\n" .
                                 "Error: " . ($send_result['description'] ?? 'Unknown error') . "\n\n" .
                                 "Please try again or contact *[@nr_codex](https://t.me/nr_codex)* for help! 🙏");
            exit;
        }
    }
}

?>
