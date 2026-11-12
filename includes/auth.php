<?php
if (!defined('ABSPATH')) { exit; }

function banka_tms_app_users_table() {
    global $wpdb;
    return $wpdb->prefix . 'banka_tms_users_profile';
}


function banka_tms_app_primary_wp_admin_id() {
    $stored = absint(get_option('banka_tms_primary_wp_admin_id', 0));
    if ($stored) {
        $user = get_user_by('id', $stored);
        if ($user && in_array('administrator', (array) $user->roles, true)) {
            return $stored;
        }
    }
    $admins = get_users(array(
        'role' => 'administrator',
        'orderby' => 'ID',
        'order' => 'ASC',
        'number' => 1,
        'fields' => 'ID',
    ));
    $primary = !empty($admins) ? absint($admins[0]) : 0;
    if ($primary) {
        update_option('banka_tms_primary_wp_admin_id', $primary, false);
    }
    return $primary;
}

function banka_tms_app_is_protected_wp_admin($user) {
    if (is_numeric($user)) {
        global $wpdb;
        $user = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . banka_tms_app_users_table() . " WHERE user_id=%d LIMIT 1",
            absint($user)
        ));
    }
    if (!$user || empty($user->wp_user_id)) {
        return false;
    }
    return absint($user->wp_user_id) === banka_tms_app_primary_wp_admin_id();
}

function banka_tms_app_migrate_users() {
    global $wpdb;
    $table = banka_tms_app_users_table();
    $columns = $wpdb->get_col("DESC {$table}", 0);
    if (!$columns) { return; }

    $alter = array(
        'wp_user_id' => "ADD wp_user_id BIGINT UNSIGNED NULL AFTER user_id",
        'name' => "ADD name VARCHAR(190) NULL AFTER wp_user_id",
        'username' => "ADD username VARCHAR(100) NULL AFTER name",
        'email' => "ADD email VARCHAR(190) NULL AFTER username",
        'password_hash' => "ADD password_hash VARCHAR(255) NULL AFTER email",
        'category' => "ADD category VARCHAR(150) NULL AFTER designation",
        'active' => "ADD active TINYINT(1) NOT NULL DEFAULT 1 AFTER category",
        'updated_at' => "ADD updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    );
    foreach ($alter as $column => $sql) {
        if (!in_array($column, $columns, true)) {
            $wpdb->query("ALTER TABLE {$table} {$sql}");
        }
    }

    $indexes = $wpdb->get_results("SHOW INDEX FROM {$table}");
    $index_names = array();
    foreach ($indexes as $index) { $index_names[] = $index->Key_name; }
    if (!in_array('username_unique', $index_names, true)) {
        $wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY username_unique (username)");
    }
    if (!in_array('email_unique', $index_names, true)) {
        $wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY email_unique (email)");
    }

    // Only the original/primary WordPress administrator remains linked to WordPress.
    $primary_admin_id = banka_tms_app_primary_wp_admin_id();
    if (!$primary_admin_id) { return; }

    // Detach every other TaskPro user from WordPress. Do not recreate deleted users.
    $wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET wp_user_id=NULL WHERE wp_user_id IS NOT NULL AND wp_user_id<>%d",
        $primary_admin_id
    ));

    $wp_user = get_user_by('id', $primary_admin_id);
    if (!$wp_user) { return; }

    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE wp_user_id=%d OR username=%s OR email=%s LIMIT 1",
        $primary_admin_id,
        $wp_user->user_login,
        $wp_user->user_email
    ));

    $data = array(
        'wp_user_id' => $primary_admin_id,
        'role' => 'Admin',
        'active' => 1,
    );

    if ($existing) {
        if (empty($existing->name)) { $data['name'] = $wp_user->display_name ?: $wp_user->user_login; }
        if (empty($existing->username)) { $data['username'] = $wp_user->user_login; }
        if (empty($existing->email)) { $data['email'] = $wp_user->user_email; }
        if (empty($existing->password_hash)) { $data['password_hash'] = $wp_user->user_pass; }
        $wpdb->update($table, $data, array('id' => (int) $existing->id));
    } else {
        $candidate_id = (int) $primary_admin_id;
        $id_exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id=%d", $candidate_id));
        if ($id_exists) {
            $candidate_id = max(1, (int) $wpdb->get_var("SELECT COALESCE(MAX(user_id),0)+1 FROM {$table}"));
        }
        $wpdb->insert($table, array(
            'user_id' => $candidate_id,
            'wp_user_id' => $primary_admin_id,
            'name' => $wp_user->display_name ?: $wp_user->user_login,
            'username' => $wp_user->user_login,
            'email' => $wp_user->user_email,
            'password_hash' => $wp_user->user_pass,
            'role' => 'Admin',
            'designation' => get_user_meta($primary_admin_id, 'designation', true),
            'mobile' => get_user_meta($primary_admin_id, 'mobile', true),
            'category' => get_user_meta($primary_admin_id, 'category', true),
            'active' => 1,
        ));
    }
}

