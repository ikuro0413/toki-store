<?php
/**
 * TOKI STORE — サーバー側の共通処理
 *
 * 秘密鍵・商品DB・注文採番・Stripe API呼び出し・ログをここに集める。
 * 設定ファイルと商品DBは公開ディレクトリの外に置く（下の TOKI_PRIVATE_DIR）。
 * FTP自動デプロイは public_html 配下だけを同期するので、その外は消されない。
 */

declare(strict_types=1);

/** 非公開ディレクトリ。/home/<id>/detoxnews.jp/private/ を想定 */
function toki_private_dir(): string {
    $env = getenv('TOKI_PRIVATE_DIR');
    if ($env && is_dir($env)) return rtrim($env, '/');
    // __DIR__ = .../public_html/store.detoxnews.jp/api
    $guess = dirname(__DIR__, 3) . '/private';
    return $guess;
}

/** 設定の読み込み。鍵が無ければここで止める */
function toki_config(): array {
    static $conf = null;
    if ($conf !== null) return $conf;

    $path = toki_private_dir() . '/toki-env.php';
    if (!is_readable($path)) {
        toki_fail(500, 'config_missing', '設定ファイルが読めない: ' . $path);
    }
    $conf = require $path;
    foreach (['stripe_secret_key', 'stripe_webhook_secret', 'site_base_url'] as $k) {
        if (empty($conf[$k])) toki_fail(500, 'config_incomplete', '設定が足りない: ' . $k);
    }
    return $conf;
}

/** 商品DB。価格の唯一の出所。フロントから金額を受け取らないための要 */
function toki_products(): array {
    $path = toki_private_dir() . '/products.json';
    if (!is_readable($path)) toki_fail(500, 'products_missing', '商品DBが読めない: ' . $path);
    $json = json_decode((string)file_get_contents($path), true);
    if (!is_array($json)) toki_fail(500, 'products_broken', '商品DBが壊れている');
    return $json;
}

function toki_product(string $slug): ?array {
    $all = toki_products();
    return $all[$slug] ?? null;
}

/**
 * 注文番号の採番。T-YYYYMMDD-0001 形式。
 * 排他ロックを取ってカウンタを進めるので、同時アクセスでも重複しない。
 */
function toki_next_order_id(): string {
    $dir = toki_private_dir();
    $path = $dir . '/order-counter.json';
    $fp = fopen($path, 'c+');
    if (!$fp) toki_fail(500, 'counter_open_failed', '採番ファイルを開けない');
    flock($fp, LOCK_EX);

    $raw = stream_get_contents($fp);
    $state = json_decode((string)$raw, true);
    $today = date('Ymd');
    if (!is_array($state) || ($state['date'] ?? '') !== $today) {
        $state = ['date' => $today, 'seq' => 0];
    }
    $state['seq']++;

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($state));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return sprintf('T-%s-%04d', $today, $state['seq']);
}

/** Stripe REST API を叩く。SDKもComposerも使わない */
function toki_stripe(string $method, string $path, array $params = [], array $headers = []): array {
    $conf = toki_config();
    $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');

    $ch = curl_init();
    $hdr = [
        'Authorization: Bearer ' . $conf['stripe_secret_key'],
        'Content-Type: application/x-www-form-urlencoded',
    ];
    foreach ($headers as $k => $v) $hdr[] = $k . ': ' . $v;

    if ($method === 'GET') {
        if ($params) $url .= '?' . http_build_query($params);
    } else {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $hdr,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) toki_fail(502, 'stripe_unreachable', 'Stripeに接続できない: ' . $err);
    $json = json_decode((string)$body, true);
    if (!is_array($json)) toki_fail(502, 'stripe_bad_response', 'Stripeの応答を解釈できない');
    if ($code >= 400) {
        $msg = $json['error']['message'] ?? 'unknown';
        toki_log('ERROR', 'stripe ' . $code . ' ' . $msg);
        toki_fail(502, 'stripe_error', $msg);
    }
    return $json;
}

/** 注文を記録する。GASウェブアプリ（スプレッドシート）へ送り、同時にローカルへも残す */
function toki_record_order(array $order): void {
    // 先にローカル保存。GASが落ちても注文を失わない
    $line = json_encode($order, JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents(toki_private_dir() . '/orders.jsonl', $line, FILE_APPEND | LOCK_EX);

    $conf = toki_config();
    if (empty($conf['gas_order_endpoint'])) {
        toki_log('skip', 'gas_endpoint未設定 ' . ($order['order_id'] ?? ''));
        return;
    }

    $payload = $order;
    $payload['token'] = $conf['gas_shared_token'] ?? '';

    $ch = curl_init($conf['gas_order_endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,   // GASは302を返す
        CURLOPT_TIMEOUT        => 20,
    ]);
    $res  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code >= 200 && $code < 300) {
        toki_log('ok', 'order→GAS ' . ($order['order_id'] ?? ''));
    } else {
        toki_log('ERROR', 'GAS送信失敗 ' . $code . ' ' . substr((string)$res, 0, 200));
    }
}

/** Webhookの二重処理を防ぐ。処理済みの event.id を記録して弾く */
function toki_event_seen(string $eventId): bool {
    $path = toki_private_dir() . '/webhook-seen.log';
    $seen = is_readable($path) ? (string)file_get_contents($path) : '';
    if (strpos($seen, $eventId) !== false) return true;
    @file_put_contents($path, $eventId . "\n", FILE_APPEND | LOCK_EX);
    return false;
}

function toki_log(string $kind, string $message): void {
    $line = sprintf("%s\t%s\t%s\n", date('c'), $kind, $message);
    @file_put_contents(toki_private_dir() . '/store.log', $line, FILE_APPEND | LOCK_EX);
}

/** 失敗時の共通出口。購入者には詳細を見せない */
function toki_fail(int $status, string $code, string $detail): void {
    toki_log('ERROR', $code . ' / ' . $detail);
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $code], JSON_UNESCAPED_UNICODE);
    exit;
}
