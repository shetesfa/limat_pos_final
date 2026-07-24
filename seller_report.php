<?php
require_once 'config.php';
requireLogin();

$user_id     = (int)$_SESSION['user_id'];
$role        = $_SESSION['role'] ?? 'seller';
$is_admin    = ($role === 'admin');
$branch_id   = (int)($_SESSION['branch_id'] ?? 1);
$biz_name    = getSetting($conn, 'business_name', 'አጸደ ትጉሃን');
$user_name   = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'ሻጭ';
$active_page = 'receipts';
$eth_now     = getCurrentEthDatetime();

function formatQtyPhp($n) {
    $f = (float)$n;
    return (floor($f) == $f) ? (string)(int)$f : rtrim(rtrim(number_format($f, 2), '0'), '.');
}

// ── AJAX: receipt detail ────────────────────────────────────────
// Sellers can only ever open their own receipts. Admins can open any
// receipt in their branch (needed to browse everyone's sales below).
if (isset($_GET['api']) && $_GET['api'] === 'receipt' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $txn_id = (int)$_GET['id'];
    if ($is_admin) {
        $t = $conn->prepare(
            "SELECT t.*, u.full_name as seller_fullname FROM transactions t
             LEFT JOIN users u ON u.id=t.seller_id
             WHERE t.id=? AND t.branch_id=?"
        );
        $t->bind_param('ii', $txn_id, $branch_id);
    } else {
        $t = $conn->prepare(
            "SELECT t.*, u.full_name as seller_fullname FROM transactions t
             LEFT JOIN users u ON u.id=t.seller_id
             WHERE t.id=? AND t.seller_id=?"
        );
        $t->bind_param('ii', $txn_id, $user_id);
    }
    $t->execute();
    $txn = $t->get_result()->fetch_assoc(); $t->close();
    if (!$txn) { echo json_encode(['error'=>'Not found']); exit(); }

    $items_q = $conn->prepare(
        "SELECT ti.*, p.name as product_name, p.unit FROM transaction_items ti
         LEFT JOIN products p ON p.id=ti.product_id WHERE ti.transaction_id=?"
    );
    $items_q->bind_param('i', $txn_id); $items_q->execute();
    $items = $items_q->get_result()->fetch_all(MYSQLI_ASSOC); $items_q->close();

    // Group items by product (multiple batches → one line)
    $grouped = [];
    foreach ($items as $item) {
        $pid = $item['product_id'];
        if (!isset($grouped[$pid])) {
            $grouped[$pid] = ['name'=>$item['product_name'],'unit'=>$item['unit'],
                              'quantity'=>0,'unit_price'=>$item['unit_price'],'subtotal'=>0];
        }
        $grouped[$pid]['quantity'] += $item['quantity'];
        $grouped[$pid]['subtotal'] += $item['subtotal'];
    }

    $eth = ethDate($txn['transaction_date']);
    $txn['eth_date'] = $eth['display'];
    $txn['greg_time'] = time12hr($txn['transaction_date']);
    $txn['items'] = array_values($grouped);
    $txn['footer'] = getSetting($conn, 'receipt_footer', 'እግዚአብሔር ይባርክዎ! ✝');
    $txn['biz_name'] = $biz_name;
    $txn['biz_address'] = getSetting($conn, 'business_address', '');
    $txn['biz_phone'] = getSetting($conn, 'business_phone', '');
    echo json_encode($txn);
    exit();
}

// ── Filters ──────────────────────────────────────────────────────
$period = $_GET['period'] ?? 'today';
[$from, $to] = resolveDateRangePreset($period, $_GET['from'] ?? null, $_GET['to'] ?? null);
$receipt_search = trim($_GET['receipt_search'] ?? '');
$item_search    = trim($_GET['item_search'] ?? '');
$payment_filter = trim($_GET['payment_filter'] ?? '');
$seller_search  = $is_admin ? trim($_GET['seller_search'] ?? '') : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 15;
$off  = ($page - 1) * $per;

