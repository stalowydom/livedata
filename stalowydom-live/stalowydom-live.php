<?php
/*
Plugin Name: StalowyDOM Live Data
Description: Odbiera odczyty z Home Assistant i pokazuje je na stronie jako kafelki i wykresy. Shortcode: [sdl_heiko].
Version: 1.51.16
Author: StalowyDOM
Requires PHP: 7.4
*/

if (!defined('ABSPATH')) exit;

define('SDL_VER', '1.51.16');
define('SDL_URL', plugin_dir_url(__FILE__));

function sdl_table() { global $wpdb; return $wpdb->prefix . 'sdl_readings'; }

/* ---------- Divi: szablony nagłówka/stopki i formularze WPForms edytowalne przez REST (tylko administrator) ---------- */

add_action('rest_api_init', function () {
    register_rest_route('sdl/v1', '/layout/(?P<id>\d+)', [
        'methods'             => ['GET', 'POST'],
        'permission_callback' => function () { return current_user_can('edit_theme_options'); },
        'callback'            => function (WP_REST_Request $r) {
            $p = get_post((int) $r['id']);
            if (!$p || !in_array($p->post_type, ['et_header_layout', 'et_footer_layout', 'et_body_layout', 'wpforms'], true)) {
                return new WP_Error('sdl_not_layout', 'To nie jest szablon Divi ani formularz.', ['status' => 404]);
            }
            if ($r->get_method() === 'POST') {
                $c = $r->get_param('content');
                if (!is_string($c) || $c === '') return new WP_Error('sdl_empty', 'Brak treści.', ['status' => 400]);
                $res = wp_update_post(['ID' => $p->ID, 'post_content' => wp_slash($c)], true);
                if (is_wp_error($res)) return $res;
                clean_post_cache($p->ID);
                $p = get_post($p->ID);
            }
            return ['id' => $p->ID, 'type' => $p->post_type, 'title' => $p->post_title, 'content' => $p->post_content];
        },
    ]);
});

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

    /* statystyki kodów QR – dla Home Assistant (ten sam token co przy wysyłaniu odczytów) */
    register_rest_route('sdl/v1', '/qr', [
        'methods'  => 'GET',
        'callback' => function () { return ['codes' => (object) get_option('sdl_qr', [])]; },
        'permission_callback' => function (WP_REST_Request $r) {
            $token = (string) get_option('sdl_token');
            $given = (string) $r->get_header('x-sdl-token');
            return current_user_can('manage_options') || ($token !== '' && $given !== '' && hash_equals($token, $given));
        },
    ]);

    /* zerowanie liczników QR – tylko administrator; bez parametru "code" zeruje wszystkie */
    register_rest_route('sdl/v1', '/qr/reset', [
        'methods'  => 'POST',
        'callback' => function (WP_REST_Request $r) {
            $all = get_option('sdl_qr', []);
            $code = sanitize_key((string) $r->get_param('code'));
            $codes = $code !== '' ? [$code] : array_keys($all);
            foreach ($codes as $c) { unset($all[$c]); delete_transient('sdl_qr_seen_' . $c); }
            update_option('sdl_qr', $all, false);
            return ['reset' => $codes, 'codes' => (object) $all];
        },
        'permission_callback' => function () { return current_user_can('manage_options'); },
    ]);

    $public = function () { return true; };
    register_rest_route('sdl/v1', '/latest', ['methods' => 'GET', 'callback' => 'sdl_latest', 'permission_callback' => $public]);
    register_rest_route('sdl/v1', '/series', ['methods' => 'GET', 'callback' => 'sdl_series', 'permission_callback' => $public]);
    register_rest_route('sdl/v1', '/daily',  ['methods' => 'GET', 'callback' => 'sdl_daily',  'permission_callback' => $public]);
    register_rest_route('sdl/v1', '/events', ['methods' => 'GET', 'callback' => 'sdl_events', 'permission_callback' => $public]);
});

function sdl_push(WP_REST_Request $r) {
    global $wpdb;
    $body   = $r->get_json_params();
    $device = $body['device'] ?? '';
    $values = $body['values'] ?? null;
    if (!sdl_key_ok($device) || !is_array($values) || count($values) > 40) {
        return new WP_Error('sdl_bad', 'Nieprawidłowe dane', ['status' => 400]);
    }
    /* prywatność: danych, których nie publikujemy, nie zapisujemy */
    if ($device === 'pompa_cwu') unset($values['power']);
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

function sdl_events(WP_REST_Request $r) {
    $device = $r->get_param('device');
    $days   = min(31, max(0, (int) $r->get_param('days')));
    $limit  = $days ? 400 : min(50, max(1, (int) $r->get_param('limit') ?: 10));
    if (!sdl_key_ok($device)) return new WP_Error('sdl_bad', 'device', ['status' => 400]);
    return sdl_cached("events|$device|$limit|$days", function () use ($device, $limit, $days) {
        global $wpdb;
        $t = sdl_table();
        $since = $days ? $wpdb->prepare(' AND ts >= UTC_TIMESTAMP() - INTERVAL %d DAY', $days) : '';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT ts, metric, value FROM $t WHERE device=%s$since ORDER BY ts DESC, id DESC LIMIT %d", $device, $limit * 12));
        $ev = [];
        foreach ($rows as $row) {
            if (!isset($ev[$row->ts])) { if (count($ev) >= $limit) break; $ev[$row->ts] = ['ts' => strtotime($row->ts . ' UTC'), 'values' => []]; }
            $ev[$row->ts]['values'][$row->metric] = round((float) $row->value, 2);
        }
        return array_values($ev);
    }, 120);
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
        $out['today_kwh'] = $today && $today->kwh !== null ? round((float) $today->kwh, 4) : null;
        /* zużycie dziś z liczników CO / CWU (licznik z taryfami w HA) */
        foreach (['energy_co' => 'today_co', 'energy_cwu' => 'today_cwu', 'energy_standby' => 'today_standby'] as $m => $k) {
            $v = $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(value) - MIN(value) FROM $t WHERE device=%s AND metric=%s
                 AND DATE(DATE_ADD(ts, INTERVAL %d SECOND)) = DATE(DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND))",
                $device, $m, $off, $off));
            $out[$k] = $v !== null ? round((float) $v, $m === 'energy_standby' ? 4 : 2) : null;
        }
        /* liczba startów wczoraj = najwyższy stan dziennego licznika 'starts' z poprzedniej doby */
        $y = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(value) FROM $t WHERE device=%s AND metric='starts'
             AND DATE(DATE_ADD(ts, INTERVAL %d SECOND)) = DATE(DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND)) - INTERVAL 1 DAY",
            $device, $off, $off));
        $out['starts_yday'] = $y !== null ? (int) round((float) $y) : null;
        foreach (['starts_co', 'starts_cwu'] as $mm) {
            $yy = $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(value) FROM $t WHERE device=%s AND metric=%s
                 AND DATE(DATE_ADD(ts, INTERVAL %d SECOND)) = DATE(DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND)) - INTERVAL 1 DAY",
                $device, $mm, $off, $off));
            $out[$mm . '_yday'] = $yy !== null ? (int) round((float) $yy) : null;
        }
        if ($device === 'heiko') $out = array_merge($out, sdl_heiko_totals());
        return $out;
    }, 60);
}

function sdl_series(WP_REST_Request $r) {
    $device  = $r->get_param('device');
    $metrics = array_values(array_filter(explode(',', (string) $r->get_param('metrics')), 'sdl_key_ok'));
    $ranges  = ['24h' => [86400, 900], '48h' => [172800, 300], '7d' => [604800, 3600], '30d' => [2592000, 14400]];
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
            $out = [$row->d, round((float) $row->v, 4)];
            if ($agg === 'stats') { $out[] = round((float) $row->vmin, 1); $out[] = round((float) $row->vmax, 1); }
            return $out;
        }, $rows);
    }, 600);
}

/* ---------- pompa ciepła: doby od początku roku (zużycie, starty, czas pracy) ----------
   Źródła: bieżące odczyty (energy_total – przyrost w dobie, starts i run_h – największy stan licznika w dobie)
   oraz jednorazowo zaimportowane z historii HA wartości dobowe (kwh_day, starts_day, run_day), które mają pierwszeństwo. */
function sdl_heiko_days($since) {
    return sdl_cached("heiko_days3|$since", function () use ($since) {
        global $wpdb;
        $t = sdl_table(); $off = sdl_offset();
        $q = function ($metric, $expr, $dev = 'heiko') use ($wpdb, $t, $off, $since) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT DATE(DATE_ADD(ts, INTERVAL %d SECOND)) AS d, $expr AS v FROM $t
                 WHERE device=%s AND metric=%s AND ts >= %s - INTERVAL 1 DAY GROUP BY d", $off, $dev, $metric, $since));
            $o = [];
            foreach ($rows as $r) if ($r->d >= $since && $r->v !== null) $o[$r->d] = (float) $r->v;
            return $o;
        };
        $live = ['kwh' => $q('energy_total', 'MAX(value) - MIN(value)'), 'starts' => $q('starts', 'MAX(value)'), 'run' => $q('run_h', 'MAX(value)'), 'tout' => $q('outdoor', 'AVG(value)'), 'mat' => $q('zawor_temp', 'AVG(value)', 'maty')];
        $imp  = ['kwh' => $q('kwh_day', 'MAX(value)'), 'starts' => $q('starts_day', 'MAX(value)'), 'run' => $q('run_day', 'MAX(value)'), 'tout' => $q('tout_day', 'MAX(value)'), 'mat' => $q('mat_day', 'MAX(value)')];
        $days = [];
        foreach (['kwh', 'starts', 'run', 'tout', 'mat'] as $k) {
            foreach ($live[$k] + [] as $d => $v) $days[$d][$k] = $v;
            foreach ($imp[$k] as $d => $v) $days[$d][$k] = $v;      /* import z HA ma pierwszeństwo */
        }
        ksort($days);
        return $days;
    }, 600);
}

function sdl_heiko_totals() {
    $tz = sdl_tz();
    $now = new DateTime('now', $tz);
    $today = $now->format('Y-m-d');
    $days = sdl_heiko_days($now->format('Y') . '-01-01');
    $sum = function ($from, $k) use ($days, $today) { $s = 0; $n = 0; foreach ($days as $d => $v) if ($d >= $from && $d <= $today && isset($v[$k])) { $s += $v[$k]; $n++; } return [$s, $n]; };
    $week = (clone $now)->modify('monday this week')->format('Y-m-d');
    $month = $now->format('Y-m-01');
    $daysIn = function ($from) use ($now, $tz) { return (int) (new DateTime($from, $tz))->diff(new DateTime($now->format('Y-m-d'), $tz))->days + 1; };
    [$ws] = $sum($week, 'starts'); [$ms] = $sum($month, 'starts');
    [$yk] = $sum('0000', 'kwh'); [$ys] = $sum('0000', 'starts'); [$yr] = $sum('0000', 'run');
    $months = [];
    for ($i = 0; $i < 5; $i++) {
        $m = (new DateTime($now->format('Y-m-01'), $tz))->modify("-$i month")->format('Y-m');
        $k = 0; $any = false; $rh = 0; $anyr = false;
        foreach (sdl_heiko_days($m . '-01') as $d => $v) {
            if (substr($d, 0, 7) !== $m) continue;
            if (isset($v['kwh'])) { $k += $v['kwh']; $any = true; }
            if (isset($v['run'])) { $rh += $v['run']; $anyr = true; }
        }
        $months[] = [$m, $any ? round($k, 1) : null, $anyr ? round($rh, 1) : null];
    }
    return [
        'months' => $months,
        'starts_week_avg'  => round($ws / $daysIn($week), 1),
        'starts_month_avg' => round($ms / $daysIn($month), 1),
        'year_kwh'   => round($yk, 1),
        'year_starts'=> (int) round($ys),
        'year_starts_avg' => $days ? round($ys / $daysIn(array_key_first($days)), 1) : null,
        'year_run_h' => round($yr, 1),
        'year_since' => $days ? array_key_first($days) : null,
    ];
}

/* ---------- dane po stronie serwera: dla czytników ekranu, wyszukiwarek i agentów AI (wygląd bez zmian) ---------- */

function sdl_now_latest($dev) {
    static $c = [];
    if (!isset($c[$dev])) {
        $r = new WP_REST_Request('GET', '/sdl/v1/latest');
        $r->set_param('device', $dev);
        $res = sdl_latest($r);
        $c[$dev] = is_array($res) ? $res : ['values' => [], 'ts' => null];
    }
    return $c[$dev];
}

function sdl_now_daily($dev, $metric, $days = 30, $agg = 'delta') {
    $r = new WP_REST_Request('GET', '/sdl/v1/daily');
    foreach (['device' => $dev, 'metric' => $metric, 'days' => $days, 'agg' => $agg] as $k => $v) $r->set_param($k, $v);
    $res = sdl_daily($r);
    return is_array($res) ? $res : [];
}

function sdl_num($v, $d = 1) {
    if ($v === null || !is_numeric($v)) return '–';
    return number_format((float) $v, $d, ',', abs((float) $v) >= 10000 ? "\u{00A0}" : '');
}

function sdl_kwh_txt($kwh) {
    if ($kwh === null) return '–';
    return $kwh < 1 ? sdl_num($kwh * 1000, 0) . ' Wh' : sdl_num($kwh, $kwh < 10 ? 2 : 1) . ' kWh';
}

function sdl_dur_txt($h) {
    if ($h === null) return '–';
    $m = (int) round($h * 60);
    return $m < 60 ? $m . ' min' : intdiv($m, 60) . ' h ' . ($m % 60) . ' min';
}

function sdl_when($ts) {
    return $ts ? wp_date('j.m.Y, H:i', $ts, sdl_tz()) : '–';
}

function sdl_month_sums($rows) {
    $tz = sdl_tz();
    $cur = wp_date('Y-m', null, $tz);
    $prev = wp_date('Y-m', strtotime('first day of last month', current_time('timestamp')), $tz);
    $sum = function ($k) use ($rows) { $f = array_filter($rows, function ($x) use ($k) { return substr($x[0], 0, 7) === $k; }); return $f ? array_sum(array_column($f, 1)) : null; };
    return [$sum($cur), $sum($prev)];
}

function sdl_day_val($rows, $day) { foreach ($rows as $x) if ($x[0] === $day) return $x[1]; return null; }

/* tekstowe podsumowanie pompy ciepła */
function sdl_summary_heiko($dev = 'heiko') {
    $L = sdl_now_latest($dev); $v = $L['values'];
    $tz = sdl_tz();
    $daily = sdl_now_daily($dev, 'energy_total', 62);
    $co = sdl_now_daily($dev, 'energy_co', 30); $cwu = sdl_now_daily($dev, 'energy_cwu', 30);
    $out = sdl_now_daily($dev, 'outdoor', 30, 'stats');
    $yday = wp_date('Y-m-d', strtotime('-1 day', current_time('timestamp')), $tz);
    [$mc, $mp] = sdl_month_sums($daily);
    $t = [];
    $t[] = 'Stan na ' . sdl_when($L['ts']) . ' (czas polski).';
    $t[] = 'Moc pobierana teraz: ' . sdl_num($v['power'] ?? null, 0) . ' W. Temperatura na zewnątrz: ' . sdl_num($v['outdoor'] ?? null) . ' °C.';
    $t[] = 'Zużycie energii dziś: ' . sdl_kwh_txt($L['today_kwh'] ?? null)
        . (isset($L['today_co'], $L['today_cwu']) ? ' (ogrzewanie CO ' . sdl_num($L['today_co']) . ' kWh, ciepła woda CWU ' . sdl_num($L['today_cwu']) . ' kWh)' : '')
        . '; wczoraj: ' . sdl_kwh_txt(sdl_day_val($daily, $yday)) . '.';
    $t[] = 'Bieżący miesiąc (do dziś): ' . sdl_num($mc, 0) . ' kWh; poprzedni miesiąc: ' . sdl_num($mp, 0) . ' kWh.';
    if (isset($v['noel_top'])) $t[] = 'Bufor NOEL 700 l – góra ' . sdl_num($v['noel_top']) . ' °C, środek ' . sdl_num($v['noel_mid'] ?? null) . ' °C, dół ' . sdl_num($v['noel_bot'] ?? null) . ' °C.';
    $F = sdl_frost($v['outdoor'] ?? null, $v['rh_out'] ?? null, $v['tp'] ?? null, ($v['power'] ?? 0) > 300);
    if ($F) $t[] = 'Warunki szronienia: wilgotność na zewnątrz ' . sdl_num($F['rh'], 0) . ' %, woda w powietrzu ' . sdl_num($F['ah']) . ' g/m³, punkt rosy ' . sdl_num($F['dew']) . ' °C, punkt szronu ' . sdl_num($F['frost']) . ' °C, parownik ' . sdl_num($F['ts']) . ' °C' . ($F['est'] ? ' (szacunek)' : '') . ', potencjał szronienia ' . sdl_num($F['pot']) . ' g/m³ (' . sdl_frost_level($F['pot']) . ').';
    if (isset($L['year_kwh'])) $t[] = 'Starty na dobę: średnio ' . sdl_num($L['starts_week_avg'] ?? null) . ' w tym tygodniu i ' . sdl_num($L['starts_month_avg'] ?? null) . ' w tym miesiącu; wczoraj ' . sdl_num($L['starts_yday'] ?? null, 0) . '. Od ' . esc_html($L['year_since'] ?? '') . ': zużycie ' . sdl_num($L['year_kwh'], 0) . ' kWh, ' . sdl_num($L['year_starts'] ?? null, 0) . ' startów (średnio ' . sdl_num($L['year_starts_avg'] ?? null) . ' na dobę), czas pracy sprężarki ' . sdl_num($L['year_run_h'] ?? null, 0) . ' h.';
    $t[] = 'Starty sprężarki dziś: ' . sdl_num($v['starts'] ?? null, 0) . '. Odszraniania dziś: ' . sdl_num($v['defrost_n'] ?? null, 0) . ' (łącznie ' . sdl_num(isset($v['defrost_h']) ? $v['defrost_h'] * 60 : null) . ' min).';
    if (isset($L['today_standby'])) $t[] = 'Czuwanie dziś (sprężarka stoi: elektronika i pompa obiegowa): ' . sdl_kwh_txt($L['today_standby']) . ', starty pompy obiegowej: ' . sdl_num($v['circ_starts'] ?? null, 0) . '.';
    $o = [];
    foreach ($out as $x) $o[$x[0]] = $x;
    $cm = []; foreach ($co as $x) $cm[$x[0]] = $x[1];
    $wm = []; foreach ($cwu as $x) $wm[$x[0]] = $x[1];
    $rows = '';
    foreach (array_slice(array_values(array_filter($daily, function ($x) { return $x[1] > 0; })), -14) as $x) {
        $d = $x[0];
        $rows .= '<tr><td>' . esc_html(wp_date('d.m', strtotime($d . ' 12:00'), $tz)) . '</td><td>' . sdl_num($x[1]) . '</td><td>'
            . (isset($cm[$d], $wm[$d]) && ($cm[$d] + $wm[$d]) >= 0.95 * $x[1] ? sdl_num($cm[$d]) . ' / ' . sdl_num($wm[$d]) : '–') . '</td><td>'
            . (isset($o[$d]) ? sdl_num($o[$d][2]) . ' … ' . sdl_num($o[$d][3]) : '–') . '</td></tr>';
    }
    $html = '<p>' . esc_html(implode(' ', $t)) . '</p>';
    if ($rows) $html .= '<table><caption>Dobowe zużycie energii pompy ciepła – ostatnie dni</caption><thead><tr><th>Dzień</th><th>Zużycie [kWh]</th><th>CO / CWU [kWh]</th><th>Temp. zewn. min … maks. [°C]</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    return $html;
}

/* tekstowe podsumowanie całego domu */
function sdl_summary_dom() {
    $d = []; foreach (['dom', 'heiko', 'reqnet', 'maty', 'pompa_cwu', 'serwer'] as $k) $d[$k] = sdl_now_latest($k);
    $s30 = function ($dev) { $r = sdl_now_daily($dev, 'energy_total', 30); return $r ? array_sum(array_column($r, 1)) : null; };
    $ts = max(array_map(function ($x) { return (int) $x['ts']; }, $d));
    $dv = $d['dom']['values']; $hv = $d['heiko']['values']; $rv = $d['reqnet']['values']; $mv = $d['maty']['values']; $cv = $d['pompa_cwu']['values']; $sv = $d['serwer']['values'];
    $li = [];
    $li[] = 'Cały dom: moc pobierana teraz ' . sdl_num(isset($dv['power']) ? $dv['power'] / 1000 : null, 2) . ' kW; zużycie dziś ' . sdl_num($d['dom']['today_kwh'] ?? null) . ' kWh; ostatnie 30 dni ' . sdl_num($s30('dom'), 0) . ' kWh. Na zewnątrz ' . sdl_num($dv['outdoor'] ?? ($hv['outdoor'] ?? null)) . ' °C.';
    $li[] = 'Pompa ciepła Heiko 15 kW: moc teraz ' . sdl_num($hv['power'] ?? null, 0) . ' W; dziś ' . sdl_kwh_txt($d['heiko']['today_kwh'] ?? null)
        . (isset($d['heiko']['today_co'], $d['heiko']['today_cwu']) ? ' (CO ' . sdl_num($d['heiko']['today_co']) . ' / CWU ' . sdl_num($d['heiko']['today_cwu']) . ' kWh)' : '') . '; starty dziś ' . sdl_num($hv['starts'] ?? null, 0) . '; odszraniania dziś ' . sdl_num($hv['defrost_n'] ?? null, 0) . '.';
    $li[] = 'Bufor NOEL 700 l: góra ' . sdl_num($hv['noel_top'] ?? null) . ' °C, środek ' . sdl_num($hv['noel_mid'] ?? null) . ' °C, dół ' . sdl_num($hv['noel_bot'] ?? null) . ' °C.';
    $li[] = 'Rekuperator reQnet: wymiana powietrza ' . sdl_num($rv['flow_in'] ?? null, 0) . ' / ' . sdl_num($rv['flow_out'] ?? null, 0) . ' m³/h (nawiew / wyciąg); czerpnia ' . sdl_num($rv['czerpnia'] ?? null) . ' °C, nawiew ' . sdl_num($rv['nawiew'] ?? null) . ' °C, wyciąg ' . sdl_num($rv['wyciag'] ?? null) . ' °C, wyrzutnia ' . sdl_num($rv['wyrzutnia'] ?? null) . ' °C; moc ' . sdl_num($rv['power'] ?? null, 0) . ' W; dziś ' . sdl_kwh_txt($d['reqnet']['today_kwh'] ?? null) . '.';
    $li[] = 'Maty kapilarne (temperatury pomieszczeń): salon ' . sdl_num($mv['salon'] ?? null) . ' °C, sypialnia ' . sdl_num($mv['sypialnia'] ?? null) . ' °C, łazienka ' . sdl_num($mv['lazienka'] ?? null) . ' °C; punkt rosy ' . sdl_num($mv['rosa'] ?? null) . ' °C. Zawór mieszający: temperatura zadana ' . sdl_num($mv['zawor_zadana'] ?? null, 0) . ' °C, zasilanie mat ' . sdl_num($mv['zawor_temp'] ?? null) . ' °C, powrót z mat ' . sdl_num($mv['zawor_powrot'] ?? null) . ' °C, woda z wężownicy przed zaworem ' . sdl_num($mv['zawor_przed'] ?? null) . ' °C, otwarcie zaworu ' . sdl_num($mv['zawor_otwarcie'] ?? null, 0) . ' %.';
    $li[] = 'Pompa cyrkulacyjna CWU: dziś ' . sdl_kwh_txt($d['pompa_cwu']['today_kwh'] ?? null) . '; czas pracy dziś ' . sdl_dur_txt($cv['run_h'] ?? null) . ', wczoraj ' . sdl_dur_txt($cv['run_h_yday'] ?? null) . '; włączeń dziś ' . sdl_num($cv['starts'] ?? null, 0) . ', wczoraj ' . sdl_num($d['pompa_cwu']['starts_yday'] ?? null, 0) . '.';
    $li[] = 'Serwer domowy: moc ' . sdl_num($sv['power'] ?? null, 0) . ' W; dziś ' . sdl_kwh_txt($d['serwer']['today_kwh'] ?? null) . '.';
    return '<p>Stan na ' . esc_html(sdl_when($ts ?: null)) . ' (czas polski).</p><ul><li>' . implode('</li><li>', array_map('esc_html', $li)) . '</li></ul>';
}

/* podpowiedź „obróć telefon” – tylko na stronach z danymi, tylko telefon w pionie, raz na wizytę */
function sdl_rotate_hint() {
    static $done = false;
    if ($done) return '';
    $done = true;
    return '<div class="sdl-rot" role="status" hidden>'
        . '<svg class="sdl-rot-ico" viewBox="0 0 24 24" aria-hidden="true"><rect x="7" y="2.5" width="10" height="19" rx="2.2" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="18.3" r="1" fill="currentColor"/></svg>'
        . '<span>Obróć telefon poziomo – wykresy i kafelki będą czytelniejsze.</span>'
        . '<button type="button" class="sdl-rot-x" aria-label="Zamknij podpowiedź">✕</button></div>'
        /* raz na wizytę: po zamknięciu lub obróceniu telefonu wraca dopiero po 30 min przerwy (sessionStorage w mobilnym Chrome żyje tyle co karta, czyli dniami) */
        . '<script>(function(){var el=document.currentScript.previousElementSibling,K="sdl_rot_ts",q;'
        . 'try{var t=+localStorage.getItem(K);if(t&&Date.now()-t<18e5){localStorage.setItem(K,Date.now());return}}catch(e){}'
        . 'q=window.matchMedia("(orientation: portrait) and (max-width: 600px)");'
        . 'function upd(){el.hidden=!q.matches}'
        . 'function off(){el.hidden=true;try{localStorage.setItem(K,Date.now())}catch(e){}q.removeEventListener?q.removeEventListener("change",upd):q.removeListener(upd)}'
        . 'el.querySelector(".sdl-rot-x").addEventListener("click",off);'
        . 'q.addEventListener?q.addEventListener("change",function(){if(!q.matches)off();else upd()}):q.addListener(upd);'
        . 'setTimeout(upd,1200);})();</script>';
}

function sdl_sr_block($html) {
    return '<section class="sdl-sr" aria-label="Dane w formie tekstu"><h2>Dane w formie tekstu</h2>' . $html
        . '<p>Te same dane w formacie JSON: ' . esc_html(rest_url('sdl/v1/')) . ' (opis w ' . esc_html(home_url('/llms.txt')) . ').</p></section>';
}

