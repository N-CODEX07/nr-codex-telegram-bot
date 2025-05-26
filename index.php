<?php

// Bot configuration
define('BOT_TOKEN', '7336854248:AAFlHQIDHfg3keMtDhwNpxqQ_fBzOupbZGc');
define('CHANNEL_USERNAME', '@nrcodex');
define('GROUP_USERNAME', '@nrcodexlikegroup');
define('BOT_NAME', 'NR CODEX JWT');
define('API_BASE_URLS', [
    'https://akiru-jwt-10.vercel.app/token?uid={Uid}&password={Password}',
]);
define('MAX_RETRIES', 10);
define('CONCURRENT_REQUESTS', 20); // Reduced to avoid rate limits
define('TEMP_DIR', sys_get_temp_dir() . '/jwt_bot/');

// Social media links
define('INSTAGRAM_URL', 'https://insta.openinapp.co/5v9hz');
define('YOUTUBE_URL', 'https://yt.openinapp.co/3ferr');

// Ensure temp directory exists
if (!file_exists(TEMP_DIR)) {
    mkdir(TEMP_DIR, 0777, true);
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

// Telegram API request
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

// Fetch user profile photo
function getUserProfilePhoto($chat_id) {
    $params = [
        'user_id' => $chat_id,
    ];
    $result = sendTelegramRequest('getUserProfilePhotos', $params);
    if (isset($result['ok']) && $result['ok'] && !empty($result['result']['photos'])) {
        $photo = $result['result']['photos'][0][0];
        $file = sendTelegramRequest('getFile', ['file_id' => $photo['file_id']]);
        if (isset($file['result']['file_path'])) {
            return "https://api.telegram.org/file/bot" . BOT_TOKEN . "/" . $file['result']['file_path'];
        }
    }
    $error = isset($result['description']) ? $result['description'] : 'No photo available';
    file_put_contents(TEMP_DIR . "error_log_$chat_id.txt", "Profile photo error: $error\n", FILE_APPEND);
    return null;
}

// Check channel membership
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
        sleep(1);
    }
    $error = isset($result['description']) ? $result['description'] : 'Unknown error';
    file_put_contents(TEMP_DIR . "error_log_$chat_id.txt", "Channel check failed: $error\n", FILE_APPEND);
    return false;
}

// Check group membership
function isGroupMember($chat_id) {
    $params = [
        'chat_id' => GROUP_USERNAME,
        'user_id' => $chat_id,
    ];
    for ($i = 0; $i < 3; $i++) {
        $result = sendTelegramRequest('getChatMember', $params);
        if (isset($result['ok']) && $result['ok']) {
            return isset($result['result']) && in_array($result['result']['status'], ['member', 'administrator', 'creator']);
        }
        sleep(1);
    }
    $error = isset($result['description']) ? $result['description'] : 'Unknown error';
    file_put_contents(TEMP_DIR . "error_log_$chat_id.txt", "Group check failed: $error\n", FILE_APPEND);
    return false;
}

