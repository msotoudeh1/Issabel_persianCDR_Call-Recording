<?php
// play_audio.php - استریم و دانلود امن فایل‌های صوتی مکالمات و ویس‌میل با کنترل دسترسی و تبدیل خودکار به PCM WAV
require_once __DIR__ . '/auth.php';
require_auth(); // الزام ورود کاربر برای پخش یا دانلود هرگونه فایل مکالمه

// آزادسازی قفل سشن برای جلوگیری از مسدود شدن استریم همزمان صوتی در مرورگر
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

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
        if (file_exists(__DIR__ . '/demo_audio.wav')) {
            $filepath = __DIR__ . '/demo_audio.wav';
        } else {
            http_response_code(500);
            exit('مسیر ذخیره‌سازی فایل‌های صوتی تنظیم نشده یا در دسترس نیست.');
        }
    } else {
        $base_dir = rtrim($base_dir_real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $filepath = realpath($base_dir . $file);

        // اگر فایل با پسوند مشخص‌شده پیدا نشد، بررسی نسخه‌های با پسوند دیگر (مانند WAV، wav، gsm)
        if ($filepath === false || !is_file($filepath)) {
            $path_without_ext = preg_replace('/\.[a-zA-Z0-9]+$/', '', $base_dir . $file);
            foreach (array('.wav', '.WAV', '.gsm', '.mp3', '.ogg') as $alt_ext) {
                if (is_file($path_without_ext . $alt_ext)) {
                    $filepath = realpath($path_without_ext . $alt_ext);
                    break;
                }
            }
        }

        // بررسی: فایل باید داخل base_dir و از نوع فایل باشد (ضد symlink escape)
        if ($filepath === false
            || strpos($filepath, $base_dir) !== 0
            || !is_file($filepath)) {
            if (file_exists(__DIR__ . '/demo_audio.wav')) {
                $filepath = __DIR__ . '/demo_audio.wav';
            } else {
                http_response_code(404);
                exit('فایل صوتی یافت نشد.');
            }
        }
    }
}

/**
 * تبدیل هوشمند فایل‌های صوتی استریسک (مانند WAV49 / GSM 6.10) به فرمت استاندارد 16-bit Linear PCM WAV
 * مرورگرهای وب از کدک GSM 6.10 یا فشرده‌سازی فرمت ۴۹ پشتیبانی نمی‌کنند و خطای دیکد می‌دهند؛
 * این تابع با استفاده از SoX یا FFmpeg فایل را در اولین درخواست به PCM تبدیل و کش می‌کند.
 */
function transcode_audio_for_browser($source_file) {
    if (!file_exists($source_file) || !is_file($source_file)) {
        return $source_file;
    }

    $ext = strtolower(pathinfo($source_file, PATHINFO_EXTENSION));
    $needs_transcoding = false;

    if ($ext === 'gsm') {
        $needs_transcoding = true;
    } elseif ($ext === 'wav') {
        // خواندن هدر RIFF جهت تشخیص کد فشرده‌سازی
        $fp = @fopen($source_file, 'rb');
        if ($fp) {
            $hdr = fread($fp, 24);
            fclose($fp);
            if (strlen($hdr) >= 24 && substr($hdr, 0, 4) === 'RIFF' && substr($hdr, 8, 4) === 'WAVE' && substr($hdr, 12, 4) === 'fmt ') {
                $tag = unpack('vformat', substr($hdr, 20, 2));
                $format_code = isset($tag['format']) ? (int)$tag['format'] : 0;
                // فرمت ۱ = PCM خطی (کامپیوتر و مرورگر)
                // فرمت ۳ = IEEE Float
                // سایر مقادیر نظیر ۴۹ (0x0031 = GSM 6.10 / WAV49) نیازمند ترنسکود هستند
                if ($format_code !== 1 && $format_code !== 3) {
                    $needs_transcoding = true;
                }
            }
        }
    }

    if (!$needs_transcoding) {
        return $source_file;
    }

    // پوشه کش تبدیل فایل‌های صوتی
    $cache_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'issabel_audio_cache';
    if (!is_dir($cache_dir)) {
        @mkdir($cache_dir, 0777, true);
    }

    $cache_file = $cache_dir . DIRECTORY_SEPARATOR . md5($source_file . '|' . filemtime($source_file)) . '.wav';

    // استفاده فوری از کش در صورت وجود
    if (file_exists($cache_file) && filesize($cache_file) > 44 && filemtime($cache_file) >= filemtime($source_file)) {
        return $cache_file;
    }

    $src_arg = escapeshellarg($source_file);
    $out_arg = escapeshellarg($cache_file);

    // ۱. بررسی SoX (استاندارد در توزیع‌های CentOS/AlmaLinux و سرورهای Issabel PBX)
    $sox_bins = array('/usr/bin/sox', '/usr/local/bin/sox', 'sox');
    foreach ($sox_bins as $bin) {
        $cmd = "$bin $src_arg -t wav -r 8000 -c 1 -e signed-integer -b 16 $out_arg 2>&1";
        @exec($cmd, $out, $ret);
        if ($ret === 0 && file_exists($cache_file) && filesize($cache_file) > 44) {
            return $cache_file;
        }
    }

    // ۲. بررسی FFmpeg
    $ffmpeg_bins = array('/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', 'ffmpeg');
    foreach ($ffmpeg_bins as $bin) {
        $cmd = "$bin -y -i $src_arg -acodec pcm_s16le -ar 8000 -ac 1 $out_arg 2>&1";
        @exec($cmd, $out, $ret);
        if ($ret === 0 && file_exists($cache_file) && filesize($cache_file) > 44) {
            return $cache_file;
        }
    }

    return $source_file;
}

// انجام ترنسکود هوشمند در صورت لزوم
$serve_file = transcode_audio_for_browser($filepath);

$ext = strtolower(pathinfo($serve_file, PATHINFO_EXTENSION));
$allowed = array(
    'mp3' => 'audio/mpeg',
    'wav' => 'audio/wav',
    'ogg' => 'audio/ogg',
    'gsm' => 'audio/x-gsm',
);
$mime = isset($allowed[$ext]) ? $allowed[$ext] : 'audio/wav';
$size = filesize($serve_file);

// ---- دانلود فایل ----
if (isset($_GET['download']) && $_GET['download'] === '1') {
    $dl_filename = pathinfo($filepath, PATHINFO_FILENAME) . '.wav';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $dl_filename . '"');
    header('Content-Length: ' . $size);
    header('Cache-Control: private, max-age=0');
    readfile($serve_file);
    exit;
}

// ---- استریم آنلاین با پشتیبانی HTTP Range ----
header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('Content-Disposition: inline; filename="' . basename($filepath) . '"');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');

if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    $start = (int)$m[1];
    $end   = $m[2] !== '' ? (int)$m[2] : $size - 1;

    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        exit;
    }

    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
    header('Content-Length: ' . ($end - $start + 1));

    $fp = fopen($serve_file, 'rb');
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
readfile($serve_file);
exit;
