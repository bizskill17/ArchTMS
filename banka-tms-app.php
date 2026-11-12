<?php
/*
Plugin Name: Banka TMS App
Description: Recreates the Banka TMS workflow as a WordPress plugin with app-style pages and blank-template rendering.
Version: 3.0.69
Author: Copilot
*/

if (!defined('ABSPATH')) {
    exit;
}

define('BANKA_TMS_APP_VERSION', '3.0.69');
define('BANKA_TMS_APP_DIR', plugin_dir_path(__FILE__));
define('BANKA_TMS_APP_URL', plugin_dir_url(__FILE__));

/**
 * TaskPro uses India Standard Time consistently, independent of the
 * WordPress site's general timezone setting.
 */
function banka_tms_ist_timezone() {
    static $timezone = null;
    if ($timezone === null) {
        $timezone = new DateTimeZone('Asia/Kolkata');
    }
    return $timezone;
}

function banka_tms_ist_now() {
    return new DateTimeImmutable('now', banka_tms_ist_timezone());
}

function banka_tms_ist_timestamp() {
    return banka_tms_ist_now()->getTimestamp();
}

function banka_tms_ist_date($format, $timestamp = null) {
    if ($timestamp === null) {
        return banka_tms_ist_now()->format($format);
    }
    return (new DateTimeImmutable('@' . (int) $timestamp))
        ->setTimezone(banka_tms_ist_timezone())
        ->format($format);
}

function banka_tms_ist_mysql() {
    return banka_tms_ist_now()->format('Y-m-d H:i:s');
}


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
require_once BANKA_TMS_APP_DIR . 'includes/pdf-reports.php';
require_once BANKA_TMS_APP_DIR . 'includes/notifications.php';
require_once BANKA_TMS_APP_DIR . 'includes/bulk-import.php';

// Allow TaskPro application users (who are not WordPress users) to use the existing AJAX endpoints.
$banka_tms_nopriv_actions = array(
    'banka_tms_app_update_task','banka_tms_app_get_task_activity','banka_tms_app_quick_update_task','banka_tms_app_delete_task','banka_tms_app_bulk_tasks',
    'banka_tms_app_create_user','banka_tms_app_update_user','banka_tms_app_delete_user','banka_tms_app_toggle_user_status',
    'banka_tms_app_save_designation','banka_tms_app_update_designation','banka_tms_app_delete_designation',
    'banka_tms_app_save_category','banka_tms_app_update_category','banka_tms_app_delete_category','banka_tms_app_list_categories',
    'banka_tms_app_save_task_master','banka_tms_app_update_task_master','banka_tms_app_delete_task_master',
    'banka_tms_app_save_project','banka_tms_app_update_project','banka_tms_app_delete_project','banka_tms_app_get_project_logs',
    'banka_tms_app_save_project_master_item','banka_tms_app_delete_project_master_item',
    'banka_tms_app_save_task','banka_tms_app_list_tasks','banka_tms_app_list_users',
    'banka_tms_app_save_vendor','banka_tms_app_list_vendors','banka_tms_bulk_upload'
);
foreach ($banka_tms_nopriv_actions as $banka_tms_nopriv_action) {
    add_action('wp_ajax_nopriv_' . $banka_tms_nopriv_action, $banka_tms_nopriv_action);
}
add_action('admin_post_nopriv_banka_tms_task_pdf', 'banka_tms_download_task_pdf');
add_action('admin_post_nopriv_banka_tms_action_log_pdf', 'banka_tms_download_action_log_pdf');


register_activation_hook(__FILE__, 'banka_tms_app_activate');
register_deactivation_hook(__FILE__, 'banka_tms_app_deactivate');

add_action('init', 'banka_tms_app_init');

/**
 * Never cache TaskPro application or login responses. Full-page caching can
 * otherwise serve a previously cached Sign In screen even when the browser
 * already has a valid TaskPro cookie.
 */
function banka_tms_app_disable_frontend_cache() {
    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if (strpos($request_uri, '/tms-') === false && !banka_tms_app_is_app_page()) {
        return;
    }

    if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
    if (!defined('DONOTCACHEOBJECT')) { define('DONOTCACHEOBJECT', true); }
    if (!defined('DONOTCACHEDB')) { define('DONOTCACHEDB', true); }

    nocache_headers();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0', true);
    header('Pragma: no-cache', true);
    header('Expires: Wed, 11 Jan 1984 05:00:00 GMT', true);
    header('Vary: Cookie', false);
    header('X-LiteSpeed-Cache-Control: no-cache', true);
}
add_action('template_redirect', 'banka_tms_app_disable_frontend_cache', 0);

add_action('wp_enqueue_scripts', 'banka_tms_app_enqueue_assets');
add_filter('template_include', 'banka_tms_app_template_include');
add_filter('page_template', 'banka_tms_app_page_template');
add_action('admin_menu', 'banka_tms_app_admin_menu');
add_shortcode('banka_tms_app_page', 'banka_tms_app_shortcode');

