<?php
require_once 'config.php';
requireLogin();

mysqli_set_charset($conn, "utf8mb4");
$seller_id   = $_SESSION['user_id'];
$seller_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Seller';
$user_role   = $_SESSION['role'] ?? 'seller';
$branch_id   = $_SESSION['branch_id'] ?? 1;

// ── AJAX: Save Sale (FIFO atomic transaction) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_sale') {
    ini_set('display_errors', 0);
    error_reporting(0);
    header('Content-Type: application/json');
    $resp = ['success'=>false,'message'=>''];

    try {
        // CSRF
        if (!verify_csrf($_POST['csrf_token'] ?? ''))
            throw new Exception('CSRF validation failed');

        // Idempotency — prevent double submit
        $idem_key = trim($_POST['idempotency_key'] ?? '');
        if ($idem_key) {
            $chk = $conn->prepare("SELECT id FROM transactions WHERE idempotency_key=?");
            $chk->bind_param('s', $idem_key);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $chk->close();
                throw new Exception('duplicate:This sale was already saved.');
            }
            $chk->close();
        }

        $cart = json_decode($_POST['cart'] ?? '[]', true);
        if (empty($cart)) throw new Exception('Cart is empty');

        $client_discount = (float)($_POST['discount'] ?? 0);
        $payment  = trim($_POST['payment'] ?? 'cash');
        $bank_name= trim($_POST['bank_name'] ?? '');
        $cash_p   = (float)($_POST['cash_paid'] ?? 0);
        $bank_p   = (float)($_POST['bank_paid'] ?? 0);
        $mobile_p = (float)($_POST['mobile_paid'] ?? 0);
        $receipt  = generateReceiptNumber($conn);

        $conn->begin_transaction();

        // Insert transaction with placeholder amounts — corrected below once the real
        // FIFO-priced total is known, so the recorded sale total can never drift from
        // (or be tampered to differ from) the actual value of goods sold.
        $stmt = $conn->prepare(
            "INSERT INTO transactions (receipt_number,seller_id,branch_id,subtotal,discount,total_amount,
             payment_method,bank_name,cash_paid,bank_paid,mobile_paid,change_given,idempotency_key)
             VALUES (?,?,?,0,0,0,?,?,?,?,?,0,?)"
        );
        $stmt->bind_param('siissddds', $receipt, $seller_id, $branch_id, $payment, $bank_name, $cash_p, $bank_p, $mobile_p, $idem_key);
        if (!$stmt->execute()) throw new Exception('Transaction insert failed: '.$stmt->error);
        $txn_id = $conn->insert_id;
        $stmt->close();

        $real_subtotal = 0.0;

        // Process each cart item via FIFO
        foreach ($cart as $item) {
            $product_id = (int)($item['product_id'] ?? 0);
            $qty_needed = (float)$item['qty'];
            if ($product_id <= 0 || $qty_needed <= 0) continue;

            // Verify product exists
            $ps = $conn->prepare("SELECT id FROM products WHERE id=? AND is_active=1");
            $ps->bind_param('i', $product_id);
            $ps->execute();
            if ($ps->get_result()->num_rows === 0) {
                $ps->close();
                throw new Exception("Product ID $product_id not found");
            }
            $ps->close();

            // FIFO: deplete batches oldest first
            $qty_left = $qty_needed;
            while ($qty_left > 0) {
                // Lock batch row
                $bs = $conn->prepare(
                    "SELECT * FROM stock_batches
                     WHERE product_id=? AND branch_id=? AND is_depleted=0 AND quantity_remaining>0
                     ORDER BY created_at ASC LIMIT 1 FOR UPDATE"
                );
                $bs->bind_param('ii', $product_id, $branch_id);
                $bs->execute();
                $batch = $bs->get_result()->fetch_assoc();
                $bs->close();

                if (!$batch) {
                    throw new Exception("Insufficient stock for product ID $product_id");
                }

                $take = min($qty_left, $batch['quantity_remaining']);
                $new_rem = $batch['quantity_remaining'] - $take;
                $depleted = $new_rem <= 0 ? 1 : 0;

                $bu = $conn->prepare(
                    "UPDATE stock_batches SET quantity_remaining=?, is_depleted=? WHERE id=?"
                );
                $bu->bind_param('dii', $new_rem, $depleted, $batch['id']);
                if (!$bu->execute()) throw new Exception('Batch update failed');
                $bu->close();

                // Insert transaction item for this batch slice
                $profit_per = $batch['sell_price'] - $batch['buy_price'];
                $line_sub   = round($take * $batch['sell_price'], 2);
                $line_prof  = round($take * $profit_per, 2);

                $is = $conn->prepare(
                    "INSERT INTO transaction_items
                     (transaction_id,product_id,batch_id,quantity,buy_price,unit_price,subtotal,profit)
                     VALUES (?,?,?,?,?,?,?,?)"
                );
                $is->bind_param('iiiddddd',
                    $txn_id, $product_id, $batch['id'], $take,
                    $batch['buy_price'], $batch['sell_price'], $line_sub, $line_prof
                );
                if (!$is->execute()) throw new Exception('Item insert failed');
                $is->close();

                $real_subtotal += $line_sub;
                $qty_left -= $take;
            }
        }

        // Correct the transaction to the server-verified total (never trust client-submitted amounts)
        $real_discount = min(max($client_discount, 0), $real_subtotal);
        $real_total    = round($real_subtotal - $real_discount, 2);
        $real_change   = max(0, round($cash_p - $real_total, 2));

        $upd = $conn->prepare("UPDATE transactions SET subtotal=?, discount=?, total_amount=?, change_given=? WHERE id=?");
        $upd->bind_param('ddddi', $real_subtotal, $real_discount, $real_total, $real_change, $txn_id);
        if (!$upd->execute()) throw new Exception('Transaction total correction failed: '.$upd->error);
        $upd->close();

        $conn->commit();

        auditLog($conn, 'SALE', 'transactions', $txn_id, null,
            json_encode(['total'=>$real_total,'payment'=>$payment,'items'=>count($cart)]),
            "ሽያጭ: $receipt — ".money($real_total).' ብር');

        $resp = ['success'=>true,'message'=>'ሽያጭ ተጠናቋል!',
                 'receipt_number'=>$receipt,'transaction_id'=>$txn_id,
                 'change'=>$real_change];
    } catch (Exception $e) {
        $conn->rollback();
        $msg = $e->getMessage();
        if (str_starts_with($msg, 'duplicate:')) {
            $resp = ['success'=>true,'duplicate'=>true,'message'=>substr($msg,10)];
        } else {
            $resp['message'] = $msg;
        }
    }
    echo json_encode($resp);
    exit();
}

