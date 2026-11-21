<?php
/*
Plugin Name: StalowyDOM Live Data
Description: Odbiera odczyty z Home Assistant i pokazuje je na stronie jako kafelki i wykresy. Shortcode: [sdl_heiko].
Version: 1.4.0
Author: StalowyDOM
Requires PHP: 7.4
*/

if (!defined('ABSPATH')) exit;

define('SDL_VER', '1.4.0');
define('SDL_URL', plugin_dir_url(__FILE__));

function sdl_table() { global $wpdb; return $wpdb->prefix . 'sdl_readings'; }

/* ---------- instalacja ---------- */

register_activation_hook(__FILE__, function () {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = sdl_table();
    $c = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE $t (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        device varchar(32) NOT NULL,
        metric varchar(32) NOT NULL,
        ts datetime NOT NULL,
        value double NOT NULL,
        PRIMARY KEY  (id),
        KEY dmt (device,metric,ts)
    ) $c;");
    if (!get_option('sdl_token')) update_option('sdl_token', wp_generate_password(40, false), false);
    if (!wp_next_scheduled('sdl_cleanup')) wp_schedule_event(time() + 3600, 'daily', 'sdl_cleanup');
});

register_deactivation_hook(__FILE__, function () { wp_clear_scheduled_hook('sdl_cleanup'); });

add_action('sdl_cleanup', function () {
    global $wpdb;
    $t = sdl_table();
    $wpdb->query("DELETE FROM $t WHERE ts < UTC_TIMESTAMP() - INTERVAL 800 DAY");
});

/* przesunięcie strefy czasowej strony względem UTC (sekundy) */
function sdl_tz() {
    /* gdy w WordPressie ustawiono samo przesunięcie UTC (np. UTC+0), liczymy doby wg czasu polskiego */
    $name = get_option('timezone_string');
    return new DateTimeZone($name ? $name : 'Europe/Warsaw');
}
function sdl_offset() {
    return sdl_tz()->getOffset(new DateTime('now', new DateTimeZone('UTC')));
}

function sdl_key_ok($s) { return is_string($s) && preg_match('/^[a-z0-9_]{1,32}$/', $s); }

/* ---------- REST API ---------- */

add_action('rest_api_init', function () {

    register_rest_route('sdl/v1', '/push', [
        'methods'  => 'POST',
        'callback' => 'sdl_push',
        'permission_callback' => function (WP_REST_Request $r) {
            $token = (string) get_option('sdl_token');
            $given = (string) $r->get_header('x-sdl-token');
            return $token !== '' && $given !== '' && hash_equals($token, $given);
        },
    ]);

    /* jednorazowy import historii – tylko zalogowany administrator */
    register_rest_route('sdl/v1', '/backfill', [
        'methods'  => 'POST',
        'callback' => 'sdl_backfill',
        'permission_callback' => function () { return current_user_can('manage_options'); },
    ]);

    $public = function () { return true; };
    register_rest_route('sdl/v1', '/latest', ['methods' => 'GET', 'callback' => 'sdl_latest', 'permission_callback' => $public]);
    register_rest_route('sdl/v1', '/series', ['methods' => 'GET', 'callback' => 'sdl_series', 'permission_callback' => $public]);
    register_rest_route('sdl/v1', '/daily',  ['methods' => 'GET', 'callback' => 'sdl_daily',  'permission_callback' => $public]);
});

function sdl_push(WP_REST_Request $r) {
    global $wpdb;
    $body   = $r->get_json_params();
    $device = $body['device'] ?? '';
    $values = $body['values'] ?? null;
    if (!sdl_key_ok($device) || !is_array($values) || count($values) > 40) {
        return new WP_Error('sdl_bad', 'Nieprawidłowe dane', ['status' => 400]);
    }
    $now = current_time('mysql', true);
    $n = 0;
    foreach ($values as $metric => $v) {
        if (!sdl_key_ok($metric) || !is_numeric($v)) continue;
        $v = (float) $v;
        if (!is_finite($v) || $v <= -90) continue;
        $wpdb->insert(sdl_table(), ['device' => $device, 'metric' => $metric, 'ts' => $now, 'value' => $v], ['%s', '%s', '%s', '%f']);
        $n++;
    }
    return ['ok' => true, 'saved' => $n];
}

