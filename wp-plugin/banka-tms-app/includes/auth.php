<?php
if (!defined('ABSPATH')) {
    exit;
}

function banka_tms_app_render_auth() {
    if (is_user_logged_in()) {
        return;
    }

    echo '<div class="banka-tms-auth">';
    echo '<h2>Sign In</h2>';
    echo '<form method="post" action="' . wp_login_url(home_url(add_query_arg(array(), wp_unslash($_SERVER['REQUEST_URI'])))) . '">';
    echo '<input type="text" name="log" placeholder="Username or Email" required />';
    echo '<input type="password" name="pwd" placeholder="Password" required />';
    echo '<button type="submit">Login</button>';
    echo '</form>';
    echo '</div>';
}

function banka_tms_app_require_login() {
    if (!is_user_logged_in()) {
        wp_redirect(wp_login_url(home_url(add_query_arg(array(), wp_unslash($_SERVER['REQUEST_URI'])))));
        exit;
    }
}
