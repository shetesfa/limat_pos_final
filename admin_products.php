<?php
require_once 'config.php';
requireAdmin();

$branch_id  = (int)($_SESSION['branch_id'] ?? 1);
$biz_name   = getSetting($conn, 'business_name', 'አጸደ ትጉሃን');
$active_page = 'products';
$eth_now    = getCurrentEthDatetime();
$msg = ''; $msg_type = '';

// ── Handle AJAX file upload separately ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'CSRF error']); exit(); }

    $action = $_POST['action'];

    // ── Add Product ──
    if ($action === 'add_product') {
        $name = trim($_POST['name'] ?? '');
        $unit = $_POST['unit'] ?? 'PCS';
        $category = trim($_POST['category'] ?? '') ?: null;
        $sku = trim($_POST['sku'] ?? '') ?: null;
        $barcode = trim($_POST['barcode'] ?? '') ?: null;
        $desc = trim($_POST['description'] ?? '');
        $min_stock = (float)($_POST['min_stock'] ?? 5);
        $image_path = '';

        if (!$name) { echo json_encode(['success'=>false,'message'=>'ምርት ስም አስፈላጊ ነው']); exit(); }
        if (!in_array($unit, ['PCS','KG','LITER','BOX','PACK'])) $unit = 'PCS';

        // Handle image upload
        if (!empty($_FILES['image']['name'])) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                if (!is_dir('uploads/products')) mkdir('uploads/products', 0755, true);
                $fn = 'prod_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($_FILES['image']['tmp_name'], 'uploads/products/' . $fn)) {
                    $image_path = 'uploads/products/' . $fn;
                }
            }
        }

        $uid = $_SESSION['user_id'];
        $stmt = $conn->prepare("INSERT INTO products (name,unit,category,sku,barcode,description,image,min_stock,is_active,created_by) VALUES (?,?,?,?,?,?,?,?,1,?)");
        $stmt->bind_param('sssssssdi', $name, $unit, $category, $sku, $barcode, $desc, $image_path, $min_stock, $uid);
        if ($stmt->execute()) {
            $pid = $conn->insert_id;
            auditLog($conn,'ADD_PRODUCT','products',$pid,null,json_encode(['name'=>$name,'unit'=>$unit]),"ምርት ተጨምሯል: $name");
            echo json_encode(['success'=>true,'product_id'=>$pid,'product_name'=>$name]);
        } else {
            echo json_encode(['success'=>false,'message'=>'Save failed: '.$stmt->error]);
        }
        $stmt->close(); exit();
    }

    // ── Receive Stock (FIFO batch) ──
    if ($action === 'receive_stock') {
        $pid       = (int)($_POST['product_id'] ?? 0);
        $buy       = (float)($_POST['buy_price'] ?? 0);
        $sell      = (float)($_POST['sell_price'] ?? 0);
        $qty       = (float)($_POST['quantity'] ?? 0);
        $supplier  = trim($_POST['supplier'] ?? '');
        $invoice   = trim($_POST['invoice_number'] ?? '');
        $rec_date  = trim($_POST['date_received'] ?? date('Y-m-d'));
        $notes     = trim($_POST['notes'] ?? '');

        if (!$pid || $qty <= 0 || $sell <= 0)
            { echo json_encode(['success'=>false,'message'=>'ዋጋ ወይም ብዛት ያስፈልጋል']); exit(); }

        // Generate batch number — same clean sequential style as receipts (ATSs00001, ATSs00002...)
        $bn_r = $conn->query("SELECT COUNT(*)+1 as n FROM stock_batches");
        $bn_n = $bn_r->fetch_assoc()['n'];
        $batch_num = 'ATSs' . str_pad($bn_n, 5, '0', STR_PAD_LEFT);

        $uid = $_SESSION['user_id'];
        $stmt = $conn->prepare("INSERT INTO stock_batches (product_id,branch_id,batch_number,supplier,invoice_number,quantity_received,quantity_remaining,buy_price,sell_price,received_by,notes,date_received) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('iisssdddddss', $pid,$branch_id,$batch_num,$supplier,$invoice,$qty,$qty,$buy,$sell,$uid,$notes,$rec_date);
        if ($stmt->execute()) {
            $bid = $conn->insert_id;
            // Log to product_edit_history
            $pn = $conn->prepare("SELECT name FROM products WHERE id=?");
            $pn->bind_param('i', $pid); $pn->execute();
            $pn = $pn->get_result()->fetch_assoc()['name'];
            $ph = $conn->prepare("INSERT INTO product_edit_history (product_id,user_id,field_changed,new_value,reason,ip_address,device_type) VALUES (?,?,'STOCK_RECEIVE',?,?,?,?)");
            $new_v = json_encode(['qty'=>$qty,'buy'=>$buy,'sell'=>$sell,'batch'=>$batch_num]);
            $reason_txt = "ክምችት ተቀብሏል: $qty";
            $ip = getClientIP(); $dev = getDeviceType();
            $ph->bind_param('iissss',$pid,$uid,$new_v,$reason_txt,$ip,$dev);
            $ph->execute(); $ph->close();
            auditLog($conn,'RECEIVE_STOCK','stock_batches',$bid,null,$new_v,"ክምችት: $pn — $qty");
            echo json_encode(['success'=>true,'message'=>'ክምችት ተቀብሏል! Batch: '.$batch_num]);
        } else {
            echo json_encode(['success'=>false,'message'=>$stmt->error]);
        }
        $stmt->close(); exit();
    }

    // ── Edit Product ──
    if ($action === 'edit_product') {
        $pid  = (int)($_POST['product_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $unit = $_POST['unit'] ?? 'PCS';
        $category = trim($_POST['category'] ?? '') ?: null;
        $sku = trim($_POST['sku'] ?? '') ?: null;
        $barcode = trim($_POST['barcode'] ?? '') ?: null;
        $desc = trim($_POST['description'] ?? '');
        $min_stock = (float)($_POST['min_stock'] ?? 5);
        $reason = trim($_POST['reason'] ?? '');

        if (!$pid || !$name) { echo json_encode(['success'=>false,'message'=>'ትክክለኛ መረጃ ያስፈልጋል']); exit(); }

        // Get old values
        $old = $conn->prepare("SELECT * FROM products WHERE id=?");
        $old->bind_param('i',$pid); $old->execute();
        $old_data = $old->get_result()->fetch_assoc(); $old->close();
        if (!$old_data) { echo json_encode(['success'=>false,'message'=>'ምርት አልተገኘም']); exit(); }

        $image_path = $old_data['image'];
        if (!empty($_FILES['image']['name'])) {
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                if (!is_dir('uploads/products')) mkdir('uploads/products', 0755, true);
                $fn = 'prod_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($_FILES['image']['tmp_name'], 'uploads/products/' . $fn)) {
                    if ($image_path && file_exists($image_path)) @unlink($image_path);
                    $image_path = 'uploads/products/' . $fn;
                }
            }
        }

        $uid = $_SESSION['user_id'];
        $stmt = $conn->prepare("UPDATE products SET name=?,unit=?,category=?,sku=?,barcode=?,description=?,image=?,min_stock=? WHERE id=?");
        $stmt->bind_param('sssssssdi',$name,$unit,$category,$sku,$barcode,$desc,$image_path,$min_stock,$pid);
        if ($stmt->execute()) {
            // Log changed fields
            $old_v = json_encode(['name'=>$old_data['name'],'unit'=>$old_data['unit']]);
            $new_v = json_encode(['name'=>$name,'unit'=>$unit]);
            $ip=getClientIP();$dev=getDeviceType();
            $ph=$conn->prepare("INSERT INTO product_edit_history (product_id,user_id,field_changed,old_value,new_value,reason,ip_address,device_type) VALUES (?,?,'PRODUCT_EDIT',?,?,?,?,?)");
            $ph->bind_param('iisssss',$pid,$uid,$old_v,$new_v,$reason,$ip,$dev);
            $ph->execute();$ph->close();
            auditLog($conn,'EDIT_PRODUCT','products',$pid,$old_v,$new_v,"ምርት ተስተካክሏል: $name");
            echo json_encode(['success'=>true,'message'=>'ምርት ተስተካክሏል']);
        } else { echo json_encode(['success'=>false,'message'=>$stmt->error]); }
        $stmt->close(); exit();
    }

    // ── Toggle product active ──
    if ($action === 'toggle_product') {
        $pid = (int)($_POST['product_id'] ?? 0);
        $r = $conn->query("SELECT is_active,name FROM products WHERE id=$pid")->fetch_assoc();
        if (!$r) { echo json_encode(['success'=>false]); exit(); }
        $new = $r['is_active'] ? 0 : 1;
        $conn->query("UPDATE products SET is_active=$new WHERE id=$pid");
        auditLog($conn,'TOGGLE_PRODUCT','products',$pid,$r['is_active'],$new,($new?'ተቃ':'ቀ').'ነ: '.$r['name']);
        echo json_encode(['success'=>true,'is_active'=>$new]);
        exit();
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']); exit();
}

// ── Load products list ─────────────────────────────────────────
$products = [];
$pq = $conn->query(
    "SELECT p.id, p.name, p.unit, p.category, p.sku, p.barcode, p.description, p.image, p.min_stock, p.is_active, p.created_at,
            COALESCE(SUM(sb.quantity_remaining),0) AS stock,
            (SELECT sb2.sell_price FROM stock_batches sb2 WHERE sb2.product_id=p.id AND sb2.branch_id=$branch_id AND sb2.is_depleted=0 ORDER BY sb2.created_at ASC LIMIT 1) AS sell_price,
            (SELECT sb2.buy_price  FROM stock_batches sb2 WHERE sb2.product_id=p.id AND sb2.branch_id=$branch_id AND sb2.is_depleted=0 ORDER BY sb2.created_at ASC LIMIT 1) AS buy_price,
            (SELECT COUNT(*) FROM stock_batches sb3 WHERE sb3.product_id=p.id) AS batch_count,
            (SELECT GROUP_CONCAT(DISTINCT sb4.supplier SEPARATOR ', ') FROM stock_batches sb4 WHERE sb4.product_id=p.id AND sb4.supplier IS NOT NULL AND sb4.supplier<>'') AS suppliers
     FROM products p
     LEFT JOIN stock_batches sb ON sb.product_id=p.id AND sb.branch_id=$branch_id AND sb.is_depleted=0
     GROUP BY p.id ORDER BY p.name"
);
while($r=$pq->fetch_assoc()) $products[]=$r;

// ── Dashboard summary (Step 4) ──────────────────────────────────
$total_products = count($products);
$low_stock_count = 0; $out_of_stock_count = 0; $inventory_value = 0.0;
foreach ($products as $p) {
    $stk = (float)$p['stock'];
    if ($stk <= 0) $out_of_stock_count++;
    elseif ($stk <= (float)$p['min_stock']) $low_stock_count++;
}
$ivRow = $conn->query(
    "SELECT COALESCE(SUM(sb.quantity_remaining * sb.buy_price),0) as val
     FROM stock_batches sb JOIN products p ON p.id=sb.product_id
     WHERE sb.branch_id=$branch_id AND sb.is_depleted=0 AND p.is_active=1"
)->fetch_assoc();
$inventory_value = (float)$ivRow['val'];

// Categories for filter dropdown
$categories = [];
$catq = $conn->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category<>'' ORDER BY category");
while ($cr = $catq->fetch_assoc()) $categories[] = $cr['category'];

?><!DOCTYPE html>
<html lang="am">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ምርቶች — <?= h($biz_name) ?></title>
<link rel="icon" type="image/png" href="images/icon.png">
<?php include 'layout_start.php'; ?>
<style>
.prod-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;}
.prod-card{
  background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;
  transition:all var(--transition);border:2px solid transparent;
}
.prod-card:hover{border-color:var(--gold);transform:translateY(-3px);box-shadow:var(--shadow-md);}
.prod-card.inactive{opacity:.6;}
.prod-card-img{
  width:100%;height:120px;object-fit:cover;background:var(--gold-pale);
  display:flex;align-items:center;justify-content:center;font-size:3rem;position:relative;
}
.prod-card-img img{width:100%;height:100%;object-fit:cover;}
.prod-card-img .stock-badge{
  position:absolute;top:8px;right:8px;
}
.prod-card-body{padding:14px;}
.prod-card-name{font-size:0.95rem;font-weight:700;color:var(--text);margin-bottom:6px;}
.prod-card-meta{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;}
.prod-card-prices{display:flex;gap:12px;margin-bottom:12px;}
.price-box{background:var(--bg);border-radius:8px;padding:6px 10px;flex:1;text-align:center;}
.price-label{font-size:0.68rem;color:var(--text-muted);}
.price-val{font-size:0.9rem;font-weight:700;color:var(--brown);}
.prod-card-actions{display:flex;gap:8px;}
.batches-table{font-size:0.82rem;}
.batches-table th{padding:8px 10px;font-size:0.72rem;}
.batches-table td{padding:8px 10px;}
@media(max-width:600px){.prod-grid{grid-template-columns:1fr;}}
</style>
</head>
<body>
<?php include 'nav.php'; ?>
<div class="main-content">
  <!-- Header -->
  <div class="page-header">
    <div>
      <h1><i class="fas fa-boxes"></i> ምርቶች</h1>
      <div class="page-title-sub"><?= count($products) ?> ምርቶች · <?= h($eth_now['eth_date']) ?></div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <button class="btn btn-primary" onclick="openAddProduct()"><i class="fas fa-plus"></i> ምርት ጨምር</button>
    </div>
  </div>

  <!-- Dashboard summary -->
  <div class="stats-grid">
    <div class="stat-card blue">
      <div class="stat-icon">🏷️</div>
      <div class="stat-value"><?= $total_products ?></div>
      <div class="stat-label">ጠቅላላ ምርቶች</div>
    </div>
    <div class="stat-card <?= $low_stock_count>0?'gold':'green' ?>">
      <div class="stat-icon">⚠️</div>
      <div class="stat-value"><?= $low_stock_count ?></div>
      <div class="stat-label">ቀሪ ክምችት (ዝቅተኛ)</div>
    </div>
    <div class="stat-card <?= $out_of_stock_count>0?'red':'green' ?>">
      <div class="stat-icon">🚫</div>
      <div class="stat-value"><?= $out_of_stock_count ?></div>
      <div class="stat-label">ያለቀ ክምችት</div>
    </div>
    <div class="stat-card gold">
      <div class="stat-icon">💰</div>
      <div class="stat-value"><?= money($inventory_value) ?></div>
      <div class="stat-label">የክምችት ዋጋ (ብር)</div>
    </div>
  </div>

  <!-- Search + Filters -->
  <div class="filter-bar" style="background:var(--card);border-radius:var(--radius);padding:14px 18px;margin-bottom:18px;box-shadow:var(--shadow);">
    <div class="filter-row" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
      <div class="form-group" style="margin:0;flex:2;min-width:220px;">
        <label style="font-size:0.78rem;">ፈልግ (ስም, አቅራቢ)</label>
        <input type="text" class="form-control" id="prodSearch" placeholder="🔍 ለምሳሌ: ስኳር, 12345..." oninput="filterProds()">
      </div>
      <div class="form-group" style="margin:0;flex:1;min-width:160px;">
        <label style="font-size:0.78rem;">ምድብ</label>
        <select class="form-control" id="catFilter" onchange="filterProds()">
          <option value="">ሁሉም ምድቦች</option>
          <?php foreach ($categories as $c): ?>
          <option value="<?= h(strtolower($c)) ?>"><?= h($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0;flex:1;min-width:160px;">
        <label style="font-size:0.78rem;">ሁኔታ</label>
        <select class="form-control" id="stockFilter" onchange="filterProds()">
          <option value="">ሁሉም</option>
          <option value="low">ቀሪ ክምችት</option>
          <option value="out">ያለቀ</option>
          <option value="ok">በቂ ክምችት</option>
        </select>
      </div>
    </div>
  </div>

  <div id="noResults" style="display:none;text-align:center;padding:48px;color:var(--text-muted);">
    <i class="fas fa-search" style="font-size:3rem;display:block;margin-bottom:12px;color:var(--border);"></i>
    ምንም ውጤት አልተገኘም
  </div>

  <!-- Products Grid -->
  <div class="prod-grid" id="prodGrid">
    <?php foreach($products as $p):
      $stock = (float)$p['stock'];
      $isLow = $stock <= (float)$p['min_stock'];
      $isOut = $stock <= 0;
      $sell  = (float)($p['sell_price'] ?? 0);
      $buy   = (float)($p['buy_price'] ?? 0);
    ?>
    <div class="prod-card <?= $p['is_active']?'':'inactive' ?>"
         data-name="<?= h(strtolower($p['name'])) ?>"
         data-category="<?= h(strtolower($p['category'] ?? '')) ?>"
         data-suppliers="<?= h(strtolower($p['suppliers'] ?? '')) ?>"
         data-stockstatus="<?= $isOut ? 'out' : ($isLow ? 'low' : 'ok') ?>">
      <div class="prod-card-img">
        <?php if ($p['image'] && file_exists($p['image'])): ?>
          <img src="<?= h($p['image']) ?>" alt="<?= h($p['name']) ?>">
        <?php else: ?>
          <i class="fas fa-box" style="color:var(--gold);opacity:.6;"></i>
        <?php endif; ?>
        <span class="stock-badge badge <?= $isOut?'badge-red':($isLow?'badge-gold':'badge-green') ?>">
          <?= $isOut?'አልቋል':($isLow?'ቀሪ':'አለ') ?>
        </span>
      </div>
      <div class="prod-card-body">
        <div class="prod-card-name"><?= h($p['name']) ?></div>
        <div class="prod-card-meta">
          <span class="badge badge-blue"><?= h($p['unit']) ?></span>
          <?php if(!empty($p['category'])): ?><span class="badge badge-purple"><?= h($p['category']) ?></span><?php endif; ?>
          <?php if(!$p['is_active']): ?><span class="badge badge-red">ቆሟል</span><?php endif; ?>
          <span class="badge badge-purple"><?= $p['batch_count'] ?> batch<?= $p['batch_count']!=1?'es':'' ?></span>
        </div>
        <div style="font-size:0.82rem;color:var(--text-muted);margin-bottom:10px;">
          ክምችት: <strong style="color:<?= $isOut?'var(--red)':($isLow?'var(--orange)':'var(--green)') ?>">
            <?= number_format($stock, $stock==intval($stock)?0:2) ?> <?= h($p['unit']) ?>
          </strong>
          &nbsp;(ዝቅ: <?= h($p['min_stock']) ?>)
        </div>
        <div class="prod-card-prices">
          <div class="price-box">
            <div class="price-label">ገዥ ዋጋ</div>
            <div class="price-val"><?= $buy>0?money($buy).'<small> ብር</small>':'—' ?></div>
          </div>
          <div class="price-box">
            <div class="price-label">ሻጭ ዋጋ</div>
            <div class="price-val"><?= $sell>0?money($sell).'<small> ብር</small>':'—' ?></div>
          </div>
        </div>
        <div class="prod-card-actions">
          <button class="btn btn-sm btn-primary" style="flex:1;" onclick='openReceiveStock(<?= $p["id"] ?>, <?= json_encode($p["name"]) ?>, <?= $stock ?>, <?= json_encode($p["unit"]) ?>, <?= $sell ?>, <?= $buy ?>)'>
            <i class="fas fa-plus-circle"></i> ክምችት / ዋጋ
          </button>
          <button class="btn btn-sm btn-outline" onclick='openEditProduct(<?= json_encode($p) ?>)' title="አስተካክል">
            <i class="fas fa-edit"></i> አስተካክል
          </button>
          <button class="btn btn-sm btn-outline" onclick="openHistory(<?= $p['id'] ?>, <?= json_encode($p['name']) ?>)" title="ታሪክ">
            <i class="fas fa-history"></i> ታሪክ
          </button>
          <button class="btn btn-sm <?= $p['is_active']?'btn-outline':'btn-success' ?>" onclick="toggleProduct(<?= $p['id'] ?>, this)" title="<?= $p['is_active']?'አቁም':'አስጀምር' ?>">
            <i class="fas fa-<?= $p['is_active']?'ban':'check' ?>"></i>
          </button>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if(empty($products)): ?>
    <div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--text-muted);">
      <i class="fas fa-box-open" style="font-size:3rem;margin-bottom:12px;display:block;color:var(--border);"></i>
      ምርት አልተጨመረም። ክፍፍሉ ምርት ጨምሩ።
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ═══ Add Product Modal ═══ -->
<div class="modal-backdrop" id="addProductModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title"><i class="fas fa-plus" style="color:var(--gold)"></i> ምርት ጨምር</span>
      <button class="modal-close" onclick="closeModal('addProductModal')">✕</button>
    </div>
    <div class="modal-body">
      <form id="addProductForm" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_product">
        <div class="form-group">
          <label>የምርት ስም *</label>
          <input type="text" name="name" class="form-control" placeholder="ለምሳሌ: መጽሐፍ ቅዱስ" required maxlength="150">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="form-group">
            <label>ዓይነት</label>
            <select name="unit" class="form-control">
              <option>PCS</option><option>KG</option><option>LITER</option><option>BOX</option><option>PACK</option>
            </select>
          </div>
          <div class="form-group">
            <label>ዝቅተኛ ክምችት</label>
            <input type="number" name="min_stock" class="form-control" value="5" min="0" step="1">
          </div>
        </div>
        <div class="form-group">
          <label>ምድብ (Category)</label>
          <input type="text" name="category" class="form-control" list="categoryOptions" placeholder="ለምሳሌ: መጠጦች">
        </div>
        <div class="form-group">
          <label>መግለጫ</label>
          <textarea name="description" class="form-control" rows="2" placeholder="አጭር መግለጫ..."></textarea>
        </div>
        <div class="form-group">
          <label>ፎቶ (አማራጭ)</label>
          <input type="file" name="image" class="form-control" accept="image/*" onchange="previewImg(this,'addPreview')">
          <img id="addPreview" style="max-width:100%;max-height:120px;border-radius:8px;margin-top:8px;display:none;">
        </div>
        <div class="alert alert-info">
          <i class="fas fa-info-circle"></i>
          ምርት ከጨመሩ በኋላ የክምችት ሰነድ ይከፈታል
        </div>
        <datalist id="categoryOptions">
          <?php foreach ($categories as $c): ?><option value="<?= h($c) ?>"><?php endforeach; ?>
        </datalist>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('addProductModal')">ሰርዝ</button>
      <button class="btn btn-primary" onclick="submitAddProduct()"><i class="fas fa-save"></i> ጨምርና ቀጥል</button>
    </div>
  </div>
