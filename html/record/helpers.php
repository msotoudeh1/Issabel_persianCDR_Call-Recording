<?php
// helpers.php - توابع کمکی مشترک، تقویم جلالی، آنالیز تماس‌ها، ویس‌میل و کامپوننت‌های رابط کاربری

date_default_timezone_set('Asia/Tehran');

/**
 * نمایش اعداد: طبق درخواست اکید کاربر، تمام اعداد داخل پنل به صورت لاتین/انگلیسی نمایش داده می‌شوند.
 */
function to_persian_num($string) {
    // بازگرداندن بدون تغییر ارقام جهت نمایش ارقام لاتین (1, 2, 3 ...)
    return (string)$string;
}

/**
 * تبدیل تاریخ میلادی به شمسی (با ارقام لاتین)
 */
function gregorian_to_jalali($g_y, $g_m, $g_d) {
    $gdm = array(31,28,31,30,31,30,31,31,30,31,30,31);
    $jdm = array(31,31,31,31,31,31,30,30,30,30,30,29);
    $gy = $g_y - 1600; $gm = $g_m - 1; $gd = $g_d - 1;
    $g_day_no = 365*$gy + (int)(($gy+3)/4) - (int)(($gy+99)/100) + (int)(($gy+399)/400);
    for ($i = 0; $i < $gm; ++$i) $g_day_no += $gdm[$i];
    if ($gm > 1 && (($gy%4==0 && gy%100!=0) || $gy%400==0)) $g_day_no++;
    $g_day_no += $gd;
    $j_day_no = $g_day_no - 79;
    $j_np = (int)($j_day_no/12053); $j_day_no %= 12053;
    $jy = 979 + 33*$j_np + 4*(int)($j_day_no/1461);
    $j_day_no %= 1461;
    if ($j_day_no >= 366) {
        $jy += (int)(($j_day_no-1)/365);
        $j_day_no = ($j_day_no-1) % 365;
    }
    for ($i = 0; $i < 11 && $j_day_no >= $jdm[$i]; ++$i) $j_day_no -= $jdm[$i];
    return sprintf("%04d/%02d/%02d", $jy, $i+1, $j_day_no+1);
}

/**
 * تبدیل تاریخ شمسی به میلادی
 */
function jalali_to_gregorian($j_y, $j_m, $j_d) {
    $gdm = array(31,28,31,30,31,30,31,31,30,31,30,31);
    $jdm = array(31,31,31,31,31,31,30,30,30,30,30,29);
    $jy = $j_y - 979; $jm = $j_m - 1; $jd = $j_d - 1;
    $j_day_no = 365*$jy + (int)($jy/33)*8 + (int)(($jy%33+3)/4);
    for ($i = 0; $i < $jm; ++$i) $j_day_no += $jdm[$i];
    $j_day_no += $jd;
    $g_day_no = $j_day_no + 79;
    $gy = 1600 + 400*(int)($g_day_no/146097); $g_day_no %= 146097;
    $leap = true;
    if ($g_day_no >= 36525) {
        $g_day_no--;
        $gy += 100*(int)($g_day_no/36524); $g_day_no %= 36524;
        if ($g_day_no >= 365) $g_day_no++; else $leap = false;
    }
    $gy += 4*(int)($g_day_no/1461); $g_day_no %= 1461;
    if ($g_day_no >= 366) {
        $leap = false; $g_day_no--;
        $gy += (int)($g_day_no/365); $g_day_no %= 365;
    }
    for ($i = 0; $i < 12; $i++) {
        $dim = $gdm[$i];
        if ($i == 1 && $leap) $dim++;
        if ($g_day_no < $dim) break;
        $g_day_no -= $dim;
    }
    return sprintf("%04d-%02d-%02d", $gy, $i+1, $g_day_no+1);
}

/**
 * فرمت‌بندی ثانیه به فرمت ساعت:دقیقه:ثانیه (با ارقام لاتین)
 */
function format_duration($seconds) {
    $s = max(0, (int)$seconds);
    return sprintf("%02d:%02d:%02d", (int)($s/3600), (int)(($s%3600)/60), $s%60);
}

/**
 * استانداردسازی رشته تاریخ شمسی
 */
function normalize_jalali_date($str) {
    $str = str_replace(
        array('۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','/'),
        array('0','1','2','3','4','5','6','7','8','9','-'),
        trim((string)$str)
    );
    $parts = preg_split('/[-]/', $str);
    if (count($parts) !== 3) return null;
    $y = (int)$parts[0];
    $m = (int)$parts[1];
    $d = (int)$parts[2];
    if ($y < 1300 || $y > 1500 || $m < 1 || $m > 12 || $d < 1 || $d > 31) return null;
    return array($y, $m, $d);
}

/**
 * تبدیل کلید بازه زمانی (Period) به تاریخ شمسی
 */
function get_dates_by_period($period) {
    $today_dt = new DateTime();
    $pad = function($n) { return String($n); };

    switch ($period) {
        case 'today':
            $y = (int)$today_dt->format('Y'); $m = (int)$today_dt->format('m'); $d = (int)$today_dt->format('d');
            $sh = gregorian_to_jalali($y, $m, $d);
            return array('start' => $sh, 'end' => $sh);

        case 'yesterday':
            $yd = (new DateTime())->modify('-1 day');
            $y = (int)$yd->format('Y'); $m = (int)$yd->format('m'); $d = (int)$yd->format('d');
            $sh = gregorian_to_jalali($y, $m, $d);
            return array('start' => $sh, 'end' => $sh);

        case 'week':
        default:
            $wd = (new DateTime())->modify('-6 days');
            $y1 = (int)$wd->format('Y'); $m1 = (int)$wd->format('m'); $d1 = (int)$wd->format('d');
            $y2 = (int)$today_dt->format('Y'); $m2 = (int)$today_dt->format('m'); $d2 = (int)$today_dt->format('d');
            return array(
                'start' => gregorian_to_jalali($y1, $m1, $d1),
                'end'   => gregorian_to_jalali($y2, $m2, $d2),
            );

        case 'month':
            $md = (new DateTime())->modify('-29 days');
            $y1 = (int)$md->format('Y'); $m1 = (int)$md->format('m'); $d1 = (int)$md->format('d');
            $y2 = (int)$today_dt->format('Y'); $m2 = (int)$today_dt->format('m'); $d2 = (int)$today_dt->format('d');
            return array(
                'start' => gregorian_to_jalali($y1, $m1, $d1),
                'end'   => gregorian_to_jalali($y2, $m2, $d2),
            );

        case 'all':
            return array('start' => '', 'end' => '');
    }
}