/* rows: [[metric, unix_ts, value], ...]
   Import historii: dla każdej metryki usuwa istniejące odczyty z zakresu czasu paczki i wstawia nowe.
   Paczka nie może sięgać ostatnich 6 godzin, żeby nie ruszać danych na żywo. */
function sdl_backfill(WP_REST_Request $r) {
    global $wpdb;
    $t = sdl_table();
    $body   = $r->get_json_params();
    $device = $body['device'] ?? '';
    $rows   = $body['rows'] ?? null;
    if (!sdl_key_ok($device) || !is_array($rows) || count($rows) > 5000) {
        return new WP_Error('sdl_bad', 'Nieprawidłowe dane', ['status' => 400]);
    }
    $limit = time() - 6 * 3600;
    $by = [];
    foreach ($rows as $row) {
        if (!is_array($row) || count($row) !== 3) continue;
        [$metric, $ts, $v] = $row;
        if (!sdl_key_ok($metric) || !is_numeric($ts) || !is_numeric($v)) continue;
        $ts = (int) $ts;
        if ($ts >= $limit || $ts < time() - 400 * 86400) continue;
        $by[$metric][] = [$ts, (float) $v];
    }
    $n = 0;
    foreach ($by as $metric => $list) {
        $min = min(array_column($list, 0));
        $max = max(array_column($list, 0));
        $wpdb->query($wpdb->prepare("DELETE FROM $t WHERE device=%s AND metric=%s AND ts BETWEEN %s AND %s",
            $device, $metric, gmdate('Y-m-d H:i:s', $min), gmdate('Y-m-d H:i:s', $max)));
        foreach ($list as [$ts, $v]) {
            $wpdb->insert($t, ['device' => $device, 'metric' => $metric, 'ts' => gmdate('Y-m-d H:i:s', $ts), 'value' => $v], ['%s', '%s', '%s', '%f']);
            $n++;
        }
    }
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_sdl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_sdl\\_%'");
    return ['ok' => true, 'saved' => $n];
}

function sdl_cached($key, $fn, $ttl = 120) {
    $k = 'sdl_' . md5($key);
    $v = get_transient($k);
    if ($v === false) { $v = $fn(); set_transient($k, $v, $ttl); }
    return $v;
}

function sdl_latest(WP_REST_Request $r) {
    $device = $r->get_param('device');
    if (!sdl_key_ok($device)) return new WP_Error('sdl_bad', 'device', ['status' => 400]);
    return sdl_cached("latest|$device", function () use ($device) {
        global $wpdb;
        $t = sdl_table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.metric, r.value, r.ts FROM $t r
             JOIN (SELECT metric, MAX(id) mid FROM $t WHERE device=%s AND ts > UTC_TIMESTAMP() - INTERVAL 2 DAY GROUP BY metric) m
             ON r.id = m.mid", $device));
        $out = ['values' => [], 'ts' => null];
        foreach ($rows as $row) {
            $out['values'][$row->metric] = (float) $row->value;
            $ts = strtotime($row->ts . ' UTC');
            if ($out['ts'] === null || $ts > $out['ts']) $out['ts'] = $ts;
        }
        /* zużycie dziś (lokalny dzień) z licznika energii */
        $off = sdl_offset();
        $today = $wpdb->get_row($wpdb->prepare(
            "SELECT MAX(value) - MIN(value) AS kwh FROM $t
             WHERE device=%s AND metric='energy_total'
             AND DATE(DATE_ADD(ts, INTERVAL %d SECOND)) = DATE(DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND))",
            $device, $off, $off));
        $out['today_kwh'] = $today && $today->kwh !== null ? round((float) $today->kwh, 2) : null;
        return $out;
    }, 60);
}

