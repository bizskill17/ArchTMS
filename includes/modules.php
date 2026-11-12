<?php
if (!defined('ABSPATH')) {
    exit;
}

function banka_tms_app_register_module_pages() {
    $pages = array(
        'vendors' => array('title' => 'Vendors', 'slug' => 'tms-vendors'),
        'sites' => array('title' => 'Sites', 'slug' => 'tms-sites'),
        'categories' => array('title' => 'Categories', 'slug' => 'tms-categories'),
        'designations' => array('title' => 'Designations', 'slug' => 'tms-designations'),
        'recurring' => array('title' => 'Recurring Tasks', 'slug' => 'tms-recurring'),
        'project-types' => array('title' => 'Project Types', 'slug' => 'tms-project-types'),
        'project-categories' => array('title' => 'Project Categories', 'slug' => 'tms-project-categories'),
        'action-log' => array('title' => 'Action Log', 'slug' => 'tms-action-log'),
        'notification-log' => array('title' => 'Notification Log', 'slug' => 'tms-notification-log'),
    );

    foreach ($pages as $key => $page) {
        $option = 'banka_tms_app_' . $key . '_page_id';
        if (get_option($option)) {
            continue;
        }

        $id = wp_insert_post(array(
            'post_title' => $page['title'],
            'post_name' => $page['slug'],
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_content' => '[banka_tms_app_page]',
        ));

        if ($id && !is_wp_error($id)) {
            update_option($option, $id);
        }
    }
}

add_action('init', 'banka_tms_app_register_module_pages');
