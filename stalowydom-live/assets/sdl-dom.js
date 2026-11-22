(function () {
  'use strict';
  var DEVICES = ['dom', 'heiko', 'reqnet', 'maty', 'pompa_cwu', 'serwer'];
  var SHARE = [
    { dev: 'heiko', label: 'Pompa ciepła', color: '#f97316' },
    { dev: 'reqnet', label: 'Rekuperator', color: '#1baf7a' },
    { dev: 'serwer', label: 'Serwer', color: '#6250d6' },
    { dev: 'pompa_cwu', label: 'Pompa CWU', color: '#e87ba4' }
  ];
  var nf = function (v, d) { return v == null || isNaN(v) ? '–' : Number(v).toLocaleString('pl-PL', { minimumFractionDigits: d, maximumFractionDigits: d }); };
  var en = function (kwh) { if (kwh == null || isNaN(kwh)) return '–'; return kwh < 1 ? nf(kwh * 1000, 0) + ' Wh' : nf(kwh, kwh < 10 ? 2 : 1) + ' kWh'; };
  var get = function (path) { return fetch(SDL.api + path, { credentials: 'omit' }).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); }); };

  var MONTHS = ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];
  function monthSums(rows) {
    var now = new Date(), cy = now.getFullYear(), cm = now.getMonth();
    var py = cm === 0 ? cy - 1 : cy, pm = cm === 0 ? 11 : cm - 1;
    var key = function (y, m) { return y + '-' + String(m + 1).padStart(2, '0'); };
    var sum = function (k) { var f = rows.filter(function (x) { return x[0].slice(0, 7) === k; }); return f.length ? f.reduce(function (a, x) { return a + x[1]; }, 0) : null; };
    return { cur: sum(key(cy, cm)), prev: sum(key(py, pm)), curName: MONTHS[cm], prevName: MONTHS[pm] };
  }

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
        setD('heiko_co', nf(data.heiko.today_co, 1));
        setD('heiko_cwu', nf(data.heiko.today_cwu, 1));
        setD('heiko_defrost', heiko.defrost_n == null ? '–' : nf(heiko.defrost_n, 0) + '× · ' + nf((heiko.defrost_h || 0) * 60, 1) + ' min');
        var setE = function (k, v) { root.querySelectorAll('[data-e="' + k + '"]').forEach(function (el) { el.textContent = en(v); }); };
        setE('pompa_cwu_today', data.pompa_cwu.today_kwh);
        setE('serwer_today', data.serwer.today_kwh);
        setE('reqnet_today', data.reqnet.today_kwh);
        var running = heiko.power != null && heiko.power > 300;
        root.querySelectorAll('[data-only="running"]').forEach(function (el) { el.hidden = !running; });
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
      var total = data.dom.today_kwh;
      var bar = root.querySelector('.sdl-share'), leg = root.querySelector('.sdl-share-legend');
      var parts = SHARE.map(function (s) { var v = data[s.dev].today_kwh; return { label: s.label, color: s.color, w: v == null ? null : Math.max(0, v) }; });
      var known = parts.reduce(function (a, p) { return a + (p.w || 0); }, 0);
      if (total != null) parts.push({ label: 'Reszta domu', color: '#4b5563', w: Math.max(0, total - known) });
      var sum = parts.reduce(function (a, p) { return a + (p.w || 0); }, 0);
      var fmt = function (w) { return w == null ? '–' : nf(w, w < 10 ? 2 : 1) + ' kWh'; };
      var pct = function (w) { return sum > 0 && w != null ? ' (' + nf(100 * w / sum, 0) + '%)' : ''; };
      bar.innerHTML = ''; leg.innerHTML = '';
      parts.forEach(function (p) {
        if (sum > 0 && p.w > 0) { var seg = document.createElement('div'); seg.style.flex = p.w + ' 1 0'; seg.style.background = p.color; seg.title = p.label + ': ' + fmt(p.w) + pct(p.w); bar.appendChild(seg); }
        var li = document.createElement('span');
        li.innerHTML = '<i style="background:' + p.color + '"></i>' + p.label + ' <strong>' + fmt(p.w) + '</strong>' + pct(p.w);
        leg.appendChild(li);
      });
      bar.setAttribute('aria-label', 'Zużycie energii dziś: ' + parts.map(function (p) { return p.label + ' ' + fmt(p.w) + pct(p.w); }).join(', '));
    }

    function loadDaily() {
      var sum = function (rows) { return rows.length ? rows.reduce(function (a, x) { return a + x[1]; }, 0) : null; };
      get('daily?device=dom&metric=energy_total&days=30').then(function (r) { setD('dom_30', nf(sum(r), 0)); }).catch(function () {});
      get('daily?device=heiko&metric=energy_total&days=30').then(function (r) {
        setD('heiko_30', nf(sum(r), 0));
      }).catch(function () {});
      get('daily?device=heiko&metric=energy_total&days=62').then(function (r) {
        var m = monthSums(r), cap = function (x) { return x.charAt(0).toUpperCase() + x.slice(1); };
        setD('heiko_mcur', nf(m.cur, 0)); setD('heiko_mprev', nf(m.prev, 0));
        setD('heiko_mcur_name', cap(m.curName) + ' (do dziś)'); setD('heiko_mprev_name', cap(m.prevName));
        var yd = new Date(); yd.setDate(yd.getDate() - 1);
        var ykey = yd.getFullYear() + '-' + String(yd.getMonth() + 1).padStart(2, '0') + '-' + String(yd.getDate()).padStart(2, '0');
        var y = r.filter(function (x) { return x[0] === ykey; })[0];
        setD('heiko_yday', y ? nf(y[1], 1) : '–');
      }).catch(function () {});
      ['pompa_cwu', 'serwer', 'reqnet'].forEach(function (d) {
        get('daily?device=' + d + '&metric=energy_total&days=30').then(function (r) {
          var v = sum(r); root.querySelectorAll('[data-e="' + d + '_30"]').forEach(function (el) { el.textContent = en(v); });
        }).catch(function () {});
      });
    }

    loadLatest().catch(function () { root.querySelector('.sdl-status-text').textContent = 'Nie udało się pobrać danych'; });
    loadDaily();
    setInterval(function () { loadLatest().catch(function () {}); }, 300000);
  }

  function start() { document.querySelectorAll('[data-sdl-dom]').forEach(init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