</div>

<!-- ═══ Receive Stock Modal ═══ -->
<div class="modal-backdrop" id="receiveStockModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title"><i class="fas fa-truck" style="color:var(--gold)"></i> ክምችት ተቀበል / ዋጋ ቀይር</span>
      <button class="modal-close" onclick="closeModal('receiveStockModal')">✕</button>
    </div>
    <div class="modal-body">
      <div style="background:var(--gold-pale);border-radius:var(--radius-sm);padding:12px 16px;margin-bottom:16px;">
        <div style="font-size:0.82rem;color:var(--text-muted);">ምርት</div>
        <div style="font-size:1rem;font-weight:700;color:var(--brown);" id="rsProductName">—</div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
        <div style="background:rgba(0,0,0,0.04);border-radius:var(--radius-sm);padding:10px 14px;text-align:center;">
          <div style="font-size:0.78rem;color:var(--text-muted);">አሁን ያለ ክምችት</div>
          <div style="font-size:1.15rem;font-weight:800;" id="rsCurrentStock" data-stock="0">0</div>
        </div>
        <div style="background:rgba(46,160,67,0.12);border-radius:var(--radius-sm);padding:10px 14px;text-align:center;">
          <div style="font-size:0.78rem;color:var(--text-muted);">አዲስ ክምችት ይሆናል</div>
          <div style="font-size:1.15rem;font-weight:800;color:var(--green);" id="rsNewStock">0</div>
        </div>
      </div>

      <div id="rsPricePreview" style="display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:16px;">
        <div style="background:rgba(0,0,0,0.04);border-radius:var(--radius-sm);padding:8px 12px;text-align:center;">
          <div style="font-size:0.72rem;color:var(--text-muted);">የቀድሞ ግዢ ዋጋ</div>
          <div style="font-size:0.95rem;font-weight:700;" id="rsOldBuy">—</div>
        </div>
        <div style="background:rgba(0,0,0,0.04);border-radius:var(--radius-sm);padding:8px 12px;text-align:center;">
          <div style="font-size:0.72rem;color:var(--text-muted);">የቀድሞ ሻጭ ዋጋ</div>
          <div style="font-size:0.95rem;font-weight:700;" id="rsOldSell">—</div>
        </div>
        <div style="background:rgba(218,165,32,0.12);border-radius:var(--radius-sm);padding:8px 12px;text-align:center;">
          <div style="font-size:0.72rem;color:var(--text-muted);">የሚጠበቅ ትርፍ/ዩኒት</div>
          <div style="font-size:0.95rem;font-weight:800;color:var(--brown);" id="rsExpProfit">—</div>
        </div>
        <div style="background:rgba(46,125,50,0.12);border-radius:var(--radius-sm);padding:8px 12px;text-align:center;">
          <div style="font-size:0.72rem;color:var(--text-muted);">የትርፍ መቶኛ</div>
          <div style="font-size:0.95rem;font-weight:800;color:var(--green);" id="rsExpMargin">—</div>
        </div>
      </div>

      <form id="receiveStockForm" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="receive_stock">
        <input type="hidden" name="product_id" id="rsProductId">
        <input type="hidden" name="supplier" value="">
        <input type="hidden" name="invoice_number" value="">
        <div class="form-group">
          <label>ብዛት (የሚቀበሉት) *</label>
          <input type="number" name="quantity" id="rsQuantity" class="form-control" min="0.01" step="0.01" placeholder="0" required oninput="updateNewStockPreview()">
        </div>
        <div class="form-group">
          <label>ግዢ ዋጋ (ብር) *</label>
          <div class="input-group"><span class="input-icon"><i class="fas fa-tag"></i></span>
          <input type="number" name="buy_price" id="rsBuyPrice" class="form-control" min="0" step="0.01" placeholder="0.00" required oninput="updatePricePreview()"></div>
        </div>
        <div class="form-group">
          <label>ምክንያት (አማራጭ)</label>
          <textarea name="notes" class="form-control" rows="2" placeholder="ለምሳሌ: ተጨማሪ ክምችት..."></textarea>
        </div>

        <div id="rsMoreToggle" onclick="toggleRsMore()" style="cursor:pointer;font-size:0.82rem;color:var(--gold);font-weight:700;margin:4px 0 12px;user-select:none;">
          <i class="fas fa-chevron-down" id="rsMoreIcon"></i> ተጨማሪ (ሻጭ ዋጋ እና ቀን)
        </div>
        <div id="rsMoreFields" style="display:none;">
          <div class="form-group">
            <label>ሻጭ ዋጋ (ብር) *</label>
            <div class="input-group"><span class="input-icon"><i class="fas fa-dollar-sign"></i></span>
            <input type="number" name="sell_price" id="rsSellPrice" class="form-control" min="0" step="0.01" placeholder="0.00" required oninput="updatePricePreview()"></div>
            <small style="color:var(--text-muted);">ካልቀየሩት ካለፈው ክምችት ተመሳሳይ ዋጋ ይያዛል</small>
          </div>
          <div class="form-group">
            <label>የተቀበለ ቀን</label>
            <input type="date" name="date_received" id="rsDateReceived" class="form-control" value="<?= date('Y-m-d') ?>">
          </div>
        </div>

        <div class="alert alert-info" style="font-size:0.82rem;">
          <i class="fas fa-info-circle"></i>
          <strong>FIFO:</strong> ይህ ክምችት አዲስ batch ይፈጥራል። ቀድሞ ያለ ክምችት ሲጠናቀቅ ይህ ይጀምራል።
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('receiveStockModal')">ሰርዝ</button>
      <button class="btn btn-success" onclick="submitReceiveStock()"><i class="fas fa-check"></i> ተቀበል</button>
    </div>
  </div>