// ── AJAX: Get products ─────────────────────────────────────────
if (isset($_GET['api']) && $_GET['api'] === 'products') {
    header('Content-Type: application/json');
    $branch = (int)$branch_id;
    $result = $conn->query(
        "SELECT p.id, p.name, p.unit, p.image, p.min_stock,
                COALESCE(SUM(sb.quantity_remaining),0) AS stock,
                (SELECT sb2.sell_price FROM stock_batches sb2
                 WHERE sb2.product_id=p.id AND sb2.branch_id=$branch AND sb2.is_depleted=0
                 ORDER BY sb2.created_at ASC LIMIT 1) AS sell_price,
                (SELECT sb2.buy_price FROM stock_batches sb2
                 WHERE sb2.product_id=p.id AND sb2.branch_id=$branch AND sb2.is_depleted=0
                 ORDER BY sb2.created_at ASC LIMIT 1) AS buy_price,
                (SELECT sb2.id FROM stock_batches sb2
                 WHERE sb2.product_id=p.id AND sb2.branch_id=$branch AND sb2.is_depleted=0
                 ORDER BY sb2.created_at ASC LIMIT 1) AS batch_id
         FROM products p
         LEFT JOIN stock_batches sb ON sb.product_id=p.id AND sb.branch_id=$branch AND sb.is_depleted=0
         WHERE p.is_active=1
         GROUP BY p.id ORDER BY p.name"
    );
    $products = [];
    while ($row = $result->fetch_assoc()) $products[] = $row;
    echo json_encode($products);
    exit();
}

// ── AJAX: Get receipt ──────────────────────────────────────────
if (isset($_GET['api']) && $_GET['api'] === 'receipt' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $txn_id = (int)$_GET['id'];
    $t = $conn->prepare(
        "SELECT t.*, u.full_name as seller_fullname FROM transactions t
         LEFT JOIN users u ON u.id=t.seller_id WHERE t.id=?"
    );
    $t->bind_param('i', $txn_id); $t->execute();
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
    $txn['biz_name'] = getSetting($conn, 'business_name', 'አጸደ ትጉሃን ሰንበት ትምህርት ቤት');
    $txn['biz_address'] = getSetting($conn, 'business_address', '');
    $txn['biz_phone'] = getSetting($conn, 'business_phone', '');
    echo json_encode($txn);
    exit();
}

$eth_now = getCurrentEthDatetime();
$biz_name = getSetting($conn, 'business_name', 'አጸደ ትጉሃን');
$active_page = 'pos';
?><!DOCTYPE html>
<html lang="am">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title>ሽያጭ (POS) — <?= h($biz_name) ?></title>
<link rel="icon" type="image/png" href="images/icon.png">
<?php include 'layout_start.php'; ?>
<style>
/* ── POS-specific layout ── */
.pos-shell{display:flex;height:100vh;overflow:hidden;}

/* Products panel */
.pos-products{
  flex:1;overflow-y:auto;padding:16px;
  padding-bottom:80px; /* bottom nav clearance on mobile */
}

