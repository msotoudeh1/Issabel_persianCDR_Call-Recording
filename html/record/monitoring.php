<?php

date_default_timezone_set('Asia/Tehran');

// ==========================================
// monitoring.php - داشبورد شنود و آنالیز (تم روشن - PHP 5.4+)
// ==========================================
$config = require __DIR__ . '/config.php';

$db_host     = $config['db_host'];
$db_name     = $config['db_name'];
$db_user     = $config['db_user'];
$db_pass     = $config['db_pass'];
$monitor_dir = rtrim($config['monitor_dir'], '/\\') . '/';

$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mysqli->connect_error) {
    die("<div style='text-align:center;padding:50px;font-family:sans-serif;color:#ef4444;'>خطا در اتصال به دیتابیس.</div>");
}
$mysqli->set_charset("utf8mb4");

// ==========================================
// توابع کمکی
// ==========================================
function to_persian_num($string) {
    return str_replace(
        array('0','1','2','3','4','5','6','7','8','9'),
        array('۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'),
        (string)$string
    );
}

function gregorian_to_jalali($g_y, $g_m, $g_d) {
    $gdm = array(31,28,31,30,31,30,31,31,30,31,30,31);
    $jdm = array(31,31,31,31,31,31,30,30,30,30,30,29);
    $gy = $g_y - 1600; $gm = $g_m - 1; $gd = $g_d - 1;
    $g_day_no = 365*$gy + (int)(($gy+3)/4) - (int)(($gy+99)/100) + (int)(($gy+399)/400);
    for ($i = 0; $i < $gm; ++$i) $g_day_no += $gdm[$i];
    if ($gm > 1 && (($gy%4==0 && $gy%100!=0) || $gy%400==0)) $g_day_no++;
    $g_day_no += $gd;
    $j_day_no = $g_day_no - 79;
    $j_np = (int)($j_day_no/12053); $j_day_no %= 12053;
    $jy = 979 + 33*$j_np + 4*(int)($j_day_no/1461);
    $j_day_no %= 1461;
    if ($j_day_no >= 366) {
        $jy += (int)(($j_day_no-1)/365);
        $j_day_no = ($j_day_no-1) % 365;
    }
    for ($i = 0; $i < 11 && $j_day_no >= $jdm[$i]; ++$i) $j_day_no -= $jdm[$i];
    return sprintf("%04d/%02d/%02d", $jy, $i+1, $j_day_no+1);
}

function jalali_to_gregorian($j_y, $j_m, $j_d) {
    $gdm = array(31,28,31,30,31,30,31,31,30,31,30,31);
    $jdm = array(31,31,31,31,31,31,30,30,30,30,30,29);
    $jy = $j_y - 979; $jm = $j_m - 1; $jd = $j_d - 1;
    $j_day_no = 365*$jy + (int)($jy/33)*8 + (int)(($jy%33+3)/4);
    for ($i = 0; $i < $jm; ++$i) $j_day_no += $jdm[$i];
    $j_day_no += $jd;
    $g_day_no = $j_day_no + 79;
    $gy = 1600 + 400*(int)($g_day_no/146097); $g_day_no %= 146097;
    $leap = true;
    if ($g_day_no >= 36525) {
        $g_day_no--;
        $gy += 100*(int)($g_day_no/36524); $g_day_no %= 36524;
        if ($g_day_no >= 365) $g_day_no++; else $leap = false;
    }
    $gy += 4*(int)($g_day_no/1461); $g_day_no %= 1461;
    if ($g_day_no >= 366) {
        $leap = false; $g_day_no--;
        $gy += (int)($g_day_no/365); $g_day_no %= 365;
    }
    for ($i = 0; $i < 12; $i++) {
        $dim = $gdm[$i];
        if ($i == 1 && $leap) $dim++;
        if ($g_day_no < $dim) break;
        $g_day_no -= $dim;
    }
    return sprintf("%04d-%02d-%02d", $gy, $i+1, $g_day_no+1);
}

function format_duration($seconds) {
    $s = (int)$seconds;
    return sprintf("%02d:%02d:%02d", (int)($s/3600), (int)(($s%3600)/60), $s%60);
}

function normalize_jalali_date($str) {
    $str = str_replace(
        array('۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','/'),
        array('0','1','2','3','4','5','6','7','8','9','-'),
        (string)$str
    );
    $parts = preg_split('/[-]/', $str);
    if (count($parts) !== 3) return null;
    return array((int)$parts[0], (int)$parts[1], (int)$parts[2]);
}

function find_audio_files($monitor_dir, $calldate, $uniqueid) {
    if (empty($uniqueid)) return array();
    try { $dt = new DateTime($calldate); }
    catch (Exception $e) { return array(); }

    $target_dir = $monitor_dir . $dt->format('Y/m/d') . '/';
    if (!is_dir($target_dir)) $target_dir = $monitor_dir;

    $allowed = array('mp3','wav','ogg','gsm');
    $result = array();

    foreach (glob($target_dir . '*' . $uniqueid . '*') as $path) {
        if (!is_file($path)) continue;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) continue;
        $result[] = array(
            'path' => str_replace($monitor_dir, '', $path),
            'name' => basename($path),
            'ext'  => $ext,
        );
    }
    return $result;
}

function is_trunk_channel($channel, $extensions_list) {
    if (empty($channel)) return false;
    if (preg_match('#^DAHDI/#i', $channel)) return true;
    if (preg_match('#^Local/#i', $channel)) return false;
    if (preg_match('#^(?:SIP|PJSIP|IAX2|SCCP)/([^-@;]+)#i', $channel, $m)) {
        $peer = $m[1];
        if (ctype_digit($peer)) return !in_array($peer, $extensions_list, true);
        return true;
    }
    return false;
}