</div>

<!-- ═══ Edit Product Modal ═══ -->
<div class="modal-backdrop" id="editProductModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title"><i class="fas fa-edit" style="color:var(--gold)"></i> ምርት አስተካክል</span>
      <button class="modal-close" onclick="closeModal('editProductModal')">✕</button>
    </div>
    <div class="modal-body">
      <form id="editProductForm" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="edit_product">
        <input type="hidden" name="product_id" id="epId">
        <div class="form-group">
          <label>የምርት ስም *</label>
          <input type="text" name="name" id="epName" class="form-control" required maxlength="150">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="form-group">
            <label>ዓይነት</label>
            <select name="unit" id="epUnit" class="form-control">
              <option>PCS</option><option>KG</option><option>LITER</option><option>BOX</option><option>PACK</option>
            </select>
          </div>
          <div class="form-group">
            <label>ዝቅተኛ ክምችት</label>
            <input type="number" name="min_stock" id="epMinStock" class="form-control" min="0" step="1">
          </div>
        </div>
        <div class="form-group">
          <label>ምድብ (Category)</label>
          <input type="text" name="category" id="epCategory" class="form-control" list="categoryOptions">
        </div>
        <div class="form-group">
          <label>መግለጫ</label>
          <textarea name="description" id="epDesc" class="form-control" rows="2"></textarea>
        </div>
        <div class="form-group">
          <label>አዲስ ፎቶ (አማራጭ)</label>
          <input type="file" name="image" class="form-control" accept="image/*" onchange="previewImg(this,'epPreview')">
          <img id="epPreview" style="max-width:100%;max-height:120px;border-radius:8px;margin-top:8px;display:none;">
        </div>
        <div class="form-group">
          <label>ምክንያት *</label>
          <input type="text" name="reason" class="form-control" placeholder="ለምን ተስተካከለ?" required>
        </div>
        <div class="alert alert-warning" style="font-size:0.82rem;">
          <i class="fas fa-exclamation-triangle"></i>
          <strong>ማስታወሻ:</strong> ዋጋ ለመቀየር ክምችት ተቀበያ ተጠቀሙ። ይህ ፎርም ስም/ዓይነት ብቻ ያስተካክላል።
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('editProductModal')">ሰርዝ</button>
      <button class="btn btn-primary" onclick="submitEditProduct()"><i class="fas fa-save"></i> አስቀምጥ</button>
    </div>
  </div>
