<?php
if (!defined('ABSPATH')) {
    exit;
}


function banka_tms_action_log_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'banka_tms_action_logs';
}

function banka_tms_ensure_action_log_table() {
    global $wpdb;
    $table = banka_tms_action_log_table_name();
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($exists === $table) {
        $column = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", 'next_action_date'));
        if (!$column) { $wpdb->query("ALTER TABLE {$table} ADD COLUMN next_action_date DATE NULL AFTER project_name"); }
        banka_tms_sync_action_log_owner_names();
        return;
    }
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        task_id BIGINT UNSIGNED NULL,
        task_title VARCHAR(255) NOT NULL,
        action_name VARCHAR(100) NOT NULL,
        remarks TEXT NULL,
        owner_names TEXT NULL,
        assignee_names TEXT NULL,
        project_name VARCHAR(255) NULL,
        next_action_date DATE NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY task_id (task_id),
        KEY action_name (action_name)
    ) {$charset_collate};";
    dbDelta($sql);
    banka_tms_sync_action_log_owner_names();
}

/**
 * Keep historical Action Log owner names aligned with the task's selected
 * owner. Older releases stored created_by for normal updates, which could show
 * the task creator instead of the owner selected on the task.
 */
function banka_tms_sync_action_log_owner_names() {
    global $wpdb;
    $log_table = banka_tms_action_log_table_name();
    $task_table = $wpdb->prefix . 'banka_tms_tasks';
    $user_table = function_exists('banka_tms_app_users_table')
        ? banka_tms_app_users_table()
        : $wpdb->prefix . 'banka_tms_users_profile';

    $wpdb->query(
        "UPDATE {$log_table} l
         INNER JOIN {$task_table} t ON t.id = l.task_id
         INNER JOIN {$user_table} u ON u.user_id = COALESCE(NULLIF(t.owner_id, 0), t.created_by)
         SET l.owner_names = COALESCE(NULLIF(u.name, ''), u.username, '—')
         WHERE COALESCE(l.owner_names, '') <> COALESCE(NULLIF(u.name, ''), u.username, '—')"
    );
}


function banka_tms_project_display_label($stored_project = '') {
    global $wpdb;
    $stored_project = trim((string) $stored_project);
    if ($stored_project === '' || strcasecmp($stored_project, 'General Project') === 0) {
        return $stored_project === '' ? 'General Project' : $stored_project;
    }

    // Values already saved in the required display format are retained.
    if (preg_match('/\s\([^()]+\)$/', $stored_project)) {
        return $stored_project;
    }

    $project_table = $wpdb->prefix . 'banka_tms_projects';
    $project = $wpdb->get_row($wpdb->prepare(
        "SELECT project_name, address FROM {$project_table} WHERE project_name = %s LIMIT 1",
        $stored_project
    ));
    if (!$project) {
        return $stored_project;
    }

    $label = trim((string) $project->project_name);
    $address = trim((string) $project->address);
    return $address !== '' ? $label . ' (' . $address . ')' : $label;
}

function banka_tms_normalize_selected_project($selected_project = '') {
    global $wpdb;
    $selected_project = trim(sanitize_text_field($selected_project));
    if ($selected_project === '') {
        return '';
    }

    $project_table = $wpdb->prefix . 'banka_tms_projects';
    $rows = $wpdb->get_results("SELECT project_name, address FROM {$project_table} ORDER BY project_name ASC");
    foreach ($rows as $row) {
        $name = trim((string) $row->project_name);
        $address = trim((string) $row->address);
        $label = $address !== '' ? $name . ' (' . $address . ')' : $name;
        if (strcasecmp($selected_project, $label) === 0 || strcasecmp($selected_project, $name) === 0) {
            return $label;
        }
    }
    return $selected_project;
}

function banka_tms_task_project_name($task) {
    if (is_object($task) && isset($task->project_name) && trim((string) $task->project_name) !== '') {
        return banka_tms_project_display_label((string) $task->project_name);
    }
    return 'General Project';
}

function banka_tms_insert_action_log($args = array()) {
    global $wpdb;
    banka_tms_ensure_action_log_table();
    $defaults = array(
        'task_id' => 0,
        'task_title' => '',
        'action_name' => '',
        'remarks' => '',
        'owner_names' => '',
        'assignee_names' => '',
        'project_name' => 'General Project',
        'next_action_date' => '',
        'created_at' => banka_tms_ist_mysql(),
    );
    $data = wp_parse_args($args, $defaults);
    if ($data['task_title'] === '' || $data['action_name'] === '') {
        return false;
    }
    return $wpdb->insert(
        banka_tms_action_log_table_name(),
        array(
            'task_id' => absint($data['task_id']),
            'task_title' => sanitize_text_field($data['task_title']),
            'action_name' => sanitize_text_field($data['action_name']),
            'remarks' => sanitize_textarea_field($data['remarks']),
            'owner_names' => sanitize_text_field($data['owner_names']),
            'assignee_names' => sanitize_text_field($data['assignee_names']),
            'project_name' => sanitize_text_field($data['project_name']),
            'next_action_date' => !empty($data['next_action_date']) ? sanitize_text_field($data['next_action_date']) : null,
            'created_at' => sanitize_text_field($data['created_at']),
        ),
        array('%d','%s','%s','%s','%s','%s','%s','%s','%s')
    );
}

function banka_tms_seed_created_logs_for_existing_tasks() {
    global $wpdb;
    banka_tms_ensure_action_log_table();
    $task_table = $wpdb->prefix . 'banka_tms_tasks';
    $log_table = banka_tms_action_log_table_name();
    $tasks = $wpdb->get_results("SELECT * FROM {$task_table} ORDER BY id ASC");
    if (!$tasks) {
        return;
    }
    foreach ($tasks as $task) {
        if (!empty($task->source_task_id)) { continue; }
        $has_created = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$log_table} WHERE task_id = %d AND action_name = %s LIMIT 1",
            (int) $task->id,
            'Created'
        ));
        if ($has_created) {
            continue;
        }
        $owner_id = !empty($task->owner_id) ? (int) $task->owner_id : (int) ($task->created_by ?? 0);
        $owner = $owner_id ? banka_tms_app_get_user($owner_id) : false;
        $assignee = !empty($task->assignee_id) ? banka_tms_app_get_user((int) $task->assignee_id) : false;
        banka_tms_insert_action_log(array(
            'task_id' => (int) $task->id,
            'task_title' => (string) $task->title,
            'action_name' => 'Created',
            'remarks' => 'Task created.',
            'owner_names' => $owner ? $owner->display_name : '—',
            'assignee_names' => $assignee ? $assignee->display_name : '—',
            'project_name' => banka_tms_task_project_name($task),
            'created_at' => !empty($task->created_at) ? $task->created_at : banka_tms_ist_mysql(),
        ));
    }
}

add_action('wp_ajax_banka_tms_app_update_task', 'banka_tms_app_update_task');
add_action('wp_ajax_nopriv_banka_tms_app_update_task', 'banka_tms_app_update_task');
add_action('wp_ajax_banka_tms_app_get_task_activity', 'banka_tms_app_get_task_activity');
add_action('wp_ajax_nopriv_banka_tms_app_get_task_activity', 'banka_tms_app_get_task_activity');
add_action('wp_ajax_banka_tms_app_quick_update_task', 'banka_tms_app_quick_update_task');
add_action('wp_ajax_nopriv_banka_tms_app_quick_update_task', 'banka_tms_app_quick_update_task');
add_action('wp_ajax_banka_tms_app_delete_task', 'banka_tms_app_delete_task');
add_action('wp_ajax_nopriv_banka_tms_app_delete_task', 'banka_tms_app_delete_task');
add_action('wp_ajax_banka_tms_app_bulk_tasks', 'banka_tms_app_bulk_tasks');
add_action('wp_ajax_nopriv_banka_tms_app_bulk_tasks', 'banka_tms_app_bulk_tasks');
add_action('wp_ajax_banka_tms_app_create_user', 'banka_tms_app_create_user');
add_action('wp_ajax_banka_tms_app_update_user', 'banka_tms_app_update_user');
add_action('wp_ajax_banka_tms_app_delete_user', 'banka_tms_app_delete_user');
add_action('wp_ajax_nopriv_banka_tms_app_delete_user', 'banka_tms_app_delete_user');
add_action('wp_ajax_banka_tms_app_toggle_user_status', 'banka_tms_app_toggle_user_status');
add_action('wp_ajax_banka_tms_app_save_designation', 'banka_tms_app_save_designation');
add_action('wp_ajax_banka_tms_app_update_designation', 'banka_tms_app_update_designation');
add_action('wp_ajax_banka_tms_app_delete_designation', 'banka_tms_app_delete_designation');
add_action('wp_ajax_nopriv_banka_tms_app_delete_designation', 'banka_tms_app_delete_designation');
add_action('wp_ajax_banka_tms_app_save_category', 'banka_tms_app_save_category');
add_action('wp_ajax_nopriv_banka_tms_app_save_category', 'banka_tms_app_save_category');
add_action('wp_ajax_banka_tms_app_update_category', 'banka_tms_app_update_category');
add_action('wp_ajax_banka_tms_app_delete_category', 'banka_tms_app_delete_category');
add_action('wp_ajax_nopriv_banka_tms_app_delete_category', 'banka_tms_app_delete_category');
add_action('wp_ajax_banka_tms_app_list_categories', 'banka_tms_app_list_categories');
add_action('wp_ajax_banka_tms_app_save_task_master', 'banka_tms_app_save_task_master');
add_action('wp_ajax_banka_tms_app_update_task_master', 'banka_tms_app_update_task_master');
add_action('wp_ajax_banka_tms_app_delete_task_master', 'banka_tms_app_delete_task_master');
add_action('wp_ajax_banka_tms_app_save_project', 'banka_tms_app_save_project');
add_action('wp_ajax_banka_tms_app_update_project', 'banka_tms_app_update_project');
add_action('wp_ajax_banka_tms_app_delete_project', 'banka_tms_app_delete_project');
add_action('wp_ajax_nopriv_banka_tms_app_delete_project', 'banka_tms_app_delete_project');
add_action('wp_ajax_banka_tms_app_get_project_logs', 'banka_tms_app_get_project_logs');
add_action('wp_ajax_nopriv_banka_tms_app_get_project_logs', 'banka_tms_app_get_project_logs');
add_action('wp_ajax_banka_tms_app_save_project_master_item', 'banka_tms_app_save_project_master_item');
add_action('wp_ajax_banka_tms_app_delete_project_master_item', 'banka_tms_app_delete_project_master_item');



