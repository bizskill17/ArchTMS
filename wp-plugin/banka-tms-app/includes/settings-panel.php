<?php
if (!defined('ABSPATH')) {
    exit;
}

function banka_tms_app_render_settings_panel() {
    if (!current_user_can('manage_options')) {
        return;
    }

    echo '<div class="banka-tms-card"><h3>Application Settings</h3><p>Organization name, branding, and app controls can be configured here.</p></div>';
}

add_action('wp_footer', 'banka_tms_app_render_settings_panel');
