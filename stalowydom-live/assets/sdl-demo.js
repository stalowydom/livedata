/* [sdl_heiko_demo] – wersja demonstracyjna układu strony Heiko: rozdziały zwijane, przyklejony spis, oś kroków laboratorium */
(function () {
  var CH = [
    { sel: '.sdl-defrost', id: 'r-odszranianie', name: 'Odszranianie i warunki szronienia', sub: 'Kiedy pompa się szroni, ile to trwa i ile kosztuje', key: 'na żywo' },
    { sel: '.sdl-grzalki', id: 'r-grzalki', name: 'Grzałki kontra pompa ciepła', sub: 'Rok 2025 na grzałkach – i co zmieniła pompa', key: 'rok 2025' },
    { sel: '.sdl-cop:not(.sdl-cop-m)', id: 'r-cop', name: 'COP: laboratorium kontra prawdziwa instalacja', sub: 'Skąd katalogowe 4,5 i dlaczego w domu wychodzi mniej', key: 'katalog' },
    { sel: '.sdl-cop-m', id: 'r-cop-pomiar', name: 'COP zmierzony: bufor jako ciepłomierz', sub: 'Każdy cykl pracy policzony z bilansu zbiornika', key: 'ok. 2,65 przy 0 °C' },
    { sel: '.sdl-plan', id: 'r-laboratorium', name: 'Laboratorium: ciepła woda, ogrzewanie i plan', sub: 'Kalibracja, krzywa grzewcza, zawór mieszający, regulator', key: 'krok 1: obserwujemy' }
  ];
  function topOffset() {
    var h = document.querySelector('.et-l--header, #main-header, header');
    var adm = document.getElementById('wpadminbar');
    return (h && getComputedStyle(h).position === 'fixed' ? h.offsetHeight : 0) + (adm ? adm.offsetHeight : 0);
  }
  function build() {
    var root = document.querySelector('.sdl-demo'); if (!root) return;
    var nav = root.querySelector('.sdl-demo-nav-in');
    var made = {};
    CH.forEach(function (c) {
      var el = document.querySelector(c.sel); if (!el) return;
      var mod = el.closest('.et_pb_module') || el;
      var parts = [mod];
      var prev = mod.previousElementSibling;
      if (prev && prev.classList.contains('et_pb_text') && !prev.querySelector('[class*="sdl-"]')) parts.unshift(prev);
      var bar = document.createElement('button');
      bar.type = 'button'; bar.className = 'sdl-demo-bar'; bar.id = c.id;
      bar.innerHTML = '<span class="sdl-demo-bar-t"><b>' + c.name + '</b><small>' + c.sub + '</small></span><span class="sdl-demo-bar-k">' + c.key + '</span><span class="sdl-demo-chev" aria-hidden="true"></span>';
      bar.setAttribute('aria-expanded', 'false');
      parts[0].parentNode.insertBefore(bar, parts[0]);
      parts.forEach(function (p) { p.classList.add('sdl-demo-hide'); });
      var toggle = function (open) {
        var o = open === undefined ? bar.getAttribute('aria-expanded') !== 'true' : open;
        bar.setAttribute('aria-expanded', o ? 'true' : 'false');
        parts.forEach(function (p) { p.classList.toggle('sdl-demo-hide', !o); });
        if (o) window.dispatchEvent(new Event('resize'));
      };
      bar.addEventListener('click', function () { toggle(); });
      made[c.id] = toggle;
      var a = document.createElement('a'); a.href = '#' + c.id; a.textContent = c.name.split(':')[0];
      nav.appendChild(a);
    });
    var go = function (id, anchor) {
      if (made[id]) made[id](true);
      var t = document.getElementById(anchor || id);
      if (t) window.scrollTo({ top: t.getBoundingClientRect().top + window.scrollY - topOffset() - 64, behavior: 'smooth' });
    };
    root.querySelectorAll('[data-go]').forEach(function (b) {
      b.addEventListener('click', function (e) { e.preventDefault(); go(b.getAttribute('data-go'), b.getAttribute('data-anchor')); });
    });
    nav.querySelectorAll('a').forEach(function (a) {
      a.addEventListener('click', function (e) { e.preventDefault(); go(a.getAttribute('href').slice(1)); });
    });
    var sticky = root.querySelector('.sdl-demo-nav');
    var setTop = function () { sticky.style.top = topOffset() + 'px'; };
    setTop(); window.addEventListener('resize', setTop);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', build); else build();
})();
