<?php
// ==========================================
// تنظیمات دیتابیس ایزابل (Asterisk CDR)
// ==========================================
$db_host = '127.0.0.1';
$db_name = 'asteriskcdrdb';
$db_user = 'asteriskuser';
$db_pass = 'database_password'; // <--- رمز عبور دیتابیس خود را اینجا وارد کنید

// مسیر ذخیره‌سازی فایل‌های ضبط مکالمات در ایزابل
$monitor_dir = '/var/spool/asterisk/monitor/';

// اتصال به دیتابیس با MySQLi (سازگار با PHP 5.4)
$mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($mysqli->connect_error) {
    die("<div style='text-align:center;padding:50px;font-family:sans-serif;color:red;'>خطا در اتصال به دیتابیس: " . $mysqli->connect_error . "</div>");
}
$mysqli->set_charset("utf8");

// ==========================================
// توابع تبدیل تاریخ و اعداد
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
    return sprintf("%04d/%02d/%02d", $jy, $jm, $jd);
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
    return sprintf("%02d:%02d:%02d", $hours, $minutes, $secs);
}

function to_persian_num($string) {
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    return str_replace($english, $persian, $string);
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
// پردازش فیلترها و صفحه‌بندی صحیح (Pagination)
// ==========================================
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$extension = isset($_GET['extension']) ? trim($_GET['extension']) : '';

$limit = 15; 
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$where = array();

if ($start_date) {
    $parts = explode('/', $start_date);
    if (count($parts) == 3) {
        $g_start = jalali_to_gregorian((int)$parts[0], (int)$parts[1], (int)$parts[2]) . ' 00:00:00';
        $where[] = "calldate >= '" . $mysqli->real_escape_string($g_start) . "'";
    }
}

if ($end_date) {
    $parts = explode('/', $end_date);
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

// ۱. محاسبه تعداد کل رکوردها مستقیماً از دیتابیس (بدون محدودیت 1000تایی)
$count_sql = "SELECT COUNT(*) as total FROM cdr $where_clause";
$count_result = $mysqli->query($count_sql);
$total_records = 0;
if ($count_result) {
    $row_cnt = $count_result->fetch_assoc();
    $total_records = (int)$row_cnt['total'];
}
$total_pages = ceil($total_records / $limit);
if ($total_pages > 0 && $page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $limit;
}

// ۲. دریافت فقط رکوردهای صفحه فعلی از دیتابیس (سرعت بالا و عدم افت اطلاعات)
$sql = "SELECT calldate, src, dst, duration, uniqueid, disposition FROM cdr $where_clause ORDER BY calldate DESC LIMIT $offset, $limit";
$result = $mysqli->query($sql);

$paginated_rows = array();
if ($result && $result->num_rows > 0) {
    while ($call = $result->fetch_assoc()) {
        $dt = new DateTime($call['calldate']);
        $year = $dt->format('Y');
        $month = $dt->format('m');
        $day = $dt->format('d');
        $uniqueid = $call['uniqueid'];
        
        $found_audio_path = '';
        $target_dir = $monitor_dir . "$year/$month/$day/";
        if (!is_dir($target_dir)) {
            $target_dir = $monitor_dir;
        }
        
        $glob_pattern = $target_dir . "*" . $uniqueid . "*";
        $matched_files = glob($glob_pattern);
        
        if (!empty($matched_files)) {
            $full_path = $matched_files[0];
            $found_audio_path = str_replace($monitor_dir, '', $full_path);
        }

        $call['audio_path'] = $found_audio_path;
        $paginated_rows[] = $call;
    }
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سامانه مانیتورینگ و شنود مکالمات 365voip</title>
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
<body class="bg-slate-950 text-slate-100 font-sans min-h-screen antialiased selection:bg-emerald-500 selection:text-white">

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        
        <!-- هدر صفحه -->
        <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-8 gap-4 border-b border-slate-800 pb-6">
            <div>
                <h1 class="text-3xl font-black bg-gradient-to-r from-emerald-400 via-teal-400 to-sky-400 bg-clip-text text-transparent">
                    مانیتورینگ و شنود مکالمات
                </h1>
                <p class="text-slate-400 text-sm mt-1">جستجو، بررسی و پخش آنلاین فایل‌های ضبط شده مرکز تماس بر اساس داخلی و تاریخ شمسی</p>
            </div>
            
            <a href="report.php" 
               class="inline-flex items-center justify-center gap-2 bg-slate-800 hover:bg-slate-700 text-slate-300 border border-slate-700 px-5 py-2.5 rounded-xl transition duration-200 font-medium text-sm">
                گزارشات متنی تماس‌ها
            </a>
        </div>

        <!-- کارت فیلتر -->
        <div class="bg-slate-900/80 border border-slate-800 rounded-2xl p-6 mb-8 shadow-xl backdrop-blur-xl">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-2">از تاریخ (شمسی):</label>
                    <input type="text" name="start_date" placeholder="1405/01/01" value="<?php echo htmlspecialchars($start_date); ?>"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-2">تا تاریخ (شمسی):</label>
                    <input type="text" name="end_date" placeholder="1405/01/30" value="<?php echo htmlspecialchars($end_date); ?>"
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-300 mb-2">انتخاب شماره داخلی:</label>
                    <select name="extension" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-white focus:outline-none focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 transition">
                        <option value="">-- همه داخلی‌ها --</option>
                        <?php foreach ($extensions_list as $ext): ?>
                            <option value="<?php echo htmlspecialchars($ext); ?>" <?php echo ($extension === $ext) ? 'selected' : ''; ?>>
                                داخلی <?php echo to_persian_num(htmlspecialchars($ext)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="flex-1 bg-emerald-600 hover:bg-emerald-500 text-white font-medium py-2.5 px-4 rounded-xl text-sm transition duration-200 shadow-lg shadow-emerald-600/20">
                        فیلتر و جستجو
                    </button>
                    <a href="?" class="bg-slate-800 hover:bg-slate-700 text-slate-300 py-2.5 px-4 rounded-xl text-sm transition duration-200">
                        ریست
                    </a>
                </div>
            </form>
        </div>

        <!-- جدول داده‌ها و پخش صوت -->
        <div class="bg-slate-900/80 border border-slate-800 rounded-2xl overflow-hidden shadow-2xl backdrop-blur-xl">
            <div class="overflow-x-auto">
                <table class="w-full text-right border-collapse">
                    <thead>
                        <tr class="bg-slate-800/50 border-b border-slate-800 text-slate-400 text-xs font-semibold uppercase tracking-wider">
                            <th class="py-4 px-6">تاریخ و زمان (شمسی)</th>
                            <th class="py-4 px-6">داخلی / مبدا</th>
                            <th class="py-4 px-6">مقصد</th>
                            <th class="py-4 px-6">مدت مکالمه</th>
                            <th class="py-4 px-6">پخش آنلاین / دانلود صوت</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/60 text-sm">
                        <?php 
                        if (!empty($paginated_rows)): 
                            foreach ($paginated_rows as $call): 
                                $dt = new DateTime($call['calldate']);
                                $shamsi_date = gregorian_to_jalali((int)$dt->format('Y'), (int)$dt->format('m'), (int)$dt->format('d'));
                                $time_str = $dt->format('H:i:s');
                                $found_audio_path = $call['audio_path'];
                        ?>
                                <tr class="hover:bg-slate-800/40 transition duration-150">
                                    <td class="py-4 px-6 whitespace-nowrap text-slate-300 dir-ltr text-right">
                                        <span class="font-medium text-white"><?php echo to_persian_num($shamsi_date); ?></span>
                                        <span class="text-xs text-slate-500 mr-2"><?php echo to_persian_num($time_str); ?></span>
                                    </td>
                                    <td class="py-4 px-6 text-emerald-400 font-semibold"><?php echo to_persian_num(htmlspecialchars($call['src'])); ?></td>
                                    <td class="py-4 px-6 text-sky-400 font-semibold"><?php echo to_persian_num(htmlspecialchars($call['dst'])); ?></td>
                                    <td class="py-4 px-6 text-slate-300"><?php echo to_persian_num(format_duration($call['duration'])); ?></td>
                                    <td class="py-4 px-6">
                                        <?php if (!empty($found_audio_path)): ?>
                                            <div class="flex items-center gap-3">
                                                <audio controls class="h-10 w-64 accent-emerald-500">
                                                    <source src="play_audio.php?file=<?php echo urlencode($found_audio_path); ?>" type="audio/mpeg">
                                                    مرورگر شما از پخش صوت پشتیبانی نمی‌کند.
                                                </audio>
                                                <a href="play_audio.php?file=<?php echo urlencode($found_audio_path); ?>&download=1" 
                                                   class="bg-slate-800 hover:bg-slate-700 text-slate-300 p-2 rounded-xl transition" title="دانلود فایل صوتی">
                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-500 bg-slate-800 px-3 py-1.5 rounded-lg inline-block">فایل صوتی یافت نشد</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                        <?php 
                            endforeach; 
                        else: 
                        ?>
                            <tr>
                                <td colspan="5" class="py-12 text-center text-slate-500">
                                    هیچ رکوردی با فیلترهای انتخاب شده یافت نشد.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- بخش صفحه‌بندی (Pagination) -->
            <?php if ($total_pages > 1): ?>
                <div class="flex flex-col sm:flex-row items-center justify-between border-t border-slate-800 px-6 py-4 bg-slate-900/50 gap-4">
                    <div class="text-xs text-slate-400">
                        نمایش صفحه <span class="font-bold text-white"><?php echo to_persian_num($page); ?></span> از <span class="font-bold text-white"><?php echo to_persian_num($total_pages); ?></span> (مجموعاً <span class="font-bold text-white"><?php echo to_persian_num($total_records); ?></span> رکورد)
                    </div>
                    
                    <div class="flex items-center gap-1.5 flex-wrap">
                        <?php
                        $query_params = $_GET;
                        
                        if ($page > 1) {
                            $query_params['page'] = $page - 1;
                            $prev_url = '?' . http_build_query($query_params);
                            echo '<a href="' . $prev_url . '" class="bg-slate-800 hover:bg-slate-700 text-slate-300 px-3 py-1.5 rounded-lg text-xs font-medium transition">قبلی</a>';
                        }

                        $start_p = max(1, $page - 2);
                        $end_p = min($total_pages, $page + 2);

                        if ($start_p > 1) {
                            $query_params['page'] = 1;
                            echo '<a href="?' . http_build_query($query_params) . '" class="bg-slate-800/60 hover:bg-slate-700 text-slate-300 px-3 py-1.5 rounded-lg text-xs font-medium transition">' . to_persian_num(1) . '</a>';
                            if ($start_p > 2) {
                                echo '<span class="text-slate-600 px-1">...</span>';
                            }
                        }

                        for ($i = $start_p; $i <= $end_p; $i++) {
                            $query_params['page'] = $i;
                            $p_url = '?' . http_build_query($query_params);
                            if ($i == $page) {
                                echo '<span class="bg-emerald-600 text-white px-3 py-1.5 rounded-lg text-xs font-bold shadow-lg shadow-emerald-600/20">' . to_persian_num($i) . '</span>';
                            } else {
                                echo '<a href="' . $p_url . '" class="bg-slate-800/60 hover:bg-slate-700 text-slate-300 px-3 py-1.5 rounded-lg text-xs font-medium transition">' . to_persian_num($i) . '</a>';
                            }
                        }

                        if ($end_p < $total_pages) {
                            if ($end_p < $total_pages - 1) {
                                echo '<span class="text-slate-600 px-1">...</span>';
                            }
                            $query_params['page'] = $total_pages;
                            echo '<a href="?' . http_build_query($query_params) . '" class="bg-slate-800/60 hover:bg-slate-700 text-slate-300 px-3 py-1.5 rounded-lg text-xs font-medium transition">' . to_persian_num($total_pages) . '</a>';
                        }

                        if ($page < $total_pages) {
                            $query_params['page'] = $page + 1;
                            $next_url = '?' . http_build_query($query_params);
                            echo '<a href="' . $next_url . '" class="bg-slate-800 hover:bg-slate-700 text-slate-300 px-3 py-1.5 rounded-lg text-xs font-medium transition">بعدی</a>';
                        }
                        ?>
                    </div>
                </div>
            <?php endif; ?>

        </div>

    </div>

</body>
</html>
<?php 
$mysqli->close(); 
?>