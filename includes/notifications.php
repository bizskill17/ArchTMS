<?php
if (!defined('ABSPATH')) { exit; }

function banka_tms_notification_log_table() {
    global $wpdb;
    return $wpdb->prefix . 'banka_tms_notification_logs';
}

function banka_tms_create_notification_table() {
    global $wpdb;
    $table = banka_tms_notification_log_table();
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_key VARCHAR(190) NOT NULL,
        channel VARCHAR(30) NOT NULL,
        event_type VARCHAR(60) NOT NULL,
        task_id BIGINT UNSIGNED NULL,
        recipient_name VARCHAR(190) NULL,
        recipient_address VARCHAR(190) NULL,
        message LONGTEXT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'Pending',
        response LONGTEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY event_key (event_key),
        KEY task_id (task_id),
        KEY channel_status (channel,status)
    ) {$charset};");
}

function banka_tms_notification_enabled($key, $default = '0') {
    return get_option('banka_tms_setting_' . $key, $default) === '1';
}

function banka_tms_normalize_mobile($mobile) {
    $digits = preg_replace('/\D+/', '', (string) $mobile);

    // Message Auto Sender accepts Indian mobile numbers in 10-digit format only.
    if (strlen($digits) === 12 && substr($digits, 0, 2) === '91') {
        $digits = substr($digits, -10);
    } elseif (strlen($digits) === 11 && substr($digits, 0, 1) === '0') {
        $digits = substr($digits, -10);
    }

    return strlen($digits) === 10 ? $digits : '';
}

function banka_tms_format_date_message($date) {
    if (empty($date) || $date === '0000-00-00') { return '—'; }
    $ts = strtotime($date);
    return $ts ? wp_date('d/m/Y', $ts, banka_tms_ist_timezone()) : (string) $date;
}

function banka_tms_current_actor_name() {
    $id = function_exists('banka_tms_app_current_user_id') ? (int) banka_tms_app_current_user_id() : 0;
    $user = $id && function_exists('banka_tms_app_get_user') ? banka_tms_app_get_user($id) : false;
    if ($user && !empty($user->display_name)) { return $user->display_name; }
    $wp = wp_get_current_user();
    return $wp && $wp->exists() ? $wp->display_name : 'System';
}

function banka_tms_log_and_send($channel, $event_type, $task_id, $recipient_name, $recipient_address, $message, $unique_seed, $sender) {
    global $wpdb;
    banka_tms_create_notification_table();
    $event_key = hash('sha256', implode('|', array($channel, $event_type, (int)$task_id, $recipient_address, $unique_seed)));
    $table = banka_tms_notification_log_table();
    $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE event_key=%s LIMIT 1", $event_key));
    if ($exists) { return array('success'=>true, 'duplicate'=>true); }

    $wpdb->insert($table, array(
        'event_key'=>$event_key, 'channel'=>$channel, 'event_type'=>$event_type, 'task_id'=>$task_id ?: null,
        'recipient_name'=>$recipient_name, 'recipient_address'=>$recipient_address, 'message'=>$message,
        'status'=>'Pending', 'created_at'=>banka_tms_ist_mysql(),
    ));
    $log_id = (int) $wpdb->insert_id;
    $result = call_user_func($sender, $recipient_address, $message);
    $ok = !empty($result['success']);
    $wpdb->update($table, array(
        'status'=>$ok ? 'Sent' : 'Failed',
        'response'=>wp_json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ), array('id'=>$log_id), array('%s','%s'), array('%d'));
    return $result;
}

