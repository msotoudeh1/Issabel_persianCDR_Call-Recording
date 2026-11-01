<?php
// play_audio.php - استریم و دانلود امن فایل‌های صوتی مکالمات و ویس‌میل با کنترل دسترسی
require_once __DIR__ . '/auth.php';
require_auth(); // الزام ورود کاربر برای پخش یا دانلود هرگونه فایل مکالمه

$config = require __DIR__ . '/config.php';

if (!isset($_GET['file']) || $_GET['file'] === '') {
    http_response_code(400);
    exit('فایلی مشخص نشده است.');
}

$type = isset($_GET['type']) ? $_GET['type'] : 'monitor';

// پاکسازی ورودی (null byte + backslash + path traversal)
$file = str_replace(array("\0", '\\', '..'), '', (string)$_GET['file']);
$file = ltrim($file, '/');

// فایل صوتی پیش‌نمایش لوکال
if ($file === 'demo_audio.wav' && file_exists(__DIR__ . '/demo_audio.wav')) {
    $filepath = __DIR__ . '/demo_audio.wav';
} else {
    if ($type === 'voicemail') {
        $base_dir_cfg = isset($config['voicemail_dir']) ? $config['voicemail_dir'] : '/var/spool/asterisk/voicemail/default/';
    } else {
        $base_dir_cfg = isset($config['monitor_dir']) ? $config['monitor_dir'] : '/var/spool/asterisk/monitor/';
    }

    $base_dir_real = realpath($base_dir_cfg);
    if (!$base_dir_real || !is_dir($base_dir_real)) {
        http_response_code(500);
        exit('مسیر ذخیره‌سازی فایل‌های صوتی تنظیم نشده یا در دسترس نیست.');
    }
    $base_dir = rtrim($base_dir_real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $filepath = realpath($base_dir . $file);

    // بررسی: فایل باید داخل base_dir و از نوع فایل باشد (ضد symlink escape)
    if ($filepath === false
        || strpos($filepath, $base_dir) !== 0
        || !is_file($filepath)) {
        http_response_code(404);
        exit('فایل صوتی یافت نشد.');
    }
}

// Whitelist پسوندها
$ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
$allowed = array(
    'mp3'  => 'audio/mpeg',
    'wav'  => 'audio/wav',
    'ogg'  => 'audio/ogg',
    'gsm'  => 'audio/x-gsm',
);
if (!isset($allowed[$ext])) {
    http_response_code(403);
    exit('فرمت فایل مجاز نیست.');
}

$mime = $allowed[$ext];
$size = filesize($filepath);

// ---- دانلود فایل ----
if (isset($_GET['download']) && $_GET['download'] === '1') {
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
    header('Content-Length: ' . $size);
    header('Cache-Control: private, max-age=0');
    readfile($filepath);
    exit;
}

// ---- استریم آنلاین با پشتیبانی HTTP Range ----
header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('Content-Disposition: inline; filename="' . basename($filepath) . '"');
header('Cache-Control: private, max-age=3600');

if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    $start = (int)$m[1];
    $end   = $m[2] !== '' ? (int)$m[2] : $size - 1;

    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        exit;
    }

    header('HTTP/1.1 206 Partial Content');
    header("Content-Range: bytes $start-$end/$size");
    header('Content-Length: ' . ($end - $start + 1));

    $fp = fopen($filepath, 'rb');
    fseek($fp, $start);
    $remaining = $end - $start + 1;
    while ($remaining > 0 && !feof($fp)) {
        $chunk = fread($fp, min(8192, $remaining));
        if ($chunk === false) break;
        echo $chunk;
        $remaining -= strlen($chunk);
    }
    fclose($fp);
    exit;
}

header('Content-Length: ' . $size);
readfile($filepath);
exit;