function banka_tms_app_quick_update_task() {
    // TaskPro users authenticate through the plugin's signed session cookie,
    // independently of the WordPress user session. A WordPress nonce can become
    // stale on cached frontend pages and must not block a valid TaskPro session.
    if (!function_exists('banka_tms_app_is_logged_in') || !banka_tms_app_is_logged_in()) {
        wp_send_json_error(array('message' => 'Your ArchTMS session has expired. Please sign in again.'), 401);
    }

    // Keep the nonce as an additional check when it is valid, but do not reject
    // an authenticated TaskPro user merely because the frontend nonce is stale.
    $submitted_nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if ($submitted_nonce !== '') {
        wp_verify_nonce($submitted_nonce, 'banka_tms_app_nonce');
    }

    global $wpdb;
    $id = absint($_POST['id'] ?? 0);
    $status = sanitize_text_field(wp_unslash($_POST['status'] ?? 'Pending'));
    $remarks = sanitize_textarea_field(wp_unslash($_POST['remarks'] ?? ''));
    $assignee_raw = isset($_POST['assignee_id']) ? trim((string) wp_unslash($_POST['assignee_id'])) : '';
    $next_action_input = sanitize_text_field(wp_unslash($_POST['next_action_date'] ?? ''));
    $next_action_date = '';
    if ($status !== 'Completed' && $next_action_input !== '') {
        if (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', $next_action_input, $m)) {
            $day=(int)$m[1]; $month=(int)$m[2]; $year=(int)$m[3];
        } elseif (preg_match('~^(\d{4})-(\d{2})-(\d{2})$~', $next_action_input, $m)) {
            $year=(int)$m[1]; $month=(int)$m[2]; $day=(int)$m[3];
        } else { wp_send_json_error(array('message'=>'Please enter Next Action Date in DD/MM/YYYY format')); }
        if (!checkdate($month,$day,$year)) { wp_send_json_error(array('message'=>'Please enter a valid Next Action Date')); }
        $next_action_date=sprintf('%04d-%02d-%02d',$year,$month,$day);
        $today_ist = (new DateTimeImmutable('now', banka_tms_ist_timezone()))->format('Y-m-d');
        if ($next_action_date <= $today_ist) {
            wp_send_json_error(array('message'=>'Next Action Date must be later than today. Please select a future date.'));
        }
    }
    $allowed = array('Pending', 'In Progress', 'Completed');

    if (!$id || !in_array($status, $allowed, true)) {
        wp_send_json_error(array('message' => 'Invalid task update request'));
    }
    if ($remarks === '') {
        wp_send_json_error(array('message' => 'Remarks are required'));
    }

    $task_table = $wpdb->prefix . 'banka_tms_tasks';

    // Repair older task-table structures before attempting the quick update.
    // This allows sites upgraded from earlier plugin versions to update tasks
    // without requiring deactivation/reactivation of the plugin.
    $required_columns = array(
        'remarks'    => "TEXT NULL AFTER description",
        'status'     => "VARCHAR(50) NOT NULL DEFAULT 'Pending' AFTER remarks",
        'assignee_id'=> "BIGINT UNSIGNED NULL AFTER status",
        'updated_at' => "DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        'next_action_date' => "DATE NULL AFTER due_date",
    );
    foreach ($required_columns as $column_name => $definition) {
        $column_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM {$task_table} LIKE %s",
            $column_name
        ));
        if (!$column_exists) {
            $wpdb->last_error = '';
            $altered = $wpdb->query("ALTER TABLE {$task_table} ADD COLUMN {$column_name} {$definition}");
            if ($altered === false) {
                $reason = trim((string) $wpdb->last_error);
                wp_send_json_error(array(
                    'message' => 'Unable to prepare task field ' . $column_name . ($reason !== '' ? ': ' . $reason : '')
                ));
            }
        }
    }

    $task = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$task_table} WHERE id = %d LIMIT 1",
        $id
    ));
    if (!$task) {
        wp_send_json_error(array('message' => 'Task not found'), 404);
    }

    // Admin users may update every task. Employees may update only tasks where
    // they are the assignee or task owner, matching the task-list visibility rule.
    $current_taskpro_user = function_exists('banka_tms_app_current_user') ? banka_tms_app_current_user() : false;
    if (!$current_taskpro_user || !banka_tms_app_user_can_access_task($task, $current_taskpro_user)) {
        wp_send_json_error(array('message' => 'You do not have permission to update this task.'), 403);
    }

    $effective_assignee_id = (int) $task->assignee_id;
    $update_data = array(
        'status' => $status,
        'remarks' => $remarks,
        'updated_at' => banka_tms_ist_mysql(),
        'next_action_date' => $status === 'Completed' ? null : ($next_action_date !== '' ? $next_action_date : null),
    );
    $update_formats = array('%s', '%s', '%s', '%s');

    // Blank means retain the existing assignee. A selected user changes it.
    if ($assignee_raw !== '') {
        $selected_assignee_id = absint($assignee_raw);
        if ($selected_assignee_id <= 0 || !banka_tms_app_get_user($selected_assignee_id)) {
            wp_send_json_error(array('message' => 'Please select a valid user for re-assignment'));
        }
        $effective_assignee_id = $selected_assignee_id;
        $update_data['assignee_id'] = $selected_assignee_id;
        $update_formats[] = '%d';
    }

    $updated = $wpdb->update(
        $task_table,
        $update_data,
        array('id' => $id),
        $update_formats,
        array('%d')
    );

    if ($updated === false) {
        $reason = trim((string) $wpdb->last_error);

        // Some older MySQL table definitions can make wpdb::update fail without
        // returning a useful error. Retry with a direct prepared UPDATE.
        $wpdb->last_error = '';
        if ($assignee_raw !== '') {
            $sql = $wpdb->prepare(
                "UPDATE {$task_table}
                 SET status=%s, remarks=%s, assignee_id=%d, updated_at=%s, next_action_date=%s
                 WHERE id=%d",
                $status,
                $remarks,
                $effective_assignee_id,
                banka_tms_ist_mysql(),
                $status === 'Completed' ? null : ($next_action_date !== '' ? $next_action_date : null),
                $id
            );
        } else {
            $sql = $wpdb->prepare(
                "UPDATE {$task_table}
                 SET status=%s, remarks=%s, updated_at=%s, next_action_date=%s
                 WHERE id=%d",
                $status,
                $remarks,
                banka_tms_ist_mysql(),
                $status === 'Completed' ? null : ($next_action_date !== '' ? $next_action_date : null),
                $id
            );
        }
        $retry = $wpdb->query($sql);
        if ($retry === false) {
            $retry_reason = trim((string) $wpdb->last_error);
            $message = $retry_reason !== '' ? $retry_reason : ($reason !== '' ? $reason : 'Database did not accept the task update');
            wp_send_json_error(array('message' => 'Unable to update task: ' . $message));
        }
    }

    $saved_task = $wpdb->get_row($wpdb->prepare(
        "SELECT id, status, remarks, assignee_id, updated_at, next_action_date FROM {$task_table} WHERE id=%d LIMIT 1",
        $id
    ));
    if (!$saved_task || (string) $saved_task->status !== (string) $status || trim((string) $saved_task->remarks) !== trim((string) $remarks)) {
        wp_send_json_error(array('message' => 'Task update could not be verified after saving'));
    }

    $assignee = $effective_assignee_id > 0 ? banka_tms_app_get_user($effective_assignee_id) : false;
    $owner_id = !empty($task->owner_id) ? (int) $task->owner_id : (int) ($task->created_by ?? 0);
    $owner = $owner_id ? banka_tms_app_get_user($owner_id) : false;
    banka_tms_insert_action_log(array(
        'task_id' => (int) $task->id,
        'task_title' => (string) $task->title,
        'action_name' => $status === 'Pending' ? 'Not Yet Started' : $status,
        'remarks' => $remarks,
        'owner_names' => $owner ? $owner->display_name : '—',
        'assignee_names' => $assignee ? $assignee->display_name : '—',
        'project_name' => banka_tms_task_project_name($task),
        'next_action_date' => $status === 'Completed' ? '' : $next_action_date,
        'created_at' => banka_tms_ist_mysql(),
    ));
    $notification_results = function_exists('banka_tms_notify_task_update') ? banka_tms_notify_task_update((int) $task->id, $remarks) : array();
    wp_send_json_success(array(
        'message' => 'Task updated successfully',
        'notifications' => $notification_results,
        'status' => $status,
        'status_label' => $status === 'Pending' ? 'Not Yet Started' : $status,
        'remarks' => $remarks,
        'assignee_id' => $effective_assignee_id,
        'assignee_name' => $assignee ? $assignee->display_name : '',
        'update_date' => banka_tms_ist_date('Y-m-d H:i'),
        'next_action_date' => $status === 'Completed' ? '' : $next_action_date,
        'next_action_date_display' => ($status !== 'Completed' && $next_action_date !== '') ? wp_date('d/m/Y', strtotime($next_action_date), banka_tms_ist_timezone()) : '',
    ));
}




