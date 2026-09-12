(function () {
  'use strict';

  /* FAQ 開閉 */
  document.querySelectorAll('.faq__q').forEach(function (btn, i) {
    var answer = btn.closest('.faq').querySelector('.faq__a');
    answer.id = 'faq-answer-' + i;
    btn.setAttribute('aria-controls', answer.id);
    btn.setAttribute('aria-expanded', 'false');
    btn.addEventListener('click', function () {
      var open = btn.closest('.faq').classList.toggle('open');
      btn.setAttribute('aria-expanded', String(open));
    });
  });

  var menu = document.querySelector('.menu-toggle');
  var nav = document.querySelector('.header-nav');
  if (menu && nav) {
    function closeMenu() {
      menu.setAttribute('aria-expanded', 'false');
      nav.classList.remove('is-open');
      menu.textContent = 'メニュー';
    }
    menu.addEventListener('click', function () {
      var open = menu.getAttribute('aria-expanded') !== 'true';
      menu.setAttribute('aria-expanded', String(open));
      nav.classList.toggle('is-open', open);
      menu.textContent = open ? '閉じる' : 'メニュー';
    });
    nav.addEventListener('click', function (e) { if (e.target.closest('a')) closeMenu(); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && menu.getAttribute('aria-expanded') === 'true') {
        closeMenu(); menu.focus();
      }
    });
    window.matchMedia('(max-width:680px)').addEventListener('change', closeMenu);
  }

  /* スクロールで薄く現れる */
  if (!('IntersectionObserver' in window) || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  document.documentElement.classList.add('has-reveal');
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); }
    });
  }, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' });
  document.querySelectorAll('.reveal').forEach(function (el) { io.observe(el); });
})();
