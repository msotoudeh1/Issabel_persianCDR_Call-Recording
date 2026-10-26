<?php
// ==========================================
// reports.php - گزارش متنی مکالمات ایزابل (نسخه کامل و جامع با ۱۴ فلش‌کارت)
// ==========================================
$config = require __DIR__ . '/config.php';

$db_host = $config['db_host'];
$db_name = $config['db_name'];
$db_user = $config['db_user'];
$db_pass = $config['db_pass'];

$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mysqli->connect_error) {
    die("<div style='text-align:center;padding:50px;font-family:sans-serif;color:#ef4444;'>خطا در اتصال به دیتابیس.</div>");
}
$mysqli->set_charset("utf8mb4");

// ==========================================
// توابع کمکی عمومی
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

function get_jalali_month_name($g_month) {
    $months = array(
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر',
        5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان',
        9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'
    );
    return isset($months[$g_month]) ? $months[$g_month] : '';
}

// ==========================================
// توابع تشخیص ترانک / داخلی / نوع شماره
// ==========================================
function is_trunk_channel($channel) {
    if (empty($channel)) return false;
    if (preg_match('#^DAHDI/#i', $channel)) return true;
    if (preg_match('#^Local/#i', $channel)) return false;
    if (preg_match('#^(?:SIP|PJSIP|IAX2|SCCP)/([^-@;]+)#i', $channel, $m)) {
        $peer = $m[1];
        if (ctype_digit($peer)) return false;
        return true;
    }
    return false;
}

function extract_extension_from_channel($channel) {
    if (empty($channel)) return '';
    if (preg_match('#^(?:SIP|PJSIP|IAX2|SCCP|Local)/(\d+)(?:[-;@]|$)#i', $channel, $m)) {
        return $m[1];
    }
    return '';
}

function find_involved_extension($channel, $dstchannel) {
    $ext = extract_extension_from_channel($dstchannel);
    if ($ext !== '') return $ext;
    return extract_extension_from_channel($channel);
}

function classify_number($number, $extensions_list) {
    $raw = trim((string)$number);
    if ($raw === '') return 'unknown';
    $clean = preg_replace('/[^0-9]/', '', $raw);
    if ($clean === '') return 'unknown';

    if (in_array($raw, $extensions_list, true))   return 'extension';
    if (in_array($clean, $extensions_list, true)) return 'extension';

    $len = strlen($clean);
    if ($len >= 3 && $len <= 5) return 'extension';
    if (substr($clean, 0, 2) === '00') return 'international';
    if ($len === 11 && substr($clean, 0, 2) === '09') return 'mobile';
    if ($len === 10 && substr($clean, 0, 1) === '9')  return 'mobile';
    if ($len === 11 && substr($clean, 0, 1) === '0')  return 'city';
    if ($len === 10) return 'city';
    if ($len === 8)  return 'city';

    return 'unknown';
}

function classify_call_direction($channel, $dstchannel, $src, $dst, $dcontext, $extensions_list) {
    $ch_trunk  = is_trunk_channel($channel);
    $dst_trunk = is_trunk_channel($dstchannel);

    if ($ch_trunk && $dst_trunk)  return 'transit';
    if ($ch_trunk && !$dst_trunk) return 'inbound';
    if (!$ch_trunk && $dst_trunk) return 'outbound';

    $src_t = classify_number($src, $extensions_list);
    $dst_t = classify_number($dst, $extensions_list);
    $src_ext = ($src_t === 'extension');
    $dst_ext = ($dst_t === 'extension');

    if ($src_ext && $dst_ext)   return 'internal';
    if (!$src_ext && $dst_ext)  return 'inbound';
    if ($src_ext && !$dst_ext)  return 'outbound';

    if (!empty($dcontext)) {
        if (preg_match('#^from-(trunk|pstn|sip-external|analog|did|outside)#i', $dcontext)) return 'inbound';
        if (preg_match('#^(outbound|outrt|out-|outbound-)#i', $dcontext)) return 'outbound';
        if (preg_match('#^from-internal#i', $dcontext)) return 'internal';
    }
    return 'unknown';
}

function direction_label($direction) {
    switch ($direction) {
        case 'inbound':  return 'ورودی';
        case 'outbound': return 'خروجی';
        case 'internal': return 'داخلی';
        case 'transit':  return 'ترانزیت';
        default:         return 'نامشخص';
    }
}

