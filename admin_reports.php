<?php
require_once 'config.php';
requireAdmin();

$branch_id   = (int)($_SESSION['branch_id'] ?? 1);
$biz_name    = getSetting($conn, 'business_name', 'አጸደ ትጉሃን');
$active_page = 'reports';
$eth_now     = getCurrentEthDatetime();

$report_type = $_GET['type'] ?? 'daily';
$from        = $_GET['from'] ?? date('Y-m-01');
$to          = $_GET['to']   ?? date('Y-m-d');

// Validate dates
$from = date('Y-m-d', strtotime($from));
$to   = date('Y-m-d', strtotime($to));
if ($to < $from) $to = $from;

$eth_from = ethDateDisplay($from);
$eth_to   = ethDateDisplay($to);

// ── Shared: daily sales in range ──────────────────────────────
// NOTE: revenue is aggregated from `transactions` alone (one row per sale).
// Profit is aggregated separately from `transaction_items` and joined back
// by day. Joining items directly to transactions and then SUM(t.total_amount)
// would multiply each transaction's total once per item row (a "fan-out"),
// wildly inflating revenue for any day with multi-item sales — this is what
// was previously causing reported totals to run far higher than reality.
function getDailySales($conn, $from, $to, $branch_id) {
    $r = $conn->query(
        "SELECT dt.day, dt.revenue, dt.txn_count, COALESCE(dp.profit,0) as profit
         FROM (
             SELECT DATE(transaction_date) as day,
                    SUM(total_amount) as revenue,
                    COUNT(*) as txn_count
             FROM transactions
             WHERE DATE(transaction_date) BETWEEN '$from' AND '$to' AND branch_id=$branch_id
             GROUP BY DATE(transaction_date)
         ) dt
         LEFT JOIN (
             SELECT DATE(t.transaction_date) as day, SUM(ti.profit) as profit
             FROM transactions t
             JOIN transaction_items ti ON ti.transaction_id=t.id
             WHERE DATE(t.transaction_date) BETWEEN '$from' AND '$to' AND t.branch_id=$branch_id
             GROUP BY DATE(t.transaction_date)
         ) dp ON dp.day = dt.day
         ORDER BY dt.day DESC"
    );
    $rows = [];
    while($x=$r->fetch_assoc()) $rows[]=$x;
    return $rows;
}

function getExpenses($conn,$from,$to,$branch_id){
    $r=$conn->query("SELECT SUM(amount) as total FROM expenses WHERE expense_date BETWEEN '$from' AND '$to' AND branch_id=$branch_id");
    return (float)($r->fetch_assoc()['total']??0);
}

$total_revenue=0;$total_profit=0;$total_txns=0;
$daily = getDailySales($conn,$from,$to,$branch_id);
foreach($daily as $d){$total_revenue+=$d['revenue'];$total_profit+=$d['profit'];$total_txns+=$d['txn_count'];}
$total_expenses = getExpenses($conn,$from,$to,$branch_id);
$net_profit = $total_profit - $total_expenses;

// ── Product report ────────────────────────────────────────────
$prod_report = [];
if ($report_type === 'product') {
    // pt = only the sales that fall inside the selected date range + branch,
    // pre-aggregated per product BEFORE joining to the products list. This is
    // what makes the date filter actually apply — previously ti was joined to
    // p by product_id alone (unfiltered), so every product's SUM included its
    // entire sales history no matter what date range was picked.
    $r=$conn->query(
        "SELECT p.id,p.name,p.unit,
                COALESCE(pt.sold_qty,0) as sold_qty,
                COALESCE(pt.revenue,0) as revenue,
                COALESCE(pt.profit,0) as profit,
                COALESCE(pt.cost,0) as cost,
                (SELECT COALESCE(SUM(sb2.quantity_received),0) FROM stock_batches sb2 WHERE sb2.product_id=p.id AND sb2.branch_id=$branch_id AND DATE(sb2.date_received) BETWEEN '$from' AND '$to') as bought_qty,
                (SELECT COALESCE(SUM(sb3.quantity_remaining),0) FROM stock_batches sb3 WHERE sb3.product_id=p.id AND sb3.branch_id=$branch_id AND sb3.is_depleted=0) as remaining_qty,
                (SELECT sb4.buy_price FROM stock_batches sb4 WHERE sb4.product_id=p.id AND sb4.branch_id=$branch_id AND sb4.is_depleted=0 ORDER BY sb4.created_at ASC LIMIT 1) as current_buy_price,
                (SELECT sb5.sell_price FROM stock_batches sb5 WHERE sb5.product_id=p.id AND sb5.branch_id=$branch_id AND sb5.is_depleted=0 ORDER BY sb5.created_at ASC LIMIT 1) as current_sell_price
         FROM products p
         LEFT JOIN (
             SELECT ti.product_id,
                    SUM(ti.quantity) as sold_qty,
                    SUM(ti.subtotal) as revenue,
                    SUM(ti.profit) as profit,
                    SUM(ti.buy_price*ti.quantity) as cost
             FROM transaction_items ti
             JOIN transactions t ON t.id=ti.transaction_id
             WHERE DATE(t.transaction_date) BETWEEN '$from' AND '$to' AND t.branch_id=$branch_id
             GROUP BY ti.product_id
         ) pt ON pt.product_id=p.id
         WHERE p.is_active=1
         ORDER BY revenue DESC"
    );
    while($x=$r->fetch_assoc()) $prod_report[]=$x;
}