function banka_tms_app_bulk_tasks() {
    if (!function_exists('banka_tms_app_is_logged_in') || !banka_tms_app_is_logged_in()) {
        wp_send_json_error(array('message' => 'Your ArchTMS session has expired. Please sign in again.'), 401);
    }

    global $wpdb;
    if (function_exists('banka_tms_ensure_task_update_schema')) {
        banka_tms_ensure_task_update_schema();
    }

    $current_user = function_exists('banka_tms_app_current_user') ? banka_tms_app_current_user() : false;
    if (!$current_user) {
        wp_send_json_error(array('message' => 'Unable to identify the logged-in ArchTMS user.'), 401);
    }

    $task_table = $wpdb->prefix . 'banka_tms_tasks';
    $action = sanitize_key(wp_unslash($_POST['bulk_action'] ?? ''));
    $ids_raw = isset($_POST['ids']) ? (array) $_POST['ids'] : array();
    $ids = array_values(array_unique(array_filter(array_map('absint', $ids_raw))));
    if (!$ids) {
        wp_send_json_error(array('message' => 'Please select at least one task.'));
    }

    if (!in_array($action, array('delete', 'complete', 'reassign'), true)) {
        wp_send_json_error(array('message' => 'Invalid bulk action.'));
    }

    $assignee_id = 0;
    $new_assignee = false;
    if ($action === 'reassign') {
        $assignee_id = absint($_POST['assignee_id'] ?? 0);
        $new_assignee = $assignee_id ? banka_tms_app_get_user($assignee_id) : false;
        if (!$new_assignee || empty($new_assignee->active) || !empty($new_assignee->wp_user_id)) {
            wp_send_json_error(array('message' => 'Please select a valid active ArchTMS assignee.'));
        }
    }

    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $tasks = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$task_table} WHERE id IN ({$placeholders})", $ids));
    if (!$tasks) {
        wp_send_json_error(array('message' => 'Selected tasks were not found.'));
    }

    $found_ids = array_map('intval', wp_list_pluck($tasks, 'id'));
    $missing_ids = array_values(array_diff($ids, $found_ids));
    if ($missing_ids) {
        wp_send_json_error(array('message' => 'One or more selected tasks no longer exist. Please refresh the page and try again.'));
    }

    foreach ($tasks as $task) {
        if (!banka_tms_app_user_can_access_task($task, $current_user)) {
            wp_send_json_error(array('message' => 'You do not have permission to change one or more selected tasks.'), 403);
        }
    }

    $affected = array();
    $errors = array();
    $now = banka_tms_ist_mysql();

    foreach ($tasks as $task) {
        $task_id = (int) $task->id;
        $wpdb->last_error = '';

        if ($action === 'delete') {
            $result = $wpdb->delete($task_table, array('id' => $task_id), array('%d'));
            if ($result === false) {
                $errors[] = 'Task #' . $task_id . ': ' . ($wpdb->last_error ?: 'database delete failed');
                continue;
            }
            if (function_exists('banka_tms_action_log_table_name')) {
                $wpdb->delete(banka_tms_action_log_table_name(), array('task_id' => $task_id), array('%d'));
            }
            $affected[] = $task_id;
            continue;
        }

        if ($action === 'complete') {
            $data = array(
                'status' => 'Completed',
                'remarks' => 'Task completed through bulk action.',
                'next_action_date' => null,
                'updated_at' => $now,
            );
            $result = $wpdb->update($task_table, $data, array('id' => $task_id), array('%s','%s','%s','%s'), array('%d'));
            if ($result === false) {
                $errors[] = 'Task #' . $task_id . ': ' . ($wpdb->last_error ?: 'database update failed');
                continue;
            }

            $assignee = !empty($task->assignee_id) ? banka_tms_app_get_user((int) $task->assignee_id) : false;
            $owner_id = !empty($task->owner_id) ? (int) $task->owner_id : (int) ($task->created_by ?? 0);
            $owner = $owner_id ? banka_tms_app_get_user($owner_id) : false;
            banka_tms_insert_action_log(array(
                'task_id' => $task_id,
                'task_title' => (string) $task->title,
                'action_name' => 'Completed',
                'remarks' => 'Task completed through bulk action.',
                'owner_names' => $owner ? $owner->display_name : '—',
                'assignee_names' => $assignee ? $assignee->display_name : '—',
                'project_name' => banka_tms_task_project_name($task),
                'next_action_date' => '',
                'created_at' => $now,
            ));
            if (function_exists('banka_tms_notify_task_update')) {
                banka_tms_notify_task_update($task_id, 'Task completed through bulk action.');
            }
            $affected[] = $task_id;
            continue;
        }

        $result = $wpdb->update(
            $task_table,
            array('assignee_id' => $assignee_id, 'updated_at' => $now, 'remarks' => 'Task reassigned through bulk action.'),
            array('id' => $task_id),
            array('%d','%s','%s'),
            array('%d')
        );
        if ($result === false) {
            $errors[] = 'Task #' . $task_id . ': ' . ($wpdb->last_error ?: 'database update failed');
            continue;
        }

        $owner_id = !empty($task->owner_id) ? (int) $task->owner_id : (int) ($task->created_by ?? 0);
        $owner = $owner_id ? banka_tms_app_get_user($owner_id) : false;
        banka_tms_insert_action_log(array(
            'task_id' => $task_id,
            'task_title' => (string) $task->title,
            'action_name' => 'Reassigned',
            'remarks' => 'Task reassigned through bulk action.',
            'owner_names' => $owner ? $owner->display_name : '—',
            'assignee_names' => $new_assignee ? $new_assignee->display_name : '—',
            'project_name' => banka_tms_task_project_name($task),
            'next_action_date' => !empty($task->next_action_date) ? $task->next_action_date : '',
            'created_at' => $now,
        ));
        if (function_exists('banka_tms_notify_task_update')) {
            banka_tms_notify_task_update($task_id, 'Task reassigned through bulk action.');
        }
        $affected[] = $task_id;
    }

    if (!$affected) {
        $message = $errors ? implode(' | ', array_slice($errors, 0, 3)) : 'No selected tasks could be updated.';
        wp_send_json_error(array('message' => $message), 500);
    }

    $message = $action === 'delete'
        ? 'Selected tasks deleted successfully.'
        : ($action === 'complete' ? 'Selected tasks completed successfully.' : 'Selected tasks reassigned successfully.');
    if ($errors) {
        $message .= ' Some tasks failed: ' . implode(' | ', array_slice($errors, 0, 2));
    }

    wp_send_json_success(array(
        'message' => $message,
        'ids' => $affected,
        'assignee_id' => $assignee_id,
        'assignee_name' => $new_assignee ? $new_assignee->display_name : '',
        'update_date' => banka_tms_ist_date('d/m/Y H:i'),
    ));
}

function banka_tms_app_get_task_activity() {
    if (!function_exists('banka_tms_app_is_logged_in') || !banka_tms_app_is_logged_in()) { wp_send_json_error(array('message'=>'Your ArchTMS session has expired. Please sign in again.'),401); }
    global $wpdb;
    banka_tms_ensure_action_log_table();
    $task_id = absint($_POST['task_id'] ?? 0);
    if (!$task_id) { wp_send_json_error(array('message'=>'Invalid task')); }
    $task=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}banka_tms_tasks WHERE id=%d LIMIT 1",$task_id));
    $current=function_exists('banka_tms_app_current_user')?banka_tms_app_current_user():false;
    if (!$task || !$current || !banka_tms_app_user_can_access_task($task,$current)) { wp_send_json_error(array('message'=>'You do not have permission to view this task activity.'),403); }
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . banka_tms_action_log_table_name() . " WHERE task_id=%d AND action_name <> 'Created' ORDER BY created_at DESC, id DESC", $task_id));
    $data=array();
    foreach($rows as $r){
        $data[]=array(
            'action'=>sanitize_text_field($r->action_name),
            'remarks'=>sanitize_textarea_field($r->remarks),
            'user'=>sanitize_text_field($r->assignee_names ?: $r->owner_names),
            'date'=>mysql2date('d/m/Y H:i', $r->created_at),
            'next_action_date'=>!empty($r->next_action_date)?wp_date('d/m/Y',strtotime($r->next_action_date),banka_tms_ist_timezone()):'',
        );
    }
    wp_send_json_success(array('logs'=>$data));
}