function banka_tms_send_mas_direct($mobile, $message) {
    $username = get_option('banka_tms_setting_mas_username', '');
    $password = get_option('banka_tms_setting_mas_password', '');
    $mobile = banka_tms_normalize_mobile($mobile);
    if ($username === '' || $password === '' || $mobile === '') {
        return array('success'=>false, 'error'=>'Missing MAS credentials or invalid mobile number');
    }
    $response = wp_remote_post('https://app.messageautosender.com/api/v1/message/create', array(
        'timeout'=>25,
        'headers'=>array(
            'accept'=>'application/json',
            'Authorization'=>'Basic ' . base64_encode($username . ':' . $password),
            'Content-Type'=>'application/json',
        ),
        'body'=>wp_json_encode(array('receiverMobileNo'=>$mobile, 'recipientIds'=>array(), 'message'=>array($message))),
    ));
    if (is_wp_error($response)) { return array('success'=>false, 'error'=>$response->get_error_message()); }
    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    return array('success'=>$code >= 200 && $code < 300, 'http_code'=>$code, 'body'=>$body);
}

function banka_tms_send_mas_group($group_id, $message) {
    $username = get_option('banka_tms_setting_mas_username', '');
    $password = get_option('banka_tms_setting_mas_password', '');
    if ($username === '' || $password === '' || trim($group_id) === '') {
        return array('success'=>false, 'error'=>'Missing MAS credentials or WhatsApp Group ID');
    }
    $response = wp_remote_post('https://app.messageautosender.com/api/v1/message/create', array(
        'timeout'=>25,
        'headers'=>array('accept'=>'application/json','Authorization'=>'Basic ' . base64_encode($username . ':' . $password),'Content-Type'=>'application/json'),
        'body'=>wp_json_encode(array('receiverMobileNo'=>'', 'recipientIds'=>array(trim($group_id)), 'message'=>array($message))),
    ));
    if (is_wp_error($response)) { return array('success'=>false, 'error'=>$response->get_error_message()); }
    $code = wp_remote_retrieve_response_code($response);
    return array('success'=>$code >= 200 && $code < 300, 'http_code'=>$code, 'body'=>wp_remote_retrieve_body($response));
}


function banka_tms_meta_normalize_mobile($mobile) {
    $digits = preg_replace('/\D+/', '', (string)$mobile);
    $country = preg_replace('/\D+/', '', (string)get_option('banka_tms_setting_meta_country_code', '91'));
    if (strlen($digits) === 10 && $country !== '') { $digits = $country . $digits; }
    if (strlen($digits) === 11 && substr($digits, 0, 1) === '0' && $country !== '') { $digits = $country . substr($digits, 1); }
    return (strlen($digits) >= 11 && strlen($digits) <= 15) ? $digits : '';
}

function banka_tms_send_meta_template($mobile, $template_name, $language, $parameters) {
    $phone_number_id = trim((string)get_option('banka_tms_setting_meta_phone_number_id', ''));
    $token = trim((string)get_option('banka_tms_setting_meta_access_token', ''));
    $version = trim((string)get_option('banka_tms_setting_meta_api_version', 'v23.0'));
    $version = preg_match('/^v\d+\.\d+$/', $version) ? $version : 'v23.0';
    $mobile = banka_tms_meta_normalize_mobile($mobile);
    if ($phone_number_id === '' || $token === '' || $mobile === '' || $template_name === '') {
        return array('success'=>false,'error'=>'Missing Meta credentials, approved template name, or valid mobile number');
    }
    $body_params = array();
    foreach ((array)$parameters as $value) { $body_params[] = array('type'=>'text','text'=>(string)($value === '' ? '—' : $value)); }
    $payload = array(
        'messaging_product'=>'whatsapp', 'to'=>$mobile, 'type'=>'template',
        'template'=>array('name'=>$template_name,'language'=>array('code'=>$language ?: 'en'),'components'=>array(array('type'=>'body','parameters'=>$body_params)))
    );
    $response = wp_remote_post('https://graph.facebook.com/' . rawurlencode($version) . '/' . rawurlencode($phone_number_id) . '/messages', array(
        'timeout'=>30,
        'headers'=>array('Authorization'=>'Bearer ' . $token,'Content-Type'=>'application/json'),
        'body'=>wp_json_encode($payload),
    ));
    if (is_wp_error($response)) { return array('success'=>false,'error'=>$response->get_error_message()); }
    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    return array('success'=>$code >= 200 && $code < 300,'http_code'=>$code,'body'=>$body);
}