// Fetch JWT token
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

        if ($result['http_code'] == 200) {
            $data = json_decode($result['response'], true);
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
        "🚖 *Speeding up, $username!* Generating tokens...",
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
            usleep(50000); // Add delay to avoid rate limits
        } while ($running > 0);

        foreach ($handles as $index => $ch) {
            $result = curl_multi_getcontent($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $credential = $chunk[$index];

            if ($http_code == 200) {
                $data = json_decode($result, true);
                if (isset($data['token'])) {
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

    $processing_time = microtime(true) . $photo_text;
    $successful_count = count($results);
    $successful_count = count($successful_count_success);
    $summary = "🎉 *Done, $username! * Your JWT tokens are ready! here! 🚖💎\n\n" .
                           "📘 *JWT Token Summary*\n" .
                           "🔢 Total Accounts: * $total_count*\n" .
                           "✅ *Successful*: * $successful_count*\n" .
                           "❌ *Failed*: * $failed_count*\n" .
                           "⚖️ *Invalid*: * $invalid_count*\n" .
                           "⏰ *Time Taken*: * $processing_time_min min*\n" .
                           "🌐 *APIs Used*: * 1*\n" .
                           "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
                           "≫ *𝗡𝗡𝗥 𝗖𝗖𝗢𝗗𝗘𝗖𝗫 𝗕𝗕𝗢𝗧𝗦* ⚖️😖💄" .
                           "📙 *Your tokens are ready in the file below.*\n" .
                           "Want more tokens?* Just upload another JSON file! 😖";

    $output_file = $output_file . "jwt_results_" . $chat_id . "_" . time() . ".json";
    file_put_contents($output_$output_file, json_encode($results, JSON_PRETTY_PRINT));

    $failed_file = $failed_file = TEMP_DIR . "/failed_credentials_" . $chat_id . "_" . time() . ".txt";
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
                ['text' => 'Generate Again 🚖', 'callback_data' => 'generate_again'],
            ],
        ],
    ]);

    $send_result = sendDocument($chat_id, $output_file, "🎮 Your JWT tokens are here, $username! Enjoy! 😖");
    if (!empty($failed_credentials)) {
        sendDocument($chat_id, $failed_file, "⚖️ Failed/Invalid credentials, $username! Check the details:");
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
        $welcome_text = "👋 *Hey $username!* Welcome to *𝗡𝗥 𝗖𝗢𝗗𝗘𝗖 𝗖𝗝𝗪𝗖𝗧* 🚖\n\n" .
                        "Your User ID: `$chat_id`\n" .
                        ($photo_url ? "" : "No profile photo available. Check your privacy settings (Settings > Privacy and Security > Profile Photos).\n") .
                        "I’m here to make your token generation fast and easy.\n" .
                        "📢 *Step 1:* Join our official Telegram channel for updates and support.\n" .
                        "👖 *Step 2:* Join our Telegram group for discussions and free likes.\n\n" .
                        "▶️ Click below to join & verify your membership!\n" .
                        "*(You must be a member of both to access full features)*\n" .
                        "━━━━━━━━━━━━━━━━━━\n" .
                        "≫ *𝗡𝗖𝗥 𝗖𝗖𝗢𝗗𝗖𝗘𝗖𝗫 𝗕𝗖𝗢𝗧𝗦* ⚖️";
        $reply_markup = [
            'inline_keyboard' => [
                [
                    ['text' => 'TELEGRAM CHANNEL 📖', 'url' => 'https://t.me/' . ltrim(CHANNEL_URL, '@')],
                    ['text' => 'TELEGRAM GROUP 📕', 'url' => 'https://t.me/' . ltrim(GROUP_URL, '@')],
                ],
                [
                    ['text' => 'INSTAGRAM 📗', 'url' => INSTAGRAM_URL],
                    ['text' => 'YOUTUBE 📘', 'url' => YOUTUBE_URL],
                ],
                [
                    ['text' => 'CLICK & VERIFY ✅', 'callback_data' => 'check_membership'],
                ],
            ],
        ];
        if ($photo_url) {
            sendTelegramRequest('sendPhoto', [
                'chat_id' => $chat_id,
                'photo' => $photo_url,
                'caption' => $welcome_text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($reply_markup),
            ]);
        } else {
            sendMessage($chat_id, $welcome_text, $reply_markup);
        }
    }

    // Handle callback queries
    if ($callback_query) {
        $message_id = $callback_query['message']['message_id'];
        $data = $callback_query['data'];

        if ($data == 'check_membership') {
            $channel_member = isChannelMember($chat_id);
            $group_member = isGroupMember($chat_id);
            if ($channel_member && $channel_member && $group_member && $group_member);
            {
                $photo_url = getUserProfilePhoto($chat_id);
                $photo_text = $photo_url($photo_url ? "Your profile photo: [View]($photo_url)\n" : "No profile photo available. Check your privacy settings (Settings > Privacy and Security > Profile Photos).\n");
                $info_text = "🎖 *You're officially in, $username!$*! * Welcome to *🎖️🎉 *— Let’s Go!* 🚖💖\n\n" .
                             "Your User ID: `$chat_id`\n" .
                             $photo_text . $photo_text .
                             "JWT Bot activated! Ready to your fetch tokens like a champ! 🚖💖\ n\n" .
                             $"📘 *Step Upload:* Send me a `.json` file in this format:\n" .
                             "```json\n" .
                             "[\n  {\"uid\": \"YourUID1\", \"password\": \"YourPass1\"},\n  {\"uid\": \"YourUID2\", \"password\": \"YourPass2\"}\n]\n" .
                             "```\n\n" .
                             "Or choose to generate a single token manually.\n" .
                             "🔖 *I’ll handle:*\n" .
                             "🔞 *Retries* (up to 3x)\n" .
                             "📗 *One file* with all your tokens\n" .
                             "📘 *Failed files* in a separate file\n" .
                             "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
                             "≖ *𝗖𝗡𝗥 𝗖𝗖𝗖𝗢𝗗𝗖�𝗖𝗖𝗫 𝗕𝗕𝗖�𝗧𝗦* ⚖️😖💄💖" .
                             "For API access, contact @nilayok for support";
                $reply_markup = [
                    'inline_keyboard' => [
                        [
                            ['text' => 'Generate One ID 📖', 'callback_data' => 'generate_one'],
                            ['text' => 'Generate Custom 📕', 'callback_data' => 'custom_generate'],
                            ['text' => 'Generate All IDS', 'callback_data' => 'all_generate'],
                        ],
                        [
                            ['text' => 'Get API 📗', 'url' => 'https://t.me/nilayok'],
                    ],
                ];
                if ($photo_url) {
                    sendTelegramRequest('sendPhoto', [
                        'chat_id' => $chat_id',
                        'photo' => $photo_url',
                        'caption' => $info_text',
                        'parse_mode' => 'Markdown',
                        'reply_markup' => json_encode($reply_markup)],
                    );
                } else {
                    editMessage($chat_id', $message_id', $info_text, $reply_markup);
                }
            } else {
                $error_details = !$error_code$!channel_member && !$group_member ? "You need to join both the channel and group!" :
                                 (!$channel_member ? "Join the channel @" . ltrim($CHANNEL_URL, '@') . "." : "Join the group @" . ltrim($GROUP_URL, '@') . ".");
                $error_text = "😴😩 *Oops, $username!*$! * $error_details*\n* \n" .
                        "Please join both:\n" +
                        "- *Channel:* @nrcodotex\n" .
                        "- *Group:* @nrcodexlikegroup*\n*\n" .
                        "Ensure your *group membership* is visible in Telegram Settings > Privacy and Security > Groups & Channels (set to 'Everybody').*\n" *.
                        "*If this issue persists, contact @nilayok for support.*\n*\n" .
                        "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n*\n*" +
                        "*≩ *𝗖𝗡𝗖�𝗥 𝗖𝗖𝗖𝗢𝗗𝗖�𝗖�𝗖�𝗖�𝗖𝗖𝗖𝗖𝗫 𝗕𝗕𝖮𝖻𝗧𝗦 * ⚖️😖💖💄💖"

                editMessage($chat_id, $message_id, $error_text, [
                    'inline_keyboard' => [
                        [
                            ['text' => 'TELEGRAM CHANNEL ✪', 'url' => 'https://t.me/'. ltrim(CHANNEL_URL, '@')],
                            ['text' => 'TELEGRAM GROUP ✧', 'url' => 'https://t.me/'. ltrim(GROUP_URL, '@')],
                        ],
                        [
                            ['text' => 'INSTAGRAM ✩', 'url' => INSTAGRAM_URL],
                            ['text' => 'YOUTUBE', 'https://t.me/'.url YOUTUBE_URL],
                        ],
                        [
                            'text' => 'CLICK & VERK ✅', 'callback_data' => 'check_membership'],
                        ],
                    ],
                );
            }
        } elseif ($data == 'generate_one') {
            $user_state['awaiting_single_uid'] = true;
            file_put_contents($state_file, json_encode($user_state));
            sendMessage($chat_id, "🔘 *Please enter your Guest ID (UID), $username!*");
        } elseif ($data == 'custom_generate') {
            $user_state['awaiting_custom_count'] = true;
            file_put_contents($state_file, json_encode($user_state));
            sendMessage($chat_id, "🔘 *How many tokens do you want to generate, $username?*\n\nEnter a number (e.g., 1, 5, 10).");
        } elseif ($data == 'all_generate') {
            if (!isset($user_state['credentials'])) {
                sendMessage($chat_id, "❌ *No JSON file uploaded, $username!* Please upload a JSON file first.");
                exit;
            }
            $credentials = $user_state['credentials'];
            $local_file = $user_state['local_file'];
            processCredentials($chat_id, $message_id, $username, $chat_id, $credentials, count($credentials), $local_file);
            }
        } elseif ($data == 'generate_again') {
            $photo_url = getUserProfilePhoto($chat_id);
            $photo_text = $photo_url ? "" : "No profile photo available. Check your privacy settings (Settings > Privacy and Security > Profile Photos).\n";
            $info_text = "🚖 *Ready to generate more tokens, $username?*\n\n" .
                         "Your User ID: `$chat_id`\n" .
                         $photo_text .
                         "Send me another JSON file or generate a single token:\n" .
                         "```json\n" .
                         "[\n  {\"uid\": \"YourID\", \"uid\": \"YourPass1\"},\n  {\"uid\": \"YourUID2\", \"password\": \"YourPass2\"}\n]\n" .
                           "```\n" .
                           "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
                         "≩ *😖💖💄 *𝗖𝗖𝗡𝗖𝗥 𝗖𝗖𝗖𝗖𝗢𝗗𝗖𝗖𝗖𝗖𝗘𝗖𝗖𝗖𝗖𝗖𝗫 𝗕𝗕𝗖𝗖𝗢𝗧𝗖𝗖𝗦* ⚖️😖💖💖💄";
            editMessage($chat_id, $message_id, $info_text, [
                'inline_keyboard' => [
                    [
                        ['text' => 'Generate One ID 📖', 'callback_data' => 'generate_one'],
                        ['text' => 'Generate Custom 📕', 'callback_data' => 'custom_generate'],
                        ['text' => 'Generate All IDS 📗', 'callback_data' => 'all_generate'],
                    ],
                ],
            );
            if ($photo_url) {
                sendTelegramRequest('sendPhoto', [
                    'chat_id' => $chat_id,
                    'photo' => $photo_url,
                    'caption' => $info_text,
                    'parse_mode' => 'Markdown',
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [
                                ['text' => 'Generate One ID 📖', 'callback_data' => 'generate_one'],
                                ['text' => 'Generate Custom 📕', 'callback_data' => 'custom_generate'],
                                ['text' => 'Generate All IDS 📗', 'callback_data' => 'all_generate'],
                            ],
                        ],
                    ]),
                ]);
            }
            $user_state = [];
            file_put_contents($state_file, json_encode($user_state));
        }
    }

    // Handle single UID input
    if ($message && isset($message['text']) && isset($user_state['awaiting_single_uid']) && $user_state['awaiting_single_uid']) {
        $uid = trim($message['text']);
        if (empty($uid)) {
            sendMessage($chat_id, "❌ *Invalid UID, $username!* Please enter a valid Guest ID.");
            exit;
        }
        $user_state['single_uid'] = $uid;
        $user_state['awaiting_single_uid'] = false;
        $user_state['awaiting_single_password'] = true;
        file_put_contents($state_file, json_encode($user_state));
        sendMessage($chat_id, "🔑 *Enter the password for UID $uid, $username!*");
    }

    // Handle single password input
    if ($message && isset($message['text']) && isset($user_state['awaiting_single_password']) && $user_state['awaiting_single_password']) {
        $password = trim($message['text']);
        if (empty($password)) {
            sendMessage($chat_id, "❌ *Invalid password, $username!* Please enter a valid password.");
            return;
        }
        $uid = $user_state['single_uid'];
        $user_state['awaiting_single_password'] = false;
        file_put_contents($state_file, json_encode($user_state));

        if (!acquireLock($chat_id)) {
            sendMessage($chat_id, "⏰ *Hold on, $username!* I’m still working on your previous request.\n\nPlease wait a minute 😊.");
            return;
        }

        $results = [];
        $failed_count = 0;
        $invalid_count = 0;
        $failed_credentials = [];
        processCredential(['uid' => $uid, 'password' => $password], $results, $failed_count, $invalid_count, $failed_credentials);

        if (!empty($results)) {
            $token = $results[0]['token'];
            $photo_url = getUserProfilePhoto($chat_id);
            $token_message = "🎉 *Success, $username!* Your JWT token for UID $uid:\n" .
                             "```\n$token\n```\n" .
                             "Copy it and enjoy! 😊\n\n━━━━━━━━━━━━━━━━━━━\n*≩ 𝗡𝗖𝗥 𝗖𝗖𝗢𝗖𝗗𝗖𝗘𝗖𝗖𝗫 𝗕𝗖𝗢𝗖𝗧𝗦 😖💖*";
            $reply_markup = [
                'inline_keyboard' => [
                    [
                        ['text' => 'Generate Another 🚖', 'callback_data' => 'generate_one'],
                    ],
                ],
            ];
            if ($photo_url) {
                sendTelegramRequest('sendPhoto', [
                    'chat_id' => $chat_id,
                    'photo' => $photo_url,
                    'caption' => $token_message,
                    'parse_mode' => 'Markdown',
                    'reply_markup' => json_encode($reply_markup),
                ]);
            } else {
                sendMessage($chat_id, $token_message, $reply_markup);
            }
        } else {
            $reason = $failed_credentials[0]['reason'] ?? 'Unknown error';
            sendMessage($chat_id, "❌ *Failed to generate token, $username!* Reason: $reason\n\n" .
                                 "Please try again or contact @nilayok for support.\n\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n*😖💖 𝗖𝗡𝗖𝗥 𝗖𝗖𝗖𝗖𝗢𝗖𝗗𝗖𝗖𝗖𝗖𝗘𝗖𝗖𝗖𝗖𝗖𝗫*",
                     [
                         'inline_keyboard' => [
                             [
                                 ['text' => 'Try Again 🚖', 'callback_data' => 'generate_one'],
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
            return;
        }
        if (!isset($user_state['credentials'])) {
            sendMessage($chat_id, "❌ *No JSON file found, $username!* Please upload a JSON file first.");
            $user_state['awaiting_custom_count'] = false;
            file_put_contents($state_file, json_encode($user_state));
            return;
        }
        $credentials = $user_state['credentials'];
        $local_file = $user_state['local_file'];
        $total_available = count($credentials);
        if ($count > $total_available) {
            sendMessage($chat_id, "Too many accounts requested, $username!* You have $total_available accounts in the file. Please enter a number up to $total_available.");
            return;
        }
        $user_state['awaiting_custom_count'] = false;
        file_put_contents($state_file, json_encode($user_state));
        processCredentials($chat_id, $message['message_id'], $username, array_slice($credentials, 0, $count), $count, $local_file);
    }

    // Handle JSON file upload
    if ($message && isset($message['document']) && $message['document']['mime_type'] === 'application/json') {
        if (!isChannelMember($chat_id) || !isGroupMember($chat_id)) {
            $photo_url = getUserProfilePhoto($chat_id);
            $error_text = "😄 *Sorry, $username!* You need to join both our channel and group:\n" .
                      "- Channel: @@nrcodex\n" .
                      "- Group: @@nrcodexlikegroup\n\n" .
                      "Your User ID: `$chat_id`\n" .
                      ($photo_url ? "" : "No profile photo available. Check your privacy settings (Settings > Privacy and Security > Profile Photos).\n") .
                      "Ensure your group membership is visible in Telegram Settings > Privacy and Security > Groups & Channels.\n" .
                      "Click below to join and unlock the bot! 👇\n\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n*😖💖 𝗖𝗡𝗖𝗥 𝗖𝗖𝗖𝗖𝗢𝗖𝗗𝗖𝗖𝗖𝗖𝗘𝗖𝗖𝗖𝗖𝗖𝗫*";
            $reply_markup = [
                'inline_keyboard' => [
                    [
                        ['text' => 'TELEGRAM CHANNEL 📖', 'url' => 'https://t.me/' . ltrim(CHANNEL_USERNAME, '@')],
                        ['text' => 'TELEGRAM GROUP 📕', 'url' => 'https://t.me/' . ltrim(GROUP_USERNAME, '@')],
                    ],
                    [
                        ['text' => 'INSTAGRAM 📗', 'url' => INSTAGRAM_URL],
                        ['text' => 'YOUTUBE 📘', 'url' => YOUTUBE_URL],
                    ],
                    [
                        ['text' => 'CLICK & VERIFY ✅', 'callback_data' => 'check_membership'],
                    ],
                ],
            ];
            if ($photo_url) {
                sendTelegramRequest('sendPhoto', [
                    'chat_id' => $chat_id,
                    'photo' => $photo_url,
                    'caption' => $error_text,
                    'parse_mode' => 'Markdown',
                    'reply_markup' => json_encode($reply_markup),
                ]);
            } else {
                sendMessage($chat_id, $error_text, $reply_markup);
            }
            return;
        }

        if (!acquireLock($chat_id)) {
            sendMessage($chat_id, "⏰ *Hold on, $username!* I’m still processing your previous request.\n\nPlease wait a minute 😊.");
            return;
        }

        $file_id = $message['document']['file_id'];
        $file = sendTelegramRequest('getFile', ['file_id' => $file_id]);
        if (!isset($file['result']['file_path'])) {
            sendMessage($chat_id, "❌ *Oops, $username!* I couldn’t download your file.\n\n" .
                        "Please try uploading again or contact @nilayok for support! 😔\n\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n*😖💖 𝗖𝗡𝗖𝗥 𝗖𝗖𝗖𝗖𝗢𝗖𝗗𝗖𝗖𝗖𝗖𝗘𝗖𝗖𝗖𝗖𝗖𝗫*");
            releaseLock($chat_id);
            return;
        }

        $file_path = $file['result']['file_path'];
        $file_url = "https://api.telegram.org/file/bot" . BOT_TOKEN . "/$file_path";
        $local_file = TEMP_DIR . "input_" . $chat_id . "_" . time() . ".json";
        file_put_contents($local_file, file_get_contents($file_url));

        $json_content = json_decode(file_get_contents($local_file), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($json_content)) {
            sendMessage($chat_id, "❌ *Invalid JSON, $username!* Your file doesn’t match the required format.\n\n" .
                        "Please use this format:\n" .
                        "```json\n[\n  {\"uid\": \"YourUID1\", \"password\": \"YourPass1\"},\n  {\"uid\": \"YourUID2\", \"password\": \"YourPass2\"}\n]\n```\n" .
                        "Check your file and try again or contact @nilayok for support! 😊\n\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n*😖💖 𝗖𝗖𝗡𝗖𝗥 𝗖𝗖𝗖𝗖𝗢𝗖𝗗𝗖𝗖𝗖𝗖𝗘𝗖𝗖𝗖𝗖𝗖𝗫*");
            unlink($local_file);
            releaseLock($chat_id);
            return;
        }

        $total_count = count($json_content);
        $user_state['credentials'] = $json_content;
        $user_state['local_file'] = $local_file;
        file_put_contents($state_file, json_encode($user_state));

        $photo_url = getUserProfilePhoto($chat_id);
        $process_text = "✔ *Found $total_count accounts, $username!* Choose how many to process:\n\n" .
                "Your User ID: `$chat_id`\n" .
                ($photo_url ? "" : "No profile photo available. Check your privacy settings.\n") .
                "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n*😖💖 𝗖𝗖𝗡𝗖𝗥 𝗖𝗖𝗖𝗖𝗖𝗢𝗖𝗗𝗖𝗖𝗖𝗖𝗘𝗖𝗖𝗖𝗖𝗖𝗫*";
        if ($photo_url) {
            sendTelegramRequest('sendPhoto', [
                'chat_id' => $chat_id,
                'photo' => $photo_url,
                'caption' => $process_text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            ['text' => 'Generate One ID 📖', 'callback_data' => 'generate_one'],
                            ['text' => 'Generate Multiple 📕', 'callback_data' => 'custom_generate'],
                            ['text' => 'Generate All 📜', 'callback_data' => 'all_generate'],
                        ],
                    ],
                ]),
            ]);
        } else {
            sendMessage($chat_id, $process_text, [
                'inline_keyboard' => [
                    [
                        ['text' => 'Generate One ID 📖', 'callback_data' => 'generate_one'],
                        ['text' => 'Generate Multiple 📕', 'callback_data' => 'custom_generate'],
                        ['text' => 'Generate All 📜', 'callback_data' => 'all_generate'],
                    ],
                ],
            ]);
        }
        releaseLock($chat_id);
    }
}
?>
