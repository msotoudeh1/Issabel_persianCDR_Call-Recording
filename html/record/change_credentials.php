<?php
// change_credentials.php - پردازش امن تغییر نام کاربری و رمز عبور پنل
require_once __DIR__ . '/auth.php';
require_auth();

$config_path = __DIR__ . '/config.php';
$config = require $config_path;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('success' => false, 'message' => 'متد درخواست غیرمجاز است.'));
    exit;
}

$token            = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
$current_password = isset($_POST['current_password']) ? $_POST['current_password'] : '';
$new_username     = trim(isset($_POST['new_username']) ? $_POST['new_username'] : '');
$new_password     = isset($_POST['new_password']) ? $_POST['new_password'] : '';
$confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';

// بررسی توکن CSRF
if (!verify_csrf_token($token)) {
    echo json_encode(array('success' => false, 'message' => 'نشست امنیتی شما منقضی شده است. لطفاً صفحه را تازه‌سازی کنید.'));
    exit;
}

// اعتبارسنجی فیلدهای خالی
if (empty($current_password) || empty($new_username) || empty($new_password) || empty($confirm_password)) {
    echo json_encode(array('success' => false, 'message' => 'لطفاً تمامی فیلدها را با دقت تکمیل نمایید.'));
    exit;
}

// تایید رمز عبور فعلی
$valid_pass = isset($config['admin_password']) ? $config['admin_password'] : 'admin@123';
$pass_valid = false;
$pass_info = password_get_info($valid_pass);
if (!empty($pass_info['algo'])) {
    $pass_valid = password_verify($current_password, $valid_pass);
} else {
    $pass_valid = hash_equals((string)$valid_pass, (string)$current_password);
}

if (!$pass_valid) {
    echo json_encode(array('success' => false, 'message' => 'رمز عبور فعلی وارد شده نادرست است.'));
    exit;
}

// اعتبارسنجی نام کاربری جدید
if (mb_strlen($new_username, 'UTF-8') < 3 || mb_strlen($new_username, 'UTF-8') > 50) {
    echo json_encode(array('success' => false, 'message' => 'نام کاربری باید بین 3 تا 50 کاراکتر باشد.'));
    exit;
}

// بررسی تطابق رمز عبور جدید
if ($new_password !== $confirm_password) {
    echo json_encode(array('success' => false, 'message' => 'رمز عبور جدید با تکرار آن مطابقت ندارد.'));
    exit;
}

if (strlen($new_password) < 4) {
    echo json_encode(array('success' => false, 'message' => 'رمز عبور جدید باید حداقل 4 کاراکتر باشد.'));
    exit;
}

// بررسی دسترسی نوشتن به فایل config.php
if (!is_writable($config_path)) {
    echo json_encode(array('success' => false, 'message' => 'فایل تنظیمات config.php قابل ویرایش نیست. لطفاً مجوز نوشتن (Write Permission) فایل را بررسی کنید.'));
    exit;
}

// به‌روزرسانی تنظیمات و هش رمز عبور
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
    echo json_encode(array(
        'success'      => true,
        'message'      => 'نام کاربری و رمز عبور با موفقیت به‌روزرسانی شدند.',
        'new_username' => $new_username
    ));
    exit;
}

echo json_encode(array('success' => false, 'message' => 'خطای سیستمی در ذخیره‌سازی فایل تنظیمات رخ داد.'));