function banka_tms_app_user_is_active_meta($wp_user_id) {
    $stored = get_user_meta((int) $wp_user_id, 'banka_tms_active', true);
    return $stored === '' || $stored === '1' || $stored === 1 || $stored === true;
}

function banka_tms_app_user_object($row) {
    if (!$row) { return false; }
    $role_key = strtolower((string) $row->role);
    if ($role_key === 'admin' || $role_key === 'administrator') { $role_key = 'administrator'; }
    elseif ($role_key === 'manager' || $role_key === 'editor') { $role_key = 'editor'; }
    else { $role_key = 'subscriber'; }
    return (object) array(
        'ID' => (int) $row->user_id,
        'id' => (int) $row->user_id,
        'profile_id' => (int) $row->id,
        'wp_user_id' => (int) $row->wp_user_id,
        'display_name' => (string) ($row->name ?: $row->username),
        'user_login' => (string) $row->username,
        'user_email' => (string) $row->email,
        'mobile' => (string) $row->mobile,
        'designation' => (string) $row->designation,
        'category' => (string) $row->category,
        'role' => (string) $row->role,
        'roles' => array($role_key),
        'active' => (int) $row->active === 1,
        'password_hash' => (string) $row->password_hash,
    );
}

function banka_tms_app_get_user($user_id) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . banka_tms_app_users_table() . " WHERE user_id=%d LIMIT 1", (int) $user_id));
    return banka_tms_app_user_object($row);
}

function banka_tms_app_get_users($args = array()) {
    global $wpdb;
    $order = (isset($args['order']) && strtoupper($args['order']) === 'DESC') ? 'DESC' : 'ASC';
    $include_wordpress_users = !empty($args['include_wordpress_users']);
    $where = $include_wordpress_users ? '' : ' WHERE COALESCE(wp_user_id, 0) = 0';
    $rows = $wpdb->get_results("SELECT * FROM " . banka_tms_app_users_table() . $where . " ORDER BY name {$order}, user_id {$order}");
    return array_values(array_filter(array_map('banka_tms_app_user_object', $rows)));
}

function banka_tms_app_session_cookie_name() { return 'banka_tms_app_session'; }

function banka_tms_app_session_storage_key($token) {
    return 'banka_tms_session_' . hash('sha256', (string) $token);
}

/**
 * Create a self-contained signed TaskPro login cookie.
 * Format: user_id|expiry|signature
 */
function banka_tms_app_build_signed_session($user_id, $expires) {
    $payload = absint($user_id) . '|' . absint($expires);
    $signature = hash_hmac('sha256', $payload, wp_salt('auth'));
    return base64_encode($payload . '|' . $signature);
}

function banka_tms_app_parse_signed_session($cookie) {
    $decoded = base64_decode((string) $cookie, true);
    if (!$decoded) { return 0; }
    $parts = explode('|', $decoded);
    if (count($parts) !== 3) { return 0; }
    list($user_id, $expires, $signature) = $parts;
    $user_id = absint($user_id);
    $expires = absint($expires);
    if (!$user_id || !$expires || $expires < time()) { return 0; }
    $payload = $user_id . '|' . $expires;
    $expected = hash_hmac('sha256', $payload, wp_salt('auth'));
    if (!hash_equals($expected, (string) $signature)) { return 0; }
    return $user_id;
}

function banka_tms_app_get_session_user_id() {
    $cookie_name = banka_tms_app_session_cookie_name();
    $cookie = isset($_COOKIE[$cookie_name]) ? trim((string) wp_unslash($_COOKIE[$cookie_name])) : '';
    if ($cookie === '') { return 0; }

    // Current session format: signed, self-contained cookie. This does not
    // depend on transients, object cache, PHP sessions or per-page options.
    $signed_user_id = banka_tms_app_parse_signed_session($cookie);
    if ($signed_user_id) {
        return $signed_user_id;
    }

    // Backward compatibility for token sessions created by older versions.
    $key = banka_tms_app_session_storage_key($cookie);
    $data = get_option($key, false);
    if (!is_array($data) || empty($data['user_id'])) {
        $legacy = get_transient($key);
        if (is_array($legacy) && !empty($legacy['user_id'])) {
            $data = $legacy;
        }
    }
    if (!is_array($data) || empty($data['user_id'])) { return 0; }

    // Upgrade the old token immediately to the signed format.
    $user_id = absint($data['user_id']);
    if ($user_id) {
        banka_tms_app_set_session($user_id);
        delete_option($key);
        delete_transient($key);
    }
    return $user_id;
}

