<?php
/*
Plugin Name: Banka TMS App
Description: Recreates the Banka TMS workflow as a WordPress plugin with app-style pages and blank-template rendering.
Version: 1.0.0
Author: Copilot
*/

if (!defined('ABSPATH')) {
    exit;
}

define('BANKA_TMS_APP_VERSION', '1.0.0');
define('BANKA_TMS_APP_DIR', plugin_dir_path(__FILE__));
define('BANKA_TMS_APP_URL', plugin_dir_url(__FILE__));

require_once BANKA_TMS_APP_DIR . 'includes/activation.php';
require_once BANKA_TMS_APP_DIR . 'includes/pages.php';
require_once BANKA_TMS_APP_DIR . 'includes/database.php';
require_once BANKA_TMS_APP_DIR . 'includes/auth.php';
require_once BANKA_TMS_APP_DIR . 'includes/tasks.php';
require_once BANKA_TMS_APP_DIR . 'includes/users.php';
require_once BANKA_TMS_APP_DIR . 'includes/settings.php';
require_once BANKA_TMS_APP_DIR . 'includes/api.php';
require_once BANKA_TMS_APP_DIR . 'includes/crud.php';
require_once BANKA_TMS_APP_DIR . 'includes/modules.php';
require_once BANKA_TMS_APP_DIR . 'includes/settings-panel.php';
require_once BANKA_TMS_APP_DIR . 'includes/vendor-module.php';
require_once BANKA_TMS_APP_DIR . 'includes/role-access.php';

register_activation_hook(__FILE__, 'banka_tms_app_activate');
register_deactivation_hook(__FILE__, 'banka_tms_app_deactivate');

add_action('init', 'banka_tms_app_init');
add_action('wp_enqueue_scripts', 'banka_tms_app_enqueue_assets');
add_filter('template_include', 'banka_tms_app_template_include');
add_filter('page_template', 'banka_tms_app_page_template');
add_action('admin_menu', 'banka_tms_app_admin_menu');
add_shortcode('banka_tms_app_page', 'banka_tms_app_shortcode');

function banka_tms_app_init() {
    banka_tms_app_create_tables();
    banka_tms_app_create_required_pages();
}

function banka_tms_app_enqueue_assets() {
    if (!is_page(banka_tms_app_get_page_ids())) {
        return;
    }

    wp_enqueue_style('banka-tms-app-style', BANKA_TMS_APP_URL . 'assets/css/app.css', array(), BANKA_TMS_APP_VERSION);
    wp_enqueue_script('banka-tms-app-script', BANKA_TMS_APP_URL . 'assets/js/app.js', array('jquery'), BANKA_TMS_APP_VERSION, true);
    wp_localize_script('banka-tms-app-script', 'bankaTmsApp', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('banka_tms_app_nonce'),
    ));
}

function banka_tms_app_template_include($template) {
    $page_ids = banka_tms_app_get_page_ids();
    if (is_page($page_ids)) {
        return BANKA_TMS_APP_DIR . 'templates/app-page.php';
    }

    return $template;
}

function banka_tms_app_page_template($template) {
    $page_ids = banka_tms_app_get_page_ids();
    if (is_page($page_ids)) {
        return BANKA_TMS_APP_DIR . 'templates/app-page.php';
    }

    return $template;
}

function banka_tms_app_admin_menu() {
    add_menu_page('Banka TMS', 'Banka TMS', 'manage_options', 'banka-tms-app', 'banka_tms_app_admin_dashboard', 'dashicons-list-view', 20);
    add_submenu_page('banka-tms-app', 'Dashboard', 'Dashboard', 'manage_options', 'banka-tms-app', 'banka_tms_app_admin_dashboard');
    add_submenu_page('banka-tms-app', 'Users', 'Users', 'manage_options', 'banka-tms-users', 'banka_tms_app_admin_users');
    add_submenu_page('banka-tms-app', 'Tasks', 'Tasks', 'manage_options', 'banka-tms-tasks', 'banka_tms_app_admin_tasks');
    add_submenu_page('banka-tms-app', 'Settings', 'Settings', 'manage_options', 'banka-tms-settings', 'banka_tms_app_admin_settings');
}

function banka_tms_app_admin_dashboard() {
    if (!current_user_can('manage_options')) {
        wp_die('Access denied');
    }

    echo '<div class="wrap"><h1>Banka TMS Admin</h1><p>This plugin creates app-style pages and provides user/task management.</p></div>';
}

function banka_tms_app_shortcode() {
    return '';
}

function banka_tms_app_get_page_ids() {
    $pages = array(
        'dashboard' => get_option('banka_tms_app_dashboard_page_id'),
        'tasks' => get_option('banka_tms_app_tasks_page_id'),
        'users' => get_option('banka_tms_app_users_page_id'),
        'vendors' => get_option('banka_tms_app_vendors_page_id'),
        'settings' => get_option('banka_tms_app_settings_page_id'),
    );

    return array_filter($pages);
}
