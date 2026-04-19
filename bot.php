<?php
/**
 * ╔══════════════════════════════════════════╗
 * ║   BEST SMM PANEL — Telegram Bot         ║
 * ║   Railway.app pe deploy karo            ║
 * ╚══════════════════════════════════════════╝
 *
 * SETUP:
 * 1. Railway.app pe deploy karo
 * 2. .env mein BOT_TOKEN aur ADMIN_CHAT_ID daalo
 * 3. Webhook set karo:
 *    https://api.telegram.org/botTOKEN/setWebhook?url=https://YOUR_RAILWAY_URL/bot.php
 */

// ═══════════════ CONFIG ═══════════════
$BOT_TOKEN    = getenv('BOT_TOKEN')    ?: '8218865814:AAE9CJ_pEphpLV_aCOJ5DPD9gooPhN4uLho';
$ADMIN_ID     = getenv('ADMIN_CHAT_ID') ?: '6270522295';
$MINI_APP_URL = getenv('MINI_APP_URL') ?: 'https://YOUR_RAILWAY_URL/miniapp.html';
$DATA_FILE    = __DIR__ . '/data.json';
$STATE_FILE   = __DIR__ . '/state.json';
// ═════════════════════════════════════

define('BOT_TOKEN',    $BOT_TOKEN);
define('ADMIN_ID',     $ADMIN_ID);
define('MINI_APP_URL', $MINI_APP_URL);

// ── GET requests (webhook setup / ping) ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $q = $_GET['q'] ?? '';
    if ($q === 'setup') {
        $appUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        $r = tgReq('setWebhook', ['url' => $appUrl . '/bot.php']);
        echo json_encode(['webhook' => $r]);
    } elseif ($q === 'info') {
        echo json_encode(tgReq('getWebhookInfo', []));
    } else {
        echo json_encode(['status' => 'ok', 'bot' => 'Best SMM Panel Bot', 'time' => date('Y-m-d H:i:s')]);
    }
    exit();
}

// ── Notification from Mini App ──
if (!empty($_POST['notify_type'])) {
    handleNotify();
    exit();
}

// ── Telegram Update ──
$input  = file_get_contents('php://input');
$update = json_decode($input, true);
if (!$update) exit();

// Callback button press
if (!empty($update['callback_query'])) {
    handleCallback($update['callback_query']);
    exit();
}

$msg  = $update['message'] ?? null;
if (!$msg) exit();

$chatId = $msg['chat']['id'];
$text   = trim($msg['text'] ?? '');
$from   = $msg['from']['first_name'] ?? 'User';
$uname  = $msg['from']['username'] ?? '';

// ── User registration ──
registerUser($chatId, $from, $uname);

// ── Admin check ──
$isAdmin = ((string)$chatId === (string)ADMIN_ID);

// ── State machine ──
$state = getState($chatId);
if ($state && !str_starts_with($text, '/')) {
    handleState($chatId, $text, $state, $isAdmin);
    exit();
}

// ── Commands ──
switch (true) {
    case $text === '/start' || $text === '/menu':
        // Check referral
        if (str_starts_with($text, '/start ref_')) {
            $refId = substr($text, 11);
            handleReferral($chatId, $refId);
        }
        sendMainMenu($chatId, $from, $isAdmin);
        break;
    case $text === '/admin' && $isAdmin:
        sendAdminMenu($chatId);
        break;
    default:
        sendMainMenu($chatId, $from, $isAdmin);
}