function direction_badge_class($direction) {
    switch ($direction) {
        case 'inbound':  return 'bg-cyan-50 text-cyan-700 border-cyan-200';
        case 'outbound': return 'bg-violet-50 text-violet-700 border-violet-200';
        case 'internal': return 'bg-amber-50 text-amber-700 border-amber-200';
        case 'transit':  return 'bg-fuchsia-50 text-fuchsia-700 border-fuchsia-200';
        default:         return 'bg-slate-50 text-slate-600 border-slate-200';
    }
}

function number_type_label($type) {
    switch ($type) {
        case 'extension':     return 'داخلی';
        case 'city':          return 'شهری';
        case 'mobile':        return 'همراه';
        case 'international': return 'بین‌المللی';
        default:              return '';
    }
}

function number_type_badge_class($type) {
    switch ($type) {
        case 'city':          return 'bg-blue-50 text-blue-700 border-blue-200';
        case 'mobile':        return 'bg-pink-50 text-pink-700 border-pink-200';
        case 'international': return 'bg-rose-50 text-rose-700 border-rose-200';
        default:              return 'bg-slate-50 text-slate-600 border-slate-200';
    }
}

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
sort($extensions_list);

// ==========================================
// لیست وضعیت‌ها
// ==========================================
$dispositions = array();
$res = $mysqli->query("SELECT DISTINCT disposition FROM cdr WHERE disposition IS NOT NULL AND disposition != '' ORDER BY disposition");
if ($res) {
    while ($row = $res->fetch_assoc()) $dispositions[] = $row['disposition'];
}

$disp_labels = array(
    'ANSWERED'   => 'پاسخ داده شده',
    'NO ANSWER'  => 'بدون پاسخ',
    'BUSY'       => 'مشغول',
    'FAILED'     => 'ناموفق',
    'CONGESTION' => 'ترافیک',
    'CANCEL'     => 'لغو شده',
);

// ==========================================
// فیلترها
// ==========================================
$start_date    = trim(isset($_GET['start_date'])    ? $_GET['start_date']    : '');
$end_date      = trim(isset($_GET['end_date'])      ? $_GET['end_date']      : '');
$extension     = trim(isset($_GET['extension'])     ? $_GET['extension']     : '');
$disposition   = trim(isset($_GET['disposition'])   ? $_GET['disposition']   : '');
$search_number = preg_replace('/[^0-9+]/', '', isset($_GET['search_number']) ? $_GET['search_number'] : '');

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
if ($disposition !== '') {
    $safe = $mysqli->real_escape_string($disposition);
    $where[] = "disposition = '$safe'";
}
if ($search_number !== '') {
    $safe = $mysqli->real_escape_string($search_number);
    $where[] = "(src LIKE '%$safe%' OR dst LIKE '%$safe%')";
}

$where_clause = $where ? "WHERE " . implode(" AND ", $where) : "";

// ==========================================
// خروجی CSV
// ==========================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $export_limit = 5000;

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=calls_report_' . date('Ymd_His') . '.csv');
    header('Cache-Control: private, max-age=0');

    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF");
    fputcsv($output, array(
        'تاریخ و زمان', 'مبدا', 'مقصد', 'جهت تماس',
        'نوع مبدا', 'نوع مقصد', 'داخلی درگیر',
        'مدت انتظار', 'مدت مکالمه', 'وضعیت'
    ));

    $export_sql = "SELECT calldate, src, dst, duration, billsec, disposition,
                          channel, dstchannel, dcontext
                   FROM cdr $where_clause
                   ORDER BY calldate DESC
                   LIMIT $export_limit";
    $export_res = $mysqli->query($export_sql);

    if ($export_res) {
        while ($row = $export_res->fetch_assoc()) {
            $dt = new DateTime($row['calldate']);
            $shamsi = gregorian_to_jalali((int)$dt->format('Y'), (int)$dt->format('m'), (int)$dt->format('d'));
            $direction = classify_call_direction($row['channel'], $row['dstchannel'], $row['src'], $row['dst'], $row['dcontext'], $extensions_list);
            $src_type  = classify_number($row['src'], $extensions_list);
            $dst_type  = classify_number($row['dst'], $extensions_list);
            $ans_ext   = find_involved_extension($row['channel'], $row['dstchannel']);
            $wait      = max(0, (int)$row['duration'] - (int)$row['billsec']);
            $disp_label = isset($disp_labels[$row['disposition']]) ? $disp_labels[$row['disposition']] : $row['disposition'];

            fputcsv($output, array(
                $shamsi . ' ' . $dt->format('H:i:s'),
                $row['src'],
                $row['dst'],
                direction_label($direction),
                number_type_label($src_type),
                number_type_label($dst_type),
                $ans_ext !== '' ? $ans_ext : '—',
                format_duration($wait),
                format_duration($row['billsec']),
                $disp_label,
            ));
        }
    }
    fclose($output);
    exit;
}