// Show the WordPress admin bar only inside wp-admin, never on TaskPro/front-end pages.
add_filter('show_admin_bar', function ($show) {
    return is_admin() ? $show : false;
});

function banka_tms_app_init() {
    banka_tms_app_create_tables();
    banka_tms_cleanup_legacy_seed_projects();
    banka_tms_migrate_project_meta_to_columns();
    banka_tms_app_create_dummy_user();
    banka_tms_app_migrate_users();
    banka_tms_app_create_required_pages();
    banka_tms_create_notification_table();
}

function banka_tms_app_is_app_page() {
    $page_ids = banka_tms_app_get_page_ids();
    $slugs = array('tms-dashboard','tms-tasks','tms-users','tms-vendors','tms-sites','tms-categories','tms-designations','tms-task-master','tms-project-types','tms-project-categories','tms-recurring','tms-action-log','tms-settings','tms-notification-log');
    return is_page($page_ids) || is_page($slugs);
}

function banka_tms_app_enqueue_assets() {
    if (!banka_tms_app_is_app_page()) {
        return;
    }


    wp_enqueue_style(
        'banka-tms-montserrat',
        'https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap',
        array(),
        null
    );

    $css_file = BANKA_TMS_APP_DIR . 'assets/css/app.css';
    $css_version = file_exists($css_file) ? filemtime($css_file) : BANKA_TMS_APP_VERSION;
    wp_enqueue_style('banka-tms-app-style', BANKA_TMS_APP_URL . 'assets/css/app.css', array('banka-tms-montserrat'), $css_version);
    $js_file = BANKA_TMS_APP_DIR . 'assets/js/app.js';
    $js_version = file_exists($js_file) ? filemtime($js_file) : BANKA_TMS_APP_VERSION;
    wp_enqueue_script('banka-tms-app-script', BANKA_TMS_APP_URL . 'assets/js/app.js', array('jquery'), $js_version, true);
    global $wpdb;
    $category_names = $wpdb->get_col("SELECT name FROM {$wpdb->prefix}banka_tms_categories ORDER BY name ASC");
    $task_master_names = $wpdb->get_col("SELECT name FROM {$wpdb->prefix}banka_tms_task_master ORDER BY name ASC");
    $project_rows_for_dropdown = $wpdb->get_results("SELECT project_name, address FROM {$wpdb->prefix}banka_tms_projects ORDER BY project_name ASC");
    $project_names = array();
    foreach ($project_rows_for_dropdown as $project_row_for_dropdown) {
        $project_label = trim((string) $project_row_for_dropdown->project_name);
        $project_address = trim((string) $project_row_for_dropdown->address);
        if ($project_address !== '') {
            $project_label .= ' (' . $project_address . ')';
        }
        if ($project_label !== '') {
            $project_names[] = $project_label;
        }
    }
    $project_type_names = $wpdb->get_col("SELECT name FROM {$wpdb->prefix}banka_tms_project_types ORDER BY name ASC");
    $project_category_names = $wpdb->get_col("SELECT name FROM {$wpdb->prefix}banka_tms_project_categories ORDER BY name ASC");
    wp_localize_script('banka-tms-app-script', 'bankaTmsApp', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'taskPdfUrl' => admin_url('admin-post.php'),
        'taskPdfNonce' => wp_create_nonce('banka_tms_task_pdf'),
        'actionLogPdfNonce' => wp_create_nonce('banka_tms_action_log_pdf'),
        'nonce' => wp_create_nonce('banka_tms_app_nonce'),
        'categories' => array_values(array_map('strval', $category_names)),
        'taskMasters' => array_values(array_map('strval', $task_master_names)),
        'projects' => array_values(array_map('strval', $project_names)),
        'projectTypes' => array_values(array_map('strval', $project_type_names)),
        'projectCategories' => array_values(array_map('strval', $project_category_names)),
    ));
}

function banka_tms_app_template_include($template) {
    if (banka_tms_app_is_app_page()) {
        return BANKA_TMS_APP_DIR . 'templates/app-page.php';
    }

    return $template;
}

function banka_tms_app_page_template($template) {
    if (banka_tms_app_is_app_page()) {
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
        'sites' => get_option('banka_tms_app_sites_page_id'),
        'categories' => get_option('banka_tms_app_categories_page_id'),
        'designations' => get_option('banka_tms_app_designations_page_id'),
        'task_master' => get_option('banka_tms_app_task_master_page_id'),
        'project_types' => get_option('banka_tms_app_project-types_page_id'),
        'project_categories' => get_option('banka_tms_app_project-categories_page_id'),
        'recurring' => get_option('banka_tms_app_recurring_page_id'),
        'action_log' => get_option('banka_tms_app_action-log_page_id'),
        'notification_log' => get_option('banka_tms_app_notification-log_page_id'),
        'settings' => get_option('banka_tms_app_settings_page_id'),
    );

    return array_filter($pages);
}
