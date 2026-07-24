<?php
require_once 'config.php';
requireAdmin();

$branch_id   = (int)($_SESSION['branch_id'] ?? 1);
$biz_name    = getSetting($conn, 'business_name', 'አጸደ ትጉሃን');
$active_page = 'products';
$eth_now     = getCurrentEthDatetime();

$period = $_GET['period'] ?? 'this_month';
[$from, $to] = resolveDateRangePreset($period, $_GET['from'] ?? null, $_GET['to'] ?? null);
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 25;
$off  = ($page - 1) * $per;

$product_filter = (int)($_GET['pid'] ?? 0);
$where = "WHERE DATE(sb.date_received) BETWEEN '$from' AND '$to' AND sb.branch_id=$branch_id";
if ($product_filter) $where .= " AND sb.product_id=$product_filter";

// Total count
$ct = $conn->query("SELECT COUNT(*) as c FROM stock_batches sb $where")->fetch_assoc();
$total = $ct['c'];
$pages = max(1, ceil($total / $per));

// Load batches
$batches = [];
$bq = $conn->query(
    "SELECT sb.*, p.name as product_name, p.unit, u.full_name as received_by_name
     FROM stock_batches sb
     JOIN products p ON p.id=sb.product_id
     LEFT JOIN users u ON u.id=sb.received_by
     $where
     ORDER BY sb.date_received DESC, sb.id DESC
     LIMIT $per OFFSET $off"
);
while($r=$bq->fetch_assoc()) {
    $r['eth_date']    = ethDateDisplay($r['date_received']);
    $r['eth_created'] = ethDateDisplay($r['created_at']);
    $r['time_12h']    = time12hr($r['created_at']);
    $r['pct_used']    = $r['quantity_received'] > 0
        ? round((($r['quantity_received'] - $r['quantity_remaining']) / $r['quantity_received']) * 100, 1)
        : 0;
    $batches[] = $r;
}

// Sold-per-batch aggregates for all displayed batches in a single query (avoids N+1)
$sold_by_batch = [];
if (!empty($batches)) {
    $batchIds = array_map(fn($b) => (int)$b['id'], $batches);
    $idList = implode(',', $batchIds);
    $sq = $conn->query(
        "SELECT batch_id, COALESCE(SUM(quantity),0) as sold, COALESCE(SUM(subtotal),0) as rev, COALESCE(SUM(profit),0) as profit
         FROM transaction_items WHERE batch_id IN ($idList) GROUP BY batch_id"
    );
    while ($sr = $sq->fetch_assoc()) $sold_by_batch[$sr['batch_id']] = $sr;
}

// Summary
$sm = $conn->query(
    "SELECT COUNT(*) as batch_count,
            COALESCE(SUM(sb.quantity_received),0) as total_received,
            COALESCE(SUM(sb.quantity_remaining),0) as total_remaining,
            COALESCE(SUM(sb.quantity_received * sb.buy_price),0) as total_cost,
            COALESCE(SUM(sb.quantity_remaining * sb.buy_price),0) as stock_value
     FROM stock_batches sb $where"
)->fetch_assoc();