// ── Stock movement ────────────────────────────────────────────
$stock_move = [];
if ($report_type === 'stock') {
    $r=$conn->query(
        "SELECT p.id,p.name,p.unit,
                COALESCE((SELECT SUM(sb.quantity_received) FROM stock_batches sb WHERE sb.product_id=p.id AND sb.branch_id=$branch_id AND DATE(sb.date_received) BETWEEN '$from' AND '$to'),0) as received,
                COALESCE((SELECT SUM(ti.quantity) FROM transaction_items ti JOIN transactions t ON t.id=ti.transaction_id WHERE ti.product_id=p.id AND t.branch_id=$branch_id AND DATE(t.transaction_date) BETWEEN '$from' AND '$to'),0) as sold,
                COALESCE((SELECT SUM(sb2.quantity_remaining) FROM stock_batches sb2 WHERE sb2.product_id=p.id AND sb2.branch_id=$branch_id AND sb2.is_depleted=0),0) as remaining
         FROM products p WHERE p.is_active=1
         ORDER BY p.name"
    );
    while($x=$r->fetch_assoc()) { $x['net']=$x['received']-$x['sold']; $stock_move[]=$x; }
}

// ── Low stock report ──────────────────────────────────────────
$low_stock_report = [];
if ($report_type === 'lowstock') {
    $r=$conn->query(
        "SELECT p.id,p.name,p.unit,p.min_stock,
                COALESCE(SUM(sb.quantity_remaining),0) as stock,
                (SELECT sb2.sell_price FROM stock_batches sb2 WHERE sb2.product_id=p.id AND sb2.branch_id=$branch_id AND sb2.is_depleted=0 ORDER BY sb2.created_at ASC LIMIT 1) as sell_price
         FROM products p
         LEFT JOIN stock_batches sb ON sb.product_id=p.id AND sb.branch_id=$branch_id AND sb.is_depleted=0
         WHERE p.is_active=1
         GROUP BY p.id HAVING stock<=p.min_stock ORDER BY stock ASC"
    );
    while($x=$r->fetch_assoc()) $low_stock_report[]=$x;
}

// ── Top products ──────────────────────────────────────────────
$top_products = [];
if ($report_type === 'top') {
    $r=$conn->query(
        "SELECT p.name,p.unit,
                COALESCE(SUM(ti.quantity),0) as qty,
                COALESCE(SUM(ti.subtotal),0) as revenue,
                COALESCE(SUM(ti.profit),0) as profit
         FROM transaction_items ti
         JOIN transactions t ON t.id=ti.transaction_id
         JOIN products p ON p.id=ti.product_id
         WHERE DATE(t.transaction_date) BETWEEN '$from' AND '$to' AND t.branch_id=$branch_id
         GROUP BY p.id ORDER BY revenue DESC LIMIT 20"
    );
    while($x=$r->fetch_assoc()) $top_products[]=$x;
}