// ==========================================
// آمار کلی (کارت‌های اصلی)
// ==========================================
$stats_sql = "SELECT
    COUNT(DISTINCT uniqueid) AS total_calls,
    COUNT(DISTINCT CASE WHEN disposition = 'ANSWERED' THEN uniqueid END) AS answered_calls,
    COUNT(DISTINCT CASE WHEN disposition != 'ANSWERED' THEN uniqueid END) AS missed_calls,
    COALESCE(SUM(billsec), 0) AS total_duration
    FROM cdr $where_clause";
$stats_res = $mysqli->query($stats_sql);
$stats = $stats_res ? $stats_res->fetch_assoc() : array(
    'total_calls' => 0, 'answered_calls' => 0, 'missed_calls' => 0, 'total_duration' => 0,
);
if (!$stats) {
    $stats = array('total_calls' => 0, 'answered_calls' => 0, 'missed_calls' => 0, 'total_duration' => 0);
}

// ==========================================
// ۴ آمار تحلیلی سبک (بدون درگیر کردن شدید دیتابیس)
// ==========================================

// ۱. نرخ پاسخ‌دهی (محاسبه از آمار موجود، بدون کوئری جدید)
$answer_rate = $stats['total_calls'] > 0 ? round(($stats['answered_calls'] / $stats['total_calls']) * 100, 1) : 0;

// ۲. میانگین زمان مکالمه (فقط تماس‌های پاسخ داده شده)
$avg_talk_sec = 0;
$w_ans = $where; 
$w_ans[] = "disposition = 'ANSWERED'";
$where_ans_clause = $w_ans ? "WHERE " . implode(" AND ", $w_ans) : "";
$res_at = $mysqli->query("SELECT AVG(billsec) as avg_t FROM cdr $where_ans_clause");
if ($res_at && $row_at = $res_at->fetch_assoc()) {
    $avg_talk_sec = (int)round($row_at['avg_t']);
}

// ۳. میانگین زمان انتظار (کل زمان منهای زمان مکالمه)
$avg_wait_sec = 0;
$res_wait = $mysqli->query("SELECT AVG(GREATEST(0, duration - billsec)) as avg_w FROM cdr $where_clause");
if ($res_wait && $row_w = $res_wait->fetch_assoc()) {
    $avg_wait_sec = (int)round($row_w['avg_w']);
}

// ۴. شلوغ‌ترین ساعت روز (سبک‌ترین نوع GROUP BY چون فقط ۲۴ خروجی دارد)
$peak_hour_str = '—';
$res_hour = $mysqli->query("SELECT HOUR(calldate) as h, COUNT(*) as cnt FROM cdr $where_clause GROUP BY HOUR(calldate) ORDER BY cnt DESC LIMIT 1");
if ($res_hour && $row_h = $res_hour->fetch_assoc()) {
    if ($row_h['h'] !== null) {
        $h = (int)$row_h['h'];
        $next_h = ($h + 1) % 24;
        $peak_hour_str = sprintf('%02d:00 الی %02d:00', $h, $next_h);
    }
}

// ==========================================
// صفحه‌بندی
// ==========================================
$per_page = 20;
$page = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
$total_records = (int)$stats['total_calls'];
$total_pages = max(1, (int)ceil($total_records / $per_page));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;

// ==========================================
// کوئری اصلی لیست مکالمات
// ==========================================
$sql = "SELECT calldate, src, dst, duration, billsec, disposition, uniqueid,
               channel, dstchannel, lastapp, lastdata, accountcode, userfield,
               dcontext, amaflags
        FROM cdr $where_clause
        ORDER BY calldate DESC
        LIMIT $offset, $per_page";
$result = $mysqli->query($sql);

