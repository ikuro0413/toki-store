<?php
/** Relay to the existing request form; acknowledge only a confirmed GAS response. */
declare(strict_types=1);
require_once __DIR__ . '/_lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function request_reply(int $status, bool $ok): void {
    http_response_code($status);
    echo json_encode(['ok' => $ok]);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    request_reply(405, false);
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 20000) request_reply(413, false);
$fields = [];
foreach (['item' => 2000, 'problem' => 8000, 'budget' => 100, 'email' => 254, 'website' => 500, 'page' => 2000, 'ua' => 1000] as $key => $limit) {
    $v = $_POST[$key] ?? '';
    if (!is_string($v) || strlen($v) > $limit) request_reply(422, false);
    $fields[$key] = trim($v);
}
if ($fields['website'] !== '') request_reply(200, true);
if ($fields['item'] === '' || ($fields['email'] !== '' && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL))) request_reply(422, false);
if (!toki_rate_limit('request:' . toki_client_ip(), 5, 600)) request_reply(429, false);

// 送信先と合言葉は公開ディレクトリの外の設定から読む。
// 設定がまだ入っていない間は、従来の埋め込みURLへ倒してフォームを止めない。
// toki-env.php に gas_request_endpoint を入れたら、下の既定値は消してよい。
$conf = toki_config();
$endpoint = (string)($conf['gas_request_endpoint'] ?? '');
if ($endpoint === '') {
    $endpoint = 'https://script.google.com/macros/s/AKfycbyzrdHXZylQcxo4vWrZPAedu6mCmvX3Pk9qJmww5MdX1CrZcG9HmjuLlJz9lx8if36w/exec';
}

// GAS側にも合言葉を付ける。PHPの連打よけだけだと、GASのURLを直接叩かれたら素通りする
$payload = $fields;
if (!empty($conf['gas_request_token'])) $payload['token'] = (string)$conf['gas_request_token'];

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($payload),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 3,
    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 35,
]);
$body = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$result = is_string($body) ? json_decode($body, true) : null;
if ($status !== 200 || !is_array($result) || ($result['ok'] ?? false) !== true) request_reply(502, false);
request_reply(200, true);
