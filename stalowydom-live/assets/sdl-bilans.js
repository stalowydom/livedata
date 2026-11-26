/* [sdl_bilans] – wykres: dobowe zużycie prądu pompy w funkcji temperatury zewnętrznej, sezon po sezonie */
(function () {
  var nf = function (v, d) { return v == null || isNaN(v) ? '–' : Number(v).toLocaleString('pl-PL', { minimumFractionDigits: d, maximumFractionDigits: d }); };
  var COLORS = ['#94a3b8', '#f97316', '#3b82f6', '#22c55e'];
  function get(url, tries) {
    return fetch(url, { credentials: 'omit' }).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .catch(function (e) { if ((tries || 0) >= 2) throw e; return new Promise(function (res) { setTimeout(res, 700); }).then(function () { return get(url, (tries || 0) + 1); }); });
  }
  function scatter(cv, points, fits, yTitle, unit, yZero) {
      var seasons = [];
      points.forEach(function (p) { if (seasons.indexOf(p[3]) < 0) seasons.push(p[3]); });
      var ds = [];
      seasons.forEach(function (se, i) {
        var col = COLORS[Math.min(i, COLORS.length - 1) + (seasons.length === 1 ? 1 : 0)];
        var label = 'Sezon ' + se + (i < seasons.length - 1 ? ' (przed murkiem)' : ' (po murku)');
        ds.push({ type: 'scatter', label: label, data: points.filter(function (p) { return p[3] === se && p[1] <= 16; }).map(function (p) { return { x: p[1], y: p[2], d: p[0] }; }),
          backgroundColor: col + 'aa', borderColor: col, pointRadius: 3.5, pointHoverRadius: 6 });
        var f = fits[se];
        if (f) ds.push({ type: 'line', label: 'Trend ' + se, data: [{ x: f.tmin, y: f.a + f.b * f.tmin }, { x: f.tmax, y: f.a + f.b * f.tmax }],
          borderColor: col, borderWidth: 2.5, pointRadius: 0, fill: false, borderDash: i < seasons.length - 1 ? [6, 4] : [] });
      });
      new Chart(cv, {
        data: { datasets: ds },
        options: {
          responsive: true, maintainAspectRatio: false, animation: false,
          scales: {
            x: { type: 'linear', title: { display: true, text: 'Średnia temperatura zewnętrzna doby [°C]', color: '#9aa7b4' }, ticks: { color: '#9aa7b4' }, grid: { color: 'rgba(255,255,255,.06)' } },
            y: { beginAtZero: yZero, title: { display: true, text: yTitle, color: '#9aa7b4' }, ticks: { color: '#9aa7b4' }, grid: { color: 'rgba(255,255,255,.06)' } }
          },
          plugins: {
            legend: { labels: { color: '#e6edf3', filter: function (it) { return it.text.indexOf('Trend') !== 0; } } },
            tooltip: { callbacks: { label: function (c) {
              if (c.dataset.type === 'line') return c.dataset.label;
              return new Date(c.raw.d + 'T12:00:00').toLocaleDateString('pl-PL') + ': ' + nf(c.raw.x, 1) + ' °C na zewnątrz, ' + nf(c.raw.y, 1) + ' ' + unit;
            } } }
          }
        }
      });
  }
  function init(root) {
    if (!window.Chart) return;
    get(SDL.api + 'seasons').then(function (d) {
      var c1 = root.querySelector('[data-b="chart"]'), c2 = root.querySelector('[data-b="chart-mat"]');
      if (c1) scatter(c1, d.points, d.fits, 'Prąd pobrany przez pompę [kWh/dobę]', 'kWh', true);
      if (c2 && d.mat_points) scatter(c2, d.mat_points, d.mat_fits || {}, 'Woda do mat sufitowych [°C]', '°C', false);
    }).catch(function () {});
  }
  function start() { document.querySelectorAll('.sdl-bilans').forEach(init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
