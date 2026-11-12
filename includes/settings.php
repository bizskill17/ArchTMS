<?php
if (!defined('ABSPATH')) {
    exit;
}

function banka_tms_app_admin_settings() {
    if (!current_user_can('manage_options')) {
        wp_die('Access denied');
    }

    echo '<div class="wrap"><h1>Settings</h1><p>Plugin settings panel.</p></div>';
}