/* Cart panel */
.pos-cart{
  width:360px;flex-shrink:0;background:var(--card);border-left:1px solid var(--border);
  display:flex;flex-direction:column;box-shadow:-4px 0 20px rgba(0,0,0,0.06);
}
.cart-header{
  padding:16px 18px;background:linear-gradient(135deg,var(--brown-dark),var(--brown));
  color:#fff;display:flex;align-items:center;justify-content:space-between;
  flex-shrink:0;
}
.cart-header h2{font-size:1rem;font-weight:700;display:flex;align-items:center;gap:8px;}
.cart-count-badge{
  background:var(--gold);color:var(--brown-dark);
  width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;
  font-size:0.75rem;font-weight:800;
}
.cart-items{flex:1;overflow-y:auto;padding:12px;}
.cart-empty{
  display:flex;flex-direction:column;align-items:center;justify-content:center;
  height:100%;color:var(--text-muted);gap:12px;padding:32px;text-align:center;
}
.cart-empty i{font-size:3rem;color:var(--border);}
.cart-item{
  background:var(--bg);border-radius:var(--radius-sm);
  padding:12px;margin-bottom:10px;display:flex;flex-direction:column;gap:8px;
  border:1px solid var(--border);animation:fadeIn .2s ease;
}
.cart-item-top{display:flex;align-items:center;gap:10px;}
.cart-item-name{flex:1;font-size:0.88rem;font-weight:600;color:var(--text);}
.cart-item-remove{background:none;border:none;color:var(--red);cursor:pointer;font-size:1rem;padding:4px;}
.cart-item-bottom{display:flex;align-items:center;justify-content:space-between;}
.qty-controls{display:flex;align-items:center;gap:6px;}
.qty-btn{
  width:30px;height:30px;border-radius:50%;border:1px solid var(--border);
  background:var(--card);cursor:pointer;font-size:1rem;font-weight:700;
  color:var(--brown);display:flex;align-items:center;justify-content:center;
  transition:all var(--transition);
}
.qty-btn:hover{background:var(--gold);color:#fff;border-color:var(--gold);}
.qty-input{
  width:48px;text-align:center;border:1px solid var(--border);
  border-radius:6px;padding:4px;font-size:0.9rem;font-family:inherit;
}
.cart-item-price{font-size:0.88rem;font-weight:700;color:var(--brown);}

/* Cart summary */
.cart-summary{
  padding:14px 18px;border-top:1px solid var(--border);background:var(--card);flex-shrink:0;
}
.summary-row{
  display:flex;justify-content:space-between;align-items:center;
  padding:5px 0;font-size:0.88rem;color:var(--text-muted);
}
.summary-row.total{
  font-size:1.1rem;font-weight:800;color:var(--brown);
  border-top:2px solid var(--border);padding-top:10px;margin-top:6px;
}
.discount-row{display:flex;align-items:center;gap:8px;margin:8px 0;}
.discount-input{
  width:80px;text-align:right;border:1px solid var(--border);
  border-radius:6px;padding:5px 8px;font-size:0.9rem;font-family:inherit;
}

/* Payment section */
.cart-payment{padding:14px 18px;border-top:1px solid var(--border);flex-shrink:0;}
.payment-methods{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-bottom:12px;}
.pay-btn{
  padding:10px 6px;border:2px solid var(--border);border-radius:var(--radius-sm);
  background:var(--card);cursor:pointer;font-size:0.78rem;font-weight:600;
  color:var(--text-muted);text-align:center;transition:all var(--transition);font-family:inherit;
}
.pay-btn.active{border-color:var(--gold);background:var(--gold-pale);color:var(--brown);}
.pay-btn i{display:block;font-size:1.2rem;margin-bottom:4px;}
.mixed-pay-fields{display:none;gap:8px;margin-bottom:12px;}
.mixed-pay-fields.show{display:grid;grid-template-columns:repeat(2,1fr);}
.mixed-field label{font-size:0.72rem;font-weight:600;margin-bottom:4px;display:block;}
.mixed-field input{width:100%;padding:8px;border:1px solid var(--border);border-radius:6px;font-size:0.9rem;font-family:inherit;}
.cash-change{
  background:var(--green-light);border-radius:var(--radius-sm);
  padding:10px;margin-bottom:12px;display:none;
}
.cash-change.show{display:block;}
.charge-btn{
  width:100%;padding:15px;background:linear-gradient(135deg,var(--brown-dark),var(--gold));
  color:#fff;border:none;border-radius:var(--radius-sm);
  font-size:1.05rem;font-weight:800;cursor:pointer;transition:all var(--transition);
  font-family:inherit;letter-spacing:.5px;box-shadow:0 4px 15px rgba(139,69,19,0.4);
}
.charge-btn:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 8px 25px rgba(139,69,19,0.5);}
.charge-btn:disabled{background:#ccc;cursor:not-allowed;box-shadow:none;}

/* Mobile cart toggle */
.mobile-cart-fab{
  display:none;position:fixed;bottom:80px;right:16px;z-index:300;
  width:58px;height:58px;border-radius:50%;
  background:linear-gradient(135deg,var(--brown),var(--gold));
  color:#fff;border:none;cursor:pointer;font-size:1.3rem;
  box-shadow:0 6px 20px rgba(139,69,19,0.5);align-items:center;justify-content:center;
  transition:all var(--transition);
}
.mobile-cart-fab .fab-badge{
  position:absolute;top:-4px;right:-4px;
  background:var(--red);color:#fff;width:20px;height:20px;
  border-radius:50%;font-size:0.7rem;font-weight:800;
  display:flex;align-items:center;justify-content:center;
}
.mobile-cart-panel{
  display:none;position:fixed;inset:0;z-index:500;background:var(--card);
  flex-direction:column;
}
.mobile-cart-panel.open{display:flex;}
.mobile-cart-close{
  background:var(--brown);color:#fff;border:none;padding:14px 18px;
  display:flex;align-items:center;gap:10px;cursor:pointer;font-size:1rem;
  font-weight:700;font-family:inherit;
}

/* Search bar */
.pos-search-wrap{position:sticky;top:0;z-index:50;background:var(--bg);padding:0 0 12px;}
.pos-search{
  width:100%;padding:13px 16px 13px 44px;border:2px solid var(--border);
  border-radius:var(--radius-sm);font-size:0.95rem;color:var(--text);
  background:var(--card);transition:border-color var(--transition);font-family:inherit;
  box-shadow:var(--shadow);
}
.pos-search:focus{outline:none;border-color:var(--gold);}
.search-icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--brown);font-size:1rem;}
.pos-search-container{position:relative;}

/* Product grid adjustments for POS */
.pos-products .product-grid{grid-template-columns:repeat(auto-fill,minmax(150px,1fr));}

