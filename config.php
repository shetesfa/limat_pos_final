<?php
// ============================================================
// config.php — Core configuration & helpers
// አጸደ ትጉሃን ሰንበት ትምህርት ቤት POS v2.0
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => false, // set true on HTTPS
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

// --- Session timeout (30 min inactivity) ---
define('SESSION_TIMEOUT', 1800);
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
    session_unset(); session_destroy(); session_start();
}
if (isset($_SESSION['user_id'])) {
    $_SESSION['last_activity'] = time();
}

// --- DB ---
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'atsedeteguhan_pos');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
mysqli_set_charset($conn, "utf8mb4");

// --- Auth helpers ---
function isLoggedIn()  { return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']); }
function isAdmin()     { return isset($_SESSION['role']) && $_SESSION['role'] === 'admin'; }
function isSeller()    { return isset($_SESSION['role']) && $_SESSION['role'] === 'seller'; }
function redirect($u)  { header("Location: $u"); exit(); }

function requireLogin()  { if (!isLoggedIn()) redirect('index.php'); }
function requireAdmin()  { requireLogin(); if (!isAdmin()) redirect('seller_pos.php'); }

// --- CSRF ---
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}
function verify_csrf($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// --- XSS ---
function h($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
function money($v) { return number_format((float)$v, 2); }

// --- Device detection ---
function getDeviceType() {
    $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (strpos($ua, 'mobile') !== false || strpos($ua, 'android') !== false) return 'Mobile';
    if (strpos($ua, 'tablet') !== false || strpos($ua, 'ipad') !== false) return 'Tablet';
    return 'Desktop';
}

function getClientIP() {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

// --- Audit log ---
function auditLog($conn, $action, $table = null, $record_id = null, $old = null, $new = null, $desc = null) {
    $user_id    = $_SESSION['user_id'] ?? null;
    $username   = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'System';
    $branch_id  = $_SESSION['branch_id'] ?? 1;
    $ip         = getClientIP();
    $device     = getDeviceType();
    $ua         = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

    $stmt = $conn->prepare(
        "INSERT INTO audit_log (user_id, username, action, table_name, record_id, old_value, new_value, description, ip_address, user_agent, device_type, branch_id)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    $stmt->bind_param('isssissssssi',
        $user_id, $username, $action, $table, $record_id,
        $old, $new, $desc, $ip, $ua, $device, $branch_id
    );
    $stmt->execute();
    $stmt->close();
}

// --- Branch helpers ---
function getUserBranch($conn, $user_id) {
    $stmt = $conn->prepare("SELECT branch_id FROM users WHERE id=?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $r['branch_id'] ?? 1;
}

function getCurrentBranchId($conn, $user_id, $role) {
    if (in_array($role, ['admin','super_admin'])) return $_SESSION['branch_id'] ?? 1;
    return getUserBranch($conn, $user_id);
}

function getCurrentBranchName($conn, $branch_id) {
    $stmt = $conn->prepare("SELECT name FROM branches WHERE id=?");
    $stmt->bind_param('i', $branch_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $r['name'] ?? 'ዋና ቅርንጫፍ';
}

// --- Ethiopian Calendar ---
function gregorianToEthiopian($gYear, $gMonth, $gDay) {
    $months = [1=>'መስከረም',2=>'ጥቅምት',3=>'ህዳር',4=>'ታህሳስ',5=>'ጥር',6=>'የካቲት',
               7=>'መጋቢት',8=>'ሚያዝያ',9=>'ግንቦት',10=>'ሰኔ',11=>'ሐምሌ',12=>'ነሐሴ',13=>'ጳጉሜ'];

    $isLeap = ($gYear % 4 === 0 && $gYear % 100 !== 0) || $gYear % 400 === 0;
    $nyd = $isLeap ? 12 : 11;
    $eYear = ($gMonth > 9 || ($gMonth === 9 && $gDay >= $nyd)) ? $gYear - 7 : $gYear - 8;

    $nyGY = ($gMonth < 9 || ($gMonth === 9 && $gDay < $nyd)) ? $gYear - 1 : $gYear;
    $nyIsLeap = ($nyGY % 4 === 0 && $nyGY % 100 !== 0) || $nyGY % 400 === 0;
    $nyDay = $nyIsLeap ? 12 : 11;

    $nyTs = mktime(0, 0, 0, 9, $nyDay, $nyGY);
    $curTs = mktime(0, 0, 0, $gMonth, $gDay, $gYear);
    $diff = (int)(($curTs - $nyTs) / 86400);

    $eMonth = (int)($diff / 30) + 1;
    $eDay   = ($diff % 30) + 1;

    if ($eMonth > 13) { $eMonth = 13; $eDay = min($eDay, ($eYear % 4 === 3) ? 6 : 5); }

    return [
        'year'       => $eYear,
        'month'      => $eMonth,
        'month_name' => $months[$eMonth] ?? 'ጳጉሜ',
        'day'        => $eDay,
        'display'    => "$eDay " . ($months[$eMonth] ?? '') . " $eYear",
        'ymd'        => sprintf('%04d-%02d-%02d', $eYear, $eMonth, $eDay),
    ];
}

function ethDate($datetime = null) {
    if ($datetime === null) $datetime = date('Y-m-d H:i:s');
    $ts = strtotime($datetime);
    return gregorianToEthiopian((int)date('Y',$ts),(int)date('n',$ts),(int)date('j',$ts));
}

function ethDateDisplay($datetime = null) {
    return ethDate($datetime)['display'];
}

function ethDateYMD($datetime = null) {
    return ethDate($datetime)['ymd'];
}

// --- Ethiopian to Gregorian (inverse of gregorianToEthiopian) ---
function ethiopianToGregorianYMD($eYear, $eMonth, $eDay) {
    $nyGY = $eYear + 7; // Gregorian year the Ethiopian year's Meskerem 1 falls in
    $isLeap = ($nyGY % 4 === 0 && $nyGY % 100 !== 0) || $nyGY % 400 === 0;
    $nyd = $isLeap ? 12 : 11;
    $nyTs = mktime(0, 0, 0, 9, $nyd, $nyGY);
    $daysOffset = ($eMonth - 1) * 30 + ($eDay - 1);
    return date('Y-m-d', $nyTs + $daysOffset * 86400);
}

// --- Resolve a named date-range preset (Ethiopian-calendar aware) into [from, to] Gregorian dates ---
function resolveDateRangePreset($period, $customFrom = null, $customTo = null) {
    $today = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
    $todayYmd = $today->format('Y-m-d');
    switch ($period) {
        case 'today':
            return [$todayYmd, $todayYmd];
        case 'yesterday':
            $y = (clone $today)->modify('-1 day')->format('Y-m-d');
            return [$y, $y];
        case 'this_week': {
            $start = (clone $today)->modify('monday this week')->format('Y-m-d');
            return [$start, $todayYmd];
        }
        case 'last_week': {
            $start = (clone $today)->modify('monday last week')->format('Y-m-d');
            $end   = (clone $today)->modify('sunday last week')->format('Y-m-d');
            return [$start, $end];
        }
        case 'this_month': {
            $e = ethDate($todayYmd);
            $start = ethiopianToGregorianYMD($e['year'], $e['month'], 1);
            return [$start, $todayYmd];
        }
        case 'last_month': {
            $e = ethDate($todayYmd);
            $lm = $e['month'] - 1; $ly = $e['year'];
            if ($lm < 1) { $lm = 13; $ly--; }
            $start = ethiopianToGregorianYMD($ly, $lm, 1);
            $end   = ethiopianToGregorianYMD($e['year'], $e['month'], 1);
            $end   = date('Y-m-d', strtotime($end . ' -1 day'));
            return [$start, $end];
        }
        case 'this_year': {
            $e = ethDate($todayYmd);
            $start = ethiopianToGregorianYMD($e['year'], 1, 1);
            return [$start, $todayYmd];
        }
        case 'custom':
        default:
            $from = $customFrom ?: date('Y-m-01');
            $to   = $customTo ?: $todayYmd;
            return [date('Y-m-d', strtotime($from)), date('Y-m-d', strtotime($to))];
    }
}

function time12hr($datetime = null) {
    if ($datetime === null) $datetime = 'now';
    return (new DateTime($datetime, new DateTimeZone('Africa/Addis_Ababa')))->format('h:i A');
}

function getCurrentEthDatetime() {
    $now = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
    $eth = ethDate($now->format('Y-m-d H:i:s'));
    return [
        'eth_date'    => $eth['display'],
        'eth_ymd'     => $eth['ymd'],
        'greg_time'   => $now->format('h:i:s A'),
        'greg_date'   => $now->format('Y-m-d'),
        'timestamp'   => $now->format('Y-m-d H:i:s'),
    ];
}

// --- FIFO: get current batch for product ---
function getCurrentBatch($conn, $product_id, $branch_id = 1) {
    $stmt = $conn->prepare(
        "SELECT * FROM stock_batches
         WHERE product_id=? AND branch_id=? AND is_depleted=0 AND quantity_remaining>0
         ORDER BY created_at ASC LIMIT 1"
    );
    $stmt->bind_param('ii', $product_id, $branch_id);
    $stmt->execute();
    $batch = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $batch;
}

// --- Generate receipt number ---
function generateReceiptNumber($conn) {
    $r = $conn->query("SELECT COUNT(*) as c FROM transactions");
    $n = ($r->fetch_assoc()['c'] ?? 0) + 1;
    return 'ATS' . str_pad($n, 5, '0', STR_PAD_LEFT);
}

// --- Settings ---
function getSetting($conn, $key, $default = '') {
    $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key=?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $r ? $r['setting_value'] : $default;
}
?>
