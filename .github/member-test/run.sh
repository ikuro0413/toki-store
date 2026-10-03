#!/usr/bin/env bash
# 会員登録 → ログイン → 初回割引 → 支払い確定 → 使用済み → ログアウト → 退会 を実際のPHPで通す
set -u
ROOT=$(pwd)
W=$(mktemp -d); P=$W/private; M=$W/mock; mkdir -p "$P" "$M"
export TOKI_PRIVATE_DIR=$P TOKI_STRIPE_BASE=http://127.0.0.1:8001/v1 TOKI_MAIL_FILE=$W/mail.log TOKI_INSECURE_COOKIE=1 MOCK_DIR=$M
SITE=http://127.0.0.1:8000

printf "<?php return ['stripe_secret_key'=>'sk_test_x','stripe_webhook_secret'=>'whsec_testsecret','site_base_url'=>'%s'];\n" "$SITE" > "$P/toki-env.php"
printf '%s\n' '{"off-box":{"sku":"TOKI-OFF-001","name":"スマホタイムロックケース","price_jpy":3180,"status":"preorder","ship_eta":"2026年11月30日まで","preorder_cap":30,"stock":0,"max_qty_per_order":2}}' > "$P/products.json"

php -S 127.0.0.1:8001 "$ROOT/.github/member-test/stripe-mock.php" > "$W/mock.out" 2>&1 &
php -S 127.0.0.1:8000 -t "$ROOT" > "$W/site.out" 2>&1 &
sleep 1

fail=0
check() { if eval "$2"; then echo "OK   $1"; else echo "FAIL $1"; fail=1; fi; }
J=$W/jar
me()  { curl -s -c "$J" -b "$J" "$SITE/api/member-me.php"; }
loc() { curl -s -o /dev/null -w '%{redirect_url}' -c "$J" -b "$J" "$@"; }
post() { loc -H "Origin: $SITE" -X POST "$@"; }
lastlink() { php -r '$l=file($argv[1]);$m=json_decode(end($l),true);preg_match("#https?://\S+#",$m["body"],$x);echo $x[0];' "$W/mail.log"; }

# 1. 未ログイン
check "未ログインの me" '[[ $(me) == *"\"loggedIn\":false"* ]]'

# 2. 未ログインの購入は従来どおり（メール固定なし・割引なし）
r=$(loc -X POST -d "sku=off-box&color=white" "$SITE/api/checkout.php")
check "未ログインの購入は決済画面へ" '[[ $r == https://checkout.example/pay/1 ]]'
check "未ログインは割引なし・メール固定なし" '! grep -q discounts "$M/session-1.json" && ! grep -q customer_email "$M/session-1.json"'

# 3. 入力の検査
code=$(curl -s -o /dev/null -w '%{http_code}' -H "Origin: https://evil.example" -X POST -d "email=a@example.com&agree=1" "$SITE/api/member-login.php")
check "他サイトからのPOSTは403" '[[ $code == 403 ]]'
check "同意なしは戻す" '[[ $(post -d "email=a@example.com" "$SITE/api/member-login.php") == *error=agree ]]'
check "不正なアドレスは戻す" '[[ $(post -d "email=bad&agree=1" "$SITE/api/member-login.php") == *error=email ]]'

# 4. ログイン用リンクを送る
r=$(post -d "email=Buyer@Example.com&agree=1&next=/products/off-box.html" "$SITE/api/member-login.php")
check "送信後は送信済みページへ" '[[ $r == $SITE/account/sent.html ]]'
link=$(lastlink)
check "メールにリンクが入っている" '[[ $link == $SITE/api/member-verify.php?t=* ]]'
check "宛先は小文字に正規化" 'grep -q "\"to\":\"buyer@example.com\"" "$W/mail.log"'
tok=$(echo "$link" | sed 's/.*t=//; s/&.*//')
check "トークンの平文は保存しない" '! grep -q "$tok" "$P/login-tokens.json"'

# 5. リンクを開く → ログイン
r=$(loc "$link")
check "リンクで商品ページへ戻る" '[[ $r == "$SITE/products/off-box.html?welcome=1" ]]'
m=$(me)
check "ログイン後の me" '[[ $m == *"\"loggedIn\":true"* && $m == *"\"firstCoupon\":true"* && $m == *buyer@example.com* ]]'
check "同じリンクは2回使えない" '[[ $(curl -s -o /dev/null -w "%{redirect_url}" "$link") == *error=expired ]]'