function sdl_dataset_ld($name, $desc, $vars) {
    $ld = [
        '@context' => 'https://schema.org', '@type' => 'Dataset',
        'name' => $name, 'description' => $desc, 'url' => get_permalink(),
        'inLanguage' => 'pl-PL', 'isAccessibleForFree' => true,
        'creator' => ['@type' => 'Organization', 'name' => 'StalowyDOM', 'url' => home_url('/')],
        'spatialCoverage' => ['@type' => 'Place', 'name' => 'okolice Poznania, Polska'],
        'temporalCoverage' => '2026-10-01/..',
        'variableMeasured' => $vars,
        'distribution' => [['@type' => 'DataDownload', 'encodingFormat' => 'application/json', 'contentUrl' => rest_url('sdl/v1/latest')]],
    ];
    return '<script type="application/ld+json">' . wp_json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
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

  <div class="sdl-kpis sdl-kpis-5">
    <div class="sdl-kpi"><div class="sdl-kpi-label">Zużycie dziś</div><div class="sdl-kpi-val"><span data-k="today">–</span> <small>kWh</small></div><div class="sdl-kpi-sub" data-k="today_split"></div></div>
    <div class="sdl-kpi"><div class="sdl-kpi-label">Wczoraj</div><div class="sdl-kpi-val"><span data-k="yesterday">–</span> <small>kWh</small></div><div class="sdl-kpi-sub" data-k="yesterday_split"></div></div>
    <div class="sdl-kpi"><div class="sdl-kpi-label">Moc teraz</div><div class="sdl-kpi-val"><span data-k="power">–</span> <small data-k="power_unit">W</small></div></div>
    <div class="sdl-kpi" title="Energia pobrana, gdy sprężarka stoi: elektronika (ok. 12 W) i okresowe włączenia pompy obiegowej (ok. 109 W)."><div class="sdl-kpi-label">Czuwanie dziś</div><div class="sdl-kpi-val"><span data-k="standby">–</span> <small data-k="standby_unit">Wh</small></div><div class="sdl-kpi-sub">starty pompy obiegowej: <span data-k="circ">–</span></div></div>
    <div class="sdl-kpi"><div class="sdl-kpi-label">Na zewnątrz</div><div class="sdl-kpi-val"><span data-k="outdoor">–</span> <small>°C</small></div><div class="sdl-kpi-sub" data-k="feels" title="Temperatura odczuwalna z uwzględnieniem wiatru (wzór wind chill). Wiatr: prognoza met.no dla naszej okolicy."></div></div>
  </div>
  <div class="sdl-kpis sdl-kpis-5">
    <div class="sdl-kpi"><div class="sdl-kpi-label">Starty dziś</div><div class="sdl-kpi-val"><span data-k="st_today">–</span></div><div class="sdl-kpi-sub" data-k="st_today_sub"></div></div>
    <div class="sdl-kpi"><div class="sdl-kpi-label">Starty wczoraj</div><div class="sdl-kpi-val"><span data-k="st_yday">–</span></div><div class="sdl-kpi-sub" data-k="st_yday_sub"></div></div>
    <div class="sdl-kpi"><div class="sdl-kpi-label">Starty – ten tydzień</div><div class="sdl-kpi-val"><span data-k="st_week">–</span> <small>/ dobę</small></div><div class="sdl-kpi-sub">średnio od poniedziałku</div></div>
    <div class="sdl-kpi"><div class="sdl-kpi-label">Starty – ten miesiąc</div><div class="sdl-kpi-val"><span data-k="st_month">–</span> <small>/ dobę</small></div><div class="sdl-kpi-sub">średnio od 1. dnia miesiąca</div></div>
    <div class="sdl-kpi" title="Suma czasu, w którym sprężarka pracowała od północy (wg poboru mocy)."><div class="sdl-kpi-label">Praca sprężarki dziś</div><div class="sdl-kpi-val" data-k="run">–</div></div>
  </div>
  <div class="sdl-kpis sdl-kpis-5">
    <div class="sdl-kpi" style="border-top-color:#3b82f6"><div class="sdl-kpi-label" data-k="m0_name">Ten miesiąc (do dziś)</div><div class="sdl-kpi-val"><span data-k="m0">–</span> <small>kWh</small></div><div class="sdl-kpi-sub" data-k="m0_sub"></div></div>
    <div class="sdl-kpi" style="border-top-color:#3b82f6"><div class="sdl-kpi-label" data-k="m1_name">–</div><div class="sdl-kpi-val"><span data-k="m1">–</span> <small>kWh</small></div><div class="sdl-kpi-sub" data-k="m1_sub"></div></div>
    <div class="sdl-kpi" style="border-top-color:#3b82f6"><div class="sdl-kpi-label" data-k="m2_name">–</div><div class="sdl-kpi-val"><span data-k="m2">–</span> <small>kWh</small></div><div class="sdl-kpi-sub" data-k="m2_sub"></div></div>
    <div class="sdl-kpi" style="border-top-color:#3b82f6"><div class="sdl-kpi-label" data-k="m3_name">–</div><div class="sdl-kpi-val"><span data-k="m3">–</span> <small>kWh</small></div><div class="sdl-kpi-sub" data-k="m3_sub"></div></div>
    <div class="sdl-kpi" style="border-top-color:#3b82f6"><div class="sdl-kpi-label" data-k="m4_name">–</div><div class="sdl-kpi-val"><span data-k="m4">–</span> <small>kWh</small></div><div class="sdl-kpi-sub" data-k="m4_sub"></div></div>
  </div>
  <div class="sdl-kpis sdl-kpis-5">
    <div class="sdl-kpi" style="border-top-color:#84cc16"><div class="sdl-kpi-label" data-k="r0_name">Praca sprężarki – ten miesiąc</div><div class="sdl-kpi-val" data-k="r0">–</div><div class="sdl-kpi-sub" data-k="r0_sub"></div></div>
    <div class="sdl-kpi" style="border-top-color:#84cc16"><div class="sdl-kpi-label" data-k="r1_name">–</div><div class="sdl-kpi-val" data-k="r1">–</div><div class="sdl-kpi-sub" data-k="r1_sub"></div></div>
    <div class="sdl-kpi" style="border-top-color:#84cc16"><div class="sdl-kpi-label" data-k="r2_name">–</div><div class="sdl-kpi-val" data-k="r2">–</div><div class="sdl-kpi-sub" data-k="r2_sub"></div></div>
    <div class="sdl-kpi" style="border-top-color:#84cc16"><div class="sdl-kpi-label" data-k="r3_name">–</div><div class="sdl-kpi-val" data-k="r3">–</div><div class="sdl-kpi-sub" data-k="r3_sub"></div></div>
    <div class="sdl-kpi" style="border-top-color:#84cc16"><div class="sdl-kpi-label" data-k="r4_name">–</div><div class="sdl-kpi-val" data-k="r4">–</div><div class="sdl-kpi-sub" data-k="r4_sub"></div></div>
  </div>
  <div class="sdl-kpis sdl-kpis-5">
    <div class="sdl-kpi sdl-kpi-year"><div class="sdl-kpi-label"><span data-k="y_label">Od początku roku</span>: zużycie</div><div class="sdl-kpi-val"><span data-k="y_kwh">–</span> <small>kWh</small></div></div>
    <div class="sdl-kpi sdl-kpi-year"><div class="sdl-kpi-label"><span data-k="y_label2">Od początku roku</span>: starty</div><div class="sdl-kpi-val"><span data-k="y_starts">–</span></div></div>
    <div class="sdl-kpi sdl-kpi-year"><div class="sdl-kpi-label"><span data-k="y_label3">Od początku roku</span>: czas pracy</div><div class="sdl-kpi-val" data-k="y_run">–</div><div class="sdl-kpi-sub" data-k="y_run_sub"></div></div>
    <div class="sdl-kpi sdl-kpi-year"><div class="sdl-kpi-label"><span data-k="y_label4">Od początku roku</span>: starty na dobę</div><div class="sdl-kpi-val"><span data-k="y_starts_avg">–</span> <small>/ dobę</small></div><div class="sdl-kpi-sub">średnia dobowa</div></div>
  </div>

  <hr class="sdl-hr">
  <div class="sdl-mode" aria-label="Tryb pracy i harmonogram pompy ciepła">
    <div class="sdl-mode-item" title="Co pompa robi w tej chwili (odczyt z pompy co 5 minut)."><div class="sdl-kpi-label">Teraz</div><div class="sdl-mode-val" data-k="mode_now">–</div></div>
    <div class="sdl-mode-item" title="Tryb ustawiony w pompie: Ogrzewanie = tylko maty (CO), Auto = maty i ciepła woda (CO + CWU)."><div class="sdl-kpi-label">Tryb pompy</div><div class="sdl-mode-val" data-k="mode_sel">–</div></div>
    <div class="sdl-mode-item" title="Częstotliwość pracy sprężarki inwerterowej: im wyższa, tym większa moc grzewcza (i pobór prądu). 0 Hz = sprężarka stoi."><div class="sdl-kpi-label">Sprężarka</div><div class="sdl-mode-val"><span data-k="hz">–</span> <small>Hz</small></div></div>
    <div class="sdl-mode-item sdl-mode-wide" title="Harmonogram w Home Assistant przełącza tryb pompy o stałych godzinach – CWU grzejemy raz na dobę, wieczorem."><div class="sdl-kpi-label">Harmonogram</div><div class="sdl-mode-val" data-k="sched">–</div><div class="sdl-kpi-sub" data-k="sched_sub"></div></div>
  </div>
  <hr class="sdl-hr">

  <div class="sdl-row">
    <div class="sdl-card sdl-tank-card">
      <svg class="sdl-tank" viewBox="0 0 380 560" role="img" aria-label="<?php echo esc_attr($a['tank']); ?> – przekrój: wężownica CWU u góry, wężownica mat kapilarnych pod środkiem, temperatury warstw wody">
  <defs>
    <linearGradient id="sdl-water-<?php echo esc_attr($a['device']); ?>" x1="0" y1="0" x2="0" y2="1">
      <stop data-stop="noel_top" offset="0.08" stop-color="#ef4444"/><stop data-stop="noel_mid" offset="0.5" stop-color="#f97316"/><stop data-stop="noel_bot" offset="0.92" stop-color="#3b82f6"/>
    </linearGradient>
    <linearGradient id="sdl-shell-<?php echo esc_attr($a['device']); ?>" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0" stop-color="#b9c0c9"/><stop offset=".22" stop-color="#e9ecef"/><stop offset=".55" stop-color="#ffffff"/><stop offset="1" stop-color="#f1f3f5"/>
    </linearGradient>
    <linearGradient id="sdl-stripe-<?php echo esc_attr($a['device']); ?>" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0" stop-color="#f07a2c"/><stop offset="1" stop-color="#e8661a"/>
    </linearGradient>
    <linearGradient id="sdl-cu-<?php echo esc_attr($a['device']); ?>" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0" stop-color="#8a3d14"/><stop offset=".3" stop-color="#e9935a"/><stop offset=".55" stop-color="#ffc79a"/><stop offset=".8" stop-color="#c8662d"/><stop offset="1" stop-color="#7a3512"/>
    </linearGradient>
    <pattern id="sdl-ins-<?php echo esc_attr($a['device']); ?>" width="6" height="6" patternUnits="userSpaceOnUse"><rect width="6" height="6" fill="#d9dde2"/><circle cx="2" cy="2" r=".9" fill="#b3b9c1"/><circle cx="5" cy="4.6" r=".7" fill="#c4c9cf"/></pattern>
    <clipPath id="sdl-water-clip-<?php echo esc_attr($a['device']); ?>"><path d="M180 72 H246 a16 16 0 0 1 16 16 V478 H180 Z"/></clipPath>
  </defs>

  <text x="190" y="24" text-anchor="middle" class="sdl-tank-title"><?php echo esc_html($a['tank']); ?> · przekrój</text>

  <!-- rury: lewa strona = pompa ciepła (woda grzewcza w zbiorniku) -->
  <path class="sdl-pipe sdl-hot" d="M8 190 H100"/>
  <path class="sdl-pipe sdl-cold" d="M100 462 H8"/>
  <text x="8" y="178" class="sdl-ts">zasilanie z PC</text><text x="8" y="208" class="sdl-tv"><tspan data-t="supply">–</tspan> °C</text>
  <text x="8" y="482" class="sdl-ts">powrót do PC</text>

  <!-- rury: prawa strona = wężownice -->
  <path class="sdl-pipe sdl-hot sdl-thin" d="M260 92 H372"/>
  <path class="sdl-pipe sdl-cold sdl-thin" d="M372 232 H260"/>
  <path class="sdl-pipe sdl-warm sdl-thin" d="M262 292 H372"/>
  <path class="sdl-pipe sdl-cold sdl-thin" d="M372 352 H262"/>
  <text x="372" y="82" text-anchor="end" class="sdl-ts">ciepła woda (CWU)</text>
  <text x="372" y="250" text-anchor="end" class="sdl-ts">zimna woda</text>
  <text x="372" y="282" text-anchor="end" class="sdl-ts">maty – zasilanie</text>
  <text x="372" y="370" text-anchor="end" class="sdl-ts">maty – powrót</text>

  <!-- cień i nóżki -->
  <ellipse cx="180" cy="512" rx="112" ry="10" fill="#0d141a" opacity=".55"/>
  <rect x="112" y="494" width="12" height="14" rx="2" fill="#4b5563"/><rect x="236" y="494" width="12" height="14" rx="2" fill="#4b5563"/>

  <!-- lewa połowa: widok z zewnątrz -->
  <path d="M116 50 H180 V500 H116 a16 16 0 0 1 -16 -16 V66 a16 16 0 0 1 16 -16 Z" fill="url(#sdl-shell-<?php echo esc_attr($a['device']); ?>) #f4f6f8"/>
  <rect x="158" y="50" width="22" height="450" fill="url(#sdl-stripe-<?php echo esc_attr($a['device']); ?>) #ee7024"/>
  <g class="sdl-gauge"><circle cx="169" cy="96" r="9"/><path d="M169 96 L174 91"/></g>
  <g class="sdl-gauge"><circle cx="169" cy="458" r="9"/><path d="M169 458 L164 453"/></g>

  <!-- prawa połowa: przekrój -->
  <path d="M180 50 H244 a16 16 0 0 1 16 16 V484 a16 16 0 0 1 -16 16 H180 Z" fill="url(#sdl-ins-<?php echo esc_attr($a['device']); ?>) #d9dde2"/>
  <path d="M180 72 H246 a16 16 0 0 1 16 16 V478 H180 Z" fill="url(#sdl-water-<?php echo esc_attr($a['device']); ?>) #f97316"/>
  <g clip-path="url(#sdl-water-clip-<?php echo esc_attr($a['device']); ?>)" stroke="url(#sdl-cu-<?php echo esc_attr($a['device']); ?>) #d07a45" fill="none" stroke-linecap="round">
    <g class="sdl-coil"><path d="M184 92 Q223.0 99 262 91"/><path d="M184 102 Q223.0 109 262 101"/><path d="M184 112 Q223.0 119 262 111"/><path d="M184 122 Q223.0 129 262 121"/><path d="M184 132 Q223.0 139 262 131"/><path d="M184 142 Q223.0 149 262 141"/><path d="M184 152 Q223.0 159 262 151"/><path d="M184 162 Q223.0 169 262 161"/><path d="M184 172 Q223.0 179 262 171"/><path d="M184 182 Q223.0 189 262 181"/><path d="M184 192 Q223.0 199 262 191"/><path d="M184 202 Q223.0 209 262 201"/><path d="M184 212 Q223.0 219 262 211"/><path d="M184 222 Q223.0 229 262 221"/><path d="M184 232 Q223.0 239 262 231"/></g>
    <g class="sdl-coil"><path d="M192 292 Q222.0 299 252 291"/><path d="M192 302 Q222.0 309 252 301"/><path d="M192 312 Q222.0 319 252 311"/><path d="M192 322 Q222.0 329 252 321"/><path d="M192 332 Q222.0 339 252 331"/><path d="M192 342 Q222.0 349 252 341"/><path d="M192 352 Q222.0 359 252 351"/></g>
  </g>
  <path d="M180 50 V500" stroke="#ffffff" stroke-width="2"/>
  <path d="M180 72 H246 a16 16 0 0 1 16 16 V478 H180" fill="none" stroke="#6b7280" stroke-width="1.5"/>

  <!-- etykiety wężownic -->
  <path class="sdl-callout" d="M228 160 H296"/>
  <text x="300" y="158" class="sdl-cl">wężownica</text><text x="300" y="174" class="sdl-cl">CWU</text>
  <path class="sdl-callout" d="M224 314 H296"/>
  <text x="300" y="312" class="sdl-cl">wężownica</text><text x="300" y="328" class="sdl-cl">mat</text>

  <!-- czujniki temperatury (w wodzie, na linii przekroju) -->
  <g class="sdl-sensor"><circle cx="180" cy="122" r="5"/><path d="M175 122 H156"/></g>
  <g class="sdl-sensor"><circle cx="180" cy="275" r="5"/><path d="M175 275 H156"/></g>
  <g class="sdl-sensor"><circle cx="180" cy="430" r="5"/><path d="M175 430 H156"/></g>
  <g class="sdl-chip"><rect x="76" y="108" width="80" height="28" rx="14"/><text x="116" y="127" text-anchor="middle"><tspan data-t="noel_top">–</tspan> °C</text></g>
  <g class="sdl-chip"><rect x="76" y="261" width="80" height="28" rx="14"/><text x="116" y="280" text-anchor="middle"><tspan data-t="noel_mid">–</tspan> °C</text></g>
  <g class="sdl-chip"><rect x="76" y="416" width="80" height="28" rx="14"/><text x="116" y="435" text-anchor="middle"><tspan data-t="noel_bot">–</tspan> °C</text></g>
  <text x="116" y="102" text-anchor="middle" class="sdl-cs">góra</text>
  <text x="116" y="255" text-anchor="middle" class="sdl-cs">środek</text>
  <text x="116" y="410" text-anchor="middle" class="sdl-cs">dół</text>
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
    <h3 class="sdl-subh">Zużycie na każdy stopień różnicy temperatur <small>(kWh/°C)</small></h3>
    <div class="sdl-canvas sdl-canvas-sm"><canvas data-chart="dailykd" role="img" aria-label="Zużycie energii pompy ciepła w kWh na stopień różnicy między 20 °C w domu a średnią temperaturą zewnętrzną, doby ze średnią do 12 °C"></canvas></div>
    <p class="sdl-note"><span data-kd-note></span>Dzielimy zużycie doby przez różnicę między 20 °C w domu a średnią temperaturą na zewnątrz – tylko w doby ze średnią do 12 °C, gdy dom faktycznie trzeba ogrzewać. Dzięki temu chłodne i ciepłe dni da się uczciwie porównać: im niżej, tym mniej prądu na każdy stopień mrozu. Wartość obejmuje też ciepłą wodę (CWU).</p>
    <div class="sdl-legend sdl-legend-temp"><span><i style="background:#f97316"></i>Temperatura zewnętrzna – maks. w ciągu dnia</span><span><i style="background:#3b82f6"></i>min. w ciągu dnia</span></div>
    <div class="sdl-canvas sdl-canvas-sm"><canvas data-chart="dailytemp" role="img" aria-label="Najniższa, średnia i najwyższa temperatura zewnętrzna w kolejnych dniach"></canvas></div>
    <p class="sdl-note">Pomarańczowy – ogrzewanie domu (CO), niebieski – podgrzewanie ciepłej wody (CWU); podział liczymy od 22.11.2026. Po najechaniu na słupek widać zużycie oraz najniższą i najwyższą temperaturę zewnętrzną z tego dnia.</p>
  </div>
  <?php echo sdl_rotate_hint(); echo sdl_sr_block(sdl_summary_heiko($a['device'])); echo sdl_dataset_ld('Pompa ciepła Heiko Thermal 15 kW – dane na żywo', 'Moc, zużycie energii (z podziałem na ogrzewanie CO i ciepłą wodę CWU), temperatury bufora NOEL 700 l, starty i odszraniania pompy ciepła powietrze–woda w domu z kontenerów pod Poznaniem. Odczyty co 5 minut.', ['moc [W]', 'zużycie energii [kWh]', 'zużycie CO i CWU [kWh]', 'temperatura zewnętrzna [°C]', 'temperatury bufora [°C]', 'starty sprężarki', 'odszraniania', 'energia w czuwaniu [kWh]']); ?>
  <?php echo sdl_footer_note(); ?>
</div>
<?php
    return ob_get_clean();
});

/* ---------- strona zbiorcza: [sdl_dom heiko="/heiko/"] ---------- */

function sdl_dom_kpi($label, $key, $unit, $sub = '', $accent = '#f97316') {
    return '<div class="sdl-kpi" style="border-top-color:' . esc_attr($accent) . '"><div class="sdl-kpi-label">' . esc_html($label) . '</div>'
        . '<div class="sdl-kpi-val"><span data-d="' . esc_attr($key) . '">–</span> <small>' . esc_html($unit) . '</small></div>'
        . ($sub !== '' ? '<div class="sdl-kpi-sub">' . $sub . '</div>' : '') . '</div>';
}

function sdl_heiko_mode_txt() {
    $v = sdl_now_latest('heiko')['values'];
    if (!isset($v['mode'])) return '–';
    return [0 => 'postój', 1 => 'grzanie CWU', 2 => 'ogrzewanie (CO)'][(int) round($v['mode'])] ?? '–';
}
function sdl_heiko_sched_txt() {
    $v = sdl_now_latest('heiko')['values'];
    if (!isset($v['sched_on'])) return '–';
    if (!$v['sched_on']) return 'wyłączony';
    $hm = function ($m) { $m = (int) round($m); return intdiv($m, 60) . ':' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT); };
    return isset($v['auto_from'], $v['co_from']) ? 'CWU od ' . $hm($v['auto_from']) . ', CO od ' . $hm($v['co_from']) : 'włączony';
}

function sdl_dom_row($name, $nameClass, $title, $desc, $kpis, $link) {
    $k = '';
    $rows = in_array('br', $kpis, true); /* 'br' = nowy wiersz kafelków */
    foreach ($kpis as $kp) {
        if ($kp === 'br') { $k .= '</div><div class="sdl-dev-grp">'; continue; }
        $k .= '<div class="sdl-dev-kpi"' . (!empty($kp[2]) ? ' data-only="' . esc_attr($kp[2]) . '" hidden' : '') . '><p class="sdl-dev-l">' . wp_kses($kp[0], ['span' => ['data-d' => true]]) . '</p><p class="sdl-dev-v">' . $kp[1] . '</p></div>';
    }
    $btn = $link
        ? '<a class="sdl-btn" href="' . esc_url($link) . '">zobacz więcej</a>'
        : '<span class="sdl-btn sdl-btn-soon">podstrona wkrótce</span>';
    return '<article class="sdl-dev"><div class="sdl-dev-main"><p class="sdl-dev-name ' . esc_attr($nameClass) . '">' . esc_html($name) . '</p>'
        . '<h3>' . esc_html($title) . '</h3><p class="sdl-dev-desc">' . esc_html($desc) . '</p></div>'
        . ($rows ? '<div class="sdl-dev-kpis sdl-dev-rows"><div class="sdl-dev-grp">' . $k . '</div></div>' : '<div class="sdl-dev-kpis">' . $k . '</div>') . '<div class="sdl-dev-act">' . $btn . '</div></article>';
}

function sdl_v($dev, $key, $unit = '', $dec = 1) {
    $L = sdl_now_latest($dev);
    $v = $L['values'][$key] ?? null;
    return '<span data-v="' . esc_attr($dev . '.' . $key) . '" data-dec="' . (int) $dec . '">' . sdl_num($v, $dec) . '</span>' . ($unit !== '' ? ' ' . esc_html($unit) : '');
}

