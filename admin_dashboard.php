<?php
require_once 'config.php';
requireAdmin();

$admin_name = $_SESSION['full_name'] ?? 'Admin';
$branch_id  = (int)($_SESSION['branch_id'] ?? 1);
$branch_name = getCurrentBranchName($conn, $branch_id);
$eth_now    = getCurrentEthDatetime();
$biz_name   = getSetting($conn, 'business_name', 'አጸደ ትጉሃን');
$active_page = 'dashboard';
$today      = date('Y-m-d');
$thisMonth  = date('Y-m-01');

// ── Stats ──────────────────────────────────────────────────────
// Today sales
$ts = $conn->query("SELECT COALESCE(SUM(total_amount),0) as rev, COUNT(*) as cnt FROM transactions WHERE DATE(transaction_date)='$today' AND branch_id=$branch_id")->fetch_assoc();
$today_sales = $ts['rev']; $today_txns = $ts['cnt'];

// Today profit (from transaction_items with buy_price)
$tp = $conn->query("SELECT COALESCE(SUM(ti.profit),0) as prof FROM transaction_items ti JOIN transactions t ON t.id=ti.transaction_id WHERE DATE(t.transaction_date)='$today' AND t.branch_id=$branch_id")->fetch_assoc();
$today_profit = $tp['prof'];

// Total stock value
$sv = $conn->query("SELECT COALESCE(SUM(sb.quantity_remaining * sb.buy_price),0) as val, COALESCE(SUM(sb.quantity_remaining),0) as qty FROM stock_batches sb JOIN products p ON p.id=sb.product_id WHERE sb.branch_id=$branch_id AND sb.is_depleted=0 AND p.is_active=1")->fetch_assoc();
$stock_value = $sv['val']; $total_stock_qty = $sv['qty'];

// Product count
$pc = $conn->query("SELECT COUNT(*) as c FROM products WHERE is_active=1")->fetch_assoc();
$product_count = $pc['c'];

// Low stock
$low_stock = [];
$ls = $conn->query("SELECT p.id,p.name,p.unit,p.min_stock,COALESCE(SUM(sb.quantity_remaining),0) as stock FROM products p LEFT JOIN stock_batches sb ON sb.product_id=p.id AND sb.branch_id=$branch_id AND sb.is_depleted=0 WHERE p.is_active=1 GROUP BY p.id HAVING stock<=p.min_stock AND stock>=0 ORDER BY stock ASC LIMIT 8");
while($r = $ls->fetch_assoc()) $low_stock[] = $r;

// Monthly revenue for chart — every month of the CURRENT Ethiopian year,
// starting at Meskerem (month 1) through the current month (not a rolling
// "last 6 months" window, so the chart always starts at the Ethiopian new
// year). Revenue is summed from transactions alone (no item join) so it is
// never multiplied by how many line items a sale had; profit is summed
// separately from transaction_items and merged in per month.
$monthly = [];
$curEthM = ethDate($today);
$ey = $curEthM['year']; $em = $curEthM['month'];
for ($mm = 1; $mm <= $em; $mm++) {
    $mns = ethiopianToGregorianYMD($ey, $mm, 1);
    $nmm = $mm + 1; $nmy = $ey;
    if ($nmm > 13) { $nmm = 1; $nmy++; }
    $mne = date('Y-m-d', strtotime(ethiopianToGregorianYMD($nmy, $nmm, 1) . ' -1 day'));
    if ($mne > $today) $mne = $today;
    $mr = $conn->query("SELECT COALESCE(SUM(total_amount),0) as rev FROM transactions WHERE DATE(transaction_date) BETWEEN '$mns' AND '$mne' AND branch_id=$branch_id")->fetch_assoc();
    $mp = $conn->query("SELECT COALESCE(SUM(ti.profit),0) as prof FROM transactions t JOIN transaction_items ti ON ti.transaction_id=t.id WHERE DATE(t.transaction_date) BETWEEN '$mns' AND '$mne' AND t.branch_id=$branch_id")->fetch_assoc();
    $monthly[] = ['month'=>ethDate($mns)['month_name'],'rev'=>(float)$mr['rev'],'prof'=>(float)$mp['prof']];
}

