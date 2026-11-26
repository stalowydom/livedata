/* [sdl_cop_pomiar] – COP każdego cyklu w funkcji temperatury zewnętrznej + katalog Heiko */
(function () {
  var nf = function (v, d) { return v == null || isNaN(v) ? '–' : Number(v).toLocaleString('pl-PL', { minimumFractionDigits: d, maximumFractionDigits: d }); };
  function get(url, tries) {
    return fetch(url, { credentials: 'omit' }).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .catch(function (e) { if ((tries || 0) >= 2) throw e; return new Promise(function (res) { setTimeout(res, 800); }).then(function () { return get(url, (tries || 0) + 1); }); });
  }
  function init(root) {
    var cv = root.querySelector('[data-cop="chart"]'); if (!cv || typeof Chart === 'undefined') return;
    get(SDLCOP.api).then(function (D) {
      var pt = function (k) { return D.cycles.filter(function (c) { return c.kind === k; }).map(function (c) { return { x: c.tout, y: c.cop, c: c }; }); };
      var cat = function (k) { return D.catalog[k].map(function (p) { return { x: p[0], y: p[1] }; }); };
      var grid = 'rgba(255,255,255,.06)', tick = '#9aa7b4';
      new Chart(cv, {
        data: { datasets: [
          { type: 'scatter', label: 'Grzanie domu – cykl', data: pt(0), backgroundColor: '#f97316cc', borderColor: '#f97316', pointRadius: 4, pointHoverRadius: 7 },
          { type: 'scatter', label: 'Ciepła woda – cykl', data: pt(1), backgroundColor: '#38bdf8aa', borderColor: '#38bdf8', pointRadius: 4, pointHoverRadius: 7 },
          { type: 'line', label: 'Katalog Heiko – woda 35 °C', data: cat('w35'), borderColor: '#e5e7eb', backgroundColor: '#e5e7eb', borderWidth: 2, pointRadius: 3, tension: 0.2 },
          { type: 'line', label: 'Katalog Heiko – woda 55 °C', data: cat('w55'), borderColor: '#7c8794', backgroundColor: '#7c8794', borderWidth: 2, borderDash: [6, 4], pointRadius: 3, tension: 0.2 }
        ] },
        options: {
          responsive: true, maintainAspectRatio: false, animation: false,
          scales: {
            x: { type: 'linear', min: -16, max: 13, title: { display: true, text: 'Temperatura na zewnątrz [°C]', color: tick }, ticks: { color: tick }, grid: { color: grid } },
            y: { min: 0, suggestedMax: 6.5, title: { display: true, text: 'COP', color: tick }, ticks: { color: tick }, grid: { color: grid } }
          },
          plugins: {
            legend: { position: 'bottom', labels: { color: '#e6edf3', boxWidth: 12, boxHeight: 12, padding: 14 } },
            tooltip: { callbacks: { label: function (c) {
              var r = c.raw;
              if (!r.c) return ' ' + c.dataset.label + ': COP ' + nf(r.y, 2) + ' przy ' + nf(r.x, 0) + ' °C';
              var d = new Date(r.c.ts * 1000).toLocaleString('pl-PL', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
              var l = [' ' + d + ': COP ' + nf(r.y, 2) + ' przy ' + nf(r.x, 1) + ' °C'];
              if (r.c.tb != null) l.push(' Bufor ' + nf(r.c.tb, 1) + ' → ' + nf(r.c.te, 1) + ' °C, prąd ' + nf(r.c.el, 2) + ' kWh');
              return l;
            } } }
          }
        }
      });
    }).catch(function () {});
  }
  function start() { document.querySelectorAll('.sdl-cop-m').forEach(init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
