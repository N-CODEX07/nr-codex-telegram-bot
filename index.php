<?php
// Bot configuration
define('BOT_TOKEN', '7336854248:AAGpA-3psQcSCiO86XuGkoZNu1QsuxLdTbc');
define('CHANNEL_USERNAME', '@nr_codex');
define('GROUP_USERNAME', '@nr_codex_likegroup');
define('BOT_NAME', 'NR CODEX JWT');
define('INSTAGRAM_URL', 'https://www.instagram.com/nr_codex?igsh=MjZlZWo2cGd3bDVk');
define('YOUTUBE_URL', 'https://youtube.com/@nr_codex06?si=5pbP9qsDLfT4uTgf');
define('SHORTNER_API_TOKEN', '9dce09f1bad6420cb109c121c07659169ac8865c');
define('GITHUB_TOKEN', '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');
define('GITHUB_REPO', 'N-CODEX07/File-bot-verify');
define('GITHUB_FILE', 'jwt_verify.json');
define('API_BASE_URLS', [
    'https://uditanshu-jwt-ob49.vercel.app/token?uid={uid}&password={password}',
]);
define('DECODE_API_URL', 'https://nr-codex-jwt-decode.vercel.app/api?token={token}');
define('BIO_CHANGE_API_URL', 'https://akiru-bio-change.vercel.app/bio_change?bio={bio_text}&region={region}&uid={uid}&password={password}');
define('MAX_RETRIES', 10);
define('CONCURRENT_REQUESTS', 55);
define('TEMP_DIR', sys_get_temp_dir() . '/jwt_bot/');
define('VERIFICATION_TIMEOUT', 3600); // 1 hour in seconds

// Ensure temp directory exists
if (!file_exists(TEMP_DIR)) {
    mkdir(TEMP_DIR, 0777, true);
}

// Logging function for debugging
function logMessage($message) {
    $log_file = TEMP_DIR . 'bot_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
}

// Simple lock file to prevent concurrent processing per user
function acquireLock($chat_id) {
    $lock_file = TEMP_DIR . "lock_$chat_id";
    if (file_exists($lock_file) && (time() - filemtime($lock_file)) < 300) {
        logMessage("Lock exists for chat_id $chat_id, age: " . (time() - filemtime($lock_file)) . " seconds");
        return false;
    }
    file_put_contents($lock_file, time());
    logMessage("Lock acquired for chat_id $chat_id");
    return true;
}