</div>

<!-- ═══ Batch History Modal ═══ -->
<div class="modal-backdrop" id="historyModal">
  <div class="modal" style="max-width:620px;">
    <div class="modal-header">
      <span class="modal-title"><i class="fas fa-layer-group" style="color:var(--gold)"></i> Batch ታሪክ — <span id="histProdName"></span></span>
      <button class="modal-close" onclick="closeModal('historyModal')">✕</button>
    </div>
    <div class="modal-body" id="historyContent">
      <div class="flex-center" style="padding:32px;"><i class="fas fa-spinner fa-spin fa-2x" style="color:var(--gold)"></i></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('historyModal')">ዝጋ</button>
    </div>
  </div>
</div>

<!-- Toast -->
<div id="toast" style="position:fixed;bottom:80px;left:50%;transform:translateX(-50%) translateY(20px);background:#333;color:#fff;padding:12px 24px;border-radius:30px;font-size:0.88rem;z-index:9999;opacity:0;transition:all .3s;pointer-events:none;white-space:nowrap;box-shadow:0 8px 24px rgba(0,0,0,0.3);"></div>

<script>
const CSRF_TOKEN = '<?= csrf_token() ?>';

function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

function openAddProduct() {
  document.getElementById('addProductForm').reset();
  document.getElementById('addPreview').style.display='none';
  openModal('addProductModal');
}

