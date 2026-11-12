<?php
if (!defined('ABSPATH')) { exit; }

add_action('admin_post_banka_tms_bulk_template', 'banka_tms_bulk_template_download');
add_action('admin_post_nopriv_banka_tms_bulk_template', 'banka_tms_bulk_template_download');
add_action('wp_ajax_banka_tms_bulk_upload', 'banka_tms_bulk_upload');
add_action('wp_ajax_nopriv_banka_tms_bulk_upload', 'banka_tms_bulk_upload');

function banka_tms_bulk_types() {
    return array(
        'task_master' => array('label'=>'Task Master','headers'=>array('Task Name')),
        'users' => array('label'=>'Users','headers'=>array('Full Name','Username','Email','Password','Mobile','Designation','Role','Active')),
        'designations' => array('label'=>'Designations','headers'=>array('Designation Name')),
        'categories' => array('label'=>'Categories','headers'=>array('Category Name')),
        'project_types' => array('label'=>'Project Types','headers'=>array('Project Type')),
        'projects' => array('label'=>'Projects','headers'=>array('Project ID','Project Name','Client Name','Category','Project Type','Address','Telegram Group ID','Architect / Interior 1','Architect / Interior 2','Assistant Architect / Interior','In-house Structural','Structural Consultant','MEP Consultant','Landscape Consultant','3D Views','Working Drawing','Permission Drawing','Site Engineer','Project Status 1','Project Status 2','Project Status 3')),
        'tasks' => array('label'=>'Tasks','headers'=>array('id','task','notes','priority','Category','Project ID','due_date','status','last_update_remark','Owner','Assignee')),
        'action_logs' => array('label'=>'Action Log','headers'=>array('id','task_id','user_id','User Name','status','remark','created_at')),
    );
}

function banka_tms_bulk_can_manage() {
    return function_exists('banka_tms_app_is_logged_in') && banka_tms_app_is_logged_in()
        && function_exists('banka_tms_app_can_manage') && banka_tms_app_can_manage();
}

function banka_tms_bulk_template_download() {
    if (!banka_tms_bulk_can_manage()) { wp_die('Access denied', 403); }
    $type = sanitize_key($_GET['type'] ?? '');
    $types = banka_tms_bulk_types();
    if (!isset($types[$type])) { wp_die('Invalid template type', 400); }

    nocache_headers();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . sanitize_file_name('ArchTMS-' . $types[$type]['label'] . '-Template.csv') . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, $types[$type]['headers']);
    if ($type === 'users') {
        fputcsv($out, array('Example User','example.user','example@example.com','ChangeMe123','9876543210','User','Employee','Yes'));
    } elseif ($type === 'projects') {
        fputcsv($out, array('BA-ARCH-2026-001','Sample Project','Sample Client','Architect','COMMERCIAL','Guwahati','-1001234567890','Architect One','Architect Two','Assistant Architect','In-house Engineer','Structural Consultant','MEP Consultant','Landscape Consultant','3D Team','Working Drawing Team','Permission Drawing Team','Site Engineer','Concept approved','Working drawings in progress','Site work started'));
    } elseif ($type === 'tasks') {
        fputcsv($out, array('source-task-uuid','Sample Task','Task notes','Medium','Structural','BA-ARCH-2026-001','2026-08-15','Not Yet Started','','Owner Name','Assignee Name'));
    } elseif ($type === 'action_logs') {
        fputcsv($out, array('source-action-uuid','source-task-uuid','old-user-id','User Name','Created','Task created.','2026-07-23 06:20:53.446793+00'));
    }

    fclose($out);
    exit;
}

function banka_tms_bulk_normalize_header($value) {
    $value = preg_replace('/^\xEF\xBB\xBF/', '', (string)$value);
    return strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $value)));
}

