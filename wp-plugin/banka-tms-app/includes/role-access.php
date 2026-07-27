<?php
if (!defined('ABSPATH')) {
    exit;
}

function banka_tms_app_can_access_app() {
    return is_user_logged_in() && current_user_can('edit_posts');
}

function banka_tms_app_redirect_non_admins() {
    if (is_page(banka_tms_app_get_page_ids()) && !current_user_can('manage_options')) {
        wp_redirect(home_url());
        exit;
    }
}

add_action('template_redirect', 'banka_tms_app_redirect_non_admins');