// Products dropdown
$prods = [];
$pq = $conn->query("SELECT id,name FROM products WHERE is_active=1 ORDER BY name");
while($r=$pq->fetch_assoc()) $prods[] = $r;
?><!DOCTYPE html>
<html lang="am">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ክምችት ሎግ — <?= h($biz_name) ?></title>
<link rel="icon" type="image/png" href="images/icon.png">
<?php include 'layout_start.php'; ?>
<style>
.batch-card{
  background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);
  margin-bottom:14px;overflow:hidden;border-left:5px solid var(--gold);
  transition:all var(--transition);
}
.batch-card.depleted{border-color:var(--red);opacity:.8;}
.batch-card.active-batch{border-color:var(--green);}
.batch-header{display:flex;align-items:center;gap:14px;padding:14px 18px;cursor:pointer;}
.batch-header:hover{background:var(--gold-pale);}
.batch-badge{
  width:46px;height:46px;border-radius:50%;display:flex;align-items:center;justify-content:center;
  flex-shrink:0;font-weight:800;font-size:0.78rem;
}
.batch-badge.active{background:var(--green-light);color:var(--green);}
.batch-badge.depleted{background:var(--red-light);color:var(--red);}
.batch-main{flex:1;min-width:0;}
.batch-title{font-size:0.95rem;font-weight:700;color:var(--text);}
.batch-sub{font-size:0.78rem;color:var(--text-muted);margin-top:2px;}
.batch-amounts{text-align:right;white-space:nowrap;}
.batch-price{font-size:1rem;font-weight:800;color:var(--brown);}
.batch-body{display:none;padding:16px 18px;border-top:1px solid var(--border);background:var(--bg);}
.batch-body.open{display:block;}
.batch-detail-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;}
.bd-item{background:var(--card);border-radius:var(--radius-sm);padding:10px 14px;}
.bd-label{font-size:0.72rem;color:var(--text-muted);margin-bottom:3px;}
.bd-value{font-size:0.88rem;font-weight:700;color:var(--text);}
.progress-bar{height:8px;border-radius:4px;background:var(--border);overflow:hidden;margin-top:8px;}
.progress-fill{height:100%;border-radius:4px;background:linear-gradient(90deg,var(--green),var(--gold));transition:width .5s;}
.progress-fill.depleted-fill{background:linear-gradient(90deg,var(--red),var(--orange));}
.filter-bar{background:var(--card);border-radius:var(--radius);padding:14px 18px;margin-bottom:18px;box-shadow:var(--shadow);}
.filter-row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;}
.summary-row-top{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:18px;}
.sum-mini{background:var(--card);border-radius:var(--radius-sm);padding:14px;box-shadow:var(--shadow);text-align:center;}
.sum-mini-val{font-size:1.15rem;font-weight:800;color:var(--brown);}
.sum-mini-lbl{font-size:0.72rem;color:var(--text-muted);margin-top:4px;}
.pager{display:flex;gap:8px;align-items:center;justify-content:center;padding:16px 0;}
.pager a,.pager span{padding:8px 14px;border-radius:var(--radius-sm);font-size:0.85rem;font-weight:600;text-decoration:none;border:1px solid var(--border);}
.pager a{background:var(--card);color:var(--text);} .pager a:hover{border-color:var(--gold);}
.pager span{background:var(--gold);color:#fff;border-color:var(--gold);}
</style>
</head>
<body>
<?php include 'nav.php'; ?>
<div class="main-content">
  <div class="page-header">
    <div>
      <h1><i class="fas fa-warehouse"></i> ክምችት ሎግ</h1>
      <div class="page-title-sub">Stock Receive History &bull; <?= h($eth_now['eth_date']) ?></div>
    </div>
    <a href="admin_products.php" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> ክምችት ጨምር</a>
  </div>

  <!-- Summary -->
  <div class="summary-row-top">
    <div class="sum-mini"><div class="sum-mini-val"><?= $sm['batch_count'] ?></div><div class="sum-mini-lbl">Batches</div></div>
    <div class="sum-mini"><div class="sum-mini-val"><?= number_format($sm['total_received'],2) ?></div><div class="sum-mini-lbl">ጠቅላላ ተቀብሏል</div></div>
    <div class="sum-mini"><div class="sum-mini-val"><?= number_format($sm['total_remaining'],2) ?></div><div class="sum-mini-lbl">ቀሪ ክምችት</div></div>
    <div class="sum-mini"><div class="sum-mini-val"><?= money($sm['total_cost']) ?> ብር</div><div class="sum-mini-lbl">ጠቅላላ ዋጋ</div></div>
    <div class="sum-mini"><div class="sum-mini-val" style="color:var(--green);"><?= money($sm['stock_value']) ?> ብር</div><div class="sum-mini-lbl">ቀሪ ዋጋ</div></div>
  </div>

  <!-- Filter -->
  <div class="filter-bar">
    <div class="filter-row" style="margin-bottom:12px;">
      <?php
        $presets = [
          'today'=>'ዛሬ','yesterday'=>'ትናንት','this_week'=>'ይህ ሳምንት','last_week'=>'ያለፈው ሳምንት',
          'this_month'=>'ይህ ወር','last_month'=>'ያለፈው ወር','this_year'=>'ይህ ዓመት','custom'=>'ራስ-ሰር',
        ];
        foreach ($presets as $key=>$label):
          $qs = http_build_query(array_merge($_GET, ['period'=>$key]));
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
        <div class="form-group" style="margin:0;flex:1;min-width:160px;">
          <label style="font-size:0.78rem;">ምርት</label>
          <select name="pid" class="form-control">
            <option value="">ሁሉም ምርቶች</option>
            <?php foreach($prods as $p): ?>
            <option value="<?= $p['id'] ?>" <?= $product_filter==$p['id']?'selected':'' ?>><?= h($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> ፈልግ</button>
        <a href="stock_log.php" class="btn btn-outline">ሁሉ</a>
      </div>
    </form>
  </div>

  <!-- Results count -->
  <div style="font-size:0.84rem;color:var(--text-muted);margin-bottom:14px;">
    <?= $total ?> batches ተገኝቷል &bull; ገጽ <?= $page ?>/<?= $pages ?>
  </div>

  <!-- Batch Cards -->
  <?php if (empty($batches)): ?>
    <div style="text-align:center;padding:48px;color:var(--text-muted);">
      <i class="fas fa-boxes" style="font-size:3rem;display:block;margin-bottom:12px;color:var(--border);"></i>
      Batch አልተገኘም — ምርት ጨምሩ እና ክምችት ይቀበሉ
    </div>
  <?php else: ?>
    <?php foreach($batches as $b):
      $isActive = !$b['is_depleted'] && $b['quantity_remaining'] > 0;
      $pctUsed  = $b['pct_used'];
    ?>
    <div class="batch-card <?= $b['is_depleted'] ? 'depleted' : ($isActive ? 'active-batch' : '') ?>">
      <div class="batch-header" onclick="toggleBatch('bb_<?= $b['id'] ?>')">
        <div class="batch-badge <?= $b['is_depleted'] ? 'depleted' : 'active' ?>">
          <i class="fas fa-<?= $b['is_depleted'] ? 'check' : 'layer-group' ?>"></i>
        </div>
        <div class="batch-main">
          <div class="batch-title">
            <?= h($b['product_name']) ?>
            <span style="font-size:0.78rem;color:var(--text-muted);font-weight:400;"> — <?= h($b['batch_number']) ?></span>
          </div>
          <div class="batch-sub">
            <?= h($b['eth_date']) ?> &bull;
            ተቀብሏል: <?= number_format($b['quantity_received'],2) ?> <?= h($b['unit']) ?> &bull;
            ቀሪ: <strong style="color:<?= $b['is_depleted']?'var(--red)':'var(--green)' ?>"><?= number_format($b['quantity_remaining'],2) ?></strong>
          </div>
          <!-- Progress bar -->
          <div class="progress-bar" style="margin-top:6px;max-width:220px;">
            <div class="progress-fill <?= $b['is_depleted']?'depleted-fill':'' ?>" style="width:<?= $pctUsed ?>%;"></div>
          </div>
        </div>
        <div class="batch-amounts">
          <div class="batch-price"><?= money($b['sell_price']) ?> ብር</div>
          <div style="font-size:0.72rem;color:var(--text-muted);">ሻጭ ዋጋ</div>
          <span class="badge <?= $b['is_depleted']?'badge-red':($isActive?'badge-green':'badge-gold') ?>" style="margin-top:4px;">
            <?= $b['is_depleted']?'ጠናቀቀ':($isActive?'ንቁ':'ቀሪ') ?>
          </span>
        </div>
      </div>
      <div class="batch-body" id="bb_<?= $b['id'] ?>">
        <div class="batch-detail-grid">
          <div class="bd-item"><div class="bd-label">Batch #</div><div class="bd-value"><?= h($b['batch_number']) ?></div></div>
          <div class="bd-item"><div class="bd-label">ምርት</div><div class="bd-value"><?= h($b['product_name']) ?></div></div>
          <div class="bd-item"><div class="bd-label">ዓይነት</div><div class="bd-value"><?= h($b['unit']) ?></div></div>
          <div class="bd-item"><div class="bd-label">ጠቅ. ተቀብሏል</div><div class="bd-value"><?= number_format($b['quantity_received'],2) ?></div></div>
          <div class="bd-item"><div class="bd-label">ቀሪ</div><div class="bd-value" style="color:<?= $b['is_depleted']?'var(--red)':'var(--green)' ?>"><?= number_format($b['quantity_remaining'],2) ?></div></div>
          <div class="bd-item"><div class="bd-label">% ጥቅም ላይ</div><div class="bd-value"><?= $pctUsed ?>%</div></div>
          <div class="bd-item"><div class="bd-label">ገዥ ዋጋ</div><div class="bd-value"><?= money($b['buy_price']) ?> ብር</div></div>
          <div class="bd-item"><div class="bd-label">ሻጭ ዋጋ</div><div class="bd-value"><?= money($b['sell_price']) ?> ብር</div></div>
          <div class="bd-item"><div class="bd-label">ትርፍ/ዩኒት</div><div class="bd-value" style="color:var(--green);"><?= money($b['sell_price']-$b['buy_price']) ?> ብር</div></div>
          <div class="bd-item"><div class="bd-label">ተቀብሎ</div><div class="bd-value"><?= h($b['received_by_name']??'—') ?></div></div>
          <div class="bd-item"><div class="bd-label">ቀን (Ethiopian)</div><div class="bd-value"><?= h($b['eth_date']) ?></div></div>
          <div class="bd-item"><div class="bd-label">ሰዓት</div><div class="bd-value"><?= h($b['time_12h']) ?></div></div>
          <div class="bd-item"><div class="bd-label">ሁኔታ</div><div class="bd-value"><span class="badge <?= $b['is_depleted']?'badge-red':'badge-green' ?>"><?= $b['is_depleted']?'ጠናቀቀ':'ንቁ' ?></span></div></div>
        </div>
        <?php if ($b['notes']): ?>
        <div style="margin-top:12px;padding:10px;background:var(--card);border-radius:var(--radius-sm);font-size:0.85rem;color:var(--text-muted);">
          <i class="fas fa-sticky-note" style="color:var(--gold)"></i> <?= h($b['notes']) ?>
        </div>
        <?php endif; ?>
        <?php
        // Sold from this batch (looked up from pre-fetched aggregate, no per-row query)
        $sold = $sold_by_batch[$b['id']] ?? null;
        if ($sold && $sold['sold'] > 0):
        ?>
        <div style="margin-top:12px;background:var(--green-light);border-radius:var(--radius-sm);padding:12px;display:flex;gap:20px;flex-wrap:wrap;">
          <div><div style="font-size:0.72rem;color:var(--text-muted);">ከዚህ batch የተሸጠ</div><div style="font-weight:800;color:var(--green);"><?= number_format($sold['sold'],2) ?> <?= h($b['unit']) ?></div></div>
          <div><div style="font-size:0.72rem;color:var(--text-muted);">ሽያጭ</div><div style="font-weight:800;color:var(--brown);"><?= money($sold['rev']) ?> ብር</div></div>
          <div><div style="font-size:0.72rem;color:var(--text-muted);">ትርፍ</div><div style="font-weight:800;color:var(--green);"><?= money($sold['profit']) ?> ብር</div></div>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <!-- Pagination -->
  <?php if ($pages > 1):
    $base = '?from='.$from.'&to='.$to.($product_filter?'&pid='.$product_filter:'');
  ?>
  <div class="pager">
    <?php if($page>1): ?><a href="<?= $base ?>&page=<?= $page-1 ?>">← ቀዳሚ</a><?php endif; ?>
    <span><?= $page ?> / <?= $pages ?></span>
    <?php if($page<$pages): ?><a href="<?= $base ?>&page=<?= $page+1 ?>">ቀጣይ →</a><?php endif; ?>
  </div>
  <?php endif; ?>

</div><!-- /main-content -->
<script>
function toggleBatch(id){
  const el=document.getElementById(id);
  if(el) el.classList.toggle('open');
}
</script>
</body>
</html>
