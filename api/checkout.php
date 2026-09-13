<?php
/**
 * 購入ボタンの受け口。Stripe Checkout Session を作って決済画面へ送る。
 *
 * 受け取るのは sku と qty だけ。金額は商品DBから引く（フロントの数字は信用しない）。
 * 成功時は 303 で Stripe のホストする決済画面へリダイレクトする。
 */

declare(strict_types=1);
require __DIR__ . '/_lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('POST only');
}

$conf = toki_config();
$base = rtrim($conf['site_base_url'], '/');

// 同一IPから10分に10回まで。連打でSessionと注文番号が増え続けるのを止める
if (!toki_rate_limit('checkout:' . toki_client_ip(), 10, 600)) {
    toki_fail(429, 'too_many_requests', toki_client_ip());
}

$slug = isset($_POST['sku']) ? preg_replace('/[^a-z0-9\-]/', '', strtolower((string)$_POST['sku'])) : '';
$qty  = isset($_POST['qty']) ? (int)$_POST['qty'] : 1;

if ($slug === '') toki_fail(400, 'sku_missing', 'skuが空');

$p = toki_product($slug);
if (!$p) toki_fail(404, 'product_not_found', 'sku=' . $slug);

$color = '';
$colorName = '';
if ($slug === 'off-box') {
    $colors = require __DIR__ . '/colors.php';
    $color = $_POST['color'] ?? 'white';
    if (!is_string($color) || !isset($colors[$color])) {
        toki_fail(400, 'invalid_color', '未対応のカラー');
    }
    $colorName = $colors[$color];
    $p['name'] .= '（' . $colorName . '）';
    $p['image_url'] = $base . '/assets/img/' . ($color === 'white' ? 'off-box-centered.png' : 'off-box-colors.jpg');
}

// active = 在庫を持って売る / preorder = 現物が届く前の予約を受ける
$status = (string)($p['status'] ?? 'draft');
if ($status !== 'active' && $status !== 'preorder') {
    toki_fail(409, 'not_on_sale', $slug . ' は status=' . $status);
}

$isPreorder = ($status === 'preorder');
$shipEta    = trim((string)($p['ship_eta'] ?? ''));

// 前払いの予約には引渡し時期の明示が要る。空のまま受け付けない
if ($isPreorder && $shipEta === '') {
    toki_fail(409, 'ship_eta_missing', $slug . ' に ship_eta が無い');
}

$max = (int)($p['max_qty_per_order'] ?? 1);
if ($qty < 1) $qty = 1;
if ($qty > $max) toki_fail(400, 'qty_too_large', 'max=' . $max);

if ($isPreorder) {
    // 予約は在庫ではなく受付枠で止める
    $cap = (int)($p['preorder_cap'] ?? 0);
    if ($cap > 0 && toki_preorder_count((string)$p['sku']) + $qty > $cap) {
        toki_fail(409, 'preorder_full', 'cap=' . $cap);
    }
} else {
    $stock = (int)($p['stock'] ?? 0);
    if ($stock < $qty) toki_fail(409, 'out_of_stock', 'stock=' . $stock);
}

$orderId = toki_next_order_id();

// 決済画面でも予約であることが分かるようにする（購入者が最後に見る画面なので省かない）
$itemDesc = (string)($p['short_description'] ?? '');
if ($isPreorder) {
    $itemDesc = trim('【予約商品】発送予定 ' . $shipEta . '　' . $itemDesc);
}

$params = [
    'mode'   => 'payment',
    'locale' => 'ja',

    'line_items' => [[
        'quantity'   => $qty,
        'price_data' => [
            'currency'     => 'jpy',
            'unit_amount'  => (int)$p['price_jpy'],   // 税込・送料込の一本価格
            'product_data' => [
                'name'        => (string)$p['name'],
                'description' => $itemDesc,
            ],
        ],
    ]],

    'shipping_address_collection' => ['allowed_countries' => ['JP']],
    'phone_number_collection'     => ['enabled' => 'true'],
    'customer_creation'           => 'always',

    'success_url' => $base . '/order/complete.html?order=' . rawurlencode($orderId),
    'cancel_url'  => $base . '/order/cancel.html',

    'metadata' => [
        'order_id'          => $orderId,
        'sku'               => (string)$p['sku'],
        'slug'              => $slug,
        'supplier'          => (string)($p['supplier'] ?? ''),
        'source_product_id' => (string)($p['source_product_id'] ?? ''),
        'preorder'          => $isPreorder ? '1' : '0',
        'ship_eta'          => $shipEta,
        'color'             => $color,
        'color_name'        => $colorName,
    ],
    'payment_intent_data' => [
        'metadata'    => ['order_id' => $orderId, 'sku' => (string)$p['sku'], 'color' => $color, 'color_name' => $colorName],
        'description' => ($isPreorder ? 'TOKI STORE 予約 ' : 'TOKI STORE ') . $orderId,
    ],
];

// 支払いボタンの上に予約の条件を出す。ページで同意させたことを決済画面でもう一度示す
if ($isPreorder) {
    $params['custom_text'] = ['submit' => ['message' =>
        '予約商品です。代金を先にお支払いいただき、' . $shipEta . 'に発送します。'
        . '発送日が決まり次第メールでお知らせします。'
        . '最終仕様が予定と異なった場合と、発送前のお申し出の場合は、全額返金します。'
    ]];
}

// 画像は絶対URLのときだけ渡す（Stripe側が取得しに来るため）
if (!empty($p['image_url']) && strpos((string)$p['image_url'], 'https://') === 0) {
    $params['line_items'][0]['price_data']['product_data']['images'] = [(string)$p['image_url']];
}

// 同じ注文番号で二重にSessionを作らない
$session = toki_stripe('POST', 'checkout/sessions', $params, [
    'Idempotency-Key' => 'checkout-' . $orderId,
]);

if (empty($session['url'])) toki_fail(502, 'session_no_url', '決済画面のURLが返らなかった');

toki_log('ok', 'session作成 ' . $orderId . ' ' . $slug . ' x' . $qty
    . ($isPreorder ? ' 予約' : '') . ' ' . ($session['id'] ?? ''));

header('Location: ' . $session['url'], true, 303);
exit;