add_shortcode('sdl_dom', function ($atts) {
    $a = shortcode_atts(['heiko' => '/heiko/'], $atts);
    wp_enqueue_style('sdl', SDL_URL . 'assets/sdl.css', [], SDL_VER);
    wp_enqueue_script('sdl-dom', SDL_URL . 'assets/sdl-dom.js', [], SDL_VER, true);
    wp_localize_script('sdl-dom', 'SDL', ['api' => esc_url_raw(rest_url('sdl/v1/'))]);
    $heiko = $a['heiko'] ? home_url($a['heiko']) : '';
    ob_start(); ?>
<div class="sdl-wrap sdl-dom" data-sdl-dom>
  <div class="sdl-status"><span class="sdl-dot"></span><span class="sdl-status-text">Ładowanie danych…</span></div>

  <h2 class="sdl-sec">Cały dom</h2>
  <div class="sdl-kpis">
    <?php
    echo sdl_dom_kpi('Moc pobierana teraz', 'dom_power', 'kW', 'L1 ' . sdl_v('dom', 'l1', 'W', 0) . ' · L2 ' . sdl_v('dom', 'l2', 'W', 0) . ' · L3 ' . sdl_v('dom', 'l3', 'W', 0));
    echo sdl_dom_kpi('Zużycie dziś', 'dom_today', 'kWh', 'z głównego licznika domu');
    echo sdl_dom_kpi('Ostatnie 30 dni', 'dom_30', 'kWh', 'w tym pompa ciepła: <span data-d="heiko_30">–</span> kWh');
    echo sdl_dom_kpi('Na zewnątrz', 'outdoor', '°C', '', '#3b82f6');
    ?>
  </div>

  <div class="sdl-card">
    <div class="sdl-head"><h3>Na co idzie prąd – dziś</h3></div>
    <div class="sdl-share" role="img" aria-label="Podział mocy pobieranej przez dom"></div>
    <div class="sdl-share-legend"></div>
    <p class="sdl-share-max" data-d="share_max" hidden></p>
    <p class="sdl-note">Zużycie energii od północy, w kWh i jako udział w zużyciu całego domu.</p>
  </div>

  <h2 class="sdl-sec">Urządzenia</h2>
  <div class="sdl-devs">
    <?php
    echo sdl_dom_row('HEIKO', 'sdl-n-heiko', 'Pompa ciepła 15 kW', 'Powietrze–woda, sterowana lokalnie z Home Assistant.',
        [['Teraz', '<span data-d="heiko_mode">' . esc_html(sdl_heiko_mode_txt()) . '</span>'], ['Sprężarka', sdl_v('heiko', 'hz', 'Hz', 0)], ['Harmonogram', '<span data-d="heiko_sched">' . esc_html(sdl_heiko_sched_txt()) . '</span>'], 'br', ['Moc teraz', sdl_v('heiko', 'power', 'W', 0)], ['Wczoraj', '<span data-d="heiko_yday">–</span> kWh'], ['Dziś: CO / CWU', '<span data-d="heiko_co">–</span> / <span data-d="heiko_cwu">–</span> kWh'], ['Starty dziś', sdl_v('heiko', 'starts', '', 0)], ['<span data-d="heiko_mcur_name">Ten miesiąc</span>', '<span data-d="heiko_mcur">–</span> kWh'], ['<span data-d="heiko_mprev_name">Poprzedni miesiąc</span>', '<span data-d="heiko_mprev">–</span> kWh'], ['Odszranianie dziś', '<span data-d="heiko_defrost">–</span>'], ['Zasilanie z PC', sdl_v('heiko', 'supply', '°C'), 'running']], $heiko);
    echo sdl_dom_row('NOEL', 'sdl-n-noel', 'Bufor 700 l z wężownicą CWU', 'Woda grzewcza z pompy, wężownica CWU i wężownica mat kapilarnych.',
        [['Góra', sdl_v('heiko', 'noel_top', '°C')], ['Środek', sdl_v('heiko', 'noel_mid', '°C')], ['Dół', sdl_v('heiko', 'noel_bot', '°C')]], $heiko);
    echo sdl_dom_row('reQnet', '', 'Rekuperator entalpiczny', 'Wentylacja z odzyskiem ciepła i wilgoci.',
        [['Wymiana powietrza (nawiew / wyciąg)', sdl_v('reqnet', 'flow_in', '', 0) . ' / ' . sdl_v('reqnet', 'flow_out', 'm³/h', 0)], 'br',
         ['Czerpnia', sdl_v('reqnet', 'czerpnia', '°C')], ['Nawiew', sdl_v('reqnet', 'nawiew', '°C')], ['Wyciąg / wyrzutnia', sdl_v('reqnet', 'wyciag', '', 1) . ' / ' . sdl_v('reqnet', 'wyrzutnia', '°C')], 'br',
         ['Moc', sdl_v('reqnet', 'power', 'W', 0)], ['Dziś', '<span data-e="reqnet_today">–</span>'], ['30 dni', '<span data-e="reqnet_30">–</span>']], home_url('/reqnet/'));
    echo sdl_dom_row('Maty kapilarne', 'sdl-n-small', 'Ogrzewanie i chłodzenie sufitowe', 'Temperatury pomieszczeń z termostatów Sinum i zawór mieszający, który zasila maty wodą z wężownicy w buforze.',
        [['Salon', sdl_v('maty', 'salon', '°C')], ['Sypialnia', sdl_v('maty', 'sypialnia', '°C')], ['Łazienka', sdl_v('maty', 'lazienka', '°C')], 'br',
         ['Punkt rosy', sdl_v('maty', 'rosa', '°C')], ['Zawór: temp. zadana', sdl_v('maty', 'zawor_zadana', '°C', 0)], ['Otwarcie zaworu', sdl_v('maty', 'zawor_otwarcie', '%', 0)], 'br',
         ['Przed zaworem (z wężownicy)', sdl_v('maty', 'zawor_przed', '°C')], ['Zasilanie mat', sdl_v('maty', 'zawor_temp', '°C')], ['Powrót z mat', sdl_v('maty', 'zawor_powrot', '°C')], 'br',
         ['Strata wężownicy (dół bufora – przed zaworem)', '<span data-d="maty_loss">–</span> K'], ['Zasilanie – powrót mat', '<span data-d="maty_dt">–</span> K']], home_url('/maty-kapilarne/'));
    echo sdl_dom_row('Pompa CWU', 'sdl-n-small', 'Cyrkulacja ciepłej wody', 'Pompa cyrkulacyjna – ciepła woda od razu w kranie.',
        [['Dziś', '<span data-e="pompa_cwu_today">–</span>'], ['30 dni', '<span data-e="pompa_cwu_30">–</span>'], 'br',
         ['Czas pracy dziś', '<span data-d="cwu_run">–</span>'], ['Włączeń dziś', sdl_v('pompa_cwu', 'starts', '', 0)], 'br',
         ['Czas pracy wczoraj', '<span data-d="cwu_run_y">–</span>'], ['Włączeń wczoraj', '<span data-d="cwu_starts_y">' . sdl_num(sdl_now_latest('pompa_cwu')['starts_yday'] ?? null, 0) . '</span>']], '');
    echo sdl_dom_row('Serwer', '', 'Serwer domowy (NAS)', 'Pliki, zdjęcia i multimedia całej rodziny.',
        [['Moc teraz', sdl_v('serwer', 'power', 'W', 0)], ['Dziś', '<span data-e="serwer_today">–</span>'], ['30 dni', '<span data-e="serwer_30">–</span>']], '');
    ?>
  </div>

  <div class="sdl-card sdl-pv">
    <div class="sdl-head"><h3>Przepływ energii</h3><span class="sdl-pv-soon">po montażu fotowoltaiki</span></div>
    <div class="sdl-pv-grid">
      <div><p class="sdl-pv-t" style="color:#fbbf24">Fotowoltaika</p><p class="sdl-dev-l">9,6 kWp</p><p class="sdl-dev-v">–</p></div>
      <div><p class="sdl-pv-t" style="color:#1baf7a">Magazyn</p><p class="sdl-dev-l">20 kWh</p><p class="sdl-dev-v">–</p></div>
      <div><p class="sdl-pv-t">Dom</p><p class="sdl-dev-l">zużycie</p><p class="sdl-dev-v"><span data-d="dom_power">–</span> kW</p></div>
      <div><p class="sdl-pv-t" style="color:#60a5fa">Sieć</p><p class="sdl-dev-l">pobór / oddanie</p><p class="sdl-dev-v">–</p></div>
    </div>
    <p class="sdl-note">Tu pojawi się animowany schemat: skąd w danej chwili płynie prąd i dokąd trafia.</p>
  </div>
  <?php echo sdl_rotate_hint(); echo sdl_sr_block(sdl_summary_dom()); echo sdl_dataset_ld('Dom z kontenerów na żywo – zużycie energii i parametry urządzeń', 'Zużycie energii całego domu i urządzeń (pompa ciepła, rekuperator, pompa cyrkulacyjna CWU, serwer), temperatury pomieszczeń i parametry wentylacji w domu z kontenerów pod Poznaniem. Odczyty co 5 minut.', ['moc całego domu [W]', 'zużycie energii [kWh]', 'wymiana powietrza [m³/h]', 'temperatury pomieszczeń [°C]', 'czas pracy pompy CWU [h]']); ?>
  <?php echo sdl_footer_note(); ?>
</div>
<?php
    return ob_get_clean();
});

/* ---------- szronienie parownika: punkt rosy, punkt szronu, woda w powietrzu (wzory Magnusa) ---------- */
function sdl_frost($t, $rh, $tp = null, $running = true) {
    if (!is_numeric($t) || !is_numeric($rh) || $rh <= 0) return null;
    $t = (float) $t; $rh = min(100.0, (float) $rh);
    $es = function ($x) { return 6.112 * exp(17.62 * $x / (243.12 + $x)); };   /* nad wodą, hPa */
    $ei = function ($x) { return 6.112 * exp(22.46 * $x / (272.62 + $x)); };   /* nad lodem, hPa */
    $e = $rh / 100 * $es($t);
    $l = log($e / 6.112);
    $ah = function ($p, $x) { return 216.7 * $p / (273.15 + $x); };           /* g/m³ */
    $est = !is_numeric($tp) || !$running;   /* sprężarka stoi: parownik ma temperaturę otoczenia, liczymy dla pracy */
    $ts = $est ? $t - 7 : (float) $tp;                                          /* bez odczytu: parownik ok. 7 K zimniejszy */
    $sat = $ah($ts < 0 ? $ei($ts) : $es($ts), $ts);
    $air = $ah($e, $t);
    $pot = $ts < 0 ? max(0, $air - $sat) : 0;
    return ['t' => $t, 'rh' => $rh, 'dew' => 243.12 * $l / (17.62 - $l), 'frost' => 272.62 * $l / (22.46 - $l),
            'ah' => $air, 'ts' => $ts, 'est' => $est, 'pot' => $pot];
}

function sdl_frost_level($pot) {
    if ($pot < 0.3) return 'minimalny';
    if ($pot < 1) return 'mały';
    if ($pot < 2) return 'umiarkowany';
    return 'duży';
}

function sdl_frost_story($f) {
    if (!$f) return 'Czekam na odczyt temperatury i wilgotności powietrza zewnętrznego.';
    $sg = function ($v) { return ($v > 0.05 ? '+' : ($v < -0.05 ? '−' : '')) . sdl_num(abs($v)); };
    $s = 'Na zewnątrz jest ' . $sg($f['t']) . ' °C przy wilgotności ' . sdl_num($f['rh'], 0) . ' %. W każdym metrze sześciennym powietrza jest '
       . sdl_num($f['ah']) . ' g wody. Punkt rosy wypada przy ' . $sg($f['dew']) . ' °C, a punkt szronu przy ' . $sg($f['frost']) . ' °C – '
       . 'każda powierzchnia zimniejsza od tej temperatury zbiera z powietrza lód. ';
    $s .= ($f['est'] ? 'Sprężarka teraz stoi. Gdy pracuje, parownik jest zwykle ok. 7 K zimniejszy od powietrza, czyli ma ok. ' . $sg($f['ts']) . ' °C. '
                     : 'Parownik ma teraz ' . $sg($f['ts']) . ' °C. ');
    if ($f['pot'] < 0.05) {
        $s .= $f['ts'] >= 0 ? 'Jest cieplejszy niż 0 °C, więc szron się nie tworzy – wilgoć najwyżej skrapla się i spływa.'
                            : 'Mimo mrozu na lamelach nie ma z czego osiąść lodu – powietrze jest tak suche, że parownik nie schodzi poniżej jego punktu szronu.';
    } else {
        $s .= 'Z każdego metra sześciennego przepływającego powietrza może na nim osiąść do ' . sdl_num($f['pot']) . ' g lodu – potencjał szronienia jest '
            . sdl_frost_level($f['pot']) . '. ';
        $s .= $f['pot'] >= 1 ? 'Wentylator przetłacza tysiące metrów sześciennych na godzinę, więc lamele zarastają szybko i pompa musi się często odszraniać.'
                             : 'Lód przybywa powoli, więc odszraniania powinny być rzadkie.';
    }
    $s .= ' Najgorsze dla pompy są temperatury w okolicy zera przy dużej wilgotności: ciepłe powietrze niesie dużo wody. W siarczysty mróz powietrze jest suche i szronu przybywa kilka razy wolniej.';
    return $s;
}

/* ---------- bilans cieplny domu: [sdl_bilans] na stronie /projekt ----------
   Liczby z obliczeń OZC (PN-EN 12831, -18 °C) + porównanie sezonów z liczników: zużycie prądu pompy na dobę
   w funkcji średniej temperatury zewnętrznej (prosta regresja dla dni do 14 °C). */
function sdl_ozc() {
    return [
        'load_kw' => 3.6, 'load_wm2' => 34, 'heat_kwh' => 6042, 'heat_kwhm2' => 57, 'area' => 105.7,
        'load_thick_kw' => 3.4, 'garage_kw' => 3.2, 'garage_kwh' => 3909, 'pump_kw' => 15,
        'split' => [['Podłoga na gruncie', 27.2, '#f97316'], ['Wentylacja', 23.4, '#3b82f6'], ['Okna', 19.7, '#60a5fa'],
                    ['Ściany zewnętrzne', 16.3, '#94a3b8'], ['Dach', 10.5, '#64748b'], ['Drzwi', 2.8, '#475569']],
    ];
}

function sdl_season_of($d) { $y = (int) substr($d, 0, 4); $m = (int) substr($d, 5, 2); return $m >= 8 ? $y . '/' . substr($y + 1, 2) : ($y - 1) . '/' . substr($y, 2); }

function sdl_fit_seasons($by) {
    $fits = [];
    foreach ($by as $se => $xy) {
        $n = count($xy); if ($n < 10) continue;
        $mx = array_sum(array_column($xy, 0)) / $n; $my = array_sum(array_column($xy, 1)) / $n;
        $sxx = 0; $sxy = 0; foreach ($xy as [$x, $y]) { $sxx += ($x - $mx) ** 2; $sxy += ($x - $mx) * ($y - $my); }
        if ($sxx <= 0) continue;
        $b = $sxy / $sxx; $a = $my - $b * $mx;
        $fits[$se] = ['a' => round($a, 3), 'b' => round($b, 3), 'n' => $n, 'tmin' => round(min(array_column($xy, 0)), 1), 'tmax' => round(max(array_column($xy, 0)), 1), 'tmean' => round($mx, 1)];
    }
    ksort($fits);
    $cmp = null; $keys = array_keys($fits);
    if (count($keys) >= 2) {
        $new = $fits[end($keys)]; $old = $fits[$keys[count($keys) - 2]];
        $t = $new['tmean'];
        $yo = $old['a'] + $old['b'] * $t; $yn = $new['a'] + $new['b'] * $t;
        if ($yo > 0) $cmp = ['old' => $keys[count($keys) - 2], 'new' => end($keys), 't' => $t, 'old_v' => round($yo, 1), 'new_v' => round($yn, 1), 'diff' => round($yn - $yo, 1), 'pct' => round(($yn - $yo) / $yo * 100)];
    }
    return [(object) $fits, $cmp];
}

function sdl_seasons() {
    return sdl_cached('seasons2', function () {
        $today = (new DateTime('now', sdl_tz()))->format('Y-m-d');
        $pts = []; $mpts = []; $by = []; $mby = [];
        foreach (sdl_heiko_days('2025-08-01') as $d => $v) {
            if ($d >= $today || !isset($v['tout'])) continue;
            $se = sdl_season_of($d);
            if (isset($v['kwh'])) { $pts[] = [$d, round($v['tout'], 1), round($v['kwh'], 2), $se]; if ($v['tout'] <= 14) $by[$se][] = [$v['tout'], $v['kwh']]; }
            if (isset($v['mat']) && $v['tout'] <= 12 && $v['mat'] > 20) { $mpts[] = [$d, round($v['tout'], 1), round($v['mat'], 1), $se]; $mby[$se][] = [$v['tout'], $v['mat']]; }
        }
        [$fits, $cmp] = sdl_fit_seasons($by);
        [$mfits, $mcmp] = sdl_fit_seasons($mby);
        if ($cmp) { $cmp['kwh_old'] = $cmp['old_v']; $cmp['kwh_new'] = $cmp['new_v']; }
        return ['points' => $pts, 'fits' => $fits, 'compare' => $cmp, 'mat_points' => $mpts, 'mat_fits' => $mfits, 'mat_compare' => $mcmp];
    }, 600);
}

add_action('rest_api_init', function () {
    register_rest_route('sdl/v1', '/seasons', ['methods' => 'GET', 'callback' => function () { return sdl_seasons(); }, 'permission_callback' => '__return_true']);
});

/* „Czy 3,6 kW to realne?” – szacunek straty domu z wczorajszego zużycia prądu na ogrzewanie (CO) i średniej temperatury.
   COP przyjęty orientacyjnie (brak ciepłomierza), dlatego wynik podajemy jako przybliżenie. */
function sdl_reality_check() {
    return sdl_cached('reality1', function () {
        $tz = sdl_tz();
        $co = sdl_now_daily('heiko', 'energy_co', 10);
        $days = sdl_heiko_days((new DateTime('-12 days', $tz))->format('Y-m-d'));
        $today = (new DateTime('now', $tz))->format('Y-m-d');
        $cos = []; foreach ((array) $co as $r) $cos[$r[0]] = $r[1];
        krsort($days);
        foreach ($days as $d => $v) {
            if ($d >= $today || !isset($cos[$d], $v['tout']) || $v['tout'] > 8 || $cos[$d] < 3) continue;
            $cop = 3.0; $tin = 20;
            $pel = $cos[$d] / 24; $heat = $pel * $cop; $dt = $tin - $v['tout'];
            return ['day' => $d, 'tout' => round($v['tout'], 1), 'co_kwh' => round($cos[$d], 1), 'pel' => round($pel, 2), 'cop' => $cop,
                    'heat' => round($heat, 1), 'dt' => round($dt), 'at18' => round($heat / $dt * 38, 1)];
        }
        return null;
    }, 1800);
}

function sdl_donut($split) {
    $r = 70; $c = 2 * M_PI * $r; $off = 0; $seg = '';
    foreach ($split as [$label, $pct, $col]) {
        $len = $c * $pct / 100;
        $seg .= sprintf('<circle r="%d" cx="90" cy="90" fill="none" stroke="%s" stroke-width="28" stroke-dasharray="%.2f %.2f" stroke-dashoffset="%.2f" transform="rotate(-90 90 90)"><title>%s: %s%%</title></circle>',
            $r, $col, $len, $c - $len, -$off, esc_attr($label), sdl_num($pct));
        $off += $len;
    }
    return '<svg class="sdl-donut" viewBox="0 0 180 180" role="img" aria-label="Struktura strat ciepła">' . $seg . '<text x="90" y="86" text-anchor="middle" class="sdl-donut-big">27%</text><text x="90" y="106" text-anchor="middle" class="sdl-donut-small">przez podłogę</text></svg>';
}

add_shortcode('sdl_bilans', function () {
    wp_enqueue_style('sdl', SDL_URL . 'assets/sdl.css', [], SDL_VER);
    wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', [], '4.4.1', true);
    wp_enqueue_script('sdl-bilans', SDL_URL . 'assets/sdl-bilans.js', ['chartjs'], SDL_VER, true);
    wp_localize_script('sdl-bilans', 'SDL', ['api' => esc_url_raw(rest_url('sdl/v1/'))]);
    $o = sdl_ozc(); $S = sdl_seasons(); $c = $S['compare'];
    $legend = '';
    foreach ($o['split'] as [$l, $p, $col]) $legend .= '<li><span style="background:' . $col . '"></span>' . esc_html($l) . ' <b>' . sdl_num($p) . '%</b></li>';
    ob_start(); ?>
<div class="sdl-wrap sdl-bilans">
  <h3 class="sdl-bil-h">Ile ciepła potrzebuje ten dom</h3>
  <p>Przed budową policzyliśmy straty ciepła zgodnie z normą (obliczenia OZC dla mroźnego dnia: −18 °C na zewnątrz, 20 °C w środku). W rzeczywistości ocieplenie jest nawet lepsze niż w obliczeniach – pianą wypełniliśmy przetłoczenia ścian na płasko.</p>
  <div class="sdl-kpis">
    <div class="sdl-kpi"><div class="sdl-kpi-label">Moc potrzebna przy −18 °C</div><div class="sdl-kpi-val"><?php echo sdl_num($o['load_kw']); ?> <small>kW</small></div><div class="sdl-kpi-sub"><?php echo (int) $o['load_wm2']; ?> W na m² podłogi</div></div>
    <div class="sdl-kpi"><div class="sdl-kpi-label">Ciepło na ogrzewanie w roku</div><div class="sdl-kpi-val"><?php echo number_format($o['heat_kwh'], 0, ',', ' '); ?> <small>kWh</small></div><div class="sdl-kpi-sub"><?php echo (int) $o['heat_kwhm2']; ?> kWh na m² (sezon standardowy)</div></div>
    <div class="sdl-kpi"><div class="sdl-kpi-label">Zainstalowana pompa ciepła</div><div class="sdl-kpi-val"><?php echo (int) $o['pump_kw']; ?> <small>kW</small></div><div class="sdl-kpi-sub">ok. 4 × więcej niż strata ciepła</div></div>
  </div>
  <p>Dlaczego aż tyle? Pompa trafiła do nas „rzutem na taśmę”: ostatnia sztuka z wyprzedaży, w cenie tak niskiej, że zbrodnią byłoby nie skorzystać (<a href="https://youtu.be/XT13_MKPdKc" target="_blank" rel="noopener">zobacz film „Pompa ciepła za 2999 zł??”</a>). Do tego w drugim etapie dojdzie garaż z kolejnych czterech kontenerów (według obliczeń kolejne <?php echo sdl_num($o['garage_kw']); ?> kW). Duża pompa w małym domu zwykle włącza się i wyłącza co chwilę – u nas tak się nie dzieje, bo pracuje na bufor 700 l i startuje średnio kilka razy na dobę. <a href="/heiko/">Zobacz, jak pracuje na żywo →</a></p>

  <h3 class="sdl-bil-h">Czy 3,6 kW przy −18 °C to w ogóle możliwe?</h3>
  <?php $R = sdl_reality_check(); ?>
  <p>Brzmi nieprawdopodobnie mało – ale trzeba odróżnić prąd od ciepła. Pompa ciepła z 1 kWh prądu daje kilka kWh ciepła, bo resztę pobiera z powietrza. Ile dokładnie, zależy od temperatury na zewnątrz i temperatury wody: przy wodzie ok. 30 °C do mat sufitowych i lekkim mrozie to mniej więcej 3 kWh ciepła z 1 kWh prądu.</p>
  <?php if ($R) { $dl = wp_date('j.m', strtotime($R['day'] . ' 12:00'), $tz = sdl_tz()); ?>
  <p class="sdl-bil-calc">Policzmy na prawdziwych danych. <b><?php echo esc_html($dl); ?></b> średnio na zewnątrz było <b><?php echo sdl_num($R['tout']); ?> °C</b>, a pompa zużyła na samo ogrzewanie (bez ciepłej wody) <b><?php echo sdl_num($R['co_kwh']); ?> kWh prądu</b> – średnio <?php echo sdl_num($R['pel'], 2); ?> kW. Razy ok. <?php echo sdl_num($R['cop'], 0); ?> to <b>ok. <?php echo sdl_num($R['heat']); ?> kW ciepła</b> przy różnicy <?php echo (int) $R['dt']; ?> stopni między środkiem a zewnątrz. Przy −18 °C ta różnica wynosi 38 stopni, więc wychodzi <b>ok. <?php echo sdl_num($R['at18']); ?> kW</b>.</p>
  <?php } ?>
  <p><?php
    $verdict = !$R ? 'Z pomiarów zimowych wychodzi strata tego samego rzędu co w obliczeniach – nie kilka razy większa.'
      : ($R['at18'] > 3.9 ? 'Rzeczywista strata wychodzi więc trochę większa niż 3,6 kW z obliczeń, ale tego samego rzędu – nie kilka razy większa.'
      : ($R['at18'] >= 3.2 ? 'To praktycznie tyle, ile wyszło z obliczeń.' : 'To nawet mniej niż w obliczeniach – ale pamiętajmy, że COP przyjęliśmy szacunkowo.'));
    echo esc_html($verdict); ?> Na wynik wpływa kilka rzeczy: w górę – energia zużywana na odszranianie, straty bufora i wężownicy (woda z wężownicy jest o kilka stopni chłodniejsza niż w zbiorniku) i to, że obliczenia zakładają idealne wykonanie; w dół – domownicy i urządzenia, które oddają ciepło, więc część strat pokrywają oni, a nie pompa.</p>
  <h3 class="sdl-bil-h">Sprawdzian z czasów grzałek: 3,4 kW</h3>
  <p>Najprościej stratę domu widać na grzałkach elektrycznych – grzałka zamienia 1 kWh prądu dokładnie w 1 kWh ciepła, więc nie trzeba zgadywać żadnego COP. A do 25 grudnia 2025 nasz dom grzały właśnie grzałki w buforze.</p>
  <p class="sdl-bil-calc">W najzimniejsze dni przed Wigilią 2025 (ok. −10 °C) same grzałki zużyły do <b>92 kWh na dobę</b>. W sierpniu, bez ogrzewania, ciepła woda brała średnio ok. 9,5 kWh na dobę. Zostaje <b>92 − 10 = 82 kWh</b> na ogrzewanie, czyli przez 24 godziny <b>ok. 3,4 kW</b> – to zmierzona licznikiem strata domu przy −10 °C. <?php echo sdl_yt('rKTyAxtBmX8', 1358, 'liczę to w filmie'); ?></p>
  <p>Według obliczeń przy −10 °C powinno to być ok. 2,8 kW (3,6 kW przy −18 °C, przeliczone na mniejszą różnicę temperatur). Wyszło ok. 20% więcej – mniej więcej tyle, ile szacuję straty przez wychłodzoną podłogę, zanim powstał murek (25–30%, <?php echo sdl_yt('CftcsQWNF08', 872, 'mówię o tym tutaj'); ?>). Jedno i drugie to wciąż ten sam rząd wielkości: kilka kilowatów, a nie kilkanaście.</p>
  <p>Kolejne sprawdziany: ciepłomierz na wyjściu z pompy pokaże prawdziwy COP zamiast przyjętego „ok. 3”, a pełne dane dobowe z grzałek ze sterownika bufora dołączymy do wykresów, gdy tylko go podłączymy. Porównanie grzałek z pompą krok po kroku jest na <a href="/heiko/">stronie pompy ciepła</a>.</p>

  <h3 class="sdl-bil-h">Drugi sprawdzian: doba z pompą ciepła (27.11.2026)</h3>
  <p class="sdl-bil-calc">Średnio −1,0 °C na zewnątrz. Według OZC dom potrzebuje wtedy 3,6 kW × 21/38 ≈ <b>2,0 kW, czyli ok. 48 kWh ciepła na dobę</b>. Pompa zużyła 22,8 kWh prądu; po odjęciu ok. 4,3 kWh na ciepłą wodę zostaje ok. 18,5 kWh na ogrzewanie. Przy COP ok. 2,65 (zmierzonym metodą bufora) to <b>ok. 49 kWh ciepła</b> – praktycznie dokładnie tyle, ile przewiduje projekt.</p>
  <p>Zimą 2025, na grzałkach i przed murkiem, instalacja musiała dawać o ok. 20% więcej, niż wynikało z obliczeń. Teraz – z murkiem – trafia w OZC. To dwa niezależne pomiary (licznik grzałek, w których nie ma żadnego COP, i bilans bufora pompy), a razem mówią to samo: obliczenia były dobre, a nadwyżkę zjadała wychłodzona podłoga. Uczciwe zastrzeżenie: w obu przypadkach dom dostaje dodatkowo ciepło od ludzi i ok. 12 kWh prądu na dobę z pozostałych urządzeń, więc jego całkowita strata jest wyższa niż samo ciepło z instalacji – ale porównanie „przed i po” jest liczone tak samo.</p>

  <h4 class="sdl-bil-h">Ile dał murek?</h4>
  <p>Łatwo tu o pomyłkę: 20% to nie „20% z 27% strat przez podłogę”, tylko 20% <b>całego</b> zapotrzebowania na ogrzewanie. OZC od początku zakłada podłogę nad zamkniętą przestrzenią pod domem – czyli taką, jaką mamy dopiero z murkiem. Przyjmijmy zapotrzebowanie z OZC za 100:</p>
  <div class="sdl-table-wrap"><table class="sdl-table">
    <thead><tr><th scope="col"></th><th scope="col">Bez murku (zima 2025)</th><th scope="col">Z murkiem (teraz)</th><th scope="col">OZC</th></tr></thead>
    <tbody>
      <tr><td>Całe zapotrzebowanie na ogrzewanie</td><td><b>ok. 120</b></td><td><b>ok. 100</b></td><td>100</td></tr>
      <tr><td>z tego podłoga</td><td>ok. 47</td><td>ok. 27</td><td>27</td></tr>
      <tr><td>reszta (ściany, okna, dach, wentylacja)</td><td>ok. 73</td><td>ok. 73</td><td>73</td></tr>
    </tbody>
  </table></div>
  <p>Bez murku pod kontenerami hulał wiatr i podłoga traciła prawie dwa razy więcej, niż zakłada projekt. Murek zamknął tę przestrzeń i podłoga wróciła do wartości z obliczeń. W efekcie <b>całe ogrzewanie spadło o ok. 17–20%</b>, a <b>same straty przez podłogę o ok. 40%</b> – bardzo dużo jak na ścianę z bloczków. To na razie wynik z dwóch porównanych dób (z dokładnością pomiaru COP ±10–15%); pewniejszą liczbę da sezon na wykresie „zima przed i po murku” niżej.</p>

  <h3 class="sdl-bil-h">Gdzie ucieka ciepło</h3>
  <div class="sdl-bil-split">
    <div><?php echo sdl_donut($o['split']); ?><ul class="sdl-legend"><?php echo $legend; ?></ul></div>
    <div>
      <p>Według obliczeń najwięcej ciepła – ponad jedna czwarta – ucieka przez podłogę. Sama stal ścian, której wszyscy się boją w domach z kontenerów, wcale nie jest największym problemem.</p>
      <p>Obliczenia zakładają podłogę szczelnie przylegającą do gruntu. Pierwszej zimy było gorzej. Grunt pod kontenerami osiadł i pod podłogą zrobiła się pustka, przez którą hulał mroźny wiatr. Podłoga była chłodniejsza, więc trzeba było mocniej dogrzewać: wyższa temperatura wody w matach i więcej prądu zużytego przez pompę. W tym roku obmurowaliśmy dom dookoła bloczkami betonowymi – podłoga jest teraz wyraźnie cieplejsza. Ile to dało, pokazuje wykres niżej.</p>
      <figure class="sdl-bil-photo"><img src="<?php echo esc_url(SDL_URL . 'assets/img/murek-fundament.webp'); ?>" width="730" height="800" loading="lazy" alt="Murek z bloczków betonowych zamykający przestrzeń pod kontenerami domu"><figcaption>Murek z bloczków zamknął przestrzeń pod kontenerami.</figcaption></figure>
    </div>
  </div>

  <h3 class="sdl-bil-h">Projekt kontra rzeczywistość: zima przed i po murku</h3>
  <p class="sdl-bil-lead" data-b="lead"><?php
    if ($c) echo esc_html('Przy średniej temperaturze ' . sdl_num($c['t']) . ' °C pompa zużywa teraz ok. ' . sdl_num($c['kwh_new']) . ' kWh prądu na dobę, a zeszłej zimy przy tej samej temperaturze zużywała ok. ' . sdl_num($c['kwh_old']) . ' kWh – to ' . ($c['pct'] <= 0 ? abs($c['pct']) . '% mniej' : $c['pct'] . '% więcej') . '.');
    else echo 'Porównanie pojawi się, gdy w nowym sezonie zbierze się co najmniej 10 dni grzewczych.';
  ?></p>
  <div class="sdl-card"><div class="sdl-canvas sdl-chart-tall"><canvas data-b="chart" role="img" aria-label="Dobowe zużycie prądu pompy w funkcji temperatury zewnętrznej – sezon przed i po murku"></canvas></div>
  <p class="sdl-note">Każdy punkt to jedna doba: średnia temperatura zewnętrzna i zużycie prądu przez pompę (ogrzewanie razem z ciepłą wodą, ok. 2–3 kWh na dobę przez cały rok). Linie pokazują trend dla dni do 14 °C. Porównujemy zużycie przy tej samej temperaturze, więc łagodniejsza czy ostrzejsza zima nie zafałszowuje wyniku. Dane z pomiarów od stycznia 2026; wykres uzupełnia się sam każdego dnia.</p></div>

  <h3 class="sdl-bil-h">Temperatura wody w matach: zeszła zima i teraz</h3>
  <?php $mc = $S['mat_compare']; ?>
  <p class="sdl-bil-lead"><?php
    if ($mc) echo esc_html('Przy średniej ' . sdl_num($mc['t']) . ' °C na zewnątrz woda do mat sufitowych ma teraz ok. ' . sdl_num($mc['new_v']) . ' °C, a zeszłej zimy przy tej samej temperaturze potrzebowała ok. ' . sdl_num($mc['old_v']) . ' °C – o ' . sdl_num(abs($mc['diff'])) . ' °C ' . ($mc['diff'] <= 0 ? 'mniej' : 'więcej') . '.');
    else echo 'Porównanie pojawi się, gdy w nowym sezonie zbierze się co najmniej 10 dni grzewczych.';
  ?></p>
  <p>To drugi, niezależny dowód. Nie zależy od wydajności pompy: im mniej ciepła ucieka, tym chłodniejsza woda wystarcza, żeby w domu było tak samo ciepło. W mrozy zeszłej zimy trzeba było ustawiać na zaworze mieszającym nawet 37 °C. Temperaturę wody ustawiamy sami – zawsze tak, żeby w domu było komfortowo – więc ten wykres pokazuje, ile ciepła dom realnie potrzebował, a nie tylko automatykę.</p>
  <div class="sdl-card"><div class="sdl-canvas sdl-chart-tall"><canvas data-b="chart-mat" role="img" aria-label="Średnia dobowa temperatura wody zasilającej maty w funkcji temperatury zewnętrznej – sezon przed i po murku"></canvas></div>
  <p class="sdl-note">Średnia dobowa temperatura wody za zaworem mieszającym (zasilanie mat sufitowych) – dane z historii sterownika Sinum i z Home Assistant, dla dni grzewczych do 12 °C. Temperatura zewnętrzna z tego samego czujnika co na wykresie wyżej.</p></div>
</div>
<?php
    return ob_get_clean();
});