# 6. 会員の初回購入 → 割引つき
r=$(loc -X POST -d "sku=off-box&color=white" "$SITE/api/checkout.php")
check "会員の購入は決済画面へ" '[[ $r == https://checkout.example/pay/2 ]]'
check "初回は割引が入る" 'grep -q "TOKI_FIRST200" "$M/session-2.json" && grep -q "\"discounts\"" "$M/session-2.json"'
check "メール欄を会員アドレスで固定" 'grep -q "\"customer_email\":\"buyer@example.com\"" "$M/session-2.json"'
check "割引つき画面は期限つき" 'grep -q expires_at "$M/session-2.json"'
check "クーポンは1回だけ作る" '[[ $(cat "$M/coupon-count") == 1 ]]'
check "保留中の me は割引なし" '[[ $(me) == *"\"firstCoupon\":false"* ]]'

# 7. 開いている間にもう一度 → 割引なし（二重取り防止）
loc -X POST -d "sku=off-box&color=white" "$SITE/api/checkout.php" > /dev/null
check "保留中の2回目は割引なし" '! grep -q discounts "$M/session-3.json"'

# 8. 支払い確定（Webhook）→ 使用済み
ts=$(date +%s)
body='{"id":"evt_test_1","type":"checkout.session.completed","data":{"object":{"id":"cs_test_2"}}}'
sig=$(printf '%s' "$ts.$body" | openssl dgst -sha256 -hmac whsec_testsecret | sed 's/^.* //')
code=$(curl -s -o /dev/null -w '%{http_code}' -H "Stripe-Signature: t=$ts,v1=$sig" -H 'Content-Type: application/json' -d "$body" "$SITE/api/stripe-webhook.php")
sleep 1
check "Webhookは200" '[[ $code == 200 ]]'
check "注文の控えに割引が残る" 'grep -q "\"discount_jpy\":200" "$P/orders.jsonl" && grep -q "\"price_jpy\":2980" "$P/orders.jsonl"'
check "会員は使用済み" 'grep -q first_coupon_used_at "$P/members.json"'
check "me は使用済み" '[[ $(me) == *"\"firstCouponUsed\":true"* ]]'

# 9. 2回目の購入 → 割引なし
loc -X POST -d "sku=off-box&color=white" "$SITE/api/checkout.php" > /dev/null
check "2回目の購入は割引なし" '! grep -q discounts "$M/session-4.json"'

# 10. ログアウト
check "ログアウトはPOSTのみ" '[[ $(curl -s -o /dev/null -w "%{http_code}" -c "$J" -b "$J" "$SITE/api/member-logout.php") == 405 ]]'
post "$SITE/api/member-logout.php" > /dev/null
check "ログアウト後の me" '[[ $(me) == *"\"loggedIn\":false"* ]]'

# 11. 別の人：登録 → 退会
post -d "email=leave@example.com&agree=1" "$SITE/api/member-login.php" > /dev/null
loc "$(lastlink)" > /dev/null
check "別の人もログインできる" '[[ $(me) == *leave@example.com* ]]'
check "退会は確認なしだと戻す" '[[ $(post "$SITE/api/member-delete.php") == *error=confirm ]]'
post -d "confirm=1" "$SITE/api/member-delete.php" > /dev/null
check "退会で会員情報が消える" '! grep -q leave@example.com "$P/members.json"'
check "退会後はログアウト状態" '[[ $(me) == *"\"loggedIn\":false"* ]]'

# 12. 連打よけ（同じアドレスへは10分に3回まで）
for i in 1 2 3; do post -d "email=spam@example.com&agree=1" "$SITE/api/member-login.php" > /dev/null; done
check "4回目は送らない" '[[ $(post -d "email=spam@example.com&agree=1" "$SITE/api/member-login.php") == *error=busy ]]'

check "PHPの警告・エラーなし" '! grep -qiE "warning|fatal|deprecated|notice" "$W/site.out"'

echo "--- store.log"; cat "$P/store.log"
if [[ $fail == 0 ]]; then echo "ALL PASSED"; else echo "--- site.out"; cat "$W/site.out"; exit 1; fi
