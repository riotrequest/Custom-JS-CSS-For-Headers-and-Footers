<?php
/*
Plugin Name: Custom CSS & JS Plugin
Description: A simple plugin to add custom CSS and JS through the WordPress admin dashboard.
Author: RiotRequest
Version: 1.0
*/

function custom_css_js_plugin_menu() {
    add_menu_page(
        'Custom CSS & JS Plugin',
        'Custom CSS & JS',
        'manage_options',
        'custom-css-js-plugin',
        'custom_css_plugin_page',
        '',
        100
    );
    add_submenu_page(
        'custom-css-js-plugin',
        'Custom CSS',
        'Custom CSS',
        'manage_options',
        'custom-css-js-plugin',
        'custom_css_plugin_page'
    );
    add_submenu_page(
        'custom-css-js-plugin',
        'Custom JS',
        'Custom JS',
        'manage_options',
        'custom-js-plugin',
        'custom_js_plugin_page'
    );
}
add_action('admin_menu', 'custom_css_js_plugin_menu');

function custom_css_plugin_page() {
    // Handle post data
    if (isset($_POST['custom_css_head']) || isset($_POST['custom_css_foot'])) {
        update_option('custom_css_js_plugin_css_head', stripslashes_deep($_POST['custom_css_head']));
        update_option('custom_css_js_plugin_css_foot', stripslashes_deep($_POST['custom_css_foot']));
    }

    // Get the current CSS
    $current_css_head = get_option('custom_css_js_plugin_css_head', '');
    $current_css_foot = get_option('custom_css_js_plugin_css_foot', '');

    // Display the form
    echo '<form method="POST">';
    echo '<h2>Header CSS</h2>';
    echo '<textarea name="custom_css_head" style="width: 97%; height: 500px;">' . $current_css_head . '</textarea>';
    echo '<h2>Footer CSS</h2>';
    echo '<textarea name="custom_css_foot" style="width: 97%; height: 500px;">' . $current_css_foot . '</textarea>';
    echo '<input type="submit" value="Save CSS">';
    echo '</form>';
}

function custom_js_plugin_page() {
    // Handle post data
    if (isset($_POST['custom_js_head']) || isset($_POST['custom_js_foot'])) {
        update_option('custom_css_js_plugin_js_head', stripslashes_deep($_POST['custom_js_head']));
        update_option('custom_css_js_plugin_js_foot', stripslashes_deep($_POST['custom_js_foot']));
    }

    // Get the current JS
    $current_js_head = get_option('custom_css_js_plugin_js_head', '');
    $current_js_foot = get_option('custom_css_js_plugin_js_foot', '');

    // Display the form
    echo '<form method="POST">';
    echo '<h2>Header JS</h2>';
    echo '<textarea name="custom_js_head" style="width: 97%; height: 500px;">' . $current_js_head . '</textarea>';
    echo '<h2>Footer JS</h2>';
    echo '<textarea name="custom_js_foot" style="width: 97%; height: 500px;">' . $current_js_foot . '</textarea>';
    echo '<input type="submit" value="Save JS">';
    echo '</form>';
}

function custom_css_js_plugin_output_head() {
    $custom_css_head = get_option('custom_css_js_plugin_css_head', '');
    $custom_js_head = get_option('custom_css_js_plugin_js_head', '');
    if (!empty($custom_css_head)) {
        echo '<style type="text/css">' . $custom_css_head . '</style>';
    }
    if (!empty($custom_js_head)) {
        echo $custom_js_head;
    }
}
add_action('wp_head', 'custom_css_js_plugin_output_head');

function custom_css_js_plugin_output_foot() {
    $custom_css_foot = get_option('custom_css_js_plugin_css_foot', '');
    $custom_js_foot = get_option('custom_css_js_plugin_js_foot', '');
    if (!empty($custom_css_foot)) {
        echo '<style type="text/css">' . $custom_css_foot . '</style>';
    }
    if (!empty($custom_js_foot)) {
        echo $custom_js_foot;
    }
}
add_action('wp_footer', 'custom_css_js_plugin_output_foot');
?>