$where = "WHERE DATE(t.transaction_date) BETWEEN '$from' AND '$to' AND t.branch_id=$branch_id";
// Sellers are always locked to their own sales. Admins see everyone by
// default and can optionally narrow down by seller name below.
if (!$is_admin) {
    $where .= " AND t.seller_id=$user_id";
}
if ($receipt_search !== '') {
    $rs = $conn->real_escape_string($receipt_search);
    $where .= " AND t.receipt_number LIKE '%$rs%'";
}
if ($payment_filter !== '' && in_array($payment_filter, ['cash','bank','mobile','mixed'], true)) {
    $where .= " AND t.payment_method='" . $conn->real_escape_string($payment_filter) . "'";
}
if ($item_search !== '') {
    $is = $conn->real_escape_string($item_search);
    $where .= " AND EXISTS (SELECT 1 FROM transaction_items ti2 JOIN products p2 ON p2.id=ti2.product_id WHERE ti2.transaction_id=t.id AND p2.name LIKE '%$is%')";
}
if ($seller_search !== '') {
    $ss = $conn->real_escape_string($seller_search);
    $where .= " AND EXISTS (SELECT 1 FROM users u2 WHERE u2.id=t.seller_id AND u2.full_name LIKE '%$ss%')";
}

// ── Summary (aggregated straight from transactions — one row per sale,
//    so totals are never multiplied by how many items a sale had) ──
$sumRow = $conn->query("SELECT COALESCE(SUM(t.total_amount),0) as sales, COALESCE(SUM(t.discount),0) as disc, COUNT(*) as cnt FROM transactions t $where")->fetch_assoc();
$total_sales    = (float)$sumRow['sales'];
$total_discount = (float)$sumRow['disc'];
$txn_count      = (int)$sumRow['cnt'];
$avg_sale       = $txn_count > 0 ? $total_sales / $txn_count : 0;

// Items sold — safe to join here since we only sum the item quantity, not the transaction total
$itemsRow = $conn->query("SELECT COALESCE(SUM(ti.quantity),0) as qty FROM transaction_items ti JOIN transactions t ON t.id=ti.transaction_id $where")->fetch_assoc();
$items_sold = (float)$itemsRow['qty'];

// Payment method breakdown
$payRows = [];
$pr = $conn->query("SELECT t.payment_method, COALESCE(SUM(t.total_amount),0) as amt, COUNT(*) as cnt FROM transactions t $where GROUP BY t.payment_method");
while ($r = $pr->fetch_assoc()) $payRows[] = $r;
$payLabel = ['cash'=>'ጥሬ ገንዘብ','bank'=>'ባንክ','mobile'=>'ቴሌብር','mixed'=>'ድብልቅ'];

// Paginated transaction list
$countRow   = $conn->query("SELECT COUNT(*) as c FROM transactions t $where")->fetch_assoc();
$total_rows = (int)$countRow['c'];
$pages      = max(1, (int)ceil($total_rows / $per));

