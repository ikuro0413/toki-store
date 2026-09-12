(() => {
  const gallery = document.querySelector('[data-gallery]');
  if (!gallery) return;
  const main = gallery.querySelector('[data-gallery-image]');
  const links = Array.from(gallery.querySelectorAll('[data-gallery-thumb]'));
  const count = gallery.querySelector('[data-gallery-count]');
  let current = 0;
  function select(index) {
    current = (index + links.length) % links.length;
    const link = links[current];
    main.src = link.href;
    main.alt = link.querySelector('img').alt;
    links.forEach((item, i) => {
      if (i === current) item.setAttribute('aria-current', 'true');
      else item.removeAttribute('aria-current');
    });
    count.textContent = `${current + 1} / ${links.length}`;
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
})();