// ════════════════════════════════════════════
// MINI APP NOTIFICATION HANDLER
// ════════════════════════════════════════════
function handleNotify() {
    global $ADMIN_ID;
    $type    = $_POST['notify_type'];
    $payload = json_decode($_POST['data'] ?? '{}', true);

    switch ($type) {
        case 'new_order':
            $o    = $payload;
            $text = "📦 *New Order!*\n\n"
                  . "👤 {$o['name']} (@{$o['username']})\n"
                  . "🏷 Platform: " . strtoupper($o['platform'] ?? '') . "\n"
                  . "📋 {$o['svc']}\n"
                  . "🔗 `{$o['link']}`\n"
                  . "📦 Qty: {$o['qty']}\n"
                  . "💰 ₹{$o['cost']}\n"
                  . "📅 {$o['date']}";
            $kb = [[
                ['text' => '✅ Mark Done',    'callback_data' => 'ord_done_' . $o['id']],
                ['text' => '❌ Mark Failed',  'callback_data' => 'ord_fail_' . $o['id']],
            ]];
            tgReq('sendMessage', [
                'chat_id'      => ADMIN_ID,
                'text'         => $text,
                'parse_mode'   => 'Markdown',
                'reply_markup' => ['inline_keyboard' => $kb],
            ]);

            // Save order to data.json
            $data = getData();
            $data['orders'][] = $o;
            saveData($data);

            echo json_encode(['sent' => true]);
            break;

        case 'new_payment':
            $r    = $payload;
            $text = "💳 *New Payment Request!*\n\n"
                  . "👤 {$r['name']} (@{$r['username']})\n"
                  . "💰 ₹{$r['amount']}\n"
                  . "🔢 UTR: `{$r['utr']}`\n"
                  . "📅 {$r['date']}";
            $kb = [[
                ['text' => '✅ Approve ₹' . $r['amount'], 'callback_data' => 'pay_app_' . $r['id'] . '_' . $r['amount'] . '_' . ($r['username'] ?? '')],
                ['text' => '❌ Reject',                    'callback_data' => 'pay_rej_' . $r['id']],
            ]];
            tgReq('sendMessage', [
                'chat_id'      => ADMIN_ID,
                'text'         => $text,
                'parse_mode'   => 'Markdown',
                'reply_markup' => ['inline_keyboard' => $kb],
            ]);

            // Save payreq
            $data = getData();
            $data['payreqs'][] = array_merge($r, ['status' => 'pending']);
            saveData($data);

            echo json_encode(['sent' => true]);
            break;
    }
}

// ════════════════════════════════════════════
// CALLBACK HANDLER
// ════════════════════════════════════════════
function handleCallback($cb) {
    $chatId = $cb['message']['chat']['id'];
    $cbId   = $cb['id'];
    $data   = $cb['data'];
    $msgId  = $cb['message']['message_id'];
    $isAdmin = ((string)$chatId === (string)ADMIN_ID);

    tgReq('answerCallbackQuery', ['callback_query_id' => $cbId]);

    // Admin callbacks
    if ($isAdmin) {
        if (str_starts_with($data, 'pay_app_')) {
            // pay_app_{id}_{amount}_{username}
            $parts   = explode('_', substr($data, 8), 3);
            $payId   = $parts[0] ?? '';
            $amount  = $parts[1] ?? 0;
            $uname   = $parts[2] ?? '';
            approvePayment($payId, (float)$amount, $uname, $chatId, $msgId);
            return;
        }
        if (str_starts_with($data, 'pay_rej_')) {
            $payId = substr($data, 8);
            rejectPayment($payId, $chatId, $msgId);
            return;
        }
        if (str_starts_with($data, 'ord_done_')) {
            $ordId = substr($data, 9);
            updateOrderStatus($ordId, 'done');
            tgReq('editMessageText', [
                'chat_id'    => $chatId,
                'message_id' => $msgId,
                'text'       => "✅ Order `{$ordId}` marked as *DONE*",
                'parse_mode' => 'Markdown',
            ]);
            return;
        }
        if (str_starts_with($data, 'ord_fail_')) {
            $ordId = substr($data, 9);
            updateOrderStatus($ordId, 'failed');
            tgReq('editMessageText', [
                'chat_id'    => $chatId,
                'message_id' => $msgId,
                'text'       => "❌ Order `{$ordId}` marked as *FAILED*",
                'parse_mode' => 'Markdown',
            ]);
            return;
        }
        if ($data === 'admin') { sendAdminMenu($chatId); return; }
        if (str_starts_with($data, 'add_bal_')) {
            setState($chatId, ['action' => 'add_balance', 'username' => substr($data, 8)]);
            sendMsg($chatId, "💰 Enter amount to ADD for @" . substr($data, 8) . ":");
            return;
        }
        if (str_starts_with($data, 'stats')) { sendAdminStats($chatId); return; }
        if (str_starts_with($data, 'allusers')) { sendAllUsers($chatId); return; }
        if (str_starts_with($data, 'allorders')) { sendAllOrders($chatId); return; }
        if ($data === 'broadcast') {
            setState($chatId, ['action' => 'broadcast']);
            sendMsg($chatId, "📢 Enter broadcast message:");
            return;
        }
    }

    // User callbacks
    switch ($data) {
        case 'menu': sendMainMenu($chatId, '', false); break;
        case 'open_app':
            tgReq('sendMessage', [
                'chat_id'    => $chatId,
                'text'       => "🚀 Open Best SMM Panel Mini App:",
                'reply_markup' => ['inline_keyboard' => [[
                    ['text' => '🌐 Open App', 'web_app' => ['url' => MINI_APP_URL]]
                ]]]
            ]);
            break;
    }
}

