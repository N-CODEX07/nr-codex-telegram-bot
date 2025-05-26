<?php

// Constants
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: '7336854248:AAFlHQIDHfg3keMtDhwNpxqQ_fBzOupbZGc'); // Replace fallback in production
define('CHANNEL_USERNAME', '@nr_codex');
define('API_BASE_URL', 'https://akiru-jwt-10.vercel.app/token');
define('INSTAGRAM_URL', 'https://www.instagram.com/nr_codex?igsh=MjZlZWo2cGd3bDVk');
define('YOUTUBE_URL', 'https://youtube.com/@nr_codex06?si=5pbP9qsDLfT4uTgf');
define('MAX_RETRIES', 10);
define('CONCURRENT_REQUESTS', 55);
define('TEMP_DIR', '/tmp/jwt_bot/');

// Ensure temporary directory exists with secure permissions
if (!is_dir(TEMP_DIR)) {
    mkdir(TEMP_DIR, 0700, true);
}

// Clean up old temporary files
$files = glob(TEMP_DIR . '{input_*,jwt_results_*,failed_credentials_*,error_log_*}', GLOB_BRACE);
foreach ($files as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

// Telegram API request function
function sendTelegramRequest($method, $params = []) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/$method";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error) {
        file_put_contents(TEMP_DIR . "/error_log.txt", "Telegram API error ($method): $error\n", FILE_APPEND);
        return false;
    }
    return json_decode($response, true);
}

// Check channel membership
function isChannelMember($chat_id) {
    $attempt = 0;
    while ($attempt < 3) {
        $response = sendTelegramRequest('getChatMember', [
            'chat_id' => CHANNEL_USERNAME,
            'user_id' => $chat_id
        ]);
        if ($response && $response['ok'] && in_array($response['result']['status'], ['member', 'administrator', 'creator'])) {
            return true;
        }
        $attempt++;
        sleep(pow(2, $attempt)); // Exponential backoff
    }
    file_put_contents(TEMP_DIR . "/error_log_$chat_id.txt", "Membership check failed for $chat_id\n", FILE_APPEND);
    return false;
}

// Fetch JWT token from external API
function fetchJwtToken($uid, $password, $chat_id) {
    $url = API_BASE_URL . "?uid=" . urlencode($uid) . "&password=" . urlencode($password);
    $attempt = 0;
    
    while ($attempt < MAX_RETRIES) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            file_put_contents(TEMP_DIR . "/error_log_$chat_id.txt", "cURL error for UID $uid: $error\n", FILE_APPEND);
            $attempt++;
            sleep(pow(2, $attempt));
            continue;
        }

        if ($httpCode == 429) {
            $attempt++;
            sleep(pow(2, $attempt));
            continue;
        }

        $data = json_decode($response, true);
        if ($data && isset($data['token'])) {
            return ['success' => true, 'token' => $data['token']];
        }

        return ['success' => false, 'reason' => 'Invalid response or no token'];
    }

    return ['success' => false, 'reason' => 'Max retries reached'];
}