function banka_tms_bulk_ensure_import_columns() {
    global $wpdb;
    $tasks = $wpdb->prefix . 'banka_tms_tasks';
    $logs = $wpdb->prefix . 'banka_tms_action_logs';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tasks)) === $tasks) {
        if (!$wpdb->get_var("SHOW COLUMNS FROM `{$tasks}` LIKE 'source_task_id'")) {
            $wpdb->query("ALTER TABLE `{$tasks}` ADD COLUMN source_task_id VARCHAR(64) NULL AFTER updated_at");
        }
        if (!$wpdb->get_var("SHOW INDEX FROM `{$tasks}` WHERE Key_name='source_task_id'")) {
            $wpdb->query("ALTER TABLE `{$tasks}` ADD UNIQUE KEY source_task_id (source_task_id)");
        }
    }
    if (function_exists('banka_tms_ensure_action_log_table')) { banka_tms_ensure_action_log_table(); }
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $logs)) === $logs) {
        if (!$wpdb->get_var("SHOW COLUMNS FROM `{$logs}` LIKE 'source_action_id'")) {
            $wpdb->query("ALTER TABLE `{$logs}` ADD COLUMN source_action_id VARCHAR(64) NULL AFTER created_at");
        }
        if (!$wpdb->get_var("SHOW INDEX FROM `{$logs}` WHERE Key_name='source_action_id'")) {
            $wpdb->query("ALTER TABLE `{$logs}` ADD UNIQUE KEY source_action_id (source_action_id)");
        }
    }
}

function banka_tms_bulk_find_user_by_name($name) {
    global $wpdb;
    $name = trim(preg_replace('/\s+/', ' ', (string)$name));
    if ($name === '') { return false; }
    $table = function_exists('banka_tms_app_users_table') ? banka_tms_app_users_table() : $wpdb->prefix . 'banka_tms_users_profile';
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table} WHERE LOWER(TRIM(name))=LOWER(TRIM(%s)) OR LOWER(TRIM(username))=LOWER(TRIM(%s)) LIMIT 1",
        $name, $name
    ));
}

function banka_tms_bulk_normalize_task_status($status) {
    $key = strtolower(trim((string)$status));
    if (in_array($key, array('not yet started','pending','created'), true)) { return 'Pending'; }
    if ($key === 'in progress') { return 'In Progress'; }
    if ($key === 'completed') { return 'Completed'; }
    return '';
}

function banka_tms_bulk_parse_created_at($value) {
    $value = trim((string) $value);
    if ($value === '') { return ''; }

    try {
        // Supabase/PostgreSQL exports commonly use values such as:
        // 2026-07-23 06:20:53.446793+00
        $dt = new DateTimeImmutable($value);
        $timezone = function_exists('banka_tms_ist_timezone') ? banka_tms_ist_timezone() : wp_timezone();
        return $dt->setTimezone($timezone)->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return false;
    }
}

