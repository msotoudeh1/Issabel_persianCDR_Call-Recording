<?php
$config = require __DIR__ . '/config.php';
$monitor_dir_real = realpath($config['monitor_dir']);
if (!$monitor_dir_real || !is_dir($monitor_dir_real)) {
    http_response_code(500);
    exit('مسیر ذخیره‌سازی ضبط تنظیم نشده است.');
}
$monitor_dir = rtrim($monitor_dir_real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

if (!isset($_GET['file']) || $_GET['file'] === '') {
    http_response_code(400);
    exit('فایلی مشخص نشده است.');
}

// پاکسازی ورودی (null byte + backslash + path traversal)
$file = str_replace(["\0", '\\', '..'], '', (string)$_GET['file']);
$file = ltrim($file, '/');

$filepath = realpath($monitor_dir . $file);

// بررسی: فایل باید داخل monitor_dir و از نوع فایل باشد (ضد symlink escape)
if ($filepath === false
    || strpos($filepath, $monitor_dir) !== 0
    || !is_file($filepath)) {
    http_response_code(404);
    exit('فایل صوتی یافت نشد.');
}

// Whitelist پسوندها
$ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
$allowed = [
    'mp3'  => 'audio/mpeg',
    'wav'  => 'audio/wav',
    'ogg'  => 'audio/ogg',
    'gsm'  => 'audio/x-gsm',
];
if (!isset($allowed[$ext])) {
    http_response_code(403);
    exit('فرمت فایل مجاز نیست.');
}

$mime = $allowed[$ext];
$size = filesize($filepath);

// ---- دانلود ----
if (isset($_GET['download']) && $_GET['download'] === '1') {
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
    header('Content-Length: ' . $size);
    header('Cache-Control: private, max-age=0');
    readfile($filepath);
    exit;
}

// ---- استریم با پشتیبانی Range (seek در audio) ----
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