// Process bulk credentials
function processBulkCredentials($credentials, $count, $chat_id, $message_id) {
    $total_count = min($count, count($credentials));
    $successful = [];
    $failed = [];
    $invalid = [];
    $start_time = microtime(true);

    $mh = curl_multi_init();
    $handles = [];
    $batch = array_slice($credentials, 0, $total_count);
    $total_processed = 0;

    for ($i = 0; $i < $total_count; $i += CONCURRENT_REQUESTS) {
        $chunk = array_slice($batch, $i, CONCURRENT_REQUESTS);
        foreach ($chunk as $cred) {
            if (!isset($cred['uid']) || !isset($cred['password']) || empty($cred['uid']) || empty($cred['password'])) {
                $invalid[] = $cred;
                $total_processed++;
                continue;
            }
            $ch = curl_init(API_BASE_URL . "?uid=" . urlencode($cred['uid']) . "&password=" . urlencode($cred['password']));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_multi_add_handle($mh, $ch);
            $handles[] = ['ch' => $ch, 'uid' => $cred['uid'], 'password' => $cred['password']];
        }

        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
        } while ($running > 0);

        foreach ($handles as $handle) {
            $response = curl_multi_getcontent($handle['ch']);
            $httpCode = curl_getinfo($handle['ch'], CURLINFO_HTTP_CODE);
            $data = json_decode($response, true);
            if ($httpCode == 200 && $data && isset($data['token'])) {
                $successful[] = ['uid' => $handle['uid'], 'token' => $data['token']];
            } else {
                $failed[] = ['uid' => $handle['uid'], 'password' => $handle['password'], 'reason' => $data['error'] ?? 'Unknown error'];
            }
            curl_multi_remove_handle($mh, $handle['ch']);
            curl_close($handle['ch']);
            $total_processed++;
        }

        $handles = [];
        $progress = (int)(($total_processed / $total_count) * 100);
        $bar = str_repeat('▰', $progress / 10) . str_repeat('▱', 10 - $progress / 10);
        $messages = [
            '🔥 Blazing through, hang tight!',
            '🚀 Speeding up, almost there!',
            '⚡ Fetching tokens like a champ!'
        ];
        sendTelegramRequest('editMessageText', [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => "Processing: $bar $progress%\n" . $messages[array_rand($messages)],
            'parse_mode' => 'Markdown'
        ]);
    }

    curl_multi_close($mh);

    // Save results
    $json_file = TEMP_DIR . "jwt_results_$chat_id.json";
    $failed_file = TEMP_DIR . "failed_credentials_$chat_id.txt";
    file_put_contents($json_file, json_encode($successful, JSON_PRETTY_PRINT));
    $failed_content = "";
    foreach ($failed as $cred) {
        $failed_content .= "UID: {$cred['uid']}, Password: {$cred['password']}, Reason: {$cred['reason']}\n";
    }
    foreach ($invalid as $cred) {
        $failed_content .= "UID: " . ($cred['uid'] ?? 'N/A') . ", Password: " . ($cred['password'] ?? 'N/A') . ", Reason: Invalid format\n";
    }
    file_put_contents($failed_file, $failed_content);

    $processing_time = round((microtime(true) - $start_time) / 60, 2);
    $text = "🎉 *Done!* Your JWT tokens are ready! 🚀\n" .
            "📑 *JWT Token Summary*\n" .
            "🔢 Total Accounts: $total_count\n" .
            "✅ Successful: " . count($successful) . "\n" .
            "❌ Failed: " . count($failed) . "\n" .
            "⚠️ Invalid: " . count($invalid) . "\n" .
            "⏱️ Time Taken: $processing_time min\n" .
            "🌐 APIs Used: 1\n" .
            "━━━━━━━━━━━━━━━━━━\n" .
            "≫ *NR CODEX BOTS* ⚡\n" .
            "📄 Your tokens are ready in the file below.\n" .
            "Want more tokens? Just upload another JSON file! 😊";
    sendTelegramRequest('sendMessage', [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode([
            'inline_keyboard' => [[['text' => 'Generate Again 🚀', 'callback_data' => 'generate_again']]]
        ])
    ]);
    sendTelegramRequest('sendDocument', [
        'chat_id' => $chat_id,
        'document' => new CURLFile($json_file),
        'caption' => 'Successful JWT tokens'
    ]);
    if (file_exists($failed_file) && filesize($failed_file) > 0) {
        sendTelegramRequest('sendDocument', [
            'chat_id' => $chat_id,
            'document' => new CURLFile($failed_file),
            'caption' => 'Failed or invalid credentials'
        ]);
    }

    unlink($json_file);
    if (file_exists($failed_file)) {
        unlink($failed_file);
    }
}

// State management
function getState($chat_id) {
    $state_file = TEMP_DIR . "state_$chat_id.json";
    return file_exists($state_file) ? json_decode(file_get_contents($state_file), true) : [];
}

function saveState($chat_id, $state) {
    $state_file = TEMP_DIR . "state_$chat_id.json";
    file_put_contents($state_file, json_encode($state));
}

function clearState($chat_id) {
    $state_file = TEMP_DIR . "state_$chat_id.json";
    if (file_exists($state_file)) {
        unlink($state_file);
    }
}

// Lock management
function acquireLock($chat_id) {
    $lock_file = TEMP_DIR . "lock_$chat_id";
    if (file_exists($lock_file)) {
        return false;
    }
    file_put_contents($lock_file, '');
    return true;
}

function releaseLock($chat_id) {
    $lock_file = TEMP_DIR . "lock_$chat_id";
    if (file_exists($lock_file)) {
        unlink($lock_file);
    }
}

