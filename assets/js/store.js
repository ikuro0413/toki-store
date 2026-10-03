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
