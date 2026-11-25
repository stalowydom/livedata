<?php
/*
Plugin Name: StalowyDOM Live Data
Description: Odbiera odczyty z Home Assistant i pokazuje je na stronie jako kafelki i wykresy. Shortcode: [sdl_heiko].
Version: 1.25.1
Author: StalowyDOM
Requires PHP: 7.4
*/

if (!defined('ABSPATH')) exit;

define('SDL_VER', '1.25.1');
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
    $limit  = min(50, max(1, (int) $r->get_param('limit') ?: 10));
    if (!sdl_key_ok($device)) return new WP_Error('sdl_bad', 'device', ['status' => 400]);
    return sdl_cached("events|$device|$limit", function () use ($device, $limit) {
        global $wpdb;
        $t = sdl_table();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT ts, metric, value FROM $t WHERE device=%s ORDER BY ts DESC, id DESC LIMIT %d", $device, $limit * 12));
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
            $out = [$row->d, round((float) $row->v, 4)];
            if ($agg === 'stats') { $out[] = round((float) $row->vmin, 1); $out[] = round((float) $row->vmax, 1); }
            return $out;
        }, $rows);
    }, 600);
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

  <div class="sdl-kpis">
    <div class="sdl-kpi"><div class="sdl-kpi-label">Zużycie dziś</div><div class="sdl-kpi-val"><span data-k="today">–</span> <small>kWh</small></div><div class="sdl-kpi-sub" data-k="today_split"></div></div>
    <div class="sdl-kpi"><div class="sdl-kpi-label">Wczoraj</div><div class="sdl-kpi-val"><span data-k="yesterday">–</span> <small>kWh</small></div><div class="sdl-kpi-sub" data-k="yesterday_split"></div></div>
    <div class="sdl-kpi"><div class="sdl-kpi-label">Moc teraz</div><div class="sdl-kpi-val"><span data-k="power">–</span> <small data-k="power_unit">W</small></div></div>
    <div class="sdl-kpi" title="Energia pobrana, gdy sprężarka stoi: elektronika (ok. 12 W) i okresowe włączenia pompy obiegowej (ok. 109 W)."><div class="sdl-kpi-label">Czuwanie dziś</div><div class="sdl-kpi-val"><span data-k="standby">–</span> <small data-k="standby_unit">Wh</small></div><div class="sdl-kpi-sub">starty pompy obiegowej: <span data-k="circ">–</span></div></div>
    <div class="sdl-kpi"><div class="sdl-kpi-label">Na zewnątrz</div><div class="sdl-kpi-val"><span data-k="outdoor">–</span> <small>°C</small></div></div>
    <div class="sdl-kpi"><div class="sdl-kpi-label"><span data-k="m_cur_name">Ten miesiąc</span> (do dziś)</div><div class="sdl-kpi-val"><span data-k="m_cur">–</span> <small>kWh</small></div></div>
    <div class="sdl-kpi"><div class="sdl-kpi-label" data-k="m_prev_name">Poprzedni miesiąc</div><div class="sdl-kpi-val"><span data-k="m_prev">–</span> <small>kWh</small></div></div>
  </div>

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
        [['Moc teraz', sdl_v('heiko', 'power', 'W', 0)], ['Wczoraj', '<span data-d="heiko_yday">–</span> kWh'], ['Dziś: CO / CWU', '<span data-d="heiko_co">–</span> / <span data-d="heiko_cwu">–</span> kWh'], ['Starty dziś', sdl_v('heiko', 'starts', '', 0)], ['<span data-d="heiko_mcur_name">Ten miesiąc</span>', '<span data-d="heiko_mcur">–</span> kWh'], ['<span data-d="heiko_mprev_name">Poprzedni miesiąc</span>', '<span data-d="heiko_mprev">–</span> kWh'], ['Odszranianie dziś', '<span data-d="heiko_defrost">–</span>'], ['Zasilanie z PC', sdl_v('heiko', 'supply', '°C'), 'running']], $heiko);
    echo sdl_dom_row('NOEL', 'sdl-n-noel', 'Bufor 700 l z wężownicą CWU', 'Woda grzewcza z pompy, wężownica CWU i wężownica mat kapilarnych.',
        [['Góra', sdl_v('heiko', 'noel_top', '°C')], ['Środek', sdl_v('heiko', 'noel_mid', '°C')], ['Dół', sdl_v('heiko', 'noel_bot', '°C')]], $heiko);
    echo sdl_dom_row('reQnet', '', 'Rekuperator entalpiczny', 'Wentylacja z odzyskiem ciepła i wilgoci.',
        [['Wymiana powietrza (nawiew / wyciąg)', sdl_v('reqnet', 'flow_in', '', 0) . ' / ' . sdl_v('reqnet', 'flow_out', 'm³/h', 0)], 'br',
         ['Czerpnia', sdl_v('reqnet', 'czerpnia', '°C')], ['Nawiew', sdl_v('reqnet', 'nawiew', '°C')], ['Wyciąg / wyrzutnia', sdl_v('reqnet', 'wyciag', '', 1) . ' / ' . sdl_v('reqnet', 'wyrzutnia', '°C')], 'br',
         ['Moc', sdl_v('reqnet', 'power', 'W', 0)], ['Dziś', '<span data-e="reqnet_today">–</span>'], ['30 dni', '<span data-e="reqnet_30">–</span>']], '');
    echo sdl_dom_row('Maty kapilarne', 'sdl-n-small', 'Ogrzewanie i chłodzenie sufitowe', 'Temperatury pomieszczeń z termostatów Sinum i zawór mieszający, który zasila maty wodą z wężownicy w buforze.',
        [['Salon', sdl_v('maty', 'salon', '°C')], ['Sypialnia', sdl_v('maty', 'sypialnia', '°C')], ['Łazienka', sdl_v('maty', 'lazienka', '°C')], 'br',
         ['Punkt rosy', sdl_v('maty', 'rosa', '°C')], ['Zawór: temp. zadana', sdl_v('maty', 'zawor_zadana', '°C', 0)], ['Otwarcie zaworu', sdl_v('maty', 'zawor_otwarcie', '%', 0)], 'br',
         ['Przed zaworem (z wężownicy)', sdl_v('maty', 'zawor_przed', '°C')], ['Zasilanie mat', sdl_v('maty', 'zawor_temp', '°C')], ['Powrót z mat', sdl_v('maty', 'zawor_powrot', '°C')], 'br',
         ['Strata wężownicy (dół bufora – przed zaworem)', '<span data-d="maty_loss">–</span> K'], ['Zasilanie – powrót mat', '<span data-d="maty_dt">–</span> K']], '');
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

