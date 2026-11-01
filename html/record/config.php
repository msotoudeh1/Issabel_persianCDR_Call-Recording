<?php
// config.php - تنظیمات مرکزی دیتابیس، امنیت و احراز هویت
return [
    'db_host' => '127.0.0.1',
    'db_name' => 'asteriskcdrdb',
    'db_user' => 'asteriskuser',
    'db_pass' => 'database_password',
    'monitor_dir' => '/var/spool/asterisk/monitor/',
    'voicemail_dir' => '/var/spool/asterisk/voicemail/default/',
    'auth_enabled' => true,
    'admin_username' => 'hemn',
    'admin_password' => '$2y$12$TOCW5EUZsXNgmYUPM4GoiOPQcm7uQrGwp72rPAEunjJNgwJsaHoJ6',
    'session_name' => 'issabel_cdr_session',
    'session_timeout' => 86400,
    'app_title' => 'سامانه هوشمند مانیتورینگ و گزارشات ایزابل',
];
