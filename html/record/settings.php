<?php
// ============================================================
// settings.php - تنظیمات حساب کاربری و وضعیت سامانه
// ============================================================
require_once __DIR__ . '/auth.php';
require_auth();

require_once __DIR__ . '/helpers.php';
$config_path = __DIR__ . '/config.php';
$config = require $config_path;

$msg_success = '';
$msg_error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token            = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    $current_password = isset($_POST['current_password']) ? $_POST['current_password'] : '';
    $new_username     = trim(isset($_POST['new_username']) ? $_POST['new_username'] : '');
    $new_password     = isset($_POST['new_password']) ? $_POST['new_password'] : '';
    $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';

    if (!verify_csrf_token($token)) {
        $msg_error = 'نشست امنیتی شما منقضی شده است. لطفاً صفحه را تازه‌سازی کنید.';
    } elseif (empty($current_password) || empty($new_username) || empty($new_password) || empty($confirm_password)) {
        $msg_error = 'لطفاً تمامی فیلدها را تکمیل نمایید.';
    } else {
        $valid_pass = isset($config['admin_password']) ? $config['admin_password'] : 'admin@123';
        $pass_valid = false;
        $pass_info = password_get_info($valid_pass);
        if (!empty($pass_info['algo'])) {
            $pass_valid = password_verify($current_password, $valid_pass);
        } else {
            $pass_valid = hash_equals((string)$valid_pass, (string)$current_password);
        }

        if (!$pass_valid) {
            $msg_error = 'رمز عبور فعلی وارد شده اشتباه است.';
        } elseif (mb_strlen($new_username, 'UTF-8') < 3 || mb_strlen($new_username, 'UTF-8') > 50) {
            $msg_error = 'نام کاربری باید بین 3 تا 50 کاراکتر باشد.';
        } elseif ($new_password !== $confirm_password) {
            $msg_error = 'رمز عبور جدید با تکرار آن یکسان نیست.';
        } elseif (strlen($new_password) < 4) {
            $msg_error = 'رمز عبور جدید باید حداقل 4 کاراکتر باشد.';
        } elseif (!is_writable($config_path)) {
            $msg_error = 'فایل config.php قابل ویرایش نیست. مجوزهای دسترسی لینوکس را بررسی کنید.';
        } else {
            $config['admin_username'] = $new_username;
            $config['admin_password'] = password_hash($new_password, PASSWORD_BCRYPT);

            $output_content = "<?php\n// config.php - تنظیمات مرکزی دیتابیس، امنیت و احراز هویت\nreturn [\n";
            foreach ($config as $key => $val) {
                if (is_bool($val)) {
                    $v = $val ? 'true' : 'false';
                } elseif (is_int($val) || is_float($val)) {
                    $v = $val;
                } else {
                    $v = "'" . addcslashes($val, "'\\") . "'";
                }
                $output_content .= "    '{$key}' => {$v},\n";
            }
            $output_content .= "];\n";

            if (@file_put_contents($config_path, $output_content, LOCK_EX) !== false) {
                $_SESSION['cdr_username'] = $new_username;
                $msg_success = 'اطلاعات ورود به سامانه با موفقیت به‌روزرسانی شد.';
            } else {
                $msg_error = 'خطا در ذخیره‌سازی اطلاعات در فایل تنظیمات رخ داد.';
            }
        }
    }
}

// بررسی وضعیت دیتابیس
$db_connected = false;
if (class_exists('mysqli')) {
    $m = @new mysqli($config['db_host'], $config['db_user'], $config['db_pass'], $config['db_name']);
    if (!$m->connect_error) {
        $db_connected = true;
        $m->close();
    }
}

