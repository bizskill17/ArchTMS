<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_banka_tms_app_update_task', 'banka_tms_app_update_task');
add_action('wp_ajax_banka_tms_app_delete_task', 'banka_tms_app_delete_task');
add_action('wp_ajax_banka_tms_app_create_user', 'banka_tms_app_create_user');

function banka_tms_app_update_task() {
    check_ajax_referer('banka_tms_app_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Access denied'));
    }

    global $wpdb;
    $id = absint($_POST['id'] ?? 0);
    $status = sanitize_text_field($_POST['status'] ?? 'Pending');
    $title = sanitize_text_field($_POST['title'] ?? '');

    if (!$id || $title === '') {
        wp_send_json_error(array('message' => 'Invalid task update request'));
    }

    $updated = $wpdb->update(
        $wpdb->prefix . 'banka_tms_tasks',
        array('title' => $title, 'status' => $status),
        array('id' => $id)
    );

    if ($updated === false) {
        wp_send_json_error(array('message' => 'Unable to update task'));
    }

    wp_send_json_success(array('message' => 'Task updated'));
}

function banka_tms_app_delete_task() {
    check_ajax_referer('banka_tms_app_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Access denied'));
    }

    global $wpdb;
    $id = absint($_POST['id'] ?? 0);
    if (!$id) {
        wp_send_json_error(array('message' => 'Invalid task id'));
    }

    $deleted = $wpdb->delete($wpdb->prefix . 'banka_tms_tasks', array('id' => $id), array('%d'));
    if ($deleted === false) {
        wp_send_json_error(array('message' => 'Unable to delete task'));
    }

    wp_send_json_success(array('message' => 'Task deleted'));
}

function banka_tms_app_create_user() {
    check_ajax_referer('banka_tms_app_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Access denied'));
    }

    $login = sanitize_user($_POST['login'] ?? '');
    $email = sanitize_email($_POST['email'] ?? '');
    $password = sanitize_text_field($_POST['password'] ?? '');
    $role = sanitize_text_field($_POST['role'] ?? 'subscriber');

    if ($login === '' || $email === '' || $password === '') {
        wp_send_json_error(array('message' => 'Name, email and password are required'));
    }

    $user_id = wp_create_user($login, $password, $email);
    if (is_wp_error($user_id)) {
        wp_send_json_error(array('message' => $user_id->get_error_message()));
    }

    $user = get_user_by('id', $user_id);
    $user->set_role($role);
    update_user_meta($user_id, 'first_name', sanitize_text_field($_POST['name'] ?? ''));

    wp_send_json_success(array('message' => 'User created'));
}
