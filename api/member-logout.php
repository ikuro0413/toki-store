<?php
/** ログアウト（POSTのみ） */

declare(strict_types=1);
require __DIR__ . '/_member.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('POST only');
}
if (!toki_same_origin()) toki_fail(403, 'bad_origin', (string)($_SERVER['HTTP_ORIGIN'] ?? ''));

toki_member_logout();
toki_redirect('/account/login.html?bye=1');