// Main bot logic
$update = json_decode(file_get_contents('php://input'), true);
$chat_id = $update['message']['chat']['id'] ?? $update['callback_query']['from']['id'] ?? 0;
$message = $update['message'] ?? null;
$callback_data = $update['callback_query']['data'] ?? null;
$username = $update['message']['chat']['username'] ?? $update['callback_query']['from']['username'] ?? 'User';
$message_id = $update['message']['message_id'] ?? $update['callback_query']['message']['message_id'] ?? 0;

if (!$chat_id) {
    exit;
}

// Get user profile photo
$photo_response = sendTelegramRequest('getUserProfilePhotos', ['user_id' => $chat_id]);
$photo_url = 'No profile photo available...';
if ($photo_response && $photo_response['ok'] && !empty($photo_response['result']['photos'])) {
    $file_id = $photo_response['result']['photos'][0][0]['file_id'];
    $file_response = sendTelegramRequest('getFile', ['file_id' => $file_id]);
    if ($file_response && $file_response['ok']) {
        $photo_url = "https://api.telegram.org/file/bot" . BOT_TOKEN . "/" . $file_response['result']['file_path'];
        $photo_url = "[View]($photo_url)";
    }
}

// Handle commands and callbacks
if ($message && isset($message['text']) && $message['text'] === '/start') {
    $text = "👋 *Hey $username (ID: $chat_id)!* Welcome to *NR CODEX JWT* — generating JWT tokens for Free Fire guest IDs! 🚀\n" .
            "Your profile photo: $photo_url\n" .
            "I’m here to make your token generation fast and easy.\n" .
            "📢 *Step 1:* Join our official Telegram channel for updates and support.\n" .
            "▶️ Click below to join & verify your membership!\n" .
            "*(You must be a member of the channel to access full features)*\n" .
            "━━━━━━━━━━━━━━━━━━\n" .
            "≫ *NR CODEX BOTS* ⚡";
    sendTelegramRequest('sendMessage', [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [
                    ['text' => 'TELEGRAM CHANNEL ⚡', 'url' => 'https://t.me/nr_codex'],
                    ['text' => 'INSTAGRAM 🔥', 'url' => INSTAGRAM_URL],
                    ['text' => 'YOUTUBE ⚡', 'url' => YOUTUBE_URL],
                    ['text' => 'CLICK & VERIFY ✅', 'callback_data' => 'check_membership']
                ]
            ]
        ])
    ]);
} elseif ($callback_data === 'check_membership') {
    if (!isChannelMember($chat_id)) {
        $text = "😕 *Oops, $username!* You need to join our channel:\n" .
                "- Channel: " . CHANNEL_USERNAME . "\n" .
                "Also, ensure your channel membership is visible in Telegram Settings > Privacy and Security > Groups & Channels > Who can see your groups: 'Everybody'.\n" .
                "If the issue persists, contact @nilay_ok for support.\n" .
                "━━━━━━━━━━━━━━━━━━\n" .
                "≫ *NR CODEX BOTS* ⚡";
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => 'TELEGRAM CHANNEL ⚡', 'url' => 'https://t.me/nr_codex'],
                        ['text' => 'INSTAGRAM 🔥', 'url' => INSTAGRAM_URL],
                        ['text' => 'YOUTUBE ⚡', 'url' => YOUTUBE_URL],
                        ['text' => 'CLICK & VERIFY ✅', 'callback_data' => 'check_membership']
                    ]
                ]
            ])
        ]);
    } else {
        $text = "🎉 *You're officially in, $username!* Welcome to *NR CODEX BOTS* ⚡ — Let’s Go!\n" .
                "Your User ID: `$chat_id`\n" .
                "Your profile photo: $photo_url\n" .
                "JWT Bot activated! Ready to fetch those tokens like a champ. 🚀\n" .
                "📤 *Step 1:* Send me a `.json` file in this format:\n" .
                "```json\n[\n  {\"uid\": \"YourUID1\", \"password\": \"YourPass1\"},\n  {\"uid\": \"YourUID2\", \"password\": \"YourPass2\"}\n]\n" .
                "```\n" .
                "Or choose to generate a single token manually. 🔄 I’ll handle:\n" .
                "- 🔁 Retries (up to 10x)\n- 📦 One file with all your tokens\n- 📜 Failed credentials in a separate file\n" .
                "━━━━━━━━━━━━━━━━━━\n" .
                "≫ *NR CODEX BOTS* ⚡\n" .
                "For API access, contact @nilay_ok";
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => 'GENERATE ONE ID', 'callback_data' => 'generate_one'],
                        ['text' => 'GENERATE CUSTOM', 'callback_data' => 'generate_custom'],
                        ['text' => 'GENERATE ALL IDS', 'callback_data' => 'generate_all'],
                        ['text' => 'GET API', 'url' => 'https://t.me/nilay_ok']
                    ]
                ]
            ])
        ]);
    }
} elseif ($callback_data === 'generate_one') {
    if (!isChannelMember($chat_id)) {
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "😕 Please join " . CHANNEL_USERNAME . " to use this feature!",
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => [[['text' => 'CLICK & VERIFY ✅', 'callback_data' => 'check_membership']]]
            ])
        ]);
        exit;
    }
    saveState($chat_id, ['state' => 'awaiting_uid']);
    sendTelegramRequest('sendMessage', [
        'chat_id' => $chat_id,
        'text' => "🔢 Please send me the Guest ID (UID), $username!",
        'parse_mode' => 'Markdown'
    ]);
} elseif ($message && isset($message['text']) && ($state = getState($chat_id))) {
    if ($state['state'] === 'awaiting_uid') {
        $uid = trim($message['text']);
        if (empty($uid)) {
            sendTelegramRequest('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ UID cannot be empty! Please send a valid UID."]);
            exit;
        }
        saveState($chat_id, ['state' => 'awaiting_password', 'uid' => $uid]);
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "🔑 Now send me the password for UID $uid, $username!",
            'parse_mode' => 'Markdown'
        ]);
    } elseif ($state['state'] === 'awaiting_password') {
        $password = trim($message['text']);
        if (empty($password)) {
            sendTelegramRequest('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ Password cannot be empty! Please send a valid password."]);
            exit;
        }
        if (!acquireLock($chat_id)) {
            sendTelegramRequest('sendMessage', ['chat_id' => $chat_id, 'text' => "⏳ Please wait, another process is running!"]);
            exit;
        }
        $result = fetchJwtToken($state['uid'], $password, $chat_id);
        clearState($chat_id);
        releaseLock($chat_id);
        if ($result['success']) {
            $text = "🎉 *Success, $username!* Here’s your JWT token for UID {$state['uid']}:\n```json\n{$result['token']}\n```\n" .
                    "Copy the token above and use it! 😄\n" .
                    "━━━━━━━━━━━━━━━━━━\n" .
                    "≫ *NR CODEX BOTS* ⚡\n" .
                    "Want another? Click below!";
            sendTelegramRequest('sendMessage', [
                'chat_id' => $chat_id,
                'text' => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [[['text' => 'Generate Another 🚀', 'callback_data' => 'generate_one']]]
                ])
            ]);
        } else {
            $text = "❌ *Failed to generate token, $username!* Reason: {$result['reason']}\n" .
                    "Please try again or contact @nilay_ok for support.\n" .
                    "━━━━━━━━━━━━━━━━━━\n" .
                    "≫ *NR CODEX BOTS* ⚡";
            sendTelegramRequest('sendMessage', [
                'chat_id' => $chat_id,
                'text' => $text,
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [[['text' => 'Try Again 🚀', 'callback_data' => 'generate_one']]]
                ])
            ]);
        }
    } elseif ($state['state'] === 'awaiting_custom_count') {
        $count = (int)trim($message['text']);
        if ($count <= 0) {
            sendTelegramRequest('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ Please enter a valid number of accounts!"]);
            exit;
        }
        $input_file = TEMP_DIR . "input_$chat_id.json";
        $credentials = json_decode(file_get_contents($input_file), true);
        if ($count > count($credentials)) {
            sendTelegramRequest('sendMessage', ['chat_id' => $chat_id, 'text' => "❌ Cannot process more accounts than available (" . count($credentials) . ")!"]);
            exit;
        }
        if (!acquireLock($chat_id)) {
            sendTelegramRequest('sendMessage', ['chat_id' => $chat_id, 'text' => "⏳ Please wait, another process is running!"]);
            exit;
        }
        clearState($chat_id);
        $response = sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "Processing: ▱▱▱▱▱▱▱▱▱▱ 0%\n🔥 Starting token generation...",
            'parse_mode' => 'Markdown'
        ]);
        $progress_message_id = $response['result']['message_id'];
        processBulkCredentials($credentials, $count, $chat_id, $progress_message_id);
        releaseLock($chat_id);
        unlink($input_file);
    }
} elseif ($message && isset($message['document']) && isChannelMember($chat_id)) {
    $file_id = $message['document']['file_id'];
    $file_response = sendTelegramRequest('getFile', ['file_id' => $file_id]);
    if ($file_response && $file_response['ok']) {
        $file_path = $file_response['result']['file_path'];
        $file_url = "https://api.telegram.org/file/bot" . BOT_TOKEN . "/" . $file_path;
        $file_content = file_get_contents($file_url);
        $credentials = json_decode($file_content, true);
        if (!$credentials || !is_array($credentials)) {
            sendTelegramRequest('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "❌ Invalid JSON format! Please use:\n```json\n[\n  {\"uid\": \"YourUID1\", \"password\": \"YourPass1\"},\n  {\"uid\": \"YourUID2\", \"password\": \"YourPass162\"}\n]\n```",
                'parse_mode' => 'Markdown'
            ]);
            exit;
        }
        $input_file = TEMP_DIR . "input_$chat_id.json";
        file_put_contents($input_file, $file_content);
        $total_count = count($credentials);
        $text = "✅ *Found $total_count accounts, $username!* Choose how many to process:\n" .
                "Your User ID: `$chat_id`\n" .
                "Your profile photo: $photo_url\n" .
                "━━━━━━━━━━━━━━━━━━\n" .
                "≫ *NR CODEX BOTS* ⚡";
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => 'GENERATE ONE ID', 'callback_data' => 'generate_one'],
                        ['text' => 'GENERATE CUSTOM', 'callback_data' => 'generate_custom'],
                        ['text' => 'GENERATE ALL IDS', 'callback_data' => 'generate_all']
                    ]
                ]
            ])
        ]);
    } else {
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "❌ Failed to download the file! Please try again or contact @nilay_ok."
        ]);
    }
} elseif ($callback_data === 'generate_custom') {
    if (!isChannelMember($chat_id)) {
        sendTelegramRequest('sendMessageToronto', [
            'chat_id' => $chat_id,
            'text' => "😕 Please join " . CHANNEL_USERNAME . " to use this feature!",
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => [[['text' => 'CLICK & VERIFY ✅', 'callback_data' => 'check_membership']]]
            ])
        ]);
        exit;
    }
    saveState($chat_id, ['state' => 'awaiting_custom_count']);
    sendTelegramRequest('sendMessage', [
        'chat_id' => $chat_id,
        'text' => "🔢 How many accounts do you want to process, $username?",
        'parse_mode' => 'Markdown'
    ]);
} elseif ($callback_data === 'generate_all') {
    if (!isChannelMember($chat_id)) {
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "😕 Please join " . CHANNEL_USERNAME . " to use this feature!",
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => [[['text' => 'CLICK & VERIFY ✅', 'callback_data' => 'check_membership']]]
            ])
        ]);
        exit;
    }
    $input_file = TEMP_DIR . "input_$chat_id.json";
    if (!file_exists($input_file)) {
        sendTelegramRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => "❌ No JSON file found! Please upload a file first."
        ]);
        exit;
    }
    $credentials = json_decode(file_get_contents($input_file), true);
    if (!acquireLock($chat_id)) {
        sendTelegramRequest('sendMessage', ['chat_id' => $chat_id, 'text' => "⏳ Please wait, another process is running!"]);
        exit;
    }
    $response = sendTelegramRequest('sendMessage', [
        'chat_id' => $chat_id,
        'text' => "Processing: ▱▱▱▱▱▱▱▱▱▱ 0%\n🔥 Starting token generation...",
        'parse_mode' => 'Markdown'
    ]);
    $progress_message_id = $response['result']['message_id'];
    processBulkCredentials($credentials, count($credentials), $chat_id, $progress_message_id);
    releaseLock($chat_id);
    unlink($input_file);
} elseif ($callback_data === 'generate_again') {
    clearState($chat_id);
    $text = "🎉 *Ready for more, $username?* Upload a new JSON file or generate a single token!\n" .
            "Your User ID: `$chat_id`\n" .
            "Your profile photo: $photo_url\n" .
            "━━━━━━━━━━━━━━━━━━\n" .
            "≫ *NR CODEX BOTS* ⚡";
    sendTelegramRequest('sendMessage', [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'Markdown',
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [
                    ['text' => 'GENERATE ONE ID', 'callback_data' => 'generate_one'],
                    ['text' => 'GENERATE CUSTOM', 'callback_data' => 'generate_custom'],
                    ['text' => 'GENERATE ALL IDS', 'callback_data' => 'generate_all']
                ]
            ]
        ])
    ]);
}

?>