function classify_call_direction($channel, $dstchannel, $src, $dst, $dcontext, $extensions_list) {
    $ch_trunk  = is_trunk_channel($channel, $extensions_list);
    $dst_trunk = is_trunk_channel($dstchannel, $extensions_list);

    if ($ch_trunk && $dst_trunk)  return 'transit';
    if ($ch_trunk && !$dst_trunk) return 'inbound';
    if (!$ch_trunk && $dst_trunk) return 'outbound';

    $src_ext = in_array($src, $extensions_list, true) || (is_numeric($src) && strlen($src) >= 3 && strlen($src) <= 5);
    $dst_ext = in_array($dst, $extensions_list, true) || (is_numeric($dst) && strlen($dst) >= 3 && strlen($dst) <= 5);

    if ($src_ext && $dst_ext)   return 'internal';
    if (!$src_ext && $dst_ext)  return 'inbound';
    if ($src_ext && !$dst_ext)  return 'outbound';
    return 'unknown';
}

// ==========================================
// دریافت تاریخ امروز برای هدر
// ==========================================
$today_g = date('Y-m-d');
$today_parts = explode('-', $today_g);
$today_jalali = gregorian_to_jalali((int)$today_parts[0], (int)$today_parts[1], (int)$today_parts[2]);
$today_jalali_fa = to_persian_num($today_jalali);

// ==========================================
// لیست داخلی‌ها
// ==========================================
$extensions_list = array();
$mysqli_ast = @new mysqli($db_host, $db_user, $db_pass, 'asterisk');
if ($mysqli_ast && !$mysqli_ast->connect_error) {
    $mysqli_ast->set_charset("utf8");
    $res = $mysqli_ast->query("SELECT extension FROM users UNION SELECT id FROM devices WHERE id REGEXP '^[0-9]+$'");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $ext = isset($row['extension']) ? $row['extension'] : (isset($row['id']) ? $row['id'] : '');
            if ($ext !== '' && is_numeric($ext) && strlen($ext) <= 5) $extensions_list[] = $ext;
        }
    }
    $mysqli_ast->close();
}
if (empty($extensions_list)) {
    $res = $mysqli->query("SELECT DISTINCT src FROM cdr WHERE src REGEXP '^[0-9]+$' AND LENGTH(src)<=5 UNION SELECT DISTINCT dst FROM cdr WHERE dst REGEXP '^[0-9]+$' AND LENGTH(dst)<=5");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $ext = $row['src'] ? $row['src'] : $row['dst'];
            if ($ext !== '') $extensions_list[] = $ext;
        }
    }
}
$extensions_list = array_values(array_unique($extensions_list));

// ==========================================
// فیلترها
// ==========================================
$start_date    = trim(isset($_GET['start_date'])    ? $_GET['start_date']    : '');
$end_date      = trim(isset($_GET['end_date'])      ? $_GET['end_date']      : '');
$extension     = trim(isset($_GET['extension'])     ? $_GET['extension']     : '');
$search_number = preg_replace('/[^0-9+]/', '', isset($_GET['search_number']) ? $_GET['search_number'] : '');
$only_recorded = isset($_GET['only_recorded']) && $_GET['only_recorded'] === '1';

$where = array();

if ($p = normalize_jalali_date($start_date)) {
    $g = jalali_to_gregorian($p[0], $p[1], $p[2]) . ' 00:00:00';
    $where[] = "calldate >= '" . $mysqli->real_escape_string($g) . "'";
}
if ($p = normalize_jalali_date($end_date)) {
    $g = jalali_to_gregorian($p[0], $p[1], $p[2]) . ' 23:59:59';
    $where[] = "calldate <= '" . $mysqli->real_escape_string($g) . "'";
}
if ($extension !== '' && is_numeric($extension)) {
    $safe = $mysqli->real_escape_string($extension);
    $where[] = "(src = '$safe' OR dst = '$safe')";
}
if ($search_number !== '') {
    $safe = $mysqli->real_escape_string($search_number);
    $where[] = "(src LIKE '%$safe%' OR dst LIKE '%$safe%')";
}
if ($only_recorded) {
    $where[] = "disposition = 'ANSWERED' AND billsec > 0";
}

$where_clause = $where ? "WHERE " . implode(" AND ", $where) : "";


// ==========================================
// فیلتر اختصاصی نمودار توزیع ساعتی
// ==========================================
$chart_range = isset($_GET['chart_range']) ? $_GET['chart_range'] : 'all';
$ranges = array(
    'all'   => 'همه',
    'today' => 'امروز',
    'week'  => 'این هفته',
    'month' => 'این ماه',
    'year'  => 'امسال'
);

$where_chart = $where; // کپی از فیلترهای اصلی صفحه

if ($chart_range === 'today') {
    $where_chart[] = "calldate >= '" . date('Y-m-d 00:00:00') . "' AND calldate <= '" . date('Y-m-d 23:59:59') . "'";
} elseif ($chart_range === 'week') {
    $start_week = date('Y-m-d 00:00:00', strtotime('monday this week'));
    $end_week = date('Y-m-d 23:59:59', strtotime('sunday this week'));
    $where_chart[] = "calldate >= '$start_week' AND calldate <= '$end_week'";
} elseif ($chart_range === 'month') {
    $where_chart[] = "calldate >= '" . date('Y-m-01 00:00:00') . "' AND calldate <= '" . date('Y-m-t 23:59:59') . "'";
} elseif ($chart_range === 'year') {
    $where_chart[] = "calldate >= '" . date('Y-01-01 00:00:00') . "' AND calldate <= '" . date('Y-12-31 23:59:59') . "'";
}

$where_clause_chart = $where_chart ? "WHERE " . implode(" AND ", $where_chart) : "";

// ==========================================
// جمع‌آوری داده‌های نمودارها
// ==========================================
// ۱. توزیع ساعتی
$hourly_data = array_fill(0, 24, 0);
// 👇 اینجا باید حتماً $where_clause_chart باشد 👇
$sql_hourly = "SELECT HOUR(calldate) AS hr, COUNT(*) AS cnt FROM cdr $where_clause_chart GROUP BY HOUR(calldate)";
$res_h = $mysqli->query($sql_hourly);
if ($res_h) {
    while ($row = $res_h->fetch_assoc()) {
        $hourly_data[(int)$row['hr']] = (int)$row['cnt'];
    }
}

