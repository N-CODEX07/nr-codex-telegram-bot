<?php

// Telegram Bot Token
$botToken = "7711988726:AAEI3GRUWDf3_4Lhhs9G9lW3Ympwvi2zk8M";
$apiUrl = "https://api.telegram.org/bot$botToken/";
$channelLink = "https://t.me/nr_codex";
$jwtApiUrl = "https://akiru-jwt-10.vercel.app/token?uid={Uid}&password={Password}";

// Function to send Telegram messages
function sendMessage($chatId, $text, $replyMarkup = null) {
    global $apiUrl;
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'Markdown',
    ];
    if ($replyMarkup) {
        $data['reply_markup'] = json_encode($replyMarkup);
    }
    $ch = curl_init($apiUrl . "sendMessage");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

// Function to send a file
function sendDocument($chatId, $filePath, $caption = '') {
    global $apiUrl;
    $data = [
        'chat_id' => $chatId,
        'document' => new CURLFile($filePath),
        'caption' => $caption,
        'parse_mode' => 'Markdown',
    ];
    $ch = curl_init($apiUrl . "sendDocument");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

// Function to call JWT API
function getJwtToken($uid, $password) {
    global $jwtApiUrl;
    $url = str_replace(['{Uid}', '{Password}'], [$uid, $password], $jwtApiUrl);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

// Function to check if user is a member of the channel
function isChannelMember($chatId, $userId) {
    global $apiUrl;
    $ch = curl_init($apiUrl . "getChatMember?chat_id=@nr_codex&user_id=$userId");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    return isset($data['result']['status']) && in_array($data['result']['status'], ['member', 'administrator', 'creator']);
}

// Log incoming updates for debugging
function logUpdate($data) {
    file_put_contents('debug.log', print_r($data, true) . "\n", FILE_APPEND);
}

// Main bot logic
$update = json_decode(file_get_contents('php://input'), true);

// Log the update for debugging
logUpdate($update);

// Validate update
if (!is_array($update) || empty($update)) {
    error_log("Invalid or empty update received");
    exit;
}

// Extract chatId, userId, and username with null checks
$chatId = null;
$userId = null;
$username = 'User';

if (isset($update['message']['chat']['id'])) {
    $chatId = $update['message']['chat']['id'];
    $userId = $update['message']['from']['id'] ?? null;
    $username = $update['message']['from']['username'] ?? 'User';
} elseif (isset($update['callback_query']['message']['chat']['id'])) {
    $chatId = $update['callback_query']['message']['chat']['id'];
    $userId = $update['callback_query']['from']['id'] ?? null;
    $username = $update['callback_query']['from']['username'] ?? 'User';
}

if (!$chatId || !$userId) {
    error_log("Missing chatId or userId in update");
    exit;
}

$messageText = $update['message']['text'] ?? '';
$callbackData = $update['callback_query']['data'] ?? '';

if ($messageText == '/start') {
    $replyMarkup = [
        'inline_keyboard' => [
            [
                ['text' => 'Join Channel 📢', 'url' => $channelLink],
                ['text' => 'Verify Membership ✅', 'callback_data' => 'verify_membership'],
            ],
        ],
    ];
    $text = "👋 Hey @$username! Welcome to *NR CODEX JWT*! 🚀\nI'm your go-to bot for generating JWT tokens for Free Fire guest IDs. To get started, please join our official Telegram channel for updates and support: 📢 below to join and verify your membership! 😊";
    sendMessage($chatId, $text, $replyMarkup);
} elseif ($callbackData == 'verify_membership') {
    if (isChannelMember($chatId, $userId)) {
        $replyMarkup = [
            'inline_keyboard' => [
                [
                    ['text' => 'Generate One Token 🔑', 'callback_data' => 'generate_one'],
                    ['text' => 'Generate More 📚', 'callback_data' => 'generate_more'],
                ],
            ],
        ];
        $text = "🎉 Awesome, @$username! You're a member of *NR CODEX BOTS* ⚡! 🙌\nNR CODEX JWT Bot is ready to roll! 🚀 Select what you want:";
        sendMessage($chatId, $text, $replyMarkup);
    } else {
        $replyMarkup = [
            'inline_keyboard' => [
                [
                    ['text' => 'Join Channel 📢', 'url' => $channelLink],
                    ['text' => 'Verify Membership ✅', 'callback_data' => 'verify_membership'],
                ],
            ],
        ];
        $text = "❌ @$username, you need to join our channel first! 📢 Please join and verify again.";
        sendMessage($chatId, $text, $replyMarkup);
    }
} elseif ($callbackData == 'generate_one' || $callbackData == 'generate_more') {
    $text = "📤 Please upload a JSON file containing UIDs and passwords.";
    sendMessage($chatId, $text);
} elseif (isset($update['message']['document'])) {
    if (!isChannelMember($chatId, $userId)) {
        $replyMarkup = [
            'inline_keyboard' => [
                [
                    ['text' => 'Join Channel 📢', 'url' => $channelLink],
                    ['text' => 'Verify Membership ✅', 'callback_data' => 'verify_membership'],
                ],
            ],
        ];
        sendMessage($chatId, "❌ @$username, please join the channel and verify your membership first!", $replyMarkup);
        exit;
    }

    $fileId = $update['message']['document']['file_id'] ?? null;
    if (!$fileId) {
        sendMessage($chatId, "❌ Error: No valid file found in the message.");
        exit;
    }

    $filePath = json_decode(file_get_contents($apiUrl . "getFile?file_id=$fileId"), true)['result']['file_path'] ?? null;
    if (!$filePath) {
        sendMessage($chatId, "❌ Error: Could not retrieve file path.");
        exit;
    }

    $fileUrl = "https://api.telegram.org/file/bot$botToken/$filePath";
    $jsonContent = file_get_contents($fileUrl);
    $accounts = json_decode($jsonContent, true);

    if (!$accounts || !is_array($accounts)) {
        sendMessage($chatId, "❌ Invalid JSON file! Please upload a valid JSON file with UIDs and passwords.");
        exit;
    }

    $totalAccounts = count($accounts);
    $successful = 0;
    $failed = 0;
    $invalid = 0;
    $tokens = [];
    $startTime = microtime(true);

    // Progress bar simulation
    for ($i = 10; $i <= 100; $i += 10) {
        $progressBar = str_repeat('▰', $i / 10) . str_repeat('▱', 10 - $i / 10);
        $text = "⏳ Working on it, @$username! Processing your *$totalAccounts* accounts...\n$progressBar $i%";
        sendMessage($chatId, $text);
        usleep(500000); // Simulate processing delay
    }

    // Process each account
    foreach ($accounts as $account) {
        if (isset($account['uid']) && isset($account['password'])) {
            $response = getJwtToken($account['uid'], $account['password']);
            if (isset($response['token'])) {
                $tokens[] = ['token' => $response['token']];
                $successful++;
            } else {
                $failed++;
            }
        } else {
            $invalid++;
        }
    }

    // Save tokens to file
    $outputFile = "tokens_" . time() . ".json";
    file_put_contents($outputFile, json_encode($tokens, JSON_PRETTY_PRINT));

    // Calculate time taken
    $timeTaken = round((microtime(true) - $startTime) / 60, 2);

    // Send final output
    $text = "🎉 Done, @$username! Your JWT tokens are ready! 🚀\n📑 *JWT Token Results*\n🔢 *Total Accounts*: $totalAccounts\n✅ *Successful*: $successful\n❌ *Failed*: $failed\n⚠️ *Invalid*: $invalid\n⏱️ *Time Taken*: $timeTaken minutes\n🌐 *APIs Used*: akiru-jwt-10\nYour tokens are in the file below! 📄\nNeed more? Upload another JSON! 😊";
    $replyMarkup = [
        'inline_keyboard' => [
            [
                ['text' => 'Generate Again 🚀', 'callback_data' => 'generate_more'],
            ],
        ],
    ];
    sendMessage($chatId, $text, $replyMarkup);
    sendDocument($chatId, $outputFile, "Here’s your JWT tokens file! 📄");

    // Clean up
    unlink($outputFile);
}
