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
    getJSON(SDL.api + 'events?device=' + dev + '&limit=10').then(function (ev) {
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