function sdl_series(WP_REST_Request $r) {
    $device  = $r->get_param('device');
    $metrics = array_values(array_filter(explode(',', (string) $r->get_param('metrics')), 'sdl_key_ok'));
    $ranges  = ['24h' => [86400, 900], '7d' => [604800, 3600], '30d' => [2592000, 14400]];
    $range   = $r->get_param('range');
    if (!sdl_key_ok($device) || !$metrics || count($metrics) > 8 || !isset($ranges[$range])) {
        return new WP_Error('sdl_bad', 'params', ['status' => 400]);
    }
    [$span, $bucket] = $ranges[$range];
    return sdl_cached("series|$device|" . implode(',', $metrics) . "|$range", function () use ($device, $metrics, $span, $bucket) {
        global $wpdb;
        $t  = sdl_table();
        $in = implode(',', array_fill(0, count($metrics), '%s'));
        $sql = "SELECT metric, FLOOR(TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', ts) / %d) * %d AS t, AVG(value) AS v
                FROM $t WHERE device=%s AND metric IN ($in) AND ts >= UTC_TIMESTAMP() - INTERVAL %d SECOND
                GROUP BY metric, t ORDER BY t";
        $rows = $wpdb->get_results($wpdb->prepare($sql, array_merge([$bucket, $bucket, $device], $metrics, [$span])));
        $out = [];
        foreach ($metrics as $m) $out[$m] = [];
        foreach ($rows as $row) $out[$row->metric][] = [(int) $row->t, round((float) $row->v, 2)];
        return $out;
    }, 120);
}

function sdl_daily(WP_REST_Request $r) {
    $device = $r->get_param('device');
    $metric = $r->get_param('metric');
    $agg    = in_array($r->get_param('agg'), ['avg', 'stats'], true) ? $r->get_param('agg') : 'delta';
    $days   = min(400, max(1, (int) $r->get_param('days') ?: 30));
    if (!sdl_key_ok($device) || !sdl_key_ok($metric)) return new WP_Error('sdl_bad', 'params', ['status' => 400]);
    return sdl_cached("daily|$device|$metric|$agg|$days", function () use ($device, $metric, $agg, $days) {
        global $wpdb;
        $t   = sdl_table();
        $off = sdl_offset();
        $expr = [
            'avg'   => 'AVG(value) AS v',
            'delta' => 'MAX(value) - MIN(value) AS v',
            'stats' => 'AVG(value) AS v, MIN(value) AS vmin, MAX(value) AS vmax',
        ][$agg];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(DATE_ADD(ts, INTERVAL %d SECOND)) AS d, $expr FROM $t
             WHERE device=%s AND metric=%s AND ts >= UTC_TIMESTAMP() - INTERVAL %d DAY
             GROUP BY d ORDER BY d", $off, $device, $metric, $days));
        return array_map(function ($row) use ($agg) {
            $out = [$row->d, round((float) $row->v, 2)];
            if ($agg === 'stats') { $out[] = round((float) $row->vmin, 1); $out[] = round((float) $row->vmax, 1); }
            return $out;
        }, $rows);
    }, 600);
}

/* ---------- shortcode ---------- */

function sdl_enqueue() {
    wp_enqueue_style('sdl', SDL_URL . 'assets/sdl.css', [], SDL_VER);
    wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', [], '4.4.1', true);
    wp_enqueue_script('sdl', SDL_URL . 'assets/sdl.js', ['chartjs'], SDL_VER, true);
    wp_localize_script('sdl', 'SDL', ['api' => esc_url_raw(rest_url('sdl/v1/'))]);
}