// ════════════════════════════════════════════
// MENUS
// ════════════════════════════════════════════
function sendMainMenu($chatId, $name = '', $isAdmin = false) {
    $data    = getData();
    $userRec = findUser($chatId, $data);
    $bal     = $userRec['balance'] ?? 0;

    $text = "👋 Welcome back, *{$name}*!\n\n"
          . "💰 Balance: *₹" . number_format($bal, 2) . "*\n\n"
          . "🚀 *Best SMM Panel*\n"
          . "Real Likes • Views • Followers\n"
          . "Instagram • YouTube • Telegram\n"
          . "Instant • Trusted • Premium";

    $kb = [
        [['text' => '🌐 Open SMM Panel', 'web_app' => ['url' => MINI_APP_URL]]],
        [['text' => '💰 Balance: ₹' . number_format($bal, 2), 'callback_data' => 'menu'],
         ['text' => '📦 My Orders',  'callback_data' => 'open_app']],
        [['text' => '➕ Add Fund',    'callback_data' => 'open_app'],
         ['text' => '🤝 Refer & Earn','callback_data' => 'open_app']],
        [['text' => '🎁 Daily Reward', 'callback_data' => 'open_app']],
    ];

    if ($isAdmin) {
        $kb[] = [['text' => '⚙️ Admin Panel', 'callback_data' => 'admin']];
    }

    tgReq('sendMessage', [
        'chat_id'      => $chatId,
        'text'         => $text,
        'parse_mode'   => 'Markdown',
        'reply_markup' => ['inline_keyboard' => $kb],
    ]);
}

function sendAdminMenu($chatId) {
    $data   = getData();
    $users  = count($data['users'] ?? []);
    $orders = count($data['orders'] ?? []);
    $pend   = count(array_filter($data['payreqs'] ?? [], fn($r) => $r['status'] === 'pending'));

    $text = "⚙️ *Admin Panel*\n\n"
          . "👥 Total Users: *{$users}*\n"
          . "📦 Total Orders: *{$orders}*\n"
          . "💳 Pending Payments: *{$pend}*";

    $kb = [
        [['text' => '📊 Stats',       'callback_data' => 'stats'],
         ['text' => '👥 All Users',   'callback_data' => 'allusers']],
        [['text' => '📦 All Orders',  'callback_data' => 'allorders'],
         ['text' => '📢 Broadcast',   'callback_data' => 'broadcast']],
        [['text' => '🔙 Main Menu',   'callback_data' => 'menu']],
    ];

    tgReq('sendMessage', [
        'chat_id'      => $chatId,
        'text'         => $text,
        'parse_mode'   => 'Markdown',
        'reply_markup' => ['inline_keyboard' => $kb],
    ]);
}