$rows = array();
$js_data = array();
if ($result) {
    while ($call = $result->fetch_assoc()) {
        $dt = new DateTime($call['calldate']);
        $call['shamsi']       = gregorian_to_jalali((int)$dt->format('Y'), (int)$dt->format('m'), (int)$dt->format('d'));
        $call['time_str']     = $dt->format('H:i:s');
        $call['wait_sec']     = max(0, (int)$call['duration'] - (int)$call['billsec']);
        $call['answering_ext'] = find_involved_extension($call['channel'], $call['dstchannel']);
        $call['direction']    = classify_call_direction($call['channel'], $call['dstchannel'], $call['src'], $call['dst'], $call['dcontext'], $extensions_list);
        $call['dir_label']    = direction_label($call['direction']);
        $call['src_type']     = classify_number($call['src'], $extensions_list);
        $call['dst_type']     = classify_number($call['dst'], $extensions_list);
        $call['src_type_label'] = number_type_label($call['src_type']);
        $call['dst_type_label'] = number_type_label($call['dst_type']);
        $call['disp_label']   = isset($disp_labels[$call['disposition']])
                                ? $disp_labels[$call['disposition']]
                                : ($call['disposition'] ? $call['disposition'] : 'نامشخص');
        $rows[] = $call;

        $js_data[] = array(
            'date_label'       => to_persian_num($call['shamsi']) . ' - ' . to_persian_num($call['time_str']),
            'src'              => $call['src'],
            'src_type_label'   => $call['src_type_label'],
            'dst'              => $call['dst'],
            'dst_type_label'   => $call['dst_type_label'],
            'dir_label'        => $call['dir_label'],
            'answering_ext'    => $call['answering_ext'],
            'wait_label'       => format_duration($call['wait_sec']),
            'billsec_label'    => format_duration($call['billsec']),
            'duration_label'   => format_duration($call['duration']),
            'disp_label'       => $call['disp_label'],
            'channel'          => $call['channel'],
            'dstchannel'       => $call['dstchannel'],
            'lastapp'          => $call['lastapp'],
            'lastdata'         => $call['lastdata'],
            'accountcode'      => $call['accountcode'],
            'userfield'        => $call['userfield'],
            'dcontext'         => $call['dcontext'],
            'uniqueid'         => $call['uniqueid'],
        );
    }
}

$base_params = $_GET;
unset($base_params['page'], $base_params['export']);