function banka_tms_log_and_send_meta($event_type, $task_id, $recipient_name, $mobile, $template_name, $language, $params, $unique_seed) {
    global $wpdb;
    banka_tms_create_notification_table();
    $event_key = hash('sha256', implode('|', array('Meta',$event_type,(int)$task_id,$mobile,$template_name,$unique_seed)));
    $table = banka_tms_notification_log_table();
    if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE event_key=%s LIMIT 1", $event_key))) { return array('success'=>true,'duplicate'=>true); }
    $preview = $template_name . ' | ' . implode(' | ', array_map('strval', (array)$params));
    $wpdb->insert($table,array('event_key'=>$event_key,'channel'=>'Meta','event_type'=>$event_type,'task_id'=>$task_id ?: null,'recipient_name'=>$recipient_name,'recipient_address'=>$mobile,'message'=>$preview,'status'=>'Pending','created_at'=>banka_tms_ist_mysql()));
    $log_id=(int)$wpdb->insert_id;
    $result=banka_tms_send_meta_template($mobile,$template_name,$language,$params);
    $wpdb->update($table,array('status'=>!empty($result['success'])?'Sent':'Failed','response'=>wp_json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),array('id'=>$log_id),array('%s','%s'),array('%d'));
    return $result;
}
function banka_tms_send_telegram($chat_id, $message) {
    $token = get_option('banka_tms_setting_telegram_bot_token', '');
    if ($token === '' || trim($chat_id) === '') { return array('success'=>false, 'error'=>'Missing Telegram token or chat ID'); }
    $response = wp_remote_post('https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage', array(
        'timeout'=>25,
        'body'=>array('chat_id'=>trim($chat_id), 'text'=>$message, 'parse_mode'=>'Markdown'),
    ));
    if (is_wp_error($response)) { return array('success'=>false, 'error'=>$response->get_error_message()); }
    $code = wp_remote_retrieve_response_code($response);
    return array('success'=>$code >= 200 && $code < 300, 'http_code'=>$code, 'body'=>wp_remote_retrieve_body($response));
}

function banka_tms_dispatch_event_message($event_type, $task, $recipient_user, $message, $unique_seed) {
    $results = array();
    if (banka_tms_notification_enabled('whatsapp_enabled')) {
        $mobile = $recipient_user && !empty($recipient_user->mobile) ? $recipient_user->mobile : '';
        $results['whatsapp'] = banka_tms_log_and_send('WhatsApp', $event_type, (int)$task->id, $recipient_user->display_name ?? '', $mobile, $message, $unique_seed, 'banka_tms_send_mas_direct');
        // WhatsApp Group is intentionally NOT used for task-created/task-updated events.
        // Group delivery is restricted to Pending Task Reminders and Daily Activity Reports only.
    }
    if (banka_tms_notification_enabled('telegram_enabled')) {
        $chat = get_option('banka_tms_setting_telegram_reporting_group_chat_id', '');
        $results['telegram'] = banka_tms_log_and_send('Telegram', $event_type, (int)$task->id, 'Telegram Reporting Group', $chat, $message, $unique_seed, 'banka_tms_send_telegram');
    }
    return $results;
}

