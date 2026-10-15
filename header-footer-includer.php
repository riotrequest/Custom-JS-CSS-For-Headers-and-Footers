<?php
/**
 * Plugin Name: Custom CSS & JS for Headers and Footers
 * Description: Four code slots, a native WordPress editor, output switches, and one previous save. No theme edits required.
 * Author: RiotRequest
 * Version: 2.0.0
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Plugin URI: https://github.com/riotrequest/Custom-JS-CSS-For-Headers-and-Footers
 * Text Domain: custom-css-js-plugin
 */
defined('ABSPATH') || exit;
define('CCJF_VERSION', '2.0.0');
define('CCJF_OPTION', 'custom_css_js_plugin_v2');
define('CCJF_MAX_BYTES', 262144);

function ccjf_slots() { return array('css_head' => 'Header CSS', 'css_foot' => 'Footer CSS', 'js_head' => 'Header JavaScript / HTML', 'js_foot' => 'Footer JavaScript / HTML'); }
function ccjf_allowed() { return current_user_can('manage_options') && current_user_can('unfiltered_html'); }
function ccjf_output_disabled() { return (defined('CCJF_DISABLE_OUTPUT') && CCJF_DISABLE_OUTPUT) || (defined('DISALLOW_UNFILTERED_HTML') && DISALLOW_UNFILTERED_HTML); }

/** Old option names remain untouched as upgrade backups; they are read only until the first v2 save. */
function ccjf_legacy() {
    $state = array('schema' => 2, 'code' => array(), 'enabled' => array(), 'paused' => false, 'updated' => 0, 'previous' => null);
    foreach (ccjf_slots() as $key => $label) {
        $value = get_option('custom_css_js_plugin_' . $key, '');
        if (!is_string($value)) { throw new RuntimeException('A legacy code option has an unexpected format. Review the database before saving.'); }
        $state['code'][$key] = $value;
        $state['enabled'][$key] = true;
    }
    return $state;
}

function ccjf_snapshot_valid($value) {
    if (!is_array($value) || !isset($value['code'], $value['enabled'], $value['updated']) || !is_array($value['code']) || !is_array($value['enabled']) || !is_int($value['updated'])) { return false; }
    foreach (ccjf_slots() as $key => $label) {
        if (!isset($value['code'][$key], $value['enabled'][$key]) || !is_string($value['code'][$key]) || !is_bool($value['enabled'][$key])) { return false; }
    }
    return true;
}

function ccjf_validate_state($state) {
    if (!ccjf_snapshot_valid($state) || !isset($state['schema'], $state['paused']) || 2 !== $state['schema'] || !is_bool($state['paused']) || !array_key_exists('previous', $state) || (null !== $state['previous'] && !ccjf_snapshot_valid($state['previous']))) { throw new RuntimeException('Saved settings have an unexpected format. Output is suppressed; recover from a database backup.'); }
    return $state;
}

function ccjf_read() {
    $state = get_option(CCJF_OPTION, null);
    return null === $state ? ccjf_legacy() : ccjf_validate_state($state);
}
function ccjf_revision($state) { return hash('sha256', serialize($state)); }
function ccjf_snapshot($state) { return array('code' => $state['code'], 'enabled' => $state['enabled'], 'updated' => $state['updated']); }
function ccjf_css_valid($code) { return !preg_match('~</?style\b~i', $code); }