/**
 * تشخیص ترانک بودن کانال تماس
 */
function is_trunk_channel($channel, $extensions_list = array()) {
    if (empty($channel)) return false;
    if (preg_match('#^DAHDI/#i', $channel)) return true;
    if (preg_match('#^Local/#i', $channel)) return false;
    if (preg_match('#^(?:SIP|PJSIP|IAX2|SCCP)/([^-@;]+)#i', $channel, $m)) {
        $peer = $m[1];
        if (ctype_digit($peer)) {
            return !in_array($peer, $extensions_list, true);
        }
        return true;
    }
    return false;
}

function extract_extension_from_channel($channel) {
    if (empty($channel)) return '';
    if (preg_match('#^(?:SIP|PJSIP|IAX2|SCCP|Local)/(\d+)(?:[-;@]|$)#i', $channel, $m)) {
        return $m[1];
    }
    return '';
}

function find_involved_extension($channel, $dstchannel) {
    $ext = extract_extension_from_channel($dstchannel);
    if ($ext !== '') return $ext;
    return extract_extension_from_channel($channel);
}

function classify_number($number, $extensions_list = array()) {
    $raw = trim((string)$number);
    if ($raw === '') return 'unknown';
    $clean = preg_replace('/[^0-9]/', '', $raw);
    if ($clean === '') return 'unknown';

    if (in_array($raw, $extensions_list, true) || in_array($clean, $extensions_list, true)) {
        return 'extension';
    }

    $len = strlen($clean);
    if ($len >= 3 && $len <= 5) return 'extension';
    if (substr($clean, 0, 2) === '00') return 'international';
    if ($len === 11 && substr($clean, 0, 2) === '09') return 'mobile';
    if ($len === 10 && substr($clean, 0, 1) === '9')  return 'mobile';
    if ($len === 11 && substr($clean, 0, 1) === '0')  return 'city';
    if ($len === 10 || $len === 8) return 'city';

    return 'unknown';
}

function classify_call_direction($channel, $dstchannel, $src, $dst, $dcontext = '', $extensions_list = array()) {
    $ch_trunk  = is_trunk_channel($channel, $extensions_list);
    $dst_trunk = is_trunk_channel($dstchannel, $extensions_list);

    if ($ch_trunk && $dst_trunk)  return 'transit';
    if ($ch_trunk && !$dst_trunk) return 'inbound';
    if (!$ch_trunk && $dst_trunk) return 'outbound';

    $src_t = classify_number($src, $extensions_list);
    $dst_t = classify_number($dst, $extensions_list);
    $src_ext = ($src_t === 'extension');
    $dst_ext = ($dst_t === 'extension');

    if ($src_ext && $dst_ext)   return 'internal';
    if (!$src_ext && $dst_ext)  return 'inbound';
    if ($src_ext && !$dst_ext)  return 'outbound';

    if (!empty($dcontext)) {
        if (preg_match('#^from-(trunk|pstn|sip-external|analog|did|outside)#i', $dcontext)) return 'inbound';
        if (preg_match('#^(outbound|outrt|out-|outbound-)#i', $dcontext)) return 'outbound';
        if (preg_match('#^from-internal#i', $dcontext)) return 'internal';
    }
    return 'unknown';
}

function direction_label($direction) {
    switch ($direction) {
        case 'inbound':  return 'ورودی';
        case 'outbound': return 'خروجی';
        case 'internal': return 'داخلی';
        case 'transit':  return 'ترانزیت';
        default:         return 'نامشخص';
    }
}

function direction_badge_class($direction) {
    switch ($direction) {
        case 'inbound':  return 'bg-cyan-50 dark:bg-cyan-950/40 text-cyan-700 dark:text-cyan-400 border-cyan-200 dark:border-cyan-800/60';
        case 'outbound': return 'bg-violet-50 dark:bg-violet-950/40 text-violet-700 dark:text-violet-400 border-violet-200 dark:border-violet-800/60';
        case 'internal': return 'bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-400 border-amber-200 dark:border-amber-800/60';
        case 'transit':  return 'bg-fuchsia-50 dark:bg-fuchsia-950/40 text-fuchsia-700 dark:text-fuchsia-400 border-fuchsia-200 dark:border-fuchsia-800/60';
        default:         return 'bg-slate-50 dark:bg-slate-800/50 text-slate-600 dark:text-slate-400 border-slate-200 dark:border-slate-700';
    }
}

function number_type_label($type) {
    switch ($type) {
        case 'extension':     return 'داخلی';
        case 'city':          return 'شهری';
        case 'mobile':        return 'همراه';
        case 'international': return 'بین‌المللی';
        default:              return '';
    }
}

function number_type_badge_class($type) {
    switch ($type) {
        case 'city':          return 'bg-blue-50 dark:bg-blue-950/40 text-blue-700 dark:text-blue-400 border-blue-200 dark:border-blue-800/60';
        case 'mobile':        return 'bg-pink-50 dark:bg-pink-950/40 text-pink-700 dark:text-pink-400 border-pink-200 dark:border-pink-800/60';
        case 'international': return 'bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-400 border-rose-200 dark:border-rose-800/60';
        default:              return 'bg-slate-50 dark:bg-slate-800/50 text-slate-600 dark:text-slate-400 border-slate-200 dark:border-slate-700';
    }
}

/**
 * فرمت‌بندی اندازه فایل به صورت خوانا (KB, MB, GB) با ارقام لاتین
 */
