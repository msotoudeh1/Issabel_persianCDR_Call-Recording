<?php
// ==========================================
// تنظیمات دیتابیس ایزابل (Asterisk CDR)
// ==========================================
$db_host = '127.0.0.1';
$db_name = 'asteriskcdrdb';
$db_user = 'asteriskuser';
$db_pass = 'database_password'; // <--- رمز عبور دیتابیس خود را اینجا وارد کنید

// اتصال به دیتابیس با MySQLi (سازگار با PHP 5.4)
$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mysqli->connect_error) {
    die("<div style='text-align:center;padding:50px;font-family:sans-serif;color:red;'>خطا در اتصال به دیتابیس: " . $mysqli->connect_error . "</div>");
}
$mysqli->set_charset("utf8");

// ==========================================
// تابع تبدیل اعداد انگلیسی به فارسی
// ==========================================
function to_persian_num($string) {
    $english = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9');
    $persian = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹');
    return str_replace($english, $persian, $string);
}

// ==========================================
// توابع تبدیل تاریخ (Gregorian <-> Jalali)
// ==========================================
function gregorian_to_jalali($g_y, $g_m, $g_d) {
    $g_days_in_month = array(31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31);
    $j_days_in_month = array(31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29);
    $gy = $g_y - 1600;
    $gm = $g_m - 1;
    $gd = $g_d - 1;
    $g_day_no = 365 * $gy + floor(($gy + 3) / 4) - floor(($gy + 99) / 100) + floor(($gy + 399) / 400);
    for ($i = 0; $i < $gm; ++$i) $g_day_no += $g_days_in_month[$i];
    if ($gm > 1 && (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0))) $g_day_no++;
    $g_day_no += $gd;
    $j_day_no = $g_day_no - 79;
    $j_np = floor($j_day_no / 12053);
    $j_day_no %= 12053;
    $jy = 979 + 33 * $j_np + 4 * floor($j_day_no / 1461);
    $j_day_no %= 1461;
    if ($j_day_no >= 366) {
        $jy += floor(($j_day_no - 1) / 365);
        $j_day_no = ($j_day_no - 1) % 365;
    }
    for ($i = 0; $i < 11 && $j_day_no >= $j_days_in_month[$i]; ++$i) $j_day_no -= $j_days_in_month[$i];
    $jm = $i + 1;
    $jd = $j_day_no + 1;
    return to_persian_num(sprintf("%04d/%02d/%02d", $jy, $jm, $jd));
}

function jalali_to_gregorian($j_y, $j_m, $j_d) {
    $g_days_in_month = array(31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31);
    $j_days_in_month = array(31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29);
    $jy = $j_y - 979;
    $jm = $j_m - 1;
    $jd = $j_d - 1;
    $j_day_no = 365 * $jy + floor($jy / 33) * 8 + floor(($jy % 33 + 3) / 4);
    for ($i = 0; $i < $jm; ++$i) $j_day_no += $j_days_in_month[$i];
    $j_day_no += $jd;
    $g_day_no = $j_day_no + 79;
    $gy = 1600 + 400 * floor($g_day_no / 146097);
    $g_day_no %= 146097;
    $leap = true;
    if ($g_day_no >= 36525) {
        $g_day_no--;
        $gy += 100 * floor($g_day_no / 36524);
        $g_day_no %= 36524;
        if ($g_day_no >= 365) $g_day_no++;
        else $leap = false;
    }
    $gy += 4 * floor($g_day_no / 1461);
    $g_day_no %= 1461;
    if ($g_day_no >= 366) {
        $leap = false;
        $g_day_no--;
        $gy += floor($g_day_no / 365);
        $g_day_no %= 365;
    }
    for ($i = 0; $i < 12; $i++) {
        $dim = $g_days_in_month[$i];
        if ($i == 1 && $leap) $dim++;
        if ($g_day_no < $dim) break;
        $g_day_no -= $dim;
    }
    $gm = $i + 1;
    $gd = $g_day_no + 1;
    return sprintf("%04d-%02d-%02d", $gy, $gm, $gd);
}

function format_duration($seconds) {
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    return to_persian_num(sprintf("%02d:%02d:%02d", $hours, $minutes, $secs));
}