function banka_tms_app_update_task() {
    check_ajax_referer('banka_tms_app_nonce', 'nonce');
    if (!function_exists('banka_tms_app_is_logged_in') || !banka_tms_app_is_logged_in()) {
        wp_send_json_error(array('message' => 'Please log in again and retry'));
    }

    global $wpdb;
    $id = absint($_POST['id'] ?? 0);
    $status = sanitize_text_field($_POST['status'] ?? 'Pending');
    $title = sanitize_text_field($_POST['title'] ?? '');
    $description = sanitize_textarea_field($_POST['description'] ?? '');
    $assignee_id = absint($_POST['assignee_id'] ?? 0);
    $owner_id = absint($_POST['owner_id'] ?? banka_tms_app_current_user_id());
    $category_name = sanitize_text_field($_POST['category'] ?? '');
    $priority_name = sanitize_text_field($_POST['priority'] ?? 'Medium');
    $due_input = sanitize_text_field(wp_unslash($_POST['due_date'] ?? ''));
    $due_date = '';
    if ($due_input !== '') {
        $day = $month = $year = 0;
        if (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', $due_input, $m)) {
            $day=(int)$m[1]; $month=(int)$m[2]; $year=(int)$m[3];
        } elseif (preg_match('~^(\d{4})-(\d{2})-(\d{2})$~', $due_input, $m)) {
            $year=(int)$m[1]; $month=(int)$m[2]; $day=(int)$m[3];
        } else {
            wp_send_json_error(array('message' => 'Please enter Due Date in DD/MM/YYYY format'));
        }
        if (!checkdate($month, $day, $year)) {
            wp_send_json_error(array('message' => 'Please enter a valid Due Date'));
        }
        $due_date = sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
    $project_name = banka_tms_normalize_selected_project(wp_unslash($_POST['project'] ?? ''));

    if (!$id || $title === '') {
        wp_send_json_error(array('message' => 'Invalid task update request'));
    }

    $task_table = $wpdb->prefix . 'banka_tms_tasks';
    $required_columns = array(
        'owner_id' => "BIGINT UNSIGNED NULL AFTER assignee_id",
        'category_name' => "VARCHAR(150) NULL AFTER owner_id",
        'priority_name' => "VARCHAR(50) NULL AFTER category_name",
        'due_date' => "DATE NULL AFTER priority_name",
        'project_name' => "VARCHAR(255) NULL AFTER due_date",
    );
    foreach ($required_columns as $column_name => $definition) {
        $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$task_table} LIKE %s", $column_name));
        if (!$exists) { $wpdb->query("ALTER TABLE {$task_table} ADD COLUMN {$column_name} {$definition}"); }
    }
    $old_task = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$task_table} WHERE id=%d LIMIT 1", $id));
    $updated = $wpdb->update(
        $task_table,
        array(
            'title' => $title,
            'description' => $description,
            'status' => $status,
            'assignee_id' => $assignee_id ?: null,
            'owner_id' => $owner_id ?: banka_tms_app_current_user_id(),
            'category_name' => $category_name,
            'priority_name' => $priority_name ?: 'Medium',
            'due_date' => $due_date ?: null,
            'project_name' => $project_name !== '' ? $project_name : 'General Project',
        ),
        array('id' => $id)
    );

    if ($updated === false) {
        wp_send_json_error(array('message' => 'Unable to update task'));
    }

    // Editing the main task details should not create a visible action-log or notification entry.
    wp_send_json_success(array('message' => 'Task updated', 'notifications'=>array()));
}

function banka_tms_app_delete_task() {
    check_ajax_referer('banka_tms_app_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Access denied'));
    }

    global $wpdb;
    $id = absint($_POST['id'] ?? 0);
    if (!$id) {
        wp_send_json_error(array('message' => 'Invalid task id'));
    }

    $deleted = $wpdb->delete($wpdb->prefix . 'banka_tms_tasks', array('id' => $id), array('%d'));
    if ($deleted === false) {
        wp_send_json_error(array('message' => 'Unable to delete task'));
    }

    wp_send_json_success(array('message' => 'Task deleted'));
}

function banka_tms_app_create_user() {
    check_ajax_referer('banka_tms_app_nonce', 'nonce');
    if (!banka_tms_app_can_manage()) { wp_send_json_error(array('message' => 'Access denied')); }
    global $wpdb;
    $table = banka_tms_app_users_table();
    $login = sanitize_user(wp_unslash($_POST['login'] ?? ''), true);
    $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
    $password = (string) wp_unslash($_POST['password'] ?? '');
    $role_key = sanitize_key(wp_unslash($_POST['role'] ?? 'subscriber'));
    $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
    $mobile = sanitize_text_field(wp_unslash($_POST['mobile'] ?? ''));
    $designation = sanitize_text_field(wp_unslash($_POST['designation'] ?? ''));
    $category = sanitize_text_field(wp_unslash($_POST['category'] ?? ''));
    if ($name === '' || $login === '' || $email === '' || $password === '') { wp_send_json_error(array('message' => 'Name, username, email and password are required')); }
    if (!is_email($email)) { wp_send_json_error(array('message' => 'Please enter a valid email address')); }
    if ($mobile !== '' && !preg_match('/^[0-9]{10}$/', $mobile)) { wp_send_json_error(array('message' => 'Mobile number must contain exactly 10 digits.')); }
    $duplicate = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$table} WHERE username=%s OR email=%s LIMIT 1", $login, $email));
    if ($duplicate) { wp_send_json_error(array('message' => 'This username or email already exists')); }
    $role_labels = array('subscriber'=>'Employee','editor'=>'Manager','administrator'=>'Admin');
    $role = $role_labels[$role_key] ?? 'Employee';
    $next_id = max(1, (int) $wpdb->get_var("SELECT COALESCE(MAX(user_id),0)+1 FROM {$table}"));
    $inserted = $wpdb->insert($table, array(
        'user_id'=>$next_id, 'wp_user_id'=>null, 'name'=>$name, 'username'=>$login, 'email'=>$email,
        'password_hash'=>wp_hash_password($password), 'role'=>$role, 'designation'=>$designation,
        'category'=>$category, 'mobile'=>$mobile, 'active'=>1,
    ), array('%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%d'));
    if (!$inserted) { wp_send_json_error(array('message' => 'Unable to create TaskPro user')); }
    wp_send_json_success(array('message'=>'User created','user'=>array(
        'id'=>$next_id,'name'=>$name,'login'=>$login,'email'=>$email,'mobile'=>$mobile,
        'designation'=>$designation !== '' ? $designation : 'User','category'=>$category,
        'role_key'=>$role_key,'role'=>$role,'active'=>true,
    )));
}

function banka_tms_app_update_user() {
    check_ajax_referer('banka_tms_app_nonce', 'nonce');
    if (!banka_tms_app_can_manage()) { wp_send_json_error(array('message' => 'Access denied')); }
    global $wpdb;
    $table = banka_tms_app_users_table();
    $user_id = absint($_POST['user_id'] ?? 0);
    $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
    $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
    $password = (string) wp_unslash($_POST['password'] ?? '');
    $role_key = sanitize_key(wp_unslash($_POST['role'] ?? 'subscriber'));
    $mobile = sanitize_text_field(wp_unslash($_POST['mobile'] ?? ''));
    $designation = sanitize_text_field(wp_unslash($_POST['designation'] ?? ''));
    $category = sanitize_text_field(wp_unslash($_POST['category'] ?? ''));
    if (!$user_id || $name === '' || $email === '') { wp_send_json_error(array('message' => 'Name and email are required')); }
    if (!is_email($email)) { wp_send_json_error(array('message' => 'Please enter a valid email address')); }
    if ($mobile !== '' && !preg_match('/^[0-9]{10}$/', $mobile)) { wp_send_json_error(array('message' => 'Mobile number must contain exactly 10 digits.')); }
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE user_id=%d LIMIT 1", $user_id));
    if (!$row) { wp_send_json_error(array('message' => 'Invalid user')); }
    if (banka_tms_app_is_protected_wp_admin($row)) { wp_send_json_error(array('message' => 'The original WordPress administrator account is locked and cannot be edited.')); }
    $duplicate = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$table} WHERE email=%s AND user_id<>%d LIMIT 1", $email, $user_id));
    if ($duplicate) { wp_send_json_error(array('message' => 'This email already exists')); }
    $role_labels = array('subscriber'=>'Employee','editor'=>'Manager','administrator'=>'Admin');
    $data = array('name'=>$name,'email'=>$email,'role'=>$role_labels[$role_key] ?? 'Employee','mobile'=>$mobile,'designation'=>$designation,'category'=>$category);
    if ($password !== '') { $data['password_hash'] = wp_hash_password($password); }
    $wpdb->update($table, $data, array('user_id'=>$user_id));
    // Only WordPress-linked administrators are synchronised with WordPress.
    if (banka_tms_app_is_protected_wp_admin($row) && $role_key === 'administrator') {
        $wp_data = array('ID'=>(int)$row->wp_user_id,'display_name'=>$name,'user_email'=>$email);
        if ($password !== '') { $wp_data['user_pass'] = $password; }
        wp_update_user($wp_data);
    }
    wp_send_json_success(array('message'=>'User updated'));
}

