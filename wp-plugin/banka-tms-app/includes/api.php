<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_banka_tms_app_save_task', 'banka_tms_app_save_task');
add_action('wp_ajax_banka_tms_app_list_tasks', 'banka_tms_app_list_tasks');
add_action('wp_ajax_banka_tms_app_list_users', 'banka_tms_app_list_users');

function banka_tms_app_save_task() {
    check_ajax_referer('banka_tms_app_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Access denied'));
    }

    global $wpdb;
    $title = sanitize_text_field($_POST['title'] ?? '');
    $description = sanitize_textarea_field($_POST['description'] ?? '');
    $status = sanitize_text_field($_POST['status'] ?? 'Pending');

    if ($title === '') {
        wp_send_json_error(array('message' => 'Title is required'));
    }

    $inserted = $wpdb->insert($wpdb->prefix . 'banka_tms_tasks', array(
        'title' => $title,
        'description' => $description,
        'status' => $status,
        'created_by' => get_current_user_id(),
    ));

    if ($inserted === false) {
        wp_send_json_error(array('message' => 'Could not save task'));
    }

    wp_send_json_success(array('message' => 'Task saved'));
}

function banka_tms_app_list_tasks() {
    check_ajax_referer('banka_tms_app_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Access denied'));
    }

    global $wpdb;
    $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}banka_tms_tasks ORDER BY id DESC");
    wp_send_json_success($rows);
}

function banka_tms_app_list_users() {
    check_ajax_referer('banka_tms_app_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Access denied'));
    }

    $users = get_users(array('number' => 50, 'orderby' => 'ID', 'order' => 'DESC'));
    $payload = array();
    foreach ($users as $user) {
        $payload[] = array(
            'id' => $user->ID,
            'name' => $user->display_name,
            'email' => $user->user_email,
            'role' => implode(', ', $user->roles),
        );
    }

    wp_send_json_success($payload);
}