// ==========================================
// دریافت لیست داخلی‌ها از دیتابیس اصلی ایزابل
// ==========================================
$extensions_list = array();
$mysqli_ast = new mysqli($db_host, $db_user, $db_pass, 'asterisk');
if (!$mysqli_ast->connect_error) {
    $mysqli_ast->set_charset("utf8");
    $ext_sql = "SELECT extension FROM users UNION SELECT id FROM devices WHERE id REGEXP '^[0-9]+$' ORDER BY extension ASC";
    $ext_result = $mysqli_ast->query($ext_sql);
    if ($ext_result) {
        while ($row = $ext_result->fetch_assoc()) {
            $ext = isset($row['extension']) ? $row['extension'] : (isset($row['id']) ? $row['id'] : '');
            if (!empty($ext) && is_numeric($ext) && strlen($ext) <= 5) {
                $extensions_list[] = $ext;
            }
        }
    }
    $mysqli_ast->close();
}

// روش پشتیبان: استخراج از خود جدول CDR اگر دیتابیس asterisk در دسترس نبود
if (empty($extensions_list)) {
    $ext_sql = "SELECT DISTINCT src FROM cdr WHERE src REGEXP '^[0-9]+$' AND LENGTH(src) <= 5 UNION SELECT DISTINCT dst FROM cdr WHERE dst REGEXP '^[0-9]+$' AND LENGTH(dst) <= 5 ORDER BY src ASC";
    $ext_result = $mysqli->query($ext_sql);
    if ($ext_result) {
        while ($row = $ext_result->fetch_assoc()) {
            $ext = !empty($row['src']) ? $row['src'] : $row['dst'];
            if (!empty($ext)) {
                $extensions_list[] = $ext;
            }
        }
    }
}
$extensions_list = array_unique($extensions_list);
sort($extensions_list);

// ==========================================
// پردازش فیلترها
// ==========================================
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$extension = isset($_GET['extension']) ? trim($_GET['extension']) : '';

$where = array();

if ($start_date) {
    $persian_digits = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹');
    $english_digits = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9');
    $clean_start = str_replace($persian_digits, $english_digits, $start_date);
    
    $parts = explode('/', $clean_start);
    if (count($parts) == 3) {
        $g_start = jalali_to_gregorian((int)$parts[0], (int)$parts[1], (int)$parts[2]) . ' 00:00:00';
        $where[] = "calldate >= '" . $mysqli->real_escape_string($g_start) . "'";
    }
}

if ($end_date) {
    $persian_digits = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹');
    $english_digits = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9');
    $clean_end = str_replace($persian_digits, $english_digits, $end_date);

    $parts = explode('/', $clean_end);
    if (count($parts) == 3) {
        $g_end = jalali_to_gregorian((int)$parts[0], (int)$parts[1], (int)$parts[2]) . ' 23:59:59';
        $where[] = "calldate <= '" . $mysqli->real_escape_string($g_end) . "'";
    }
}

if ($extension !== '') {
    $safe_ext = $mysqli->real_escape_string($extension);
    $where[] = "(src = '$safe_ext' OR dst = '$safe_ext')";
}

$where_clause = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";

// خروجی اکسل CSV
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=calls_report_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF");
    fputcsv($output, array('تاریخ و زمان', 'مبدا', 'مقصد', 'مدت زمان کل', 'مدت مکالمه', 'وضعیت'));

    $export_sql = "SELECT calldate, src, dst, duration, billsec, disposition FROM cdr $where_clause ORDER BY calldate DESC LIMIT 2000";
    $export_result = $mysqli->query($export_sql);
    
    if ($export_result) {
        while ($row = $export_result->fetch_assoc()) {
            $dt = new DateTime($row['calldate']);
            $shamsi_dt = gregorian_to_jalali((int)$dt->format('Y'), (int)$dt->format('m'), (int)$dt->format('d')) . ' ' . to_persian_num($dt->format('H:i:s'));
            fputcsv($output, array(
                $shamsi_dt,
                $row['src'],
                $row['dst'],
                format_duration($row['duration']),
                format_duration($row['billsec']),
                $row['disposition'] == 'ANSWERED' ? 'پاسخ داده شده' : 'بدون پاسخ'
            ));
        }
    }
    fclose($output);
    exit;
}