function openReceiveStock(pid, name, currentStock, unit, lastSellPrice, lastBuyPrice) {
  document.getElementById('rsProductId').value = pid;
  document.getElementById('rsProductName').textContent = name;
  document.getElementById('receiveStockForm').reset();
  document.getElementById('rsProductId').value = pid;
  document.getElementById('rsDateReceived').value = '<?= date('Y-m-d') ?>';

  const stock = parseFloat(currentStock) || 0;
  const rsCur = document.getElementById('rsCurrentStock');
  rsCur.dataset.stock = stock;
  rsCur.textContent = fmt(stock).replace(/\.00$/, '') + (unit ? ' ' + unit : '');
  document.getElementById('rsNewStock').textContent = rsCur.textContent;

  const sellField = document.getElementById('rsSellPrice');
  const lastSell = parseFloat(lastSellPrice) || 0;
  const lastBuy  = parseFloat(lastBuyPrice) || 0;
  sellField.value = lastSell > 0 ? lastSell.toFixed(2) : '';
  document.getElementById('rsOldBuy').textContent  = lastBuy  > 0 ? fmt(lastBuy)  + ' ብር' : '—';
  document.getElementById('rsOldSell').textContent = lastSell > 0 ? fmt(lastSell) + ' ብር' : '—';
  document.getElementById('rsBuyPrice').dataset.old = lastBuy;
  document.getElementById('rsSellPrice').dataset.old = lastSell;
  updatePricePreview();

  // If we already know a sell price, keep the extra section tucked away; otherwise show it so it isn't missed
  const more = document.getElementById('rsMoreFields');
  const icon = document.getElementById('rsMoreIcon');
  more.style.display = lastSell > 0 ? 'none' : 'block';
  icon.className = lastSell > 0 ? 'fas fa-chevron-down' : 'fas fa-chevron-up';

  openModal('receiveStockModal');
}