add_shortcode('sdl_heiko', function ($atts) {
    $a = shortcode_atts(['device' => 'heiko', 'tank' => 'Bufor NOEL 700 l'], $atts);
    if (!sdl_key_ok($a['device'])) return '';
    sdl_enqueue();
    ob_start(); ?>
<div class="sdl-wrap" data-sdl-device="<?php echo esc_attr($a['device']); ?>">
  <div class="sdl-status"><span class="sdl-dot"></span><span class="sdl-status-text">Ładowanie danych…</span></div>

  <div class="sdl-kpis">
    <div class="sdl-kpi"><div class="sdl-kpi-label">Zużycie dziś</div><div class="sdl-kpi-val"><span data-k="today">–</span> <small>kWh</small></div></div>
    <div class="sdl-kpi"><div class="sdl-kpi-label">Moc teraz</div><div class="sdl-kpi-val"><span data-k="power">–</span> <small data-k="power_unit">W</small></div></div>
    <div class="sdl-kpi"><div class="sdl-kpi-label">Ostatnie 30 dni</div><div class="sdl-kpi-val"><span data-k="d30">–</span> <small>kWh</small></div></div>
    <div class="sdl-kpi"><div class="sdl-kpi-label">Na zewnątrz</div><div class="sdl-kpi-val"><span data-k="outdoor">–</span> <small>°C</small></div></div>
  </div>

  <div class="sdl-row">
    <div class="sdl-card sdl-tank-card">
      <svg class="sdl-tank" viewBox="0 0 330 520" role="img" aria-label="<?php echo esc_attr($a['tank']); ?> – przekrój z wężownicami i temperaturami warstw">
        <defs>
          <linearGradient id="sdl-strip-<?php echo esc_attr($a['device']); ?>" x1="0" y1="0" x2="0" y2="1">
            <stop data-stop="noel_top" offset="0" stop-color="#ef4444"/><stop data-stop="noel_mid" offset="0.5" stop-color="#f97316"/><stop data-stop="noel_bot" offset="1" stop-color="#3b82f6"/>
          </linearGradient>
          <linearGradient id="sdl-body-<?php echo esc_attr($a['device']); ?>" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0" stop-color="#c9ced6"/><stop offset=".35" stop-color="#f4f6f8"/><stop offset="1" stop-color="#ffffff"/>
          </linearGradient>
          <linearGradient id="sdl-copper-<?php echo esc_attr($a['device']); ?>" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#f6b27a"/><stop offset=".5" stop-color="#c8622a"/><stop offset="1" stop-color="#7a3512"/>
          </linearGradient>
          <pattern id="sdl-ins-<?php echo esc_attr($a['device']); ?>" width="5" height="5" patternUnits="userSpaceOnUse"><rect width="5" height="5" fill="#9ca3ab"/><circle cx="1.5" cy="1.5" r=".8" fill="#7d858e"/><circle cx="4" cy="3.8" r=".6" fill="#b7bdc4"/></pattern>
          <clipPath id="sdl-inner-<?php echo esc_attr($a['device']); ?>"><rect x="186" y="62" width="58" height="392" rx="22"/></clipPath>
        </defs>
        <text x="155" y="22" text-anchor="middle" class="sdl-tank-title"><?php echo esc_html($a['tank']); ?></text>
        <ellipse cx="155" cy="492" rx="105" ry="9" fill="#0d141a" opacity=".6"/>
        <rect x="88" y="476" width="10" height="12" fill="#3a4654"/><rect x="212" y="476" width="10" height="12" fill="#3a4654"/>

        <g class="sdl-pipe sdl-hot"><path d="M0 150 H62"/></g>
        <g class="sdl-pipe sdl-cold"><path d="M62 446 H0"/></g>
        <g class="sdl-pipe sdl-hot sdl-thin"><path d="M252 70 H330"/></g>
        <g class="sdl-pipe sdl-cold sdl-thin"><path d="M330 214 H252"/></g>
        <g class="sdl-pipe sdl-warm sdl-thin"><path d="M252 274 H330"/></g>
        <g class="sdl-pipe sdl-cold sdl-thin"><path d="M330 390 H252"/></g>

        <rect x="62" y="40" width="190" height="438" rx="16" fill="url(#sdl-body-<?php echo esc_attr($a['device']); ?>)"/>
        <rect x="150" y="40" width="30" height="438" fill="url(#sdl-strip-<?php echo esc_attr($a['device']); ?>)"/>
        <path d="M180 40 H236 a16 16 0 0 1 16 16 V462 a16 16 0 0 1 -16 16 H180 Z" fill="url(#sdl-ins-<?php echo esc_attr($a['device']); ?>)"/>
        <rect x="186" y="62" width="58" height="392" rx="22" fill="#1d2a36"/>
        <g clip-path="url(#sdl-inner-<?php echo esc_attr($a['device']); ?>)">
          <rect x="186" y="62" width="58" height="392" fill="url(#sdl-strip-<?php echo esc_attr($a['device']); ?>)" opacity=".42"/>
          <g fill="none" stroke="url(#sdl-copper-<?php echo esc_attr($a['device']); ?>)" stroke-width="5.5"><ellipse cx="215" cy="82" rx="23" ry="4.2"/><ellipse cx="215" cy="91" rx="23" ry="4.2"/><ellipse cx="215" cy="100" rx="23" ry="4.2"/><ellipse cx="215" cy="109" rx="23" ry="4.2"/><ellipse cx="215" cy="118" rx="23" ry="4.2"/><ellipse cx="215" cy="127" rx="23" ry="4.2"/><ellipse cx="215" cy="136" rx="23" ry="4.2"/><ellipse cx="215" cy="145" rx="23" ry="4.2"/><ellipse cx="215" cy="154" rx="23" ry="4.2"/><ellipse cx="215" cy="163" rx="23" ry="4.2"/><ellipse cx="215" cy="172" rx="23" ry="4.2"/><ellipse cx="215" cy="181" rx="23" ry="4.2"/><ellipse cx="215" cy="190" rx="23" ry="4.2"/><ellipse cx="215" cy="199" rx="23" ry="4.2"/><ellipse cx="215" cy="208" rx="23" ry="4.2"/><ellipse cx="215" cy="217" rx="23" ry="4.2"/><ellipse cx="215" cy="226" rx="23" ry="4.2"/><ellipse cx="215" cy="235" rx="23" ry="4.2"/><ellipse cx="215" cy="244" rx="23" ry="4.2"/></g>
          <g fill="none" stroke="url(#sdl-copper-<?php echo esc_attr($a['device']); ?>)" stroke-width="5.5"><ellipse cx="215" cy="296" rx="23" ry="4.2"/><ellipse cx="215" cy="305" rx="23" ry="4.2"/><ellipse cx="215" cy="314" rx="23" ry="4.2"/><ellipse cx="215" cy="323" rx="23" ry="4.2"/><ellipse cx="215" cy="332" rx="23" ry="4.2"/><ellipse cx="215" cy="341" rx="23" ry="4.2"/><ellipse cx="215" cy="350" rx="23" ry="4.2"/><ellipse cx="215" cy="359" rx="23" ry="4.2"/><ellipse cx="215" cy="368" rx="23" ry="4.2"/><ellipse cx="215" cy="377" rx="23" ry="4.2"/><ellipse cx="215" cy="386" rx="23" ry="4.2"/><ellipse cx="215" cy="395" rx="23" ry="4.2"/><ellipse cx="215" cy="404" rx="23" ry="4.2"/><ellipse cx="215" cy="413" rx="23" ry="4.2"/><ellipse cx="215" cy="422" rx="23" ry="4.2"/><ellipse cx="215" cy="431" rx="23" ry="4.2"/></g>
        </g>
        <line x1="180" y1="40" x2="180" y2="478" stroke="#e5e7eb" stroke-width="1.5"/>
        <g class="sdl-gauge"><circle cx="165" cy="70" r="11"/><path d="M165 70 L171 63"/></g>
        <g class="sdl-gauge"><circle cx="165" cy="452" r="11"/><path d="M165 452 L159 445"/></g>
        <rect x="252" y="66" width="10" height="8" fill="#cbd5e1"/><rect x="252" y="210" width="10" height="8" fill="#cbd5e1"/>
        <rect x="252" y="270" width="10" height="8" fill="#cbd5e1"/><rect x="252" y="386" width="10" height="8" fill="#cbd5e1"/>

        <circle cx="62" cy="150" r="5" class="sdl-port"/><circle cx="62" cy="446" r="5" class="sdl-port"/>
        <g class="sdl-chip"><rect x="122" y="128" width="86" height="28" rx="14"/><text x="165" y="147" text-anchor="middle"><tspan data-t="noel_top">–</tspan> °C</text></g>
        <g class="sdl-chip"><rect x="122" y="246" width="86" height="28" rx="14"/><text x="165" y="265" text-anchor="middle"><tspan data-t="noel_mid">–</tspan> °C</text></g>
        <g class="sdl-chip"><rect x="122" y="402" width="86" height="28" rx="14"/><text x="165" y="421" text-anchor="middle"><tspan data-t="noel_bot">–</tspan> °C</text></g>
        <text x="2" y="138" class="sdl-ts">z PC</text><text x="2" y="172" class="sdl-ts"><tspan data-t="supply">–</tspan>°C</text>
        <text x="2" y="466" class="sdl-ts">do PC</text>
        <text x="328" y="60" text-anchor="end" class="sdl-ts">CWU</text>
        <text x="328" y="232" text-anchor="end" class="sdl-ts">zimna woda</text>
        <text x="328" y="266" text-anchor="end" class="sdl-ts">maty</text><text x="328" y="380" text-anchor="end" class="sdl-ts">maty</text>
        <text x="328" y="408" text-anchor="end" class="sdl-ts">powrót</text>
      </svg>
    </div>
    <div class="sdl-card sdl-chart-card">
      <div class="sdl-head">
        <h3>Moc pompy ciepła</h3>
        <div class="sdl-range" role="group" aria-label="Zakres czasu">
          <button type="button" data-range="24h" class="on">24 h</button><button type="button" data-range="7d">7 dni</button><button type="button" data-range="30d">30 dni</button>
        </div>
      </div>
      <div class="sdl-canvas"><canvas data-chart="power" role="img" aria-label="Wykres mocy pompy ciepła"></canvas></div>
    </div>
  </div>

  <div class="sdl-card">
    <div class="sdl-head"><h3>Temperatury w buforze</h3></div>
    <div class="sdl-legend">
      <span><i style="background:#ef4444"></i>Góra</span><span><i style="background:#f97316"></i>Środek</span><span><i style="background:#3b82f6"></i>Dół</span><span><i class="dash"></i>Zasilanie z PC</span>
    </div>
    <div class="sdl-canvas"><canvas data-chart="temps" role="img" aria-label="Wykres temperatur w buforze NOEL"></canvas></div>
  </div>

  <div class="sdl-card">
    <div class="sdl-head"><h3>Dobowe zużycie energii – ostatnie 30 dni</h3></div>
    <div class="sdl-canvas"><canvas data-chart="daily" role="img" aria-label="Dobowe zużycie energii pompy ciepła z ostatnich 30 dni"></canvas></div>
    <div class="sdl-legend sdl-legend-temp"><span><i style="background:#f97316"></i>Temperatura zewnętrzna – maks. w ciągu dnia</span><span><i style="background:#3b82f6"></i>min. w ciągu dnia</span></div>
    <div class="sdl-canvas sdl-canvas-sm"><canvas data-chart="dailytemp" role="img" aria-label="Najniższa, średnia i najwyższa temperatura zewnętrzna w kolejnych dniach"></canvas></div>
    <p class="sdl-note">Po najechaniu na słupek widać zużycie oraz najniższą i najwyższą temperaturę zewnętrzną z tego dnia.</p>
  </div>
</div>
<?php
    return ob_get_clean();
});

