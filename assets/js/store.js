/* TOKI STORE — 検索と絞り込み
   ・ヘッダーの検索欄：「アイテムを探す／記事を探す」で送り先を切り替える
   ・製品一覧（data-kfilter="items"）：?q= と ?scene= でカードを絞る
   ・記事一覧（.post-list）：?q= で記事を絞る
   JSが動かなくても、検索は一覧ページに着く（全件表示になるだけ）。 */
(function () {
  'use strict';

  /* 検索欄の送り先を切り替える */
  document.querySelectorAll('form.search').forEach(function (f) {
    function sync() {
      var r = f.querySelector('input[name=kind]:checked');
      if (r) f.action = r.value === 'reads' ? f.getAttribute('data-reads') : f.getAttribute('data-items');
    }
    f.querySelectorAll('input[name=kind]').forEach(function (r) { r.addEventListener('change', sync); });
    sync();
  });

  /* スマホの検索アイコンで検索欄を開く */
  var spBtn = document.querySelector('.sp-search');
  var spBox = document.getElementById('ksearch');
  if (spBtn && spBox) {
    spBtn.addEventListener('click', function (e) {
      e.preventDefault();
      var open = spBox.classList.toggle('is-open');
      spBtn.setAttribute('aria-expanded', String(open));
      if (open) { var i = spBox.querySelector('input[type=search]'); if (i) i.focus(); }
    });
  }

  var params = new URLSearchParams(location.search);
  var q = (params.get('q') || '').trim().toLowerCase();
  var scene = (params.get('scene') || '').trim();

  /* 検索語を検索欄に戻しておく（記事一覧にいるときは「記事を探す」側を選んでおく） */
  if (q) {
    var onReads = /articles\.html$/.test(location.pathname);
    document.querySelectorAll('form.search').forEach(function (f) {
      var i = f.querySelector('input[type=search]');
      if (i) i.value = params.get('q');
      var r = f.querySelector('input[name=kind][value=' + (onReads ? 'reads' : 'items') + ']');
      if (r) { r.checked = true; r.dispatchEvent(new Event('change')); }
    });
  }

  function note(el, text) { if (el) { el.textContent = text; el.hidden = !text; } }

  /* 製品一覧 */
  var grid = document.querySelector('[data-kfilter="items"]');
  if (grid) {
    var cards = grid.querySelectorAll('.item');
    var shown = 0;
    cards.forEach(function (c) {
      var text = (c.getAttribute('data-text') || c.textContent).toLowerCase();
      var scenes = (c.getAttribute('data-scenes') || '').split('|');
      var ok = (!q || text.indexOf(q) >= 0) && (!scene || scenes.indexOf(scene) >= 0);
      c.classList.toggle('kfilter-hide', !ok);
      if (ok) shown++;
    });
    document.querySelectorAll('.chips a').forEach(function (a) {
      a.setAttribute('aria-current', String((a.getAttribute('data-scene') || '') === scene));
    });
    var label = scene ? '「' + scene + '」' : '';
    if (q) label += (label ? 'と' : '') + '「' + params.get('q') + '」';
    note(document.querySelector('.kfilter-note'), label ? label + 'のアイテム：' + shown + '件' : '');
    var empty = document.querySelector('.kempty');
    if (empty) empty.hidden = shown !== 0;
  }

  /* ── 会員 ───────────────────────────────────────────── */
  var couponNote = document.querySelector('[data-coupon-note]');
  var memberLabel = document.querySelector('[data-member-label]');
  var mypage = document.querySelector('[data-mypage]');
  var loginForm = document.querySelector('[data-login-form]');

  // ログイン画面：戻り先と、エラー・お知らせの表示
  if (loginForm) {
    var next = params.get('next') || '';
    if (/^\/products\/[a-z0-9-]+\.html$/.test(next)) loginForm.querySelector('[name=next]').value = next;
    var msgs = {
      email: 'メールアドレスの形式をご確認ください。',
      agree: 'プライバシーポリシーへの同意が必要です。',
      busy: '短い時間に何度も送信されたため、少し時間をおいてお試しください。',
      mail: 'メールを送れませんでした。時間をおいてお試しいただくか、info@detoxnews.jp までご連絡ください。',
      expired: 'リンクの有効期限が切れているか、すでに使われています。もう一度メールアドレスを入力してください。'
    };
    var key = params.get('error');
    var box = document.querySelector('[data-login-msg]');
    var text = key && msgs[key] ? msgs[key]
      : params.get('bye') ? 'ログアウトしました。'
      : params.get('deleted') ? '退会の手続きが完了しました。ご利用ありがとうございました。' : '';
    if (box && text) { box.textContent = text; box.hidden = false; }
  }

  if (couponNote || memberLabel || mypage) {
    fetch('/api/member-me.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (me) {
        if (!me) return;
        if (memberLabel && me.loggedIn) memberLabel.textContent = 'マイページ';

        // 初回割引が使える会員には、価格を割引後で見せる（実際の値引きは決済画面で1注文につき1回）
        if (me.loggedIn && me.firstCoupon) {
          var off = me.couponAmount || 200;
          document.querySelectorAll('[data-price]').forEach(function (el) {
            var base = parseInt(el.getAttribute('data-price'), 10);
            if (!(base > off)) return;
            var yen = el.getAttribute('data-price-style') === 'yen';
            var fmt = function (n) { return yen ? '¥' + n.toLocaleString('ja-JP') : n.toLocaleString('ja-JP') + '円'; };
            el.innerHTML = '<s class="kprice-was">' + fmt(base) + '</s> <span class="kprice-now">' + fmt(base - off) + '</span>'
              + '<small class="kprice-tag">初回価格</small>';
            el.classList.add('is-member-price');
          });
        }

        if (couponNote) {
          var amount = (me.couponAmount || 200).toLocaleString('ja-JP');
          if (me.loggedIn && me.firstCoupon) {
            couponNote.innerHTML = '<b>初回限定 ' + amount + '円引き</b>：決済画面で自動で差し引かれます。'
              + amount + '円引きはご注文1回につき1回です（2個ご注文の場合も' + amount + '円引き）。';
            couponNote.hidden = false;
          } else if (!me.loggedIn) {
            var back = encodeURIComponent(location.pathname);
            couponNote.innerHTML = '会員登録（メールアドレスだけ）で、初回のご注文が<b>' + amount + '円引き</b>になります。'
              + ' <a href="/account/login.html?next=' + back + '">登録・ログイン</a>';
            couponNote.hidden = false;
          }
        }

        if (mypage) {
          if (!me.loggedIn) { location.replace('/account/login.html'); return; }
          var set = function (sel, v) { var el = mypage.querySelector(sel); if (el) el.textContent = v; };
          set('[data-me-email]', me.email);
          set('[data-me-since]', me.memberSince);
          set('[data-me-coupon]', me.firstCoupon ? '使えます（次のご注文で' + (me.couponAmount || 200) + '円引き）'
            : me.firstCouponUsed ? '使用済み' : 'ご購入の実績があるため対象外です');
          mypage.hidden = false;
          var w = document.querySelector('[data-welcome]');
          if (w && params.get('welcome')) w.hidden = false;
        }
      })
      .catch(function () {});
  }

  /* 記事一覧 */
  var posts = document.querySelector('.post-list');
  if (posts && q) {
    var n = 0;
    posts.querySelectorAll('.post').forEach(function (p) {
      var ok = p.textContent.toLowerCase().indexOf(q) >= 0;
      p.classList.toggle('kfilter-hide', !ok);
      if (ok) n++;
    });
    var msg = document.createElement('p');
    msg.className = 'kfilter-note';
    msg.textContent = '「' + params.get('q') + '」を含む読みもの：' + n + '件';
    posts.parentNode.insertBefore(msg, posts);
  }
})();