function sendAdminStats($chatId) {
    $data   = getData();
    $orders = $data['orders'] ?? [];
    $pays   = $data['payreqs'] ?? [];
    $users  = $data['users'] ?? [];

    $rev    = array_sum(array_column(array_filter($pays, fn($r) => $r['status'] === 'approved'), 'amount'));
    $pendO  = count(array_filter($orders, fn($o) => $o['status'] === 'pending'));
    $doneO  = count(array_filter($orders, fn($o) => $o['status'] === 'done'));
    $pendP  = count(array_filter($pays,   fn($r) => $r['status'] === 'pending'));

    $text = "📊 *Statistics*\n\n"
          . "👥 Users: " . count($users) . "\n"
          . "📦 Orders: " . count($orders) . " (Pending: {$pendO}, Done: {$doneO})\n"
          . "💳 Payments: Pending {$pendP}\n"
          . "💰 Total Revenue: ₹" . number_format($rev, 2);

    sendMsg($chatId, $text, ['parse_mode' => 'Markdown']);
}

function sendAllUsers($chatId) {
    $data  = getData();
    $users = $data['users'] ?? [];
    if (!$users) { sendMsg($chatId, '👥 No users yet.'); return; }

    $text = "👥 *All Users*\n\n";
    foreach (array_slice($users, -20) as $u) {
        $text .= "• {$u['name']} (@{$u['username']}) — ₹" . ($u['balance'] ?? 0) . "\n";
    }
    sendMsg($chatId, $text, ['parse_mode' => 'Markdown']);
}

function sendAllOrders($chatId) {
    $data   = getData();
    $orders = array_slice(array_reverse($data['orders'] ?? []), 0, 10);
    if (!$orders) { sendMsg($chatId, '📦 No orders yet.'); return; }

    $text = "📦 *Recent Orders*\n\n";
    foreach ($orders as $o) {
        $icon = ['pending' => '⏳', 'done' => '✅', 'failed' => '❌', 'processing' => '🔄'][$o['status']] ?? '❓';
        $text .= "{$icon} {$o['id']} — @{$o['username']} — ₹{$o['cost']} — {$o['status']}\n";
    }
    sendMsg($chatId, $text, ['parse_mode' => 'Markdown']);
}

// ════════════════════════════════════════════
// STATE HANDLER
// ════════════════════════════════════════════
function handleState($chatId, $text, $state, $isAdmin) {
    clearState($chatId);

    switch ($state['action']) {
        case 'broadcast':
            if (!$isAdmin) return;
            $data = getData();
            $sent = 0;
            foreach ($data['users'] ?? [] as $u) {
                if (!empty($u['chat_id'])) {
                    tgReq('sendMessage', ['chat_id' => $u['chat_id'], 'text' => "📢 " . $text]);
                    $sent++;
                    usleep(50000);
                }
            }
            sendMsg($chatId, "📢 Broadcast sent to {$sent} users!");
            break;

        case 'add_balance':
            if (!$isAdmin) return;
            $amt  = floatval($text);
            $uname = $state['username'] ?? '';
            if ($amt <= 0) { sendMsg($chatId, '❌ Invalid amount'); return; }
            $pdata = getData();
            foreach ($pdata['users'] as &$u) {
                if ($u['username'] === $uname) {
                    $u['balance'] = round(($u['balance'] ?? 0) + $amt, 2);
                    break;
                }
            }
            saveData($pdata);
            sendMsg($chatId, "✅ ₹{$amt} added to @{$uname}!");
            break;
    }
}

// ════════════════════════════════════════════
// PAYMENT ACTIONS
// ════════════════════════════════════════════
function approvePayment($payId, $amount, $username, $chatId, $msgId) {
    $data = getData();
    foreach ($data['payreqs'] as &$r) {
        if ($r['id'] === $payId) {
            $r['status'] = 'approved';
            break;
        }
    }
    // Add balance to user
    foreach ($data['users'] as &$u) {
        if ($u['username'] === $username) {
            $u['balance'] = round(($u['balance'] ?? 0) + $amount, 2);
            break;
        }
    }
    saveData($data);

    tgReq('editMessageText', [
        'chat_id'    => $chatId,
        'message_id' => $msgId,
        'text'       => "✅ *Payment Approved!*\n@{$username} ko ₹{$amount} credit ho gaya!",
        'parse_mode' => 'Markdown',
    ]);
}

