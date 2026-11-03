<?php
/*
 * Aurelia Studio: приём заявок с сайта.
 * Заявка уходит в Telegram и на электронную почту.
 *
 * Меняйте только три строки ниже (между кавычками), остальное не трогайте.
 */
const TO_EMAIL = 'ВСТАВЬТЕ_ВАШУ_ПОЧТУ';        // куда присылать письма, например zayavki@mail.ru
const BOT_TOKEN = 'ВСТАВЬТЕ_ТОКЕН_БОТА';         // длинный токен от @BotFather
const CHAT_ID = 'ВСТАВЬТЕ_CHAT_ID';              // ваш номер из бота @userinfobot

/* ---------- дальше менять ничего не нужно ---------- */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out($code, $data) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function is_set_up($v) {
    return $v !== '' && strpos($v, 'ВСТАВЬТЕ') === false;
}

$tg_ready = is_set_up(BOT_TOKEN) && is_set_up(CHAT_ID);
$mail_ready = is_set_up(TO_EMAIL) && filter_var(TO_EMAIL, FILTER_VALIDATE_EMAIL);

/* Проверка: сайт спрашивает, настроен ли приём заявок */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    out(200, ['ok' => true, 'configured' => ($tg_ready || $mail_ready)]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(405, ['ok' => false]);
}

$raw = file_get_contents('php://input', false, null, 0, 8192);
$in = json_decode($raw, true);
if (!is_array($in)) {
    out(400, ['ok' => false, 'error' => 'bad_request']);
}

/* Скрытое поле-ловушка для роботов: человек его не видит и не заполняет */
if (!empty($in['website'])) {
    out(200, ['ok' => true]);
}

function clean($v, $max) {
    $v = is_string($v) ? $v : '';
    $v = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v);
    $v = trim(strip_tags($v));
    return mb_substr($v, 0, $max);
}

$type = (isset($in['type']) && $in['type'] === 'booking') ? 'booking' : 'callback';
$name = clean($in['name'] ?? '', 60);
$phone = clean($in['phone'] ?? '', 30);
$digits = preg_replace('/\D+/', '', $phone);

if (mb_strlen($name) < 2 || !preg_match('/^375\d{9}$/', $digits)) {
    out(422, ['ok' => false, 'error' => 'invalid']);
}
$phone_out = '+' . $digits;

/* Не чаще одной заявки в 30 секунд с одного адреса */
$ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
$lock = sys_get_temp_dir() . '/aurelia_lead_' . md5($ip);
if (is_file($lock) && (time() - filemtime($lock)) < 30) {
    out(429, ['ok' => false, 'error' => 'too_often']);
}

date_default_timezone_set('Europe/Minsk');
$now = date('d.m.Y H:i') . ' (Минск)';

$lines = [];
if ($type === 'booking') {
    $title = 'Новая онлайн-запись с сайта';
    $lines[] = 'Мастер: ' . clean($in['master'] ?? '', 80);
    $svc = clean($in['service'] ?? '', 120);
    $price = clean($in['price'] ?? '', 40);
    $lines[] = 'Процедура: ' . $svc . ($price !== '' ? ' (' . $price . ')' : '');
    $lines[] = 'День: ' . clean($in['date'] ?? '', 40);
    $lines[] = 'Время: ' . clean($in['slot'] ?? '', 20);
} else {
    $title = 'Новая заявка на звонок с сайта';
    $topic = clean($in['topic'] ?? '', 30);
    if ($topic === 'certificate') $lines[] = 'Тема: подарочный сертификат';
    if ($topic === 'discount') $lines[] = 'Тема: скидка 10% на первое посещение';
    $lines[] = 'Удобное время для звонка: ' . clean($in['time'] ?? '', 60);
    $lines[] = 'Согласие на рассылку: ' . (!empty($in['ads']) ? 'да' : 'нет');
}
array_unshift($lines, 'Имя: ' . $name, 'Телефон: ' . $phone_out);
$lines[] = 'Отправлено: ' . $now;

$plain = $title . "\n\n" . implode("\n", $lines);

/* ---------- Telegram ---------- */
$tg_ok = false;
if ($tg_ready) {
    $api = getenv('AURELIA_TEST_TG_API') ?: 'https://api.telegram.org';
    $url = $api . '/bot' . BOT_TOKEN . '/sendMessage';
    $body = json_encode([
        'chat_id' => CHAT_ID,
        'text' => '<b>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</b>' . "\n\n" .
                  htmlspecialchars(implode("\n", $lines), ENT_QUOTES, 'UTF-8'),
        'parse_mode' => 'HTML',
    ], JSON_UNESCAPED_UNICODE);
    $resp = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $body,
            'timeout' => 10,
        ]]);
        $resp = @file_get_contents($url, false, $ctx);
    }
    $j = $resp ? json_decode($resp, true) : null;
    $tg_ok = is_array($j) && !empty($j['ok']);
    if (!$tg_ok) error_log('Aurelia send.php: Telegram не принял заявку');
}

/* ---------- Почта ---------- */
$mail_ok = false;
if ($mail_ready) {
    $host = preg_replace('/^www\./i', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = preg_replace('/:\d+$/', '', $host);
    $headers = "From: Aurelia Studio <no-reply@" . $host . ">\r\n" .
               "Content-Type: text/plain; charset=UTF-8\r\n" .
               "MIME-Version: 1.0";
    $subject = '=?UTF-8?B?' . base64_encode($title) . '?=';
    $mail_ok = @mail(TO_EMAIL, $subject, $plain, $headers);
    if (!$mail_ok) error_log('Aurelia send.php: письмо не отправилось');
}

if ($tg_ok || $mail_ok) {
    @touch($lock);
    out(200, ['ok' => true, 'telegram' => $tg_ok, 'email' => $mail_ok]);
}
out(502, ['ok' => false, 'error' => 'delivery_failed']);
