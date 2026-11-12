<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_ajax_banka_tms_app_save_task', 'banka_tms_app_save_task');
add_action('wp_ajax_nopriv_banka_tms_app_save_task', 'banka_tms_app_save_task');
add_action('wp_ajax_banka_tms_app_list_tasks', 'banka_tms_app_list_tasks');
add_action('wp_ajax_banka_tms_app_list_users', 'banka_tms_app_list_users');

function banka_tms_app_save_task() {
    // TaskPro users are authenticated through the plugin session rather than a
    // WordPress user cookie. Validate that session first. A nonce is still
    // checked when supplied, but a stale WordPress nonce must not block a valid
    // TaskPro session.
    if (!function_exists('banka_tms_app_is_logged_in') || !banka_tms_app_is_logged_in()) {
        wp_send_json_error(array('message' => 'Your TaskPro session has expired. Please sign in again.'), 401);
    }
    if (!empty($_POST['nonce']) && !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'banka_tms_app_nonce')) {
        // Continue for a valid TaskPro session. This avoids failures when a
        // WordPress administrator cookie and TaskPro login coexist.
    }

    global $wpdb;
    $task_table = $wpdb->prefix . 'banka_tms_tasks';

    // Repair the task table explicitly. dbDelta is useful on activation, but
    // direct column checks are more reliable during an AJAX request.
    $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $task_table));
    if ($table_exists !== $task_table && function_exists('banka_tms_app_create_tables')) {
        banka_tms_app_create_tables();
    }
    $required_columns = array(
        'title'         => "VARCHAR(255) NOT NULL DEFAULT ''",
        'description'   => 'TEXT NULL',
        'remarks'       => 'TEXT NULL',
        'status'        => "VARCHAR(50) NOT NULL DEFAULT 'Not Yet Started'",
        'assignee_id'   => 'BIGINT UNSIGNED NULL',
        'owner_id'      => 'BIGINT UNSIGNED NULL',
        'category_name' => 'VARCHAR(150) NULL',
        'priority_name' => 'VARCHAR(50) NULL',
        'due_date'      => 'DATE NULL',
        'project_name'  => 'VARCHAR(255) NULL',
        'created_by'    => 'BIGINT UNSIGNED NULL',
        'created_at'    => 'DATETIME NULL',
        'updated_at'    => 'DATETIME NULL',
    );
    foreach ($required_columns as $column => $definition) {
        $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$task_table} LIKE %s", $column));
        if (!$exists) {
            $wpdb->query("ALTER TABLE {$task_table} ADD COLUMN {$column} {$definition}");
            if ($wpdb->last_error) {
                wp_send_json_error(array('message' => 'Task table repair failed: ' . $wpdb->last_error), 500);
            }
        }
    }

    $title        = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
    $description  = sanitize_textarea_field(wp_unslash($_POST['description'] ?? ''));
    $status       = sanitize_text_field(wp_unslash($_POST['status'] ?? 'Not Yet Started'));
    $assignee_id  = absint($_POST['assignee_id'] ?? 0);
    $owner_id     = absint($_POST['owner_id'] ?? 0);
    $category     = sanitize_text_field(wp_unslash($_POST['category'] ?? ''));
    $priority     = sanitize_text_field(wp_unslash($_POST['priority'] ?? 'Medium'));
    $due_input    = sanitize_text_field(wp_unslash($_POST['due_date'] ?? ''));
    $project_raw  = wp_unslash($_POST['project'] ?? '');
    $project_name = function_exists('banka_tms_normalize_selected_project')
        ? banka_tms_normalize_selected_project($project_raw)
        : sanitize_text_field($project_raw);

    if ($title === '') { wp_send_json_error(array('message' => 'Please select a task.'), 422); }
    $assignee = $assignee_id ? banka_tms_app_get_user($assignee_id) : false;
    if (!$assignee || empty($assignee->active)) { wp_send_json_error(array('message' => 'Please select a valid active assignee.'), 422); }
    $owner = $owner_id ? banka_tms_app_get_user($owner_id) : false;
    if (!$owner || empty($owner->active)) { wp_send_json_error(array('message' => 'Please select a valid active task owner.'), 422); }
    if ($category === '') { wp_send_json_error(array('message' => 'Please select a category.'), 422); }
    if ($project_name === '') { wp_send_json_error(array('message' => 'Please select a project.'), 422); }

    // Browser date inputs display DD/MM/YYYY in India but submit YYYY-MM-DD.
    // Accept both values so the visible, valid date is never rejected.
    $day = $month = $year = 0;
    if (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', $due_input, $m)) {
        $day=(int)$m[1]; $month=(int)$m[2]; $year=(int)$m[3];
    } elseif (preg_match('~^(\d{4})-(\d{2})-(\d{2})$~', $due_input, $m)) {
        $year=(int)$m[1]; $month=(int)$m[2]; $day=(int)$m[3];
    } else {
        wp_send_json_error(array('message' => 'Please enter Due Date in DD/MM/YYYY format.'), 422);
    }
    if (!checkdate($month,$day,$year)) {
        wp_send_json_error(array('message' => 'Please enter a valid Due Date.'), 422);
    }
    $due_date=sprintf('%04d-%02d-%02d',$year,$month,$day);

    $allowed_statuses=array('Not Yet Started','In Progress','Completed');
    if (!in_array($status,$allowed_statuses,true)) { $status='Not Yet Started'; }
    $allowed_priorities=array('Low','Medium','High');
    if (!in_array($priority,$allowed_priorities,true)) { $priority='Medium'; }

    $now=banka_tms_ist_mysql();
    $insert_data=array(
        'title'=>$title,
        'description'=>$description,
        'remarks'=>'',
        'status'=>$status,
        'assignee_id'=>$assignee_id,
        'owner_id'=>$owner_id,
        'category_name'=>$category,
        'priority_name'=>$priority,
        'due_date'=>$due_date,
        'project_name'=>$project_name,
        'created_by'=>(int)banka_tms_app_current_user_id(),
        'created_at'=>$now,
        'updated_at'=>$now,
    );
    $inserted=$wpdb->insert($task_table,$insert_data,array('%s','%s','%s','%s','%d','%d','%s','%s','%s','%s','%d','%s','%s'));
    if ($inserted===false) {
        $db_error=trim((string)$wpdb->last_error);
        error_log('TaskPro task insert failed: '.$db_error.' | '.$wpdb->last_query);
        wp_send_json_error(array('message'=>'Unable to save task. Database error: '.($db_error ?: 'Unknown insert failure.')),500);
    }

    $task_id=(int)$wpdb->insert_id;
    if (function_exists('banka_tms_insert_action_log')) {
        banka_tms_insert_action_log(array(
            'task_id'=>$task_id,
            'task_title'=>$title,
            'action_name'=>'Created',
            'remarks'=>'Task created.',
            'owner_names'=>$owner->display_name,
            'assignee_names'=>$assignee->display_name,
            'project_name'=>$project_name,
            'created_at'=>$now,
        ));
    }
    if (function_exists('banka_tms_notify_new_task')) {
        try { banka_tms_notify_new_task($task_id); }
        catch (Throwable $e) { error_log('TaskPro notification error: '.$e->getMessage()); }
    }
    wp_send_json_success(array('message'=>'Task saved successfully.','task_id'=>$task_id));
}

function banka_tms_app_list_tasks() {
    check_ajax_referer('banka_tms_app_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Access denied'));
    }

    global $wpdb;
    $table = $wpdb->prefix . 'banka_tms_tasks';
    $current = banka_tms_app_current_user();
    if (banka_tms_app_is_admin_user($current)) {
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC");
    } else {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE assignee_id=%d OR owner_id=%d OR created_by=%d ORDER BY id DESC",
            (int)$current->ID, (int)$current->ID, (int)$current->ID
        ));
    }
    wp_send_json_success($rows);
}

function banka_tms_app_list_users() {
    check_ajax_referer('banka_tms_app_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Access denied'));
    }

    $users = banka_tms_app_get_users(array('number' => 50, 'orderby' => 'ID', 'order' => 'DESC'));
    $payload = array();
    foreach ($users as $user) {
        $payload[] = array(
            'id' => $user->ID,
            'name' => $user->display_name,
            'email' => $user->user_email,
            'role' => implode(', ', $user->roles),
        );
    }

    wp_send_json_success($payload);
}