/* ---------- łączność: [sdl_starlink] – sprzęt, dlaczego satelita i ile danych dom wysyła na tę stronę ---------- */
function sdl_stream_stats() {
    return sdl_cached('stream1', function () {
        global $wpdb; $t = sdl_table();
        $rows = $wpdb->get_results("SELECT device, COUNT(*) AS n, COUNT(DISTINCT metric) AS m FROM $t WHERE ts >= UTC_TIMESTAMP() - INTERVAL 1 DAY GROUP BY device ORDER BY n DESC");
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t");
        $first = $wpdb->get_var("SELECT MIN(ts) FROM $t WHERE metric NOT IN ('kwh_day','starts_day','run_day','tout_day','mat_day')");
        $dev = []; $day = 0;
        foreach ($rows as $r) { if (in_array($r->device, ['heiko_defrost', 'heiko_cop'], true)) continue; $dev[] = [$r->device, (int) $r->n, (int) $r->m]; $day += (int) $r->n; }
        return ['devices' => $dev, 'day' => $day, 'total' => $total, 'first' => $first];
    }, 600);
}

add_shortcode('sdl_starlink', function () {
    wp_enqueue_style('sdl', SDL_URL . 'assets/sdl.css', [], SDL_VER);
    $st = sdl_stream_stats();
    $names = ['heiko' => 'Pompa ciepła', 'dom' => 'Cały dom (licznik)', 'reqnet' => 'Rekuperator', 'maty' => 'Maty i zawór mieszający', 'pompa_cwu' => 'Pompa cyrkulacyjna CWU', 'serwer' => 'Serwer domowy'];
    $pushes = 288; $bytes_push = 1200;                         /* jedna wysyłka: wszystkie urządzenia, ok. 1,2 KB danych */
    $mb_day = $pushes * $bytes_push / 1e6; $mb_https = 3.5;
    $sec = $mb_https * 8 / 200;                                 /* przy 200 Mbit/s */
    $nf = function ($v) { return number_format((float) $v, 0, ',', ' '); };
    ob_start(); ?>
<div class="sdl-wrap sdl-bilans sdl-starlink">
  <h3 class="sdl-bil-h">Dlaczego internet z kosmosu</h3>
  <p>Zasięg komórkowy na naszej działce jest beznadziejny. Od najbliższych nadajników skutecznie odcinają nas dwie ściany lasu, a jedyny maszt z dobrą, niczym nieprzesłoniętą widocznością stoi ok. 12 km dalej w linii prostej.</p>
  <p>Do tego sam dom działa jak klatka Faradaya – cztery stalowe kontenery tłumią sygnał komórkowy prawie do zera. Ratuje nas stabilne i szybkie Wi-Fi z satelity: dziś ok. <b>200 Mbit/s</b>. Rozmowy telefoniczne idą przez internet (VoIP i połączenia przez Wi-Fi – Wi-Fi Calling), więc w domu dzwonimy normalnie, mimo że telefon „nie widzi” sieci.</p>

  <h3 class="sdl-bil-h">Sprzęt</h3>
  <div class="sdl-kpis sdl-starlink-hw">
    <div class="sdl-kpi"><div class="sdl-kpi-label">Antena</div><div class="sdl-kpi-val sdl-kpi-txt">Starlink Mini</div><div class="sdl-kpi-sub">terminal satelitarny</div></div>
    <div class="sdl-kpi"><div class="sdl-kpi-label">Router główny</div><div class="sdl-kpi-val sdl-kpi-txt">Router Gen3</div><div class="sdl-kpi-sub">serce sieci w domu</div></div>
    <div class="sdl-kpi"><div class="sdl-kpi-label">Sieć mesh</div><div class="sdl-kpi-val sdl-kpi-txt">2 × Router Mini</div><div class="sdl-kpi-sub">Wi-Fi w całym domu i gniazda LAN dla komputerów stacjonarnych</div></div>
  </div>
  <p>Stalowe ściany zatrzymują nie tylko sygnał z zewnątrz, ale też Wi-Fi między kontenerami. Dlatego oprócz głównego routera pracują dwa routery Mini w sieci mesh – rozprowadzają Wi-Fi po całym domu, a komputery stacjonarne są podłączone do nich przewodem LAN.</p>

  <h3 class="sdl-bil-h">Ciekawostka: ile danych dom wysyła na tę stronę</h3>
  <p>Wszystkie liczby, wykresy i kafelki na tej stronie przychodzą prosto z naszego domu – przez to samo łącze satelitarne. Co 5 minut Home Assistant zbiera odczyty ze wszystkich urządzeń i wysyła je tutaj: to <?php echo (int) $pushes; ?> wysyłek na dobę, za każdym razem ok. 56 wartości (temperatury, moce, liczniki energii, przepływy powietrza).</p>
  <div class="sdl-kpis">
    <div class="sdl-kpi"><div class="sdl-kpi-label">Odczytów w ostatniej dobie</div><div class="sdl-kpi-val"><?php echo $nf($st['day']); ?></div><div class="sdl-kpi-sub">zapisanych w bazie strony</div></div>
    <div class="sdl-kpi"><div class="sdl-kpi-label">Dane na dobę</div><div class="sdl-kpi-val">ok. <?php echo sdl_num($mb_day, 2); ?> <small>MB</small></div><div class="sdl-kpi-sub">z szyfrowaniem i nagłówkami ok. <?php echo sdl_num($mb_https, 1); ?> MB</div></div>
    <div class="sdl-kpi"><div class="sdl-kpi-label">Przy 200 Mbit/s to</div><div class="sdl-kpi-val"><?php echo sdl_num($sec, 2); ?> <small>s</small></div><div class="sdl-kpi-sub">tyle trwałoby wysłanie danych z całej doby naraz</div></div>
    <div class="sdl-kpi"><div class="sdl-kpi-label">Odczytów w bazie łącznie</div><div class="sdl-kpi-val"><?php echo $nf($st['total']); ?></div><div class="sdl-kpi-sub"><?php echo $st['first'] ? 'pomiary od ' . esc_html(wp_date('j.m.Y', strtotime($st['first'] . ' UTC'), sdl_tz())) . ' + historia z Home Assistant' : ''; ?></div></div>
  </div>
  <?php $use = [['maj', 1.17], ['czerwiec', 2.00], ['lipiec', 1.40], ['sierpień', 2.03], ['wrzesień', 1.46], ['październik', 1.46], ['listopad*', 1.02]];
        $u_sum = array_sum(array_column($use, 1)); $u_days = 210; $u_gb_day = $u_sum * 1000 / $u_days; $u_max = max(array_column($use, 1));
        $u_share = $mb_https / ($u_gb_day * 1000) * 100; ?>
  <h3 class="sdl-bil-h">A ile danych pobieramy</h3>
  <p>Wysyłanie odczytów na stronę to drobiazg. W drugą stronę płynie znacznie więcej: praca zdalna, wideorozmowy, filmy, aktualizacje i kopie zapasowe. Od maja przez łącze satelitarne przeszło łącznie ok. <b><?php echo sdl_num($u_sum, 1); ?> TB</b> danych – średnio ok. <b><?php echo sdl_num($u_gb_day, 0); ?> GB na dobę</b>. Bez dobrego internetu ten dom po prostu by nie działał.</p>
  <div class="sdl-kpis">
    <div class="sdl-kpi"><div class="sdl-kpi-label">Łącznie od maja</div><div class="sdl-kpi-val"><?php echo sdl_num($u_sum, 1); ?> <small>TB</small></div><div class="sdl-kpi-sub">dane z aplikacji Starlink</div></div>
    <div class="sdl-kpi"><div class="sdl-kpi-label">Średnio na miesiąc</div><div class="sdl-kpi-val"><?php echo sdl_num(($u_sum - 1.02) / 6, 1); ?> <small>TB</small></div><div class="sdl-kpi-sub">pełne miesiące maj–październik</div></div>
    <div class="sdl-kpi"><div class="sdl-kpi-label">Rekordowa doba</div><div class="sdl-kpi-val">ok. 500 <small>GB</small></div><div class="sdl-kpi-sub">koniec czerwca</div></div>
  </div>
  <div class="sdl-card">
    <div class="sdl-head"><h3>Pobrane dane – miesiąc po miesiącu</h3></div>
    <div class="sdl-ubars"><?php foreach ($use as [$m, $tb]) { echo '<div class="sdl-ubar"><span class="sdl-ubar-m">' . esc_html($m) . '</span><span class="sdl-ubar-t"><span style="width:' . round($tb / $u_max * 100) . '%"></span></span><span class="sdl-ubar-v">' . sdl_num($tb, 2) . ' TB</span></div>'; } ?></div>
    <p class="sdl-note">* listopad do 26 dnia miesiąca. Dane wg aplikacji Starlink (liczone w czasie UTC). Na tle tego ruchu odczyty wysyłane na tę stronę to ok. <?php echo sdl_num($u_share, 3); ?>% – ułamek promila.</p>
  </div>
  <div class="sdl-card">
    <div class="sdl-head"><h3>Kto ile „mówi” – ostatnia doba</h3></div>
    <div class="sdl-table-wrap"><table class="sdl-table">
      <thead><tr><th scope="col">Urządzenie</th><th scope="col">Rodzajów odczytów</th><th scope="col">Odczytów na dobę</th></tr></thead>
      <tbody><?php foreach ($st['devices'] as [$d, $n, $m]) { echo '<tr><td>' . esc_html($names[$d] ?? $d) . '</td><td>' . (int) $m . '</td><td>' . $nf($n) . '</td></tr>'; } ?></tbody>
    </table></div>
    <p class="sdl-note">Wysyłane są tylko odczyty urządzeń – bez adresów, nazw komputerów czy innych informacji o domowej sieci. Gdy łącze na chwilę zniknie (np. w nocy, gdy Starlink instaluje aktualizacje), na stronie pojawi się przerwa w danych, a odczyty popłyną dalej po powrocie sieci.</p>
  </div>
</div>
<?php
    return ob_get_clean();
});

/* link do konkretnej minuty filmu na YouTube */
function sdl_yt($id, $sec, $label = 'zobacz w filmie') {
    $t = sprintf('%d:%02d', intdiv($sec, 60), $sec % 60);
    return '<a class="sdl-yt" href="' . esc_url('https://youtu.be/' . $id . '?t=' . (int) $sec) . '" target="_blank" rel="noopener">▶ ' . esc_html($label) . ' (' . $t . ')</a>';
}

/* ---------- [sdl_grzalki] – rok na grzałkach kontra pompa ciepła (liczby z liczników, pokazywane w filmach) ---------- */
add_shortcode('sdl_grzalki', function () {
    wp_enqueue_style('sdl', SDL_URL . 'assets/sdl.css', [], SDL_VER);
    $A = 'rKTyAxtBmX8'; $B = 'CftcsQWNF08';
    $kpi = function ($label, $val, $unit, $sub, $yt, $accent = '#f97316') {
        return '<div class="sdl-kpi" style="border-top-color:' . $accent . '"><div class="sdl-kpi-label">' . esc_html($label) . '</div><div class="sdl-kpi-val">' . $val . ($unit ? ' <small>' . esc_html($unit) . '</small>' : '') . '</div><div class="sdl-kpi-sub">' . $sub . '</div><div class="sdl-kpi-sub">' . $yt . '</div></div>';
    };
    ob_start(); ?>
<div class="sdl-wrap sdl-bilans sdl-grzalki">
  <p class="sdl-bil-lead">Zanim pojawiła się pompa, dom przez cały 2025 rok grzały grzałki elektryczne w buforze. Grzałka zamienia 1 kWh prądu w 1 kWh ciepła – więc to idealny punkt odniesienia. Od 25 grudnia 2025 grzeje pompa ciepła. Poniżej liczby z liczników, które pokazuję w filmach – przy każdej jest link do minuty, w której o tym mówię.</p>
  <div class="sdl-kpis">
    <?php
    echo $kpi('Rok 2025 na grzałkach', '9 213', 'kWh', 'ogrzewanie i ciepła woda, od połowy kwietnia, 5 osób', sdl_yt($A, 1320, 'film'), '#7c8794');
    echo $kpi('Grudzień 2025 – grzałki', '61', 'kWh/dobę', '1 546 kWh w 25 dni, najwięcej 92 kWh w dobę', sdl_yt($B, 1327, 'film'), '#7c8794');
    echo $kpi('Styczeń 2026 – pompa', '33', 'kWh/dobę', '1 059 kWh w miesiącu (mediana 34,3), mrozy do −13 °C', sdl_yt($B, 861, 'film'));
    echo $kpi('O połowę mniej prądu', 'COP ≈ 2', '', 'przy ok. −12 °C, z porównania grzałek i pompy', sdl_yt($B, 1384, 'film'));
    echo $kpi('Przy ok. 0 °C', 'COP ≈ 3', '', '700 l wody o 8 °C cieplej = 6,5 kWh ciepła za 2 kWh prądu', sdl_yt($A, 2088, 'film'));
    echo $kpi('Starty sprężarki', '15', 'na dobę', 'średnio w styczniu, najwięcej 23 – czyli najwyżej raz na godzinę', sdl_yt($B, 1143, 'film'));
    ?>
  </div>
  <h3 class="sdl-bil-h">Ile mocy naprawdę potrzebuje ten dom</h3>
  <p class="sdl-bil-calc">Z grzałek da się to policzyć wprost. W najzimniejsze dni przed Wigilią 2025 (ok. −10 °C) grzałki zużyły 92 kWh na dobę. Ciepła woda brała wtedy ok. 10 kWh (w sierpniu, bez ogrzewania, średnio 9,5 kWh na dobę). Zostaje 82 kWh na ogrzewanie, czyli <b>ok. 3,4 kW</b> przez całą dobę. <?php echo sdl_yt($A, 1358, 'liczę to w filmie'); ?></p>
  <p>Dlatego pompa 15 kW w tym domu wcale się nie męczy: pracuje długo i spokojnie na bufor 700 l, zamiast co chwilę się włączać. Więcej o tym, skąd się biorą te liczby i ile według obliczeń traci dom, jest na <a href="/projekt/">stronie projektu</a>.</p>
  <h3 class="sdl-bil-h">„Pompa stanęła na mrozie”? Nie mróz, tylko prąd</h3>
  <p>Na początku lutego 2026, przy −15 °C, rano pompa stała. Nie zamarzła – w sieci było kilka bardzo krótkich zaników napięcia, po których elektronika weszła w tryb awaryjny. Wystarczyło ją wyłączyć i włączyć. Przy mrozie pracowała cały czas bez grzałek, a bufor zdążył przez ten czas utrzymać ciepło w domu. <?php echo sdl_yt($B, 60, 'opowiadam o tym'); ?></p>
  <h3 class="sdl-bil-h">Prognoza do sprawdzenia: styczeń 2027</h3>
  <p>W styczniu 2026 część ciepła uciekała przez podłogę – ziemia pod kontenerami siadła i pod domem hulał mróz. Szacowałem, że to 25–30% zużycia i że po naprawie styczeń powinien zamknąć się w <b>ok. 700 kWh zamiast 1 059</b>. <?php echo sdl_yt($B, 872, 'prognoza w filmie'); ?> Murek jest już zrobiony – w styczniu 2027 sprawdzimy to tutaj na żywo. Pierwszy sygnał już jest: na wykresie „zużycie na każdy stopień” wyżej ta jesień wychodzi wyraźnie poniżej średniej z zeszłej zimy.</p>
  <p class="sdl-note">Filmy: „Pompa ciepła zimą – czy działa i ile bierze prądu?” oraz „I mnie dopadło… Pompa ciepła stanęła przy mrozach”. Szczegółowe dane dobowe z grzałek (ze sterownika bufora) dołączymy do wykresów, gdy tylko go podłączymy – wtedy porównamy grzałki i pompę dzień po dniu przy tych samych temperaturach.</p>
</div>
<?php
    return ob_get_clean();
});

/* ---------- [sdl_cop] – co naprawdę mówi COP: laboratorium kontra prawdziwa instalacja ---------- */
add_shortcode('sdl_cop', function () {
    wp_enqueue_style('sdl', SDL_URL . 'assets/sdl.css', [], SDL_VER);
    $A = 'rKTyAxtBmX8'; $B = 'CftcsQWNF08';
    ob_start(); ?>
<div class="sdl-wrap sdl-bilans sdl-cop">
  <p class="sdl-bil-lead">Producenci chwalą się COP 4,5 i więcej. U nas zimą wychodzi ok. 2–3. Czy to znaczy, że pompa jest słaba? Nie – to znaczy, że porównujemy dwie zupełnie różne rzeczy. Poniżej porządkujemy, co i jak mierzymy, z czym to porównujemy i dlaczego na prawdziwej instalacji łatwo się rozczarować.</p>

  <h3 class="sdl-bil-h">Uczciwe zastrzeżenie: COP na razie szacujemy</h3>
  <p>COP to stosunek ciepła oddanego przez pompę do zużytego prądu. Prąd mierzymy dokładnie, ale ciepła – jeszcze nie, bo nie mamy ciepłomierza. Dlatego wszystkie liczby poniżej to szacunki z dwóch niezależnych metod, a nie pomiar.</p>

  <h3 class="sdl-bil-h">Co już wiemy z naszych danych</h3>
  <div class="sdl-kpis">
    <div class="sdl-kpi"><div class="sdl-kpi-label">Przy ok. −10…−12 °C</div><div class="sdl-kpi-val">≈ 1,9–2</div><div class="sdl-kpi-sub">porównanie zużycia grzałek i pompy przy podobnych mrozach</div><div class="sdl-kpi-sub"><?php echo sdl_yt($B, 1384, 'film'); ?></div></div>
    <div class="sdl-kpi" style="border-top-color:#3b82f6"><div class="sdl-kpi-label">Punkt biwalentny, ok. −7 °C</div><div class="sdl-kpi-val">≈ 2,3–2,6</div><div class="sdl-kpi-sub">szacunek – wartość pośrednia między dwoma pomiarami obok, nie pomiar</div></div>
    <div class="sdl-kpi"><div class="sdl-kpi-label">Przy ok. 0 °C</div><div class="sdl-kpi-val">≈ 3</div><div class="sdl-kpi-sub">bufor 700 l ogrzany o 8 °C: 6,5 kWh ciepła za 2 kWh prądu</div><div class="sdl-kpi-sub"><?php echo sdl_yt($A, 2088, 'film'); ?></div></div>
  </div>
  <p>Punkt biwalentny to temperatura zewnętrzna, poniżej której sama pompa przestaje wystarczać i trzeba ją wspierać (np. grzałką). W naszym klimacie przyjmuje się go zwykle w okolicach −7 °C – i to jest też jeden ze standardowych punktów, w których bada się pompy (A-7/W35). Nasza pompa 15 kW przy tej temperaturze ma jeszcze ogromny zapas mocy, więc w praktyce pracuje bez grzałki nawet w największe mrozy.</p>

  <h3 class="sdl-bil-h">Skąd u producentów COP 4,5 i więcej</h3>
  <p>Te liczby są prawdziwe – ale zmierzone w zupełnie innych warunkach niż u nas zimą. Najczęściej podaje się punkt <b>A7/W35</b>: +7 °C powietrza na zewnątrz i woda 35 °C, w laboratorium, w stanie ustalonym. Bez odszraniania, bez strat bufora i wężownicy, bez podgrzewania ciepłej wody do 48 °C.</p>
  <div class="sdl-table-wrap"><table class="sdl-table">
    <thead><tr><th scope="col">Warunki badania</th><th scope="col">Na zewnątrz</th><th scope="col">Woda</th><th scope="col">Typowy COP nowoczesnej pompy inwerterowej</th></tr></thead>
    <tbody>
      <tr><td>A7/W35 – „katalogowy”</td><td>+7 °C</td><td>35 °C</td><td>4–5</td></tr>
      <tr><td>A2/W35</td><td>+2 °C</td><td>35 °C</td><td>3,5–4</td></tr>
      <tr><td>A-7/W35 – okolice punktu biwalentnego</td><td>−7 °C</td><td>35 °C</td><td>2,5–3</td></tr>
    </tbody>
  </table></div>
  <p>W warunkach A7/W35 COP 4–5 osiąga większość nowoczesnych pomp inwerterowych – także te tańsze. O wyniku decyduje przede wszystkim różnica między temperaturą powietrza a temperaturą wody, a dopiero potem marka. Każdy stopień niższej temperatury wody to mniej więcej 2–3% lepszy COP.</p>

  <h3 class="sdl-bil-h">Dlaczego u nas zimą wychodzi mniej</h3>
  <ul>
    <li><b>Bufor grzejemy do 41–44 °C</b>, a nie do 35 °C.</li>
    <li><b>Wężownica w buforze zabiera 2–3 stopnie.</b> Ciepło na maty odbieramy miedzianą wężownicą w dolnej części zbiornika. Żeby wymiana w ogóle zaszła, woda w zbiorniku musi być cieplejsza od wody w wężownicy – u nas o co najmniej 2–3 K. <?php echo sdl_yt($B, 632, 'pokazuję to w filmie'); ?></li>
    <li><b>Maty dostają ok. 35–38 °C</b> zamiast ok. 30 °C – przez wychłodzoną podłogę (zanim powstał murek) i za małą powierzchnię mat (88 m² na 102 m² domu).</li>
    <li><b>W zużyciu jest też ciepła woda 48 °C i odszranianie</b> – a tego w teście katalogowym nie ma.</li>
  </ul>
  <p>W sumie pompa pracuje na wodzie o 8–10 stopni cieplejszej niż w teście A/W35. Samo to obniża COP o 20–25% – niezależnie od tego, jaka to pompa.</p>
  <p class="sdl-bil-calc">Przykład z dziś: jeśli na zaworze mieszającym potrzebuję 31 °C na maty, nie mogę trzymać w zbiorniku 30–35 °C ani 31–36 °C. Muszę mieć więcej, bo co najmniej 3 K tracę na wężownicy – i pompa musi grzać wodę do wyższej temperatury, czyli z gorszym COP. Gdyby zamiast wężownicy pracował wymiennik płytowy dobrany do układu pompa – bufor – wymiennik – maty, strata na wymianie spadłaby do ok. 0,6–1 K. To 2–2,5 stopnia niższa temperatura w zbiorniku, czyli ok. 5–7% lepszy COP tylko z tego jednego powodu.</p>

  <h3 class="sdl-bil-h">Jakość instalacji też ma znaczenie</h3>
  <p>Na wynik pompy wpływa nie tylko temperatura wody, ale cała hydraulika. Za małe przekroje rur, źle dobrane przepływy, a nawet kształtki i zawory – w tym samym nominalnym wymiarze dwóch producentów potrafi mieć wyraźnie inny przekrój wewnętrzny, a więc inne opory i inny przepływ w l/min. Każdy taki opór to większa różnica temperatur na wymienniku pompy, wyższa temperatura zasilania potrzebna do oddania tego samego ciepła i więcej pracy pompy obiegowej. Na papierze dwie instalacje z tą samą pompą wyglądają identycznie, w praktyce mogą różnić się COP o kilkadziesiąt procent. <?php echo sdl_yt($A, 686, 'mówię o tym w filmie'); ?></p>

  <h3 class="sdl-bil-h">Czy nasze Heiko osiągnie COP 4–5?</h3>
  <p>Spodziewam się, że tak – w ciepłe dni jesieni i wiosny, przy +7…+12 °C na zewnątrz i buforze ok. 35 °C. A po naprawie podłogi (murek już jest) i z większym buforem w garażu, czyli z niższą temperaturą wody, także częściej zimą. Sprawdzimy to na prawdziwych danych, na trzy sposoby:</p>
  <ol>
    <li><b>Z cykli bufora w Home Assistant</b> – wybierzemy cykle grzania, w których maty prawie nie odbierały ciepła, policzymy ciepło z przyrostu temperatur w buforze i prąd z licznika, a wyniki pogrupujemy według temperatury na zewnątrz. Zobaczymy COP przy 0, +5 i +10 °C.</li>
    <li><b>Z danych z grzałek</b> – to najlepsza metoda dla całego sezonu. Grzałka ma COP równy 1, więc stosunek „kWh na stopień” na grzałkach i na pompie to prawdziwy sezonowy COP.</li>
    <li><b>Z ciepłomierza</b> – dokładny pomiar w każdej chwili, bez szacowania.</li>
  </ol>
  <p class="sdl-note">Typowe wartości COP w tabeli to orientacyjne zakresy dla nowoczesnych pomp powietrze–woda w warunkach badań według norm (woda 35 °C); konkretne modele różnią się między sobą. Nasze wartości to szacunki do czasu montażu ciepłomierza.</p>
</div>
<?php
    return ob_get_clean();
});