function banka_tms_notify_new_task($task_id) {
    global $wpdb;
    $task = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}banka_tms_tasks WHERE id=%d", $task_id));
    if (!$task || empty($task->assignee_id)) { return array(); }
    $assignee = banka_tms_app_get_user((int)$task->assignee_id);
    if (!$assignee) { return array(); }
    $message = "A new task has been assigned.\n\n"
        . "*Assigned to:* " . $assignee->display_name . "\n"
        . "*Task name:* " . $task->title . "\n"
        . "*Assigned by:* " . banka_tms_current_actor_name() . "\n"
        . "*Project name:* " . ($task->project_name ?: 'General Project') . "\n"
        . "*Due date:* " . banka_tms_format_date_message($task->due_date) . "\n"
        . "*Task notes:* " . (trim((string)$task->description) !== '' ? trim($task->description) : '—') . "\n\n"
        . "Please review the task and complete.";
    $results = banka_tms_dispatch_event_message('New Task Assigned', $task, $assignee, $message, 'created-' . $task->created_at);
    if (banka_tms_notification_enabled('meta_whatsapp_enabled') && banka_tms_notification_enabled('meta_assignment_enabled', '1')) {
        $params = array(
            $assignee->display_name,
            $task->title,
            banka_tms_current_actor_name(),
            $task->project_name ?: 'General Project',
            banka_tms_format_date_message($task->due_date),
            trim((string)$task->description) !== '' ? trim($task->description) : '—'
        );
        $results['meta'] = banka_tms_log_and_send_meta('New Task Assigned', (int)$task->id, $assignee->display_name, $assignee->mobile ?? '', get_option('banka_tms_setting_meta_assignment_template','archtms_task_assignment'), get_option('banka_tms_setting_meta_assignment_language','en'), $params, 'created-' . $task->created_at);
    }
    return $results;
}

function banka_tms_notify_task_update($task_id, $remark = '') {
    global $wpdb;
    $task = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}banka_tms_tasks WHERE id=%d", $task_id));
    if (!$task) { return array(); }
    $owner_id = !empty($task->owner_id) ? (int)$task->owner_id : (int)$task->created_by;
    $owner = $owner_id ? banka_tms_app_get_user($owner_id) : false;
    if (!$owner) { return array(); }
    $status = $task->status === 'Pending' ? 'Not Yet Started' : $task->status;
    $message = "*Task Updated.*\n\n"
        . "*Task name:* " . $task->title . "\n"
        . "*Updated by:* " . banka_tms_current_actor_name() . "\n"
        . "*New status:* " . $status . "\n"
        . "*Project name:* " . ($task->project_name ?: 'General Project') . "\n"
        . "*Due date:* " . banka_tms_format_date_message($task->due_date) . "\n"
        . "*Update remarks:* " . (trim((string)$remark) !== '' ? trim($remark) : (trim((string)$task->remarks) !== '' ? trim($task->remarks) : '—')) . "\n\n"
        . "Please review the latest task status and remarks.";
    $results = banka_tms_dispatch_event_message('Task Updated', $task, $owner, $message, 'updated-' . $task->updated_at . '-' . md5($remark . $status));
    if (banka_tms_notification_enabled('meta_whatsapp_enabled') && banka_tms_notification_enabled('meta_update_enabled', '1')) {
        $params = array(
            $task->title,
            banka_tms_current_actor_name(),
            $status,
            $task->project_name ?: 'General Project',
            banka_tms_format_date_message($task->due_date),
            trim((string)$remark) !== '' ? trim($remark) : (trim((string)$task->remarks) !== '' ? trim($task->remarks) : '—')
        );
        $results['meta'] = banka_tms_log_and_send_meta('Task Updated', (int)$task->id, $owner->display_name, $owner->mobile ?? '', get_option('banka_tms_setting_meta_update_template','archtms_task_update'), get_option('banka_tms_setting_meta_update_language','en'), $params, 'updated-' . $task->updated_at . '-' . md5($remark . $status));
    }
    return $results;
}

function banka_tms_add_five_minute_schedule($schedules) {
    $schedules['banka_tms_five_minutes'] = array('interval'=>300, 'display'=>'Every Five Minutes');
    return $schedules;
}
add_filter('cron_schedules', 'banka_tms_add_five_minute_schedule');