/* ---------- odszranianie: [sdl_odszranianie device="heiko_defrost"] ---------- */
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
    <div class="sdl-head"><h3>Historia odszraniania</h3></div>
    <div class="sdl-table-wrap"><table class="sdl-table">
      <thead><tr><th scope="col">Kiedy</th><th scope="col">Czas</th><th scope="col">Parownik</th><th scope="col">Woda z pompy</th><th scope="col">Na zewnątrz</th></tr></thead>
      <tbody data-f="rows"><tr><td colspan="5">–</td></tr></tbody>
    </table></div>
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
        . "- [Zastosowany sprzęt](" . $u('/sprzet/') . "): urządzenia pracujące w domu.\n"
        . "- [Aquilo WiFi](" . $u('/aquilo/') . "): bezprzewodowy czujnik poziomu szamba.\n"
        . "- [Kontakt](" . $u('/kontakt/') . "): formularz kontaktowy (współpraca, testy sprzętu).\n"
        . "- [Polityka prywatności](" . $u('/polityka-prywatnosci/') . ")\n\n"
        . "## Dane na żywo – API JSON (publiczne, bez klucza, tylko odczyt)\n\n"
        . "- `GET {$api}latest?device=DEVICE` – ostatnie odczyty (`values`), czas odczytu (`ts`, Unix), zużycie dziś (`today_kwh`; dla heiko także `today_co`, `today_cwu`, `today_standby`), liczba startów wczoraj (`starts_yday`).\n"
        . "- `GET {$api}daily?device=DEVICE&metric=energy_total&days=30` – zużycie dobowe [kWh] jako `[[\"RRRR-MM-DD\", kWh], …]`. `metric=outdoor&agg=stats` zwraca `[dzień, średnia, min, maks]` temperatury.\n"
        . "- `GET {$api}series?device=heiko&range=24h|7d|30d&metrics=power,noel_top` – przebieg w czasie.\n"
        . "- `GET {$api}events?device=heiko_defrost` – zdarzenia odszraniania.\n\n"
        . "Urządzenia (DEVICE) i metryki:\n"
        . "- `dom` – cały dom: `power` [W], `l1` `l2` `l3` [W], `energy_total` [kWh, licznik narastający], `outdoor` [°C].\n"
        . "- `heiko` – pompa ciepła: `power` [W], `energy_total` `energy_co` `energy_cwu` `energy_standby` [kWh, narastająco], `noel_top` `noel_mid` `noel_bot` `supply` `outdoor` [°C], `starts`, `defrost_n`, `defrost_h` [h], `circ_starts` (dzisiejsze), `rh_out` (wilgotność na zewnątrz) [%], `tp` (temperatura parownika) [°C], `fan` (obroty wentylatora).\n"
        . "- `reqnet` – rekuperator: `flow_in` `flow_out` [m³/h], `czerpnia` `nawiew` `wyciag` `wyrzutnia` [°C], `power` [W], `energy_total` [kWh].\n"
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