function banka_tms_app_delete_user() {
    if (!banka_tms_app_can_administer()) {
        wp_send_json_error(array('message' => 'Only an ArchTMS Admin can delete users.'), 403);
    }
    global $wpdb;
    $user_id = absint($_POST['user_id'] ?? 0);
    if (!$user_id) { wp_send_json_error(array('message'=>'Invalid user.')); }
    if ($user_id === banka_tms_app_current_user_id()) {
        wp_send_json_error(array('message'=>'You cannot delete your own logged-in account.'));
    }
    $table = banka_tms_app_users_table();
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE user_id=%d LIMIT 1", $user_id));
    if (!$row) { wp_send_json_error(array('message'=>'User not found.')); }
    if (banka_tms_app_is_protected_wp_admin($row)) {
        wp_send_json_error(array('message'=>'The original WordPress administrator account is locked and cannot be deleted.'));
    }
    $deleted = $wpdb->delete($table, array('user_id'=>$user_id), array('%d'));
    if ($deleted === false) {
        wp_send_json_error(array('message'=>'Unable to delete user: ' . ($wpdb->last_error ?: 'Database update failed.')));
    }
    if ((int) $deleted < 1) {
        wp_send_json_error(array('message'=>'User was not deleted. Please refresh and try again.'));
    }
    wp_send_json_success(array('message'=>'User deleted successfully.'));
}

function banka_tms_app_toggle_user_status() {
    check_ajax_referer('banka_tms_app_nonce', 'nonce');
    if (!banka_tms_app_can_manage()) { wp_send_json_error(array('message' => 'Access denied')); }
    global $wpdb;
    $user_id = absint($_POST['user_id'] ?? 0);
    $active = isset($_POST['active']) && (string) wp_unslash($_POST['active']) === '1';
    $user = banka_tms_app_get_user($user_id);
    if (!$user_id || !$user) { wp_send_json_error(array('message'=>'Invalid user')); }
    if ($user_id === banka_tms_app_current_user_id() && !$active) { wp_send_json_error(array('message'=>'You cannot deactivate your own logged-in account.')); }
    $wpdb->update(banka_tms_app_users_table(), array('active'=>$active ? 1 : 0), array('user_id'=>$user_id), array('%d'), array('%d'));
    if (!empty($user->wp_user_id)) { update_user_meta((int)$user->wp_user_id, 'banka_tms_active', $active ? '1' : '0'); }
    wp_send_json_success(array('message'=>$active ? 'User activated successfully.' : 'User deactivated successfully.','active'=>$active));
}

function banka_tms_app_save_designation() {
    check_ajax_referer('banka_tms_app_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Access denied'));
    }
    global $wpdb;
    $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
    if ($name === '') {
        wp_send_json_error(array('message' => 'Designation name is required'));
    }
    $table = $wpdb->prefix . 'banka_tms_designations';
    $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE name = %s LIMIT 1", $name));
    if ($exists) {
        wp_send_json_error(array('message' => 'This designation already exists'));
    }
    $inserted = $wpdb->insert($table, array('name' => $name));
    if ($inserted === false) {
        wp_send_json_error(array('message' => 'Unable to save designation'));
    }
    wp_send_json_success(array('message' => 'Designation added successfully'));
}

function banka_tms_app_update_designation() {
    check_ajax_referer('banka_tms_app_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Access denied'));
    }
    global $wpdb;
    $id = absint($_POST['designation_id'] ?? 0);
    $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
    if (!$id || $name === '') {
        wp_send_json_error(array('message' => 'Designation name is required'));
    }
    $table = $wpdb->prefix . 'banka_tms_designations';
    $old_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$table} WHERE id = %d LIMIT 1", $id));
    if (!$old_name) {
        wp_send_json_error(array('message' => 'Designation not found'));
    }
    $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE name = %s AND id != %d LIMIT 1", $name, $id));
    if ($exists) {
        wp_send_json_error(array('message' => 'This designation already exists'));
    }
    $updated = $wpdb->update($table, array('name' => $name), array('id' => $id));
    if ($updated === false) {
        wp_send_json_error(array('message' => 'Unable to update designation'));
    }
    if ($old_name !== $name) {
        global $wpdb;
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->usermeta} SET meta_value = %s WHERE meta_key = 'designation' AND meta_value = %s", $name, $old_name));
        $wpdb->update($wpdb->prefix . 'banka_tms_users_profile', array('designation' => $name), array('designation' => $old_name));
    }
    wp_send_json_success(array('message' => 'Designation updated successfully'));
}

function banka_tms_app_delete_designation() {
    if (!function_exists('banka_tms_app_can_administer') || !banka_tms_app_can_administer()) {
        wp_send_json_error(array('message' => 'Only an ArchTMS Admin can delete designations.'), 403);
    }
    global $wpdb;
    $id = absint($_POST['designation_id'] ?? 0);
    if (!$id) { wp_send_json_error(array('message' => 'Invalid designation.')); }

    $table = $wpdb->prefix . 'banka_tms_designations';
    $name = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$table} WHERE id = %d LIMIT 1", $id));
    if (!$name) { wp_send_json_error(array('message' => 'Designation not found.')); }

    $profile_table = banka_tms_app_users_table();
    // Count only TaskPro users visible in the Users view. WordPress-linked/system
    // profiles are intentionally excluded from this dependency check.
    $assigned_users = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT user_id, name, username, email FROM {$profile_table}
         WHERE COALESCE(wp_user_id, 0) = 0
           AND LOWER(TRIM(COALESCE(designation, ''))) = LOWER(TRIM(%s))
         ORDER BY name ASC, username ASC, email ASC",
        $name
    ));
    $assigned_count = is_array($assigned_users) ? count($assigned_users) : 0;
    if ($assigned_count > 0) {
        $user_names = array();
        foreach ($assigned_users as $assigned_user) {
            $label = trim((string) ($assigned_user->name ?? ''));
            if ($label === '') { $label = trim((string) ($assigned_user->username ?? '')); }
            if ($label === '') { $label = trim((string) ($assigned_user->email ?? '')); }
            if ($label === '') { $label = 'User ID ' . absint($assigned_user->user_id ?? 0); }
            $user_names[] = $label;
        }
        $user_names = array_values(array_unique(array_filter($user_names)));
        $message = "Cannot delete designation '{$name}' because it is assigned to: " . implode(', ', $user_names) . '. Reassign ' . ($assigned_count === 1 ? 'this user' : 'these users') . ' first.';
        wp_send_json_error(array('message' => $message));
    }

    $deleted = $wpdb->delete($table, array('id' => $id), array('%d'));
    if ($deleted === false) {
        wp_send_json_error(array('message' => 'Unable to delete designation: ' . ($wpdb->last_error ?: 'Database operation failed.')));
    }
    if ((int) $deleted < 1) { wp_send_json_error(array('message' => 'Designation no longer exists.')); }
    wp_send_json_success(array('message' => 'Designation deleted successfully.'));
}


function banka_tms_app_save_category() {
    if (!function_exists('banka_tms_app_can_administer') || !banka_tms_app_can_administer()) {
        wp_send_json_error(array('message' => 'Only an ArchTMS Admin can add categories.'), 403);
    }
    $submitted_nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if ($submitted_nonce !== '') { wp_verify_nonce($submitted_nonce, 'banka_tms_app_nonce'); }
    global $wpdb;
    $name=sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
    if ($name==='') { wp_send_json_error(array('message'=>'Category name is required')); }
    $table=$wpdb->prefix.'banka_tms_categories';
    if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE name=%s LIMIT 1",$name))) { wp_send_json_error(array('message'=>'This category already exists')); }
    if ($wpdb->insert($table,array('name'=>$name))===false) { wp_send_json_error(array('message'=>'Unable to save category')); }
    wp_send_json_success(array('message'=>'Category added successfully','category'=>array('id'=>(int)$wpdb->insert_id,'name'=>$name)));
}

function banka_tms_app_update_category() {
    check_ajax_referer('banka_tms_app_nonce', 'nonce');
    if (!current_user_can('manage_options')) { wp_send_json_error(array('message'=>'Access denied')); }
    global $wpdb;
    $id=absint($_POST['category_id'] ?? 0);
    $name=sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
    if (!$id || $name==='') { wp_send_json_error(array('message'=>'Category name is required')); }
    $table=$wpdb->prefix.'banka_tms_categories';
    if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE name=%s AND id!=%d LIMIT 1",$name,$id))) { wp_send_json_error(array('message'=>'This category already exists')); }
    if ($wpdb->update($table,array('name'=>$name),array('id'=>$id))===false) { wp_send_json_error(array('message'=>'Unable to update category')); }
    wp_send_json_success(array('message'=>'Category updated successfully','category'=>array('id'=>$id,'name'=>$name)));
}

