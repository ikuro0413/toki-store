<?php
/**
 * TOKI STORE — 会員（メールアドレスだけで登録・ログインする）
 *
 * パスワードは持たない。ログインはメールで届く一回きりのリンクで行う。
 * リンクを開けたこと自体が、そのアドレスの持ち主である確認になる。
 *
 * private/members.json      会員。キーはメールアドレス（小文字）
 * private/login-tokens.json ログイン用リンクのトークン（ハッシュだけを持つ・30分で失効・一回きり）
 * private/sessions/         ログイン状態（PHPセッション）
 *
 * 初回割引: 会員で、そのアドレスの支払い済み注文がなければ、決済画面に400円引きを自動で入れる。
 */

declare(strict_types=1);
require_once __DIR__ . '/_lib.php';

const TOKI_FIRST_COUPON_ID     = 'TOKI_FIRST400';   // Stripeのクーポンは金額を変えられないので、金額を変えたらIDも変える
const TOKI_FIRST_COUPON_AMOUNT = 400;
const TOKI_LOGIN_TOKEN_TTL     = 1800;      // ログイン用リンクの有効期限（秒）
const TOKI_SESSION_TTL         = 2592000;   // ログイン状態の保持（30日）
const TOKI_COUPON_HOLD         = 1860;      // 割引つき決済画面の有効期限＋余裕（秒）

/* ── ログイン状態 ─────────────────────────────────────── */