$monitor_dir_exists = is_dir($config['monitor_dir']);
$voicemail_dir_exists = is_dir($config['voicemail_dir']);
$current_user = get_auth_username();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تنظیمات حساب کاربری | ایزابل</title>

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
        .blob-1 { top: -5%; right: 10%; width: 450px; height: 450px; background: radial-gradient(circle, #10b981 0%, rgba(16,185,129,0) 70%); }
        .blob-2 { bottom: 10%; left: 5%; width: 500px; height: 500px; background: radial-gradient(circle, #3b82f6 0%, rgba(59,130,246,0) 70%); }
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

        <!-- هدر هماهنگ و یکپارچه -->
        <?php render_app_header('settings'); ?>

        <?php if (!empty($msg_success)): ?>
            <div class="mb-6 p-4 rounded-3xl bg-emerald-500/10 border border-emerald-500/20 backdrop-blur-md flex items-center gap-3 text-emerald-700 dark:text-emerald-400 text-xs font-bold">
                <svg class="w-5 h-5 text-emerald-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                <span><?php echo htmlspecialchars($msg_success); ?></span>
            </div>
        <?php endif; ?>

        <?php if (!empty($msg_error)): ?>
            <div class="mb-6 p-4 rounded-3xl bg-rose-500/10 border border-rose-500/20 backdrop-blur-md flex items-center gap-3 text-rose-700 dark:text-rose-400 text-xs font-bold">
                <svg class="w-5 h-5 text-rose-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                <span><?php echo htmlspecialchars($msg_error); ?></span>
            </div>
        <?php endif; ?>

        <!-- دو ستون تنظیمات و وضعیت -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

            <!-- کارت فرم تغییر نام کاربری و رمز عبور (2 ستون) -->
            <div class="lg:col-span-2 bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-6 sm:p-8 shadow-sm">

                <div class="flex items-center gap-3 pb-5 mb-6 border-b border-slate-100 dark:border-slate-800">
                    <div class="w-10 h-10 rounded-2xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                    </div>
                    <div>
                        <h2 class="text-base font-extrabold text-slate-800 dark:text-white">تغییر نام کاربری و رمز عبور ورود</h2>
                        <p class="text-xs text-slate-400 mt-0.5">برای افزایش امنیت پنل، رمز پیش‌فرض را حتماً به یک رمز قوی تغییر دهید.</p>
                    </div>
                </div>

                <form method="POST" action="settings.php" class="space-y-5">
                    <input type="hidden" name="csrf_token" value="<?php echo get_csrf_token(); ?>">

                    <!-- نام کاربری فعلی -->
                    <div class="bg-slate-50 dark:bg-slate-800/50 rounded-2xl p-4 border border-slate-200/70 dark:border-slate-700/70 flex items-center justify-between">
                        <span class="text-xs text-slate-500 dark:text-slate-400">نام کاربری فعال در حال حاضر:</span>
                        <span class="text-xs font-bold font-mono text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 px-3 py-1 rounded-xl">
                            <?php echo htmlspecialchars($current_user); ?>
                        </span>
                    </div>

                    <!-- رمز عبور فعلی -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-2">رمز عبور فعلی</label>
                        <input type="password" name="current_password" required placeholder="رمز فعلی جهت تایید هویت"
                               class="w-full h-11 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 rounded-2xl px-4 text-xs font-medium text-slate-800 dark:text-white focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition">
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- نام کاربری جدید -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-2">نام کاربری جدید</label>
                            <input type="text" name="new_username" required value="<?php echo htmlspecialchars($current_user); ?>"
                                   class="w-full h-11 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 rounded-2xl px-4 text-xs font-mono font-medium text-slate-800 dark:text-white focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition">
                        </div>

                        <!-- رمز عبور جدید -->
                        <div>
                            <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-2">رمز عبور جدید</label>
                            <input type="password" name="new_password" required placeholder="حداقل 4 کاراکتر"
                                   class="w-full h-11 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 rounded-2xl px-4 text-xs font-medium text-slate-800 dark:text-white focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition">
                        </div>
                    </div>

                    <!-- تکرار رمز عبور جدید -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-2">تکرار رمز عبور جدید</label>
                        <input type="password" name="confirm_password" required placeholder="تکرار رمز جدید"
                               class="w-full h-11 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 rounded-2xl px-4 text-xs font-medium text-slate-800 dark:text-white focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition">
                    </div>

                    <div class="pt-2 flex justify-end">
                        <button type="submit" class="h-11 px-6 bg-gradient-to-r from-emerald-600 to-teal-500 hover:from-emerald-700 hover:to-teal-600 text-white font-bold rounded-2xl text-xs transition shadow-md shadow-emerald-500/20 flex items-center justify-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <span>ذخیره تغییرات حساب کاربری</span>
                        </button>
                    </div>
                </form>

            </div>

            <!-- کارت وضعیت زیرساخت و ایزابل (1 ستون) -->
            <div class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-6 sm:p-8 shadow-sm flex flex-col justify-between">
                <div>
                    <div class="flex items-center gap-3 pb-5 mb-6 border-b border-slate-100 dark:border-slate-800">
                        <div class="w-10 h-10 rounded-2xl bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        </div>
                        <div>
                            <h2 class="text-base font-extrabold text-slate-800 dark:text-white">وضعیت سرویس‌ها و مسیرها</h2>
                            <p class="text-xs text-slate-400 mt-0.5">بررسی وضعیت ارتباط با استریسک و دیتابیس</p>
                        </div>
                    </div>

                    <div class="space-y-3.5 text-xs">

                        <div class="flex items-center justify-between p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-800">
                            <span class="text-slate-500 dark:text-slate-400 font-medium">پایگاه داده MySQL:</span>
                            <?php if ($db_connected): ?>
                                <span class="inline-flex items-center gap-1.5 font-bold text-emerald-600 dark:text-emerald-400">
                                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span> متصل
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1.5 font-bold text-amber-500">
                                    <span class="w-2 h-2 rounded-full bg-amber-500"></span> حالت شبیه‌ساز (لوکال)
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="flex items-center justify-between p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-800">
                            <span class="text-slate-500 dark:text-slate-400 font-medium">مسیر ضبط مکالمات:</span>
                            <span class="font-mono text-[11px] text-slate-700 dark:text-slate-300 font-bold" dir="ltr">
                                <?php echo htmlspecialchars($config['monitor_dir']); ?>
                            </span>
                        </div>

                        <div class="flex items-center justify-between p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-800">
                            <span class="text-slate-500 dark:text-slate-400 font-medium">پروتکل دریافت فایل ضبط:</span>
                            <span class="font-mono text-[11px] font-bold text-emerald-600 dark:text-emerald-400 bg-emerald-500/10 px-2 py-0.5 rounded-lg" dir="ltr">
                                <?php echo strtoupper(isset($config['storage_driver']) ? $config['storage_driver'] : 'LOCAL'); ?>
                            </span>
                        </div>

                        <div class="flex items-center justify-between p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-800">
                            <span class="text-slate-500 dark:text-slate-400 font-medium">مسیر صندوق صوتی:</span>
                            <span class="font-mono text-[11px] text-slate-700 dark:text-slate-300 font-bold" dir="ltr">
                                <?php echo htmlspecialchars($config['voicemail_dir']); ?>
                            </span>
                        </div>

                        <div class="flex items-center justify-between p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-800">
                            <span class="text-slate-500 dark:text-slate-400 font-medium">نسخه PHP سرور:</span>
                            <span class="font-mono font-bold text-slate-700 dark:text-slate-300"><?php echo PHP_VERSION; ?></span>
                        </div>

                        <div class="flex items-center justify-between p-3 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-800">
                            <span class="text-slate-500 dark:text-slate-400 font-medium">اعتبار نشست کاربری:</span>
                            <span class="font-mono font-bold text-slate-700 dark:text-slate-300">24 ساعت</span>
                        </div>

                    </div>
                </div>

                <div class="pt-6 border-t border-slate-100 dark:border-slate-800 mt-6 text-[11px] text-slate-400 leading-relaxed">
                    تنظیمات تغییر یافته مستقیماً در فایل پیکربندی هسته سیستم ذخیره شده و پس از ری‌استارت وب‌سرور یا نشست بعدی نیز معتبر خواهند ماند.
                </div>
            </div>

        </div>

    </div>

</body>
</html>
