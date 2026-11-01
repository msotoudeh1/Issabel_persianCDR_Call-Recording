<?php
// login.php - صفحه ورود امن و مدرن به سامانه
require_once __DIR__ . '/auth.php';

$config = require __DIR__ . '/config.php';
$app_title = isset($config['app_title']) ? $config['app_title'] : 'سامانه هوشمند مانیتورینگ ایزابل';

// در صورتی که کاربر از قبل لاگین است، مستقیماً به داشبورد هدایت شود
if (is_authenticated()) {
    header('Location: monitoring.php');
    exit;
}

$error = '';
$success = '';

if (isset($_GET['logged_out'])) {
    $success = 'شما با موفقیت از سامانه خارج شدید.';
}

$redirect = isset($_GET['redirect']) ? $_GET['redirect'] : 'monitoring.php';
// جلوگیری از Open Redirect
if (strpos($redirect, 'http://') === 0 || strpos($redirect, 'https://') === 0 || strpos($redirect, '//') === 0) {
    $redirect = 'monitoring.php';
}

// بررسی فرم ارسال شده
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim(isset($_POST['username']) ? $_POST['username'] : '');
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $token    = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';

    if (!verify_csrf_token($token)) {
        $error = 'خطای امنیتی: نشست شما منقضی شده است. لطفاً صفحه را مجدداً بارگذاری کنید.';
    } elseif (empty($username) || empty($password)) {
        $error = 'لطفاً نام کاربری و رمز عبور را وارد نمایید.';
    } else {
        // جلوگیری از Brute Force
        if (!isset($_SESSION['login_attempts'])) {
            $_SESSION['login_attempts'] = 0;
            $_SESSION['last_attempt_time'] = time();
        }

        if ($_SESSION['login_attempts'] >= 5 && (time() - $_SESSION['last_attempt_time'] < 60)) {
            $wait = 60 - (time() - $_SESSION['last_attempt_time']);
            $error = "تعداد دفعات ورود ناموفق بیش از حد مجاز است. لطفاً {$wait} ثانیه صبر کنید.";
        } else {
            $valid_user = isset($config['admin_username']) ? $config['admin_username'] : 'admin';
            $valid_pass = isset($config['admin_password']) ? $config['admin_password'] : 'admin@123';

            // مقایسه امن رمز عبور (پشتیبانی از متن خام یا هش bcrypt)
            $pass_valid = false;
            $pass_info = password_get_info($valid_pass);
            if (!empty($pass_info['algo'])) {
                $pass_valid = password_verify($password, $valid_pass);
            } else {
                $pass_valid = hash_equals((string)$valid_pass, (string)$password);
            }

            if (hash_equals((string)$valid_user, (string)$username) && $pass_valid) {
                // ریست تلاش‌های ناموفق
                $_SESSION['login_attempts'] = 0;
                // بازتولید شناسه سشن برای جلوگیری از Session Fixation
                session_regenerate_id(true);

                $_SESSION['cdr_logged_in'] = true;
                $_SESSION['cdr_username'] = $username;
                $_SESSION['cdr_last_activity'] = time();

                header('Location: ' . $redirect);
                exit;
            } else {
                $_SESSION['login_attempts']++;
                $_SESSION['last_attempt_time'] = time();
                $error = 'نام کاربری یا رمز عبور اشتباه است.';
            }
        }
    }
}