// Top products (month)
$top = [];
$tq = $conn->query("SELECT p.name, COALESCE(SUM(ti.quantity),0) as qty, COALESCE(SUM(ti.subtotal),0) as rev FROM transaction_items ti JOIN transactions t ON t.id=ti.transaction_id JOIN products p ON p.id=ti.product_id WHERE DATE(t.transaction_date)>='$thisMonth' AND t.branch_id=$branch_id GROUP BY p.id ORDER BY rev DESC LIMIT 5");
while($r=$tq->fetch_assoc()) $top[]=$r;

// Recent activity
$recent = [];
$rq = $conn->query("SELECT t.id,t.receipt_number,t.total_amount,t.payment_method,t.transaction_date,u.full_name as seller FROM transactions t LEFT JOIN users u ON u.id=t.seller_id WHERE t.branch_id=$branch_id ORDER BY t.transaction_date DESC LIMIT 10");
while($r=$rq->fetch_assoc()) {
    $r['eth_date'] = ethDateDisplay($r['transaction_date']);
    $r['time_12h']  = time12hr($r['transaction_date']);
    $recent[] = $r;
}

// Monthly sales for this month
$ms = $conn->query("SELECT COALESCE(SUM(total_amount),0) as rev FROM transactions WHERE DATE(transaction_date)>='$thisMonth' AND branch_id=$branch_id")->fetch_assoc();
$month_sales = $ms['rev'];
$msp = $conn->query("SELECT COALESCE(SUM(ti.profit),0) as prof FROM transaction_items ti JOIN transactions t ON t.id=ti.transaction_id WHERE DATE(t.transaction_date)>='$thisMonth' AND t.branch_id=$branch_id")->fetch_assoc();
$month_profit = $msp['prof'];
?><!DOCTYPE html>
<html lang="am">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ዳሽቦርድ — <?= h($biz_name) ?></title>
<link rel="icon" type="image/png" href="images/icon.png">
<?php include 'layout_start.php'; ?>
<script src="assets/chartjs/chart.umd.js"></script>
<style>
.chart-card{background:var(--card);border-radius:var(--radius);padding:20px;box-shadow:var(--shadow);}
.chart-card canvas{max-height:260px;}
.low-stock-item{
  display:flex;align-items:center;justify-content:space-between;
  padding:10px 0;border-bottom:1px solid var(--border-light);
}
.low-stock-item:last-child{border-bottom:none;}
.top-product-bar{height:8px;border-radius:4px;background:linear-gradient(90deg,var(--brown),var(--gold));margin-top:4px;}
.activity-item{
  display:flex;align-items:center;gap:12px;padding:10px 0;
  border-bottom:1px solid var(--border-light);animation:fadeIn .3s ease;
}
.activity-item:last-child{border-bottom:none;}
.activity-icon{
  width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;
  background:var(--gold-pale);color:var(--brown);font-size:0.9rem;flex-shrink:0;
}
.activity-info{flex:1;min-width:0;}
.activity-name{font-size:0.85rem;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.activity-meta{font-size:0.75rem;color:var(--text-muted);}
.activity-amount{font-size:0.9rem;font-weight:700;color:var(--brown);white-space:nowrap;}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
.three-col{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;}
@media(max-width:700px){.two-col,.three-col{grid-template-columns:1fr;}}
</style>
</head>
<body>
<?php include 'nav.php'; ?>

<div class="main-content">
  <!-- Header -->
  <div class="page-header">
    <div>
      <h1><i class="fas fa-tachometer-alt"></i> ዳሽቦርድ</h1>
      <div class="page-title-sub"><?= h($branch_name) ?> &bull; <?= h($eth_now['eth_date']) ?> &bull; <span id="liveTime"><?= h($eth_now['greg_time']) ?></span></div>
    </div>
    <div style="display:flex;gap:10px;align-items:center;">
      <span class="badge badge-gold"><i class="fas fa-user"></i> <?= h($admin_name) ?></span>
      <a href="admin_products.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> ምርት ጨምር</a>
    </div>
  </div>

  <!-- Stats -->
  <div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr));">
    <div class="stat-card gold">
      <div class="stat-icon">💰</div>
      <div class="stat-value"><?= money($today_sales) ?></div>
      <div class="stat-label">የዛሬ ሽያጭ (ብር)</div>
      <div style="font-size:0.75rem;color:var(--text-muted);margin-top:4px;"><?= $today_txns ?> ሽያጮች</div>
    </div>
    <div class="stat-card green">
      <div class="stat-icon">📈</div>
      <div class="stat-value"><?= money($today_profit) ?></div>
      <div class="stat-label">የዛሬ ትርፍ (ብር)</div>
      <?php if ($today_sales > 0): ?>
      <div style="font-size:0.75rem;color:var(--green);margin-top:4px;"><?= round($today_profit/$today_sales*100,1) ?>% margin</div>
      <?php endif; ?>
    </div>
    <div class="stat-card blue">
      <div class="stat-icon">📦</div>
      <div class="stat-value"><?= money($month_sales) ?></div>
      <div class="stat-label">የወር ሽያጭ (ብር)</div>
    </div>
    <div class="stat-card gold">
      <div class="stat-icon">💎</div>
      <div class="stat-value"><?= money($month_profit) ?></div>
      <div class="stat-label">የወር ትርፍ (ብር)</div>
    </div>
    <div class="stat-card blue">
      <div class="stat-icon">🏷️</div>
      <div class="stat-value"><?= $product_count ?></div>
      <div class="stat-label">ምርቶች</div>
    </div>
    <div class="stat-card <?= count($low_stock) > 0 ? 'red' : 'green' ?>">
      <div class="stat-icon"><?= count($low_stock) > 0 ? '⚠️' : '✅' ?></div>
      <div class="stat-value"><?= count($low_stock) ?></div>
      <div class="stat-label">ቀሪ ክምችት</div>
    </div>
  </div>

  <!-- Charts Row -->
  <div class="two-col section-gap">
    <div class="chart-card">
      <div class="card-header">
        <span class="card-title"><i class="fas fa-chart-bar"></i> የወር ሽያጭ (ብር)</span>
      </div>
      <canvas id="salesChart"></canvas>
    </div>
    <div class="chart-card">
      <div class="card-header">
        <span class="card-title"><i class="fas fa-chart-line"></i> የወር ትርፍ (ብር)</span>
      </div>
      <canvas id="profitChart"></canvas>
    </div>
  </div>

  <!-- Low Stock + Top Products -->
  <div class="two-col section-gap">
    <div class="card">
      <div class="card-header">
        <span class="card-title"><i class="fas fa-exclamation-triangle"></i> ቀሪ ክምችት</span>
        <a href="admin_products.php" class="btn btn-sm btn-outline">ሁሉ ይመልከቱ</a>
      </div>
      <?php if (empty($low_stock)): ?>
        <div style="text-align:center;padding:24px;color:var(--text-muted);">
          <i class="fas fa-check-circle" style="color:var(--green);font-size:2rem;display:block;margin-bottom:8px;"></i>
          ሁሉም ምርቶች ክምችት አላቸው
        </div>
      <?php else: ?>
        <?php foreach($low_stock as $ls): ?>
        <div class="low-stock-item">
          <div>
            <div style="font-size:0.88rem;font-weight:600;"><?= h($ls['name']) ?></div>
            <div style="font-size:0.75rem;color:var(--text-muted);">ዝቅ: <?= h($ls['min_stock']) ?> <?= h($ls['unit']) ?></div>
          </div>
          <div style="text-align:right;">
            <span class="badge <?= $ls['stock']==0?'badge-red':'badge-gold' ?>">
              <?= $ls['stock']==0 ? 'አልቋል' : h($ls['stock']).' '.h($ls['unit']) ?>
            </span>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-header">
        <span class="card-title"><i class="fas fa-trophy"></i> ምርጥ ምርቶች (ወር)</span>
      </div>
      <?php if (empty($top)): ?>
        <div style="text-align:center;padding:24px;color:var(--text-muted);">ገና ሽያጭ የለም</div>
      <?php else:
        $maxRev = max(array_column($top,'rev'));
        foreach($top as $i=>$t): ?>
        <div style="padding:8px 0;">
          <div style="display:flex;justify-content:space-between;font-size:0.87rem;">
            <span><?= h($t['name']) ?></span>
            <span style="font-weight:700;color:var(--brown);"><?= money($t['rev']) ?> ብር</span>
          </div>
          <div class="top-product-bar" style="width:<?= $maxRev>0?round($t['rev']/$maxRev*100):0 ?>%"></div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- Recent Activity -->
  <div class="card section-gap">
    <div class="card-header">
      <span class="card-title"><i class="fas fa-clock"></i> የቅርብ ሽያጮች</span>
      <a href="admin_reports.php" class="btn btn-sm btn-outline">ሪፖርት ይመልከቱ</a>
    </div>
    <?php if (empty($recent)): ?>
      <div style="text-align:center;padding:24px;color:var(--text-muted);">ገና ሽያጭ የለም</div>
    <?php else: ?>
      <?php foreach($recent as $a): ?>
      <div class="activity-item">
        <div class="activity-icon"><i class="fas fa-receipt"></i></div>
        <div class="activity-info">
          <div class="activity-name"><?= h($a['receipt_number']) ?> — <?= h($a['seller']) ?></div>
          <div class="activity-meta"><?= h($a['eth_date']) ?> <?= h($a['time_12h']) ?> · <?= h($a['payment_method']) ?></div>
        </div>
        <div class="activity-amount"><?= money($a['total_amount']) ?> ብር</div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<script>