// آمار کلی
$stats_sql = "SELECT 
    COUNT(*) as total_calls,
    SUM(CASE WHEN disposition = 'ANSWERED' THEN 1 ELSE 0 END) as answered_calls,
    SUM(CASE WHEN disposition != 'ANSWERED' THEN 1 ELSE 0 END) as missed_calls,
    SUM(duration) as total_duration
    FROM cdr $where_clause";
$stats_result = $mysqli->query($stats_sql);
$stats = $stats_result ? $stats_result->fetch_assoc() : array('total_calls'=>0, 'answered_calls'=>0, 'missed_calls'=>0, 'total_duration'=>0);

// ==========================================
// تنظیمات صفحه‌بندی (Pagination)
// ==========================================
$per_page = 25; // تعداد رکورد در هر صفحه
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

$total_records = isset($stats['total_calls']) ? (int)$stats['total_calls'] : 0;
$total_pages = ceil($total_records / $per_page);
if ($total_pages < 1) $total_pages = 1;
if ($page > $total_pages) $page = $total_pages;

$offset = ($page - 1) * $per_page;

// کوئری اصلی لیست تماس‌ها با اعمال LIMIT و OFFSET
$sql = "SELECT calldate, src, dst, duration, disposition, billsec FROM cdr $where_clause ORDER BY calldate DESC LIMIT $per_page OFFSET $offset";
$result = $mysqli->query($sql);
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سامانه گزارش‌گیری مکالمات 365voip</title>
    <script src="/3.4.17.js"></script>
    <link href="/Vazirmatn-font-face.css" rel="stylesheet" />
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Vazirmatn', 'sans-serif'] }
                }
            }
        }
    </script>
