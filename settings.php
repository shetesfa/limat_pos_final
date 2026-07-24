<?php
require_once 'config.php';
requireAdmin();

$branch_id   = (int)($_SESSION['branch_id'] ?? 1);
$biz_name    = getSetting($conn, 'business_name', 'አጸደ ትጉሃን');
$active_page = 'settings';
$eth_now     = getCurrentEthDatetime();
$msg = ''; $msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { $msg='CSRF error'; $msg_type='danger'; }
    else {
        $action = $_POST['action'];

        if ($action === 'save_business') {
            $fields = ['business_name','business_address','business_phone','tin_number','receipt_footer','low_stock_threshold','currency_symbol'];
            foreach ($fields as $f) {
                $val = trim($_POST[$f] ?? '');
                $stmt = $conn->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?");
                $stmt->bind_param('sss', $f, $val, $val);
                $stmt->execute(); $stmt->close();
            }
            // Logo upload
            if (!empty($_FILES['logo']['name'])) {
                $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg','jpeg','png','gif','webp','svg'])) {
                    if (!is_dir('images')) mkdir('images', 0755, true);
                    if (move_uploaded_file($_FILES['logo']['tmp_name'], 'images/icon.'.$ext)) {
                        $logo_path = 'images/icon.'.$ext;
                        $stmt=$conn->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('logo',?) ON DUPLICATE KEY UPDATE setting_value=?");
                        $stmt->bind_param('ss',$logo_path,$logo_path); $stmt->execute(); $stmt->close();
                    }
                }
            }
            auditLog($conn, 'EDIT_SETTINGS', 'settings', null, null, null, 'ቅንብሮች ተስተካክሏቸዋል');
            $msg = '✓ ቅንብሮች ተቀምጠዋል'; $msg_type = 'success';
        }

        if ($action === 'save_theme') {
            $theme = in_array($_POST['theme']??'',['light','dark','auto']) ? $_POST['theme'] : 'light';
            $stmt=$conn->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('theme',?) ON DUPLICATE KEY UPDATE setting_value=?");
            $stmt->bind_param('ss',$theme,$theme); $stmt->execute(); $stmt->close();
            $msg = '✓ ቴም ተቀምጧል'; $msg_type = 'success';
        }

        if ($action === 'add_expense') {
            $amount  = (float)($_POST['amount'] ?? 0);
            $cat     = trim($_POST['category'] ?? '');
            $desc    = trim($_POST['description'] ?? '');
            $edate   = trim($_POST['expense_date'] ?? date('Y-m-d'));
            $uid     = $_SESSION['user_id'];
            if ($amount > 0 && $cat) {
                $stmt=$conn->prepare("INSERT INTO expenses (amount,category,description,created_by,branch_id,expense_date) VALUES (?,?,?,?,?,?)");
                $stmt->bind_param('dssiss',$amount,$cat,$desc,$uid,$branch_id,$edate);
                $stmt->execute(); $stmt->close();
                auditLog($conn,'ADD_EXPENSE','expenses',$conn->insert_id,null,json_encode(['amount'=>$amount,'category'=>$cat]),"ወጪ: $cat — $amount ብር");
                $msg = '✓ ወጪ ተጨምሯል'; $msg_type = 'success';
            } else { $msg = 'ዋጋ እና ምክንያት ያስፈልጋል'; $msg_type = 'danger'; }
        }
    }
}

// Load all settings
$all_settings = [];
$sq = $conn->query("SELECT setting_key,setting_value FROM settings");
while($r=$sq->fetch_assoc()) $all_settings[$r['setting_key']] = $r['setting_value'];

function st($k, $d='') { global $all_settings; return $all_settings[$k] ?? $d; }

