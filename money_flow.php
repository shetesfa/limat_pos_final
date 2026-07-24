<?php
require_once 'config.php';
requireAdmin();

$branch_id   = (int)($_SESSION['branch_id'] ?? 1);
$biz_name    = getSetting($conn, 'business_name', 'አጸደ ትጉሃን');
$active_page = 'moneyflow';
$eth_now     = getCurrentEthDatetime();

$period = $_GET['period'] ?? 'this_month';
[$from, $to] = resolveDateRangePreset($period, $_GET['from'] ?? null, $_GET['to'] ?? null);

// ── Period-filtered figures ─────────────────────────────────────
$salesRow = $conn->query(
    "SELECT COALESCE(SUM(total_amount),0) as sales, COUNT(*) as cnt
     FROM transactions
     WHERE DATE(transaction_date) BETWEEN '$from' AND '$to' AND branch_id=$branch_id"
)->fetch_assoc();
$total_sales = (float)$salesRow['sales'];
$txn_count   = (int)$salesRow['cnt'];

$cogsRow = $conn->query(
    "SELECT COALESCE(SUM(ti.quantity * ti.buy_price),0) as cogs, COALESCE(SUM(ti.profit),0) as profit
     FROM transaction_items ti
     JOIN transactions t ON t.id = ti.transaction_id
     WHERE DATE(t.transaction_date) BETWEEN '$from' AND '$to' AND t.branch_id=$branch_id"
)->fetch_assoc();
$total_purchase_cost = (float)$cogsRow['cogs'];
$gross_profit         = (float)$cogsRow['profit'];

$expRow = $conn->query(
    "SELECT COALESCE(SUM(amount),0) as e FROM expenses
     WHERE expense_date BETWEEN '$from' AND '$to' AND branch_id=$branch_id"
)->fetch_assoc();
$total_expenses = (float)$expRow['e'];
$net_profit = $gross_profit - $total_expenses;

// ── Available Cash: cumulative, all-time cash position (not limited to the selected period) ──
// "Double counting" check: none of the three sums below can double-count.
//   - allSales reads transactions.total_amount directly (one row per sale;
//     no join to transaction_items, so it is never multiplied by item count).
//   - allStockCost reads stock_batches directly (one row per batch received;
//     no join, so each purchase is counted exactly once).
//   - allExpenses reads expenses directly (one row per expense).
// Because none of these three queries join to another one-to-many table
// before summing, there is no fan-out and no risk of the same birr being
// counted twice. The FIFO sale logic in seller_pos.php also decrements each
// stock_batches row exactly once per line item (guarded by idempotency_key
// so a double-submitted sale can never insert two transactions), so COGS
// above and Available Cash below always trace back to a single, consistent
// set of batch rows.
$allSales = $conn->query("SELECT COALESCE(SUM(total_amount),0) as s FROM transactions WHERE branch_id=$branch_id")->fetch_assoc()['s'];
$allStockCost = $conn->query("SELECT COALESCE(SUM(quantity_received*buy_price),0) as c FROM stock_batches WHERE branch_id=$branch_id")->fetch_assoc()['c'];
$allExpenses = $conn->query("SELECT COALESCE(SUM(amount),0) as e FROM expenses WHERE branch_id=$branch_id")->fetch_assoc()['e'];
$available_cash = (float)$allSales - (float)$allStockCost - (float)$allExpenses;

// ── Payment method breakdown for the period (helps explain the cash figure) ──
$payRows = [];
$pr = $conn->query(
    "SELECT payment_method, COALESCE(SUM(total_amount),0) as amt, COUNT(*) as cnt
     FROM transactions
     WHERE DATE(transaction_date) BETWEEN '$from' AND '$to' AND branch_id=$branch_id
     GROUP BY payment_method"
);
while ($r = $pr->fetch_assoc()) $payRows[] = $r;
$payLabel = ['cash'=>'ጥሬ ገንዘብ','bank'=>'ባንክ','mobile'=>'ቴሌብር','mixed'=>'ድብልቅ'];

