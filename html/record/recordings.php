<?php
// ============================================================
// recordings.php - ماژول مدیریت، آرشیو و شنود ضبط مکالمات PBX (Issabel / Asterisk)
// ============================================================
require_once __DIR__ . '/auth.php';
require_auth(); // بررسی احراز هویت کاربر

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

// بررسی وجود ستون recordingfile در جدول cdr
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

// فیلترهای جستجو و بازه زمانی
$period        = isset($_GET['period']) ? trim($_GET['period']) : 'week';
$start_date    = trim(isset($_GET['start_date']) ? $_GET['start_date'] : '');
$end_date      = trim(isset($_GET['end_date'])   ? $_GET['end_date']   : '');
$rec_status    = isset($_GET['rec_status']) ? trim($_GET['rec_status']) : 'all'; // all, recorded, missing
$direction_flt = isset($_GET['direction']) ? trim($_GET['direction']) : 'all';   // all, inbound, outbound, internal
$extension     = trim(isset($_GET['extension'])  ? $_GET['extension']  : '');
$search_number = preg_replace('/[^0-9+]/', '', isset($_GET['search_number']) ? $_GET['search_number'] : '');

// محاسبه تاریخ بر اساس بازه انتخابی
if ($period !== 'custom') {
    $period_dates = get_dates_by_period($period);
    $start_date = $period_dates['start'];
    $end_date   = $period_dates['end'];
}

$per_page = 15;
$page = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
$rows = array();
$total_records = 0;
$total_pages = 1;

