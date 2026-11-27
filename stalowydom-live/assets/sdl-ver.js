(function () {
  'use strict';
  function init(el) {
    var key = 'sdl_ver_' + el.getAttribute('data-sdl-page');
    var ver = el.getAttribute('data-sdl-ver');
    var seen = null;
    try { seen = localStorage.getItem(key); localStorage.setItem(key, ver); } catch (e) {}
    if (seen && seen !== ver) { var n = el.querySelector('.sdl-ver-new'); if (n) n.hidden = false; }
    var btn = el.querySelector('.sdl-ver-btn'), log = el.querySelector('.sdl-ver-log');
    if (!log) { btn.disabled = true; return; }
    /* okienko musi przykryć całą stronę: sekcje Divi tworzą własne warstwy (z-index),
       więc na czas otwarcia podnosimy wszystkich przodków okienka ponad resztę strony */
    var lifted = [];
    function lift() {
      for (var p = el; p && p !== document.body; p = p.parentElement) {
        lifted.push([p, p.style.zIndex, p.style.position]);
        if (getComputedStyle(p).position === 'static') p.style.position = 'relative';
        p.style.zIndex = '99990';
      }
    }
    function drop() { lifted.forEach(function (x) { x[0].style.zIndex = x[1]; x[0].style.position = x[2]; }); lifted = []; }
    function setOpen(open) {
      if (open === !log.hidden) return;
      log.hidden = !open;
      btn.setAttribute('aria-expanded', String(open));
      if (open) lift(); else drop();
    }
    btn.addEventListener('click', function () { setOpen(log.hidden); });
    document.addEventListener('click', function (e) { if (!el.contains(e.target)) setOpen(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });
  }
  function start() { document.querySelectorAll('.sdl-ver[data-sdl-ver]').forEach(init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
