<?php
if (!defined('ABSPATH')) {
    exit;
}

function banka_tms_app_create_tables() {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    $sql = array();

    $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}banka_tms_tasks (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        title VARCHAR(255) NOT NULL,
        description TEXT NULL,
        remarks TEXT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'Pending',
        assignee_id BIGINT UNSIGNED NULL,
        owner_id BIGINT UNSIGNED NULL,
        category_name VARCHAR(150) NULL,
        priority_name VARCHAR(50) NULL,
        due_date DATE NULL,
        next_action_date DATE NULL,
        project_name VARCHAR(255) NULL,
        created_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        source_task_id VARCHAR(64) NULL,
        PRIMARY KEY (id),
        UNIQUE KEY source_task_id (source_task_id)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}banka_tms_users_profile (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        wp_user_id BIGINT UNSIGNED NULL,
        name VARCHAR(190) NULL,
        username VARCHAR(100) NULL,
        email VARCHAR(190) NULL,
        password_hash VARCHAR(255) NULL,
        role VARCHAR(50) NOT NULL DEFAULT 'Employee',
        designation VARCHAR(100) NULL,
        category VARCHAR(150) NULL,
        mobile VARCHAR(30) NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY username_unique (username),
        UNIQUE KEY email_unique (email)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}banka_tms_designations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(150) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY name (name)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}banka_tms_categories (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(150) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY name (name)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}banka_tms_task_master (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY name (name)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}banka_tms_projects (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        project_code VARCHAR(100) NOT NULL,
        project_name VARCHAR(255) NOT NULL,
        client_name VARCHAR(255) NULL,
        category VARCHAR(150) NULL,
        project_type VARCHAR(150) NULL,
        address VARCHAR(255) NULL,
        telegram_group_id VARCHAR(100) NULL,
        architect_1 VARCHAR(255) NULL,
        architect_2 VARCHAR(255) NULL,
        asst_architect VARCHAR(255) NULL,
        structural_inhouse VARCHAR(255) NULL,
        structural_consultant VARCHAR(255) NULL,
        mep_consultant VARCHAR(255) NULL,
        landscape_consultant VARCHAR(255) NULL,
        views_3d VARCHAR(255) NULL,
        working VARCHAR(255) NULL,
        permission_drawing VARCHAR(255) NULL,
        site_engineer VARCHAR(255) NULL,
        status_1 TEXT NULL,
        status_2 TEXT NULL,
        status_3 TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY project_code (project_code)
    ) $charset_collate;";


    $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}banka_tms_project_types (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(150) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY name (name)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}banka_tms_project_categories (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(150) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY name (name)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}banka_tms_action_logs (
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
        source_action_id VARCHAR(64) NULL,
        PRIMARY KEY (id),
        UNIQUE KEY source_action_id (source_action_id),
        KEY task_id (task_id),
        KEY action_name (action_name)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    foreach ($sql as $query) {
        dbDelta($query);
    }

    // dbDelta can fail to add columns to an existing table on some shared
    // hosting configurations. Verify every Project column explicitly.
    banka_tms_ensure_project_columns();

    // Master data is intentionally not seeded here.
    // This function runs on every TaskPro request for schema repair, so inserting
    // default records here would recreate records that an administrator deleted.
    // Fresh installations can add master data manually or through bulk upload.


}



/**
 * Ensure all extended Project fields exist on existing installations.
 * This uses explicit SHOW COLUMNS + ALTER TABLE instead of relying only on
 * dbDelta, which is unreliable for some Hostinger/shared-hosting schemas.
 *
 * @return true|WP_Error
 */
function banka_tms_ensure_project_columns() {
    global $wpdb;

    $table = $wpdb->prefix . 'banka_tms_projects';
    $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($table_exists !== $table) {
        return new WP_Error('projects_table_missing', 'Projects table was not found.');
    }

    $definitions = array(
        'telegram_group_id'     => 'VARCHAR(100) NULL',
        'architect_1'           => 'VARCHAR(255) NULL',
        'architect_2'           => 'VARCHAR(255) NULL',
        'asst_architect'        => 'VARCHAR(255) NULL',
        'structural_inhouse'    => 'VARCHAR(255) NULL',
        'structural_consultant' => 'VARCHAR(255) NULL',
        'mep_consultant'        => 'VARCHAR(255) NULL',
        'landscape_consultant'  => 'VARCHAR(255) NULL',
        'views_3d'              => 'VARCHAR(255) NULL',
        'working'               => 'VARCHAR(255) NULL',
        'permission_drawing'    => 'VARCHAR(255) NULL',
        'site_engineer'         => 'VARCHAR(255) NULL',
        'status_1'              => 'TEXT NULL',
        'status_2'              => 'TEXT NULL',
        'status_3'              => 'TEXT NULL',
    );

    foreach ($definitions as $column => $definition) {
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s LIMIT 1",
            $table,
            $column
        ));
        if ($exists) {
            continue;
        }

        // Column names and definitions are controlled constants above.
        $result = $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        if ($result === false) {
            return new WP_Error(
                'project_column_creation_failed',
                sprintf('Unable to create Project field %s: %s', $column, $wpdb->last_error ?: 'Database error')
            );
        }
    }

    update_option('banka_tms_project_columns_ready_v3015', 1, false);
    return true;
}

/**
 * Migrate legacy serialized project metadata from wp_options into dedicated
 * columns in the Projects table. The migration is idempotent and runs once.
 */
