(function () {
  'use strict';
  var C = { accent: '#f97316', grid: '#334150', tick: '#9aa5b1', top: '#ef4444', mid: '#f97316', bot: '#3b82f6', supply: '#e5e7eb' };
  var nf = function (v, d) { return v == null || isNaN(v) ? '–' : Number(v).toLocaleString('pl-PL', { minimumFractionDigits: d, maximumFractionDigits: d }); };
  var get = function (path) { return fetch(SDL.api + path, { credentials: 'omit' }).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); }); };

  var MONTHS = ['styczeń', 'luty', 'marzec', 'kwiecień', 'maj', 'czerwiec', 'lipiec', 'sierpień', 'wrzesień', 'październik', 'listopad', 'grudzień'];
  function monthSums(rows) {
    var now = new Date(), cy = now.getFullYear(), cm = now.getMonth();
    var py = cm === 0 ? cy - 1 : cy, pm = cm === 0 ? 11 : cm - 1;
    var key = function (y, m) { return y + '-' + String(m + 1).padStart(2, '0'); };
    var sum = function (k) { var f = rows.filter(function (x) { return x[0].slice(0, 7) === k; }); return f.length ? f.reduce(function (a, x) { return a + x[1]; }, 0) : null; };
    return { cur: sum(key(cy, cm)), prev: sum(key(py, pm)), curName: MONTHS[cm], prevName: MONTHS[pm] };
  }

  function tempColor(t) {
    if (t == null) return '#3a4654';
    var stops = [[20, [59, 130, 246]], [35, [234, 179, 8]], [45, [249, 115, 22]], [55, [239, 68, 68]]];
    if (t <= stops[0][0]) return 'rgb(' + stops[0][1] + ')';
    for (var i = 1; i < stops.length; i++) {
      if (t <= stops[i][0]) {
        var a = stops[i - 1], b = stops[i], k = (t - a[0]) / (b[0] - a[0]);
        return 'rgb(' + a[1].map(function (c, j) { return Math.round(c + (b[1][j] - c) * k); }).join(',') + ')';
      }
    }
    return 'rgb(' + stops[stops.length - 1][1] + ')';
  }

  function label(ts, range) {
    var d = new Date(ts * 1000);
    return range === '24h'
      ? d.toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' })
      : d.toLocaleDateString('pl-PL', { day: '2-digit', month: '2-digit' }) + (range === '7d' ? ' ' + d.toLocaleTimeString('pl-PL', { hour: '2-digit' }) + ':00' : '');
  }

  function baseOpts(unit) {
    return {
      responsive: true, maintainAspectRatio: false, animation: { duration: 400 },
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return ' ' + (c.dataset.label ? c.dataset.label + ': ' : '') + nf(c.parsed.y, 1) + ' ' + unit; } } } },
      scales: {
        x: { grid: { display: false }, ticks: { color: C.tick, maxTicksLimit: 8, maxRotation: 0 } },
        y: { grid: { color: C.grid }, afterFit: function (s) { s.width = 62; }, ticks: { color: C.tick, callback: function (v) { return v + ' ' + unit; } } }
      }
    };
  }

  var labelPlugin = {
    id: 'sdlLabels',
    afterDatasetsDraw: function (chart) {
      var ctx = chart.ctx;
      chart.data.datasets.forEach(function (ds, i) {
        var cfg = ds.sdlLabels; if (!cfg) return;
        var meta = chart.getDatasetMeta(i); if (meta.hidden) return;
        var n = meta.data.length; if (!n) return;
        var step = chart.chartArea.width / n;
        var size = step < 22 ? 0 : step < 34 ? 10 : 12;
        if (!size) return;
        ctx.save();
        ctx.font = '600 ' + size + 'px ' + Chart.defaults.font.family;
        ctx.fillStyle = '#e5e7eb'; ctx.textAlign = 'center';
        meta.data.forEach(function (el, j) {
          var v = ds.data[j]; if (v == null || isNaN(v)) return;
          var below = cfg.pos === 'below';
          ctx.textBaseline = below ? 'top' : 'bottom';
          ctx.fillText(nf(v, cfg.d) + cfg.unit, el.x, el.y + (below ? 6 : -6));
        });
        ctx.restore();
      });
    }
  };

  function init(root) {
    var device = root.getAttribute('data-sdl-device');
    var charts = {};
    var range = '24h';
    var $ = function (s) { return root.querySelector(s); };
    var setK = function (k, v) { var el = $('[data-k="' + k + '"]'); if (el) el.textContent = v; };

    function loadLatest() {
      return get('latest?device=' + device).then(function (d) {
        var v = d.values || {};
        var txt = $('.sdl-status-text');
        if (!d.ts) { txt.textContent = 'Brak danych – czekam na pierwszy odczyt z Home Assistant'; return; }
        var ageMin = (Date.now() / 1000 - d.ts) / 60;
        var time = new Date(d.ts * 1000).toLocaleTimeString('pl-PL', { hour: '2-digit', minute: '2-digit' });
        root.classList.toggle('is-live', ageMin < 20);
        txt.textContent = ageMin < 20 ? 'Na żywo · ostatni odczyt ' + time : 'Ostatni odczyt ' + new Date(d.ts * 1000).toLocaleString('pl-PL');
        setK('today', nf(d.today_kwh, 1));
        if (v.power != null) {
          if (v.power >= 1000) { setK('power', nf(v.power / 1000, 2)); setK('power_unit', 'kW'); }
          else { setK('power', nf(v.power, 0)); setK('power_unit', 'W'); }
        }
        setK('outdoor', nf(v.outdoor, 1));
        ['noel_top', 'noel_mid', 'noel_bot', 'supply'].forEach(function (m) {
          var t = root.querySelector('[data-t="' + m + '"]'); if (t) t.textContent = nf(v[m], 1);
          var stop = root.querySelector('[data-stop="' + m + '"]'); if (stop && v[m] != null) stop.setAttribute('stop-color', tempColor(v[m]));
        });
        root.classList.toggle('is-running', v.power != null && v.power > 300);
      });
    }

    function draw(key, cfg) {
      if (charts[key]) charts[key].destroy();
      charts[key] = new Chart(root.querySelector('[data-chart="' + key + '"]'), cfg);
    }

    function loadSeries() {
      return get('series?device=' + device + '&range=' + range + '&metrics=power,noel_top,noel_mid,noel_bot,supply').then(function (s) {
        var p = s.power || [];
        var o = baseOpts('kW');
        o.scales.y.beginAtZero = true;
        draw('power', { type: 'line', options: o, data: {
          labels: p.map(function (x) { return label(x[0], range); }),
          datasets: [{ data: p.map(function (x) { return x[1] / 1000; }), borderColor: C.accent, backgroundColor: 'rgba(249,115,22,.15)', fill: true, borderWidth: 2, pointRadius: 0, tension: .3 }]
        } });

        var times = {};
        ['noel_top', 'noel_mid', 'noel_bot', 'supply'].forEach(function (m) { (s[m] || []).forEach(function (x) { times[x[0]] = 1; }); });
        var ts = Object.keys(times).map(Number).sort(function (a, b) { return a - b; });
        var series = function (m) { var map = {}; (s[m] || []).forEach(function (x) { map[x[0]] = x[1]; }); return ts.map(function (t) { return map[t] != null ? map[t] : null; }); };
        var ds = function (m, name, color, dash) { return { label: name, data: series(m), borderColor: color, borderWidth: 2, pointRadius: 0, tension: .3, spanGaps: true, borderDash: dash || [] }; };
        draw('temps', { type: 'line', options: baseOpts('°C'), data: {
          labels: ts.map(function (t) { return label(t, range); }),
          datasets: [ds('noel_top', 'Góra', C.top), ds('noel_mid', 'Środek', C.mid), ds('noel_bot', 'Dół', C.bot), ds('supply', 'Zasilanie z PC', C.supply, [5, 4])]
        } });
      });
    }

    function loadDaily() {
      return Promise.all([
        get('daily?device=' + device + '&metric=energy_total&days=30'),
        get('daily?device=' + device + '&metric=outdoor&agg=stats&days=30')
      ]).then(function (r) {
        var kwh = r[0].slice(), out = {};
        while (kwh.length && !kwh[0][1]) kwh.shift(); /* bez pustych dni na początku historii */
        r[1].forEach(function (x) { out[x[0]] = { avg: x[1], min: x[2], max: x[3] }; });
        var sum = kwh.reduce(function (a, x) { return a + x[1]; }, 0);
        get('daily?device=' + device + '&metric=energy_total&days=62').then(function (rr) {
          var m = monthSums(rr);
          setK('m_cur', nf(m.cur, 0)); setK('m_prev', nf(m.prev, 0));
          setK('m_cur_name', m.curName.charAt(0).toUpperCase() + m.curName.slice(1)); setK('m_prev_name', m.prevName.charAt(0).toUpperCase() + m.prevName.slice(1));
        }).catch(function () {});
        var yd = new Date(); yd.setDate(yd.getDate() - 1);
        var ykey = yd.getFullYear() + '-' + String(yd.getMonth() + 1).padStart(2, '0') + '-' + String(yd.getDate()).padStart(2, '0');
        var yrow = kwh.filter(function (x) { return x[0] === ykey; })[0];
        setK('yesterday', yrow ? nf(yrow[1], 1) : '–');
        var days = kwh.map(function (x) { return x[0]; });
        var labels = days.map(function (d) { var p = d.split('-'); return p[2] + '.' + p[1]; });
        var tempLines = function (i) {
          var t = out[days[i]];
          return t ? [' Na zewnątrz: min ' + nf(t.min, 1) + ' °C · maks. ' + nf(t.max, 1) + ' °C'] : [];
        };
        var kwhLine = function (i) { return ' Zużycie: ' + nf(kwh[i][1], 1) + ' kWh'; };

        var o = baseOpts('kWh');
        o.plugins.tooltip.callbacks.label = function (c) { return kwhLine(c.dataIndex); };
        o.plugins.tooltip.callbacks.afterLabel = function (c) { return tempLines(c.dataIndex); };
        o.scales.y.beginAtZero = true;
        o.layout = { padding: { top: 18 } };
        draw('daily', { type: 'bar', options: o, data: {
          labels: labels,
          datasets: [{ data: kwh.map(function (x) { return x[1]; }), backgroundColor: C.accent, borderRadius: 4, maxBarThickness: 34, sdlLabels: { unit: '', pos: 'above', d: 1 } }]
        } });

        var ot = baseOpts('°C');
        ot.plugins.tooltip.callbacks.label = function (c) { return c.datasetIndex === 0 ? tempLines(c.dataIndex) : null; };
        ot.plugins.tooltip.callbacks.afterLabel = function (c) { return c.datasetIndex === 0 ? kwhLine(c.dataIndex) : ''; };
        ot.plugins.tooltip.filter = function (item) { return item.datasetIndex === 0; };
        ot.scales.y.ticks.maxTicksLimit = 5;
        ot.layout = { padding: { top: 16, bottom: 4 } };
        var pt = function (k) { return days.map(function (d) { var t = out[d]; return t ? t[k] : null; }); };
        draw('dailytemp', { type: 'line', options: ot, data: {
          labels: labels,
          datasets: [
            { label: 'Maks.', data: pt('max'), borderColor: C.mid, backgroundColor: C.mid, borderWidth: 2, pointRadius: 3, tension: 0, spanGaps: true, sdlLabels: { unit: '°', pos: 'above', d: 1 } },
            { label: 'Min.', data: pt('min'), borderColor: C.bot, backgroundColor: C.bot, borderWidth: 2, pointRadius: 3, tension: 0, spanGaps: true, sdlLabels: { unit: '°', pos: 'below', d: 1 } }
          ]
        } });
      });
    }

    root.querySelectorAll('[data-range]').forEach(function (b) {
      b.addEventListener('click', function () {
        range = b.getAttribute('data-range');
        root.querySelectorAll('[data-range]').forEach(function (x) { x.classList.toggle('on', x === b); });
        loadSeries().catch(function () {});
      });
    });

    var fail = function () { $('.sdl-status-text').textContent = 'Nie udało się pobrać danych'; };
    loadLatest().catch(fail);
    loadSeries().catch(function () {});
    loadDaily().catch(function () {});
    setInterval(function () { loadLatest().catch(fail); if (range === '24h') loadSeries().catch(function () {}); }, 300000);
  }

  function start() {
    if (typeof Chart === 'undefined' || typeof SDL === 'undefined') return;
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.register(labelPlugin);
    document.querySelectorAll('.sdl-wrap[data-sdl-device]').forEach(init);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