/** One option holds the whole revision, so partial requests cannot clear another code slot. */
function ccjf_save($input) {
    if (!ccjf_allowed()) { return new WP_Error('ccjf_denied', 'Both manage_options and unfiltered_html permissions are required.'); }
    global $wpdb;
    $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s", CCJF_OPTION));
    try { $current = null === $raw ? ccjf_legacy() : ccjf_validate_state(maybe_unserialize($raw)); }
    catch (Throwable $error) { return new WP_Error('ccjf_format', $error->getMessage()); }
    if (!isset($input['revision']) || !is_string($input['revision']) || !hash_equals(ccjf_revision($current), $input['revision'])) { return new WP_Error('ccjf_stale', 'Another tab saved a newer version. Copy your unsaved code, then load the saved version before editing again.'); }
    $operation = isset($input['ccjf_operation']) && is_string($input['ccjf_operation']) ? $input['ccjf_operation'] : '';
    $next = $current;
    if ('save' === $operation) {
        $tab = isset($input['tab']) && is_string($input['tab']) ? $input['tab'] : '';
        if (!in_array($tab, array('css', 'js'), true)) { return new WP_Error('ccjf_invalid', 'Choose CSS or JavaScript / HTML.'); }
        foreach (array($tab . '_head', $tab . '_foot') as $key) {
            if (!isset($input[$key]) || !is_string($input[$key]) || strlen($input[$key]) > CCJF_MAX_BYTES || false !== strpos($input[$key], "\0")) { return new WP_Error('ccjf_invalid', 'Both code fields must be present as text, without NUL bytes, and at most 256 KiB each. Nothing was saved.'); }
            if ('css' === $tab && !ccjf_css_valid($input[$key])) { return new WP_Error('ccjf_invalid', 'CSS fields must not contain opening or closing style tags. Use CSS only; nothing was saved.'); }
            $next['code'][$key] = $input[$key];
            $next['enabled'][$key] = isset($input['enabled_' . $key]) && '1' === $input['enabled_' . $key];
        }
        if ($next['code'] === $current['code'] && $next['enabled'] === $current['enabled']) { return 'No changes to save.'; }
        $next['previous'] = ccjf_snapshot($current);
    } elseif ('pause' === $operation || 'resume' === $operation) {
        $next['paused'] = 'pause' === $operation;
        if ($next['paused'] === $current['paused']) { return 'Output state is unchanged.'; }
    } elseif ('restore' === $operation) {
        if (null === $current['previous']) { return new WP_Error('ccjf_invalid', 'There is no previous code save to restore.'); }
        $next['code'] = $current['previous']['code'];
        $next['enabled'] = $current['previous']['enabled'];
        $next['previous'] = ccjf_snapshot($current);
        $next['paused'] = true;
    } else { return new WP_Error('ccjf_invalid', 'Unknown operation.'); }
    $next['updated'] = time();
    // Compare-and-swap also rejects simultaneous saves; a stale form never overwrites newer code.
    if (null === $raw) { $saved = add_option(CCJF_OPTION, $next, '', false); }
    else {
        $saved = 1 === $wpdb->query($wpdb->prepare("UPDATE $wpdb->options SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s", maybe_serialize($next), CCJF_OPTION, $raw));
        wp_cache_delete(CCJF_OPTION, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
    }
    if (!$saved) { return new WP_Error('ccjf_stale', 'Settings changed during this request, or storage failed. Copy your code and reload. Nothing else was overwritten.'); }
    if ('restore' === $operation) { return 'Previous code and slot switches restored. All output is paused; review both tabs before resuming.'; }
    if ('pause' === $operation) { return 'All plugin output paused. Saved code is retained.'; }
    return 'resume' === $operation ? 'Output resumed for enabled, nonempty slots.' : 'Code and slot switches saved.';
}

function ccjf_emit($location) {
    if (is_admin() || is_feed() || (defined('REST_REQUEST') && REST_REQUEST) || ccjf_output_disabled()) { return; }
    try { $state = ccjf_read(); } catch (Throwable $error) { return; }
    if ($state['paused']) { return; }
    foreach (array('css', 'js') as $type) {
        $key = $type . '_' . $location;
        if (!isset($state['code'][$key]) || !$state['enabled'][$key] || '' === trim($state['code'][$key])) { continue; }
        $code = $state['code'][$key];
        // Trusted executable markup is intentional here. Authorization is enforced at the write boundary.
        if ('css' === $type) {
            if (ccjf_css_valid($code)) { echo "\n<style id=\"ccjf-" . esc_attr($key) . "\">\n" . $code . "\n</style>\n"; }
        } else { echo "\n" . $code . "\n"; }
    }
}
function custom_css_js_plugin_output_head() { ccjf_emit('head'); }
function custom_css_js_plugin_output_foot() { ccjf_emit('foot'); }
add_action('wp_head', 'custom_css_js_plugin_output_head', 99);
add_action('wp_footer', 'custom_css_js_plugin_output_foot', 99);

function custom_css_js_plugin_menu() {
    add_menu_page('Custom CSS & JS', 'Custom CSS & JS', 'manage_options', 'custom-css-js-plugin', 'custom_css_plugin_page', 'dashicons-editor-code', 81);
    add_submenu_page('custom-css-js-plugin', 'CSS', 'CSS', 'manage_options', 'custom-css-js-plugin', 'custom_css_plugin_page');
    add_submenu_page('custom-css-js-plugin', 'JavaScript / HTML', 'JavaScript / HTML', 'manage_options', 'custom-js-plugin', 'custom_js_plugin_page');
}
add_action('admin_menu', 'custom_css_js_plugin_menu');

function ccjf_assets($hook) {
    if (!in_array($hook, array('toplevel_page_custom-css-js-plugin', 'custom-css-js_page_custom-js-plugin'), true) || !ccjf_allowed()) { return; }
    wp_enqueue_style('ccjf-editor', plugins_url('editor.css', __FILE__), array(), CCJF_VERSION);
    $settings = array();
    foreach (array('css' => 'text/css', 'js' => 'text/html') as $type => $mime) {
        $settings[$type] = wp_enqueue_code_editor(array('type' => $mime, 'codemirror' => array('lint' => false, 'lineWrapping' => true)));
    }
    wp_enqueue_script('ccjf-editor', plugins_url('editor.js', __FILE__), $settings['css'] || $settings['js'] ? array('code-editor') : array(), CCJF_VERSION, array('in_footer' => true));
    wp_add_inline_script('ccjf-editor', 'window.ccjfEditors = ' . wp_json_encode($settings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';', 'before');
}
add_action('admin_enqueue_scripts', 'ccjf_assets');

function ccjf_export_data() {
    if (!ccjf_allowed()) { return new WP_Error('ccjf_denied', 'Permission required.'); }
    try { $state = ccjf_read(); } catch (Throwable $error) { return new WP_Error('ccjf_format', $error->getMessage()); }
    return array('plugin' => 'custom-css-js-plugin', 'version' => CCJF_VERSION, 'exported_utc' => gmdate('c'), 'code' => $state['code'], 'enabled' => $state['enabled'], 'paused' => $state['paused']);
}
function ccjf_export() {
    if (!ccjf_allowed() || 'POST' !== $_SERVER['REQUEST_METHOD']) { wp_die('Permission and POST required.', '', array('response' => 403)); }
    check_admin_referer('ccjf_export');
    $data = ccjf_export_data();
    if (is_wp_error($data)) { wp_die(esc_html($data->get_error_message())); }
    nocache_headers(); header('Content-Type: application/json; charset=utf-8'); header('X-Content-Type-Options: nosniff'); header('Content-Disposition: attachment; filename="custom-css-js-backup.json"');
    echo wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); exit;
}
add_action('admin_post_ccjf_export', 'ccjf_export');

function custom_css_plugin_page() { ccjf_page('css'); }
function custom_js_plugin_page() { ccjf_page('js'); }
function ccjf_fields($operation, $revision) {
    wp_nonce_field('ccjf_save');
    echo '<input type="hidden" name="ccjf_operation" value="' . esc_attr($operation) . '"><input type="hidden" name="revision" value="' . esc_attr($revision) . '">';
}
function ccjf_page($tab) {
    if (!ccjf_allowed()) { wp_die('Both manage_options and unfiltered_html permissions are required. WordPress policy may disable unfiltered HTML.', '', array('response' => 403)); }
    $result = null; $posted = null;
    if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['ccjf_operation'])) {
        check_admin_referer('ccjf_save');
        $posted = wp_unslash($_POST);
        $result = ccjf_save($posted);
    }
    try { $state = ccjf_read(); } catch (Throwable $error) { echo '<div class="wrap"><h1>Custom CSS &amp; JS</h1><p>' . esc_html($error->getMessage()) . '</p></div>'; return; }
    $revision = ccjf_revision($state);
    $display = $state;
    $dirty = is_wp_error($result) && isset($posted['ccjf_operation']) && 'save' === $posted['ccjf_operation'];
    if ($dirty) {
        foreach (array($tab . '_head', $tab . '_foot') as $key) {
            if (isset($posted[$key]) && is_string($posted[$key])) { $display['code'][$key] = $posted[$key]; }
            $display['enabled'][$key] = isset($posted['enabled_' . $key]) && '1' === $posted['enabled_' . $key];
        }
        if (isset($posted['revision']) && is_string($posted['revision'])) { $revision = $posted['revision']; }
    }
    $count = 0;
    foreach ($state['code'] as $key => $code) { if ($state['enabled'][$key] && '' !== trim($code) && ('css' !== substr($key, 0, 3) || ccjf_css_valid($code))) { $count++; } }
    $blocked = ccjf_output_disabled(); $live = !$state['paused'] && !$blocked;
    ?>
    <div class="wrap cj-console" id="ccjf-app" data-dirty="<?php echo $dirty ? 'yes' : 'no'; ?>">
        <header class="cj-hero"><div class="cj-eyebrow"><span class="cj-dot"></span> RIOTREQUEST / SITE UTILITIES <span class="cj-version">v<?php echo esc_html(CCJF_VERSION); ?></span></div><h1>A little code.<span>Right where you need it.</span></h1><p>Custom CSS and script markup in your site's header and footer. Four slots. No theme files to edit.</p><div class="cj-hero-bottom"><span class="cj-output <?php echo $live ? 'is-live' : ''; ?>"><?php echo $blocked ? 'OUTPUT DISABLED BY CONFIGURATION' : ($state['paused'] ? 'ALL OUTPUT PAUSED' : 'OUTPUT ENABLED'); ?></span><span><?php echo (int) $count; ?> enabled, nonempty <?php echo 1 === $count ? 'slot' : 'slots'; ?> · front end only</span></div></header>
        <?php if (null !== $result) : ?><div class="cj-feedback <?php echo is_wp_error($result) ? 'is-error' : ''; ?>" role="status"><?php echo esc_html(is_wp_error($result) ? $result->get_error_message() : $result); ?></div><?php endif; ?>
        <div class="cj-workspace"><section class="cj-editors" aria-label="Code editors"><nav class="cj-tabs" aria-label="Code language"><a href="<?php echo esc_url(admin_url('admin.php?page=custom-css-js-plugin')); ?>" <?php if ('css' === $tab) { echo 'aria-current="page"'; } ?>>CSS <span>02</span></a><a href="<?php echo esc_url(admin_url('admin.php?page=custom-js-plugin')); ?>" <?php if ('js' === $tab) { echo 'aria-current="page"'; } ?>>JavaScript / HTML <span>02</span></a></nav>
        <div class="cj-editor-intro"><p><?php echo 'css' === $tab ? 'Write CSS only. Style tags are added for you.' : 'Include complete script tags or HTML markup, just as your provider supplies it.'; ?></p><button type="button" id="ccjf-plain" class="button button-link" hidden>Use plain text</button></div>
        <form method="post" id="ccjf-code-form"><?php ccjf_fields('save', $revision); ?><input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>">
        <?php foreach (array('head' => array('Header', 'wp_head()', 'Before the closing head tag.'), 'foot' => array('Footer', 'wp_footer()', 'Near the end of the body, after theme scripts.')) as $location => $info) : $key = $tab . '_' . $location; ?>
            <section class="cj-editor-panel"><div class="cj-panel-heading"><div><span class="cj-kicker"><?php echo esc_html($info[1]); ?></span><h2><label for="ccjf-<?php echo esc_attr($key); ?>"><?php echo esc_html($info[0] . ('css' === $tab ? ' CSS' : ' JavaScript / HTML')); ?></label></h2></div><label class="cj-switch"><input type="checkbox" name="enabled_<?php echo esc_attr($key); ?>" value="1" <?php checked($display['enabled'][$key]); ?>>Enable<span class="screen-reader-text"> <?php echo esc_html(ccjf_slots()[$key]); ?></span></label></div><p class="cj-location"><?php echo esc_html($info[2]); ?></p><textarea id="ccjf-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" class="cj-code" data-mode="<?php echo esc_attr($tab); ?>" rows="12" spellcheck="false" aria-describedby="ccjf-help-<?php echo esc_attr($key); ?>"><?php echo esc_textarea($display['code'][$key]); ?></textarea><div class="cj-editor-footer"><span id="ccjf-help-<?php echo esc_attr($key); ?>"><?php echo 'css' === $tab ? 'CSS only · no style tags' : 'Script tags required for JavaScript · PHP is not executed'; ?></span><span class="cj-bytes" data-for="ccjf-<?php echo esc_attr($key); ?>"><?php echo strlen($display['code'][$key]); ?> bytes</span></div></section>
        <?php endforeach; ?>
        <div class="cj-savebar"><span id="ccjf-save-state" role="status" aria-live="polite"><?php echo $dirty ? 'Unsaved code retained.' : 'Showing saved code.'; ?></span><button class="button button-primary" type="submit">Save <?php echo 'css' === $tab ? 'CSS' : 'JavaScript / HTML'; ?> <span aria-hidden="true">↗</span></button></div></form></section>
        <aside class="cj-sidebar"><section class="cj-panel"><span class="cj-kicker">01 / OUTPUT CONTROL</span><h2>Keep the switch close.</h2><p>Pausing stops all four slots without deleting their saved code. A disabled slot stays saved too.</p><form method="post"><?php ccjf_fields($state['paused'] ? 'resume' : 'pause', ccjf_revision($state)); ?><button type="submit" class="button cj-pause" <?php disabled($blocked); ?>><?php echo $state['paused'] ? 'Resume enabled slots' : 'Pause all output'; ?></button></form><small>Saved changes affect public pages. Clear page, CDN, and browser caches when verifying.</small></section>
        <section class="cj-panel"><span class="cj-kicker">02 / RECOVERY</span><h2>Make room for a way back.</h2><p>Keep a local copy before changing live code. The export contains all four saved slots and their switches.</p><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="ccjf_export"><?php wp_nonce_field('ccjf_export'); ?><button type="submit" class="button">Download saved code</button></form><div class="cj-restore"><p>One previous code save is kept. Restoring swaps the code and slot switches, then pauses all output for review.</p><form method="post"><?php ccjf_fields('restore', ccjf_revision($state)); ?><button type="submit" class="button" <?php disabled(null === $state['previous']); ?>>Restore previous save (paused)</button></form></div><small><?php echo $state['updated'] ? 'Last change: ' . esc_html(wp_date('M j, Y H:i T', $state['updated'])) : 'Existing v1 fields are preserved until your first v2 save.'; ?></small></section>
        <section class="cj-note"><strong>Powerful code, ordinary consequences.</strong><p>Scripts can track visitors, load third-party resources, or break the page. Only use code you trust. This editor does not sandbox or execute a preview.</p><p>Emergency stop in <code>wp-config.php</code>:</p><code>define('CCJF_DISABLE_OUTPUT', true);</code><p>Feeds, REST responses, and admin pages do not receive these snippets.</p></section></aside></div><footer class="cj-footer">CUSTOM CSS &amp; JS / NO THEME FILE EDITS. <a href="https://github.com/riotrequest/Custom-JS-CSS-For-Headers-and-Footers#readme" target="_blank" rel="noopener noreferrer">Documentation ↗</a></footer>
    </div>
    <?php
}