/* ---------- COP z cykli bufora: bilans energii zbiornika NOEL (bez ciepłomierza) ----------
   Ciepło cyklu = przyrost energii w buforze (0,738 kWh/K ≈ 635 l wody, średnia z trzech czujników)
   + ciepło oddane w tym czasie na maty (z tempa stygnięcia bufora przed i po cyklu). Prąd = przyrost licznika pompy.
   Cykle zapisujemy jako urządzenie heiko_cop (metryki cop, tout, kind 0=CO/1=CWU, el, dur, tb, te) – zaczytane z HA
   (dokładność 1 min) albo wykrywane tutaj z odczytów co 5 min. */
define('SDL_TANK_KWH_K', 0.738);

function sdl_cop_catalog() {
    /* instrukcja Heiko Thermal 15, tabela wydajności grzewczej, sprężarka 49 Hz (typowa modulacja) */
    return [
        'w35' => [[-15, 2.51], [-7, 3.17], [2, 4.47], [7, 5.18], [12, 5.88]],
        'w55' => [[-15, 1.26], [-7, 1.69], [2, 2.29], [7, 2.74], [12, 3.12]],
    ];
}

function sdl_cop_detect() {
    global $wpdb; $t = sdl_table();
    $last = $wpdb->get_var("SELECT MAX(ts) FROM $t WHERE device='heiko_cop' AND metric='cop'");
    $from = max($last ? strtotime($last . ' UTC') + 3600 : 0, time() - 4 * 86400);
    $to = time() - 5400;
    if ($to - $from < 3 * 3600) return 0;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT ts, metric, value FROM $t WHERE device='heiko' AND metric IN ('power','noel_top','noel_mid','noel_bot','energy_total','energy_cwu','outdoor') AND ts BETWEEN %s AND %s ORDER BY ts",
        gmdate('Y-m-d H:i:s', $from - 7200), gmdate('Y-m-d H:i:s', $to)));
    $by = [];
    foreach ($rows as $r) $by[$r->ts][$r->metric] = (float) $r->value;
    $P = [];
    foreach ($by as $ts => $v) {
        if (!isset($v['power'], $v['noel_top'], $v['noel_mid'], $v['noel_bot'], $v['energy_total'])) continue;
        $P[] = ['t' => strtotime($ts . ' UTC'), 'p' => $v['power'], 'E' => SDL_TANK_KWH_K * ($v['noel_top'] + $v['noel_mid'] + $v['noel_bot']) / 3,
                'kwh' => $v['energy_total'], 'cwu' => $v['energy_cwu'] ?? null, 'o' => $v['outdoor'] ?? null];
    }
    $n = count($P); if ($n < 40) return 0;
    $on = function ($i) use ($P) { return $P[$i]['p'] > 300; };
    $runs = []; $i = 0;
    while ($i < $n) {
        if (!$on($i)) { $i++; continue; }
        $a = $i; $b = $i;
        while ($b + 1 < $n && ($on($b + 1) || ($b + 2 < $n && $on($b + 2)))) $b++;
        $runs[] = [$a, $b]; $i = $b + 1;
    }
    $slope = function ($a, $b) use ($P) {
        $x = []; $y = [];
        for ($k = $a; $k <= $b; $k++) { $x[] = $P[$k]['t'] / 3600; $y[] = $P[$k]['E']; }
        $m = count($x); if ($m < 6) return null;
        $mx = array_sum($x) / $m; $my = array_sum($y) / $m; $sxx = 0; $sxy = 0;
        for ($k = 0; $k < $m; $k++) { $sxx += ($x[$k] - $mx) ** 2; $sxy += ($x[$k] - $mx) * ($y[$k] - $my); }
        return $sxx > 0 ? $sxy / $sxx : null;
    };
    $found = 0;
    foreach ($runs as $r => [$a, $b]) {
        if ($b - $a < 3 || $a < 7 || $b + 8 >= $n) continue;
        if ($P[$a]['t'] < $from) continue;
        $prevEnd = $r > 0 ? $runs[$r - 1][1] : -100; $nextStart = isset($runs[$r + 1]) ? $runs[$r + 1][0] : $n + 100;
        $pa = max($a - 12, $prevEnd + 2); $pb = $a - 1; $qa = $b + 2; $qb = min($b + 14, $nextStart - 1, $n - 1);
        if ($pb - $pa < 5 || $qb - $qa < 5) continue;
        $s1 = $slope($pa, $pb); $s2 = $slope($qa, $qb);
        if ($s1 === null || $s2 === null) continue;
        $i0 = $a - 1; $i1 = $b + 2;
        $dh = ($P[$i1]['t'] - $P[$i0]['t']) / 3600;
        $heat = ($P[$i1]['E'] - $P[$i0]['E']) - ($s1 + $s2) / 2 * $dh;
        $el = $P[$i1]['kwh'] - $P[$i0]['kwh'];
        if ($el < 0.4) continue;
        $cop = $heat / $el;
        if ($cop < 1 || $cop > 7) continue;
        $cw = ($P[$i1]['cwu'] !== null && $P[$i0]['cwu'] !== null) ? ($P[$i1]['cwu'] - $P[$i0]['cwu']) / $el : 0;
        $os = []; for ($k = $a; $k <= $b; $k++) if ($P[$k]['o'] !== null) $os[] = $P[$k]['o'];
        if (!$os) continue;
        $ts = gmdate('Y-m-d H:i:s', $P[$a]['t']);
        if ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE device='heiko_cop' AND metric='cop' AND ts BETWEEN %s AND %s",
            gmdate('Y-m-d H:i:s', $P[$a]['t'] - 1800), gmdate('Y-m-d H:i:s', $P[$a]['t'] + 1800)))) continue;
        $vals = ['cop' => round($cop, 2), 'tout' => round(array_sum($os) / count($os), 1), 'kind' => $cw > 0.5 ? 1 : 0, 'el' => round($el, 2),
                 'dur' => round(($P[$b]['t'] - $P[$a]['t']) / 3600 + 1 / 12, 2), 'tb' => round($P[$i0]['E'] / SDL_TANK_KWH_K, 1), 'te' => round($P[$i1]['E'] / SDL_TANK_KWH_K, 1)];
        foreach ($vals as $m => $v) $wpdb->insert($t, ['device' => 'heiko_cop', 'metric' => $m, 'ts' => $ts, 'value' => $v], ['%s', '%s', '%s', '%f']);
        $found++;
    }
    return $found;
}

function sdl_cop_bins($cyc) {
    $edges = [[-30, -5, 'poniżej −5 °C'], [-5, -1, '−5…−1 °C'], [-1, 1, 'ok. 0 °C'], [1, 3, '+1…+3 °C'], [3, 6, '+3…+6 °C'], [6, 10, '+6…+10 °C'], [10, 40, 'powyżej +10 °C']];
    $out = [];
    foreach ($edges as [$lo, $hi, $label]) {
        $v = []; foreach ($cyc as $c) if ($c['kind'] == 0 && $c['tout'] >= $lo && $c['tout'] < $hi) $v[] = $c['cop'];
        if (!$v) continue;
        $out[] = ['label' => $label, 'n' => count($v), 'avg' => round(array_sum($v) / count($v), 2), 'max' => max($v)];
    }
    return $out;
}

function sdl_cop_data() {
    return sdl_cached('cop2', function () {
        global $wpdb; $t = sdl_table();
        sdl_cop_detect();
        $rows = $wpdb->get_results("SELECT ts, metric, value FROM $t WHERE device='heiko_cop' ORDER BY ts");
        $by = [];
        foreach ($rows as $r) $by[$r->ts][$r->metric] = (float) $r->value;
        $cyc = [];
        foreach ($by as $ts => $v) {
            if (!isset($v['cop'], $v['tout'])) continue;
            $cyc[] = ['ts' => strtotime($ts . ' UTC'), 'cop' => $v['cop'], 'tout' => $v['tout'], 'kind' => (int) ($v['kind'] ?? 0),
                      'el' => $v['el'] ?? null, 'dur' => $v['dur'] ?? null, 'tb' => $v['tb'] ?? null, 'te' => $v['te'] ?? null];
        }
        $co = array_values(array_filter($cyc, function ($c) { return $c['kind'] == 0; }));
        $best = null; foreach ($co as $c) if (!$best || $c['cop'] > $best['cop']) $best = $c;
        return ['cycles' => $cyc, 'bins' => sdl_cop_bins($cyc), 'catalog' => sdl_cop_catalog(), 'n' => count($cyc), 'n_co' => count($co),
                'best' => $best, 'first' => $cyc ? $cyc[0]['ts'] : null];
    }, 1800);
}

add_action('rest_api_init', function () {
    register_rest_route('sdl/v1', '/cop', ['methods' => 'GET', 'callback' => function () { return sdl_cop_data(); }, 'permission_callback' => '__return_true']);
});

/* ---------- [sdl_cop_pomiar] – COP zmierzony z cykli bufora, porównany z katalogiem (wykład krok po kroku) ---------- */
add_shortcode('sdl_cop_pomiar', function () {
    wp_enqueue_style('sdl', SDL_URL . 'assets/sdl.css', [], SDL_VER);
    wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', [], '4.4.1', true);
    wp_enqueue_script('sdl-cop', SDL_URL . 'assets/sdl-cop.js', ['chartjs'], SDL_VER, true);
    wp_localize_script('sdl-cop', 'SDLCOP', ['api' => esc_url_raw(rest_url('sdl/v1/cop'))]);
    $D = sdl_cop_data(); $tz = sdl_tz();
    $since = $D['first'] ? wp_date('j.m.Y', $D['first'], $tz) : '';
    ob_start(); ?>
<div class="sdl-wrap sdl-bilans sdl-cop-m">
  <p class="sdl-bil-lead">W poprzedniej części wyjaśniliśmy, czym jest COP i dlaczego katalog to nie to samo co dom. Teraz sprawdzamy to na liczbach: policzyliśmy COP naszej pompy z prawdziwych cykli pracy – bez ciepłomierza – i porównaliśmy z tabelami producenta. Wynik: <b>przy około +6…+9 °C na zewnątrz Heiko już osiąga COP 4 i więcej</b>.</p>

  <h3 class="sdl-bil-h">1. Pytanie</h3>
  <p>Czy nasza pompa w prawdziwej instalacji – z buforem 700 l, wężownicą i ciepłą wodą – osiąga COP 4–5, którym chwalą się producenci? A jeśli nie zawsze, to w jakich warunkach tak, a w jakich nie?</p>

  <h3 class="sdl-bil-h">2. Metoda: bufor jako ciepłomierz</h3>
  <p>Bufor NOEL to ok. 635 litrów wody (netto). Podgrzanie litra wody o 1 °C wymaga 1,163 Wh, więc cały zbiornik magazynuje <b>ok. 0,74 kWh na każdy stopień</b>. Jeśli wiemy, o ile podniosła się temperatura w zbiorniku podczas pracy sprężarki, wiemy, ile ciepła do niego trafiło.</p>
  <ol>
    <li><b>Cykl pracy.</b> Bierzemy każdy pełny cykl sprężarki: od startu do zatrzymania.</li>
    <li><b>Energia w buforze.</b> Średnia z trzech czujników na zbiorniku (góra, środek, dół) przed cyklem i po nim – różnica razy 0,74 kWh/K.</li>
    <li><b>Ciepło zabrane przez maty.</b> W trakcie grzania dom nadal pobiera ciepło. Ile? Mierzymy, jak szybko bufor stygnie tuż przed cyklem i tuż po nim, i przyjmujemy średnie tempo dla czasu cyklu.</li>
    <li><b>Prąd.</b> Przyrost licznika energii pompy w tym samym czasie.</li>
    <li><b>COP cyklu</b> = (przyrost energii w buforze + ciepło oddane na maty) ÷ zużyty prąd.</li>
  </ol>
  <p class="sdl-bil-calc">Przykład: bufor nagrzał się z 35,0 do 40,3 °C (+5,3 K × 0,74 = 3,9 kWh), maty w tym czasie zabrały ok. 1,3 kWh, a pompa zużyła 1,5 kWh prądu. COP = (3,9 + 1,3) ÷ 1,5 ≈ <b>3,5</b> – przy +6,8 °C na zewnątrz.</p>
  <p>W wyniku są już straty na odszranianie, praca pompy obiegowej i automatyki – to COP rzeczywisty, a nie laboratoryjny. Dokładność oceniamy na <b>±10–15%</b>: czujniki Zigbee są włożone do tulei w zbiorniku i mierzą wodę, ale tylko w trzech punktach (góra, środek, dół), a ciepło zabrane przez maty szacujemy.</p>

  <h3 class="sdl-bil-h">3. Wyniki</h3>
  <p>Grzanie domu (bufor podgrzewany z ok. 35 do 41 °C), średni COP w przedziałach temperatury zewnętrznej. Tabela i wykres <b>liczą się same</b> z każdego nowego cyklu<?php echo $since ? ' – pomiary od ' . esc_html($since) : ''; ?>.</p>
  <div class="sdl-table-wrap"><table class="sdl-table">
    <thead><tr><th scope="col">Na zewnątrz</th><th scope="col">Średni COP</th><th scope="col">Najlepszy cykl</th><th scope="col">Cykli</th></tr></thead>
    <tbody data-cop="bins"><?php foreach ($D['bins'] as $bn) { echo '<tr><td>' . esc_html($bn['label']) . '</td><td><b>' . sdl_num($bn['avg'], 2) . '</b></td><td>' . sdl_num($bn['max'], 2) . '</td><td>' . (int) $bn['n'] . '</td></tr>'; } ?></tbody>
  </table></div>
  <div class="sdl-card"><div class="sdl-canvas sdl-chart-tall"><canvas data-cop="chart" role="img" aria-label="COP każdego cyklu pracy pompy w funkcji temperatury zewnętrznej, na tle wartości z katalogu Heiko dla wody 35 i 55 °C"></canvas></div>
    <p class="sdl-note">Każdy punkt to jeden cykl pracy sprężarki. Pomarańczowe – grzanie domu, niebieskie – podgrzewanie ciepłej wody do ok. 47 °C. Linie – katalog Heiko Thermal 15 (sprężarka 49 Hz) dla wody 35 °C i 55 °C.</p></div>
  <p>Grzanie ciepłej wody do ok. 47 °C daje wyraźnie mniej: zwykle 2,4–3,7, a krótkie wieczorne dogrzewanie z 42 do 47 °C tylko 1,6–2,8. To ten sam efekt wyższej temperatury wody, o którym mówiliśmy wyżej – widoczny w naszych własnych danych.</p>

  <h3 class="sdl-bil-h">4. Porównanie z katalogiem producenta</h3>
  <p>Instrukcja Heiko Thermal 15 podaje COP dla kilku punktów badania i kilku prędkości sprężarki. Zakresy poniżej obejmują wszystkie prędkości (36–76 Hz):</p>
  <div class="sdl-table-wrap"><table class="sdl-table">
    <thead><tr><th scope="col">Warunki</th><th scope="col">Katalog, woda 35 °C</th><th scope="col">Katalog, woda 55 °C</th><th scope="col">U nas (woda ok. 38–43 °C)</th></tr></thead>
    <tbody>
      <tr><td>A7 (+7 °C)</td><td>4,7–5,2</td><td>2,4–2,7</td><td><b>≈ 4,0</b></td></tr>
      <tr><td>A2 (+2 °C)</td><td>4,2–4,5</td><td>2,0–2,4</td><td><b>≈ 3,0–3,3</b></td></tr>
      <tr><td>A-7 (−7 °C)</td><td>3,0–3,4</td><td>1,5–1,8</td><td>prognoza ≈ 2,3–2,5</td></tr>
      <tr><td>A-15 (−15 °C)</td><td>2,3–2,7</td><td>1,0–1,5</td><td>–</td></tr>
    </tbody>
  </table></div>
  <p>W karcie katalogowej jest też COP 4,0 przy +2 °C (woda 30 → 35 °C) i sezonowa efektywność ogrzewania 196,8% przy wodzie 35 °C, co odpowiada SCOP ok. 5,0. Przy wodzie 55 °C sezonowa efektywność spada do 130,2%, czyli SCOP ok. 3,3.</p>

  <h3 class="sdl-bil-h">5. Wnioski</h3>
  <ul>
    <li><b>Przy +7 °C jesteśmy na ok. 85–90% katalogu</b> – jeśli przeliczyć katalog na naszą temperaturę wody (między 35 a 55 °C wychodzi ok. 4,4). To bardzo dobry wynik jak na prawdziwą instalację.</li>
    <li><b>Przy 0…+2 °C różnica jest większa.</b> W tym zakresie przy wilgotnym powietrzu pompa najczęściej się odszrania, a każde odszranianie zabiera ciepło z bufora.</li>
    <li><b>Temperatura wody waży najwięcej.</b> W katalogu, przy tej samej temperaturze powietrza, przejście z wody 35 °C na 55 °C obniża COP prawie o połowę. To potwierdza wszystko, co napisaliśmy o wężownicy i temperaturze bufora: każde 3 K mniej w zbiorniku to ok. 7–9% więcej COP.</li>
    <li><b>Ciepła woda kontra grzanie domu</b> – różnica między podgrzewaniem wody do 47 °C a grzaniem domu to ten sam efekt wyższej temperatury, widoczny w naszych danych.</li>
    <li><b>Wcześniejszy szacunek „ok. 3 przy 0 °C”</b> z filmu, po doliczeniu ciepła zabranego przez maty, wychodzi trochę niżej: ok. 2,65. Szacunek dla −7 °C (2,3–2,5) się trzyma.</li>
  </ul>

  <h3 class="sdl-bil-h">6. Co dalej</h3>
  <p>Wykres będzie się zapełniał przez całą zimę. W styczniu zobaczymy prawdziwe punkty przy −7 i −10 °C, a wiosną, przy +10 °C, prawdopodobnie COP powyżej 4,5. Gdy pojawi się ciepłomierz, porównamy tę metodę z dokładnym pomiarem – i sprawdzimy, jak dobrze bufor sprawdza się w roli ciepłomierza.</p>
  <h3 class="sdl-bil-h">7. A jaki jest SCOP?</h3>
  <?php $nn = 0; $ss = 0; foreach ($D['bins'] as $bn) { if ($bn['avg'] !== null && $bn['n'] > 0) { $nn += $bn['n']; $ss += $bn['avg'] * $bn['n']; } } ?>
  <p>SCOP to średnia sprawność z całego sezonu grzewczego: całe ciepło oddane przez pompę od jesieni do wiosny podzielone przez cały zużyty prąd. Pojedyncze cykle z tabeli wyżej to dopiero cegiełki – zimny styczeń dopiero przed nami, a to on najmocniej zaniży wynik.<?php if ($nn) echo ' Na dziś średni COP ze wszystkich zmierzonych cykli grzania domu wynosi <b>' . sdl_num($ss / $nn, 2) . '</b> (' . (int) $nn . ' cykli, głównie przy temperaturach dodatnich).'; ?></p>
  <p>Dlatego <b>SCOP podamy po sezonie</b> – policzony tą samą metodą bufora, z każdego cyklu od jesieni do wiosny, razem z ciepłą wodą. Dla porównania: katalog Heiko obiecuje SCOP ok. 5,0 przy wodzie 35 °C i ok. 3,3 przy 55 °C. My pracujemy na wodzie ok. 38–43 °C dla domu i ok. 47–55 °C dla CWU, więc uczciwie spodziewamy się wyniku pomiędzy – bliżej dolnej granicy, bo u nas maty grzeją się z tego samego zbiornika co ciepła woda (o tym niżej).</p>

  <h3 class="sdl-bil-h">8. Ciepłomierz – dokładniejszy następca bufora</h3>
  <p>Metoda bufora działa, ale ma swoje ±10–15%. Najdokładniejszym rozwiązaniem jest <b>ciepłomierz</b>: mierzy przepływ wody i temperatury zasilania i powrotu, a z tego liczy energię cieplną w kWh – co do cyklu, bez szacowania ciepła zabranego przez maty.</p>
  <p class="sdl-bil-calc">moc cieplna = przepływ × ciepło właściwe wody × (temperatura zasilania − temperatura powrotu)</p>
  <p>U nas nie jest to jednak „wkręć i gotowe”:</p>
  <ul>
    <li><b>Rura 35 mm w systemie zaprasowywanym (steelpress).</b> Montaż oznacza rozcięcie instalacji i wpięcie przetwornika przepływu na złączkach zaprasowywanych.</li>
    <li><b>Nie wolno zdławić przepływu.</b> Przy 15 kW i różnicy temperatur 5 K przez pompę płynie ok. 2,6 m³/h. Za mały ciepłomierz stawia opór: spada przepływ, rośnie temperatura wody, a pompa traci sprawność – pomiar psułby to, co ma mierzyć. Szukamy więc modelu z zapasem (np. przepływ nominalny 6 m³/h) i z niskim spadkiem ciśnienia.</li>
    <li><b>Ultradźwiękowy, nie skrzydełkowy</b> – bez ruchomych części, z małym oporem i odporny na zanieczyszczenia.</li>
    <li><b>Czysta woda, bez glikolu</b> – wystarczy standardowy ciepłomierz do wody. Przy glikolu trzeba by modelu kalibrowanego na glikol, inaczej wynik byłby błędny.</li>
    <li><b>Montaż wg instrukcji:</b> proste odcinki rury przed przetwornikiem i za nim, położenie bez gromadzenia się powietrza (zwykle na powrocie), czujniki temperatury dobrane w parze.</li>
    <li><b>Komunikacja</b> przez M-Bus lub wyjście impulsowe – żeby dane trafiały do Home Assistant i na tę stronę automatycznie.</li>
  </ul>
  <p>Ciepłomierz zamontujemy przy przebudowie maszynowni opisanej w planie rozwojowym – i tak będziemy wtedy ciąć rury, więc nie zrobimy tego dwa razy. Docelowo rozważamy dwa: na obiegu ogrzewania i na obiegu CWU. Wtedy porównamy go z metodą bufora i sprawdzimy, jak dobrze zbiornik sprawdzał się w roli ciepłomierza.</p>
  <p class="sdl-note">COP z katalogu: instrukcja montażu Heiko Thermal (2023), tabele wydajności grzewczej modelu Thermal 15. Wartości katalogowe zaokrąglone do jednego miejsca po przecinku, liczone jako wydajność grzewcza ÷ moc wejściowa.</p>
</div>
<?php
    return ob_get_clean();
});

