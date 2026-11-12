<?php
if (!defined('ABSPATH')) { exit; }

banka_tms_app_require_login();

global $post, $wpdb;
$page_slug = $post ? $post->post_name : 'tms-dashboard';
$current_user = banka_tms_app_current_user();
$is_admin_user = banka_tms_app_is_admin_user($current_user);
$restricted_employee_pages = array('tms-users','tms-designations','tms-categories','tms-sites','tms-settings','tms-notification-log');
if (!$is_admin_user && in_array($page_slug, $restricted_employee_pages, true)) {
    wp_safe_redirect(home_url('/tms-dashboard/'));
    exit;
}
$status_query = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
$app_organization_name = trim((string) get_option('banka_tms_setting_organization_name', ''));
$app_organization_logo = trim((string) get_option('banka_tms_setting_organization_logo', ''));

// Shared page-message defaults used by Users and master views.
$user_message = '';
$user_message_type = '';

// User modal data must be available before the shared modal markup is rendered.
$edit_user_id = ($page_slug === 'tms-users') ? absint($_GET['edit_user'] ?? 0) : 0;
$edit_user = $edit_user_id ? banka_tms_app_get_user($edit_user_id) : false;
$mobile_user_id = ($page_slug === 'tms-users') ? absint($_GET['update_mobile'] ?? 0) : 0;
$mobile_user = $mobile_user_id ? banka_tms_app_get_user($mobile_user_id) : false;
$designation_rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}banka_tms_designations ORDER BY name ASC");
$user_category_rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}banka_tms_categories ORDER BY name ASC");

// Always serve fresh TaskPro application pages.
nocache_headers();
if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }

// Always use the real Users page permalink instead of a hard-coded path.
$users_page_id = (int) get_option('banka_tms_app_users_page_id', 0);
if (!$users_page_id || get_post_status($users_page_id) !== 'publish') {
    banka_tms_app_create_required_pages();
    $users_page_id = (int) get_option('banka_tms_app_users_page_id', 0);
}
$users_page_url = $users_page_id ? get_permalink($users_page_id) : home_url('/tms-users/');
if (!$users_page_url) { $users_page_url = home_url('/tms-users/'); }

function banka_tms_set_user_flash($message, $type = 'success') {
    $payload = base64_encode(wp_json_encode(array('message' => (string) $message, 'type' => (string) $type)));
    setcookie('banka_tms_user_flash', $payload, time() + 120, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true);
    $_COOKIE['banka_tms_user_flash'] = $payload;
}

// Reliable server-side Category save/update/delete. This does not depend on AJAX.
if ($page_slug === 'tms-categories' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['banka_tms_category_action'])) {
    if (!current_user_can('manage_options')) {
        wp_die('Access denied');
    }
    check_admin_referer('banka_tms_category_form', 'banka_tms_category_nonce');

    $category_table = $wpdb->prefix . 'banka_tms_categories';
    $category_action = sanitize_key(wp_unslash($_POST['banka_tms_category_action']));
    $category_id = absint($_POST['category_id'] ?? 0);
    $category_name = sanitize_text_field(wp_unslash($_POST['category_name'] ?? ''));
    $message = '';
    $message_type = 'success';

    if ($category_action === 'save') {
        if ($category_name === '') {
            $message = 'Category name is required.';
            $message_type = 'error';
        } else {
            $duplicate = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$category_table} WHERE name = %s AND id != %d LIMIT 1",
                $category_name,
                $category_id
            ));
            if ($duplicate) {
                $message = 'This category already exists.';
                $message_type = 'error';
            } elseif ($category_id) {
                $result = $wpdb->update($category_table, array('name' => $category_name), array('id' => $category_id), array('%s'), array('%d'));
                if ($result === false) {
                    $message = 'Unable to update category: ' . $wpdb->last_error;
                    $message_type = 'error';
                } else {
                    $message = 'Category updated successfully.';
                }
            } else {
                $result = $wpdb->insert($category_table, array('name' => $category_name), array('%s'));
                if ($result === false) {
                    $message = 'Unable to add category: ' . $wpdb->last_error;
                    $message_type = 'error';
                } else {
                    $message = 'Category added successfully.';
                }
            }
        }
    } elseif ($category_action === 'delete') {
        if (!$category_id) {
            $message = 'Invalid category.';
            $message_type = 'error';
        } else {
            $result = $wpdb->delete($category_table, array('id' => $category_id), array('%d'));
            if ($result === false) {
                $message = 'Unable to delete category: ' . $wpdb->last_error;
                $message_type = 'error';
            } else {
                $message = 'Category deleted successfully.';
            }
        }
    }

    $redirect_url = add_query_arg(array(
        'tms_refresh' => time(),
        'cat_message' => rawurlencode($message),
        'cat_type' => $message_type,
    ), home_url('/tms-categories/'));
    wp_safe_redirect($redirect_url);
    exit;
}

// Reliable server-side User save/delete. This works for TaskPro users even without a WordPress login.
if ($page_slug === 'tms-users' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['banka_tms_user_action'])) {
    if (!banka_tms_app_can_manage()) {
        wp_die('Access denied');
    }
    check_admin_referer('banka_tms_user_form', 'banka_tms_user_nonce');

    $user_action = sanitize_key(wp_unslash($_POST['banka_tms_user_action']));
    $user_id = absint($_POST['user_id'] ?? 0);
    $message = '';
    $message_type = 'success';
    $users_table = banka_tms_app_users_table();

    if ($user_action === 'update_mobile') {
        if (!$user_id) {
            $message = 'Invalid system user.';
            $message_type = 'error';
        } else {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$users_table} WHERE user_id=%d LIMIT 1", $user_id));
            $mobile = preg_replace('/\D+/', '', (string) wp_unslash($_POST['mobile'] ?? ''));
            if (!$row || !banka_tms_app_is_protected_wp_admin($row)) {
                $message = 'Only the locked system administrator mobile number can be updated here.';
                $message_type = 'error';
            } elseif (strlen($mobile) !== 10) {
                $message = 'Please enter a valid 10-digit mobile number.';
                $message_type = 'error';
            } else {
                $updated = $wpdb->update($users_table, array('mobile' => $mobile), array('user_id' => $user_id), array('%s'), array('%d'));
                if ($updated === false) {
                    $message = 'Unable to update mobile number: ' . $wpdb->last_error;
                    $message_type = 'error';
                } else {
                    if (!empty($row->wp_user_id)) {
                        update_user_meta((int) $row->wp_user_id, 'banka_tms_mobile', $mobile);
                    }
                    $message = 'System administrator mobile number updated successfully.';
                }
            }
        }
    } elseif ($user_action === 'delete') {
        if (!$user_id) {
            $message = 'Invalid user.';
            $message_type = 'error';
        } else {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$users_table} WHERE user_id=%d LIMIT 1", $user_id));
            if (!$row) {
                $message = 'Invalid user.';
                $message_type = 'error';
            } elseif (banka_tms_app_is_protected_wp_admin($row)) {
                $message = 'The original WordPress administrator account is locked and cannot be deleted.';
                $message_type = 'error';
            } else {
                $deleted = $wpdb->delete($users_table, array('user_id' => $user_id), array('%d'));
                if ($deleted === false) {
                    $message = 'Unable to delete user: ' . $wpdb->last_error;
                    $message_type = 'error';
                } else {
                    $message = 'User deleted successfully.';
                }
            }
        }
    } elseif ($user_action === 'save') {
        $name = sanitize_text_field(wp_unslash($_POST['user_full_name'] ?? ''));
        $login = sanitize_user(wp_unslash($_POST['login'] ?? ''), true);
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $mobile = sanitize_text_field(wp_unslash($_POST['mobile'] ?? ''));
        $designation = sanitize_text_field(wp_unslash($_POST['designation'] ?? ''));
        $category = sanitize_text_field(wp_unslash($_POST['category'] ?? ''));
        $role_key = sanitize_key(wp_unslash($_POST['role'] ?? 'subscriber'));
        $password = (string) wp_unslash($_POST['password'] ?? '');
        $role_labels = array('subscriber' => 'Employee', 'editor' => 'Manager', 'administrator' => 'Admin');
        $role = $role_labels[$role_key] ?? 'Employee';

        if ($name === '' || $email === '' || (!$user_id && ($login === '' || $password === ''))) {
            $message = $user_id ? 'Name and email are required.' : 'Name, username, email and password are required.';
            $message_type = 'error';
        } elseif (!is_email($email)) {
            $message = 'Please enter a valid email address.';
            $message_type = 'error';
        } elseif ($mobile !== '' && !preg_match('/^[0-9]{10}$/', $mobile)) {
            $message = 'Mobile number must contain exactly 10 digits.';
            $message_type = 'error';
        } elseif ($user_id) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$users_table} WHERE user_id=%d LIMIT 1", $user_id));
            if (!$row) {
                $message = 'Invalid user.';
                $message_type = 'error';
            } elseif (banka_tms_app_is_protected_wp_admin($row)) {
                $message = 'The original WordPress administrator account is locked and cannot be edited.';
                $message_type = 'error';
            } else {
                $duplicate = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$users_table} WHERE email=%s AND user_id<>%d LIMIT 1", $email, $user_id));
                if ($duplicate) {
                    $message = 'This email already exists.';
                    $message_type = 'error';
                } else {
                    $data = array('name'=>$name,'email'=>$email,'role'=>$role,'mobile'=>$mobile,'designation'=>$designation,'category'=>$category);
                    $formats = array('%s','%s','%s','%s','%s','%s');
                    if ($password !== '') { $data['password_hash'] = wp_hash_password($password); $formats[] = '%s'; }
                    $updated = $wpdb->update($users_table, $data, array('user_id'=>$user_id), $formats, array('%d'));
                    if ($updated === false) {
                        $message = 'Unable to update user: ' . $wpdb->last_error;
                        $message_type = 'error';
                    } else {
                        if (banka_tms_app_is_protected_wp_admin($row) && $role_key === 'administrator') {
                            $wp_data = array('ID'=>(int)$row->wp_user_id,'display_name'=>$name,'user_email'=>$email);
                            if ($password !== '') { $wp_data['user_pass'] = $password; }
                            wp_update_user($wp_data);
                        }
                        $message = 'User updated successfully.';
                    }
                }
            }
        } else {
            $duplicate = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$users_table} WHERE username=%s OR email=%s LIMIT 1", $login, $email));
            if ($duplicate) {
                $message = 'This username or email already exists.';
                $message_type = 'error';
            } else {
                $next_id = max(1, (int)$wpdb->get_var("SELECT COALESCE(MAX(user_id),0)+1 FROM {$users_table}"));
                $inserted = $wpdb->insert($users_table, array(
                    'user_id'=>$next_id,'wp_user_id'=>null,'name'=>$name,'username'=>$login,'email'=>$email,
                    'password_hash'=>wp_hash_password($password),'role'=>$role,'designation'=>$designation,
                    'category'=>$category,'mobile'=>$mobile,'active'=>1,
                ), array('%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%d'));
                if ($inserted === false) {
                    $message = 'Unable to create user: ' . $wpdb->last_error;
                    $message_type = 'error';
                } else {
                    $message = 'User created successfully.';
                }
            }
        }
    }

    // Keep the response on the current Users page. This avoids theme/rewrite 404s
    // caused by redirecting to a stale or regenerated page permalink.
    $user_message = $message;
    $user_message_type = $message_type;
    if ($message_type !== 'error') {
        $edit_user_id = 0;
        $edit_user = false;
    }
}

// Reliable server-side Settings save.
if ($page_slug === 'tms-settings' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['banka_tms_settings_action'])) {
    if (!current_user_can('manage_options')) {
        wp_die('Access denied');
    }
    check_admin_referer('banka_tms_settings_form', 'banka_tms_settings_nonce');

    $project_prefix = strtoupper(sanitize_text_field(wp_unslash($_POST['project_prefix'] ?? 'P')));
    $project_prefix = preg_replace('/[^A-Z0-9]+/', '', $project_prefix);
    if ($project_prefix === '') {
        $project_prefix = 'P';
    }
    $organization_logo = esc_url_raw(wp_unslash($_POST['organization_logo'] ?? get_option('banka_tms_setting_organization_logo', '')));

    if (!empty($_FILES['organization_logo_file']['name'])) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $uploaded_logo = wp_handle_upload($_FILES['organization_logo_file'], array('test_form' => false));
        if (!empty($uploaded_logo['error'])) {
            $redirect_url = add_query_arg(array(
                'tms_refresh' => time(),
                'settings_message' => rawurlencode('Logo upload failed: ' . $uploaded_logo['error']),
                'settings_type' => 'error',
            ), home_url('/tms-settings/'));
            wp_safe_redirect($redirect_url);
            exit;
        }
        if (!empty($uploaded_logo['url'])) {
            $organization_logo = esc_url_raw($uploaded_logo['url']);
        }
    }
    $mas_username = sanitize_text_field(wp_unslash($_POST['mas_username'] ?? ''));
    $mas_password = sanitize_text_field(wp_unslash($_POST['mas_password'] ?? ''));
    $whatsapp_group_id = sanitize_text_field(wp_unslash($_POST['whatsapp_group_id'] ?? ''));
    $whatsapp_enabled = !empty($_POST['whatsapp_enabled']) ? '1' : '0';
    $whatsapp_group_enabled = !empty($_POST['whatsapp_group_enabled']) ? '1' : '0';
    $meta_whatsapp_enabled = !empty($_POST['meta_whatsapp_enabled']) ? '1' : '0';
    $meta_phone_number_id = sanitize_text_field(wp_unslash($_POST['meta_phone_number_id'] ?? ''));
    $meta_business_account_id = sanitize_text_field(wp_unslash($_POST['meta_business_account_id'] ?? ''));
    $meta_access_token = sanitize_text_field(wp_unslash($_POST['meta_access_token'] ?? ''));
    $meta_api_version = sanitize_text_field(wp_unslash($_POST['meta_api_version'] ?? 'v23.0'));
    $meta_country_code = preg_replace('/\D+/', '', (string) wp_unslash($_POST['meta_country_code'] ?? '91'));
    $meta_assignment_enabled = !empty($_POST['meta_assignment_enabled']) ? '1' : '0';
    $meta_update_enabled = !empty($_POST['meta_update_enabled']) ? '1' : '0';
    $meta_assignment_template = sanitize_key(wp_unslash($_POST['meta_assignment_template'] ?? 'archtms_task_assignment'));
    $meta_assignment_language = sanitize_text_field(wp_unslash($_POST['meta_assignment_language'] ?? 'en'));
    $meta_update_template = sanitize_key(wp_unslash($_POST['meta_update_template'] ?? 'archtms_task_update'));
    $meta_update_language = sanitize_text_field(wp_unslash($_POST['meta_update_language'] ?? 'en'));
    $telegram_enabled = !empty($_POST['telegram_enabled']) ? '1' : '0';
    $pending_reminder_enabled = !empty($_POST['pending_reminder_enabled']) ? '1' : '0';
    $daily_activity_report_enabled = !empty($_POST['daily_activity_report_enabled']) ? '1' : '0';
    $telegram_bot_token = sanitize_text_field(wp_unslash($_POST['telegram_bot_token'] ?? ''));
    $telegram_reporting_group_chat_id = sanitize_text_field(wp_unslash($_POST['telegram_reporting_group_chat_id'] ?? ''));
    $telegram_daily_activity_enabled = !empty($_POST['telegram_daily_activity_enabled']) ? '1' : '0';
    $pending_tasks_reminder_time = sanitize_text_field(wp_unslash($_POST['pending_tasks_reminder_time'] ?? ''));
    $daily_activity_report_time = sanitize_text_field(wp_unslash($_POST['daily_activity_report_time'] ?? ''));

    if ($pending_tasks_reminder_time !== '' && !preg_match('/^\d{2}:\d{2}$/', $pending_tasks_reminder_time)) {
        $pending_tasks_reminder_time = '';
    }
    if ($daily_activity_report_time !== '' && !preg_match('/^\d{2}:\d{2}$/', $daily_activity_report_time)) {
        $daily_activity_report_time = '';
    }

    update_option('banka_tms_setting_project_prefix', $project_prefix);
    update_option('banka_tms_setting_organization_logo', $organization_logo);
    update_option('banka_tms_setting_mas_username', $mas_username);
    update_option('banka_tms_setting_mas_password', $mas_password);
    update_option('banka_tms_setting_whatsapp_group_id', $whatsapp_group_id);
    update_option('banka_tms_setting_whatsapp_enabled', $whatsapp_enabled);
    update_option('banka_tms_setting_whatsapp_group_enabled', $whatsapp_group_enabled);
    update_option('banka_tms_setting_meta_whatsapp_enabled', $meta_whatsapp_enabled);
    update_option('banka_tms_setting_meta_phone_number_id', $meta_phone_number_id);
    update_option('banka_tms_setting_meta_business_account_id', $meta_business_account_id);
    update_option('banka_tms_setting_meta_access_token', $meta_access_token);
    update_option('banka_tms_setting_meta_api_version', $meta_api_version ?: 'v23.0');
    update_option('banka_tms_setting_meta_country_code', $meta_country_code ?: '91');
    update_option('banka_tms_setting_meta_assignment_enabled', $meta_assignment_enabled);
    update_option('banka_tms_setting_meta_update_enabled', $meta_update_enabled);
    update_option('banka_tms_setting_meta_assignment_template', $meta_assignment_template ?: 'archtms_task_assignment');
    update_option('banka_tms_setting_meta_assignment_language', $meta_assignment_language ?: 'en');
    update_option('banka_tms_setting_meta_update_template', $meta_update_template ?: 'archtms_task_update');
    update_option('banka_tms_setting_meta_update_language', $meta_update_language ?: 'en');
    update_option('banka_tms_setting_telegram_enabled', $telegram_enabled);
    update_option('banka_tms_setting_pending_reminder_enabled', $pending_reminder_enabled);
    update_option('banka_tms_setting_daily_activity_report_enabled', $daily_activity_report_enabled);
    update_option('banka_tms_setting_telegram_bot_token', $telegram_bot_token);
    update_option('banka_tms_setting_telegram_reporting_group_chat_id', $telegram_reporting_group_chat_id);
    update_option('banka_tms_setting_telegram_daily_activity_enabled', $telegram_daily_activity_enabled);
    update_option('banka_tms_setting_pending_tasks_reminder_time', $pending_tasks_reminder_time ?: '10:00');
    update_option('banka_tms_setting_daily_activity_report_time', $daily_activity_report_time ?: '20:00');

    $settings_message = 'Settings saved successfully.';
    $settings_type = 'success';
    if (!empty($_POST['banka_tms_test_pending_reminder'])) {
        if (!function_exists('banka_tms_send_pending_reminders_now')) {
            $settings_message = 'Settings saved, but the test reminder function is unavailable.';
            $settings_type = 'error';
        } else {
            $test_result = banka_tms_send_pending_reminders_now(true);
            if ((int)$test_result['recipients'] === 0) {
                $settings_message = 'No active assignee has a pending task for testing.';
                $settings_type = 'error';
            } elseif ((int)$test_result['sent'] > 0 && (int)$test_result['failed'] === 0) {
                $settings_message = 'Test pending reminder sent successfully. Check Notification Log for details.';
            } elseif ((int)$test_result['sent'] > 0) {
                $settings_message = 'Test reminder completed with some failures. Check Notification Log.';
                $settings_type = 'error';
            } else {
                $settings_message = 'Test reminder could not be sent. Check Notification Log for the exact error.';
                $settings_type = 'error';
            }
        }
    }

    if (!empty($_POST['banka_tms_test_daily_activity'])) {
        if (!function_exists('banka_tms_send_daily_activity_report_now')) {
            $settings_message = 'Settings saved, but the activity report function is unavailable.';
            $settings_type = 'error';
        } else {
            $test_result = banka_tms_send_daily_activity_report_now(true);
            if ((int)$test_result['rows'] === 0) {
                $settings_message = 'No qualifying task activity found for today.';
                $settings_type = 'error';
            } elseif ((int)$test_result['groups'] === 0) {
                $settings_message = 'No reporting group is active or configured.';
                $settings_type = 'error';
            } elseif ((int)$test_result['sent'] > 0 && (int)$test_result['failed'] === 0) {
                $settings_message = 'Test activity report sent successfully. Check Notification Log for details.';
            } elseif ((int)$test_result['sent'] > 0) {
                $settings_message = 'Test activity report completed with some failures. Check Notification Log.';
                $settings_type = 'error';
            } else {
                $settings_message = 'Test activity report could not be sent. Check Notification Log for the exact error.';
                $settings_type = 'error';
            }
        }
    }

    $redirect_url = add_query_arg(array(
        'tms_refresh' => time(),
        'settings_message' => rawurlencode($settings_message),
        'settings_type' => $settings_type,
    ), home_url('/tms-settings/'));
    wp_safe_redirect($redirect_url);
    exit;
}

