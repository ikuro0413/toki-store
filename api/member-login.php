<?php
/**
 * 会員登録・ログインの入口。メールアドレスを受け取り、ログイン用リンクをメールで送る。
 * 登録済みかどうかで応答を変えない（他人のアドレスが登録済みか探られないようにする）。
 */

declare(strict_types=1);
require __DIR__ . '/_member.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('POST only');
}
if (!toki_same_origin()) toki_fail(403, 'bad_origin', (string)($_SERVER['HTTP_ORIGIN'] ?? ''));

$email = toki_email_normalize((string)($_POST['email'] ?? ''));
if ($email === '') toki_redirect('/account/login.html?error=email');
if (empty($_POST['agree'])) toki_redirect('/account/login.html?error=agree');

// 同一IPから10分に5回・同じアドレスへは10分に3回まで（メール爆撃よけ）
if (!toki_rate_limit('login-ip:' . toki_client_ip(), 5, 600)
    || !toki_rate_limit('login-mail:' . hash('sha256', $email), 3, 600)) {
    toki_redirect('/account/login.html?error=busy');
}

$base  = rtrim(toki_config()['site_base_url'], '/');
$token = toki_login_token_issue($email);
$next  = preg_match('#^/products/[a-z0-9-]+\.html$#', (string)($_POST['next'] ?? '')) ? (string)$_POST['next'] : '';
$url   = $base . '/api/member-verify.php?t=' . rawurlencode($token) . ($next !== '' ? '&next=' . rawurlencode($next) : '');

if (toki_send_login_mail($email, $url)) {
    toki_log('ok', 'login link sent ' . substr(hash('sha256', $email), 0, 12));
} else {
    toki_log('ERROR', 'login mail failed ' . substr(hash('sha256', $email), 0, 12));
    toki_redirect('/account/login.html?error=mail');
}
toki_redirect('/account/sent.html');