/* ---------- aktualizacje z GitHuba (Releases) ---------- */

define('SDL_REPO', 'stalowydom/livedata');

/* Najnowsza wersja = nagłówek "Version:" pliku wtyczki na gałęzi main (raw.githubusercontent.com, bez limitów API).
   Paczka = załącznik wydania vX.Y.Z budowany przez GitHub Actions. */
function sdl_remote_release($force = false) {
    $rel = $force ? false : get_site_transient('sdl_release');
    if ($rel === false) {
        $res = wp_remote_get('https://raw.githubusercontent.com/' . SDL_REPO . '/main/stalowydom-live/stalowydom-live.php', [
            'timeout' => 10,
            'headers' => ['User-Agent' => 'stalowydom-live', 'Range' => 'bytes=0-2047'],
        ]);
        $rel = ['version' => null, 'checked' => time()];
        if (is_wp_error($res)) {
            $rel['error'] = $res->get_error_message();
        } elseif (!in_array(wp_remote_retrieve_response_code($res), [200, 206], true)) {
            $rel['error'] = 'HTTP ' . wp_remote_retrieve_response_code($res);
        } elseif (preg_match('/^Version:\s*([0-9.]+)/m', wp_remote_retrieve_body($res), $m)) {
            $v = $m[1];
            $rel = [
                'version' => $v, 'checked' => time(),
                'zip'   => 'https://github.com/' . SDL_REPO . '/releases/download/v' . $v . '/stalowydom-live.zip',
                'url'   => 'https://github.com/' . SDL_REPO . '/releases/tag/v' . $v,
                'notes' => '',
            ];
        } else {
            $rel['error'] = 'Brak numeru wersji w pliku';
        }
        set_site_transient('sdl_release', $rel, empty($rel['version']) ? 30 * MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS);
    }
    return $rel;
}

