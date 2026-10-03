<?php
/**
 * ログイン中の会員の状態をページへ返す（ヘッダーの表示切替・マイページ・商品ページの割引表示に使う）。
 */

declare(strict_types=1);
require __DIR__ . '/_member.php';

$m = toki_member_current();
if (!$m) toki_json(['loggedIn' => false, 'couponAmount' => TOKI_FIRST_COUPON_AMOUNT]);

toki_json([
    'loggedIn'        => true,
    'email'           => (string)$m['email'],
    'memberSince'     => substr((string)($m['created_at'] ?? ''), 0, 10),
    'firstCoupon'     => toki_first_coupon_available($m),
    'firstCouponUsed' => !empty($m['first_coupon_used_at']),
    'couponAmount'    => TOKI_FIRST_COUPON_AMOUNT,
]);
