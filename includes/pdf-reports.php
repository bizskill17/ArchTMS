<?php
if (!defined('ABSPATH')) { exit; }

add_action('admin_post_banka_tms_task_pdf', 'banka_tms_download_task_pdf');
add_action('admin_post_banka_tms_action_log_pdf', 'banka_tms_download_action_log_pdf');

function banka_tms_pdf_text($text) {
    $text = wp_strip_all_tags((string) $text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if ($converted !== false) { $text = $converted; }
    }
    return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
}

class Banka_TMS_Simple_PDF {
    private $pages = array();
    private $content = '';
    private $width = 842;
    private $height = 595;
    private $margin = 44;
    private $y = 0;

    public function __construct() { $this->new_page(); }
    public function new_page() {
        if ($this->content !== '') { $this->pages[] = $this->content; }
        $this->content = '';
        $this->y = $this->height - $this->margin;
    }
    public function get_y() { return $this->y; }
    public function set_y($y) { $this->y = $y; }
    public function page_bottom() { return $this->margin; }
    private function esc($s) { return str_replace(array('\\','(',')'), array('\\\\','\(','\)'), banka_tms_pdf_text($s)); }
    public function text($x, $y, $text, $size=10, $bold=false, $color=array(0,0,0)) {
        $font = $bold ? 'F2' : 'F1';
        $this->content .= sprintf("BT /%s %.2F Tf %.3F %.3F %.3F rg 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n", $font, $size, $color[0]/255, $color[1]/255, $color[2]/255, $x, $y, $this->esc($text));
    }
    public function rect($x,$y,$w,$h,$fill=null,$stroke=array(200,200,200)) {
        if ($fill) {
            $this->content .= sprintf("%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n", $fill[0]/255,$fill[1]/255,$fill[2]/255,$x,$y,$w,$h);
        }
        if ($stroke) {
            $this->content .= sprintf("%.3F %.3F %.3F RG 0.5 w %.2F %.2F %.2F %.2F re S\n", $stroke[0]/255,$stroke[1]/255,$stroke[2]/255,$x,$y,$w,$h);
        }
    }
    public function line($x1,$y1,$x2,$y2,$color=array(200,200,200)) {
        $this->content .= sprintf("%.3F %.3F %.3F RG 0.5 w %.2F %.2F m %.2F %.2F l S\n",$color[0]/255,$color[1]/255,$color[2]/255,$x1,$y1,$x2,$y2);
    }
    public function wrap($text, $width, $size=9) {
        $text = trim(preg_replace('/\s+/', ' ', banka_tms_pdf_text($text)));
        if ($text === '') { return array(''); }
        $max = max(4, (int) floor($width / ($size * 0.52)));
        $words = preg_split('/\s+/', $text);
        $lines=array(); $line='';
        foreach ($words as $word) {
            if (strlen($word) > $max) {
                if ($line !== '') { $lines[]=$line; $line=''; }
                while (strlen($word)>$max) { $lines[]=substr($word,0,$max); $word=substr($word,$max); }
                $line=$word; continue;
            }
            $try = $line === '' ? $word : $line.' '.$word;
            if (strlen($try) <= $max) { $line=$try; } else { $lines[]=$line; $line=$word; }
        }
        if ($line !== '') { $lines[]=$line; }
        return $lines;
    }
    public function output($filename) {
        if ($this->content !== '') { $this->pages[]=$this->content; $this->content=''; }
        $objects=array();
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $page_count=count($this->pages);
        $kids=array();
        $font1=3; $font2=4;
        $objects[$font1]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[$font2]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
        $next=5;
        foreach ($this->pages as $stream) {
            $pageObj=$next++; $contentObj=$next++;
            $kids[]=$pageObj.' 0 R';
            $objects[$contentObj] = "<< /Length ".strlen($stream)." >>\nstream\n".$stream."endstream";
            $objects[$pageObj] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.$this->width.' '.$this->height.'] /Resources << /Font << /F1 '.$font1.' 0 R /F2 '.$font2.' 0 R >> >> /Contents '.$contentObj.' 0 R >>';
        }
        $objects[2]='<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.$page_count.' >>';
        ksort($objects);
        $pdf="%PDF-1.4\n"; $offsets=array(0);
        foreach ($objects as $num=>$obj) { $offsets[$num]=strlen($pdf); $pdf.=$num." 0 obj\n".$obj."\nendobj\n"; }
        $xref=strlen($pdf); $max=max(array_keys($objects));
        $pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";
        for($i=1;$i<=$max;$i++){ $pdf.=sprintf('%010d 00000 n ', $offsets[$i]??0)."\n"; }
        $pdf.="trailer\n<< /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="'.sanitize_file_name($filename).'"');
        header('Content-Length: '.strlen($pdf));
        echo $pdf; exit;
    }
}

