<?php
require_once 'config.php';
requireAdmin();

$branch_id   = (int)($_SESSION['branch_id'] ?? 1);
$biz_name    = getSetting($conn, 'business_name', 'አጸደ ትጉሃን');
$active_page = 'history';
$eth_now     = getCurrentEthDatetime();

$view = $_GET['view'] ?? 'audit';
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
$from = date('Y-m-d', strtotime($from));
$to   = date('Y-m-d', strtotime($to));
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 30;
$off  = ($page - 1) * $per;

// ── Audit log ─────────────────────────────────────────────────
$audit = []; $audit_total = 0;
if ($view === 'audit') {
    $action_filter = trim($_GET['action_filter'] ?? '');
    $user_filter   = (int)($_GET['user_filter'] ?? 0);
    $where = "WHERE DATE(a.created_at) BETWEEN '$from' AND '$to' AND a.branch_id=$branch_id";
    if ($action_filter) $where .= " AND a.action='" . $conn->real_escape_string($action_filter) . "'";
    if ($user_filter)   $where .= " AND a.user_id=$user_filter";

    $ct = $conn->query("SELECT COUNT(*) as c FROM audit_log a $where")->fetch_assoc();
    $audit_total = $ct['c'];

    $r = $conn->query("SELECT a.*, u.full_name FROM audit_log a LEFT JOIN users u ON u.id=a.user_id $where ORDER BY a.created_at DESC LIMIT $per OFFSET $off");
    while($x=$r->fetch_assoc()) {
        $x['eth_date'] = ethDateDisplay($x['created_at']);
        $x['time_12h'] = time12hr($x['created_at']);
        $audit[] = $x;
    }

    // Available actions for filter
    $actions_r = $conn->query("SELECT DISTINCT action FROM audit_log ORDER BY action");
    $available_actions = [];
    while($ar=$actions_r->fetch_assoc()) $available_actions[]=$ar['action'];

    // Available users
    $users_r = $conn->query("SELECT id,full_name FROM users ORDER BY full_name");
    $available_users = [];
    while($ur=$users_r->fetch_assoc()) $available_users[]=$ur;
}

// ── Product edit history ───────────────────────────────────────
$prod_edits = []; $prod_edits_total = 0;
if ($view === 'product') {
    $pid_filter = (int)($_GET['pid'] ?? 0);
    $where = "WHERE DATE(peh.created_at) BETWEEN '$from' AND '$to'";
    if ($pid_filter) $where .= " AND peh.product_id=$pid_filter";

    $ct = $conn->query("SELECT COUNT(*) as c FROM product_edit_history peh $where")->fetch_assoc();
    $prod_edits_total = $ct['c'];

    $r = $conn->query(
        "SELECT peh.*, u.full_name, p.name as product_name
         FROM product_edit_history peh
         LEFT JOIN users u ON u.id=peh.user_id
         LEFT JOIN products p ON p.id=peh.product_id
         $where ORDER BY peh.created_at DESC LIMIT $per OFFSET $off"
    );
    while($x=$r->fetch_assoc()) {
        $x['eth_date'] = ethDateDisplay($x['created_at']);
        $x['time_12h'] = time12hr($x['created_at']);
        $prod_edits[] = $x;
    }
}

// ── User activity ─────────────────────────────────────────────
$user_activity = []; $ua_total = 0;
if ($view === 'users') {
    $uid_filter = (int)($_GET['uid'] ?? 0);
    $where = "WHERE DATE(a.created_at) BETWEEN '$from' AND '$to' AND a.branch_id=$branch_id AND a.action IN ('LOGIN','LOGOUT','SALE','EDIT_PRODUCT','RECEIVE_STOCK','ADD_PRODUCT')";
    if ($uid_filter) $where .= " AND a.user_id=$uid_filter";

    $ct = $conn->query("SELECT COUNT(*) as c FROM audit_log a $where")->fetch_assoc();
    $ua_total = $ct['c'];

    $r = $conn->query(
        "SELECT a.* FROM audit_log a $where ORDER BY a.created_at DESC LIMIT $per OFFSET $off"
    );
    while($x=$r->fetch_assoc()) {
        $x['eth_date'] = ethDateDisplay($x['created_at']);
        $x['time_12h'] = time12hr($x['created_at']);
        $user_activity[] = $x;
    }

    $users_r = $conn->query("SELECT id,full_name FROM users ORDER BY full_name");
    $available_users = [];
    while($ur=$users_r->fetch_assoc()) $available_users[]=$ur;
}

