<?php
// config.php - تنظیمات مرکزی دیتابیس، امنیت و احراز هویت
return [
    'db_host' => '127.0.0.1',
    'db_name' => 'asteriskcdrdb',
    'db_user' => 'asteriskuser',
    'db_pass' => 'database_password',
    'monitor_dir' => '/var/spool/asterisk/monitor/',
    'voicemail_dir' => '/var/spool/asterisk/voicemail/default/',

    // نحوه دسترسی به فایل‌های ضبط: 'local' (اجرا روی سرور ایزابل) یا 'sftp' (ارتباط امن ریموت به PBX)
    'storage_driver' => 'local',
    'sftp' => [
        'host' => '127.0.0.1',
        'port' => 22,
        'username' => 'root',
        'password' => '',
        'private_key' => '',
        'remote_monitor_dir' => '/var/spool/asterisk/monitor/',
    ],
    'auth_enabled' => true,
    'admin_username' => 'admin',
    'admin_password' => 'admin@123',
    'session_name' => 'issabel_cdr_session',
    'session_timeout' => 86400,
    'app_title' => 'سامانه هوشمند مانیتورینگ و گزارشات ایزابل',
];