// شاخص‌های آماری
$kpi_total_calls    = 0;
$kpi_recorded_calls = 0;
$kpi_missing_calls  = 0;
$kpi_total_talk_sec = 0;

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
    if ($rec_status === 'recorded') {
        // مکالماتی که به احتمال قوی ضبط دارند (پاسخ‌داده‌شده با مدت مکالمه مثبت یا نام فایل موجود)
        if ($has_recordingfile_col) {
            $where[] = "((recordingfile IS NOT NULL AND recordingfile != '') OR (disposition = 'ANSWERED' AND billsec > 0))";
        } else {
            $where[] = "disposition = 'ANSWERED' AND billsec > 0";
        }
    } elseif ($rec_status === 'missing') {
        $where[] = "disposition != 'ANSWERED'";
    }

    $where_clause = $where ? "WHERE " . implode(" AND ", $where) : "";

    // خروجی اکسل (CSV)
    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        $export_limit = 5000;
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=pbx_recordings_' . date('Ymd_His') . '.csv');
        header('Cache-Control: private, max-age=0');

        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, array('تاریخ و زمان', 'جهت تماس', 'تماس‌گیرنده (مبدا)', 'مقصد', 'داخلی درگیر', 'مدت مکالمه (ثانیه)', 'وضعیت تماس', 'شناسه یکتا (UniqueID)', 'وضعیت فایل ضبط', 'نام فایل ضبط'));

        $fields = "calldate, src, dst, duration, billsec, disposition, channel, dstchannel, dcontext, uniqueid";
        if ($has_recordingfile_col) $fields .= ", recordingfile";
        $res_exp = $mysqli->query("SELECT $fields FROM cdr $where_clause ORDER BY calldate DESC LIMIT $export_limit");
        if ($res_exp) {
            while ($c = $res_exp->fetch_assoc()) {
                $dir = classify_call_direction($c['channel'], $c['dstchannel'], $c['src'], $c['dst'], $c['dcontext'], $extensions_list);
                $rf = isset($c['recordingfile']) ? $c['recordingfile'] : '';
                $rec = resolve_pbx_recording($monitor_dir, $c['calldate'], $c['uniqueid'], $rf);
                $ext_in = find_involved_extension($c['channel'], $c['dstchannel']);

                fputcsv($out, array(
                    $c['calldate'],
                    direction_label($dir),
                    $c['src'],
                    $c['dst'],
                    $ext_in !== '' ? $ext_in : '—',
                    $c['billsec'],
                    $c['disposition'],
                    $c['uniqueid'],
                    $rec['available'] ? 'موجود' : 'فاقد فایل',
                    $rec['available'] ? $rec['filename'] : '—'
                ));
            }
        }
        fclose($out);
        exit;
    }

    // آمار KPI
    $res_stats = $mysqli->query("SELECT COUNT(*) AS total_cnt, COUNT(CASE WHEN disposition = 'ANSWERED' AND billsec > 0 THEN 1 END) AS rec_cnt, COALESCE(SUM(billsec), 0) AS total_sec FROM cdr $where_clause");
    if ($res_stats && $st = $res_stats->fetch_assoc()) {
        $kpi_total_calls    = (int)$st['total_cnt'];
        $kpi_recorded_calls = (int)$st['rec_cnt'];
        $kpi_missing_calls  = max(0, $kpi_total_calls - $kpi_recorded_calls);
        $kpi_total_talk_sec = (int)$st['total_sec'];
    }

    $total_records = $kpi_total_calls;
    $total_pages = max(1, (int)ceil($total_records / $per_page));
    if ($page > $total_pages) $page = $total_pages;
    $offset = ($page - 1) * $per_page;

    $select_fields = "calldate, src, dst, duration, billsec, disposition, channel, dstchannel, dcontext, uniqueid";
    if ($has_recordingfile_col) $select_fields .= ", recordingfile";
    $result = $mysqli->query("SELECT $select_fields FROM cdr $where_clause ORDER BY calldate DESC LIMIT $offset, $per_page");
    if ($result) {
        while ($call = $result->fetch_assoc()) {
            $dt = new DateTime($call['calldate']);
            $call['shamsi']   = gregorian_to_jalali((int)$dt->format('Y'), (int)$dt->format('m'), (int)$dt->format('d'));
            $call['time_str'] = $dt->format('H:i:s');
            $call['date_g']   = $dt->format('Y-m-d');

            $call['direction'] = classify_call_direction($call['channel'], $call['dstchannel'], $call['src'], $call['dst'], $call['dcontext'], $extensions_list);
            $call['dir_label'] = direction_label($call['direction']);
            $call['src_type']  = classify_number($call['src'], $extensions_list);
            $call['dst_type']  = classify_number($call['dst'], $extensions_list);
            $call['src_type_label'] = number_type_label($call['src_type']);
            $call['dst_type_label'] = number_type_label($call['dst_type']);
            $call['answering_ext']  = find_involved_extension($call['channel'], $call['dstchannel']);

            // فیلتر جهت تماس در لایه استخراج در صورت انتخاب
            if ($direction_flt !== 'all' && $call['direction'] !== $direction_flt) {
                continue;
            }

            $rf = isset($call['recordingfile']) ? $call['recordingfile'] : '';
            // تطبیق با فایل واقعی از طریق UniqueID و ساختار تاریخ YYYY/MM/DD
            $call['recording'] = resolve_pbx_recording($monitor_dir, $call['calldate'], $call['uniqueid'], $rf);
            $rows[] = $call;
        }
    }
} else {
    // حالت پیش‌نمایش لوکال (Demo Mode) برای توسعه و تست بدون سرور لینوکس
    $kpi_total_calls    = 420;
    $kpi_recorded_calls = 358;
    $kpi_missing_calls  = 62;
    $kpi_total_talk_sec = 47250;
    $total_records      = 420;
    $total_pages        = (int)ceil($total_records / $per_page);

    $now = time();
    $mock_samples = array(
        array('dir'=>'outbound', 'src'=>'101', 'dst'=>'09189804907', 'billsec'=>142, 'disp'=>'ANSWERED', 'uid'=>'1794472743.358591', 'has_rec'=>true,  'pref'=>'out'),
        array('dir'=>'inbound',  'src'=>'989189804907', 'dst'=>'101', 'billsec'=>98,  'disp'=>'ANSWERED', 'uid'=>'1794473482.358770', 'has_rec'=>true,  'pref'=>'exten'),
        array('dir'=>'outbound', 'src'=>'102', 'dst'=>'09121112233', 'billsec'=>210, 'disp'=>'ANSWERED', 'uid'=>'1794474100.358900', 'has_rec'=>true,  'pref'=>'out'),
        array('dir'=>'inbound',  'src'=>'02188776655', 'dst'=>'103', 'billsec'=>0,   'disp'=>'NO ANSWER', 'uid'=>'1794474500.359120', 'has_rec'=>false, 'pref'=>'in'),
        array('dir'=>'internal', 'src'=>'101', 'dst'=>'105',         'billsec'=>45,  'disp'=>'ANSWERED', 'uid'=>'1794475200.359300', 'has_rec'=>true,  'pref'=>'internal'),
        array('dir'=>'inbound',  'src'=>'09354445566', 'dst'=>'102', 'billsec'=>175, 'disp'=>'ANSWERED', 'uid'=>'1794475800.359480', 'has_rec'=>true,  'pref'=>'exten'),
        array('dir'=>'outbound', 'src'=>'104', 'dst'=>'09197778899', 'billsec'=>0,   'disp'=>'BUSY',     'uid'=>'1794476100.359600', 'has_rec'=>false, 'pref'=>'out'),
        array('dir'=>'inbound',  'src'=>'09123334455', 'dst'=>'101', 'billsec'=>310, 'disp'=>'ANSWERED', 'uid'=>'1794476900.359850', 'has_rec'=>true,  'pref'=>'exten'),
        array('dir'=>'outbound', 'src'=>'103', 'dst'=>'02122334455', 'billsec'=>88,  'disp'=>'ANSWERED', 'uid'=>'1794477300.360100', 'has_rec'=>true,  'pref'=>'out'),
        array('dir'=>'inbound',  'src'=>'09128889900', 'dst'=>'106', 'billsec'=>130, 'disp'=>'ANSWERED', 'uid'=>'1794478100.360350', 'has_rec'=>true,  'pref'=>'exten'),
    );

    for ($i = 0; $i < count($mock_samples); $i++) {
        $s = $mock_samples[$i];
        $dt = new DateTime();
        $dt->setTimestamp($now - ($i * 1800));
        $shamsi   = gregorian_to_jalali((int)$dt->format('Y'), (int)$dt->format('m'), (int)$dt->format('d'));
        $time_str = $dt->format('H:i:s');
        $date_g   = $dt->format('Y-m-d');
        $date_compact = $dt->format('Ymd');
        $time_compact = $dt->format('His');

        if ($direction_flt !== 'all' && $s['dir'] !== $direction_flt) continue;
        if ($rec_status === 'recorded' && !$s['has_rec']) continue;
        if ($rec_status === 'missing' && $s['has_rec']) continue;

        // شبیه‌سازی نام فایل مطابق فرمت دقیق درخواستی کاربر:
        // out-09189804907-101-20261112-120903-1794472743.358591.wav
        // exten-101-989189804907-20261112-122132-1794473482.358770.wav
        $sample_filename = ($s['pref'] === 'out')
            ? "out-{$s['dst']}-{$s['src']}-{$date_compact}-{$time_compact}-{$s['uid']}.wav"
            : "exten-{$s['dst']}-{$s['src']}-{$date_compact}-{$time_compact}-{$s['uid']}.wav";

        $rel_path = $dt->format('Y/m/d') . '/' . $sample_filename;
        $mock_size = $s['has_rec'] ? (1024 * 1024 + ($s['billsec'] * 16000)) : 0;

        $recording_meta = array(
            'available'    => $s['has_rec'],
            'filename'     => $s['has_rec'] ? $sample_filename : '',
            'rel_path'     => $s['has_rec'] ? $rel_path : '',
            'full_path'    => $s['has_rec'] ? ($monitor_dir . $rel_path) : '',
            'filesize'     => $mock_size,
            'filesize_str' => $s['has_rec'] ? format_bytes($mock_size) : '—',
            'format'       => $s['has_rec'] ? 'WAV' : '—',
            'play_url'     => $s['has_rec'] ? ('play_audio.php?file=demo_audio.wav&type=monitor') : '',
            'download_url' => $s['has_rec'] ? ('play_audio.php?file=demo_audio.wav&type=monitor&download=1') : '',
        );

        $src_t = classify_number($s['src'], $extensions_list);
        $dst_t = classify_number($s['dst'], $extensions_list);

        $rows[] = array(
            'calldate'       => $dt->format('Y-m-d H:i:s'),
            'date_g'         => $date_g,
            'shamsi'         => $shamsi,
            'time_str'       => $time_str,
            'src'            => $s['src'],
            'dst'            => $s['dst'],
            'duration'       => $s['billsec'] + 12,
            'billsec'        => $s['billsec'],
            'disposition'    => $s['disp'],
            'direction'      => $s['dir'],
            'dir_label'      => direction_label($s['dir']),
            'src_type'       => $src_t,
            'dst_type'       => $dst_t,
            'src_type_label' => number_type_label($src_t),
            'dst_type_label' => number_type_label($dst_t),
            'answering_ext'  => ($s['dir'] === 'inbound') ? $s['dst'] : $s['src'],
            'channel'        => 'PJSIP/trunk-01',
            'dstchannel'     => 'PJSIP/' . $s['dst'],
            'uniqueid'       => $s['uid'],
            'recording'      => $recording_meta,
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
    <title>ضبط مکالمات PBX (PBX Recordings) | ایزابل</title>

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
        .blob-1 { top: -5%; right: 10%; width: 450px; height: 450px; background: radial-gradient(circle, #059669 0%, rgba(5,150,105,0) 70%); }
        .blob-2 { bottom: 10%; left: 5%; width: 500px; height: 500px; background: radial-gradient(circle, #0284c7 0%, rgba(2,132,199,0) 70%); }
        .blob-3 { top: 40%; left: 35%; width: 380px; height: 380px; background: radial-gradient(circle, #7c3aed 0%, rgba(124,58,237,0) 70%); }

        /* استایل اسلایدر پخش صوت */
        input[type="range"] {
            accent-color: #10b981;
        }
    </style>
</head>
<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 min-h-screen antialiased selection:bg-emerald-500 selection:text-white transition-colors duration-300">

    <div class="bg-blobs" aria-hidden="true">
        <div class="blob blob-1"></div>
        <div class="blob blob-2"></div>
        <div class="blob blob-3"></div>
    </div>

    <div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 py-4 sm:py-6 relative z-10">

        <!-- هدر 2 سطحی استاندارد و بالانس -->
        <?php render_app_header('recordings'); ?>

        <?php if ($is_demo_mode): ?>
            <!-- بنر اطلاع‌رسانی حالت پیش‌نمایش محلی -->
            <div class="mb-6 p-4 rounded-3xl bg-amber-500/10 border border-amber-500/20 backdrop-blur-md flex items-center justify-between gap-3 text-amber-700 dark:text-amber-400 text-xs">
                <div class="flex items-center gap-2.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-amber-500 animate-pulse"></span>
                    <span><strong>حالت پیش‌نمایش (Demo Mode):</strong> سرور محلی استریسک/MySQL یافت نشد. داده‌های شبیه‌سازی‌شده ضبط مکالمات با نمونه‌های تطبیق UniqueID در حال نمایش هستند.</span>
                </div>
                <span class="font-bold text-[11px] bg-amber-500/20 px-3 py-1 rounded-full font-mono">Issabel PBX Mock</span>
            </div>
        <?php endif; ?>

        <!-- 4 کارت آماری KPI متقارن و متوازن -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            
            <!-- کل تماس‌های بررسی شده -->
            <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-5 shadow-sm hover:shadow-md transition-all">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400">کل تماس‌های دوره</span>
                    <div class="w-8 h-8 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                    </div>
                </div>
                <div class="text-2xl font-black text-slate-800 dark:text-white mt-3 font-mono">
                    <?php echo number_format($kpi_total_calls); ?>
                </div>
                <div class="text-[11px] text-slate-400 mt-1">تعداد رکوردهای تماس در بازه</div>
            </div>

            <!-- مکالمات دارای فایل ضبط -->
            <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-5 shadow-sm hover:shadow-md transition-all">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-emerald-600 dark:text-emerald-400">مکالمات ضبط‌شده (موجود)</span>
                    <div class="w-8 h-8 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 100-6 3 3 0 000 6z"/></svg>
                    </div>
                </div>
                <div class="text-2xl font-black text-emerald-600 dark:text-emerald-400 mt-3 font-mono">
                    <?php echo number_format($kpi_recorded_calls); ?>
                </div>
                <div class="text-[11px] text-slate-400 mt-1">دارای فایل صوتی فعال در سرور</div>
            </div>

            <!-- فاقد فایل ضبط -->
            <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-5 shadow-sm hover:shadow-md transition-all">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-rose-500 dark:text-rose-400">فاقد فایل ضبط</span>
                    <div class="w-8 h-8 rounded-xl bg-rose-50 dark:bg-rose-950/40 text-rose-500 dark:text-rose-400 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                    </div>
                </div>
                <div class="text-2xl font-black text-rose-500 dark:text-rose-400 mt-3 font-mono">
                    <?php echo number_format($kpi_missing_calls); ?>
                </div>
                <div class="text-[11px] text-slate-400 mt-1">بی‌پاسخ، مشغول یا بدون ضبط</div>
            </div>

            <!-- مدت کل زمان مکالمات ضبط شده -->
            <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-5 shadow-sm hover:shadow-md transition-all">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-blue-600 dark:text-blue-400">مجموع مدت مکالمات</span>
                    <div class="w-8 h-8 rounded-xl bg-blue-50 dark:bg-blue-950/40 text-blue-600 dark:text-blue-400 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                </div>
                <div class="text-2xl font-black text-blue-600 dark:text-blue-400 mt-3 font-mono" dir="ltr">
                    <?php echo format_duration($kpi_total_talk_sec); ?>
                </div>
                <div class="text-[11px] text-slate-400 mt-1">مدت کل زمان صحبت ضبط‌شده</div>
            </div>

        </div>

        <!-- نوار فیلتر و جستجوی پیشرفته 4 ستونه متقارن -->
        <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-6 mb-8 shadow-sm">
            
            <div class="flex flex-wrap items-center justify-between gap-3 pb-4 mb-4 border-b border-slate-100 dark:border-slate-800">
                <div class="flex items-center gap-2 text-xs font-bold text-slate-700 dark:text-slate-300">
                    <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                    <span>فیلتر و جستجوی ضبط مکالمات PBX</span>
                </div>

                <div class="flex items-center gap-2">
                    <a href="?<?php echo build_qs(array_merge($base_params, array('export' => 'csv'))); ?>"
                       class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl text-xs font-bold bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800/60 hover:bg-emerald-500 hover:text-white transition-all shadow-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        <span>خروجی اکسل (CSV)</span>
                    </a>
                </div>
            </div>

            <form method="GET" id="filter-form" class="space-y-4">
                
                <!-- ردیف اصلی فیلترها (4 ستون کاملاً هم‌تراز با ارتفاع یکسان h-11) -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 items-end">
                    
                    <!-- 1. بازه زمانی -->
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

                    <!-- 2. وضعیت فایل ضبط -->
                    <div>
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-2">وضعیت فایل ضبط</label>
                        <select name="rec_status"
                                class="w-full h-11 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 rounded-2xl px-4 text-xs font-bold text-slate-800 dark:text-white focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition">
                            <option value="all" <?php echo $rec_status === 'all' ? 'selected' : ''; ?>>همه تماس‌ها</option>
                            <option value="recorded" <?php echo $rec_status === 'recorded' ? 'selected' : ''; ?>>فقط تماس‌های ضبط‌شده (موجود)</option>
                            <option value="missing" <?php echo $rec_status === 'missing' ? 'selected' : ''; ?>>فقط فاقد فایل ضبط</option>
                        </select>
                    </div>

                    <!-- 3. نوع / جهت تماس -->
                    <div>
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-2">جهت و نوع تماس</label>
                        <select name="direction"
                                class="w-full h-11 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 rounded-2xl px-4 text-xs font-bold text-slate-800 dark:text-white focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition">
                            <option value="all" <?php echo $direction_flt === 'all' ? 'selected' : ''; ?>>همه جهت‌ها</option>
                            <option value="inbound" <?php echo $direction_flt === 'inbound' ? 'selected' : ''; ?>>تماس‌های ورودی (Incoming)</option>
                            <option value="outbound" <?php echo $direction_flt === 'outbound' ? 'selected' : ''; ?>>تماس‌های خروجی (Outgoing)</option>
                            <option value="internal" <?php echo $direction_flt === 'internal' ? 'selected' : ''; ?>>تماس‌های داخلی (Internal)</option>
                        </select>
                    </div>

                    <!-- 4. جستجوی شماره / داخلی -->
                    <div>
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-2">جستجوی شماره (مبدا / مقصد)</label>
                        <input type="text" name="search_number" placeholder="مثلاً 09189804907 یا 101" value="<?php echo htmlspecialchars($search_number); ?>"
                               class="w-full h-11 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 rounded-2xl px-4 text-xs font-bold font-mono text-slate-800 dark:text-white focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition">
                    </div>

                </div>

                <!-- بخش کشویی تاریخ دستی در صورت انتخاب custom -->
                <div id="custom-date-container" class="<?php echo $period === 'custom' ? '' : 'hidden'; ?> p-4 bg-slate-50 dark:bg-slate-800/40 rounded-2xl border border-slate-200/80 dark:border-slate-700/60 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-2">از تاریخ (شمسی)</label>
                        <input type="text" name="start_date" id="start_date" placeholder="1405/08/01" value="<?php echo htmlspecialchars($start_date); ?>"
                               class="w-full h-11 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl px-4 text-xs font-mono text-slate-800 dark:text-white focus:outline-none focus:border-emerald-500">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-2">تا تاریخ (شمسی)</label>
                        <input type="text" name="end_date" id="end_date" placeholder="1405/08/30" value="<?php echo htmlspecialchars($end_date); ?>"
                               class="w-full h-11 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl px-4 text-xs font-mono text-slate-800 dark:text-white focus:outline-none focus:border-emerald-500">
                    </div>
                </div>

                <!-- دکمه‌های اعمال و ریست فیلتر -->
                <div class="flex items-center justify-end gap-3 pt-2">
                    <a href="recordings.php" class="h-11 px-5 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 rounded-2xl text-xs font-bold transition flex items-center justify-center">
                        ریست فیلترها
                    </a>
                    <button type="submit" class="h-11 px-6 bg-gradient-to-r from-emerald-600 to-teal-500 hover:from-emerald-700 hover:to-teal-600 text-white font-bold rounded-2xl text-xs transition shadow-md shadow-emerald-500/20 flex items-center justify-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                        <span>اعمال فیلتر و جستجو</span>
                    </button>
                </div>

            </form>
        </div>

        <!-- جدول تماس‌ها و فایل‌های ضبط‌شده متناظر -->
        <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl overflow-hidden shadow-sm mb-6">
            
            <div class="p-5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 100-6 3 3 0 000 6z"/></svg>
                    </div>
                    <div>
                        <h2 class="text-sm font-extrabold text-slate-800 dark:text-white">فهرست مکالمات و تطبیق فایل‌های ضبط</h2>
                    </div>
                </div>
                <span class="text-xs text-slate-400 font-mono">
                    تعداد رکوردهای منطبق: <strong class="text-slate-800 dark:text-white"><?php echo number_format($total_records); ?></strong>
                </span>
            </div>

            <div class="overflow-x-auto custom-scrollbar">
                <table class="w-full text-right border-collapse min-w-[820px]">
                    <thead>
                        <tr class="bg-slate-50/75 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-800 text-slate-500 dark:text-slate-400 text-xs font-bold uppercase tracking-wider">
                            <th class="py-4 px-5">زمان و تاریخ تماس</th>
                            <th class="py-4 px-4">نوع و جهت</th>
                            <th class="py-4 px-4">شماره تماس‌گیرنده (مبدا)</th>
                            <th class="py-4 px-4">شماره مقصد</th>
                            <th class="py-4 px-4">مدت مکالمه</th>
                            <th class="py-4 px-4">وضعیت ضبط در PBX</th>
                            <th class="py-4 px-5 text-center">شنود آنلاین و فایل صوت</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800/60 text-sm">
                    <?php if (!empty($rows)): ?>
                        <?php $row_idx = 0; foreach ($rows as $call): 
                            $rec = $call['recording'];
                            $has_audio = !empty($rec['available']);
                        ?>
                        <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition">
                            
                            <!-- تاریخ و ساعت -->
                            <td class="py-4 px-5 whitespace-nowrap">
                                <div class="flex flex-col">
                                    <span class="font-bold text-slate-800 dark:text-slate-200 font-mono"><?php echo htmlspecialchars($call['shamsi']); ?></span>
                                    <span class="text-[11px] text-slate-400 font-mono mt-0.5" dir="ltr"><?php echo htmlspecialchars($call['time_str']); ?></span>
                                </div>
                            </td>

                            <!-- نوع و جهت تماس -->
                            <td class="py-4 px-4 whitespace-nowrap">
                                <div class="flex items-center gap-1.5">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-xl text-[10px] font-bold border <?php echo direction_badge_class($call['direction']); ?>">
                                        <?php echo htmlspecialchars($call['dir_label']); ?>
                                    </span>
                                </div>
                            </td>

                            <!-- مبدا -->
                            <td class="py-4 px-4">
                                <div class="flex items-center gap-1.5 flex-wrap">
                                    <span class="text-blue-600 dark:text-blue-400 font-bold font-mono text-xs" dir="ltr">
                                        <?php echo htmlspecialchars($call['src']); ?>
                                    </span>
                                    <?php if ($call['src_type'] !== 'extension' && $call['src_type'] !== 'unknown'): ?>
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded-md text-[10px] border <?php echo number_type_badge_class($call['src_type']); ?>">
                                            <?php echo htmlspecialchars($call['src_type_label']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- مقصد -->
                            <td class="py-4 px-4">
                                <div class="flex items-center gap-1.5 flex-wrap">
                                    <span class="text-indigo-600 dark:text-indigo-400 font-bold font-mono text-xs" dir="ltr">
                                        <?php echo htmlspecialchars($call['dst']); ?>
                                    </span>
                                    <?php if ($call['dst_type'] !== 'extension' && $call['dst_type'] !== 'unknown'): ?>
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded-md text-[10px] border <?php echo number_type_badge_class($call['dst_type']); ?>">
                                            <?php echo htmlspecialchars($call['dst_type_label']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <!-- مدت زمان -->
                            <td class="py-4 px-4 whitespace-nowrap font-mono text-xs text-slate-700 dark:text-slate-300" dir="ltr">
                                <span class="font-bold"><?php echo format_duration($call['billsec']); ?></span>
                                <span class="text-[10px] text-slate-400 block font-normal">کل: <?php echo format_duration($call['duration']); ?></span>
                            </td>

                            <!-- وضعیت ضبط در PBX -->
                            <td class="py-4 px-4 whitespace-nowrap">
                                <?php if ($has_audio): ?>
                                    <div class="flex flex-col gap-0.5">
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl text-[11px] font-bold bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800/60 w-fit">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                            <span>موجود</span>
                                        </span>
                                        <span class="text-[10px] text-slate-400 font-mono mt-0.5" dir="ltr">
                                            <?php echo htmlspecialchars($rec['format']) . ' • ' . htmlspecialchars($rec['filesize_str']); ?>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl text-[11px] font-bold bg-slate-100 dark:bg-slate-800 text-slate-400 border border-slate-200 dark:border-slate-700 w-fit">
                                        <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                                        <span>فاقد فایل ضبط</span>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <!-- پلیر آنلاین هوشمند با کنترل لودبار، جلو/عقب و سرعت پخش -->
                            <td class="py-4 px-5">
                                <?php if ($has_audio): ?>
                                    <div class="flex items-center justify-center">
                                        <?php 
                                        $tech_btn = '<button type="button" onclick="openTechModal(\''.htmlspecialchars($call['uniqueid']).'\', \''.htmlspecialchars($rec['filename']).'\', \''.htmlspecialchars($rec['rel_path']).'\', \''.htmlspecialchars($rec['filesize_str']).'\', \''.htmlspecialchars($call['calldate']).'\')" class="w-8 h-8 rounded-xl bg-slate-100/90 dark:bg-slate-700/70 hover:bg-emerald-50 dark:hover:bg-emerald-950/40 text-slate-500 dark:text-slate-300 hover:text-emerald-600 flex items-center justify-center shrink-0 transition shadow-xs" title="اطلاعات فنی و UniqueID"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></button>';
                                        echo render_smart_audio_player($rec['play_url'], $rec['download_url'], $tech_btn, 'emerald', 'w-64 sm:w-80', $call['billsec']);
                                        ?>
                                    </div>
                                <?php else: ?>
                                    <div class="flex items-center justify-center text-slate-300 dark:text-slate-700" title="فایل ضبط در دسترس نیست">
                                        <span class="inline-block text-xs font-semibold text-slate-400 bg-slate-100 dark:bg-slate-800/60 px-3 py-1 rounded-xl">فاقد فایل ضبط</span>
                                    </div>
                                <?php endif; ?>
                            </td>

                        </tr>
                        <?php $row_idx++; endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="py-12 text-center text-slate-400">هیچ تماسی با فیلتر انتخابی یافت نشد.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>

        <!-- صفحه‌بندی -->
        <?php if ($total_pages > 1): ?>
        <div class="flex flex-col sm:flex-row items-center justify-between bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-4 gap-4 shadow-sm mb-8">
            <div class="text-xs text-slate-500 font-medium">
                صفحه <span class="text-emerald-600 dark:text-emerald-400 font-bold font-mono"><?php echo $page; ?></span>
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
                        ? 'bg-emerald-600 text-white font-bold border-emerald-600 shadow-sm'
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

    <!-- مودال نمایش جزئیات فنی تطبیق فایل و UniqueID -->
    <div id="tech-modal" class="hidden fixed inset-0 z-50 items-center justify-center bg-slate-950/60 backdrop-blur-md p-4">
        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl w-full max-w-lg overflow-hidden flex flex-col shadow-2xl transition-all">
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/40">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <h3 class="text-sm font-extrabold text-slate-800 dark:text-white">مشخصات فنی و اتصال فایل ضبط‌شده</h3>
                </div>
                <button type="button" onclick="closeTechModal()" class="text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 w-8 h-8 flex items-center justify-center rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            
            <div class="p-6 space-y-3.5 text-xs">
                <div class="flex items-center justify-between p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-800">
                    <span class="text-slate-500 dark:text-slate-400 font-bold">شناسه یکتای استریسک (UniqueID):</span>
                    <span id="modal-uid" class="font-mono font-bold text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 px-2 py-0.5 rounded-lg select-all"></span>
                </div>

                <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-800">
                    <span class="text-slate-500 dark:text-slate-400 font-bold block mb-1">نام فایل در سیستم استریسک:</span>
                    <span id="modal-filename" class="font-mono text-slate-700 dark:text-slate-300 block break-all text-[11px] select-all" dir="ltr"></span>
                </div>

                <div class="p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-800">
                    <span class="text-slate-500 dark:text-slate-400 font-bold block mb-1">مسیر نسبی در پوشه monitor (سال/ماه/روز):</span>
                    <span id="modal-relpath" class="font-mono text-slate-700 dark:text-slate-300 block break-all text-[11px]" dir="ltr"></span>
                </div>

                <div class="flex items-center justify-between p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-800">
                    <span class="text-slate-500 dark:text-slate-400 font-bold">حجم فایل صوت:</span>
                    <span id="modal-size" class="font-mono font-bold text-slate-800 dark:text-slate-200"></span>
                </div>

                <div class="flex items-center justify-between p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-800">
                    <span class="text-slate-500 dark:text-slate-400 font-bold">زمان ثبت CDR:</span>
                    <span id="modal-calldate" class="font-mono font-bold text-slate-800 dark:text-slate-200"></span>
                </div>
            </div>

            <div class="px-6 py-3 border-t border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/40 flex justify-end">
                <button type="button" onclick="closeTechModal()" class="h-10 px-5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-xs font-bold transition">بستن</button>
            </div>
        </div>
    </div>

    <!-- اسکریپت کنترل فیلتر و باز و بسته شدن مودال فنی -->
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

        function openTechModal(uid, filename, relpath, size, calldate) {
            document.getElementById('modal-uid').textContent = uid || '—';
            document.getElementById('modal-filename').textContent = filename || '—';
            document.getElementById('modal-relpath').textContent = relpath || '—';
            document.getElementById('modal-size').textContent = size || '—';
            document.getElementById('modal-calldate').textContent = calldate || '—';

            var m = document.getElementById('tech-modal');
            if (m) {
                m.classList.remove('hidden');
                m.classList.add('flex');
            }
        }

        function closeTechModal() {
            var m = document.getElementById('tech-modal');
            if (m) {
                m.classList.add('hidden');
                m.classList.remove('flex');
            }
        }

        // بستن مودال با کلید Escape و کلیک روی پس‌زمینه
        window.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeTechModal();
        });
        document.addEventListener('click', function(e) {
            var m = document.getElementById('tech-modal');
            if (m && !m.classList.contains('hidden') && e.target === m) {
                closeTechModal();
            }
        });
    </script>

</body>
</html>

