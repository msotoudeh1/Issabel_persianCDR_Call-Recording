<?php
// ============================================================
// voicemail.php - مدیریت، شنود و مرتب‌سازی پیام‌های صوتی (ویس‌میل) ایزابل
// ============================================================
require_once __DIR__ . '/auth.php';
require_auth(); // الزام ورود به سامانه

require_once __DIR__ . '/helpers.php';

$config = require __DIR__ . '/config.php';
$voicemail_dir = isset($config['voicemail_dir']) ? $config['voicemail_dir'] : '/var/spool/asterisk/voicemail/default/';

// اسکن پیام‌های صوتی از فایل‌سیستم ایزابل
$all_voicemails = scan_asterisk_voicemails($voicemail_dir);
$is_demo_mode = empty($all_voicemails);

// در صورتی که پوشه ویس‌میل روی سرور لوکال خالی باشد، داده‌های پیش‌نمایش بارگذاری می‌شوند
if ($is_demo_mode) {
    $now = time();
    $mock_vms = array(
        array('ext' => '101', 'folder' => 'INBOX',  'num' => '09121112233', 'name' => 'شرکت فناوران',  'sec' => 45,  'ago' => 1200),
        array('ext' => '102', 'folder' => 'INBOX',  'num' => '09354445566', 'name' => 'آقای رضایی',     'sec' => 78,  'ago' => 3600),
        array('ext' => '101', 'folder' => 'Old',    'num' => '02188776655', 'name' => 'پشتیبانی تهران', 'sec' => 112, 'ago' => 14400),
        array('ext' => '103', 'folder' => 'Urgent', 'num' => '09129990011', 'name' => 'مدیریت بازرگانی', 'sec' => 32,  'ago' => 28800),
        array('ext' => '105', 'folder' => 'INBOX',  'num' => '09193334455', 'name' => 'واحد مالی',       'sec' => 95,  'ago' => 43200),
        array('ext' => '104', 'folder' => 'Old',    'num' => '02122334455', 'name' => 'دفتر فروش',       'sec' => 60,  'ago' => 86400),
        array('ext' => '102', 'folder' => 'Old',    'num' => '09127778899', 'name' => 'پیگیری قرارداد',  'sec' => 135, 'ago' => 120000),
        array('ext' => '106', 'folder' => 'INBOX',  'num' => '09301234567', 'name' => 'آقای صادقی',     'sec' => 28,  'ago' => 172800),
    );

    foreach ($mock_vms as $idx => $m) {
        $ts = $now - $m['ago'];
        $dt = new DateTime();
        $dt->setTimestamp($ts);
        $sh = gregorian_to_jalali((int)$dt->format('Y'), (int)$dt->format('m'), (int)$dt->format('d'));

        $all_voicemails[] = array(
            'extension'   => $m['ext'],
            'folder'      => $m['folder'],
            'caller_num'  => $m['num'],
            'caller_name' => $m['name'],
            'duration'    => $m['sec'],
            'timestamp'   => $ts,
            'datetime_g'  => $dt->format('Y-m-d H:i:s'),
            'shamsi_date' => $sh,
            'time_str'    => $dt->format('H:i:s'),
            'audio_path'  => 'demo_audio.wav',
            'has_audio'   => true,
        );
    }
}

// لیست تمام داخلی‌های دارای پیام صوتی
$extensions_list = array();
foreach ($all_voicemails as $v) {
    if (!in_array($v['extension'], $extensions_list, true)) {
        $extensions_list[] = $v['extension'];
    }
}
sort($extensions_list);

// پارامترهای فیلتر و سورت
$filter_ext    = isset($_GET['extension']) ? trim($_GET['extension']) : '';
$filter_folder = isset($_GET['folder'])    ? trim($_GET['folder'])    : '';
$sort_by       = isset($_GET['sort'])      ? trim($_GET['sort'])      : 'date_desc';
$search_q      = isset($_GET['search'])    ? trim($_GET['search'])    : '';

// اعمال فیلترها
$filtered_vms = array_filter($all_voicemails, function($v) use ($filter_ext, $filter_folder, $search_q) {
    if ($filter_ext !== '' && $v['extension'] !== $filter_ext) {
        return false;
    }
    if ($filter_folder !== '' && $v['folder'] !== $filter_folder) {
        return false;
    }
    if ($search_q !== '') {
        $q = mb_strtolower($search_q, 'UTF-8');
        $match_num  = mb_strpos($v['caller_num'], $q) !== false;
        $match_name = mb_strpos(mb_strtolower($v['caller_name'], 'UTF-8'), $q) !== false;
        $match_ext  = mb_strpos($v['extension'], $q) !== false;
        if (!$match_num && !$match_name && !$match_ext) return false;
    }
    return true;
});