</head>
<body class="bg-slate-950 text-slate-100 font-sans min-h-screen antialiased selection:bg-indigo-500 selection:text-white">

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <!-- هدر صفحه (طراحی ویژه ooooof ali shode ali) -->
        <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-8 gap-4 border-b border-indigo-500/20 pb-6 relative overflow-hidden bg-gradient-to-r from-indigo-950/40 via-slate-900/40 to-slate-950 p-6 rounded-2xl border">
            <div class="absolute -left-10 -top-10 w-40 h-40 bg-indigo-600/10 rounded-full blur-3xl pointer-events-none"></div>
            <div>
                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 text-xs font-semibold mb-3">
                    <span class="w-2 h-2 rounded-full bg-indigo-400 animate-pulse"></span>
                  Live
                </div>
                <h1 class="text-3xl font-black bg-gradient-to-r from-indigo-400 via-sky-400 to-emerald-400 bg-clip-text text-transparent">
                    گزارشات مکالمات تلفنی ایزابل
                </h1>
                <p class="text-slate-400 text-sm mt-1">سامانه پیشرفته تحلیل، نظارت و گزارش‌گیری هوشمند مرکز تماس</p>
            </div>
            
            <a href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, array('export' => 'csv')))); ?>" 
               class="inline-flex items-center justify-center gap-2 bg-emerald-600/20 hover:bg-emerald-600/30 text-emerald-400 border border-emerald-500/30 px-5 py-2.5 rounded-xl transition duration-200 font-medium text-sm shadow-lg shadow-emerald-950/50">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                خروجی اکسل (CSV)
            </a>
        </div>

        <!-- آمار سریع -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-5 backdrop-blur-xl">
                <span class="text-xs font-semibold text-slate-400 uppercase tracking-wider">کل تماس‌ها</span>
                <div class="text-2xl font-bold text-white mt-2"><?php echo to_persian_num(number_format(isset($stats['total_calls']) ? $stats['total_calls'] : 0)); ?></div>
            </div>
            <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-5 backdrop-blur-xl">
                <span class="text-xs font-semibold text-emerald-400 uppercase tracking-wider">پاسخ داده شده</span>
                <div class="text-2xl font-bold text-emerald-400 mt-2"><?php echo to_persian_num(number_format(isset($stats['answered_calls']) ? $stats['answered_calls'] : 0)); ?></div>
            </div>
            <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-5 backdrop-blur-xl">
                <span class="text-xs font-semibold text-rose-400 uppercase tracking-wider">بدون پاسخ / ناموفق</span>
                <div class="text-2xl font-bold text-rose-400 mt-2"><?php echo to_persian_num(number_format(isset($stats['missed_calls']) ? $stats['missed_calls'] : 0)); ?></div>
            </div>
            <div class="bg-slate-900/60 border border-slate-800 rounded-2xl p-5 backdrop-blur-xl">
                <span class="text-xs font-semibold text-sky-400 uppercase tracking-wider">مجموع مدت مکالمات</span>
                <div class="text-2xl font-bold text-sky-400 mt-2"><?php echo format_duration(isset($stats['total_duration']) ? $stats['total_duration'] : 0); ?></div>
            </div>
        </div>

        <!-- کارت فیلتر -->
        <div class="bg-slate-900/80 border border-slate-800 rounded-2xl p-6 mb-8 shadow-xl backdrop-blur-xl">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-2">از تاریخ (شمسی):</label>
                    <input type="text" name="start_date" placeholder="۱۴۰۵/۰۱/۰۱" value="<?php echo htmlspecialchars($start_date); ?>"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-2">تا تاریخ (شمسی):</label>
                    <input type="text" name="end_date" placeholder="۱۴۰۵/۰۱/۳۰" value="<?php echo htmlspecialchars($end_date); ?>"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-2">انتخاب شماره داخلی:</label>
                    <select name="extension" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 transition">
                        <option value="">-- همه داخلی‌ها --</option>
                        <?php foreach ($extensions_list as $ext): ?>
                            <option value="<?php echo htmlspecialchars($ext); ?>" <?php echo ($extension === $ext) ? 'selected' : ''; ?>>
                                داخلی <?php echo to_persian_num($ext); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="flex-1 bg-indigo-600 hover:bg-indigo-500 text-white font-medium py-2.5 px-4 rounded-xl text-sm transition duration-200 shadow-lg shadow-indigo-600/20">
                        اعمال فیلتر
                    </button>
                    <a href="?" class="bg-slate-800 hover:bg-slate-700 text-slate-300 py-2.5 px-4 rounded-xl text-sm transition duration-200">
                        ریست
                    </a>
                </div>
            </form>
        </div>

        <!-- جدول داده‌ها -->
        <div class="bg-slate-900/80 border border-slate-800 rounded-2xl overflow-hidden shadow-2xl backdrop-blur-xl mb-6">
            <div class="overflow-x-auto">
                <table class="w-full text-right border-collapse">
                    <thead>
                        <tr class="bg-slate-800/50 border-b border-slate-800 text-slate-400 text-xs font-semibold uppercase tracking-wider">
                            <th class="py-4 px-6">تاریخ و زمان (شمسی)</th>
                            <th class="py-4 px-6">مبدا (Caller ID)</th>
                            <th class="py-4 px-6">مقصد (Destination)</th>
                            <th class="py-4 px-6">مدت زمان کل</th>
                            <th class="py-4 px-6">مدت مکالمه</th>
                            <th class="py-4 px-6">وضعیت</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60 text-sm">
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while ($call = $result->fetch_assoc()): ?>
                                <?php 
                                    $dt = new DateTime($call['calldate']);
                                    $shamsi_date = gregorian_to_jalali((int)$dt->format('Y'), (int)$dt->format('m'), (int)$dt->format('d'));
                                    $time_str = to_persian_num($dt->format('H:i:s'));
                                ?>
                                <tr class="hover:bg-slate-800/40 transition duration-150">
                                    <td class="py-4 px-6 whitespace-nowrap text-slate-300 dir-ltr text-right">
                                        <span class="font-medium text-white"><?php echo $shamsi_date; ?></span>
                                        <span class="text-xs text-slate-500 mr-2"><?php echo $time_str; ?></span>
                                    </td>
                                    <td class="py-4 px-6 text-indigo-400 font-semibold"><?php echo to_persian_num(htmlspecialchars($call['src'])); ?></td>