/* ---------- odszranianie: [sdl_odszranianie device="heiko_defrost"] ---------- */
/* ---------- [sdl_reqnet] – rekuperator reQ V 400 ERV: dane na żywo, bilans odzysku i strat, analizy ---------- */
function sdl_rq_calc($q, $tout, $tsup, $tex) {
    if (!is_numeric($q) || !is_numeric($tout) || !is_numeric($tsup) || !is_numeric($tex) || $q <= 0) return null;
    $den = $tex - $tout;
    return [
        'eta'  => abs($den) > 2 ? ($tsup - $tout) / $den * 100 : null,   /* sprawność temperaturowa po stronie nawiewu */
        'rec'  => 0.34 * $q * ($tsup - $tout),                           /* W: ciepło oddane świeżemu powietrzu przez wymiennik */
        'loss' => 0.34 * $q * max(0, $tex - $tsup),                      /* W: ciepło, które dom musi dogrzać */
    ];
}
add_shortcode('sdl_reqnet', function () {
    wp_enqueue_style('sdl', SDL_URL . 'assets/sdl.css', [], SDL_VER);
    $R = sdl_now_latest('reqnet'); $v = $R['values'];
    $c = sdl_rq_calc($v['flow_in'] ?? null, $v['czerpnia'] ?? null, $v['nawiew'] ?? null, $v['wyciag'] ?? null);
    $days = [];
    foreach (['flow_in', 'czerpnia', 'nawiew', 'wyciag', 'power'] as $m) {
        foreach (sdl_now_daily('reqnet', $m, 31, 'avg') as $row) $days[$row[0]][$m] = $row[1];
    }
    krsort($days);
    $tz = sdl_tz();
    $cop = ($c && !empty($v['power']) && $v['power'] > 3) ? $c['rec'] / $v['power'] : null;
    $spi = (!empty($v['flow_in']) && isset($v['power'])) ? $v['power'] / $v['flow_in'] : null;
    /* sezon grzewczy: wszystkie doby ze średnią temperaturą zewnętrzną poniżej 12 °C */
    $season = [];
    foreach (['flow_in', 'czerpnia', 'nawiew', 'wyciag', 'power'] as $m) {
        foreach (sdl_now_daily('reqnet', $m, 365, 'avg') as $row) $season[$row[0]][$m] = $row[1];
    }
    $s_rec = 0; $s_el = 0; $s_n = 0; $s_from = null;
    foreach ($season as $d => $x) {
        if (!isset($x['czerpnia'], $x['power']) || $x['czerpnia'] >= 12) continue;
        $k = sdl_rq_calc($x['flow_in'] ?? null, $x['czerpnia'], $x['nawiew'] ?? null, $x['wyciag'] ?? null);
        if (!$k || $x['power'] <= 3) continue;
        $s_rec += $k['rec'] * 24 / 1000; $s_el += $x['power'] * 24 / 1000; $s_n++;
        if ($s_from === null || $d < $s_from) $s_from = $d;
    }
    $scop = $s_el > 0 ? $s_rec / $s_el : null;
    ob_start(); ?>
<div class="sdl-wrap sdl-bilans sdl-reqnet">
  <p class="sdl-bil-lead">Centrala <b>reQnet reQ V 400 ERV</b> wymienia powietrze w całym domu przez całą dobę. Wymiennik entalpiczny oddaje świeżemu powietrzu nie tylko ciepło, ale też część wilgoci z powietrza usuwanego. Poniżej liczymy, ile ciepła odzyskuje, ile kosztuje nas wentylacja i ile powietrza naprawdę potrzebujemy.</p>

  <div class="sdl-kpis sdl-kpis-5">
    <div class="sdl-kpi"><div class="sdl-kpi-label">Wymiana teraz</div><div class="sdl-kpi-val"><?php echo sdl_num($v['flow_in'] ?? null, 0); ?> <small>m³/h</small></div><div class="sdl-kpi-sub">nawiew / wyciąg: <?php echo sdl_num($v['flow_in'] ?? null, 0) . ' / ' . sdl_num($v['flow_out'] ?? null, 0); ?></div></div>
    <div class="sdl-kpi" title="Sprawność temperaturowa po stronie nawiewu: (nawiew − czerpnia) ÷ (wyciąg − czerpnia)."><div class="sdl-kpi-label">Sprawność odzysku</div><div class="sdl-kpi-val"><?php echo sdl_num($c['eta'] ?? null, 0); ?> <small>%</small></div><div class="sdl-kpi-sub">czerpnia <?php echo sdl_num($v['czerpnia'] ?? null); ?> °C → nawiew <?php echo sdl_num($v['nawiew'] ?? null); ?> °C</div></div>
    <div class="sdl-kpi" style="border-top-color:#22c55e"><div class="sdl-kpi-label">Ciepło odzyskane</div><div class="sdl-kpi-val"><?php echo sdl_num($c['rec'] ?? null, 0); ?> <small>W</small></div><div class="sdl-kpi-sub">tyle grzałby dom bez rekuperatora</div></div>
    <div class="sdl-kpi" style="border-top-color:#ef4444"><div class="sdl-kpi-label">Do dogrzania</div><div class="sdl-kpi-val"><?php echo sdl_num($c['loss'] ?? null, 0); ?> <small>W</small></div><div class="sdl-kpi-sub">nawiew → wyciąg <?php echo sdl_num($v['wyciag'] ?? null); ?> °C</div></div>
    <div class="sdl-kpi"><div class="sdl-kpi-label">Wentylatory</div><div class="sdl-kpi-val"><?php echo sdl_num($v['power'] ?? null, 0); ?> <small>W</small></div><div class="sdl-kpi-sub"><?php if (isset($v['co2'])) echo 'CO₂ ' . sdl_num($v['co2'], 0) . ' ppm'; ?><?php if (isset($v['rh'])) echo ' · wilgotność ' . sdl_num($v['rh'], 0) . '%'; ?></div></div>
  </div>
  <div class="sdl-kpis">
    <div class="sdl-kpi" style="border-top-color:#22c55e" title="Ciepło odzyskane przez wymiennik podzielone przez prąd wentylatorów – szczegóły w punkcie 6."><div class="sdl-kpi-label">COP rekuperatora teraz</div><div class="sdl-kpi-val"><?php echo sdl_num($cop, 0); ?></div><div class="sdl-kpi-sub">pompa ciepła: ok. 3</div></div>
    <div class="sdl-kpi" style="border-top-color:#22c55e" title="SCOP: suma ciepła odzyskanego ÷ suma prądu wentylatorów, doby ze średnią temperaturą zewnętrzną poniżej 12 °C."><div class="sdl-kpi-label">SCOP – ten sezon</div><div class="sdl-kpi-val"><?php echo sdl_num($scop, 1); ?></div><div class="sdl-kpi-sub"><?php echo $s_n ? (int) $s_n . ' dób' . ($s_from ? ' od ' . esc_html(wp_date('j.m.Y', strtotime($s_from . ' 12:00'), $tz)) : '') . ' · ' . sdl_num($s_rec, 0) . ' kWh ciepła za ' . sdl_num($s_el, 1) . ' kWh prądu' : 'zbieramy dane'; ?></div></div>
    <div class="sdl-kpi" title="SPI – moc wentylatorów na każdy m³/h przetłaczanego powietrza. Im mniej, tym oszczędniej."><div class="sdl-kpi-label">SPI teraz</div><div class="sdl-kpi-val"><?php echo sdl_num($spi, 2); ?> <small>W/(m³/h)</small></div><div class="sdl-kpi-sub">poniżej ok. 0,45 = oszczędnie</div></div>
  </div>
  <p class="sdl-note">Odczyt z <?php echo esc_html(sdl_when($R['ts'])); ?> (czas polski). Wartości liczone ze wzorów opisanych niżej.</p>

  <h3 class="sdl-bil-h">1. Jak liczymy</h3>
  <p>Podgrzanie 1 m³ powietrza o 1 °C wymaga ok. <b>0,34 Wh</b>. Mamy cztery czujniki temperatury w centrali: czerpnia (powietrze z zewnątrz), nawiew (po wymienniku, do pokoi), wyciąg (z pokoi) i wyrzutnia (na zewnątrz). Stąd:</p>
  <ul>
    <li><b>sprawność odzysku</b> = (nawiew − czerpnia) ÷ (wyciąg − czerpnia),</li>
    <li><b>ciepło odzyskane</b> = 0,34 × przepływ × (nawiew − czerpnia) – tyle musiałby dogrzać dom, gdyby powietrze wpadało prosto z zewnątrz,</li>
    <li><b>ciepło do dogrzania</b> = 0,34 × przepływ × (wyciąg − nawiew) – tę różnicę pokrywa pompa ciepła przez maty.</li>
  </ul>
  <p class="sdl-bil-calc">Przykład z 25.11.2026 (+1,6 °C na zewnątrz, 207 m³/h): nawiew 16,8 °C, wyciąg 21,0 °C. Sprawność = 15,2 ÷ 19,4 ≈ <b>78%</b>. Odzysk = 0,34 × 207 × 15,2 ≈ 1,07 kW, czyli ok. <b>26 kWh na dobę</b>. Do dogrzania = 0,34 × 207 × 4,2 ≈ 0,3 kW, czyli ok. <b>7 kWh ciepła na dobę</b>.</p>

  <h3 class="sdl-bil-h">2. Ostatnie doby</h3>
  <p>Średnie dobowe z odczytów co 5 minut. To przybliżenie: liczymy ze średnich temperatur i przepływu, nie z każdego odczytu osobno.</p>
  <details class="sdl-more">
  <summary><span class="sdl-more-open">Pokaż tabelę dób (do 30 dni)</span><span class="sdl-more-close">Zwiń tabelę</span></summary>
  <div class="sdl-table-wrap"><table class="sdl-table">
    <thead><tr><th scope="col">Doba</th><th scope="col">Przepływ</th><th scope="col">Na zewnątrz</th><th scope="col">Sprawność</th><th scope="col">Odzysk</th><th scope="col">Do dogrzania</th><th scope="col">Prąd wentylatorów</th><th scope="col">COP</th></tr></thead>
    <tbody><?php foreach (array_slice($days, 0, 30, true) as $d => $x) {
        $k = sdl_rq_calc($x['flow_in'] ?? null, $x['czerpnia'] ?? null, $x['nawiew'] ?? null, $x['wyciag'] ?? null);
        if (!$k) continue;
        echo '<tr><td>' . esc_html(wp_date('j.m', strtotime($d . ' 12:00'), $tz)) . '</td><td>' . sdl_num($x['flow_in'], 0) . ' m³/h</td><td>' . sdl_num($x['czerpnia']) . ' °C</td><td><b>' . sdl_num($k['eta'], 0) . '%</b></td><td>' . sdl_num($k['rec'] * 24 / 1000) . ' kWh</td><td>' . sdl_num($k['loss'] * 24 / 1000) . ' kWh</td><td>' . (isset($x['power']) ? sdl_num($x['power'] * 24 / 1000, 2) . ' kWh' : '–') . '</td><td><b>' . (!empty($x['power']) && $x['power'] > 3 ? sdl_num($k['rec'] / $x['power'], 0) : '–') . '</b></td></tr>';
    } ?></tbody>
  </table></div>
  </details>

  <h3 class="sdl-bil-h">3. Czy wentylacja to duża strata?</h3>
  <p>Ten sam dzień (25.11.2026, +1,6 °C), przeliczony na trzy wydajności – przy tej samej sprawności ok. 78%:</p>
  <details class="sdl-more">
  <summary><span class="sdl-more-open">Pokaż porównanie wydajności</span><span class="sdl-more-close">Zwiń porównanie</span></summary>
  <div class="sdl-table-wrap"><table class="sdl-table">
    <thead><tr><th scope="col">Wydajność</th><th scope="col">Odzysk / dobę</th><th scope="col">Do dogrzania / dobę</th><th scope="col">Wentylatory / dobę</th></tr></thead>
    <tbody>
      <tr><td>207 m³/h</td><td>ok. 26 kWh</td><td><b>ok. 7,1 kWh</b> ciepła</td><td>ok. 1,8 kWh (75 W)</td></tr>
      <tr><td>160 m³/h</td><td>ok. 20 kWh</td><td><b>ok. 5,5 kWh</b> ciepła</td><td>ok. 0,9 kWh (39 W)</td></tr>
      <tr><td>110 m³/h</td><td>ok. 14 kWh</td><td><b>ok. 3,8 kWh</b> ciepła</td><td>ok. 0,6 kWh (szacunek)</td></tr>
    </tbody>
  </table></div>
  </details>
  <p><b>Odpowiedź: nie, to nie jest duża strata.</b> Ciepło do dogrzania pompa pokrywa przy COP ok. 3, więc przy 207 m³/h wentylacja kosztuje ok. 2–2,5 kWh prądu na pompie i 1,8 kWh na wentylatorach na dobę – rząd 10% całego zużycia na ogrzewanie. Bez rekuperatora ta sama wymiana powietrza kosztowałaby ponad 30 kWh ciepła dziennie. Większy zysk niż ścinanie wydajności daje utrzymanie wysokiej sprawności wymiennika (czyste filtry, szczelne kanały).</p>

  <h3 class="sdl-bil-h">4. Ile powietrza naprawdę potrzebujemy?</h3>
  <p>Dom ma ok. 250 m³ kubatury, mieszkają w nim cztery osoby. Typowo przyjmuje się ok. 30 m³/h świeżego powietrza na osobę, czyli <b>ok. 120 m³/h</b> – i tyle mniej więcej wybiera centrala w trybie automatycznym: najczęściej ok. 110 m³/h. Nasze odczucie jest jednak takie, że przy 110 m³/h powietrze nie jest wystarczająco świeże.</p>
  <p>Jedna z przyczyn jest ciekawa: <b>automatyka „uczy się” wilgotności</b>. Gdy przez dłuższy czas w domu utrzymuje się ok. 60%, centrala zaczyna traktować tę wartość jako normalną i przestaje zwiększać wydajność. Dom jest wtedy wentylowany za słabo, choć według czujnika wszystko jest w porządku.</p>
  <p>Dlatego od 27.11.2026 testujemy stałe <b>160 m³/h</b> (ok. 0,6 wymiany na godzinę – przy 207 m³/h było to ok. 0,8). Porównamy CO₂, wilgotność, odczucie domowników i koszt z dniami na 110 i 207 m³/h – wyniki pojawią się w tabeli „Ostatnie doby”.</p>

  <h3 class="sdl-bil-h">5. Wymiennik entalpiczny zimą</h3>
  <p>Zwykły rekuperator przy dużej wymianie zimą mocno wysusza dom – mroźne powietrze z zewnątrz ma bardzo mało wody. Wymiennik entalpiczny (ERV) przekazuje część wilgoci z powietrza wyciąganego do nawiewanego, więc spadek wilgotności przy 160–200 m³/h jest wyraźnie mniejszy. To jeden z powodów, dla których przy tej centrali możemy sobie pozwolić na większą wymianę bez nawilżacza.</p>

  <h3 class="sdl-bil-h">6. COP i SCOP rekuperatora – czyli rekuperator jako pompa ciepła</h3>
  <p>Pompy ciepła oceniamy jednym, bardzo wygodnym wskaźnikiem: <b>COP</b> (ang. <i>coefficient of performance</i>) – ile kilowatogodzin ciepła dostajemy z każdej kilowatogodziny prądu. Okazuje się, że ten sam sposób myślenia świetnie pasuje do rekuperatora. On też „pompuje” ciepło: wentylatory zużywają prąd, a wymiennik przenosi ciepło z powietrza, które i tak wyrzucamy na zewnątrz, do powietrza świeżego. Bez rekuperatora to ciepło po prostu uciekłoby przez wyrzutnię.</p>
  <p class="sdl-bil-calc">COP rekuperatora = ciepło odzyskane ÷ prąd zużyty przez wentylatory</p>
  <p>Różnica jest jedna, ale zasadnicza. Pompa ciepła musi „podnieść” ciepło z zimnego powietrza do ciepłej wody – to wymaga sprężarki i dużo pracy, stąd COP ok. 3. Rekuperator nie podnosi niczego pod górę: ciepło samo płynie z cieplejszego powietrza wyciąganego do zimniejszego nawiewanego, przez cienkie ścianki wymiennika. Prąd jest potrzebny tylko do przepchnięcia powietrza przez kanały i filtry. Dlatego COP rekuperatora jest kilka razy wyższy niż pompy ciepła.</p>
  <div class="sdl-table-wrap"><table class="sdl-table">
    <thead><tr><th scope="col">Sytuacja</th><th scope="col">Ciepło odzyskane</th><th scope="col">Wentylatory</th><th scope="col">COP</th></tr></thead>
    <tbody>
      <tr><td>25.11.2026 · 207 m³/h · +1,6 °C</td><td>ok. 1070 W</td><td>75 W</td><td><b>ok. 14</b></td></tr>
      <tr><td>27.11.2026 · 160 m³/h · ok. 0 °C</td><td>ok. 860 W</td><td>40 W</td><td><b>ok. 21</b></td></tr>
      <tr><td>dla porównania: pompa ciepła przy 0 °C</td><td colspan="2">–</td><td>ok. 2,7–3</td></tr>
    </tbody>
  </table></div>
  <p>Każda kilowatogodzina prądu wydana na wentylatory oddaje więc do domu <b>14–21 kWh ciepła</b>. Ciekawostka: przy mniejszej wydajności COP rośnie. Wentylatory zużywają nieproporcjonalnie mniej prądu (opór kanałów rośnie z kwadratem prędkości powietrza), a wolniej płynące powietrze ma więcej czasu na oddanie ciepła w wymienniku.</p>

  <h4 class="sdl-bil-h">SCOP – wynik za cały sezon</h4>
  <p>Pojedynczy odczyt to zdjęcie chwili. Sprawiedliwą ocenę daje <b>SCOP</b> – sezonowy COP: suma ciepła odzyskanego od jesieni do wiosny podzielona przez sumę prądu wentylatorów. Liczymy go na bieżąco (kafelek wyżej) ze wszystkich dób, w których średnia temperatura na zewnątrz była niższa niż 12 °C. Latem wskaźnik traci sens: przy ciepłym powietrzu odzysk jest znikomy albo centrala otwiera bypass, a wtedy „COP” spadłby do zera, choć urządzenie pracuje dokładnie tak, jak powinno.</p>

  <h4 class="sdl-bil-h">Dwa uczciwe zastrzeżenia</h4>
  <ul>
    <li><b>Wilgoć to też energia.</b> Wymiennik entalpiczny oddaje świeżemu powietrzu część pary wodnej z powietrza wyciąganego. Odparowanie wody pochłania energię, więc odzyskana wilgoć to odzyskane <i>ciepło utajone</i>. Nasze wzory liczą tylko ciepło jawne – z różnicy temperatur – więc prawdziwy COP centrali ERV jest jeszcze trochę wyższy. Świadomie raczej zaniżamy, niż zawyżamy.</li>
    <li><b>Ciepło z silników.</b> Część prądu wentylatora i tak zamienia się w ciepło w strumieniu powietrza. To drobna poprawka, nieistotna wobec setek watów z wymiennika.</li>
  </ul>

  <h4 class="sdl-bil-h">SPI – drugi wskaźnik, którym posługuje się branża</h4>
  <p>Producenci i normy (np. wymagania ekoprojektu UE dla central wentylacyjnych) opisują oszczędność wentylatorów wskaźnikiem <b>SPI</b> (ang. <i>specific power input</i>): ile watów potrzeba na przetłoczenie każdego metra sześciennego powietrza na godzinę.</p>
  <p class="sdl-bil-calc">SPI = moc wentylatorów [W] ÷ przepływ [m³/h]</p>
  <ul>
    <li>207 m³/h przy 75 W → SPI ≈ <b>0,36 W/(m³/h)</b>,</li>
    <li>160 m³/h przy 40 W → SPI ≈ <b>0,25 W/(m³/h)</b>.</li>
  </ul>
  <p>Wartości poniżej ok. 0,45 W/(m³/h) uchodzą za oszczędną centralę z dobrze zaprojektowanymi kanałami. Wysoki SPI zdradza za długie lub za wąskie kanały, zatkane filtry albo nieszczelności – dlatego warto go obserwować przez cały rok: powolny wzrost przy tym samym przepływie to sygnał, że czas wymienić filtry.</p>

  <h4 class="sdl-bil-h">A w portfelu?</h4>
  <p>Przy 160 m³/h wentylatory zużywają ok. 0,9 kWh prądu na dobę. W tym czasie wymiennik oddaje ok. 20 kWh ciepła – a to ciepło musiałaby inaczej wyprodukować pompa ciepła, zużywając przy COP ok. 3 jakieś 7 kWh prądu. <b>Na czysto zostaje ok. 6 kWh prądu oszczędności każdego zimowego dnia</b>, a do tego świeże powietrze o kontrolowanej wilgotności. To jeden z najlepszych „interesów” w całej instalacji domu.</p>

  <?php echo sdl_sr_block('<p>Rekuperator reQnet reQ V 400 ERV – odczyt z ' . esc_html(sdl_when($R['ts'])) . ' (czas polski): przepływ ' . sdl_num($v['flow_in'] ?? null, 0) . ' m³/h, czerpnia ' . sdl_num($v['czerpnia'] ?? null) . ' °C, nawiew ' . sdl_num($v['nawiew'] ?? null) . ' °C, wyciąg ' . sdl_num($v['wyciag'] ?? null) . ' °C, sprawność odzysku ' . sdl_num($c['eta'] ?? null, 0) . ' %, ciepło odzyskane ' . sdl_num($c['rec'] ?? null, 0) . ' W, do dogrzania ' . sdl_num($c['loss'] ?? null, 0) . ' W, moc wentylatorów ' . sdl_num($v['power'] ?? null, 0) . ' W, COP rekuperatora ' . sdl_num($cop, 0) . ', SCOP sezonu ' . sdl_num($scop, 1) . ', SPI ' . sdl_num($spi, 2) . ' W/(m³/h)' . (isset($v['co2']) ? ', CO₂ ' . sdl_num($v['co2'], 0) . ' ppm' : '') . (isset($v['rh']) ? ', wilgotność ' . sdl_num($v['rh'], 0) . ' %' : '') . '.</p>');
  echo sdl_dataset_ld('Rekuperator reQnet reQ V 400 ERV – dane na żywo', 'Przepływ powietrza, temperatury czerpni, nawiewu i wyciągu, moc wentylatorów, CO₂ i wilgotność centrali wentylacyjnej z odzyskiem ciepła w domu z kontenerów pod Poznaniem. Odczyty co 5 minut.', ['przepływ [m³/h]', 'temperatury powietrza [°C]', 'moc wentylatorów [W]', 'sprawność odzysku [%]', 'CO₂ [ppm]', 'wilgotność [%]']); ?>

  <h3 class="sdl-bil-h">7. Co dalej</h3>
  <ul>
    <li><b>Sterowanie według CO₂ z Home Assistant</b> zamiast wbudowanej automatyki wilgotności: niska wydajność, gdy CO₂ jest niskie, podbicie, gdy rośnie, i przy kąpieli.</li>
    <li><b>Stop przy pożarze:</b> planujemy wyłączanie centrali przez alarm dymu, żeby nie rozprowadzała dymu po kanałach.</li>
    <li><b>Wyniki testu 110 / 160 / 207 m³/h</b> – opiszemy je tutaj po kilku tygodniach.</li>
  </ul>
</div>
<?php
    return ob_get_clean();
});

