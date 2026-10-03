<?php
/**
 * ログイン用リンクの着地点。トークンを使い切って会員を確定し、ログイン状態にする。
 */

declare(strict_types=1);
require __DIR__ . '/_member.php';

$email = toki_login_token_consume((string)($_GET['t'] ?? ''));
if ($email === '') toki_redirect('/account/login.html?error=expired');

$m = toki_member_confirm($email);
toki_member_login($email);
toki_log('ok', 'login ' . ($m['id'] ?? ''));

$next = (string)($_GET['next'] ?? '');
toki_redirect(preg_match('#^/products/[a-z0-9-]+\.html$#', $next) ? $next . '?welcome=1' : '/account/?welcome=1');