<td class="py-4 px-6 text-sky-400 font-semibold"><?php echo to_persian_num(htmlspecialchars($call['dst'])); ?></td>
<td class="py-4 px-6 text-slate-400"><?php echo format_duration($call['duration']); ?></td>
<td class="py-4 px-6 text-slate-300"><?php echo format_duration($call['billsec']); ?></td>
                                    <td class="py-4 px-6">
                                        <?php if ($call['disposition'] == 'ANSWERED'): ?>
                                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                                                پاسخ داده شده
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium bg-rose-500/10 text-rose-400 border border-rose-500/20">
                                                <span class="w-1.5 h-1.5 rounded-full bg-rose-400"></span>
                                                <?php echo $call['disposition'] == 'NO ANSWER' ? 'بدون پاسخ' : htmlspecialchars($call['disposition']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="py-12 text-center text-slate-500">
                                    هیچ تماسی بر اساس فیلتر انتخاب شده یافت نشد.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- بخش صفحه‌بندی (Pagination) -->
        <?php if ($total_pages > 1): ?>
            <div class="flex flex-col sm:flex-row items-center justify-between bg-slate-900/80 border border-slate-800 rounded-2xl p-4 gap-4 backdrop-blur-xl">
                <div class="text-xs text-slate-400">
                    نمایش صفحه <span class="text-indigo-400 font-bold"><?php echo to_persian_num($page); ?></span> از <span class="text-white font-bold"><?php echo to_persian_num($total_pages); ?></span> (مجموع کل رکوردها: <span class="text-white font-bold"><?php echo to_persian_num($total_records); ?></span>)
                </div>
                
                <div class="flex items-center gap-1 flex-wrap justify-center">
                    <?php
                        // ساخت کوئری استرینگ حفظ کننده فیلترها برای لینک‌های صفحه‌بندی
                        $query_params = $_GET;
                        unset($query_params['page']);
                    ?>

                    <!-- دکمه صفحه قبل -->
                    <?php if ($page > 1): ?>
                        <?php $query_params['page'] = $page - 1; ?>
                        <a href="?<?php echo htmlspecialchars(http_build_query($query_params)); ?>" class="px-3.5 py-2 bg-slate-800 hover:bg-indigo-600 hover:text-white text-slate-300 rounded-xl text-xs font-medium transition">
                            قبلی
                        </a>
                    <?php endif; ?>

                    <!-- شماره صفحات (نمایش پویا محدود اطراف صفحه جاری) -->
                    <?php
                        $start_p = max(1, $page - 2);
                        $end_p = min($total_pages, $page + 2);

                        if ($start_p > 1) {
                            $query_params['page'] = 1;
                            echo '<a href="?' . htmlspecialchars(http_build_query($query_params)) . '" class="px-3.5 py-2 bg-slate-800 hover:bg-indigo-600 hover:text-white text-slate-300 rounded-xl text-xs font-medium transition">' . to_persian_num(1) . '</a>';
                            if ($start_p > 2) {
                                echo '<span class="px-2 text-slate-600">...</span>';
                            }
                        }

                        for ($i = $start_p; $i <= $end_p; $i++):
                            $query_params['page'] = $i;
                            $active_class = ($i === $page) ? 'bg-indigo-600 text-white font-bold shadow-lg shadow-indigo-600/30' : 'bg-slate-800 hover:bg-slate-700 text-slate-300';
                    ?>
                        <a href="?<?php echo htmlspecialchars(http_build_query($query_params)); ?>" class="px-3.5 py-2 rounded-xl text-xs transition <?php echo $active_class; ?>">
                            <?php echo to_persian_num($i); ?>
                        </a>
                    <?php endfor; ?>

                    <?php
                        if ($end_p < $total_pages) {
                            if ($end_p < $total_pages - 1) {
                                echo '<span class="px-2 text-slate-600">...</span>';
                            }
                            $query_params['page'] = $total_pages;
                            echo '<a href="?' . htmlspecialchars(http_build_query($query_params)) . '" class="px-3.5 py-2 bg-slate-800 hover:bg-indigo-600 hover:text-white text-slate-300 rounded-xl text-xs font-medium transition">' . to_persian_num($total_pages) . '</a>';
                        }
                    ?>

                    <!-- دکمه صفحه بعد -->
                    <?php if ($page < $total_pages): ?>
                        <?php $query_params['page'] = $page + 1; ?>
                        <a href="?<?php echo htmlspecialchars(http_build_query($query_params)); ?>" class="px-3.5 py-2 bg-slate-800 hover:bg-indigo-600 hover:text-white text-slate-300 rounded-xl text-xs font-medium transition">
                            بعدی
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    </div>

</body>
</html>
<?php 
$mysqli->close(); 
?>