function banka_tms_bulk_upload() {
    if (!banka_tms_bulk_can_manage()) { wp_send_json_error(array('message'=>'Access denied'), 403); }
    $type = sanitize_key($_POST['type'] ?? '');
    $types = banka_tms_bulk_types();
    if (!isset($types[$type])) { wp_send_json_error(array('message'=>'Invalid upload type'), 400); }
    // The active ArchTMS session and Admin permission are validated above.
    // Do not block bulk upload because a WordPress nonce expired while the
    // user remained signed in to ArchTMS.
    if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) { wp_send_json_error(array('message'=>'Please select a CSV template file.'), 400); }
    if ((int)$_FILES['file']['size'] > 2 * 1024 * 1024) { wp_send_json_error(array('message'=>'Upload file must be smaller than 2 MB.'), 400); }
    $extension = strtolower(pathinfo((string)$_FILES['file']['name'], PATHINFO_EXTENSION));
    if ($extension !== 'csv') { wp_send_json_error(array('message'=>'Please upload the downloaded CSV template.'), 400); }

    $handle = fopen($_FILES['file']['tmp_name'], 'r');
    if (!$handle) { wp_send_json_error(array('message'=>'Unable to read the uploaded file.'), 400); }
    $header = fgetcsv($handle);
    if (!$header) { fclose($handle); wp_send_json_error(array('message'=>'The uploaded file is empty.'), 400); }
    $header_map = array();
    foreach ($header as $index=>$name) { $header_map[banka_tms_bulk_normalize_header($name)] = $index; }
    $required_headers = array_map('banka_tms_bulk_normalize_header', $types[$type]['headers']);
    $missing = array();
    foreach ($required_headers as $key) { if (!array_key_exists($key, $header_map)) { $missing[] = $key; } }
    if ($missing) { fclose($handle); wp_send_json_error(array('message'=>'Template columns are missing: ' . implode(', ', $missing)), 400); }

    global $wpdb;
    banka_tms_bulk_ensure_import_columns();
    $errors = array(); $inserted = 0; $row_number = 1; $seen = array();
    while (($row = fgetcsv($handle)) !== false) {
        $row_number++;
        if ($row_number > 2001) { $errors[] = array('row'=>$row_number,'record'=>'','error'=>'Maximum 2,000 data rows are allowed.'); break; }
        $all_blank = true; foreach ($row as $cell) { if (trim((string)$cell) !== '') { $all_blank=false; break; } }
        if ($all_blank) { continue; }
        $get = function($name) use ($row,$header_map) { $key=banka_tms_bulk_normalize_header($name); return trim((string)($row[$header_map[$key]] ?? '')); };
        $record = ''; $row_errors = array();

        if ($type === 'task_master' || $type === 'designations' || $type === 'categories' || $type === 'project_types') {
            $field = $type === 'task_master' ? 'Task Name' : ($type === 'designations' ? 'Designation Name' : ($type === 'categories' ? 'Category Name' : 'Project Type'));
            $name = sanitize_text_field($get($field)); $record = $name;
            if ($name === '') { $row_errors[] = $field . ' is required.'; }
            $duplicate_key = strtolower($name);
            if ($name !== '' && isset($seen[$duplicate_key])) { $row_errors[] = 'Duplicate in upload file (also on row ' . $seen[$duplicate_key] . ').'; }
            else if ($name !== '') { $seen[$duplicate_key] = $row_number; }
            $table = $type === 'task_master' ? $wpdb->prefix.'banka_tms_task_master' : ($type === 'designations' ? $wpdb->prefix.'banka_tms_designations' : ($type === 'categories' ? $wpdb->prefix.'banka_tms_categories' : $wpdb->prefix.'banka_tms_project_types'));
            if ($name !== '' && $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE LOWER(name)=LOWER(%s) LIMIT 1", $name))) { $row_errors[] = 'Already exists in ArchTMS.'; }
            if (!$row_errors) {
                $ok = $wpdb->insert($table, array('name'=>$name), array('%s'));
                if ($ok === false) { $row_errors[] = $wpdb->last_error ?: 'Database insert failed.'; } else { $inserted++; }
            }
        } elseif ($type === 'projects') {
            if (function_exists('banka_tms_ensure_project_columns')) { banka_tms_ensure_project_columns(); }
            $project_code=sanitize_text_field($get('Project ID'));
            $project_name=sanitize_text_field($get('Project Name'));
            $client_name=sanitize_text_field($get('Client Name'));
            $category=sanitize_text_field($get('Category'));
            $project_type=sanitize_text_field($get('Project Type'));
            $address=sanitize_textarea_field($get('Address'));
            $telegram_group_id=sanitize_text_field($get('Telegram Group ID'));
            $architect_1=sanitize_text_field($get('Architect / Interior 1'));
            $architect_2=sanitize_text_field($get('Architect / Interior 2'));
            $asst_architect=sanitize_text_field($get('Assistant Architect / Interior'));
            $structural_inhouse=sanitize_text_field($get('In-house Structural'));
            $structural_consultant=sanitize_text_field($get('Structural Consultant'));
            $mep_consultant=sanitize_text_field($get('MEP Consultant'));
            $landscape_consultant=sanitize_text_field($get('Landscape Consultant'));
            $views_3d=sanitize_text_field($get('3D Views'));
            $working=sanitize_text_field($get('Working Drawing'));
            $permission_drawing=sanitize_text_field($get('Permission Drawing'));
            $site_engineer=sanitize_text_field($get('Site Engineer'));
            $status_1=sanitize_textarea_field($get('Project Status 1'));
            $status_2=sanitize_textarea_field($get('Project Status 2'));
            $status_3=sanitize_textarea_field($get('Project Status 3'));
            $record=$project_code ?: $project_name;
            if ($project_code==='') $row_errors[]='Project ID is required.';
            if ($project_name==='') $row_errors[]='Project Name is required.';
            if ($client_name==='') $row_errors[]='Client Name is required.';
            if ($category==='') $row_errors[]='Category is required.';
            $file_key='project_id:'.strtolower(trim($project_code));
            if ($project_code!=='' && isset($seen[$file_key])) $row_errors[]='Duplicate Project ID in upload file (also on row '.$seen[$file_key].').';
            elseif ($project_code!=='') $seen[$file_key]=$row_number;
            $combo_key='project_client_address:'.strtolower(trim($project_name)).'|'.strtolower(trim($client_name)).'|'.strtolower(trim($address));
            if ($project_name!=='' && $client_name!=='' && isset($seen[$combo_key])) $row_errors[]='Duplicate Project Name + Client Name + Address combination in upload file (also on row '.$seen[$combo_key].').';
            elseif ($project_name!=='' && $client_name!=='') $seen[$combo_key]=$row_number;
            $projects_table=$wpdb->prefix.'banka_tms_projects';
            if ($project_code!=='' && $wpdb->get_var($wpdb->prepare("SELECT id FROM {$projects_table} WHERE LOWER(project_code)=LOWER(%s) LIMIT 1",$project_code))) $row_errors[]='Project ID already exists in ArchTMS.';
            if ($project_name!=='' && $client_name!=='' && $wpdb->get_var($wpdb->prepare("SELECT id FROM {$projects_table} WHERE LOWER(TRIM(project_name))=LOWER(TRIM(%s)) AND LOWER(TRIM(COALESCE(client_name, '')))=LOWER(TRIM(%s)) AND LOWER(TRIM(COALESCE(address, '')))=LOWER(TRIM(%s)) LIMIT 1",$project_name,$client_name,$address))) $row_errors[]='Project Name + Client Name + Address combination already exists in ArchTMS.';
            if ($project_type!=='' && !$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}banka_tms_project_types WHERE LOWER(name)=LOWER(%s) LIMIT 1",$project_type))) $row_errors[]='Project Type does not exist.';
            if ($category!=='' && !$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}banka_tms_project_categories WHERE LOWER(name)=LOWER(%s) LIMIT 1",$category))) $row_errors[]='Project Category does not exist.';
            if (!$row_errors) {
                $project_data=array(
                    'project_code'=>$project_code,'project_name'=>$project_name,'client_name'=>$client_name,
                    'category'=>$category,'project_type'=>$project_type,'address'=>$address,
                    'telegram_group_id'=>$telegram_group_id,'architect_1'=>$architect_1,'architect_2'=>$architect_2,
                    'asst_architect'=>$asst_architect,'structural_inhouse'=>$structural_inhouse,
                    'structural_consultant'=>$structural_consultant,'mep_consultant'=>$mep_consultant,
                    'landscape_consultant'=>$landscape_consultant,'views_3d'=>$views_3d,'working'=>$working,
                    'permission_drawing'=>$permission_drawing,'site_engineer'=>$site_engineer,
                    'status_1'=>$status_1,'status_2'=>$status_2,'status_3'=>$status_3
                );
                $project_formats=array_fill(0,count($project_data),'%s');
                $ok=$wpdb->insert($projects_table,$project_data,$project_formats);
                if ($ok===false) $row_errors[]=$wpdb->last_error?:'Database insert failed.'; else $inserted++;
            }
        } elseif ($type === 'tasks') {
            $source_task_id=sanitize_text_field($get('id'));
            $title=sanitize_text_field($get('task'));
            $notes=sanitize_textarea_field($get('notes'));
            $priority=sanitize_text_field($get('priority')) ?: 'Medium';
            $category=sanitize_text_field($get('Category'));
            $project_code=sanitize_text_field($get('Project ID'));
            $due_date=sanitize_text_field($get('due_date'));
            $status_raw=sanitize_text_field($get('status'));
            $last_remark=sanitize_textarea_field($get('last_update_remark'));
            $owner_name=sanitize_text_field($get('Owner'));
            $assignee_name=sanitize_text_field($get('Assignee'));
            $record=$title ?: $source_task_id;

            if ($source_task_id==='') $row_errors[]='Source Task ID is required.';
            if ($title==='') $row_errors[]='Task is required.';
            if ($category==='') $row_errors[]='Category is required.';
            if ($project_code==='') $row_errors[]='Project ID is required.';
            if ($due_date==='' || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$due_date)) $row_errors[]='Due Date must be YYYY-MM-DD.';
            $status=banka_tms_bulk_normalize_task_status($status_raw);
            if ($status==='') $row_errors[]='Status must be Not Yet Started, In Progress or Completed.';
            if (!in_array(strtolower($priority),array('low','medium','high'),true)) $row_errors[]='Priority must be Low, Medium or High.';

            $file_key='source_task:'.strtolower($source_task_id);
            if ($source_task_id!=='' && isset($seen[$file_key])) $row_errors[]='Duplicate Source Task ID in upload file.';
            elseif ($source_task_id!=='') $seen[$file_key]=$row_number;

            $tasks_table=$wpdb->prefix.'banka_tms_tasks';
            if ($source_task_id!=='' && $wpdb->get_var($wpdb->prepare("SELECT id FROM {$tasks_table} WHERE source_task_id=%s LIMIT 1",$source_task_id))) $row_errors[]='Source Task ID already imported.';
            if ($category!=='' && !$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}banka_tms_categories WHERE LOWER(TRIM(name))=LOWER(TRIM(%s)) LIMIT 1",$category))) $row_errors[]='Category does not exist.';

            $project=$project_code!=='' ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}banka_tms_projects WHERE LOWER(TRIM(project_code))=LOWER(TRIM(%s)) LIMIT 1",$project_code)) : false;
            $owner=banka_tms_bulk_find_user_by_name($owner_name);
            $assignee=(strcasecmp(trim($assignee_name),'#N/A')===0) ? false : banka_tms_bulk_find_user_by_name($assignee_name);

            // Requested silent skips: invalid #N/A assignee, missing project, missing owner/assignee user.
            if (strcasecmp(trim($assignee_name),'#N/A')===0 || !$project || !$owner || !$assignee) { continue; }

            if (!$row_errors) {
                $project_label=trim((string)$project->project_name);
                if (trim((string)$project->address)!=='') $project_label.=' ('.trim((string)$project->address).')';
                $data=array(
                    'title'=>$title,'description'=>$notes,'remarks'=>$last_remark,'status'=>$status,
                    'assignee_id'=>(int)$assignee->user_id,'owner_id'=>(int)$owner->user_id,
                    'category_name'=>$category,'priority_name'=>ucfirst(strtolower($priority)),
                    'due_date'=>$due_date,'next_action_date'=>null,'project_name'=>$project_label,
                    'created_by'=>(int)$owner->user_id,'created_at'=>current_time('mysql'),
                    'updated_at'=>current_time('mysql'),'source_task_id'=>$source_task_id
                );
                $ok=$wpdb->insert($tasks_table,$data,array('%s','%s','%s','%s','%d','%d','%s','%s','%s','%s','%s','%d','%s','%s','%s'));
                if ($ok===false) $row_errors[]=$wpdb->last_error?:'Database insert failed.'; else $inserted++;
            }
        } elseif ($type === 'action_logs') {
            $source_action_id=sanitize_text_field($get('id'));
            $source_task_id=sanitize_text_field($get('task_id'));
            $user_name=sanitize_text_field($get('User Name'));
            $action=sanitize_text_field($get('status'));
            $remark=sanitize_textarea_field($get('remark'));
            $created_at_raw=$get('created_at');
            $created_at=banka_tms_bulk_parse_created_at($created_at_raw);
            $record=$source_task_id;
            if ($source_action_id==='') $row_errors[]='Source Action ID is required.';
            if ($source_task_id==='') $row_errors[]='Source Task ID is required.';
            if (!in_array(strtolower($action),array('created','not yet started','in progress','completed'),true)) $row_errors[]='Invalid status.';
            if ($created_at_raw==='') $row_errors[]='created_at is required.';
            elseif ($created_at===false) $row_errors[]='created_at is invalid.';
            $file_key='source_action:'.strtolower($source_action_id);
            if ($source_action_id!=='' && isset($seen[$file_key])) $row_errors[]='Duplicate Source Action ID in upload file.';
            elseif ($source_action_id!=='') $seen[$file_key]=$row_number;

            $tasks_table=$wpdb->prefix.'banka_tms_tasks';
            $logs_table=$wpdb->prefix.'banka_tms_action_logs';
            $task=$source_task_id!=='' ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$tasks_table} WHERE source_task_id=%s LIMIT 1",$source_task_id)) : false;
            $user=banka_tms_bulk_find_user_by_name($user_name);
            // Requested silent skips: missing corresponding task or user.
            if (!$task || !$user) { continue; }
            if ($source_action_id!=='' && $wpdb->get_var($wpdb->prepare("SELECT id FROM {$logs_table} WHERE source_action_id=%s LIMIT 1",$source_action_id))) $row_errors[]='Source Action ID already imported.';
            if (!$row_errors) {
                $owner=!empty($task->owner_id)?banka_tms_app_get_user((int)$task->owner_id):false;
                $assignee=!empty($task->assignee_id)?banka_tms_app_get_user((int)$task->assignee_id):false;
                $action_name=(strcasecmp($action,'Not Yet Started')===0)?'Not Yet Started':$action;
                $ok=$wpdb->insert($logs_table,array(
                    'task_id'=>(int)$task->id,'task_title'=>$task->title,'action_name'=>$action_name,
                    'remarks'=>$remark,'owner_names'=>$owner?$owner->display_name:'—',
                    'assignee_names'=>$assignee?$assignee->display_name:'—','project_name'=>$task->project_name,
                    'next_action_date'=>null,'created_at'=>$created_at,'source_action_id'=>$source_action_id
                ),array('%d','%s','%s','%s','%s','%s','%s','%s','%s','%s'));
                if ($ok===false) $row_errors[]=$wpdb->last_error?:'Database insert failed.'; else $inserted++;
            }
        } else {
            $name=sanitize_text_field($get('Full Name')); $username=sanitize_user($get('Username'),true); $email=sanitize_email($get('Email'));
            $password=(string)$get('Password'); $mobile=preg_replace('/\D+/','',$get('Mobile')); $designation=sanitize_text_field($get('Designation'));
            $role_input=strtolower(sanitize_text_field($get('Role'))); $active_input=strtolower(sanitize_text_field($get('Active'))); $record=$name ?: ($email ?: $username);
            if ($name==='') $row_errors[]='Full Name is required.'; if ($username==='') $row_errors[]='Username is required.';
            if ($email==='' || !is_email($email)) $row_errors[]='A valid Email is required.'; if ($password==='') $row_errors[]='Password is required.';
            if ($mobile!=='' && strlen($mobile)!==10) $row_errors[]='Mobile must contain exactly 10 digits.';
            $roles=array('employee'=>'Employee','manager'=>'Manager','admin'=>'Admin','administrator'=>'Admin');
            if (!isset($roles[$role_input])) $row_errors[]='Role must be Employee, Manager or Admin.';
            if (!in_array($active_input,array('yes','no','1','0','active','inactive'),true)) $row_errors[]='Active must be Yes or No.';
            if ($designation!=='' && !$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}banka_tms_designations WHERE LOWER(name)=LOWER(%s) LIMIT 1",$designation))) $row_errors[]='Designation does not exist.';
            $file_key=strtolower($username.'|'.$email); if (isset($seen[$file_key])) $row_errors[]='Duplicate username/email in upload file (also on row '.$seen[$file_key].').'; else $seen[$file_key]=$row_number;
            $users_table=banka_tms_app_users_table();
            if (($username!=='' || $email!=='') && $wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$users_table} WHERE LOWER(username)=LOWER(%s) OR LOWER(email)=LOWER(%s) LIMIT 1",$username,$email))) $row_errors[]='Username or email already exists.';
            if (!$row_errors) {
                $next_id=max(1,(int)$wpdb->get_var("SELECT COALESCE(MAX(user_id),0)+1 FROM {$users_table}"));
                $active=in_array($active_input,array('yes','1','active'),true)?1:0;
                $ok=$wpdb->insert($users_table,array('user_id'=>$next_id,'wp_user_id'=>null,'name'=>$name,'username'=>$username,'email'=>$email,'password_hash'=>wp_hash_password($password),'role'=>$roles[$role_input],'designation'=>$designation,'category'=>'','mobile'=>$mobile,'active'=>$active),array('%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%d'));
                if ($ok===false) $row_errors[]=$wpdb->last_error?:'Database insert failed.'; else $inserted++;
            }
        }
        if ($row_errors) { $errors[] = array('row'=>$row_number,'record'=>$record,'error'=>implode(' ', $row_errors)); }
    }
    fclose($handle);
    wp_send_json_success(array(
        'message'=>$inserted . ' row(s) uploaded successfully' . ($errors ? '; ' . count($errors) . ' row(s) failed.' : '.'),
        'inserted'=>$inserted,'failed'=>count($errors),'errors'=>$errors
    ));
}