function banka_tms_pdf_filter_values($key) {
    $raw = isset($_GET[$key]) ? wp_unslash($_GET[$key]) : '';
    if (is_array($raw)) {
        $values = $raw;
    } else {
        $values = preg_split('/\s*,\s*/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
    }
    return array_values(array_filter(array_map('sanitize_text_field', (array) $values), static function($value) {
        return $value !== '';
    }));
}

function banka_tms_pdf_matches_any($actual, $selected) {
    if (empty($selected)) return true;
    foreach ($selected as $value) {
        if ((string) $actual === (string) $value) return true;
    }
    return false;
}

function banka_tms_pdf_task_rows() {
    global $wpdb;
    $table=$wpdb->prefix.'banka_tms_tasks';
    $rows=$wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC");
    $view=sanitize_key($_GET['view']??'all');
    $search=sanitize_text_field(wp_unslash($_GET['search']??''));
    $status=banka_tms_pdf_filter_values('status_filter');
    $priority=banka_tms_pdf_filter_values('priority');
    $project=banka_tms_pdf_filter_values('project');
    $owner=banka_tms_pdf_filter_values('owner');
    $assignee=banka_tms_pdf_filter_values('assignee');
    $from=sanitize_text_field(wp_unslash($_GET['from']??''));
    $to=sanitize_text_field(wp_unslash($_GET['to']??''));
    $out=array();
    foreach($rows as $r){
        $st=in_array($r->status,array('Pending','In Progress','Completed'),true)?$r->status:'Pending';
        if($view==='pending' && $st==='Completed') continue;
        if($view==='completed' && $st!=='Completed') continue;
        $a=$r->assignee_id?banka_tms_app_get_user((int)$r->assignee_id):false;
        $oid=!empty($r->owner_id)?(int)$r->owner_id:(int)$r->created_by;
        $o=$oid?banka_tms_app_get_user($oid):false;
        $an=$a?$a->display_name:'—'; $on=$o?$o->display_name:'—';
        $pn=function_exists('banka_tms_project_display_label')?banka_tms_project_display_label($r->project_name??''):(trim((string)($r->project_name??''))?:'General Project');
        $pr=!empty($r->priority_name)?$r->priority_name:'Medium';
        $due=!empty($r->due_date)?$r->due_date:'';
        $due_display=$due!==''?wp_date('d/m/Y',strtotime($due), banka_tms_ist_timezone()):'';
        $label=$st==='Pending'?'Not Yet Started':$st;
        $hay=strtolower(implode(' ',array($r->title,$an,$on,$pn,$label,$pr,$r->description??'')));
        if($search!=='' && strpos($hay,strtolower($search))===false) continue;
        if(!banka_tms_pdf_matches_any($st,$status)) continue;
        if(!banka_tms_pdf_matches_any($pr,$priority)) continue;
        if(!banka_tms_pdf_matches_any($pn,$project)) continue;
        if(!banka_tms_pdf_matches_any($on,$owner)) continue;
        if(!banka_tms_pdf_matches_any($an,$assignee)) continue;
        if($from!=='' && $due!=='' && $due<$from) continue;
        if($to!=='' && $due!=='' && $due>$to) continue;
        $out[]=array('task'=>$r->title,'assignee'=>$an,'owner'=>$on,'project'=>$pn,'status'=>$label,'priority'=>$pr,'due'=>$due_display);
    }
    return $out;
}

function banka_tms_pdf_report_title($view,$group){
    $base=$view==='pending'?'Pending Tasks Report':($view==='completed'?'Completed Tasks Report':'All Tasks Report');
    if($group!=='standard') $base.=' (Grouped by '.ucfirst($group).')';
    return $base;
}

function banka_tms_pdf_draw_header($pdf,$title,$groupName=''){
    $pdf->text(44,548,$title,20,true);
    if($groupName!=='') $pdf->text(44,510,$groupName,17,true);
    return $groupName!==''?480:510;
}

function banka_tms_pdf_draw_table($pdf,$rows,$title,$groupName=''){
    $cols=array(
        array('S.No.',35),array('Task',110),array('Assignees',85),array('Owner',85),array('Project',200),array('Status',65),array('Priority',55),array('Due Date',70)
    );
    $x0=44; $y=banka_tms_pdf_draw_header($pdf,$title,$groupName); $headerH=28;
    $drawHeader=function() use($pdf,$cols,$x0,&$y,$headerH){
        $x=$x0;
        foreach($cols as $c){$pdf->rect($x,$y-$headerH,$c[1],$headerH,array(26,160,133),array(26,160,133));$pdf->text($x+6,$y-18,$c[0],10,true,array(255,255,255));$x+=$c[1];}
        $y-=$headerH;
    };
    $drawHeader(); $i=1;
    foreach($rows as $row){
        $values=array((string)$i,$row['task'],$row['assignee'],$row['owner'],$row['project'],$row['status'],$row['priority'],$row['due']);
        $lineSets=array(); $maxLines=1;
        foreach($values as $k=>$v){$lines=$pdf->wrap($v,$cols[$k][1]-10,9);$lineSets[]=$lines;$maxLines=max($maxLines,count($lines));}
        $h=max(34,12*$maxLines+12);
        if($y-$h<46){$pdf->new_page();$y=banka_tms_pdf_draw_header($pdf,$title,$groupName);$drawHeader();}
        $x=$x0;
        foreach($cols as $k=>$c){$pdf->rect($x,$y-$h,$c[1],$h,null,array(190,190,190));$ty=$y-15;foreach($lineSets[$k] as $line){$pdf->text($x+6,$ty,$line,9,false,array(40,55,65));$ty-=12;}$x+=$c[1];}
        $y-=$h; $i++;
    }
}

function banka_tms_download_task_pdf(){
    if(!banka_tms_app_is_logged_in()) banka_tms_app_render_login_screen();
    $view=sanitize_key($_GET['view']??'all');
    $group=sanitize_key($_GET['group']??'standard');
    if(!in_array($group,array('standard','project','assignee','owner'),true)) $group='standard';
    $rows=banka_tms_pdf_task_rows();
    $title=banka_tms_pdf_report_title($view,$group);
    $pdf=new Banka_TMS_Simple_PDF();
    if($group==='standard'){
        banka_tms_pdf_draw_table($pdf,$rows,$title);
    } else {
        $key=$group; $groups=array();
        foreach($rows as $r){$name=trim((string)$r[$key]);if($name==='')$name='Unassigned';$groups[$name][]=$r;}
        ksort($groups,SORT_NATURAL|SORT_FLAG_CASE); $first=true;
        foreach($groups as $name=>$groupRows){if(!$first)$pdf->new_page();banka_tms_pdf_draw_table($pdf,$groupRows,$title,$name);$first=false;}
        if(empty($groups)) banka_tms_pdf_draw_table($pdf,array(),$title);
    }
    $filename=strtolower(str_replace(' ','-',preg_replace('/[^A-Za-z0-9 ]/','',$title))).'.pdf';
    $pdf->output($filename);
}


/**
 * Collect filtered Action Log rows for direct PDF download.
 */
function banka_tms_pdf_action_log_rows() {
    global $wpdb;
    if (function_exists('banka_tms_ensure_action_log_table')) {
        banka_tms_ensure_action_log_table();
    }
    $table = function_exists('banka_tms_action_log_table_name')
        ? banka_tms_action_log_table_name()
        : $wpdb->prefix . 'banka_tms_action_log';

    $current_user = function_exists('banka_tms_app_current_user') ? banka_tms_app_current_user() : false;
    $is_admin = function_exists('banka_tms_app_is_admin_user') && banka_tms_app_is_admin_user($current_user);
    $admin_only = !empty($_GET['admin_only']);
    if ($admin_only && !$is_admin) {
        wp_die('Access denied. This report is available only to administrators.');
    }

    if ($is_admin) {
        $rows = $wpdb->get_results("SELECT task_title, created_at, action_name, remarks, owner_names, assignee_names, project_name FROM {$table} ORDER BY created_at DESC, id DESC");
    } else {
        $task_table = $wpdb->prefix . 'banka_tms_tasks';
        $current_user_id = function_exists('banka_tms_app_current_user_id') ? (int) banka_tms_app_current_user_id() : 0;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT l.task_title, l.created_at, l.action_name, l.remarks, l.owner_names, l.assignee_names, l.project_name
             FROM {$table} l
             INNER JOIN {$task_table} t ON t.id = l.task_id
             WHERE t.assignee_id = %d OR t.owner_id = %d
             ORDER BY l.created_at DESC, l.id DESC",
            $current_user_id,
            $current_user_id
        ));
    }
    $search = strtolower(sanitize_text_field(wp_unslash($_GET['search'] ?? '')));
    $action = banka_tms_pdf_filter_values('action_filter');
    $assignee = banka_tms_pdf_filter_values('assignee');
    $owner = banka_tms_pdf_filter_values('owner');
    $project = banka_tms_pdf_filter_values('project');
    $from = sanitize_text_field(wp_unslash($_GET['from'] ?? ''));
    $to = sanitize_text_field(wp_unslash($_GET['to'] ?? ''));

    $out = array();
    foreach ($rows as $row) {
        $row_date = !empty($row->created_at) ? mysql2date('Y-m-d', $row->created_at) : '';
        $owner_names = trim((string) $row->owner_names) !== '' ? (string) $row->owner_names : '—';
        $assignee_names = trim((string) $row->assignee_names) !== '' ? (string) $row->assignee_names : '—';
        $project_name = trim((string) $row->project_name) !== '' ? (string) $row->project_name : 'General Project';
        $haystack = strtolower(implode(' ', array(
            $row->task_title, $row->created_at, $row->action_name, $row->remarks,
            $owner_names, $assignee_names, $project_name,
        )));
        if (strcasecmp(trim((string) $row->action_name), 'Created') === 0) continue;
        if ($search !== '' && strpos($haystack, $search) === false) continue;
        if (!banka_tms_pdf_matches_any((string) $row->action_name, $action)) continue;
        if (!banka_tms_pdf_matches_any($assignee_names, $assignee)) continue;
        if (!banka_tms_pdf_matches_any($owner_names, $owner)) continue;
        if (!banka_tms_pdf_matches_any((string) $row->project_name, $project)) continue;
        if ($from !== '' && $row_date !== '' && $row_date < $from) continue;
        if ($to !== '' && $row_date !== '' && $row_date > $to) continue;

        $out[] = array(
            'task' => (string) $row->task_title,
            'update_date' => !empty($row->created_at) ? mysql2date('d/m/Y H:i', $row->created_at) : '',
            'status' => (string) $row->action_name,
            'remarks' => (string) $row->remarks,
            'owner' => $owner_names,
            'assignee' => $assignee_names,
            'project' => $project_name,
        );
    }
    return $out;
}