function releaseLock($chat_id) {
    $lock_file = TEMP_DIR . "lock_$chat_id";
    if (file_exists($lock_file)) {
        unlink($lock_file);
        logMessage("Lock released for chat_id $chat_id");
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
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    logMessage("Telegram API request: $method, HTTP code: $http_code");
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
    $result = sendTelegramRequest('sendMessage', $params);
    logMessage("Sent message to chat_id $chat_id: " . substr($text, 0, 50) . "...");
    return $result;
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
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    logMessage("Sent document to chat_id $chat_id, file: $file_path, HTTP code: $http_code");
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
    $result = sendTelegramRequest('editMessageText', $params);
    logMessage("Edited message in chat_id $chat_id, message_id $message_id");
    return $result;
}

// Check if user is a member of the channel
function isChannelMember($chat_id) {
    $params = [
        'chat_id' => CHANNEL_USERNAME,
        'user_id' => $chat_id,
    ];
    $result = sendTelegramRequest('getChatMember', $params);
    $is_member = isset($result['result']) && in_array($result['result']['status'], ['member', 'administrator', 'creator']);
    logMessage("Checked channel membership for chat_id $chat_id: " . ($is_member ? 'Member' : 'Not a member'));
    return $is_member;
}

// Check if user is a member of the group
function isGroupMember($chat_id) {
    $params = [
        'chat_id' => GROUP_USERNAME,
        'user_id' => $chat_id,
    ];
    $result = sendTelegramRequest('getChatMember', $params);
    $is_member = isset($result['result']) && in_array($result['result']['status'], ['member', 'administrator', 'creator']);
    logMessage("Checked group membership for chat_id $chat_id: " . ($is_member ? 'Member' : 'Not a member'));
    return $is_member;
}

// Make API request to fetch JWT token
function fetchJwtToken($uid, $password, $api_url) {
    if (empty($api_url) || strpos($api_url, 'VERCEL_URL') !== false) {
        logMessage("Error: Invalid or unresolved API URL: $api_url");
        return ['response' => '', 'http_code' => 0];
    }
    $url = str_replace(['{uid}', '{password}'], [urlencode($uid), urlencode($password)], $api_url);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    logMessage("API request for UID $uid: HTTP code $http_code, URL: $url, Response: " . substr($response, 0, 100) . ($error ? ", Error: $error" : ""));
    return ['response' => $response, 'http_code' => $http_code];
}

// Decode JWT token
function decodeJwtToken($token) {
    $url = str_replace('{token}', urlencode($token), DECODE_API_URL);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    logMessage("Decode API request for token: HTTP code $http_code, Response: " . substr($response, 0, 100) . ($error ? ", Error: $error" : ""));
    return ['response' => $response, 'http_code' => $http_code];
}

// Change bio via API
function changeBio($uid, $password, $region, $bio_text) {
    $url = str_replace(
        ['{bio_text}', '{region}', '{uid}', '{password}'],
        [urlencode($bio_text), urlencode($region), urlencode($uid), urlencode($password)],
        BIO_CHANGE_API_URL
    );
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    logMessage("Bio change API request for UID $uid: HTTP code $http_code, Response: " . substr($response, 0, 100) . ($error ? ", Error: $error" : ""));
    return ['response' => $response, 'http_code' => $http_code];
}

// Shorten URL using Shortner.in API
function shortenUrl($long_url) {
    $api_token = SHORTNER_API_TOKEN;
    $api_url = "https://shortner.in/api?api={$api_token}&url=" . urlencode($long_url) . "&format=json";
    $result = @file_get_contents($api_url);
    if ($result === false) {
        logMessage("Failed to shorten URL: $long_url");
        return false;
    }
    $data = json_decode($result, true);
    if ($data['status'] === 'success') {
        logMessage("Shortened URL: {$data['shortenedUrl']}");
        return $data['shortenedUrl'];
    }
    logMessage("Shortner API error: " . ($data['message'] ?? 'Unknown error'));
    return false;
}

// Check GitHub verification status
function checkGitHubVerification($chat_id) {
    $url = "https://api.github.com/repos/" . GITHUB_REPO . "/contents/" . GITHUB_FILE;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: token ' . GITHUB_TOKEN,
        'Accept: application/vnd.github.v3+json',
        'User-Agent: NR-Codex-Bot'
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http_code != 200) {
        logMessage("Failed to fetch GitHub file: HTTP $http tell me more about the code you provided and how to enhance it further to include the new features like JWT token decoding and bio change with GitHub-based verification. Here's a comprehensive solution:

```php
<?php
// Bot configuration
define('BOT_TOKEN', '7336854248:AAGpA-3psQcSCiO86XuGkoZNu1QsuxLdTbc');
define('CHANNEL_USERNAME', '@nr_codex');
define('GROUP_USERNAME', '@nr_codex_likegroup');
define('BOT_NAME', 'NR CODEX JWT');
define('INSTAGRAM_URL', 'https://www.instagram.com/nr_codex?igsh=MjZlZWo2cGd3bDVk');
define('YOUTUBE_URL', 'https://youtube.com/@nr_codex06?si=5pbP9qsDLfT4uTgf');
define('SHORTNER_API_TOKEN', '9dce09f1bad6420cb109c121c07659169ac8865c');
define('GITHUB_TOKEN', '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');
define('GITHUB_REPO', 'N-CODEX07/File-bot-verify');
define('GITHUB_FILE', 'jwt_verify.json');
define('API_BASE_URLS', [
    'https://uditanshu-jwt-ob49.vercel.app/token?uid={uid}&password={password}',
]);
define('DECODE_API_URL', 'https://nr-codex-jwt-decode.vercel.app/api?token={token}');
define('BIO_CHANGE_API_URL', 'https://akiru-bio-change.vercel.app/bio_change?bio={bio_text}&region={region}&uid={uid}&password={password}');
define('MAX_RETRIES', 10);
define('CONCURRENT_REQUESTS', 55);
define('TEMP_DIR', sys_get_temp_dir() . '/jwt_bot/');
define('VERIFICATION_TIMEOUT', 3600); // 1 hour in seconds

// Ensure temp directory exists
if (!file_exists(TEMP_DIR)) {
    mkdir(TEMP_DIR, 0777, true);
}

// Logging function for debugging
function logMessage($message) {
    $log_file = TEMP_DIR . 'bot_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
}

// Simple lock file to prevent concurrent processing per user
function acquireLock($chat_id) {
    $lock_file = TEMP_DIR . "lock_$chat_id";
    if (file_exists LANGuAGE="php" line="1" file="bot.php"

    $lock_file = TEMP_DIR . "lock_$chat_id";
    if (file_exists($lock_file) && (time() - filemtime($lock_file)) < 300) {
        logMessage("Lock exists for chat_id $chat_id, age: " . (time() - filemtime($lock_file)) . " seconds");
        return false;
    }
    file_put_contents($lock_file, time());
    logMessage("Lock acquired for chat_id $chat_id");
    return true;
}

function releaseLock($chat_id) {
    $lock_file = TEMP_DIR . "lock_$chat_id";
    if (file_exists($lock_file)) {
        unlink($lock_file);
        logMessage("Lock released for chat_id $chat_id");
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
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    logMessage("Telegram API request: $method, HTTP code: $http_code");
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
    $result = sendTelegramRequest('sendMessage', $params);
    logMessage("Sent message to chat_id $chat_id: " . substr($text, 0, 50) . "...");
    return $result;
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
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    logMessage("Sent document to chat_id $chat_id, file: $file_path, HTTP code: $http_code");
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
    $result = sendTelegramRequest('editMessageText', $params);
    logMessage("Edited message in chat_id $chat_id, message_id $message_id");
    return $result;
}

// Check if user is a member of the channel
function isChannelMember($chat_id) {
    $params = [
        'chat_id' => CHANNEL_USERNAME,
        'user_id' => $chat_id,
    ];
    $result = sendTelegramRequest('getChatMember', $params);
    $is_member = isset($result['result']) && in_array($result['result']['status'], ['member', 'administrator', 'creator']);
    logMessage("Checked channel membership for chat_id $chat_id: " . ($is_member ? 'Member' : 'Not a member'));
    return $is_member;
}

// Check if user is a member of the group
function isGroupMember($chat_id) {
    $params = [
        'chat_id' => GROUP_USERNAME,
        'user_id' => $chat_id,
    ];
    $result = sendTelegramRequest('getChatMember', $params);
    $is_member = isset($result['result']) && in_array($result['result']['status'], ['member', 'administrator', 'creator']);
    logMessage("Checked group membership for chat_id $chat_id: " . ($is_member ? 'Member' : 'Not a member'));
    return $is_member;
}

// Make API request to fetch JWT token
function fetchJwtToken($uid, $password, $api_url) {
    if (empty($api_url) || strpos($api_url, 'VERCEL_URL') !== false) {
        logMessage("Error: Invalid or unresolved API URL: $api_url");
        return ['response' => '', 'http_code' => 0];
    }
    $url = str_replace(['{uid}', '{password}'], [urlencode($uid), urlencode($password)], $api_url);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    logMessage("API request for UID $uid: HTTP code $http_code, URL: $url, Response: " . substr($response, 0, 100) . ($error ? ", Error: $error" : ""));
    return ['response' => $response, 'http_code' => $http_code];
}

// Decode JWT token
function decodeJwtToken($token) {
    $url = str_replace('{token}', urlencode($token), DECODE_API_URL);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    logMessage("Decode API request for token: HTTP code $http_code, Response: " . substr($response, 0, 100) . ($error ? ", Error: $error" : ""));
    return ['response' => $response, 'http_code' => $http_code];
}

// Change bio via API
function changeBio($uid, $password, $region, $bio_text) {
    $url = str_replace(
        ['{bio_text}', '{region}', '{uid}', '{password}'],
        [urlencode($bio_text), urlencode($region), urlencode($uid), urlencode($password)],
        BIO_CHANGE_API_URL
    );
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    logMessage("Bio change API request for UID $uid: HTTP code $http_code, Response: " . substr($response, 0, 100) . ($error ? ", Error: $error" : ""));
    return ['response' => $response, 'http_code' => $http_code];
}

// Shorten URL using Shortner.in API
function shortenUrl($long_url) {
    $api_token = SHORTNER_API_TOKEN;
    $api_url = "https://shortner.in/api?api={$api_token}&url=" . urlencode($long_url) . "&format=json";
    $result = @file_get_contents($api_url);
    if ($result === false) {
        logMessage("Failed to shorten URL: $long_url");
        return false;
    }
    $data = json_decode($result, true);
    if ($data['status'] === 'success') {
        logMessage("Shortened URL: {$data['shortenedUrl']}");
        return $data['shortenedUrl'];
    }
    logMessage("Shortner API error: " . ($data['message'] ?? 'Unknown error'));
    return false;
}

// Check GitHub verification status
function checkGitHubVerification($chat_id) {
    $url = "https://api.github.com/repos/" . GITHUB_REPO . "/contents/" . GITHUB_FILE;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: token ' . GITHUB_TOKEN,
        'Accept: application/vnd.github.v3+json',
        'User-Agent: NR-Codex-Bot'
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http_code != 200) {
        logMessage("Failed to fetch GitHub file: HTTP $http_code");
        return false;
    }
    $data = json_decode($response, true);
    $content = base64_decode($data['content']);
    $verified_users = json_decode($content, true) ?: [];
    if (isset($verified_users[$chat_id]) && (time() - $verified_users[$chat_id]['timestamp']) < VERIFICATION_TIMEOUT) {
        logMessage("User $chat_id is verified");
        return true;
    }
    logMessage("User $chat_id is not verified or verification expired");
    return false;
}

// Update GitHub verification
function updateGitHubVerification($chat_id) {
    $url = "https://api.github.com/repos/" . GITHUB_REPO . "/contents/" . GITHUB_FILE;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: token ' . GITHUB_TOKEN,
        'Accept: application/vnd.github.v3+json',
        'User-Agent: NR-Codex-Bot'
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $data = json_decode($response, true);
    curl_close($ch);
    if ($http_code != 200) {
        logMessage("Failed to fetch GitHub file for update: HTTP $http_code");
        return false;
    }
    $sha = $data['sha'];
    $verified_users = json_decode(base64_decode($data['content']), true) ?: [];
    $verified_users[$chat_id] = ['timestamp' => time()];
    $content = base64_encode(json_encode($verified_users));
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'message' => 'Update verification for user ' . $chat_id,
        'content' => $content,
        'sha' => $sha
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: token ' . GITHUB_TOKEN,
        'Accept: application/vnd.github.v3+json',
        'User-Agent: NR-Codex-Bot'
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http_code == 200 || $http_code == 201) {
        logMessage("Updated GitHub verification for chat_id $chat_id");
        return true;
    }
    logMessage("Failed to update GitHub verification: HTTP $http_code");
    return false;
}

// Process credentials with retries
function processCredential($credential, &$results, &$failed_count, &$invalid_count, &$failed_credentials) {
    $uid = $credential['uid'] ?? '';
    $password = $credential['password'] ?? '';
    if (empty($uid) || empty($password)) {
        $invalid_count++;
        $failed_credentials[] = ['uid' => $uid, 'password' => $password, 'reason' => 'Invalid: Missing UID or password'];
        logMessage("Invalid credential: UID=$uid, Password=$password");
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
            if (isset($data['token']) && !empty($data['token'])) {
                $results[] = ['token' => $data['token']];
                $success = true;
                logMessage("Success: Token generated for UID $uid");
            } else {
                $invalid_count++;
                $failed_credentials[] = ['uid' => $uid, 'password' => $password, 'reason' => 'Invalid: No token returned'];
                logMessage("Invalid: No token returned for UID $uid");
                break;
            }
        } else {
            if ($attempts == MAX_RETRIES) {
                $failed_count++;
                $failed_credentials[] = ['uid' => $uid, 'password' => $password, 'reason' => 'Failed: Max retries reached'];
                logMessage("Failed: Max retries reached for UID $uid");
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
        sendMessage($chat_id, "⏳ Hold on, $username! I’m still processing your previous request.\n\n" .
                             "Please wait a minute or contact support (@nilay_ok) to clear the lock! 😊");
        return;
    }
    $start_time = microtime(true);
    $results = [];
    $failed_count = 0;
    $invalid_count = 0;
    $failed_credentials = [];
    $progress_message = sendMessage($chat_id, "⏳ Working on it, $username! $total_count guest IDs — please wait a moment...");
    $progress_message_id = $progress_message['result']['message_id'] ?? null;
    $progress_bar_message = sendMessage($chat_id, getProgressBar(10));
    $progress_bar_message_id = $progress_bar_message['result']['message_id'] ?? null;
    if (!$progress_message_id || !$progress_bar_message_id) {
        sendMessage($chat_id, "❌ Error, $username! Failed to initialize processing messages.");
        releaseLock($chat_id);
        return;
    }
    $chunks = array_chunk($credentials, CONCURRENT_REQUESTS);
    $total_processed = 0;
    $progress_messages = [
        "🔥 Blazing through, $username! Fetching tokens...",
        "⚡ Almost there, $username! Processing your accounts...",
        "🚀 Speeding up, $username! Generating tokens...",
    ];
    foreach ($chunks as $chunk_index => $chunk) {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($chunk as $credential) {
            $ch = curl_init();
            $api_url = API_BASE_URLS[0];
            $url = str_replace(['{uid}', '{password}'], [urlencode($credential['uid'] ?? ''), urlencode($credential['password'] ?? '')], $api_url);
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
            if ($http_code == 200) {
                $data = json_decode($result, true);
                if (isset($data['token']) && !empty($data['token'])) {
                    $results[] = ['token' => $data['token']];
                    logMessage("Success: Token generated for UID " . ($credential['uid'] ?? ''));
                } else {
                    $invalid_count++;
                    $failed_credentials[] = ['uid' => $credential['uid'] ?? '', 'password' => $credential['password'] ?? '', 'reason' => 'Invalid: No token returned'];
                    logMessage("Invalid: No token returned for UID " . ($credential['uid'] ?? ''));
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
    $summary = "🎉 Done, $username! Here is your output!\n" .
               "━━━━━━━━━━━━━━━━━━\n" .
               "📑 JWT Token Summary\n" .
               "🔢 Total Accounts: $total_count\n" .
               "✅ Successful: $successful_count\n" .
               "❌ Failed: $failed_count\n" .
               "⚠️ Invalid: $invalid_count\n" .
               "⏱️ Time Taken: $processing_time_min min\n" .
               "🌐 APIs Used: 1\n" .
               "━━━━━━━━━━━━━━━━━━\n" .
               "𝗡𝗥 𝗖𝗢𝗗𝗘𝗫 𝗕𝗢𝗧𝗦 ⚡\n" .
               "📄 Your tokens are ready in the file below.\n" .
               "Want more tokens? Use the buttons below! 😊";
    $output_file = TEMP_DIR . "jwt_results_" . $chat_id . "_" . time() . ".json";
    file_put_contents($output_file, json_encode($results, JSON_PRETTY_PRINT));
    logMessage("Saved results to $output_file");
    $failed_file = TEMP_DIR . "failed_credentials_" . $chat_id . "_" . time() . ".txt";
    $failed_content = "";
    if (!empty($failed_credentials)) {
        foreach ($failed_credentials as $cred) {
            $failed_content .= "UID: {$cred['uid']}, Password: {$cred['password']}, Reason: {$cred['reason']}\n";
        }
        file_put_contents($failed_file, $failed_content);
        logMessage("Saved failed credentials to $failed_file");
    }
    editMessage($chat_id, $progress_message_id, $summary, [
        'inline_keyboard' => [
            [
                ['text' => 'Generate Again 🚀', 'callback_data' => 'generate_again'],
                ['text' => 'Decode Tokens 🔍', 'callback_data' => 'decode_tokens'],
                ['text' => 'Change Bio ✍️', 'callback_data' => 'change_bio'],
            ],
        ],
    ]);
    sendDocument($chat_id, $output_file, "🎮 Your JWT tokens are here, $username! Enjoy! 😄");
    if (!empty($failed_credentials)) {
        sendDocument($chat_id, $failed_file, "⚠️ Failed/Invalid credentials, $username! Check the details below:");
    }
    foreach ([$local_file, $output_file, $failed_file] as $file) {
        if (file_exists($file)) {
            unlink($file);
            logMessage("Deleted file: $file");
        }
    }
    releaseLock($chat_id);
    $state_file = TEMP_DIR . "state_$chat_id.json";
    $user_state = [];
    file_put_contents($state_file, json_encode($user_state));
    logMessage("Cleared state for chat_id $chat_id");
}

// Process decode tokens
function processDecodeTokens($chat_id, $message_id, $username, $tokens, $local_file) {
    if (!acquireLock($chat_id)) {
        sendMessage($chat_id, "⏳ Hold on, $username! I’m still processing your previous request.\n\n" .
                             "Please wait a minute or contact support (@nilay_ok) to clear the lock! 😊");
        return;
    }
    $start_time = microtime(true);
    $results = [];
    $failed_count = 0;
    $progress_message = sendMessage($chat_id, "⏳ Decoding tokens, $username! Please wait a moment...");
    $progress_message_id = $progress_message['result']['message_id'] ?? null;
    $progress_bar_message = sendMessage($chat_id, getProgressBar(10));
    $progress_bar_message_id = $progress_bar_message['result']['message_id'] ?? null;
    if (!$progress_message_id || !$progress_bar_message_id) {
        sendMessage($chat_id, "❌ Error, $username! Failed to initialize processing messages.");
        releaseLock($chat_id);
        return;
    }
    $total_count = count($tokens);
    $total_processed = 0;
    $chunks = array_chunk($tokens, CONCURRENT_REQUESTS);
    foreach ($chunks as $chunk_index => $chunk) {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($chunk as $token) {
            $ch = curl_init();
            $url = str_replace('{token}', urlencode($token['token']), DECODE_API_URL);
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
            if ($http_code == 200) {
                $data = json_decode($result, true);
                if (isset($data['formatted_payload'])) {
                    $results[] = $data['formatted_payload'];
                    logMessage("Success: Decoded token");
                } else {
                    $failed_count++;
                    logMessage("Failed to decode token: Invalid response");
                }
            } else {
                $failed_count++;
                logMessage("Failed to decode token: HTTP $http_code");
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        $total_processed += count($chunk);
        $progress = 10 + (($total_processed / $total_count) * 90);
        $progress_bar = getProgressBar($progress);
        editMessage($chat_id, $progress_bar_message_id, $progress_bar);
    }
    editMessage($chat_id, $progress_bar_message_id, getProgressBar(100));
    $processing_time = microtime(true) - $start_time;
    $processing_time_min = number_format($processing_time / 60, 2);
    $successful_count = count($results);
    $summary = "🎉 Done decoding, $username!\n" .
               "━━━━━━━━━━━━━━━━━━\n" .
               "📑 Token Decode Summary\n" .
               "🔢 Total Tokens: $total_count\n" .
               "✅ Successful: $successful_count\n" .
               "❌ Failed: $failed_count\n" .
               "⏱️ Time Taken: $processing_time_min min\n" .
               "━━━━━━━━━━━━━━━━━━\n" .
               "�_N𝗥 𝗖𝗢𝗗𝗘𝗫 𝗕𝗢𝗧𝗦 ⚡\n" .
               "📄 Your decoded tokens are ready in the file below.";
    $output_file = TEMP_DIR . "decode_results_" . $chat_id . "_" . time() . ".json";
    file_put_contents($output_file, json_encode($results, JSON_PRETTY_PRINT));
    logMessage("Saved decode results to $output_file");
    editMessage($chat_id, $progress_message_id, $summary, [
        'inline_keyboard' => [
            [
                ['text' => 'Generate Again 🚀', 'callback_data' => 'generate_again'],
                ['text' => 'Decode Tokens 🔍', 'callback_data' => 'decode_tokens'],
                ['text' => 'Change Bio ✍️', 'callback_data' => 'change_bio'],
            ],
        ],
    ]);
    sendDocument($chat_id, $output_file, "🔍 Your decoded tokens are here, $username! Enjoy! 😄");
    foreach ([$local_file, $output_file] as $file) {
        if (file_exists($file)) {
            unlink($file);
            logMessage("Deleted file: $file");
        }
    }
    releaseLock($chat_id);
    $state_file = TEMP_DIR . "state_$chat_id.json";
    $user_state = [];
    file_put_contents($state_file, json_encode($user_state));
    logMessage("Cleared state for chat_id $chat_id");
}

// Handle incoming updates
$update = json_decode(file_get_contents('php://input'), true);
if (!$update) {
    logMessage("No update received");
    exit;
}
$chat_id = $update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? null;
$message = $update['message'] ?? null;
$callback_query = $update['callback_query'] ?? null;
$user = $update['message']['from'] ?? $update['callback_query']['from'] ?? null;
$username = $user['username'] ?? $user['first_name'] ?? 'User';
logMessage("Received update for chat_id $chat_id, username: $username");
if (!$chat_id) {
    logMessage("Error: No chat_id found in update");
    exit;
}
$state_file = TEMP_DIR . "state_$chat_id.json";
$user_state = file_exists($state_file) ? json_decode(file_get_contents($state_file), true) : [];

// Handle /start command
if ($message && isset($message['text']) && $message['text'] == '/start') {
    $welcome_text = "👋 Hey $username! Welcome to " . BOT_NAME . " — generating JWT tokens for Free Fire guest IDs! 🚀\n\n" .
                    "I’m here to make your token generation fast.\n" .
                    "*Step 1:* Join our official Telegram channel and group for the latest updates, support, and bot news.\n" .
                    "*Step 2:* Verify your membership to unlock full features!\n";
    $reply_markup = [
        'inline_keyboard' => [
            [
                ['text' => 'IG 𝗡𝗥_𝗖𝗢𝗗𝗘𝗫 ⚡', 'url' => INSTAGRAM_URL],
            ],
            [
                ['text' => 'YT 𝗡𝗥_𝗖𝗢𝗗𝗘𝗫𝟬𝟲 ⚡', 'url' => YOUTUBE_URL],
            ],
            [
                ['text' => 'TG 𝗡𝗥 𝗖𝗢𝗗𝗘𝗫 𝗕𝗢𝗧𝗦 ⚡', 'url' => 'https://t.me/' . ltrim(CHANNEL_USERNAME, '@')],
            ],
            [
                ['text' => 'TG 𝗡𝗥 𝗖𝗢𝗗𝗘𝗫 𝗚𝗥𝗢𝗨𝗣 ⚡', 'url' => 'https://t.me/' . ltrim(GROUP_USERNAME, '@')],
            ],
            [
                ['text' => '𝗩𝗘𝗥𝗜𝗙𝗬 𝗠𝗘𝗠𝗕𝗘𝗥𝗦𝗛𝗜𝗣 ✅', 'callback_data' => 'check_membership'],
            ],
        ],
    ];
    sendMessage($chat_id, $welcome_text, $reply_markup);
}

// Handle callback query
if ($callback_query) {
    $message_id = $callback_query['message']['message_id'];
    $data = $callback_query['data'];
    logMessage("Callback query received: $data");
    if ($data == 'check_membership') {
        if (isChannelMember($chat_id) && isGroupMember($chat_id)) {
            $info_text = "🎉 $username! You're officially in 𝗡𝗥 𝗖𝗢𝗗𝗘𝗫 𝗕𝗢𝗧𝗦 ⚡ — Let’s Go!\n\n" .
                         "JWT Bot activated! Ready to fetch tokens, decode them, or change bios.\n\n" .
                         "*Step 1:* Send me a JSON file (e.g., data.json) with your Free Fire guest ID credentials:\n\n" .
                         "```json\n" .
                         "[\n  {\"uid\": \"YourUID1\", \"password\": \"YourPass1\"},\n  {\"uid\": \"YourUID2\", \"password\": \"YourPass2\"}\n]\n" .
                         "```\n\n" .
                         "For bio change, you'll need to verify via a link (one-time use per hour).\n" .
                         "━━━━━━━━━━━\n" .
                         "𝗡𝗥 𝗖𝗢𝗗𝗘𝗫 𝗕𝗢𝗧𝗦⚡";
            $reply_markup = [
                'inline_keyboard' => [
                    [
                        ['text' => 'Generate Tokens 🚀', 'callback_data' => 'generate_again'],
                        ['text' => 'Decode Tokens 🔍', 'callback_data' => 'decode_tokens'],
                        ['text' => 'Change Bio ✍️', 'callback_data' => 'change_bio'],
                    ],
                ],
            ];
            editMessage($chat_id, $message_id, $info_text, $reply_markup);
        } else {
            $error_text = "😩 Oops, $username! You haven’t joined both our channel and group yet.\n\n" .
                          "Please join both to use the bot. Click below to join and try again! 👇";
            $reply_markup = [
                'inline_keyboard' => [
                    [
                        ['text' => 'TG 𝗡𝗥 𝗖𝗢𝗗𝗘𝗫 𝗕𝗢𝗧𝗦 ⚡', 'url' => 'https://t.me/' . ltrim(CHANNEL_USERNAME, '@')],
                        ['text' => 'TG 𝗡𝗥 𝗖𝗢𝗗𝗘𝗫 𝗚𝗥𝗢𝗨𝗣 ⚡', 'url' => 'https://t.me/' . ltrim(GROUP_USERNAME, '@')],
                    ],
                    [
                        ['text' => '�_V𝗘𝗥𝗜𝗙𝗬 𝗠𝗘𝗠𝗕𝗘𝗥𝗦𝗛𝗜𝗣 ✅', 'callback_data' => 'check_membership'],
                    ],
                ],
            ];
            editMessage($chat_id, $message_id, $error_text, $reply_markup);
        }
    } elseif ($data == 'custom_generate') {
        $user_state['awaiting_custom_count'] = true;
        file_put_contents($state_file, json_encode($user_state));
        sendMessage($chat_id, "🔢 How many accounts do you want to process, $username?\n\n" .
                             "Please enter a number (e.g., 1, 5, 10).");
    } elseif ($data == 'all_generate') {
        if (empty($user_state['credentials'])) {
            sendMessage($chat_id, "❌ No JSON file found, $username! Please upload a JSON file first.");
            logMessage("No credentials found for all_generate, chat_id $chat_id");
            exit;
        }
        $credentials = $user_state['credentials'];
        $local_file = $user_state['local_file'];
        processCredentials($chat_id, $message_id, $username, $credentials, count($credentials), $local_file);
    } elseif ($data == 'generate_again') {
        $info_text = "🚀 Ready to generate more tokens, $username?\n\n" .
                     "Send me another JSON file (e.g., data.json) with your Free Fire guest ID credentials:\n\n" .
                     "```json\n" .
                     "[\n  {\"uid\": \"YourUID1\", \"password\": \"YourPass1\"},\n  {\"uid\": \"YourUID2\", \"password\": \"YourPass2\"}\n]\n" .
                     "```\n\n" .
                     "I’ll process them and send back your JWT tokens! 😄";
        editMessage($chat_id, $message_id, $info_text);
        $user_state = [];
        file_put_contents($state_file, json_encode($user_state));
        logMessage("Reset state for generate_again, chat_id $chat_id");
    } elseif ($data == 'decode_tokens') {
        if (empty($user_state['credentials'])) {
            sendMessage($chat_id, "❌ No tokens found, $username! Please generate tokens first by uploading a JSON file.");
            logMessage("No credentials for decode_tokens, chat_id $chat_id");
            exit;
        }
        $tokens = $user_state['credentials'];
        $local_file = $user_state['local_file'];
        processDecodeTokens($chat_id, $message_id, $username, $tokens, $local_file);
    } elseif ($data == 'change_bio') {
        if (!isChannelMember($chat_id) || !isGroupMember($chat_id)) {
            sendMessage($chat_id, "😩 Sorry, $username! You need to join both our channel and group.\n\n" .
                                 "Please join both to use the bio change feature. 👇",
                                 [
                                     'inline_keyboard' => [
                                         [
                                             ['text' => 'TG 𝗡𝗥 𝗖𝗢𝗗𝗘𝗫 𝗕𝗢𝗧𝗦 ⚡', 'url' => 'https://t.me/' . ltrim(CHANNEL_USERNAME, '@')],
                                             ['text' => 'TG 𝗡𝗥 𝗖𝗢𝗗𝗘𝗫 𝗚𝗥𝗢𝗨𝗣 ⚡', 'url' => 'https://t.me/' . ltrim(GROUP_USERNAME, '@')],
                                         ],
                                         [
                                             ['text' => '𝗩𝗘𝗥𝗜𝗙𝗬 𝗠𝗘𝗠𝗕𝗘�_R𝗦𝗛𝗜𝗣 ✅', 'callback_data' => 'check_membership'],
                                         ],
                                     ],
                                 ]);
            logMessage("User not a member for bio change, chat_id $chat_id");
            exit;
        }
        if (checkGitHubVerification($chat_id)) {
            $user_state['awaiting_bio_json'] = true;
            file_put_contents($state_file, json_encode($user_state));
            sendMessage($chat_id, "✍️ Alright, $username! Please upload a JSON file with one set of credentials for bio change:\n\n" .
                                 "```json\n" .
                                 "[{\"uid\": \"YourUID\", \"password\": \"YourPass\"}]\n" .
                                 "```");
        } else {
            $verify_url = "https://t.me/" . ltrim(CHANNEL_USERNAME, '@');
            $short_url = shortenUrl($verify_url);
            if ($short_url) {
                sendMessage($chat_id, "🔐 You need to verify to use the bio change feature, $username!\n\n" .
                                     "Click the link below to verify (valid for 1 hour):",
                                     [
                                         'inline_keyboard' => [
                                             [
                                                 ['text' => 'Verify Now ✅', 'url' => $short_url],
                                                 ['text' => 'Check Verification', 'callback_data' => 'check_bio_verification'],
                                             ],
                                         ],
                                     ]);
            } else {
                sendMessage($chat_id, "❌ Failed to generate verification link, $username! Please try again or contact support (@nilay_ok).");
            }
        }
    } elseif ($data == 'check_bio_verification') {
        if (checkGitHubVerification($chat_id)) {
            $user_state['awaiting_bio_json'] = true;
            file_put_contents($state_file, json_encode($user_state));
            sendMessage($chat_id, "✅ Verification successful, $username! Please upload a JSON file with one set of credentials for bio change:\n\n" .
                                 "```json\n" .
                                 "[{\"uid\": \"YourUID\", \"password\": \"YourPass\"}]\n" .
                                 "```");
        } else {
            sendMessage($chat_id, "❌ Not verified yet, $username! Please complete verification using the link provided earlier.");
        }
    }
}

