<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_banka_tms_app_save_vendor', 'banka_tms_app_save_vendor');
add_action('wp_ajax_banka_tms_app_list_vendors', 'banka_tms_app_list_vendors');

function banka_tms_app_save_vendor() {
    check_ajax_referer('banka_tms_app_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Access denied'));
    }

    $name = sanitize_text_field($_POST['name'] ?? '');
    if ($name === '') {
        wp_send_json_error(array('message' => 'Vendor name is required'));
    }

    $vendors = get_option('banka_tms_app_vendors', array());
    $vendors[] = array(
        'id' => time(),
        'name' => $name,
        'email' => sanitize_email($_POST['email'] ?? ''),
        'mobile' => sanitize_text_field($_POST['mobile'] ?? ''),
    );
    update_option('banka_tms_app_vendors', $vendors);
    wp_send_json_success(array('message' => 'Vendor saved'));
}

function banka_tms_app_list_vendors() {
    check_ajax_referer('banka_tms_app_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Access denied'));
    }

    $vendors = get_option('banka_tms_app_vendors', array());
    wp_send_json_success($vendors);
}
