<?php

// Bot configuration
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: '7336854248:AAFlHQIDHfg3keMtDhwNpxqQ_fBzOupbZGc'); // Use environment variable
define('CHANNEL_USERNAME', '@nr_codex');
define('BOT_NAME', 'NR CODEX JWT');
define('API_BASE_URLS', [
    'https://akiru-jwt-10.vercel.app/token?uid={Uid}&password={Password}',
]);
define('MAX_RETRIES', 10);
define('CONCURRENT_REQUESTS', 55);
define('TEMP_DIR', sys_get_temp_dir() . '/jwt_bot/');

// Social media links
define('INSTAGRAM_URL', 'https://www.instagram.com/nr_codex?igsh=MjZlZWo2cGd3bDVk');
define('YOUTUBE_URL', 'https://youtube.com/@nr_codex06?si=5pbP9qsDLfT4uTgf');

// Ensure temp directory exists with secure permissions
if (!file_exists(TEMP_DIR)) {
    mkdir(TEMP_DIR, 0700, true);
}

// Clean up old temporary files on startup
foreach (glob(TEMP_DIR . "input_*_*.json") as $old_file) {
    unlink($old_file);
}
foreach (glob(TEMP_DIR . "jwt_results_*_*.json") as $old_file) {
    unlink($old_file);
}
foreach (glob(TEMP_DIR . "failed_credentials_*_*.txt") as $old_file) {
    unlink($old_file);
}

// Lock functions
function acquireLock($chat_id) {
    $lock_file = TEMP_DIR . "lock_$chat_id";
    if (file_exists($lock_file) && (time() - filemtime($lock_file)) < 300) {
        return false;
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

// Telegram API request with error handling
function sendTelegramRequest($method, $params = []) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/$method";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Add timeout
    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        file_put_contents(TEMP_DIR . "error_log.txt", "Telegram API error: " . curl_error($ch) . "\n", FILE_APPEND);
        curl_close($ch);
        return ['ok' => false, 'description' => 'cURL error: ' . curl_error($ch)];
    }
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
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        file_put_contents(TEMP_DIR . "error_log_$chat_id.txt", "sendDocument error: " . curl_error($ch) . "\n", FILE_APPEND);
        curl_close($ch);
        return ['ok' => false, 'description' => 'cURL error: ' . curl_error($ch)];
    }
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

// Fetch user profile photo
function getUserProfilePhoto($chat_id) {
    $params = [
        'user_id' => $chat_id,
    ];
    $result = sendTelegramRequest('getUserProfilePhotos', $params);
    if (isset($result['ok']) && $result['ok'] && !empty($result['result']['photos'])) {
        $photo = $result['result']['photos'][0][0]; // Get smallest size photo
        $file = sendTelegramRequest('getFile', ['file_id' => $photo['file_id']]);
        if (isset($file['result']['file_path'])) {
            return "https://api.telegram.org/file/bot" . BOT_TOKEN . "/" . $file['result']['file_path'];
        }
    }
    return null;
}

// Check channel membership with exponential backoff
function isChannelMember($chat_id) {
    $params = [
        'chat_id' => CHANNEL_USERNAME,
        'user_id' => $chat_id,
    ];
    for ($i = 0; $i < 3; $i++) {
        $result = sendTelegramRequest('getChatMember', $params);
        if (isset($result['ok']) && $result['ok']) {
            return isset($result['result']) && in_array($result['result']['status'], ['member', 'administrator', 'creator']);
        }
        file_put_contents(TEMP_DIR . "error_log_$chat_id.txt", "getChatMember (channel) attempt " . ($i + 1) . " failed: " . json_encode($result) . "\n", FILE_APPEND);
        sleep(pow(2, $i)); // Exponential backoff: 1s, 2s, 4s
    }
    // Fallback message for persistent failure
    file_put_contents(TEMP_DIR . "error_log_$chat_id.txt", "getChatMember (channel) failed after retries: " . json_encode($result) . "\n", FILE_APPEND);
    return false;
}

