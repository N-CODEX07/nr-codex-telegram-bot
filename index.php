<?php
// Bot configuration
define('BOT_TOKEN', '7711988726:AAGimofS_-3_2zU1xu9e7DQalJ756nj3hKI');
define('CHANNEL_ID', '@nr_codex');
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('JWT_API', 'https://akiru-jwt-10.vercel.app/token?uid={Uid}&password={Password}');

// Error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Get update from Telegram
$update = json_decode(file_get_contents('php://input'), true);

if (!empty($update)) {
    processUpdate($update);
} else {
    echo "Bot is running. Waiting for updates...";
}

function processUpdate($update) {
    if (isset($update['message'])) {
        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        $text = $message['text'] ?? '';
        $from_id = $message['from']['id'];

        if ($text === '/start') {
            sendWelcomeMessage($chat_id);
        } elseif (isset($message['document'])) {
            if (checkChannelMembership($from_id)) {
                handleDocument($chat_id, $message['document']);
            } else {
                askToJoinChannel($chat_id);
            }
        }
    } elseif (isset($update['callback_query'])) {
        handleCallbackQuery($update['callback_query']);
    }
}

function checkChannelMembership($user_id) {
    $url = API_URL . "getChatMember?chat_id=" . CHANNEL_ID . "&user_id=$user_id";
    $response = json_decode(file_get_contents($url), true);
    return ($response['ok'] && in_array($response['result']['status'], ['member', 'administrator', 'creator']));
}

function askToJoinChannel($chat_id) {
    $message = "❌ Please join our channel first: " . CHANNEL_ID;
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => 'Join Channel', 'url' => 'https://t.me/nr_codex'],
                ['text' => '✅ I Joined', 'callback_data' => 'check_membership']
            ]
        ]
    ];
    sendMessage($chat_id, $message, $keyboard);
}

function sendWelcomeMessage($chat_id) {
    $welcome_text = "Welcome to NR CODEX JWT Bot!\n\n"
                  . "Send me a JSON file with accounts in this format:\n\n"
                  . "[{\"uid\": \"12345\", \"password\": \"pass123\"}, ...]";
    
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => 'Join Channel', 'url' => 'https://t.me/nr_codex'],
                ['text' => '✅ I Joined', 'callback_data' => 'check_membership']
            ]
        ]
    ];
    sendMessage($chat_id, $welcome_text, $keyboard);
}

function handleCallbackQuery($callback_query) {
    $chat_id = $callback_query['message']['chat']['id'];
    $user_id = $callback_query['from']['id'];
    
    if (checkChannelMembership($user_id)) {
        sendMessage($chat_id, "✅ Thank you for joining! Now send your JSON file.");
    } else {
        askToJoinChannel($chat_id);
    }
}

function handleDocument($chat_id, $document) {
    if ($document['mime_type'] !== 'application/json') {
        sendMessage($chat_id, "❌ Please upload a valid JSON file.");
        return;
    }

    $file_id = $document['file_id'];
    $file_info = json_decode(file_get_contents(API_URL . "getFile?file_id=$file_id"), true);
    
    if (!$file_info['ok']) {
        sendMessage($chat_id, "❌ Error downloading file.");
        return;
    }

    $file_url = "https://api.telegram.org/file/bot" . BOT_TOKEN . "/" . $file_info['result']['file_path'];
    $json_content = file_get_contents($file_url);
    $accounts = json_decode($json_content, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        sendMessage($chat_id, "❌ Invalid JSON format.");
        return;
    }

    processAccounts($chat_id, $accounts);
}

function processAccounts($chat_id, $accounts) {
    $total = count($accounts);
    $success = 0;
    $failed = 0;
    $tokens = [];
    
    $progress_msg = sendMessage($chat_id, "⏳ Processing 0/$total accounts...");

    foreach ($accounts as $index => $account) {
        if (!isset($account['uid']) || !isset($account['password'])) {
            $failed++;
            continue;
        }

        $api_url = str_replace(
            ['{Uid}', '{Password}'],
            [$account['uid'], $account['password']],
            JWT_API
        );

        $response = file_get_contents($api_url);
        $data = json_decode($response, true);

        if (isset($data['token'])) {
            $tokens[] = $data['token'];
            $success++;
        } else {
            $failed++;
        }

        // Update progress every 10 accounts
        if ($index % 10 === 0) {
            editMessage($chat_id, $progress_msg['result']['message_id'], 
                "⏳ Processing $index/$total accounts...\nSuccess: $success, Failed: $failed");
        }
    }

    // Save results
    $output = [
        'total_accounts' => $total,
        'successful' => $success,
        'failed' => $failed,
        'tokens' => $tokens
    ];

    $filename = 'tokens_' . time() . '.json';
    file_put_contents($filename, json_encode($output, JSON_PRETTY_PRINT));
    
    sendDocument($chat_id, $filename);
    unlink($filename);
}

function sendMessage($chat_id, $text, $reply_markup = null) {
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
    if ($reply_markup) {
        $data['reply_markup'] = json_encode($reply_markup);
    }
    
    return apiRequest('sendMessage', $data);
}

function editMessage($chat_id, $message_id, $text) {
    return apiRequest('editMessageText', [
        'chat_id' => $chat_id,
        'message_id' => $message_id,
        'text' => $text
    ]);
}

function sendDocument($chat_id, $document_path) {
    return apiRequest('sendDocument', [
        'chat_id' => $chat_id,
        'document' => new CURLFile($document_path)
    ]);
}

function apiRequest($method, $data) {
    $url = API_URL . $method;
    $ch = curl_init($url);
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}
