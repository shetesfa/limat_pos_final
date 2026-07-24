<?php
require_once 'config.php';
requireAdmin();

$branch_id   = (int)($_SESSION['branch_id'] ?? 1);
$biz_name    = getSetting($conn, 'business_name', 'አጸደ ትጉሃን');
$active_page = 'expenses';
$eth_now     = getCurrentEthDatetime();
$msg = ''; $msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_expense') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { $msg='CSRF error'; $msg_type='danger'; }
    else {
        $amount  = (float)($_POST['amount'] ?? 0);
        $cat     = trim($_POST['category'] ?? '');
        $desc    = trim($_POST['description'] ?? '');
        $edate   = trim($_POST['expense_date'] ?? date('Y-m-d'));
        $uid     = $_SESSION['user_id'];
        if ($amount > 0 && $cat) {
            $stmt = $conn->prepare("INSERT INTO expenses (amount,category,description,created_by,branch_id,expense_date) VALUES (?,?,?,?,?,?)");
            $stmt->bind_param('dssiss', $amount, $cat, $desc, $uid, $branch_id, $edate);
            $stmt->execute(); $stmt->close();
            auditLog($conn, 'ADD_EXPENSE', 'expenses', $conn->insert_id, null, json_encode(['amount'=>$amount,'category'=>$cat]), "ወጪ: $cat — $amount ብር");
            $msg = '✓ ወጪ ተጨምሯል'; $msg_type = 'success';
        } else { $msg = 'መጠን እና ምክንያት ያስፈልጋል'; $msg_type = 'danger'; }
    }
}

