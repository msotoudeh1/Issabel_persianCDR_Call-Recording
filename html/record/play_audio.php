<?php
$monitor_dir = '/var/spool/asterisk/monitor/';

if (!isset($_GET['file'])) {
    http_response_code(400);
    exit('فایلی مشخص نشده است.');
}

// جلوگیری از نفوذ دایرکتوری تراورسوال
$file = str_replace(array('..', '\\'), '', $_GET['file']);
$filepath = $monitor_dir . $file;

if (!file_exists($filepath)) {
    http_response_code(404);
    exit('فایل صوتی یافت نشد.');
}

$mime_type = 'audio/mpeg';
$ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
if ($ext == 'wav') {
    $mime_type = 'audio/wav';
} elseif ($ext == 'ogg') {
    $mime_type = 'audio/ogg';
}

header('Content-Type: ' . $mime_type);
header('Content-Length: ' . filesize($filepath));

if (isset($_GET['download']) && $_GET['download'] == '1') {
    header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
} else {
    header('Content-Disposition: inline; filename="' . basename($filepath) . '"');
}

readfile($filepath);
exit;
?>