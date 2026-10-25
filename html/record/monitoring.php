<?php
// ==========================================
// monitoring.php - شنود و پخش مکالمات (PHP 5.4+)
// ==========================================
$config = require __DIR__ . '/config.php';

$db_host     = $config['db_host'];
$db_name     = $config['db_name'];
$db_user     = $config['db_user'];
$db_pass     = $config['db_pass'];
$monitor_dir = rtrim($config['monitor_dir'], '/\\') . '/';

$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mysqli->connect_error) {
    die("<div style='text-align:center;padding:50px;font-family:sans-serif;color:red;'>خطا در اتصال به دیتابیس.</div>");
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
// شمارش کل
// ==========================================
$count_sql = "SELECT COUNT(*) AS total FROM cdr $where_clause";
$count_res = $mysqli->query($count_sql);
$total_records = 0;
if ($count_res) {
    $row_cnt = $count_res->fetch_assoc();
    $total_records = (int)$row_cnt['total'];
}

// ==========================================
// صفحه‌بندی
// ==========================================
$per_page = 15;
$page = max(1, (int)(isset($_GET['page']) ? $_GET['page'] : 1));
$total_pages = max(1, (int)ceil($total_records / $per_page));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $per_page;

// ==========================================
// کوئری صفحه جاری
// ==========================================
$sql = "SELECT calldate, src, dst, duration, billsec, disposition, uniqueid
        FROM cdr $where_clause
        ORDER BY calldate DESC
        LIMIT $offset, $per_page";
$result = $mysqli->query($sql);

$rows = array();
$recorded_count = 0;
if ($result) {
    while ($call = $result->fetch_assoc()) {
        $call['audio_files'] = find_audio_files($monitor_dir, $call['calldate'], $call['uniqueid']);
        if (!empty($call['audio_files'])) $recorded_count++;
        $rows[] = $call;
    }
}

$base_params = $_GET;
unset($base_params['page']);
function build_qs($params) {
    return htmlspecialchars(http_build_query($params), ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>شنود مکالمات ایزابل</title>
    <script src="/3.4.17.js"></script>
    <link href="/Vazirmatn-font-face.css" rel="stylesheet">
    <script>
        tailwind.config = { theme: { extend: { fontFamily: { sans: ['Vazirmatn', 'sans-serif'] } } } }
    </script>
    <style>
        audio::-webkit-media-controls-panel { background-color: #1e293b; }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 font-sans min-h-screen antialiased selection:bg-emerald-500 selection:text-white">

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <!-- هدر -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-8 gap-4 border-b border-emerald-500/20 pb-6 relative overflow-hidden bg-gradient-to-r from-emerald-950/40 via-slate-900/40 to-slate-950 p-6 rounded-2xl border">
        <div class="absolute -left-10 -top-10 w-40 h-40 bg-emerald-600/10 rounded-full blur-3xl pointer-events-none"></div>
        <div>
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-xs font-semibold mb-3">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                شنود آنلاین
            </div>
            <h1 class="text-3xl font-black bg-gradient-to-r from-emerald-400 via-teal-400 to-sky-400 bg-clip-text text-transparent">
                مانیتورینگ و پخش مکالمات
            </h1>
            <p class="text-slate-400 text-sm mt-1">جستجو، پخش و دانلود فایل‌های ضبط‌شده بر اساس تاریخ، داخلی و شماره</p>
        </div>
        <a href="reports.php" class="inline-flex items-center gap-2 bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-700 px-4 py-2.5 rounded-xl text-sm font-medium transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2a4 4 0 014-4h4m0 0l-3-3m3 3l-3 3M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
            گزارشات متنی
        </a>
    </div>

    <!-- آمار سریع -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
        <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-5 backdrop-blur-xl">
            <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">نتایج یافت‌شده</span>
            <div class="text-2xl font-bold text-white mt-2"><?php echo to_persian_num(number_format($total_records)); ?></div>
        </div>
        <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-5 backdrop-blur-xl">
            <span class="text-xs font-semibold text-emerald-400 uppercase tracking-wider">با فایل صوتی (این صفحه)</span>
            <div class="text-2xl font-bold text-emerald-400 mt-2"><?php echo to_persian_num($recorded_count); ?> <span class="text-sm text-slate-500">/ <?php echo to_persian_num(count($rows)); ?></span></div>
        </div>
        <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-5 backdrop-blur-xl">
            <span class="text-xs font-semibold text-sky-400 uppercase tracking-wider">صفحه جاری</span>
            <div class="text-2xl font-bold text-sky-400 mt-2"><?php echo to_persian_num($page); ?> <span class="text-sm text-slate-500">/ <?php echo to_persian_num($total_pages); ?></span></div>
        </div>
    </div>

    <!-- فیلتر -->
    <div class="bg-slate-900/80 border border-slate-800 rounded-2xl p-6 mb-8 shadow-xl backdrop-blur-xl">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 items-end">
            <div>
                <label class="block text-xs font-medium text-slate-300 mb-2">از تاریخ (شمسی)</label>
                <input type="text" name="start_date" placeholder="1405/01/01" value="<?php echo htmlspecialchars($start_date); ?>"
                       class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-300 mb-2">تا تاریخ (شمسی)</label>
                <input type="text" name="end_date" placeholder="1405/01/30" value="<?php echo htmlspecialchars($end_date); ?>"
                       class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-300 mb-2">شماره داخلی</label>
                <select name="extension" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition">
                    <option value="">-- همه داخلی‌ها --</option>
                    <?php foreach ($extensions_list as $ext): ?>
                        <option value="<?php echo htmlspecialchars($ext); ?>" <?php echo $extension === $ext ? 'selected' : ''; ?>>
                            داخلی <?php echo to_persian_num($ext); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-300 mb-2">جستجو شماره (مبدا/مقصد)</label>
                <input type="text" name="search_number" placeholder="مثلاً 09121234567" value="<?php echo htmlspecialchars($search_number); ?>"
                       class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition">
            </div>
            <div class="flex items-center gap-2 pb-1">
                <input type="checkbox" name="only_recorded" value="1" id="only_recorded" <?php echo $only_recorded ? 'checked' : ''; ?>
                       class="w-4 h-4 rounded border-slate-700 bg-slate-950 text-emerald-500 focus:ring-emerald-500">
                <label for="only_recorded" class="text-xs text-slate-300 cursor-pointer select-none">فقط مکالمات پاسخ‌داده‌شده (احتمال وجود ضبط)</label>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="flex-1 bg-emerald-600 hover:bg-emerald-500 text-white font-medium py-2.5 px-4 rounded-xl text-sm transition shadow-lg shadow-emerald-600/20">
                    فیلتر و جستجو
                </button>
                <a href="?" class="bg-slate-800 hover:bg-slate-700 text-slate-300 py-2.5 px-4 rounded-xl text-sm transition">ریست</a>
            </div>
        </form>
    </div>

    <!-- جدول -->
    <div class="bg-slate-900/80 border border-slate-800 rounded-2xl overflow-hidden shadow-2xl backdrop-blur-xl mb-6">
        <div class="overflow-x-auto">
            <table class="w-full text-right border-collapse">
                <thead>
                    <tr class="bg-slate-800/50 border-b border-slate-800 text-slate-400 text-xs font-semibold uppercase tracking-wider">
                        <th class="py-4 px-4">تاریخ و زمان</th>
                        <th class="py-4 px-4">مبدا</th>
                        <th class="py-4 px-4">مقصد</th>
                        <th class="py-4 px-4">مدت مکالمه</th>
                        <th class="py-4 px-4">وضعیت</th>
                        <th class="py-4 px-4">پخش / دانلود</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60 text-sm">
                <?php if (!empty($rows)): ?>
                    <?php foreach ($rows as $call):
                        $dt = new DateTime($call['calldate']);
                        $shamsi = gregorian_to_jalali((int)$dt->format('Y'), (int)$dt->format('m'), (int)$dt->format('d'));
                        $time = $dt->format('H:i:s');
                        $isAnswered = ($call['disposition'] === 'ANSWERED');
                        $files = $call['audio_files'];
                    ?>
                    <tr class="hover:bg-slate-800/40 transition">
                        <td class="py-4 px-4 whitespace-nowrap">
                            <span class="font-medium text-white"><?php echo to_persian_num($shamsi); ?></span>
                            <span class="text-xs text-slate-500 mr-2"><?php echo to_persian_num($time); ?></span>
                        </td>
                        <td class="py-4 px-4 text-emerald-400 font-semibold"><?php echo to_persian_num(htmlspecialchars($call['src'])); ?></td>
                        <td class="py-4 px-4 text-sky-400 font-semibold"><?php echo to_persian_num(htmlspecialchars($call['dst'])); ?></td>
                        <td class="py-4 px-4 text-slate-300"><?php echo to_persian_num(format_duration($call['billsec'])); ?></td>
                        <td class="py-4 px-4">
                            <?php if ($isAnswered): ?>
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> پاسخ داده شده
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-rose-500/10 text-rose-400 border border-rose-500/20">
                                    <span class="w-1.5 h-1.5 rounded-full bg-rose-400"></span>
                                    <?php echo htmlspecialchars($call['disposition'] ? $call['disposition'] : 'نامشخص'); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="py-4 px-4">
                            <?php if (!empty($files)): ?>
                                <div class="space-y-2">
                                    <?php foreach ($files as $f): ?>
                                        <div class="flex items-center gap-2">
                                            <audio controls preload="none" class="w-52 h-9 rounded-lg"
                                                   src="play_audio.php?file=<?php echo urlencode($f['path']); ?>"></audio>
                                            <a href="play_audio.php?file=<?php echo urlencode($f['path']); ?>&download=1"
                                               class="inline-flex items-center justify-center bg-slate-800 hover:bg-emerald-600 text-slate-300 hover:text-white w-8 h-8 rounded-lg transition"
                                               title="دانلود <?php echo htmlspecialchars($f['name']); ?>">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                            </a>
                                            <span class="text-[10px] uppercase text-slate-500 bg-slate-800/60 px-1.5 py-0.5 rounded"><?php echo htmlspecialchars($f['ext']); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <span class="inline-block text-xs text-slate-500 bg-slate-800/60 px-3 py-1.5 rounded-lg">بدون ضبط</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="py-12 text-center text-slate-500">هیچ رکوردی با فیلترهای انتخاب‌شده یافت نشد.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- صفحه‌بندی -->
    <?php if ($total_pages > 1): ?>
    <div class="flex flex-col sm:flex-row items-center justify-between bg-slate-900/80 border border-slate-800 rounded-2xl p-4 gap-4 backdrop-blur-xl">
        <div class="text-xs text-slate-400">
            صفحه <span class="text-emerald-400 font-bold"><?php echo to_persian_num($page); ?></span>
            از <span class="text-white font-bold"><?php echo to_persian_num($total_pages); ?></span>
            — مجموع: <span class="text-white font-bold"><?php echo to_persian_num(number_format($total_records)); ?></span> رکورد
        </div>
        <div class="flex items-center gap-1 flex-wrap justify-center">
            <?php
            $start_p = max(1, $page - 2);
            $end_p   = min($total_pages, $page + 2);

            if ($page > 1) {
                echo '<a href="?' . build_qs(array_merge($base_params, array('page' => $page - 1))) . '" class="px-3.5 py-2 bg-slate-800 hover:bg-emerald-600 text-slate-300 rounded-xl text-xs font-medium transition">قبلی</a>';
            }
            if ($start_p > 1) {
                echo '<a href="?' . build_qs(array_merge($base_params, array('page' => 1))) . '" class="px-3.5 py-2 bg-slate-800 hover:bg-emerald-600 text-slate-300 rounded-xl text-xs font-medium transition">' . to_persian_num(1) . '</a>';
                if ($start_p > 2) echo '<span class="px-2 text-slate-600">...</span>';
            }
            for ($i = $start_p; $i <= $end_p; $i++) {
                $cls = $i === $page
                    ? 'bg-emerald-600 text-white font-bold shadow-lg shadow-emerald-600/30'
                    : 'bg-slate-800 hover:bg-slate-700 text-slate-300';
                echo '<a href="?' . build_qs(array_merge($base_params, array('page' => $i))) . '" class="px-3.5 py-2 rounded-xl text-xs transition ' . $cls . '">' . to_persian_num($i) . '</a>';
            }
            if ($end_p < $total_pages) {
                if ($end_p < $total_pages - 1) echo '<span class="px-2 text-slate-600">...</span>';
                echo '<a href="?' . build_qs(array_merge($base_params, array('page' => $total_pages))) . '" class="px-3.5 py-2 bg-slate-800 hover:bg-emerald-600 text-slate-300 rounded-xl text-xs font-medium transition">' . to_persian_num($total_pages) . '</a>';
            }
            if ($page < $total_pages) {
                echo '<a href="?' . build_qs(array_merge($base_params, array('page' => $page + 1))) . '" class="px-3.5 py-2 bg-slate-800 hover:bg-emerald-600 text-slate-300 rounded-xl text-xs font-medium transition">بعدی</a>';
            }
            ?>
        </div>
    </div>
    <?php endif; ?>

</div>

</body>
</html>
<?php $mysqli->close(); ?>