function build_qs($params) {
    return htmlspecialchars(http_build_query($params), ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>گزارش مکالمات ایزابل</title>
    <!-- اسکریپت پیش‌بارگذاری تم -->
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
    
    <script>
        tailwind.config = { 
            darkMode: 'class',
            theme: { 
                extend: { 
                    fontFamily: { sans: ['Vazirmatn', 'sans-serif'] } 
                } 
            } 
        }
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
        html.dark .bg-cyan-50 { background-color: rgba(6,182,212,0.12) !important; }
        html.dark .bg-violet-50 { background-color: rgba(139,92,246,0.12) !important; }
        html.dark .bg-amber-50 { background-color: rgba(245,158,11,0.12) !important; }
        html.dark .bg-blue-50 { background-color: rgba(59,130,246,0.12) !important; }
        html.dark .bg-pink-50 { background-color: rgba(236,72,153,0.12) !important; }
        html.dark .bg-gradient-to-br { background-image: none !important; background-color: #111c2e !important; }
        html.dark .bg-slate-50\/50 { background-color: rgba(15,23,42,0.5) !important; }

        html.dark .text-slate-800 { color: #f1f5f9 !important; }
        html.dark .text-slate-700 { color: #e2e8f0 !important; }
        html.dark .text-slate-600 { color: #cbd5e1 !important; }
        html.dark .text-slate-500 { color: #94a3b8 !important; }
        html.dark .text-slate-400 { color: #64748b !important; }
        html.dark .text-emerald-600 { color: #34d399 !important; }
        html.dark .text-rose-600 { color: #fb7185 !important; }
        html.dark .text-blue-600 { color: #60a5fa !important; }
        html.dark .text-indigo-600 { color: #a5b4fc !important; }
        html.dark .text-cyan-700 { color: #67e8f9 !important; }
        html.dark .text-violet-700 { color: #c4b5fd !important; }
        html.dark .text-amber-700 { color: #fcd34d !important; }

        html.dark .border-slate-200 { border-color: #1e293b !important; }
        html.dark .border-slate-300 { border-color: #334155 !important; }
        html.dark .divide-slate-100 > * + * { border-color: #1e293b !important; }
        html.dark .border-emerald-200 { border-color: rgba(16,185,129,0.3) !important; }
        html.dark .border-rose-200 { border-color: rgba(244,63,94,0.3) !important; }

        html.dark .hover\:bg-slate-50:hover { background-color: rgba(255,255,255,0.06) !important; }
        html.dark .hover\:bg-slate-200:hover { background-color: rgba(255,255,255,0.1) !important; }
        html.dark input, html.dark select { color-scheme: dark; }

        /* --- سوئیچ تم --- */
        .theme-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 12px;
            height: 42px; /* هماهنگ با دکمه‌های گزارش */
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

        html.dark .theme-toggle { background: #111c2e; border-color: #334155; }
        html.dark .theme-toggle:hover { background: #1e293b; }
        html.dark .theme-track { background: #475569; }
        html.dark .theme-knob { transform: translateX(24px); background: #0f172a; }
        html.dark .theme-knob .icon-sun { opacity: 0; transform: rotate(90deg) scale(0.4); }
        html.dark .theme-knob .icon-moon { opacity: 1; transform: rotate(0deg) scale(1); }
		
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
<body class="bg-slate-50 text-slate-800 font-sans min-h-screen antialiased selection:bg-emerald-200 selection:text-emerald-900 overflow-x-hidden">

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

    <!-- ===== هدر ===== -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-8 gap-4 bg-white p-6 rounded-2xl border border-slate-200 shadow-sm relative overflow-hidden">
        <div class="absolute -left-10 -top-10 w-40 h-40 bg-emerald-50 rounded-full blur-3xl pointer-events-none"></div>
        <div>
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-semibold mb-3">
                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                سامانه گزارش‌گیری ایزابل
            </div>
            <h1 class="text-3xl font-black text-slate-800">
                گزارش مکالمات تلفنی
            </h1>
            <p class="text-slate-500 text-sm mt-1">تحلیل و نظارت پیشرفته بر مکالمات مرکز تماس</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="monitoring.php" class="inline-flex items-center gap-2 bg-white hover:bg-slate-50 text-slate-700 border border-slate-300 px-4 py-2.5 rounded-xl text-sm font-medium transition shadow-sm h-[42px]">
                <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.536 8.464a5 5 0 010 7.072m2.828-9.9a9 9 0 010 12.728M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z"/></svg>
                بخش مکالمات ضبط شده
            </a>
            <a href="?<?php echo build_qs(array_merge($base_params, array('export' => 'csv'))); ?>"
               class="inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white border border-transparent px-4 py-2.5 rounded-xl text-sm font-medium transition shadow-sm h-[42px]">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                خروجی CSV
            </a>
            
            <!-- دکمه تغییر تم (سوئیچ) -->
            <button type="button" id="theme-toggle" title="تغییر تم" aria-label="تغییر تم" class="theme-toggle">
                <span class="theme-track">
                    <span class="theme-knob">
                        <svg class="icon-sun" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                        <svg class="icon-moon" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
                        </svg>
                    </span>
                </span>
            </button>
        </div>
    </div>

    <!-- ===== آمار اصلی ===== -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">کل تماس‌ها</span>
            <div class="text-2xl font-bold text-slate-800 mt-2"><?php echo to_persian_num(number_format((int)$stats['total_calls'])); ?></div>
        </div>
        <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
            <span class="text-xs font-semibold text-emerald-600 uppercase tracking-wider">پاسخ داده شده</span>
            <div class="text-2xl font-bold text-emerald-600 mt-2"><?php echo to_persian_num(number_format((int)$stats['answered_calls'])); ?></div>
        </div>
        <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
            <span class="text-xs font-semibold text-rose-600 uppercase tracking-wider">بدون پاسخ / ناموفق</span>
            <div class="text-2xl font-bold text-rose-600 mt-2"><?php echo to_persian_num(number_format((int)$stats['missed_calls'])); ?></div>
        </div>
        <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm">
            <span class="text-xs font-semibold text-blue-600 uppercase tracking-wider">مجموع مکالمات</span>
            <div class="text-2xl font-bold text-blue-600 mt-2"><?php echo format_duration((int)$stats['total_duration']); ?></div>
        </div>
    </div>

        <!-- ===== ۴ فلش کارت تحلیلی سبک ===== -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        
        <!-- 1. نرخ پاسخ‌دهی -->
        <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm hover:shadow-md transition">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">نرخ پاسخ‌دهی</span>
                <div class="p-1.5 bg-amber-100 rounded-lg">
                    <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
            </div>
            <div class="text-2xl font-bold text-amber-600 mt-2"><?php echo to_persian_num($answer_rate); ?>%</div>
            <div class="text-xs text-slate-500 mt-1 font-medium">از کل تماس‌های ثبت شده</div>
        </div>

        <!-- 2. میانگین زمان مکالمه -->
        <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm hover:shadow-md transition">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">میانگین زمان مکالمه</span>
                <div class="p-1.5 bg-sky-100 rounded-lg">
                    <svg class="w-4 h-4 text-sky-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 100-6 3 3 0 000 6z"></path></svg>
                </div>
            </div>
            <div class="text-2xl font-bold text-sky-600 mt-2" dir="ltr"><?php echo to_persian_num(format_duration($avg_talk_sec)); ?></div>
            <div class="text-xs text-slate-500 mt-1 font-medium">برای تماس‌های موفق</div>
        </div>

        <!-- 3. میانگین زمان انتظار -->
        <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm hover:shadow-md transition">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">میانگین زمان انتظار</span>
                <div class="p-1.5 bg-teal-100 rounded-lg">
                    <svg class="w-4 h-4 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
            </div>
            <div class="text-2xl font-bold text-teal-600 mt-2" dir="ltr"><?php echo to_persian_num(format_duration($avg_wait_sec)); ?></div>
            <div class="text-xs text-slate-500 mt-1 font-medium">پشت خط / صف انتظار</div>
        </div>

        <!-- 4. شلوغ‌ترین ساعت روز -->
        <div class="bg-white border border-slate-200 rounded-2xl p-5 shadow-sm hover:shadow-md transition">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">شلوغ‌ترین ساعت روز</span>
                <div class="p-1.5 bg-rose-100 rounded-lg">
                    <svg class="w-4 h-4 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
            </div>
            <div class="text-2xl font-bold text-rose-600 mt-2" dir="ltr"><?php echo to_persian_num($peak_hour_str); ?></div>
            <div class="text-xs text-slate-500 mt-1 font-medium">بازه اوج ترافیک تماس‌ها</div>
        </div>

    </div>
	
    <!-- ===== فیلترها ===== -->
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
                        <option value="<?php echo htmlspecialchars($ext); ?>" <?php echo $extension === $ext ? 'selected' : ''; ?>>
                            داخلی <?php echo to_persian_num($ext); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-2">وضعیت تماس</label>
                <select name="disposition" class="w-full bg-slate-50 border border-slate-300 rounded-xl px-4 py-2.5 text-sm text-slate-800 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition">
                    <option value="">-- همه وضعیت‌ها --</option>
                    <?php foreach ($dispositions as $d): ?>
                        <option value="<?php echo htmlspecialchars($d); ?>" <?php echo $disposition === $d ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(isset($disp_labels[$d]) ? $disp_labels[$d] : $d); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-600 mb-2">جستجو شماره (مبدا/مقصد)</label>
                <input type="text" name="search_number" placeholder="مثلاً 09121234567" value="<?php echo htmlspecialchars($search_number); ?>"
                       class="w-full bg-slate-50 border border-slate-300 rounded-xl px-4 py-2.5 text-sm text-slate-800 focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white font-medium py-2.5 px-4 rounded-xl text-sm transition shadow-sm">
                    اعمال فیلتر
                </button>
                <a href="?" class="bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 py-2.5 px-4 rounded-xl text-sm transition shadow-sm">ریست</a>
            </div>
        </form>
    </div>

    <!-- ===== جدول ===== -->
    <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-sm mb-6">
        <div class="overflow-x-auto">
            <table class="w-full text-right border-collapse">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-slate-600 text-xs font-bold uppercase tracking-wider">
                        <th class="py-4 px-4">تاریخ و زمان</th>
                        <th class="py-4 px-4">مبدا / جهت</th>
                        <th class="py-4 px-4">مقصد</th>
                        <th class="py-4 px-4">داخلی درگیر</th>
                        <th class="py-4 px-4">مدت انتظار</th>
                        <th class="py-4 px-4">مدت مکالمه</th>
                        <th class="py-4 px-4">وضعیت</th>
                        <th class="py-4 px-4 text-center">جزئیات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-sm">
                <?php if (!empty($rows)): ?>
                    <?php $idx = 0; foreach ($rows as $call):
                        $isAnswered = ($call['disposition'] === 'ANSWERED');
                        $waitClass = 'text-slate-600 font-medium';
                        if ($call['wait_sec'] > 60) $waitClass = 'text-rose-600 font-bold';
                        elseif ($call['wait_sec'] > 30) $waitClass = 'text-amber-600 font-semibold';
                    ?>
                    <tr class="hover:bg-slate-50 transition">
                        <td class="py-4 px-4 whitespace-nowrap">
                            <span class="font-bold text-slate-800"><?php echo to_persian_num($call['shamsi']); ?></span>
                            <span class="text-xs text-slate-500 mr-2"><?php echo to_persian_num($call['time_str']); ?></span>
                        </td>
                        <td class="py-4 px-4">
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold border <?php echo direction_badge_class($call['direction']); ?>">
                                    <?php echo htmlspecialchars($call['dir_label']); ?>
                                </span>
                                <span class="text-blue-600 font-bold"><?php echo to_persian_num(htmlspecialchars($call['src'])); ?></span>
                                <?php if ($call['src_type'] !== 'extension' && $call['src_type'] !== 'unknown'): ?>
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] border <?php echo number_type_badge_class($call['src_type']); ?>">
                                        <?php echo htmlspecialchars($call['src_type_label']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="py-4 px-4">
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <span class="text-indigo-600 font-bold"><?php echo to_persian_num(htmlspecialchars($call['dst'])); ?></span>
                                <?php if ($call['dst_type'] !== 'extension' && $call['dst_type'] !== 'unknown'): ?>
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] border <?php echo number_type_badge_class($call['dst_type']); ?>">
                                        <?php echo htmlspecialchars($call['dst_type_label']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="py-4 px-4">
                            <?php if ($call['answering_ext'] !== ''): ?>
                                <span class="inline-flex items-center gap-1 text-emerald-600 font-bold">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                    <?php echo to_persian_num($call['answering_ext']); ?>
                                </span>
                            <?php else: ?>
                                <span class="text-slate-400">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="py-4 px-4 <?php echo $waitClass; ?>"><?php echo to_persian_num(format_duration($call['wait_sec'])); ?></td>
                        <td class="py-4 px-4 text-slate-600"><?php echo to_persian_num(format_duration($call['billsec'])); ?></td>
                        <td class="py-4 px-4">
                            <?php if ($isAnswered): ?>
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-200">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> پاسخ داده شده
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-rose-50 text-rose-700 border border-rose-200">
                                    <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                                    <?php echo htmlspecialchars($call['disp_label']); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="py-4 px-4 text-center">
                            <button type="button"
                                    class="js-details inline-flex items-center gap-1.5 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 px-3 py-1.5 rounded-lg text-xs transition shadow-sm"
                                    data-index="<?php echo $idx; ?>">
                                <svg class="w-3.5 h-3.5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                جزئیات
                            </button>
                        </td>
                    </tr>
                    <?php $idx++; endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" class="py-12 text-center text-slate-400">هیچ تماسی با فیلتر انتخابی یافت نشد.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ===== صفحه‌بندی ===== -->
    <?php if ($total_pages > 1): ?>
    <div class="flex flex-col sm:flex-row items-center justify-between bg-white border border-slate-200 rounded-2xl p-4 gap-4 shadow-sm">
        <div class="text-xs text-slate-500 font-medium">
            صفحه <span class="text-emerald-600 font-bold"><?php echo to_persian_num($page); ?></span>
            از <span class="text-slate-800 font-bold"><?php echo to_persian_num($total_pages); ?></span>
            — مجموع: <span class="text-slate-800 font-bold"><?php echo to_persian_num(number_format($total_records)); ?></span> رکورد
        </div>
        <div class="flex items-center gap-1 flex-wrap justify-center">
            <?php
            $start_p = max(1, $page - 2);
            $end_p   = min($total_pages, $page + 2);
            if ($page > 1) {
                echo '<a href="?' . build_qs(array_merge($base_params, array('page' => $page - 1))) . '" class="px-3.5 py-2 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 rounded-xl text-xs font-medium transition shadow-sm">قبلی</a>';
            }
            if ($start_p > 1) {
                echo '<a href="?' . build_qs(array_merge($base_params, array('page' => 1))) . '" class="px-3.5 py-2 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 rounded-xl text-xs font-medium transition shadow-sm">' . to_persian_num(1) . '</a>';
                if ($start_p > 2) echo '<span class="px-2 text-slate-400">...</span>';
            }
            for ($i = $start_p; $i <= $end_p; $i++) {
                $cls = $i === $page
                    ? 'bg-emerald-600 text-white font-bold border-emerald-600 shadow-sm'
                    : 'bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 shadow-sm';
                echo '<a href="?' . build_qs(array_merge($base_params, array('page' => $i))) . '" class="px-3.5 py-2 rounded-xl text-xs transition border ' . $cls . '">' . to_persian_num($i) . '</a>';
            }
            if ($end_p < $total_pages) {
                if ($end_p < $total_pages - 1) echo '<span class="px-2 text-slate-400">...</span>';
                echo '<a href="?' . build_qs(array_merge($base_params, array('page' => $total_pages))) . '" class="px-3.5 py-2 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 rounded-xl text-xs font-medium transition shadow-sm">' . to_persian_num($total_pages) . '</a>';
            }
            if ($page < $total_pages) {
                echo '<a href="?' . build_qs(array_merge($base_params, array('page' => $page + 1))) . '" class="px-3.5 py-2 bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 rounded-xl text-xs font-medium transition shadow-sm">بعدی</a>';
            }
            ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- ===== مودال جزئیات ===== -->
<div id="detail-modal" class="hidden fixed inset-0 z-50 items-center justify-center bg-slate-900/40 backdrop-blur-sm p-4">
    <div class="bg-white border border-slate-200 rounded-2xl w-full max-w-2xl max-h-[90vh] overflow-hidden flex flex-col shadow-2xl">
        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-200 bg-slate-50/50">
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                <h2 class="text-lg font-bold text-slate-800">جزئیات کامل تماس</h2>
            </div>
            <button type="button" class="js-close-modal text-slate-400 hover:text-slate-700 w-8 h-8 flex items-center justify-center rounded-lg hover:bg-slate-200 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div id="modal-content" class="px-5 py-3 overflow-y-auto"></div>
        <div class="px-5 py-3 border-t border-slate-200 bg-slate-50/50">
            <button type="button" class="js-close-modal w-full bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 py-2 rounded-xl text-sm font-medium transition shadow-sm">بستن</button>
        </div>
    </div>
</div>

<script id="calls-json" type="application/json"><?php echo json_encode($js_data); ?></script>

<script>
(function() {
    var modal = document.getElementById('detail-modal');
    var content = document.getElementById('modal-content');
    var dataEl = document.getElementById('calls-json');
    if (!modal || !content || !dataEl) return;

    var data = [];
    try { data = JSON.parse(dataEl.textContent || '[]'); } catch (e) { data = []; }

    function fa(s) {
        return String(s == null ? '' : s).replace(/[0-9]/g, function(d) {
            return '۰۱۲۳۴۵۶۷۸۹'[d];
        });
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }
    function row(label, value, isRaw) {
        var v = (value === null || value === undefined || value === '') ? '—' : (isRaw ? esc(value) : fa(esc(value)));
        return '<div class="flex items-start justify-between gap-4 py-2 border-b border-slate-100 last:border-0">'
             + '<span class="text-xs font-medium text-slate-500 shrink-0">' + label + '</span>'
             + '<span class="text-sm text-slate-800 font-bold text-left break-all" dir="ltr">' + v + '</span>'
             + '</div>';
    }

    function openModal(idx) {
        var d = data[idx];
        if (!d) return;
        var html = '';
        html += row('تاریخ و زمان', d.date_label);
        html += row('جهت تماس', d.dir_label, true);
        html += row('مبدا', d.src);
        if (d.src_type_label) html += row('نوع مبدا', d.src_type_label, true);
        html += row('مقصد', d.dst);
        if (d.dst_type_label) html += row('نوع مقصد', d.dst_type_label, true);
        html += row('داخلی درگیر', d.answering_ext || '—');
        html += row('مدت انتظار', d.wait_label);
        html += row('مدت مکالمه', d.billsec_label);
        html += row('مدت کل', d.duration_label);
        html += row('وضعیت', d.disp_label, true);
        html += '<div class="mt-4 mb-2 text-[11px] font-bold text-blue-500 uppercase tracking-wider">اطلاعات فنی</div>';
        html += row('کانال مبدا', d.channel, true);
        html += row('کانال مقصد', d.dstchannel, true);
        html += row('Last App', d.lastapp, true);
        html += row('Last Data', d.lastdata, true);
        html += row('Account Code', d.accountcode, true);
        html += row('User Field', d.userfield, true);
        html += row('Context', d.dcontext, true);
        html += row('Unique ID', d.uniqueid, true);
        content.innerHTML = html;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeModal() {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    var btns = document.querySelectorAll('.js-details');
    for (var i = 0; i < btns.length; i++) {
        (function(btn) {
            btn.addEventListener('click', function() {
                openModal(parseInt(btn.getAttribute('data-index'), 10));
            });
        })(btns[i]);
    }

    var closers = document.querySelectorAll('.js-close-modal');
    for (var j = 0; j < closers.length; j++) {
        closers[j].addEventListener('click', closeModal);
    }
    modal.addEventListener('click', function(e) {
        if (e.target === modal) closeModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' || e.keyCode === 27) closeModal();
    });
})();

(function() {
        var btn = document.getElementById('theme-toggle');
        if (!btn) return;

        btn.addEventListener('click', function() {
            var isDark = document.documentElement.classList.contains('dark');
            if (isDark) {
                document.documentElement.classList.remove('dark');
                try { localStorage.setItem('theme', 'light'); } catch(e) {}
            } else {
                document.documentElement.classList.add('dark');
                try { localStorage.setItem('theme', 'dark'); } catch(e) {}
            }
        });
    })();
	
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