function toggleRsMore() {
  const more = document.getElementById('rsMoreFields');
  const icon = document.getElementById('rsMoreIcon');
  const showing = more.style.display !== 'none';
  more.style.display = showing ? 'none' : 'block';
  icon.className = showing ? 'fas fa-chevron-down' : 'fas fa-chevron-up';
}

function updateNewStockPreview() {
  const current = parseFloat(document.getElementById('rsCurrentStock').dataset.stock) || 0;
  const qty = parseFloat(document.getElementById('rsQuantity').value) || 0;
  document.getElementById('rsNewStock').textContent = fmt(current + qty).replace(/\.00$/, '');
}

function updatePricePreview() {
  const buy  = parseFloat(document.getElementById('rsBuyPrice').value) || 0;
  const sell = parseFloat(document.getElementById('rsSellPrice').value) || 0;
  const profit = sell - buy;
  const margin = sell > 0 ? (profit / sell * 100) : 0;
  document.getElementById('rsExpProfit').textContent = (buy || sell) ? fmt(profit) + ' ብር' : '—';
  document.getElementById('rsExpProfit').style.color = profit < 0 ? 'var(--red)' : 'var(--brown)';
  document.getElementById('rsExpMargin').textContent = (buy || sell) ? margin.toFixed(1) + '%' : '—';
  document.getElementById('rsExpMargin').style.color = margin < 0 ? 'var(--red)' : 'var(--green)';
}