// Handle text input for custom count or bio details
if ($message && isset($message['text'])) {
    if (isset($user_state['awaiting_custom_count']) && $user_state['awaiting_custom_count']) {
        $count = intval($message['text']);
        if ($count <= 0) {
            sendMessage($chat_id, "❌ Invalid number, $username! Please enter a positive number.");
            logMessage("Invalid custom count: $count, chat_id $chat_id");
            exit;
        }
        if (empty($user_state['credentials'])) {
            sendMessage($chat_id, "❌ No JSON file found, $username! Please upload a JSON file first.");
            $user_state['awaiting_custom_count'] = false;
            file_put_contents($state_file, json_encode($user_state));
            logMessage("No credentials for custom count, chat_id $chat_id");
            exit;
        }
        $credentials = $user_state['credentials'];
        $local_file = $user_state['local_file'];
        $total_available = count($credentials);
        if ($count > $total_available) {
            sendMessage($chat_id, "❌ Too many accounts requested, $username! You have $total_available accounts in the file. Please enter a number up to $total_available.");
            logMessage("Custom count $count exceeds available $total_available, chat_id $chat_id");
            exit;
        }
        $user_state['awaiting_custom_count'] = false;
        file_put_contents($state_file, json_encode($user_state));
        processCredentials($chat_id, $message['message_id'], $username, array_slice($credentials, 0, $count), $count, $local_file);
        exit;
    } elseif (isset($user_state['awaiting_bio_region']) && $user_state['awaiting_bio_region']) {
        $region = trim($message['text']);
        if (empty($region)) {
            sendMessage($chat_id, "❌ Invalid region, $username! Please enter a valid region code (e.g., IND, US).");
            logMessage("Invalid region: $region, chat_id $chat_id");
            exit;
        }
        $user_state['bio_region'] = $region;
        $user_state['awaiting_bio_region'] = false;
        $user_state['awaiting_bio_text'] = true;
        file_put_contents($state_file, json_encode($user_state));
        sendMessage($chat_id, "📝 Please enter the new bio text (no spaces or special characters):");
    } elseif (isset($user_state['awaiting_bio_text']) && $user_state['awaiting_bio_text']) {
        $bio_text = trim($message['text']);
        if (empty($bio_text) || preg_match('/[\s\W]/', $bio_text)) {
            sendMessage($chat_id, "❌ Invalid bio text, $username! Please enter text without spaces or special characters.");
            logMessage("Invalid bio text: $bio_text, chat_id $chat_id");
            exit;
        }
        $credentials = $user_state['bio_credentials'];
        $region = $user_state['bio_region'];
        $uid = $credentials[0]['uid'];
        $password = $credentials[0]['password'];
        $result = changeBio($uid, $password, $region, $bio_text);
        $user_state['awaiting_bio_text'] = false;
        file_put_contents($state_file, json_encode($user_state));
        if ($result['http_code'] == 200) {
            $data = json_decode($result['response'], true);
            if ($data['status'] === 'success') {
                sendMessage($chat_id, "✅ Bio changed successfully, $username!\n\n" .
                                    "UID: $uid\n" .
                                    "Region: $region\n" .
                                    "New Bio: $bio_text\n\n" .
                                    "You can change bio again after 1 hour. Upload a new JSON file for more actions!",
                                    [
                                        'inline_keyboard' => [
                                            [
                                                ['text' => 'Generate Tokens 🚀', 'callback_data' => 'generate_again'],
                                                ['text' => 'Decode Tokens 🔍', 'callback_data' => 'decode_tokens'],
                                                ['text' => 'Change Bio ✍️', 'callback_data' => 'change_bio'],
                                            ],
                                        ],
                                    ]);
                updateGitHubVerification($chat_id); // Update verification to reset timeout
            } else {
                sendMessage($chat_id, "❌ Failed to change bio, $username! Error: " . ($data['message'] ?? 'Unknown error') . "\n\nTry again or contact support (@nilay_ok).");
            }
        } else {
            sendMessage($chat_id, "❌ Failed to change bio, $username! HTTP Code: {$result['http_code']}\n\nTry again or contact support (@nilay_ok).");
        }
        if (isset($user_state['bio_local_file']) && file_exists($user_state['bio_local_file'])) {
            unlink($user_state['bio_local_file']);
            logMessage("Deleted bio JSON file: {$user_state['bio_local_file']}");
        }
        releaseLock($chat_id);
        $user_state = [];
        file_put_contents($state_file, json_encode($user_state));
    }
}