function banka_tms_icon($name, $class = '') {
    $icons = array(
        'dashboard' => '<svg viewBox="0 0 24 24" fill="none"><rect x="4" y="4" width="6" height="6" rx="1.2"/><rect x="14" y="4" width="6" height="6" rx="1.2"/><rect x="4" y="14" width="6" height="6" rx="1.2"/><rect x="14" y="14" width="6" height="6" rx="1.2"/></svg>',
        'tasks' => '<svg viewBox="0 0 24 24" fill="none"><rect x="4.5" y="4.5" width="15" height="15" rx="2"/><path d="m8 12 2.3 2.3L16.5 8"/></svg>',
        'pending' => '<svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8"/><path d="M12 7.5V12l3 2"/></svg>',
        'completed' => '<svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8"/><path d="m8.3 12.2 2.3 2.4 5.1-5.5"/></svg>',
        'users' => '<svg viewBox="0 0 24 24" fill="none"><circle cx="9" cy="8" r="3"/><path d="M3.8 18c.8-3 2.6-4.6 5.2-4.6S13.4 15 14.2 18"/><circle cx="17" cy="9" r="2.3"/><path d="M15.5 14.2c2.5-.4 4.2.9 4.8 3.1"/></svg>',
        'designations' => '<svg viewBox="0 0 24 24" fill="none"><rect x="4" y="7" width="16" height="12" rx="2"/><path d="M9 7V5.8A1.8 1.8 0 0 1 10.8 4h2.4A1.8 1.8 0 0 1 15 5.8V7M4 11h16M10 11v2h4v-2"/></svg>',
        'categories' => '<svg viewBox="0 0 24 24" fill="none"><path d="M4 11.2 11.2 4H18l2 2v6.8L12.8 20 4 11.2Z"/><circle cx="16.2" cy="7.8" r="1.1"/></svg>',
        'sites' => '<svg viewBox="0 0 24 24" fill="none"><path d="M4 20h16M6 20V9l6-5 6 5v11M9 20v-6h6v6"/></svg>',
        'vendors' => '<svg viewBox="0 0 24 24" fill="none"><path d="M4 9h16l-1-4H5L4 9Z"/><path d="M5 9v10h14V9M9 19v-5h6v5"/><path d="M4 9c0 1.5 1 2.5 2.3 2.5S8.7 10.5 8.7 9c0 1.5 1 2.5 2.3 2.5s2.3-1 2.3-2.5c0 1.5 1 2.5 2.3 2.5S18 10.5 18 9"/></svg>',
        'recurring' => '<svg viewBox="0 0 24 24" fill="none"><path d="M18.5 8A7 7 0 0 0 6.3 6.2L4 8.5M5.5 16A7 7 0 0 0 17.7 17.8L20 15.5"/><path d="M4 4.5v4h4M20 19.5v-4h-4"/></svg>',
        'log' => '<svg viewBox="0 0 24 24" fill="none"><path d="M7 4h10a2 2 0 0 1 2 2v14H7a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z"/><path d="M8.5 9h7M8.5 13h7M8.5 17h4.5"/></svg>',
        'settings' => '<svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="3"/><path d="M19 13.5v-3l-2-.6a7 7 0 0 0-.7-1.7l1-1.8-2.1-2.1-1.8 1a7 7 0 0 0-1.7-.7L10.5 3h-3l-.6 2a7 7 0 0 0-1.7.7l-1.8-1-2.1 2.1 1 1.8a7 7 0 0 0-.7 1.7L0 10.5v3l2 .6a7 7 0 0 0 .7 1.7l-1 1.8 2.1 2.1 1.8-1a7 7 0 0 0 1.7.7l.6 2h3l.6-2a7 7 0 0 0 1.7-.7l1.8 1 2.1-2.1-1-1.8a7 7 0 0 0 .7-1.7l2-.6Z" transform="translate(1.5 -.5) scale(.88)"/></svg>',
        'total_tasks' => '<svg viewBox="0 0 24 24" fill="none"><rect x="5" y="4" width="14" height="16" rx="2"/><path d="m8.5 12 2.2 2.2 4.8-5.1"/></svg>',
        'overdue' => '<svg viewBox="0 0 24 24" fill="none"><path d="M12 4.5 20 19H4L12 4.5Z"/><path d="M12 9.3v4.2M12 16.5h.01"/></svg>',
        'plus' => '<svg viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14"/></svg>',
        'file' => '<svg viewBox="0 0 24 24" fill="none"><path d="M8 4h6l4 4v12H8z"/><path d="M14 4v4h4"/></svg>',
        'search' => '<svg viewBox="0 0 24 24" fill="none"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4 4"/></svg>',
        'filter' => '<svg viewBox="0 0 24 24" fill="none"><path d="M4 6h16M7 12h10M10 18h4"/></svg>',
        'calendar' => '<svg viewBox="0 0 24 24" fill="none"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3.8v3.5M16 3.8v3.5M4 9.2h16"/></svg>',
        'edit' => '<svg viewBox="0 0 24 24" fill="none"><path d="m4 20 4.1-1 9.1-9.1-3.1-3.1L5 15.9 4 20Z"/><path d="m12.9 5 3.1 3.1"/></svg>',
        'info' => '<svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="8"/><path d="M12 10.5v4.7M12 8.2h.01"/></svg>',
        'trash' => '<svg viewBox="0 0 24 24" fill="none"><path d="M4.5 7.5h15"/><path d="M9 4.8h6l.6 2.7H8.4L9 4.8Z"/><path d="M8 7.5v10.2a1.3 1.3 0 0 0 1.3 1.3h5.4a1.3 1.3 0 0 0 1.3-1.3V7.5"/><path d="M10.2 10.2v5.4M13.8 10.2v5.4"/></svg>',
        'toggle_on' => '<svg viewBox="0 0 24 24" fill="none"><rect x="3" y="7" width="18" height="10" rx="5"/><circle cx="15.8" cy="12" r="3.2" fill="currentColor" stroke="none"/></svg>',
        'toggle_off' => '<svg viewBox="0 0 24 24" fill="none"><rect x="3" y="7" width="18" height="10" rx="5"/><circle cx="8.2" cy="12" r="3.2" fill="currentColor" stroke="none"/></svg>',
        'user_add' => '<svg viewBox="0 0 24 24" fill="none"><path d="M9 11.2a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4Zm0 0c-2.4 0-4.6 1.2-5.4 3.3M17 7v6M14 10h6"/></svg>',
        'phone' => '<svg viewBox="0 0 24 24" fill="none"><path d="M7.2 4.5 9.4 8l-1.7 1.7c1.2 2.5 3.1 4.4 5.6 5.6l1.7-1.7 3.5 2.2-.7 3c-.2.7-.8 1.2-1.5 1.2C9.5 20 4 14.5 4 7.7c0-.7.5-1.3 1.2-1.5l2-.7Z"/></svg>'
    );
    if (!isset($icons[$name])) { return ''; }
    return '<span class="banka-tms-icon ' . esc_attr($class) . '" aria-hidden="true">' . $icons[$name] . '</span>';
}

function banka_tms_nav_item($slug, $label, $icon, $current_slug) {
    $active = $slug === $current_slug ? ' active' : '';
    printf(
        '<a class="banka-tms-nav-item%s" href="%s">%s<span>%s</span></a>',
        esc_attr($active),
        esc_url(home_url('/' . $slug . '/')),
        banka_tms_icon($icon, 'banka-tms-nav-icon'),
        esc_html($label)
    );
}

$task_table = $wpdb->prefix . 'banka_tms_tasks';
$task_scope_sql = '';
$task_scope_args = array();
if (!$is_admin_user) {
    $task_scope_sql = ' AND (assignee_id=%d OR owner_id=%d)';
    $taskpro_user_id = function_exists('banka_tms_app_current_user_id') ? (int) banka_tms_app_current_user_id() : (int) ($current_user->id ?? 0);
    $task_scope_args = array($taskpro_user_id, $taskpro_user_id);
}
$scoped_count = function($where = '1=1') use ($wpdb, $task_table, $task_scope_sql, $task_scope_args) {
    $sql = "SELECT COUNT(*) FROM {$task_table} WHERE {$where}{$task_scope_sql}";
    return (int) ($task_scope_args ? $wpdb->get_var($wpdb->prepare($sql, $task_scope_args)) : $wpdb->get_var($sql));
};
$total_tasks = $scoped_count();
$pending_tasks = $scoped_count("status <> 'Completed'");
$not_started_tasks = $scoped_count("status IN ('Pending','Not Yet Started')");
$in_progress_tasks = $scoped_count("status='In Progress'");
$completed_tasks = $scoped_count("status='Completed'");
$dashboard_user_rows = banka_tms_app_get_users(array('orderby' => 'display_name', 'order' => 'ASC'));
$dashboard_user_rows = array_values(array_filter($dashboard_user_rows, function($user_row) {
    return !banka_tms_app_is_protected_wp_admin((object) array('wp_user_id' => $user_row->wp_user_id ?? 0));
}));
$total_users = count($dashboard_user_rows);
$overdue_where = "status <> 'Completed' AND due_date IS NOT NULL AND due_date <> '' AND due_date < %s";
$overdue_sql = "SELECT COUNT(*) FROM {$task_table} WHERE {$overdue_where}{$task_scope_sql}";
$overdue_args = array_merge(array(banka_tms_ist_date('Y-m-d')), $task_scope_args);
$overdue_tasks = (int) $wpdb->get_var($wpdb->prepare($overdue_sql, $overdue_args));

if ($is_admin_user) {
    // Admin dashboard: count each unique pending task for every employee who is
    // either the Assignee or the Owner. UNION removes a duplicate when the same
    // employee is both Assignee and Owner on one task.
    $assignee_sql = "SELECT employee_id AS assignee_id,
        COUNT(*) AS total_pending,
        SUM(CASE WHEN status IN ('Pending','Not Yet Started') THEN 1 ELSE 0 END) AS not_started,
        SUM(CASE WHEN status='In Progress' THEN 1 ELSE 0 END) AS in_progress
        FROM (
            SELECT id AS task_id, assignee_id AS employee_id, status
            FROM {$task_table}
            WHERE status IN ('Pending','Not Yet Started','In Progress')
              AND assignee_id IS NOT NULL AND assignee_id > 0
            UNION
            SELECT id AS task_id, owner_id AS employee_id, status
            FROM {$task_table}
            WHERE status IN ('Pending','Not Yet Started','In Progress')
              AND owner_id IS NOT NULL AND owner_id > 0
        ) AS employee_pending_tasks
        GROUP BY employee_id
        ORDER BY total_pending DESC";
    $assignee_rows = $wpdb->get_results($assignee_sql);
} else {
    // Employee dashboard: count every pending task where the logged-in user is
    // either Assignee or Owner. A task matching both roles is counted only once.
    $taskpro_user_id = function_exists('banka_tms_app_current_user_id') ? (int) banka_tms_app_current_user_id() : 0;
    $employee_pending_sql = "SELECT %d assignee_id,
        COUNT(*) total_pending,
        SUM(CASE WHEN status IN ('Pending','Not Yet Started') THEN 1 ELSE 0 END) not_started,
        SUM(CASE WHEN status='In Progress' THEN 1 ELSE 0 END) in_progress
        FROM {$task_table}
        WHERE status IN ('Pending','Not Yet Started','In Progress')
          AND (assignee_id=%d OR owner_id=%d)";
    $employee_pending_row = $taskpro_user_id
        ? $wpdb->get_row($wpdb->prepare($employee_pending_sql, $taskpro_user_id, $taskpro_user_id, $taskpro_user_id))
        : null;
    $assignee_rows = ($employee_pending_row && (int) $employee_pending_row->total_pending > 0)
        ? array($employee_pending_row)
        : array();
}

$projectwise_sql = "SELECT COALESCE(NULLIF(TRIM(project_name),''),'Unassigned Project') project_name,
    COUNT(*) total_pending,
    SUM(CASE WHEN status IN ('Pending','Not Yet Started') THEN 1 ELSE 0 END) not_started,
    SUM(CASE WHEN status='In Progress' THEN 1 ELSE 0 END) in_progress
    FROM {$task_table}
    WHERE status IN ('Pending','Not Yet Started','In Progress'){$task_scope_sql}
    GROUP BY COALESCE(NULLIF(TRIM(project_name),''),'Unassigned Project')
    ORDER BY total_pending DESC, project_name ASC";