function banka_tms_migrate_project_meta_to_columns() {
    global $wpdb;

    $migration_key = 'banka_tms_project_meta_columns_migrated_v3015';
    if (get_option($migration_key)) {
        return;
    }

    $table = $wpdb->prefix . 'banka_tms_projects';

    $schema_ready = banka_tms_ensure_project_columns();
    if (is_wp_error($schema_ready)) {
        return;
    }

    $legacy = get_option('banka_tms_project_meta', array());
    if (!is_array($legacy) || empty($legacy)) {
        $legacy = get_option('banka_tms_project_meta_migrated_backup', array());
    }
    if (!is_array($legacy) || empty($legacy)) {
        update_option($migration_key, 1, false);
        return;
    }

    // Preserve one backup before removing the live serialized option.
    if (get_option('banka_tms_project_meta_migrated_backup', null) === null) {
        add_option('banka_tms_project_meta_migrated_backup', $legacy, '', false);
    }

    $column_formats = array(
        'telegram_group_id' => '%s', 'architect_1' => '%s', 'architect_2' => '%s',
        'asst_architect' => '%s', 'structural_inhouse' => '%s',
        'structural_consultant' => '%s', 'mep_consultant' => '%s',
        'landscape_consultant' => '%s', 'views_3d' => '%s', 'working' => '%s',
        'permission_drawing' => '%s', 'site_engineer' => '%s',
        'status_1' => '%s', 'status_2' => '%s', 'status_3' => '%s',
    );

    $all_ok = true;
    foreach ($legacy as $project_id => $meta) {
        $project_id = absint($project_id);
        if (!$project_id || !is_array($meta)) {
            continue;
        }
        $data = array();
        $formats = array();
        foreach ($column_formats as $column => $format) {
            if (array_key_exists($column, $meta)) {
                $data[$column] = sanitize_text_field((string) $meta[$column]);
                $formats[] = $format;
            }
        }
        if (!$data) {
            continue;
        }
        $result = $wpdb->update($table, $data, array('id' => $project_id), $formats, array('%d'));
        if ($result === false) {
            $all_ok = false;
        }
    }

    if ($all_ok) {
        delete_option('banka_tms_project_meta');
        update_option($migration_key, 1, false);
    }
}

/**
 * Remove legacy demo projects that were recreated by older versions.
 * Only exact legacy project codes with no linked task are removed, and this
 * cleanup runs once. Projects that are actually used by tasks are preserved.
 */
function banka_tms_cleanup_legacy_seed_projects() {
    global $wpdb;

    $option_key = 'banka_tms_legacy_project_seed_cleanup_v3010';
    if (get_option($option_key)) {
        return;
    }

    $projects_table = $wpdb->prefix . 'banka_tms_projects';
    $tasks_table = $wpdb->prefix . 'banka_tms_tasks';
    $legacy_codes = array(
        'BA-ARCH-2025-001',
        'BA-ARCH-2025-002',
        'BA-ARCH-2025-003',
        'BA-ARCH-2025-004',
        'BA-ARCH-2025-005',
        'BA-ARCH-2025-006',
    );

    foreach ($legacy_codes as $project_code) {
        $project = $wpdb->get_row($wpdb->prepare(
            "SELECT id, project_name FROM {$projects_table} WHERE project_code = %s LIMIT 1",
            $project_code
        ));
        if (!$project) {
            continue;
        }

        $linked_tasks = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$tasks_table} WHERE project_name = %s",
            (string) $project->project_name
        ));
        if ($linked_tasks === 0) {
            $wpdb->delete($projects_table, array('id' => (int) $project->id), array('%d'));
        }
    }

    update_option($option_key, 1, false);
}


/**
 * Create one sample TaskPro user for demonstration/testing.
 * This runs only once per WordPress installation and does not recreate the
 * account after an administrator intentionally deletes it.
 */
function banka_tms_app_create_dummy_user() {
    global $wpdb;

    $option_key = 'banka_tms_dummy_user_admin_v303';
    if (get_option($option_key)) {
        return;
    }

    $users_table = $wpdb->prefix . 'banka_tms_users_profile';
    $designations_table = $wpdb->prefix . 'banka_tms_designations';

    // Keep the sample designation available for the Users form.
    $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$designations_table} (name, created_at) VALUES (%s, %s)",
        'User',
        current_time('mysql')
    ));

    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$users_table} WHERE username = %s OR email = %s LIMIT 1",
        'dummyuser',
        'dummyuser@archtms.local'
    ));

    if ($existing) {
        $wpdb->update(
            $users_table,
            array(
                'name'          => 'Dummy User',
                'username'      => 'dummyuser',
                'email'         => 'dummyuser@archtms.local',
                'password_hash' => wp_hash_password('Demo@12345'),
                'role'          => 'Admin',
                'designation'   => 'User',
                'mobile'        => '9999999999',
                'active'        => 1,
                'updated_at'    => current_time('mysql'),
            ),
            array('id' => (int) $existing),
            array('%s','%s','%s','%s','%s','%s','%s','%d','%s'),
            array('%d')
        );
    } else {
        $next_user_id = (int) $wpdb->get_var("SELECT COALESCE(MAX(user_id), 0) + 1 FROM {$users_table}");
        if ($next_user_id < 1) {
            $next_user_id = 1;
        }

        $inserted = $wpdb->insert(
            $users_table,
            array(
                'user_id'       => $next_user_id,
                'wp_user_id'    => null,
                'name'          => 'Dummy User',
                'username'      => 'dummyuser',
                'email'         => 'dummyuser@archtms.local',
                'password_hash' => wp_hash_password('Demo@12345'),
                'role'          => 'Admin',
                'designation'   => 'User',
                'category'      => '',
                'mobile'        => '9999999999',
                'active'        => 1,
                'created_at'    => current_time('mysql'),
                'updated_at'    => current_time('mysql'),
            ),
            array('%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s')
        );

        if ($inserted === false) {
            return;
        }
    }

    update_option($option_key, 1, false);
}