/* Receipt modal */
.receipt-box{
  font-family:'Courier New',monospace;background:#fff;
  border-radius:var(--radius-sm);border:2px dashed var(--border);
  padding:20px;font-size:0.85rem;line-height:1.8;
}
.receipt-box .r-header{text-align:center;border-bottom:1px dashed #ccc;padding-bottom:12px;margin-bottom:12px;}
.receipt-box .r-footer{text-align:center;border-top:1px dashed #ccc;padding-top:12px;margin-top:12px;color:var(--brown);}
.receipt-box .r-row{display:flex;justify-content:space-between;}
.receipt-box .r-total{font-weight:800;font-size:1rem;color:var(--brown);}
.receipt-box .r-item{border-bottom:1px dotted #eee;padding:4px 0;}

/* Mobile: product grid tighter */
@media(max-width:900px){
  .pos-shell{flex-direction:column;height:auto;overflow:visible;}
  .pos-cart{display:none;}
  .mobile-cart-fab{display:flex;}
  .pos-products .product-grid{grid-template-columns:repeat(2,1fr);gap:10px;}
}
@media(max-width:380px){
  .pos-products .product-grid{grid-template-columns:repeat(2,1fr);}
}
</style>
</head>
<body>
<?php include 'nav.php'; ?>

<div class="main-content" style="padding:0;">
<div class="pos-shell">

  <!-- ═══ LEFT: PRODUCTS ═══ -->
  <div class="pos-products">
    <!-- Mobile top bar with date -->
    <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 0 8px;flex-wrap:wrap;gap:8px;">
      <div>
        <div style="font-size:1rem;font-weight:700;color:var(--brown);">
          <i class="fas fa-cash-register" style="color:var(--gold)"></i> ሽያጭ — <?= h($seller_name) ?>
        </div>
        <div style="font-size:0.75rem;color:var(--text-muted);">
          <?= h($eth_now['eth_date']) ?> &bull; <?= h($eth_now['greg_time']) ?>
        </div>
      </div>
      <div style="display:flex;gap:8px;">
        <button class="btn btn-outline btn-sm" id="lastReceiptBtn" onclick="showReceipt(lastTxnId)" disabled style="opacity:0.5;">
          <i class="fas fa-receipt"></i> የመጨረሻ ደረሰኝ
        </button>
        <button class="btn btn-outline btn-sm" onclick="loadProducts()">
          <i class="fas fa-sync-alt"></i> አድስ
        </button>
      </div>
    </div>

    <!-- Search -->
    <div class="pos-search-wrap">
      <div class="pos-search-container" style="position:relative;">
        <i class="fas fa-search search-icon"></i>
        <input type="text" class="pos-search" id="searchInput" placeholder="ምርት ፈልግ..." oninput="filterProducts(this.value)">
      </div>
    </div>

    <!-- Category filter -->
    <div style="display:flex;gap:8px;overflow-x:auto;padding-bottom:10px;margin-bottom:14px;" id="stockFilterWrap">
      <button class="btn btn-sm btn-primary" onclick="filterStock('all')" data-filter="all">ሁሉም</button>
      <button class="btn btn-sm btn-outline" onclick="filterStock('instock')" data-filter="instock">
        <i class="fas fa-check-circle" style="color:var(--green)"></i> አለ
      </button>
      <button class="btn btn-sm btn-outline" onclick="filterStock('low')" data-filter="low">
        <i class="fas fa-exclamation-triangle" style="color:var(--orange)"></i> ዝቅተኛ
      </button>
    </div>

    <!-- Products Grid -->
    <div class="product-grid" id="productGrid">
      <div class="flex-center" style="grid-column:1/-1;padding:40px;">
        <i class="fas fa-spinner fa-spin" style="font-size:2rem;color:var(--gold)"></i>
      </div>
    </div>
  </div>

  <!-- ═══ RIGHT: CART (Desktop) ═══ -->
  <div class="pos-cart" id="desktopCart">
    <div class="cart-header">
      <h2><i class="fas fa-shopping-cart"></i> ዕቃ ዝርዝር
        <span class="cart-count-badge" id="cartCountBadge">0</span>
      </h2>
      <button onclick="clearCart()" class="btn btn-sm" style="background:rgba(255,255,255,0.15);color:#fff;border:none;" title="ሁሉ ደምስስ">
        <i class="fas fa-trash"></i>
      </button>
    </div>

    <div class="cart-items" id="cartItems">
      <div class="cart-empty" id="cartEmpty">
        <i class="fas fa-shopping-bag"></i>
        <p>ዕቃ አልተጨመረም</p>
        <small>ከላይ ምርት ይምረጡ</small>
      </div>
    </div>

    <!-- Summary -->
    <div class="cart-summary">
      <div class="summary-row">
        <span>ድምር</span>
        <span id="subtotalDisplay">0.00 ብር</span>
      </div>
      <div class="discount-row">
        <label style="font-size:0.82rem;font-weight:600;white-space:nowrap;">ቅናሽ (ብር):</label>
        <input type="number" class="discount-input" id="discountInput" min="0" value="0" step="0.50" oninput="recalcTotal()">
      </div>
      <div class="summary-row total">
        <span>ጠቅላላ</span>
        <span id="totalDisplay" style="color:var(--gold);">0.00 ብር</span>
      </div>
    </div>

    <!-- Payment -->
    <div class="cart-payment">
      <div style="font-size:0.82rem;font-weight:700;margin-bottom:8px;color:var(--text);">የክፍያ ዓይነት</div>
      <div class="payment-methods">
        <button class="pay-btn active" onclick="selectPayment('cash')" data-pay="cash">
          <i class="fas fa-money-bill-wave"></i>ጥሬ ገንዘብ
        </button>
        <button class="pay-btn" onclick="selectPayment('bank')" data-pay="bank">
          <i class="fas fa-university"></i>ባንክ
        </button>
        <button class="pay-btn" onclick="selectPayment('mobile')" data-pay="mobile">
          <i class="fas fa-mobile-alt"></i>ቴሌብር
        </button>
        <button class="pay-btn" onclick="selectPayment('mixed')" data-pay="mixed">
          <i class="fas fa-layer-group"></i>ድብልቅ
        </button>
      </div>

      <!-- Bank selection (shown for Bank payment, or Mixed payment with a bank amount) -->
      <div id="bankNameWrap" style="margin-bottom:10px;display:none;">
        <div style="font-size:0.78rem;font-weight:600;margin-bottom:5px;">ባንክ ይምረጡ</div>
        <select class="form-control" id="bankNameSelect">
          <option value="CBE">CBE</option>
          <option value="Ahadu Bank">Ahadu Bank</option>
          <option value="Bank of Abyssinia">Bank of Abyssinia</option>
          <option value="Dashen Bank">Dashen Bank</option>
          <option value="Awash Bank">Awash Bank</option>
        </select>
      </div>

      <!-- Cash change calculator -->
      <div id="cashChangeWrap" style="margin-bottom:10px;display:none;">
        <div style="font-size:0.78rem;font-weight:600;margin-bottom:5px;">የተቀበለ ገንዘብ (ብር)</div>
        <input type="number" class="form-control" id="cashReceivedInput" placeholder="0.00" oninput="calcChange()" step="0.50">
        <div class="cash-change" id="changeDisplay">
          <div style="display:flex;justify-content:space-between;font-weight:700;color:var(--green);">
            <span>ምንዛሬ:</span><span id="changeAmount">0.00 ብር</span>
          </div>
        </div>
      </div>

      <!-- Mixed payment -->
      <div class="mixed-pay-fields" id="mixedFields">
        <div class="mixed-field">
          <label><i class="fas fa-money-bill"></i> ጥሬ (ብር)</label>
          <input type="number" id="mixCash" min="0" value="0" step="0.50" oninput="updateMixedRemaining()">
        </div>
        <div class="mixed-field">
          <label><i class="fas fa-university"></i> ባንክ (ብር)</label>
          <input type="number" id="mixBank" min="0" value="0" step="0.50" oninput="updateMixedRemaining()">
        </div>
        <div class="mixed-field">
          <label><i class="fas fa-mobile-alt"></i> ቴሌብር (ብር)</label>
          <input type="number" id="mixMobile" min="0" value="0" step="0.50" oninput="updateMixedRemaining()">
        </div>
      </div>
      <div id="mixedRemaining" style="display:none;font-size:0.82rem;font-weight:700;text-align:center;margin:-4px 0 10px;"></div>

      <button class="charge-btn" id="chargeBtn" onclick="processSale()" disabled>
        <i class="fas fa-check-circle"></i> ሽያጭ ፍጸም
      </button>
    </div>
  </div>
</div>

<!-- ═══ Mobile FAB + Cart Panel ═══ -->
<button class="mobile-cart-fab" id="cartFab" onclick="openMobileCart()">
  <i class="fas fa-shopping-cart"></i>
  <span class="fab-badge" id="fabBadge" style="display:none">0</span>
</button>

<div class="mobile-cart-panel" id="mobileCartPanel">
  <button class="mobile-cart-close" onclick="closeMobileCart()">
    <i class="fas fa-arrow-left"></i> ሽያጭ ቀጥል
    <span style="margin-left:auto;font-size:0.8rem;color:rgba(255,255,255,0.7);" id="mobileSummaryTop">0.00 ብር</span>
  </button>
  <!-- Cart content cloned here -->
  <div style="flex:1;overflow-y:auto;" id="mobileCartContent"></div>
</div>
</div><!-- /main-content -->

<!-- ═══ Receipt Modal ═══ -->
<div class="modal-backdrop" id="receiptModal">
  <div class="modal" style="max-width:440px;">
    <div class="modal-header">
      <span class="modal-title"><i class="fas fa-receipt" style="color:var(--gold)"></i> ደረሰኝ</span>
      <button class="modal-close" onclick="closeReceipt()">✕</button>
    </div>
    <div class="modal-body" id="receiptContent"></div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="window.print()"><i class="fas fa-print"></i> አትም</button>
      <button class="btn btn-primary" onclick="newSale()"><i class="fas fa-plus"></i> ሽያጭ ጀምር</button>
    </div>
  </div>
</div>

<!-- Toast -->
<div id="toast" style="
  position:fixed;bottom:80px;left:50%;transform:translateX(-50%) translateY(20px);
  background:#333;color:#fff;padding:12px 24px;border-radius:30px;
  font-size:0.88rem;z-index:9999;opacity:0;transition:all .3s;pointer-events:none;
  white-space:nowrap;box-shadow:0 8px 24px rgba(0,0,0,0.3);
"></div>

<script>
const CSRF_TOKEN = '<?= csrf_token() ?>';
let products = [];
let cart = {};  // { product_id: { name, qty, price, unit, batch_id } }
let currentPayment = 'cash';
let currentFilter = 'all';
let searchTerm = '';
let isSaving = false;
let lastTxnId = null;

// ── Load products ──────────────────────────────────────────────
async function loadProducts() {
  const grid = document.getElementById('productGrid');
  grid.innerHTML = '<div class="flex-center" style="grid-column:1/-1;padding:40px;"><i class="fas fa-spinner fa-spin" style="font-size:2rem;color:var(--gold)"></i></div>';
  try {
    const res = await fetch('seller_pos.php?api=products');
    products = await res.json();
    renderProducts();
  } catch(e) {
    grid.innerHTML = '<div class="flex-center" style="grid-column:1/-1;padding:40px;color:var(--red)"><i class="fas fa-exclamation-circle"></i> Error loading products</div>';
  }
}

function renderProducts() {
  const grid = document.getElementById('productGrid');
  let filtered = products.filter(p => {
    const nameMatch = p.name.toLowerCase().includes(searchTerm.toLowerCase());
    if (currentFilter === 'instock') return nameMatch && parseFloat(p.stock) > parseFloat(p.min_stock);
    if (currentFilter === 'low') return nameMatch && parseFloat(p.stock) <= parseFloat(p.min_stock) && parseFloat(p.stock) > 0;
    return nameMatch;
  });

  if (filtered.length === 0) {
    grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--text-muted);"><i class="fas fa-search" style="font-size:2rem;margin-bottom:8px;display:block;color:var(--border)"></i>ምርት አልተገኘም</div>';
    return;
  }

  grid.innerHTML = filtered.map(p => {
    const stock = parseFloat(p.stock);
    const price = parseFloat(p.sell_price) || 0;
    const minStock = parseFloat(p.min_stock);
    const isOut = stock <= 0;
    const isLow = stock > 0 && stock <= minStock;
    const inCart = cart[p.id] ? cart[p.id].qty : 0;
    const imgHtml = p.image
      ? `<img src="${escHtml(p.image)}" style="width:100%;height:100%;object-fit:cover;" onerror="this.parentNode.innerHTML='<i class=\\'fas fa-book\\' style=\\'font-size:2rem;color:var(--gold)\\'></i>'">`
      : `<i class="fas fa-book" style="font-size:2rem;color:var(--gold)"></i>`;

    return `<div class="product-card ${isOut?'out-of-stock':''}" onclick="${!isOut?'addToCart('+p.id+')':''}">
      <div class="product-img">${imgHtml}</div>
      ${inCart>0?`<div style="position:absolute;top:8px;right:8px;background:var(--gold);color:#fff;border-radius:50%;width:24px;height:24px;display:flex;align-items:center;justify-content:center;font-size:0.75rem;font-weight:800;">${inCart}</div>`:''}
      <div class="product-info">
        <div class="product-name">${escHtml(p.name)}</div>
        <div class="product-price">${formatMoney(price)} ብር</div>
        <div class="product-stock ${isLow?'low':''} ${isOut?'low':''}">
          ${isOut?'<i class="fas fa-times-circle"></i> አልቋል':isLow?'<i class="fas fa-exclamation-triangle"></i> '+stock+' '+escHtml(p.unit):'<i class="fas fa-check"></i> '+formatQty(stock)+' '+escHtml(p.unit)}
        </div>
      </div>
      <button class="add-to-cart-btn" ${isOut?'disabled':''} onclick="event.stopPropagation();${!isOut?'addToCart('+p.id+')':''}">
        ${isOut?'አልቋል':'<i class="fas fa-plus"></i> ጨምር'}
      </button>
    </div>`;
  }).join('');
}

function filterProducts(q) {
  searchTerm = q;
  renderProducts();
}

function filterStock(f) {
  currentFilter = f;
  document.querySelectorAll('#stockFilterWrap [data-filter]').forEach(b=>{
    b.className = b.dataset.filter===f ? 'btn btn-sm btn-primary' : 'btn btn-sm btn-outline';
  });
  renderProducts();
}

// ── Cart operations ────────────────────────────────────────────
function addToCart(productId) {
  const p = products.find(x=>parseInt(x.id)===parseInt(productId));
  if (!p || parseFloat(p.stock)<=0) return;

  if (!cart[productId]) {
    cart[productId] = {
      product_id: parseInt(p.id),
      name: p.name,
      unit: p.unit,
      price: parseFloat(p.sell_price)||0,
      batch_id: parseInt(p.batch_id)||0,
      max_stock: parseFloat(p.stock),
      qty: 0
    };
  }

  if (cart[productId].qty >= cart[productId].max_stock) {
    showToast('⚠️ ያለው ክምችት ' + cart[productId].max_stock + ' ' + p.unit + ' ነው', 'warning');
    return;
  }

  cart[productId].qty = parseFloat((cart[productId].qty + 1).toFixed(2));
  renderCart();
  renderProducts(); // update badge on card
  showToast('✓ ' + p.name + ' ተጨምሯል', 'success');
}

function changeQty(productId, delta) {
  if (!cart[productId]) return;
  const newQty = parseFloat((cart[productId].qty + delta).toFixed(2));
  if (newQty <= 0) {
    removeFromCart(productId);
    return;
  }
  if (newQty > cart[productId].max_stock) {
    showToast('⚠️ ከ ' + cart[productId].max_stock + ' ' + cart[productId].unit + ' አይበልጥም', 'warning');
    return;
  }
  cart[productId].qty = newQty;
  renderCart();
  renderProducts();
}

function setQty(productId, val) {
  const q = parseFloat(val)||0;
  if (!cart[productId]) return;
  if (q <= 0) { removeFromCart(productId); return; }
  if (q > cart[productId].max_stock) {
    showToast('⚠️ ክምችት ያልፋል', 'warning');
    cart[productId].qty = cart[productId].max_stock;
  } else {
    cart[productId].qty = q;
  }
  renderCart();
  renderProducts();
}

function removeFromCart(productId) {
  delete cart[productId];
  renderCart();
  renderProducts();
}

function clearCart() {
  if (Object.keys(cart).length===0) return;
  if (!confirm('ሁሉንም ዕቃ ማስወጣት ይፈልጋሉ?')) return;
  cart = {};
  renderCart();
  renderProducts();
}

function renderCart() {
  const items = Object.values(cart);
  const badge = document.getElementById('cartCountBadge');
  const fab = document.getElementById('fabBadge');
  const empty = document.getElementById('cartEmpty');
  const cartDiv = document.getElementById('cartItems');
  const chargeBtn = document.getElementById('chargeBtn');
  const totalCount = items.reduce((s,i)=>s+i.qty,0);

  if(badge) badge.textContent = items.length;
  if(fab) { fab.textContent=items.length; fab.style.display=items.length?'flex':'none'; }

  if (items.length === 0) {
    cartDiv.innerHTML = '<div class="cart-empty" id="cartEmpty"><i class="fas fa-shopping-bag"></i><p>ዕቃ አልተጨመረም</p><small>ከላይ ምርት ይምረጡ</small></div>';
    recalcTotal();
    if(chargeBtn) chargeBtn.disabled = true;
    return;
  }

  cartDiv.innerHTML = items.map(item => `
    <div class="cart-item fade-in">
      <div class="cart-item-top">
        <div class="cart-item-name">${escHtml(item.name)}</div>
        <button class="cart-item-remove" onclick="removeFromCart(${item.product_id})" title="አስወጣ"><i class="fas fa-times"></i></button>
      </div>
      <div class="cart-item-bottom">
        <div class="qty-controls">
          <button class="qty-btn" onclick="changeQty(${item.product_id},-1)">−</button>
          <input class="qty-input" type="number" value="${item.qty}" min="0.01" step="0.01"
                 onchange="setQty(${item.product_id},this.value)" style="width:48px;">
          <button class="qty-btn" onclick="changeQty(${item.product_id},1)">+</button>
          <span style="font-size:0.75rem;color:var(--text-muted);margin-left:2px;">${escHtml(item.unit)}</span>
        </div>
        <div class="cart-item-price">${formatMoney(item.qty * item.price)} ብር</div>
      </div>
    </div>
  `).join('');

  recalcTotal();
  if(chargeBtn) chargeBtn.disabled = false;
  syncMobileCart();
}

function recalcTotal() {
  const items = Object.values(cart);
  const subtotal = items.reduce((s,i)=>s+(i.qty*i.price),0);
  const discount = parseFloat(document.getElementById('discountInput')?.value)||0;
  const total = Math.max(0, subtotal - discount);

  const sd = document.getElementById('subtotalDisplay');
  const td = document.getElementById('totalDisplay');
  const ms = document.getElementById('mobileSummaryTop');
  if(sd) sd.textContent = formatMoney(subtotal) + ' ብር';
  if(td) td.textContent = formatMoney(total) + ' ብር';
  if(ms) ms.textContent = formatMoney(total) + ' ብር';

  calcChange();
}

// ── Payment ────────────────────────────────────────────────────
function selectPayment(method) {
  currentPayment = method;
  document.querySelectorAll('.pay-btn').forEach(b=>b.classList.toggle('active', b.dataset.pay===method));
  const cashWrap = document.getElementById('cashChangeWrap');
  const mixed = document.getElementById('mixedFields');
  const bankWrap = document.getElementById('bankNameWrap');
  const remaining = document.getElementById('mixedRemaining');
  if(cashWrap) cashWrap.style.display = method==='cash' ? 'block' : 'none';
  if(mixed) mixed.classList.toggle('show', method==='mixed');
  if(remaining) remaining.style.display = method==='mixed' ? 'block' : 'none';
  if(bankWrap) bankWrap.style.display = method==='bank' ? 'block' : 'none';
  if(document.getElementById('changeDisplay')) document.getElementById('changeDisplay').classList.remove('show');
  if(method==='mixed') updateMixedRemaining();
}

function updateMixedRemaining() {
  const total = getTotal();
  const c = parseFloat(document.getElementById('mixCash')?.value)||0;
  const b = parseFloat(document.getElementById('mixBank')?.value)||0;
  const m = parseFloat(document.getElementById('mixMobile')?.value)||0;
  const remaining = total - (c + b + m);
  const el = document.getElementById('mixedRemaining');
  if (el) {
    if (Math.abs(remaining) < 0.01) { el.textContent = '✓ ተሟልቷል'; el.style.color = 'var(--green)'; }
    else if (remaining > 0) { el.textContent = 'ቀሪ: ' + formatMoney(remaining) + ' ብር'; el.style.color = 'var(--red)'; }
    else { el.textContent = 'ትርፍ: ' + formatMoney(Math.abs(remaining)) + ' ብር'; el.style.color = 'var(--orange)'; }
  }
  // Bank dropdown only relevant if a bank amount was entered in the mix
  const bankWrap = document.getElementById('bankNameWrap');
  if (bankWrap && currentPayment === 'mixed') bankWrap.style.display = b > 0 ? 'block' : 'none';
}

function calcChange() {
  if (currentPayment !== 'cash') return;
  const total = getTotal();
  const received = parseFloat(document.getElementById('cashReceivedInput')?.value)||0;
  const change = Math.max(0, received - total);
  const disp = document.getElementById('changeDisplay');
  const amt = document.getElementById('changeAmount');
  if(amt) amt.textContent = formatMoney(change) + ' ብር';
  if(disp) disp.classList.toggle('show', received > 0);
}

function getTotal() {
  const items = Object.values(cart);
  const subtotal = items.reduce((s,i)=>s+(i.qty*i.price),0);
  const discount = parseFloat(document.getElementById('discountInput')?.value)||0;
  return Math.max(0, subtotal - discount);
}
function getSubtotal() {
  return Object.values(cart).reduce((s,i)=>s+(i.qty*i.price),0);
}

// ── Process sale ───────────────────────────────────────────────
async function processSale() {
  if (isSaving) { showToast('⏳ ቀጥ ቀጥ...'); return; }
  const items = Object.values(cart);
  if (items.length === 0) { showToast('⚠️ ዕቃ ምረጡ!', 'warning'); return; }

  const total = getTotal();
  if (total <= 0) { showToast('⚠️ ጠቅላላ 0 ነው!', 'warning'); return; }

  if (currentPayment === 'cash') {
    const received = parseFloat(document.getElementById('cashReceivedInput')?.value)||0;
    if (received > 0 && received < total) {
      showToast('⚠️ የተቀበለው ገንዘብ ያንሳል!', 'warning'); return;
    }
  }

  // Work out how much was paid by each method, based on the selected payment type
  let cash_paid = 0, bank_paid = 0, mobile_paid = 0, bank_name = '';
  if (currentPayment === 'cash') {
    cash_paid = parseFloat(document.getElementById('cashReceivedInput')?.value) || total;
  } else if (currentPayment === 'bank') {
    bank_paid = total;
    bank_name = document.getElementById('bankNameSelect')?.value || '';
  } else if (currentPayment === 'mobile') {
    mobile_paid = total;
  } else if (currentPayment === 'mixed') {
    cash_paid   = parseFloat(document.getElementById('mixCash')?.value)   || 0;
    bank_paid   = parseFloat(document.getElementById('mixBank')?.value)   || 0;
    mobile_paid = parseFloat(document.getElementById('mixMobile')?.value) || 0;
    if (bank_paid > 0) bank_name = document.getElementById('bankNameSelect')?.value || '';
    if (Math.abs((cash_paid + bank_paid + mobile_paid) - total) > 0.01) {
      showToast('⚠️ ድምር ክፍያ ከጠቅላላው ጋር አይመሳሰልም!', 'warning'); return;
    }
  }

  isSaving = true;
  const btn = document.getElementById('chargeBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ቀጥ...';

  const idem = 'sale_' + Date.now() + '_' + Math.random().toString(36).substr(2,9);
  const fd = new FormData();
  fd.append('action','save_sale');
  fd.append('csrf_token', CSRF_TOKEN);
  fd.append('cart', JSON.stringify(items.map(i=>({product_id:i.product_id,qty:i.qty,price:i.price}))));
  fd.append('subtotal', getSubtotal().toFixed(2));
  fd.append('discount', (parseFloat(document.getElementById('discountInput')?.value)||0).toFixed(2));
  fd.append('total', total.toFixed(2));
  fd.append('payment', currentPayment);
  fd.append('bank_name', bank_name);
  fd.append('cash_paid', cash_paid.toFixed(2));
  fd.append('bank_paid', bank_paid.toFixed(2));
  fd.append('mobile_paid', mobile_paid.toFixed(2));
  fd.append('idempotency_key', idem);

  try {
    const res = await fetch('seller_pos.php', {method:'POST', body:fd});
    const data = await res.json();

    if (data.success) {
      // Auto-finish: no need to view the receipt to continue — the sale is
      // done and the cart resets immediately, ready for the next customer.
      // The receipt is still one tap away via "የመጨረሻ ደረሰኝ" if printing is needed.
      lastTxnId = data.transaction_id;
      const lrBtn = document.getElementById('lastReceiptBtn');
      if (lrBtn) { lrBtn.disabled = false; lrBtn.style.opacity = '1'; }
      showToast('✅ ሽያጭ ተጠናቀቀ! ደረሰኝ: ' + data.receipt_number, 'success');
      newSale();
    } else {
      showToast('❌ ' + (data.message || 'Error'), 'error');
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-check-circle"></i> ሽያጭ ፍጸም';
    }
  } catch(e) {
    showToast('❌ Network error', 'error');
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-check-circle"></i> ሽያጭ ፍጸም';
  }
  isSaving = false;
}

// ── Receipt ────────────────────────────────────────────────────
async function showReceipt(txnId) {
  const res = await fetch('seller_pos.php?api=receipt&id='+txnId);
  const t = await res.json();
  if (t.error) { newSale(); return; }

  const payLabel = {cash:'ጥሬ ገንዘብ',bank:'ባንክ',mobile:'ቴሌብር',mixed:'ድብልቅ'};
  const itemsHtml = (t.items||[]).map(i=>`
    <div class="r-item">
      <div>${escHtml(i.product_name)} (${escHtml(i.unit)})</div>
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

function newSale() {
  closeReceipt();
  cart = {};
  document.getElementById('discountInput').value = '0';
  document.getElementById('cashReceivedInput').value = '';
  if(document.getElementById('changeDisplay')) document.getElementById('changeDisplay').classList.remove('show');
  selectPayment('cash');
  renderCart();
  loadProducts();
  const btn = document.getElementById('chargeBtn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-check-circle"></i> ሽያጭ ፍጸም';
  closeMobileCart();
}

// ── Mobile Cart ────────────────────────────────────────────────
function openMobileCart() {
  syncMobileCart();
  document.getElementById('mobileCartPanel').classList.add('open');
  document.body.style.overflow='hidden';
}
function closeMobileCart() {
  document.getElementById('mobileCartPanel').classList.remove('open');
  document.body.style.overflow='';
}
function syncMobileCart() {
  const mc = document.getElementById('mobileCartContent');
  if (!mc) return;
  const cartItemsHtml = document.getElementById('cartItems').innerHTML;
  mc.innerHTML = `
    <div style="padding:12px;">${cartItemsHtml}</div>
    <div style="padding:14px 16px;border-top:1px solid var(--border);background:var(--card);">
      <div style="display:flex;justify-content:space-between;margin-bottom:8px;">
        <span>ድምር</span><span id="mSubtotal">${document.getElementById('subtotalDisplay')?.textContent||'0.00 ብር'}</span>
      </div>
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
        <label style="font-size:0.82rem;font-weight:600;white-space:nowrap;">ቅናሽ:</label>
        <input type="number" class="form-control" id="mDiscountInput" min="0" value="${document.getElementById('discountInput')?.value||0}" step="0.50"
               oninput="document.getElementById('discountInput').value=this.value;recalcTotal();" style="flex:1;">
      </div>
      <div style="display:flex;justify-content:space-between;font-size:1.1rem;font-weight:800;color:var(--gold);border-top:2px solid var(--border);padding-top:10px;margin-bottom:12px;">
        <span style="color:var(--text);">ጠቅላላ</span><span>${document.getElementById('totalDisplay')?.textContent||'0.00 ብር'}</span>
      </div>
      <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-bottom:12px;">
        ${['cash','bank','mobile','mixed'].map(m=>`<button class="pay-btn ${currentPayment===m?'active':''}" onclick="selectPayment('${m}')" data-pay="${m}"><i class="fas ${m==='cash'?'fa-money-bill-wave':m==='bank'?'fa-university':m==='mobile'?'fa-mobile-alt':'fa-layer-group'}"></i>${m==='cash'?'ጥሬ ገንዘብ':m==='bank'?'ባንክ':m==='mobile'?'ቴሌብር':'ድብልቅ'}</button>`).join('')}
      </div>
      ${currentPayment==='cash'?`<div style="margin-bottom:10px;"><label style="font-size:0.78rem;font-weight:600;display:block;margin-bottom:4px;">የተቀበለ ገንዘብ:</label><input type="number" class="form-control" id="mCashInput" placeholder="0.00" step="0.50" value="${document.getElementById('cashReceivedInput')?.value||''}" oninput="document.getElementById('cashReceivedInput').value=this.value;calcChange();"></div>`:''}
      ${currentPayment==='bank'?`<div style="margin-bottom:10px;"><label style="font-size:0.78rem;font-weight:600;display:block;margin-bottom:4px;">ባንክ ይምረጡ:</label><select class="form-control" id="mBankNameSelect" onchange="document.getElementById('bankNameSelect').value=this.value;">
        <option value="CBE">CBE</option><option value="Ahadu Bank">Ahadu Bank</option><option value="Bank of Abyssinia">Bank of Abyssinia</option><option value="Dashen Bank">Dashen Bank</option><option value="Awash Bank">Awash Bank</option>
      </select></div>`:''}
      ${currentPayment==='mixed'?`
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-bottom:8px;">
        <div class="mixed-field"><label>ጥሬ</label><input type="number" class="form-control" min="0" value="${document.getElementById('mixCash')?.value||0}" step="0.50" oninput="mSyncMixed('mixCash',this.value)"></div>
        <div class="mixed-field"><label>ባንክ</label><input type="number" class="form-control" min="0" value="${document.getElementById('mixBank')?.value||0}" step="0.50" oninput="mSyncMixed('mixBank',this.value)"></div>
        <div class="mixed-field"><label>ቴሌብር</label><input type="number" class="form-control" min="0" value="${document.getElementById('mixMobile')?.value||0}" step="0.50" oninput="mSyncMixed('mixMobile',this.value)"></div>
      </div>
      <div id="mMixedRemaining" style="font-size:0.82rem;font-weight:700;text-align:center;margin-bottom:10px;"></div>
      <div id="mMixedBankWrap" style="margin-bottom:10px;display:${(parseFloat(document.getElementById('mixBank')?.value)||0)>0?'block':'none'};">
        <label style="font-size:0.78rem;font-weight:600;display:block;margin-bottom:4px;">ባንክ ይምረጡ:</label>
        <select class="form-control" id="mBankNameSelect" onchange="document.getElementById('bankNameSelect').value=this.value;">
          <option value="CBE">CBE</option><option value="Ahadu Bank">Ahadu Bank</option><option value="Bank of Abyssinia">Bank of Abyssinia</option><option value="Dashen Bank">Dashen Bank</option><option value="Awash Bank">Awash Bank</option>
        </select>
      </div>`:''}
      <button class="charge-btn" onclick="processSale();" ${Object.keys(cart).length===0?'disabled':''}>
        <i class="fas fa-check-circle"></i> ሽያጭ ፍጸም
      </button>
    </div>`;
  if (currentPayment==='mixed') updateMixedRemaining();
}

// Mirror a mobile mixed-payment input into the (hidden on mobile) desktop field, then refresh both remaining displays
function mSyncMixed(id, val) {
  const el = document.getElementById(id);
  if (el) el.value = val;
  updateMixedRemaining();
  if (id === 'mixBank') {
    const wrap = document.getElementById('mMixedBankWrap');
    if (wrap) wrap.style.display = (parseFloat(val)||0) > 0 ? 'block' : 'none';
  }
  const src = document.getElementById('mixedRemaining');
  const dst = document.getElementById('mMixedRemaining');
  if (src && dst) { dst.textContent = src.textContent; dst.style.color = src.style.color; }
}

// ── Helpers ────────────────────────────────────────────────────
function formatMoney(n) { return parseFloat(n||0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,','); }
function formatQty(n) { const f=parseFloat(n||0); return Number.isInteger(f)?f:f.toFixed(2); }
function escHtml(s) { return (s||'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function showToast(msg, type='success') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.style.background = type==='error'?'#C62828':type==='warning'?'#E65100':'#2E7D32';
  t.style.opacity='1';t.style.transform='translateX(-50%) translateY(0)';
  setTimeout(()=>{t.style.opacity='0';t.style.transform='translateX(-50%) translateY(20px)';},2500);
}

// Init
loadProducts();
selectPayment('cash');
</script>
</body>
</html>
