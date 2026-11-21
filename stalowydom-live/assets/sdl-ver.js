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
    btn.addEventListener('click', function () {
      log.hidden = !log.hidden;
      btn.setAttribute('aria-expanded', String(!log.hidden));
    });
    document.addEventListener('click', function (e) { if (!el.contains(e.target)) { log.hidden = true; btn.setAttribute('aria-expanded', 'false'); } });
  }
  function start() { document.querySelectorAll('.sdl-ver[data-sdl-ver]').forEach(init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
