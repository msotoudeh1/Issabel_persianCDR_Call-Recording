<?php
// ============================================================
// reports.php - گزارش تفصیلی مکالمات ایزابل با شاخص‌های تحلیلی
// ============================================================
require_once __DIR__ . '/auth.php';
require_auth(); // الزام ورود به سامانه

require_once __DIR__ . '/helpers.php';

$config = require __DIR__ . '/config.php';

$db_host = $config['db_host'];
$db_name = $config['db_name'];
$db_user = $config['db_user'];
$db_pass = $config['db_pass'];

$mysqli = null;
$is_demo_mode = false;

if (class_exists('mysqli')) {
    $mysqli = @new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($mysqli->connect_error) {
        $mysqli = null;
    } else {
        $mysqli->set_charset("utf8mb4");
    }
}

if (!$mysqli) {
    $is_demo_mode = true;
}

// لیست داخلی‌ها
$extensions_list = array();
if ($mysqli) {
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
        $res = $mysqli->query("SELECT DISTINCT src FROM cdr WHERE src REGEXP '^[0-9]{2,5}$' UNION SELECT DISTINCT dst FROM cdr WHERE dst REGEXP '^[0-9]{2,5}$'");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $ext = $row['src'] ? $row['src'] : $row['dst'];
                if ($ext !== '') $extensions_list[] = $ext;
            }
        }
    }
} else {
    $extensions_list = array('101', '102', '103', '104', '105', '106', '107', '108');
}
$extensions_list = array_values(array_unique($extensions_list));
sort($extensions_list);

// لیست وضعیت‌ها
$dispositions = array('ANSWERED', 'NO ANSWER', 'BUSY', 'FAILED', 'CONGESTION', 'CANCEL');
if ($mysqli) {
    $res = $mysqli->query("SELECT DISTINCT disposition FROM cdr WHERE disposition IS NOT NULL AND disposition != '' ORDER BY disposition");
    if ($res) {
        $dispositions = array();
        while ($row = $res->fetch_assoc()) $dispositions[] = $row['disposition'];
    }
}

$disp_labels = array(
    'ANSWERED'   => 'پاسخ داده شده',
    'NO ANSWER'  => 'بدون پاسخ',
    'BUSY'       => 'مشغول',
    'FAILED'     => 'ناموفق',
    'CONGESTION' => 'ترافیک شبکه',
    'CANCEL'     => 'لغو شده',
);

// فیلترها
$period        = isset($_GET['period']) ? trim($_GET['period']) : '';
$start_date    = trim(isset($_GET['start_date'])  ? $_GET['start_date']  : '');
$end_date      = trim(isset($_GET['end_date'])    ? $_GET['end_date']    : '');
$extension     = trim(isset($_GET['extension'])   ? $_GET['extension']   : '');
$disposition   = trim(isset($_GET['disposition']) ? $_GET['disposition'] : '');
$search_number = preg_replace('/[^0-9+]/', '', isset($_GET['search_number']) ? $_GET['search_number'] : '');
$all_time      = (isset($_GET['all_time']) && $_GET['all_time'] === '1') || $period === 'all';

if (empty($period) && empty($start_date) && empty($end_date) && !$all_time) {
    $period = 'week';
}

if ($period !== '' && $period !== 'custom') {
    $period_dates = get_dates_by_period($period);
    if ($period_dates['start'] !== null && $period_dates['end'] !== null) {
        $start_date = $period_dates['start'];
        $end_date   = $period_dates['end'];
    } else {
        $start_date = '';
        $end_date   = '';
    }
}

$per_page = 20;
$page = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
$rows = array();
$js_data = array();