$list = [];
$sellerCol = $is_admin ? ", u.full_name as seller_name" : "";
$sellerJoin = $is_admin ? "LEFT JOIN users u ON u.id=t.seller_id" : "";
$lr = $conn->query(
    "SELECT t.id, t.receipt_number, t.transaction_date, t.total_amount, t.payment_method
            $sellerCol,
            (SELECT COALESCE(SUM(ti3.quantity),0) FROM transaction_items ti3 WHERE ti3.transaction_id=t.id) as item_qty,
            (SELECT COUNT(*) FROM transaction_items ti4 WHERE ti4.transaction_id=t.id) as item_lines
     FROM transactions t
     $sellerJoin
     $where
     ORDER BY t.transaction_date DESC
     LIMIT $per OFFSET $off"
);
while ($r = $lr->fetch_assoc()) {
    $r['eth_date'] = ethDateDisplay($r['transaction_date']);
    $r['time_12h'] = time12hr($r['transaction_date']);
    $list[] = $r;
}
?><!DOCTYPE html>
<html lang="am">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $is_admin ? 'ደረሰኞች' : 'የእኔ ሽያጭ' ?> — <?= h($biz_name) ?></title>
<link rel="icon" type="image/png" href="images/icon.png">
<?php include 'layout_start.php'; ?>
<style>
.sr-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin-bottom:20px;}
.sr-card{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);padding:16px 18px;border-left:5px solid var(--gold);}
.sr-card .lbl{font-size:0.76rem;color:var(--text-muted);font-weight:600;margin-bottom:6px;}
.sr-card .val{font-size:1.4rem;font-weight:800;color:var(--text);}
.sr-card.sales{border-color:#2E86C1;} .sr-card.sales .val{color:#2E86C1;}
.sr-card.avg{border-color:var(--gold);} .sr-card.avg .val{color:var(--gold);}
.sr-card.items{border-color:var(--purple);} .sr-card.items .val{color:var(--purple);}
.sr-card.count{border-color:var(--green);} .sr-card.count .val{color:var(--green);}
.balance-summary{background:var(--card);border-radius:var(--radius);padding:16px;margin-bottom:20px;box-shadow:var(--shadow);display:grid;grid-template-columns:repeat(3,1fr);gap:14px;}
@media (max-width:768px){.balance-summary{grid-template-columns:1fr;}}
.balance-item{text-align:center;padding:14px;border-radius:var(--radius-sm);}
.balance-label{font-size:0.75rem;color:var(--text-muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.4px;}
.balance-value{font-size:1.3rem;font-weight:800;}
.balance-sales{background:var(--green-light);color:var(--green);}
.balance-discount{background:#FFF3E0;color:#E65100;}
.balance-pay{background:var(--blue-light);color:var(--blue);}
.filter-bar{background:var(--card);border-radius:var(--radius);padding:14px 18px;margin-bottom:18px;box-shadow:var(--shadow);}
.filter-row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;}
.pay-breakdown{display:flex;gap:10px;flex-wrap:wrap;margin-top:6px;}
.pay-chip{background:var(--bg);border-radius:20px;padding:6px 14px;font-size:0.8rem;font-weight:600;}
.rpt-table-wrap{overflow-x:auto;border-radius:var(--radius-sm);}
.rpt-table{width:100%;border-collapse:collapse;font-size:0.86rem;}
.rpt-table th{text-align:left;padding:10px 12px;background:var(--bg);color:var(--text-muted);font-size:0.75rem;text-transform:uppercase;letter-spacing:.4px;}
.rpt-table td{padding:10px 12px;border-top:1px solid var(--border);}
.rpt-table td.num{text-align:right;font-variant-numeric:tabular-nums;}
.pager{display:flex;gap:8px;align-items:center;justify-content:center;padding:16px 0;}
.pager a,.pager span{padding:8px 14px;border-radius:var(--radius-sm);font-size:0.85rem;font-weight:600;text-decoration:none;border:1px solid var(--border);}
.pager a{background:var(--card);color:var(--text);}
.pager a:hover{border-color:var(--gold);color:var(--brown);}
.pager span{background:var(--gold);color:#fff;border-color:var(--gold);}
.receipt-box{font-family:'Courier New',monospace;font-size:0.85rem;max-width:320px;margin:0 auto;}
.r-header{text-align:center;border-bottom:1px dashed #ccc;padding-bottom:8px;margin-bottom:8px;}
.r-item{padding:4px 0;border-bottom:1px dotted #eee;}
.r-row{display:flex;justify-content:space-between;}
.r-total{font-weight:800;font-size:1rem;border-top:1px dashed #ccc;padding-top:6px;margin-top:4px;}
.r-footer{text-align:center;margin-top:10px;padding-top:8px;border-top:1px dashed #ccc;font-size:0.8rem;}
@media print{
  body *{visibility:hidden;}
  #receiptContent,#receiptContent *{visibility:visible;}
  #receiptContent{position:absolute;left:0;top:0;width:100%;}
}
</style>
</head>
<body>
<?php include 'nav.php'; ?>
<div class="main-content">
  <div class="page-header">
    <div>
      <h1><i class="fas fa-receipt"></i> <?= $is_admin ? 'ደረሰኞች' : 'የእኔ ሽያጭ' ?></h1>
      <div class="page-title-sub"><?= $is_admin ? 'ሁሉም ሻጮች' : h($user_name) ?> &bull; <?= h($eth_now['eth_date']) ?></div>
    </div>
  </div>

  <!-- Summary -->
  <div class="sr-grid">
    <div class="sr-card sales">
      <div class="lbl"><i class="fas fa-coins"></i> ጠቅላላ ሽያጭ</div>
      <div class="val"><?= money($total_sales) ?> ብር</div>
    </div>
    <div class="sr-card count">
      <div class="lbl"><i class="fas fa-shopping-cart"></i> ሽያጮች</div>
      <div class="val"><?= $txn_count ?></div>
    </div>
    <div class="sr-card avg">
      <div class="lbl"><i class="fas fa-balance-scale"></i> አማካይ ሽያጭ</div>
      <div class="val"><?= money($avg_sale) ?> ብር</div>
    </div>
    <div class="sr-card items">
      <div class="lbl"><i class="fas fa-box"></i> የተሸጡ ዕቃዎች</div>
      <div class="val"><?= (int)$items_sold ?></div>
    </div>
  </div>

  <!-- Balance-style summary -->
  <div class="balance-summary">
    <div class="balance-item balance-sales">
      <div class="balance-label">ጠቅላላ ሽያጭ</div>
      <div class="balance-value"><?= money($total_sales) ?> ብር</div>
    </div>
    <div class="balance-item balance-discount">
      <div class="balance-label">ጠቅላላ ቅናሽ</div>
      <div class="balance-value">− <?= money($total_discount) ?> ብር</div>
    </div>
    <div class="balance-item balance-pay">
      <div class="balance-label">ተጣራ ሽያጭ</div>
      <div class="balance-value"><?= money($total_sales - $total_discount) ?> ብር</div>
    </div>
  </div>

  <!-- Payment breakdown -->
  <div class="card section-gap" style="padding:18px;">
    <div class="card-header"><span class="card-title"><i class="fas fa-wallet"></i> በክፍያ ዓይነት</span></div>
    <div class="pay-breakdown">
      <?php if (empty($payRows)): ?>
        <div style="color:var(--text-muted);font-size:0.85rem;">ውሂብ የለም</div>
      <?php else: foreach ($payRows as $p): ?>
        <div class="pay-chip"><?= h($payLabel[$p['payment_method']] ?? $p['payment_method']) ?>: <?= money($p['amt']) ?> ብር (<?= $p['cnt'] ?>)</div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- Filters -->
  <div class="filter-bar section-gap">
    <div class="filter-row" style="margin-bottom:12px;">
      <?php
        $presets = ['today'=>'ዛሬ','yesterday'=>'ትላንት','this_week'=>'ይህ ሳምንት','this_month'=>'ይህ ወር','this_year'=>'ይህ ዓመት','custom'=>'ራስ-ሰር'];
        foreach ($presets as $key=>$label):
          $qs = http_build_query(array_merge($_GET, ['period'=>$key,'page'=>1]));
      ?>
        <a href="?<?= h($qs) ?>" class="btn <?= $period===$key?'btn-primary':'btn-outline' ?> btn-sm"><?= h($label) ?></a>
      <?php endforeach; ?>
    </div>
    <form method="GET">
      <input type="hidden" name="period" value="custom">
      <div class="filter-row">
        <div class="form-group" style="margin:0;flex:1;min-width:120px;">
          <label style="font-size:0.78rem;">ከ</label>
          <input type="date" name="from" class="form-control" value="<?= h($from) ?>">
        </div>
        <div class="form-group" style="margin:0;flex:1;min-width:120px;">
          <label style="font-size:0.78rem;">እስከ</label>
          <input type="date" name="to" class="form-control" value="<?= h($to) ?>">
        </div>
        <div class="form-group" style="margin:0;flex:1;min-width:140px;">
          <label style="font-size:0.78rem;">ደረሰኝ ቁጥር</label>
          <input type="text" name="receipt_search" class="form-control" placeholder="ATS00001" value="<?= h($receipt_search) ?>">
        </div>
        <div class="form-group" style="margin:0;flex:1;min-width:140px;">
          <label style="font-size:0.78rem;">የዕቃ ስም</label>
          <input type="text" name="item_search" class="form-control" placeholder="ለምሳሌ: ዘይት" value="<?= h($item_search) ?>">
        </div>
        <?php if ($is_admin): ?>
        <div class="form-group" style="margin:0;flex:1;min-width:140px;">
          <label style="font-size:0.78rem;">ሻጭ</label>
          <input type="text" name="seller_search" class="form-control" placeholder="የሻጭ ስም" value="<?= h($seller_search) ?>">
        </div>
        <?php endif; ?>
        <div class="form-group" style="margin:0;flex:1;min-width:130px;">
          <label style="font-size:0.78rem;">የክፍያ ዓይነት</label>
          <select name="payment_filter" class="form-control">
            <option value="">ሁሉም</option>
            <?php foreach ($payLabel as $pk=>$pl): ?>
              <option value="<?= h($pk) ?>" <?= $payment_filter===$pk?'selected':'' ?>><?= h($pl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> ፈልግ</button>
        <?php if ($receipt_search || $item_search || $payment_filter || $seller_search || $period==='custom'): ?>
          <a href="seller_report.php" class="btn btn-outline"><i class="fas fa-times"></i> አጽዳ</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <!-- Transaction list -->
  <div class="card" style="padding:18px;">
    <div class="card-header"><span class="card-title"><i class="fas fa-list"></i> ደረሰኞች (<?= $total_rows ?>)</span></div>
    <div class="rpt-table-wrap">
      <table class="rpt-table">
        <thead>
          <tr>
            <th>ደረሰኝ</th><th>ቀን / ሰዓት</th><?php if ($is_admin): ?><th>ሻጭ</th><?php endif; ?><th>ዕቃዎች</th><th>ክፍያ</th><th class="num">ጠቅላላ</th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($list)): ?>
            <tr><td colspan="<?= $is_admin ? 7 : 6 ?>" style="text-align:center;color:var(--text-muted);padding:24px;">ምንም ደረሰኝ አልተገኘም</td></tr>
          <?php else: foreach ($list as $row): ?>
            <tr>
              <td><strong><?= h($row['receipt_number']) ?></strong></td>
              <td><?= h($row['eth_date']) ?> <span style="color:var(--text-muted);">&bull; <?= h($row['time_12h']) ?></span></td>
              <?php if ($is_admin): ?><td><?= h($row['seller_name'] ?? '—') ?></td><?php endif; ?>
              <td><span class="badge badge-blue"><?= (int)$row['item_lines'] ?> ዓይነት &bull; <?= formatQtyPhp($row['item_qty']) ?> ብዛት</span></td>
              <td><span class="badge badge-gold"><?= h($payLabel[$row['payment_method']] ?? $row['payment_method']) ?></span></td>
              <td class="num"><?= money($row['total_amount']) ?> ብር</td>
              <td><button class="btn btn-outline btn-sm" onclick="showReceipt(<?= (int)$row['id'] ?>)"><i class="fas fa-eye"></i> ይመልከቱ</button></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Pagination -->
  <?php if ($pages > 1):
    $pagerParams = $_GET; unset($pagerParams['page']);
    $baseUrl = '?' . http_build_query($pagerParams);
  ?>
  <div class="pager">
    <?php if ($page>1): ?><a href="<?= h($baseUrl) ?>&page=<?= $page-1 ?>">&larr; ቀዳሚ</a><?php endif; ?>
    <span><?= $page ?> / <?= $pages ?></span>
    <?php if ($page<$pages): ?><a href="<?= h($baseUrl) ?>&page=<?= $page+1 ?>">ቀጣይ &rarr;</a><?php endif; ?>
  </div>
  <?php endif; ?>

</div><!-- /main-content -->

<!-- Receipt modal -->
<div class="modal-backdrop" id="receiptModal">
  <div class="modal" style="max-width:380px;">
    <div class="modal-header">
      <span class="modal-title"><i class="fas fa-receipt"></i> ደረሰኝ</span>
      <span class="modal-close" onclick="closeReceipt()">&times;</span>
    </div>
    <div class="modal-body" id="receiptContent"></div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeReceipt()">ዝጋ</button>
      <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> አትም</button>
    </div>
  </div>
</div>

<script>
function formatMoney(n) { return parseFloat(n||0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,','); }
function formatQty(n) { const f=parseFloat(n||0); return Number.isInteger(f)?f:f.toFixed(2); }
function escHtml(s) { return (s||'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

async function showReceipt(txnId) {
  const res = await fetch('seller_report.php?api=receipt&id='+txnId);
  const t = await res.json();
  if (t.error) { return; }

  const payLabel = {cash:'ጥሬ ገንዘብ',bank:'ባንክ',mobile:'ቴሌብር',mixed:'ድብልቅ'};
  const itemsHtml = (t.items||[]).map(i=>`
    <div class="r-item">
      <div>${escHtml(i.name)} (${escHtml(i.unit)})</div>
      <div class="r-row"><span>${formatQty(i.quantity)} × ${formatMoney(i.unit_price)}</span><span>${formatMoney(i.subtotal)} ብር</span></div>
    </div>
  `).join('');

  document.getElementById('receiptContent').innerHTML = `
    <div class="receipt-box">
      <div class="r-header">
        <div style="font-size:1.1rem;font-weight:800;">${escHtml(t.biz_name)}</div>
        ${t.biz_address?`<div>${escHtml(t.biz_address)}</div>`:''}
        ${t.biz_phone?`<div>${escHtml(t.biz_phone)}</div>`:''}
        <div style="margin-top:8px;font-weight:700;">ደረሰኝ: ${escHtml(t.receipt_number)}</div>
        <div>ሻጭ: ${escHtml(t.seller_fullname || '—')}</div>
        <div>${escHtml(t.eth_date)} ${escHtml(t.greg_time)}</div>
      </div>
      <div style="margin:8px 0;">${itemsHtml}</div>
      <div style="border-top:1px dashed #ccc;padding-top:8px;">
        ${parseFloat(t.discount)>0?`<div class="r-row"><span>ቅናሽ</span><span>−${formatMoney(t.discount)} ብር</span></div>`:''}
        <div class="r-row r-total"><span>ጠቅላላ</span><span>${formatMoney(t.total_amount)} ብር</span></div>
        <div class="r-row"><span>ክፍያ</span><span>${payLabel[t.payment_method]||t.payment_method}${(t.bank_name && t.payment_method==='bank')?' ('+escHtml(t.bank_name)+')':''}</span></div>
        ${parseFloat(t.change_given)>0?`<div class="r-row"><span>ምንዛሬ</span><span>${formatMoney(t.change_given)} ብር</span></div>`:''}
      </div>
      <div class="r-footer"><i class="fas fa-cross"></i> ${escHtml(t.footer||'')}</div>
    </div>`;

  document.getElementById('receiptModal').classList.add('open');
}
function closeReceipt() {
  document.getElementById('receiptModal').classList.remove('open');
}
</script>
</body>
</html>
