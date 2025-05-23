<?php

// Enable error logging for debugging
ini_set('display_errors', 0); // Errors ko browser mein show nahi karenge
ini_set('log_errors', 1);
ini_set('error_log', 'error_log.txt'); // Errors ko file mein log karenge
error_reporting(E_ALL);

// Telegram Bot Token
$botToken = "7711988726:AAGimofS_-3_2zU1xu9e7DQalJ756nj3hKI";
$apiUrl = "https://api.telegram.org/bot$botToken/";
$channelLink = "https://t.me/nr_codex";
$instaId = "@im_.nilay._";
$jwtApiUrl = "https://akiru-jwt-10.vercel.app/token?uid={Uid}&password={Password}";

// Log incoming updates for debugging
$update = json_decode(file_get_contents("php://input"), true);
file_put_contents('update_log.txt', print_r($update, true) . "\n", FILE_APPEND);

// Function to send messages to Telegram
function sendMessage($chatId, $message, $replyMarkup = null) {
    global $apiUrl;
    $data = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'Markdown',
    ];
    if ($replyMarkup) {
        $data['reply_markup'] = json_encode($replyMarkup);
    }
    $ch = curl_init($apiUrl . "sendMessage");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        file_put_contents('error_log.txt', "SendMessage Error: " . curl_error($ch) . "\n", FILE_APPEND);
    }
    curl_close($ch);
    return json_decode($response, true);
}

// Function to send a file to Telegram
function sendDocument($chatId, $filePath, $caption, $replyMarkup = null) {
    global $apiUrl;
    $data = [
        'chat_id' => $chatId,
        'caption' => $caption,
        'parse_mode' => 'Markdown',
        'document' => new CURLFile($filePath)
    ];
    if ($replyMarkup) {
        $data['reply_markup'] = json_encode($replyMarkup);
    }
    $ch = curl_init($apiUrl . "sendDocument");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        file_put_contents('error_log.txt', "SendDocument Error: " . curl_error($ch) . "\n", FILE_APPEND);
    }
    curl_close($ch);
    return json_decode($response, true);
}

// Function to make API request to generate JWT
function generateJwtToken($uid, $password) {
    global $jwtApiUrl;
    $url = str_replace(["{Uid}", "{Password}"], [$uid, $password], $jwtApiUrl);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) {
        file_put_contents('error_log.txt', "JWT API Error: " . curl_error($ch) . "\n", FILE_APPEND);
    }
    curl_close($ch);
    return [$httpCode, $response];
}

// Function to check if user is a member of the channel
function isChannelMember($chatId, $userId) {
    global $apiUrl, $channelLink;
    $ch = curl_init($apiUrl . "getChatMember?chat_id=" . urlencode($channelLink) . "&user_id=$userId");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        file_put_contents('error_log.txt', "Channel Member Check Error: " . curl_error($ch) . "\n", FILE_APPEND);
    }
    curl_close($ch);
    $result = json_decode($response, true);
    return isset($result['result']['status']) && in_array($result['result']['status'], ['member', 'administrator', 'creator']);
}