function toki_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $dir = toki_private_dir() . '/sessions';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    session_save_path($dir);
    ini_set('session.gc_maxlifetime', (string)TOKI_SESSION_TTL);
    ini_set('session.use_strict_mode', '1');
    session_name('TOKISID');
    session_set_cookie_params([
        'lifetime' => TOKI_SESSION_TTL,
        'path'     => '/',
        'secure'   => (getenv('TOKI_INSECURE_COOKIE') !== '1'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** ログイン中の会員。いなければ null。Cookieが無いときはセッションを作らない */
function toki_member_current(): ?array {
    if (empty($_COOKIE['TOKISID'])) return null;
    toki_session_start();
    $email = (string)($_SESSION['member_email'] ?? '');
    if ($email === '') return null;
    $m = toki_member_get($email);
    if (!$m || !empty($m['deleted'])) return null;
    return $m;
}

function toki_member_login(string $email): void {
    toki_session_start();
    session_regenerate_id(true);
    $_SESSION['member_email'] = $email;
    $_SESSION['login_at'] = time();
}

function toki_member_logout(): void {
    if (empty($_COOKIE['TOKISID'])) return;
    toki_session_start();
    $_SESSION = [];
    session_destroy();
    setcookie('TOKISID', '', [
        'expires' => time() - 3600, 'path' => '/',
        'secure' => (getenv('TOKI_INSECURE_COOKIE') !== '1'), 'httponly' => true, 'samesite' => 'Lax',
    ]);
}

/* ── 会員の保存（JSON1ファイル・排他ロック） ─────────────── */

function toki_email_normalize(string $raw): string {
    $e = strtolower(trim($raw));
    if (strlen($e) > 254) return '';
    return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
}

/** JSONファイルをロックして読み、$fn の戻り値で書き戻す。$fn が null を返したら書かない */
function toki_json_update(string $file, callable $fn) {
    $fp = fopen(toki_private_dir() . '/' . $file, 'c+');
    if (!$fp) toki_fail(500, 'store_open_failed', $file . ' を開けない');
    flock($fp, LOCK_EX);
    $data = json_decode((string)stream_get_contents($fp), true);
    if (!is_array($data)) $data = [];
    $result = null;
    $new = $fn($data, $result);
    if (is_array($new)) {
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($new, JSON_UNESCAPED_UNICODE));
        fflush($fp);
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    @chmod(toki_private_dir() . '/' . $file, 0600);
    return $result;
}

function toki_member_get(string $email): ?array {
    $path = toki_private_dir() . '/members.json';
    if (!is_readable($path)) return null;
    $all = json_decode((string)file_get_contents($path), true);
    if (!is_array($all) || !isset($all[$email])) return null;
    $m = $all[$email];
    $m['email'] = $email;
    return $m;
}

/** 本人確認が済んだ会員を作る（既にいれば最終ログインだけ更新する） */
function toki_member_confirm(string $email): array {
    return toki_json_update('members.json', function (array $all, &$result) use ($email) {
        $now = date('c');
        if (!isset($all[$email]) || !empty($all[$email]['deleted'])) {
            $all[$email] = [
                'id'         => 'M-' . bin2hex(random_bytes(6)),
                'created_at' => $now,
            ];
        }
        $all[$email]['last_login_at'] = $now;
        $result = $all[$email] + ['email' => $email];
        return $all;
    });
}

/** 退会。会員情報を消し、メールアドレスは残さない（注文の控えは法定の保存のため別に残る） */
function toki_member_delete(string $email): void {
    toki_json_update('members.json', function (array $all) use ($email) {
        unset($all[$email]);
        return $all;
    });
}

/* ── ログイン用リンク ─────────────────────────────────── */

function toki_login_token_issue(string $email): string {
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $hash  = hash('sha256', $token);
    toki_json_update('login-tokens.json', function (array $all) use ($email, $hash) {
        $now = time();
        foreach ($all as $h => $t) {
            if (!is_array($t) || ($t['exp'] ?? 0) < $now) unset($all[$h]);
        }
        $all[$hash] = ['email' => $email, 'exp' => $now + TOKI_LOGIN_TOKEN_TTL];
        return $all;
    });
    return $token;
}

/** トークンを使い切る。正しければメールアドレス、だめなら空文字 */
function toki_login_token_consume(string $token): string {
    if (!preg_match('/^[A-Za-z0-9_-]{30,64}$/', $token)) return '';
    $hash = hash('sha256', $token);
    return (string)toki_json_update('login-tokens.json', function (array $all, &$result) use ($hash) {
        $result = '';
        $t = $all[$hash] ?? null;
        if (is_array($t) && ($t['exp'] ?? 0) >= time()) $result = (string)$t['email'];
        unset($all[$hash]);
        return $all;
    });
}

/** ログイン用リンクのメールを送る。テストでは TOKI_MAIL_FILE に書き出す */
function toki_send_login_mail(string $email, string $url): bool {
    $subject = '【TOKI STORE】ログイン用のリンク';
    $body = "TOKI STORE をご利用いただきありがとうございます。\n\n"
        . "下のリンクを開くと、会員登録（またはログイン）が完了します。\n"
        . "リンクの有効期限は30分で、一度だけ使えます。\n\n"
        . $url . "\n\n"
        . "このメールに心当たりがない場合は、そのまま削除してください。\n"
        . "リンクを開かなければ、登録やログインは行われません。\n\n"
        . "――――――――――\n"
        . "TOKI STORE（https://store.detoxnews.jp/）\n"
        . "お問い合わせ: info@detoxnews.jp\n";

    $file = getenv('TOKI_MAIL_FILE');
    if ($file) {
        return file_put_contents($file, json_encode(['to' => $email, 'subject' => $subject, 'body' => $body], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND) !== false;
    }
    mb_language('Japanese');
    mb_internal_encoding('UTF-8');
    $from = 'info@detoxnews.jp';
    $headers = 'From: ' . mb_encode_mimeheader('TOKI STORE') . ' <' . $from . '>' . "\r\n"
        . 'Reply-To: ' . $from;
    return mb_send_mail($email, $subject, $body, $headers, '-f' . $from);
}

/* ── 初回割引 ─────────────────────────────────────────── */

/** そのアドレスで支払い済み（返金されていない）の注文があるか */
function toki_email_has_paid_order(string $email): bool {
    $path = toki_private_dir() . '/orders.jsonl';
    if (!is_readable($path)) return false;
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $paid = [];
    $refunded = [];
    foreach ($lines as $line) {
        $o = json_decode($line, true);
        if (!is_array($o)) continue;
        $id = (string)($o['order_id'] ?? '');
        if (($o['payment_status'] ?? '') === 'refunded') { $refunded[$id] = true; continue; }
        if (($o['payment_status'] ?? '') === 'paid' && strtolower((string)($o['email'] ?? '')) === $email) $paid[$id] = true;
    }
    foreach ($paid as $id => $_) {
        if (!isset($refunded[$id])) return true;
    }
    return false;
}

/**
 * 初回割引を使えるか。
 * 使用済みの印がある／支払い済み注文がある／割引つきの決済画面を開いている途中、のどれかなら使えない。
 * 返金した注文は「購入済み」に数えないが、割引を一度使った印は返金しても消さない。
 */
function toki_first_coupon_available(array $member): bool {
    if (!empty($member['first_coupon_used_at'])) return false;
    if (($member['coupon_hold_until'] ?? 0) > time()) return false;
    return !toki_email_has_paid_order((string)$member['email']);
}

/** 割引つきの決済画面を開いた。期限切れまでは二重に割引を出さない */
function toki_first_coupon_hold(string $email, string $sessionId): void {
    toki_json_update('members.json', function (array $all) use ($email, $sessionId) {
        if (!isset($all[$email])) return null;
        $all[$email]['coupon_hold_until']   = time() + TOKI_COUPON_HOLD;
        $all[$email]['coupon_hold_session'] = $sessionId;
        return $all;
    });
}

/** 支払いが確定した。割引を使ったら使用済みにする */
function toki_first_coupon_mark_used(string $email, string $orderId): void {
    toki_json_update('members.json', function (array $all) use ($email, $orderId) {
        if (!isset($all[$email])) return null;
        $all[$email]['first_coupon_used_at'] = date('c');
        $all[$email]['first_coupon_order']   = $orderId;
        unset($all[$email]['coupon_hold_until'], $all[$email]['coupon_hold_session']);
        return $all;
    });
}

/**
 * Stripe側に割引（クーポン）を用意する。初回だけ作り、以後は作成済みの印を見て何もしない。
 * 既にある場合の作成エラーは成功として扱う。失敗しても決済は止めない（割引なしで進める）。
 */
function toki_ensure_first_coupon(): bool {
    $flag = toki_private_dir() . '/coupon-ready-' . TOKI_FIRST_COUPON_ID . '.json';
    if (is_readable($flag)) return true;

    $conf = toki_config();
    $base = rtrim((string)(getenv('TOKI_STRIPE_BASE') ?: 'https://api.stripe.com/v1'), '/');
    $ch = curl_init($base . '/coupons');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'id'         => TOKI_FIRST_COUPON_ID,
            'amount_off' => TOKI_FIRST_COUPON_AMOUNT,
            'currency'   => 'jpy',
            'duration'   => 'once',
            'name'       => '初回限定 ' . TOKI_FIRST_COUPON_AMOUNT . '円引き',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $conf['stripe_secret_key']],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode((string)$body, true);

    $ok = ($code >= 200 && $code < 300)
        || (($json['error']['code'] ?? '') === 'resource_already_exists');
    if ($ok) {
        @file_put_contents($flag, json_encode(['id' => TOKI_FIRST_COUPON_ID, 'at' => date('c')]));
        toki_log('ok', 'coupon ready ' . TOKI_FIRST_COUPON_ID);
    } else {
        toki_log('ERROR', 'coupon作成失敗 ' . $code . ' ' . substr((string)$body, 0, 200));
    }
    return $ok;
}

/* ── 応答 ─────────────────────────────────────────────── */

function toki_json(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function toki_redirect(string $path): void {
    $base = rtrim(toki_config()['site_base_url'], '/');
    header('Cache-Control: no-store');
    header('Location: ' . $base . $path, true, 303);
    exit;
}

/** 同じサイトからのPOSTか。ログアウト・退会・ログイン用リンクの送信を他サイトから撃たせない */
function toki_same_origin(): bool {
    $base = rtrim(toki_config()['site_base_url'], '/');
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') return $origin === $base;
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    return $ref === '' || strpos($ref, $base . '/') === 0;
}
