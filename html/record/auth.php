<?php
// auth.php - ماژول مدیریت احراز هویت، سشن و کنترل دسترسی

$config = require __DIR__ . '/config.php';

$session_name = isset($config['session_name']) ? $config['session_name'] : 'issabel_cdr_session';
if (session_status() === PHP_SESSION_NONE) {
    session_name($session_name);
    // تنظیم کوکی سشن امن
    ini_set('session.cookie_httponly', 1);
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', 1);
    }
    session_start();
}

/**
 * بررسی لاگین بودن کاربر (چه از طریق ورود اختصاصی و چه از طریق نشست ایزابل)
 */
function is_authenticated() {
    global $config;
    if (empty($config['auth_enabled'])) {
        return true;
    }

    // ۱. بررسی نشست اختصاصی پنل
    if (!empty($_SESSION['cdr_logged_in']) && $_SESSION['cdr_logged_in'] === true) {
        $timeout = isset($config['session_timeout']) ? (int)$config['session_timeout'] : 86400;
        if (isset($_SESSION['cdr_last_activity']) && (time() - $_SESSION['cdr_last_activity'] > $timeout)) {
            destroy_auth_session();
            return false;
        }
        $_SESSION['cdr_last_activity'] = time();
        return true;
    }

    // ۲. بررسی نشست خود ایزابل (در صورتی که کاربر قبلاً در ایزابل لاگین کرده باشد)
    if (!empty($_SESSION['issabel_user'])) {
        return true;
    }

    return false;
}

/**
 * دریافت نام کاربری فرد وارد شده
 */
function get_auth_username() {
    if (!empty($_SESSION['cdr_username'])) {
        return $_SESSION['cdr_username'];
    }
    if (!empty($_SESSION['issabel_user'])) {
        return $_SESSION['issabel_user'];
    }
    return 'مدیر سامانه';
}

/**
 * الزام احراز هویت - در صورت عدم لاگین هدایت به صفحه ورود
 */
function require_auth() {
    if (!is_authenticated()) {
        $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                   || (isset($_GET['ajax']) && $_GET['ajax'] === '1');

        if ($is_ajax) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array('error' => 'unauthorized', 'message' => 'لطفاً وارد شوید.'));
            exit;
        }

        // اگر فایل صوتی باشد
        if (basename($_SERVER['PHP_SELF']) === 'play_audio.php') {
            http_response_code(401);
            exit('دسترسی غیرمجاز: لطفاً ابتدا وارد سامانه شوید.');
        }

        $redirect = urlencode($_SERVER['REQUEST_URI']);
        header('Location: login.php?redirect=' . $redirect);
        exit;
    }
}

/**
 * نابودی و پاکسازی سشن
 */
function destroy_auth_session() {
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

/**
 * توکن CSRF
 */
function get_csrf_token() {
    if (empty($_SESSION['cdr_csrf_token'])) {
        $_SESSION['cdr_csrf_token'] = bin2hex(function_exists('random_bytes') ? random_bytes(32) : openssl_random_pseudo_bytes(32));
    }
    return $_SESSION['cdr_csrf_token'];
}

function verify_csrf_token($token) {
    if (empty($_SESSION['cdr_csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['cdr_csrf_token'], $token);
}
