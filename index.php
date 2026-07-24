<?php
require_once 'config.php';
if (isLoggedIn()) redirect(isAdmin() ? 'admin_dashboard.php' : 'seller_pos.php');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $stmt = $conn->prepare(
            "SELECT id, full_name, username, password, role, branch_id, is_active
             FROM users WHERE username = ? LIMIT 1"
        );
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && $user['is_active'] && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']       = $user['id'];
            $_SESSION['full_name']     = $user['full_name'];
            $_SESSION['username']      = $user['username'];
            $_SESSION['role']          = $user['role'];
            $_SESSION['branch_id']     = $user['branch_id'];
            $_SESSION['last_activity'] = time();

            $conn->query("UPDATE users SET last_login=NOW() WHERE id=" . (int)$user['id']);
            auditLog($conn, 'LOGIN', 'users', $user['id'], null, null, 'ወደ ስርዓቱ ገብቷል');

            redirect($user['role'] === 'admin' ? 'admin_dashboard.php' : 'seller_pos.php');
        } else {
            if (!$user || !$user['is_active']) {
                $error = 'Username not found or account is disabled.';
            } else {
                $error = 'Incorrect password. Please try again.';
            }
        }
    }
}

$eth = getCurrentEthDatetime();
?><!DOCTYPE html>
<html lang="am" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>Login — አጸደ ትጉሃን</title>
<link rel="icon" type="image/png" href="images/icon.png">
<link rel="stylesheet" href="assets/fontawesome/css/all.min.css">
<style>
:root{
  --gold:#DAA520;--gold-light:#F0C040;--brown:#8B4513;--brown-dark:#5C2E0B;
  --orange:#D96B2B;--cream:#FFF8E7;--white:#FFFFFF;
  --text:#1a1a1a;--text-muted:#666;
  --radius:18px;--radius-sm:10px;
  --shadow:0 8px 32px rgba(0,0,0,0.12);
  --shadow-lg:0 20px 60px rgba(0,0,0,0.2);
}
*{margin:0;padding:0;box-sizing:border-box;}
body{
  font-family:'Segoe UI',Tahoma,Arial,sans-serif;
  min-height:100vh;
  background:linear-gradient(135deg,#5C2E0B 0%,#8B4513 30%,#D96B2B 65%,#DAA520 100%);
  display:flex;align-items:center;justify-content:center;padding:20px;
}
.login-card{
  background:var(--white);border-radius:var(--radius);
  box-shadow:var(--shadow-lg);width:100%;max-width:420px;
  overflow:hidden;animation:slideUp .5s ease;
}
@keyframes slideUp{from{opacity:0;transform:translateY(30px)}to{opacity:1;transform:translateY(0)}}
.login-header{
  background:linear-gradient(135deg,var(--brown-dark),var(--brown));
  padding:32px 30px;text-align:center;position:relative;
}
.login-header::after{
  content:'';position:absolute;bottom:-1px;left:0;right:0;height:20px;
  background:var(--white);border-radius:50% 50% 0 0/100% 100% 0 0;
}
.logo-ring{
  width:84px;height:84px;border-radius:50%;
  border:3px solid rgba(218,165,32,0.6);
  display:flex;align-items:center;justify-content:center;
  margin:0 auto 16px;background:rgba(255,255,255,0.1);
}
.logo-ring img{width:64px;height:64px;border-radius:50%;object-fit:cover;}
.logo-ring i{font-size:36px;color:var(--gold);}
.login-header h1{color:var(--gold);font-size:1rem;font-weight:700;line-height:1.4;margin-bottom:4px;}
.login-header p{color:rgba(255,255,255,0.7);font-size:0.78rem;}
.login-body{padding:32px 30px 24px;}
.eth-date-box{
  text-align:center;background:var(--cream);border-radius:var(--radius-sm);
  padding:8px 16px;margin-bottom:24px;color:var(--brown);font-size:0.8rem;font-weight:600;
}
.form-group{margin-bottom:18px;}
.form-group label{
  display:block;font-size:0.82rem;font-weight:700;
  color:var(--text);margin-bottom:7px;letter-spacing:.3px;
}
.input-wrap{position:relative;}
.input-wrap .icon{
  position:absolute;left:14px;top:50%;transform:translateY(-50%);
  color:var(--brown);font-size:0.9rem;pointer-events:none;
}
.input-wrap input{
  width:100%;padding:13px 42px 13px 40px;border:2px solid #e0e0e0;
  border-radius:var(--radius-sm);font-size:1rem;color:var(--text);
  transition:border-color .2s;background:var(--white);font-family:inherit;
}
.input-wrap input:focus{outline:none;border-color:var(--gold);box-shadow:0 0 0 3px rgba(218,165,32,0.1);}
.eye-btn{
  position:absolute;right:12px;top:50%;transform:translateY(-50%);
  background:none;border:none;cursor:pointer;color:var(--text-muted);
  padding:6px;font-size:0.9rem;
}
.eye-btn:hover{color:var(--brown);}
.error-box{
  background:#fff0f0;border:1px solid #ffcdd2;border-radius:var(--radius-sm);
  padding:12px 16px;margin-bottom:18px;color:#c62828;
  font-size:0.85rem;display:flex;gap:10px;align-items:flex-start;
}
.hint-box{
  background:var(--cream);border:1px solid rgba(218,165,32,0.3);border-radius:var(--radius-sm);
  padding:10px 14px;margin-bottom:18px;font-size:0.8rem;color:var(--brown);
}
.hint-box strong{display:block;margin-bottom:4px;}
.hint-row{display:flex;justify-content:space-between;padding:2px 0;}
.btn-login{
  width:100%;padding:15px;
  background:linear-gradient(135deg,var(--brown),var(--gold));
  color:var(--white);border:none;border-radius:var(--radius-sm);
  font-size:1rem;font-weight:700;cursor:pointer;
  transition:all .3s;font-family:inherit;
  box-shadow:0 4px 15px rgba(139,69,19,0.35);letter-spacing:.5px;
}
.btn-login:hover{transform:translateY(-2px);box-shadow:0 8px 25px rgba(139,69,19,0.45);}
.btn-login:active{transform:translateY(0);}
.login-footer{
  text-align:center;padding:14px 30px 22px;
  color:var(--text-muted);font-size:0.75rem;
}
@media(max-width:440px){
  .login-body{padding:24px 18px 18px;}
  .login-header{padding:24px 18px;}
}
</style>
</head>
<body>
<div class="login-card">
  <div class="login-header">
    <div class="logo-ring">
      <?php if (file_exists('images/icon.png')): ?>
        <img src="images/icon.png" alt="Logo">
      <?php else: ?>
        <i class="fas fa-cross"></i>
      <?php endif; ?>
    </div>
    <h1>አጸደ ትጉሃን ሰንበት ትምህርት ቤት</h1>
    <p>Inventory &amp; POS System</p>
  </div>

  <div class="login-body">
    <div class="eth-date-box">
      <i class="fas fa-calendar-alt"></i>
      <?= htmlspecialchars($eth['eth_date'], ENT_QUOTES, 'UTF-8') ?>
      &nbsp;&nbsp;|&nbsp;&nbsp;
      <i class="fas fa-clock"></i>
      <span id="liveClock"><?= htmlspecialchars($eth['greg_time'], ENT_QUOTES, 'UTF-8') ?></span>
    </div>

    <?php if ($error): ?>
    <div class="error-box">
      <i class="fas fa-exclamation-circle" style="flex-shrink:0;margin-top:2px;"></i>
      <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <?php endif; ?>

    <!-- Default credentials hint -->
    <div class="hint-box">
      <strong><i class="fas fa-info-circle"></i> Default Login Credentials</strong>
      <div class="hint-row"><span>Admin:</span><span><code>admin</code> / <code>admin123</code></span></div>
      <div class="hint-row"><span>Seller:</span><span><code>selam</code> / <code>admin123</code></span></div>
    </div>

    <form method="POST" autocomplete="off">
      <?= csrf_field() ?>

      <div class="form-group">
        <label for="username">Username</label>
        <div class="input-wrap">
          <i class="fas fa-user icon"></i>
          <input
            type="text"
            id="username"
            name="username"
            placeholder="Enter username"
            required
            autocomplete="username"
            value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
          >
        </div>
      </div>

      <div class="form-group">
        <label for="pwd">Password</label>
        <div class="input-wrap">
          <i class="fas fa-lock icon"></i>
          <input
            type="password"
            id="pwd"
            name="password"
            placeholder="Enter password"
            required
            autocomplete="current-password"
          >
          <button type="button" class="eye-btn" onclick="togglePwd()" title="Show/Hide">
            <i class="fas fa-eye" id="eyeIcon"></i>
          </button>
        </div>
      </div>

      <button type="submit" class="btn-login">
        <i class="fas fa-sign-in-alt"></i>&nbsp; Login / ግባ
      </button>
    </form>
  </div>

  <div class="login-footer">
    ✝ አጸደ ትጉሃን ሰንበት ትምህርት ቤት &copy; <?= date('Y') ?>
  </div>
</div>

<script>
function togglePwd() {
  const p = document.getElementById('pwd');
  const i = document.getElementById('eyeIcon');
  if (p.type === 'password') {
    p.type = 'text';
    i.className = 'fas fa-eye-slash';
  } else {
    p.type = 'password';
    i.className = 'fas fa-eye';
  }
}

// Live clock
setInterval(function() {
  const now = new Date();
  let h = now.getHours() % 12 || 12;
  const m = String(now.getMinutes()).padStart(2, '0');
  const s = String(now.getSeconds()).padStart(2, '0');
  const ap = now.getHours() < 12 ? 'AM' : 'PM';
  const el = document.getElementById('liveClock');
  if (el) el.textContent = String(h).padStart(2,'0') + ':' + m + ':' + s + ' ' + ap;
}, 1000);
</script>
</body>
</html>