// ۲. توزیع وضعیت تماس‌ها
$status_data = array('ANSWERED'=>0, 'NO ANSWER'=>0, 'BUSY'=>0, 'FAILED'=>0, 'OTHER'=>0);
$sql_status = "SELECT disposition, COUNT(*) AS cnt FROM cdr $where_clause GROUP BY disposition";
$res_s = $mysqli->query($sql_status);
if ($res_s) {
    while ($row = $res_s->fetch_assoc()) {
        $d = strtoupper($row['disposition']);
        if (isset($status_data[$d])) $status_data[$d] += (int)$row['cnt'];
        else $status_data['OTHER'] += (int)$row['cnt'];
    }
}

// ۳. توزیع جهت تماس‌ها
$direction_data = array('inbound'=>0, 'outbound'=>0, 'internal'=>0, 'transit'=>0);
$sql_dir = "SELECT channel, dstchannel, src, dst, dcontext FROM cdr $where_clause";
$res_d = $mysqli->query($sql_dir);
if ($res_d) {
    while ($row = $res_d->fetch_assoc()) {
        $dir = classify_call_direction($row['channel'], $row['dstchannel'], $row['src'], $row['dst'], $row['dcontext'], $extensions_list);
        if (isset($direction_data[$dir])) $direction_data[$dir]++;
    }
}

// ۴. آمار اپراتورها
$operators = array();
$op_in_where = $where_chart; // 👈 تغییر به where_chart
$op_in_where[] = "dst REGEXP '^[0-9]{3,5}$'";
$op_in_where[] = "disposition = 'ANSWERED'";
$sql_op_in = "SELECT dst AS ext, COUNT(*) AS cnt, SUM(billsec) AS sec FROM cdr WHERE " . implode(" AND ", $op_in_where) . " GROUP BY dst";
$res_in = $mysqli->query($sql_op_in);
if ($res_in) {
    while ($row = $res_in->fetch_assoc()) {
        $ext = $row['ext'];
        if (!isset($operators[$ext])) $operators[$ext] = array('cnt' => 0, 'sec' => 0);
        $operators[$ext]['cnt'] += (int)$row['cnt'];
        $operators[$ext]['sec'] += (int)$row['sec'];
    }
}

$op_out_where = $where_chart; // 👈 تغییر به where_chart
$op_out_where[] = "src REGEXP '^[0-9]{3,5}$'";
$op_out_where[] = "disposition = 'ANSWERED'";
$sql_op_out = "SELECT src AS ext, COUNT(*) AS cnt, SUM(billsec) AS sec FROM cdr WHERE " . implode(" AND ", $op_out_where) . " GROUP BY src";
$res_out = $mysqli->query($sql_op_out);
if ($res_out) {
    while ($row = $res_out->fetch_assoc()) {
        $ext = $row['ext'];
        if (!isset($operators[$ext])) $operators[$ext] = array('cnt' => 0, 'sec' => 0);
        $operators[$ext]['cnt'] += (int)$row['cnt'];
        $operators[$ext]['sec'] += (int)$row['sec'];
    }
}
uasort($operators, function($a, $b) { return $b['cnt'] - $a['cnt']; });
$top_operators = array_slice($operators, 0, 8, true); 
$max_op_cnt = 1;
foreach ($top_operators as $op) {
    if ($op['cnt'] > $max_op_cnt) $max_op_cnt = $op['cnt'];
}

// ==========================================
// خروجی JSON برای درخواست‌های AJAX (بدون رفرش صفحه)
// ==========================================
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'hourly_data'  => array_values($hourly_data),
        'operators'    => $top_operators,
        'max_op_cnt'   => $max_op_cnt,
        'range_label'  => isset($ranges[$chart_range]) ? $ranges[$chart_range] : 'همه'
    ));
    exit; // توقف اجرای بقیه کدها و جلوگیری از لود HTML
}

// ==========================================
// شمارش و صفحه‌بندی جدول
// ==========================================
$count_sql = "SELECT COUNT(*) AS total FROM cdr $where_clause";
$count_res = $mysqli->query($count_sql);
$total_records = 0;
if ($count_res) {
    $row_cnt = $count_res->fetch_assoc();
    $total_records = (int)$row_cnt['total'];
}

$per_page = 15;
$page = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
$total_pages = max(1, (int)ceil($total_records / $per_page));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;

$sql = "SELECT calldate, src, dst, duration, billsec, disposition, uniqueid FROM cdr $where_clause ORDER BY calldate DESC LIMIT $offset, $per_page";
$result = $mysqli->query($sql);

$rows = array();
if ($result) {
    while ($call = $result->fetch_assoc()) {
        $call['audio_files'] = find_audio_files($monitor_dir, $call['calldate'], $call['uniqueid']);
        $rows[] = $call;
    }
}

$base_params = $_GET;
unset($base_params['page']);
function build_qs($params) { return htmlspecialchars(http_build_query($params), ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>داشبورد آنالیز و شنود</title>
	
	<script>
    (function() {
        try {
            if (localStorage.getItem('theme') === 'dark') {
                document.documentElement.classList.add('dark');
            }
        } catch(e) {}
    })();
</script>

    
    <script src="/3.4.17.js"></script>
    <link href="/Vazirmatn-font-face.css" rel="stylesheet">
    <script src="/chart.js"></script>

<script>
    tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                fontFamily: {
                    sans: ['Vazirmatn', 'sans-serif']
                },
                colors: {
                    brand: {
                        50:  '#f0fdf4',
                        100: '#dcfce7',
                        500: '#22c55e',
                        600: '#16a34a',
                        700: '#15803d'
                    }
                }
            }
        }
    };
