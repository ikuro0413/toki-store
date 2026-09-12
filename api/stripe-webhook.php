<?php
/**
 * Stripe Webhook の受け口。支払いが本当に完了したかを決めるのはここだけ。
 *
 * 決済完了ページへ遷移したことを支払い済みの根拠にしない。
 * 署名検証を通り、checkout.session.completed かつ payment_status=paid のものだけを確定扱いにする。
 *
 * Stripeダッシュボードで登録するURL: https://store.detoxnews.jp/api/stripe-webhook.php
 * 送るイベント: checkout.session.completed / charge.refunded
 */

declare(strict_types=1);
require __DIR__ . '/_lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('POST only');
}

$payload   = (string)file_get_contents('php://input');
$sigHeader = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
$conf      = toki_config();

if (!toki_verify_signature($payload, $sigHeader, (string)$conf['stripe_webhook_secret'])) {
    toki_log('ERROR', 'webhook署名検証に失敗');
    http_response_code(400);
    exit('invalid signature');
}

$event = json_decode($payload, true);
if (!is_array($event) || empty($event['id'])) {
    http_response_code(400);
    exit('bad payload');
}

// Stripeは同じイベントを再送する。処理済みなら200を返して何もしない
if (toki_event_seen((string)$event['id'])) {
    toki_log('skip', '重複イベント ' . $event['id']);
    http_response_code(200);
    exit('duplicate');
}

$type = (string)($event['type'] ?? '');

if ($type === 'checkout.session.completed') {
    $sessionId = (string)($event['data']['object']['id'] ?? '');
    if ($sessionId === '') {
        http_response_code(400);
        exit('no session id');
    }

    // イベントの中身をそのまま信じず、Stripeから取り直す
    $s = toki_stripe('GET', 'checkout/sessions/' . $sessionId, [
        'expand' => ['line_items', 'payment_intent'],
    ]);

    if (($s['payment_status'] ?? '') !== 'paid') {
        toki_log('skip', '未払い ' . $sessionId . ' status=' . ($s['payment_status'] ?? ''));
        http_response_code(200);
        exit('not paid');
    }

    $meta = $s['metadata'] ?? [];
    $cust = $s['customer_details'] ?? [];
    $ship = $s['shipping_details'] ?? ($s['collected_information']['shipping_details'] ?? []);
    $addr = $ship['address'] ?? ($cust['address'] ?? []);
    $item = $s['line_items']['data'][0] ?? [];

    $pi   = $s['payment_intent'] ?? null;
    $piId = is_array($pi) ? (string)($pi['id'] ?? '') : (string)$pi;

    $order = [
        'order_id'          => (string)($meta['order_id'] ?? ''),
        'created_at'        => date('c'),
        'customer_name'     => (string)($ship['name'] ?? ($cust['name'] ?? '')),
        'email'             => (string)($cust['email'] ?? ''),
        'phone'             => (string)($cust['phone'] ?? ''),
        'shipping_postal'   => (string)($addr['postal_code'] ?? ''),
        'shipping_address'  => trim(implode(' ', array_filter([
            (string)($addr['state'] ?? ''),
            (string)($addr['city'] ?? ''),
            (string)($addr['line1'] ?? ''),
            (string)($addr['line2'] ?? ''),
        ]))),
        'sku'                   => (string)($meta['sku'] ?? ''),
        'item_name'             => (string)($item['description'] ?? ''),
        'quantity'              => (int)($item['quantity'] ?? 1),
        'price_jpy'             => (int)($s['amount_total'] ?? 0),
        'payment_status'        => 'paid',
        'stripe_session_id'     => $sessionId,
        'stripe_payment_intent' => $piId,
        'fulfillment_status'    => '未発送',
        'supplier'              => (string)($meta['supplier'] ?? ''),
        'source_product_id'     => (string)($meta['source_product_id'] ?? ''),
        'ckb_order_id'          => '',
        'tracking_number'       => '',
    ];

    // 控えを先に残してからStripeへ200を返し、遅いGAS送信は応答の後ろへ回す
    toki_save_order_local($order);
    toki_finish_response('ok');
    toki_push_order_to_gas($order);
    exit;
}

if ($type === 'charge.refunded') {
    $ch      = $event['data']['object'] ?? [];
    $piId    = (string)($ch['payment_intent'] ?? '');
    $orderId = (string)($ch['metadata']['order_id'] ?? '');

    // ChargeのmetadataにはPaymentIntentのmetadataが載らないことがある。
    // 空ならPaymentIntentを取りに行って注文番号を解決する。
    if ($orderId === '' && $piId !== '') {
        $pi = toki_stripe('GET', 'payment_intents/' . $piId);
        $orderId = (string)($pi['metadata']['order_id'] ?? '');
    }

    if ($orderId === '') {
        toki_log('ERROR', '返金の注文番号を解決できない charge=' . (string)($ch['id'] ?? '') . ' pi=' . $piId);
        http_response_code(200);
        exit('no order_id');
    }

    $refund = [
        'order_id'              => $orderId,
        'created_at'            => date('c'),
        'payment_status'        => 'refunded',
        'stripe_payment_intent' => $piId,
        'price_jpy'             => (int)($ch['amount_refunded'] ?? 0),
    ];
    toki_save_order_local($refund);
    toki_finish_response('ok');
    toki_push_order_to_gas($refund);
    exit;
}

// 扱わない種類のイベントも200で返す（Stripeに再送させない）
toki_log('skip', '未対応イベント ' . $type);
http_response_code(200);
exit('ignored');


/**
 * Stripe-Signature ヘッダの検証。
 * t=タイムスタンプ と v1=署名 を取り出し、"t.payload" のHMAC-SHA256と突き合わせる。
 */
function toki_verify_signature(string $payload, string $header, string $secret): bool
{
    if ($header === '' || $secret === '') return false;

    $timestamp  = null;
    $signatures = [];
    foreach (explode(',', $header) as $part) {
        $kv = explode('=', trim($part), 2);
        if (count($kv) !== 2) continue;
        if ($kv[0] === 't')  $timestamp    = $kv[1];
        if ($kv[0] === 'v1') $signatures[] = $kv[1];
    }
    if ($timestamp === null || !$signatures) return false;

    // 古い署名の使い回しを防ぐ（許容5分）
    if (abs(time() - (int)$timestamp) > 300) return false;

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) return true;
    }
    return false;
}