// ── Daily trend within the selected range (for the chart) ──
$trend = [];
$tr = $conn->query(
    "SELECT DATE(transaction_date) as d, COALESCE(SUM(total_amount),0) as sales
     FROM transactions
     WHERE DATE(transaction_date) BETWEEN '$from' AND '$to' AND branch_id=$branch_id
     GROUP BY DATE(transaction_date) ORDER BY d ASC"
);
while ($r = $tr->fetch_assoc()) {
    $trend[] = ['date'=>ethDateDisplay($r['d']), 'sales'=>(float)$r['sales']];
}
?><!DOCTYPE html>
<html lang="am">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>የገንዘብ ፍሰት — <?= h($biz_name) ?></title>
<link rel="icon" type="image/png" href="images/icon.png">
<?php include 'layout_start.php'; ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<style>
.mf-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin-bottom:22px;}
.mf-card{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);padding:18px;border-left:5px solid var(--gold);}
.mf-card .lbl{font-size:0.78rem;color:var(--text-muted);font-weight:600;margin-bottom:6px;}
.mf-card .val{font-size:1.5rem;font-weight:800;color:var(--text);}
.mf-card.sales{border-color:#2E86C1;} .mf-card.sales .val{color:#2E86C1;}
.mf-card.cost{border-color:#E67E22;} .mf-card.cost .val{color:#E67E22;}
.mf-card.gross{border-color:var(--gold);} .mf-card.gross .val{color:var(--gold);}
.mf-card.expense{border-color:var(--red);} .mf-card.expense .val{color:var(--red);}
.mf-card.net{border-color:var(--green);} .mf-card.net .val{color:var(--green);}
.mf-card.cash{border-color:#6C3483;} .mf-card.cash .val{color:#6C3483;}
.mf-card .sub{font-size:0.72rem;color:var(--text-muted);margin-top:4px;}
.filter-bar{background:var(--card);border-radius:var(--radius);padding:14px 18px;margin-bottom:18px;box-shadow:var(--shadow);}
.filter-row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;}
.pay-breakdown{display:flex;gap:10px;flex-wrap:wrap;margin-top:6px;}
.pay-chip{background:var(--bg);border-radius:20px;padding:6px 14px;font-size:0.8rem;font-weight:600;}
.mf-help{display:none;margin-top:10px;padding-top:10px;border-top:1px dashed var(--border);}
.mf-help.open{display:block;}
</style>
</head>
<body>
<?php include 'nav.php'; ?>
<div class="main-content">
  <div class="page-header">
    <div>
      <h1><i class="fas fa-money-bill-trend-up"></i> የገንዘብ ፍሰት</h1>
      <div class="page-title-sub">Money Flow &bull; <?= h($eth_now['eth_date']) ?></div>
    </div>
  </div>

  <!-- Preset filters -->
  <div class="filter-bar">
    <div class="filter-row" style="margin-bottom:12px;">
      <?php
        $presets = ['today'=>'ዛሬ','this_week'=>'ይህ ሳምንት','this_month'=>'ይህ ወር','this_year'=>'ይህ ዓመት','custom'=>'ራስ-ሰር'];
        foreach ($presets as $key=>$label):
          $qs = http_build_query(array_merge($_GET, ['period'=>$key]));
      ?>
        <a href="?<?= h($qs) ?>" class="btn <?= $period===$key?'btn-primary':'btn-outline' ?> btn-sm"><?= h($label) ?></a>
      <?php endforeach; ?>
      <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('mfHelp').classList.toggle('open')" style="margin-left:auto;">
        <i class="fas fa-circle-info"></i> እንዴት እንደሚነበብ
      </button>
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
        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> ፈልግ</button>
      </div>
    </form>
    <div id="mfHelp" class="mf-help">
      <ul style="margin:10px 0 0;padding-left:20px;font-size:0.83rem;line-height:1.7;color:var(--text);">
        <li><strong>ጠቅላላ ሽያጭ</strong> — በተመረጠው ጊዜ ውስጥ የተደረጉ ሽያጮች ጠቅላላ ድምር (ከላይ ካሉት አዝራሮች ወይም ከቀን መስክ ጋር ይቀያየራል)።</li>
        <li><strong>የግዢ ወጪ</strong> — በዚያ ጊዜ ውስጥ ለተሸጡ ዕቃዎች ብቻ የተከፈለ ግዥ ዋጋ (COGS)፣ በያንዳንዱ ንጥል ትክክለኛ የግዢ ዋጋ (buy price) ተሰልቷል።</li>
        <li><strong>ጠቅላላ ትርፍ</strong> = ጠቅላላ ሽያጭ − የግዢ ወጪ።</li>
        <li><strong>ወጪዎች</strong> — በዚያ ጊዜ ውስጥ የተመዘገቡ ሌሎች ወጪዎች (ኪራይ፣ መጓጓዣ፣ ወዘተ)።</li>
        <li><strong>የተጣራ ትርፍ</strong> = ጠቅላላ ትርፍ − ወጪዎች።</li>
        <li><strong>ያለ ገንዘብ (Available Cash)</strong> — ይህ ቁጥር ብቻ ከጅምሩ ጀምሮ ያለውን ጠቅላላ ሁኔታ ያሳያል (በተመረጠው ጊዜ ማጣሪያ አይለወጥም)፦ ከጅምሩ ጀምሮ ያለው ጠቅላላ ሽያጭ ገንዘብ ሁሉ ሲቀነስ ከጅምሩ ጀምሮ የገዛነው ሁሉም ዕቃ ዋጋ (የተሸጠም ያልተሸጠም) እና ወጪዎች። ስለዚህ ገና ያልተሸጠ ክምችት ላይ የወጣ ገንዘብ ስለሚቀነስ አሉታዊ ቁጥር ማየት የተለመደ ነው — ያ ማለት ገንዘቡ በክምችት ውስጥ አለ ማለት ነው፣ ጠፍቷል ማለት አይደለም።</li>
      </ul>
    </div>
  </div>

  <!-- Metrics -->
  <div class="mf-grid">
    <div class="mf-card sales">
      <div class="lbl">ጠቅላላ ሽያጭ (Total Sales)</div>
      <div class="val"><?= money($total_sales) ?> ብር</div>
      <div class="sub"><?= $txn_count ?> ሽያጮች</div>
    </div>
    <div class="mf-card cost">
      <div class="lbl">የግዢ ወጪ (Purchase Cost)</div>
      <div class="val"><?= money($total_purchase_cost) ?> ብር</div>
      <div class="sub">የተሸጠ ዕቃ ዋጋ</div>
    </div>
    <div class="mf-card gross">
      <div class="lbl">ጠቅላላ ትርፍ (Gross Profit)</div>
      <div class="val"><?= money($gross_profit) ?> ብር</div>
      <div class="sub"><?= $total_sales>0 ? round($gross_profit/$total_sales*100,1) : 0 ?>% margin</div>
    </div>
    <div class="mf-card expense">
      <div class="lbl">ወጪዎች (Expenses)</div>
      <div class="val"><?= money($total_expenses) ?> ብር</div>
    </div>
    <div class="mf-card net">
      <div class="lbl">የተጣራ ትርፍ (Net Profit)</div>
      <div class="val"><?= money($net_profit) ?> ብር</div>
    </div>
    <div class="mf-card cash">
      <div class="lbl">ያለ ገንዘብ (Available Cash)</div>
      <div class="val"><?= money($available_cash) ?> ብር</div>
      <div class="sub">ከጅምሩ ጀምሮ &bull; ከተመረጠው ጊዜ ውጭ</div>
    </div>
  </div>

  <div class="two-col section-gap" style="display:grid;grid-template-columns:2fr 1fr;gap:20px;">
    <div class="chart-card card" style="padding:18px;">
      <div class="card-header"><span class="card-title"><i class="fas fa-chart-area"></i> የቀን ሽያጭ (በተመረጠው ጊዜ)</span></div>
      <canvas id="trendChart" height="90"></canvas>
    </div>
    <div class="card" style="padding:18px;">
      <div class="card-header"><span class="card-title"><i class="fas fa-wallet"></i> በክፍያ ዓይነት</span></div>
      <div class="pay-breakdown">
        <?php if (empty($payRows)): ?>
          <div style="color:var(--text-muted);font-size:0.85rem;">ውሂብ የለም</div>
        <?php else: foreach ($payRows as $p): ?>
          <div class="pay-chip"><?= h($payLabel[$p['payment_method']] ?? $p['payment_method']) ?>: <?= money($p['amt']) ?> ብር (<?= $p['cnt'] ?>)</div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
const trendLabels = <?= json_encode(array_column($trend,'date')) ?>;
const trendSales  = <?= json_encode(array_column($trend,'sales')) ?>;
new Chart(document.getElementById('trendChart').getContext('2d'),{
  type:'line',
  data:{labels:trendLabels,datasets:[{label:'ሽያጭ',data:trendSales,backgroundColor:'rgba(46,134,193,0.12)',borderColor:'#2E86C1',borderWidth:2,fill:true,tension:0.35,pointRadius:3}]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true},x:{grid:{display:false}}}}
});
</script>
</body>
</html>