</script>
	<style>
    html, body { transition: background-color .25s ease, color .25s ease; }

    /* --- دارک مود --- */
    html.dark body { background-color: #0b1220 !important; color: #e2e8f0 !important; }
    html.dark .bg-white { background-color: #111c2e !important; }
    html.dark .bg-slate-50 { background-color: #0b1220 !important; }
    html.dark .bg-slate-100 { background-color: #1e293b !important; }
    html.dark .bg-emerald-50 { background-color: rgba(16,185,129,0.12) !important; }
    html.dark .bg-emerald-100 { background-color: rgba(16,185,129,0.22) !important; }
    html.dark .bg-rose-50 { background-color: rgba(244,63,94,0.12) !important; }
    html.dark .bg-gradient-to-l { background-image: none !important; background-color: #111c2e !important; }

    html.dark .text-slate-800 { color: #f1f5f9 !important; }
    html.dark .text-slate-700 { color: #e2e8f0 !important; }
    html.dark .text-slate-600 { color: #cbd5e1 !important; }
    html.dark .text-slate-500 { color: #94a3b8 !important; }
    html.dark .text-slate-400 { color: #64748b !important; }
    html.dark .text-emerald-600 { color: #34d399 !important; }
    html.dark .text-emerald-700 { color: #6ee7b7 !important; }
    html.dark .text-emerald-500 { color: #34d399 !important; }
    html.dark .text-rose-700 { color: #fda4af !important; }
    html.dark .text-blue-500 { color: #60a5fa !important; }
    html.dark .text-blue-600 { color: #93c5fd !important; }
    html.dark .text-indigo-600 { color: #a5b4fc !important; }
    html.dark .text-amber-500 { color: #fbbf24 !important; }

    html.dark .border-slate-200 { border-color: #1e293b !important; }
    html.dark .border-slate-300 { border-color: #334155 !important; }
    html.dark .border-emerald-200 { border-color: rgba(16,185,129,0.3) !important; }
    html.dark .border-rose-200 { border-color: rgba(244,63,94,0.3) !important; }
    html.dark .divide-slate-100 > * + * { border-color: #1e293b !important; }

    html.dark .hover\:bg-slate-50:hover { background-color: rgba(255,255,255,0.06) !important; }
    html.dark .hover\:bg-emerald-100:hover { background-color: rgba(16,185,129,0.25) !important; }
    html.dark .hover\:text-emerald-700:hover { color: #6ee7b7 !important; }

    html.dark input, html.dark select, html.dark audio { color-scheme: dark; }
/* --- سوئیچ تم --- */
.theme-toggle {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 16px;
    height: 56px;
    border-radius: 12px;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    cursor: pointer;
    transition: background-color 0.2s ease, border-color 0.2s ease;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
}
.theme-toggle:hover { background: #f8fafc; }

.theme-track {
    position: relative;
    display: block;
    width: 52px;
    height: 28px;
    border-radius: 9999px;
    background: #cbd5e1;
    transition: background-color 0.3s ease;
}
.theme-knob {
    position: absolute;
    top: 2px;
    left: 2px;
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: #ffffff;
    box-shadow: 0 2px 4px rgba(0,0,0,0.15);
    transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1), background-color 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}
.theme-knob svg {
    position: absolute;
    width: 14px;
    height: 14px;
    transition: opacity 0.3s ease, transform 0.35s ease;
}
.theme-knob .icon-sun {
    color: #f59e0b;
    opacity: 1;
    transform: rotate(0deg) scale(1);
}
.theme-knob .icon-moon {
    color: #fbbf24;
    opacity: 0;
    transform: rotate(-90deg) scale(0.4);
}

/* حالت تاریک */
html.dark .theme-toggle       { background: #111c2e; border-color: #334155; }
html.dark .theme-toggle:hover { background: #1e293b; }
html.dark .theme-track        { background: #475569; }
html.dark .theme-knob {
    transform: translateX(24px);
    background: #0f172a;
}
html.dark .theme-knob .icon-sun {
    opacity: 0;
    transform: rotate(90deg) scale(0.4);
}
html.dark .theme-knob .icon-moon {
    opacity: 1;
    transform: rotate(0deg) scale(1);
}
    /* ============================================================
       المان‌های نوری مات و بلور متحرک (Glass Blobs)
       ============================================================ */
    .bg-blobs {
        position: fixed;
        inset: 0;
        overflow: hidden;
        pointer-events: none; /* جلوگیری از تداخل با کلیک‌ها */
        z-index: 0;
    }

    .blob-wrapper {
        position: absolute;
        will-change: transform;
    }

    .blob {
        border-radius: 50%;
        filter: blur(90px);
        opacity: 0.6;
        will-change: transform;
    }

    /* در حالت دارک مود شفافیت را کمتر میکنیم تا چشم را اذیت نکند */
    html.dark .blob { opacity: 0.3; }

    .blob-1 { width: 450px; height: 450px; background: radial-gradient(circle, #818cf8 0%, rgba(129, 140, 248, 0) 70%); }
    .blob-2 { width: 550px; height: 550px; background: radial-gradient(circle, #22d3ee 0%, rgba(34, 211, 238, 0) 70%); }
    .blob-3 { width: 400px; height: 400px; background: radial-gradient(circle, #c084fc 0%, rgba(192, 132, 252, 0) 70%); }
    .blob-4 { width: 350px; height: 350px; background: radial-gradient(circle, #f472b6 0%, rgba(244, 114, 182, 0) 70%); }
    .blob-5 { width: 480px; height: 480px; background: radial-gradient(circle, #f97316 0%, rgba(249, 115, 22, 0) 70%); }

    /* موقعیت‌دهی و انیمیشن‌های خودکار */
    .wrapper-1 { top: -10%; left: -10%; animation: move1 15s infinite alternate cubic-bezier(0.4, 0, 0.2, 1); }
    .wrapper-2 { bottom: -20%; right: -10%; animation: move2 20s infinite alternate cubic-bezier(0.4, 0, 0.2, 1); }
    .wrapper-3 { top: 40%; left: 40%; animation: move3 18s infinite alternate cubic-bezier(0.4, 0, 0.2, 1); }
    .wrapper-4 { top: 60%; left: 10%; animation: move4 25s infinite alternate cubic-bezier(0.4, 0, 0.2, 1); }
    .wrapper-5 { bottom: 15%; left: 25%; animation: move5 22s infinite alternate cubic-bezier(0.4, 0, 0.2, 1); }

    @keyframes move1 { 0% { transform: translate(0, 0) scale(1); } 100% { transform: translate(150px, 100px) scale(1.2); } }
    @keyframes move2 { 0% { transform: translate(0, 0) scale(1); } 100% { transform: translate(-150px, -100px) scale(1.3); } }
    @keyframes move3 { 0% { transform: translate(0, 0) scale(1); } 100% { transform: translate(-100px, 150px) scale(0.8); } }
    @keyframes move4 { 0% { transform: translate(0, 0) scale(1); } 100% { transform: translate(200px, -50px) scale(1.1); } }
    @keyframes move5 { 0% { transform: translate(0, 0) scale(1); } 100% { transform: translate(-80px, -120px) scale(1.15); } }
</style>
</head>
<body class="bg-slate-50 text-slate-800 font-sans min-h-screen antialiased selection:bg-emerald-200 selection:text-emerald-900 overflow-x-hidden"
      style="font-family: 'Vazirmatn', sans-serif;">

<!-- المان‌های نوری متحرک پس‌زمینه -->
<div class="bg-blobs" aria-hidden="true">
    <div class="blob-wrapper wrapper-1"><div class="blob blob-1"></div></div>
    <div class="blob-wrapper wrapper-2"><div class="blob blob-2"></div></div>
    <div class="blob-wrapper wrapper-3"><div class="blob blob-3"></div></div>
    <div class="blob-wrapper wrapper-4"><div class="blob blob-4"></div></div>
    <!-- لایه نارنجی -->
    <div class="blob-wrapper wrapper-5"><div class="blob blob-5"></div></div>
</div>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 relative z-10">

    <!-- هدر -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-8 gap-4 bg-white p-6 rounded-2xl border border-slate-200 shadow-sm relative overflow-hidden">
        <div class="absolute -left-10 -top-10 w-40 h-40 bg-emerald-50 rounded-full blur-3xl pointer-events-none"></div>
        
        <!-- بخش عنوان -->
        <div>
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-semibold mb-3">
                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                پنل آنالیز و شنود
            </div>
            <h1 class="text-3xl font-black text-slate-800">
                داشبورد مکالمات
            </h1>
            <p class="text-slate-500 text-sm mt-1">نمودارهای تحلیلی، عملکرد اپراتورها و شنود مکالمات</p>
        </div>

<!-- بخش اکشن‌ها و ساعت -->
<div class="flex flex-col sm:flex-row items-center gap-4">

    <!-- ویجت ساعت و تاریخ -->
    <div class="flex items-center gap-3 bg-gradient-to-l from-slate-50 to-white border border-slate-200 rounded-xl px-4 h-14 shadow-sm w-full sm:w-auto">
        <div class="flex flex-col items-end">
            <span class="text-xs text-slate-500 font-medium">امروز: <?php echo $today_jalali_fa; ?></span>
            <span id="live-clock" class="text-2xl font-black text-emerald-600 tracking-wider dir-ltr text-left mt-0.5" style="font-variant-numeric: tabular-nums;">۰۰:۰۰:۰۰</span>
        </div>
        <div class="w-10 h-10 rounded-full bg-emerald-50 flex items-center justify-center">
            <svg class="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
        </div>
    </div>

    <!-- دکمه گزارشات -->
    <a href="reports.php" class="inline-flex items-center justify-center gap-2 bg-white hover:bg-slate-50 text-slate-700 border border-slate-300 px-4 h-14 rounded-xl text-sm font-medium transition shadow-sm w-full sm:w-auto">
        <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2a4 4 0 014-4h4m0 0l-3-3m3 3l-3 3M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
        بخش گزارش مکالمات
    </a>

<!-- دکمه تغییر تم (سوئیچ) -->
<button type="button" id="theme-toggle" title="تغییر تم" aria-label="تغییر تم"
        class="theme-toggle w-full sm:w-auto">
    <span class="theme-track">
        <span class="theme-knob">
            <svg class="icon-sun" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
            </svg>
            <svg class="icon-moon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
            </svg>
        </span>
    </span>
</button>
</div>
    </div>

    <!-- بخش نمودارها -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        
        <!-- نمودار توزیع ساعتی (Bar) -->
        <div class="lg:col-span-2 bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
            <!-- هدر کارت و دکمه‌ها -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-4 gap-3">
                <h3 class="text-sm font-bold text-slate-700">توزیع تماس‌ها در ساعات شبانه‌روز</h3>
                
                <!-- دکمه‌های بازه زمانی -->
                <div class="flex flex-wrap gap-1.5" id="chart-range-buttons">
                    <?php
                    foreach ($ranges as $key => $label) {
                        $active = ($chart_range === $key);
                        $btnClass = $active 
                            ? 'bg-emerald-500 text-white border-emerald-500 shadow-sm' 
                            : 'bg-slate-50 text-slate-600 border-slate-200 hover:bg-slate-100';
                    ?>
                        <button type="button" data-range="<?php echo $key; ?>" 
                                class="chart-range-btn px-3 py-1.5 rounded-lg text-[11px] font-bold border transition <?php echo $btnClass; ?>">
                            <?php echo $label; ?>
                        </button>
                    <?php } ?>
                </div>
            </div>
            
            <div class="h-64 w-full" dir="ltr"><canvas id="hourlyChart"></canvas></div>
        </div>

        <!-- برترین اپراتورها -->
        <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
            <div class="flex items-center justify-between mb-4">
                               <h3 class="text-sm font-bold text-slate-700">
    برترین اپراتورها 
    <span id="operator-range-label" class="text-[10px] text-slate-400 font-normal">
        (<?php echo isset($ranges[$chart_range]) ? $ranges[$chart_range] : 'همه'; ?>)
    </span>
</h3>
                <svg class="w-4 h-4 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>
            </div>
            <div class="space-y-3" id="operators-list">
                <?php if (empty($top_operators)): ?>
                    <div class="text-center text-xs text-slate-400 py-4">داده‌ای وجود ندارد.</div>
                <?php else: ?>
                    <?php foreach ($top_operators as $ext => $op): 
                        $percent = ($op['cnt'] / $max_op_cnt) * 100;
                    ?>
                        <div>
                            <div class="flex items-center justify-between text-xs mb-1">
                                <span class="text-emerald-600 font-bold">داخلی <?php echo to_persian_num($ext); ?></span>
                                <span class="text-slate-600"><?php echo to_persian_num($op['cnt']); ?> تماس <span class="text-slate-400 text-[10px] mr-1">(<?php echo format_duration($op['sec']); ?>)</span></span>
                            </div>
                            <div class="w-full bg-slate-100 rounded-full h-1.5">
                                <div class="bg-emerald-500 h-1.5 rounded-full" style="width: <?php echo $percent; ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ردیف دوم نمودارها (وضعیت و جهت) -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
        <!-- Donut Chart: وضعیت -->
        <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
            <h3 class="text-sm font-bold text-slate-700 mb-4">وضعیت تماس‌ها</h3>
            <div class="h-48 w-full flex justify-center"><canvas id="statusChart"></canvas></div>
        </div>
        <!-- Donut Chart: جهت -->
        <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
            <h3 class="text-sm font-bold text-slate-700 mb-4">جهت تماس‌ها</h3>
            <div class="h-48 w-full flex justify-center"><canvas id="directionChart"></canvas></div>
        </div>
    </div>

    <!-- کارت فیلتر -->
    <div class="bg-white border border-slate-200 rounded-2xl p-6 mb-8 shadow-sm">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 items-end">
            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-2">از تاریخ (شمسی)</label>
                <input type="text" name="start_date" placeholder="1405/01/01" value="<?php echo htmlspecialchars($start_date); ?>"
                       class="w-full bg-slate-50 border border-slate-300 rounded-xl px-4 py-2.5 text-sm text-slate-800 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition">
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-2">تا تاریخ (شمسی)</label>
                <input type="text" name="end_date" placeholder="1405/01/30" value="<?php echo htmlspecialchars($end_date); ?>"
                       class="w-full bg-slate-50 border border-slate-300 rounded-xl px-4 py-2.5 text-sm text-slate-800 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition">
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-2">شماره داخلی</label>
                <select name="extension" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-4 py-2.5 text-sm text-slate-800 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition">
                    <option value="">-- همه داخلی‌ها --</option>
                    <?php foreach ($extensions_list as $ext): ?>
                        <option value="<?php echo htmlspecialchars($ext); ?>" <?php echo $extension === $ext ? 'selected' : ''; ?>>داخلی <?php echo to_persian_num($ext); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-2">جستجو شماره</label>
                <input type="text" name="search_number" placeholder="مثلاً 09121234567" value="<?php echo htmlspecialchars($search_number); ?>"
                       class="w-full bg-slate-50 border border-slate-300 rounded-xl px-4 py-2.5 text-sm text-slate-800 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition">
            </div>
            <div class="flex items-center gap-2 pb-1">
                <input type="checkbox" name="only_recorded" value="1" id="only_recorded" <?php echo $only_recorded ? 'checked' : ''; ?> class="w-4 h-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500 cursor-pointer">
                <label for="only_recorded" class="text-xs font-medium text-slate-600 cursor-pointer select-none">فقط مکالمات پاسخ‌داده‌شده</label>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white font-medium py-2.5 px-4 rounded-xl text-sm transition shadow-sm">اعمال فیلتر</button>
                <a href="?" class="bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 py-2.5 px-4 rounded-xl text-sm transition shadow-sm">ریست</a>
            </div>
        </form>
    </div>

    <!-- جدول داده‌ها -->
    <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-sm mb-6">
        <div class="overflow-x-auto">
            <table class="w-full text-right border-collapse">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-slate-600 text-xs font-bold uppercase tracking-wider">
                        <th class="py-4 px-4">تاریخ و زمان</th>
                        <th class="py-4 px-4">مبدا</th>
                        <th class="py-4 px-4">مقصد</th>
                        <th class="py-4 px-4">مدت مکالمه</th>
                        <th class="py-4 px-4">وضعیت</th>
                        <th class="py-4 px-4">پخش / دانلود</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-sm">
                <?php if (!empty($rows)): ?>
                    <?php foreach ($rows as $call):
                        $dt = new DateTime($call['calldate']);
                        $shamsi = gregorian_to_jalali((int)$dt->format('Y'), (int)$dt->format('m'), (int)$dt->format('d'));
                        $time = $dt->format('H:i:s');
                    ?>
                    <tr class="hover:bg-slate-50 transition">
                        <td class="py-4 px-4 whitespace-nowrap">
                            <span class="font-bold text-slate-800"><?php echo to_persian_num($shamsi); ?></span>
                            <span class="text-xs text-slate-500 mr-2"><?php echo to_persian_num($time); ?></span>
                        </td>
                        <td class="py-4 px-4 text-blue-600 font-bold"><?php echo to_persian_num(htmlspecialchars($call['src'])); ?></td>
                        <td class="py-4 px-4 text-indigo-600 font-bold"><?php echo to_persian_num(htmlspecialchars($call['dst'])); ?></td>
                        <td class="py-4 px-4 text-slate-600 font-medium"><?php echo to_persian_num(format_duration($call['billsec'])); ?></td>
                        <td class="py-4 px-4">
                            <?php if ($call['disposition'] === 'ANSWERED'): ?>
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-200">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> پاسخ داده شده
                                </span>
<?php else: 
    $disp_map = array(
        'NO ANSWER'  => 'بدون پاسخ',
        'BUSY'       => 'مشغول',
        'FAILED'     => 'ناموفق',
        'CONGESTION' => 'ازدحام',
        'CANCEL'     => 'لغو شده',
        'UNKNOWN'    => 'نامشخص'
    );
    $disp_fa = isset($disp_map[$call['disposition']]) ? $disp_map[$call['disposition']] : $call['disposition'];
?>
    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-rose-50 text-rose-700 border border-rose-200">
        <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span> <?php echo htmlspecialchars($disp_fa); ?>
    </span>
<?php endif; ?>
                        </td>
                        <td class="py-4 px-4">
                            <?php if (!empty($call['audio_files'])): ?>
                                <div class="space-y-2">
                                    <?php foreach ($call['audio_files'] as $f): ?>
                                        <div class="flex items-center gap-2">
                                            <!-- پلیر در تم روشن به صورت پیش‌فرض زیباست -->
                                            <audio controls preload="none" class="w-52 h-9 rounded-lg" src="play_audio.php?file=<?php echo urlencode($f['path']); ?>"></audio>
                                            <a href="play_audio.php?file=<?php echo urlencode($f['path']); ?>&download=1" class="inline-flex items-center justify-center bg-slate-100 hover:bg-emerald-100 text-slate-500 hover:text-emerald-700 w-8 h-8 rounded-lg transition" title="دانلود">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="inline-block text-xs font-medium text-slate-500 bg-slate-100 px-3 py-1.5 rounded-lg">بدون ضبط</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" class="py-12 text-center text-slate-400">هیچ رکوردی یافت نشد.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- صفحه‌بندی -->
    <?php if ($total_pages > 1): ?>
    <div class="flex flex-col sm:flex-row items-center justify-between bg-white border border-slate-200 rounded-2xl p-4 gap-4 shadow-sm">
        <div class="text-xs text-slate-500 font-medium">
            صفحه <span class="text-emerald-600 font-bold"><?php echo to_persian_num($page); ?></span> از <span class="text-slate-800 font-bold"><?php echo to_persian_num($total_pages); ?></span>
            — مجموع: <span class="text-slate-800 font-bold"><?php echo to_persian_num(number_format($total_records)); ?></span> رکورد
        </div>
        <div class="flex items-center gap-1 flex-wrap justify-center">
            <?php
            $start_p = max(1, $page - 2);
            $end_p   = min($total_pages, $page + 2);
            if ($page > 1) echo '<a href="?' . build_qs(array_merge($base_params, array('page' => $page - 1))) . '" class="px-3.5 py-2 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 rounded-xl text-xs font-medium transition shadow-sm">قبلی</a>';
            if ($start_p > 1) {
                echo '<a href="?' . build_qs(array_merge($base_params, array('page' => 1))) . '" class="px-3.5 py-2 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 rounded-xl text-xs font-medium transition shadow-sm">' . to_persian_num(1) . '</a>';
                if ($start_p > 2) echo '<span class="px-2 text-slate-400">...</span>';
            }
            for ($i = $start_p; $i <= $end_p; $i++) {
                $cls = $i === $page ? 'bg-emerald-600 text-white font-bold border-emerald-600 shadow-sm' : 'bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 shadow-sm';
                echo '<a href="?' . build_qs(array_merge($base_params, array('page' => $i))) . '" class="px-3.5 py-2 rounded-xl text-xs transition border ' . $cls . '">' . to_persian_num($i) . '</a>';
            }
            if ($end_p < $total_pages) {
                if ($end_p < $total_pages - 1) echo '<span class="px-2 text-slate-400">...</span>';
                echo '<a href="?' . build_qs(array_merge($base_params, array('page' => $total_pages))) . '" class="px-3.5 py-2 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 rounded-xl text-xs font-medium transition shadow-sm">' . to_persian_num($total_pages) . '</a>';
            }
            if ($page < $total_pages) echo '<a href="?' . build_qs(array_merge($base_params, array('page' => $page + 1))) . '" class="px-3.5 py-2 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 rounded-xl text-xs font-medium transition shadow-sm">بعدی</a>';
            ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- اسکریپت‌های Chart.js با تم روشن -->
<script>
    // ۱. اسکریپت ساعت زنده
    function updateClock() {
        var now = new Date();
        var h = String(now.getHours()).padStart(2, '0');
        var m = String(now.getMinutes()).padStart(2, '0');
        var s = String(now.getSeconds()).padStart(2, '0');
        var timeStr = h + ':' + m + ':' + s;

        // تبدیل به اعداد فارسی
        var faTimeStr = timeStr.replace(/[0-9]/g, function(d) {
            return '۰۱۲۳۴۵۶۷۸۹'[d];
        });
        
        var clockEl = document.getElementById('live-clock');
        if (clockEl) clockEl.textContent = faTimeStr;
    }
    setInterval(updateClock, 1000);
    updateClock(); // فراخوانی اولیه

    // ۲. اسکریپت‌های نمودار
    document.addEventListener('DOMContentLoaded', function() {
        // تولتیپ‌های سفید با متن تیره
        var commonTooltip = {
            backgroundColor: '#ffffff',
            titleColor: '#1e293b',
            bodyColor: '#475569',
            borderColor: '#e2e8f0',
            borderWidth: 1,
            padding: 10,
            boxShadow: '0 4px 6px -1px rgba(0, 0, 0, 0.1)'
        };

        // تنظیمات مشترک محورها
        var commonScales = {
            y: { 
                beginAtZero: true, 
                ticks: { color: '#64748b', stepSize: 1, font: { family: 'Vazirmatn' } }, 
                grid: { color: '#f1f5f9' } 
            },
            x: { 
                ticks: { color: '#64748b', maxTicksLimit: 12, font: { family: 'Vazirmatn' } }, 
                grid: { display: false } 
            }
        };

        // نمودار ساعتی (Bar)
        var ctxHourly = document.getElementById('hourlyChart').getContext('2d');
        var hourlyData = <?php echo json_encode(array_values($hourly_data)); ?>;
        var labels = [];
        for (var i = 0; i < 24; i++) labels.push(i.toString().padStart(2, '0') + ':00');

        var gradient = ctxHourly.createLinearGradient(0, 0, 0, 250);
        gradient.addColorStop(0, 'rgba(16, 185, 129, 0.9)'); // Emerald 500
        gradient.addColorStop(1, 'rgba(16, 185, 129, 0.1)');

        window.myHourlyChart = new Chart(ctxHourly, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'تعداد تماس',
                    data: hourlyData,
                    backgroundColor: gradient,
                    borderColor: '#10b981',
                    borderWidth: 1,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: commonTooltip },
                scales: commonScales
            }
        });

        // نمودار وضعیت (Doughnut)
        var ctxStatus = document.getElementById('statusChart').getContext('2d');
        var statusData = <?php echo json_encode(array_values($status_data)); ?>;
        
        new Chart(ctxStatus, {
            type: 'doughnut',
            data: {
                labels: ['پاسخ داده شده', 'بدون پاسخ', 'مشغول', 'ناموفق', 'سایر'],
                datasets: [{
                    data: statusData,
                    backgroundColor: ['#10b981', '#f43f5e', '#f59e0b', '#64748b', '#cbd5e1'],
                    borderWidth: 2,
                    borderColor: '#ffffff',
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: {
                    legend: { position: 'right', labels: { color: '#475569', font: { family: 'Vazirmatn', size: 11 }, padding: 20 } },
                    tooltip: commonTooltip
                }
            }
        });

        // نمودار جهت (Doughnut)
        var ctxDirection = document.getElementById('directionChart').getContext('2d');
        var dirData = <?php echo json_encode(array_values($direction_data)); ?>;
        
        new Chart(ctxDirection, {
            type: 'doughnut',
            data: {
                labels: ['ورودی', 'خروجی', 'داخلی', 'ترانزیت'],
                datasets: [{
                    data: dirData,
                    backgroundColor: ['#3b82f6', '#8b5cf6', '#f59e0b', '#ec4899'], // Blue, Violet, Amber, Pink
                    borderWidth: 2,
                    borderColor: '#ffffff',
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: {
                    legend: { position: 'right', labels: { color: '#475569', font: { family: 'Vazirmatn', size: 11 }, padding: 20 } },
                    tooltip: commonTooltip
                }
            }
        });
    });
	
	// دکمه تغییر تم
    (function() {
        var btn   = document.getElementById('theme-toggle');
        var label = document.getElementById('theme-label');
        if (!btn) return;

        function syncLabel() {
            if (!label) return;
            var isDark = document.documentElement.classList.contains('dark');
            label.textContent = isDark ? 'حالت روشن' : 'حالت تاریک';
        }
        syncLabel();

        btn.addEventListener('click', function() {
            var isDark = document.documentElement.classList.contains('dark');
            if (isDark) {
                document.documentElement.classList.remove('dark');
                try { localStorage.setItem('theme', 'light'); } catch(e) {}
            } else {
                document.documentElement.classList.add('dark');
                try { localStorage.setItem('theme', 'dark'); } catch(e) {}
            }
            syncLabel();
        });
    })();
	
	    // ==========================================
    // اسکریپت تغییر بازه زمانی با ایجکس (بدون رفرش)
    // ==========================================
    document.querySelectorAll('.chart-range-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var range = this.getAttribute('data-range');
            
            // 1. تغییر ظاهر دکمه‌ها (فعال/غیرفعال)
            document.querySelectorAll('.chart-range-btn').forEach(function(b) {
                b.className = 'chart-range-btn px-3 py-1.5 rounded-lg text-[11px] font-bold border transition bg-slate-50 text-slate-600 border-slate-200 hover:bg-slate-100';
            });
            this.className = 'chart-range-btn px-3 py-1.5 rounded-lg text-[11px] font-bold border transition bg-emerald-500 text-white border-emerald-500 shadow-sm';

            // 2. گرفتن پارامترهای فعلی URL
            var urlParams = new URLSearchParams(window.location.search);
            urlParams.set('chart_range', range);
            urlParams.set('ajax', '1');

            // 3. درخواست به سرور
            fetch('?' + urlParams.toString())
                .then(response => response.json())
                .then(data => {
                    // آپدیت نمودار ساعتی
                    if(window.myHourlyChart) {
                        window.myHourlyChart.data.datasets[0].data = data.hourly_data;
                        window.myHourlyChart.update();
                    }

                    // آپدیت لیبل بازه در کارت اپراتورها
                    var labelEl = document.getElementById('operator-range-label');
                    if(labelEl) labelEl.textContent = '(' + data.range_label + ')';

                    // آپدیت لیست برترین اپراتورها
                    var opHtml = '';
                    var operators = data.operators;
                    
                    if (Object.keys(operators).length === 0) {
                        opHtml = '<div class="text-center text-xs text-slate-400 py-4">داده‌ای وجود ندارد.</div>';
                    } else {
                        for (var ext in operators) {
                            var op = operators[ext];
                            var percent = (op.cnt / data.max_op_cnt) * 100;
                            var secFormatted = new Date(op.sec * 1000).toISOString().substr(11, 8); 
                            
                            var faExt = ext.replace(/[0-9]/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
                            var faCnt = String(op.cnt).replace(/[0-9]/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
                            var faSec = secFormatted.replace(/[0-9]/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);

                            opHtml += '<div>' +
                                '<div class="flex items-center justify-between text-xs mb-1">' +
                                    '<span class="text-emerald-600 font-bold">داخلی ' + faExt + '</span>' +
                                    '<span class="text-slate-600">' + faCnt + ' تماس <span class="text-slate-400 text-[10px] mr-1">(' + faSec + ')</span></span>' +
                                '</div>' +
                                '<div class="w-full bg-slate-100 rounded-full h-1.5">' +
                                    '<div class="bg-emerald-500 h-1.5 rounded-full" style="width: ' + percent + '%"></div>' +
                                '</div>' +
                            '</div>';
                        }
                    }
                    var listEl = document.getElementById('operators-list');
                    if(listEl) listEl.innerHTML = opHtml;

                    // 4. آپدیت تاریخچه مرورگر بدون رفرش
                    urlParams.delete('ajax');
                    window.history.pushState({}, '', '?' + urlParams.toString());
                })
                .catch(error => console.error('Error:', error));
        });
    });
	
	    // ==========================================
    // حرکت المان‌های نوری با حرکت موس (Parallax)
    // ==========================================
    (function() {
        var blobs = document.querySelectorAll('.blob');
        if (blobs.length === 0) return;

        var mouseX = 0;
        var mouseY = 0;
        var targetX = 0;
        var targetY = 0;
        var speed = 0.08; // میزان نرمی حرکت (هرچه کمتر، نرم‌تر)

        window.addEventListener('mousemove', function(e) {
            // محاسبه فاصله موس از مرکز صفحه
            targetX = (e.clientX - window.innerWidth / 2) * 0.15;
            targetY = (e.clientY - window.innerHeight / 2) * 0.15;
        });

        function animateBlobs() {
            // حرکت نرم به سمت هدف
            mouseX += (targetX - mouseX) * speed;
            mouseY += (targetY - mouseY) * speed;

            blobs.forEach(function(blob, index) {
                // هر المان با سرعت متفاوتی حرکت می‌کند تا حس عمق (3D) ایجاد شود
                var depth = (index + 1) * 0.4; 
                blob.style.transform = 'translate(' + (mouseX * depth) + 'px, ' + (mouseY * depth) + 'px)';
            });

            requestAnimationFrame(animateBlobs);
        }

        // شروع انیمیشن
        animateBlobs();
    })();
	
	
</script>

</body>
</html>
<?php $mysqli->close(); ?>