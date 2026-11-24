<?php
$cbs=[];function add_action($h,$f,$p=10){global $cbs;$cbs[$h][]=$f;} function add_filter(){} function add_shortcode(){} function register_activation_hook(){} function register_deactivation_hook(){} function wp_next_scheduled(){return 1;} function wp_schedule_event(){} function is_admin(){return false;} function __($s){return $s;} function plugin_dir_url(){return '/';} function plugin_dir_path(){return '/';} function register_rest_route(){} function plugin_basename(){return 'x';}
define('ABSPATH','/');
include __DIR__ . '/../stalowydom-live/stalowydom-live.php';
foreach($cbs['wp_head'] as $f){ob_start();$f();$o=ob_get_clean();echo strlen($o)," bajtów, footer-has: ",substr_count($o,"polityka-prywatnosci")," mobile: ",substr_count($o,'et_mobile_menu'),"\n";}