$csrf_token = get_csrf_token();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ورود به سامانه | <?php echo htmlspecialchars($app_title); ?></title>

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
            opacity: 0.55;
            will-change: transform;
        }
        html.dark .blob { opacity: 0.3; }
        .blob-1 { top: -10%; right: -5%; width: 500px; height: 500px; background: radial-gradient(circle, #10b981 0%, rgba(16,185,129,0) 70%); animation: float1 18s ease-in-out infinite alternate; }
        .blob-2 { bottom: -10%; left: -5%; width: 550px; height: 550px; background: radial-gradient(circle, #06b6d4 0%, rgba(6,182,212,0) 70%); animation: float2 22s ease-in-out infinite alternate; }
        .blob-3 { top: 35%; left: 30%; width: 400px; height: 400px; background: radial-gradient(circle, #6366f1 0%, rgba(99,102,241,0) 70%); animation: float3 20s ease-in-out infinite alternate; }

        @keyframes float1 { 0% { transform: translate(0, 0) scale(1); } 100% { transform: translate(-80px, 60px) scale(1.15); } }
        @keyframes float2 { 0% { transform: translate(0, 0) scale(1); } 100% { transform: translate(70px, -50px) scale(1.2); } }
        @keyframes float3 { 0% { transform: translate(0, 0) scale(1); } 100% { transform: translate(-50px, -60px) scale(0.9); } }
    </style>
</head>
<body class="bg-slate-50 dark:bg-slate-950 text-slate-800 dark:text-slate-100 min-h-screen flex items-center justify-center p-4 relative overflow-x-hidden selection:bg-emerald-500 selection:text-white transition-colors duration-300">

    <!-- المان‌های نوری متحرک پس‌زمینه -->
    <div class="bg-blobs" aria-hidden="true">
        <div class="blob blob-1"></div>
        <div class="blob blob-2"></div>
        <div class="blob blob-3"></div>
    </div>

    <!-- دکمه سوئیچ تم در گوشه بالا -->
    <div class="fixed top-5 left-5 z-20">
        <button type="button" id="theme-toggle" class="w-11 h-11 rounded-2xl bg-white/80 dark:bg-slate-900/80 backdrop-blur-md border border-slate-200/80 dark:border-slate-800/80 shadow-lg flex items-center justify-center text-slate-600 dark:text-slate-300 hover:text-emerald-600 dark:hover:text-emerald-400 transition-all hover:scale-105" title="تغییر تم">
            <svg id="icon-sun" class="w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            <svg id="icon-moon" class="w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
        </button>
    </div>

    <!-- بدنه اصلی فرم کارت لاگین -->
    <div class="w-full max-w-md relative z-10">
        <div class="bg-white/85 dark:bg-slate-900/85 backdrop-blur-2xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-8 sm:p-10 shadow-2xl relative overflow-hidden transition-all duration-300">

            <!-- هاله نورانی درون کارت -->
            <div class="absolute -top-24 -right-24 w-48 h-48 bg-emerald-500/15 dark:bg-emerald-500/10 rounded-full blur-3xl pointer-events-none"></div>
            <div class="absolute -bottom-24 -left-24 w-48 h-48 bg-cyan-500/15 dark:bg-cyan-500/10 rounded-full blur-3xl pointer-events-none"></div>

            <!-- هدر کارت / لوگو -->
            <div class="text-center relative mb-8">
                <div class="inline-flex items-center justify-center w-20 h-20 rounded-2xl bg-gradient-to-tr from-emerald-500 to-teal-400 text-white shadow-xl shadow-emerald-500/25 mb-4 transform hover:scale-105 transition-transform duration-300">
                    <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                    </svg>
                </div>
                <h1 class="text-2xl font-black text-slate-800 dark:text-white tracking-tight">ورود به سامانه</h1>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-2 font-medium">مانیتورینگ، آنالیز و شنود مکالمات ایزابل</p>
            </div>

            <!-- پیام‌های موفقیت یا خطا -->
            <?php if (!empty($error)): ?>
                <div class="mb-6 p-4 rounded-2xl bg-rose-50 dark:bg-rose-950/40 border border-rose-200 dark:border-rose-900/60 text-rose-600 dark:text-rose-400 text-sm flex items-center gap-3">
                    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="mb-6 p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-900/60 text-emerald-600 dark:text-emerald-400 text-sm flex items-center gap-3">
                    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>

            <!-- فرم ورود -->
            <form method="POST" action="" class="space-y-5">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                <!-- فیلد نام کاربری -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-2">نام کاربری</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 right-0 pr-4 flex items-center pointer-events-none text-slate-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        </div>
                        <input type="text" name="username" required autofocus autocomplete="username"
                               value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                               placeholder="مثلاً admin"
                               class="w-full bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 rounded-2xl pr-11 pl-4 py-3 text-sm text-slate-800 dark:text-white placeholder-slate-400 focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all">
                    </div>
                </div>

                <!-- فیلد کلمه عبور -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-2">کلمه عبور</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 right-0 pr-4 flex items-center pointer-events-none text-slate-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        </div>
                        <input type="password" name="password" id="password" required autocomplete="current-password"
                               placeholder="••••••••"
                               class="w-full bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 rounded-2xl pr-11 pl-12 py-3 text-sm text-slate-800 dark:text-white placeholder-slate-400 focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all">
                        <button type="button" id="toggle-password" class="absolute inset-y-0 left-0 pl-4 flex items-center text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors" title="نمایش/پنهان‌سازی رمز">
                            <svg id="eye-open" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            <svg id="eye-closed" class="w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18"/></svg>
                        </button>
                    </div>
                </div>

                <!-- دکمه ورود -->
                <button type="submit"
                        class="w-full mt-2 bg-gradient-to-r from-emerald-600 via-teal-600 to-emerald-500 hover:from-emerald-700 hover:to-teal-600 text-white font-bold py-3.5 px-4 rounded-2xl text-sm shadow-lg shadow-emerald-500/25 hover:shadow-emerald-500/40 transform hover:-translate-y-0.5 active:translate-y-0 transition-all duration-200 flex items-center justify-center gap-2">
                    <span>ورود به حساب کاربری</span>
                    <svg class="w-4 h-4 rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                </button>
            </form>

            <div class="mt-8 pt-6 border-t border-slate-100 dark:border-slate-800 text-center">
                <p class="text-[11px] text-slate-400 dark:text-slate-500">
                    سیستم مانیتورینگ ایزابل PBX &bull; نسخه اختصاصی
                </p>
            </div>
        </div>
    </div>

    <script>
        // سوئیچ نمایش/مخفی کردن کلمه عبور
        const toggleBtn = document.getElementById('toggle-password');
        const passInput = document.getElementById('password');
        const eyeOpen   = document.getElementById('eye-open');
        const eyeClosed = document.getElementById('eye-closed');

        toggleBtn.addEventListener('click', () => {
            if (passInput.type === 'password') {
                passInput.type = 'text';
                eyeOpen.classList.add('hidden');
                eyeClosed.classList.remove('hidden');
            } else {
                passInput.type = 'password';
                eyeOpen.classList.remove('hidden');
                eyeClosed.classList.add('hidden');
            }
        });

        // مدیریت تغییر تم
        const themeBtn  = document.getElementById('theme-toggle');
        const iconSun   = document.getElementById('icon-sun');
        const iconMoon  = document.getElementById('icon-moon');

        function updateThemeIcons() {
            if (document.documentElement.classList.contains('dark')) {
                iconSun.classList.remove('hidden');
                iconMoon.classList.add('hidden');
            } else {
                iconSun.classList.add('hidden');
                iconMoon.classList.remove('hidden');
            }
        }
        updateThemeIcons();

        themeBtn.addEventListener('click', () => {
            if (document.documentElement.classList.contains('dark')) {
                document.documentElement.classList.remove('dark');
                localStorage.setItem('theme', 'light');
            } else {
                document.documentElement.classList.add('dark');
                localStorage.setItem('theme', 'dark');
            }
            updateThemeIcons();
        });
    </script>
</body>
</html>
