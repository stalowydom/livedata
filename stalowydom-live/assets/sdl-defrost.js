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
  function init(root) {
    var dev = root.getAttribute('data-sdl-defrost');
    var set = function (k, v) { var el = root.querySelector('[data-f="' + k + '"]'); if (el) el.textContent = v; };
    fetch(SDL.api + 'events?device=' + dev + '&limit=10', { credentials: 'omit' }).then(function (r) { return r.json(); }).then(function (ev) {
      if (!ev || !ev.length) return;
      var today = new Date(); today.setHours(0, 0, 0, 0);
      set('today', String(ev.filter(function (e) { return e.ts * 1000 >= today.getTime(); }).length));
      var durs = ev.map(function (e) { return e.values.duration_min; }).filter(function (x) { return x != null; });
      set('avg', durs.length ? nf(durs.reduce(function (a, b) { return a + b; }, 0) / durs.length, 1) : '–');
      set('last', when(ev[0].ts - (ev[0].values.duration_min || 0) * 60));
      set('story', story(ev[0]));
      var tb = root.querySelector('[data-f="rows"]'); tb.innerHTML = '';
      ev.forEach(function (e) {
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
  function start() { document.querySelectorAll('[data-sdl-defrost]').forEach(init); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