function actionBadge($action) {
    $map = [
        'LOGIN'=>['badge-green','fa-sign-in-alt'],
        'LOGOUT'=>['badge-red','fa-sign-out-alt'],
        'SALE'=>['badge-gold','fa-cash-register'],
        'ADD_PRODUCT'=>['badge-blue','fa-plus'],
        'EDIT_PRODUCT'=>['badge-purple','fa-edit'],
        'RECEIVE_STOCK'=>['badge-blue','fa-truck'],
        'TOGGLE_PRODUCT'=>['badge-gold','fa-toggle-on'],
        'ADD_USER'=>['badge-blue','fa-user-plus'],
        'EDIT_USER'=>['badge-purple','fa-user-edit'],
    ];
    $c = $map[$action] ?? ['badge-gold','fa-circle'];
    return '<span class="badge '.$c[0].'"><i class="fas '.$c[1].'"></i> '.htmlspecialchars($action, ENT_QUOTES, 'UTF-8').'</span>';
}
?><!DOCTYPE html>
<html lang="am">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ታሪክ — <?= h($biz_name) ?></title>
<link rel="icon" type="image/png" href="images/icon.png">
<?php include 'layout_start.php'; ?>
<style>
.hist-tabs{display:flex;gap:6px;margin-bottom:20px;flex-wrap:wrap;}
.hist-tab{
  padding:9px 18px;border-radius:20px;border:2px solid var(--border);
  background:var(--card);cursor:pointer;font-size:0.82rem;font-weight:600;
  color:var(--text-muted);transition:all var(--transition);text-decoration:none;
  display:inline-flex;align-items:center;gap:6px;
}
.hist-tab.active{background:linear-gradient(135deg,var(--brown),var(--gold));color:#fff;border-color:transparent;}
.hist-tab:hover:not(.active){border-color:var(--gold);color:var(--brown);}
.hist-card{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);margin-bottom:14px;overflow:hidden;border-left:4px solid var(--gold);}
.hist-card.login{border-color:var(--green);}
.hist-card.sale{border-color:var(--blue);}
.hist-card.edit{border-color:var(--purple);}
.hist-card.danger{border-color:var(--red);}
.hist-card-header{
  display:flex;align-items:center;gap:10px;padding:12px 16px;
  cursor:pointer;
}
.hist-card-header:hover{background:var(--gold-pale);}
.hist-info{flex:1;min-width:0;}
.hist-action{font-size:0.88rem;font-weight:700;color:var(--text);}
.hist-meta{font-size:0.75rem;color:var(--text-muted);margin-top:2px;}
.hist-time{font-size:0.75rem;color:var(--text-muted);text-align:right;white-space:nowrap;}
.hist-body{
  display:none;padding:14px 16px;border-top:1px solid var(--border);
  background:var(--bg);animation:fadeIn .2s ease;
}
.hist-body.open{display:block;}
.hist-detail-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;}
.hist-detail{background:var(--card);border-radius:var(--radius-sm);padding:10px 14px;}
.hist-detail-label{font-size:0.72rem;color:var(--text-muted);margin-bottom:3px;}
.hist-detail-val{font-size:0.85rem;font-weight:600;color:var(--text);word-break:break-word;}
.old-new{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:10px;}
.old-box{background:#fff5f5;border:1px solid #ffd0d0;border-radius:var(--radius-sm);padding:10px;font-size:0.82rem;}
.new-box{background:#f5fff5;border:1px solid #d0ffd0;border-radius:var(--radius-sm);padding:10px;font-size:0.82rem;}
.old-box strong,.new-box strong{display:block;margin-bottom:4px;font-size:0.72rem;text-transform:uppercase;letter-spacing:.5px;}
.old-box strong{color:var(--red);}
.new-box strong{color:var(--green);}
.filter-strip{background:var(--card);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;box-shadow:var(--shadow);}
.filter-row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;}
.pager{display:flex;gap:8px;align-items:center;justify-content:center;padding:16px 0;}
.pager a,.pager span{
  padding:8px 14px;border-radius:var(--radius-sm);font-size:0.85rem;font-weight:600;
  text-decoration:none;border:1px solid var(--border);
}
.pager a{background:var(--card);color:var(--text);}
.pager a:hover{border-color:var(--gold);color:var(--brown);}
.pager span{background:var(--gold);color:#fff;border-color:var(--gold);}
.device-icon{font-size:0.9rem;}
.ip-chip{background:var(--blue-light);color:var(--blue);border-radius:4px;padding:2px 6px;font-size:0.72rem;font-family:monospace;}
</style>
</head>
<body>
<?php include 'nav.php'; ?>
<div class="main-content">
  <!-- Header -->
  <div class="page-header">
    <div>
      <h1><i class="fas fa-history"></i> ታሪክ & ሎግ</h1>
      <div class="page-title-sub">ሙሉ የሥርዓት ታሪክ — <?= h($eth_now['eth_date']) ?></div>
    </div>
    <button class="btn btn-outline btn-sm" onclick="window.print()"><i class="fas fa-print"></i> አትም</button>
  </div>

  <!-- Tabs -->
  <div class="hist-tabs">
    <a href="?view=audit&from=<?= $from ?>&to=<?= $to ?>" class="hist-tab <?= $view==='audit'?'active':'' ?>">
      <i class="fas fa-shield-alt"></i> Audit Log
    </a>
    <a href="?view=product&from=<?= $from ?>&to=<?= $to ?>" class="hist-tab <?= $view==='product'?'active':'' ?>">
      <i class="fas fa-boxes"></i> የምርት ለውጦች
    </a>
    <a href="?view=users&from=<?= $from ?>&to=<?= $to ?>" class="hist-tab <?= $view==='users'?'active':'' ?>">
      <i class="fas fa-users"></i> የተጠቃሚ ታሪክ
    </a>
  </div>

  <!-- Date + Filters -->
  <div class="filter-strip">
    <form method="GET" action="">
      <input type="hidden" name="view" value="<?= h($view) ?>">
      <div class="filter-row">
        <div class="form-group" style="margin:0;flex:1;min-width:130px;">
          <label style="font-size:0.78rem;">ከ</label>
          <input type="date" name="from" class="form-control" value="<?= h($from) ?>">
        </div>
        <div class="form-group" style="margin:0;flex:1;min-width:130px;">
          <label style="font-size:0.78rem;">እስከ</label>
          <input type="date" name="to" class="form-control" value="<?= h($to) ?>">
        </div>

        <?php if ($view === 'audit'): ?>
        <div class="form-group" style="margin:0;flex:1;min-width:140px;">
          <label style="font-size:0.78rem;">ድርጊት</label>
          <select name="action_filter" class="form-control">
            <option value="">ሁሉም</option>
            <?php foreach($available_actions??[] as $aa): ?>
            <option value="<?= h($aa) ?>" <?= ($_GET['action_filter']??'')===$aa?'selected':'' ?>><?= h($aa) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="margin:0;flex:1;min-width:140px;">
          <label style="font-size:0.78rem;">ተጠቃሚ</label>
          <select name="user_filter" class="form-control">
            <option value="">ሁሉም</option>
            <?php foreach($available_users??[] as $au): ?>
            <option value="<?= h($au['id']) ?>" <?= ($_GET['user_filter']??'')==$au['id']?'selected':'' ?>><?= h($au['full_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> ፈልግ</button>
        <a href="?view=<?= h($view) ?>&from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-outline">ወር</a>
      </div>
    </form>
  </div>

  <?php
  // ── AUDIT LOG VIEW ─────────────────────────────────────────────
  if ($view === 'audit'):
    $total = $audit_total;
    $pages = max(1, ceil($total / $per));
  ?>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
    <div style="font-size:0.85rem;color:var(--text-muted);"><?= $total ?> ሎጎች ተገኝቷል</div>
    <div style="font-size:0.8rem;color:var(--text-muted);">ገጽ <?= $page ?> / <?= $pages ?></div>
  </div>

  <?php if (empty($audit)): ?>
    <div style="text-align:center;padding:48px;color:var(--text-muted);"><i class="fas fa-inbox" style="font-size:3rem;display:block;margin-bottom:12px;color:var(--border);"></i>ሎግ አልተገኘም</div>
  <?php else: foreach($audit as $a):
    $cardClass = match($a['action']){
      'LOGIN','LOGOUT' => 'login',
      'SALE' => 'sale',
      'EDIT_PRODUCT','EDIT_USER' => 'edit',
      default => ''
    };
    $deviceIcon = match($a['device_type']??''){
      'Mobile' => 'fa-mobile-alt',
      'Tablet' => 'fa-tablet-alt',
      default  => 'fa-desktop'
    };
  ?>
  <div class="hist-card <?= $cardClass ?>" id="hist_<?= $a['id'] ?>">
    <div class="hist-card-header" onclick="toggleHist('body_<?= $a['id'] ?>')">
      <div style="width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:var(--gold-pale);flex-shrink:0;">
        <i class="fas fa-user" style="color:var(--brown);"></i>
      </div>
      <div class="hist-info">
        <div class="hist-action">
          <?= actionBadge($a['action']) ?>
          &nbsp;<?= h($a['username'] ?? 'System') ?>
        </div>
        <div class="hist-meta">
          <span class="ip-chip"><?= h($a['ip_address']??'') ?></span>
          &nbsp;<i class="fas <?= $deviceIcon ?> device-icon"></i> <?= h($a['device_type']??'') ?>
          <?php if($a['description']): ?>&nbsp;&bull;&nbsp;<?= h(substr($a['description'],0,60)) ?><?php endif; ?>
        </div>
      </div>
      <div class="hist-time">
        <div><?= h($a['eth_date']) ?></div>
        <div><?= h($a['time_12h']) ?></div>
        <i class="fas fa-chevron-down" id="arrow_<?= $a['id'] ?>" style="color:var(--text-muted);transition:.2s;"></i>
      </div>
    </div>
    <div class="hist-body" id="body_<?= $a['id'] ?>">
      <div class="hist-detail-grid">
        <div class="hist-detail"><div class="hist-detail-label">ድርጊት</div><div class="hist-detail-val"><?= h($a['action']) ?></div></div>
        <div class="hist-detail"><div class="hist-detail-label">ሠንጠረዥ</div><div class="hist-detail-val"><?= h($a['table_name']??'—') ?></div></div>
        <div class="hist-detail"><div class="hist-detail-label">Record ID</div><div class="hist-detail-val"><?= h($a['record_id']??'—') ?></div></div>
        <div class="hist-detail"><div class="hist-detail-label">IP</div><div class="hist-detail-val"><code><?= h($a['ip_address']??'—') ?></code></div></div>
        <div class="hist-detail"><div class="hist-detail-label">መሣሪያ</div><div class="hist-detail-val"><i class="fas <?= $deviceIcon ?>"></i> <?= h($a['device_type']??'—') ?></div></div>
        <div class="hist-detail"><div class="hist-detail-label">ቀን/ሰዓት</div><div class="hist-detail-val"><?= h($a['eth_date']) ?> <?= h($a['time_12h']) ?></div></div>
      </div>
      <?php if ($a['old_value'] || $a['new_value']): ?>
      <div class="old-new">
        <div class="old-box">
          <strong>⬅ ቀዳሚ ዋጋ</strong>
          <code style="font-size:0.8rem;word-break:break-all;"><?= h($a['old_value']??'—') ?></code>
        </div>
        <div class="new-box">
          <strong>➡ አዲስ ዋጋ</strong>
          <code style="font-size:0.8rem;word-break:break-all;"><?= h($a['new_value']??'—') ?></code>
        </div>
      </div>
      <?php endif; ?>
      <?php if ($a['description']): ?>
      <div style="margin-top:10px;padding:10px;background:var(--card);border-radius:var(--radius-sm);font-size:0.85rem;color:var(--text-muted);">
        <i class="fas fa-comment-dots"></i> <?= h($a['description']) ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; endif; ?>

  <?php
  // ── PRODUCT EDIT HISTORY ───────────────────────────────────────
  elseif ($view === 'product'):
    $total = $prod_edits_total;
    $pages = max(1, ceil($total / $per));
  ?>
  <div style="font-size:0.85rem;color:var(--text-muted);margin-bottom:12px;"><?= $total ?> ለውጦች ተገኝቷል</div>

  <?php if (empty($prod_edits)): ?>
    <div style="text-align:center;padding:48px;color:var(--text-muted);"><i class="fas fa-inbox" style="font-size:3rem;display:block;margin-bottom:12px;color:var(--border);"></i>ለውጥ አልተገኘም</div>
  <?php else: foreach($prod_edits as $e): ?>
  <div class="hist-card edit">
    <div class="hist-card-header" onclick="toggleHist('pbody_<?= $e['id'] ?>')">
      <div style="width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:var(--purple-light);flex-shrink:0;">
        <i class="fas fa-box" style="color:var(--purple);"></i>
      </div>
      <div class="hist-info">
        <div class="hist-action">
          <span class="badge badge-purple"><i class="fas fa-edit"></i> <?= h($e['field_changed']??'EDIT') ?></span>
          &nbsp;<strong><?= h($e['product_name']??'—') ?></strong>
        </div>
        <div class="hist-meta">
          <?= h($e['full_name']??'System') ?>
          &nbsp;&bull;&nbsp;<span class="ip-chip"><?= h($e['ip_address']??'') ?></span>
        </div>
      </div>
      <div class="hist-time">
        <div><?= h($e['eth_date']) ?></div>
        <div><?= h($e['time_12h']) ?></div>
      </div>
    </div>
    <div class="hist-body" id="pbody_<?= $e['id'] ?>">
      <div class="hist-detail-grid">
        <div class="hist-detail"><div class="hist-detail-label">ምርት</div><div class="hist-detail-val"><?= h($e['product_name']??'—') ?></div></div>
        <div class="hist-detail"><div class="hist-detail-label">ተጠቃሚ</div><div class="hist-detail-val"><?= h($e['full_name']??'—') ?></div></div>
        <div class="hist-detail"><div class="hist-detail-label">IP</div><div class="hist-detail-val"><code><?= h($e['ip_address']??'—') ?></code></div></div>
        <div class="hist-detail"><div class="hist-detail-label">መሣሪያ</div><div class="hist-detail-val"><?= h($e['device_type']??'—') ?></div></div>
      </div>
      <div class="old-new">
        <div class="old-box"><strong>⬅ ቀዳሚ</strong><?= h($e['old_value']??'—') ?></div>
        <div class="new-box"><strong>➡ አዲስ</strong><?= h($e['new_value']??'—') ?></div>
      </div>
      <?php if ($e['reason']): ?>
      <div style="margin-top:10px;padding:10px;background:var(--card);border-radius:var(--radius-sm);font-size:0.85rem;">
        <i class="fas fa-comment-dots" style="color:var(--gold)"></i> ምክንያት: <?= h($e['reason']) ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; endif; ?>

  <?php
  // ── USER ACTIVITY ──────────────────────────────────────────────
  elseif ($view === 'users'):
    $total = $ua_total;
    $pages = max(1, ceil($total / $per));
  ?>
  <!-- User filter -->
  <div style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap;">
    <a href="?view=users&from=<?= $from ?>&to=<?= $to ?>" class="btn btn-sm <?= !($_GET['uid']??0)?'btn-primary':'btn-outline' ?>">ሁሉም</a>
    <?php foreach($available_users??[] as $u): ?>
    <a href="?view=users&from=<?= $from ?>&to=<?= $to ?>&uid=<?= $u['id'] ?>" class="btn btn-sm <?= ($_GET['uid']??0)==$u['id']?'btn-primary':'btn-outline' ?>"><?= h($u['full_name']) ?></a>
    <?php endforeach; ?>
  </div>
  <div style="font-size:0.85rem;color:var(--text-muted);margin-bottom:12px;"><?= $total ?> ድርጊቶች</div>

  <?php if (empty($user_activity)): ?>
    <div style="text-align:center;padding:48px;color:var(--text-muted);"><i class="fas fa-inbox" style="font-size:3rem;display:block;margin-bottom:12px;color:var(--border);"></i>ድርጊት አልተገኘም</div>
  <?php else: foreach($user_activity as $a):
    $deviceIcon = match($a['device_type']??''){
      'Mobile' => 'fa-mobile-alt', 'Tablet' => 'fa-tablet-alt', default => 'fa-desktop'
    };
  ?>
  <div class="hist-card <?= in_array($a['action'],['LOGIN','LOGOUT'])?'login':($a['action']==='SALE'?'sale':'edit') ?>">
    <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;">
      <div style="width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:var(--gold-pale);flex-shrink:0;">
        <i class="fas <?= $deviceIcon ?>" style="color:var(--brown);"></i>
      </div>
      <div style="flex:1;min-width:0;">
        <div style="font-size:0.88rem;font-weight:700;">
          <?= actionBadge($a['action']) ?> &nbsp;<?= h($a['username']??'') ?>
        </div>
        <div style="font-size:0.75rem;color:var(--text-muted);margin-top:2px;">
          <span class="ip-chip"><?= h($a['ip_address']??'') ?></span>
          &nbsp;&bull;&nbsp;<?= h($a['description']??'') ?>
        </div>
      </div>
      <div style="text-align:right;font-size:0.75rem;color:var(--text-muted);white-space:nowrap;">
        <?= h($a['eth_date']) ?><br><?= h($a['time_12h']) ?>
      </div>
    </div>
  </div>
  <?php endforeach; endif; ?>
  <?php endif; ?>

  <!-- Pagination -->
  <?php if (($pages??1) > 1):
    $baseUrl = '?view='.urlencode($view).'&from='.$from.'&to='.$to.
      (!empty($_GET['action_filter'])?'&action_filter='.urlencode($_GET['action_filter']):'').
      (!empty($_GET['user_filter'])?'&user_filter='.urlencode($_GET['user_filter']):'').
      (!empty($_GET['uid'])?'&uid='.urlencode($_GET['uid']):'');
  ?>
  <div class="pager">
    <?php if($page>1): ?><a href="<?= $baseUrl ?>&page=<?= $page-1 ?>">← ቀዳሚ</a><?php endif; ?>
    <span><?= $page ?> / <?= $pages ?></span>
    <?php if($page<$pages): ?><a href="<?= $baseUrl ?>&page=<?= $page+1 ?>">ቀጣይ →</a><?php endif; ?>
  </div>
  <?php endif; ?>

</div><!-- /main-content -->

<script>
function toggleHist(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.toggle('open');
  // rotate arrow
  const histId = id.replace('body_','').replace('pbody_','');
  const arrow = document.getElementById('arrow_'+histId);
  if (arrow) arrow.style.transform = el.classList.contains('open') ? 'rotate(180deg)' : '';
}
</script>
</body>
</html>