function banka_tms_ensure_notification_cron() {
    if (!wp_next_scheduled('banka_tms_pending_reminder_cron')) {
        wp_schedule_event(time() + 60, 'banka_tms_five_minutes', 'banka_tms_pending_reminder_cron');
    }
}
add_action('init', 'banka_tms_ensure_notification_cron', 30);
add_action('banka_tms_pending_reminder_cron', 'banka_tms_process_pending_reminders');
add_action('banka_tms_pending_reminder_cron', 'banka_tms_process_daily_activity_report');


function banka_tms_send_pending_reminders_now($test_mode = false) {
    global $wpdb;
    $tasks = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}banka_tms_tasks WHERE status <> 'Completed' AND assignee_id IS NOT NULL ORDER BY assignee_id, project_name, due_date, id");
    $by_assignee = array();
    foreach ($tasks as $task) { $by_assignee[(int)$task->assignee_id][] = $task; }

    $summary = array('sent'=>0, 'failed'=>0, 'skipped'=>0, 'recipients'=>0);
    $seed_base = $test_mode ? ('test-' . banka_tms_ist_date('YmdHis')) : banka_tms_ist_date('Y-m-d');

    foreach ($by_assignee as $assignee_id=>$rows) {
        $assignee = banka_tms_app_get_user($assignee_id);
        if (!$assignee || empty($assignee->active)) { $summary['skipped']++; continue; }
        $projects = array();
        foreach ($rows as $task) { $projects[$task->project_name ?: 'Unassigned Project'][] = $task; }
        $message = 'Hi *' . $assignee->display_name . "*, here are your pending tasks for today:
";
        foreach ($projects as $project=>$project_tasks) {
            $message .= "
*Project: " . $project . "*
";
            $i=1;
            foreach ($project_tasks as $task) {
                $message .= $i . '. ' . $task->title . ' (Due: ' . banka_tms_format_date_message($task->due_date) . ")
";
                $i++;
            }
        }

        $summary['recipients']++;
        $did_attempt = false;
        if (banka_tms_notification_enabled('whatsapp_enabled')) {
            $did_attempt = true;
            $result = banka_tms_log_and_send('WhatsApp', $test_mode ? 'Pending Reminder Test' : 'Pending Reminder', 0, $assignee->display_name, $assignee->mobile ?? '', $message, $seed_base . '-' . $assignee_id . '-wa', 'banka_tms_send_mas_direct');
            if (!empty($result['success'])) { $summary['sent']++; } else { $summary['failed']++; }

            // WhatsApp Group receives only Pending Reminder and Daily Activity Report messages.
            if (banka_tms_notification_enabled('whatsapp_group_enabled')) {
                $group = trim((string)get_option('banka_tms_setting_whatsapp_group_id', ''));
                if ($group !== '') {
                    $group_result = banka_tms_log_and_send(
                        'WhatsApp Group',
                        $test_mode ? 'Pending Reminder Test' : 'Pending Reminder',
                        0,
                        'WhatsApp Group',
                        $group,
                        $message,
                        $seed_base . '-' . $assignee_id . '-wa-group',
                        'banka_tms_send_mas_group'
                    );
                    if (!empty($group_result['success'])) { $summary['sent']++; } else { $summary['failed']++; }
                }
            }
        }
        if (banka_tms_notification_enabled('telegram_enabled')) {
            $did_attempt = true;
            $chat = get_option('banka_tms_setting_telegram_reporting_group_chat_id', '');
            $result = banka_tms_log_and_send('Telegram', $test_mode ? 'Pending Reminder Test' : 'Pending Reminder', 0, 'Telegram Reporting Group', $chat, $message, $seed_base . '-' . $assignee_id . '-tg', 'banka_tms_send_telegram');
            if (!empty($result['success'])) { $summary['sent']++; } else { $summary['failed']++; }
        }
        if (!$did_attempt) { $summary['skipped']++; }
    }
    return $summary;
}



