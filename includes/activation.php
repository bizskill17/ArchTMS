<?php
if (!defined('ABSPATH')) {
    exit;
}

function banka_tms_app_activate() {
    banka_tms_app_create_tables();
    if (function_exists('banka_tms_migrate_project_meta_to_columns')) {
        banka_tms_migrate_project_meta_to_columns();
    }
    banka_tms_app_create_required_pages();
    if (function_exists('banka_tms_create_notification_table')) { banka_tms_create_notification_table(); }
    if (!wp_next_scheduled('banka_tms_pending_reminder_cron')) { wp_schedule_event(time() + 60, 'banka_tms_five_minutes', 'banka_tms_pending_reminder_cron'); }
    flush_rewrite_rules();
}

function banka_tms_app_deactivate() {
    wp_clear_scheduled_hook('banka_tms_pending_reminder_cron');
    flush_rewrite_rules();
}
