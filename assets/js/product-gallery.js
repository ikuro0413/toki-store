(() => {
  const gallery = document.querySelector('[data-gallery]');
  if (!gallery) return;
  const main = gallery.querySelector('[data-gallery-image]');
  const links = Array.from(gallery.querySelectorAll('[data-gallery-thumb]'));
  const count = gallery.querySelector('[data-gallery-count]');
  let current = 0;
  const outline = gallery.querySelector('[data-color-outline]');
  const preview = gallery.querySelector('[data-color-preview]');
  function showColor() {
    const chosen = document.querySelector('input[name="color"]:checked');
    if (!chosen || !outline) return;
    outline.hidden = current !== 3;
    outline.style.left = chosen.dataset.left + '%';
  }
  function select(index) {
    current = (index + links.length) % links.length;
    const link = links[current];
    preview.hidden = !link.dataset.color;
    main.style.visibility = link.dataset.color ? 'hidden' : '';
    if (link.dataset.color) {
      preview.style.setProperty('--crop-left', (-Number(link.dataset.left) * 5) + '%');
      preview.querySelector('img').alt = link.querySelector('img').alt;
      const radio = document.querySelector('input[name="color"][value="' + link.dataset.color + '"]');
      radio.checked = true;
      document.querySelector('[data-color-name]').textContent = radio.dataset.label;
      gallery.querySelector('[data-color-caption]').textContent = '選択カラー：' + radio.dataset.label;
    }
    main.src = link.href;
    main.alt = link.querySelector('img').alt;
    links.forEach((item, i) => {
      if (i === current) item.setAttribute('aria-current', 'true');
      else item.removeAttribute('aria-current');
    });
    count.textContent = `${current + 1} / ${links.length}`;
    showColor();
  }
  links.forEach((link, i) => link.addEventListener('click', event => {
    event.preventDefault();
    select(i);
  }));
  gallery.querySelectorAll('[data-gallery-step]').forEach(button => {
    button.hidden = false;
    button.addEventListener('click', () => select(current + Number(button.dataset.galleryStep)));
  });
  gallery.addEventListener('keydown', event => {
    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
    event.preventDefault();
    select(current + (event.key === 'ArrowRight' ? 1 : -1));
  });
  document.querySelectorAll('input[name="color"]').forEach(input => {
    input.addEventListener('change', () => {
      document.querySelector('[data-color-name]').textContent = input.dataset.label;
      gallery.querySelector('[data-color-caption]').textContent = '選択カラー：' + input.dataset.label;
      select(links.findIndex(link => link.dataset.color === input.value));
    });
  });
})();
