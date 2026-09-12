(function () {
  'use strict';
  var form = document.getElementById('requestForm');
  if (!form) return;
  var btn = document.getElementById('submitBtn');
  var err = document.getElementById('formError');
  var done = document.getElementById('formDone');
  var item = document.getElementById('item');
  var email = document.getElementById('email');
  var notice = new URLSearchParams(location.search).get('item') === 'off-box';
  if (notice) {
    document.querySelector('.article-title').textContent = 'OFF BOX 販売開始のお知らせ';
    document.querySelector('.form-lead').textContent = '販売開始が決まったときに、メールでお知らせします。予約や購入のお申し込みではありません。';
    item.value = 'OFF BOXの販売開始のお知らせを希望';
    item.readOnly = true;
    email.required = true;
    document.querySelector('label[for="email"]').innerHTML = 'メールアドレス <span class="req">必須</span>';
    email.placeholder = '例：mail@example.com';
    email.previousElementSibling.textContent = '販売開始のお知らせと、このご希望についての確認に使います。';
    btn.textContent = 'お知らせを申し込む';
  }
  var buttonText = btn.textContent;
  form.addEventListener('submit', async function (ev) {
    ev.preventDefault();
    if (btn.disabled) return;
    if (!item.value.trim()) {
      err.textContent = '欲しい道具をご記入ください。'; err.hidden = false; item.focus(); return;
    }
    if (!form.reportValidity()) return;
    err.hidden = true;
    btn.disabled = true;
    btn.textContent = '送信中…';
    form.setAttribute('aria-busy', 'true');
    var controller = new AbortController();
    var timeout = setTimeout(function () { controller.abort(); }, 45000);
    try {
      var response = await fetch('api/request.php', {
        method: 'POST', signal: controller.signal,
        body: new URLSearchParams({item:item.value.trim(), problem:document.getElementById('problem').value.trim(), budget:document.getElementById('budget').value, email:email.value.trim(), website:document.getElementById('website').value, page:location.origin + location.pathname, ua:navigator.userAgent})
      });
      var result = await response.json();
      if (!response.ok || result.ok !== true) throw new Error('unconfirmed');
      if (window.gtag) gtag('event', notice ? 'availability_request' : 'request_submit');
      if (notice) {
        done.querySelector('.form-done__ttl').textContent = 'お知らせのご希望を受け付けました';
        done.querySelectorAll('p')[1].textContent = '販売開始が決まりましたら、ご記入のメールアドレスへご連絡します。';
      }
      form.hidden = true; done.hidden = false; done.focus();
    } catch (_) {
      err.textContent = '受付を確認できませんでした。入力内容は残しています。重複を避けたい場合は info@detoxnews.jp へお問い合わせください。';
      err.hidden = false;
    } finally {
      clearTimeout(timeout); btn.disabled = false; btn.textContent = buttonText; form.removeAttribute('aria-busy');
    }
  });
})();