$projectwise_rows = $task_scope_args ? $wpdb->get_results($wpdb->prepare($projectwise_sql, $task_scope_args)) : $wpdb->get_results($projectwise_sql);
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class('banka-tms-app-body'); ?>>
<div class="banka-tms-shell">
    <aside class="banka-tms-sidebar" id="banka-tms-sidebar">
        <div class="banka-tms-brand">
            <div class="banka-tms-brand-logo-wrap">
                <img class="banka-tms-brand-logo" src="<?php echo esc_url(BANKA_TMS_APP_URL . 'assets/images/archtms-logo.png'); ?>" alt="ArchTMS">
            </div>
            <a class="banka-tms-brand-tagline" href="https://bizskilledu.com/" target="_blank" rel="noopener noreferrer">Automate with BizSkill</a>
        </div>

        <button class="banka-tms-close-sidebar" type="button" aria-label="Close menu">×</button>

        <nav class="banka-tms-nav">
            <?php banka_tms_nav_item('tms-dashboard', 'Dashboard', 'dashboard', $page_slug); ?>
            <a class="banka-tms-nav-item<?php echo ($page_slug === 'tms-tasks' && $status_query === '') ? ' active' : ''; ?>" href="<?php echo esc_url(home_url('/tms-tasks/')); ?>"><?php echo banka_tms_icon('tasks', 'banka-tms-nav-icon'); ?><span>All Tasks</span></a>
            <a class="banka-tms-nav-item<?php echo ($page_slug === 'tms-tasks' && $status_query === 'pending') ? ' active' : ''; ?>" href="<?php echo esc_url(home_url('/tms-tasks/?status=pending')); ?>"><?php echo banka_tms_icon('pending', 'banka-tms-nav-icon'); ?><span>Pending Tasks</span></a>
            <a class="banka-tms-nav-item<?php echo ($page_slug === 'tms-tasks' && $status_query === 'completed') ? ' active' : ''; ?>" href="<?php echo esc_url(home_url('/tms-tasks/?status=completed')); ?>"><?php echo banka_tms_icon('completed', 'banka-tms-nav-icon'); ?><span>Completed Tasks</span></a>
            <?php if ($is_admin_user) : ?>
                <?php banka_tms_nav_item('tms-users', 'Users', 'users', $page_slug); ?>
                <?php banka_tms_nav_item('tms-designations', 'Designations', 'designations', $page_slug); ?>
                <?php banka_tms_nav_item('tms-categories', 'Categories', 'categories', $page_slug); ?>
            <?php endif; ?>
            <?php banka_tms_nav_item('tms-task-master', 'Task Master', 'tasks', $page_slug); ?>
            <?php banka_tms_nav_item('tms-project-types', 'Project Types', 'sites', $page_slug); ?>
            <?php if ($is_admin_user) : ?><?php banka_tms_nav_item('tms-sites', 'Projects', 'sites', $page_slug); ?><?php endif; ?>
            <?php banka_tms_nav_item('tms-action-log', 'Action Log', 'log', $page_slug); ?>
            <?php if ($is_admin_user) : ?>
                <?php banka_tms_nav_item('tms-settings', 'Admin Settings', 'settings', $page_slug); ?>
                <?php banka_tms_nav_item('tms-notification-log', 'Notification Log', 'log', $page_slug); ?>
            <?php endif; ?>
        </nav>

        <?php
        $sidebar_user_name = '';
        $sidebar_user_email = '';

        // Resolve the sidebar identity directly from the active ArchTMS
        // session profile. This avoids stale/fallback WordPress identities and
        // guarantees that the employee who signed in is shown in the menu.
        $sidebar_session_user_id = function_exists('banka_tms_app_current_user_id')
            ? (int) banka_tms_app_current_user_id()
            : 0;

        if ($sidebar_session_user_id > 0) {
            $sidebar_profile = $wpdb->get_row($wpdb->prepare(
                "SELECT name, username, email FROM {$wpdb->prefix}banka_tms_users_profile WHERE user_id = %d LIMIT 1",
                $sidebar_session_user_id
            ));

            if ($sidebar_profile) {
                $sidebar_user_name = trim((string) ($sidebar_profile->name ?? ''));
                if ($sidebar_user_name === '') {
                    $sidebar_user_name = trim((string) ($sidebar_profile->username ?? ''));
                }
                $sidebar_user_email = trim((string) ($sidebar_profile->email ?? ''));
            }
        }

        // Safe fallback to the already authenticated ArchTMS user object.
        if ($sidebar_user_name === '' && $current_user) {
            $sidebar_user_name = trim((string) ($current_user->display_name ?? ''));
            if ($sidebar_user_name === '') {
                $sidebar_user_name = trim((string) ($current_user->user_login ?? ''));
            }
            $sidebar_user_email = trim((string) ($current_user->user_email ?? ''));
        }

        if ($sidebar_user_name === '') { $sidebar_user_name = 'ArchTMS User'; }
        ?>
        <div class="banka-tms-sidebar-footer">
            <div class="banka-tms-sidebar-identity">
                <div class="banka-tms-user-name"><?php echo esc_html($sidebar_user_name); ?></div>
                <?php if ($sidebar_user_email !== '') : ?>
                    <div class="banka-tms-user-email"><?php echo esc_html($sidebar_user_email); ?></div>
                <?php endif; ?>
            </div>
            <a class="banka-tms-signout" href="<?php echo esc_url(add_query_arg('banka_tms_logout','1',home_url('/'))); ?>"><span class="banka-tms-signout-icon">⇥</span><span class="banka-tms-signout-label">Sign Out</span></a>
            <div class="banka-tms-developed"><a href="https://bizskilledu.com/" target="_blank" rel="noopener noreferrer">Developed by <strong>BizSkill</strong></a></div>
        </div>
    </aside>

    <main class="banka-tms-content">
        <header class="banka-tms-app-header">
            <div class="banka-tms-header-menu-controls">
                <button class="banka-tms-sidebar-visibility-toggle" type="button" aria-label="Hide left menu" title="Hide Menu">
                    <span class="banka-tms-sidebar-toggle-icon" aria-hidden="true">‹</span>
                </button>
                <button class="banka-tms-menu-toggle" type="button" aria-label="Open menu"><span></span><span></span><span></span></button>
            </div>
            <div class="banka-tms-client-logo banka-tms-app-organization-logo">
                <?php if ($app_organization_logo !== '') : ?>
                    <img src="<?php echo esc_url($app_organization_logo); ?>" alt="<?php echo esc_attr($app_organization_name !== '' ? $app_organization_name : 'Organization Logo'); ?>" />
                <?php else : ?>
                    <span class="banka-logo-mark">▶</span><span><strong><?php echo esc_html($app_organization_name !== '' ? $app_organization_name : 'BANKA'); ?></strong><?php if ($app_organization_name === '') : ?><small>AND ASSOCIATES</small><?php endif; ?></span>
                <?php endif; ?>
            </div>
        </header>

        <?php if ($page_slug === 'tms-dashboard') : ?>
            <section class="banka-tms-dashboard">
                <h1 class="banka-tms-page-title">Dashboard</h1>

                <div class="banka-tms-card banka-tms-quick-card">
                    <h2>Quick Actions</h2>
                    <div class="banka-tms-quick-actions banka-tms-quick-actions-three">
                        <button type="button" class="banka-tms-btn blue" id="banka-tms-open-task-modal"><span class="banka-tms-svg-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg></span> New Task</button>
                        <?php if ($is_admin_user) : ?><button type="button" class="banka-tms-btn green" id="banka-tms-open-user-modal"><span class="banka-tms-svg-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M8.5 12.2a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4Zm0 0c-2.4 0-4.6 1.3-5.5 3.4M16.8 7.2v5.4M14.1 9.9h5.4" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg></span> Add User</button><?php endif; ?>
                        <?php if ($is_admin_user) : ?><button type="button" class="banka-tms-btn orange" id="banka-tms-open-project-modal"><span class="banka-tms-svg-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 8.5 12 4l8 4.5v9L12 22l-8-4.5v-9Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M12 12v10M4.4 8.7 12 13l7.6-4.3" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg></span> Add Project</button><?php endif; ?>
                        <?php if ($is_admin_user) : ?>
                        <a class="banka-tms-btn yellow" href="<?php echo esc_url(add_query_arg(array('action'=>'banka_tms_action_log_pdf','from'=>banka_tms_ist_date('Y-m-d'),'to'=>banka_tms_ist_date('Y-m-d'),'group'=>'assignee','report_title'=>"Today's Activity Report",'admin_only'=>'1'), admin_url('admin-post.php'))); ?>"><span class="banka-tms-svg-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M7 3v3M17 3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v14H4V6a1 1 0 0 1 1-1Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span> Today's Activity Report</a>
                        <button type="button" class="banka-tms-btn pink" id="banka-tms-open-activity-period-report"><span class="banka-tms-svg-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 5h16v15H4zM8 3v4M16 3v4M4 9h16" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span> Periodic Activity Reports</button>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($is_admin_user) : ?>
                <div class="banka-tms-modal" id="banka-tms-activity-period-modal" style="display:none">
                    <div class="banka-tms-modal-backdrop"></div>
                    <div class="banka-tms-modal-card banka-tms-modal-card-small">
                        <button type="button" class="banka-tms-modal-close" data-close-modal>×</button>
                        <h2 class="banka-tms-modal-title">Periodic Activity Reports</h2>
                        <form id="banka-tms-activity-period-form" class="banka-tms-form banka-tms-popup-form">
                            <label><span>From Date</span><input type="date" id="banka-tms-report-from" required></label>
                            <label><span>To Date</span><input type="date" id="banka-tms-report-to" required></label>
                            <div class="banka-tms-status" id="banka-tms-activity-period-status"></div>
                            <div class="banka-tms-modal-actions"><button type="button" class="banka-tms-secondary-btn" data-close-modal>Cancel</button><button type="submit" class="banka-tms-primary-btn">Download Report</button></div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <div class="banka-tms-stats-grid">
                    <a class="banka-tms-stat-card banka-tms-dashboard-link" href="<?php echo esc_url(home_url('/tms-tasks/')); ?>"><span class="banka-tms-stat-label">Total Tasks</span><strong class="banka-tms-stat-value"><?php echo esc_html($total_tasks); ?></strong></a>
                    <a class="banka-tms-stat-card banka-tms-dashboard-link" href="<?php echo esc_url(home_url('/tms-tasks/?status=pending')); ?>"><span class="banka-tms-stat-label">Pending Tasks</span><strong class="banka-tms-stat-value"><?php echo esc_html($pending_tasks); ?></strong></a>
                    <a class="banka-tms-stat-card banka-tms-dashboard-link" href="<?php echo esc_url(add_query_arg(array('status' => 'pending', 'overdue' => '1'), home_url('/tms-tasks/'))); ?>"><span class="banka-tms-stat-label">Overdue Tasks</span><strong class="banka-tms-stat-value"><?php echo esc_html($overdue_tasks); ?></strong></a>
                    <a class="banka-tms-stat-card banka-tms-dashboard-link" href="<?php echo esc_url(home_url('/tms-tasks/?status=completed')); ?>"><span class="banka-tms-stat-label">Completed Tasks</span><strong class="banka-tms-stat-value"><?php echo esc_html($completed_tasks); ?></strong></a>
                    <?php if ($is_admin_user) : ?><a class="banka-tms-stat-card banka-tms-dashboard-link" href="<?php echo esc_url($users_page_url); ?>"><span class="banka-tms-stat-label">Total Users</span><strong class="banka-tms-stat-value"><?php echo esc_html($total_users); ?></strong></a><?php endif; ?>
                </div>

                <div class="banka-tms-card banka-tms-report-card">
                    <h2>Pending Tasks</h2>
                    <div class="banka-tms-table-wrap">
                        <table class="banka-tms-table">
                            <thead><tr><th>Employee</th><th>Total Pending</th><th>Not Yet Started</th><th>In Progress</th></tr></thead>
                            <tbody>
                            <?php if ($assignee_rows) : foreach ($assignee_rows as $row) :
                                $employee = $row->assignee_id ? banka_tms_app_get_user((int)$row->assignee_id) : null;
                                $employee_name = $employee ? $employee->display_name : 'Unassigned'; ?>
                                <?php
                                if ($is_admin_user) {
                                    $assignee_base_url = add_query_arg(array('status' => 'pending', 'scope_user' => $employee_name), home_url('/tms-tasks/'));
                                    $assignee_not_started_url = add_query_arg(array('status' => 'pending', 'scope_user' => $employee_name, 'task_status' => 'Pending'), home_url('/tms-tasks/'));
                                    $assignee_in_progress_url = add_query_arg(array('status' => 'pending', 'scope_user' => $employee_name, 'task_status' => 'In Progress'), home_url('/tms-tasks/'));
                                } else {
                                    // Employee task pages already enforce Assignee OR Owner scope.
                                    $assignee_base_url = add_query_arg(array('status' => 'pending'), home_url('/tms-tasks/'));
                                    $assignee_not_started_url = add_query_arg(array('status' => 'pending', 'task_status' => 'Pending'), home_url('/tms-tasks/'));
                                    $assignee_in_progress_url = add_query_arg(array('status' => 'pending', 'task_status' => 'In Progress'), home_url('/tms-tasks/'));
                                }
                                ?>
                                <tr class="banka-tms-dashboard-report-row">
                                    <td><a class="banka-tms-dashboard-cell-link" href="<?php echo esc_url($assignee_base_url); ?>"><?php echo esc_html($employee_name); ?></a></td>
                                    <td><a class="banka-tms-dashboard-cell-link" href="<?php echo esc_url($assignee_base_url); ?>"><?php echo esc_html($row->total_pending); ?></a></td>
                                    <td><a class="banka-tms-dashboard-cell-link" href="<?php echo esc_url($assignee_not_started_url); ?>"><?php echo esc_html($row->not_started); ?></a></td>
                                    <td><a class="banka-tms-dashboard-cell-link" href="<?php echo esc_url($assignee_in_progress_url); ?>"><?php echo esc_html($row->in_progress); ?></a></td>
                                </tr>
                            <?php endforeach; else : ?>
                                <tr><td colspan="4" class="banka-tms-empty">No pending tasks to analyse.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="banka-tms-card banka-tms-report-card">
                    <h2>Projectwise Pending Tasks</h2>
                    <div class="banka-tms-table-wrap">
                        <table class="banka-tms-table">
                            <thead><tr><th>Project</th><th>Total Pending</th><th>Not Yet Started</th><th>In Progress</th></tr></thead>
                            <tbody>
                                <?php if ($projectwise_rows) : foreach ($projectwise_rows as $projectwise_row) :
                                    $project_label = function_exists('banka_tms_project_display_label') ? banka_tms_project_display_label($projectwise_row->project_name) : $projectwise_row->project_name;
                                ?>
                                <?php
                                $project_base_url = add_query_arg(array('status' => 'pending', 'project' => $project_label), home_url('/tms-tasks/'));
                                $project_not_started_url = add_query_arg(array('status' => 'pending', 'project' => $project_label, 'task_status' => 'Pending'), home_url('/tms-tasks/'));
                                $project_in_progress_url = add_query_arg(array('status' => 'pending', 'project' => $project_label, 'task_status' => 'In Progress'), home_url('/tms-tasks/'));
                                ?>
                                <tr class="banka-tms-dashboard-report-row">
                                    <td><a class="banka-tms-dashboard-cell-link" href="<?php echo esc_url($project_base_url); ?>"><?php echo esc_html($project_label); ?></a></td>
                                    <td><a class="banka-tms-dashboard-cell-link" href="<?php echo esc_url($project_base_url); ?>"><?php echo esc_html($projectwise_row->total_pending); ?></a></td>
                                    <td><a class="banka-tms-dashboard-cell-link" href="<?php echo esc_url($project_not_started_url); ?>"><?php echo esc_html($projectwise_row->not_started); ?></a></td>
                                    <td><a class="banka-tms-dashboard-cell-link" href="<?php echo esc_url($project_in_progress_url); ?>"><?php echo esc_html($projectwise_row->in_progress); ?></a></td>
                                </tr>
                                <?php endforeach; else : ?>
                                <tr><td colspan="4" class="banka-tms-empty">No pending project tasks to analyse.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
                <?php
                $project_type_options = $wpdb->get_col("SELECT name FROM {$wpdb->prefix}banka_tms_project_types ORDER BY name ASC");
                $project_category_options = $wpdb->get_col("SELECT name FROM {$wpdb->prefix}banka_tms_project_categories ORDER BY name ASC");
                ?>
            <div class="banka-tms-modal" id="banka-tms-task-modal" style="display:none">
                <div class="banka-tms-modal-backdrop"></div>
                <div class="banka-tms-modal-card banka-tms-modal-card-wide banka-tms-task-modal-card-exact">
                    <button type="button" class="banka-tms-modal-close" data-close-modal onclick="<?php echo $edit_user ? "window.location.href='" . esc_js($users_page_url) . "'" : ''; ?>">×</button>
                    <h2 class="banka-tms-modal-title" id="banka-tms-task-modal-title">Add Task</h2>
                    <form id="banka-tms-task-form" class="banka-tms-form banka-tms-popup-form banka-tms-task-form-grid banka-tms-task-form-grid-exact">
                        <input type="hidden" id="task-id" value="0" />
                        <input type="hidden" id="task-status" value="Pending" />

                        <div class="banka-tms-field-group banka-tms-field-with-plus banka-tms-task-field banka-tms-task-field-task">
                            <label>
                                <span>Task</span>
                                <select id="task-title" required>
                                    <option value="">Select a task...</option>
                                    <?php foreach ($wpdb->get_results("SELECT name FROM {$wpdb->prefix}banka_tms_task_master ORDER BY name ASC") as $task_master_row) : ?>
                                        <option value="<?php echo esc_attr($task_master_row->name); ?>"><?php echo esc_html($task_master_row->name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <button type="button" class="banka-tms-inline-plus" id="banka-tms-quick-add-task-master" aria-label="Add task to Task Master">+</button>
                        </div>

                        <label class="banka-tms-field-group banka-tms-task-field">
                            <span>Priority <em>*</em></span>
                            <select id="task-priority">
                                <option value="High">High</option>
                                <option value="Medium" selected>Medium</option>
                                <option value="Low">Low</option>
                            </select>
                        </label>

                        <label class="banka-tms-field-group banka-tms-task-field">
                            <span>Assignees <em>*</em></span>
                            <select id="task-assignee-id">
                                <option value="0">Select Assignees...</option>
                                <?php foreach (banka_tms_app_get_users(array('orderby' => 'display_name', 'order' => 'ASC')) as $u) : ?>
                                    <option value="<?php echo esc_attr($u->ID); ?>"><?php echo esc_html($u->display_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label class="banka-tms-field-group banka-tms-task-field">
                            <span>Task Owners <em>*</em></span>
                            <select id="task-owner-id">
                                <?php foreach (banka_tms_app_get_users(array('orderby' => 'display_name', 'order' => 'ASC')) as $u) : ?>
                                    <option value="<?php echo esc_attr($u->ID); ?>" <?php selected($u->ID, banka_tms_app_current_user_id()); ?>><?php echo esc_html($u->display_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <div class="banka-tms-field-group banka-tms-field-with-plus banka-tms-task-field">
                            <label>
                                <span>Category <em>*</em></span>
                                <select id="task-category">
                                    <option value="">Select Category...</option>
                                    <?php foreach ($wpdb->get_results("SELECT name FROM {$wpdb->prefix}banka_tms_categories ORDER BY name ASC") as $task_category_row) : ?>
                                        <option value="<?php echo esc_attr($task_category_row->name); ?>"><?php echo esc_html($task_category_row->name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <button type="button" class="banka-tms-inline-plus banka-tms-quick-add-category" aria-label="Add category">+</button>
                        </div>

                        <div class="banka-tms-field-group banka-tms-field-with-plus banka-tms-task-field">
                            <label>
                                <span>Project <em>*</em></span>
                                <select id="task-project">
                                    <option value="">Select Project...</option>
                                    <?php foreach ($wpdb->get_results("SELECT project_name, address FROM {$wpdb->prefix}banka_tms_projects ORDER BY project_name ASC") as $task_project_row) : ?>
                                        <?php $task_project_label = trim((string) $task_project_row->project_name) . (trim((string) $task_project_row->address) !== '' ? ' (' . trim((string) $task_project_row->address) . ')' : ''); ?>
                                        <option value="<?php echo esc_attr($task_project_label); ?>"><?php echo esc_html($task_project_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <button type="button" class="banka-tms-inline-plus banka-tms-quick-add-project" aria-label="Add project">+</button>
                        </div>

                        <label class="banka-tms-field-group banka-tms-field-half banka-tms-task-field banka-tms-task-due-date-field">
                            <span>Due Date <em>*</em></span>
                            <span class="banka-tms-date-input-wrap">
                                <input type="date" id="task-due-date" placeholder="dd-mm-yyyy" />
                                <button type="button" class="banka-tms-calendar-trigger" aria-label="Open calendar" title="Open calendar">📅</button>
                            </span>
                        </label>
                        <label class="banka-tms-field-group banka-tms-field-half banka-tms-task-field banka-tms-task-status-display is-hidden" id="banka-tms-task-status-field">
                            <span>Status</span>
                            <input type="text" id="task-status-display" value="" readonly />
                        </label>

                        <label class="banka-tms-field-group banka-tms-field-full banka-tms-task-field">
                            <span>Notes</span>
                            <textarea id="task-description" placeholder=""></textarea>
                        </label>

                        <div id="banka-tms-task-status" class="banka-tms-status banka-tms-field-full"></div>
                        <div class="banka-tms-modal-actions banka-tms-field-full"><button type="button" class="banka-tms-secondary-btn" data-close-modal>Cancel</button><button type="button" class="banka-tms-secondary-btn banka-tms-template-help-btn">Template</button><button type="submit" class="banka-tms-primary-btn" id="banka-tms-task-submit-btn">Create Task</button></div>
                    </form>
                </div>
            </div>


            <div class="banka-tms-modal" id="banka-tms-quick-task-master-modal" style="display:none">
                <div class="banka-tms-modal-backdrop"></div>
                <div class="banka-tms-modal-card banka-tms-quick-task-master-card">
                    <button type="button" class="banka-tms-modal-close" data-close-modal>×</button>
                    <h2 class="banka-tms-modal-title">Add New Task</h2>
                    <form id="banka-tms-quick-task-master-form" class="banka-tms-quick-update-form">
                        <label><span>Task Name</span><input type="text" id="banka-tms-quick-task-master-name" placeholder="Enter task name" required /></label>
                        <div id="banka-tms-quick-task-master-status" class="banka-tms-status"></div>
                        <div class="banka-tms-modal-actions"><button type="button" class="banka-tms-secondary-btn" data-close-modal>Cancel</button><button type="submit" class="banka-tms-primary-btn">Add Task</button></div>
                    </form>
                </div>
            </div>

            <div class="banka-tms-modal" id="banka-tms-quick-category-modal" style="display:none">
                <div class="banka-tms-modal-backdrop"></div>
                <div class="banka-tms-modal-card banka-tms-quick-task-master-card">
                    <button type="button" class="banka-tms-modal-close" data-quick-category-close>×</button>
                    <h2 class="banka-tms-modal-title">Add New Category</h2>
                    <form id="banka-tms-quick-category-form" class="banka-tms-quick-update-form">
                        <label><span>Category Name</span><input type="text" id="banka-tms-quick-category-name" placeholder="Enter category name" required /></label>
                        <div id="banka-tms-quick-category-status" class="banka-tms-status"></div>
                        <div class="banka-tms-modal-actions"><button type="button" class="banka-tms-secondary-btn" data-quick-category-close>Cancel</button><button type="submit" class="banka-tms-primary-btn">Add Category</button></div>
                    </form>
                </div>
            </div>

            <div class="banka-tms-modal" id="banka-tms-bulk-reassign-modal" style="display:none">
                <div class="banka-tms-modal-backdrop"></div>
                <div class="banka-tms-modal-card banka-tms-quick-update-card">
                    <button type="button" class="banka-tms-modal-close" data-close-modal>×</button>
                    <h2 class="banka-tms-modal-title">Reassign Selected Tasks</h2>
                    <form id="banka-tms-bulk-reassign-form" class="banka-tms-quick-update-form">
                        <label><span>Assignee</span><select id="banka-tms-bulk-assignee" required>
                            <option value="">Select Assignee...</option>
                            <?php foreach (banka_tms_app_get_users(array('orderby' => 'display_name', 'order' => 'ASC')) as $u) : ?>
                                <option value="<?php echo esc_attr($u->ID); ?>"><?php echo esc_html($u->display_name); ?></option>
                            <?php endforeach; ?>
                        </select></label>
                        <div id="banka-tms-bulk-status" class="banka-tms-status"></div>
                        <div class="banka-tms-modal-actions"><button type="button" class="banka-tms-secondary-btn" data-close-modal>Cancel</button><button type="submit" class="banka-tms-primary-btn">Reassign</button></div>
                    </form>
                </div>
            </div>

            <div class="banka-tms-modal" id="banka-tms-task-update-modal" style="display:none">
                <div class="banka-tms-modal-backdrop"></div>
                <div class="banka-tms-modal-card banka-tms-task-update-card">
                    <button type="button" class="banka-tms-modal-close" data-close-modal>×</button>
                    <h2 class="banka-tms-modal-title">Update Task</h2>
                    <form id="banka-tms-quick-update-form" class="banka-tms-quick-update-form">
                        <input type="hidden" id="quick-update-task-id" value="0" />
                        <label><span>Status <em>*</em></span><select id="quick-update-status" required>
                            <option value="Pending">Not Yet Started</option>
                            <option value="In Progress" selected>In Progress</option>
                            <option value="Completed">Completed</option>
                        </select></label>
                        <label><span>Remarks <em>*</em></span><textarea id="quick-update-remarks" placeholder="Add remarks..." required></textarea></label>
                        <label id="banka-tms-next-action-wrap" class="banka-tms-next-action-field"><span>Next Action Date</span><input type="date" id="quick-update-next-action-date" min="<?php echo esc_attr((new DateTimeImmutable('now', banka_tms_ist_timezone()))->modify('+1 day')->format('Y-m-d')); ?>" autocomplete="off" /></label>
                        <label class="banka-tms-reassign-field"><span>Re-Assign</span><select id="quick-update-assignee">
                            <option value="">Select assignee (keep current)</option>
                            <?php foreach (banka_tms_app_get_users(array('orderby' => 'display_name', 'order' => 'ASC')) as $u) : ?>
                                <option value="<?php echo esc_attr($u->ID); ?>"><?php echo esc_html($u->display_name); ?></option>
                            <?php endforeach; ?>
                        </select></label>
                        <div id="banka-tms-quick-update-status" class="banka-tms-status"></div>
                        <div class="banka-tms-modal-actions"><button type="button" class="banka-tms-secondary-btn" data-close-modal>Cancel</button><button type="submit" class="banka-tms-primary-btn">Update Task</button></div>
                    </form>
                </div>
            </div>

            <div class="banka-tms-modal" id="banka-tms-task-view-modal" style="display:none">
                <div class="banka-tms-modal-backdrop"></div>
                <div class="banka-tms-modal-card banka-tms-task-detail-card">
                    <button type="button" class="banka-tms-modal-close" data-close-modal>×</button>
                    <h2 class="banka-tms-modal-title" id="banka-tms-detail-title"></h2>
                    <div class="banka-tms-task-detail-grid-modern">
                        <div class="banka-tms-detail-item"><span class="banka-tms-detail-icon blue"><?php echo banka_tms_icon('info'); ?></span><div><small>Status</small><strong id="banka-tms-detail-status"></strong></div></div>
                        <div class="banka-tms-detail-item"><span class="banka-tms-detail-icon red"><?php echo banka_tms_icon('calendar'); ?></span><div><small>Due Date</small><strong id="banka-tms-detail-duedate"></strong></div></div>
                        <div class="banka-tms-detail-item"><span class="banka-tms-detail-icon orange"><?php echo banka_tms_icon('calendar'); ?></span><div><small>Next Action Date</small><strong id="banka-tms-detail-next-action-date"></strong></div></div>
                        <div class="banka-tms-detail-item"><span class="banka-tms-detail-icon green"><?php echo banka_tms_icon('users'); ?></span><div><small>Assignees</small><strong id="banka-tms-detail-assignee"></strong></div></div>
                        <div class="banka-tms-detail-item"><span class="banka-tms-detail-icon purple"><?php echo banka_tms_icon('users'); ?></span><div><small>Owners</small><strong id="banka-tms-detail-owner"></strong></div></div>
                        <div class="banka-tms-detail-item"><span class="banka-tms-detail-icon amber"><?php echo banka_tms_icon('categories'); ?></span><div><small>Category</small><strong id="banka-tms-detail-category"></strong></div></div>
                        <div class="banka-tms-detail-item"><span class="banka-tms-detail-icon violet"><?php echo banka_tms_icon('sites'); ?></span><div><small>Project</small><strong id="banka-tms-detail-project"></strong></div></div>
                    </div>
                    <div class="banka-tms-detail-section"><h3>Notes</h3><div class="banka-tms-detail-notes" id="banka-tms-detail-description"></div></div>
                    <div class="banka-tms-detail-section"><h3>Activity Log</h3><div class="banka-tms-detail-activity" id="banka-tms-detail-activity"><div class="banka-tms-detail-loading">Loading activity...</div></div></div>
                </div>
            </div>
            <?php if ($page_slug !== 'tms-users') : ?>
            <div class="banka-tms-modal" id="banka-tms-user-modal" style="<?php echo $edit_user ? 'display:flex' : 'display:none'; ?>">
                <div class="banka-tms-modal-backdrop"></div>
                <div class="banka-tms-modal-card banka-tms-user-modal-card">
                    <button type="button" class="banka-tms-modal-close" data-close-modal>×</button>
                    <h2 class="banka-tms-modal-title" id="banka-tms-user-modal-title"><?php echo $edit_user ? 'Edit User' : 'Pre-register User'; ?></h2>
                    <form id="banka-tms-user-form" class="banka-tms-form banka-tms-user-form-grid" method="post">
                        <?php wp_nonce_field('banka_tms_user_form', 'banka_tms_user_nonce'); ?>
                        <input type="hidden" name="banka_tms_user_action" value="save" />
                        <input type="hidden" id="user-id" name="user_id" value="<?php echo esc_attr($edit_user ? $edit_user->ID : 0); ?>" />
                        <label class="banka-tms-field-group"><span>Full Name</span><input type="text" id="user-name" name="user_full_name" placeholder="Full name" value="<?php echo esc_attr($edit_user ? $edit_user->display_name : ''); ?>" required /></label>
                        <label class="banka-tms-field-group"><span>Username</span><input type="text" id="user-login" name="login" placeholder="Username" value="<?php echo esc_attr($edit_user ? $edit_user->user_login : ''); ?>" <?php echo $edit_user ? 'readonly' : 'required'; ?> /></label>
                        <label class="banka-tms-field-group"><span>Email</span><input type="email" id="user-email" name="email" placeholder="Email" value="<?php echo esc_attr($edit_user ? $edit_user->user_email : ''); ?>" required /></label>
                        <label class="banka-tms-field-group"><span>Mobile</span><input type="text" id="user-mobile" name="mobile" placeholder="10-digit mobile number" inputmode="numeric" maxlength="10" pattern="[0-9]{10}" title="Mobile number must contain exactly 10 digits" value="<?php echo esc_attr($edit_user ? $edit_user->mobile : ''); ?>" /></label>
                        <label class="banka-tms-field-group"><span>Designation</span><select id="user-designation" name="designation"><option value="">Select designation</option><?php foreach ($designation_rows as $designation_row) : ?><option value="<?php echo esc_attr($designation_row->name); ?>" <?php selected($edit_user ? $edit_user->designation : '', $designation_row->name); ?>><?php echo esc_html($designation_row->name); ?></option><?php endforeach; ?></select></label>
                        <label class="banka-tms-field-group"><span>Category</span><select id="user-category" name="category"><option value="">Select category</option><?php foreach ($user_category_rows as $category_row) : ?><option value="<?php echo esc_attr($category_row->name); ?>" <?php selected($edit_user ? $edit_user->category : '', $category_row->name); ?>><?php echo esc_html($category_row->name); ?></option><?php endforeach; ?></select></label>
                        <label class="banka-tms-field-group"><span>Role</span><select id="user-role" name="role"><option value="subscriber" <?php selected($edit_user && in_array('subscriber', $edit_user->roles, true)); ?>>Employee</option><option value="editor" <?php selected($edit_user && in_array('editor', $edit_user->roles, true)); ?>>Manager</option><option value="administrator" <?php selected($edit_user && in_array('administrator', $edit_user->roles, true)); ?>>Admin</option></select></label>
                        <label class="banka-tms-field-group banka-tms-field-full"><span>Password</span><input type="password" id="user-password" name="password" placeholder="Password" <?php echo $edit_user ? '' : 'required'; ?> /><small id="banka-tms-user-password-help" style="display:none">Leave blank to retain the existing password.</small></label>
                        <div id="banka-tms-user-status" class="banka-tms-status banka-tms-field-full"></div>
                        <div class="banka-tms-modal-actions banka-tms-field-full"><button type="button" class="banka-tms-secondary-btn" data-close-modal>Cancel</button><button type="submit" class="banka-tms-primary-btn" id="banka-tms-user-submit-btn"><?php echo $edit_user ? 'Save Changes' : 'Create User'; ?></button></div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
            <div class="banka-tms-modal" id="banka-tms-project-modal" style="display:none">
                <div class="banka-tms-modal-backdrop"></div>
                <div class="banka-tms-modal-card banka-tms-project-modal-card-exact">
                    <button type="button" class="banka-tms-modal-close" data-close-modal>×</button>
                    <h2 class="banka-tms-modal-title" id="banka-tms-project-modal-title">Add New Project</h2>
                    <form id="banka-tms-project-form" class="banka-tms-project-form-exact">
                        <input type="hidden" id="project-id" value="0" />
                        <div class="banka-tms-project-form-scroll">
                            <div class="banka-tms-project-section-title">Basic Details</div>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-name" placeholder="Project Name *" required /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-telegram-group-id" placeholder="Telegram Group ID" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><textarea id="project-address" rows="4" placeholder="Address *" required></textarea></label>
                            <input type="hidden" id="project-code" value="" />
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-client-name" placeholder="Client Name" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full">
                                <select id="project-type" required>
                                    <option value="">Select Project Type</option>
                                    <?php foreach ($project_type_options as $project_type_option) : ?>
                                        <option value="<?php echo esc_attr($project_type_option); ?>"><?php echo esc_html($project_type_option); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full">
                                <select id="project-category" required>
                                    <option value="">Select Category</option>
                                    <?php foreach ($project_category_options as $category_option) : ?>
                                        <option value="<?php echo esc_attr($category_option); ?>"><?php echo esc_html($category_option); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <div class="banka-tms-project-section-title">Consultants</div>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-architect-1" placeholder="Architect / Interior" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-architect-2" placeholder="Architect / Interior - 2" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-asst-architect" placeholder="Asst. Architect / Interior" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-structural-inhouse" placeholder="In-House Structural" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-structural-consultant" placeholder="Structural Consultant" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-mep-consultant" placeholder="MEP Consultant" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-landscape-consultant" placeholder="Landscape Consultant" /></label>

                            <div class="banka-tms-project-section-title">Drawings &amp; Status</div>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-views-3d" placeholder="3D Views" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-working" placeholder="Working" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-permission-drawing" placeholder="Permission Drawing" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-site-engineer" placeholder="Site Engineer" /></label>
                            <div class="banka-tms-project-status-grid">
                                <label class="banka-tms-project-form-field"><input type="text" id="project-status-1" placeholder="Status 1" /></label>
                                <label class="banka-tms-project-form-field"><input type="text" id="project-status-2" placeholder="Status 2" /></label>
                                <label class="banka-tms-project-form-field"><input type="text" id="project-status-3" placeholder="Status 3" /></label>
                            </div>
                            <div id="banka-tms-project-status" class="banka-tms-status banka-tms-field-full"></div>
                        </div>
                        <div class="banka-tms-modal-actions banka-tms-project-modal-actions"><button type="submit" class="banka-tms-primary-btn" id="banka-tms-project-submit-btn">Add New Project</button><button type="button" class="banka-tms-secondary-btn" data-close-modal>Cancel</button></div>
                    </form>
                </div>
            </div>


        <?php elseif ($page_slug === 'tms-tasks') : ?>
            <?php
            if (function_exists('banka_tms_ensure_action_log_table')) {
                banka_tms_ensure_action_log_table();
            }
            $task_rows_sql = "SELECT * FROM {$task_table}";
            if (!$is_admin_user) {
                $taskpro_user_id = function_exists('banka_tms_app_current_user_id') ? (int) banka_tms_app_current_user_id() : (int) ($current_user->id ?? 0);
                $task_rows_sql .= $wpdb->prepare(" WHERE assignee_id=%d OR owner_id=%d", $taskpro_user_id, $taskpro_user_id);
            }
            $task_rows_sql .= " ORDER BY id DESC";
            $task_rows = $wpdb->get_results($task_rows_sql);
            $last_update_map = array();
            $action_log_table = $wpdb->prefix . 'banka_tms_action_logs';
            $action_log_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $action_log_table));
            if ($action_log_exists === $action_log_table) {
                $latest_update_rows = $wpdb->get_results("SELECT l.* FROM {$action_log_table} l INNER JOIN (SELECT task_id, MAX(id) AS max_id FROM {$action_log_table} WHERE action_name <> 'Created' AND TRIM(COALESCE(remarks, '')) <> 'Task details updated.' GROUP BY task_id) x ON x.max_id = l.id");
                foreach ($latest_update_rows as $latest_update_row) {
                    $last_update_map[(int) $latest_update_row->task_id] = $latest_update_row;
                }
            }
            $task_page_heading = 'All Tasks';
            $task_page_subtitle = 'Manage all tasks across the organization';
            if ($status_query === 'pending') {
                $task_page_heading = 'Pending Tasks';
                $task_page_subtitle = 'Review tasks that are pending or in progress';
            } elseif ($status_query === 'completed') {
                $task_page_heading = 'Completed Tasks';
                $task_page_subtitle = 'Review all completed tasks';
            }
            ?>
            <section class="banka-tms-task-page" data-task-view="<?php echo esc_attr($status_query ?: 'all'); ?>">
                <div class="banka-tms-list-header">
                    <div>
                        <h1 class="banka-tms-list-title"><?php echo esc_html($task_page_heading); ?></h1>
                        <p class="banka-tms-list-subtitle"><?php echo esc_html($task_page_subtitle); ?></p>
                    </div>
                    <div class="banka-tms-list-actions">
                        <button type="button" class="banka-tms-mini-btn banka-tms-filter-toggle-btn" id="banka-tms-toggle-task-filters" aria-expanded="false" aria-controls="banka-tms-advanced-task-filters"><?php echo banka_tms_icon('filter', 'banka-tms-mini-icon'); ?><span>Filter</span></button>
                        <button type="button" class="banka-tms-mini-btn banka-tms-primary" id="banka-tms-open-task-modal"><?php echo banka_tms_icon('plus', 'banka-tms-mini-icon'); ?><span>Add Task</span></button>
                        <button type="button" class="banka-tms-mini-btn" id="banka-tms-export-excel"><?php echo banka_tms_icon('file', 'banka-tms-mini-icon'); ?><span>Excel</span></button>
                        <div class="banka-tms-pdf-menu-wrap">
                            <button type="button" class="banka-tms-mini-btn" id="banka-tms-export-pdf" aria-haspopup="true" aria-expanded="false"><?php echo banka_tms_icon('file', 'banka-tms-mini-icon'); ?><span>PDF</span></button>
                            <div class="banka-tms-pdf-menu" id="banka-tms-pdf-menu" style="display:none">
                                <button type="button" data-pdf-group="standard">Standard PDF</button>
                                <button type="button" data-pdf-group="project">Group by Project</button>
                                <button type="button" data-pdf-group="assignee">Group by Assignee</button>
                                <button type="button" data-pdf-group="owner">Group by Owner</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="banka-tms-bulk-action-bar" id="banka-tms-bulk-action-bar" style="display:none">
                    <strong id="banka-tms-selected-count">0 task(s) selected</strong>
                    <div class="banka-tms-bulk-action-buttons">
                        <button type="button" class="banka-tms-bulk-btn banka-tms-bulk-delete" id="banka-tms-bulk-delete"><?php echo banka_tms_icon('trash', 'banka-tms-mini-icon'); ?><span><?php echo $status_query === 'completed' ? 'Delete Selected' : 'Delete'; ?></span></button>
                        <?php if ($status_query !== 'completed') : ?>
                            <button type="button" class="banka-tms-bulk-btn" id="banka-tms-bulk-complete"><?php echo banka_tms_icon('completed', 'banka-tms-mini-icon'); ?><span>Complete</span></button>
                            <button type="button" class="banka-tms-bulk-btn" id="banka-tms-bulk-reassign"><?php echo banka_tms_icon('edit', 'banka-tms-mini-icon'); ?><span>Reassign</span></button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="banka-tms-card banka-tms-task-list-card">
                    <div class="banka-tms-task-filters">
                        <div class="banka-tms-task-search-row">
                            <div class="banka-tms-search-box"><?php echo banka_tms_icon('search', 'banka-tms-filter-icon'); ?><input type="text" id="banka-tms-task-search" placeholder="Search tasks..." /></div>
                        </div>
                        <div class="banka-tms-filter-grid" id="banka-tms-advanced-task-filters" style="display:none">
                            <select id="banka-tms-filter-status">
                                <option value="">All Status</option>
                                <option value="Pending">Not Yet Started</option>
                                <option value="In Progress">In Progress</option>
                                <option value="Completed" <?php selected($status_query, 'completed'); ?>>Completed</option>
                            </select>
                            <select id="banka-tms-filter-priority">
                                <option value="">All Priorities</option>
                                <option value="High">High</option>
                                <option value="Medium">Medium</option>
                                <option value="Low">Low</option>
                            </select>
                            <select id="banka-tms-filter-project">
                                <option value="">All Projects</option>
                                <?php foreach ($wpdb->get_results("SELECT project_name, address FROM {$wpdb->prefix}banka_tms_projects ORDER BY project_name ASC") as $filter_project_row) : ?>
                                    <?php $filter_project_label = trim((string) $filter_project_row->project_name) . (trim((string) $filter_project_row->address) !== '' ? ' (' . trim((string) $filter_project_row->address) . ')' : ''); ?>
                                    <option value="<?php echo esc_attr($filter_project_label); ?>"><?php echo esc_html($filter_project_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="banka-tms-filter-owner">
                                <option value="">All Owners</option>
                                <?php foreach (banka_tms_app_get_users(array('orderby' => 'display_name', 'order' => 'ASC')) as $u) : ?>
                                    <option value="<?php echo esc_attr($u->display_name); ?>"><?php echo esc_html($u->display_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="banka-tms-filter-assignee">
                                <option value="">All Assignees</option>
                                <?php foreach (banka_tms_app_get_users(array('orderby' => 'display_name', 'order' => 'ASC')) as $u) : ?>
                                    <option value="<?php echo esc_attr($u->display_name); ?>"><?php echo esc_html($u->display_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="banka-tms-date-box" for="banka-tms-filter-from"><?php echo banka_tms_icon('calendar', 'banka-tms-filter-icon'); ?><span class="banka-tms-date-label">From Date</span><input type="date" id="banka-tms-filter-from" aria-label="From Date" title="From Date" /></label>
                            <label class="banka-tms-date-box" for="banka-tms-filter-to"><?php echo banka_tms_icon('calendar', 'banka-tms-filter-icon'); ?><span class="banka-tms-date-label">To Date</span><input type="date" id="banka-tms-filter-to" aria-label="To Date" title="To Date" /></label>
                            <button type="button" class="banka-tms-clear-filter-btn" id="banka-tms-clear-task-filters">Clear</button>
                        </div>
                    </div>

                    <div class="banka-tms-table-wrap banka-tms-advanced-table-wrap">
                        <table class="banka-tms-table banka-tms-task-data-table" id="banka-tms-task-data-table">
                            <thead>
                                <tr>
                                    <th class="banka-tms-col-check"><input type="checkbox" id="banka-tms-check-all" /></th>
                                    <th>S.No.</th>
                                    <th>Task</th>
                                    <th>Notes</th>
                                    <th>Assignees</th>
                                    <th>Owner</th>
                                    <th>Project</th>
                                    <th>Status</th>
                                    <th>Last Update Date</th>
                                    <th>Last Update Remarks</th>
                                    <th>Next Action Date</th>
                                    <th>Priority</th>
                                    <th>DueDate</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="banka-tms-task-table-body">
                                <?php if ($task_rows) : $sno = 1; foreach ($task_rows as $task) :
                                    $assignee_name = $task->assignee_id ? (banka_tms_app_get_user((int) $task->assignee_id)->display_name ?? '—') : '—';
                                    $owner_name = $task->created_by ? (banka_tms_app_get_user((int) $task->created_by)->display_name ?? '—') : '—';
                                    $status_value = in_array($task->status, array('Pending', 'In Progress', 'Completed'), true) ? $task->status : 'Pending';
                                    if ($status_query === 'pending' && $status_value === 'Completed') { continue; }
                                    if ($status_query === 'completed' && $status_value !== 'Completed') { continue; }
                                    $project_name = function_exists('banka_tms_project_display_label') ? banka_tms_project_display_label($task->project_name ?? '') : (isset($task->project_name) && trim((string) $task->project_name) !== '' ? (string) $task->project_name : 'General Project');
                                    $priority_name = !empty($task->priority_name) ? (string) $task->priority_name : 'Medium';
                                    $due_date = !empty($task->due_date) ? (string) $task->due_date : (!empty($task->created_at) ? gmdate('Y-m-d', strtotime($task->created_at)) : '');
                                    $category_name = !empty($task->category_name) ? (string) $task->category_name : '';
                                    $owner_id = !empty($task->owner_id) ? (int) $task->owner_id : (int) $task->created_by;
                                    $owner_user = $owner_id ? banka_tms_app_get_user($owner_id) : false;
                                    $owner_name = $owner_user ? $owner_user->display_name : $owner_name;
                                    $status_label = $status_value === 'Pending' ? 'Not Yet Started' : $status_value;
                                    $last_update = isset($last_update_map[(int) $task->id]) ? $last_update_map[(int) $task->id] : null;
                                    $last_update_date = $last_update && !empty($last_update->created_at) ? mysql2date('d/m/Y H:i', $last_update->created_at) : '';
                                    $last_update_remarks = $last_update ? (string) $last_update->remarks : '';
                                    $next_action_date = !empty($task->next_action_date) ? (string)$task->next_action_date : '';
                                    ?>
                                    <tr data-id="<?php echo esc_attr($task->id); ?>" data-title="<?php echo esc_attr($task->title); ?>" data-description="<?php echo esc_attr($task->description); ?>" data-remarks="<?php echo esc_attr($task->remarks ?? ''); ?>" data-status="<?php echo esc_attr($status_value); ?>" data-priority="<?php echo esc_attr($priority_name); ?>" data-project="<?php echo esc_attr($project_name); ?>" data-owner="<?php echo esc_attr($owner_name); ?>" data-assignee="<?php echo esc_attr($assignee_name); ?>" data-duedate="<?php echo esc_attr($due_date); ?>" data-assigneeid="<?php echo esc_attr((int) $task->assignee_id); ?>" data-ownerid="<?php echo esc_attr($owner_id); ?>" data-category="<?php echo esc_attr($category_name); ?>" data-last-update-date="<?php echo esc_attr($last_update_date); ?>" data-last-update-remarks="<?php echo esc_attr($last_update_remarks); ?>" data-next-action-date="<?php echo esc_attr($next_action_date); ?>">
                                        <td class="banka-tms-col-check"><input type="checkbox" class="banka-tms-row-check" /></td>
                                        <td><?php echo esc_html($sno); ?></td>
                                        <td class="banka-tms-task-name-cell"><?php echo esc_html($task->title); ?></td>
                                        <td class="banka-tms-task-notes-cell"><?php echo esc_html((string) ($task->description ?? '')); ?></td>
                                        <td><?php echo esc_html($assignee_name); ?></td>
                                        <td><?php echo esc_html($owner_name); ?></td>
                                        <td><?php echo esc_html($project_name); ?></td>
                                        <td><span class="banka-tms-status-pill <?php echo $status_value === 'Completed' ? 'completed' : ($status_value === 'In Progress' ? 'progress' : 'pending'); ?>"><?php echo esc_html($status_label); ?></span></td>
                                        <td class="banka-tms-last-update-date"><?php echo esc_html($last_update_date); ?></td>
                                        <td class="banka-tms-last-update-remarks"><?php echo esc_html($last_update_remarks); ?></td>
                                        <td class="banka-tms-next-action-date"><?php echo esc_html($next_action_date !== '' ? wp_date('d/m/Y', strtotime($next_action_date), banka_tms_ist_timezone()) : ''); ?></td>
                                        <td><?php echo esc_html($priority_name); ?></td>
                                        <td><?php echo esc_html($due_date !== '' ? wp_date('d/m/Y', strtotime($due_date), banka_tms_ist_timezone()) : ''); ?></td>
                                        <td>
                                            <div class="banka-tms-action-buttons">
                                                <button type="button" class="banka-tms-table-btn banka-tms-quick-update-task">Update</button>
                                                <button type="button" class="banka-tms-icon-btn banka-tms-edit-task" title="Edit"><?php echo banka_tms_icon('edit', 'banka-tms-table-action-icon'); ?></button>
                                                <button type="button" class="banka-tms-icon-btn banka-tms-view-task" title="Details"><?php echo banka_tms_icon('info', 'banka-tms-table-action-icon'); ?></button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php $sno++; endforeach; else : ?>
                                    <tr class="banka-tms-initial-empty-row"><td colspan="14" class="banka-tms-empty">No tasks found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <div class="banka-tms-modal" id="banka-tms-task-modal" style="display:none">
                <div class="banka-tms-modal-backdrop"></div>
                <div class="banka-tms-modal-card banka-tms-modal-card-wide banka-tms-task-modal-card-exact">
                    <button type="button" class="banka-tms-modal-close" data-close-modal>×</button>
                    <h2 class="banka-tms-modal-title" id="banka-tms-task-modal-title">Add Task</h2>
                    <form id="banka-tms-task-form" class="banka-tms-form banka-tms-popup-form banka-tms-task-form-grid banka-tms-task-form-grid-exact">
                        <input type="hidden" id="task-id" value="0" />
                        <input type="hidden" id="task-status" value="Pending" />

                        <div class="banka-tms-field-group banka-tms-field-with-plus banka-tms-task-field banka-tms-task-field-task">
                            <label>
                                <span>Task</span>
                                <select id="task-title" required>
                                    <option value="">Select a task...</option>
                                    <?php foreach ($wpdb->get_results("SELECT name FROM {$wpdb->prefix}banka_tms_task_master ORDER BY name ASC") as $task_master_row) : ?>
                                        <option value="<?php echo esc_attr($task_master_row->name); ?>"><?php echo esc_html($task_master_row->name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <button type="button" class="banka-tms-inline-plus" id="banka-tms-quick-add-task-master" aria-label="Add task to Task Master">+</button>
                        </div>

                        <label class="banka-tms-field-group banka-tms-task-field">
                            <span>Priority <em>*</em></span>
                            <select id="task-priority">
                                <option value="High">High</option>
                                <option value="Medium" selected>Medium</option>
                                <option value="Low">Low</option>
                            </select>
                        </label>

                        <label class="banka-tms-field-group banka-tms-task-field">
                            <span>Assignees <em>*</em></span>
                            <select id="task-assignee-id">
                                <option value="0">Select Assignees...</option>
                                <?php foreach (banka_tms_app_get_users(array('orderby' => 'display_name', 'order' => 'ASC')) as $u) : ?>
                                    <option value="<?php echo esc_attr($u->ID); ?>"><?php echo esc_html($u->display_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label class="banka-tms-field-group banka-tms-task-field">
                            <span>Task Owners <em>*</em></span>
                            <select id="task-owner-id">
                                <?php foreach (banka_tms_app_get_users(array('orderby' => 'display_name', 'order' => 'ASC')) as $u) : ?>
                                    <option value="<?php echo esc_attr($u->ID); ?>" <?php selected($u->ID, banka_tms_app_current_user_id()); ?>><?php echo esc_html($u->display_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <div class="banka-tms-field-group banka-tms-field-with-plus banka-tms-task-field">
                            <label>
                                <span>Category <em>*</em></span>
                                <select id="task-category">
                                    <option value="">Select Category...</option>
                                    <?php foreach ($wpdb->get_results("SELECT name FROM {$wpdb->prefix}banka_tms_categories ORDER BY name ASC") as $task_category_row) : ?>
                                        <option value="<?php echo esc_attr($task_category_row->name); ?>"><?php echo esc_html($task_category_row->name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <button type="button" class="banka-tms-inline-plus banka-tms-quick-add-category" aria-label="Add category">+</button>
                        </div>

                        <div class="banka-tms-field-group banka-tms-field-with-plus banka-tms-task-field">
                            <label>
                                <span>Project <em>*</em></span>
                                <select id="task-project">
                                    <option value="">Select Project...</option>
                                    <?php foreach ($wpdb->get_results("SELECT project_name, address FROM {$wpdb->prefix}banka_tms_projects ORDER BY project_name ASC") as $task_project_row) : ?>
                                        <?php $task_project_label = trim((string) $task_project_row->project_name) . (trim((string) $task_project_row->address) !== '' ? ' (' . trim((string) $task_project_row->address) . ')' : ''); ?>
                                        <option value="<?php echo esc_attr($task_project_label); ?>"><?php echo esc_html($task_project_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <button type="button" class="banka-tms-inline-plus banka-tms-quick-add-project" aria-label="Add project">+</button>
                        </div>

                        <label class="banka-tms-field-group banka-tms-field-half banka-tms-task-field banka-tms-task-due-date-field">
                            <span>Due Date <em>*</em></span>
                            <span class="banka-tms-date-input-wrap">
                                <input type="date" id="task-due-date" placeholder="dd-mm-yyyy" />
                                <button type="button" class="banka-tms-calendar-trigger" aria-label="Open calendar" title="Open calendar">📅</button>
                            </span>
                        </label>
                        <label class="banka-tms-field-group banka-tms-field-half banka-tms-task-field banka-tms-task-status-display is-hidden" id="banka-tms-task-status-field">
                            <span>Status</span>
                            <input type="text" id="task-status-display" value="" readonly />
                        </label>

                        <label class="banka-tms-field-group banka-tms-field-full banka-tms-task-field">
                            <span>Notes</span>
                            <textarea id="task-description" placeholder=""></textarea>
                        </label>

                        <div id="banka-tms-task-status" class="banka-tms-status banka-tms-field-full"></div>
                        <div class="banka-tms-modal-actions banka-tms-field-full"><button type="button" class="banka-tms-secondary-btn" data-close-modal>Cancel</button><button type="button" class="banka-tms-secondary-btn banka-tms-template-help-btn">Template</button><button type="submit" class="banka-tms-primary-btn" id="banka-tms-task-submit-btn">Create Task</button></div>
                    </form>
                </div>
            </div>


            <div class="banka-tms-modal" id="banka-tms-quick-task-master-modal" style="display:none">
                <div class="banka-tms-modal-backdrop"></div>
                <div class="banka-tms-modal-card banka-tms-quick-task-master-card">
                    <button type="button" class="banka-tms-modal-close" data-close-modal>×</button>
                    <h2 class="banka-tms-modal-title">Add New Task</h2>
                    <form id="banka-tms-quick-task-master-form" class="banka-tms-quick-update-form">
                        <label><span>Task Name</span><input type="text" id="banka-tms-quick-task-master-name" placeholder="Enter task name" required /></label>
                        <div id="banka-tms-quick-task-master-status" class="banka-tms-status"></div>
                        <div class="banka-tms-modal-actions"><button type="button" class="banka-tms-secondary-btn" data-close-modal>Cancel</button><button type="submit" class="banka-tms-primary-btn">Add Task</button></div>
                    </form>
                </div>
            </div>

            <div class="banka-tms-modal" id="banka-tms-quick-category-modal" style="display:none">
                <div class="banka-tms-modal-backdrop"></div>
                <div class="banka-tms-modal-card banka-tms-quick-task-master-card">
                    <button type="button" class="banka-tms-modal-close" data-quick-category-close>×</button>
                    <h2 class="banka-tms-modal-title">Add New Category</h2>
                    <form id="banka-tms-quick-category-form" class="banka-tms-quick-update-form">
                        <label><span>Category Name</span><input type="text" id="banka-tms-quick-category-name" placeholder="Enter category name" required /></label>
                        <div id="banka-tms-quick-category-status" class="banka-tms-status"></div>
                        <div class="banka-tms-modal-actions"><button type="button" class="banka-tms-secondary-btn" data-quick-category-close>Cancel</button><button type="submit" class="banka-tms-primary-btn">Add Category</button></div>
                    </form>
                </div>
            </div>

            <div class="banka-tms-modal" id="banka-tms-bulk-reassign-modal" style="display:none">
                <div class="banka-tms-modal-backdrop"></div>
                <div class="banka-tms-modal-card banka-tms-quick-update-card">
                    <button type="button" class="banka-tms-modal-close" data-close-modal>×</button>
                    <h2 class="banka-tms-modal-title">Reassign Selected Tasks</h2>
                    <form id="banka-tms-bulk-reassign-form" class="banka-tms-quick-update-form">
                        <label><span>Assignee</span><select id="banka-tms-bulk-assignee" required>
                            <option value="">Select Assignee...</option>
                            <?php foreach (banka_tms_app_get_users(array('orderby' => 'display_name', 'order' => 'ASC')) as $u) : ?>
                                <option value="<?php echo esc_attr($u->ID); ?>"><?php echo esc_html($u->display_name); ?></option>
                            <?php endforeach; ?>
                        </select></label>
                        <div id="banka-tms-bulk-status" class="banka-tms-status"></div>
                        <div class="banka-tms-modal-actions"><button type="button" class="banka-tms-secondary-btn" data-close-modal>Cancel</button><button type="submit" class="banka-tms-primary-btn">Reassign</button></div>
                    </form>
                </div>
            </div>

            <div class="banka-tms-modal" id="banka-tms-task-update-modal" style="display:none">
                <div class="banka-tms-modal-backdrop"></div>
                <div class="banka-tms-modal-card banka-tms-task-update-card">
                    <button type="button" class="banka-tms-modal-close" data-close-modal>×</button>
                    <h2 class="banka-tms-modal-title">Update Task</h2>
                    <form id="banka-tms-quick-update-form" class="banka-tms-quick-update-form">
                        <input type="hidden" id="quick-update-task-id" value="0" />
                        <label><span>Status <em>*</em></span><select id="quick-update-status" required>
                            <option value="Pending">Not Yet Started</option>
                            <option value="In Progress" selected>In Progress</option>
                            <option value="Completed">Completed</option>
                        </select></label>
                        <label><span>Remarks <em>*</em></span><textarea id="quick-update-remarks" placeholder="Add remarks..." required></textarea></label>
                        <label id="banka-tms-next-action-wrap" class="banka-tms-next-action-field"><span>Next Action Date</span><input type="date" id="quick-update-next-action-date" min="<?php echo esc_attr((new DateTimeImmutable('now', banka_tms_ist_timezone()))->modify('+1 day')->format('Y-m-d')); ?>" autocomplete="off" /></label>
                        <label class="banka-tms-reassign-field"><span>Re-Assign</span><select id="quick-update-assignee">
                            <option value="">Select assignee (keep current)</option>
                            <?php foreach (banka_tms_app_get_users(array('orderby' => 'display_name', 'order' => 'ASC')) as $u) : ?>
                                <option value="<?php echo esc_attr($u->ID); ?>"><?php echo esc_html($u->display_name); ?></option>
                            <?php endforeach; ?>
                        </select></label>
                        <div id="banka-tms-quick-update-status" class="banka-tms-status"></div>
                        <div class="banka-tms-modal-actions"><button type="button" class="banka-tms-secondary-btn" data-close-modal>Cancel</button><button type="submit" class="banka-tms-primary-btn">Update Task</button></div>
                    </form>
                </div>
            </div>

            <div class="banka-tms-modal" id="banka-tms-task-view-modal" style="display:none">
                <div class="banka-tms-modal-backdrop"></div>
                <div class="banka-tms-modal-card banka-tms-task-detail-card">
                    <button type="button" class="banka-tms-modal-close" data-close-modal>×</button>
                    <h2 class="banka-tms-modal-title" id="banka-tms-detail-title"></h2>
                    <div class="banka-tms-task-detail-grid-modern">
                        <div class="banka-tms-detail-item"><span class="banka-tms-detail-icon blue"><?php echo banka_tms_icon('info'); ?></span><div><small>Status</small><strong id="banka-tms-detail-status"></strong></div></div>
                        <div class="banka-tms-detail-item"><span class="banka-tms-detail-icon red"><?php echo banka_tms_icon('calendar'); ?></span><div><small>Due Date</small><strong id="banka-tms-detail-duedate"></strong></div></div>
                        <div class="banka-tms-detail-item"><span class="banka-tms-detail-icon orange"><?php echo banka_tms_icon('calendar'); ?></span><div><small>Next Action Date</small><strong id="banka-tms-detail-next-action-date"></strong></div></div>
                        <div class="banka-tms-detail-item"><span class="banka-tms-detail-icon green"><?php echo banka_tms_icon('users'); ?></span><div><small>Assignees</small><strong id="banka-tms-detail-assignee"></strong></div></div>
                        <div class="banka-tms-detail-item"><span class="banka-tms-detail-icon purple"><?php echo banka_tms_icon('users'); ?></span><div><small>Owners</small><strong id="banka-tms-detail-owner"></strong></div></div>
                        <div class="banka-tms-detail-item"><span class="banka-tms-detail-icon amber"><?php echo banka_tms_icon('categories'); ?></span><div><small>Category</small><strong id="banka-tms-detail-category"></strong></div></div>
                        <div class="banka-tms-detail-item"><span class="banka-tms-detail-icon violet"><?php echo banka_tms_icon('sites'); ?></span><div><small>Project</small><strong id="banka-tms-detail-project"></strong></div></div>
                    </div>
                    <div class="banka-tms-detail-section"><h3>Notes</h3><div class="banka-tms-detail-notes" id="banka-tms-detail-description"></div></div>
                    <div class="banka-tms-detail-section"><h3>Activity Log</h3><div class="banka-tms-detail-activity" id="banka-tms-detail-activity"><div class="banka-tms-detail-loading">Loading activity...</div></div></div>
                </div>
            </div>
            <div class="banka-tms-modal" id="banka-tms-project-modal" style="display:none">
                <div class="banka-tms-modal-backdrop"></div>
                <div class="banka-tms-modal-card banka-tms-project-modal-card-exact">
                    <button type="button" class="banka-tms-modal-close" data-close-modal>×</button>
                    <h2 class="banka-tms-modal-title" id="banka-tms-project-modal-title">Add New Project</h2>
                    <form id="banka-tms-project-form" class="banka-tms-project-form-exact">
                        <input type="hidden" id="project-id" value="0" />
                        <div class="banka-tms-project-form-scroll">
                            <div class="banka-tms-project-section-title">Basic Details</div>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-name" placeholder="Project Name *" required /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-telegram-group-id" placeholder="Telegram Group ID" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><textarea id="project-address" rows="4" placeholder="Address *" required></textarea></label>
                            <input type="hidden" id="project-code" value="" />
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-client-name" placeholder="Client Name" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full">
                                <select id="project-type" required>
                                    <option value="">Select Project Type</option>
                                    <?php foreach ($project_type_options as $project_type_option) : ?>
                                        <option value="<?php echo esc_attr($project_type_option); ?>"><?php echo esc_html($project_type_option); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full">
                                <select id="project-category" required>
                                    <option value="">Select Category</option>
                                    <?php foreach ($project_category_options as $category_option) : ?>
                                        <option value="<?php echo esc_attr($category_option); ?>"><?php echo esc_html($category_option); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <div class="banka-tms-project-section-title">Consultants</div>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-architect-1" placeholder="Architect / Interior" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-architect-2" placeholder="Architect / Interior - 2" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-asst-architect" placeholder="Asst. Architect / Interior" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-structural-inhouse" placeholder="In-House Structural" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-structural-consultant" placeholder="Structural Consultant" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-mep-consultant" placeholder="MEP Consultant" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-landscape-consultant" placeholder="Landscape Consultant" /></label>

                            <div class="banka-tms-project-section-title">Drawings &amp; Status</div>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-views-3d" placeholder="3D Views" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-working" placeholder="Working" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-permission-drawing" placeholder="Permission Drawing" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-site-engineer" placeholder="Site Engineer" /></label>
                            <div class="banka-tms-project-status-grid">
                                <label class="banka-tms-project-form-field"><input type="text" id="project-status-1" placeholder="Status 1" /></label>
                                <label class="banka-tms-project-form-field"><input type="text" id="project-status-2" placeholder="Status 2" /></label>
                                <label class="banka-tms-project-form-field"><input type="text" id="project-status-3" placeholder="Status 3" /></label>
                            </div>
                            <div id="banka-tms-project-status" class="banka-tms-status banka-tms-field-full"></div>
                        </div>
                        <div class="banka-tms-modal-actions banka-tms-project-modal-actions"><button type="submit" class="banka-tms-primary-btn" id="banka-tms-project-submit-btn">Add New Project</button><button type="button" class="banka-tms-secondary-btn" data-close-modal>Cancel</button></div>
                    </form>
                </div>
            </div>

        <?php elseif ($page_slug === 'tms-users') : ?>
            <?php
            $user_rows = banka_tms_app_get_users(array('orderby' => 'display_name', 'order' => 'ASC'));
            // The original WordPress administrator is a system account, not a TaskPro user.
            // Keep it available for WordPress login, but do not show it in the TaskPro Users table.
            $user_rows = array_values(array_filter($user_rows, function($user_row) {
                return !banka_tms_app_is_protected_wp_admin((object) array('wp_user_id' => $user_row->wp_user_id ?? 0));
            }));
            if ($user_message === '' && !empty($_COOKIE['banka_tms_user_flash'])) {
                $flash_json = base64_decode((string) $_COOKIE['banka_tms_user_flash'], true);
                $flash = $flash_json ? json_decode($flash_json, true) : null;
                if (is_array($flash)) {
                    $user_message = sanitize_text_field($flash['message'] ?? '');
                    $user_message_type = sanitize_key($flash['type'] ?? 'success');
                }
                setcookie('banka_tms_user_flash', '', time() - 3600, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true);
                unset($_COOKIE['banka_tms_user_flash']);
            }
            ?>
            <section class="banka-tms-user-page">
                <div class="banka-tms-list-header">
                    <div>
                        <h1 class="banka-tms-list-title">Users</h1>
                        <p class="banka-tms-list-subtitle">Pre-register users and manage their roles</p>
                    </div>
                    <div class="banka-tms-list-actions"><?php banka_tms_bulk_import_buttons('users'); ?></div>
                </div>

                <div class="banka-tms-card banka-tms-task-list-card">
                    <div id="banka-tms-user-page-message" class="banka-tms-user-page-message <?php echo $user_message_type === 'error' ? 'is-error' : ($user_message ? 'is-success' : ''); ?>" role="status" aria-live="polite" style="<?php echo $user_message ? 'display:block' : 'display:none'; ?>"><?php echo esc_html($user_message); ?></div>
                    <div class="banka-tms-users-toolbar">
                        <div class="banka-tms-search-box banka-tms-user-search-box"><?php echo banka_tms_icon('search', 'banka-tms-filter-icon'); ?><input type="text" id="banka-tms-user-search" placeholder="Search users..." /></div>
                        <button type="button" class="banka-tms-mini-btn banka-tms-primary" id="banka-tms-open-user-modal"><?php echo banka_tms_icon('plus', 'banka-tms-mini-icon'); ?><span>Pre-register User</span></button>
                    </div>

                    <div class="banka-tms-table-wrap banka-tms-advanced-table-wrap">
                        <table class="banka-tms-table banka-tms-user-data-table" id="banka-tms-user-data-table">
                            <thead>
                                <tr>
                                    <th>S.No.</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Mobile</th>
                                    <th>Designation</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $i = 1; foreach ($user_rows as $u) : 
                                    $mobile = $u->mobile;
                                    $designation = $u->designation !== '' ? $u->designation : 'User';
                                    $user_category = $u->category;
                                    $role_labels = array(
                                        'administrator' => 'Admin',
                                        'editor' => 'Manager',
                                        'subscriber' => 'Employee',
                                    );
                                    $mapped_roles = array();
                                    foreach ($u->roles as $user_role_key) {
                                        $mapped_roles[] = $role_labels[$user_role_key] ?? ucwords(str_replace('_', ' ', $user_role_key));
                                    }
                                    $role_label = !empty($mapped_roles) ? implode(', ', $mapped_roles) : 'Employee';
                                    $is_active = !empty($u->active);
                                    $is_protected_admin = banka_tms_app_is_protected_wp_admin((object) array('wp_user_id' => $u->wp_user_id ?? 0));
                                ?>
                                <tr data-user-id="<?php echo esc_attr($u->ID); ?>" data-name="<?php echo esc_attr($u->display_name ?: $u->user_login); ?>" data-login="<?php echo esc_attr($u->user_login); ?>" data-email="<?php echo esc_attr($u->user_email); ?>" data-mobile="<?php echo esc_attr($mobile); ?>" data-designation="<?php echo esc_attr($designation); ?>" data-category="<?php echo esc_attr($user_category); ?>" data-role="<?php echo esc_attr(!empty($u->roles) ? $u->roles[0] : 'subscriber'); ?>" data-active="<?php echo $is_active ? '1' : '0'; ?>">
                                    <td><?php echo esc_html($i); ?></td>
                                    <td><?php echo esc_html($u->display_name ?: $u->user_login); ?></td>
                                    <td><?php echo esc_html($u->user_email); ?></td>
                                    <td><?php echo esc_html($mobile); ?></td>
                                    <td><?php echo esc_html($designation); ?></td>
                                    <td><?php echo esc_html($role_label); ?></td>
                                    <td>
                                        <?php if ($is_protected_admin) : ?>
                                            <span class="banka-tms-locked-user" title="Original WordPress administrator account is locked"><?php echo banka_tms_icon('lock', 'banka-tms-toggle-icon'); ?></span>
                                        <?php else : ?>
                                            <button type="button" class="banka-tms-status-toggle <?php echo $is_active ? 'is-active' : 'is-inactive'; ?>" data-user-id="<?php echo esc_attr($u->ID); ?>" data-active="<?php echo $is_active ? '1' : '0'; ?>" title="<?php echo $is_active ? 'Deactivate user' : 'Activate user'; ?>" aria-label="<?php echo $is_active ? 'Deactivate user' : 'Activate user'; ?>"><?php echo banka_tms_icon($is_active ? 'toggle_on' : 'toggle_off', 'banka-tms-toggle-icon'); ?></button>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="banka-tms-action-buttons banka-tms-user-action-buttons">
                                            <?php if ($is_protected_admin) : ?>
                                                <a class="banka-tms-icon-btn banka-tms-system-mobile-btn" title="Update Mobile" aria-label="Update Mobile" href="<?php echo esc_url(add_query_arg('update_mobile', (int)$u->ID, $users_page_url)); ?>"><?php echo banka_tms_icon('phone', 'banka-tms-user-table-icon'); ?></a>
                                                <span class="banka-tms-icon-btn banka-tms-user-locked" title="Other system administrator fields are locked"><?php echo banka_tms_icon('lock', 'banka-tms-user-table-icon'); ?></span>
                                            <?php else : ?>
                                                <a class="banka-tms-icon-btn banka-tms-server-user-edit" title="Edit" href="<?php echo esc_url(add_query_arg('edit_user', (int)$u->ID, $users_page_url)); ?>"><?php echo banka_tms_icon('edit', 'banka-tms-user-table-icon banca-tms-green-icon'); ?></a>
                                                <button type="button" class="banka-tms-icon-btn banka-tms-user-delete" title="Delete" aria-label="Delete user"><?php echo banka_tms_icon('trash', 'banka-tms-user-table-icon banka-tms-red-icon'); ?></button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php $i++; endforeach; ?>
                                <tr class="banka-tms-user-no-result-row" style="display:none"><td colspan="8" class="banka-tms-empty">No users match the search.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <div class="banka-tms-modal" id="banka-tms-user-modal" style="<?php echo $edit_user ? 'display:flex' : 'display:none'; ?>">
                <div class="banka-tms-modal-backdrop"></div>
                <div class="banka-tms-modal-card banka-tms-user-modal-card">
                    <button type="button" class="banka-tms-modal-close" data-close-modal onclick="<?php echo $edit_user ? "window.location.href='" . esc_js($users_page_url) . "'" : ''; ?>">×</button>
                    <h2 class="banka-tms-modal-title" id="banka-tms-user-modal-title"><?php echo $edit_user ? 'Edit User' : 'Pre-register User'; ?></h2>
                    <form id="banka-tms-user-form" class="banka-tms-form banka-tms-user-form-grid" method="post" data-server-submit="1">
                        <?php wp_nonce_field('banka_tms_user_form', 'banka_tms_user_nonce'); ?>
                        <input type="hidden" name="banka_tms_user_action" value="save" />
                        <input type="hidden" id="user-id" name="user_id" value="<?php echo esc_attr($edit_user ? $edit_user->ID : 0); ?>" />
                        <label class="banka-tms-field-group"><span>Full Name</span><input type="text" id="user-name" name="user_full_name" placeholder="Full name" value="<?php echo esc_attr($edit_user ? $edit_user->display_name : ''); ?>" required /></label>
                        <label class="banka-tms-field-group"><span>Username</span><input type="text" id="user-login" name="login" placeholder="Username" value="<?php echo esc_attr($edit_user ? $edit_user->user_login : ''); ?>" <?php echo $edit_user ? 'readonly' : 'required'; ?> /></label>
                        <label class="banka-tms-field-group"><span>Email</span><input type="email" id="user-email" name="email" placeholder="Email" value="<?php echo esc_attr($edit_user ? $edit_user->user_email : ''); ?>" required /></label>
                        <label class="banka-tms-field-group"><span>Mobile</span><input type="text" id="user-mobile" name="mobile" placeholder="10-digit mobile number" inputmode="numeric" maxlength="10" pattern="[0-9]{10}" title="Mobile number must contain exactly 10 digits" value="<?php echo esc_attr($edit_user ? $edit_user->mobile : ''); ?>" /></label>
                        <label class="banka-tms-field-group"><span>Designation</span><select id="user-designation" name="designation"><option value="">Select designation</option><?php foreach ($designation_rows as $designation_row) : ?><option value="<?php echo esc_attr($designation_row->name); ?>" <?php selected($edit_user ? $edit_user->designation : '', $designation_row->name); ?>><?php echo esc_html($designation_row->name); ?></option><?php endforeach; ?></select></label>
                        <label class="banka-tms-field-group"><span>Category</span><select id="user-category" name="category"><option value="">Select category</option><?php foreach ($user_category_rows as $category_row) : ?><option value="<?php echo esc_attr($category_row->name); ?>" <?php selected($edit_user ? $edit_user->category : '', $category_row->name); ?>><?php echo esc_html($category_row->name); ?></option><?php endforeach; ?></select></label>
                        <label class="banka-tms-field-group"><span>Role</span><select id="user-role" name="role"><option value="subscriber" <?php selected($edit_user && in_array('subscriber', $edit_user->roles, true)); ?>>Employee</option><option value="editor" <?php selected($edit_user && in_array('editor', $edit_user->roles, true)); ?>>Manager</option><option value="administrator" <?php selected($edit_user && in_array('administrator', $edit_user->roles, true)); ?>>Admin</option></select></label>
                        <label class="banka-tms-field-group banka-tms-field-full"><span>Password</span><input type="password" id="user-password" name="password" placeholder="<?php echo $edit_user ? 'Leave blank to retain password' : 'Password'; ?>" <?php echo $edit_user ? '' : 'required'; ?> /></label>
                        <div id="banka-tms-user-status" class="banka-tms-status banka-tms-field-full"></div>
                        <div class="banka-tms-modal-actions banka-tms-field-full">
                            <?php if ($edit_user) : ?><a class="banka-tms-secondary-btn" href="<?php echo esc_url($users_page_url); ?>">Cancel</a><?php else : ?><button type="button" class="banka-tms-secondary-btn" data-close-modal>Cancel</button><?php endif; ?>
                            <button type="submit" class="banka-tms-primary-btn" id="banka-tms-user-submit-btn"><?php echo $edit_user ? 'Save Changes' : 'Create User'; ?></button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($mobile_user && banka_tms_app_is_protected_wp_admin((object) array('wp_user_id' => $mobile_user->wp_user_id ?? 0))) : ?>
            <div class="banka-tms-modal" id="banka-tms-system-mobile-modal" style="display:flex;">
                <div class="banka-tms-modal-backdrop"></div>
                <div class="banka-tms-modal-card banka-tms-system-mobile-card">
                    <a class="banka-tms-modal-close" href="<?php echo esc_url($users_page_url); ?>" aria-label="Close">×</a>
                    <h2 class="banka-tms-modal-title">Update Mobile Number</h2>
                    <form class="banka-tms-form" method="post" data-server-submit="1">
                        <?php wp_nonce_field('banka_tms_user_form', 'banka_tms_user_nonce'); ?>
                        <input type="hidden" name="banka_tms_user_action" value="update_mobile" />
                        <input type="hidden" name="user_id" value="<?php echo esc_attr($mobile_user->ID); ?>" />
                        <label class="banka-tms-field-group">
                            <span>System Administrator</span>
                            <input type="text" value="<?php echo esc_attr($mobile_user->display_name ?: $mobile_user->user_login); ?>" readonly />
                        </label>
                        <label class="banka-tms-field-group">
                            <span>Mobile Number *</span>
                            <input type="text" name="mobile" inputmode="numeric" maxlength="10" pattern="[0-9]{10}" placeholder="10-digit mobile number" value="<?php echo esc_attr($mobile_user->mobile); ?>" required />
                        </label>
                        <div class="banka-tms-modal-actions">
                            <a class="banka-tms-secondary-btn" href="<?php echo esc_url($users_page_url); ?>">Cancel</a>
                            <button type="submit" class="banka-tms-primary-btn">Update Mobile</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        <?php elseif ($page_slug === 'tms-categories') : ?>
            <?php $category_rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}banka_tms_categories ORDER BY id ASC"); ?>
            <section class="banka-tms-user-page banka-tms-category-page">
                <div class="banka-tms-list-header">
                    <div>
                        <h1 class="banka-tms-list-title">Categories</h1>
                        <p class="banka-tms-list-subtitle">Manage task categories</p>
                    </div>
                    <div class="banka-tms-list-actions">
                        <button type="button" class="banka-tms-mini-btn banka-tms-primary" id="banka-tms-open-category-modal"><?php echo banka_tms_icon('plus', 'banka-tms-mini-icon'); ?><span>Add Category</span></button>
                        <?php banka_tms_bulk_import_buttons('categories'); ?>
                        <button type="button" class="banka-tms-mini-btn" id="banka-tms-category-excel"><?php echo banka_tms_icon('file', 'banka-tms-mini-icon'); ?><span>Excel</span></button>
                        <button type="button" class="banka-tms-mini-btn" id="banka-tms-category-pdf"><?php echo banka_tms_icon('file', 'banka-tms-mini-icon'); ?><span>PDF</span></button>
                    </div>
                </div>

                <div class="banka-tms-card banka-tms-task-list-card">
                    <?php $category_message = isset($_GET['cat_message']) ? sanitize_text_field(rawurldecode(wp_unslash($_GET['cat_message']))) : ''; $category_message_type = isset($_GET['cat_type']) ? sanitize_key(wp_unslash($_GET['cat_type'])) : ''; ?>
                    <div id="banka-tms-category-status" class="banka-tms-status banka-tms-page-inline-status <?php echo $category_message_type === 'error' ? 'is-error' : 'is-success'; ?>" style="<?php echo $category_message !== '' ? 'display:block' : 'display:none'; ?>"><?php echo esc_html($category_message); ?></div>
                    <div class="banka-tms-users-toolbar">
                        <div class="banka-tms-search-box banka-tms-user-search-box"><?php echo banka_tms_icon('search', 'banka-tms-filter-icon'); ?><input type="text" id="banka-tms-category-search" placeholder="Search categories..." /></div>
                    </div>
                    <div class="banka-tms-table-wrap banka-tms-advanced-table-wrap">
                        <table class="banka-tms-table banka-tms-category-table" id="banka-tms-category-table">
                            <thead><tr><th>S.No.</th><th>Category Name</th><th>Actions</th></tr></thead>
                            <tbody>
                            <?php $i=1; foreach ($category_rows as $category_row) : ?>
                                <tr data-category-id="<?php echo esc_attr($category_row->id); ?>" data-name="<?php echo esc_attr($category_row->name); ?>">
                                    <td><?php echo esc_html($i); ?></td>
                                    <td><?php echo esc_html($category_row->name); ?></td>
                                    <td><div class="banka-tms-action-buttons banka-tms-user-action-buttons">
                                        <button type="button" class="banka-tms-icon-btn banka-tms-category-edit" title="Edit"><?php echo banka_tms_icon('edit','banka-tms-user-table-icon banka-tms-green-icon'); ?></button>
                                        <form method="post" class="banka-tms-inline-delete-form" onsubmit="return confirm('Delete this category?');">
                                            <?php wp_nonce_field('banka_tms_category_form', 'banka_tms_category_nonce'); ?>
                                            <input type="hidden" name="banka_tms_category_action" value="delete" />
                                            <input type="hidden" name="category_id" value="<?php echo esc_attr($category_row->id); ?>" />
                                            <button type="submit" class="banka-tms-icon-btn banka-tms-category-delete" title="Delete"><?php echo banka_tms_icon('trash','banka-tms-user-table-icon banka-tms-red-icon'); ?></button>
                                        </form>
                                    </div></td>
                                </tr>
                            <?php $i++; endforeach; ?>
                                <tr class="banka-tms-category-no-result-row" style="display:none"><td colspan="3" class="banka-tms-empty">No categories match the search.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <div class="banka-tms-modal" id="banka-tms-category-modal" style="display:none">
                <div class="banka-tms-modal-backdrop"></div>
                <div class="banka-tms-modal-card banka-tms-category-modal-card">
                    <button type="button" class="banka-tms-modal-close" data-close-modal>×</button>
                    <h2 class="banka-tms-modal-title" id="banka-tms-category-modal-title">Add New Category</h2>
                    <form id="banka-tms-category-form" class="banka-tms-form banka-tms-user-form-grid" method="post">
                        <?php wp_nonce_field('banka_tms_category_form', 'banka_tms_category_nonce'); ?>
                        <input type="hidden" name="banka_tms_category_action" value="save" />
                        <input type="hidden" id="category-id" name="category_id" value="0" />
                        <label class="banka-tms-field-group banka-tms-field-full"><input type="text" id="category-name" name="category_name" placeholder="Category Name *" required /></label>
                        <div id="banka-tms-category-form-status" class="banka-tms-status banka-tms-field-full"></div>
                        <div class="banka-tms-modal-actions banka-tms-field-full"><button type="button" class="banka-tms-secondary-btn" data-close-modal>Cancel</button><button type="submit" class="banka-tms-primary-btn" id="banka-tms-category-submit-btn">Add Category</button></div>
                    </form>
                </div>
            </div>

        <?php elseif ($page_slug === 'tms-task-master') : ?>
            <?php $task_master_rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}banka_tms_task_master ORDER BY name ASC"); ?>
            <section class="banka-tms-user-page banka-tms-task-master-page">
                <div class="banka-tms-list-header">
                    <div>
                        <h1 class="banka-tms-list-title">Task Master</h1>
                        <p class="banka-tms-list-subtitle">Manage tasks used in task-selection dropdowns</p>
                    </div>
                    <div class="banka-tms-list-actions">
                        <button type="button" class="banka-tms-mini-btn banka-tms-primary" id="banka-tms-open-task-master-modal"><?php echo banka_tms_icon('plus', 'banka-tms-mini-icon'); ?><span>Add Task</span></button>
                        <?php banka_tms_bulk_import_buttons('task_master'); ?>
                    </div>
                </div>

                <div class="banka-tms-card banka-tms-task-list-card">
                    <div id="banka-tms-task-master-page-message" class="banka-tms-user-page-message"></div>
                    <div class="banka-tms-users-toolbar">
                        <div class="banka-tms-search-box banka-tms-user-search-box"><?php echo banka_tms_icon('search', 'banka-tms-filter-icon'); ?><input type="text" id="banka-tms-task-master-search" placeholder="Search tasks..." /></div>
                    </div>
                    <div class="banka-tms-table-wrap banka-tms-advanced-table-wrap">
                        <table class="banka-tms-table banka-tms-designation-table banka-tms-task-master-table" id="banka-tms-task-master-table">
                            <thead><tr><th>S.No.</th><th>Task Name</th><th>Actions</th></tr></thead>
                            <tbody>
                                <?php $tm_i = 1; foreach ($task_master_rows as $task_master_row) : ?>
                                <tr data-task-master-id="<?php echo esc_attr($task_master_row->id); ?>" data-name="<?php echo esc_attr($task_master_row->name); ?>">
                                    <td><?php echo esc_html($tm_i); ?></td>
                                    <td><?php echo esc_html($task_master_row->name); ?></td>
                                    <td><div class="banka-tms-action-buttons banka-tms-user-action-buttons">
                                        <button type="button" class="banka-tms-icon-btn banka-tms-task-master-edit" title="Edit"><?php echo banka_tms_icon('edit', 'banka-tms-user-table-icon banka-tms-green-icon'); ?></button>
                                        <button type="button" class="banka-tms-icon-btn banka-tms-task-master-delete" title="Delete"><?php echo banka_tms_icon('trash', 'banka-tms-user-table-icon banka-tms-red-icon'); ?></button>
                                    </div></td>
                                </tr>
                                <?php $tm_i++; endforeach; ?>
                                <tr class="banka-tms-task-master-no-result-row" style="display:none"><td colspan="3" class="banka-tms-empty">No tasks match the search.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <div class="banka-tms-modal" id="banka-tms-task-master-modal" style="display:none">
                <div class="banka-tms-modal-backdrop"></div>
                <div class="banka-tms-modal-card banka-tms-category-modal-card">
                    <button type="button" class="banka-tms-modal-close" data-close-modal>×</button>
                    <h2 class="banka-tms-modal-title" id="banka-tms-task-master-modal-title">Add New Task</h2>
                    <form id="banka-tms-task-master-form" class="banka-tms-form banka-tms-user-form-grid">
                        <input type="hidden" id="task-master-id" value="0" />
                        <label class="banka-tms-field-group banka-tms-field-full"><input type="text" id="task-master-name" placeholder="Task Name *" required /></label>
                        <div id="banka-tms-task-master-form-status" class="banka-tms-status banka-tms-field-full"></div>
                        <div class="banka-tms-modal-actions banka-tms-field-full"><button type="button" class="banka-tms-secondary-btn" data-close-modal>Cancel</button><button type="submit" class="banka-tms-primary-btn" id="banka-tms-task-master-submit-btn">Add Task</button></div>
                    </form>
                </div>
            </div>

        <?php elseif ($page_slug === 'tms-designations') : ?>
            <?php $designation_rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}banka_tms_designations ORDER BY id ASC"); ?>
            <section class="banka-tms-user-page">
                <div class="banka-tms-list-header">
                    <div>
                        <h1 class="banka-tms-list-title">Designations</h1>
                        <p class="banka-tms-list-subtitle">Manage user designations</p>
                    </div>
                    <div class="banka-tms-list-actions">
                        <button type="button" class="banka-tms-mini-btn banka-tms-primary" id="banka-tms-open-designation-modal"><?php echo banka_tms_icon('plus', 'banka-tms-mini-icon'); ?><span>Add Designation</span></button>
                        <?php banka_tms_bulk_import_buttons('designations'); ?>
                    </div>
                </div>

                <div class="banka-tms-card banka-tms-task-list-card">
                    <div id="banka-tms-designation-status" class="banka-tms-status banka-tms-page-inline-status" style="display:none"></div>
                    <div class="banka-tms-users-toolbar">
                        <div class="banka-tms-search-box banka-tms-user-search-box"><?php echo banka_tms_icon('search', 'banka-tms-filter-icon'); ?><input type="text" id="banka-tms-designation-search" placeholder="Search designations..." /></div>
                    </div>
                    <div class="banka-tms-table-wrap banka-tms-advanced-table-wrap">
                        <table class="banka-tms-table banka-tms-designation-table" id="banka-tms-designation-table">
                            <thead>
                                <tr>
                                    <th>S.No.</th>
                                    <th>Designation Name</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $i = 1; foreach ($designation_rows as $designation_row) : ?>
                                <tr data-designation-id="<?php echo esc_attr($designation_row->id); ?>" data-name="<?php echo esc_attr($designation_row->name); ?>">
                                    <td><?php echo esc_html($i); ?></td>
                                    <td><?php echo esc_html($designation_row->name); ?></td>
                                    <td>
                                        <div class="banka-tms-action-buttons banka-tms-user-action-buttons">
                                            <button type="button" class="banka-tms-icon-btn banka-tms-designation-edit" title="Edit"><?php echo banka_tms_icon('edit', 'banka-tms-user-table-icon banka-tms-green-icon'); ?></button>
                                            <button type="button" class="banka-tms-icon-btn banka-tms-designation-delete" title="Delete"><?php echo banka_tms_icon('trash', 'banka-tms-user-table-icon banka-tms-red-icon'); ?></button>
                                        </div>
                                    </td>
                                </tr>
                                <?php $i++; endforeach; ?>
                                <tr class="banka-tms-designation-no-result-row" style="display:none"><td colspan="3" class="banka-tms-empty">No designations match the search.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <div class="banka-tms-modal" id="banka-tms-designation-modal" style="display:none">
                <div class="banka-tms-modal-backdrop"></div>
                <div class="banka-tms-modal-card banka-tms-designation-modal-card">
                    <button type="button" class="banka-tms-modal-close" data-close-modal>×</button>
                    <h2 class="banka-tms-modal-title" id="banka-tms-designation-modal-title">Add Designation</h2>
                    <form id="banka-tms-designation-form" class="banka-tms-form banka-tms-user-form-grid">
                        <input type="hidden" id="designation-id" value="0" />
                        <label class="banka-tms-field-group banka-tms-field-full"><span>Designation Name</span><input type="text" id="designation-name" placeholder="Designation name" required /></label>
                        <div id="banka-tms-designation-form-status" class="banka-tms-status banka-tms-field-full"></div>
                        <div class="banka-tms-modal-actions banka-tms-field-full"><button type="button" class="banka-tms-secondary-btn" data-close-modal>Cancel</button><button type="submit" class="banka-tms-primary-btn" id="banka-tms-designation-submit-btn">Save Designation</button></div>
                    </form>
                </div>
            </div>

        <?php elseif ($page_slug === 'tms-project-types' || $page_slug === 'tms-project-categories') : ?>
            <?php
                $is_type_master = $page_slug === 'tms-project-types';
                $master_table = $is_type_master ? $wpdb->prefix . 'banka_tms_project_types' : $wpdb->prefix . 'banka_tms_project_categories';
                $master_rows = $wpdb->get_results("SELECT * FROM {$master_table} ORDER BY name ASC");
                $master_title = $is_type_master ? 'Project Types' : 'Project Categories';
                $master_singular = $is_type_master ? 'Project Type' : 'Project Category';
                $master_key = $is_type_master ? 'type' : 'category';
            ?>
            <section class="banka-tms-user-page banka-tms-project-master-page <?php echo !$is_type_master ? 'banka-tms-project-category-page' : ''; ?>" data-master="<?php echo esc_attr($master_key); ?>">
                <div class="banka-tms-list-header">
                    <div><h1 class="banka-tms-list-title"><?php echo esc_html($master_title); ?></h1><p class="banka-tms-list-subtitle">Manage <?php echo esc_html(strtolower($master_title)); ?></p></div>
                    <div class="banka-tms-list-actions"><?php if ($is_type_master) : ?><button type="button" class="banka-tms-mini-btn banka-tms-primary banka-tms-open-project-master-modal"><?php echo banka_tms_icon('plus','banka-tms-mini-icon'); ?><span>Add <?php echo esc_html($master_singular); ?></span></button><?php banka_tms_bulk_import_buttons('project_types'); ?><?php endif; ?></div>
                </div>
                <div class="banka-tms-card banka-tms-task-list-card">
                    <div id="banka-tms-user-page-message" class="banka-tms-page-message <?php echo $user_message_type === 'error' ? 'is-error' : 'is-success'; ?>" style="<?php echo $user_message !== '' ? 'display:block' : 'display:none'; ?>"><?php echo esc_html($user_message); ?></div>
                    <div class="banka-tms-users-toolbar"><div class="banka-tms-search-box banka-tms-user-search-box"><?php echo banka_tms_icon('search','banka-tms-filter-icon'); ?><input type="text" class="banka-tms-project-master-search" placeholder="Search <?php echo esc_attr(strtolower($master_title)); ?>..." /></div></div>
                    <div class="banka-tms-table-wrap"><table class="banka-tms-table banka-tms-designation-table banka-tms-project-master-table"><thead><tr><th>S.No.</th><th><?php echo esc_html($master_singular); ?></th><?php if ($is_type_master) : ?><th>Actions</th><?php endif; ?></tr></thead><tbody>
                    <?php $mi=1; foreach($master_rows as $mr): ?><tr data-id="<?php echo esc_attr($mr->id); ?>" data-name="<?php echo esc_attr($mr->name); ?>"><td><?php echo esc_html($mi); ?></td><td><?php echo esc_html($mr->name); ?></td><?php if ($is_type_master) : ?><td><div class="banka-tms-action-buttons"><button type="button" class="banka-tms-icon-btn banka-tms-project-master-edit"><?php echo banka_tms_icon('edit','banka-tms-user-table-icon banka-tms-green-icon'); ?></button><button type="button" class="banka-tms-icon-btn banka-tms-project-master-delete"><?php echo banka_tms_icon('trash','banka-tms-user-table-icon banka-tms-red-icon'); ?></button></div></td><?php endif; ?></tr><?php $mi++; endforeach; ?>
                    <tr class="banka-tms-project-master-empty" style="display:none"><td colspan="<?php echo $is_type_master ? '3' : '2'; ?>" class="banka-tms-empty">No matching records found.</td></tr></tbody></table></div>
                </div>
            </section>
            <div class="banka-tms-modal" id="banka-tms-project-master-modal" style="display:none"><div class="banka-tms-modal-backdrop"></div><div class="banka-tms-modal-card banka-tms-designation-modal-card"><button type="button" class="banka-tms-modal-close" data-close-modal>×</button><h2 class="banka-tms-modal-title" id="banka-tms-project-master-title">Add <?php echo esc_html($master_singular); ?></h2><form id="banka-tms-project-master-form" class="banka-tms-form banka-tms-user-form-grid"><input type="hidden" id="project-master-id" value="0"><input type="hidden" id="project-master-key" value="<?php echo esc_attr($master_key); ?>"><label class="banka-tms-field-group banka-tms-field-full"><span><?php echo esc_html($master_singular); ?></span><input type="text" id="project-master-name" required></label><div id="project-master-status" class="banka-tms-status banka-tms-field-full"></div><div class="banka-tms-modal-actions banka-tms-field-full"><button type="button" class="banka-tms-secondary-btn" data-close-modal>Cancel</button><button type="submit" class="banka-tms-primary-btn">Save</button></div></form></div></div>

        <?php elseif ($page_slug === 'tms-sites') : ?>
            <?php
                $project_rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}banka_tms_projects ORDER BY id ASC");
                $project_types = $wpdb->get_col("SELECT DISTINCT project_type FROM {$wpdb->prefix}banka_tms_projects WHERE project_type <> '' ORDER BY project_type ASC");
                $project_categories = $wpdb->get_col("SELECT DISTINCT category FROM {$wpdb->prefix}banka_tms_projects WHERE category <> '' ORDER BY category ASC");
                $project_type_options = $wpdb->get_col("SELECT name FROM {$wpdb->prefix}banka_tms_project_types ORDER BY name ASC");
                $all_project_category_options = $wpdb->get_col("SELECT name FROM {$wpdb->prefix}banka_tms_project_categories ORDER BY name ASC");
                $project_category_options = array_values(array_filter($all_project_category_options, function($name){ return in_array(strtolower((string)$name), array('architect','interior'), true); }));
                if (empty($project_category_options)) { $project_category_options = array('Architect','Interior'); }
            ?>
            <section class="banka-tms-user-page banka-tms-project-page">
                <div class="banka-tms-list-header">
                    <div>
                        <h1 class="banka-tms-list-title">Projects</h1>
                    </div>
                    <div class="banka-tms-list-actions">
                        <?php banka_tms_bulk_import_buttons('projects'); ?>
                        <button type="button" class="banka-tms-mini-btn" id="banka-tms-project-pdf"><?php echo banka_tms_icon('file', 'banka-tms-mini-icon'); ?><span>PDF</span></button>
                        <button type="button" class="banka-tms-mini-btn" id="banka-tms-project-excel"><?php echo banka_tms_icon('file', 'banka-tms-mini-icon'); ?><span>Excel</span></button>
                        <button type="button" class="banka-tms-mini-btn banka-tms-primary" id="banka-tms-open-project-modal"><?php echo banka_tms_icon('plus', 'banka-tms-mini-icon'); ?><span>Add Project</span></button>
                    </div>
                </div>

                <div class="banka-tms-card banka-tms-task-list-card banka-tms-project-card">
                    <div id="banka-tms-project-page-message" class="banka-tms-user-page-message"></div>
                    <div class="banka-tms-project-toolbar">
                        <div class="banka-tms-search-box banka-tms-project-search-box"><?php echo banka_tms_icon('search', 'banka-tms-filter-icon'); ?><input type="text" id="banka-tms-project-search" placeholder="Search projects..." /></div>
                        <select id="banka-tms-project-type-filter" class="banka-tms-toolbar-select">
                            <option value="">Project Type</option>
                            <?php foreach ($project_types as $project_type) : ?>
                                <option value="<?php echo esc_attr($project_type); ?>"><?php echo esc_html($project_type); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="banka-tms-project-category-filter" class="banka-tms-toolbar-select">
                            <option value="">Project Category</option>
                            <?php foreach ($project_category_options as $project_category) : ?>
                                <option value="<?php echo esc_attr($project_category); ?>"><?php echo esc_html($project_category); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="banka-tms-clear-filter-btn" id="banka-tms-clear-project-filters">Clear</button>
                    </div>
                    <div class="banka-tms-table-wrap banka-tms-advanced-table-wrap">
                        <table class="banka-tms-table banka-tms-project-table" id="banka-tms-project-table">
                            <thead>
                                <tr>
                                    <th>S.No.</th>
                                    <th>Project ID</th>
                                    <th>Project Name</th>
                                    <th>Client Name</th>
                                    <th>Category</th>
                                    <th>Project Type</th>
                                    <th>Address</th>
                                    <th>Telegram Group ID</th>
                                    <th>Architect / Interiors 1</th>
                                    <th>Architect / Interiors 2</th>
                                    <th>Assistant Architect / Interiors</th>
                                    <th>In-house Structural</th>
                                    <th>Structural Consultant</th>
                                    <th>MEP Consultant</th>
                                    <th>Landscape Consultant</th>
                                    <th>3D Views</th>
                                    <th>Working Drawing</th>
                                    <th>Permission Drawing</th>
                                    <th>Site Engineer</th>
                                    <th>Project Status 1</th>
                                    <th>Project Status 2</th>
                                    <th>Project Status 3</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $project_index = 1; foreach ($project_rows as $project_row) : $row_meta = array(
                                    'telegram_group_id' => $project_row->telegram_group_id ?? '',
                                    'architect_1' => $project_row->architect_1 ?? '',
                                    'architect_2' => $project_row->architect_2 ?? '',
                                    'asst_architect' => $project_row->asst_architect ?? '',
                                    'structural_inhouse' => $project_row->structural_inhouse ?? '',
                                    'structural_consultant' => $project_row->structural_consultant ?? '',
                                    'mep_consultant' => $project_row->mep_consultant ?? '',
                                    'landscape_consultant' => $project_row->landscape_consultant ?? '',
                                    'views_3d' => $project_row->views_3d ?? '',
                                    'working' => $project_row->working ?? '',
                                    'permission_drawing' => $project_row->permission_drawing ?? '',
                                    'site_engineer' => $project_row->site_engineer ?? '',
                                    'status_1' => $project_row->status_1 ?? '',
                                    'status_2' => $project_row->status_2 ?? '',
                                    'status_3' => $project_row->status_3 ?? '',
                                ); ?>
                                <tr data-project-id="<?php echo esc_attr($project_row->id); ?>"
                                    data-project-code="<?php echo esc_attr($project_row->project_code); ?>"
                                    data-project-name="<?php echo esc_attr($project_row->project_name); ?>"
                                    data-client-name="<?php echo esc_attr($project_row->client_name); ?>"
                                    data-category="<?php echo esc_attr($project_row->category); ?>"
                                    data-project-type="<?php echo esc_attr($project_row->project_type); ?>"
                                    data-address="<?php echo esc_attr($project_row->address); ?>"
                                    data-telegram-group-id="<?php echo esc_attr($row_meta['telegram_group_id'] ?? ''); ?>"
                                    data-architect-1="<?php echo esc_attr($row_meta['architect_1'] ?? ''); ?>"
                                    data-architect-2="<?php echo esc_attr($row_meta['architect_2'] ?? ''); ?>"
                                    data-asst-architect="<?php echo esc_attr($row_meta['asst_architect'] ?? ''); ?>"
                                    data-structural-inhouse="<?php echo esc_attr($row_meta['structural_inhouse'] ?? ''); ?>"
                                    data-structural-consultant="<?php echo esc_attr($row_meta['structural_consultant'] ?? ''); ?>"
                                    data-mep-consultant="<?php echo esc_attr($row_meta['mep_consultant'] ?? ''); ?>"
                                    data-landscape-consultant="<?php echo esc_attr($row_meta['landscape_consultant'] ?? ''); ?>"
                                    data-views-3d="<?php echo esc_attr($row_meta['views_3d'] ?? ''); ?>"
                                    data-working="<?php echo esc_attr($row_meta['working'] ?? ''); ?>"
                                    data-permission-drawing="<?php echo esc_attr($row_meta['permission_drawing'] ?? ''); ?>"
                                    data-site-engineer="<?php echo esc_attr($row_meta['site_engineer'] ?? ''); ?>"
                                    data-status-1="<?php echo esc_attr($row_meta['status_1'] ?? ''); ?>"
                                    data-status-2="<?php echo esc_attr($row_meta['status_2'] ?? ''); ?>"
                                    data-status-3="<?php echo esc_attr($row_meta['status_3'] ?? ''); ?>">
                                    <td><?php echo esc_html($project_index); ?></td>
                                    <td><?php echo esc_html($project_row->project_code); ?></td>
                                    <td><?php echo esc_html($project_row->project_name); ?></td>
                                    <td><?php echo esc_html($project_row->client_name); ?></td>
                                    <td><?php echo esc_html($project_row->category); ?></td>
                                    <td><?php echo esc_html($project_row->project_type); ?></td>
                                    <td><?php echo esc_html($project_row->address); ?></td>
                                    <td><?php echo esc_html($row_meta['telegram_group_id'] ?? ''); ?></td>
                                    <td><?php echo esc_html($row_meta['architect_1'] ?? ''); ?></td>
                                    <td><?php echo esc_html($row_meta['architect_2'] ?? ''); ?></td>
                                    <td><?php echo esc_html($row_meta['asst_architect'] ?? ''); ?></td>
                                    <td><?php echo esc_html($row_meta['structural_inhouse'] ?? ''); ?></td>
                                    <td><?php echo esc_html($row_meta['structural_consultant'] ?? ''); ?></td>
                                    <td><?php echo esc_html($row_meta['mep_consultant'] ?? ''); ?></td>
                                    <td><?php echo esc_html($row_meta['landscape_consultant'] ?? ''); ?></td>
                                    <td><?php echo esc_html($row_meta['views_3d'] ?? ''); ?></td>
                                    <td><?php echo esc_html($row_meta['working'] ?? ''); ?></td>
                                    <td><?php echo esc_html($row_meta['permission_drawing'] ?? ''); ?></td>
                                    <td><?php echo esc_html($row_meta['site_engineer'] ?? ''); ?></td>
                                    <td><?php echo esc_html($row_meta['status_1'] ?? ''); ?></td>
                                    <td><?php echo esc_html($row_meta['status_2'] ?? ''); ?></td>
                                    <td><?php echo esc_html($row_meta['status_3'] ?? ''); ?></td>
                                    <td>
                                        <div class="banka-tms-action-buttons banka-tms-project-action-buttons">
                                            <button type="button" class="banka-tms-icon-btn banka-tms-project-details" title="Details"><?php echo banka_tms_icon('info', 'banka-tms-user-table-icon'); ?></button>
                                            <button type="button" class="banka-tms-icon-btn banka-tms-project-edit" title="Edit"><?php echo banka_tms_icon('edit', 'banka-tms-user-table-icon'); ?></button>
                                            <button type="button" class="banka-tms-icon-btn banka-tms-project-delete" title="Delete"><?php echo banka_tms_icon('trash', 'banka-tms-user-table-icon'); ?></button>
                                        </div>
                                    </td>
                                </tr>
                                <?php $project_index++; endforeach; ?>
                                <tr class="banka-tms-project-no-result-row" style="display:none"><td colspan="23" class="banka-tms-empty">No projects match the search.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <div class="banka-tms-modal" id="banka-tms-project-modal" style="display:none">
                <div class="banka-tms-modal-backdrop"></div>
                <div class="banka-tms-modal-card banka-tms-project-modal-card-exact">
                    <button type="button" class="banka-tms-modal-close" data-close-modal>×</button>
                    <h2 class="banka-tms-modal-title" id="banka-tms-project-modal-title">Add New Project</h2>
                    <form id="banka-tms-project-form" class="banka-tms-project-form-exact">
                        <input type="hidden" id="project-id" value="0" />
                        <div class="banka-tms-project-form-scroll">
                            <div class="banka-tms-project-section-title">Basic Details</div>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-name" placeholder="Project Name *" required /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-telegram-group-id" placeholder="Telegram Group ID" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><textarea id="project-address" rows="4" placeholder="Address *" required></textarea></label>
                            <input type="hidden" id="project-code" value="" />
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-client-name" placeholder="Client Name" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full">
                                <select id="project-type" required>
                                    <option value="">Select Project Type</option>
                                    <?php foreach ($project_type_options as $project_type_option) : ?>
                                        <option value="<?php echo esc_attr($project_type_option); ?>"><?php echo esc_html($project_type_option); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full">
                                <select id="project-category" required>
                                    <option value="">Select Category</option>
                                    <?php foreach ($project_category_options as $category_option) : ?>
                                        <option value="<?php echo esc_attr($category_option); ?>"><?php echo esc_html($category_option); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <div class="banka-tms-project-section-title">Consultants</div>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-architect-1" placeholder="Architect / Interior" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-architect-2" placeholder="Architect / Interior - 2" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-asst-architect" placeholder="Asst. Architect / Interior" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-structural-inhouse" placeholder="In-House Structural" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-structural-consultant" placeholder="Structural Consultant" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-mep-consultant" placeholder="MEP Consultant" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-landscape-consultant" placeholder="Landscape Consultant" /></label>

                            <div class="banka-tms-project-section-title">Drawings &amp; Status</div>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-views-3d" placeholder="3D Views" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-working" placeholder="Working" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-permission-drawing" placeholder="Permission Drawing" /></label>
                            <label class="banka-tms-project-form-field banka-tms-project-form-field-full"><input type="text" id="project-site-engineer" placeholder="Site Engineer" /></label>
                            <div class="banka-tms-project-status-grid">
                                <label class="banka-tms-project-form-field"><input type="text" id="project-status-1" placeholder="Status 1" /></label>
                                <label class="banka-tms-project-form-field"><input type="text" id="project-status-2" placeholder="Status 2" /></label>
                                <label class="banka-tms-project-form-field"><input type="text" id="project-status-3" placeholder="Status 3" /></label>
                            </div>
                            <div id="banka-tms-project-status" class="banka-tms-status banka-tms-field-full"></div>
                        </div>
                        <div class="banka-tms-modal-actions banka-tms-project-modal-actions"><button type="submit" class="banka-tms-primary-btn" id="banka-tms-project-submit-btn">Add New Project</button><button type="button" class="banka-tms-secondary-btn" data-close-modal>Cancel</button></div>
                    </form>
                </div>
            </div>

            <div class="banka-tms-modal" id="banka-tms-project-details-modal" style="display:none">
                <div class="banka-tms-modal-backdrop"></div>
                <div class="banka-tms-modal-card banka-tms-project-details-card">
                    <button type="button" class="banka-tms-modal-close" data-close-modal>×</button>
                    <div class="banka-tms-project-details-head">
                        <h2 class="banka-tms-modal-title" id="banka-tms-project-details-title">Project Details</h2>
                        <p id="banka-tms-project-details-subtitle"></p>
                    </div>
                    <div class="banka-tms-project-details-body">
                        <div class="banka-tms-project-details-summary" id="banka-tms-project-details-summary"></div>
                        <h3>Action Log</h3>
                        <div class="banka-tms-table-wrap banka-tms-project-log-wrap">
                            <table class="banka-tms-table banka-tms-project-log-table">
                                <thead>
                                    <tr>
                                        <th>S.No.</th>
                                        <th>Update Date</th>
                                        <th>Task</th>
                                        <th>Status</th>
                                        <th>Remarks</th>
                                        <th>Owner(s)</th>
                                        <th>Assignee(s)</th>
                                    </tr>
                                </thead>
                                <tbody id="banka-tms-project-log-body">
                                    <tr><td colspan="7" class="banka-tms-empty">Loading project activity...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($page_slug === 'tms-action-log') : ?>
            <?php
            if (function_exists('banka_tms_seed_created_logs_for_existing_tasks')) {
                banka_tms_seed_created_logs_for_existing_tasks();
            }
            $action_log_table = $wpdb->prefix . 'banka_tms_action_logs';
            if ($is_admin_user) {
                $action_log_rows = $wpdb->get_results("SELECT * FROM {$action_log_table} WHERE action_name <> 'Created' AND TRIM(COALESCE(remarks, '')) <> 'Task details updated.' ORDER BY created_at DESC, id DESC");
            } else {
                $taskpro_user_id = function_exists('banka_tms_app_current_user_id') ? (int) banka_tms_app_current_user_id() : 0;
                $action_log_rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT l.* FROM {$action_log_table} l
                     INNER JOIN {$task_table} t ON t.id = l.task_id
                     WHERE l.action_name <> 'Created' AND TRIM(COALESCE(l.remarks, '')) <> 'Task details updated.' AND (t.assignee_id = %d OR t.owner_id = %d)
                     ORDER BY l.created_at DESC, l.id DESC",
                    $taskpro_user_id,
                    $taskpro_user_id
                ));
            }
            $action_values = array();
            $owner_values = array();
            $assignee_values = array();
            $project_values = array();
            if ($action_log_rows) {
                foreach ($action_log_rows as $log_row) {
                    if (!empty($log_row->action_name)) { $action_values[$log_row->action_name] = $log_row->action_name; }
                    if (!empty($log_row->owner_names) && $log_row->owner_names !== '—') { $owner_values[$log_row->owner_names] = $log_row->owner_names; }
                    if (!empty($log_row->assignee_names) && $log_row->assignee_names !== '—') { $assignee_values[$log_row->assignee_names] = $log_row->assignee_names; }
                    if (!empty($log_row->project_name)) { $project_values[$log_row->project_name] = $log_row->project_name; }
                }
            }
            ksort($action_values); ksort($owner_values); ksort($assignee_values); ksort($project_values);
            ?>
            <section class="banka-tms-user-page banka-tms-action-log-page">
                <div class="banka-tms-list-header">
                    <div>
                        <h1 class="banka-tms-list-title">Action Log</h1>
                        <p class="banka-tms-list-subtitle">Complete history of all task activities</p>
                    </div>
                    <div class="banka-tms-list-actions">
                        <button type="button" class="banka-tms-mini-btn banka-tms-filter-toggle-btn" id="banka-tms-toggle-action-log-filters" aria-expanded="false" aria-controls="banka-tms-advanced-action-log-filters"><?php echo banka_tms_icon('filter', 'banka-tms-mini-icon'); ?><span>Filter</span></button>
                        <button type="button" class="banka-tms-mini-btn" id="banka-tms-action-log-excel"><?php echo banka_tms_icon('file', 'banka-tms-mini-icon'); ?><span>Excel</span></button>
                        <button type="button" class="banka-tms-mini-btn" id="banka-tms-action-log-pdf"><?php echo banka_tms_icon('file', 'banka-tms-mini-icon'); ?><span>PDF</span></button>
                    </div>
                </div>

                <div class="banka-tms-card banka-tms-task-list-card">
                    <div class="banka-tms-task-filters banka-tms-action-log-filters">
                        <div class="banka-tms-task-search-row">
                            <div class="banka-tms-search-box"><?php echo banka_tms_icon('search', 'banka-tms-filter-icon'); ?><input type="text" id="banka-tms-action-log-search" placeholder="Search logs..." /></div>
                        </div>
                        <div class="banka-tms-action-log-grid" id="banka-tms-advanced-action-log-filters" style="display:none">
                            <select id="banka-tms-action-log-filter-action" multiple data-multi-placeholder="Select Actions"><option value="">All Actions</option><?php foreach ($action_values as $value) : ?><option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($value); ?></option><?php endforeach; ?></select>
                            <select id="banka-tms-action-log-filter-assignee" multiple data-multi-placeholder="Select Assignees"><option value="">All Assignees</option><?php foreach ($assignee_values as $value) : ?><option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($value); ?></option><?php endforeach; ?></select>
                            <select id="banka-tms-action-log-filter-owner" multiple data-multi-placeholder="Select Owners"><option value="">All Owners</option><?php foreach ($owner_values as $value) : ?><option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($value); ?></option><?php endforeach; ?></select>
                            <select id="banka-tms-action-log-filter-project" multiple data-multi-placeholder="Select Projects"><option value="">All Projects</option><?php foreach ($project_values as $value) : ?><option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($value); ?></option><?php endforeach; ?></select>
                            <label class="banka-tms-date-box" for="banka-tms-action-log-filter-from"><?php echo banka_tms_icon('calendar', 'banka-tms-filter-icon'); ?><span class="banka-tms-date-label">From Date</span><input type="date" id="banka-tms-action-log-filter-from" aria-label="From Date" title="From Date" /></label>
                            <label class="banka-tms-date-box" for="banka-tms-action-log-filter-to"><?php echo banka_tms_icon('calendar', 'banka-tms-filter-icon'); ?><span class="banka-tms-date-label">To Date</span><input type="date" id="banka-tms-action-log-filter-to" aria-label="To Date" title="To Date" /></label>
                            <button type="button" class="banka-tms-clear-filter-btn" id="banka-tms-clear-action-log-filters">Clear</button>
                        </div>
                    </div>
                    <div class="banka-tms-table-wrap banka-tms-advanced-table-wrap">
                        <table class="banka-tms-table banka-tms-action-log-table" id="banka-tms-action-log-table">
                            <thead>
                                <tr>
                                    <th>Task</th>
                                    <th>Update Date</th>
                                    <th>Status</th>
                                    <th>Remarks</th>
                                    <th>Next Action Date</th>
                                    <th>Owner(s)</th>
                                    <th>Assignee(s)</th>
                                    <th>Project</th>
                                </tr>
                            </thead>
                            <tbody id="banka-tms-action-log-body">
                                <?php if ($action_log_rows) : foreach ($action_log_rows as $log_row) : ?>
                                    <?php $row_date = !empty($log_row->created_at) ? mysql2date('Y-m-d', $log_row->created_at) : ''; ?>
                                    <tr data-action="<?php echo esc_attr($log_row->action_name); ?>" data-owner="<?php echo esc_attr($log_row->owner_names); ?>" data-assignee="<?php echo esc_attr($log_row->assignee_names); ?>" data-project="<?php echo esc_attr($log_row->project_name); ?>" data-date="<?php echo esc_attr($row_date); ?>">
                                        <td><?php echo esc_html($log_row->task_title); ?></td>
                                        <td><?php echo esc_html(!empty($log_row->created_at) ? mysql2date('d/m/Y H:i', $log_row->created_at) : ''); ?></td>
                                        <td><?php echo esc_html($log_row->action_name); ?></td>
                                        <td><?php echo esc_html($log_row->remarks); ?></td>
                                        <td><?php echo esc_html(!empty($log_row->next_action_date) ? wp_date('d/m/Y', strtotime($log_row->next_action_date), banka_tms_ist_timezone()) : ''); ?></td>
                                        <td><?php echo esc_html($log_row->owner_names ?: '—'); ?></td>
                                        <td><?php echo esc_html($log_row->assignee_names ?: '—'); ?></td>
                                        <td><?php echo esc_html($log_row->project_name ?: 'General Project'); ?></td>
                                    </tr>
                                <?php endforeach; else : ?>
                                    <tr class="banka-tms-no-result-row"><td colspan="8" class="banka-tms-empty">No action logs found.</td></tr>
                                <?php endif; ?>
                                <tr class="banka-tms-action-log-no-result-row banka-tms-no-result-row" style="display:none"><td colspan="8" class="banka-tms-empty">No logs match the selected filters.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>


        <?php elseif ($page_slug === 'tms-notification-log') : ?>
            <?php
            if (function_exists('banka_tms_create_notification_table')) { banka_tms_create_notification_table(); }
            $notification_table = function_exists('banka_tms_notification_log_table') ? banka_tms_notification_log_table() : ($wpdb->prefix . 'banka_tms_notification_logs');
            $notif_page = max(1, isset($_GET['notif_page']) ? absint($_GET['notif_page']) : 1);
            $notif_per_page = 20;
            $notif_total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$notification_table}");
            $notif_offset = ($notif_page - 1) * $notif_per_page;
            $notification_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$notification_table} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
                    $notif_per_page,
                    $notif_offset
                )
            );
            $notif_total_pages = max(1, (int) ceil($notif_total / $notif_per_page));
            ?>
            <section class="banka-tms-user-page banka-tms-notification-log-page">
                <div class="banka-tms-list-header">
                    <div><h1 class="banka-tms-list-title">Notification Log</h1></div>
                </div>
                <div class="banka-tms-card banka-tms-task-list-card">
                    <div class="banka-tms-table-wrap banka-tms-advanced-table-wrap">
                        <table class="banka-tms-table banka-tms-notification-log-table">
                            <thead><tr><th>S.No.</th><th>Date &amp; Time</th><th>Event Type</th><th>Channel</th><th>Recipient</th><th>Mobile / Chat ID</th><th>Task ID</th><th>Message</th><th>Status</th><th>API Response / Error</th></tr></thead>
                            <tbody>
                            <?php if ($notification_rows) : foreach ($notification_rows as $index=>$notification_row) :
                                $response_text = (string) $notification_row->response;
                                $decoded_response = json_decode($response_text, true);
                                if (is_array($decoded_response)) {
                                    if (!empty($decoded_response['error'])) { $response_text = $decoded_response['error']; }
                                    elseif (!empty($decoded_response['body'])) { $response_text = $decoded_response['body']; }
                                }
                            ?>
                                <tr>
                                    <td><?php echo esc_html($notif_offset + $index + 1); ?></td>
                                    <td><?php echo esc_html(!empty($notification_row->created_at) ? mysql2date('d/m/Y H:i', $notification_row->created_at) : ''); ?></td>
                                    <td><?php echo esc_html($notification_row->event_type); ?></td>
                                    <td><?php echo esc_html($notification_row->channel); ?></td>
                                    <td><?php echo esc_html($notification_row->recipient_name ?: '—'); ?></td>
                                    <td><?php echo esc_html($notification_row->recipient_address ?: '—'); ?></td>
                                    <td><?php echo esc_html($notification_row->task_id ?: '—'); ?></td>
                                    <td class="banka-tms-notification-message-cell"><?php echo nl2br(esc_html($notification_row->message ?: '—')); ?></td>
                                    <td><span class="banka-tms-notification-status is-<?php echo esc_attr(strtolower($notification_row->status)); ?>"><?php echo esc_html($notification_row->status); ?></span></td>
                                    <td class="banka-tms-notification-response-cell"><?php echo esc_html($response_text ?: '—'); ?></td>
                                </tr>
                            <?php endforeach; else : ?>
                                <tr><td colspan="10" class="banka-tms-empty">No notification records found.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($notif_total_pages > 1) : ?>
                    <div class="banka-tms-pagination">
                        <span>Rows <?php echo esc_html($notif_per_page); ?></span>
                        <?php for ($p=1; $p<=$notif_total_pages; $p++) : $page_url = add_query_arg('notif_page', $p, home_url('/tms-notification-log/')); ?>
                            <a class="<?php echo $p===$notif_page ? 'active' : ''; ?>" href="<?php echo esc_url($page_url); ?>"><?php echo esc_html($p); ?></a>
                        <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </section>


        <?php elseif ($page_slug === 'tms-settings') : ?>
            <?php
            $project_prefix = trim((string) get_option('banka_tms_setting_project_prefix', 'P'));
            if ($project_prefix === '') { $project_prefix = 'P'; }
            $organization_logo = get_option('banka_tms_setting_organization_logo', '');
            $mas_username = get_option('banka_tms_setting_mas_username', '');
            $mas_password = get_option('banka_tms_setting_mas_password', '');
            $whatsapp_group_id = get_option('banka_tms_setting_whatsapp_group_id', '');
            $whatsapp_enabled = get_option('banka_tms_setting_whatsapp_enabled', '0');
            $whatsapp_group_enabled = get_option('banka_tms_setting_whatsapp_group_enabled', '0');
            $telegram_enabled = get_option('banka_tms_setting_telegram_enabled', '0');
            $pending_reminder_enabled = get_option('banka_tms_setting_pending_reminder_enabled', '0');
            $daily_activity_report_enabled = get_option('banka_tms_setting_daily_activity_report_enabled', '0');
            $meta_whatsapp_enabled = get_option('banka_tms_setting_meta_whatsapp_enabled', '0');
            $meta_phone_number_id = get_option('banka_tms_setting_meta_phone_number_id', '');
            $meta_business_account_id = get_option('banka_tms_setting_meta_business_account_id', '');
            $meta_access_token = get_option('banka_tms_setting_meta_access_token', '');
            $meta_api_version = get_option('banka_tms_setting_meta_api_version', 'v23.0');
            $meta_country_code = get_option('banka_tms_setting_meta_country_code', '91');
            $meta_assignment_enabled = get_option('banka_tms_setting_meta_assignment_enabled', '1');
            $meta_update_enabled = get_option('banka_tms_setting_meta_update_enabled', '1');
            $meta_assignment_template = get_option('banka_tms_setting_meta_assignment_template', 'archtms_task_assignment');
            $meta_assignment_language = get_option('banka_tms_setting_meta_assignment_language', 'en');
            $meta_update_template = get_option('banka_tms_setting_meta_update_template', 'archtms_task_update');
            $meta_update_language = get_option('banka_tms_setting_meta_update_language', 'en');
            $telegram_bot_token = get_option('banka_tms_setting_telegram_bot_token', '');
            $telegram_reporting_group_chat_id = get_option('banka_tms_setting_telegram_reporting_group_chat_id', '');
            $telegram_daily_activity_enabled = get_option('banka_tms_setting_telegram_daily_activity_enabled', '1');
            $pending_tasks_reminder_time = get_option('banka_tms_setting_pending_tasks_reminder_time', '10:00');
            $daily_activity_report_time = get_option('banka_tms_setting_daily_activity_report_time', '20:00');
            $settings_message = isset($_GET['settings_message']) ? sanitize_text_field(wp_unslash($_GET['settings_message'])) : '';
            $settings_type = isset($_GET['settings_type']) ? sanitize_html_class(wp_unslash($_GET['settings_type'])) : 'success';
            ?>
            <section class="banka-tms-settings-page">
                <div class="banka-tms-page-head banka-tms-page-head-left">
                    <h1 class="banka-tms-page-title-left">Admin Settings</h1>
                    <p class="banka-tms-page-subtitle-left">Manage application-wide settings</p>
                </div>

                <?php if ($settings_message !== '') : ?>
                    <div class="banka-tms-page-message is-<?php echo esc_attr($settings_type); ?>"><?php echo esc_html($settings_message); ?></div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data" class="banka-tms-settings-form">
                    <?php wp_nonce_field('banka_tms_settings_form', 'banka_tms_settings_nonce'); ?>
                    <input type="hidden" name="banka_tms_settings_action" value="save" />

                    <div class="banka-tms-settings-card">
                        <div class="banka-tms-settings-card-header">
                            <span class="banka-tms-settings-card-icon"><?php echo banka_tms_icon('settings'); ?></span>
                            <h2>Organization</h2>
                        </div>
                        <div class="banka-tms-settings-grid banka-tms-settings-grid-org">
                            <label class="banka-tms-settings-field banka-tms-settings-field-name">
                                <span>Prefix</span>
                                <input type="text" name="project_prefix" value="<?php echo esc_attr($project_prefix); ?>" placeholder="e.g. P" maxlength="10" />
                            </label>
                            <div class="banka-tms-settings-field banka-tms-settings-field-logo">
                                <span>Organization Logo</span>
                                <div class="banka-tms-settings-logo-row">
                                    <input type="hidden" id="banka-tms-organization-logo" name="organization_logo" value="<?php echo esc_attr($organization_logo); ?>" />
                                    <label class="banka-tms-logo-upload-btn" for="banka-tms-organization-logo-file"><?php echo banka_tms_icon('file'); ?> Upload Logo</label>
                                    <input type="file" id="banka-tms-organization-logo-file" name="organization_logo_file" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml" class="banka-tms-logo-file-input" />
                                    <div class="banka-tms-logo-preview" id="banka-tms-logo-preview">
                                        <?php if ($organization_logo) : ?>
                                            <img src="<?php echo esc_url($organization_logo); ?>" alt="Organization Logo" />
                                        <?php else : ?>
                                            <span class="banka-tms-logo-preview-empty">No logo</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="banka-tms-settings-card">
                        <div class="banka-tms-settings-card-header">
                            <h2>WhatsApp Messaging (MAS)</h2>
                        </div>
                        <div class="banka-tms-settings-stack">
                            <label class="banka-tms-settings-toggle-row"><span>WhatsApp Messaging Active</span><span class="banka-tms-switch"><input type="checkbox" name="whatsapp_enabled" value="1" <?php checked($whatsapp_enabled, '1'); ?> /><i></i></span></label>
                            <label class="banka-tms-settings-field"><span>MAS Username</span><input type="text" name="mas_username" value="<?php echo esc_attr($mas_username); ?>" placeholder="Enter MAS Username" /></label>
                            <label class="banka-tms-settings-field"><span>MAS Password</span><input type="password" name="mas_password" value="<?php echo esc_attr($mas_password); ?>" placeholder="Enter MAS Password" /></label>
                            <label class="banka-tms-settings-field"><span>WhatsApp Group ID</span><input type="text" name="whatsapp_group_id" value="<?php echo esc_attr($whatsapp_group_id); ?>" placeholder="Enter Group ID" /></label>
                            <label class="banka-tms-settings-toggle-row"><span>Use WhatsApp Group for Pending Reminder & Daily Activity Report</span><span class="banka-tms-switch"><input type="checkbox" name="whatsapp_group_enabled" value="1" <?php checked($whatsapp_group_enabled, '1'); ?> /><i></i></span></label>
                        </div>
                    </div>

                    <div class="banka-tms-settings-card">
                        <div class="banka-tms-settings-card-header">
                            <h2>WhatsApp Messaging (Meta)</h2>
                        </div>
                        <div class="banka-tms-settings-stack">
                            <label class="banka-tms-settings-toggle-row"><span>Meta WhatsApp Messaging Active</span><span class="banka-tms-switch"><input type="checkbox" name="meta_whatsapp_enabled" value="1" <?php checked($meta_whatsapp_enabled, '1'); ?> /><i></i></span></label>
                            <label class="banka-tms-settings-field"><span>Phone Number ID</span><input type="text" name="meta_phone_number_id" value="<?php echo esc_attr($meta_phone_number_id); ?>" placeholder="Meta Phone Number ID" /></label>
                            <label class="banka-tms-settings-field"><span>WhatsApp Business Account ID</span><input type="text" name="meta_business_account_id" value="<?php echo esc_attr($meta_business_account_id); ?>" placeholder="WABA ID" /></label>
                            <label class="banka-tms-settings-field"><span>Permanent Access Token</span><input type="password" name="meta_access_token" value="<?php echo esc_attr($meta_access_token); ?>" placeholder="Permanent System User Token" autocomplete="new-password" /></label>
                            <div class="banka-tms-settings-grid banca-tms-settings-grid-two">
                                <label class="banka-tms-settings-field"><span>API Version</span><input type="text" name="meta_api_version" value="<?php echo esc_attr($meta_api_version); ?>" placeholder="v23.0" /></label>
                                <label class="banka-tms-settings-field"><span>Default Country Code</span><input type="text" name="meta_country_code" value="<?php echo esc_attr($meta_country_code); ?>" placeholder="91" /></label>
                            </div>
                            <label class="banka-tms-settings-toggle-row"><span>Send Meta Template on Task Assignment</span><span class="banka-tms-switch"><input type="checkbox" name="meta_assignment_enabled" value="1" <?php checked($meta_assignment_enabled, '1'); ?> /><i></i></span></label>
                            <div class="banka-tms-settings-grid banca-tms-settings-grid-two">
                                <label class="banka-tms-settings-field"><span>Assignment Template Name</span><input type="text" name="meta_assignment_template" value="<?php echo esc_attr($meta_assignment_template); ?>" /></label>
                                <label class="banka-tms-settings-field"><span>Assignment Language Code</span><input type="text" name="meta_assignment_language" value="<?php echo esc_attr($meta_assignment_language); ?>" placeholder="en" /></label>
                            </div>
                            <label class="banka-tms-settings-toggle-row"><span>Send Meta Template on Task Update</span><span class="banka-tms-switch"><input type="checkbox" name="meta_update_enabled" value="1" <?php checked($meta_update_enabled, '1'); ?> /><i></i></span></label>
                            <div class="banka-tms-settings-grid banca-tms-settings-grid-two">
                                <label class="banka-tms-settings-field"><span>Update Template Name</span><input type="text" name="meta_update_template" value="<?php echo esc_attr($meta_update_template); ?>" /></label>
                                <label class="banka-tms-settings-field"><span>Update Language Code</span><input type="text" name="meta_update_language" value="<?php echo esc_attr($meta_update_language); ?>" placeholder="en" /></label>
                            </div>
                            <button type="button" class="banka-tms-template-help-btn banka-tms-secondary-btn">Template Setup & Process</button>
                        </div>
                    </div>

                    <div class="banka-tms-settings-card">
                        <div class="banka-tms-settings-card-header">
                            <span class="banka-tms-settings-card-icon"><?php echo banka_tms_icon('pending'); ?></span>
                            <h2>Telegram Notifications</h2>
                        </div>
                        <div class="banka-tms-settings-stack">
                            <label class="banka-tms-settings-toggle-row"><span>Telegram Notifications Active</span><span class="banka-tms-switch"><input type="checkbox" name="telegram_enabled" value="1" <?php checked($telegram_enabled, '1'); ?> /><i></i></span></label>
                            <label class="banka-tms-settings-field"><span>Bot Token</span><input type="password" name="telegram_bot_token" value="<?php echo esc_attr($telegram_bot_token); ?>" placeholder="Enter Bot Token" /></label>
                            <label class="banka-tms-settings-field"><span>Reporting Group Chat ID</span><input type="text" name="telegram_reporting_group_chat_id" value="<?php echo esc_attr($telegram_reporting_group_chat_id); ?>" placeholder="Enter Reporting Group Chat ID" /></label>
                            <label class="banka-tms-settings-toggle-row">
                                <span>Send Daily Activity Report to Reporting Group</span>
                                <span class="banka-tms-switch">
                                    <input type="checkbox" name="telegram_daily_activity_enabled" value="1" <?php checked($telegram_daily_activity_enabled, '1'); ?> />
                                    <i></i>
                                </span>
                            </label>
                        </div>
                    </div>

                    <div class="banka-tms-settings-card">
                        <div class="banka-tms-settings-card-header">
                            <span class="banka-tms-settings-card-icon"><?php echo banka_tms_icon('pending'); ?></span>
                            <h2>Report Triggers</h2>
                        </div>
                        <div class="banka-tms-settings-grid banca-tms-settings-grid-two">
                            <div class="banka-tms-settings-field banka-tms-reminder-test-field"><span>Pending Tasks Reminder Time</span><div class="banka-tms-setting-time-toggle"><input type="time" name="pending_tasks_reminder_time" value="<?php echo esc_attr($pending_tasks_reminder_time); ?>" /><label class="banka-tms-switch" title="Pending Task Reminder Active"><input type="checkbox" name="pending_reminder_enabled" value="1" <?php checked($pending_reminder_enabled, '1'); ?> /><i></i></label></div><button type="submit" name="banka_tms_test_pending_reminder" value="1" class="banka-tms-test-reminder-btn">Test Pending Reminder</button></div>
                            <div class="banka-tms-settings-field banka-tms-reminder-test-field"><span>Daily Activity Report Time</span><div class="banka-tms-setting-time-toggle"><input type="time" name="daily_activity_report_time" value="<?php echo esc_attr($daily_activity_report_time); ?>" /><label class="banka-tms-switch" title="Daily Activity Report Active"><input type="checkbox" name="daily_activity_report_enabled" value="1" <?php checked($daily_activity_report_enabled, '1'); ?> /><i></i></label></div><button type="submit" name="banka_tms_test_daily_activity" value="1" class="banka-tms-test-reminder-btn">Test Activity Report</button></div>
                        </div>
                    </div>

                    <button type="submit" class="banka-tms-settings-save-fab" aria-label="Save settings"><?php echo banka_tms_icon('settings'); ?></button>
                </form>
            </section>
        <?php elseif ($page_slug === 'tms-vendors') : ?>
            <h1 class="banka-tms-page-title">Vendors</h1><div class="banka-tms-card banka-tms-module-card"><h2>Vendor Management</h2><form id="banka-tms-vendor-form" class="banka-tms-form"><input type="text" id="vendor-name" placeholder="Vendor name" required /><input type="email" id="vendor-email" placeholder="Email" /><input type="text" id="vendor-mobile" placeholder="Mobile" /><button type="submit">Save Vendor</button></form><div id="banka-tms-vendor-status" class="banka-tms-status"></div><div id="banka-tms-vendor-list"></div></div>
        <?php else : ?>
            <h1 class="banka-tms-page-title"><?php echo esc_html(get_the_title()); ?></h1><div class="banka-tms-card banka-tms-module-card"><h2><?php echo esc_html(get_the_title()); ?></h2><p>This module is ready for its detailed workflow and master data.</p></div>
        <?php endif; ?>
    <?php if (in_array($page_slug, array('tms-users','tms-categories','tms-task-master','tms-designations','tms-tasks','tms-action-log','tms-project-types','tms-sites'), true)) { banka_tms_bulk_import_modal(); } ?>
</main>
</div>
<?php wp_footer(); ?>

<div class="banka-tms-modal" id="banka-tms-meta-template-help-modal" style="display:none">
  <div class="banka-tms-modal-backdrop"></div>
  <div class="banka-tms-modal-card banka-tms-meta-template-card">
    <button type="button" class="banka-tms-modal-close" data-meta-template-close>×</button>
    <h2 class="banka-tms-modal-title">WhatsApp Meta Templates</h2>
    <div class="banka-tms-meta-template-tabs">
      <button type="button" class="is-active" data-meta-template-tab="assignment">Task Assignment Template</button>
      <button type="button" data-meta-template-tab="update">Task Update Template</button>
      <button type="button" data-meta-template-tab="process">How to Add in Meta</button>
    </div>
    <div class="banka-tms-meta-template-panel is-active" data-meta-template-panel="assignment">
      <h3>Template name: <code>archtms_task_assignment</code></h3>
      <pre>A new task has been assigned.

Assigned to: {{1}}
Task name: {{2}}
Assigned by: {{3}}
Project name: {{4}}
Due date: {{5}}
Task notes: {{6}}

Please review the task and complete.</pre>
      <p><strong>Variables:</strong> 1 Assignee Name, 2 Task, 3 Assigned By, 4 Project, 5 Due Date, 6 Notes.</p>
    </div>
    <div class="banka-tms-meta-template-panel" data-meta-template-panel="update">
      <h3>Template name: <code>archtms_task_update</code></h3>
      <pre>Task Updated.

Task name: {{1}}
Updated by: {{2}}
New status: {{3}}
Project name: {{4}}
Due date: {{5}}
Update remarks: {{6}}

Please review the latest task status and remarks.</pre>
      <p><strong>Variables:</strong> 1 Task, 2 Updated By, 3 New Status, 4 Project, 5 Due Date, 6 Remark.</p>
    </div>
    <div class="banka-tms-meta-template-panel" data-meta-template-panel="process">
      <ol>
        <li>Open Meta Business Manager → WhatsApp Manager → Message Templates.</li>
        <li>Create a <strong>Utility</strong> template using the exact template name and body shown here.</li>
        <li>Add variables in the same order. Do not change the parameter sequence.</li>
        <li>Submit both templates for Meta approval.</li>
        <li>After approval, enter Phone Number ID, WABA ID and a permanent access token under Admin Settings → WhatsApp Messaging (Meta).</li>
        <li>Enter the approved template names and language codes, save settings, then create or update a task.</li>
      </ol>
      <p>Meta messages are sent only when the Meta provider and the corresponding Assignment or Update switch are active.</p>
    </div>
  </div>
</div>
</body></html>
<?php exit;