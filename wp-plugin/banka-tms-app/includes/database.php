<?php
if (!defined('ABSPATH')) {
    exit;
}

function banka_tms_app_create_tables() {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    $sql = array();

    $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}banka_tms_tasks (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        title VARCHAR(255) NOT NULL,
        description TEXT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'Pending',
        assignee_id BIGINT UNSIGNED NULL,
        created_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}banka_tms_users_profile (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        role VARCHAR(50) NOT NULL DEFAULT 'Employee',
        designation VARCHAR(100) NULL,
        mobile VARCHAR(30) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    foreach ($sql as $query) {
        dbDelta($query);
    }
}