// Delete expense
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_expense') {
    header('Content-Type: application/json');
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false]); exit(); }
    $eid = (int)($_POST['expense_id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM expenses WHERE id=? AND branch_id=?");
    $stmt->bind_param('ii', $eid, $branch_id);
    $ok = $stmt->execute(); $stmt->close();
    if ($ok) auditLog($conn, 'DELETE_EXPENSE', 'expenses', $eid, null, null, 'ወጪ ተሰርዟል');
    echo json_encode(['success'=>$ok]); exit();
}

// Period filter (reuse the same Ethiopian-aware presets used elsewhere)
$period = $_GET['period'] ?? 'this_month';
[$from, $to] = resolveDateRangePreset($period, $_GET['from'] ?? null, $_GET['to'] ?? null);

$expenses = [];
$eq = $conn->query("SELECT e.*,u.full_name FROM expenses e LEFT JOIN users u ON u.id=e.created_by
                     WHERE e.branch_id=$branch_id AND e.expense_date BETWEEN '$from' AND '$to'
                     ORDER BY e.expense_date DESC, e.id DESC");
$total_expenses = 0;
while ($r = $eq->fetch_assoc()) { $r['eth_date'] = ethDateDisplay($r['expense_date']); $expenses[] = $r; $total_expenses += (float)$r['amount']; }
?><!DOCTYPE html>
<html lang="am">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ወጪዎች — <?= h($biz_name) ?></title>
<link rel="icon" type="image/png" href="images/icon.png">
<?php include 'layout_start.php'; ?>
<style>
.expense-item{display:flex;align-items:center;justify-content:space-between;padding:12px 0;border-bottom:1px solid var(--border-light);}
.expense-item:last-child{border-bottom:none;}
.filter-bar{background:var(--card);border-radius:var(--radius);padding:14px 18px;margin-bottom:18px;box-shadow:var(--shadow);}
.filter-row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;}
</style>
</head>
<body>
<?php include 'nav.php'; ?>
<div class="main-content">
  <div class="page-header">
    <div>
      <h1><i class="fas fa-receipt"></i> ወጪዎች</h1>
      <div class="page-title-sub"><?= h($eth_now['eth_date']) ?></div>
    </div>
  </div>

  <?php if ($msg): ?>
  <div class="alert alert-<?= $msg_type ?> fade-in"><i class="fas fa-<?= $msg_type==='success'?'check-circle':'exclamation-circle' ?>"></i> <?= h($msg) ?></div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:1fr 1.4fr;gap:20px;" class="two-col-settings">
    <div class="card">
      <div class="card-header"><span class="card-title"><i class="fas fa-plus-circle"></i> ወጪ ጨምር</span></div>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_expense">
        <div class="form-group">
          <label>መጠን (ብር) *</label>
          <div class="input-group"><span class="input-icon"><i class="fas fa-coins"></i></span>
          <input type="number" name="amount" class="form-control" min="0.01" step="0.01" placeholder="0.00" required></div>
        </div>
        <div class="form-group">
          <label>ምክንያት *</label>
          <input type="text" name="category" class="form-control" list="expenseCategories" placeholder="ለምሳሌ: ኪራይ, ደሞዝ..." required>
          <datalist id="expenseCategories">
            <option value="ኪራይ"></option><option value="ደሞዝ"></option><option value="ትራንስፖርት"></option>
            <option value="ቢሮ ቁሳቁስ"></option><option value="ውሃ/መብራት"></option><option value="ጥገና"></option><option value="ሌሎች"></option>
          </datalist>
        </div>
        <div class="form-group">
          <label>ቀን</label>
          <input type="date" name="expense_date" class="form-control" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-group">
          <label>ማስታወሻ</label>
          <textarea name="description" class="form-control" rows="2" placeholder="ወጪ መግለጫ..."></textarea>
        </div>
        <button type="submit" class="btn btn-primary btn-full"><i class="fas fa-plus"></i> ጨምር</button>
      </form>
    </div>

    <div class="card">
      <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <span class="card-title"><i class="fas fa-list"></i> ወጪዎች</span>
        <span style="font-weight:800;color:var(--red);">ጠቅላላ: <?= money($total_expenses) ?> ብር</span>
      </div>
      <div class="filter-row" style="margin-bottom:14px;">
        <?php
          $presets = ['today'=>'ዛሬ','this_week'=>'ይህ ሳምንት','this_month'=>'ይህ ወር','this_year'=>'ይህ ዓመት'];
          foreach ($presets as $key=>$label):
            $qs = http_build_query(array_merge($_GET, ['period'=>$key]));
        ?>
          <a href="?<?= h($qs) ?>" class="btn <?= $period===$key?'btn-primary':'btn-outline' ?> btn-sm"><?= h($label) ?></a>
        <?php endforeach; ?>
      </div>
      <?php if (empty($expenses)): ?>
        <div style="text-align:center;padding:32px;color:var(--text-muted);">ወጪ አልተጨመረም</div>
      <?php else: foreach($expenses as $e): ?>
        <div class="expense-item" id="exp_<?= $e['id'] ?>">
          <div>
            <div style="font-size:0.9rem;font-weight:600;"><?= h($e['category']) ?></div>
            <div style="font-size:0.75rem;color:var(--text-muted);">
              <?= h($e['eth_date']) ?> · <?= h($e['full_name']??'') ?>
              <?php if($e['description']): ?> — <?= h(substr($e['description'],0,40)) ?><?php endif; ?>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:10px;">
            <div style="font-size:0.95rem;font-weight:800;color:var(--red);"><?= money($e['amount']) ?> ብር</div>
            <button class="btn btn-sm btn-outline" onclick="deleteExpense(<?= $e['id'] ?>)" title="ሰርዝ"><i class="fas fa-trash"></i></button>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div><!-- /main-content -->

<script>
const CSRF_TOKEN = '<?= csrf_token() ?>';
async function deleteExpense(id) {
  if (!confirm('ይህን ወጪ መሰረዝ ይፈልጋሉ?')) return;
  const fd = new FormData();
  fd.append('action','delete_expense'); fd.append('expense_id', id); fd.append('csrf_token', CSRF_TOKEN);
  const res = await fetch('expense.php', {method:'POST', body:fd});
  const data = await res.json();
  if (data.success) { const el = document.getElementById('exp_'+id); if (el) el.remove(); }
  else alert('ስህተት ተከስቷል');
}
</script>
</body>
</html>