// Load recent expenses
$expenses = [];
$eq = $conn->query("SELECT e.*,u.full_name FROM expenses e LEFT JOIN users u ON u.id=e.created_by WHERE e.branch_id=$branch_id ORDER BY e.expense_date DESC,e.id DESC LIMIT 20");
while($r=$eq->fetch_assoc()){ $r['eth_date']=ethDateDisplay($r['expense_date']); $expenses[]=$r; }
?><!DOCTYPE html>
<html lang="am">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ቅንብሮች — <?= h($biz_name) ?></title>
<link rel="icon" type="image/png" href="images/icon.png">
<?php include 'layout_start.php'; ?>
<style>
.settings-tabs{display:flex;gap:6px;margin-bottom:24px;flex-wrap:wrap;}
.stab{
  padding:9px 18px;border-radius:20px;border:2px solid var(--border);
  background:var(--card);cursor:pointer;font-size:0.82rem;font-weight:600;
  color:var(--text-muted);transition:all var(--transition);font-family:inherit;
  display:inline-flex;align-items:center;gap:6px;
}
.stab.active{background:linear-gradient(135deg,var(--brown),var(--gold));color:#fff;border-color:transparent;}
.stab:hover:not(.active){border-color:var(--gold);color:var(--brown);}
.settings-panel{display:none;} .settings-panel.active{display:block;}
.theme-options{display:flex;gap:12px;flex-wrap:wrap;}
.theme-card{
  flex:1;min-width:100px;padding:18px;border:3px solid var(--border);border-radius:var(--radius);
  cursor:pointer;text-align:center;transition:all var(--transition);
}
.theme-card.selected{border-color:var(--gold);}
.theme-card i{font-size:2rem;margin-bottom:8px;display:block;}
.theme-preview{
  height:50px;border-radius:var(--radius-sm);margin-bottom:8px;
  display:flex;align-items:center;justify-content:center;font-size:0.75rem;font-weight:600;
}
.expense-item{
  display:flex;align-items:center;justify-content:space-between;
  padding:10px 0;border-bottom:1px solid var(--border-light);
}
.expense-item:last-child{border-bottom:none;}
</style>
</head>
<body>
<?php include 'nav.php'; ?>
<div class="main-content">
  <div class="page-header">
    <div>
      <h1><i class="fas fa-cog"></i> ቅንብሮች</h1>
      <div class="page-title-sub"><?= h($eth_now['eth_date']) ?></div>
    </div>
  </div>

  <?php if ($msg): ?>
  <div class="alert alert-<?= $msg_type ?> fade-in"><i class="fas fa-<?= $msg_type==='success'?'check-circle':'exclamation-circle' ?>"></i> <?= h($msg) ?></div>
  <?php endif; ?>

  <!-- Settings Tabs -->
  <div class="settings-tabs">
    <button class="stab active" onclick="showTab('business',this)"><i class="fas fa-building"></i> ንግድ መረጃ</button>
    <button class="stab" onclick="showTab('theme',this)"><i class="fas fa-palette"></i> ቴምና ቀን</button>
    <button class="stab" onclick="showTab('expenses',this)"><i class="fas fa-receipt"></i> ወጪ</button>
  </div>

  <!-- ── Business Info ── -->
  <div class="settings-panel active" id="panel_business">
    <div class="card">
      <div class="card-header"><span class="card-title"><i class="fas fa-building"></i> የንግድ መረጃ</span></div>
      <form method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_business">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;" class="two-col-settings">
          <div class="form-group">
            <label>የንግድ ስም</label>
            <input type="text" name="business_name" class="form-control" value="<?= h(st('business_name')) ?>" required>
          </div>
          <div class="form-group">
            <label>ስልክ</label>
            <input type="tel" name="business_phone" class="form-control" value="<?= h(st('business_phone')) ?>">
          </div>
          <div class="form-group">
            <label>አድራሻ</label>
            <input type="text" name="business_address" class="form-control" value="<?= h(st('business_address')) ?>">
          </div>
          <div class="form-group">
            <label>TIN ቁጥር</label>
            <input type="text" name="tin_number" class="form-control" value="<?= h(st('tin_number')) ?>">
          </div>
          <div class="form-group">
            <label>ዝቅ ክምችት ማስጠንቀቂያ</label>
            <input type="number" name="low_stock_threshold" class="form-control" value="<?= h(st('low_stock_threshold','5')) ?>" min="0">
          </div>
          <div class="form-group">
            <label>ምንዛሬ ምልክት</label>
            <input type="text" name="currency_symbol" class="form-control" value="<?= h(st('currency_symbol','ብር')) ?>">
          </div>
        </div>
        <div class="form-group">
          <label>ደረሰኝ ግርጌ ጽሑፍ</label>
          <textarea name="receipt_footer" class="form-control" rows="2"><?= h(st('receipt_footer','እግዚአብሔር ይባርክዎ! ✝')) ?></textarea>
        </div>
        <div class="form-group">
          <label>ሎጎ ምስል</label>
          <?php $logo = st('logo'); if ($logo && file_exists($logo)): ?>
            <div style="margin-bottom:10px;">
              <img src="<?= h($logo) ?>" style="max-height:60px;border-radius:8px;border:2px solid var(--border);">
            </div>
          <?php endif; ?>
          <input type="file" name="logo" class="form-control" accept="image/*" onchange="previewImg(this,'logoPreview')">
          <img id="logoPreview" style="max-height:60px;border-radius:8px;margin-top:8px;display:none;">
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> አስቀምጥ</button>
      </form>
    </div>
  </div>

  <!-- ── Theme & Date ── -->
  <div class="settings-panel" id="panel_theme">
    <div class="card" style="margin-bottom:20px;">
      <div class="card-header"><span class="card-title"><i class="fas fa-palette"></i> ቴምና ቅርጸት</span></div>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_theme">
        <div class="form-group">
          <label>ቴም</label>
          <div class="theme-options">
            <?php foreach(['light'=>['fa-sun','ብርሃን','#FFF8E7','#8B4513'],'dark'=>['fa-moon','ጨለማ','#1E1E1E','#DAA520'],'auto'=>['fa-circle-half-stroke','Auto','linear-gradient(135deg,#FFF8E7 50%,#1E1E1E 50%)','#555']] as $tk=>$tv): ?>
            <label class="theme-card <?= st('theme','light')===$tk?'selected':'' ?>" onclick="selectTheme('<?= $tk ?>')">
              <input type="radio" name="theme" value="<?= $tk ?>" <?= st('theme','light')===$tk?'checked':'' ?> style="display:none;">
              <div class="theme-preview" style="background:<?= $tv[2] ?>;color:<?= $tv[3] ?>;border:1px solid var(--border);">
                <i class="fas <?= $tv[0] ?>"></i>
              </div>
              <div style="font-size:0.82rem;font-weight:600;"><?= $tv[1] ?></div>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <button type="submit" class="btn btn-primary mt-2"><i class="fas fa-save"></i> አስቀምጥ</button>
      </form>
    </div>

    <div class="card">
      <div class="card-header"><span class="card-title"><i class="fas fa-calendar-alt"></i> የቀን ቅርጸት</span></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
        <div style="background:var(--gold-pale);border-radius:var(--radius-sm);padding:16px;text-align:center;">
          <div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:4px;">Ethiopian Calendar</div>
          <div style="font-size:1.1rem;font-weight:800;color:var(--brown);"><?= h($eth_now['eth_date']) ?></div>
          <div style="font-size:0.8rem;color:var(--text-muted);margin-top:4px;">ነባሪ ቅርጸት</div>
        </div>
        <div style="background:var(--bg);border-radius:var(--radius-sm);padding:16px;text-align:center;">
          <div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:4px;">Gregorian Time (12hr)</div>
          <div style="font-size:1.1rem;font-weight:800;color:var(--brown);" id="liveClockSettings"><?= h($eth_now['greg_time']) ?></div>
          <div style="font-size:0.8rem;color:var(--text-muted);margin-top:4px;">AM/PM ቅርጸት</div>
        </div>
      </div>
    </div>
  </div>

  <!-- ── Expenses ── -->
  <div class="settings-panel" id="panel_expenses">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
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
        <div class="card-header"><span class="card-title"><i class="fas fa-list"></i> የቅርብ ወጪዎች</span></div>
        <?php if (empty($expenses)): ?>
          <div style="text-align:center;padding:32px;color:var(--text-muted);">ወጪ አልተጨመረም</div>
        <?php else: ?>
          <?php foreach($expenses as $e): ?>
          <div class="expense-item">
            <div>
              <div style="font-size:0.88rem;font-weight:600;"><?= h($e['category']) ?></div>
              <div style="font-size:0.75rem;color:var(--text-muted);">
                <?= h($e['eth_date']) ?> · <?= h($e['full_name']??'') ?>
                <?php if($e['description']): ?> — <?= h(substr($e['description'],0,30)) ?><?php endif; ?>
              </div>
            </div>
            <div style="font-size:0.95rem;font-weight:800;color:var(--red);"><?= money($e['amount']) ?> ብር</div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div><!-- /main-content -->

<script>
function showTab(tab, btn) {
  document.querySelectorAll('.settings-panel').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.stab').forEach(b=>b.classList.remove('active'));
  document.getElementById('panel_'+tab).classList.add('active');
  btn.classList.add('active');
}
function selectTheme(t) {
  document.querySelectorAll('.theme-card').forEach(c=>c.classList.remove('selected'));
  event.currentTarget.classList.add('selected');
}
function previewImg(input,id){
  const f=input.files[0];if(!f)return;
  const r=new FileReader();r.onload=e=>{const img=document.getElementById(id);img.src=e.target.result;img.style.display='block';};r.readAsDataURL(f);
}
setInterval(()=>{
  const d=new Date(),h=d.getHours()%12||12,m=String(d.getMinutes()).padStart(2,'0'),
        s=String(d.getSeconds()).padStart(2,'0'),ap=d.getHours()<12?'AM':'PM';
  const el=document.getElementById('liveClockSettings');
  if(el)el.textContent=`${String(h).padStart(2,'0')}:${m}:${s} ${ap}`;
},1000);
</script>
</body>
</html>
