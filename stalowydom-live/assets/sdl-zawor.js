/* [sdl_plan] – zawór mieszający a krzywa pompy (48 h, odczyty co 5 min) */
(function () {
  var nf = function (v, d) { return v == null || isNaN(v) ? '–' : Number(v).toLocaleString('pl-PL', { minimumFractionDigits: d, maximumFractionDigits: d }); };
  function get(url, tries) {
    return fetch(url, { credentials: 'omit' }).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .catch(function (e) { if ((tries || 0) >= 2) throw e; return new Promise(function (res) { setTimeout(res, 800); }).then(function () { return get(url, (tries || 0) + 1); }); });
  }
  function pts(a) { return (a || []).map(function (p) { return { x: p[0] * 1000, y: p[1] }; }); }
  function init(root) {
    var cv = root.querySelector('[data-zawor="chart"]'); if (!cv || typeof Chart === 'undefined') return;
    Promise.all([get(SDLZAW.maty), get(SDLZAW.heiko)]).then(function (r) {
      var M = r[0], H = r[1], xs = [].concat(M.zawor_temp || [], H.setpoint || []).map(function (p) { return p[0] * 1000; }), x0 = Math.min.apply(null, xs), x1 = Math.max.apply(null, xs), grid = 'rgba(255,255,255,.06)', tick = '#9aa7b4';
      var fmt = function (ms) { return new Date(ms).toLocaleString('pl-PL', { weekday: 'short', hour: '2-digit', minute: '2-digit' }); };
      new Chart(cv, {
        type: 'line',
        data: { datasets: [
          { label: 'Zawór – nastawa', data: pts(M.zawor_zadana), borderColor: '#f2cc0c', borderDash: [8, 5], borderWidth: 2, stepped: true, pointRadius: 0, yAxisID: 'y' },
          { label: 'Za zaworem – rzeczywista', data: pts(M.zawor_temp), borderColor: '#ff9830', borderWidth: 2, pointRadius: 0, tension: 0.2, yAxisID: 'y' },
          { label: 'Pompa – temperatura zadana', data: pts(H.setpoint), borderColor: '#f2495c', borderWidth: 3, stepped: true, pointRadius: 0, yAxisID: 'y' },
          { label: 'Temperatura na zewnątrz (prawa oś)', data: pts(H.outdoor), borderColor: '#5794f2', borderWidth: 2, pointRadius: 0, tension: 0.3, yAxisID: 'y2' }
        ] },
        options: {
          responsive: true, maintainAspectRatio: false, animation: false, parsing: true, spanGaps: true,
          interaction: { mode: 'index', intersect: false },
          scales: {
            x: { type: 'linear', min: x0, max: x1, ticks: { color: tick, maxTicksLimit: 8, callback: function (v) { return fmt(v); } }, grid: { color: grid } },
            y: { position: 'left', suggestedMin: 25, suggestedMax: 50, title: { display: true, text: 'Woda [°C]', color: tick }, ticks: { color: tick }, grid: { color: grid } },
            y2: { position: 'right', title: { display: true, text: 'Na zewnątrz [°C]', color: tick }, ticks: { color: tick }, grid: { drawOnChartArea: false } }
          },
          plugins: {
            legend: { position: 'bottom', labels: { color: '#e6edf3', boxWidth: 12, boxHeight: 12, padding: 14 } },
            tooltip: { callbacks: {
              title: function (it) { return it.length ? fmt(it[0].parsed.x) : ''; },
              label: function (c) { return ' ' + c.dataset.label.replace(' (prawa oś)', '') + ': ' + nf(c.parsed.y, 1) + ' °C'; }
            } }
          }
        }
      });
    }).catch(function () {});
  }
  function start() { document.querySelectorAll('.sdl-zawor').forEach(init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
