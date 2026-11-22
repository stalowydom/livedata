(function () {
  'use strict';
  var DEVICES = ['dom', 'heiko', 'reqnet', 'maty', 'pompa_cwu', 'atlas'];
  var SHARE = [
    { dev: 'heiko', label: 'Pompa ciepła', color: '#f97316' },
    { dev: 'reqnet', label: 'Rekuperator', color: '#1baf7a' },
    { dev: 'atlas', label: 'Serwer', color: '#6250d6' },
    { dev: 'pompa_cwu', label: 'Pompa CWU', color: '#e87ba4' }
  ];
  var nf = function (v, d) { return v == null || isNaN(v) ? '–' : Number(v).toLocaleString('pl-PL', { minimumFractionDigits: d, maximumFractionDigits: d }); };
  var get = function (path) { return fetch(SDL.api + path, { credentials: 'omit' }).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); }); };

  function init(root) {
    var setD = function (k, v) { root.querySelectorAll('[data-d="' + k + '"]').forEach(function (el) { el.textContent = v; }); };

    function loadLatest() {
      return Promise.all(DEVICES.map(function (d) { return get('latest?device=' + d).catch(function () { return { values: {} }; }); })).then(function (res) {
        var data = {}, newest = null;
        DEVICES.forEach(function (d, i) { data[d] = res[i]; if (res[i].ts && (!newest || res[i].ts > newest)) newest = res[i].ts; });
        root.querySelectorAll('[data-v]').forEach(function (el) {
          var p = el.getAttribute('data-v').split('.'), dv = data[p[0]] && data[p[0]].values;
          el.textContent = nf(dv && dv[p[1]], +el.getAttribute('data-dec'));
        });
        var dom = data.dom.values || {}, heiko = data.heiko.values || {};
        setD('dom_power', nf(dom.power != null ? dom.power / 1000 : null, 2));
        setD('dom_today', nf(data.dom.today_kwh, 1));
        setD('heiko_today', nf(data.heiko.today_kwh, 1));
        setD('pompa_cwu_today', nf(data.pompa_cwu.today_kwh, 2));
        setD('atlas_today', nf(data.atlas.today_kwh, 2));
        setD('outdoor', nf(dom.outdoor != null ? dom.outdoor : heiko.outdoor, 1));
        drawShare(data);
        var txt = root.querySelector('.sdl-status-text');
        if (!newest) { txt.textContent = 'Brak danych – czekam na pierwszy odczyt z Home Assistant'; return; }
        var age = (Date.now() / 1000 - newest) / 60;
        root.classList.toggle('is-live', age < 20);
        txt.textContent = (age < 20 ? 'Na żywo · ostatni odczyt ' : 'Ostatni odczyt ') + new Date(newest * 1000).toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' });
      });
    }

    function drawShare(data) {
      var total = data.dom.values && data.dom.values.power;
      var bar = root.querySelector('.sdl-share'), leg = root.querySelector('.sdl-share-legend');
      var parts = SHARE.map(function (s) { var v = data[s.dev].values && data[s.dev].values.power; return { label: s.label, color: s.color, w: v == null ? null : Math.max(0, v) }; });
      var known = parts.reduce(function (a, p) { return a + (p.w || 0); }, 0);
      if (total != null) parts.push({ label: 'Reszta domu', color: '#4b5563', w: Math.max(0, total - known) });
      var sum = parts.reduce(function (a, p) { return a + (p.w || 0); }, 0);
      bar.innerHTML = ''; leg.innerHTML = '';
      parts.forEach(function (p) {
        if (sum > 0 && p.w > 0) { var seg = document.createElement('div'); seg.style.flex = p.w + ' 1 0'; seg.style.background = p.color; seg.title = p.label + ': ' + nf(p.w, 0) + ' W'; bar.appendChild(seg); }
        var li = document.createElement('span');
        li.innerHTML = '<i style="background:' + p.color + '"></i>' + p.label + ' <strong>' + (p.w == null ? '–' : nf(p.w, 0) + ' W') + '</strong>';
        leg.appendChild(li);
      });
      bar.setAttribute('aria-label', 'Podział mocy domu: ' + parts.map(function (p) { return p.label + ' ' + (p.w == null ? 'brak danych' : nf(p.w, 0) + ' W'); }).join(', '));
    }

    function loadDaily() {
      var sum = function (rows) { return rows.length ? rows.reduce(function (a, x) { return a + x[1]; }, 0) : null; };
      get('daily?device=dom&metric=energy_total&days=30').then(function (r) { setD('dom_30', nf(sum(r), 0)); }).catch(function () {});
      get('daily?device=heiko&metric=energy_total&days=30').then(function (r) { setD('heiko_30', nf(sum(r), 0)); }).catch(function () {});
    }

    loadLatest().catch(function () { root.querySelector('.sdl-status-text').textContent = 'Nie udało się pobrać danych'; });
    loadDaily();
    setInterval(function () { loadLatest().catch(function () {}); }, 300000);
  }

  function start() { document.querySelectorAll('[data-sdl-dom]').forEach(init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
