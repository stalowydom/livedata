(function () {
  'use strict';
  var nf = function (v, d) { return v == null || isNaN(v) ? '–' : Number(v).toLocaleString('pl-PL', { minimumFractionDigits: d, maximumFractionDigits: d }); };
  var sg = function (v) { return (v > 0 ? '+' : v < 0 ? '−' : '') + nf(Math.abs(v), 1); };
  var when = function (ts) {
    var d = new Date(ts * 1000), today = new Date(); today.setHours(0, 0, 0, 0);
    var y = new Date(today); y.setDate(y.getDate() - 1);
    var t = d.toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' });
    if (d >= today) return 'dziś ' + t;
    if (d >= y) return 'wczoraj ' + t;
    return d.toLocaleDateString('pl-PL', { day: '2-digit', month: '2-digit' }) + ' ' + t;
  };
  function story(e) {
    var v = e.values, start = e.ts - (v.duration_min || 0) * 60;
    var s = 'Ostatnie odszranianie zaczęło się ' + when(start) + ' i trwało ' + nf(v.duration_min, 1) + ' min. ';
    s += 'Sprężarka przełączyła obieg, a wentylator jednostki zewnętrznej stanął. ';
    if (v.tp_start != null && v.tp_end != null) s += 'Parownik ogrzał się z ' + sg(v.tp_start) + ' °C do ' + sg(v.tp_end) + ' °C – szron i lód na lamelach stopniały. ';
    if (v.tuo_start != null && v.tuo_end != null) {
      var drop = v.tuo_start - v.tuo_end;
      if (drop > 0.2) s += 'Woda na wyjściu z pompy ochłodziła się z ' + nf(v.tuo_start, 1) + ' °C do ' + nf(v.tuo_end, 1) + ' °C (o ' + nf(drop, 1) + ' °C), bo to ciepło z instalacji poszło na roztopienie szronu. ';
    }
    if (v.outdoor != null) s += 'Na zewnątrz było ' + sg(v.outdoor) + ' °C. ';
    s += 'Potem wentylator ruszył i pompa wróciła do normalnej pracy.';
    return s;
  }

  /* warunki szronienia – te same wzory co w PHP (sdl_frost) */
  function frost(t, rh, tp, running) {
    if (t == null || rh == null || rh <= 0) return null;
    rh = Math.min(100, rh);
    var es = function (x) { return 6.112 * Math.exp(17.62 * x / (243.12 + x)); };
    var ei = function (x) { return 6.112 * Math.exp(22.46 * x / (272.62 + x)); };
    var ah = function (p, x) { return 216.7 * p / (273.15 + x); };
    var e = rh / 100 * es(t), l = Math.log(e / 6.112), est = tp == null || !running, ts = est ? t - 7 : tp;
    var air = ah(e, t), sat = ah(ts < 0 ? ei(ts) : es(ts), ts);
    return { t: t, rh: rh, dew: 243.12 * l / (17.62 - l), frost: 272.62 * l / (22.46 - l), ah: air, ts: ts, est: est, pot: ts < 0 ? Math.max(0, air - sat) : 0 };
  }
  var level = function (p) { return p < 0.3 ? 'minimalny' : p < 1 ? 'mały' : p < 2 ? 'umiarkowany' : 'duży'; };
  function frostStory(f) {
    var s = 'Na zewnątrz jest ' + sg(f.t) + ' °C przy wilgotności ' + nf(f.rh, 0) + ' %. W każdym metrze sześciennym powietrza jest ' + nf(f.ah, 1) +
      ' g wody. Punkt rosy wypada przy ' + sg(f.dew) + ' °C, a punkt szronu przy ' + sg(f.frost) + ' °C – każda powierzchnia zimniejsza od tej temperatury zbiera z powietrza lód. ';
    s += f.est ? 'Sprężarka teraz stoi. Gdy pracuje, parownik jest zwykle ok. 7 K zimniejszy od powietrza, czyli ma ok. ' + sg(f.ts) + ' °C. ' : 'Parownik ma teraz ' + sg(f.ts) + ' °C. ';
    if (f.pot < 0.05) {
      s += f.ts >= 0 ? 'Jest cieplejszy niż 0 °C, więc szron się nie tworzy – wilgoć najwyżej skrapla się i spływa.'
        : 'Mimo mrozu na lamelach nie ma z czego osiąść lodu – powietrze jest tak suche, że parownik nie schodzi poniżej jego punktu szronu.';
    } else {
      s += 'Z każdego metra sześciennego przepływającego powietrza może na nim osiąść do ' + nf(f.pot, 1) + ' g lodu – potencjał szronienia jest ' + level(f.pot) + '. ';
      s += f.pot >= 1 ? 'Wentylator przetłacza tysiące metrów sześciennych na godzinę, więc lamele zarastają szybko i pompa musi się często odszraniać.'
        : 'Lód przybywa powoli, więc odszraniania powinny być rzadkie.';
    }
    return s + ' Najgorsze dla pompy są temperatury w okolicy zera przy dużej wilgotności: ciepłe powietrze niesie dużo wody. W siarczysty mróz powietrze jest suche i szronu przybywa kilka razy wolniej.';
  }
  var getJSON = function (url, tries) {
    return fetch(url, { credentials: 'omit' }).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .catch(function (e) { if ((tries || 0) >= 2) throw e; return new Promise(function (res) { setTimeout(res, 700 * ((tries || 0) + 1)); }).then(function () { return getJSON(url, (tries || 0) + 1); }); });
  };
  function loadFrost(root) {
    var box = root.querySelector('[data-frost]'); if (!box) return;
    var set = function (k, v) { var el = root.querySelector('[data-fr="' + k + '"]'); if (el) el.textContent = v; };
    getJSON(SDL.api + 'latest?device=heiko').then(function (d) {
      var v = d.values || {}, f = frost(v.outdoor, v.rh_out, v.tp, (v.power || 0) > 300);
      if (!f) return;
      ['t', 'ah', 'dew', 'frost', 'ts', 'pot'].forEach(function (k) { set(k, nf(f[k], 1)); });
      set('rh', nf(f.rh, 0)); set('ts_note', f.est ? 'szacunek przy pracy sprężarki' : 'odczyt z pompy'); set('lvl', level(f.pot)); set('story', frostStory(f));
    }).catch(function () {});
  }
  function init(root) {
    loadFrost(root); setInterval(function () { loadFrost(root); }, 300000);
    var dev = root.getAttribute('data-sdl-defrost');
    var set = function (k, v) { var el = root.querySelector('[data-f="' + k + '"]'); if (el) el.textContent = v; };
    getJSON(SDL.api + 'events?device=' + dev + '&days=7').then(function (ev) {
      if (!ev || !ev.length) return;
      var today = new Date(); today.setHours(0, 0, 0, 0);
      set('today', String(ev.filter(function (e) { return e.ts * 1000 >= today.getTime(); }).length));
      var durs = ev.map(function (e) { return e.values.duration_min; }).filter(function (x) { return x != null; });
      set('avg', durs.length ? nf(durs.reduce(function (a, b) { return a + b; }, 0) / durs.length, 1) : '–');
      set('last', when(ev[0].ts - (ev[0].values.duration_min || 0) * 60));
      set('story', story(ev[0]));
      /* podsumowanie tygodnia + tabela pogrupowana na dni (domyślnie zwinięta) */
      var dayKey = function (ts) { var d = new Date(ts * 1000); return d.getFullYear() + '-' + d.getMonth() + '-' + d.getDate(); };
      var days = {}, order = [];
      ev.forEach(function (e) { var k = dayKey(e.ts); if (!days[k]) { days[k] = []; order.push(k); } days[k].push(e); });
      var allMin = durs.reduce(function (a, b) { return a + b; }, 0);
      set('week', 'W ciągu ostatnich 7 dni pompa odszraniała się ' + ev.length + ' razy, łącznie przez ' + nf(allMin, 0) + ' min (średnio ' +
        nf(ev.length / Math.max(1, order.length), 1) + ' razy na dobę). Najwięcej odszronień: ' +
        (function () { var best = order.reduce(function (a, k) { return days[k].length > days[a].length ? k : a; }, order[0]);
          return new Date(days[best][0].ts * 1000).toLocaleDateString('pl-PL', { weekday: 'long', day: 'numeric', month: 'numeric' }) + ' (' + days[best].length + ').'; })());
      var sum = root.querySelector('.sdl-more-open'); if (sum) sum.textContent = 'Pokaż wszystkie odszraniania (' + ev.length + ')';
      var tb = root.querySelector('[data-f="rows"]'); tb.innerHTML = '';
      var lastDay = null;
      ev.forEach(function (e) {
        var k = dayKey(e.ts);
        if (k !== lastDay) {
          lastDay = k;
          var hr = document.createElement('tr'), th = document.createElement('th');
          th.colSpan = 5; th.scope = 'rowgroup'; th.className = 'sdl-day';
          var dm = days[k].reduce(function (a, x) { return a + (x.values.duration_min || 0); }, 0);
          th.textContent = new Date(e.ts * 1000).toLocaleDateString('pl-PL', { weekday: 'long', day: 'numeric', month: 'long' }) + ' – ' + days[k].length + ' ×, razem ' + nf(dm, 0) + ' min';
          hr.appendChild(th); tb.appendChild(hr);
        }
        var v = e.values, tr = document.createElement('tr');
        var cells = [when(e.ts - (v.duration_min || 0) * 60), nf(v.duration_min, 1) + ' min',
          (v.tp_start != null ? sg(v.tp_start) + ' → ' + sg(v.tp_end) + ' °C' : '–'),
          (v.tuo_start != null ? nf(v.tuo_start, 1) + ' → ' + nf(v.tuo_end, 1) + ' °C' : '–'),
          (v.outdoor != null ? sg(v.outdoor) + ' °C' : '–')];
        cells.forEach(function (c) { var td = document.createElement('td'); td.textContent = c; tr.appendChild(td); });
        tb.appendChild(tr);
      });
    }).catch(function () {});
  }
  /* odszranianie w dłuższym okresie: wykres dobowy (lub tygodniowy dla długich zakresów) i tabela według temperatury */
  function initLong(root) {
    var box = root.querySelector('[data-dl]'); if (!box) return;
    var cv = box.querySelector('[data-dl="chart"]'), tb = box.querySelector('[data-dl="rows"]'), sumEl = box.querySelector('[data-dl="sum"]'), chart = null, ALL = [];
    var B = [[-99, -5, 'poniżej −5 °C'], [-5, -2, '−5 … −2 °C'], [-2, 0, '−2 … 0 °C'], [0, 2, '0 … +2 °C'], [2, 5, '+2 … +5 °C'], [5, 99, 'powyżej +5 °C']];
    var dfmt = function (d) { return new Date(d + 'T12:00:00').toLocaleDateString('pl-PL', { day: 'numeric', month: 'numeric' }); };
    function render(range) {
      var L = range ? ALL.slice(-range) : ALL.slice();
      if (!L.length) return;
      var full = L.filter(function (x) { return !x[6]; });
      var n = L.reduce(function (a, x) { return a + x[1]; }, 0), m = L.reduce(function (a, x) { return a + x[2]; }, 0);
      var run = full.reduce(function (a, x) { return a + (x[5] || 0); }, 0), mf = full.reduce(function (a, x) { return a + x[2]; }, 0);
      var cold = L.reduce(function (a, x) { return x[3] != null && (a == null || x[3] < a[3]) ? x : a; }, null);
      sumEl.textContent = (range ? 'W ostatnich ' + L.length + ' dobach' : 'Od ' + dfmt(L[0][0]) + ' (' + L.length + ' dób)') + ' pompa odszraniała się ' + n + ' razy, łącznie przez ' + nf(m, 0) + ' min – średnio ' +
        nf(n / L.length, 1) + ' razy na dobę, po ' + nf(n ? m / n : null, 1) + ' min.' + (run > 0 ? ' Odszranianie zajęło ' + nf(mf / (run * 60) * 100, 1) + ' % czasu pracy sprężarki.' : '') +
        (cold ? ' Najzimniejsza doba: ' + dfmt(cold[0]) + ' (średnio ' + sg(cold[3]) + ' °C, ' + cold[1] + ' odszronień).' : '');
      /* dla zakresów powyżej 60 dób – sumy tygodniowe */
      var pts = L;
      if (L.length > 60) {
        var W = {}, order = [];
        L.forEach(function (x) { var d = new Date(x[0] + 'T12:00:00'), wd = (d.getDay() + 6) % 7; d.setDate(d.getDate() - wd); var k = d.toISOString().slice(0, 10);
          if (!W[k]) { W[k] = [k, 0, 0, 0, 0]; order.push(k); } W[k][1] += x[1]; W[k][2] += x[2]; if (x[3] != null) { W[k][3] += x[3]; W[k][4]++; } });
        pts = order.map(function (k) { var w = W[k]; return [w[0], w[1], w[2], w[4] ? w[3] / w[4] : null, null, null, false, true]; });
      }
      var labels = pts.map(function (x) { return (x[7] ? 'tydz. od ' : '') + dfmt(x[0]) + (x[6] ? ' (dziś)' : ''); });
      var tick = '#9aa7b4', grid = 'rgba(255,255,255,.06)';
      if (chart) chart.destroy();
      if (typeof Chart !== 'undefined' && cv) chart = new Chart(cv, {
        data: { labels: labels, datasets: [
          { type: 'bar', label: pts.length && pts[0][7] ? 'Odszronienia w tygodniu' : 'Odszronienia na dobę', data: pts.map(function (x) { return x[1]; }), backgroundColor: pts.map(function (x) { return x[6] ? 'rgba(249,115,22,.45)' : '#f97316'; }), yAxisID: 'y', order: 2 },
          { type: 'line', label: 'Średnia temperatura na zewnątrz (prawa oś)', data: pts.map(function (x) { return x[3]; }), borderColor: '#5794f2', backgroundColor: '#5794f2', borderWidth: 2, pointRadius: 2, tension: 0.3, yAxisID: 'y2', order: 1, spanGaps: true }
        ] },
        options: { responsive: true, maintainAspectRatio: false, animation: false, interaction: { mode: 'index', intersect: false },
          scales: { x: { ticks: { color: tick, maxTicksLimit: 12 }, grid: { color: grid } },
            y: { beginAtZero: true, title: { display: true, text: 'Odszronienia', color: tick }, ticks: { color: tick, precision: 0 }, grid: { color: grid } },
            y2: { position: 'right', title: { display: true, text: 'Na zewnątrz [°C]', color: tick }, ticks: { color: tick }, grid: { drawOnChartArea: false } } },
          plugins: { legend: { position: 'bottom', labels: { color: '#e6edf3', boxWidth: 12, boxHeight: 12, padding: 14 } },
            tooltip: { callbacks: { label: function (c) { var x = pts[c.dataIndex];
              if (c.datasetIndex === 1) return ' Na zewnątrz: ' + sg(x[3]) + ' °C' + (x[4] != null ? ', wilgotność ' + x[4] + ' %' : '');
              return ' ' + x[1] + ' odszronień, razem ' + nf(x[2], 0) + ' min' + (x[5] ? ', sprężarka ' + nf(x[5], 1) + ' h' : ''); } } } } }
      });
      /* tabela według średniej temperatury doby – tylko pełne doby */
      tb.innerHTML = '';
      var any = false;
      B.forEach(function (b) {
        var D = full.filter(function (x) { return x[3] != null && x[3] >= b[0] && x[3] < b[1]; });
        if (!D.length) return; any = true;
        var c = D.reduce(function (a, x) { return a + x[1]; }, 0), mm = D.reduce(function (a, x) { return a + x[2]; }, 0);
        var R = D.filter(function (x) { return x[5]; }), rh = R.reduce(function (a, x) { return a + x[5]; }, 0), cr = R.reduce(function (a, x) { return a + x[1]; }, 0);
        var H = D.filter(function (x) { return x[4] != null; }), hum = H.length ? H.reduce(function (a, x) { return a + x[4]; }, 0) / H.length : null;
        var tr = document.createElement('tr');
        [b[2], String(D.length), nf(c / D.length, 1), nf(mm / D.length, 0) + ' min', nf(c ? mm / c : null, 1) + ' min', rh > 0 ? nf(cr / rh, 2) : '–', hum != null ? nf(hum, 0) + ' %' : '–']
          .forEach(function (v, i) { var td = document.createElement(i ? 'td' : 'th'); if (!i) td.scope = 'row'; td.textContent = v; tr.appendChild(td); });
        tb.appendChild(tr);
      });
      if (!any) tb.innerHTML = '<tr><td colspan="7">Za mało pełnych dób w tym zakresie.</td></tr>';
    }
    box.querySelectorAll('[data-dr]').forEach(function (b) {
      b.addEventListener('click', function () { box.querySelectorAll('[data-dr]').forEach(function (x) { x.classList.toggle('on', x === b); }); render(+b.getAttribute('data-dr')); });
    });
    getJSON(SDL.api + 'defrost_stats?days=400').then(function (d) { ALL = (d && d.days) || []; render(7); }).catch(function () {});
  }
  function start() { document.querySelectorAll('[data-sdl-defrost]').forEach(function (r) { init(r); initLong(r); }); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