add_filter('pre_set_site_transient_update_plugins', function ($t) {
    if (!is_object($t) || empty($t->checked)) return $t;
    $file = plugin_basename(__FILE__);
    $rel  = sdl_remote_release();
    $item = (object) ['id' => SDL_REPO, 'slug' => 'stalowydom-live', 'plugin' => $file, 'new_version' => SDL_VER, 'url' => 'https://github.com/' . SDL_REPO, 'package' => ''];
    if (!empty($rel['version']) && version_compare($rel['version'], SDL_VER, '>')) {
        $item->new_version = $rel['version'];
        $item->package = $rel['zip'];
        $t->response[$file] = $item;
    } else {
        $t->no_update[$file] = $item;
    }
    return $t;
});

add_filter('plugins_api', function ($res, $action, $args) {
    if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'stalowydom-live') return $res;
    $rel = sdl_remote_release();
    return (object) [
        'name' => 'StalowyDOM Live Data', 'slug' => 'stalowydom-live',
        'version' => $rel['version'] ?? SDL_VER, 'author' => 'StalowyDOM',
        'homepage' => 'https://github.com/' . SDL_REPO, 'download_link' => $rel['zip'] ?? '',
        'sections' => ['changelog' => nl2br(esc_html($rel['notes'] ?? ''))],
    ];
}, 10, 3);