function banka_tms_pdf_draw_action_log_table($pdf, $rows, $title = 'Action Log Report', $subtitle = '') {
    $cols = array(
        array('Task', 105),
        array('Update Date', 82),
        array('Status', 70),
        array('Remarks', 145),
        array('Owner(s)', 90),
        array('Assignee(s)', 90),
        array('Project', 128),
    );
    $x0 = 44;
    $y = banka_tms_pdf_draw_header($pdf, $title);
    if ($subtitle !== '') { $pdf->text($x0, $y - 2, $subtitle, 10, true, array(70,80,95)); $y -= 18; }
    $header_h = 30;
    $draw_header = function() use ($pdf, $cols, $x0, &$y, $header_h) {
        $x = $x0;
        foreach ($cols as $col) {
            $pdf->rect($x, $y - $header_h, $col[1], $header_h, array(49,94,251), array(0,0,0));
            $pdf->text($x + 5, $y - 19, $col[0], 9, true, array(255,255,255));
            $x += $col[1];
        }
        $y -= $header_h;
    };
    $draw_header();

    foreach ($rows as $row) {
        $values = array($row['task'], $row['update_date'], $row['status'], $row['remarks'], $row['owner'], $row['assignee'], $row['project']);
        $line_sets = array();
        $max_lines = 1;
        foreach ($values as $index => $value) {
            $lines = $pdf->wrap($value, $cols[$index][1] - 10, 8);
            $line_sets[] = $lines;
            $max_lines = max($max_lines, count($lines));
        }
        $height = max(32, 11 * $max_lines + 12);
        if ($y - $height < 44) {
            $pdf->new_page();
            $y = banka_tms_pdf_draw_header($pdf, $title);
            $draw_header();
        }
        $x = $x0;
        foreach ($cols as $index => $col) {
            $pdf->rect($x, $y - $height, $col[1], $height, null, array(0,0,0));
            $text_y = $y - 14;
            foreach ($line_sets[$index] as $line) {
                $pdf->text($x + 5, $text_y, $line, 8, false, array(32,41,57));
                $text_y -= 11;
            }
            $x += $col[1];
        }
        $y -= $height;
    }

    if (empty($rows)) {
        $pdf->text($x0, $y - 24, 'No action logs match the selected filters.', 10, false, array(70,80,95));
    }
}