// Handle JSON file upload
if ($message && !empty($message['document']) && $message['document']['mime_type'] === 'application/json') {
    logMessage("JSON file uploaded by chat_id $chat_id, file_name: " . ($message['document']['file_name'] ?? 'unknown'));
    try {
        if (!isChannelMember($chat_id) || !isGroupMember($chat_id)) {
            sendMessage($chat_id, "😩 Sorry, $username! You need to join both our channel and group.\n\n" .
                                 "Please join both to use the bot. 👇",
                                 [
                                     'inline_keyboard' => [
                                         [
                                             ['text' => 'TG 𝗡𝗥 𝗖𝗢𝗗𝗘𝗫 𝗕𝗢𝗧𝗦 ⚡', 'url' => 'https://t.me/' . ltrim(CHANNEL_USERNAME, '@')],
                                             ['text' => 'TG 𝗡𝗥 𝗖𝗢𝗗𝗘𝗫 𝗚𝗥𝗢𝗨𝗣 ⚡', 'url' => 'https://t.me/' . ltrim(GROUP_USERNAME, '@')],
                                         ],
                                         [
                                             ['text' => '𝗩𝗘𝗥𝗜𝗙𝗬 𝗠𝗘𝗠𝗕𝗘𝗥𝗦�_H𝗜𝗣 ✅', 'callback_data' => 'check_membership'],
                                         ],
                                     ],
                                 ]);
            logMessage("User not a member, chat_id $chat_id");
            exit;
        }
        if (!acquireLock($chat_id)) {
            sendMessage($chat_id, "⏳ Hold on, $username! I’m still processing your previous request.\n\n" .
                                 "Please wait a minute or contact support (@nilay_ok) to clear the lock! 😊");
            logMessage("Failed to acquire lock for chat_id $chat_id");
            exit;
        }
        $file_id = $message['document']['file_id'];
        $file = sendTelegramRequest('getFile', ['file_id' => $file_id]);
        if (!isset($file['result']['file_path'])) {
            sendMessage($chat_id, "❌ Oops, $username! I couldn’t download your JSON file.\n\n" .
                                 "Error: File not found on Telegram servers.\n\nPlease try uploading it again or contact support (@nilay_ok)! 😔");
            releaseLock($chat_id);
            logMessage("Failed to get file path for file_id $file_id, chat_id $chat_id");
            exit;
        }
        $file_path = $file['result']['file_path'];
        $file_url = "https://api.telegram.org/file/bot" . BOT_TOKEN . "/$file_path";
        $local_file = TEMP_DIR . "input_" . $chat_id . "_" . time() . ".json";
        $file_content = @file_get_contents($file_url);
        if ($file_content === false) {
            sendMessage($chat_id, "❌ Oops, $username! I couldn’t download your JSON file.\n\n" .
                                 "Error: Failed to fetch file from Telegram.\n\nPlease try uploading it again or contact support (@nilay_ok)! 😔");
            releaseLock($chat_id);
            logMessage("Failed to download file from $file_url, chat_id $chat_id");
            exit;
        }
        file_put_contents($local_file, $file_content);
        logMessage("Downloaded JSON file to $local_file");
        $json_content = file_get_contents($local_file);
        if (empty($json_content)) {
            sendMessage($chat_id, "❌ Invalid JSON, $username! Your file is empty.\n\n" .
                                 "Please upload a valid JSON file with this format:\n" .
                                 "```json\n" .
                                 "[\n  {\"uid\": \"YourUID1\", \"password\": \"YourPass1\"},\n  {\"uid\": \"YourUID2\", \"password\": \"YourPass2\"}\n]\n" .
                                 "```\n\nCheck your file and try again. Need help? Contact support (@nilay_ok)! 😊");
            unlink($local_file);
            releaseLock($chat_id);
            logMessage("Empty JSON file, chat_id $chat_id");
            exit;
        }
        $credentials = json_decode($json_content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($credentials)) {
            $json_error = json_last_error_msg();
            sendMessage($chat_id, "❌ Invalid JSON, $username! Your file doesn’t match the required format.\n\n" .
                                 "Error: $json_error\n\nPlease use this format:\n" .
                                 "```json\n" .
                                 "[\n  {\"uid\": \"YourUID1\", \"password\": \"YourPass1\"},\n  {\"uid\": \"YourUID2\", \"password\": \"YourPass2\"}\n]\n" .
                                 "```\n\nCheck your file and try again. Need help? Contact support (@nilay_ok)! 😊");
            unlink($local_file);
            releaseLock($chat_id);
            logMessage("JSON parsing error: $json_error, chat_id $chat_id");
            exit;
        }
        if (empty($credentials)) {
            sendMessage($chat_id, "❌ No accounts found, $username! Your JSON file contains no credentials.\n\n" .
                                 "Please upload a valid JSON file with this format:\n" .
                                 "```json\n" .
                                 "[\n  {\"uid\": \"YourUID1\", \"password\": \"YourPass1\"},\n  {\"uid\": \"YourUID2\", \"password\": \"YourPass2\"}\n]\n" .
                                 "```\n\nCheck your file and try again. Need help? Contact support (@nilay_ok)! 😊");
            unlink($local_file);
            releaseLock($chat_id);
            logMessage("Empty credentials array, chat_id $chat_id");
            exit;
        }
        if (isset($user_state['awaiting_bio_json']) && $user_state['awaiting_bio_json']) {
            if (count($credentials) != 1) {
                sendMessage($chat_id, "❌ Invalid JSON, $username! Bio change requires exactly one set of credentials:\n\n" .
                                     "```json\n" .
                                     "[{\"uid\": \"YourUID\", \"password\": \"YourPass\"}]\n" .
                                     "```\n\nPlease upload a JSON file with one credential set.");
                unlink($local_file);
                releaseLock($chat_id);
                logMessage("Invalid JSON for bio change: Expected 1 credential, got " . count($credentials));
                exit;
            }
            if (!isset($credentials[0]['uid']) || !isset($credentials[0]['password'])) {
                sendMessage($chat_id, "❌ Invalid JSON structure, $username! The credential must have 'uid' and 'password' fields.\n\n" .
                                     "Please upload a valid JSON file with this format:\n\n" .
                                     "```json\n" .
                                     "[{\"uid\": \"YourUID\", \"password\": \"YourPass\"}]\n" .
                                     "```\n\nCheck your file and try again. Contact support (@nilay_ok)! 😊");
                unlink($local_file);
                releaseLock($chat_id);
                logMessage("Invalid JSON structure for bio change: Missing uid or password, chat_id $chat_id");
                exit;
            }
            $user_state['bio_credentials'] = $credentials;
            $user_state['bio_local_file'] = $local_file;
            $user_state['awaiting_bio_json'] = false;
            $user_state['awaiting_bio_region'] = true;
            file_put_contents($state_file, json_encode($user_state));
            sendMessage($chat_id, "🌍 Please enter the region code for the bio change (e.g., IND, US):");
        } else {
            foreach ($credentials as $cred) {
                if (!isset($cred['uid']) || !isset($cred['password'])) {
                    sendMessage($chat_id, "❌ Invalid JSON structure, $username! Each entry must have 'uid' and 'password' fields.\n\n" .
                                         "Please upload a valid JSON file with this format:\n\n" .
                                         "```json\n" .
                                         "[\n  {\"uid\": \"YourUID1\", \"password\": \"YourPass1\"},\n  {\"uid\": \"YourUID2\", \"password\": \"YourPass2\"}\n]\n" .
                                         "```\n\nCheck your file and try again. Contact support (@nilay_ok)! 😊");
                    unlink($local_file);
                    releaseLock($chat_id);
                    logMessage("Invalid JSON structure: Missing uid or password, chat_id $chat_id");
                    exit;
                }
            }
            $total_count = count($credentials);
            $user_state['credentials'] = $credentials;
            $user_state['local_file'] = $local_file;
            file_put_contents($state_file, json_encode($user_state));
            logMessage("Stored $total_count credentials for chat_id $chat_id");
            sendMessage($chat_id, "✅ Found $total_count guest IDs in your JSON file, $username! Choose an option:", [
                'inline_keyboard' => [
                    [
                        ['text' => 'GENERATE CUSTOM', 'callback_data' => 'custom_generate'],
                        ['text' => 'GENERATE ALL IDS', 'callback_data' => 'all_generate'],
                    ],
                    [
                        ['text' => 'Decode Tokens 🔍', 'callback_data' => 'decode_tokens'],
                        ['text' => 'Change Bio ✍️', 'callback_data' => 'change_bio'],
                    ],
                ],
            ]);
        }
        releaseLock($chat_id);
    } catch (Exception $e) {
        sendMessage($chat_id, "❌ Unexpected error, $username! Something went wrong while processing your file.\n\n" .
                            "Error: " . $e->getMessage() . "\n\nPlease try again or contact support (@nilay_ok)! 😔");
        if (isset($local_file) && file_exists($local_file)) {
            unlink($local_file);
        }
        releaseLock($chat_id);
        logMessage("Exception in JSON handling: " . $e->getMessage() . ", chat_id $chat_id");
        exit;
    }
}
?>