function openEditProduct(p) {
  document.getElementById('epId').value = p.id;
  document.getElementById('epName').value = p.name;
  document.getElementById('epUnit').value = p.unit;
  document.getElementById('epMinStock').value = p.min_stock;
  document.getElementById('epDesc').value = p.description || '';
  document.getElementById('epCategory').value = p.category || '';
  document.getElementById('epPreview').style.display='none';
  openModal('editProductModal');
}

async function openHistory(pid, name) {
  document.getElementById('histProdName').textContent = name;
  document.getElementById('historyContent').innerHTML = '<div class="flex-center" style="padding:32px;"><i class="fas fa-spinner fa-spin fa-2x" style="color:var(--gold)"></i></div>';
  openModal('historyModal');

  const res = await fetch('api_batches.php?product_id='+pid+'&csrf_token=<?= csrf_token() ?>');
  const data = await res.json();
  if (!data.batches || data.batches.length === 0) {
    document.getElementById('historyContent').innerHTML = '<p style="text-align:center;padding:24px;color:var(--text-muted);">ገና ክምችት አልገባም</p>';
    return;
  }

  let html = `<div style="background:var(--gold-pale);border-radius:var(--radius-sm);padding:10px 14px;margin-bottom:14px;font-size:0.82rem;">
    <i class="fas fa-info-circle" style="color:var(--gold)"></i>
    ከታች ያለው ቅደም ተከተል ትክክለኛው የሽያጭ ቅደም ተከተል ነው — ከላይ ያለው ክምችት መጀመሪያ ያልቃል (FIFO)።
  </div><div style="display:flex;flex-direction:column;gap:10px;">`;

  let sellOrderNum = 0;
  let nextToSellShown = false;
  data.batches.forEach(b=>{
    const pct = b.quantity_received>0 ? Math.round(b.quantity_remaining/b.quantity_received*100) : 0;
    const isDepleted = b.is_depleted == 1 || parseFloat(b.quantity_remaining) <= 0;
    let orderBadge = '';
    let isNext = false;
    if (!isDepleted) {
      sellOrderNum++;
      isNext = !nextToSellShown;
      if (isNext) nextToSellShown = true;
    }
    html += `
      <div style="border:2px solid ${isNext?'var(--green)':'var(--border)'};border-radius:var(--radius-sm);padding:12px 14px;${isDepleted?'opacity:0.55;':''}">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
          <div style="display:flex;align-items:center;gap:8px;">
            ${!isDepleted ? `<span class="badge badge-blue">${sellOrderNum} ኛ የሚሸጥ</span>` : `<span class="badge badge-red">ተሽጦ አልቋል</span>`}
            ${isNext ? `<span class="badge badge-green"><i class="fas fa-arrow-right"></i> አሁን የሚሸጠው</span>` : ''}
          </div>
          <div style="font-size:0.72rem;color:var(--text-muted);">ገባ: ${escHtml(b.eth_date)}</div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(100px,1fr));gap:8px;margin-bottom:8px;font-size:0.85rem;">
          <div><div style="color:var(--text-muted);font-size:0.72rem;">ገዥ ዋጋ</div><div style="font-weight:700;">${fmt(b.buy_price)} ብር</div></div>
          <div><div style="color:var(--text-muted);font-size:0.72rem;">ሻጭ ዋጋ</div><div style="font-weight:700;">${fmt(b.sell_price)} ብር</div></div>
          <div><div style="color:var(--text-muted);font-size:0.72rem;">አቅራቢ</div><div style="font-weight:700;">${escHtml(b.supplier||'—')}</div></div>
        </div>
        <div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:4px;">ቀሪ: ${b.quantity_remaining} / ${b.quantity_received} (${pct}%)</div>
        <div style="background:rgba(0,0,0,0.08);border-radius:20px;height:8px;overflow:hidden;">
          <div style="background:${pct<=20?'var(--red)':pct<=50?'var(--gold)':'var(--green)'};height:100%;width:${pct}%;"></div>
        </div>
      </div>`;
  });
  html += '</div>';
  document.getElementById('historyContent').innerHTML = html;
}

