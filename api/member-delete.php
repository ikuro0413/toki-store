<?php
/** 退会（POSTのみ・確認のチェックが必要）。会員情報を消してログアウトする */

declare(strict_types=1);
require __DIR__ . '/_member.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('POST only');
}
if (!toki_same_origin()) toki_fail(403, 'bad_origin', (string)($_SERVER['HTTP_ORIGIN'] ?? ''));

$m = toki_member_current();
if (!$m) toki_redirect('/account/login.html');
if (empty($_POST['confirm'])) toki_redirect('/account/?error=confirm');

toki_member_delete((string)$m['email']);
toki_member_logout();
toki_log('ok', 'member deleted ' . ($m['id'] ?? ''));
toki_redirect('/account/login.html?deleted=1');