function banka_tms_bulk_import_buttons($type) {
    $types=banka_tms_bulk_types(); if (!isset($types[$type])) return;
    $url=add_query_arg(array('action'=>'banka_tms_bulk_template','type'=>$type),admin_url('admin-post.php'));
    echo '<a class="banka-tms-mini-btn banka-tms-bulk-download" href="'.esc_url($url).'">'.banka_tms_icon('file','banka-tms-mini-icon').'<span>Download Template</span></a>';
    echo '<button type="button" class="banka-tms-mini-btn banka-tms-bulk-upload-button" data-bulk-type="'.esc_attr($type).'">'.banka_tms_icon('file','banka-tms-mini-icon').'<span>Upload Template</span></button>';
    echo '<input type="file" class="banka-tms-bulk-file" data-bulk-type="'.esc_attr($type).'" accept=".csv,text/csv" hidden />';
}

function banka_tms_bulk_import_modal() { ?>
<div class="banka-tms-modal" id="banka-tms-bulk-result-modal" style="display:none">
 <div class="banka-tms-modal-backdrop"></div><div class="banka-tms-modal-card banka-tms-bulk-result-card">
  <button type="button" class="banka-tms-modal-close" data-close-modal>×</button><h2 class="banka-tms-modal-title">Bulk Upload Result</h2>
  <div id="banka-tms-bulk-summary" class="banka-tms-status"></div>
  <div class="banka-tms-table-wrap"><table class="banka-tms-table banka-tms-bulk-error-table"><thead><tr><th>Row</th><th>Record</th><th>Error</th></tr></thead><tbody id="banka-tms-bulk-errors"></tbody></table></div>
  <div class="banka-tms-modal-actions"><button type="button" class="banka-tms-primary-btn" id="banka-tms-bulk-done">Done</button></div>
 </div>
</div><?php }