function banka_tms_app_delete_category() {
    if (!function_exists('banka_tms_app_can_administer') || !banka_tms_app_can_administer()) {
        wp_send_json_error(array('message' => 'Only an ArchTMS Admin can delete categories.'), 403);
    }
    global $wpdb;
    $id = absint($_POST['category_id'] ?? 0);
    if (!$id) { wp_send_json_error(array('message' => 'Invalid category.')); }

    $table = $wpdb->prefix . 'banka_tms_categories';
    $name = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$table} WHERE id = %d LIMIT 1", $id));
    if (!$name) { wp_send_json_error(array('message' => 'Category not found.')); }

    $tasks_table = $wpdb->prefix . 'banka_tms_tasks';
    $task_count = 0;
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tasks_table)) === $tasks_table) {
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$tasks_table}", 0);
        $category_column = in_array('category_name', $columns, true) ? 'category_name' : (in_array('category', $columns, true) ? 'category' : '');
        if ($category_column !== '') {
            $task_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$tasks_table} WHERE LOWER(TRIM(COALESCE({$category_column}, ''))) = LOWER(TRIM(%s))",
                $name
            ));
        }
    }
    if ($task_count > 0) {
        wp_send_json_error(array('message' => 'Cannot delete this category because it is used in ' . $task_count . ' task(s). Change those tasks first.'));
    }

    $deleted = $wpdb->delete($table, array('id' => $id), array('%d'));
    if ($deleted === false) {
        wp_send_json_error(array('message' => 'Unable to delete category: ' . ($wpdb->last_error ?: 'Database operation failed.')));
    }
    if ((int) $deleted < 1) { wp_send_json_error(array('message' => 'Category no longer exists.')); }
    wp_send_json_success(array('message' => 'Category deleted successfully.'));
}


function banka_tms_app_list_categories() {
    check_ajax_referer('banka_tms_app_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Access denied'));
    }
    global $wpdb;
    $table = $wpdb->prefix . 'banka_tms_categories';
    $rows = $wpdb->get_results("SELECT id, name FROM {$table} ORDER BY id ASC", ARRAY_A);
    wp_send_json_success(array('categories' => $rows));
}


function banka_tms_app_save_task_master() {
    check_ajax_referer('banka_tms_app_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Access denied'));
    }
    global $wpdb;
    $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
    if ($name === '') {
        wp_send_json_error(array('message' => 'Task name is required'));
    }
    $table = $wpdb->prefix . 'banka_tms_task_master';
    $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE name = %s LIMIT 1", $name));
    if ($exists) {
        wp_send_json_error(array('message' => 'This task already exists in Task Master'));
    }
    $ok = $wpdb->insert($table, array('name' => $name));
    if ($ok === false) {
        wp_send_json_error(array('message' => 'Unable to save task master'));
    }
    wp_send_json_success(array(
        'message' => 'Task added successfully',
        'task' => array('id' => (int) $wpdb->insert_id, 'name' => $name),
    ));
}

function banka_tms_app_update_task_master() {
    check_ajax_referer('banka_tms_app_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Access denied'));
    }
    global $wpdb;
    $id = absint($_POST['task_master_id'] ?? 0);
    $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
    if (!$id || $name === '') {
        wp_send_json_error(array('message' => 'Task name is required'));
    }
    $table = $wpdb->prefix . 'banka_tms_task_master';
    $old_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$table} WHERE id = %d LIMIT 1", $id));
    if (!$old_name) {
        wp_send_json_error(array('message' => 'Task master entry not found'));
    }
    $duplicate = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE name = %s AND id != %d LIMIT 1", $name, $id));
    if ($duplicate) {
        wp_send_json_error(array('message' => 'This task already exists in Task Master'));
    }
    $ok = $wpdb->update($table, array('name' => $name), array('id' => $id));
    if ($ok === false) {
        wp_send_json_error(array('message' => 'Unable to update task master'));
    }
    if ($old_name !== $name) {
        $wpdb->update($wpdb->prefix . 'banka_tms_tasks', array('title' => $name), array('title' => $old_name));
    }
    wp_send_json_success(array('message' => 'Task updated successfully'));
}

function banka_tms_app_delete_task_master() {
    if (!function_exists('banka_tms_app_can_administer') || !banka_tms_app_can_administer()) {
        wp_send_json_error(array('message' => 'Only an ArchTMS Admin can delete Task Master records.'), 403);
    }
    global $wpdb;
    $id = absint($_POST['task_master_id'] ?? 0);
    if (!$id) { wp_send_json_error(array('message' => 'Invalid Task Master record.')); }
    $table = $wpdb->prefix . 'banka_tms_task_master';
    $name = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$table} WHERE id = %d LIMIT 1", $id));
    if (!$name) { wp_send_json_error(array('message' => 'Task Master record not found.')); }
    $tasks_table = $wpdb->prefix . 'banka_tms_tasks';
    $used = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$tasks_table} WHERE LOWER(TRIM(title)) = LOWER(TRIM(%s))", $name
    ));
    if ($used > 0) {
        wp_send_json_error(array('message' => 'Cannot delete this Task Master record because it is used in ' . $used . ' task(s).'));
    }
    $deleted = $wpdb->delete($table, array('id' => $id), array('%d'));
    if ($deleted === false) { wp_send_json_error(array('message' => 'Unable to delete Task Master record: ' . ($wpdb->last_error ?: 'Database operation failed.'))); }
    if ((int) $deleted < 1) { wp_send_json_error(array('message' => 'Task Master record no longer exists.')); }
    wp_send_json_success(array('message' => 'Task Master record deleted successfully.'));
}


function banka_tms_app_next_project_code($category) {
    global $wpdb;
    $table = $wpdb->prefix . 'banka_tms_projects';

    $category_key = strtolower(trim((string) $category));
    $category_prefixes = array(
        'architect' => 'ARCH',
        'interior'  => 'INT',
    );

    if (!isset($category_prefixes[$category_key])) {
        return new WP_Error('invalid_project_category', 'Category must be Architect or Interior');
    }

    // Generate a separate running series for each category and calendar year.
    // The leading organization/project prefix is configurable in Admin Settings.
    $year = banka_tms_ist_date('Y');
    $project_prefix = strtoupper(trim((string) get_option('banka_tms_setting_project_prefix', 'P')));
    $project_prefix = preg_replace('/[^A-Z0-9]+/', '', $project_prefix);
    if ($project_prefix === '') {
        $project_prefix = 'P';
    }
    $prefix = $project_prefix . '-' . $category_prefixes[$category_key] . '-' . $year . '-';
    $existing = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT project_code FROM {$table} WHERE project_code LIKE %s",
            $wpdb->esc_like($prefix) . '%'
        )
    );

    $max = 0;
    foreach ((array) $existing as $code) {
        if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', (string) $code, $matches)) {
            $max = max($max, (int) $matches[1]);
        }
    }

    return $prefix . str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
}