/* "Sprawdź aktualizacje" w WP od razu pyta GitHuba */
add_action('load-update-core.php', function () { if (!empty($_GET['force-check'])) sdl_remote_release(true); });

/* ---------- ustawienia ---------- */

add_action('admin_menu', function () {
    add_options_page('StalowyDOM Live', 'StalowyDOM Live', 'manage_options', 'sdl', 'sdl_settings_page');
});

function sdl_settings_page() {
    if (!current_user_can('manage_options')) return;
    if (isset($_POST['sdl_regen']) && check_admin_referer('sdl_regen')) {
        update_option('sdl_token', wp_generate_password(40, false), false);
        echo '<div class="notice notice-success"><p>Wygenerowano nowy token. Zaktualizuj go w Home Assistant.</p></div>';
    }
    global $wpdb;
    $t = sdl_table();
    $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t");
    $last  = $wpdb->get_var("SELECT MAX(ts) FROM $t");
    ?>
    <div class="wrap">
      <h1>StalowyDOM Live Data</h1>
      <table class="form-table">
        <tr><th>Adres dla Home Assistant</th><td><code><?php echo esc_html(rest_url('sdl/v1/push')); ?></code></td></tr>
        <tr><th>Token (nagłówek X-SDL-Token)</th><td><input type="text" readonly class="regular-text code" value="<?php echo esc_attr(get_option('sdl_token')); ?>" onclick="this.select()"></td></tr>
        <tr><th>Zapisanych odczytów</th><td><?php echo number_format_i18n($count); ?></td></tr>
        <tr><th>Ostatni odczyt</th><td><?php echo $last ? esc_html(wp_date('Y-m-d H:i', strtotime($last . ' UTC'), sdl_tz())) . ' (czas polski)' : 'brak – HA jeszcze nic nie wysłał'; ?></td></tr>
        <tr><th>Shortcode</th><td><code>[sdl_heiko]</code></td></tr>
        <?php $rel = sdl_remote_release(isset($_GET['sdl_check'])); ?>
        <tr><th>Aktualizacje (GitHub)</th><td>
          Zainstalowana: <strong><?php echo esc_html(SDL_VER); ?></strong> ·
          Najnowsza: <strong><?php echo esc_html($rel['version'] ?? '?'); ?></strong>
          <?php if (!empty($rel['error'])) echo ' · <span style="color:#b32d2e">błąd: ' . esc_html($rel['error']) . '</span>'; ?>
          <?php if (!empty($rel['checked'])) echo ' · sprawdzono ' . esc_html(wp_date('Y-m-d H:i', $rel['checked'], sdl_tz())); ?>
          · <a href="<?php echo esc_url(add_query_arg('sdl_check', 1)); ?>">Sprawdź teraz</a>
        </td></tr>
      </table>
      <form method="post"><?php wp_nonce_field('sdl_regen'); ?>
        <p><button class="button" name="sdl_regen" value="1" onclick="return confirm('Stary token przestanie działać. Kontynuować?')">Wygeneruj nowy token</button></p>
      </form>
    </div>
    <?php
}
