<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * App access is handled inside the app template.
 * Logged-out visitors must be allowed to reach the template so the
 * custom TaskPro login screen can render. Redirecting them to home_url()
 * can create an infinite loop when the Dashboard is configured as home.
 */
function banka_tms_app_can_access_app() {
    return banka_tms_app_is_logged_in();
}

function banka_tms_app_redirect_non_admins() {
    // Intentionally do not redirect app pages here.
    // Authentication and inactive-user checks are handled by
    // banka_tms_app_require_login() in templates/app-page.php.
    return;
}

add_action('template_redirect', 'banka_tms_app_redirect_non_admins', 1);