// Live clock
setInterval(()=>{
  const d=new Date(),h=d.getHours()%12||12,m=String(d.getMinutes()).padStart(2,'0'),
        s=String(d.getSeconds()).padStart(2,'0'),ap=d.getHours()<12?'AM':'PM';
  const el=document.getElementById('liveTime');
  if(el) el.textContent=`${String(h).padStart(2,'0')}:${m}:${s} ${ap}`;
},1000);

// Charts
const months = <?= json_encode(array_column($monthly,'month')) ?>;
const revData = <?= json_encode(array_column($monthly,'rev')) ?>;
const profData = <?= json_encode(array_column($monthly,'prof')) ?>;
const chartOpts = (label,color)=>({
  type:'bar',
  data:{labels:months,datasets:[{label:label,data:revData.map?revData:revData,backgroundColor:color+'30',borderColor:color,borderWidth:2,borderRadius:6}]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,grid:{color:'rgba(0,0,0,0.05)'}},x:{grid:{display:false}}}}
});

const sc = new Chart(document.getElementById('salesChart').getContext('2d'),{
  type:'bar',
  data:{labels:months,datasets:[{label:'ሽያጭ',data:revData,backgroundColor:'rgba(139,69,19,0.15)',borderColor:'#8B4513',borderWidth:2,borderRadius:6}]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true},x:{grid:{display:false}}}}
});
const pc2 = new Chart(document.getElementById('profitChart').getContext('2d'),{
  type:'line',
  data:{labels:months,datasets:[{label:'ትርፍ',data:profData,backgroundColor:'rgba(46,125,50,0.1)',borderColor:'#2E7D32',borderWidth:2,fill:true,tension:0.4,pointBackgroundColor:'#2E7D32',pointRadius:5}]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true},x:{grid:{display:false}}}}
});
</script>
</body>
</html>
