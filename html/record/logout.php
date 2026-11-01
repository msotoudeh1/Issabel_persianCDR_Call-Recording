<?php
// logout.php - خروج امن از سامانه
require_once __DIR__ . '/auth.php';

destroy_auth_session();

header('Location: login.php?logged_out=1');
exit;