if ($mysqli) {
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

    // خروجی CSV
    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        $export_limit = 5000;
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=calls_report_' . date('Ymd_His') . '.csv');
        header('Cache-Control: private, max-age=0');

        $output = fopen('php://output', 'w');
        fputs($output, "\xEF\xBB\xBF");
        fputcsv($output, array('تاریخ و زمان', 'مبدا', 'مقصد', 'جهت تماس', 'نوع مبدا', 'نوع مقصد', 'داخلی درگیر', 'مدت انتظار (ثانیه)', 'مدت مکالمه (ثانیه)', 'وضعیت'));

        $export_res = $mysqli->query("SELECT calldate, src, dst, duration, billsec, disposition, channel, dstchannel, dcontext FROM cdr $where_clause ORDER BY calldate DESC LIMIT $export_limit");
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
                    $row['src'], $row['dst'], direction_label($direction),
                    number_type_label($src_type), number_type_label($dst_type),
                    $ans_ext !== '' ? $ans_ext : '—', format_duration($wait),
                    format_duration($row['billsec']), $disp_label
                ));
            }
        }
        fclose($output);
        exit;
    }

    // آمار کل
    $stats_res = $mysqli->query("SELECT COUNT(*) AS total_calls, COUNT(CASE WHEN disposition = 'ANSWERED' THEN 1 END) AS answered_calls, COUNT(CASE WHEN disposition != 'ANSWERED' THEN 1 END) AS missed_calls, COALESCE(SUM(billsec), 0) AS total_duration FROM cdr $where_clause");
    $stats = $stats_res ? $stats_res->fetch_assoc() : array('total_calls' => 0, 'answered_calls' => 0, 'missed_calls' => 0, 'total_duration' => 0);
    if (!$stats) $stats = array('total_calls' => 0, 'answered_calls' => 0, 'missed_calls' => 0, 'total_duration' => 0);

    $answer_rate = $stats['total_calls'] > 0 ? round(($stats['answered_calls'] / $stats['total_calls']) * 100, 1) : 0;

    $avg_talk_sec = 0;
    $w_ans = $where; $w_ans[] = "disposition = 'ANSWERED'";
    $res_at = $mysqli->query("SELECT AVG(billsec) as avg_t FROM cdr WHERE " . implode(" AND ", $w_ans));
    if ($res_at && $row_at = $res_at->fetch_assoc()) $avg_talk_sec = (int)round($row_at['avg_t']);

    $avg_wait_sec = 0;
    $res_wait = $mysqli->query("SELECT AVG(GREATEST(0, duration - billsec)) as avg_w FROM cdr $where_clause");
    if ($res_wait && $row_w = $res_wait->fetch_assoc()) $avg_wait_sec = (int)round($row_w['avg_w']);

    $peak_hour_str = '—';
    $res_hour = $mysqli->query("SELECT HOUR(calldate) as h, COUNT(*) as cnt FROM cdr $where_clause GROUP BY HOUR(calldate) ORDER BY cnt DESC LIMIT 1");
    if ($res_hour && $row_h = $res_hour->fetch_assoc()) {
        if ($row_h['h'] !== null) {
            $h = (int)$row_h['h'];
            $peak_hour_str = sprintf('%02d:00 الی %02d:00', $h, ($h + 1) % 24);
        }
    }

    $total_records = (int)$stats['total_calls'];
    $total_pages = max(1, (int)ceil($total_records / $per_page));
    if ($page > $total_pages) $page = $total_pages;
    $offset = ($page - 1) * $per_page;

    $sql = "SELECT calldate, src, dst, duration, billsec, disposition, uniqueid, channel, dstchannel, lastapp, lastdata, accountcode, userfield, dcontext FROM cdr $where_clause ORDER BY calldate DESC LIMIT $offset, $per_page";
    $result = $mysqli->query($sql);
    if ($result) {
        while ($call = $result->fetch_assoc()) {
            $dt = new DateTime($call['calldate']);
            $call['shamsi']         = gregorian_to_jalali((int)$dt->format('Y'), (int)$dt->format('m'), (int)$dt->format('d'));
            $call['time_str']       = $dt->format('H:i:s');
            $call['wait_sec']       = max(0, (int)$call['duration'] - (int)$call['billsec']);
            $call['answering_ext']  = find_involved_extension($call['channel'], $call['dstchannel']);
            $call['direction']      = classify_call_direction($call['channel'], $call['dstchannel'], $call['src'], $call['dst'], $call['dcontext'], $extensions_list);
            $call['dir_label']      = direction_label($call['direction']);
            $call['src_type']       = classify_number($call['src'], $extensions_list);
            $call['dst_type']       = classify_number($call['dst'], $extensions_list);
            $call['src_type_label'] = number_type_label($call['src_type']);
            $call['dst_type_label'] = number_type_label($call['dst_type']);
            $call['disp_label']     = isset($disp_labels[$call['disposition']]) ? $disp_labels[$call['disposition']] : ($call['disposition'] ? $call['disposition'] : 'نامشخص');
            $rows[] = $call;

            $js_data[] = array(
                'date_label'       => $call['shamsi'] . ' - ' . $call['time_str'],
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
} else {
    // داده‌های نمونه گزارشات در حالت پیش‌نمایش
    $stats = array('total_calls' => 1560, 'answered_calls' => 1240, 'missed_calls' => 320, 'total_duration' => 164200);
    $answer_rate = 79.5;
    $avg_talk_sec = 132;
    $avg_wait_sec = 16;
    $peak_hour_str = '10:00 الی 11:00';
    $total_records = 1560;
    $total_pages = (int)ceil($total_records / $per_page);

    $now = time();
    $mock_samples = array(
        array('src'=>'09121112233', 'dst'=>'102', 'billsec'=>165, 'duration'=>178, 'disp'=>'ANSWERED', 'dir'=>'inbound', 'ext'=>'102'),
        array('src'=>'09354445566', 'dst'=>'105', 'billsec'=>84,  'duration'=>95,  'disp'=>'ANSWERED', 'dir'=>'inbound', 'ext'=>'105'),
        array('src'=>'02188776655', 'dst'=>'101', 'billsec'=>310, 'duration'=>325, 'disp'=>'ANSWERED', 'dir'=>'inbound', 'ext'=>'101'),
        array('src'=>'103', 'dst'=>'09197778899', 'billsec'=>0,   'duration'=>32,  'disp'=>'BUSY',     'dir'=>'outbound', 'ext'=>'103'),
        array('src'=>'09123334455', 'dst'=>'104', 'billsec'=>0,   'duration'=>45,  'disp'=>'NO ANSWER', 'dir'=>'inbound', 'ext'=>''),
        array('src'=>'102', 'dst'=>'105',         'billsec'=>52,  'duration'=>55,  'disp'=>'ANSWERED', 'dir'=>'internal', 'ext'=>'105'),
        array('src'=>'09128889900', 'dst'=>'101', 'billsec'=>195, 'duration'=>205, 'disp'=>'ANSWERED', 'dir'=>'inbound', 'ext'=>'101'),
        array('src'=>'106', 'dst'=>'09301234567', 'billsec'=>110, 'duration'=>122, 'disp'=>'ANSWERED', 'dir'=>'outbound', 'ext'=>'106'),
        array('src'=>'02122334455', 'dst'=>'102', 'billsec'=>0,   'duration'=>20,  'disp'=>'FAILED',   'dir'=>'inbound', 'ext'=>''),
        array('src'=>'09129990011', 'dst'=>'103', 'billsec'=>240, 'duration'=>255, 'disp'=>'ANSWERED', 'dir'=>'inbound', 'ext'=>'103'),
    );

    for ($i = 0; $i < 10; $i++) {
        $s = $mock_samples[$i];
        $dt = new DateTime();
        $dt->setTimestamp($now - ($i * 2100));
        $shamsi = gregorian_to_jalali((int)$dt->format('Y'), (int)$dt->format('m'), (int)$dt->format('d'));
        $time_str = $dt->format('H:i:s');
        $wait_sec = max(0, $s['duration'] - $s['billsec']);
        $src_type = classify_number($s['src'], $extensions_list);
        $dst_type = classify_number($s['dst'], $extensions_list);

        $call = array(
            'calldate'       => $dt->format('Y-m-d H:i:s'),
            'shamsi'         => $shamsi,
            'time_str'       => $time_str,
            'src'            => $s['src'],
            'dst'            => $s['dst'],
            'duration'       => $s['duration'],
            'billsec'        => $s['billsec'],
            'wait_sec'       => $wait_sec,
            'disposition'    => $s['disp'],
            'disp_label'     => isset($disp_labels[$s['disp']]) ? $disp_labels[$s['disp']] : $s['disp'],
            'direction'      => $s['dir'],
            'dir_label'      => direction_label($s['dir']),
            'src_type'       => $src_type,
            'dst_type'       => $dst_type,
            'src_type_label' => number_type_label($src_type),
            'dst_type_label' => number_type_label($dst_type),
            'answering_ext'  => $s['ext'],
            'channel'        => 'PJSIP/trunk-01',
            'dstchannel'     => 'PJSIP/' . ($s['ext'] ? $s['ext'] : '101'),
            'lastapp'        => 'Dial',
            'lastdata'       => 'PJSIP/' . $s['dst'],
            'accountcode'    => '',
            'userfield'      => '',
            'dcontext'       => 'from-trunk',
            'uniqueid'       => (string)(1710000000 + $i),
        );
        $rows[] = $call;

        $js_data[] = array(
            'date_label'       => $shamsi . ' - ' . $time_str,
            'src'              => $call['src'],
            'src_type_label'   => $call['src_type_label'],
            'dst'              => $call['dst'],
            'dst_type_label'   => $call['dst_type_label'],
            'dir_label'        => $call['dir_label'],
            'answering_ext'    => $call['answering_ext'],
            'wait_label'       => format_duration($wait_sec),
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
function build_qs($params) { return htmlspecialchars(http_build_query($params), ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>گزارش تفصیلی مکالمات | ایزابل</title>

    <script>
        (function() {
            try {
                if (localStorage.getItem('theme') === 'dark' || 
                    (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                    document.documentElement.classList.add('dark');
                }
            } catch(e) {}
        })();
    </script>

    <script src="../3.4.17.js"></script>
    <link href="../Vazirmatn-font-face.css" rel="stylesheet">

    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Vazirmatn', 'sans-serif'] },
                    colors: {
                        brand: {
                            50:  '#f0fdf4',
                            100: '#dcfce7',
                            500: '#10b981',
                            600: '#059669',
                            700: '#047857'
                        }
                    }
                }
            }
        };
    </script>

    <style>
        body { font-family: 'Vazirmatn', sans-serif; }
        .bg-blobs {
            position: fixed;
            inset: 0;
            overflow: hidden;
            pointer-events: none;
            z-index: 0;
        }
        .blob {
            position: absolute;
            border-radius: 50%;
            filter: blur(100px);
            opacity: 0.45;
            will-change: transform;
        }
        html.dark .blob { opacity: 0.22; }
        .blob-1 { top: -5%; right: 10%; width: 450px; height: 450px; background: radial-gradient(circle, #3b82f6 0%, rgba(59,130,246,0) 70%); }
        .blob-2 { bottom: 10%; left: 5%; width: 500px; height: 500px; background: radial-gradient(circle, #10b981 0%, rgba(16,185,129,0) 70%); }
        .blob-3 { top: 40%; left: 35%; width: 380px; height: 380px; background: radial-gradient(circle, #f59e0b 0%, rgba(245,158,11,0) 70%); }
    </style>
</head>
<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 min-h-screen antialiased selection:bg-emerald-500 selection:text-white transition-colors duration-300">

    <div class="bg-blobs" aria-hidden="true">
        <div class="blob blob-1"></div>
        <div class="blob blob-2"></div>
        <div class="blob blob-3"></div>
    </div>

    <div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 py-4 sm:py-6 relative z-10">

        <!-- هدر هماهنگ و یکپارچه -->
        <?php render_app_header('reports'); ?>

        <?php if ($is_demo_mode): ?>
            <!-- بنر اطلاع‌رسانی حالت پیش‌نمایش محلی -->
            <div class="mb-6 p-4 rounded-3xl bg-amber-500/10 border border-amber-500/20 backdrop-blur-md flex items-center justify-between gap-3 text-amber-700 dark:text-amber-400 text-xs">
                <div class="flex items-center gap-2.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-amber-500 animate-pulse"></span>
                    <span><strong>حالت پیش‌نمایش (Demo Mode):</strong> سرور دیتابیس MySQL یافت نشد، داده‌های شبیه‌سازی‌شده گزارشات در حال نمایش هستند.</span>
                </div>
                <span class="font-bold text-[11px] bg-amber-500/20 px-3 py-1 rounded-full">پیش‌نمایش لوکال</span>
            </div>
        <?php endif; ?>

        <!-- کارت‌های شاخص‌های کلیدی (KPIs) ردیف اول -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
            
            <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-5 shadow-sm hover:shadow-md transition-all">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400">کل تماس‌ها</span>
                    <div class="w-8 h-8 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                    </div>
                </div>
                <div class="text-2xl font-black text-slate-800 dark:text-white mt-3 font-mono">
                    <?php echo number_format((int)$stats['total_calls']); ?>
                </div>
                <div class="text-[11px] text-slate-400 mt-1">تعداد کل ورودی و خروجی</div>
            </div>

            <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-5 shadow-sm hover:shadow-md transition-all">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-emerald-600 dark:text-emerald-400">پاسخ داده شده</span>
                    <div class="w-8 h-8 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    </div>
                </div>
                <div class="text-2xl font-black text-emerald-600 dark:text-emerald-400 mt-3 font-mono">
                    <?php echo number_format((int)$stats['answered_calls']); ?>
                </div>
                <div class="text-[11px] text-slate-400 mt-1">تماس‌های موفق و متصل</div>
            </div>

            <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-5 shadow-sm hover:shadow-md transition-all">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-rose-600 dark:text-rose-400">بدون پاسخ / از دست رفته</span>
                    <div class="w-8 h-8 rounded-xl bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-400 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </div>
                </div>
                <div class="text-2xl font-black text-rose-600 dark:text-rose-400 mt-3 font-mono">
                    <?php echo number_format((int)$stats['missed_calls']); ?>
                </div>
                <div class="text-[11px] text-slate-400 mt-1">بی‌پاسخ، مشغول یا ناموفق</div>
            </div>

            <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-5 shadow-sm hover:shadow-md transition-all">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-blue-600 dark:text-blue-400">مجموع مکالمات</span>
                    <div class="w-8 h-8 rounded-xl bg-blue-50 dark:bg-blue-950/40 text-blue-600 dark:text-blue-400 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                </div>
                <div class="text-2xl font-black text-blue-600 dark:text-blue-400 mt-3 font-mono" dir="ltr">
                    <?php echo format_duration((int)$stats['total_duration']); ?>
                </div>
                <div class="text-[11px] text-slate-400 mt-1">مدت کل زمان صحبت</div>
            </div>

        </div>

        <!-- کارت‌های تحلیلی ردیف دوم -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            
            <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-5 shadow-sm hover:shadow-md transition-all">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400">نرخ پاسخ‌دهی</span>
                    <div class="w-8 h-8 rounded-xl bg-amber-50 dark:bg-amber-950/40 text-amber-600 dark:text-amber-400 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                </div>
                <div class="text-2xl font-black text-amber-600 dark:text-amber-400 mt-3 font-mono">
                    <?php echo $answer_rate; ?>%
                </div>
                <div class="text-[11px] text-slate-400 mt-1">درصد موفقیت پاسخ به تماس‌ها</div>
            </div>

            <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-5 shadow-sm hover:shadow-md transition-all">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400">میانگین زمان مکالمه</span>
                    <div class="w-8 h-8 rounded-xl bg-sky-50 dark:bg-sky-950/40 text-sky-600 dark:text-sky-400 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 100-6 3 3 0 000 6z"/></svg>
                    </div>
                </div>
                <div class="text-2xl font-black text-sky-600 dark:text-sky-400 mt-3 font-mono" dir="ltr">
                    <?php echo format_duration($avg_talk_sec); ?>
                </div>
                <div class="text-[11px] text-slate-400 mt-1">برای تماس‌های موفق</div>
            </div>

            <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-5 shadow-sm hover:shadow-md transition-all">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400">میانگین زمان انتظار</span>
                    <div class="w-8 h-8 rounded-xl bg-teal-50 dark:bg-teal-950/40 text-teal-600 dark:text-teal-400 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                </div>
                <div class="text-2xl font-black text-teal-600 dark:text-teal-400 mt-3 font-mono" dir="ltr">
                    <?php echo format_duration($avg_wait_sec); ?>
                </div>
                <div class="text-[11px] text-slate-400 mt-1">زمان انتظار مشتری در صف</div>
            </div>

            <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-5 shadow-sm hover:shadow-md transition-all">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400">شلوغ‌ترین ساعت</span>
                    <div class="w-8 h-8 rounded-xl bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-400 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                    </div>
                </div>
                <div class="text-lg font-black text-rose-600 dark:text-rose-400 mt-3 font-mono">
                    <?php echo htmlspecialchars($peak_hour_str); ?>
                </div>
                <div class="text-[11px] text-slate-400 mt-1">بازه اوج ترافیک روزانه</div>
            </div>

        </div>

        <!-- نوار فیلتر و جستجوی کاملاً بالانس و متقارن (4 ستون هماهنگ) -->
        <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-6 mb-8 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3 pb-4 mb-4 border-b border-slate-100 dark:border-slate-800">
                <div class="flex items-center gap-2 text-xs font-bold text-slate-700 dark:text-slate-300">
                    <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                    <span>فیلتر و جستجوی گزارشات</span>
                </div>

                <a href="?<?php echo build_qs(array_merge($base_params, array('export' => 'csv'))); ?>"
                   class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-bold bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800/60 hover:bg-emerald-500 hover:text-white transition-all shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    <span>خروجی فایل اکسل (CSV)</span>
                </a>
            </div>

            <form method="GET" id="filter-form" class="space-y-4">
                
                <!-- ردیف اصلی: 4 ستون کاملاً هم‌اندازه و بالانس شده -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 items-end">
                    
                    <!-- 1. بازه زمانی -->
                    <div>
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-2">بازه زمانی</label>
                        <select name="period" id="period-select" onchange="toggleCustomDates(this.value)"
                                class="w-full h-11 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 rounded-2xl px-4 text-xs font-bold text-slate-800 dark:text-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 transition">
                            <option value="week" <?php echo $period === 'week' ? 'selected' : ''; ?>>7 روز اخیر (پیش‌فرض)</option>
                            <option value="today" <?php echo $period === 'today' ? 'selected' : ''; ?>>امروز</option>
                            <option value="yesterday" <?php echo $period === 'yesterday' ? 'selected' : ''; ?>>دیروز</option>
                            <option value="month" <?php echo $period === 'month' ? 'selected' : ''; ?>>30 روز اخیر</option>
                            <option value="all" <?php echo $period === 'all' ? 'selected' : ''; ?>>تمام زمان‌ها (بدون فیلتر)</option>
                            <option value="custom" <?php echo $period === 'custom' ? 'selected' : ''; ?>>تاریخ دلخواه دستی...</option>
                        </select>
                    </div>

                    <!-- 2. شماره داخلی -->
                    <div>
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-2">شماره داخلی</label>
                        <select name="extension" class="w-full h-11 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 rounded-2xl px-4 text-xs font-bold text-slate-800 dark:text-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 transition font-mono">
                            <option value="">-- همه داخلی‌ها --</option>
                            <?php foreach ($extensions_list as $ext): ?>
                                <option value="<?php echo htmlspecialchars($ext); ?>" <?php echo $extension === $ext ? 'selected' : ''; ?>>
                                    داخلی <?php echo $ext; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- 3. وضعیت تماس -->
                    <div>
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-2">وضعیت تماس</label>
                        <select name="disposition" class="w-full h-11 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 rounded-2xl px-4 text-xs font-bold text-slate-800 dark:text-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 transition">
                            <option value="">-- همه وضعیت‌ها --</option>
                            <?php foreach ($dispositions as $d): ?>
                                <option value="<?php echo htmlspecialchars($d); ?>" <?php echo $disposition === $d ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(isset($disp_labels[$d]) ? $disp_labels[$d] : $d); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- 4. جستجوی شماره تلفن -->
                    <div>
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-2">جستجو (مبدا / مقصد)</label>
                        <input type="text" name="search_number" placeholder="مثلاً 09121234567" value="<?php echo htmlspecialchars($search_number); ?>"
                               class="w-full h-11 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 rounded-2xl px-4 text-xs font-bold text-slate-800 dark:text-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 transition font-mono">
                    </div>

                </div>

                <!-- ردیف انتخابی تاریخ دلخواه در صورت انتخاب 'custom' -->
                <div id="custom-date-container" class="<?php echo $period === 'custom' ? '' : 'hidden'; ?> p-4 bg-slate-50 dark:bg-slate-800/40 rounded-2xl border border-slate-200/80 dark:border-slate-700/60 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-2">از تاریخ (شمسی)</label>
                        <input type="text" name="start_date" id="start_date" placeholder="1403/08/01" value="<?php echo htmlspecialchars($start_date); ?>"
                               class="w-full h-11 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl px-4 text-xs text-slate-800 dark:text-white focus:outline-none focus:border-blue-500 font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-2">تا تاریخ (شمسی)</label>
                        <input type="text" name="end_date" id="end_date" placeholder="1403/08/30" value="<?php echo htmlspecialchars($end_date); ?>"
                               class="w-full h-11 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl px-4 text-xs text-slate-800 dark:text-white focus:outline-none focus:border-blue-500 font-mono">
                    </div>
                </div>

                <!-- ردیف دکمه‌های عملیات (هم‌تراز و بالانس) -->
                <div class="flex items-center justify-end gap-3 pt-2">
                    <a href="reports.php" class="h-11 px-5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 rounded-2xl text-xs font-bold transition flex items-center justify-center">
                        ریست فیلترها
                    </a>
                    <button type="submit" class="h-11 px-6 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-bold rounded-2xl text-xs transition shadow-md shadow-blue-500/20 flex items-center justify-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                        <span>اعمال فیلتر</span>
                    </button>
                </div>

            </form>
        </div>

        <!-- جدول مکالمات تفصیلی -->
        <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl overflow-hidden shadow-sm mb-6">
            <div class="p-5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <h2 class="text-sm font-extrabold text-slate-800 dark:text-white">ریز مکالمات ثبت‌شده در سیستم</h2>
                </div>
                <span class="text-xs text-slate-500 dark:text-slate-400 font-mono">
                    تعداد رکوردهای منطبق: <strong class="text-slate-800 dark:text-white"><?php echo number_format($total_records); ?></strong>
                </span>
            </div>

            <div class="overflow-x-auto custom-scrollbar">
                <table class="w-full text-right border-collapse min-w-[800px]">
                    <thead>
                        <tr class="bg-slate-50/75 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-800 text-slate-500 dark:text-slate-400 text-xs font-bold uppercase tracking-wider">
                            <th class="py-4 px-5">تاریخ و ساعت</th>
                            <th class="py-4 px-4">مبدا / جهت</th>
                            <th class="py-4 px-4">مقصد</th>
                            <th class="py-4 px-4">داخلی درگیر</th>
                            <th class="py-4 px-4">مدت انتظار</th>
                            <th class="py-4 px-4">مدت مکالمه</th>
                            <th class="py-4 px-4">وضعیت</th>
                            <th class="py-4 px-5 text-center">جزئیات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800/60 text-sm">
                    <?php if (!empty($rows)): ?>
                        <?php $idx = 0; foreach ($rows as $call):
                            $isAnswered = ($call['disposition'] === 'ANSWERED');
                            $waitClass = 'text-slate-600 dark:text-slate-300 font-medium';
                            if ($call['wait_sec'] > 60) $waitClass = 'text-rose-600 dark:text-rose-400 font-bold';
                            elseif ($call['wait_sec'] > 30) $waitClass = 'text-amber-600 dark:text-amber-400 font-semibold';
                        ?>
                        <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition">
                            <td class="py-4 px-5 whitespace-nowrap">
                                <span class="font-bold text-slate-800 dark:text-slate-200 font-mono"><?php echo htmlspecialchars($call['shamsi']); ?></span>
                                <span class="text-xs text-slate-400 mr-2 font-mono" dir="ltr"><?php echo htmlspecialchars($call['time_str']); ?></span>
                            </td>
                            <td class="py-4 px-4">
                                <div class="flex items-center gap-1.5 flex-wrap">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-lg text-[10px] font-bold border <?php echo direction_badge_class($call['direction']); ?>">
                                        <?php echo htmlspecialchars($call['dir_label']); ?>
                                    </span>
                                    <span class="text-blue-600 dark:text-blue-400 font-bold font-mono"><?php echo htmlspecialchars($call['src']); ?></span>
                                    <?php if ($call['src_type'] !== 'extension' && $call['src_type'] !== 'unknown'): ?>
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded-md text-[10px] border <?php echo number_type_badge_class($call['src_type']); ?>">
                                            <?php echo htmlspecialchars($call['src_type_label']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="py-4 px-4">
                                <div class="flex items-center gap-1.5 flex-wrap">
                                    <span class="text-indigo-600 dark:text-indigo-400 font-bold font-mono"><?php echo htmlspecialchars($call['dst']); ?></span>
                                    <?php if ($call['dst_type'] !== 'extension' && $call['dst_type'] !== 'unknown'): ?>
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded-md text-[10px] border <?php echo number_type_badge_class($call['dst_type']); ?>">
                                            <?php echo htmlspecialchars($call['dst_type_label']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="py-4 px-4">
                                <?php if ($call['answering_ext'] !== ''): ?>
                                    <span class="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400 font-bold font-mono">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                        <?php echo htmlspecialchars($call['answering_ext']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-slate-400">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-4 px-4 font-mono <?php echo $waitClass; ?>" dir="ltr">
                                <?php echo format_duration($call['wait_sec']); ?>
                            </td>
                            <td class="py-4 px-4 font-mono font-bold text-slate-700 dark:text-slate-300" dir="ltr">
                                <?php echo format_duration($call['billsec']); ?>
                            </td>
                            <td class="py-4 px-4">
                                <?php if ($isAnswered): ?>
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800/60">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> پاسخ داده شده
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-400 border border-rose-200 dark:border-rose-800/60">
                                        <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                                        <?php echo htmlspecialchars($call['disp_label']); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="py-4 px-5 text-center">
                                <button type="button"
                                        class="js-details inline-flex items-center gap-1.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:bg-blue-50 dark:hover:bg-blue-950/40 text-slate-700 dark:text-slate-300 hover:text-blue-600 px-3 py-1.5 rounded-xl text-xs font-bold transition shadow-sm"
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

        <!-- صفحه‌بندی -->
        <?php if ($total_pages > 1): ?>
        <div class="flex flex-col sm:flex-row items-center justify-between bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-4 gap-4 shadow-sm">
            <div class="text-xs text-slate-500 font-medium">
                صفحه <span class="text-blue-600 dark:text-blue-400 font-bold font-mono"><?php echo $page; ?></span>
                از <span class="text-slate-800 dark:text-white font-bold font-mono"><?php echo $total_pages; ?></span>
                — مجموع: <span class="text-slate-800 dark:text-white font-bold font-mono"><?php echo number_format($total_records); ?></span> رکورد
            </div>
            <div class="flex items-center gap-1 flex-wrap justify-center font-mono">
                <?php
                $start_p = max(1, $page - 2);
                $end_p   = min($total_pages, $page + 2);
                if ($page > 1) {
                    echo '<a href="?' . build_qs(array_merge($base_params, array('page' => $page - 1))) . '" class="px-3.5 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:bg-slate-100 text-slate-700 dark:text-slate-200 rounded-2xl text-xs font-bold transition shadow-sm font-sans">قبلی</a>';
                }
                if ($start_p > 1) {
                    echo '<a href="?' . build_qs(array_merge($base_params, array('page' => 1))) . '" class="px-3.5 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:bg-slate-100 text-slate-700 dark:text-slate-200 rounded-2xl text-xs font-bold transition shadow-sm">1</a>';
                    if ($start_p > 2) echo '<span class="px-2 text-slate-400">...</span>';
                }
                for ($i = $start_p; $i <= $end_p; $i++) {
                    $cls = $i === $page
                        ? 'bg-blue-600 text-white font-bold border-blue-600 shadow-sm'
                        : 'bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:bg-slate-100 text-slate-700 dark:text-slate-200 shadow-sm';
                    echo '<a href="?' . build_qs(array_merge($base_params, array('page' => $i))) . '" class="px-3.5 py-2 rounded-2xl text-xs transition border ' . $cls . '">' . $i . '</a>';
                }
                if ($end_p < $total_pages) {
                    if ($end_p < $total_pages - 1) echo '<span class="px-2 text-slate-400">...</span>';
                    echo '<a href="?' . build_qs(array_merge($base_params, array('page' => $total_pages))) . '" class="px-3.5 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:bg-slate-100 text-slate-700 dark:text-slate-200 rounded-2xl text-xs font-bold transition shadow-sm">' . $total_pages . '</a>';
                }
                if ($page < $total_pages) {
                    echo '<a href="?' . build_qs(array_merge($base_params, array('page' => $page + 1))) . '" class="px-3.5 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:bg-slate-100 text-slate-700 dark:text-slate-200 rounded-2xl text-xs font-bold transition shadow-sm font-sans">بعدی</a>';
                }
                ?>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- مودال مدرن جزئیات کامل تماس -->
    <div id="detail-modal" class="hidden fixed inset-0 z-50 items-center justify-center bg-slate-950/60 backdrop-blur-md p-4">
        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl w-full max-w-2xl max-h-[90vh] overflow-hidden flex flex-col shadow-2xl transition-all">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/40">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-xl bg-blue-500/10 text-blue-500 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <h2 class="text-base font-extrabold text-slate-800 dark:text-white">شناسنامه و جزئیات کامل تماس</h2>
                </div>
                <button type="button" class="js-close-modal text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 w-8 h-8 flex items-center justify-center rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div id="modal-content" class="px-6 py-4 overflow-y-auto space-y-1"></div>
            <div class="px-6 py-3 border-t border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/40 flex justify-end">
                <button type="button" class="js-close-modal bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 px-6 py-2 rounded-2xl text-xs font-bold transition shadow-sm">بستن</button>
            </div>
        </div>
    </div>

    <script id="calls-json" type="application/json"><?php echo json_encode($js_data); ?></script>

    <script>
    function toggleCustomDates(val) {
        var container = document.getElementById('custom-date-container');
        if (!container) return;
        if (val === 'custom') {
            container.classList.remove('hidden');
        } else {
            container.classList.add('hidden');
        }
    }

    (function() {
        var modal = document.getElementById('detail-modal');
        var content = document.getElementById('modal-content');
        var dataEl = document.getElementById('calls-json');
        if (!modal || !content || !dataEl) return;

        var data = [];
        try { data = JSON.parse(dataEl.textContent || '[]'); } catch (e) { data = []; }

        function fa(s) {
            return String(s == null ? '' : s);
        }
        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
                return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
            });
        }
        function row(label, value, isRaw) {
            var v = (value === null || value === undefined || value === '') ? '—' : (isRaw ? esc(value) : fa(esc(value)));
            return '<div class="flex items-center justify-between gap-4 py-2.5 border-b border-slate-100 dark:border-slate-800 last:border-0">'
                 + '<span class="text-xs font-bold text-slate-500 dark:text-slate-400 shrink-0">' + label + '</span>'
                 + '<span class="text-xs text-slate-800 dark:text-slate-200 font-bold text-left break-all font-mono" dir="ltr">' + v + '</span>'
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
            html += '<div class="mt-4 mb-2 text-xs font-black text-blue-500 uppercase tracking-wider flex items-center gap-1.5"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>پارامترهای فنی استریسک</div>';
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

        document.querySelectorAll('.js-details').forEach(function(btn) {
            btn.addEventListener('click', function() {
                openModal(parseInt(btn.getAttribute('data-index'), 10));
            });
        });

        document.querySelectorAll('.js-close-modal').forEach(function(closer) {
            closer.addEventListener('click', closeModal);
        });

        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeModal();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' || e.keyCode === 27) closeModal();
        });
    })();
    </script>
</body>
</html>
<?php if ($mysqli) $mysqli->close(); ?>