// ── Monthly for charts (Ethiopian calendar year, always starting at መስከረም) ──
// Shows every month of the CURRENT Ethiopian year from Meskerem (month 1)
// through the current month — not a rolling "last 6 months" window — so the
// chart always lines up with the Ethiopian new year rather than an arbitrary
// cut-off that could start mid-year.
$monthly_data = [];
if ($report_type === 'monthly') {
    $curEth = ethDate(date('Y-m-d'));
    $ey = $curEth['year']; $em = $curEth['month'];
    for ($mm = 1; $mm <= $em; $mm++) {
        $ms = ethiopianToGregorianYMD($ey, $mm, 1);
        $nmm = $mm + 1; $nmy = $ey;
        if ($nmm > 13) { $nmm = 1; $nmy++; }
        $me = date('Y-m-d', strtotime(ethiopianToGregorianYMD($nmy, $nmm, 1) . ' -1 day'));
        if ($me > date('Y-m-d')) $me = date('Y-m-d'); // don't run past today for the current month

        // Revenue from transactions alone (no item fan-out); profit joined in separately.
        $mr  = $conn->query("SELECT COALESCE(SUM(total_amount),0) as rev FROM transactions WHERE DATE(transaction_date) BETWEEN '$ms' AND '$me' AND branch_id=$branch_id")->fetch_assoc();
        $mp  = $conn->query("SELECT COALESCE(SUM(ti.profit),0) as prof FROM transactions t JOIN transaction_items ti ON ti.transaction_id=t.id WHERE DATE(t.transaction_date) BETWEEN '$ms' AND '$me' AND t.branch_id=$branch_id")->fetch_assoc();
        $exp = $conn->query("SELECT COALESCE(SUM(amount),0) as e FROM expenses WHERE expense_date BETWEEN '$ms' AND '$me' AND branch_id=$branch_id")->fetch_assoc();
        $monthly_data[] = ['month'=>ethDate($ms)['month_name'],'rev'=>(float)$mr['rev'],'prof'=>(float)$mp['prof'],'exp'=>(float)$exp['e'],'net'=>(float)$mp['prof']-(float)$exp['e']];
    }
}
?><!DOCTYPE html>
<html lang="am">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ሪፖርቶች — <?= h($biz_name) ?></title>
<link rel="icon" type="image/png" href="images/icon.png">
<?php include 'layout_start.php'; ?>
<script src="assets/chartjs/chart.umd.js"></script>
<style>
.report-tabs{display:flex;gap:6px;overflow-x:auto;padding-bottom:8px;margin-bottom:20px;flex-wrap:nowrap;}
.report-tab{
  padding:9px 16px;border-radius:20px;border:2px solid var(--border);
  background:var(--card);cursor:pointer;font-size:0.82rem;font-weight:600;
  color:var(--text-muted);white-space:nowrap;transition:all var(--transition);font-family:inherit;text-decoration:none;
  display:inline-flex;align-items:center;gap:6px;
}
.report-tab.active,.report-tab:hover{border-color:var(--gold);background:var(--gold-pale);color:var(--brown);}
.report-tab.active{background:linear-gradient(135deg,var(--brown),var(--gold));color:#fff;border-color:transparent;}
.summary-strip{
  display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;margin-bottom:20px;
}
.sum-card{background:var(--card);border-radius:var(--radius);padding:18px;box-shadow:var(--shadow);text-align:center;border-top:4px solid var(--border);}
.sum-card.g{border-color:var(--gold);} .sum-card.gr{border-color:var(--green);}
.sum-card.r{border-color:var(--red);} .sum-card.b{border-color:var(--blue);}
.sum-val{font-size:1.3rem;font-weight:800;} .sum-lbl{font-size:0.75rem;color:var(--text-muted);margin-top:4px;}
.rpt-table-wrap{overflow-x:auto;border-radius:var(--radius-sm);}
.rpt-table{width:100%;border-collapse:collapse;font-size:0.84rem;}
.rpt-table th{background:var(--brown);color:#fff;padding:10px 12px;text-align:left;font-size:0.78rem;font-weight:700;}
.rpt-table th:first-child{border-radius:var(--radius-sm) 0 0 0;}
.rpt-table th:last-child{border-radius:0 var(--radius-sm) 0 0;}
.rpt-table td{padding:11px 12px;border-bottom:1px solid var(--border-light);vertical-align:middle;}
.rpt-table tr:nth-child(even) td{background:var(--gold-pale);}
.rpt-table tr:hover td{background:rgba(218,165,32,0.12);}
.rpt-table .num{text-align:right;font-weight:700;}
.rpt-table .profit-col{color:var(--green);font-weight:700;}
.rpt-table .loss-col{color:var(--red);font-weight:700;}
.rank-num{
  width:28px;height:28px;border-radius:50%;background:var(--gold-pale);color:var(--brown);
  display:inline-flex;align-items:center;justify-content:center;font-size:0.78rem;font-weight:800;
}
.rank-num.top1{background:var(--gold);color:#fff;}
.rank-num.top2{background:#C0C0C0;color:#333;}
.rank-num.top3{background:#CD7F32;color:#fff;}
.date-filter{background:var(--card);border-radius:var(--radius);padding:16px 20px;margin-bottom:20px;box-shadow:var(--shadow);}
.date-filter-row{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;}
.no-data{text-align:center;padding:48px;color:var(--text-muted);}
.no-data i{font-size:3rem;margin-bottom:12px;display:block;color:var(--border);}
</style>
</head>
<body>
<?php include 'nav.php'; ?>
<div class="main-content">
  <!-- Header -->
  <div class="page-header">
    <div>
      <h1><i class="fas fa-chart-bar"></i> ሪፖርቶች</h1>
      <div class="page-title-sub"><?= h($eth_from) ?> — <?= h($eth_to) ?></div>
    </div>
    <button class="btn btn-outline btn-sm" onclick="window.print()"><i class="fas fa-print"></i> አትም</button>
  </div>

  <!-- Report Tabs -->
  <div class="report-tabs">
    <?php $tabs=[
      ['daily','fas fa-calendar-day','ዕለታዊ'],
      ['product','fas fa-boxes','የምርት'],
      ['monthly','fas fa-chart-line','ወርሃዊ'],
      ['stock','fas fa-warehouse','ክምችት'],
      ['lowstock','fas fa-exclamation-triangle','ቀሪ ክምችት'],
      ['top','fas fa-trophy','ምርጥ ምርቶች'],
    ];
    foreach($tabs as $t): ?>
    <a href="?type=<?= $t[0] ?>&from=<?= $from ?>&to=<?= $to ?>" class="report-tab <?= $report_type===$t[0]?'active':'' ?>">
      <i class="<?= $t[1] ?>"></i> <?= $t[2] ?>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Date Filter -->
  <div class="date-filter">
    <form method="GET" action="">
      <input type="hidden" name="type" value="<?= h($report_type) ?>">
      <div class="date-filter-row">
        <div class="form-group" style="margin:0;flex:1;min-width:140px;">
          <label>ከ (Gregorian)</label>
          <input type="date" name="from" class="form-control" value="<?= h($from) ?>">
        </div>
        <div class="form-group" style="margin:0;flex:1;min-width:140px;">
          <label>እስከ</label>
          <input type="date" name="to" class="form-control" value="<?= h($to) ?>">
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> ፈልግ</button>
        <a href="?type=<?= h($report_type) ?>&from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-outline">ወር</a>
        <a href="?type=<?= h($report_type) ?>&from=<?= date('Y-01-01') ?>&to=<?= date('Y-m-d') ?>" class="btn btn-outline">ዓመት</a>
      </div>
    </form>
  </div>

  <!-- Summary Strip (always shown) -->
  <div class="summary-strip">
    <div class="sum-card g"><div class="sum-val"><?= money($total_revenue) ?> ብር</div><div class="sum-lbl"><i class="fas fa-coins"></i> ጠቅላላ ሽያጭ</div></div>
    <div class="sum-card gr"><div class="sum-val"><?= money($total_profit) ?> ብር</div><div class="sum-lbl"><i class="fas fa-chart-line"></i> ጠቅላላ ትርፍ</div></div>
    <div class="sum-card r"><div class="sum-val"><?= money($total_expenses) ?> ብር</div><div class="sum-lbl"><i class="fas fa-receipt"></i> ወጪ</div></div>
    <div class="sum-card <?= $net_profit>=0?'gr':'r' ?>"><div class="sum-val" style="color:<?= $net_profit>=0?'var(--green)':'var(--red)' ?>"><?= money(abs($net_profit)) ?> ብር</div><div class="sum-lbl"><i class="fas fa-balance-scale"></i> የተጣራ ትርፍ</div></div>
    <div class="sum-card b"><div class="sum-val"><?= $total_txns ?></div><div class="sum-lbl"><i class="fas fa-shopping-cart"></i> ሽያጮች</div></div>
  </div>

  <?php
  // ── DAILY REPORT ─────────────────────────────────────────────
  if ($report_type === 'daily'):
  ?>
  <div class="card">
    <div class="card-header">
      <span class="card-title"><i class="fas fa-calendar-day"></i> ዕለታዊ ሪፖርት</span>
      <span style="font-size:0.8rem;color:var(--text-muted);"><?= count($daily) ?> ቀናት</span>
    </div>
    <?php if (empty($daily)): ?>
      <div class="no-data"><i class="fas fa-calendar-times"></i> ሽያጭ አልተገኘም</div>
    <?php else: ?>
    <div class="rpt-table-wrap">
      <table class="rpt-table">
        <thead><tr>
          <th>ቀን (Ethiopian)</th><th>Gregorian</th>
          <th class="num">ሽያጮች</th><th class="num">ሽያጭ (ብር)</th>
          <th class="num">ትርፍ (ብር)</th><th class="num">ትርፍ %</th>
        </tr></thead>
        <tbody>
        <?php foreach($daily as $d):
          $margin = $d['revenue']>0?round($d['profit']/$d['revenue']*100,1):0;
        ?>
        <tr>
          <td><?= h(ethDateDisplay($d['day'])) ?></td>
          <td><?= h($d['day']) ?></td>
          <td class="num"><?= h($d['txn_count']) ?></td>
          <td class="num"><?= money($d['revenue']) ?></td>
          <td class="num profit-col"><?= money($d['profit']) ?></td>
          <td class="num"><span class="badge <?= $margin>=20?'badge-green':($margin>=10?'badge-gold':'badge-red') ?>"><?= $margin ?>%</span></td>
        </tr>
        <?php endforeach; ?>
        <tr style="background:var(--gold-pale);font-weight:800;">
          <td colspan="3" style="font-weight:800;">ጠቅላላ</td>
          <td class="num"><?= money($total_revenue) ?></td>
          <td class="num profit-col"><?= money($total_profit) ?></td>
          <td class="num"><?= $total_revenue>0?round($total_profit/$total_revenue*100,1):0 ?>%</td>
        </tr>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <?php
  // ── PRODUCT REPORT ────────────────────────────────────────────
  elseif ($report_type === 'product'):
  ?>
  <div class="card">
    <div class="card-header">
      <span class="card-title"><i class="fas fa-boxes"></i> የምርት ሪፖርት</span>
    </div>
    <?php if (empty($prod_report)): ?>
      <div class="no-data"><i class="fas fa-box-open"></i> ምርት አልተገኘም</div>
    <?php else: ?>
    <div class="rpt-table-wrap">
      <table class="rpt-table">
        <thead><tr>
          <th>ምርት</th><th>ዓይነት</th>
          <th class="num">የተቀበለ</th><th class="num">የተሸጠ</th><th class="num">ቀሪ</th>
          <th class="num">ገዥ ዋጋ</th><th class="num">ሻጭ ዋጋ</th>
          <th class="num">ሽያጭ (ብር)</th><th class="num">ትርፍ (ብር)</th>
        </tr></thead>
        <tbody>
        <?php $gRev=0;$gProf=0;
        foreach($prod_report as $p):
          $gRev+=$p['revenue'];$gProf+=$p['profit'];
        ?>
        <tr>
          <td><?= h($p['name']) ?></td>
          <td><span class="badge badge-blue"><?= h($p['unit']) ?></span></td>
          <td class="num"><?= number_format($p['bought_qty'],2) ?></td>
          <td class="num"><?= number_format($p['sold_qty'],2) ?></td>
          <td class="num"><?= number_format($p['remaining_qty'],2) ?></td>
          <td class="num"><?= $p['current_buy_price']?money($p['current_buy_price']):'—' ?></td>
          <td class="num"><?= $p['current_sell_price']?money($p['current_sell_price']):'—' ?></td>
          <td class="num"><?= money($p['revenue']) ?></td>
          <td class="num profit-col"><?= money($p['profit']) ?></td>
        </tr>
        <?php endforeach; ?>
        <tr style="background:var(--gold-pale);font-weight:800;">
          <td colspan="7" style="font-weight:800;">ጠቅላላ</td>
          <td class="num"><?= money($gRev) ?></td>
          <td class="num profit-col"><?= money($gProf) ?></td>
        </tr>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <?php
  // ── MONTHLY REPORT ────────────────────────────────────────────
  elseif ($report_type === 'monthly'):
  ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;margin-bottom:20px;">
    <div class="card"><div class="card-header"><span class="card-title"><i class="fas fa-chart-bar"></i> ሽያጭ vs ትርፍ</span></div><canvas id="monthChart" style="max-height:260px;"></canvas></div>
    <div class="card"><div class="card-header"><span class="card-title"><i class="fas fa-chart-line"></i> ወጪ vs የተጣራ ትርፍ</span></div><canvas id="expChart" style="max-height:260px;"></canvas></div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title"><i class="fas fa-table"></i> ወርሃዊ ሠንጠረዥ</span></div>
    <div class="rpt-table-wrap">
      <table class="rpt-table">
        <thead><tr><th>ወር</th><th class="num">ሽያጭ</th><th class="num">ትርፍ</th><th class="num">ወጪ</th><th class="num">የተጣራ</th><th class="num">Margin</th></tr></thead>
        <tbody>
        <?php foreach($monthly_data as $m): $margin=$m['rev']>0?round($m['prof']/$m['rev']*100,1):0; ?>
        <tr>
          <td><strong><?= h($m['month']) ?></strong></td>
          <td class="num"><?= money($m['rev']) ?></td>
          <td class="num profit-col"><?= money($m['prof']) ?></td>
          <td class="num"><?= money($m['exp']) ?></td>
          <td class="num <?= $m['net']>=0?'profit-col':'loss-col' ?>"><?= money($m['net']) ?></td>
          <td class="num"><?= $margin ?>%</td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <script>
  const md=<?= json_encode($monthly_data) ?>;
  const labels=md.map(x=>x.month),revs=md.map(x=>x.rev),profs=md.map(x=>x.prof),exps=md.map(x=>x.exp),nets=md.map(x=>x.net);
  new Chart(document.getElementById('monthChart'),{type:'bar',data:{labels,datasets:[
    {label:'ሽያጭ',data:revs,backgroundColor:'rgba(139,69,19,0.15)',borderColor:'#8B4513',borderWidth:2,borderRadius:6},
    {label:'ትርፍ',data:profs,backgroundColor:'rgba(46,125,50,0.15)',borderColor:'#2E7D32',borderWidth:2,borderRadius:6}
  ]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}},scales:{y:{beginAtZero:true},x:{grid:{display:false}}}}});
  new Chart(document.getElementById('expChart'),{type:'line',data:{labels,datasets:[
    {label:'ወጪ',data:exps,backgroundColor:'rgba(198,40,40,0.1)',borderColor:'#C62828',borderWidth:2,fill:true,tension:0.4},
    {label:'የተጣራ',data:nets,backgroundColor:'rgba(21,101,192,0.1)',borderColor:'#1565C0',borderWidth:2,fill:true,tension:0.4}
  ]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}},scales:{y:{beginAtZero:true},x:{grid:{display:false}}}}});
  </script>

  <?php
  // ── STOCK MOVEMENT ────────────────────────────────────────────
  elseif ($report_type === 'stock'):
  ?>
  <div class="card">
    <div class="card-header"><span class="card-title"><i class="fas fa-warehouse"></i> ክምችት ሪፖርት</span></div>
    <?php if (empty($stock_move)): ?>
      <div class="no-data"><i class="fas fa-boxes"></i> ምርት አልተገኘም</div>
    <?php else: ?>
    <div class="rpt-table-wrap">
      <table class="rpt-table">
        <thead><tr>
          <th>ምርት</th><th>ዓይነት</th>
          <th class="num">የተቀበለ</th><th class="num">የተሸጠ</th>
          <th class="num">ቀሪ</th><th>ሁኔታ</th>
        </tr></thead>
        <tbody>
        <?php foreach($stock_move as $s):
          $isLow=($s['remaining']<10);$isOut=($s['remaining']<=0);
        ?>
        <tr>
          <td><?= h($s['name']) ?></td>
          <td><span class="badge badge-blue"><?= h($s['unit']) ?></span></td>
          <td class="num" style="color:var(--blue);"><?= number_format($s['received'],2) ?></td>
          <td class="num" style="color:var(--orange);"><?= number_format($s['sold'],2) ?></td>
          <td class="num" style="color:<?= $isOut?'var(--red)':($isLow?'var(--orange)':'var(--green)') ?>;font-weight:800;"><?= number_format($s['remaining'],2) ?></td>
          <td><span class="badge <?= $isOut?'badge-red':($isLow?'badge-gold':'badge-green') ?>"><?= $isOut?'አልቋል':($isLow?'ቀሪ':'ጥሩ') ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <?php
  // ── LOW STOCK ─────────────────────────────────────────────────
  elseif ($report_type === 'lowstock'):
  ?>
  <div class="card">
    <div class="card-header">
      <span class="card-title"><i class="fas fa-exclamation-triangle" style="color:var(--orange)"></i> ቀሪ ክምችት ሪፖርት</span>
      <span class="badge badge-red"><?= count($low_stock_report) ?> ምርቶች</span>
    </div>
    <?php if (empty($low_stock_report)): ?>
      <div class="no-data" style="color:var(--green);">
        <i class="fas fa-check-circle" style="color:var(--green);"></i>
        ሁሉም ምርቶች በቂ ክምችት አላቸው
      </div>
    <?php else: ?>
    <div class="rpt-table-wrap">
      <table class="rpt-table">
        <thead><tr><th>ምርት</th><th>ዓይነት</th><th class="num">ቀሪ</th><th class="num">ዝቅ ነጥብ</th><th class="num">ሻጭ ዋጋ</th><th>ሁኔታ</th></tr></thead>
        <tbody>
        <?php foreach($low_stock_report as $s): ?>
        <tr>
          <td><strong><?= h($s['name']) ?></strong></td>
          <td><?= h($s['unit']) ?></td>
          <td class="num" style="color:<?= $s['stock']<=0?'var(--red)':'var(--orange)' ?>;font-weight:800;"><?= number_format($s['stock'],2) ?></td>
          <td class="num"><?= number_format($s['min_stock'],2) ?></td>
          <td class="num"><?= $s['sell_price']?money($s['sell_price']):'—' ?> ብር</td>
          <td><span class="badge <?= $s['stock']<=0?'badge-red':'badge-gold' ?>"><?= $s['stock']<=0?'⚠️ አልቋል':'⬇ ቀሪ' ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <?php
  // ── TOP PRODUCTS ──────────────────────────────────────────────
  elseif ($report_type === 'top'):
  ?>
  <div class="card">
    <div class="card-header"><span class="card-title"><i class="fas fa-trophy"></i> ምርጥ ምርቶች</span></div>
    <?php if (empty($top_products)): ?>
      <div class="no-data"><i class="fas fa-award"></i> ሽያጭ አልተገኘም</div>
    <?php else:
      $maxRev = max(array_column($top_products,'revenue'));
    ?>
    <div class="rpt-table-wrap">
      <table class="rpt-table">
        <thead><tr><th>#</th><th>ምርት</th><th class="num">ብዛት</th><th class="num">ሽያጭ (ብር)</th><th class="num">ትርፍ (ብር)</th><th>ደረጃ</th></tr></thead>
        <tbody>
        <?php foreach($top_products as $i=>$p): $rank=$i+1; ?>
        <tr>
          <td><span class="rank-num <?= $rank===1?'top1':($rank===2?'top2':($rank===3?'top3':'')) ?>"><?= $rank ?></span></td>
          <td><strong><?= h($p['name']) ?></strong></td>
          <td class="num"><?= number_format($p['qty'],2) ?> <?= h($p['unit']) ?></td>
          <td class="num"><?= money($p['revenue']) ?></td>
          <td class="num profit-col"><?= money($p['profit']) ?></td>
          <td>
            <div style="background:var(--border-light);border-radius:4px;height:8px;min-width:80px;">
              <div style="height:8px;border-radius:4px;background:linear-gradient(90deg,var(--brown),var(--gold));width:<?= $maxRev>0?round($p['revenue']/$maxRev*100):0 ?>%;"></div>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div><!-- /main-content -->
</body>
</html>
