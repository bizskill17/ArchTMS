<?php
if (!defined('ABSPATH')) {
    exit;
}

function banka_tms_app_create_required_pages() {
    $pages = array(
        array(
            'slug' => 'tms-dashboard',
            'title' => 'Dashboard',
            'option' => 'banka_tms_app_dashboard_page_id',
        ),
        array(
            'slug' => 'tms-tasks',
            'title' => 'Tasks',
            'option' => 'banka_tms_app_tasks_page_id',
        ),
        array(
            'slug' => 'tms-users',
            'title' => 'Users',
            'option' => 'banka_tms_app_users_page_id',
        ),
        array(
            'slug' => 'tms-task-master',
            'title' => 'Task Master',
            'option' => 'banka_tms_app_task_master_page_id',
        ),
        array(
            'slug' => 'tms-vendors',
            'title' => 'Vendors',
            'option' => 'banka_tms_app_vendors_page_id',
        ),
        array(
            'slug' => 'tms-settings',
            'title' => 'Settings',
            'option' => 'banka_tms_app_settings_page_id',
        ),
    );

    foreach ($pages as $page) {
        $existing = get_page_by_path($page['slug'], OBJECT, 'page');
        if ($existing) {
            update_option($page['option'], $existing->ID);
            continue;
        }

        $page_id = wp_insert_post(array(
            'post_title' => $page['title'],
            'post_name' => $page['slug'],
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_content' => '[banka_tms_app_page]',
        ));

        if ($page_id && !is_wp_error($page_id)) {
            update_option($page['option'], $page_id);
        }
    }
}
