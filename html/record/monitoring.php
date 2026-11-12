<?php
// ============================================================
// monitoring.php - داشبورد پیشرفته مانیتورینگ، آنالیز و شنود مکالمات
// ============================================================
require_once __DIR__ . '/auth.php';
require_auth(); // بررسی احراز هویت

require_once __DIR__ . '/helpers.php';

$config = require __DIR__ . '/config.php';

$db_host     = $config['db_host'];
$db_name     = $config['db_name'];
$db_user     = $config['db_user'];
$db_pass     = $config['db_pass'];
$monitor_dir = rtrim($config['monitor_dir'], '/\\') . '/';

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

$has_recordingfile_col = false;
if ($mysqli) {
    $check_col = $mysqli->query("SHOW COLUMNS FROM cdr LIKE 'recordingfile'");
    if ($check_col && $check_col->num_rows > 0) {
        $has_recordingfile_col = true;
    }
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

// پارامترهای فیلتر هوشمند و بالانس شده
$period        = isset($_GET['period']) ? trim($_GET['period']) : 'week';
$start_date    = trim(isset($_GET['start_date']) ? $_GET['start_date'] : '');
$end_date      = trim(isset($_GET['end_date'])   ? $_GET['end_date']   : '');
$extension     = trim(isset($_GET['extension'])  ? $_GET['extension']  : '');
$search_number = preg_replace('/[^0-9+]/', '', isset($_GET['search_number']) ? $_GET['search_number'] : '');
$only_recorded = isset($_GET['only_recorded']) && $_GET['only_recorded'] === '1';

// محاسبه تاریخ بر اساس بازه انتخابی
if ($period !== 'custom') {
    $period_dates = get_dates_by_period($period);
    $start_date = $period_dates['start'];
    $end_date   = $period_dates['end'];
}

$chart_range = isset($_GET['chart_range']) ? $_GET['chart_range'] : 'all';
$ranges = array(
    'all'   => 'کل بازه انتخابی',
    'today' => 'امروز',
    'week'  => 'این هفته',
    'month' => 'این ماه',
    'year'  => 'امسال'
);

$hourly_data = array_fill(0, 24, 0);
$status_data = array('ANSWERED'=>0, 'NO ANSWER'=>0, 'BUSY'=>0, 'FAILED'=>0, 'OTHER'=>0);
$direction_data = array('inbound'=>0, 'outbound'=>0, 'internal'=>0, 'transit'=>0);
$top_operators = array();
$max_op_cnt = 1;
$total_records = 0;
$per_page = 15;
$page = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
$rows = array();

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
    if ($search_number !== '') {
        $safe = $mysqli->real_escape_string($search_number);
        $where[] = "(src LIKE '%$safe%' OR dst LIKE '%$safe%')";
    }
    if ($only_recorded) {
        $where[] = "disposition = 'ANSWERED' AND billsec > 0";
    }

    $where_clause = $where ? "WHERE " . implode(" AND ", $where) : "";

    $where_chart = $where;
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

    // توزیع ساعتی
    $res_h = $mysqli->query("SELECT HOUR(calldate) AS hr, COUNT(*) AS cnt FROM cdr $where_clause_chart GROUP BY HOUR(calldate)");
    if ($res_h) {
        while ($row = $res_h->fetch_assoc()) $hourly_data[(int)$row['hr']] = (int)$row['cnt'];
    }

    // وضعیت
    $res_s = $mysqli->query("SELECT disposition, COUNT(*) AS cnt FROM cdr $where_clause GROUP BY disposition");
    if ($res_s) {
        while ($row = $res_s->fetch_assoc()) {
            $d = strtoupper($row['disposition']);
            if (isset($status_data[$d])) $status_data[$d] += (int)$row['cnt'];
            else $status_data['OTHER'] += (int)$row['cnt'];
        }
    }

    // جهت
    $res_d = $mysqli->query("SELECT channel, dstchannel, src, dst, dcontext FROM cdr $where_clause LIMIT 10000");
    if ($res_d) {
        while ($row = $res_d->fetch_assoc()) {
            $dir = classify_call_direction($row['channel'], $row['dstchannel'], $row['src'], $row['dst'], $row['dcontext'], $extensions_list);
            if (isset($direction_data[$dir])) $direction_data[$dir]++;
        }
    }

    // اپراتورها
    $operators = array();
    $op_in_where = $where_chart;
    $op_in_where[] = "dst REGEXP '^[0-9]{2,5}$'";
    $op_in_where[] = "disposition = 'ANSWERED'";
    $res_in = $mysqli->query("SELECT dst AS ext, COUNT(*) AS cnt, SUM(billsec) AS sec FROM cdr WHERE " . implode(" AND ", $op_in_where) . " GROUP BY dst");
    if ($res_in) {
        while ($row = $res_in->fetch_assoc()) {
            $ext = $row['ext'];
            if (!isset($operators[$ext])) $operators[$ext] = array('cnt' => 0, 'sec' => 0);
            $operators[$ext]['cnt'] += (int)$row['cnt'];
            $operators[$ext]['sec'] += (int)$row['sec'];
        }
    }

    $op_out_where = $where_chart;
    $op_out_where[] = "src REGEXP '^[0-9]{2,5}$'";
    $op_out_where[] = "disposition = 'ANSWERED'";
    $res_out = $mysqli->query("SELECT src AS ext, COUNT(*) AS cnt, SUM(billsec) AS sec FROM cdr WHERE " . implode(" AND ", $op_out_where) . " GROUP BY src");
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
    foreach ($top_operators as $op) {
        if ($op['cnt'] > $max_op_cnt) $max_op_cnt = $op['cnt'];
    }

    // جدول داده‌ها
    $count_res = $mysqli->query("SELECT COUNT(*) AS total FROM cdr $where_clause");
    if ($count_res) {
        $row_cnt = $count_res->fetch_assoc();
        $total_records = (int)$row_cnt['total'];
    }
    $total_pages = max(1, (int)ceil($total_records / $per_page));
    if ($page > $total_pages) $page = $total_pages;
    $offset = ($page - 1) * $per_page;

    $select_fields = "calldate, src, dst, duration, billsec, disposition, uniqueid";
    if ($has_recordingfile_col) $select_fields .= ", recordingfile";
    $result = $mysqli->query("SELECT $select_fields FROM cdr $where_clause ORDER BY calldate DESC LIMIT $offset, $per_page");
    if ($result) {
        while ($call = $result->fetch_assoc()) {
            $rec_file = isset($call['recordingfile']) ? $call['recordingfile'] : '';
            $call['audio_files'] = find_audio_files($monitor_dir, $call['calldate'], $call['uniqueid'], $rec_file);
            $rows[] = $call;
        }
    }
} else {
    // داده‌های نمونه پیش‌نمایش
    $hourly_data = array(
        0=>1, 1=>0, 2=>0, 3=>0, 4=>1, 5=>3, 6=>8, 7=>18,
        8=>45, 9=>82, 10=>98, 11=>88, 12=>72, 13=>60, 14=>78, 15=>92,
        16=>65, 17=>48, 18=>32, 19=>22, 20=>14, 21=>9, 22=>5, 23=>2
    );
    $status_data = array('ANSWERED' => 1240, 'NO ANSWER' => 185, 'BUSY' => 92, 'FAILED' => 28, 'OTHER' => 15);
    $direction_data = array('inbound' => 840, 'outbound' => 490, 'internal' => 195, 'transit' => 35);
    $top_operators = array(
        '102' => array('cnt' => 174, 'sec' => 24500),
        '105' => array('cnt' => 158, 'sec' => 21300),
        '101' => array('cnt' => 132, 'sec' => 19800),
        '103' => array('cnt' => 115, 'sec' => 15400),
        '104' => array('cnt' => 94,  'sec' => 12100),
        '106' => array('cnt' => 68,  'sec' => 9200),
    );
    $max_op_cnt = 174;
    $total_records = 1560;
    $total_pages = (int)ceil($total_records / $per_page);

    $now = time();
    $mock_samples = array(
        array('src'=>'09121112233', 'dst'=>'102', 'billsec'=>165, 'duration'=>178, 'disp'=>'ANSWERED'),
        array('src'=>'09354445566', 'dst'=>'105', 'billsec'=>84,  'duration'=>95,  'disp'=>'ANSWERED'),
        array('src'=>'02188776655', 'dst'=>'101', 'billsec'=>310, 'duration'=>325, 'disp'=>'ANSWERED'),
        array('src'=>'103', 'dst'=>'09197778899', 'billsec'=>0,   'duration'=>32,  'disp'=>'BUSY'),
        array('src'=>'09123334455', 'dst'=>'104', 'billsec'=>0,   'duration'=>45,  'disp'=>'NO ANSWER'),
        array('src'=>'102', 'dst'=>'105',         'billsec'=>52,  'duration'=>55,  'disp'=>'ANSWERED'),
        array('src'=>'09128889900', 'dst'=>'101', 'billsec'=>195, 'duration'=>205, 'disp'=>'ANSWERED'),
        array('src'=>'106', 'dst'=>'09301234567', 'billsec'=>110, 'duration'=>122, 'disp'=>'ANSWERED'),
        array('src'=>'02122334455', 'dst'=>'102', 'billsec'=>0,   'duration'=>20,  'disp'=>'FAILED'),
        array('src'=>'09129990011', 'dst'=>'103', 'billsec'=>240, 'duration'=>255, 'disp'=>'ANSWERED'),
    );

    for ($i = 0; $i < 10; $i++) {
        $s = $mock_samples[$i];
        $call_time = date('Y-m-d H:i:s', $now - ($i * 1800));
        $has_audio = ($s['disp'] === 'ANSWERED' && file_exists(__DIR__ . '/demo_audio.wav'));
        $rows[] = array(
            'calldate'    => $call_time,
            'src'         => $s['src'],
            'dst'         => $s['dst'],
            'duration'    => $s['duration'],
            'billsec'     => $s['billsec'],
            'disposition' => $s['disp'],
            'uniqueid'    => (string)(1710000000 + $i),
            'audio_files' => $has_audio ? array(array('path' => 'demo_audio.wav', 'name' => 'demo_audio.wav', 'ext' => 'wav')) : array()
        );
    }
}

