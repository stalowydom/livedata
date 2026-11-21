<?php
/*
Plugin Name: StalowyDOM Live Data
Description: Odbiera odczyty z Home Assistant i pokazuje je na stronie jako kafelki i wykresy. Shortcode: [sdl_heiko].
Version: 1.2.1
Author: StalowyDOM
Requires PHP: 7.4
*/

if (!defined('ABSPATH')) exit;

define('SDL_VER', '1.2.1');
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
      <svg class="sdl-tank" viewBox="0 0 200 300" role="img" aria-label="<?php echo esc_attr($a['tank']); ?> – temperatury warstw">
        <defs><clipPath id="sdl-clip-<?php echo esc_attr($a['device']); ?>"><rect x="51" y="31" width="78" height="228" rx="37"/></clipPath></defs>
        <g clip-path="url(#sdl-clip-<?php echo esc_attr($a['device']); ?>)">
          <rect data-layer="noel_top" x="51" y="31" width="78" height="76" fill="#3a4654"/>
          <rect data-layer="noel_mid" x="51" y="107" width="78" height="76" fill="#3a4654"/>
          <rect data-layer="noel_bot" x="51" y="183" width="78" height="76" fill="#3a4654"/>
        </g>
        <rect x="50" y="30" width="80" height="230" rx="38" fill="none" stroke="#9aa5b1" stroke-width="2"/>
        <text x="140" y="74" class="sdl-t"><tspan data-t="noel_top">–</tspan>°</text>
        <text x="140" y="150" class="sdl-t"><tspan data-t="noel_mid">–</tspan>°</text>
        <text x="140" y="226" class="sdl-t"><tspan data-t="noel_bot">–</tspan>°</text>
        <text x="0" y="18" class="sdl-ts">Zasilanie z PC: <tspan data-t="supply">–</tspan>°C</text>
        <text x="90" y="290" text-anchor="middle" class="sdl-ts"><?php echo esc_html($a['tank']); ?></text>
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

function sdl_remote_release($force = false) {
    $rel = $force ? false : get_site_transient('sdl_release');
    if ($rel === false) {
        $res = wp_remote_get('https://api.github.com/repos/' . SDL_REPO . '/releases/latest', [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'stalowydom-live'],
        ]);
        $rel = ['version' => null];
        if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) {
            $j = json_decode(wp_remote_retrieve_body($res), true);
            $zip = null;
            foreach (($j['assets'] ?? []) as $a) {
                if (($a['name'] ?? '') === 'stalowydom-live.zip') $zip = $a['browser_download_url'];
            }
            if ($zip) {
                $rel = ['version' => ltrim((string) $j['tag_name'], 'vV'), 'zip' => $zip, 'url' => $j['html_url'] ?? '', 'notes' => $j['body'] ?? ''];
            }
        }
        set_site_transient('sdl_release', $rel, 6 * HOUR_IN_SECONDS);
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
        <tr><th>Ostatni odczyt (UTC)</th><td><?php echo $last ? esc_html($last) : 'brak – HA jeszcze nic nie wysłał'; ?></td></tr>
        <tr><th>Shortcode</th><td><code>[sdl_heiko]</code></td></tr>
      </table>
      <form method="post"><?php wp_nonce_field('sdl_regen'); ?>
        <p><button class="button" name="sdl_regen" value="1" onclick="return confirm('Stary token przestanie działać. Kontynuować?')">Wygeneruj nowy token</button></p>
      </form>
    </div>
    <?php
}