function format_bytes($bytes, $precision = 1) {
    $units = array('B', 'KB', 'MB', 'GB');
    $bytes = max((int)$bytes, 0);
    if ($bytes === 0) return '0 B';
    $pow = floor(log($bytes) / log(1024));
    $pow = min((int)$pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * اتصال هوشمند و بسیار سریع رکورد تماس به فایل ضبط‌شده استریسک از طریق UniqueID و تاریخ
 * پشتیبانی از پوشه‌های ساختار تاریخ YYYY/MM/DD و مسیر مستقیم، به همراه کش درون‌حافظه‌ای دایرکتوری‌ها
 */
function resolve_pbx_recording($monitor_dir, $calldate, $uniqueid, $recordingfile = '') {
    static $resolved_cache = array();
    static $dir_files_cache = array();

    $cache_key = $uniqueid . '|' . $calldate . '|' . $recordingfile;
    if (isset($resolved_cache[$cache_key])) {
        return $resolved_cache[$cache_key];
    }

    $default_not_found = array(
        'available'    => false,
        'filename'     => '',
        'rel_path'     => '',
        'full_path'    => '',
        'filesize'     => 0,
        'filesize_str' => '—',
        'format'       => '—',
        'play_url'     => '',
        'download_url' => '',
    );

    $monitor_dir_real = realpath($monitor_dir);
    if (!$monitor_dir_real || !is_dir($monitor_dir_real)) {
        $resolved_cache[$cache_key] = $default_not_found;
        return $default_not_found;
    }

    $monitor_base = rtrim($monitor_dir_real, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $allowed_exts = array('wav', 'mp3', 'gsm', 'ogg', 'WAV');

    // تابع کمکی خواندن و کش کردن محتویات دایرکتوری در حافظه
    $get_dir_files = function($dir_path) use (&$dir_files_cache) {
        $real = realpath($dir_path);
        if (!$real || !is_dir($real)) return array();
        if (isset($dir_files_cache[$real])) return $dir_files_cache[$real];
        $entries = @scandir($real);
        $files = array();
        if ($entries) {
            foreach ($entries as $e) {
                if ($e === '.' || $e === '..') continue;
                $full = $real . DIRECTORY_SEPARATOR . $e;
                if (is_file($full)) {
                    $files[] = array(
                        'name' => $e,
                        'full' => $full,
                        'size' => (int)@filesize($full),
                        'ext'  => strtolower(pathinfo($e, PATHINFO_EXTENSION)),
                    );
                }
            }
        }
        $dir_files_cache[$real] = $files;
        return $files;
    };

    // 1. بررسی مستقیم فیلد recordingfile از دیتابیس در صورت وجود
    if (!empty($recordingfile)) {
        $clean_rf = str_replace(array("\0", '..', '\\'), '', (string)$recordingfile);
        $clean_rf = ltrim($clean_rf, '/');

        $test_paths = array(
            $monitor_base . $clean_rf,
        );
        try {
            $dt = new DateTime($calldate);
            $test_paths[] = $monitor_base . $dt->format('Y/m/d') . '/' . basename($clean_rf);
            $test_paths[] = $monitor_base . $dt->format('Y/m/d') . '/' . $clean_rf;
        } catch (Exception $e) {}

        foreach ($test_paths as $tp) {
            if (is_file($tp)) {
                $ext = strtolower(pathinfo($tp, PATHINFO_EXTENSION));
                if (in_array($ext, $allowed_exts, true)) {
                    $rel = ltrim(str_replace($monitor_base, '', realpath($tp)), '/\\');
                    $rel = str_replace('\\', '/', $rel);
                    $sz = (int)@filesize($tp);
                    $res = array(
                        'available'    => true,
                        'filename'     => basename($tp),
                        'rel_path'     => $rel,
                        'full_path'    => $tp,
                        'filesize'     => $sz,
                        'filesize_str' => format_bytes($sz),
                        'format'       => strtoupper($ext),
                        'play_url'     => 'play_audio.php?file=' . urlencode($rel) . '&type=monitor',
                        'download_url' => 'play_audio.php?file=' . urlencode($rel) . '&type=monitor&download=1',
                    );
                    $resolved_cache[$cache_key] = $res;
                    return $res;
                }
            }
        }
    }

    // 2. تطبیق با استفاده از شناسه یکتا (UniqueID) و ساختار تاریخ YYYY/MM/DD
    if (!empty($uniqueid)) {
        $target_dirs = array();
        try {
            $dt = new DateTime($calldate);
            // ساختار سال/ماه/روز
            $target_dirs[] = $monitor_base . $dt->format('Y/m/d');
            // در صورتی که تماس در مرز روز ثبت شده باشد، روز قبل و بعد نیز بررسی شوند
            $dt_prev = (clone $dt)->modify('-1 day');
            $dt_next = (clone $dt)->modify('+1 day');
            $target_dirs[] = $monitor_base . $dt_prev->format('Y/m/d');
            $target_dirs[] = $monitor_base . $dt_next->format('Y/m/d');
        } catch (Exception $e) {}

        // همچنین ریشه پوشه monitor بررسی شود
        $target_dirs[] = rtrim($monitor_base, DIRECTORY_SEPARATOR);

        foreach ($target_dirs as $td) {
            $files_in_dir = $get_dir_files($td);
            foreach ($files_in_dir as $f) {
                if (in_array($f['ext'], $allowed_exts, true) && strpos($f['name'], (string)$uniqueid) !== false) {
                    $rel = ltrim(str_replace($monitor_base, '', $f['full']), '/\\');
                    $rel = str_replace('\\', '/', $rel);
                    $res = array(
                        'available'    => true,
                        'filename'     => $f['name'],
                        'rel_path'     => $rel,
                        'full_path'    => $f['full'],
                        'filesize'     => $f['size'],
                        'filesize_str' => format_bytes($f['size']),
                        'format'       => strtoupper($f['ext']),
                        'play_url'     => 'play_audio.php?file=' . urlencode($rel) . '&type=monitor',
                        'download_url' => 'play_audio.php?file=' . urlencode($rel) . '&type=monitor&download=1',
                    );
                    $resolved_cache[$cache_key] = $res;
                    return $res;
                }
            }
        }
    }

    $resolved_cache[$cache_key] = $default_not_found;
    return $default_not_found;
}

/**
 * یافتن فایل صوتی مکالمه
 */
function find_audio_files($monitor_dir, $calldate, $uniqueid, $recordingfile = '') {
    $result = array();
    $allowed = array('mp3', 'wav', 'ogg', 'gsm', 'WAV');

    if (!empty($recordingfile)) {
        $clean_rf = str_replace(array("\0", '..', '\\'), '', $recordingfile);
        if (file_exists($clean_rf) && is_file($clean_rf)) {
            $ext = strtolower(pathinfo($clean_rf, PATHINFO_EXTENSION));
            if (in_array($ext, $allowed, true)) {
                $result[] = array(
                    'path' => ltrim(str_replace($monitor_dir, '', $clean_rf), '/'),
                    'name' => basename($clean_rf),
                    'ext'  => $ext,
                );
                return $result;
            }
        }
        if (file_exists($monitor_dir . $clean_rf) && is_file($monitor_dir . $clean_rf)) {
            $ext = strtolower(pathinfo($clean_rf, PATHINFO_EXTENSION));
            if (in_array($ext, $allowed, true)) {
                $result[] = array(
                    'path' => ltrim($clean_rf, '/'),
                    'name' => basename($clean_rf),
                    'ext'  => $ext,
                );
                return $result;
            }
        }
    }

    if (empty($uniqueid)) return $result;

    try { $dt = new DateTime($calldate); }
    catch (Exception $e) { return $result; }

    $target_dir = $monitor_dir . $dt->format('Y/m/d') . '/';
    if (!is_dir($target_dir)) $target_dir = $monitor_dir;

    $matches = @glob($target_dir . '*' . $uniqueid . '*');
    if (!empty($matches)) {
        foreach ($matches as $path) {
            if (!is_file($path)) continue;
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) continue;
            $result[] = array(
                'path' => ltrim(str_replace($monitor_dir, '', $path), '/'),
                'name' => basename($path),
                'ext'  => $ext,
            );
        }
    }
    return $result;
}

/**
 * اسکن پیام‌های صوتی (Voicemails) در ساختار دایرکتوری‌های ایزابل
 * ساختار دایرکتوری استاندارد استریسک: /var/spool/asterisk/voicemail/<context>/<extension>/<folder>/msgXXXX.txt
 */
function scan_asterisk_voicemails($voicemail_dir) {
    $voicemails = array();
    $vm_real = realpath($voicemail_dir);
    if (!$vm_real || !is_dir($vm_real)) {
        return $voicemails;
    }

    $ext_dirs = @glob(rtrim($vm_real, '/\\') . '/*', GLOB_ONLYDIR);
    if (empty($ext_dirs)) return $voicemails;

    foreach ($ext_dirs as $ext_path) {
        $ext = basename($ext_path);
        // پوشه‌های زیرمجموعه داخلی (مانند INBOX, Old, Urgent)
        $folder_dirs = @glob($ext_path . '/*', GLOB_ONLYDIR);
        if (empty($folder_dirs)) continue;

        foreach ($folder_dirs as $f_path) {
            $folder_name = basename($f_path);
            $txt_files = @glob($f_path . '/msg*.txt');
            if (empty($txt_files)) continue;

            foreach ($txt_files as $meta_file) {
                $msg_base = preg_replace('/\.txt$/i', '', $meta_file);
                
                // خواندن اطلاعات پیام صوتی
                $meta = @parse_ini_file($meta_file);
                $callerid = '';
                $caller_num = '';
                $caller_name = '';
                $duration = 0;
                $origdate = '';
                $origtime = 0;

                if ($meta !== false) {
                    $callerid = isset($meta['callerid']) ? trim($meta['callerid'], '"\'') : '';
                    if (preg_match('/^(.*?)\s*<([0-9+]+)>/', $callerid, $cm)) {
                        $caller_name = trim($cm[1]);
                        $caller_num  = trim($cm[2]);
                    } else {
                        $caller_num = $callerid;
                    }
                    $duration = isset($meta['duration']) ? (int)$meta['duration'] : 0;
                    $origtime = isset($meta['origtime']) ? (int)$meta['origtime'] : filemtime($meta_file);
                    $origdate = isset($meta['origdate']) ? $meta['origdate'] : date('Y-m-d H:i:s', $origtime);
                } else {
                    $origtime = filemtime($meta_file);
                    $origdate = date('Y-m-d H:i:s', $origtime);
                }

                // یافتن فایل صوتی متناظر
                $audio_rel_path = '';
                $allowed = array('wav', 'WAV', 'mp3', 'gsm', 'ogg');
                foreach ($allowed as $ext_test) {
                    $try_audio = $msg_base . '.' . $ext_test;
                    if (file_exists($try_audio) && is_file($try_audio)) {
                        $audio_rel_path = ltrim(str_replace($vm_real, '', $try_audio), '/\\');
                        break;
                    }
                }

                $calldate_dt = new DateTime();
                $calldate_dt->setTimestamp($origtime);
                $shamsi = gregorian_to_jalali((int)$calldate_dt->format('Y'), (int)$calldate_dt->format('m'), (int)$calldate_dt->format('d'));

                $voicemails[] = array(
                    'extension'     => $ext,
                    'folder'        => $folder_name,
                    'caller_num'    => $caller_num !== '' ? $caller_num : 'ناشناس',
                    'caller_name'   => $caller_name,
                    'duration'      => $duration,
                    'timestamp'     => $origtime,
                    'datetime_g'    => $calldate_dt->format('Y-m-d H:i:s'),
                    'shamsi_date'   => $shamsi,
                    'time_str'      => $calldate_dt->format('H:i:s'),
                    'audio_path'    => $audio_rel_path,
                    'has_audio'     => !empty($audio_rel_path),
                );
            }
        }
    }

    // مرتب‌سازی پیش‌فرض: جدیدترین پیام‌ها اول
    usort($voicemails, function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });

    return $voicemails;
}

/**
 * ساخت کامپوننت پلیر هوشمند و مینیمال صوتی با کنترل لودبار، اسکراب نرم عقب/جلو و طراحی مدرن
 */
function render_smart_audio_player($src, $download_url = '', $extra_html = '', $color = 'emerald', $width_class = 'w-64 sm:w-80', $duration_sec = 0) {
    if (empty($src)) {
        return '<span class="inline-block text-xs font-semibold text-slate-400 bg-slate-100 dark:bg-slate-800/60 px-3 py-1 rounded-xl">فاقد فایل ضبط</span>';
    }

    $color_classes = array(
        'emerald' => array(
            'btn'      => 'bg-gradient-to-tr from-emerald-600 via-teal-500 to-emerald-400 shadow-emerald-500/25 hover:shadow-emerald-500/40',
            'gradient' => 'from-emerald-500 to-teal-400',
            'thumb'    => 'border-emerald-500 ring-emerald-500/20',
            'hover_bg' => 'hover:bg-emerald-50 dark:hover:bg-emerald-950/40 hover:text-emerald-600 dark:hover:text-emerald-400',
        ),
        'purple' => array(
            'btn'      => 'bg-gradient-to-tr from-purple-600 via-indigo-600 to-purple-500 shadow-purple-500/25 hover:shadow-purple-500/40',
            'gradient' => 'from-purple-600 to-indigo-500',
            'thumb'    => 'border-purple-600 ring-purple-600/20',
            'hover_bg' => 'hover:bg-purple-50 dark:hover:bg-purple-950/40 hover:text-purple-600 dark:hover:text-purple-400',
        ),
        'blue' => array(
            'btn'      => 'bg-gradient-to-tr from-blue-600 via-indigo-500 to-cyan-500 shadow-blue-500/25 hover:shadow-blue-500/40',
            'gradient' => 'from-blue-600 to-cyan-500',
            'thumb'    => 'border-blue-600 ring-blue-600/20',
            'hover_bg' => 'hover:bg-blue-50 dark:hover:bg-blue-950/40 hover:text-blue-600 dark:hover:text-blue-400',
        )
    );

    $cfg = isset($color_classes[$color]) ? $color_classes[$color] : $color_classes['emerald'];
    $dl_link = !empty($download_url) ? $download_url : ($src . (strpos($src, '?') !== false ? '&download=1' : '?download=1'));

    $dur_str = '--:--';
    if ($duration_sec > 0) {
        $m = floor($duration_sec / 60);
        $s = $duration_sec % 60;
        $dur_str = sprintf('%02d:%02d', $m, $s);
    }

    ob_start();
    ?>
    <div class="smart-audio-player flex items-center gap-2.5 sm:gap-3 bg-white/90 dark:bg-slate-800/90 hover:bg-white dark:hover:bg-slate-800 px-3 py-2 rounded-2xl border border-slate-200/90 dark:border-slate-700/80 <?php echo $width_class; ?> max-w-full shadow-sm transition-all duration-200 select-none" dir="ltr" data-src="<?php echo htmlspecialchars($src); ?>" data-duration="<?php echo (int)$duration_sec; ?>">
        
        <!-- تگ صوتی بومی مجزا برای هر ردیف بدون تداخل و بدون مسدود شدن سشن -->
        <audio class="sap-audio-native" preload="none" src="<?php echo htmlspecialchars($src); ?>"></audio>

        <!-- دکمه گرد و ارگونومیک پخش / توقف -->
        <button type="button" class="sap-play-btn w-9 h-9 rounded-full <?php echo $cfg['btn']; ?> text-white flex items-center justify-center shrink-0 shadow-md transition-all hover:scale-105 active:scale-95 cursor-pointer focus:outline-none" title="پخش / توقف">
            <svg class="sap-play-icon w-4 h-4 translate-x-0.5" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
            <svg class="sap-pause-icon w-4 h-4 hidden" fill="currentColor" viewBox="0 0 24 24"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>
            <svg class="sap-spin-icon w-4 h-4 hidden animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
        </button>

        <!-- نوار اسکراب و لودبار عریض با قابلیت عقب و جلو کردن نرم -->
        <div class="flex-1 min-w-[110px] flex flex-col justify-center py-0.5 select-none">
            <div class="sap-seek-track w-full bg-slate-200 dark:bg-slate-700 h-2 rounded-full cursor-pointer relative overflow-visible group" title="کلیک یا درگ برای عقب و جلو کردن صدا">
                <!-- نوار بافر شبکه -->
                <div class="sap-buffer-fill bg-slate-300 dark:bg-slate-600 h-full rounded-full absolute left-0 top-0 transition-all pointer-events-none" style="width: 0%"></div>
                <!-- نوار پیشرفت پخش -->
                <div class="sap-progress-fill bg-gradient-to-r <?php echo $cfg['gradient']; ?> h-full rounded-full absolute left-0 top-0 pointer-events-none" style="width: 0%"></div>
                <!-- دستگیره متحرک اسکراب -->
                <div class="sap-scrub-thumb w-3.5 h-3.5 bg-white dark:bg-slate-100 shadow border-2 <?php echo $cfg['thumb']; ?> rounded-full absolute top-1/2 -translate-y-1/2 -ml-1.5 transition-transform pointer-events-none group-hover:scale-125" style="left: 0%"></div>
            </div>
            
            <!-- زمان سپری شده و کل زمان مکالمه با ارقام لاتین استاندارد -->
            <div class="flex justify-between items-center text-[10px] sm:text-[11px] font-mono text-slate-500 dark:text-slate-400 mt-1 select-none">
                <span class="sap-time-current">00:00</span>
                <span class="sap-time-duration"><?php echo $dur_str; ?></span>
            </div>
        </div>

        <!-- دکمه‌های کنترلی سمت راست (دانلود و امکانات اختیاری) -->
        <div class="flex items-center gap-1.5 shrink-0">
            <?php if (!empty($dl_link)): ?>
                <a href="<?php echo htmlspecialchars($dl_link); ?>" class="w-8 h-8 rounded-xl bg-slate-100/90 dark:bg-slate-700/70 <?php echo $cfg['hover_bg']; ?> text-slate-600 dark:text-slate-300 flex items-center justify-center transition shadow-xs" title="دانلود فایل صوتی" download>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                </a>
            <?php endif; ?>

            <?php if (!empty($extra_html)) echo $extra_html; ?>
        </div>

    </div>
    <?php
    return ob_get_clean();
}

/**
 * هدر استاندارد، مدرن و هماهنگ سامانه با ساختار 2 سطحی کاملاً بالانس شده
 */
function render_app_header($active_tab = 'monitoring') {
    $username = get_auth_username();
    $today_g = date('Y-m-d');
    $p = explode('-', $today_g);
    $today_shamsi = gregorian_to_jalali((int)$p[0], (int)$p[1], (int)$p[2]);
    $csrf = get_csrf_token();
    ?>
    <style>
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .custom-scrollbar::-webkit-scrollbar { height: 6px; width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(148, 163, 184, 0.35); border-radius: 9999px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(100, 116, 139, 0.55); }
    </style>

    <header class="mb-6 sm:mb-8">
        <div class="bg-white/85 dark:bg-slate-900/85 backdrop-blur-xl border border-slate-200/90 dark:border-slate-800/90 rounded-3xl p-4 sm:p-5 shadow-sm transition-all duration-300">
            
            <!-- سطر اول: هویت بصری، وضعیت آنلاین، ساعت و کنترل‌های حساب (کاملاً ریسپانسیو) -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3.5 pb-4 border-b border-slate-100 dark:border-slate-800/80">
                
                <!-- سمت راست: نشان و عنوان اختصاصی سامانه -->
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 sm:w-11 sm:h-11 rounded-2xl bg-gradient-to-tr from-emerald-600 via-teal-500 to-emerald-400 text-white shadow-md shadow-emerald-500/20 flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 100-6 3 3 0 000 6z"/>
                        </svg>
                    </div>
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-sm sm:text-base md:text-lg font-black text-slate-800 dark:text-white tracking-tight">
                                سامانه هوشمند مانیتورینگ ایزابل
                            </span>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800/60">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> آنلاین
                            </span>
                        </div>
                        <p class="text-[11px] text-slate-400 mt-0.5">تحلیل مکالمات، آمار تماس‌ها و مدیریت ویس‌میل</p>
                    </div>
                </div>

                <!-- سمت چپ: تقویم و ساعت، تغییر تم، دکمه سریع تغییر رمز و خروج -->
                <div class="flex items-center gap-2 flex-wrap justify-between sm:justify-end">
                    
                    <!-- ویجت تاریخ و ساعت زنده (با ارقام لاتین) -->
                    <div class="flex items-center gap-2 bg-slate-50 dark:bg-slate-800/70 border border-slate-200 dark:border-slate-700/80 rounded-2xl px-3 py-1.5 text-xs font-mono">
                        <span class="text-slate-500 dark:text-slate-400"><?php echo $today_shamsi; ?></span>
                        <span class="text-slate-300 dark:text-slate-600">|</span>
                        <span id="app-live-clock" class="font-bold text-slate-800 dark:text-slate-200">00:00:00</span>
                    </div>

                    <!-- دکمه تغییر تم شب و روز -->
                    <button type="button" id="header-theme-toggle" class="w-9 h-9 rounded-2xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200 dark:border-slate-700/80 flex items-center justify-center text-slate-600 dark:text-slate-300 hover:text-emerald-600 dark:hover:text-emerald-400 transition-all shadow-sm" title="تغییر حالت تم">
                        <svg id="header-icon-sun" class="w-4 h-4 hidden text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                        <svg id="header-icon-moon" class="w-4 h-4 hidden text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                    </button>

                    <!-- دکمه تغییر آنی رمز عبور (پاپ‌آپ مدال) -->
                    <button type="button" onclick="openCredsModal()" class="inline-flex items-center gap-1.5 h-9 px-3 rounded-2xl bg-slate-50 dark:bg-slate-800/70 border border-slate-200 dark:border-slate-700/80 text-slate-700 dark:text-slate-300 hover:text-emerald-600 dark:hover:text-emerald-400 hover:border-emerald-500/40 text-xs font-bold transition shadow-sm" title="تغییر نام کاربری و رمز عبور">
                        <svg class="w-3.5 h-3.5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                        <span>تغییر رمز</span>
                    </button>

                    <!-- نشان کاربر و دکمه خروج -->
                    <div class="flex items-center gap-1.5 bg-slate-50 dark:bg-slate-800/70 border border-slate-200 dark:border-slate-700/80 rounded-2xl p-1 pr-2.5">
                        <div class="flex items-center gap-1.5">
                            <div class="w-6 h-6 rounded-lg bg-emerald-600 text-white flex items-center justify-center text-[10px] font-bold uppercase" id="header-avatar-initial">
                                <?php echo mb_substr($username, 0, 1, 'UTF-8'); ?>
                            </div>
                            <span class="text-xs font-bold text-slate-700 dark:text-slate-200 font-mono" id="header-username-text"><?php echo htmlspecialchars($username); ?></span>
                        </div>
                        <a href="logout.php" class="inline-flex items-center p-1.5 rounded-xl text-rose-500 hover:bg-rose-50 dark:hover:bg-rose-950/40 transition-colors" title="خروج از سامانه">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                        </a>
                    </div>

                </div>

            </div>

            <!-- سطر دوم: ناوبری تب‌های اصلی (اسکرول افقی نرم در موبایل، بدون شکستن ناموزون سطرها) -->
            <nav class="flex items-center gap-1.5 sm:gap-2 pt-3.5 overflow-x-auto no-scrollbar scroll-smooth flex-nowrap sm:flex-wrap pb-1">
                
                <a href="monitoring.php"
                   class="shrink-0 whitespace-nowrap flex items-center gap-2 px-3.5 py-2 rounded-2xl text-xs sm:text-sm font-bold transition-all <?php echo $active_tab === 'monitoring' ? 'bg-emerald-500 text-white shadow-md shadow-emerald-500/25 ring-2 ring-emerald-500/20' : 'bg-slate-100/80 dark:bg-slate-800/80 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700'; ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    <span>داشبورد و مانیتورینگ</span>
                </a>

                <a href="reports.php"
                   class="shrink-0 whitespace-nowrap flex items-center gap-2 px-3.5 py-2 rounded-2xl text-xs sm:text-sm font-bold transition-all <?php echo $active_tab === 'reports' ? 'bg-emerald-500 text-white shadow-md shadow-emerald-500/25 ring-2 ring-emerald-500/20' : 'bg-slate-100/80 dark:bg-slate-800/80 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700'; ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    <span>گزارشات تفصیلی</span>
                </a>

                <!-- تب جدید و اختصاصی ضبط مکالمات PBX -->
                <a href="recordings.php"
                   class="shrink-0 whitespace-nowrap flex items-center gap-2 px-3.5 py-2 rounded-2xl text-xs sm:text-sm font-bold transition-all <?php echo $active_tab === 'recordings' ? 'bg-emerald-500 text-white shadow-md shadow-emerald-500/25 ring-2 ring-emerald-500/20' : 'bg-slate-100/80 dark:bg-slate-800/80 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700'; ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 100-6 3 3 0 000 6z"/></svg>
                    <span>ضبط مکالمات PBX</span>
                </a>

                <a href="voicemail.php"
                   class="shrink-0 whitespace-nowrap flex items-center gap-2 px-3.5 py-2 rounded-2xl text-xs sm:text-sm font-bold transition-all <?php echo $active_tab === 'voicemail' ? 'bg-emerald-500 text-white shadow-md shadow-emerald-500/25 ring-2 ring-emerald-500/20' : 'bg-slate-100/80 dark:bg-slate-800/80 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700'; ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    <span>صندوق صوتی</span>
                </a>

                <a href="settings.php"
                   class="shrink-0 whitespace-nowrap flex items-center gap-2 px-3.5 py-2 rounded-2xl text-xs sm:text-sm font-bold transition-all <?php echo $active_tab === 'settings' ? 'bg-emerald-500 text-white shadow-md shadow-emerald-500/25 ring-2 ring-emerald-500/20' : 'bg-slate-100/80 dark:bg-slate-800/80 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700'; ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    <span>تنظیمات</span>
                </a>

            </nav>

        </div>
    </header>

    <!-- مودال اختصاصی تغییر نام کاربری و رمز عبور (در دسترس در تمامی صفحات) -->
    <div id="creds-modal" class="hidden fixed inset-0 z-50 items-center justify-center bg-slate-950/60 backdrop-blur-md p-4">
        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-3xl w-full max-w-md overflow-hidden flex flex-col shadow-2xl transition-all">
            
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-800/40">
                <div class="flex items-center gap-2.5">
                    <div class="w-8 h-8 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                    </div>
                    <h3 class="text-sm font-extrabold text-slate-800 dark:text-white">تغییر نام کاربری و رمز ورود</h3>
                </div>
                <button type="button" onclick="closeCredsModal()" class="text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 w-8 h-8 flex items-center justify-center rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <form id="creds-form" onsubmit="submitCredsForm(event)" class="p-6 space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">

                <div id="creds-alert" class="hidden p-3 rounded-2xl text-xs font-bold"></div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">رمز عبور فعلی</label>
                    <input type="password" name="current_password" required placeholder="رمز فعلی جهت تایید هویت"
                           class="w-full h-10 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 rounded-xl px-3.5 text-xs text-slate-800 dark:text-white focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">نام کاربری جدید</label>
                    <input type="text" name="new_username" required value="<?php echo htmlspecialchars($username); ?>"
                           class="w-full h-10 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 rounded-xl px-3.5 text-xs font-mono text-slate-800 dark:text-white focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">رمز عبور جدید</label>
                    <input type="password" name="new_password" required placeholder="حداقل 4 کاراکتر"
                           class="w-full h-10 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 rounded-xl px-3.5 text-xs text-slate-800 dark:text-white focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1.5">تکرار رمز عبور جدید</label>
                    <input type="password" name="confirm_password" required placeholder="تکرار رمز عبور جدید"
                           class="w-full h-10 bg-slate-50 dark:bg-slate-800/80 border border-slate-200 dark:border-slate-700/80 rounded-xl px-3.5 text-xs text-slate-800 dark:text-white focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition">
                </div>

                <div class="pt-2 flex items-center justify-end gap-2">
                    <button type="button" onclick="closeCredsModal()" class="h-10 px-4 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-xs font-bold transition">انصراف</button>
                    <button type="submit" id="creds-submit-btn" class="h-10 px-5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-500 hover:from-emerald-700 hover:to-teal-600 text-white text-xs font-bold transition shadow-md shadow-emerald-500/20 flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <span>ذخیره تغییرات</span>
                    </button>
                </div>
            </form>

        </div>
    </div>

    <script>
        function openCredsModal() {
            var modal = document.getElementById('creds-modal');
            var alertEl = document.getElementById('creds-alert');
            if (alertEl) { alertEl.className = 'hidden'; alertEl.textContent = ''; }
            if (modal) {
                modal.classList.remove('hidden');
                modal.classList.add('flex');
            }
        }

        function closeCredsModal() {
            var modal = document.getElementById('creds-modal');
            if (modal) {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }
        }

        function submitCredsForm(e) {
            e.preventDefault();
            var form = document.getElementById('creds-form');
            var alertEl = document.getElementById('creds-alert');
            var btn = document.getElementById('creds-submit-btn');
            if (!form || !alertEl || !btn) return;

            var fd = new FormData(form);
            btn.disabled = true;
            btn.classList.add('opacity-50');

            fetch('change_credentials.php', {
                method: 'POST',
                body: fd
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                btn.disabled = false;
                btn.classList.remove('opacity-50');
                if (data.success) {
                    alertEl.className = 'p-3 rounded-2xl text-xs font-bold bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20';
                    alertEl.textContent = data.message;
                    if (data.new_username) {
                        var userText = document.getElementById('header-username-text');
                        var userInit = document.getElementById('header-avatar-initial');
                        if (userText) userText.textContent = data.new_username;
                        if (userInit) userInit.textContent = data.new_username.charAt(0).toUpperCase();
                    }
                    setTimeout(function() {
                        closeCredsModal();
                        form.reset();
                    }, 1800);
                } else {
                    alertEl.className = 'p-3 rounded-2xl text-xs font-bold bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20';
                    alertEl.textContent = data.message || 'خطا در ثبت اطلاعات.';
                }
            })
            .catch(function(err) {
                btn.disabled = false;
                btn.classList.remove('opacity-50');
                alertEl.className = 'p-3 rounded-2xl text-xs font-bold bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20';
                alertEl.textContent = 'خطا در برقراری ارتباط با سرور.';
            });
        }

        (function() {
            var clockEl = document.getElementById('app-live-clock');
            if (!clockEl) return;
            function updateClock() {
                var now = new Date();
                var h = String(now.getHours()).padStart(2, '0');
                var m = String(now.getMinutes()).padStart(2, '0');
                var s = String(now.getSeconds()).padStart(2, '0');
                clockEl.textContent = h + ':' + m + ':' + s;
            }
            updateClock();
            setInterval(updateClock, 1000);
        })();

        (function() {
            var btn = document.getElementById('header-theme-toggle');
            var iconSun = document.getElementById('header-icon-sun');
            var iconMoon = document.getElementById('header-icon-moon');
            if (!btn) return;

            function syncIcons() {
                if (document.documentElement.classList.contains('dark')) {
                    if (iconSun) iconSun.classList.remove('hidden');
                    if (iconMoon) iconMoon.classList.add('hidden');
                } else {
                    if (iconSun) iconSun.classList.add('hidden');
                    if (iconMoon) iconMoon.classList.remove('hidden');
                }
            }
            syncIcons();

            btn.addEventListener('click', function() {
                if (document.documentElement.classList.contains('dark')) {
                    document.documentElement.classList.remove('dark');
                    localStorage.setItem('theme', 'light');
                } else {
                    document.documentElement.classList.add('dark');
                    localStorage.setItem('theme', 'dark');
                }
                syncIcons();
                window.dispatchEvent(new Event('themechanged'));
            });
        })();

        // بستن مودال تغییر مشخصات با کلید Escape و کلیک روی پس‌زمینه
        window.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeCredsModal();
        });
        document.addEventListener('click', function(e) {
            var modal = document.getElementById('creds-modal');
            if (modal && !modal.classList.contains('hidden') && e.target === modal) {
                closeCredsModal();
            }
        });

        // موتور جامع و پایدار پلیرهای صوتی با قابلیت کنترل لودبار، اسکراب نرم عقب و جلو، و مدیریت همزمانی
        (function() {
            function formatTime(sec) {
                if (!sec || isNaN(sec) || sec < 0) return '00:00';
                var s = Math.floor(sec);
                var m = Math.floor(s / 60);
                s = s % 60;
                return String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
            }

            function setupPlayer(player) {
                if (player.__sap_ready) return;
                player.__sap_ready = true;

                var audio = player.querySelector('.sap-audio-native');
                var playBtn = player.querySelector('.sap-play-btn');
                var track = player.querySelector('.sap-seek-track');
                var pFill = player.querySelector('.sap-progress-fill');
                var bFill = player.querySelector('.sap-buffer-fill');
                var thumb = player.querySelector('.sap-scrub-thumb');
                var timeCur = player.querySelector('.sap-time-current');
                var timeDur = player.querySelector('.sap-time-duration');
                var playIcon = player.querySelector('.sap-play-icon');
                var pauseIcon = player.querySelector('.sap-pause-icon');
                var spinIcon = player.querySelector('.sap-spin-icon');
                var isDragging = false;

                if (!audio || !playBtn || !track) return;

                function getDuration() {
                    if (audio.duration && !isNaN(audio.duration) && isFinite(audio.duration) && audio.duration > 0) {
                        return audio.duration;
                    }
                    var attrDur = parseFloat(player.getAttribute('data-duration') || '0');
                    if (attrDur > 0) return attrDur;
                    return 0;
                }

                function setBtnState(state) {
                    // state: 'playing' | 'paused' | 'loading'
                    if (!playIcon || !pauseIcon || !spinIcon) return;
                    if (state === 'loading') {
                        spinIcon.classList.remove('hidden');
                        playIcon.classList.add('hidden');
                        pauseIcon.classList.add('hidden');
                    } else if (state === 'playing') {
                        spinIcon.classList.add('hidden');
                        playIcon.classList.add('hidden');
                        pauseIcon.classList.remove('hidden');
                    } else {
                        spinIcon.classList.add('hidden');
                        playIcon.classList.remove('hidden');
                        pauseIcon.classList.add('hidden');
                    }
                }

                function updateProgress(curr, dur) {
                    if (dur > 0) {
                        var pct = Math.max(0, Math.min(100, (curr / dur) * 100));
                        if (pFill) pFill.style.width = pct + '%';
                        if (thumb) thumb.style.left = pct + '%';
                    }
                    if (timeCur) timeCur.textContent = formatTime(curr);
                }

                // پخش صدا و توقف سایر فایل‌های صوتی صفحه
                playBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();

                    if (audio.paused) {
                        // متوقف ساختن تمام پلیرهای دیگر روی صفحه
                        document.querySelectorAll('.sap-audio-native').forEach(function(other) {
                            if (other !== audio && !other.paused) {
                                other.pause();
                            }
                        });

                        setBtnState('loading');
                        var playPromise = audio.play();
                        if (playPromise !== undefined) {
                            playPromise.then(function() {
                                setBtnState('playing');
                            }).catch(function(err) {
                                console.error('Play failed:', err);
                                setBtnState('paused');
                            });
                        }
                    } else {
                        audio.pause();
                    }
                });

                // رویدادهای Audio بومی
                audio.addEventListener('play', function() {
                    setBtnState('playing');
                });

                audio.addEventListener('playing', function() {
                    setBtnState('playing');
                });

                audio.addEventListener('pause', function() {
                    setBtnState('paused');
                });

                audio.addEventListener('waiting', function() {
                    setBtnState('loading');
                });

                audio.addEventListener('loadedmetadata', function() {
                    if (audio.duration && !isNaN(audio.duration) && isFinite(audio.duration)) {
                        if (timeDur) timeDur.textContent = formatTime(audio.duration);
                    }
                });

                audio.addEventListener('durationchange', function() {
                    if (audio.duration && !isNaN(audio.duration) && isFinite(audio.duration)) {
                        if (timeDur) timeDur.textContent = formatTime(audio.duration);
                    }
                });

                audio.addEventListener('progress', function() {
                    if (audio.buffered.length > 0 && audio.duration) {
                        var end = audio.buffered.end(audio.buffered.length - 1);
                        var pct = Math.min(100, (end / audio.duration) * 100);
                        if (bFill) bFill.style.width = pct + '%';
                    }
                });

                audio.addEventListener('timeupdate', function() {
                    if (!isDragging) {
                        var dur = getDuration();
                        updateProgress(audio.currentTime, dur);
                    }
                });

                audio.addEventListener('ended', function() {
                    setBtnState('paused');
                    updateProgress(0, getDuration());
                });

                audio.addEventListener('error', function(err) {
                    console.error('Audio playback error on', audio.src, err);
                    setBtnState('paused');
                    if (timeCur) timeCur.textContent = 'خطا در پخش';
                });

                // اسکراب و لودبار (کلیک و درگ بسیار نرم عقب و جلو)
                function seekToClientX(clientX) {
                    var rect = track.getBoundingClientRect();
                    var pct = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
                    var dur = getDuration();

                    if (dur > 0) {
                        var targetTime = pct * dur;
                        audio.currentTime = targetTime;
                        updateProgress(targetTime, dur);
                    } else {
                        if (pFill) pFill.style.width = (pct * 100) + '%';
                        if (thumb) thumb.style.left = (pct * 100) + '%';
                    }
                }

                track.addEventListener('click', function(e) {
                    seekToClientX(e.clientX);
                });

                track.addEventListener('mousedown', function(e) {
                    isDragging = true;
                    seekToClientX(e.clientX);

                    function onMouseMove(moveEvt) {
                        if (!isDragging) return;
                        seekToClientX(moveEvt.clientX);
                    }
                    function onMouseUp(upEvt) {
                        if (!isDragging) return;
                        isDragging = false;
                        window.removeEventListener('mousemove', onMouseMove);
                        window.removeEventListener('mouseup', onMouseUp);
                    }

                    window.addEventListener('mousemove', onMouseMove);
                    window.addEventListener('mouseup', onMouseUp);
                });

                track.addEventListener('touchstart', function(e) {
                    if (!e.touches || e.touches.length === 0) return;
                    isDragging = true;
                    seekToClientX(e.touches[0].clientX);

                    function onTouchMove(moveEvt) {
                        if (!isDragging || !moveEvt.touches || moveEvt.touches.length === 0) return;
                        seekToClientX(moveEvt.touches[0].clientX);
                    }
                    function onTouchEnd() {
                        if (!isDragging) return;
                        isDragging = false;
                        window.removeEventListener('touchmove', onTouchMove);
                        window.removeEventListener('touchend', onTouchEnd);
                    }

                    window.addEventListener('touchmove', onTouchMove, { passive: true });
                    window.addEventListener('touchend', onTouchEnd, { passive: true });
                }, { passive: true });
            }

            function initAllPlayers() {
                document.querySelectorAll('.smart-audio-player').forEach(setupPlayer);
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initAllPlayers);
            } else {
                initAllPlayers();
            }

            // پشتیبانی از لود دینامیک احتمالی
            window.initSmartAudioPlayers = initAllPlayers;
        })();
    </script>
    <?php
}