// Main bot logic
if (!$update) {
    file_put_contents('error_log.txt', "No update received: " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
    http_response_code(200); // Telegram expects a 200 response
    exit;
}

$chatId = isset($update['message']['chat']['id']) ? $update['message']['chat']['id'] : (isset($update['callback_query']['message']['chat']['id']) ? $update['callback_query']['message']['chat']['id'] : null);
$userId = isset($update['message']['from']['id']) ? $update['message']['from']['id'] : (isset($update['callback_query']['from']['id']) ? $update['callback_query']['from']['id'] : null);
$username = isset($update['message']['from']['username']) ? "@" . $update['message']['from']['username'] : (isset($update['message']['from']['first_name']) ? $update['message']['from']['first_name'] : "User");
$messageText = isset($update['message']['text']) ? $update['message']['text'] : "";
$callbackData = isset($update['callback_query']['data']) ? $update['callback_query']['data'] : "";

if (!$chatId || !$userId) {
    file_put_contents('error_log.txt', "Invalid chatId or userId: " . print_r($update, true) . "\n", FILE_APPEND);
    http_response_code(200);
    exit;
}

// Handle /start command
if ($messageText === "/start") {
    $replyMarkup = [
        'inline_keyboard' => [
            [
                ['text' => 'Join Telegram Channel 📢', 'url' => $channelLink],
                ['text' => 'Join Instagram 📸', 'url' => "https://instagram.com/" . ltrim($instaId, '@')]
            ],
            [['text' => 'Verify Membership ✅', 'callback_data' => 'verify_membership']]
        ]
    ];
    sendMessage($chatId, "👋 Hey $username! Welcome to NR CODEX JWT! 🚀\nI'm your go-to bot for generating JWT tokens for Free Fire guest IDs. To get started, please join our official Telegram and Instagram for updates and support:\n📢 Below to join and verify your membership! 😊", $replyMarkup);
}

// Handle callback queries
if ($callbackData === "verify_membership") {
    if (isChannelMember($chatId, $userId)) {
        sendMessage($chatId, "🎉 Awesome, $username! You're a member of *NR CODEX BOTS* ⚡! 🙌\nNR CODEX JWT Bot is ready to roll! 🚀\nSend me a JSON file with your Free Fire guest ID credentials in this format:\n```json\n[\n  {\"uid\": \"1234567890\", \"password\": \"PASSWORD1\"},\n  {\"uid\": \"0987654321\", \"password\": \"PASSWORD2\"}\n]\n```\n🔄 *Retry failed accounts only 10 times*\n📄 *Send you a single JSON file with all your JWT tokens*");
    } else {
        $replyMarkup = [
            'inline_keyboard' => [
                [['text' => 'Join Telegram Channel 📢', 'url' => $channelLink]],
                [['text' => 'Verify Membership ✅', 'callback_data' => 'verify_membership']]
            ]
        ];
        sendMessage($chatId, "❌ $username, you need to join our Telegram channel first! 😊\nPlease join and try verifying again.", $replyMarkup);
    }
}

// Handle JSON file upload
if (isset($update['message']['document']) && $update['message']['document']['mime_type'] === 'application/json') {
    if (!isChannelMember($chatId, $userId)) {
        sendMessage($chatId, "❌ $username, please join our Telegram channel and verify your membership before uploading a JSON file!");
        exit;
    }

    // Download the JSON file
    $fileId = $update['message']['document']['file_id'];
    $fileInfo = json_decode(file_get_contents($apiUrl . "getFile?file_id=$fileId"), true);
    if (!$fileInfo['ok']) {
        file_put_contents('error_log.txt', "File download failed: " . print_r($fileInfo, true) . "\n", FILE_APPEND);
        sendMessage($chatId, "❌ Error downloading the JSON file. Please try again!");
        exit;
    }

    $filePath = $fileInfo['result']['file_path'];
    $fileUrl = "https://api.telegram.org/file/bot$botToken/$filePath";
    $jsonContent = file_get_contents($fileUrl);
    $accounts = json_decode($jsonContent, true);

    // Validate JSON
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($accounts)) {
        sendMessage($chatId, "❌ Invalid JSON format! Please send a valid JSON file with uid and password pairs.");
        exit;
    }

    $totalAccounts = count($accounts);
    if ($totalAccounts === 0) {
        sendMessage($chatId, "❌ The JSON file is empty! Please provide valid account data.");
        exit;
    }

    // Initialize progress
    $successful = 0;
    $failed = 0;
    $invalid = 0;
    $tokens = [];
    $startTime = microtime(true);

    // Progress bar logic
    sendMessage($chatId, "⏳ Working on it, $username! Processing your *$totalAccounts accounts*...");
    $progressMessageId = null;

    // Process each account
    foreach ($accounts as $index => $account) {
        if (!isset($account['uid']) || !isset($account['password'])) {
            $invalid++;
            continue;
        }

        $uid = $account['uid'];
        $password = $account['password'];
        $retries = 0;
        $maxRetries = 10;
        $success = false;

        // Retry logic
        while ($retries < $maxRetries && !$success) {
            list($httpCode, $response) = generateJwtToken($uid, $password);
            $responseData = json_decode($response, true);

            if ($httpCode === 200 && isset($responseData['token'])) {
                $tokens[] = ['uid' => $uid, 'token' => $responseData['token']];
                $successful++;
                $success = true;
            } else {
                $retries++;
                if ($retries === $maxRetries) {
                    $failed++;
                    file_put_contents('error_log.txt', "Failed for UID $uid after $maxRetries retries: " . $response . "\n", FILE_APPEND);
                }
                sleep(2); // Increased delay to avoid rate limits
            }
        }

        // Update progress bar
        $progress = floor(($index + 1) / $totalAccounts * 100);
        $bar = str_repeat("▰", $progress / 10) . str_repeat("▱", 10 - $progress / 10);
        $progressText = "⏳ Working on it, $username! Processing your *$totalAccounts accounts*...\n$bar $progress%";

        if ($progressMessageId) {
            $editData = [
                'chat_id' => $chatId,
                'message_id' => $progressMessageId,
                'text' => $progressText,
                'parse_mode' => 'Markdown'
            ];
            $ch = curl_init($apiUrl . "editMessageText");
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $editData);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $response = curl_exec($ch);
            if (curl_errno($ch)) {
                file_put_contents('error_log.txt', "EditMessage Error: " . curl_error($ch) . "\n", FILE_APPEND);
            } else {
                $responseData = json_decode($response, true);
                if (!$responseData['ok']) {
                    file_put_contents('error_log.txt', "EditMessage Failed: " . print_r($responseData, true) . "\n", FILE_APPEND);
                }
            }
            curl_close($ch);
        } else {
            $response = sendMessage($chatId, $progressText);
            if ($response['ok']) {
                $progressMessageId = $response['result']['message_id'];
            } else {
                file_put_contents('error_log.txt', "Progress Message Failed: " . print_r($response, true) . "\n", FILE_APPEND);
            }
        }
    }

    // Calculate time taken
    $timeTaken = round((microtime(true) - $startTime) / 60, 2);

    // Save tokens to a JSON file
    $outputFile = "jwt_tokens_" . time() . ".json";
    file_put_contents($outputFile, json_encode($tokens, JSON_PRETTY_PRINT));

    // Send final output
    $caption = "🎉 Done, $username! Your JWT tokens are ready! 🚀\n📑 *JWT Token Results*\n🔢 *Total Accounts*: $totalAccounts\n✅ *Successful*: $successful\n❌ *Failed*: $failed\n⚠️ *Invalid*: $invalid\n⏱️ *Time Taken*: $timeTaken minutes\n🌐 *APIs Used*: $jwtApiUrl\nYour tokens are in the file below! 📄\nNeed more? Upload another JSON! 😊";
    $replyMarkup = [
        'inline_keyboard' => [
            [['text' => 'Generate Again 🚀', 'callback_data' => 'start_again']]
        ]
    ];
    sendDocument($chatId, $outputFile, $caption, $replyMarkup);

    // Clean up
    unlink($outputFile);
}

// Handle "Generate Again" callback
if ($callbackData === "start_again") {
    sendMessage($chatId, "🚀 Ready to generate more JWT tokens, $username?\nPlease send a new JSON file with your Free Fire guest ID credentials!");
}

// Send HTTP 200 to Telegram to acknowledge receipt
http_response_code(200);
?>