// اعمال سورت (مرتب‌سازی)
usort($filtered_vms, function($a, $b) use ($sort_by) {
    switch ($sort_by) {
        case 'date_asc':
            return $a['timestamp'] - $b['timestamp'];
        case 'duration_desc':
            return $b['duration'] - $a['duration'];
        case 'duration_asc':
            return $a['duration'] - $b['duration'];
        case 'ext_asc':
            return strcmp($a['extension'], $b['extension']);
        case 'caller_asc':
            return strcmp($a['caller_num'], $b['caller_num']);
        case 'date_desc':
        default:
            return $b['timestamp'] - $a['timestamp'];
    }
});

// شاخص‌های آماری پیام‌های صوتی
$total_vms_count = count($all_voicemails);
$new_vms_count   = 0;
$old_vms_count   = 0;
$total_duration  = 0;

foreach ($all_voicemails as $v) {
    if ($v['folder'] === 'INBOX') $new_vms_count++;
    else $old_vms_count++;
    $total_duration += $v['duration'];
}

// صفحه‌بندی
$per_page = 15;
$page = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
$total_records = count($filtered_vms);
$total_pages = max(1, (int)ceil($total_records / $per_page));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;
$display_vms = array_slice($filtered_vms, $offset, $per_page);

$base_params = $_GET;
unset($base_params['page']);
function build_qs($params) { return htmlspecialchars(http_build_query($params), ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>صندوق صوتی (ویس‌میل) | ایزابل</title>

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
        .blob-1 { top: -5%; right: 10%; width: 450px; height: 450px; background: radial-gradient(circle, #8b5cf6 0%, rgba(139,92,246,0) 70%); }
        .blob-2 { bottom: 10%; left: 5%; width: 500px; height: 500px; background: radial-gradient(circle, #10b981 0%, rgba(16,185,129,0) 70%); }
        .blob-3 { top: 40%; left: 35%; width: 380px; height: 380px; background: radial-gradient(circle, #06b6d4 0%, rgba(6,182,212,0) 70%); }
    </style>
</head>
<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 min-h-screen antialiased selection:bg-emerald-500 selection:text-white transition-colors duration-300">

    <div class="bg-blobs" aria-hidden="true">
        <div class="blob blob-1"></div>
        <div class="blob blob-2"></div>
        <div class="blob blob-3"></div>
    </div>

    <div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 py-4 sm:py-6 relative z-10">

        <!-- هدر ناوبری یکپارچه -->
        <?php render_app_header('voicemail'); ?>

        <?php if ($is_demo_mode): ?>
            <div class="mb-6 p-4 rounded-3xl bg-amber-500/10 border border-amber-500/20 backdrop-blur-md flex items-center justify-between gap-3 text-amber-700 dark:text-amber-400 text-xs">
                <div class="flex items-center gap-2.5">
                    <span class="w-2.5 h-2.5 rounded-full bg-amber-500 animate-pulse"></span>
                    <span><strong>حالت پیش‌نمایش (Demo Mode):</strong> پوشه ویس‌میل محلی یافت نشد، پیام‌های صوتی نمونه جهت تست و شنود نمایش داده شده‌اند.</span>
                </div>
                <span class="font-bold text-[11px] bg-amber-500/20 px-3 py-1 rounded-full">پیش‌نمایش لوکال</span>
            </div>
        <?php endif; ?>

        <!-- 4 کارت آماری متقارن و بالانس شده -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">

            <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-5 shadow-sm hover:shadow-md transition-all">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-slate-500 dark:text-slate-400">کل پیام‌های صوتی</span>
                    <div class="w-8 h-8 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 100-6 3 3 0 000 6z"/></svg>
                    </div>
                </div>
                <div class="text-2xl font-black text-slate-800 dark:text-white mt-3 font-mono">
                    <?php echo number_format($total_vms_count); ?>
                </div>
                <div class="text-[11px] text-slate-400 mt-1">تعداد کل پیام‌ها در صندوق</div>
            </div>

            <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-5 shadow-sm hover:shadow-md transition-all">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-emerald-600 dark:text-emerald-400">پیام‌های جدید (خوانده نشده)</span>
                    <div class="w-8 h-8 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                    </div>
                </div>
                <div class="text-2xl font-black text-emerald-600 dark:text-emerald-400 mt-3 font-mono">
                    <?php echo number_format($new_vms_count); ?>
                </div>
                <div class="text-[11px] text-slate-400 mt-1">صندوق ورودی INBOX</div>
            </div>

            <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-5 shadow-sm hover:shadow-md transition-all">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-blue-600 dark:text-blue-400">پیام‌های آرشیو شده</span>
                    <div class="w-8 h-8 rounded-xl bg-blue-50 dark:bg-blue-950/40 text-blue-600 dark:text-blue-400 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                    </div>
                </div>
                <div class="text-2xl font-black text-blue-600 dark:text-blue-400 mt-3 font-mono">
                    <?php echo number_format($old_vms_count); ?>
                </div>
                <div class="text-[11px] text-slate-400 mt-1">پوشه Old و ذخیره‌شده‌ها</div>
            </div>

            <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-5 shadow-sm hover:shadow-md transition-all">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-purple-600 dark:text-purple-400">مجموع مدت پیام‌ها</span>
                    <div class="w-8 h-8 rounded-xl bg-purple-50 dark:bg-purple-950/40 text-purple-600 dark:text-purple-400 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                </div>
                <div class="text-2xl font-black text-purple-600 dark:text-purple-400 mt-3 font-mono" dir="ltr">
                    <?php echo format_duration($total_duration); ?>
                </div>
                <div class="text-[11px] text-slate-400 mt-1">مدت کل زمان ضبط صدا</div>
            </div>

        </div>

        <!-- نوار فیلتر و سورت کاملاً بالانس شده و متقارن (4 ستون هماهنگ) -->
        <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-6 mb-8 shadow-sm">
            <div class="flex items-center justify-between pb-4 mb-4 border-b border-slate-100 dark:border-slate-800">
                <div class="flex items-center gap-2 text-xs font-bold text-slate-700 dark:text-slate-300">
                    <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                    <span>فیلتر و مرتب‌سازی هوشمند پیام‌های صوتی</span>
                </div>
                <span class="text-xs text-slate-400 font-mono">
                    تعداد نتایج: <strong class="text-slate-800 dark:text-white"><?php echo count($filtered_vms); ?></strong>
                </span>
            </div>

            <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 items-end">

                <!-- 1. داخلی -->
                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-2">انتخاب داخلی</label>
                    <select name="extension" class="w-full h-11 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 rounded-2xl px-4 text-xs font-bold text-slate-800 dark:text-white focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition font-mono">
                        <option value="">-- همه داخلی‌ها --</option>
                        <?php foreach ($extensions_list as $ext): ?>
                            <option value="<?php echo htmlspecialchars($ext); ?>" <?php echo $filter_ext === $ext ? 'selected' : ''; ?>>
                                داخلی <?php echo $ext; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 2. پوشه -->
                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-2">پوشه صندوق</label>
                    <select name="folder" class="w-full h-11 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 rounded-2xl px-4 text-xs font-bold text-slate-800 dark:text-white focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition">
                        <option value="">-- تمام پوشه‌ها --</option>
                        <option value="INBOX" <?php echo $filter_folder === 'INBOX' ? 'selected' : ''; ?>>صندوق ورودی (جدید)</option>
                        <option value="Old" <?php echo $filter_folder === 'Old' ? 'selected' : ''; ?>>آرشیو شده (Old)</option>
                        <option value="Urgent" <?php echo $filter_folder === 'Urgent' ? 'selected' : ''; ?>>پیام‌های فوری (Urgent)</option>
                    </select>
                </div>

                <!-- 3. مرتب‌سازی (Sort) -->
                <div>
                    <label class="block text-xs font-bold text-slate-600 dark:text-slate-300 mb-2">مرتب‌سازی بر اساس</label>
                    <select name="sort" class="w-full h-11 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 rounded-2xl px-4 text-xs font-bold text-slate-800 dark:text-white focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition">
                        <option value="date_desc" <?php echo $sort_by === 'date_desc' ? 'selected' : ''; ?>>جدیدترین تاریخ (پیش‌فرض)</option>
                        <option value="date_asc" <?php echo $sort_by === 'date_asc' ? 'selected' : ''; ?>>قدیمی‌ترین تاریخ</option>
                        <option value="duration_desc" <?php echo $sort_by === 'duration_desc' ? 'selected' : ''; ?>>بیشترین مدت زمان</option>
                        <option value="duration_asc" <?php echo $sort_by === 'duration_asc' ? 'selected' : ''; ?>>کمترین مدت زمان</option>
                        <option value="ext_asc" <?php echo $sort_by === 'ext_asc' ? 'selected' : ''; ?>>شماره داخلی</option>
                        <option value="caller_asc" <?php echo $sort_by === 'caller_asc' ? 'selected' : ''; ?>>شماره تماس‌گیرنده</option>
                    </select>
                </div>

                <!-- 4. دکمه‌ها -->
                <div class="flex items-center gap-2">
                    <button type="submit" class="flex-1 h-11 bg-gradient-to-r from-emerald-600 to-teal-500 hover:from-emerald-700 hover:to-teal-600 text-white font-bold rounded-2xl text-xs transition shadow-md shadow-emerald-500/20 flex items-center justify-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                        <span>اعمال فیلتر و سورت</span>
                    </button>
                    <a href="voicemail.php" class="h-11 px-4 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 rounded-2xl text-xs font-bold transition flex items-center justify-center" title="پاکسازی فیلتر">
                        ریست
                    </a>
                </div>

            </form>
        </div>

        <!-- جدول پیام‌های صوتی -->
        <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl overflow-hidden shadow-sm mb-6">
            <div class="p-5 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-xl bg-purple-500/10 text-purple-600 dark:text-purple-400 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 100-6 3 3 0 000 6z"/></svg>
                    </div>
                    <h2 class="text-sm font-extrabold text-slate-800 dark:text-white">فهرست پیام‌های ضبط‌شده در صندوق صوتی</h2>
                </div>
                <span class="text-xs text-slate-500 dark:text-slate-400 font-mono">
                    صفحه <?php echo $page; ?> از <?php echo $total_pages; ?>
                </span>
            </div>

            <div class="overflow-x-auto custom-scrollbar">
                <table class="w-full text-right border-collapse min-w-[860px]">
                    <thead>
                        <tr class="bg-slate-50/75 dark:bg-slate-800/50 border-b border-slate-200 dark:border-slate-800 text-slate-500 dark:text-slate-400 text-xs font-bold uppercase tracking-wider">
                            <th class="py-4 px-5">داخلی</th>
                            <th class="py-4 px-4">تماس‌گیرنده</th>
                            <th class="py-4 px-4">تاریخ و زمان دریافت</th>
                            <th class="py-4 px-4">مدت پیام</th>
                            <th class="py-4 px-4">پوشه / وضعیت</th>
                            <th class="py-4 px-5 text-center min-w-[280px]">شنود و دانلود پیام صوتی</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800/60 text-sm">
                    <?php if (!empty($display_vms)): ?>
                        <?php foreach ($display_vms as $vm):
                            $audio_url = 'play_audio.php?type=voicemail&file=' . urlencode($vm['audio_path']);
                            $download_url = $audio_url . '&download=1';
                        ?>
                        <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40 transition">
                            <td class="py-4 px-5 whitespace-nowrap">
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl text-xs font-black bg-slate-100 dark:bg-slate-800 text-slate-800 dark:text-slate-200 font-mono">
                                    داخلی <?php echo htmlspecialchars($vm['extension']); ?>
                                </span>
                            </td>
                            <td class="py-4 px-4">
                                <div>
                                    <span class="text-blue-600 dark:text-blue-400 font-bold font-mono"><?php echo htmlspecialchars($vm['caller_num']); ?></span>
                                    <?php if (!empty($vm['caller_name'])): ?>
                                        <div class="text-xs text-slate-400"><?php echo htmlspecialchars($vm['caller_name']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="py-4 px-4 whitespace-nowrap">
                                <span class="font-bold text-slate-800 dark:text-slate-200 font-mono"><?php echo $vm['shamsi_date']; ?></span>
                                <span class="text-xs text-slate-400 mr-2 font-mono" dir="ltr"><?php echo $vm['time_str']; ?></span>
                            </td>
                            <td class="py-4 px-4 font-mono font-bold text-slate-700 dark:text-slate-300" dir="ltr">
                                <?php echo format_duration($vm['duration']); ?>
                            </td>
                            <td class="py-4 px-4">
                                <?php if ($vm['folder'] === 'INBOX'): ?>
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800/60">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> جدید (INBOX)
                                    </span>
                                <?php elseif ($vm['folder'] === 'Urgent'): ?>
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-400 border border-rose-200 dark:border-rose-800/60">
                                        <span class="w-1.5 h-1.5 rounded-full bg-rose-500 animate-pulse"></span> فوری (Urgent)
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700">
                                        آرشیو شده (Old)
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="py-4 px-5">
                                <?php if ($vm['has_audio']): ?>
                                    <div class="flex items-center justify-center">
                                        <?php echo render_smart_audio_player($audio_url, $download_url, '', 'purple', 'w-64 sm:w-80', $vm['duration']); ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center text-xs text-slate-400">فایل صوتی یافت نشد</div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="py-12 text-center text-slate-400">هیچ پیام صوتی با فیلترهای انتخابی یافت نشد.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- صفحه‌بندی با ارقام لاتین -->
        <?php if ($total_pages > 1): ?>
        <div class="flex flex-col sm:flex-row items-center justify-between bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-4 gap-4 shadow-sm">
            <div class="text-xs text-slate-500 font-medium">
                صفحه <span class="text-purple-600 dark:text-purple-400 font-bold font-mono"><?php echo $page; ?></span> از <span class="text-slate-800 dark:text-white font-bold font-mono"><?php echo $total_pages; ?></span>
                — مجموع: <span class="text-slate-800 dark:text-white font-bold font-mono"><?php echo number_format($total_records); ?></span> پیام
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
                        ? 'bg-purple-600 text-white font-bold border-purple-600 shadow-sm'
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
</body>
</html>