function banka_tms_app_cookie_options($expires) {
    $is_https = is_ssl()
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off');

    return array(
        'expires' => (int) $expires,
        'path' => '/',
        'domain' => '',
        'secure' => $is_https,
        'httponly' => true,
        'samesite' => 'Lax',
    );
}

function banka_tms_app_set_session($user_id) {
    $expires = time() + (5 * YEAR_IN_SECONDS);
    $cookie = banka_tms_app_build_signed_session($user_id, $expires);
    setcookie(banka_tms_app_session_cookie_name(), $cookie, banka_tms_app_cookie_options($expires));
    $_COOKIE[banka_tms_app_session_cookie_name()] = $cookie;
}

function banka_tms_app_clear_session() {
    $cookie_name = banka_tms_app_session_cookie_name();
    $cookie = isset($_COOKIE[$cookie_name]) ? trim((string) wp_unslash($_COOKIE[$cookie_name])) : '';

    // Clean up any legacy server-side token if the browser still has one.
    if ($cookie !== '' && !banka_tms_app_parse_signed_session($cookie)) {
        $key = banka_tms_app_session_storage_key($cookie);
        delete_option($key);
        delete_transient($key);
    }

    setcookie($cookie_name, '', banka_tms_app_cookie_options(time() - HOUR_IN_SECONDS));
    unset($_COOKIE[$cookie_name]);
}

function banka_tms_app_current_user() {
    static $cached = null;
    if ($cached !== null) { return $cached; }

    // TaskPro authentication is completely independent of WordPress login.
    // Only a valid TaskPro signed cookie and an active TaskPro user record
    // can establish an application session.
    $session_user_id = banka_tms_app_get_session_user_id();
    if (!$session_user_id) {
        return $cached = false;
    }

    $session_user = banka_tms_app_get_user($session_user_id);
    if (!$session_user || empty($session_user->active) || !empty($session_user->wp_user_id)) {
        // WordPress-linked/system users are not TaskPro application users.
        banka_tms_app_clear_session();
        return $cached = false;
    }

    return $cached = $session_user;
}

function banka_tms_app_current_user_id() {
    $user = banka_tms_app_current_user();
    return $user ? (int) $user->ID : 0;
}

function banka_tms_app_is_logged_in() {
    $user = banka_tms_app_current_user();
    return $user && !empty($user->active);
}

function banka_tms_app_user_is_active($user_id) {
    $user = banka_tms_app_get_user($user_id);
    return $user ? !empty($user->active) : false;
}

function banka_tms_app_is_admin_user($user = null) {
    if ($user === null) { $user = banka_tms_app_current_user(); }
    if (!$user) { return false; }
    $role = strtolower(trim((string) ($user->role ?? '')));
    return in_array($role, array('admin', 'administrator'), true);
}

function banka_tms_app_can_manage() {
    return banka_tms_app_is_logged_in();
}

function banka_tms_app_can_administer() {
    return banka_tms_app_is_logged_in() && banka_tms_app_is_admin_user();
}

function banka_tms_app_user_can_access_task($task, $user = null) {
    if (!$task) { return false; }
    if ($user === null) { $user = banka_tms_app_current_user(); }
    if (!$user) { return false; }
    if (banka_tms_app_is_admin_user($user)) { return true; }
    $uid = (int) $user->ID;
    return $uid > 0 && ((int) ($task->assignee_id ?? 0) === $uid || (int) ($task->owner_id ?? 0) === $uid || (int) ($task->created_by ?? 0) === $uid);
}

add_filter('user_has_cap', function($allcaps, $caps, $args, $user) {
    if ((!$user || !$user->exists()) && banka_tms_app_is_logged_in()) {
        $allcaps['read'] = true;
        $allcaps['upload_files'] = true;
        if (banka_tms_app_is_admin_user()) {
            $allcaps['manage_options'] = true;
        } else {
            unset($allcaps['manage_options']);
        }
    }
    return $allcaps;
}, 20, 4);