/**
 * Build and send the Daily Activity Report to configured reporting groups.
 * Only tasks whose latest non-created action log is today in IST are included.
 * Tasks whose current status is Pending / Not Yet Started are excluded.
 */
function banka_tms_send_daily_activity_report_now($test_mode = false) {
    global $wpdb;

    if (function_exists('banka_tms_ensure_action_log_table')) {
        banka_tms_ensure_action_log_table();
    }

    $task_table = $wpdb->prefix . 'banka_tms_tasks';
    $log_table = $wpdb->prefix . 'banka_tms_action_logs';
    $today = banka_tms_ist_date('Y-m-d');
    $start = $today . ' 00:00:00';
    $end_dt = banka_tms_ist_now()->modify('+1 day');
    $end = $end_dt->format('Y-m-d') . ' 00:00:00';

    $sql = $wpdb->prepare(
        "SELECT t.*, l.id AS log_id, l.action_name, l.remarks AS update_remarks, l.created_at AS last_update_date
         FROM {$task_table} t
         INNER JOIN {$log_table} l ON l.task_id = t.id
         INNER JOIN (
             SELECT task_id, MAX(id) AS max_id
             FROM {$log_table}
             WHERE action_name <> 'Created' AND created_at >= %s AND created_at < %s
             GROUP BY task_id
         ) latest ON latest.max_id = l.id
         WHERE LOWER(TRIM(COALESCE(t.status,''))) NOT IN ('pending','not yet started')
         ORDER BY t.assignee_id ASC, t.status ASC, l.created_at ASC, t.id ASC",
        $start,
        $end
    );
    $rows = $wpdb->get_results($sql);

    if (!$rows) {
        return array('sent'=>0, 'failed'=>0, 'rows'=>0, 'groups'=>0, 'message'=>'No qualifying task activity found for today.');
    }

    $grouped = array();
    foreach ($rows as $row) {
        $employee = 'Unassigned';
        if (!empty($row->assignee_id) && function_exists('banka_tms_app_get_user')) {
            $assignee = banka_tms_app_get_user((int)$row->assignee_id);
            if ($assignee && !empty($assignee->display_name)) {
                $employee = $assignee->display_name;
            }
        }
        $status = trim((string)$row->status);
        if ($status === '' || strcasecmp($status, 'Pending') === 0 || strcasecmp($status, 'Not Yet Started') === 0) {
            continue;
        }
        $grouped[$employee][$status][] = $row;
    }

    if (!$grouped) {
        return array('sent'=>0, 'failed'=>0, 'rows'=>0, 'groups'=>0, 'message'=>'No qualifying task activity found for today.');
    }

    ksort($grouped, SORT_NATURAL | SORT_FLAG_CASE);
    $message = "*Daily Activity Report*
*Date:* " . banka_tms_ist_date('d/m/Y') . "
";
    $row_count = 0;
    foreach ($grouped as $employee => $statuses) {
        $message .= "
*Employee: " . strtoupper($employee) . "*
";
        foreach ($statuses as $status => $tasks) {
            $message .= "
*" . $status . "*

";
            $i = 1;
            foreach ($tasks as $task) {
                $project = function_exists('banka_tms_project_display_label')
                    ? banka_tms_project_display_label((string)$task->project_name)
                    : (trim((string)$task->project_name) !== '' ? trim((string)$task->project_name) : 'General Project');
                $remark = trim((string)$task->update_remarks);
                if ($remark === '') { $remark = '-'; }
                $message .= $i . '. ' . trim((string)$task->title) . "
";
                $message .= '   Project: ' . $project . "
";
                $message .= '   Remark: ' . $remark . "
";
                $i++;
                $row_count++;
            }
        }
    }

    $summary = array('sent'=>0, 'failed'=>0, 'rows'=>$row_count, 'groups'=>0, 'message'=>$message);
    $seed = $test_mode ? ('test-' . banka_tms_ist_date('YmdHis')) : $today;

    if (banka_tms_notification_enabled('whatsapp_enabled') && banka_tms_notification_enabled('whatsapp_group_enabled')) {
        $group_id = trim((string)get_option('banka_tms_setting_whatsapp_group_id', ''));
        if ($group_id !== '') {
            $summary['groups']++;
            $result = banka_tms_log_and_send(
                'WhatsApp Group',
                $test_mode ? 'Daily Activity Report Test' : 'Daily Activity Report',
                0,
                'WhatsApp Reporting Group',
                $group_id,
                $message,
                $seed . '-wa-group',
                'banka_tms_send_mas_group'
            );
            if (!empty($result['success'])) { $summary['sent']++; } else { $summary['failed']++; }
        }
    }

    if (banka_tms_notification_enabled('telegram_enabled') && banka_tms_notification_enabled('telegram_daily_activity_enabled', '1')) {
        $chat_id = trim((string)get_option('banka_tms_setting_telegram_reporting_group_chat_id', ''));
        if ($chat_id !== '') {
            $summary['groups']++;
            $result = banka_tms_log_and_send(
                'Telegram',
                $test_mode ? 'Daily Activity Report Test' : 'Daily Activity Report',
                0,
                'Telegram Reporting Group',
                $chat_id,
                $message,
                $seed . '-tg-group',
                'banka_tms_send_telegram'
            );
            if (!empty($result['success'])) { $summary['sent']++; } else { $summary['failed']++; }
        }
    }

    return $summary;
}

