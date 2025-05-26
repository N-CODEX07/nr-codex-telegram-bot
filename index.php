<?php

// Bot configuration
define('BOT_TOKEN', '7336854248:AAFlHQIDHfg3keMtDhwNpxqQ_fBzOupbZGc');
define('CHANNEL_USERNAME', '@nr_codex');
define('GROUP_USERNAME', '@nr_codex_likegroup');
define('BOT_NAME', 'NR CODEX JWT');
define('INSTAGRAM_URL', 'https://www.instagram.com/nr_codex?igsh=MjZlZWo2cGd3bDVk');
define('YOUTUBE_URL', 'https://youtube.com/@nr_codex06?si=5pbP9qsDLfT4uTgf');
define('API_BASE_URLS', [
    'https://nr-codex-jwtapi.vercel.app/token?uid={Uid}&password={Password}', // Replace VERCEL_URL with actual API endpoint
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
        $api_url = API_BASE_URLS[0]; // Use the single API endpoint
        $result = fetchJwtToken($uid, $password, $api_url);
        $attempts++;

        if ($result['http_code'] == 200) {
            $data = json_decode($result['response'], true);
            if (isset($data['token'])) {
                $results[] = ['uid' => $uid, 'token' => $data['token']]; // Include UID in results
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
                             "Please wait a minute and try again. If this persists, contact support! 😊");
        return;
    }

    // Start processing
    $start_time = microtime(true);
    $results = [];
    $failed_count = 0;
    $invalid_count = 0;
    $failed_credentials = [];

    // Send initial processing message
    $progress_message = sendMessage($chat_id, "⏳ *Working on it, $username!* $total_count guest IDs — please wait a moment...");
    $progress_message_id = $progress_message['result']['message_id'];
    $progress_bar_message = sendMessage($chat_id, getProgressBar(10));
    $progress_bar_message_id = $progress_bar_message['result']['message_id'];

    // Process credentials in chunks for concurrency
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
                    $results[] = ['uid' => $credential['uid'] ?? '', 'token' => $data['token']];
                } else {
                    $invalid_count++;
                    $failed_credentials[] = ['uid' => $credential['uid'] ?? '', 'password' => $credential['password'] ?? '', 'reason' => 'Invalid: No token returned'];
                }
            } else {
                // Retry logic for failed requests
                processCredential($credential, $results, $failed_count, $invalid_count, $failed_credentials);
            }

            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);

        // Update progress bar
        $total_processed += count($chunk);
        $progress = 10 + (($total_processed / $total_count) * 90);
        $progress_bar = getProgressBar($progress);
        editMessage($chat_id, $progress_bar_message_id, $progress_bar);
        if ($chunk_index < count($progress_messages)) {
            editMessage($chat_id, $progress_message_id, $progress_messages[$chunk_index]);
        }
    }

    // Ensure final progress bar shows 100%
    editMessage($chat_id, $progress_bar_message_id, getProgressBar(100));

    // Calculate processing time
    $processing_time = microtime(true) - $start_time;
    $processing_time_min = number_format($processing_time / 60, 2); // Convert to minutes

    // Prepare summary
    $successful_count = count($results);
    $summary = "🎉 *Done, $username!* Here is your output!\n" .
               "━━━━━━━━━━━━━━━━━━\n" .
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

    // Save results to a JSON file
    $output_file = TEMP_DIR . "jwt_results_" . $chat_id . "_" . time() . ".json";
    file_put_contents($output_file, json_encode($results, JSON_PRETTY_PRINT));

    // Save failed credentials to a text file
    $failed_file = TEMP_DIR . "failed_credentials_" . $chat_id . "_" . time() . ".txt";
    $failed_content = "";
    if (!empty($failed_credentials)) {
        foreach ($failed_credentials as $cred) {
            $failed_content .= "UID: {$cred['uid']}, Password: {$cred['password']}, Reason: {$cred['reason']}\n";
        }
        file_put_contents($failed_file, $failed_content);
    }

    // Send summary with Generate Again button
    editMessage($chat_id, $progress_message_id, $summary, [
        'inline_keyboard' => [
            [
                ['text' => 'Generate Again 🚀', 'callback_data' => 'generate_again'],
            ],
        ],
    ]);

    // Send output file
    $send_result = sendDocument($chat_id, $output_file, "🎮 Your JWT tokens are here, $username! Enjoy! 😄");

    // Send failed credentials file if it exists
    if (!empty($failed_credentials)) {
        sendDocument($chat_id, $failed_file, "⚠️ Failed/Invalid credentials, $username! Check the details below:");
    }

    // Clean up immediately
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
    // Clear state
    $state_file = TEMP_DIR . "state_$chat_id.json";
    $user_state = [];
    file_put_contents($state_file, json_encode($user_state));

    if (!$send_result['ok']) {
        sendMessage($chat_id, "❌ *Oops, $username!* I processed your tokens, but couldn’t send the file. 😔\n\n" .
                             "Error: " . ($send_result['description'] ?? 'Unknown error') . "\n\n" .
                             "Please try again or contact support! 🙏");
        exit;
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

    // Store user state in a temporary file
    $state_file = TEMP_DIR . "state_$chat_id.json";

    // Load or initialize user state
    $user_state = file_exists($state_file) ? json_decode(file_get_contents($state_file), true) : [];

    // Handle /start command
    if ($message && isset($message['text']) && $message['text'] == '/start') {
        $welcome_text = "👋 *Hey $username!* Welcome to *" . BOT_NAME . "* — generating JWT tokens for Free Fire guest IDs! 🚀\n\n" .
                        "I’m here to make your token generation fast.\n" .
                        "*Step 1:* Join our official Telegram channel for the latest updates, support, and bot news.\n" .
                        "*Step 2:* Join our official Telegram group for free likes and discussion.\n\n" .
                        "▶️ Click below to join & verify your membership!\n" .
                        "(You must be a member to access full features)\n";
        $reply_markup = [
            'inline_keyboard' => [
                [
                    ['text' => 'TELEGRAM CHANNEL ⚡', 'url' => 'https://t.me/' . ltrim(CHANNEL_USERNAME, '@')],
                ],
                [
                    ['text' => 'TELEGRAM GROUP 🔥', 'url' => 'https://t.me/' . ltrim(GROUP_USERNAME, '@')],
                ],
                [
                    ['text' => 'INSTAGRAM 🔥', 'url' => INSTAGRAM_URL],
                ],
                [
                    ['text' => 'YOUTUBE ⚡', 'url' => YOUTUBE_URL],
                ],
                [
                    ['text' => 'CLICK & VERIFY ✅', 'callback_data' => 'check_membership'],
                ],
            ],
        ];
        sendMessage($chat_id, $welcome_text, $reply_markup);
    }

    // Handle callback query
    if ($callback_query) {
        $message_id = $callback_query['message']['message_id'];
        $data = $callback_query['data'];

        if ($data == 'check_membership') {
            if (isChannelMember($chat_id)) {
                $info_text = "🎉 *$username*! You're officially in *𝗡𝗥 𝗖𝗢𝗗𝗘𝗫 𝗕𝗢𝗧𝗦* ⚡ — Let’s Go!\n\n" .
                             "JWT Bot activated! Ready to fetch those tokens like a champ.\n\n" .
                             "*Step 1:* Send me a *token.json* file with your Free Fire guest ID credentials in this format:\n\n" .
                             "```json\n" .
                             "[\n  {\"uid\": \"YourUID1\", \"password\": \"YourPass1\"},\n  {\"uid\": \"YourUID2\", \"password\": \"YourPass2\"}\n]\n" .
                             "```\n\n" .
                             "Sit back — I’ll handle the rest:\n" .
                             "🔁 Retries (up to 10x)\n" .
                             "📦 Returns one failed file with all your Tokens\n" .
                             "━━━━━━━━━━━\n" .
                             "≫ *𝗡𝗥 𝗖𝗢𝗗𝗘𝗫 𝗕�_O𝗧𝗦* ⚡";
                $reply_markup = [
                    'inline_keyboard' => [
                        [
                            ['text' => 'GET API', 'url' => 'https://t.me/nilay_ok'],
                        ],
                    ],
                ];
                editMessage($chat_id, $message_id, $info_text, $reply_markup);
            } else {
                $error_text = "😕 *Oops, $username!* You haven’t joined yet.\n\n" .
                              "Please join our channel to use the bot. It’s where we share updates and support! 📢\n\n" .
                              "Click below to join and try again! 👇";
                $reply_markup = [
                    'inline_keyboard' => [
                        [
                            ['text' => 'TELEGRAM CHANNEL ⚡', 'url' => 'https://t.me/' . ltrim(CHANNEL_USERNAME, '@')],
                            ['text' => 'CLICK & VERIFY ✅', 'callback_data' => 'check_membership'],
                        ],
                    ],
                ];
                editMessage($chat_id, $message_id, $error_text, $reply_markup);
            }
        } elseif ($data == 'custom_generate') {
            $user_state['awaiting_custom_count'] = true;
            file_put_contents($state_file, json_encode($user_state));
            sendMessage($chat_id, "🔢 *How many accounts do you want to process, $username?*\n\n" .
                                 "Please enter a number (e.g., 1, 5, 10).");
        } elseif ($data == 'all_generate') {
            if (!isset($user_state['credentials'])) {
                sendMessage($chat_id, "❌ *No token.json file found, $username!* Please upload a token.json file first.");
                exit;
            }
            $credentials = $user_state['credentials'];
            $local_file = $user_state['local_file'];
            processCredentials($chat_id, $message_id, $username, $credentials, count($credentials), $local_file);
        } elseif ($data == 'generate_again') {
            $info_text = "🚀 *Ready to generate more tokens, $username?*\n\n" .
                         "Send me another *token.json* file with your Free Fire guest ID credentials in this format:\n\n" .
                         "```json\n" .
                         "[\n  {\"uid\": \"YourUID1\", \"password\": \"YourPass1\"},\n  {\"uid\": \"YourUID2\", \"password\": \"YourPass2\"}\n]\n" .
                         "```\n\n" .
                         "I’ll process them and send back your JWT tokens! 😄";
            editMessage($chat_id, $message_id, $info_text);
            $user_state = [];
            file_put_contents($state_file, json_encode($user_state));
        }
    }

    // Handle text input for custom count
    if ($message && isset($message['text']) && isset($user_state['awaiting_custom_count']) && $user_state['awaiting_custom_count']) {
        $count = intval($message['text']);
        if ($count <= 0) {
            sendMessage($chat_id, "❌ *Invalid number, $username!* Please enter a positive number.");
            exit;
        }
        if (!isset($user_state['credentials'])) {
            sendMessage($chat_id, "❌ *No token.json file found, $username!* Please upload a token.json file first.");
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
    if ($message && isset($message['document']) && $message['document']['mime_type'] == 'application/json' && $message['document']['file_name'] == 'token.json') {
        if (!isChannelMember($chat_id)) {
            sendMessage($chat_id, "😕 *Sorry, $username!* You need to join first.\n\n" .
                                 "Click below to join and unlock the bot! 👇", [
                'inline_keyboard' => [
                    [
                        ['text' => 'TELEGRAM CHANNEL ⚡', 'url' => 'https://t.me/' . ltrim(CHANNEL_USERNAME, '@')],
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

        // Download JSON file
        $file_id = $message['document']['file_id'];
        $file = sendTelegramRequest('getFile', ['file_id' => $file_id]);
        if (!isset($file['result']['file_path'])) {
            sendMessage($chat_id, "❌ *Oops, $username!* I couldn’t download your token.json file.\n\n" .
                                 "Please try uploading it again. If the issue continues, contact support! 😔");
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
            sendMessage($chat_id, "❌ *Invalid token.json, $username!* Your file doesn’t match the required format.\n\n" .
                                 "Please use this format:\n" .
                                 "```json\n" .
                                 "[\n  {\"uid\": \"YourUID1\", \"password\": \"YourPass1\"},\n  {\"uid\": \"YourUID2\", \"password\": \"YourPass2\"}\n]\n" .
                                 "```\n\n" .
                                 "Check your file and try again. Need help? Contact support! 😊");
            unlink($local_file);
            releaseLock($chat_id);
            exit;
        }

        $total_count = count($credentials);
        $user_state['credentials'] = $credentials;
        $user_state['local_file'] = $local_file;
        file_put_contents($state_file, json_encode($user_state));

        // Send confirmation message with options
        sendMessage($chat_id, "✅ *Found $total_count guest IDs in token.json, $username!* Choose an option:", [
            'inline_keyboard' => [
                [
                    ['text' => 'GENERATE CUSTOM', 'callback_data' => 'custom_generate'],
                    ['text' => 'GENERATE ALL IDS', 'callback_data' => 'all_generate'],
                ],
            ],
        ]);
        releaseLock($chat_id);
    }
}

?>