function banka_tms_app_render_login_screen($message = '', $message_type = 'error') {
    $redirect_to = home_url('/tms-dashboard/');
    if (!empty($_SERVER['REQUEST_URI']) && strpos((string) $_SERVER['REQUEST_URI'], 'tms-') !== false) {
        $redirect_to = home_url(wp_unslash($_SERVER['REQUEST_URI']));
    }
    $organization_name = get_option('banka_tms_setting_organization_name', 'TaskPro');
    $organization_logo = get_option('banka_tms_setting_organization_logo', '');
    $brand_name = $organization_name !== '' ? $organization_name : 'TaskPro';
    ?>
    <!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><title>TaskPro — Sign In</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet"><style>
    *{box-sizing:border-box}html,body{margin:0;min-height:100%;font-family:Montserrat,Arial,sans-serif;background:#eef5ff;color:#162033}body{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}.tms-login-shell{width:min(460px,100%);background:#fff;border:1px solid #dfe6f1;border-radius:18px;overflow:hidden;box-shadow:0 28px 70px rgba(31,49,84,.15)}.tms-login-card{padding:54px 52px}.tms-login-logo{min-height:54px;margin-bottom:24px;display:flex;align-items:center;justify-content:center}.tms-login-logo img{display:block;max-width:190px;max-height:64px}.tms-login-wordmark{font-size:30px;font-weight:800;color:#315efb}.tms-login-message{margin:0 0 18px;padding:12px 14px;border-radius:8px;font-size:13px;font-weight:600}.tms-login-message.error{background:#fff1f1;color:#b42318;border:1px solid #fecaca}.tms-login-field{display:block;margin-bottom:17px}.tms-login-field span{display:block;margin-bottom:8px;font-size:13px;font-weight:600}.tms-login-field input{width:100%;height:46px;padding:0 14px;border:1px solid #d6dce7;border-radius:7px;background:#fff;font:inherit}.tms-login-button{width:100%;height:46px;border:0;border-radius:7px;background:linear-gradient(100deg,#376ff2,#8534ef);color:#fff;font:inherit;font-weight:700}.tms-login-credit{text-align:center;margin-top:24px;color:#98a2b3;font-size:12px}.tms-login-credit a{color:inherit;text-decoration:none}.tms-login-credit a:hover{text-decoration:underline}@media(max-width:760px){body{padding:12px}.tms-login-card{padding:38px 24px}}
    </style></head><body><main class="tms-login-shell"><section class="tms-login-card"><div class="tms-login-logo"><?php if ($organization_logo) : ?><img src="<?php echo esc_url($organization_logo); ?>" alt="<?php echo esc_attr($brand_name); ?>"><?php else : ?><div class="tms-login-wordmark">TaskPro</div><?php endif; ?></div><?php if ($message !== '') : ?><div class="tms-login-message <?php echo esc_attr($message_type); ?>"><?php echo esc_html($message); ?></div><?php endif; ?><form method="post" autocomplete="on"><?php wp_nonce_field('banka_tms_custom_login', 'banka_tms_login_nonce'); ?><input type="hidden" name="banka_tms_login_action" value="login"><input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect_to); ?>"><label class="tms-login-field"><span>Username or Email</span><input type="text" name="log" autocomplete="username" required autofocus></label><label class="tms-login-field"><span>Password</span><input type="password" name="pwd" autocomplete="current-password" required></label><button class="tms-login-button" type="submit">Sign In</button></form><div class="tms-login-credit"><a href="https://bizskilledu.com/" target="_blank" rel="noopener noreferrer">Developed by BizSkill</a></div></section></main></body></html>
    <?php exit;
}

function banka_tms_app_require_login() {
    if (isset($_GET['banka_tms_logout'])) {
        banka_tms_app_clear_session();
        wp_safe_redirect(home_url('/'));
        exit;
    }
    if (banka_tms_app_is_logged_in()) { return; }
    $message = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['banka_tms_login_action'])) {
        $nonce = sanitize_text_field(wp_unslash($_POST['banka_tms_login_nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'banka_tms_custom_login')) {
            $message = 'Your session expired. Please try again.';
        } else {
            global $wpdb;
            $login = sanitize_text_field(wp_unslash($_POST['log'] ?? ''));
            $password = (string) wp_unslash($_POST['pwd'] ?? '');
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . banka_tms_app_users_table() . " WHERE username=%s OR email=%s LIMIT 1", $login, $login));
            $user = banka_tms_app_user_object($row);
            if (!$user || !$user->active || !wp_check_password($password, $user->password_hash, $user->ID)) {
                $message = 'Invalid username/email or password.';
            } else {
                banka_tms_app_set_session($user->ID);
                $redirect_to = esc_url_raw(wp_unslash($_POST['redirect_to'] ?? home_url('/tms-dashboard/')));
                wp_safe_redirect($redirect_to ?: home_url('/tms-dashboard/'));
                exit;
            }
        }
    }
    banka_tms_app_render_login_screen($message);
}

function banka_tms_app_render_auth() { if (!banka_tms_app_is_logged_in()) { banka_tms_app_render_login_screen(); } }
function banka_tms_app_block_inactive_user_login($user, $password) { return $user; }