/** Directly download the Action Log PDF instead of opening print preview. */
function banka_tms_download_action_log_pdf() {
    if (!banka_tms_app_is_logged_in()) {
        banka_tms_app_render_login_screen();
    }
    $rows = banka_tms_pdf_action_log_rows();
    $pdf = new Banka_TMS_Simple_PDF();
    $group = sanitize_key(wp_unslash($_GET['group'] ?? ''));
    $title = sanitize_text_field(wp_unslash($_GET['report_title'] ?? 'Action Log Report'));
    $from = sanitize_text_field(wp_unslash($_GET['from'] ?? ''));
    $to = sanitize_text_field(wp_unslash($_GET['to'] ?? ''));
    $subtitle = '';
    if ($from !== '' && $to !== '') {
        $subtitle = ($from === $to) ? 'Date: ' . mysql2date('d/m/Y', $from) : 'Period: ' . mysql2date('d/m/Y', $from) . ' to ' . mysql2date('d/m/Y', $to);
    }
    if ($group === 'assignee') {
        $groups = array();
        foreach ($rows as $row) {
            $name = trim((string) $row['assignee']);
            if ($name === '' || $name === '—') $name = 'Unassigned';
            foreach (array_filter(array_map('trim', preg_split('/[,;]+/', $name))) as $assignee_name) {
                $groups[$assignee_name][] = $row;
            }
        }
        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
        $first = true;
        foreach ($groups as $name => $group_rows) {
            if (!$first) $pdf->new_page();
            banka_tms_pdf_draw_action_log_table($pdf, $group_rows, $title, trim($subtitle . ($subtitle !== '' ? ' | ' : '') . 'Assignee: ' . $name));
            $first = false;
        }
        if (empty($groups)) banka_tms_pdf_draw_action_log_table($pdf, array(), $title, $subtitle);
    } else {
        banka_tms_pdf_draw_action_log_table($pdf, $rows, $title, $subtitle);
    }
    $filename = sanitize_file_name(strtolower(str_replace(' ', '-', $title)) . ($from !== '' ? '-' . $from : '') . ($to !== '' && $to !== $from ? '-to-' . $to : '') . '.pdf');
    $pdf->output($filename);
}