function rejectPayment($payId, $chatId, $msgId) {
    $data = getData();
    foreach ($data['payreqs'] as &$r) {
        if ($r['id'] === $payId) { $r['status'] = 'rejected'; break; }
    }
    saveData($data);

    tgReq('editMessageText', [
        'chat_id'    => $chatId,
        'message_id' => $msgId,
        'text'       => "❌ Payment *rejected*.",
        'parse_mode' => 'Markdown',
    ]);
}

function updateOrderStatus($ordId, $status) {
    $data = getData();
    foreach ($data['orders'] as &$o) {
        if ($o['id'] === $ordId) { $o['status'] = $status; break; }
    }
    saveData($data);
}

// ════════════════════════════════════════════
// USER HELPERS
// ════════════════════════════════════════════
function registerUser($chatId, $name, $username) {
    $data = getData();
    foreach ($data['users'] as $u) {
        if ($u['chat_id'] == $chatId) return; // already exists
    }
    $data['users'][] = [
        'chat_id'  => $chatId,
        'name'     => $name,
        'username' => $username,
        'balance'  => 0,
        'joined'   => date('d/m/Y'),
    ];
    saveData($data);
}

function findUser($chatId, $data) {
    foreach ($data['users'] ?? [] as $u) {
        if ($u['chat_id'] == $chatId) return $u;
    }
    return [];
}

function handleReferral($chatId, $refId) {
    if ($refId == $chatId) return; // self referral nahi
    $data = getData();
    foreach ($data['users'] as &$u) {
        if ($u['chat_id'] == $refId) {
            $u['balance']     = round(($u['balance'] ?? 0) + 10, 2);
            $u['referCount']  = ($u['referCount'] ?? 0) + 1;
            $u['referEarned'] = ($u['referEarned'] ?? 0) + 10;
            // Notify referrer
            sendMsg($refId, "🎉 *New Referral!* ₹10 credited to your balance!", ['parse_mode' => 'Markdown']);
            break;
        }
    }
    saveData($data);
}

// ════════════════════════════════════════════
// DATA HELPERS
// ════════════════════════════════════════════
function getData() {
    global $DATA_FILE;
    if (!file_exists($DATA_FILE)) return ['users' => [], 'orders' => [], 'payreqs' => []];
    return json_decode(file_get_contents($DATA_FILE), true) ?? ['users' => [], 'orders' => [], 'payreqs' => []];
}

function saveData($data) {
    global $DATA_FILE;
    file_put_contents($DATA_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// ════════════════════════════════════════════
// STATE HELPERS (per user)
// ════════════════════════════════════════════
function getState($chatId) {
    global $STATE_FILE;
    if (!file_exists($STATE_FILE)) return null;
    $all = json_decode(file_get_contents($STATE_FILE), true) ?? [];
    $s   = $all[(string)$chatId] ?? null;
    if ($s && (time() - ($s['ts'] ?? 0)) > 120) {
        unset($all[(string)$chatId]);
        file_put_contents($STATE_FILE, json_encode($all));
        return null;
    }
    return $s;
}

function setState($chatId, $data) {
    global $STATE_FILE;
    $all = file_exists($STATE_FILE) ? (json_decode(file_get_contents($STATE_FILE), true) ?? []) : [];
    $all[(string)$chatId] = array_merge($data, ['ts' => time()]);
    file_put_contents($STATE_FILE, json_encode($all));
}

function clearState($chatId) {
    global $STATE_FILE;
    if (!file_exists($STATE_FILE)) return;
    $all = json_decode(file_get_contents($STATE_FILE), true) ?? [];
    unset($all[(string)$chatId]);
    file_put_contents($STATE_FILE, json_encode($all));
}

// ════════════════════════════════════════════
// TELEGRAM API
// ════════════════════════════════════════════
function sendMsg($chatId, $text, $extra = []) {
    return tgReq('sendMessage', array_merge(['chat_id' => $chatId, 'text' => $text], $extra));
}

function tgReq($method, $params) {
    $url = 'https://api.telegram.org/bot' . BOT_TOKEN . '/' . $method;
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true);
}
