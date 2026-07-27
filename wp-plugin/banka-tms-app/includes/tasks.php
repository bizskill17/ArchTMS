<?php
if (!defined('ABSPATH')) {
    exit;
}

function banka_tms_app_admin_tasks() {
    global $wpdb;

    if (!current_user_can('manage_options')) {
        wp_die('Access denied');
    }

    if (isset($_POST['task_title'])) {
        $wpdb->insert($wpdb->prefix . 'banka_tms_tasks', array(
            'title' => sanitize_text_field($_POST['task_title']),
            'description' => sanitize_textarea_field($_POST['task_description']),
            'status' => sanitize_text_field($_POST['task_status']),
            'created_by' => get_current_user_id(),
        ));
        echo '<div class="updated"><p>Task created.</p></div>';
    }

    $tasks = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}banka_tms_tasks ORDER BY id DESC");

    echo '<div class="wrap"><h1>Tasks</h1>';
    echo '<form method="post" action="' . admin_url('admin.php?page=banka-tms-tasks') . '">';
    echo '<table class="form-table"><tr><th>Title</th><td><input type="text" name="task_title" required /></td></tr>';
    echo '<tr><th>Description</th><td><textarea name="task_description"></textarea></td></tr>';
    echo '<tr><th>Status</th><td><select name="task_status"><option>Pending</option><option>In Progress</option><option>Completed</option></select></td></tr>';
    echo '</table><p class="submit"><button type="submit" class="button button-primary">Create Task</button></p></form>';
    echo '<h2>Existing Tasks</h2><ul>';
    foreach ($tasks as $task) {
        echo '<li>#' . esc_html($task->id) . ' - ' . esc_html($task->title) . ' [' . esc_html($task->status) . ']</li>';
    }
    echo '</ul></div>';
}