function banka_tms_process_daily_activity_report() {
    if (!banka_tms_notification_enabled('daily_activity_report_enabled')) { return; }
    $configured = get_option('banka_tms_setting_daily_activity_report_time', '20:00');
    $now = banka_tms_ist_now();
    $target = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i',
        $now->format('Y-m-d') . ' ' . $configured,
        banka_tms_ist_timezone()
    );
    if (!$target || $now < $target) { return; }
    banka_tms_send_daily_activity_report_now(false);
}

function banka_tms_process_pending_reminders() {
    if (!banka_tms_notification_enabled('pending_reminder_enabled')) { return; }
    $configured = get_option('banka_tms_setting_pending_tasks_reminder_time', '10:00');
    $now = banka_tms_ist_now();
    $target = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i',
        $now->format('Y-m-d') . ' ' . $configured,
        banka_tms_ist_timezone()
    );
    // Send on the first scheduler check at or after the configured IST time.
    // Duplicate protection in the notification log prevents repeat delivery.
    if (!$target || $now < $target) { return; }
    banka_tms_send_pending_reminders_now(false);
}

/**
 * Browser-assisted scheduler.
 * Every open TaskPro page pings this endpoint once per minute. This removes
 * the need for users to access Hostinger just to trigger WP-Cron. The normal
 * WP-Cron event remains as a fallback.
 */
function banka_tms_app_run_internal_scheduler_ajax() {
    check_ajax_referer('banka_tms_app_nonce', 'nonce');
    if (!function_exists('banka_tms_app_is_logged_in') || !banka_tms_app_is_logged_in()) {
        wp_send_json_error(array('message' => 'Session expired.'), 403);
    }

    update_option('banka_tms_internal_scheduler_last_check', banka_tms_ist_mysql(), false);
    banka_tms_process_pending_reminders();
    banka_tms_process_daily_activity_report();

    wp_send_json_success(array(
        'checked_at' => banka_tms_ist_date('d/m/Y H:i'),
        'next_cron' => wp_next_scheduled('banka_tms_pending_reminder_cron'),
    ));
}
add_action('wp_ajax_banka_tms_app_run_internal_scheduler', 'banka_tms_app_run_internal_scheduler_ajax');
add_action('wp_ajax_nopriv_banka_tms_app_run_internal_scheduler', 'banka_tms_app_run_internal_scheduler_ajax');
