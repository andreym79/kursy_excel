<?php
/**
 * Приём заявки с лендинга и рассылка ответственному.
 * POST application/json → {"ok":true} | {"ok":false,"error":"..."}
 * Каналы: Telegram, MAX, e-mail + резервная копия в файл.
 * Требования: PHP 7.4+, расширение curl.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function respond(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, ['ok' => false, 'error' => 'method']);

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) respond(500, ['ok' => false, 'error' => 'config_missing']);
$cfg = require $configPath;

// --- Проверка источника запроса ---
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && !in_array($origin, $cfg['allowed_origins'] ?? [], true)) {
    respond(403, ['ok' => false, 'error' => 'origin']);
}

// --- Чтение данных ---
$raw = file_get_contents('php://input', false, null, 0, 20000);
$in = json_decode($raw ?: '', true);
if (!is_array($in)) respond(400, ['ok' => false, 'error' => 'bad_json']);

// Ловушка для ботов: скрытое поле должно быть пустым
if (!empty($in['website'])) respond(200, ['ok' => true]);

$clean = static function ($v, int $max = 200): string {
    $v = is_string($v) ? trim($v) : '';
    $v = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $v) ?? '';
    return mb_substr($v, 0, $max);
};

$lead = [
    'name'    => $clean($in['name'] ?? '', 120),
    'phone'   => preg_replace('/[^\d+]/', '', $clean($in['phone'] ?? '', 20)) ?? '',
    'module'  => $clean($in['module'] ?? '', 120),
    'payer'   => $clean($in['payer'] ?? '', 40),
    'consent' => $clean($in['consent'] ?? '', 5),
    'variant' => $clean($in['page_variant'] ?? '', 10),
];
$utmKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'yclid', 'ym_client_id'];
$utm = [];
foreach ($utmKeys as $k) $utm[$k] = $clean($in[$k] ?? '', 200);
$pageUrl  = $clean($in['page_url'] ?? '', 500);
$referrer = $clean($in['referrer'] ?? '', 500);

// --- Валидация ---
if (mb_strlen($lead['name']) < 2) respond(422, ['ok' => false, 'error' => 'name']);
if (!preg_match('/^\+7\d{10}$/', $lead['phone'])) respond(422, ['ok' => false, 'error' => 'phone']);
if ($lead['consent'] !== 'yes') respond(422, ['ok' => false, 'error' => 'consent']);

// --- Текст сообщения ---
$payerText = $lead['payer'] === 'company' ? 'Компания (юрлицо)' : 'Сам (физлицо)';
$source = $utm['utm_source'] !== '' ? $utm['utm_source'] . ($utm['utm_medium'] ? ' / ' . $utm['utm_medium'] : '') : 'прямой заход / неизвестно';
$lines = [
    '🆕 Новая заявка с сайта',
    '',
    'Имя: ' . $lead['name'],
    'Телефон: ' . $lead['phone'],
    'Модуль: ' . ($lead['module'] ?: 'помочь выбрать'),
    'Оплата: ' . $payerText,
    '',
    'Источник: ' . $source,
];
if ($utm['utm_campaign'] !== '') $lines[] = 'Кампания: ' . $utm['utm_campaign'];
if ($utm['utm_content']  !== '') $lines[] = 'Объявление: ' . $utm['utm_content'];
if ($utm['utm_term']     !== '') $lines[] = 'Ключевая фраза: ' . $utm['utm_term'];
if ($utm['yclid']        !== '') $lines[] = 'yclid: ' . $utm['yclid'];
if ($utm['ym_client_id'] !== '') $lines[] = 'ClientID Метрики: ' . $utm['ym_client_id'];
$lines[] = 'Вариант страницы: ' . ($lead['variant'] ?: '—');
$lines[] = 'Время: ' . date('d.m.Y H:i');
$text = implode("\n", $lines);

// --- HTTP-помощник ---
function http_post_json(string $url, array $body, array $headers = [], string $caFile = ''): bool {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
    ];
    if ($caFile !== '') $opts[CURLOPT_CAINFO] = $caFile;
    curl_setopt_array($ch, $opts);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

$sent = [];

// Telegram
$tg = $cfg['telegram'] ?? [];
if (!empty($tg['enabled']) && $tg['bot_token'] && $tg['chat_id']) {
    $sent['telegram'] = http_post_json(
        'https://api.telegram.org/bot' . $tg['bot_token'] . '/sendMessage',
        ['chat_id' => $tg['chat_id'], 'text' => $text, 'disable_web_page_preview' => true]
    );
}

// MAX
$mx = $cfg['max'] ?? [];
if (!empty($mx['enabled']) && $mx['bot_token'] && ($mx['user_id'] || $mx['chat_id'])) {
    $query = $mx['user_id'] ? 'user_id=' . rawurlencode((string)$mx['user_id']) : 'chat_id=' . rawurlencode((string)$mx['chat_id']);
    $sent['max'] = http_post_json(
        rtrim($mx['api_base'], '/') . '/messages?' . $query,
        ['text' => $text],
        ['Authorization: ' . $mx['bot_token']],
        (string)($mx['ca_file'] ?? '')
    );
}

// E-mail
$em = $cfg['email'] ?? [];
if (!empty($em['enabled']) && $em['to']) {
    $subject = '=?UTF-8?B?' . base64_encode('Заявка с сайта: ' . ($lead['module'] ?: 'подбор модуля')) . '?=';
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nFrom: " . $em['from'];
    $sent['email'] = @mail($em['to'], $subject, $text, $headers);
}

// Резервная копия в файл
if (!empty($cfg['log_file'])) {
    $dir = dirname($cfg['log_file']);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    @file_put_contents(
        $cfg['log_file'],
        json_encode(['at' => date('c'), 'lead' => $lead, 'utm' => $utm, 'page' => $pageUrl, 'ref' => $referrer, 'sent' => $sent], JSON_UNESCAPED_UNICODE) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

if (in_array(true, $sent, true)) respond(200, ['ok' => true]);
respond(502, ['ok' => false, 'error' => 'delivery_failed']);
