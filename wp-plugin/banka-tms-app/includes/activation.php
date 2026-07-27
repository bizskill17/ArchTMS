<?php
if (!defined('ABSPATH')) {
    exit;
}

function banka_tms_app_activate() {
    banka_tms_app_create_tables();
    banka_tms_app_create_required_pages();
    flush_rewrite_rules();
}

function banka_tms_app_deactivate() {
    flush_rewrite_rules();
}