function banka_tms_app_save_project() {
    // ArchTMS application users authenticate through the plugin session and may
    // not have a WordPress administrator session. Do not let a stale frontend
    // nonce or missing WordPress capability block a valid ArchTMS Admin.
    if (!function_exists('banka_tms_app_can_administer') || !banka_tms_app_can_administer()) {
        wp_send_json_error(array('message' => 'Only an ArchTMS Admin can add projects.'), 403);
    }
    $submitted_nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if ($submitted_nonce !== '') {
        wp_verify_nonce($submitted_nonce, 'banka_tms_app_nonce');
    }
    global $wpdb;
    $table = $wpdb->prefix . 'banka_tms_projects';
    $schema_ready = function_exists('banka_tms_ensure_project_columns') ? banka_tms_ensure_project_columns() : true;
    if (is_wp_error($schema_ready)) {
        wp_send_json_error(array('message' => $schema_ready->get_error_message()), 500);
    }
    $project_code = sanitize_text_field(wp_unslash($_POST['project_code'] ?? ''));
    $project_name = sanitize_text_field(wp_unslash($_POST['project_name'] ?? ''));
    $client_name = sanitize_text_field(wp_unslash($_POST['client_name'] ?? ''));
    $category = sanitize_text_field(wp_unslash($_POST['category'] ?? ''));
    $project_type = sanitize_text_field(wp_unslash($_POST['project_type'] ?? ''));
    $address = sanitize_textarea_field(wp_unslash($_POST['address'] ?? ''));
    if ($project_name === '') {
        wp_send_json_error(array('message' => 'Project Name is required'));
    }
    if ($address === '') {
        wp_send_json_error(array('message' => 'Address is required'));
    }
    if ($project_type === '') {
        wp_send_json_error(array('message' => 'Project Type is required'));
    }
    if (!in_array(strtolower($category), array('architect', 'interior'), true)) {
        wp_send_json_error(array('message' => 'Category must be Architect or Interior'));
    }
    if ($project_code === '') {
        $project_code = banka_tms_app_next_project_code($category);
        if (is_wp_error($project_code)) {
            wp_send_json_error(array('message' => $project_code->get_error_message()));
        }
    }
    $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE project_code = %s LIMIT 1", $project_code));
    if ($exists) {
        wp_send_json_error(array('message' => 'This Project ID already exists'));
    }
    $duplicate_combo = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table} WHERE LOWER(TRIM(project_name)) = LOWER(TRIM(%s)) AND LOWER(TRIM(COALESCE(client_name, ''))) = LOWER(TRIM(%s)) AND LOWER(TRIM(COALESCE(address, ''))) = LOWER(TRIM(%s)) LIMIT 1",
        $project_name,
        $client_name,
        $address
    ));
    if ($duplicate_combo) {
        wp_send_json_error(array('message' => 'This Project Name, Client Name and Address combination already exists'));
    }
    $saved = $wpdb->insert($table, array(
        'project_code' => $project_code,
        'project_name' => $project_name,
        'client_name' => $client_name,
        'category' => $category,
        'project_type' => $project_type,
        'address' => $address,
        'telegram_group_id' => sanitize_text_field(wp_unslash($_POST['telegram_group_id'] ?? '')),
        'architect_1' => sanitize_text_field(wp_unslash($_POST['architect_1'] ?? '')),
        'architect_2' => sanitize_text_field(wp_unslash($_POST['architect_2'] ?? '')),
        'asst_architect' => sanitize_text_field(wp_unslash($_POST['asst_architect'] ?? '')),
        'structural_inhouse' => sanitize_text_field(wp_unslash($_POST['structural_inhouse'] ?? '')),
        'structural_consultant' => sanitize_text_field(wp_unslash($_POST['structural_consultant'] ?? '')),
        'mep_consultant' => sanitize_text_field(wp_unslash($_POST['mep_consultant'] ?? '')),
        'landscape_consultant' => sanitize_text_field(wp_unslash($_POST['landscape_consultant'] ?? '')),
        'views_3d' => sanitize_text_field(wp_unslash($_POST['views_3d'] ?? '')),
        'working' => sanitize_text_field(wp_unslash($_POST['working'] ?? '')),
        'permission_drawing' => sanitize_text_field(wp_unslash($_POST['permission_drawing'] ?? '')),
        'site_engineer' => sanitize_text_field(wp_unslash($_POST['site_engineer'] ?? '')),
        'status_1' => sanitize_text_field(wp_unslash($_POST['status_1'] ?? '')),
        'status_2' => sanitize_text_field(wp_unslash($_POST['status_2'] ?? '')),
        'status_3' => sanitize_text_field(wp_unslash($_POST['status_3'] ?? '')),
    ));
    if (!$saved) {
        wp_send_json_error(array('message' => 'Unable to save project: ' . ($wpdb->last_error ?: 'Database insert failed.')));
    }
    $project_id = (int) $wpdb->insert_id;
    $project_meta = array(
        'telegram_group_id' => sanitize_text_field(wp_unslash($_POST['telegram_group_id'] ?? '')),
        'architect_1' => sanitize_text_field(wp_unslash($_POST['architect_1'] ?? '')),
        'architect_2' => sanitize_text_field(wp_unslash($_POST['architect_2'] ?? '')),
        'asst_architect' => sanitize_text_field(wp_unslash($_POST['asst_architect'] ?? '')),
        'structural_inhouse' => sanitize_text_field(wp_unslash($_POST['structural_inhouse'] ?? '')),
        'structural_consultant' => sanitize_text_field(wp_unslash($_POST['structural_consultant'] ?? '')),
        'mep_consultant' => sanitize_text_field(wp_unslash($_POST['mep_consultant'] ?? '')),
        'landscape_consultant' => sanitize_text_field(wp_unslash($_POST['landscape_consultant'] ?? '')),
        'views_3d' => sanitize_text_field(wp_unslash($_POST['views_3d'] ?? '')),
        'working' => sanitize_text_field(wp_unslash($_POST['working'] ?? '')),
        'permission_drawing' => sanitize_text_field(wp_unslash($_POST['permission_drawing'] ?? '')),
        'site_engineer' => sanitize_text_field(wp_unslash($_POST['site_engineer'] ?? '')),
        'status_1' => sanitize_text_field(wp_unslash($_POST['status_1'] ?? '')),
        'status_2' => sanitize_text_field(wp_unslash($_POST['status_2'] ?? '')),
        'status_3' => sanitize_text_field(wp_unslash($_POST['status_3'] ?? '')),
    );
    wp_send_json_success(array('message' => 'Project saved successfully', 'project' => array(
        'id' => $project_id,
        'project_code' => $project_code,
        'project_name' => $project_name,
        'client_name' => $client_name,
        'category' => $category,
        'project_type' => $project_type,
        'address' => $address,
        'meta' => $project_meta,
    )));
}

function banka_tms_app_update_project() {
    if (!function_exists('banka_tms_app_can_administer') || !banka_tms_app_can_administer()) {
        wp_send_json_error(array('message' => 'Only an ArchTMS Admin can update projects.'), 403);
    }
    $submitted_nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if ($submitted_nonce !== '') {
        wp_verify_nonce($submitted_nonce, 'banka_tms_app_nonce');
    }
    global $wpdb;
    $table = $wpdb->prefix . 'banka_tms_projects';
    $schema_ready = function_exists('banka_tms_ensure_project_columns') ? banka_tms_ensure_project_columns() : true;
    if (is_wp_error($schema_ready)) {
        wp_send_json_error(array('message' => $schema_ready->get_error_message()), 500);
    }
    $id = absint($_POST['project_id'] ?? 0);
    $project_code = sanitize_text_field(wp_unslash($_POST['project_code'] ?? ''));
    $project_name = sanitize_text_field(wp_unslash($_POST['project_name'] ?? ''));
    $client_name = sanitize_text_field(wp_unslash($_POST['client_name'] ?? ''));
    $category = sanitize_text_field(wp_unslash($_POST['category'] ?? ''));
    $project_type = sanitize_text_field(wp_unslash($_POST['project_type'] ?? ''));
    $address = sanitize_textarea_field(wp_unslash($_POST['address'] ?? ''));
    if (!$id || $project_name === '') {
        wp_send_json_error(array('message' => 'Project Name is required'));
    }
    if ($address === '') {
        wp_send_json_error(array('message' => 'Address is required'));
    }
    if ($project_code === '') {
        $project_code = banka_tms_app_next_project_code($category);
        if (is_wp_error($project_code)) {
            wp_send_json_error(array('message' => $project_code->get_error_message()));
        }
    }
    $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE project_code = %s AND id != %d LIMIT 1", $project_code, $id));
    if ($exists) {
        wp_send_json_error(array('message' => 'This Project ID already exists'));
    }
    $duplicate_combo = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$table} WHERE LOWER(TRIM(project_name)) = LOWER(TRIM(%s)) AND LOWER(TRIM(COALESCE(client_name, ''))) = LOWER(TRIM(%s)) AND LOWER(TRIM(COALESCE(address, ''))) = LOWER(TRIM(%s)) AND id != %d LIMIT 1",
        $project_name,
        $client_name,
        $address,
        $id
    ));
    if ($duplicate_combo) {
        wp_send_json_error(array('message' => 'This Project Name, Client Name and Address combination already exists'));
    }
    $updated = $wpdb->update($table, array(
        'project_code' => $project_code,
        'project_name' => $project_name,
        'client_name' => $client_name,
        'category' => $category,
        'project_type' => $project_type,
        'address' => $address,
        'telegram_group_id' => sanitize_text_field(wp_unslash($_POST['telegram_group_id'] ?? '')),
        'architect_1' => sanitize_text_field(wp_unslash($_POST['architect_1'] ?? '')),
        'architect_2' => sanitize_text_field(wp_unslash($_POST['architect_2'] ?? '')),
        'asst_architect' => sanitize_text_field(wp_unslash($_POST['asst_architect'] ?? '')),
        'structural_inhouse' => sanitize_text_field(wp_unslash($_POST['structural_inhouse'] ?? '')),
        'structural_consultant' => sanitize_text_field(wp_unslash($_POST['structural_consultant'] ?? '')),
        'mep_consultant' => sanitize_text_field(wp_unslash($_POST['mep_consultant'] ?? '')),
        'landscape_consultant' => sanitize_text_field(wp_unslash($_POST['landscape_consultant'] ?? '')),
        'views_3d' => sanitize_text_field(wp_unslash($_POST['views_3d'] ?? '')),
        'working' => sanitize_text_field(wp_unslash($_POST['working'] ?? '')),
        'permission_drawing' => sanitize_text_field(wp_unslash($_POST['permission_drawing'] ?? '')),
        'site_engineer' => sanitize_text_field(wp_unslash($_POST['site_engineer'] ?? '')),
        'status_1' => sanitize_text_field(wp_unslash($_POST['status_1'] ?? '')),
        'status_2' => sanitize_text_field(wp_unslash($_POST['status_2'] ?? '')),
        'status_3' => sanitize_text_field(wp_unslash($_POST['status_3'] ?? '')),
    ), array('id' => $id));
    if ($updated === false) {
        wp_send_json_error(array('message' => 'Unable to update project: ' . ($wpdb->last_error ?: 'Database update failed.')));
    }
    $project_meta = array(
        'telegram_group_id' => sanitize_text_field(wp_unslash($_POST['telegram_group_id'] ?? '')),
        'architect_1' => sanitize_text_field(wp_unslash($_POST['architect_1'] ?? '')),
        'architect_2' => sanitize_text_field(wp_unslash($_POST['architect_2'] ?? '')),
        'asst_architect' => sanitize_text_field(wp_unslash($_POST['asst_architect'] ?? '')),
        'structural_inhouse' => sanitize_text_field(wp_unslash($_POST['structural_inhouse'] ?? '')),
        'structural_consultant' => sanitize_text_field(wp_unslash($_POST['structural_consultant'] ?? '')),
        'mep_consultant' => sanitize_text_field(wp_unslash($_POST['mep_consultant'] ?? '')),
        'landscape_consultant' => sanitize_text_field(wp_unslash($_POST['landscape_consultant'] ?? '')),
        'views_3d' => sanitize_text_field(wp_unslash($_POST['views_3d'] ?? '')),
        'working' => sanitize_text_field(wp_unslash($_POST['working'] ?? '')),
        'permission_drawing' => sanitize_text_field(wp_unslash($_POST['permission_drawing'] ?? '')),
        'site_engineer' => sanitize_text_field(wp_unslash($_POST['site_engineer'] ?? '')),
        'status_1' => sanitize_text_field(wp_unslash($_POST['status_1'] ?? '')),
        'status_2' => sanitize_text_field(wp_unslash($_POST['status_2'] ?? '')),
        'status_3' => sanitize_text_field(wp_unslash($_POST['status_3'] ?? '')),
    );
    wp_send_json_success(array('message' => 'Project updated successfully', 'project' => array(
        'id' => $id,
        'project_code' => $project_code,
        'project_name' => $project_name,
        'client_name' => $client_name,
        'category' => $category,
        'project_type' => $project_type,
        'address' => $address,
        'meta' => $project_meta,
    )));
}