/* ---------- [sdl_maty] – maty kapilarne: kafelki na żywo, wykład o ogrzewaniu promieniowaniem, termowizja (szkic 27.11.2026, treść do akceptacji właściciela) ---------- */
add_shortcode('sdl_maty', function () {
    wp_enqueue_style('sdl', SDL_URL . 'assets/sdl.css', [], SDL_VER);
    $M = sdl_now_latest('maty'); $v = $M['values'];
    $sup = $v['zawor_temp'] ?? null; $ret = $v['zawor_powrot'] ?? null;
    $dt  = (is_numeric($sup) && is_numeric($ret)) ? $sup - $ret : null;
    $avg = (is_numeric($sup) && is_numeric($ret)) ? ($sup + $ret) / 2 : null;
    $img = function ($f, $alt, $cap, $w = 720, $h = 960) {
        return '<figure class="sdl-fig"><img src="' . esc_url(SDL_URL . 'assets/img/' . $f) . '" width="' . $w . '" height="' . $h . '" loading="lazy" alt="' . esc_attr($alt) . '"><figcaption>' . $cap . '</figcaption></figure>';
    };
    ob_start(); ?>
<div class="sdl-wrap sdl-bilans sdl-maty">
  <p class="sdl-bil-lead">W całym domu grzeją <b>maty kapilarne w sufitach</b>, a w łazienkach także w podłogach – ok. 88 m² mat na 102 m² domu. Nie ma grzejników ani nawiewu ciepłego powietrza. Sufit jest cieplejszy od pokoju tylko o 5–10 stopni, a mimo to wystarcza do ogrzania domu. Jak to możliwe? Bo <b>grzejemy światłem – podczerwonym, tak jak Słońce</b>.</p>

  <div class="sdl-kpis sdl-kpis-5">
    <div class="sdl-kpi" style="border-top-color:#f97316"><div class="sdl-kpi-label">Zasilanie mat</div><div class="sdl-kpi-val"><?php echo sdl_num($sup); ?> <small>°C</small></div><div class="sdl-kpi-sub">zawór: zadana <?php echo sdl_num($v['zawor_zadana'] ?? null, 0); ?> °C</div></div>
    <div class="sdl-kpi" style="border-top-color:#3b82f6"><div class="sdl-kpi-label">Powrót z mat</div><div class="sdl-kpi-val"><?php echo sdl_num($ret); ?> <small>°C</small></div><div class="sdl-kpi-sub">różnica <?php echo sdl_num($dt); ?> K · średnio <?php echo sdl_num($avg); ?> °C</div></div>
    <div class="sdl-kpi" title="Zawór mieszający domieszuje chłodniejszą wodę z powrotu do gorącej wody z wężownicy w buforze."><div class="sdl-kpi-label">Otwarcie zaworu</div><div class="sdl-kpi-val"><?php echo sdl_num($v['zawor_otwarcie'] ?? null, 0); ?> <small>%</small></div><div class="sdl-kpi-sub">z wężownicy: <?php echo sdl_num($v['zawor_przed'] ?? null); ?> °C</div></div>
    <div class="sdl-kpi"><div class="sdl-kpi-label">W pokojach</div><div class="sdl-kpi-val"><?php echo sdl_num($v['salon'] ?? null); ?> <small>°C</small></div><div class="sdl-kpi-sub">salon · sypialnia <?php echo sdl_num($v['sypialnia'] ?? null); ?> · łazienka <?php echo sdl_num($v['lazienka'] ?? null); ?> °C</div></div>
    <div class="sdl-kpi" style="border-top-color:#06b6d4" title="Punkt rosy powietrza w domu. Powierzchnia chłodniejsza od punktu rosy zaczyna się pocić – ważne przy chłodzeniu i przy zimnych ramach okien."><div class="sdl-kpi-label">Punkt rosy</div><div class="sdl-kpi-val"><?php echo sdl_num($v['rosa'] ?? null); ?> <small>°C</small></div><div class="sdl-kpi-sub">granica rosienia powierzchni</div></div>
  </div>
  <p class="sdl-note">Odczyt z <?php echo esc_html(sdl_when($M['ts'])); ?> (czas polski), z termostatów i zaworu mieszającego Sinum. Pełny zestaw danych domu: <a href="<?php echo esc_url(home_url('/live/')); ?>">Dom na żywo</a>.</p>

  <h3 class="sdl-bil-h">1. Grzejemy światłem – podczerwonym, jak Słońce</h3>
  <p>Każde ciało cieplejsze od zera bezwzględnego świeci. Nie zawsze widzimy to światło, ale ono jest – i niesie energię. Długość fali, przy której ciało świeci najmocniej, opisuje <b>prawo Wiena</b>:</p>
  <p class="sdl-bil-calc">λ<sub>max</sub> ≈ 2898 ÷ T [µm], gdzie T to temperatura w kelwinach</p>
  <ul>
    <li><b>Słońce</b> (ok. 5800 K): λ ≈ 0,5 µm – światło widzialne. Ale mniej więcej połowa energii słonecznej dociera do nas jako podczerwień – to ją czujemy na twarzy jako ciepło.</li>
    <li><b>Sufit z matami</b> (ok. 25 °C, czyli 298 K): λ ≈ <b>9,7 µm</b> – daleka podczerwień, dla oka niewidzialna, ale doskonale widoczna dla kamery termowizyjnej (zdjęcia niżej).</li>
  </ul>
  <p>Mechanizm jest ten sam. Promieniowanie przelatuje przez powietrze, prawie go nie ogrzewając, i oddaje energię dopiero wtedy, gdy trafi na ciało stałe: podłogę, ściany, meble i nas. Dlatego zimą na słońcu jest ciepło, choć powietrze ma kilka stopni – i dlatego pod ciepłym sufitem jest komfortowo, choć powietrze nie jest gorące.</p>

  <h3 class="sdl-bil-h">2. Ile ciepła daje sufit cieplejszy tylko o kilka stopni</h3>
  <p>Moc promieniowania między dwiema powierzchniami opisuje <b>prawo Stefana–Boltzmanna</b>:</p>
  <p class="sdl-bil-calc">q = ε · σ · (T<sub>sufitu</sub>⁴ − T<sub>otoczenia</sub>⁴), σ = 5,67 · 10⁻⁸ W/(m²·K⁴), ε ≈ 0,93 (tynk, farba)</p>
  <p class="sdl-bil-calc">Przykład: sufit 25 °C (298,15 K), ściany i podłoga 20 °C (293,15 K):<br>q = 0,93 · 5,67·10⁻⁸ · (298,15⁴ − 293,15⁴) ≈ <b>27 W/m²</b> samym promieniowaniem.</p>
  <p>Do tego dochodzi trochę konwekcji, ale niewiele: ciepłe powietrze zostaje pod sufitem, bo nie ma gdzie „uciec” wyżej. To ok. 3–5 W/m². Razem <b>ok. 30 W/m²</b>, z czego <b>85–90% to promieniowanie</b>. Grzejnik działa odwrotnie – większość ciepła oddaje przez ogrzewanie powietrza, które krąży po pokoju.</p>
  <p>To wynik dla jesiennego wieczoru, gdy sufit ma ok. 25 °C. Zimą podnosimy temperaturę wody i sufit robi się cieplejszy – projektowo do ok. <b>30 °C</b>, a w mrozy miejscami nawet więcej. A moc rośnie szybciej niż różnica temperatur, bo promieniowanie zależy od czwartej potęgi temperatury bezwzględnej:</p>
  <div class="sdl-table-wrap"><table class="sdl-table">
    <thead><tr><th scope="col">Sufit (pokój 20 °C)</th><th scope="col">Promieniowanie</th><th scope="col">Konwekcja</th><th scope="col">Razem</th><th scope="col">88 m² mat</th></tr></thead>
    <tbody>
      <tr><td>25 °C – jesień, zdjęcia niżej</td><td>27 W/m²</td><td>4 W/m²</td><td><b>ok. 31 W/m²</b></td><td>ok. 2,7 kW</td></tr>
      <tr><td>28 °C</td><td>44 W/m²</td><td>6 W/m²</td><td><b>ok. 51 W/m²</b></td><td>ok. 4,5 kW</td></tr>
      <tr><td>30 °C – zima, wartość projektowa</td><td>56 W/m²</td><td>8 W/m²</td><td><b>ok. 64 W/m²</b></td><td>ok. 5,6 kW</td></tr>
      <tr><td>32 °C</td><td>68 W/m²</td><td>10 W/m²</td><td><b>ok. 77 W/m²</b></td><td>ok. 6,8 kW</td></tr>
    </tbody>
  </table></div>
  <p>Konwekcję przyjęliśmy ostrożnie: ok. 0,8 W/m² na każdy stopień różnicy. Razem wychodzi <b>ok. 6,3–6,5 W/m² na każdy stopień</b>, o który sufit jest cieplejszy od pokoju – i tyle samo podają normy badań sufitów grzewczych (EN 14240). <b>Producent mat podaje nawet ok. 70 W/m²</b> – to nie przeczy rachunkowi, tylko odpowiada sufitowi o ok. 31 °C, czyli o ok. 11 stopni cieplejszemu od pokoju. Teoria, katalog i pomiar to jedna i ta sama krzywa, tylko w różnych punktach.</p>
  <p>Dwie uwagi praktyczne. Pierwsza: termowizja pokazuje najcieplejsze pasy nad rurkami, a w przerwach między matami sufit jest chłodniejszy – średnia temperatura całej powierzchni jest więc niższa niż szczytowe odczyty. Druga: nie cały sufit jest pokryty matami, więc moc liczymy dla 88 m² mat, a nie dla całego sufitu. Dla porównania nasz dom potrzebuje w mrozy ok. 3–3,5 kW (bilans na stronie <a href="<?php echo esc_url(home_url('/projekt/')); ?>">Projekt</a>) – zapas jest, choć w niektórych pokojach mat jest za mało (<a href="#co-inaczej">punkt 8</a>).</p>

  <h3 class="sdl-bil-h">3. Temperatura odczuwalna – dlaczego 20 °C może grzać jak 21 °C</h3>
  <p>Człowiek nie ma termometru powietrza. Odczuwamy bilans ciepła ciała: ile oddajemy powietrzu i ile promieniowaniem do otaczających powierzchni. Inżynierowie opisują to <b>temperaturą operacyjną</b> – w pokoju bez przeciągów to w przybliżeniu średnia z temperatury powietrza i średniej temperatury powierzchni dookoła:</p>
  <p class="sdl-bil-calc">t<sub>odczuwalna</sub> ≈ (t<sub>powietrza</sub> + t<sub>powierzchni</sub>) ÷ 2</p>
  <p>Ciepły sufit podnosi średnią temperaturę powierzchni, więc to samo poczucie komfortu osiągamy przy powietrzu chłodniejszym o ok. 1 °C. A każdy stopień mniej w powietrzu to – według popularnej reguły – ok. 6% mniej ciepła na ogrzewanie, bo mniej ucieka przez ściany i wentylację.</p>
  <p>Są też granice. Norma komfortu cieplnego (EN ISO 7730) ogranicza tzw. asymetrię promieniowania od ciepłego sufitu – zbyt gorący sufit nad głową jest odczuwany jako „grzanie w głowę”. Ile to stopni na powierzchni, zależy od wielkości pokoju i wysokości sufitu, dlatego sufity grzewcze w pomieszczeniach mieszkalnych projektuje się zwykle do ok. <b>30–32 °C</b>, a nie na 40 °C jak grzejnik. U nas jesienią sufit ma ok. 25 °C, zimą ok. 30 °C.</p>
  <p>Dodatkowe zalety, które czuć na co dzień: nie ma ruchu powietrza ani kurzu unoszonego przez grzejniki, nic nie szumi, a ściany i meble nie zabierają miejsca.</p>

  <h3 class="sdl-bil-h">4. Niska temperatura wody, czyli dobra praca pompy ciepła</h3>
  <p>Skoro sufit ma mieć 25–30 °C, woda w matach wystarczy ciepła o kilka stopni więcej – teraz zasilamy maty wodą o temperaturze <b><?php echo sdl_num($sup); ?> °C</b>, a wraca <b><?php echo sdl_num($ret); ?> °C</b>. Zimą, w mrozy, potrzebujemy wody ok. 35–38 °C. Grzejniki potrzebują zwykle 45–55 °C. Dla pompy ciepła to ogromna różnica: im niższa temperatura wody, tym mniej pracy musi wykonać sprężarka. Typowo każdy stopień mniej poprawia COP o ok. 2–3%.</p>
  <p>Mata kapilarna to siatka cienkich rurek z polipropylenu połączonych z grubszymi rurami zbiorczymi. W matach typu B (np. GB10, SB10, SB20) rurki mają średnicę <b>4,3 mm</b>, w wersjach bez „B” – 3,4 mm. Liczba w nazwie to rozstaw rurek: co 10 mm albo co 20 mm. W zdecydowanej większości instalacji, które wykonuję, wybieram najmocniejsze maty <b>GB10 i SB10</b>: montaż wymaga tyle samo pracy, cena jest prawie taka sama, a moc z metra kwadratowego – najwyższa. Rurki są gęsto, więc ciepło rozchodzi się równomiernie i z wody do powierzchni sufitu tracimy tylko kilka stopni. Na naszych zdjęciach: woda średnio ok. <?php echo sdl_num($avg, 0); ?> °C, sufit ok. 24–25 °C.</p>

  <h3 class="sdl-bil-h">5. Co widzi kamera termowizyjna</h3>
  <p>Zdjęcia zrobione 27.11.2026 wieczorem, w trakcie grzania. Kolory oznaczają temperaturę powierzchni: żółty – najcieplej, fiolet – najzimniej (skala z prawej strony każdego zdjęcia).</p>
  <div class="sdl-figs">
    <?php echo $img('termo-sufit-okno-1.webp', 'Termowizja: sufit z matami kapilarnymi ok. 25 °C, widoczne pasy mat, okno balkonowe z ramą 12,5 °C', 'Sufit ok. 25 °C. Widać pasy mat – jaśniejsze tam, gdzie biegną rurki, ciemniejsze w przerwach między matami.'); ?>
    <?php echo $img('termo-sufit-okno-2.webp', 'Termowizja: ciepły sufit z matami kapilarnymi 24,3 °C nad oknem, najzimniejszy punkt dolna rama okna 12,4 °C', 'Ten sam układ w drugim pokoju: sufit 24,3 °C, najzimniej przy dolnej ramie okna – 12,4 °C.'); ?>
    <?php echo $img('termo-okno-biurko.webp', 'Termowizja: ciepły sufit z matami kapilarnymi nad oknem, rama okna 14,3 °C, gorący punkt elektroniki na biurku 43,6 °C', 'Ciepły sufit u góry, okno z ramą 14,3 °C. Gorący punkt 43,6 °C na biurku to elektronika – świeci w podczerwieni najmocniej w kadrze.', 960, 720); ?>
    <?php echo $img('termo-okno-poziome.webp', 'Termowizja: drzwi wejściowe z przeszkleniem, krawędź 12,7 °C, nad nimi ciepły sufit z matami ok. 26 °C', 'Drzwi wejściowe: najzimniej na krawędzi – 12,7 °C. Nad nimi ciepły sufit, ok. 26 °C.', 960, 720); ?>
  </div>
  <p>Czego uczą te obrazy:</p>
  <ul>
    <li><b>Sufit jest najcieplejszą dużą powierzchnią w pokoju</b> – to on oddaje ciepło. Pasy widać, bo maty nie pokrywają sufitu w 100%: między nimi zostają wąskie przerwy (na profile, oprawy, rury zbiorcze).</li>
    <li><b>Okna i drzwi wejściowe są najzimniejsze.</b> Szyby, ramy i krawędzie drzwi mają 12–14 °C, podczas gdy ściany ok. 20 °C. Okno „zabiera” promieniowanie z pokoju – stojąc przy nim, czujemy chłód, choć powietrze ma tyle samo stopni.</li>
    <li><b>Punkt rosy ma znaczenie także zimą.</b> Teraz w domu wynosi <?php echo sdl_num($v['rosa'] ?? null); ?> °C, a najzimniejsza rama ma 12,4 °C – zapas jest, ale niewielki. Przy wyższej wilgotności dolna krawędź okna zaczęłaby się pocić. To jeden z powodów, dla których pilnujemy wymiany powietrza (<a href="<?php echo esc_url(home_url('/reqnet/')); ?>">rekuperator</a>).</li>
    <li><b>Uwaga na szkło:</b> szyba częściowo odbija podczerwień jak lustro, więc kamera pokazuje na niej mieszankę własnej temperatury szkła i odbić. Wiarygodniejsze są odczyty z ram i ścian.</li>
  </ul>

  <h3 class="sdl-bil-h">6. Łazienki – maty także w podłodze</h3>
  <p>W łazienkach maty leżą również w podłodze, pod mikrocementem – łazienki mają mikrocement na podłogach i ścianach, bez płytek; tylko sufit jest z płyt g-k, tak jak w pokojach. Cienka warstwa mikrocementu dobrze przewodzi ciepło, więc podłoga reaguje szybko. Ciepła podłoga to komfort bosych stóp i szybko schnąca posadzka. Norma ogrzewania płaszczyznowego (EN 1264) dopuszcza w łazienkach powierzchnię podłogi do 33 °C, w pokojach do 29 °C – my, przy niskiej temperaturze wody, jesteśmy wyraźnie poniżej.</p>
  <div class="sdl-figs sdl-figs-2">
    <?php echo $img('termo-lazienka-1.webp', 'Termowizja łazienki: podłoga z matami kapilarnymi pod mikrocementem ok. 24 °C, ściany ok. 17–19 °C', 'Podłoga ok. 24 °C – widać dokładnie prostokąt maty. Ściany z mikrocementu bez mat: ok. 17–19 °C.'); ?>
    <?php echo $img('termo-lazienka-2.webp', 'Termowizja drugiej łazienki: podłoga z matami ok. 28 °C, dywaniki chłodniejsze', 'Druga łazienka: podłoga do 28 °C. Dywaniki są chłodniejsze – izolują i zatrzymują ciepło pod sobą.'); ?>
  </div>
  <p>Na zdjęciach dobrze widać, gdzie leżą maty: ciepłe pole kończy się ostrą krawędzią, a poza nim podłoga ma temperaturę pokoju. To zaleta i ograniczenie jednocześnie – ciepło jest tam, gdzie je zaplanowano, więc matę trzeba rozłożyć na całej wolnej powierzchni podłogi.</p>

  <h3 class="sdl-bil-h">7. Te same maty latem chłodzą</h3>
  <p>Wystarczy puścić przez maty chłodną wodę, a sufit zacznie pochłaniać promieniowanie cieplne ludzi i przedmiotów – bez nawiewu zimnego powietrza i bez przeciągów. Granicą jest <b>punkt rosy</b>: jeśli sufit będzie chłodniejszy od punktu rosy powietrza, zacznie się na nim skraplać woda. Dlatego temperaturę wody chłodzącej ustawia się z zapasem 1,5–2 K powyżej punktu rosy, liczonego na bieżąco z temperatury i wilgotności w pokojach (kafelek „Punkt rosy” wyżej).</p>

  <?php echo sdl_sr_block('<p>Maty kapilarne – odczyt z ' . esc_html(sdl_when($M['ts'])) . ' (czas polski): zasilanie mat ' . sdl_num($sup) . ' °C, powrót z mat ' . sdl_num($ret) . ' °C (różnica ' . sdl_num($dt) . ' K), temperatura zadana na zaworze mieszającym ' . sdl_num($v['zawor_zadana'] ?? null, 0) . ' °C, otwarcie zaworu ' . sdl_num($v['zawor_otwarcie'] ?? null, 0) . ' %, woda z wężownicy przed zaworem ' . sdl_num($v['zawor_przed'] ?? null) . ' °C. Temperatury pomieszczeń: salon ' . sdl_num($v['salon'] ?? null) . ' °C, sypialnia ' . sdl_num($v['sypialnia'] ?? null) . ' °C, łazienka ' . sdl_num($v['lazienka'] ?? null) . ' °C; punkt rosy ' . sdl_num($v['rosa'] ?? null) . ' °C. Ok. 88 m² mat na 102 m² domu; sufit grzewczy ok. 25 °C jesienią i ok. 30 °C zimą daje ok. 31–64 W/m².</p>');
  echo sdl_dataset_ld('Maty kapilarne – ogrzewanie sufitowe na żywo', 'Temperatury wody zasilającej i powrotnej mat kapilarnych, nastawa i otwarcie zaworu mieszającego, temperatury pomieszczeń i punkt rosy w domu z kontenerów pod Poznaniem. Odczyty co 5 minut.', ['zasilanie mat [°C]', 'powrót z mat [°C]', 'temperatura zadana zaworu [°C]', 'otwarcie zaworu [%]', 'temperatury pomieszczeń [°C]', 'punkt rosy [°C]']); ?>

  <h3 class="sdl-bil-h" id="co-inaczej">8. Co zrobilibyśmy dziś inaczej</h3>
  <div class="sdl-figs sdl-figs-1">
    <?php echo $img('termo-sypialnia.webp', 'Termowizja sypialni: sufit z matami tylko ok. 22,5 °C, okno 12,5–16,5 °C', 'Sypialnia: sufit ok. 22,5 °C, wyraźnie chłodniejszy niż w innych pokojach. Okno: rama 12,5 °C, szyba ok. 16,5 °C.'); ?>
  </div>
  <p>Maty w domu to SB20 i jest ich trochę za mało – dlatego w mrozy potrzebujemy cieplejszej wody, niż byłoby to konieczne. Najlepiej widać to w sypialni: sufit ma tam ok. 22,5 °C, a sypialnia bywa niedogrzana, choć ustawiamy w niej niższą temperaturę niż w reszcie domu. Dziś wybralibyśmy gęstsze maty SB10 i dodali maty ścienne w kilku pokojach. Więcej o tym: <a href="<?php echo esc_url(home_url('/heiko/#czego-nauczyl-nas-dom')); ?>">czego nauczył nas ten dom</a>, a o planowanym osobnym buforze dla ogrzewania: <a href="<?php echo esc_url(home_url('/heiko/#plan-rozwojowy')); ?>">Pompa ciepła Heiko – plan rozwojowy</a>.</p>
</div>
<?php
    return ob_get_clean();
});

/* ---------- [sdl_plan] – podział CO/CWU, zawory przeciwzamrożeniowe, plan rozwojowy (treść uzgodniona z właścicielem 27.11.2026) ---------- */
add_shortcode('sdl_plan', function () {
    wp_enqueue_style('sdl', SDL_URL . 'assets/sdl.css', [], SDL_VER);
    wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', [], '4.4.1', true);
    wp_enqueue_script('sdl-zawor', SDL_URL . 'assets/sdl-zawor.js', ['chartjs'], SDL_VER, true);
    wp_localize_script('sdl-zawor', 'SDLZAW', ['maty' => rest_url('sdl/v1/series?device=maty&metrics=zawor_zadana,zawor_temp&range=48h'), 'heiko' => rest_url('sdl/v1/series?device=heiko&metrics=setpoint,outdoor&range=48h')]);
    ob_start(); ?>
<div class="sdl-wrap sdl-bilans sdl-plan">
  <p class="sdl-bil-lead">Liczby na tej stronie pokazują nie tylko pompę, ale cały układ, w którym pracuje. Kilka jego cech zmienia sposób czytania danych – a część z nich chcemy w najbliższych latach poprawić.</p>

  <h3 class="sdl-bil-h">1. Zużycie „CWU” a ogrzewanie – jak czytać podział</h3>
  <p>Pompa grzeje jeden zasobnik NOEL 700 l. W górnej części ma wężownicę ciepłej wody (CWU), a w dolnej wężownicę miedzianą, która zasila kapilarne maty sufitowe. W praktyce <b>część energii zapisanej jako „CWU” to w rzeczywistości ogrzewanie domu</b>: gdy pompa grzeje zasobnik w trybie CWU, maty pobierają z niego ciepło.</p>
  <p>Widać to w danych. Dobowe zużycie na „CWU” rośnie razem z mrozem – z ok. 6,6 kWh przy +1,6 °C do ok. 10 kWh przy −1,5 °C (23–26.11.2026) – a przecież ilość ciepłej wody zużywanej przez domowników nie zależy od pogody. Do tego maty dostają ciepło z wody o temperaturze CWU, a nie z obiegu niskotemperaturowego, co obniża sprawność pompy.</p>
  <p>Dlatego <b>podział CO / CWU traktujemy orientacyjnie</b>. Miarodajne jest zużycie łączne, przeliczone na temperaturę zewnętrzną – stąd wykres kWh na stopień wyżej na stronie.</p>

  <h3 class="sdl-bil-h">2. Zawory przeciwzamrożeniowe – ochrona, która kosztuje</h3>
  <p>W obiegu nie ma glikolu – płynie czysta woda. Przed zamarznięciem chronią ją zawory przeciwzamrożeniowe przy jednostce zewnętrznej: gdy woda zbliży się do zera, zawory otwierają się i ją wypuszczają, zanim rozsadzi rury albo wymiennik. To skuteczne zabezpieczenie na wypadek awarii zasilania, gdy staje pompa obiegowa.</p>
  <p>Ma jednak swoją cenę. Zawory muszą „czuć” temperaturę otoczenia, więc są odkryte i nieizolowane – to miejsce, przez które instalacja traci ciepło przez cały sezon. Strata nie jest duża, ale stała.</p>
  <p>Gdy dom dostanie magazyn energii, pompa obiegowa będzie miała zasilanie także podczas przerw w dostawie prądu. Wtedy zawory będzie można usunąć albo solidnie zaizolować (co w praktyce wyłącza ich działanie) – a rolę zabezpieczenia przejmie zasilanie awaryjne i alarm w Home Assistant przy spadku temperatury wody w obiegu zewnętrznym.</p>

  <h3 class="sdl-bil-h" id="plan-rozwojowy">3. Plan rozwojowy (2027–2028)</h3>
  <ol>
    <li><b>Etap 2: garaż.</b> Cztery kontenery już stoją – teraz czeka nas ich ocieplenie i wykończenie.</li>
    <li><b>Rozbudowa instalacji po wykończeniu garażu:</b> osobny bufor dla ogrzewania, który oddzieli ciepłą wodę od obiegu mat. Maty będą zasilane bezpośrednio niską temperaturą, bez pośrednictwa zasobnika CWU. Przy tej przebudowie zamontujemy też ciepłomierz.</li>
    <li><b>Spodziewany efekt:</b> mniej kWh na stopień, wyższy COP i rzetelny podział energii na ogrzewanie i ciepłą wodę.</li>
  </ol>
  <p>Wszystkie zmiany zmierzymy tym samym wskaźnikiem kWh na stopień – żeby porównanie „przed i po” było uczciwe.</p>

  <h3 class="sdl-bil-h" id="czego-nauczyl-nas-dom">4. Czego nauczył nas ten dom</h3>
  <p>Układ mat zostaje bez zmian – jego przebudowa oznaczałaby zbyt duże zniszczenia wykończonych wnętrz. Gdybyśmy jednak budowali dziś, zrobilibyśmy to inaczej:</p>
  <ul>
    <li><b>gęstsze maty kapilarne SB10</b> zamiast SB20,</li>
    <li><b>dodatkowe maty ścienne</b> w jednym z pokoi dzieci, w sypialni i w salonie.</li>
  </ul>
  <p>Dane z naszych termostatów pokazują dlaczego: w tych dwóch pokojach temperatura przez dużą część sezonu nie osiąga wartości zadanej (w ostatnich dwóch tygodniach przez 36–50% czasu była niższa o ponad 0,5 °C), a w salonie dodatkowe ok. 10 m² mat pozwoliłoby częściej zamykać obieg. To wiedza, którą stosujemy dziś w nowych projektach.</p>

  <h3 class="sdl-bil-h" id="plan-optymalizacyjny">5. Plan optymalizacyjny – nastawy sterownika krok po kroku</h3>
  <p>Przejrzeliśmy wszystkie ekrany sterownika hydroboksa i porównaliśmy nastawy z danymi z ostatnich tygodni. Wniosek: pompa pracuje poprawnie, ale część ustawień każe jej grzać wodę cieplej, niż dom potrzebuje. A każdy stopień niżej to ok. 2–3% lepszy COP.</p>
  <p>Zmiany wprowadzamy <b>po kolei, co kilka dni</b>, a nie wszystkie naraz. Inaczej nie dałoby się powiedzieć, która zmiana ile dała – ani która odpowiada za ewentualny chłodniejszy pokój czy więcej startów sprężarki. Każdy krok oceniamy na tych samych danych co resztę strony: COP z cykli bufora, liczba startów, czas pracy i temperatury w pokojach, przy podobnej pogodzie.</p>
  <h4 class="sdl-bil-h" id="kalibracja">Krok 0: kalibracja czujników – zanim cokolwiek policzymy</h4>
  <p>Już pierwsza noc z krzywą grzewczą pokazała, że czujniki w instalacji nie mówią tym samym językiem. W zbiorniku siedzą obok siebie czujniki pompy i nasze czujniki Zigbee (wszystkie w tulejach, czyli w wodzie). Czujnik pompy na dole zbiornika pokazuje o ok. 2 K więcej niż Zigbee obok – a nawet więcej niż czujnik pompy w połowie zbiornika, co w zbiorniku z ciepłą wodą u góry jest fizycznie niemożliwe. Jeden z czujników zaworu mieszającego wskazuje z kolei temperaturę powietrza w maszynowni, zamiast wody.</p>
  <p>To nie drobiazg: pompa reguluje grzanie na swoim czujniku z dołu zbiornika. Jeśli zawyża on o 2 K, pompa rusza za późno, a maty przez część doby dostają chłodniejszą wodę, niż ustawiliśmy. Dlatego przed oceną kolejnych nastaw kalibrujemy wszystko, co się da wyjąć:</p>
  <ul>
    <li><b>Punkt 0,0 °C – lód z wodą.</b> Naczynie pełne pokruszonego lodu, zalane wodą tylko w szczelinach. To wzorzec praktycznie laboratoryjny: mieszanina lodu i wody ma zawsze 0 °C, niezależnie od tego, co pokazuje jakikolwiek termometr. Odchyłka każdego czujnika to jego poprawka.</li>
    <li><b>Punkt kontrolny ok. 30 °C – kąpiel w myjce ultradźwiękowej z podgrzewaną misą</b> (bez włączania ultradźwięków). Tu nie liczy się, ile pokażą czujniki, tylko czy po poprawce z 0 °C pokażą <b>dokładnie to samo</b>. Jeśli któryś odjedzie, ma błąd rosnący z temperaturą i dostanie poprawkę dwupunktową.</li>
    <li><b>Kolejność:</b> najpierw czujniki Zigbee z bufora i czujniki Sinum z rur, na końcu – przy wyłączonej pompie – czujniki pompy. Odczyty z tej samej minuty zapisuje Home Assistant, więc nikt nie przepisuje liczb z ekranów.</li>
    <li><b>Montaż po kalibracji:</b> czujniki rurowe wracają na miejsce z pastą termoprzewodzącą, opaską i otuliną – kalibracja usuwa błąd czujnika, ale nie błąd złego przylegania do rury.</li>
  </ul>
  <p>Wyniki – tabelę z poprawką dla każdego czujnika – opublikujemy tutaj. Od nich zależy też, gdzie dokładnie położyć krzywą grzewczą pompy: musi ona wypadać o kilka stopni powyżej krzywej pogodowej zaworu mieszającego (strata na wężownicy + histereza).</p>
  <ol>
    <li><b>Krzywa grzewcza zamiast stałej temperatury 37 °C i histereza 3 K zamiast 5 K</b> – <i>wdrożone w nocy z 27 na 28.11.2026, obserwujemy</i>. Dotąd zbiornik wahał się między ok. 32 a 42 °C niezależnie od pogody. Teraz temperatura wody zależy od pogody (−20 / −10 / −5 / +5 / +10 °C → 44 / 41 / 39 / 35 / 30 °C), a wahania są węższe. Lekcja z pierwszej godziny: w sterowniku było ustawione przesunięcie krzywej o +3 K („Curve 1 Parallel Move”) – niewidoczne przy stałej temperaturze, ale po włączeniu krzywej pompa zadała 39 °C zamiast ok. 36 °C. Po zmianie przesunięcia na 0 wszystko się zgadza. Uczciwie: przy ok. 0 °C zysk będzie niewielki – już przy 37 °C zawór mieszający przez znaczną część doby nie dobijał do zadanej, więc niżej zejść się nie da. Oszczędność przyjdzie w cieplejsze dni (+3…+10 °C), kiedy woda może być o kilka stopni chłodniejsza.</li>
    <li><b>Ciepła woda raz dziennie, w najcieplejszych godzinach</b> (ok. 13–16), a w nocy bez grzania CWU. Taryfa jest całodobowa, więc nie chodzi o cenę prądu, tylko o COP: po południu powietrze jest o kilka stopni cieplejsze, a grzanie wody do 48 °C to najcięższa praca pompy.</li>
    <li><b>Temperatura ciepłej wody 48 → 45 °C</b>, jeśli komfort domowników się utrzyma, i <b>dezynfekcja termiczna raz w tygodniu</b> (dziś wyłączona). Kilka procent oszczędności na CWU, praktycznie za darmo.</li>
  </ol>
  <h4 class="sdl-bil-h" id="zawor-a-krzywa">Wykres, który prowadzi nas dalej: zawór mieszający a krzywa pompy</h4>
  <p>W domu pracują dwie krzywe pogodowe. <b>Krzywa zaworu mieszającego</b> w Sinum ustala, jak ciepłą wodę dostają maty. <b>Krzywa pompy</b> ustala, jak ciepły jest zbiornik, z którego zawór tę wodę miesza. Zawór może tylko schłodzić wodę z wężownicy – nie podgrzeje jej. Jeśli zbiornik jest za chłodny, rzeczywista temperatura za zaworem spada poniżej nastawy, a pokoje z najmniejszą powierzchnią mat zaczynają marznąć.</p>
  <div class="sdl-card sdl-zawor"><div class="sdl-canvas sdl-chart-tall"><canvas data-zawor="chart" role="img" aria-label="Wykres z ostatnich 48 godzin: nastawa zaworu mieszającego, rzeczywista temperatura za zaworem, temperatura zadana pompy ciepła i temperatura zewnętrzna"></canvas></div>
  <p class="sdl-note">Ostatnie 48 godzin, odczyty co 5 minut. Gdy pompa grzeje ciepłą wodę, jej temperatura zadana na chwilę skacze do ok. 48 °C – to nie ogrzewanie, tylko CWU.</p></div>
  <p><b>Wniosek, którego będziemy się trzymać:</b> krzywej pompy nie szukamy „na oko”, tylko patrząc, jak <b>rzeczywista temperatura za zaworem</b> zależy od <b>temperatury zadanej przez krzywą pompy</b>. Dobra krzywa pompy to taka, przy której pomarańczowa linia (za zaworem) przez całą dobę leży na żółtej (nastawa zaworu) – i ani stopnia wyżej, bo każdy nadmiarowy stopień w zbiorniku to gorszy COP. Najpierw jednak kalibracja czujników (krok 0) – dopiero po niej różnica między liniami będzie mówić o instalacji, a nie o błędach termometrów. Potem dla każdej temperatury zewnętrznej odczytamy z danych, ile stopni zapasu ponad krzywą zaworu naprawdę potrzebuje pompa, i dopiero z tego ułożymy jej krzywą.</p>
  <h4 class="sdl-bil-h" id="regulacja-kaskadowa">Krok 4 (plan): Home Assistant jako nadrzędny regulator</h4>
  <p>Skoro najwięcej mówi zależność „zawór – pompa”, naturalny następny krok to nie ręczne przestawianie krzywych, tylko automat, który pilnuje obu urządzeń naraz. W automatyce nazywa się to <b>regulacją kaskadową</b>:</p>
  <ul>
    <li><b>Warstwa szybka zostaje na miejscu.</b> Sinum dalej trzyma temperaturę za zaworem z sekundy na sekundę, pompa dalej pilnuje sprężarki. Gdy Home Assistant przestanie działać, dom grzeje się na własnych krzywych urządzeń.</li>
    <li><b>Warstwa wolna w Home Assistant</b>, z korektą co 15–30 minut. Zadana zaworu = krzywa pogodowa + korekta z pokoi (stopniominuty niedogrzania, czyli różnica między temperaturą zadaną a rzeczywistą, sumowana w czasie – liczona z mediany pokoi, a nie z najsłabszego, żeby nie przegrzewać reszty domu). Zadana pompy = zadana zaworu + zapas na wężownicę i histerezę – a ten zapas <b>sam się dostraja</b>: rośnie, gdy zawór nie dobija do nastawy, maleje, gdy przez dobę ma nadmiar.</li>
    <li><b>Ograniczone uprawnienia.</b> Automat zmienia tylko przesunięcia krzywych – „korektę” zaworu w Sinum i przesunięcie krzywej pompy – w zakresie kilku stopni. Krzywe w urządzeniach zostają jako podstawa i zabezpieczenie.</li>
    <li><b>Zwykły regulator PI, a nie sztuczna inteligencja.</b> Dom z matami w suficie reaguje w ciągu godzin, ma ogromną bezwładność i mało zakłóceń – wystarczy wolna całka, ograniczenie szybkości zmian i zabezpieczenie przed jej „rozpędzeniem”. Coś mądrzejszego – sterowanie predykcyjne z prognozą pogody i energią z fotowoltaiki – ma sens dopiero na końcu.</li>
  </ul>
  <p>Kolejność: kalibracja czujników, obserwacja kroku 1, sprawdzenie, czy Home Assistant może zapisywać korektę zaworu i przesunięcie krzywej pompy, potem najpierw sam dostrajany zapas pompy (to on likwiduje „zawór nie dobija”), a na końcu korekta z pokoi.</p>
  <p>Trybu cichego nie ruszamy: ogranicza obroty sprężarki, a niższe obroty to wyższy COP. Wrócimy do niego tylko wtedy, gdy w mrozy zabraknie mocy. Wyniki każdego kroku opiszemy tutaj.</p>
</div>
<?php
    return ob_get_clean();
});

