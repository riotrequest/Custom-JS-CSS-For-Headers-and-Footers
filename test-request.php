<?php
if ('cli' !== PHP_SAPI || empty($argv[2])) { http_response_code(404); exit(1); }
$input = json_decode(base64_decode($argv[2]), true);
if (!empty($input['kill'])) { define('CCJF_DISABLE_OUTPUT', true); }
if (!empty($input['policy'])) { define('DISALLOW_UNFILTERED_HTML', true); }
require $argv[1];
if (!defined('CCJF_TESTING') || !CCJF_TESTING) { exit(1); }
wp_set_current_user($input['user']);
$_SERVER['REQUEST_METHOD'] = $input['method'];
$_POST = wp_slash($input['post']); $_REQUEST = $_POST;
add_filter('wp_die_handler', function () { return function ($message) { throw new RuntimeException(strip_tags((string) $message)); }; });
try {
    if ('export' === $input['mode']) { ccjf_export(); }
    ob_start();
    if ('emit' === $input['mode']) { ccjf_emit('head'); ccjf_emit('foot'); }
    else { ccjf_page($input['tab']); }
    $html = ob_get_clean(); echo wp_json_encode(array('allowed' => true, 'html' => $html));
} catch (RuntimeException $error) {
    while (ob_get_level()) { ob_end_clean(); }
    echo wp_json_encode(array('allowed' => false, 'message' => $error->getMessage()));
}
