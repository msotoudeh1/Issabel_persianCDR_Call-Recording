<?php
// ==========================================
// reports.php - گزارش متنی مکالمات (PHP 5.4+)
// با تشخیص هوشمند جهت تماس (ورودی/خروجی/داخلی/ترانزیت)
// ==========================================
$config = require __DIR__ . '/config.php';

$db_host = $config['db_host'];
$db_name = $config['db_name'];
$db_user = $config['db_user'];
$db_pass = $config['db_pass'];

$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mysqli->connect_error) {
    die("<div style='text-align:center;padding:50px;font-family:sans-serif;color:red;'>خطا در اتصال به دیتابیس.</div>");
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

// ==========================================
// توابع تشخیص ترانک / داخلی / نوع شماره
// ==========================================

/**
 * آیا کانال یک ترانک است؟
 *  SIP/201-xxxxx       → داخلی (peer عددی)
 *  SIP/trunk1-xxxxx    → ترانک (peer حروفی)
 *  DAHDI/g1/xxx        → ترانک
 *  Local/xxx           → داخلی/فوروارد
 *  PJSIP/provider-xxx  → ترانک
 */
function is_trunk_channel($channel) {
    if (empty($channel)) return false;

    // DAHDI = همیشه ترانک (خطوط آنالوگ یا E1)
    if (preg_match('#^DAHDI/#i', $channel)) return true;

    // Local = فوروارد داخلی
    if (preg_match('#^Local/#i', $channel)) return false;

    // SIP/PJSIP/IAX2/SCCP/xxx-...
    if (preg_match('#^(?:SIP|PJSIP|IAX2|SCCP)/([^-@;]+)#i', $channel, $m)) {
        $peer = $m[1];
        // peer تماماً عددی → داخلی
        if (ctype_digit($peer)) return false;
        // peer حروفی (یا عدد+حرف) → ترانک
        return true;
    }

    return false;
}

/**
 * استخراج شماره داخلی از کانال (SIP/201-xxx → 201)
 */
function extract_extension_from_channel($channel) {
    if (empty($channel)) return '';
    if (preg_match('#^(?:SIP|PJSIP|IAX2|SCCP|Local)/(\d+)(?:[-;@]|$)#i', $channel, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * داخلی درگیر در تماس:
 *  ورودی  → dstchannel (پاسخ‌دهنده)
 *  خروجی  → channel (تماس‌گیرنده)
 *  داخلی  → dstchannel (پاسخ‌دهنده)
 */
function find_involved_extension($channel, $dstchannel) {
    $ext = extract_extension_from_channel($dstchannel);
    if ($ext !== '') return $ext;
    return extract_extension_from_channel($channel);
}

/**
 * تشخیص نوع شماره تلفن
 *  extension     = داخلی (3-5 رقم یا در لیست)
 *  city          = شهری / ثابت (0 + کد استان + 8 رقم)
 *  mobile        = همراه (09xxxxxxxxx)
 *  international = بین‌المللی (00...)
 *  unknown       = نامشخص
 */
function classify_number($number, $extensions_list) {
    $raw = trim((string)$number);
    if ($raw === '') return 'unknown';

    $clean = preg_replace('/[^0-9]/', '', $raw);
    if ($clean === '') return 'unknown';

    // تو لیست داخلی‌های شناخته‌شده
    if (in_array($raw, $extensions_list, true))   return 'extension';
    if (in_array($clean, $extensions_list, true)) return 'extension';

    $len = strlen($clean);

    // داخلی معمولی: 3 تا 5 رقم
    if ($len >= 3 && $len <= 5) return 'extension';

    // بین‌المللی: 00 + کد کشور
    if (substr($clean, 0, 2) === '00') return 'international';

    // موبایل ایران: 09xxxxxxxxx
    if ($len === 11 && substr($clean, 0, 2) === '09') return 'mobile';
    if ($len === 10 && substr($clean, 0, 1) === '9')  return 'mobile';

    // شهری / ثابت ایران: 0 + کد استان + شماره
    if ($len === 11 && substr($clean, 0, 1) === '0')  return 'city';
    if ($len === 10) return 'city';  // تهران بدون ۰
    if ($len === 8)  return 'city';  // حالت کوتاه (تهران 8 رقمی)

    return 'unknown';
}

/**
 * تشخیص جهت تماس (اولویت: تحلیل کانال → تحلیل شماره → dcontext)
 *  inbound   = ورودی (از ترانک به داخل)
 *  outbound  = خروجی (از داخل به ترانک)
 *  internal  = داخلی به داخلی
 *  transit   = ترانک به ترانک (فوروارد)
 *  unknown   = نامشخص
 */
function classify_call_direction($channel, $dstchannel, $src, $dst, $dcontext, $extensions_list) {
    $ch_trunk  = is_trunk_channel($channel);
    $dst_trunk = is_trunk_channel($dstchannel);

    // 1) تحلیل کانال (دقیق‌ترین)
    if ($ch_trunk && $dst_trunk)  return 'transit';
    if ($ch_trunk && !$dst_trunk) return 'inbound';
    if (!$ch_trunk && $dst_trunk) return 'outbound';

    // 2) تحلیل شماره (فالبک)
    $src_t = classify_number($src, $extensions_list);
    $dst_t = classify_number($dst, $extensions_list);
    $src_ext = ($src_t === 'extension');
    $dst_ext = ($dst_t === 'extension');

    if ($src_ext && $dst_ext)   return 'internal';
    if (!$src_ext && $dst_ext)  return 'inbound';
    if ($src_ext && !$dst_ext)  return 'outbound';

    // 3) dcontext (آخرین فالبک)
    if (!empty($dcontext)) {
        if (preg_match('#^from-(trunk|pstn|sip-external|analog|did|outside)#i', $dcontext)) return 'inbound';
        if (preg_match('#^(outbound|outrt|out-|outbound-)#i', $dcontext)) return 'outbound';
        if (preg_match('#^from-internal#i', $dcontext)) return 'internal';
    }

    return 'unknown';
}

/**
 * برچسب فارسی جهت تماس
 */
function direction_label($direction) {
    switch ($direction) {
        case 'inbound':  return 'ورودی';
        case 'outbound': return 'خروجی';
        case 'internal': return 'داخلی';
        case 'transit':  return 'ترانزیت';
        default:         return 'نامشخص';
    }
}

/**
 * کلاس رنگ بج جهت تماس
 */
function direction_badge_class($direction) {
    switch ($direction) {
        case 'inbound':  return 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20';
        case 'outbound': return 'bg-violet-500/10 text-violet-400 border-violet-500/20';
        case 'internal': return 'bg-amber-500/10 text-amber-400 border-amber-500/20';
        case 'transit':  return 'bg-fuchsia-500/10 text-fuchsia-400 border-fuchsia-500/20';
        default:         return 'bg-slate-500/10 text-slate-400 border-slate-500/20';
    }
}

/**
 * برچسب فارسی نوع شماره
 */
function number_type_label($type) {
    switch ($type) {
        case 'extension':     return 'داخلی';
        case 'city':          return 'شهری';
        case 'mobile':        return 'همراه';
        case 'international': return 'بین‌المللی';
        default:              return '';
    }
}

/**
 * کلاس رنگ بج نوع شماره
 */
function number_type_badge_class($type) {
    switch ($type) {
        case 'city':          return 'bg-sky-500/10 text-sky-400 border-sky-500/20';
        case 'mobile':        return 'bg-pink-500/10 text-pink-400 border-pink-500/20';
        case 'international': return 'bg-rose-500/10 text-rose-400 border-rose-500/20';
        default:              return 'bg-slate-500/10 text-slate-400 border-slate-500/20';
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
// آمار کلی
// ==========================================
$stats_sql = "SELECT
    COUNT(*) AS total_calls,
    SUM(CASE WHEN disposition = 'ANSWERED' THEN 1 ELSE 0 END) AS answered_calls,
    SUM(CASE WHEN disposition != 'ANSWERED' THEN 1 ELSE 0 END) AS missed_calls,
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
// صفحه‌بندی
// ==========================================
$per_page = 20;
$page = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
$total_records = (int)$stats['total_calls'];
$total_pages = max(1, (int)ceil($total_records / $per_page));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;

// ==========================================
// کوئری اصلی
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
    <script src="/3.4.17.js"></script>
    <link href="/Vazirmatn-font-face.css" rel="stylesheet">
    <script>
        tailwind.config = { theme: { extend: { fontFamily: { sans: ['Vazirmatn', 'sans-serif'] } } } }
    </script>
</head>
<body class="bg-slate-950 text-slate-100 font-sans min-h-screen antialiased selection:bg-indigo-500 selection:text-white">

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <!-- ===== هدر ===== -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-8 gap-4 border-b border-indigo-500/20 pb-6 relative overflow-hidden bg-gradient-to-r from-indigo-950/40 via-slate-900/40 to-slate-950 p-6 rounded-2xl border">
        <div class="absolute -left-10 -top-10 w-40 h-40 bg-indigo-600/10 rounded-full blur-3xl pointer-events-none"></div>
        <div>
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 text-xs font-semibold mb-3">
                <span class="w-2 h-2 rounded-full bg-indigo-400 animate-pulse"></span>
                سامانه گزارش‌گیری ایزابل
            </div>
            <h1 class="text-3xl font-black bg-gradient-to-r from-indigo-400 via-sky-400 to-emerald-400 bg-clip-text text-transparent">
                گزارش مکالمات تلفنی
            </h1>
            <p class="text-slate-400 text-sm mt-1">تحلیل و نظارت بر مکالمات مرکز تماس</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="monitoring.php" class="inline-flex items-center gap-2 bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-700 px-4 py-2.5 rounded-xl text-sm font-medium transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.536 8.464a5 5 0 010 7.072m2.828-9.9a9 9 0 010 12.728M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z"/></svg>
                شنود مکالمات
            </a>
            <a href="?<?php echo build_qs(array_merge($base_params, array('export' => 'csv'))); ?>"
               class="inline-flex items-center gap-2 bg-emerald-600/20 hover:bg-emerald-600/30 text-emerald-400 border border-emerald-500/30 px-4 py-2.5 rounded-xl text-sm font-medium transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                خروجی CSV
            </a>
        </div>
    </div>

    <!-- ===== آمار ===== -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-5 backdrop-blur-xl">
            <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">کل تماس‌ها</span>
            <div class="text-2xl font-bold text-white mt-2"><?php echo to_persian_num(number_format((int)$stats['total_calls'])); ?></div>
        </div>
        <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-5 backdrop-blur-xl">
            <span class="text-xs font-semibold text-emerald-400 uppercase tracking-wider">پاسخ داده شده</span>
            <div class="text-2xl font-bold text-emerald-400 mt-2"><?php echo to_persian_num(number_format((int)$stats['answered_calls'])); ?></div>
        </div>
        <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-5 backdrop-blur-xl">
            <span class="text-xs font-semibold text-rose-400 uppercase tracking-wider">بدون پاسخ / ناموفق</span>
            <div class="text-2xl font-bold text-rose-400 mt-2"><?php echo to_persian_num(number_format((int)$stats['missed_calls'])); ?></div>
        </div>
        <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-5 backdrop-blur-xl">
            <span class="text-xs font-semibold text-sky-400 uppercase tracking-wider">مجموع مکالمات</span>
            <div class="text-2xl font-bold text-sky-400 mt-2"><?php echo format_duration((int)$stats['total_duration']); ?></div>
        </div>
    </div>

    <!-- ===== فیلترها ===== -->
    <div class="bg-slate-900/80 border border-slate-800 rounded-2xl p-6 mb-8 shadow-xl backdrop-blur-xl">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 items-end">
            <div>
                <label class="block text-xs font-medium text-slate-300 mb-2">از تاریخ (شمسی)</label>
                <input type="text" name="start_date" placeholder="1405/01/01" value="<?php echo htmlspecialchars($start_date); ?>"
                       class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-300 mb-2">تا تاریخ (شمسی)</label>
                <input type="text" name="end_date" placeholder="1405/01/30" value="<?php echo htmlspecialchars($end_date); ?>"
                       class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-300 mb-2">شماره داخلی</label>
                <select name="extension" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition">
                    <option value="">-- همه داخلی‌ها --</option>
                    <?php foreach ($extensions_list as $ext): ?>
                        <option value="<?php echo htmlspecialchars($ext); ?>" <?php echo $extension === $ext ? 'selected' : ''; ?>>
                            داخلی <?php echo to_persian_num($ext); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-300 mb-2">وضعیت تماس</label>
                <select name="disposition" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition">
                    <option value="">-- همه وضعیت‌ها --</option>
                    <?php foreach ($dispositions as $d): ?>
                        <option value="<?php echo htmlspecialchars($d); ?>" <?php echo $disposition === $d ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(isset($disp_labels[$d]) ? $disp_labels[$d] : $d); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-300 mb-2">جستجو شماره (مبدا/مقصد)</label>
                <input type="text" name="search_number" placeholder="مثلاً 09121234567" value="<?php echo htmlspecialchars($search_number); ?>"
                       class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="flex-1 bg-indigo-600 hover:bg-indigo-500 text-white font-medium py-2.5 px-4 rounded-xl text-sm transition shadow-lg shadow-indigo-600/20">
                    اعمال فیلتر
                </button>
                <a href="?" class="bg-slate-800 hover:bg-slate-700 text-slate-300 py-2.5 px-4 rounded-xl text-sm transition">ریست</a>
            </div>
        </form>
    </div>

    <!-- ===== جدول ===== -->
    <div class="bg-slate-900/80 border border-slate-800 rounded-2xl overflow-hidden shadow-2xl backdrop-blur-xl mb-6">
        <div class="overflow-x-auto">
            <table class="w-full text-right border-collapse">
                <thead>
                    <tr class="bg-slate-800/50 border-b border-slate-800 text-slate-400 text-xs font-semibold uppercase tracking-wider">
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
                <tbody class="divide-y divide-slate-800/60 text-sm">
                <?php if (!empty($rows)): ?>
                    <?php $idx = 0; foreach ($rows as $call):
                        $isAnswered = ($call['disposition'] === 'ANSWERED');
                        $waitClass = 'text-slate-300';
                        if ($call['wait_sec'] > 60) $waitClass = 'text-rose-400 font-semibold';
                        elseif ($call['wait_sec'] > 30) $waitClass = 'text-amber-400 font-medium';
                    ?>
                    <tr class="hover:bg-slate-800/40 transition">
                        <td class="py-4 px-4 whitespace-nowrap">
                            <span class="font-medium text-white"><?php echo to_persian_num($call['shamsi']); ?></span>
                            <span class="text-xs text-slate-500 mr-2"><?php echo to_persian_num($call['time_str']); ?></span>
                        </td>
                        <td class="py-4 px-4">
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold border <?php echo direction_badge_class($call['direction']); ?>">
                                    <?php echo htmlspecialchars($call['dir_label']); ?>
                                </span>
                                <span class="text-indigo-400 font-semibold"><?php echo to_persian_num(htmlspecialchars($call['src'])); ?></span>
                                <?php if ($call['src_type'] !== 'extension' && $call['src_type'] !== 'unknown'): ?>
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] border <?php echo number_type_badge_class($call['src_type']); ?>">
                                        <?php echo htmlspecialchars($call['src_type_label']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="py-4 px-4">
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <span class="text-sky-400 font-semibold"><?php echo to_persian_num(htmlspecialchars($call['dst'])); ?></span>
                                <?php if ($call['dst_type'] !== 'extension' && $call['dst_type'] !== 'unknown'): ?>
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] border <?php echo number_type_badge_class($call['dst_type']); ?>">
                                        <?php echo htmlspecialchars($call['dst_type_label']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="py-4 px-4">
                            <?php if ($call['answering_ext'] !== ''): ?>
                                <span class="inline-flex items-center gap-1 text-emerald-400 font-medium">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                    <?php echo to_persian_num($call['answering_ext']); ?>
                                </span>
                            <?php else: ?>
                                <span class="text-slate-600">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="py-4 px-4 <?php echo $waitClass; ?>"><?php echo to_persian_num(format_duration($call['wait_sec'])); ?></td>
                        <td class="py-4 px-4 text-slate-300"><?php echo to_persian_num(format_duration($call['billsec'])); ?></td>
                        <td class="py-4 px-4">
                            <?php if ($isAnswered): ?>
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> پاسخ داده شده
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-rose-500/10 text-rose-400 border border-rose-500/20">
                                    <span class="w-1.5 h-1.5 rounded-full bg-rose-400"></span>
                                    <?php echo htmlspecialchars($call['disp_label']); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="py-4 px-4 text-center">
                            <button type="button"
                                    class="js-details inline-flex items-center gap-1.5 bg-slate-800 hover:bg-indigo-600 text-slate-300 hover:text-white px-3 py-1.5 rounded-lg text-xs transition"
                                    data-index="<?php echo $idx; ?>">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                جزئیات
                            </button>
                        </td>
                    </tr>
                    <?php $idx++; endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" class="py-12 text-center text-slate-500">هیچ تماسی با فیلتر انتخابی یافت نشد.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ===== صفحه‌بندی ===== -->
    <?php if ($total_pages > 1): ?>
    <div class="flex flex-col sm:flex-row items-center justify-between bg-slate-900/80 border border-slate-800 rounded-2xl p-4 gap-4 backdrop-blur-xl">
        <div class="text-xs text-slate-400">
            صفحه <span class="text-indigo-400 font-bold"><?php echo to_persian_num($page); ?></span>
            از <span class="text-white font-bold"><?php echo to_persian_num($total_pages); ?></span>
            — مجموع: <span class="text-white font-bold"><?php echo to_persian_num(number_format($total_records)); ?></span> رکورد
        </div>
        <div class="flex items-center gap-1 flex-wrap justify-center">
            <?php
            $start_p = max(1, $page - 2);
            $end_p   = min($total_pages, $page + 2);
            if ($page > 1) {
                echo '<a href="?' . build_qs(array_merge($base_params, array('page' => $page - 1))) . '" class="px-3.5 py-2 bg-slate-800 hover:bg-indigo-600 text-slate-300 rounded-xl text-xs font-medium transition">قبلی</a>';
            }
            if ($start_p > 1) {
                echo '<a href="?' . build_qs(array_merge($base_params, array('page' => 1))) . '" class="px-3.5 py-2 bg-slate-800 hover:bg-indigo-600 text-slate-300 rounded-xl text-xs font-medium transition">' . to_persian_num(1) . '</a>';
                if ($start_p > 2) echo '<span class="px-2 text-slate-600">...</span>';
            }
            for ($i = $start_p; $i <= $end_p; $i++) {
                $cls = $i === $page
                    ? 'bg-indigo-600 text-white font-bold shadow-lg shadow-indigo-600/30'
                    : 'bg-slate-800 hover:bg-slate-700 text-slate-300';
                echo '<a href="?' . build_qs(array_merge($base_params, array('page' => $i))) . '" class="px-3.5 py-2 rounded-xl text-xs transition ' . $cls . '">' . to_persian_num($i) . '</a>';
            }
            if ($end_p < $total_pages) {
                if ($end_p < $total_pages - 1) echo '<span class="px-2 text-slate-600">...</span>';
                echo '<a href="?' . build_qs(array_merge($base_params, array('page' => $total_pages))) . '" class="px-3.5 py-2 bg-slate-800 hover:bg-indigo-600 text-slate-300 rounded-xl text-xs font-medium transition">' . to_persian_num($total_pages) . '</a>';
            }
            if ($page < $total_pages) {
                echo '<a href="?' . build_qs(array_merge($base_params, array('page' => $page + 1))) . '" class="px-3.5 py-2 bg-slate-800 hover:bg-indigo-600 text-slate-300 rounded-xl text-xs font-medium transition">بعدی</a>';
            }
            ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- ===== مودال جزئیات ===== -->
<div id="detail-modal" class="hidden fixed inset-0 z-50 items-center justify-center bg-slate-950/80 backdrop-blur-sm p-4">
    <div class="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-2xl max-h-[90vh] overflow-hidden flex flex-col shadow-2xl">
        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-800 bg-slate-900/80">
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                <h2 class="text-lg font-bold text-white">جزئیات کامل تماس</h2>
            </div>
            <button type="button" class="js-close-modal text-slate-400 hover:text-white w-8 h-8 flex items-center justify-center rounded-lg hover:bg-slate-800 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div id="modal-content" class="px-5 py-3 overflow-y-auto"></div>
        <div class="px-5 py-3 border-t border-slate-800 bg-slate-900/80">
            <button type="button" class="js-close-modal w-full bg-slate-800 hover:bg-slate-700 text-slate-200 py-2 rounded-xl text-sm transition">بستن</button>
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
        return '<div class="flex items-start justify-between gap-4 py-2 border-b border-slate-800/60 last:border-0">'
             + '<span class="text-xs text-slate-400 shrink-0">' + label + '</span>'
             + '<span class="text-sm text-slate-100 font-medium text-left break-all" dir="ltr">' + v + '</span>'
             + '</div>';
    }

    function open(idx) {
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
        html += '<div class="mt-3 mb-1 text-[11px] font-bold text-indigo-400 uppercase tracking-wider">اطلاعات فنی</div>';
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

    function close() {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    var btns = document.querySelectorAll('.js-details');
    for (var i = 0; i < btns.length; i++) {
        (function(btn) {
            btn.addEventListener('click', function() {
                open(parseInt(btn.getAttribute('data-index'), 10));
            });
        })(btns[i]);
    }

    var closers = document.querySelectorAll('.js-close-modal');
    for (var j = 0; j < closers.length; j++) {
        closers[j].addEventListener('click', close);
    }
    modal.addEventListener('click', function(e) {
        if (e.target === modal) close();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' || e.keyCode === 27) close();
    });
})();
</script>

</body>
</html>
<?php $mysqli->close(); ?>