async function submitAddProduct() {
  const form = document.getElementById('addProductForm');
  const fd = new FormData(form);
  const btn = event.target;
  btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> ቀጥ...';

  const res = await fetch('admin_products.php', {method:'POST',body:fd});
  const data = await res.json();
  btn.disabled=false; btn.innerHTML='<i class="fas fa-save"></i> ጨምርና ቀጥል';

  if (data.success) {
    closeModal('addProductModal');
    openReceiveStock(data.product_id, data.product_name, 0, '', 0, 0);
    showToast('✓ ምርት ተጨምሯል');
  } else { showToast('❌ '+data.message,'error'); }
}

async function submitReceiveStock() {
  const form = document.getElementById('receiveStockForm');
  const fd = new FormData(form);
  const btn = event.target;
  btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> ቀጥ...';

  const res = await fetch('admin_products.php', {method:'POST',body:fd});
  const data = await res.json();
  btn.disabled=false; btn.innerHTML='<i class="fas fa-check"></i> ተቀበል';

  if (data.success) {
    closeModal('receiveStockModal');
    showToast('✓ '+data.message);
    setTimeout(()=>location.reload(),1500);
  } else { showToast('❌ '+data.message,'error'); }
}

async function submitEditProduct() {
  const form = document.getElementById('editProductForm');
  const fd = new FormData(form);
  const btn = event.target;
  btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> ቀጥ...';

  const res = await fetch('admin_products.php', {method:'POST',body:fd});
  const data = await res.json();
  btn.disabled=false; btn.innerHTML='<i class="fas fa-save"></i> አስቀምጥ';

  if (data.success) {
    closeModal('editProductModal');
    showToast('✓ ምርት ተስተካክሏል');
    setTimeout(()=>location.reload(),1200);
  } else { showToast('❌ '+data.message,'error'); }
}

async function toggleProduct(pid, btn) {
  if (!confirm('ሁኔታ ለመቀየር ይፈልጋሉ?')) return;
  const fd=new FormData(); fd.append('action','toggle_product'); fd.append('product_id',pid); fd.append('csrf_token',CSRF_TOKEN);
  const res=await fetch('admin_products.php',{method:'POST',body:fd});
  const data=await res.json();
  if(data.success){showToast('✓ ሁኔታ ተቀይሯል'); setTimeout(()=>location.reload(),800);}
}

function filterProds() {
  const q = (document.getElementById('prodSearch').value || '').toLowerCase().trim();
  const cat = (document.getElementById('catFilter').value || '').toLowerCase();
  const status = document.getElementById('stockFilter').value || '';
  let visibleCount = 0;
  document.querySelectorAll('.prod-card').forEach(c=>{
    const matchesQ = !q ||
      c.dataset.name.includes(q) ||
      c.dataset.suppliers.includes(q) ||
      c.dataset.category.includes(q);
    const matchesCat = !cat || c.dataset.category === cat;
    const matchesStatus = !status || c.dataset.stockstatus === status;
    const show = matchesQ && matchesCat && matchesStatus;
    c.style.display = show ? '' : 'none';
    if (show) visibleCount++;
  });
  document.getElementById('noResults').style.display = visibleCount===0 ? 'block' : 'none';
}

function previewImg(input, previewId) {
  const f=input.files[0]; if(!f) return;
  const r=new FileReader();
  r.onload=e=>{const img=document.getElementById(previewId);img.src=e.target.result;img.style.display='block';};
  r.readAsDataURL(f);
}

function escHtml(s){return (s||'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function fmt(n){return parseFloat(n||0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');}

function showToast(msg,type='success'){
  const t=document.getElementById('toast');
  t.textContent=msg;
  t.style.background=type==='error'?'#C62828':type==='warning'?'#E65100':'#2E7D32';
  t.style.opacity='1';t.style.transform='translateX(-50%) translateY(0)';
  setTimeout(()=>{t.style.opacity='0';t.style.transform='translateX(-50%) translateY(20px)';},2800);
}

// Close modal on backdrop click
document.querySelectorAll('.modal-backdrop').forEach(bd=>{
  bd.addEventListener('click',e=>{if(e.target===bd) bd.classList.remove('open');});
});
</script>
</body>
</html>
