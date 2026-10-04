<?php
// テスト用のStripeの偽物。受け取った内容を記録し、決まった応答を返す。
$dir = getenv('MOCK_DIR');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
file_put_contents("$dir/requests.log", json_encode(['m' => $method, 'p' => $path, 'post' => $_POST]) . "\n", FILE_APPEND);
header('Content-Type: application/json');

if ($method === 'POST' && $path === '/v1/coupons') {
    $n = (int)@file_get_contents("$dir/coupon-count") + 1;
    file_put_contents("$dir/coupon-count", (string)$n);
    if ($n > 1) { http_response_code(400); echo json_encode(['error' => ['code' => 'resource_already_exists']]); exit; }
    echo json_encode(['id' => $_POST['id']]); exit;
}
if ($method === 'POST' && $path === '/v1/checkout/sessions') {
    $n = (int)@file_get_contents("$dir/session-count") + 1;
    file_put_contents("$dir/session-count", (string)$n);
    file_put_contents("$dir/session-$n.json", json_encode($_POST));
    echo json_encode(['id' => "cs_test_$n", 'url' => "https://checkout.example/pay/$n"]); exit;
}
if ($method === 'GET' && preg_match('#^/v1/checkout/sessions/cs_test_(\d+)$#', $path, $m)) {
    $n = (int)$m[1];
    $p = json_decode((string)file_get_contents("$dir/session-$n.json"), true);
    $disc = isset($p['discounts']) ? 400 : 0;
    echo json_encode([
        'id' => "cs_test_$n", 'payment_status' => 'paid',
        'amount_total' => 3180 - $disc,
        'total_details' => ['amount_discount' => $disc],
        'metadata' => $p['metadata'] ?? [],
        'customer_details' => ['email' => $p['customer_email'] ?? 'guest@example.com', 'name' => 'テスト', 'phone' => '+819000000000',
            'address' => ['postal_code' => '1000001', 'state' => '東京都', 'city' => '千代田区', 'line1' => '1-1']],
        'line_items' => ['data' => [['description' => 'test', 'quantity' => 1]]],
        'payment_intent' => 'pi_test_' . $n,
    ]); exit;
}
http_response_code(404);
echo json_encode(['error' => ['message' => 'mock: unknown ' . $path]]);
