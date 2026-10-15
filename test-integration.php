<?php
/** Run only against a disposable WordPress site: php test-integration.php /path/to/wp-load.php */
if ('cli' !== PHP_SAPI || empty($argv[1])) { http_response_code(404); exit(1); }
require $argv[1];
if (!defined('CCJF_TESTING') || !CCJF_TESTING) { throw new RuntimeException('Enable CCJF_TESTING on a disposable WordPress installation.'); }
require_once __DIR__ . '/header-footer-includer.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
$admins = get_users(array('role' => 'administrator', 'number' => 1));
if (!$admins) { throw new RuntimeException('An administrator is required.'); }
$admin = $admins[0]->ID; wp_set_current_user($admin);
$old_syntax = get_user_meta($admin, 'syntax_highlighting', true);
$names = array(CCJF_OPTION);
foreach (ccjf_slots() as $key => $label) { $names[] = 'custom_css_js_plugin_' . $key; }
$options = array(); foreach ($names as $name) { $options[$name] = get_option($name, null); }
$users = array(); $checks = 0;
function cj_check($value, $message) { global $checks; if (!$value) { throw new RuntimeException($message); } $checks++; echo "PASS: $message\n"; }
function cj_save($operation, $data = array()) { return ccjf_save(array_merge(array('ccjf_operation' => $operation, 'revision' => ccjf_revision(ccjf_read())), $data)); }
function cj_emit($where) { ob_start(); ccjf_emit($where); return ob_get_clean(); }
function cj_request($post = array(), $extra = array()) {
    global $admin, $argv;
    $input = array_merge(array('user' => $admin, 'method' => 'POST', 'mode' => 'page', 'tab' => 'js', 'post' => $post), $extra);
    $proc = proc_open(array(PHP_BINARY, __DIR__ . '/test-request.php', realpath($argv[1]), base64_encode(wp_json_encode($input))), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($proc);
    wp_cache_delete(CCJF_OPTION, 'options'); wp_cache_delete('notoptions', 'options');
    $result = json_decode($out, true);
    if ($code || !is_array($result)) { throw new RuntimeException('Request failed: ' . $err . substr($out, 0, 200)); }
    return $result;
}
try {
    delete_option(CCJF_OPTION);
    $css = 'body::after { content: "<hello> \\263A"; color: #246; }';
    $js = '<script id="cj-fixture">const sample = "quote\\\\path"; /* inert test source */</script>';
    foreach (array('css_head' => $css, 'css_foot' => '', 'js_head' => $js, 'js_foot' => '<script type="application/ld+json">{"name":"Fixture"}</script>') as $key => $value) { update_option('custom_css_js_plugin_' . $key, $value, false); }
    $legacy = ccjf_read();
    cj_check($css === $legacy['code']['css_head'] && $js === $legacy['code']['js_head'], 'All legacy snippet strings are read without stripping tags or slashes.');
    cj_check(!$legacy['paused'] && 4 === count(array_filter($legacy['enabled'])), 'Legacy slot switches default to enabled without changing output state.');
    cj_check(false !== strpos(cj_emit('head'), $css) && false !== strpos(cj_emit('head'), $js), 'Header output preserves CSS literals and complete script tags.');
    cj_check(false === strpos(cj_emit('foot'), $js), 'Header code is not duplicated into the footer.');
    cj_check(false !== strpos(cj_emit('foot'), 'application/ld+json'), 'Footer markup preserves JSON-LD script tags.');
    $css_data = array('tab' => 'css', 'css_head' => $css . "\n/* new */", 'css_foot' => '.footer { opacity: .9; }', 'enabled_css_head' => '1');
    cj_check(!is_wp_error(cj_save('save', $css_data)), 'A valid two-slot CSS save creates the v2 state.');
    $state = ccjf_read();
    cj_check($state['code']['js_head'] === $js && !$state['enabled']['css_foot'], 'Saving CSS preserves JavaScript and saves an explicitly disabled footer slot.');
    cj_check($state['previous']['code'] === $legacy['code'], 'The first v2 code save retains the legacy code as its previous save.');
    cj_check($css === get_option('custom_css_js_plugin_css_head'), 'Original v1 options remain untouched as upgrade backups.');
    cj_check('' === cj_emit('foot') || false === strpos(cj_emit('foot'), '.footer'), 'A disabled CSS slot does not emit its saved code.');
    $base = ccjf_revision($state);
    cj_check(is_wp_error(ccjf_save(array('ccjf_operation' => 'save', 'revision' => $base, 'tab' => 'css', 'css_head' => 'partial'))), 'A partial form cannot erase the other code slot.');
    cj_check($base === ccjf_revision(ccjf_read()), 'A rejected partial request leaves the revision unchanged.');
    cj_check(is_wp_error(cj_save('save', array_merge($css_data, array('css_head' => array('bad'))))), 'Array-valued code fields are rejected.');
    cj_check(is_wp_error(cj_save('save', array_merge($css_data, array('css_head' => str_repeat('x', CCJF_MAX_BYTES + 1))))), 'Oversized code is rejected without truncation.');
    cj_check(is_wp_error(cj_save('save', array_merge($css_data, array('css_head' => "bad\0code")))), 'NUL bytes are rejected.');
    cj_check(is_wp_error(cj_save('save', array_merge($css_data, array('css_head' => '</STYLE><script>bad</script>')))), 'CSS cannot escape its style wrapper.');
    cj_check(is_wp_error(cj_save('save', array('tab' => 'unexpected'))), 'Unknown language groups fail closed.');
    cj_check(is_wp_error(cj_save('unknown')), 'Unknown operations fail closed.');
    $new_js = '<script type="module" id="cj-saved">const path = "C:\\\\example"; console.info("<kept>");</script>';
    $js_data = array('tab' => 'js', 'js_head' => $new_js, 'js_foot' => $js, 'enabled_js_head' => '1', 'enabled_js_foot' => '1');
    cj_check(!is_wp_error(cj_save('save', $js_data)), 'Authorized script markup is saved without KSES stripping.');
    cj_check($new_js === ccjf_read()['code']['js_head'], 'Module tags, quotes, backslashes, and angle brackets survive exactly.');
    cj_check(false !== strpos(cj_emit('head'), $new_js), 'The saved script is emitted without a duplicate wrapper.');
    cj_check(is_wp_error(ccjf_save(array('ccjf_operation' => 'pause', 'revision' => $base))), 'A stale form cannot overwrite a newer revision.');
    cj_check(false === ccjf_read()['paused'], 'A rejected stale pause does not change output state.');
    $snapshot = ccjf_read()['previous'];
    cj_check(!is_wp_error(cj_save('pause')) && '' === cj_emit('head') && '' === cj_emit('foot'), 'Master pause suppresses all four slots.');
    cj_check($snapshot === ccjf_read()['previous'] && $new_js === ccjf_read()['code']['js_head'], 'Pause preserves both code and the previous code save.');
    cj_check(!is_wp_error(cj_save('resume')) && false !== strpos(cj_emit('head'), $new_js), 'Resume re-enables only enabled, nonempty slots.');
    cj_check(!is_wp_error(cj_save('restore')) && ccjf_read()['paused'], 'Restoring the previous code save pauses output for review.');
    cj_check($js === ccjf_read()['code']['js_head'] && $new_js === ccjf_read()['previous']['code']['js_head'], 'Restore swaps current and previous code without discarding either.');
    cj_save('resume');
    cj_check('' === cj_request(array(), array('mode' => 'emit', 'kill' => true))['html'], 'The emergency configuration switch suppresses output.');
    cj_check('' === cj_request(array(), array('mode' => 'emit', 'policy' => true))['html'], 'DISALLOW_UNFILTERED_HTML also suppresses saved output.');
    cj_check(!cj_request(array(), array('mode' => 'page', 'policy' => true))['allowed'], 'WordPress unfiltered-HTML policy blocks editor access.');
    $prefix = 'cj-test-' . bin2hex(random_bytes(5));
    $subscriber = wp_insert_user(array('user_login' => $prefix, 'user_pass' => wp_generate_password(32), 'role' => 'subscriber')); $users[] = $subscriber;
    $limited = wp_insert_user(array('user_login' => $prefix . '-admin', 'user_pass' => wp_generate_password(32), 'role' => 'administrator')); $users[] = $limited;
    $limited_user = get_userdata($limited); $limited_user->add_cap('unfiltered_html', false);
    wp_set_current_user($limited); cj_check(!ccjf_allowed() && is_wp_error(cj_save('pause')), 'Manage-options permission alone cannot save executable markup.');
    wp_set_current_user($subscriber); cj_check(is_wp_error(cj_save('pause')) && is_wp_error(ccjf_export_data()), 'Subscribers cannot mutate or export code.');
    wp_set_current_user(0); cj_check(is_wp_error(cj_save('pause')), 'Anonymous mutations are rejected.');
    wp_set_current_user($admin);
    $post = array_merge($js_data, array('ccjf_operation' => 'save', 'revision' => ccjf_revision(ccjf_read()), '_wpnonce' => 'bad'));
    cj_check(!cj_request($post)['allowed'], 'Invalid form nonces stop the save.');
    $post['_wpnonce'] = wp_create_nonce('ccjf_save');
    cj_check(!cj_request($post, array('user' => $subscriber))['allowed'], 'The editor refuses a subscriber before rendering code.');
    cj_request($post, array('method' => 'GET'));
    cj_check($js === ccjf_read()['code']['js_head'], 'GET requests cannot save posted snippets.');
    $valid = cj_request($post);
    cj_check($valid['allowed'] && $new_js === ccjf_read()['code']['js_head'], 'An actual nonce-protected form preserves script source through WordPress slashing.');
    $danger = '</textarea><script id="cj-admin-breakout">bad()</script>';
    cj_save('save', array_merge($js_data, array('js_head' => $danger)));
    $page = cj_request(array(), array('method' => 'GET'))['html'];
    cj_check(false !== strpos($page, esc_textarea($danger)) && false === strpos($page, '<script id="cj-admin-breakout">'), 'Saved code cannot escape its admin textarea or execute in the console.');
    $invalid = array_merge($css_data, array('ccjf_operation' => 'save', 'revision' => ccjf_revision(ccjf_read()), '_wpnonce' => wp_create_nonce('ccjf_save'), 'css_head' => '<style>retained</style>'));
    $error_page = cj_request($invalid, array('tab' => 'css'))['html'];
    cj_check(false !== strpos($error_page, '&lt;style&gt;retained&lt;/style&gt;') && false !== strpos($error_page, 'data-dirty="yes"'), 'Invalid input remains visible as escaped, unsaved text for correction.');
    $backup = cj_request(array('_wpnonce' => wp_create_nonce('ccjf_export')), array('mode' => 'export'));
    cj_check($backup['code'] === ccjf_read()['code'] && !isset($backup['previous']), 'The authenticated JSON export contains all four saved slots, not browser edits or history.');
    cj_check(!cj_request(array('_wpnonce' => 'bad'), array('mode' => 'export'))['allowed'], 'Export also requires its own valid nonce.');
    cj_check(!cj_request(array('_wpnonce' => wp_create_nonce('ccjf_export')), array('mode' => 'export', 'method' => 'GET'))['allowed'], 'Export requires POST.');
    $saved = ccjf_read(); update_option(CCJF_OPTION, 'corrupt', false);
    cj_check('' === cj_emit('head') && is_wp_error(ccjf_save(array('ccjf_operation' => 'pause', 'revision' => 'invalid'))), 'Malformed stored state suppresses output and refuses overwriting the damaged record.');
    update_option(CCJF_OPTION, $saved, false);
    ccjf_assets('dashboard'); cj_check(!wp_script_is('ccjf-editor', 'enqueued'), 'Unrelated admin pages load no editor assets.');
    update_user_option($admin, 'syntax_highlighting', 'false', true);
    wp_set_current_user(0); wp_set_current_user($admin);
    ccjf_assets('toplevel_page_custom-css-js-plugin');
    cj_check(wp_script_is('ccjf-editor', 'enqueued') && wp_style_is('ccjf-editor', 'enqueued') && !wp_script_is('code-editor', 'enqueued'), 'The console respects the WordPress plain-text preference without loading CodeMirror.');
    echo "\n$checks checks passed on WordPress " . get_bloginfo('version') . ' / PHP ' . PHP_VERSION . ".\n";
} finally {
    foreach ($options as $name => $value) { if (null === $value) { delete_option($name); } else { update_option($name, $value, false); } }
    foreach ($users as $id) { wp_delete_user($id); }
    if ('' === $old_syntax) { delete_user_meta($admin, 'syntax_highlighting'); } else { update_user_meta($admin, 'syntax_highlighting', $old_syntax); }
    wp_set_current_user($admin);
}