// خروجی AJAX برای چارت
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'hourly_data'  => array_values($hourly_data),
        'operators'    => $top_operators,
        'max_op_cnt'   => $max_op_cnt,
        'range_label'  => isset($ranges[$chart_range]) ? $ranges[$chart_range] : 'همه'
    ));
    exit;
}

$base_params = $_GET;
unset($base_params['page'], $base_params['ajax']);
function build_qs($params) { return htmlspecialchars(http_build_query($params), ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>داشبورد آنالیز و شنود مکالمات | ایزابل</title>

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
    <script src="../chart.js"></script>

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
        .blob-1 { top: -5%; right: 10%; width: 450px; height: 450px; background: radial-gradient(circle, #10b981 0%, rgba(16,185,129,0) 70%); }
        .blob-2 { bottom: 10%; left: 5%; width: 500px; height: 500px; background: radial-gradient(circle, #06b6d4 0%, rgba(6,182,212,0) 70%); }
        .blob-3 { top: 40%; left: 35%; width: 380px; height: 380px; background: radial-gradient(circle, #8b5cf6 0%, rgba(139,92,246,0) 70%); }
    </style>
</head>
<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 min-h-screen antialiased selection:bg-emerald-500 selection:text-white transition-colors duration-300">

    <div class="bg-blobs" aria-hidden="true">
        <div class="blob blob-1"></div>
        <div class="blob blob-2"></div>
        <div class="blob blob-3"></div>
    </div>

    <div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 py-4 sm:py-6 relative z-10">

        <!-- هدر یکپارچه و مدرن سامانه -->
        <?php render_app_header('monitoring'); ?>

        <?php if ($is_demo_mode): ?>
            <div class="mb-6 p-4 rounded-3xl bg-amber-500/10 border border-amber-500/20 backdrop-blur-md flex items-center justify-between gap-3 text-amber-700 dark:text-amber-400 text-xs">
                <div class="flex items-center gap-2.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-amber-500 animate-pulse"></span>
                    <span><strong>حالت پیش‌نمایش (Demo Mode):</strong> سرور دیتابیس MySQL یافت نشد، داده‌های شبیه‌سازی‌شده با ارقام لاتین در حال نمایش هستند.</span>
                </div>
                <span class="font-bold text-[11px] bg-amber-500/20 px-3 py-1 rounded-full">پیش‌نمایش لوکال</span>
            </div>
        <?php endif; ?>

        <!-- ردیف بالایی: نمودار ساعتی و آمار اپراتورها -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            
            <div class="lg:col-span-2 bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-6 shadow-sm transition-all duration-300">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
                    <div>
                        <h2 class="text-base font-extrabold text-slate-800 dark:text-white flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span>
                            توزیع ساعتی تماس‌ها
                        </h2>
                        <p class="text-xs text-slate-400 mt-1">تعداد تماس‌های ورودی و خروجی در 24 ساعت شبانه‌روز</p>
                    </div>

                    <div class="flex items-center gap-1 bg-slate-100 dark:bg-slate-800/70 p-1 rounded-2xl border border-slate-200/70 dark:border-slate-700/70">
                        <?php foreach ($ranges as $key => $title): ?>
                            <button type="button" data-range="<?php echo $key; ?>"
                                    class="chart-range-btn px-3 py-1.5 rounded-xl text-xs font-bold transition-all <?php echo $chart_range === $key ? 'bg-emerald-500 text-white shadow-sm' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'; ?>">
                                <?php echo $title; ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="h-64 w-full">
                    <canvas id="hourlyChart"></canvas>
                </div>
            </div>

            <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-6 shadow-sm flex flex-col justify-between transition-all duration-300">
                <div>
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-base font-extrabold text-slate-800 dark:text-white flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-cyan-500"></span>
                            پرترافیک‌ترین داخلی‌ها
                        </h2>
                        <span id="operator-range-label" class="text-xs text-slate-400 font-medium">
                            (<?php echo isset($ranges[$chart_range]) ? $ranges[$chart_range] : 'همه'; ?>)
                        </span>
                    </div>

                    <div id="operators-list" class="space-y-4">
                        <?php if (empty($top_operators)): ?>
                            <div class="text-center text-xs text-slate-400 py-10">هیچ داده‌ای برای این بازه یافت نشد.</div>
                        <?php else: ?>
                            <?php 
                            $rank = 1;
                            foreach ($top_operators as $ext => $op): 
                                $percent = round(($op['cnt'] / $max_op_cnt) * 100);
                                $rank_badge = '';
                                if ($rank === 1) $rank_badge = '🥇';
                                elseif ($rank === 2) $rank_badge = '🥈';
                                elseif ($rank === 3) $rank_badge = '🥉';
                                else $rank_badge = '#' . $rank;
                            ?>
                                <div>
                                    <div class="flex items-center justify-between text-xs mb-1.5">
                                        <div class="flex items-center gap-1.5">
                                            <span class="text-sm"><?php echo $rank_badge; ?></span>
                                            <span class="font-extrabold text-slate-800 dark:text-slate-200 font-mono">داخلی <?php echo $ext; ?></span>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <span class="text-emerald-600 dark:text-emerald-400 font-bold font-mono"><?php echo $op['cnt']; ?> تماس</span>
                                            <span class="text-[11px] text-slate-400 font-mono" dir="ltr">(<?php echo format_duration($op['sec']); ?>)</span>
                                        </div>
                                    </div>
                                    <div class="w-full bg-slate-100 dark:bg-slate-800 rounded-full h-2 overflow-hidden">
                                        <div class="bg-gradient-to-r from-emerald-500 to-teal-400 h-2 rounded-full transition-all duration-500" style="width: <?php echo $percent; ?>%"></div>
                                    </div>
                                </div>
                            <?php $rank++; endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>

        <!-- ردیف دوم نمودارها -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
            <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-6 shadow-sm">
                <h3 class="text-sm font-bold text-slate-700 dark:text-slate-200 mb-4 flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span> وضعیت تماس‌ها
                </h3>
                <div class="h-52 flex items-center justify-center">
                    <canvas id="statusChart"></canvas>
                </div>
            </div>

            <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-6 shadow-sm">
                <h3 class="text-sm font-bold text-slate-700 dark:text-slate-200 mb-4 flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-indigo-500"></span> جهت تماس‌ها
                </h3>
                <div class="h-52 flex items-center justify-center">
                    <canvas id="directionChart"></canvas>
                </div>
            </div>
        </div>

        <!-- نوار فیلتر و جستجوی کاملاً بالانس و متقارن (4 ستون هماهنگ) -->
        <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-6 mb-8 shadow-sm">
            <div class="flex items-center justify-between pb-4 mb-4 border-b border-slate-100 dark:border-slate-800">
                <div class="flex items-center gap-2 text-xs font-bold text-slate-700 dark:text-slate-300">
                    <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                    <span>فیلتر و جستجوی مکالمات</span>
                </div>
                <label class="flex items-center gap-2 cursor-pointer text-xs font-semibold text-slate-600 dark:text-slate-300 select-none">
                    <input type="checkbox" name="only_recorded" value="1" form="filter-form" <?php echo $only_recorded ? 'checked' : ''; ?>
                           class="w-4 h-4 rounded-lg border-slate-300 text-emerald-600 focus:ring-emerald-500 cursor-pointer">
                    <span>فقط مکالمات پاسخ‌داده‌شده</span>
                </label>
            </div>

            <form method="GET" id="filter-form" class="space-y-4">
                
                <!-- ردیف اصلی: 4 ستون کاملاً هم‌اندازه و بالانس شده -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 items-end">
                    
                    <!-- 1. سلکتور ساده و یکپارچه بازه زمانی -->
                    <div>
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-2">بازه زمانی</label>
                        <select name="period" id="period-select" onchange="toggleCustomDates(this.value)"
                                class="w-full h-11 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 rounded-2xl px-4 text-xs font-bold text-slate-800 dark:text-white focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition">
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
                        <select name="extension" class="w-full h-11 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 rounded-2xl px-4 text-xs font-bold text-slate-800 dark:text-white focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition font-mono">
                            <option value="">-- همه داخلی‌ها --</option>
                            <?php foreach ($extensions_list as $ext): ?>
                                <option value="<?php echo htmlspecialchars($ext); ?>" <?php echo $extension === $ext ? 'selected' : ''; ?>>
                                    داخلی <?php echo $ext; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- 3. جستجوی شماره تلفن -->
                    <div>
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-2">جستجوی شماره تلفن</label>
                        <input type="text" name="search_number" placeholder="مثلاً 09121234567" value="<?php echo htmlspecialchars($search_number); ?>"
                               class="w-full h-11 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 rounded-2xl px-4 text-xs font-bold text-slate-800 dark:text-white focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition font-mono">
                    </div>

                    <!-- 4. دکمه‌ها با همان ارتفاع یکسان (h-11) -->
                    <div class="flex items-center gap-2">
                        <button type="submit" class="flex-1 h-11 bg-gradient-to-r from-emerald-600 to-teal-500 hover:from-emerald-700 hover:to-teal-600 text-white font-bold rounded-2xl text-xs transition shadow-md shadow-emerald-500/20 flex items-center justify-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                            <span>اعمال فیلتر</span>
                        </button>
                        <a href="monitoring.php" class="h-11 px-4 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 rounded-2xl text-xs font-bold transition flex items-center justify-center" title="پاکسازی فیلترها">
                            ریست
                        </a>
                    </div>

                </div>

                <!-- ردیف تاشو تاریخ دلخواه دستی (فقط در صورت انتخاب 'custom') -->
                <div id="custom-dates-row" class="<?php echo $period === 'custom' ? '' : 'hidden'; ?> pt-3 border-t border-slate-100 dark:border-slate-800 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1.5">از تاریخ</label>
                        <input type="text" name="start_date" id="start_date" placeholder="1403/08/01" value="<?php echo htmlspecialchars($start_date); ?>"
                               class="w-full h-11 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 rounded-2xl px-4 text-xs font-bold text-slate-800 dark:text-white font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 dark:text-slate-400 mb-1.5">تا تاریخ</label>
                        <input type="text" name="end_date" id="end_date" placeholder="1403/08/30" value="<?php echo htmlspecialchars($end_date); ?>"
                               class="w-full h-11 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 rounded-2xl px-4 text-xs font-bold text-slate-800 dark:text-white font-mono">
                    </div>
                </div>

            </form>
        </div>

        <!-- جدول مکالمات و شنود -->
        <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl overflow-hidden shadow-sm mb-6">
            <div class="p-5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 100-6 3 3 0 000 6z"/></svg>
                    </div>
                    <h2 class="text-sm font-extrabold text-slate-800 dark:text-white">فهرست مکالمات و فایل‌های شنود</h2>
                </div>
                <span class="text-xs text-slate-500 dark:text-slate-400 font-mono">
                    مجموع رکوردها: <strong class="text-slate-800 dark:text-white"><?php echo number_format($total_records); ?></strong>
                </span>
            </div>

            <div class="overflow-x-auto custom-scrollbar">
                <table class="w-full text-right border-collapse min-w-[820px]">
                    <thead>
                        <tr class="bg-slate-50/75 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-800 text-slate-500 dark:text-slate-400 text-xs font-bold uppercase tracking-wider">
                            <th class="py-4 px-5">تاریخ و ساعت</th>
                            <th class="py-4 px-4">مبدا</th>
                            <th class="py-4 px-4">مقصد</th>
                            <th class="py-4 px-4">مدت مکالمه</th>
                            <th class="py-4 px-4">وضعیت</th>
                            <th class="py-4 px-5 text-center">پخش و دانلود فایل صوتی</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800/60 text-sm">
                    <?php if (!empty($rows)): ?>
                        <?php foreach ($rows as $call):
                            $dt = new DateTime($call['calldate']);
                            $shamsi = gregorian_to_jalali((int)$dt->format('Y'), (int)$dt->format('m'), (int)$dt->format('d'));
                            $time = $dt->format('H:i:s');
                        ?>
                        <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition">
                            <td class="py-4 px-5 whitespace-nowrap">
                                <span class="font-bold text-slate-800 dark:text-slate-200 font-mono"><?php echo $shamsi; ?></span>
                                <span class="text-xs text-slate-400 mr-2 font-mono" dir="ltr"><?php echo $time; ?></span>
                            </td>
                            <td class="py-4 px-4">
                                <span class="text-blue-600 dark:text-blue-400 font-bold font-mono"><?php echo htmlspecialchars($call['src']); ?></span>
                            </td>
                            <td class="py-4 px-4">
                                <span class="text-indigo-600 dark:text-indigo-400 font-bold font-mono"><?php echo htmlspecialchars($call['dst']); ?></span>
                            </td>
                            <td class="py-4 px-4 font-mono font-bold text-slate-700 dark:text-slate-300" dir="ltr">
                                <?php echo format_duration($call['billsec']); ?>
                            </td>
                            <td class="py-4 px-4">
                                <?php if ($call['disposition'] === 'ANSWERED'): ?>
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800/60">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> پاسخ داده شده
                                    </span>
                                <?php else:
                                    $disp_map = array(
                                        'NO ANSWER'  => 'بدون پاسخ',
                                        'BUSY'       => 'مشغول',
                                        'FAILED'     => 'ناموفق',
                                        'CONGESTION' => 'ترافیک شبکه',
                                        'CANCEL'     => 'لغو شده'
                                    );
                                    $disp_fa = isset($disp_map[$call['disposition']]) ? $disp_map[$call['disposition']] : $call['disposition'];
                                ?>
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-400 border border-rose-200 dark:border-rose-800/60">
                                        <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span> <?php echo htmlspecialchars($disp_fa); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="py-4 px-5">
                                <?php if (!empty($call['audio_files'])): ?>
                                    <div class="flex flex-col gap-2 items-center justify-center">
                                        <?php foreach ($call['audio_files'] as $f): 
                                            $audio_url = 'play_audio.php?file=' . urlencode($f['path']);
                                            $download_url = $audio_url . '&download=1';
                                            echo render_smart_audio_player($audio_url, $download_url, '', 'emerald', 'w-64 sm:w-72');
                                        ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center">
                                        <span class="inline-block text-xs font-semibold text-slate-400 bg-slate-100 dark:bg-slate-800/60 px-3 py-1 rounded-xl">
                                            بدون ضبط
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="py-12 text-center text-slate-400">هیچ مکالمه‌ای با مشخصات انتخاب‌شده یافت نشد.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- صفحه‌بندی با ارقام لاتین -->
        <?php if ($total_pages > 1): ?>
        <div class="flex flex-col sm:flex-row items-center justify-between bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-4 gap-4 shadow-sm">
            <div class="text-xs text-slate-500 font-medium">
                صفحه <span class="text-emerald-600 dark:text-emerald-400 font-bold font-mono"><?php echo $page; ?></span> از <span class="text-slate-800 dark:text-white font-bold font-mono"><?php echo $total_pages; ?></span>
                — مجموع: <span class="text-slate-800 dark:text-white font-bold font-mono"><?php echo number_format($total_records); ?></span> رکورد
            </div>
            <div class="flex items-center gap-1 flex-wrap justify-center font-mono">
                <?php
                $start_p = max(1, $page - 2);
                $end_p   = min($total_pages, $page + 2);
                if ($page > 1) {
                    echo '<a href="?' . build_qs(array_merge($base_params, array('page' => $page - 1))) . '" class="px-3.5 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:bg-slate-100 text-slate-700 dark:text-slate-200 rounded-2xl text-xs font-bold transition shadow-sm font-sans">قبلی</a>';
                }
                for ($i = $start_p; $i <= $end_p; $i++) {
                    $cls = $i === $page
                        ? 'bg-emerald-500 text-white font-bold border-emerald-500 shadow-sm'
                        : 'bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:bg-slate-100 text-slate-700 dark:text-slate-200 shadow-sm';
                    echo '<a href="?' . build_qs(array_merge($base_params, array('page' => $i))) . '" class="px-3.5 py-2 rounded-2xl text-xs transition border ' . $cls . '">' . $i . '</a>';
                }
                if ($page < $total_pages) {
                    echo '<a href="?' . build_qs(array_merge($base_params, array('page' => $page + 1))) . '" class="px-3.5 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:bg-slate-100 text-slate-700 dark:text-slate-200 rounded-2xl text-xs font-bold transition shadow-sm font-sans">بعدی</a>';
                }
                ?>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- تنظیمات چارت‌ها با ارقام لاتین -->
    <script>
    function toggleCustomDates(val) {
        var row = document.getElementById('custom-dates-row');
        if (val === 'custom') {
            row.classList.remove('hidden');
        } else {
            row.classList.add('hidden');
            document.getElementById('filter-form').submit();
        }
    }

    document.addEventListener("DOMContentLoaded", function () {
        const isDark = document.documentElement.classList.contains('dark');
        const textColor = isDark ? '#94a3b8' : '#64748b';
        const gridColor = isDark ? '#1e293b' : '#f1f5f9';

        const commonTooltip = {
            backgroundColor: '#0f172a',
            titleFont: { family: 'Vazirmatn' },
            bodyFont: { family: 'Vazirmatn' },
            padding: 10,
            cornerRadius: 10,
            rtl: false
        };

        const ctxHourly = document.getElementById('hourlyChart').getContext('2d');
        const gradientHourly = ctxHourly.createLinearGradient(0, 0, 0, 240);
        gradientHourly.addColorStop(0, 'rgba(16, 185, 129, 0.35)');
        gradientHourly.addColorStop(1, 'rgba(16, 185, 129, 0.0)');

        window.myHourlyChart = new Chart(ctxHourly, {
            type: 'line',
            data: {
                labels: Array.from({length: 24}, (_, i) => String(i).padStart(2, '0') + ':00'),
                datasets: [{
                    label: 'تعداد تماس',
                    data: <?php echo json_encode(array_values($hourly_data)); ?>,
                    borderColor: '#10b981',
                    borderWidth: 2.5,
                    backgroundColor: gradientHourly,
                    fill: true,
                    tension: 0.35,
                    pointBackgroundColor: '#10b981',
                    pointRadius: 3,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: commonTooltip
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: gridColor },
                        ticks: { color: textColor, font: { family: 'sans-serif', size: 11 } }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: textColor, font: { family: 'sans-serif', size: 10 } }
                    }
                }
            }
        });

        const ctxStatus = document.getElementById('statusChart').getContext('2d');
        new Chart(ctxStatus, {
            type: 'doughnut',
            data: {
                labels: ['پاسخ داده شده', 'بدون پاسخ', 'مشغول', 'ناموفق', 'سایر'],
                datasets: [{
                    data: [
                        <?php echo $status_data['ANSWERED']; ?>,
                        <?php echo $status_data['NO ANSWER']; ?>,
                        <?php echo $status_data['BUSY']; ?>,
                        <?php echo $status_data['FAILED']; ?>,
                        <?php echo $status_data['OTHER']; ?>
                    ],
                    backgroundColor: ['#10b981', '#f43f5e', '#f59e0b', '#64748b', '#94a3b8'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right', labels: { color: textColor, font: { family: 'Vazirmatn', size: 11 }, padding: 14 } },
                    tooltip: commonTooltip
                },
                cutout: '70%'
            }
        });

        const ctxDir = document.getElementById('directionChart').getContext('2d');
        new Chart(ctxDir, {
            type: 'doughnut',
            data: {
                labels: ['ورودی', 'خروجی', 'داخلی', 'ترانزیت'],
                datasets: [{
                    data: [
                        <?php echo $direction_data['inbound']; ?>,
                        <?php echo $direction_data['outbound']; ?>,
                        <?php echo $direction_data['internal']; ?>,
                        <?php echo $direction_data['transit']; ?>
                    ],
                    backgroundColor: ['#06b6d4', '#8b5cf6', '#f59e0b', '#d946ef'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right', labels: { color: textColor, font: { family: 'Vazirmatn', size: 11 }, padding: 14 } },
                    tooltip: commonTooltip
                },
                cutout: '70%'
            }
        });
    });

    document.querySelectorAll('.chart-range-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var range = this.getAttribute('data-range');
            
            document.querySelectorAll('.chart-range-btn').forEach(function(b) {
                b.className = 'chart-range-btn px-3 py-1.5 rounded-xl text-xs font-bold transition-all text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white';
            });
            this.className = 'chart-range-btn px-3 py-1.5 rounded-xl text-xs font-bold transition-all bg-emerald-500 text-white shadow-sm';

            var urlParams = new URLSearchParams(window.location.search);
            urlParams.set('chart_range', range);
            urlParams.set('ajax', '1');

            fetch('?' + urlParams.toString())
                .then(r => r.json())
                .then(data => {
                    if (window.myHourlyChart) {
                        window.myHourlyChart.data.datasets[0].data = data.hourly_data;
                        window.myHourlyChart.update();
                    }
                    var labelEl = document.getElementById('operator-range-label');
                    if (labelEl) labelEl.textContent = '(' + data.range_label + ')';

                    var opHtml = '';
                    var operators = data.operators;
                    if (Object.keys(operators).length === 0) {
                        opHtml = '<div class="text-center text-xs text-slate-400 py-10">داده‌ای یافت نشد.</div>';
                    } else {
                        var rank = 1;
                        for (var ext in operators) {
                            var op = operators[ext];
                            var percent = (op.cnt / data.max_op_cnt) * 100;
                            var secFormatted = new Date(op.sec * 1000).toISOString().substr(11, 8);
                            var badge = rank === 1 ? '🥇' : (rank === 2 ? '🥈' : (rank === 3 ? '🥉' : '#' + rank));

                            opHtml += '<div>' +
                                '<div class="flex items-center justify-between text-xs mb-1.5">' +
                                    '<div class="flex items-center gap-1.5"><span class="text-sm">' + badge + '</span><span class="font-extrabold text-slate-800 dark:text-slate-200 font-mono">داخلی ' + ext + '</span></div>' +
                                    '<div class="flex items-center gap-2"><span class="text-emerald-600 dark:text-emerald-400 font-bold font-mono">' + op.cnt + ' تماس</span><span class="text-[11px] text-slate-400 font-mono" dir="ltr">(' + secFormatted + ')</span></div>' +
                                '</div>' +
                                '<div class="w-full bg-slate-100 dark:bg-slate-800 rounded-full h-2 overflow-hidden">' +
                                    '<div class="bg-gradient-to-r from-emerald-500 to-teal-400 h-2 rounded-full" style="width: ' + percent + '%"></div>' +
                                '</div>' +
                            '</div>';
                            rank++;
                        }
                    }
                    var listEl = document.getElementById('operators-list');
                    if (listEl) listEl.innerHTML = opHtml;

                    urlParams.delete('ajax');
                    window.history.pushState({}, '', '?' + urlParams.toString());
                })
                .catch(err => console.error(err));
        });
    });
    </script>
</body>
</html>
<?php if ($mysqli) $mysqli->close(); ?>