add_shortcode('sdl_odszranianie', function ($atts) {
    $a = shortcode_atts(['device' => 'heiko_defrost'], $atts);
    if (!sdl_key_ok($a['device'])) return '';
    wp_enqueue_style('sdl', SDL_URL . 'assets/sdl.css', [], SDL_VER);
    wp_enqueue_script('sdl-defrost', SDL_URL . 'assets/sdl-defrost.js', [], SDL_VER, true);
    wp_localize_script('sdl-defrost', 'SDL', ['api' => esc_url_raw(rest_url('sdl/v1/'))]);
    ob_start(); ?>
<div class="sdl-wrap sdl-defrost" data-sdl-defrost="<?php echo esc_attr($a['device']); ?>">
  <?php $H = sdl_now_latest('heiko'); $hv = $H['values']; $F = sdl_frost($hv['outdoor'] ?? null, $hv['rh_out'] ?? null, $hv['tp'] ?? null, ($hv['power'] ?? 0) > 300);
        $fv = function ($k, $d = 1) use ($F) { return $F ? sdl_num($F[$k], $d) : '–'; }; ?>
  <h3 class="sdl-sec">Warunki szronienia</h3>
  <div class="sdl-kpis" data-frost>
    <div class="sdl-kpi" style="border-top-color:#3b82f6"><div class="sdl-kpi-label">Wilgotność na zewnątrz</div><div class="sdl-kpi-val"><span data-fr="rh"><?php echo $fv('rh', 0); ?></span> <small>%</small></div><div class="sdl-kpi-sub">przy <span data-fr="t"><?php echo $fv('t'); ?></span> °C</div></div>
    <div class="sdl-kpi" style="border-top-color:#3b82f6"><div class="sdl-kpi-label">Woda w powietrzu</div><div class="sdl-kpi-val"><span data-fr="ah"><?php echo $fv('ah'); ?></span> <small>g/m³</small></div><div class="sdl-kpi-sub">wilgotność bezwzględna</div></div>
    <div class="sdl-kpi" style="border-top-color:#3b82f6"><div class="sdl-kpi-label">Punkt rosy / szronu</div><div class="sdl-kpi-val"><span data-fr="dew"><?php echo $fv('dew'); ?></span> / <span data-fr="frost"><?php echo $fv('frost'); ?></span> <small>°C</small></div></div>
    <div class="sdl-kpi"><div class="sdl-kpi-label">Parownik</div><div class="sdl-kpi-val"><span data-fr="ts"><?php echo $fv('ts'); ?></span> <small>°C</small></div><div class="sdl-kpi-sub" data-fr="ts_note"><?php echo $F && $F['est'] ? 'szacunek przy pracy sprężarki' : 'odczyt z pompy'; ?></div></div>
    <div class="sdl-kpi"><div class="sdl-kpi-label">Potencjał szronienia</div><div class="sdl-kpi-val"><span data-fr="pot"><?php echo $fv('pot'); ?></span> <small>g/m³</small></div><div class="sdl-kpi-sub" data-fr="lvl"><?php echo $F ? esc_html(sdl_frost_level($F['pot'])) : ''; ?></div></div>
  </div>
  <div class="sdl-card">
    <div class="sdl-head"><h3>Czy parownik teraz szronieje?</h3></div>
    <p class="sdl-defrost-text" data-fr="story"><?php echo esc_html(sdl_frost_story($F)); ?></p>
    <p class="sdl-note">Potencjał szronienia to ilość wody, która może osiąść jako lód z każdego m³ powietrza przechodzącego przez parownik: różnica między wodą w powietrzu a nasyceniem nad lodem przy temperaturze parownika. Wzory Magnusa; wilgotność z czujnika zewnętrznego.</p>
  </div>
  <h3 class="sdl-sec">Odszranianie</h3>
  <div class="sdl-kpis">
    <div class="sdl-kpi"><div class="sdl-kpi-label">Odszronień dziś</div><div class="sdl-kpi-val"><span data-f="today">–</span></div></div>
    <div class="sdl-kpi"><div class="sdl-kpi-label">Średni czas (ostatnie)</div><div class="sdl-kpi-val"><span data-f="avg">–</span> <small>min</small></div></div>
    <div class="sdl-kpi" style="border-top-color:#3b82f6"><div class="sdl-kpi-label">Ostatnie</div><div class="sdl-kpi-val" style="font-size:24px"><span data-f="last">–</span></div></div>
  </div>
  <div class="sdl-card">
    <div class="sdl-head"><h3>Ostatnie odszranianie – co się działo</h3></div>
    <p class="sdl-defrost-text" data-f="story">Czekam na pierwsze odszranianie zarejestrowane przez Home Assistant.</p>
  </div>
  <div class="sdl-card">
    <div class="sdl-head"><h3>Historia odszraniania – 7 dni</h3></div>
    <p class="sdl-defrost-text" data-f="week">Wczytuję historię…</p>
    <details class="sdl-more">
      <summary><span class="sdl-more-open">Pokaż wszystkie odszraniania</span><span class="sdl-more-close">Zwiń historię</span></summary>
      <div class="sdl-table-wrap"><table class="sdl-table">
        <thead><tr><th scope="col">Kiedy</th><th scope="col">Czas</th><th scope="col">Parownik</th><th scope="col">Woda z pompy</th><th scope="col">Na zewnątrz</th></tr></thead>
        <tbody data-f="rows"><tr><td colspan="5">–</td></tr></tbody>
      </table></div>
    </details>
    <p class="sdl-note">Odszranianie liczymy tylko wtedy, gdy sprężarka pracuje, a wentylator jednostki zewnętrznej stoi – sama flaga z pompy potrafi „wisieć” dłużej.</p>
  </div>
</div>
<?php
    return ob_get_clean();
});

/* stopka bloku danych na żywo – wspólna dla wszystkich podstron */
function sdl_footer_note() {
    return '<div class="sdl-foot"><span class="sdl-foot-dot"></span><p>'
        . 'Dane na żywo z naszego domu z kontenerów, prosto z Home Assistant. '
        . 'Odczyty trafiają na stronę co 5 minut, a otwarta strona odświeża je sama, bez przeładowania. '
        . 'Wartości mogą być opóźnione o kilka minut; dobowe zużycie aktualizuje się co 10 minut. '
        . 'Doby liczymy według czasu polskiego.</p></div>';
}

/* ---------- wersja podstrony: [sdl_wersja v="1.0" data="21.11.2026"]1.0 – opis zmian (linia na wersję)[/sdl_wersja] ---------- */

/* ---------- diagnostyka poczty: POST /sdl/v1/mailtest (tylko administrator, wysyła na e-mail administratora) ---------- */

add_action('rest_api_init', function () {
    register_rest_route('sdl/v1', '/mailtest', [
        'methods'             => 'POST',
        'permission_callback' => function () { return current_user_can('manage_options'); },
        'callback'            => function (WP_REST_Request $r) {
            $err = null;
            $fail = function ($e) use (&$err) { $err = $e->get_error_message(); };
            add_action('wp_mail_failed', $fail);
            $info = [];
            $grab = function ($m) use (&$info) { $info = ['mailer' => $m->Mailer, 'from' => $m->From, 'sender' => $m->Sender, 'host' => $m->Host]; };
            add_action('phpmailer_init', $grab);
            $from = sanitize_email((string) $r->get_param('from'));
            $headers = $from ? ['From: StalowyDOM <' . $from . '>'] : [];
            $ok = wp_mail(get_option('admin_email'), 'StalowyDOM – test poczty ' . wp_date('H:i:s'), 'Wiadomość testowa z serwera strony.', $headers);
            remove_action('wp_mail_failed', $fail);
            remove_action('phpmailer_init', $grab);
            return ['ok' => $ok, 'error' => $err, 'info' => $info, 'mail_fn' => function_exists('mail'), 'sendmail_path' => ini_get('sendmail_path')];
        },
    ]);
});

/* ---------- język strony: treść jest po polsku ---------- */

add_filter('language_attributes', function ($out) { return is_admin() ? $out : preg_replace('/lang="[^"]*"/', 'lang="pl-PL"', $out); }, 20);
add_filter('rank_math/opengraph/facebook/og_locale', function () { return 'pl_PL'; });

/* ---------- kod QR: /qr lub /qr/<nazwa> → licznik skanów i przekierowanie na stronę główną ----------
   Kod wskazuje na naszą domenę, więc nie wygaśnie. Nie zapisujemy adresów IP: unikalność liczymy po skrócie
   (IP + przeglądarka + sól zmieniana codziennie), trzymanym tylko do końca doby. */
add_action('init', function () {
    $path = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
    if (!preg_match('~^/qr(?:/([a-z0-9-]{1,32}))?$~', $path, $m)) return;
    $code = $m[1] ?? 'strona';
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (!preg_match('/bot|crawl|spider|preview|facebookexternalhit|whatsapp|slurp|curl|wget|python/i', $ua)) {
        $day = wp_date('Y-m-d', null, sdl_tz());
        $all = get_option('sdl_qr', []);
        $c = $all[$code] ?? ['total' => 0, 'unique' => 0, 'today' => 0, 'day' => $day, 'last' => 0];
        if ($c['day'] !== $day) { $c['today'] = 0; $c['day'] = $day; }
        $hash = substr(hash('sha256', wp_salt('nonce') . $day . ($_SERVER['REMOTE_ADDR'] ?? '') . $ua), 0, 16);
        $seen = get_transient('sdl_qr_seen_' . $code) ?: [];
        if (($seen['day'] ?? '') !== $day) $seen = ['day' => $day, 'h' => []];
        if (!in_array($hash, $seen['h'], true)) {
            $seen['h'][] = $hash; $c['unique']++;
            set_transient('sdl_qr_seen_' . $code, $seen, DAY_IN_SECONDS);
        }
        $c['total']++; $c['today']++; $c['last'] = time();
        $all[$code] = $c;
        update_option('sdl_qr', $all, false);
    }
    nocache_headers();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    wp_redirect(add_query_arg(['utm_source' => 'qr', 'utm_medium' => $code], home_url('/')), 302);
    exit;
}, 1);

/* ---------- /llms.txt – przewodnik po stronie dla agentów AI (generowany na żywo) ---------- */

add_action('init', function () {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if ($path !== '/llms.txt') return;
    $u = function ($p) { return home_url($p); };
    $api = rest_url('sdl/v1/');
    $L = sdl_now_latest('dom'); $H = sdl_now_latest('heiko');
    $txt = "# StalowyDOM\n\n"
        . "> Parterowy dom z czterech kontenerów morskich 40ft HC pod Poznaniem (budowa 5 lat, mieszkamy od połowy 2025). Dom jest też laboratorium: pompa ciepła, rekuperator, maty kapilarne i inne urządzenia pracują na co dzień, a ich dane są publikowane na żywo. Strona prywatna, uzupełnienie kanału YouTube; język: polski.\n\n"
        . "## Strony\n\n"
        . "- [Projekt domu](" . $u('/projekt/') . "): układ (prostokąt ok. 10 × 12 m, podział na strefę dzienną i odpoczynku), ocieplenie pianą PUR, instalacje.\n"
        . "- [Dom na żywo](" . $u('/live/') . "): zużycie energii całego domu i urządzeń, temperatury, wentylacja – dane co 5 minut.\n"
        . "- [Pompa ciepła Heiko Thermal 15 kW](" . $u('/heiko/') . "): moc, zużycie dobowe i miesięczne z podziałem CO/CWU, bufor NOEL 700 l, odszranianie, czuwanie.\n"
        . "- [Rekuperator reQnet reQ V 400 ERV](" . $u('/reqnet/') . "): wentylacja z odzyskiem ciepła i wilgoci – sprawność odzysku, ciepło odzyskane i do dogrzania, koszt wentylacji, test wydajności 110/160/207 m³/h.\n"
        . "- [Maty kapilarne – grzejemy światłem podczerwonym](" . $u('/maty-kapilarne/') . "): ogrzewanie sufitowe (i podłogowe w łazienkach) matami kapilarnymi – dane na żywo z zaworu mieszającego, moc sufitu 25–32 °C z prawa Stefana–Boltzmanna, temperatura odczuwalna, zdjęcia termowizyjne.\n"
        . "- [Zastosowany sprzęt](" . $u('/sprzet/') . "): urządzenia pracujące w domu.\n"
        . "- [Aquilo WiFi](" . $u('/aquilo/') . "): bezprzewodowy czujnik poziomu szamba.\n"
        . "- [Kontakt](" . $u('/kontakt/') . "): formularz kontaktowy (współpraca, testy sprzętu).\n"
        . "- [Polityka prywatności](" . $u('/polityka-prywatnosci/') . ")\n\n"
        . "## Dane na żywo – API JSON (publiczne, bez klucza, tylko odczyt)\n\n"
        . "- `GET {$api}latest?device=DEVICE` – ostatnie odczyty (`values`), czas odczytu (`ts`, Unix), zużycie dziś (`today_kwh`; dla heiko także `today_co`, `today_cwu`, `today_standby`), liczba startów wczoraj (`starts_yday`).\n"
        . "- `GET {$api}daily?device=DEVICE&metric=energy_total&days=30` – zużycie dobowe [kWh] jako `[[\"RRRR-MM-DD\", kWh], …]`. `metric=outdoor&agg=stats` zwraca `[dzień, średnia, min, maks]` temperatury.\n"
        . "- `GET {$api}series?device=heiko&range=24h|7d|30d&metrics=power,noel_top` – przebieg w czasie.\n"
        . "- `GET {$api}events?device=heiko_defrost` – zdarzenia odszraniania.\n"
        . "- Łączność: " . $u('/starlink/') . " – internet satelitarny Starlink (antena Mini, router Gen3, 2 routery Mini w sieci mesh) i statystyki przesyłu danych z domu na stronę.\n"
        . "- `GET {$api}seasons` – dobowe zużycie prądu pompy i średnia temperatura zewnętrzna (od 01.2026), trendy dla sezonów grzewczych i porównanie przy tej samej temperaturze (efekt obmurowania podłogi). Obliczenia strat ciepła (OZC) są opisane na stronie projektu.\n\n"
        . "Urządzenia (DEVICE) i metryki:\n"
        . "- `dom` – cały dom: `power` [W], `l1` `l2` `l3` [W], `energy_total` [kWh, licznik narastający], `outdoor` [°C].\n"
        . "- `heiko` – pompa ciepła: `power` [W], `energy_total` `energy_co` `energy_cwu` `energy_standby` [kWh, narastająco], `noel_top` `noel_mid` `noel_bot` `supply` `outdoor` [°C], `starts`, `defrost_n`, `defrost_h` [h], `circ_starts` (dzisiejsze), `rh_out` (wilgotność na zewnątrz) [%], `tp` (temperatura parownika) [°C], `fan` (obroty wentylatora), `wind` (wiatr z prognozy met.no) [km/h], `run_h` (czas pracy sprężarki dziś) [h]. Odpowiedź `latest?device=heiko` zawiera też `starts_week_avg`, `starts_month_avg` (średnio startów na dobę), `year_kwh`, `year_starts`, `year_run_h`, `year_starts_avg` (od początku roku), `months` (zużycie i czas pracy sprężarki w 5 ostatnich miesiącach, [RRRR-MM, kWh, h]) i `year_since`.\n"
        . "- `reqnet` – rekuperator: `flow_in` `flow_out` [m³/h], `czerpnia` `nawiew` `wyciag` `wyrzutnia` [°C], `power` [W], `energy_total` [kWh], `co2` [ppm], `rh` [%].\n"
        . "- `maty` – temperatury pomieszczeń: `salon` `sypialnia` `lazienka` [°C], `rosa` (punkt rosy) [°C]; zawór mieszający: `zawor_zadana` (temperatura zadana), `zawor_temp` (zasilanie mat), `zawor_powrot` (powrót z mat), `zawor_przed` (woda z wężownicy w buforze przed zaworem) [°C], `zawor_otwarcie` [%].\n"
        . "- `pompa_cwu` – pompa cyrkulacyjna: `energy_total` [kWh], `starts` (dziś), `run_h` `run_h_yday` [h].\n"
        . "- `serwer` – serwer domowy: `power` [W], `energy_total` [kWh].\n\n"
        . "Odczyty co 5 minut; doby według czasu polskiego (Europe/Warsaw).\n\n"
        . "## Stan teraz\n\n"
        . "- Odczyt: " . sdl_when($L['ts'] ?: $H['ts']) . " (czas polski)\n"
        . "- Cały dom: " . sdl_num(isset($L['values']['power']) ? $L['values']['power'] / 1000 : null, 2) . " kW teraz, " . sdl_num($L['today_kwh'] ?? null) . " kWh dziś\n"
        . "- Pompa ciepła: " . sdl_num($H['values']['power'] ?? null, 0) . " W teraz, " . sdl_num($H['today_kwh'] ?? null) . " kWh dziś\n"
        . "- Na zewnątrz: " . sdl_num($L['values']['outdoor'] ?? ($H['values']['outdoor'] ?? null)) . " °C\n";
    status_header(200);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    header('X-Robots-Tag: noindex');
    echo $txt;
    exit;
}, 0);

/* ---------- opisy SEO i noindex: POST /sdl/v1/meta/<id> (tylko administrator) ---------- */

add_action('rest_api_init', function () {
    register_rest_route('sdl/v1', '/meta/(?P<id>\d+)', [
        'methods'             => 'POST',
        'permission_callback' => function () { return current_user_can('edit_pages'); },
        'callback'            => function (WP_REST_Request $r) {
            $id = (int) $r['id'];
            if (!get_post($id)) return new WP_Error('sdl_nopost', 'Brak strony.', ['status' => 404]);
            $done = [];
            foreach (['rank_math_description', 'rank_math_title', 'rank_math_robots'] as $k) {
                $v = $r->get_param($k);
                if ($v === null) continue;
                update_post_meta($id, $k, is_array($v) ? array_map('sanitize_text_field', $v) : sanitize_text_field($v));
                $done[] = $k;
            }
            /* nowa strona tworzona przez REST: skopiuj ustawienia Divi (builder, układ bez paska bocznego) ze wzorcowej podstrony */
            $src = (int) $r->get_param('copy_divi_from');
            if ($src && get_post($src)) {
                foreach (get_post_meta($src) as $k => $vals) {
                    if (strpos($k, '_et_pb_') !== 0 || $k === '_et_pb_old_content') continue;
                    update_post_meta($id, $k, maybe_unserialize($vals[0]));
                    $done[] = $k;
                }
            }
            return ['id' => $id, 'updated' => $done];
        },
    ]);
});

/* ---------- adres e-mail ukryty przed robotami: [sdl_email u="nazwa" d="domena.pl"] ---------- */

add_shortcode('sdl_email', function ($atts) {
    $a = shortcode_atts(['u' => '', 'd' => ''], $atts);
    $u = preg_replace('/[^a-z0-9._+-]/i', '', $a['u']);
    $d = preg_replace('/[^a-z0-9.-]/i', '', $a['d']);
    if ($u === '' || $d === '') return '';
    $fallback = esc_html($u . ' [małpa] ' . str_replace('.', ' [kropka] ', $d));
    $id = 'sdl-mail-' . wp_unique_id();
    return '<a id="' . $id . '" class="sdl-mail" href="#" data-u="' . esc_attr(strrev($u)) . '" data-d="' . esc_attr(strrev($d)) . '">' . $fallback . '</a>'
        . '<script>(function(a){if(!a)return;var r=function(s){return s.split("").reverse().join("")},m=r(a.dataset.u)+String.fromCharCode(64)+r(a.dataset.d);a.href="mai"+"lto:"+m;a.textContent=m;})(document.getElementById("' . $id . '"));</script>';
});

add_shortcode('sdl_wersja', function ($atts, $content = '') {
    $a = shortcode_atts(['v' => '1.0', 'data' => '', 'id' => ''], $atts);
    $id = $a['id'] !== '' ? $a['id'] : (string) get_the_ID();
    $data = $a['data'] !== '' ? $a['data'] : get_the_modified_date('d.m.Y');
    $lines = array_filter(array_map('trim', preg_split('/\r?\n|<br\s*\/?>/i', wp_strip_all_tags($content, false))));
    wp_enqueue_style('sdl', SDL_URL . 'assets/sdl.css', [], SDL_VER);
    wp_enqueue_script('sdl-ver', SDL_URL . 'assets/sdl-ver.js', [], SDL_VER, true);
    ob_start(); ?>
<div class="sdl-ver" data-sdl-ver="<?php echo esc_attr($a['v']); ?>" data-sdl-page="<?php echo esc_attr($id); ?>">
  <span class="sdl-ver-new" hidden>zmieniono od Twojej ostatniej wizyty ·</span>
  <button type="button" class="sdl-ver-btn" aria-expanded="false">wersja <?php echo esc_html($a['v']); ?> · <?php echo esc_html($data); ?></button>
  <?php if ($lines) : ?>
  <div class="sdl-ver-log" hidden>
    <div class="sdl-ver-log-title">Co nowego</div>
    <ul><?php foreach ($lines as $l) echo '<li>' . esc_html($l) . '</li>'; ?></ul>
  </div>
  <?php endif; ?>
</div>
<?php
    return ob_get_clean();
});

/* ---------- poprawki wyglądu całej witryny (motyw Divi) ---------- */
add_action('wp_head', function () {
    /* nowdoc: cudzysłowy w CSS nie przerywają łańcucha PHP */
    echo <<<'SDLCSS'
<style id="sdl-site-css">
.et_mobile_menu{background:#242f3a !important;border-top-color:#f97316 !important}
.et_mobile_menu li a{color:#ffffff !important;border-bottom-color:#334150 !important}
.et_mobile_menu li a:hover,.et_mobile_menu li.current-menu-item>a{background:#1a222a !important;color:#f97316 !important;opacity:1 !important}
.mobile_menu_bar:before{color:#f97316 !important}
img.wp-image-21{height:auto !important;object-fit:contain}
.et-l--footer .et_pb_text:has(a[href='/polityka-prywatnosci/']),.et-l--footer .et_pb_text:has(a[href='/polityka-prywatnosci/']) p{text-align:center;justify-content:center}
.et-l--footer .et_pb_image{text-align:center;justify-content:center}
.wpforms-container .wpforms-field-label,.wpforms-container .wpforms-field-sublabel{color:#e6edf3 !important}
.wpforms-container input[type=text],.wpforms-container input[type=email],.wpforms-container input[type=tel],.wpforms-container input[type=number],.wpforms-container textarea,.wpforms-container select{color:#ffffff !important;background:#242f3a !important;border-color:#4a5a6b !important}
.wpforms-container input:focus,.wpforms-container textarea:focus{border-color:#f97316 !important;box-shadow:0 0 0 1px #f97316 !important}
.wpforms-container ::placeholder{color:#9aa7b4 !important;opacity:1 !important}
.wpforms-container .wpforms-required-label{color:#f97316 !important}
.wpforms-container label.wpforms-error,.wpforms-container em.wpforms-error{color:#ff8a80 !important}
.wpforms-confirmation-container-full{background:#242f3a !important;border-color:#f97316 !important;color:#e6edf3 !important}
@media (min-width:768px){.et_pb_row:has(>.et_pb_column>.et_pb_blurb){align-items:stretch}.et_pb_column:has(>.et_pb_blurb){display:flex;flex-direction:column}.et_pb_column>.et_pb_blurb{flex:1 1 auto}.et_pb_column>.et_pb_image:empty{display:none}}
</style>

SDLCSS;
}, 99);

/* ---------- aktualizacje z GitHuba (Releases) ---------- */

define('SDL_REPO', 'stalowydom/livedata');

/* Najnowsza wersja = nagłówek "Version:" pliku wtyczki na gałęzi main (raw.githubusercontent.com, bez limitów API).
   Paczka = załącznik wydania vX.Y.Z budowany przez GitHub Actions. */
function sdl_remote_release($force = false) {
    $rel = $force ? false : get_site_transient('sdl_release');
    if ($rel === false) {
        $res = wp_remote_get('https://raw.githubusercontent.com/' . SDL_REPO . '/main/stalowydom-live/stalowydom-live.php?nocache=' . time(), [
            'timeout' => 10,
            'headers' => ['User-Agent' => 'stalowydom-live', 'Range' => 'bytes=0-2047', 'Cache-Control' => 'no-cache'],
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