function banka_tms_app_delete_project() {
    if (!function_exists('banka_tms_app_can_administer') || !banka_tms_app_can_administer()) {
        wp_send_json_error(array('message' => 'Only an ArchTMS Admin can delete projects'), 403);
    }
    global $wpdb;
    $table = $wpdb->prefix . 'banka_tms_projects';
    $task_table = $wpdb->prefix . 'banka_tms_tasks';
    $id = absint($_POST['project_id'] ?? 0);
    if (!$id) {
        wp_send_json_error(array('message' => 'Invalid project id'));
    }

    $project = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id));
    if (!$project) {
        wp_send_json_error(array('message' => 'Project not found'));
    }

    $project_name = trim((string) $project->project_name);
    $project_code = trim((string) $project->project_code);
    $address = trim((string) $project->address);
    $project_label = $project_name . ($address !== '' ? ' (' . $address . ')' : '');
    $task_count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$task_table}
         WHERE LOWER(TRIM(project_name)) IN (LOWER(TRIM(%s)), LOWER(TRIM(%s)), LOWER(TRIM(%s)))",
        $project_name,
        $project_code,
        $project_label
    ));
    if ($task_count > 0) {
        wp_send_json_error(array('message' => 'Cannot delete this project because it is used in ' . $task_count . ' task(s). Delete or move those tasks first.'));
    }

    $deleted = $wpdb->delete($table, array('id' => $id), array('%d'));
    if ($deleted === false) {
        wp_send_json_error(array('message' => 'Unable to delete project: ' . ($wpdb->last_error ?: 'Database operation failed')));
    }
    if ($deleted === 0) {
        wp_send_json_error(array('message' => 'Project was not deleted because it no longer exists'));
    }

    wp_send_json_success(array('message' => 'Project deleted successfully'));
}


function banka_tms_app_get_project_logs() {
    // ArchTMS uses its own signed frontend session and does not depend on the
    // WordPress user session. This endpoint must therefore work through both
    // wp_ajax and wp_ajax_nopriv routes.
    if (!function_exists('banka_tms_app_is_logged_in') || !banka_tms_app_is_logged_in()) {
        wp_send_json_error(array('message' => 'Your ArchTMS session has expired. Please sign in again.'), 401);
    }

    // A cached frontend page can contain an expired WordPress nonce. Keep the
    // nonce as an additional check when present, but never reject a valid
    // ArchTMS session only because that WordPress nonce has expired.
    $submitted_nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if ($submitted_nonce !== '') {
        wp_verify_nonce($submitted_nonce, 'banka_tms_app_nonce');
    }
    global $wpdb;
    $project_id = absint($_POST['project_id'] ?? 0);
    if (!$project_id) {
        wp_send_json_error(array('message' => 'Invalid project id'));
    }

    $project_table = $wpdb->prefix . 'banka_tms_projects';
    $project = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$project_table} WHERE id = %d LIMIT 1", $project_id));
    if (!$project) {
        wp_send_json_error(array('message' => 'Project not found'));
    }

    if (function_exists('banka_tms_ensure_action_log_table')) {
        banka_tms_ensure_action_log_table();
    }
    $log_table = $wpdb->prefix . 'banka_tms_action_logs';
    $project_name = trim((string) $project->project_name);
    $address = trim((string) $project->address);
    $project_label = $project_name . ($address !== '' ? ' (' . $address . ')' : '');
    $like_name = $wpdb->esc_like($project_name) . '%';

    $logs = $wpdb->get_results($wpdb->prepare(
        "SELECT id, task_title, action_name, remarks, owner_names, assignee_names, project_name, created_at
         FROM {$log_table}
         WHERE project_name = %s OR project_name = %s OR project_name LIKE %s
         ORDER BY created_at DESC, id DESC",
        $project_label,
        $project_name,
        $like_name
    ));

    $items = array();
    foreach ((array) $logs as $log) {
        $items[] = array(
            'id' => (int) $log->id,
            'task_title' => (string) $log->task_title,
            'status' => (string) $log->action_name,
            'remarks' => (string) $log->remarks,
            'owners' => (string) $log->owner_names,
            'assignees' => (string) $log->assignee_names,
            'created_at' => (string) $log->created_at,
            'created_display' => !empty($log->created_at) ? mysql2date('d/m/Y H:i', $log->created_at) : '',
        );
    }

    wp_send_json_success(array(
        'project' => array(
            'id' => (int) $project->id,
            'project_code' => (string) $project->project_code,
            'project_name' => $project_name,
            'client_name' => (string) $project->client_name,
            'category' => (string) $project->category,
            'project_type' => (string) $project->project_type,
            'address' => $address,
            'label' => $project_label,
        ),
        'logs' => $items,
    ));
}


function banka_tms_app_project_master_table($master) {
    global $wpdb;
    if ($master === 'type') { return $wpdb->prefix . 'banka_tms_project_types'; }
    if ($master === 'category') { return $wpdb->prefix . 'banka_tms_project_categories'; }
    return '';
}

function banka_tms_app_save_project_master_item() {
    check_ajax_referer('banka_tms_app_nonce', 'nonce');
    if (!current_user_can('manage_options')) { wp_send_json_error(array('message' => 'Access denied')); }
    global $wpdb;
    $master = sanitize_key(wp_unslash($_POST['master'] ?? ''));
    $id = absint($_POST['id'] ?? 0);
    $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
    $table = banka_tms_app_project_master_table($master);
    if ($table === '' || $name === '') { wp_send_json_error(array('message' => 'Name is required')); }
    if ($master === 'category') {
        $normalized = strtolower($name);
        if (!in_array($normalized, array('architect', 'interior'), true)) {
            wp_send_json_error(array('message' => 'Only Architect and Interior are allowed as Project Categories'));
        }
        $name = $normalized === 'architect' ? 'Architect' : 'Interior';
    }
    $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE name = %s AND id != %d LIMIT 1", $name, $id));
    if ($exists) { wp_send_json_error(array('message' => 'This entry already exists')); }
    if ($id) {
        $saved = $wpdb->update($table, array('name' => $name), array('id' => $id));
    } else {
        $saved = $wpdb->insert($table, array('name' => $name));
        $id = (int) $wpdb->insert_id;
    }
    if ($saved === false) { wp_send_json_error(array('message' => 'Unable to save entry')); }
    wp_send_json_success(array('message' => 'Saved successfully', 'id' => $id, 'name' => $name));
}

function banka_tms_app_delete_project_master_item() {
    if (!function_exists('banka_tms_app_can_administer') || !banka_tms_app_can_administer()) {
        wp_send_json_error(array('message' => 'Only an ArchTMS Admin can delete project masters.'), 403);
    }
    global $wpdb;
    $master = sanitize_key(wp_unslash($_POST['master'] ?? ''));
    $id = absint($_POST['id'] ?? 0);
    $table = banka_tms_app_project_master_table($master);
    if ($table === '' || !$id) { wp_send_json_error(array('message' => 'Invalid request.')); }

    $name = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$table} WHERE id = %d LIMIT 1", $id));
    if (!$name) { wp_send_json_error(array('message' => 'Record not found.')); }

    $projects_table = $wpdb->prefix . 'banka_tms_projects';
    $field = $master === 'type' ? 'project_type' : 'category';
    $usage_count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$projects_table} WHERE LOWER(TRIM(COALESCE({$field}, ''))) = LOWER(TRIM(%s))",
        $name
    ));
    if ($usage_count > 0) {
        $label = $master === 'type' ? 'Project Type' : 'Project Category';
        wp_send_json_error(array('message' => 'Cannot delete this ' . $label . ' because it is used in ' . $usage_count . ' project(s). Change those projects first.'));
    }

    $deleted = $wpdb->delete($table, array('id' => $id), array('%d'));
    if ($deleted === false) { wp_send_json_error(array('message' => 'Unable to delete record: ' . ($wpdb->last_error ?: 'Database operation failed.'))); }
    if ((int) $deleted < 1) { wp_send_json_error(array('message' => 'Record no longer exists.')); }
    wp_send_json_success(array('message' => 'Deleted successfully.'));
}