// Fetch JWT token with error handling
function fetchJwtToken($uid, $password, $api_url) {
    $url = str_replace(['{Uid}', '{Password}'], [urlencode($uid), urlencode($password)], $api_url);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_errno($ch) ? curl_error($ch) : null;
    curl_close($ch);
    return ['response' => $response, 'http_code' => $http_code, 'error' => $error];
}

// Process single credential
function processCredential($credential, &$results, &$failed_count, &$invalid_count, &$failed_credentials) {
    $uid = $credential['uid'] ?? '';
    $password = $credential['password'] ?? '';
    if (empty($uid) || empty($password)) {
        $invalid_count++;
        $failed_credentials[] = ['uid' => $uid, 'password' => $password, 'reason' => 'Invalid: Missing UID or password'];
        return;
    }

    $attempts = 0;
    $success = false;
    while ($attempts < MAX_RETRIES && !$success) {
        $api_url = API_BASE_URLS[0];
        $result = fetchJwtToken($uid, $password, $api_url);
        $attempts++;

        if ($result['error']) {
            $failed_count++;
            $failed_credentials[] = ['uid' => $uid, 'password' => $password, 'reason' => 'cURL error: ' . $result['error']];
            break;
        }

        if ($result['http_code'] == 200) {
            $data = json_decode($result['response'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $invalid_count++;
                $failed_credentials[] = ['uid' => $uid, 'password' => $password, 'reason' => 'Invalid: Malformed JSON response'];
                break;
            }
            if (isset($data['token'])) {
                $results[] = ['token' => $data['token'], 'uid' => $uid, 'password' => $password];
                $success = true;
            } else {
                $invalid_count++;
                $failed_credentials[] = ['uid' => $uid, 'password' => $password, 'reason' => 'Invalid: No token returned'];
                break;
            }
        } else {
            if ($attempts == MAX_RETRIES) {
                $failed_count++;
                $failed_credentials[] = ['uid' => $uid, 'password' => $password, 'reason' => 'Failed: Max retries reached'];
            }
        }
    }
}

// Get progress bar
function getProgressBar($progress) {
    $bars = [
        10 => '▰▱▱▱▱▱▱▱▱▱ 10%',
        20 => '▰▰▱▱▱▱▱▱▱▱ 20%',
        30 => '▰▰▰▱▱▱▱▱▱▱ 30%',
        40 => '▰▰▰▰▱▱▱▱▱▱ 40%',
        50 => '▰▰▰▰▰▱▱▱▱▱ 50%',
        60 => '▰▰▰▰▰▰▱▱▱▱ 60%',
        70 => '▰▰▰▰▰▰▰▱▱▱ 70%',
        80 => '▰▰▰▰▰▰▰▰▱▱ 80%',
        90 => '▰▰▰▰▰▰▰▰▰▱ 90%',
        100 => '▰▰▰▰▰▰▰▰▰▰ 100%'
    ];
    $progress = min(100, max(10, round($progress / 10) * 10));
    return $bars[$progress];
}

// Process credentials
function processCredentials($chat_id, $message_id, $username, $credentials, $total_count, $local_file) {
    if (!acquireLock($chat_id)) {
        sendMessage($chat_id, "⏳ *Hold on, $username!* I’m still processing your previous request.\n\n" .
                             "Please wait a minute 😊");
        return;
    }

    $start_time = microtime(true);
    $results = [];
    $failed_count = 0;
    $invalid_count = 0;
    $failed_credentials = [];

    $progress_message = sendMessage($chat_id, "⏳ *Working on it, $username!* Processing your $total_count guest IDs...");
    $progress_message_id = $progress_message['result']['message_id'];
    $progress_bar_message = sendMessage($chat_id, getProgressBar(10));
    $progress_bar_message_id = $progress_bar_message['result']['message_id'];

    $chunks = array_chunk($credentials, CONCURRENT_REQUESTS);
    $total_processed = 0;
    $progress_messages = [
        "🔥 *Blazing through, $username!* Fetching tokens...",
        "⚡ *Almost there, $username!* Processing your accounts...",
        "🚀 *Speeding up, $username!* Generating tokens...",
    ];

    foreach ($chunks as $chunk_index => $chunk) {
        $mh = curl_multi_init();
        $handles = [];

        foreach ($chunk as $credential) {
            $ch = curl_init();
            $api_url = API_BASE_URLS[0];
            $url = str_replace(['{Uid}', '{Password}'], [urlencode($credential['uid'] ?? ''), urlencode($credential['password'] ?? '')], $api_url);
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }

        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
        } while ($running > 0);

        foreach ($handles as $index => $ch) {
            $result = curl_multi_getcontent($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $credential = $chunk[$index];

            if (curl_errno($ch)) {
                $invalid_count++;
                $failed_credentials[] = ['uid' => $credential['uid'] ?? '', 'password' => $credential['password'] ?? '', 'reason' => 'cURL error: ' . curl_error($ch)];
            } elseif ($http_code == 200) {
                $data = json_decode($result, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $invalid_count++;
                    $failed_credentials[] = ['uid' => $credential['uid'] ?? '', 'password' => $credential['password'] ?? '', 'reason' => 'Invalid: Malformed JSON response'];
                } elseif (isset($data['token'])) {
                    $results[] = ['token' => $data['token'], 'uid' => $credential['uid'], 'password' => $credential['password']];
                } else {
                    $invalid_count++;
                    $failed_credentials[] = ['uid' => $credential['uid'] ?? '', 'password' => $credential['password'] ?? '', 'reason' => 'Invalid: No token returned'];
                }
            } else {
                processCredential($credential, $results, $failed_count, $invalid_count, $failed_credentials);
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);

        $total_processed += count($chunk);
        $progress = 10 + (($total_processed / $total_count) * 90);
        $progress_bar = getProgressBar($progress);
        editMessage($chat_id, $progress_bar_message_id, $progress_bar);
        if ($chunk_index < count($progress_messages)) {
            editMessage($chat_id, $progress_message_id, $progress_messages[$chunk_index]);
        }
    }

    editMessage($chat_id, $progress_bar_message_id, getProgressBar(100));

    $processing_time = microtime(true) - $start_time;
    $processing_time_min = number_format($processing_time / 60, 2);

    $successful_count = count($results);
    $summary = "🎉 *Done, $username!* Your JWT tokens are ready! 🚀\n\n" .
               "📑 *JWT Token Summary*\n" .
               "🔢 Total Accounts: $total_count\n" .
               "✅ Successful: $successful_count\n" .
               "❌ Failed: $failed_count\n" .
               "⚠️ Invalid: $invalid_count\n" .
               "⏱️ Time Taken: $processing_time_min min\n" .
               "🌐 APIs Used: 1\n" .
               "━━━━━━━━━━━━━━━━━━\n" .
               "≫ *𝗡𝗥 𝗖𝗢𝗗𝗘𝗫 𝗕𝗢𝗧𝗦* ⚡\n" .
               "📄 Your tokens are ready in the file below.\n" .
               "Want more tokens? Just upload another JSON file! 😊";

    $output_file = TEMP_DIR . "jwt_results_" . $chat_id . "_" . time() . ".json";
    file_put_contents($output_file, json_encode($results, JSON_PRETTY_PRINT));

    $failed_file = TEMP_DIR . "failed_credentials_" . $chat_id . "_" . time() . ".txt";
    $failed_content = "";
    if (!empty($failed_credentials)) {
        foreach ($failed_credentials as $cred) {
            $failed_content .= "UID: {$cred['uid']}, Password: {$cred['password']}, Reason: {$cred['reason']}\n";
        }
        file_put_contents($failed_file, $failed_content);
    }

    editMessage($chat_id, $progress_message_id, $summary, [
        'inline_keyboard' => [
            [
                ['text' => 'Generate Again 🚀', 'callback_data' => 'generate_again'],
            ],
        ],
    ]);

    $send_result = sendDocument($chat_id, $output_file, "🎮 Your JWT tokens are here, $username! Enjoy! 😄");
    if (!empty($failed_credentials)) {
        sendDocument($chat_id, $failed_file, "⚠️ Failed/Invalid credentials, $username! Check the details below:");
    }

    // Clean up
    if (file_exists($local_file)) {
        unlink($local_file);
    }
    if (file_exists($output_file)) {
        unlink($output_file);
    }
    if (file_exists($failed_file)) {
        unlink($failed_file);
    }
    releaseLock($chat_id);

    $state_file = TEMP_DIR . "state_$chat_id.json";
    $user_state = [];
    file_put_contents($state_file, json_encode($user_state));

    if (!$send_result['ok']) {
        sendMessage($chat_id, "❌ *Oops, $username!* I processed your tokens, but couldn’t send the file. 😔\n\n" .
                             "Error: " . ($send_result['description'] ?? 'Unknown error') . "\n\n" .
                             "Please try again or contact @nilay_ok for support! 🙏");
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

    $state_file = TEMP_DIR . "state_$chat_id.json";
    $user_state = file_exists($state_file) ? json_decode(file_get_contents($state_file), true) : [];

    // Handle /start command
    if ($message && isset($message['text']) && $message['text'] == '/start') {
        $photo_url = getUserProfilePhoto($chat_id);
        $photo_text = $photo_url ? "Your profile photo: [View]($photo_url)\n" : "No profile photo available. Check your privacy settings (Settings > Privacy and Security > Profile Photos).\n";
        $welcome_text = "👋 *Hey $username (ID: $chat_id)!* Welcome to *𝗡𝗥 𝗖𝗢𝗗𝗘𝗫 𝗝𝗪𝗧* — generating JWT tokens for Free Fire guest IDs! 🚀\n\n" .
                        $photo_text .
                        "I’m here to make your token generation fast and easy.\n" .
                        "📢 *Step 1:* Join our official Telegram channel for updates and support.\n\n" .
                        "▶️ Click below to join & verify your membership!\n" .
                        "*(You must be a member of the channel to access full features)*\n" .
                        "━━━━━━━━━━━━━━━━━━\n" .
                        "≫ *𝗡𝗥 𝗖𝗢𝗗𝗘𝗫 𝗕𝗢𝗧𝗦* ⚡";
        $reply_markup = [
            'inline_keyboard' => [
                [
                    ['text' => 'TELEGRAM CHANNEL ⚡', 'url' => 'https://t.me/' . ltrim(CHANNEL_USERNAME, '@')],
                ],
                [
                    ['text' => 'INSTAGRAM 🔥', 'url' => INSTAGRAM_URL],
                    ['text' => 'YOUTUBE ⚡', 'url' => YOUTUBE_URL],
                ],
                [
                    ['text' => 'CLICK & VERIFY ✅', 'callback_data' => 'check_membership'],
                ],
            ],
        ];
        sendMessage($chat_id, $welcome_text, $reply_markup);
    }

    // Handle callback queries
    if ($callback_query) {
        $message_id = $callback_query['message']['message_id'];
        $data = $callback_query['data'];

        if ($data == 'check_membership') {
            if (isChannelMember($chat_id)) {
                $photo_url = getUserProfilePhoto($chat_id);
                $photo_text = $photo_url ? "Your profile photo: [View]($photo_url)\n" : "No profile photo available. Check your privacy settings (Settings > Privacy and Security > Profile Photos).\n";
                $info_text = "🎉 *You're officially in, $username!* Welcome to *𝗡𝗥 𝗖𝗢𝗗𝗘𝗫 𝗕𝗢𝗧𝗦* ⚡ — Let’s Go!\n\n" .
                             "Your User ID: `$chat_id`\n" .
                             $photo_text .
                             "JWT Bot activated! Ready to fetch those tokens like a champ. 🚀\n\n" .
                             "📤 *Step 1:* Send me a `.json` file in this format:\n" .
                             "```json\n" .
                             "[\n  {\"uid\": \"YourUID1\", \"password\": \"YourPass1\"},\n  {\"uid\": \"YourUID2\", \"password\": \"YourPass2\"}\n]\n" .
                             "```\n\n" .
                             "Or choose to generate a single token manually.\n" .
                             "🔄 *I’ll handle:*\n" .
                             "🔁 Retries (up to 10x)\n" .
                             "📦 One file with all your tokens\n" .
                             "📜 Failed credentials in a separate file\n" .
                             "━━━━━━━━━━━━━━━━━━\n" .
                             "≫ *𝗡𝗥 𝗖𝗢𝗗𝗘𝗫 𝗕𝗢𝗧𝗦* ⚡\n" .
                             "For API access, contact @nilay_ok";
                $reply_markup = [
                    'inline_keyboard' => [
                        [
                            ['text' => 'GENERATE ONE ID', 'callback_data' => 'generate_one'],
                            ['text' => 'GENERATE CUSTOM', 'callback_data' => 'custom_generate'],
                            ['text' => 'GENERATE ALL IDS', 'callback_data' => 'all_generate'],
                        ],
                        [
                            ['text' => 'GET API', 'url' => 'https://t.me/nilay_ok'],
                        ],
                    ],
                ];
                editMessage($chat_id, $message_id, $info_text, $reply_markup);
            } else {
                $error_text = "😕 *Oops, $username!* You need to join our channel:\n" .
                              "- Channel: @" . ltrim(CHANNEL_USERNAME, '@') . "\n\n" .
                              "Also, ensure your channel membership is visible in Telegram Settings > Privacy and Security > Groups & Channels > Who can see your groups: 'Everybody'.\n" .
                              "If the issue persists, contact @nilay_ok for support.\n" .
                              "━━━━━━━━━━━━━━━━━━\n" .
                              "≫ *𝗡𝗥 𝗖𝗢𝗗𝗘𝗫 𝗕𝗢𝗧𝗦* ⚡";
                editMessage($chat_id, $message_id, $error_text, [
                    'inline_keyboard' => [
                        [
                            ['text' => 'TELEGRAM CHANNEL ⚡', 'url' => 'https://t.me/' . ltrim(CHANNEL_USERNAME, '@')],
                        ],
                        [
                            ['text' => 'INSTAGRAM 🔥', 'url' => INSTAGRAM_URL],
                            ['text' => 'YOUTUBE ⚡', 'url' => YOUTUBE_URL],
                        ],
                        [
                            ['text' => 'CLICK & VERIFY ✅', 'callback_data' => 'check_membership'],
                        ],
                    ],
                ]);
            }
        } elseif ($data == 'generate_one') {
            $user_state['awaiting_single_uid'] = true;
            file_put_contents($state_file, json_encode($user_state));
            sendMessage($chat_id, "🔢 *Please send me the Guest ID (UID), $username!*");
        } elseif ($data == 'custom_generate') {
            $user_state['awaiting_custom_count'] = true;
            file_put_contents($state_file, json_encode($user_state));
            sendMessage($chat_id, "🔢 *How many accounts do you want to process, $username?*\n\n" .
                                 "Please enter a number (e.g., 1, 5, 10).");
        } elseif ($data == 'all_generate') {
            if (!isset($user_state['credentials'])) {
                sendMessage($chat_id, "❌ *No JSON file found, $username!* Please upload a JSON file first.");
                exit;
            }
            $credentials = $user_state['credentials'];
            $local_file = $user_state['local_file'];
            processCredentials($chat_id, $message_id, $username, $credentials, count($credentials), $local_file);
        } elseif ($data == 'generate_again') {
            $photo_url = getUserProfilePhoto($chat_id);
            $photo_text = $photo_url ? "Your profile photo: [View]($photo_url)\n" : "No profile photo available. Check your privacy settings (Settings > Privacy and Security > Profile Photos).\n";
            $info_text = "🚀 *Ready to generate more tokens, $username?*\n\n" .
                         "Your User ID: `$chat_id`\n" .
                         $photo_text .
                         "Send me another JSON file or generate a single token:\n" .
                         "```json\n" .
                         "[\n  {\"uid\": \"YourUID1\", \"password\": \"YourPass1\"},\n  {\"uid\": \"YourUID2\", \"password\": \"YourPass2\"}\n]\n" .
                         "```\n" .
                         "━━━━━━━━━━━━━━━━━━\n" .
                         "≫ *𝗡𝗥 𝗖𝗢𝗗𝗘𝗫 �𝗕𝗢𝗧𝗦* ⚡";
            editMessage($chat_id, $message_id, $info_text, [
                'inline_keyboard' => [
                    [
                        ['text' => 'GENERATE ONE ID', 'callback_data' => 'generate_one'],
                        ['text' => 'GENERATE CUSTOM', 'callback_data' => 'custom_generate'],
                        ['text' => 'GENERATE ALL IDS', 'callback_data' => 'all_generate'],
                    ],
                ],
            ]);
            $user_state = [];
            file_put_contents($state_file, json_encode($user_state));
        }
    }

    // Handle single UID input
    if ($message && isset($message['text']) && isset($user_state['awaiting_single_uid']) && $user_state['awaiting_single_uid']) {
        $uid = trim($message['text']);
        if (empty($uid)) {
            sendMessage($chat_id, "❌ *Invalid UID, $username!* Please send a valid Guest ID.");
            exit;
        }
        $user_state['single_uid'] = $uid;
        $user_state['awaiting_single_uid'] = false;
        $user_state['awaiting_single_password'] = true;
        file_put_contents($state_file, json_encode($user_state));
        sendMessage($chat_id, "🔑 *Now send me the password for UID $uid, $username!*");
    }

    // Handle single password input
    if ($message && isset($message['text']) && isset($user_state['awaiting_single_password']) && $user_state['awaiting_single_password']) {
        $password = trim($message['text']);
        if (empty($password)) {
            sendMessage($chat_id, "❌ *Invalid password, $username!* Please send a valid password.");
            exit;
        }
        $uid = $user_state['single_uid'];
        $user_state['awaiting_single_password'] = false;
        file_put_contents($state_file, json_encode($user_state));

        if (!acquireLock($chat_id)) {
            sendMessage($chat_id, "⏳ *Hold on, $username!* I’m still processing your previous request.\n\n" .
                                 "Please wait a minute 😊");
            exit;
        }

        $results = [];
        $failed_count = 0;
        $invalid_count = 0;
        $failed_credentials = [];
        processCredential(['uid' => $uid, 'password' => $password], $results, $failed_count, $invalid_count, $failed_credentials);

        if (!empty($results)) {
            $token = $results[0]['token'];
            $token_message = "🎉 *Success, $username!* Here’s your JWT token for UID $uid:\n\n" .
                             "```\n$token\n```\n" .
                             "Copy the token above and use it! 😄\n" .
                             "━━━━━━━━━━━━━━━━━━\n" .
                             "≫ *𝗡𝗥 𝗖𝗢𝗗𝗘𝗫 𝗕𝗢𝗧𝗦* ⚡\n" .
                             "Want another? Click below!";
            sendMessage($chat_id, $token_message, [
                'inline_keyboard' => [
                    [
                        ['text' => 'Generate Another 🚀', 'callback_data' => 'generate_one'],
                    ],
                ],
            ]);
        } else {
            $reason = $failed_credentials[0]['reason'] ?? 'Unknown error';
            sendMessage($chat_id, "❌ *Failed to generate token, $username!* Reason: $reason\n\n" .
                                 "Please try again or contact @nilay_ok for support.\n" .
                                 "━━━━━━━━━━━━━━━━━━\n" .
                                 "≫ *𝗡𝗥 𝗖𝗢𝗗𝗘𝗫 𝗕𝗢𝗧𝗦* ⚡", [
                'inline_keyboard' => [
                    [
                        ['text' => 'Try Again 🚀', 'callback_data' => 'generate_one'],
                    ],
                ],
            ]);
        }

        releaseLock($chat_id);
    }

    // Handle custom count input
    if ($message && isset($message['text']) && isset($user_state['awaiting_custom_count']) && $user_state['awaiting_custom_count']) {
        $count = intval($message['text']);
        if ($count <= 0) {
            sendMessage($chat_id, "❌ *Invalid number, $username!* Please enter a positive number.");
            exit;
        }
        if (!isset($user_state['credentials'])) {
            sendMessage($chat_id, "❌ *No JSON file found, $username!* Please upload a JSON file first.");
            $user_state['awaiting_custom_count'] = false;
            file_put_contents($state_file, json_encode($user_state));
            exit;
        }
        $credentials = $user_state['credentials'];
        $local_file = $user_state['local_file'];
        $total_available = count($credentials);
        if ($count > $total_available) {
            sendMessage($chat_id, "❌ *Too many accounts requested, $username!* You have $total_available accounts in the file. Please enter a number up to $total_available.");
            exit;
        }
        $user_state['awaiting_custom_count'] = false;
        file_put_contents($state_file, json_encode($user_state));
        processCredentials($chat_id, $message['message_id'], $username, array_slice($credentials, 0, $count), $count, $local_file);
    }

    // Handle JSON file upload
    if ($message && isset($message['document']) && $message['document']['mime_type'] == 'application/json') {
        if (!isChannelMember($chat_id)) {
            $photo_url = getUserProfilePhoto($chat_id);
            $photo_text = $photo_url ? "Your profile photo: [View]($photo_url)\n" : "No profile photo available. Check your privacy settings (Settings > Privacy and Security > Profile Photos).\n";
            sendMessage($chat_id, "😕 *Sorry, $username!* You need to join our channel:\n" .
                                 "- Channel: @" . ltrim(CHANNEL_USERNAME, '@') . "\n\n" .
                                 "Your User ID: `$chat_id`\n" .
                                 $photo_text .
                                 "Also, ensure your channel membership is visible in Telegram Settings > Privacy and Security > Groups & Channels > Who can see your groups: 'Everybody'.\n" .
                                 "Click below to join and unlock the bot! 👇\n" .
                                 "━━━━━━━━━━━━━━━━━━\n" .
                                 "≫ *�_N𝗥 𝗖𝗢𝗗𝗘𝗫 𝗕𝗢𝗧𝗦* ⚡", [
                'inline_keyboard' => [
                    [
                        ['text' => 'TELEGRAM CHANNEL ⚡', 'url' => 'https://t.me/' . ltrim(CHANNEL_USERNAME, '@')],
                    ],
                    [
                        ['text' => 'INSTAGRAM 🔥', 'url' => INSTAGRAM_URL],
                        ['text' => 'YOUTUBE ⚡', 'url' => YOUTUBE_URL],
                    ],
                    [
                        ['text' => 'CLICK & VERIFY ✅', 'callback_data' => 'check_membership'],
                    ],
                ],
            ]);
            exit;
        }

        if (!acquireLock($chat_id)) {
            sendMessage($chat_id, "⏳ *Hold on, $username!* I’m still processing your previous request.\n\n" .
                                 "Please wait a minute 😊");
            exit;
        }

        $file_id = $message['document']['file_id'];
        $file = sendTelegramRequest('getFile', ['file_id' => $file_id]);
        if (!isset($file['result']['file_path'])) {
            sendMessage($chat_id, "❌ *Oops, $username!* I couldn’t download your file.\n\n" .
                                 "Please try uploading it again or contact @nilay_ok for support! 😔\n" .
                                 "━━━━━━━━━━━━━━━━━━\n" .
                                 "≫ *𝗡𝗥 𝗖𝗢𝗗𝗘𝗫 𝗕𝗢𝗧𝗦* ⚡");
            releaseLock($chat_id);
            exit;
        }

        $file_path = $file['result']['file_path'];
        $file_url = "https://api.telegram.org/file/bot" . BOT_TOKEN . "/$file_path";
        $local_file = TEMP_DIR . "input_" . $chat_id . "_" . time() . ".json";
        file_put_contents($local_file, file_get_contents($file_url));

        $json_content = file_get_contents($local_file);
        $credentials = json_decode($json_content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($credentials)) {
            sendMessage($chat_id, "❌ *Invalid JSON, $username!* Your file doesn’t match the required format.\n\n" .
                                 "Please use this format:\n" .
                                 "```json\n" .
                                 "[\n  {\"uid\": \"YourUID1\", \"password\": \"YourPass1\"},\n  {\"uid\": \"YourUID2\", \"password\": \"YourPass2\"}\n]\n" .
                                 "```\n\n" .
                                 "Check your file and try again or contact @nilay_ok for support! 😊\n" .
                                 "━━━━━━━━━━━━━━━━━━\n" .
                                 "≫ *𝗡𝗥 𝗖𝗢𝗗𝗘𝗫 𝗕𝗢𝗧𝗦* ⚡");
            unlink($local_file);
            releaseLock($chat_id);
            exit;
        }

        // Validate JSON structure
        foreach ($credentials as $cred) {
            if (!isset($cred['uid']) || !isset($cred['password'])) {
                sendMessage($chat_id, "❌ *Invalid JSON structure, $username!* Each entry must have 'uid' and 'password' keys.\n\n" .
                                     "Please use this format:\n" .
                                     "```json\n" .
                                     "[\n  {\"uid\": \"YourUID1\", \"password\": \"YourPass1\"},\n  {\"uid\": \"YourUID2\", \"password\": \"YourPass2\"}\n]\n" .
                                     "```\n\n" .
                                     "Check your file and try again! 😊\n" .
                                     "━━━━━━━━━━━━━━━━━━\n" .
                                     "≫ *𝗡𝗥 𝗖𝗢𝗗𝗘𝗫 �𝗕𝗢𝗧𝗦* ⚡");
                unlink($local_file);
                releaseLock($chat_id);
                exit;
            }
        }

        $total_count = count($credentials);
        $user_state['credentials'] = $credentials;
        $user_state['local_file'] = $local_file;
        file_put_contents($state_file, json_encode($user_state));

        $photo_url = getUserProfilePhoto($chat_id);
        $photo_text = $photo_url ? "Your profile photo: [View]($photo_url)\n" : "No profile photo available. Check your privacy settings (Settings > Privacy and Security > Profile Photos).\n";
        sendMessage($chat_id, "✅ *Found $total_count accounts, $username!* Choose how many to process:\n\n" .
                             "Your User ID: `$chat_id`\n" .
                             $photo_text .
                             "━━━━━━━━━━━━━━━━━━\n" .
                             "≫ *𝗡𝗥 𝗖𝗢𝗗𝗘𝗫 𝗕𝗢𝗧𝗦* ⚡", [
            'inline_keyboard' => [
                [
                    ['text' => 'GENERATE ONE ID', 'callback_data' => 'generate_one'],
                    ['text' => 'GENERATE CUSTOM', 'callback_data' => 'custom_generate'],
                    ['text' => 'GENERATE ALL IDS', 'callback_data' => 'all_generate'],
                ],
            ],
        ]);
        releaseLock($chat_id);
    }
}
?>