<?php
if (!defined('ABSPATH')) {
    exit;
}

function banka_tms_app_admin_users() {
    if (!current_user_can('manage_options')) {
        wp_die('Access denied');
    }

    if (isset($_POST['new_user_login'])) {
        $user_id = wp_create_user(
            sanitize_user($_POST['new_user_login']),
            sanitize_text_field($_POST['new_user_password']),
            sanitize_email($_POST['new_user_email'])
        );

        if (!is_wp_error($user_id)) {
            $user = get_user_by('id', $user_id);
            $user->set_role(sanitize_text_field($_POST['new_user_role']));
            update_user_meta($user_id, 'first_name', sanitize_text_field($_POST['new_user_name']));
            echo '<div class="updated"><p>User created successfully.</p></div>';
        } else {
            echo '<div class="error"><p>' . esc_html($user_id->get_error_message()) . '</p></div>';
        }
    }

    echo '<div class="wrap"><h1>Create User</h1>';
    echo '<form method="post" action="' . admin_url('admin.php?page=banka-tms-users') . '">';
    echo '<table class="form-table"><tr><th>Name</th><td><input type="text" name="new_user_name" required /></td></tr>';
    echo '<tr><th>Username</th><td><input type="text" name="new_user_login" required /></td></tr>';
    echo '<tr><th>Email</th><td><input type="email" name="new_user_email" required /></td></tr>';
    echo '<tr><th>Password</th><td><input type="password" name="new_user_password" required /></td></tr>';
    echo '<tr><th>Role</th><td><select name="new_user_role"><option value="administrator">Administrator</option><option value="editor">Editor</option><option value="author">Author</option><option value="subscriber">Subscriber</option></select></td></tr>';
    echo '</table><p class="submit"><button type="submit" class="button button-primary">Create User</button></p></form></div>';
}
