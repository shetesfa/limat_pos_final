<?php
require_once 'config.php';
requireAdmin();

$branch_id   = (int)($_SESSION['branch_id'] ?? 1);
$biz_name    = getSetting($conn, 'business_name', 'አጸደ ትጉሃን');
$active_page = 'users';
$eth_now     = getCurrentEthDatetime();
$current_uid = (int)$_SESSION['user_id'];

// ── AJAX handlers ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { echo json_encode(['success'=>false,'message'=>'CSRF error']); exit(); }
    $action = $_POST['action'];

    if ($action === 'add_user' || $action === 'edit_user') {
        $uid   = (int)($_POST['user_id'] ?? 0);
        $name  = trim($_POST['full_name'] ?? '');
        $uname = trim($_POST['username'] ?? '');
        $role  = in_array($_POST['role']??'', ['admin','seller']) ? $_POST['role'] : 'seller';
        $phone = trim($_POST['phone'] ?? '');
        $pwd   = trim($_POST['password'] ?? '');

        if (!$name || !$uname) { echo json_encode(['success'=>false,'message'=>'ስም ያስፈልጋል']); exit(); }

        // Check username unique
        $ck = $conn->prepare("SELECT id FROM users WHERE username=? AND id!=?");
        $ck->bind_param('si',$uname,$uid); $ck->execute();
        if ($ck->get_result()->num_rows>0) { echo json_encode(['success'=>false,'message'=>'Username already exists']); exit(); }
        $ck->close();

        $photo = '';
        if (!empty($_FILES['photo']['name'])) {
            $ext=strtolower(pathinfo($_FILES['photo']['name'],PATHINFO_EXTENSION));
            if (in_array($ext,['jpg','jpeg','png','gif','webp'])) {
                if (!is_dir('uploads/users')) mkdir('uploads/users',0755,true);
                $fn='user_'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
                if (move_uploaded_file($_FILES['photo']['tmp_name'],'uploads/users/'.$fn)) $photo='uploads/users/'.$fn;
            }
        }

        if ($action === 'add_user') {
            if (!$pwd) { echo json_encode(['success'=>false,'message'=>'Password required']); exit(); }
            $hash = password_hash($pwd, PASSWORD_ARGON2ID);
            $stmt=$conn->prepare("INSERT INTO users (full_name,username,password,role,branch_id,phone,photo,is_active) VALUES (?,?,?,'seller',?,?,?,1)");
            $stmt->bind_param('sssiss',$name,$uname,$hash,$branch_id,$phone,$photo);
            // override role
            if ($stmt->execute()) {
                $nid=$conn->insert_id;
                $conn->query("UPDATE users SET role='$role' WHERE id=$nid");
                if($photo) $conn->query("UPDATE users SET photo='$photo' WHERE id=$nid");
                auditLog($conn,'ADD_USER','users',$nid,null,json_encode(['name'=>$name,'role'=>$role]),"ተጠቃሚ ተጨምሯል: $name");
                echo json_encode(['success'=>true]); $stmt->close(); exit();
            }
            echo json_encode(['success'=>false,'message'=>$stmt->error]); $stmt->close(); exit();
        } else {
            $old=$conn->query("SELECT * FROM users WHERE id=$uid")->fetch_assoc();
            $updates="full_name='".addslashes($name)."',username='".addslashes($uname)."',role='$role',phone='".addslashes($phone)."'";
            if($photo) $updates.=",photo='".addslashes($photo)."'";
            if($pwd) { $hash=password_hash($pwd,PASSWORD_ARGON2ID); $updates.=",password='".addslashes($hash)."'"; }
            $conn->query("UPDATE users SET $updates WHERE id=$uid");
            $old_v=json_encode(['name'=>$old['full_name'],'role'=>$old['role']]);
            $new_v=json_encode(['name'=>$name,'role'=>$role]);
            auditLog($conn,'EDIT_USER','users',$uid,$old_v,$new_v,"ተጠቃሚ ተስተካክሏል: $name");
            echo json_encode(['success'=>true]); exit();
        }
    }

    if ($action === 'toggle_user') {
        $uid=(int)($_POST['user_id']??0);
        if ($uid===$current_uid) { echo json_encode(['success'=>false,'message'=>'ራስዎን ማቀናጀት አይቻልም']); exit(); }
        $r=$conn->query("SELECT is_active,full_name FROM users WHERE id=$uid")->fetch_assoc();
        $new=$r['is_active']?0:1;
        $conn->query("UPDATE users SET is_active=$new WHERE id=$uid");
        auditLog($conn,'TOGGLE_USER','users',$uid,$r['is_active'],$new,($new?'አስጀምሯቸዋል':'አቁሟቸዋል').': '.$r['full_name']);
        echo json_encode(['success'=>true,'is_active'=>$new]); exit();
    }

    if ($action === 'reset_password') {
        $uid=(int)($_POST['user_id']??0);
        $newpwd=trim($_POST['new_password']??'');
        if (!$newpwd || strlen($newpwd)<6) { echo json_encode(['success'=>false,'message'=>'ቢያንስ 6 ፊደል']); exit(); }
        $hash=password_hash($newpwd,PASSWORD_ARGON2ID);
        $conn->query("UPDATE users SET password='".addslashes($hash)."' WHERE id=$uid");
        auditLog($conn,'RESET_PASSWORD','users',$uid,null,null,'ፓስዎርድ ተቀይሯል');
        echo json_encode(['success'=>true]); exit();
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']); exit();
}

// ── Load users ─────────────────────────────────────────────────
$users = [];
$uq=$conn->query(
    "SELECT u.*,
            (SELECT COUNT(*) FROM transactions t WHERE t.seller_id=u.id) as sale_count,
            (SELECT COALESCE(SUM(t2.total_amount),0) FROM transactions t2 WHERE t2.seller_id=u.id AND DATE(t2.transaction_date)>=DATE_FORMAT(NOW(),'%Y-%m-01')) as month_sales,
            (SELECT created_at FROM audit_log al WHERE al.user_id=u.id AND al.action='LOGIN' ORDER BY created_at DESC LIMIT 1) as last_login_ts
     FROM users u ORDER BY u.is_active DESC, u.full_name ASC"
);
while($r=$uq->fetch_assoc()){
    $r['eth_last_login']=$r['last_login_ts']?ethDateDisplay($r['last_login_ts']):'—';
    $users[]=$r;
}
?><!DOCTYPE html>
<html lang="am">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ተጠቃሚዎች — <?= h($biz_name) ?></title>
<link rel="icon" type="image/png" href="images/icon.png">
<?php include 'layout_start.php'; ?>
<style>
.users-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;}
.user-card{
  background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;
  transition:all var(--transition);border:2px solid transparent;
}
.user-card:hover{border-color:var(--gold);transform:translateY(-3px);box-shadow:var(--shadow-md);}
.user-card.inactive{opacity:.65;}
.user-card-top{
  background:linear-gradient(135deg,var(--brown-dark),var(--brown));
  padding:20px;display:flex;align-items:center;gap:14px;position:relative;
}
.user-avatar-lg{
  width:60px;height:60px;border-radius:50%;border:3px solid var(--gold);
  overflow:hidden;flex-shrink:0;display:flex;align-items:center;justify-content:center;
  background:rgba(218,165,32,0.2);font-size:1.8rem;color:var(--gold);
}
.user-avatar-lg img{width:100%;height:100%;object-fit:cover;}
.user-card-name{color:#fff;font-size:1rem;font-weight:700;}
.user-card-username{color:rgba(255,255,255,.7);font-size:0.78rem;}
.user-card-role{
  position:absolute;top:10px;right:10px;
}
.user-card-body{padding:14px;}
.user-stat-row{display:flex;gap:10px;margin-bottom:14px;}
.user-stat{flex:1;background:var(--bg);border-radius:var(--radius-sm);padding:8px;text-align:center;}
.user-stat-val{font-size:0.95rem;font-weight:800;color:var(--brown);}
.user-stat-lbl{font-size:0.68rem;color:var(--text-muted);}
.user-card-actions{display:flex;gap:6px;flex-wrap:wrap;}
@media(max-width:600px){.users-grid{grid-template-columns:1fr;}}
</style>
</head>
<body>
<?php include 'nav.php'; ?>
<div class="main-content">
  <div class="page-header">
    <div>
      <h1><i class="fas fa-users"></i> ተጠቃሚዎች</h1>
      <div class="page-title-sub"><?= count($users) ?> ተጠቃሚዎች · <?= h($eth_now['eth_date']) ?></div>
    </div>
    <button class="btn btn-primary" onclick="openAddUser()"><i class="fas fa-user-plus"></i> ጨምር</button>
  </div>

  <div class="users-grid">
    <?php foreach($users as $u): ?>
    <div class="user-card <?= $u['is_active']?'':'inactive' ?>">
      <div class="user-card-top">
        <div class="user-avatar-lg">
          <?php if ($u['photo'] && file_exists($u['photo'])): ?>
            <img src="<?= h($u['photo']) ?>" alt="<?= h($u['full_name']) ?>">
          <?php else: ?>
            <i class="fas fa-user-circle"></i>
          <?php endif; ?>
        </div>
        <div>
          <div class="user-card-name"><?= h($u['full_name']) ?></div>
          <div class="user-card-username">@<?= h($u['username']) ?></div>
          <?php if ($u['phone']): ?>
          <div style="font-size:0.75rem;color:rgba(255,255,255,.6);margin-top:2px;"><i class="fas fa-phone"></i> <?= h($u['phone']) ?></div>
          <?php endif; ?>
        </div>
        <div class="user-card-role">
          <span class="badge <?= $u['role']==='admin'?'badge-gold':'badge-blue' ?>">
            <?= $u['role']==='admin'?'አስተዳዳሪ':'ሻጭ' ?>
          </span>
        </div>
      </div>
      <div class="user-card-body">
        <div class="user-stat-row">
          <div class="user-stat">
            <div class="user-stat-val"><?= h($u['sale_count']) ?></div>
            <div class="user-stat-lbl">ሽያጮች</div>
          </div>
          <div class="user-stat">
            <div class="user-stat-val"><?= money($u['month_sales']) ?></div>
            <div class="user-stat-lbl">የወር ሽያጭ (ብር)</div>
          </div>
        </div>
        <div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:12px;">
          <i class="fas fa-clock"></i> የመጨረሻ ግቤት: <?= h($u['eth_last_login']) ?>
          &bull; <span class="badge <?= $u['is_active']?'badge-green':'badge-red' ?>"><?= $u['is_active']?'ንቁ':'ቆሟል' ?></span>
        </div>
        <div class="user-card-actions">
          <button class="btn btn-sm btn-outline" style="flex:1;" onclick='openEditUser(<?= json_encode($u) ?>)'>
            <i class="fas fa-edit"></i> አስተካክል
          </button>
          <button class="btn btn-sm btn-outline" onclick="openResetPwd(<?= $u['id'] ?>, <?= json_encode($u['full_name']) ?>)" title="ፓስዎርድ">
            <i class="fas fa-key"></i>
          </button>
          <?php if ($u['id'] !== $current_uid): ?>
          <button class="btn btn-sm <?= $u['is_active']?'btn-danger':'btn-success' ?>" onclick="toggleUser(<?= $u['id'] ?>, this, <?= $u['is_active']?'true':'false' ?>)" title="<?= $u['is_active']?'አቁም':'አስጀምር' ?>">
            <i class="fas fa-<?= $u['is_active']?'ban':'check' ?>"></i>
          </button>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Add User Modal -->
<div class="modal-backdrop" id="addUserModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title"><i class="fas fa-user-plus" style="color:var(--gold)"></i> ተጠቃሚ ጨምር</span>
      <button class="modal-close" onclick="closeModal('addUserModal')">✕</button>
    </div>
    <div class="modal-body">
      <form id="addUserForm" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_user">
        <div class="form-group"><label>ሙሉ ስም *</label><input type="text" name="full_name" class="form-control" required></div>
        <div class="form-group"><label>Username *</label><input type="text" name="username" class="form-control" required autocomplete="off"></div>
        <div class="form-group"><label>Password *</label><input type="password" name="password" class="form-control" required minlength="6" autocomplete="new-password"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="form-group"><label>ሚና</label>
            <select name="role" class="form-control"><option value="seller">ሻጭ</option><option value="admin">አስተዳዳሪ</option></select>
          </div>
          <div class="form-group"><label>ስልክ</label><input type="tel" name="phone" class="form-control"></div>
        </div>
        <div class="form-group"><label>ፎቶ (አማራጭ)</label>
          <input type="file" name="photo" class="form-control" accept="image/*" onchange="previewImg(this,'auPreview')">
          <img id="auPreview" style="max-width:80px;max-height:80px;border-radius:50%;margin-top:8px;display:none;">
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('addUserModal')">ሰርዝ</button>
      <button class="btn btn-primary" onclick="submitUser('addUserForm',this)"><i class="fas fa-save"></i> ጨምር</button>
    </div>
  </div>
</div>

<!-- Edit User Modal -->
<div class="modal-backdrop" id="editUserModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title"><i class="fas fa-user-edit" style="color:var(--gold)"></i> ተጠቃሚ አስተካክል</span>
      <button class="modal-close" onclick="closeModal('editUserModal')">✕</button>
    </div>
    <div class="modal-body">
      <form id="editUserForm" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="edit_user">
        <input type="hidden" name="user_id" id="euId">
        <div class="form-group"><label>ሙሉ ስም *</label><input type="text" name="full_name" id="euName" class="form-control" required></div>
        <div class="form-group"><label>Username *</label><input type="text" name="username" id="euUsername" class="form-control" required></div>
        <div class="form-group"><label>አዲስ Password (ባዶ ከሆነ አይቀይርም)</label><input type="password" name="password" class="form-control" autocomplete="new-password"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="form-group"><label>ሚና</label>
            <select name="role" id="euRole" class="form-control"><option value="seller">ሻጭ</option><option value="admin">አስተዳዳሪ</option></select>
          </div>
          <div class="form-group"><label>ስልክ</label><input type="tel" name="phone" id="euPhone" class="form-control"></div>
        </div>
        <div class="form-group"><label>አዲስ ፎቶ</label>
          <input type="file" name="photo" class="form-control" accept="image/*" onchange="previewImg(this,'euPreview')">
          <img id="euPreview" style="max-width:80px;max-height:80px;border-radius:50%;margin-top:8px;display:none;">
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('editUserModal')">ሰርዝ</button>
      <button class="btn btn-primary" onclick="submitUser('editUserForm',this)"><i class="fas fa-save"></i> አስቀምጥ</button>
    </div>
  </div>
</div>

<!-- Reset Password Modal -->
<div class="modal-backdrop" id="resetPwdModal">
  <div class="modal" style="max-width:400px;">
    <div class="modal-header">
      <span class="modal-title"><i class="fas fa-key" style="color:var(--gold)"></i> ፓስዎርድ ቀይር</span>
      <button class="modal-close" onclick="closeModal('resetPwdModal')">✕</button>
    </div>
    <div class="modal-body">
      <div style="background:var(--gold-pale);border-radius:var(--radius-sm);padding:12px;margin-bottom:16px;font-weight:700;color:var(--brown);" id="resetUserName"></div>
      <form id="resetPwdForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reset_password">
        <input type="hidden" name="user_id" id="rpUserId">
        <div class="form-group">
          <label>አዲስ Password *</label>
          <input type="password" name="new_password" class="form-control" minlength="6" required placeholder="ቢያንስ 6 ፊደሎች">
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('resetPwdModal')">ሰርዝ</button>
      <button class="btn btn-primary" onclick="submitResetPwd(this)"><i class="fas fa-key"></i> ቀይር</button>
    </div>
  </div>
</div>

<div id="toast" style="position:fixed;bottom:80px;left:50%;transform:translateX(-50%) translateY(20px);background:#333;color:#fff;padding:12px 24px;border-radius:30px;font-size:0.88rem;z-index:9999;opacity:0;transition:all .3s;pointer-events:none;"></div>

<script>
const CSRF_TOKEN='<?= csrf_token() ?>';
function openModal(id){document.getElementById(id).classList.add('open');}
function closeModal(id){document.getElementById(id).classList.remove('open');}
document.querySelectorAll('.modal-backdrop').forEach(bd=>bd.addEventListener('click',e=>{if(e.target===bd)bd.classList.remove('open');}));

function openAddUser(){document.getElementById('addUserForm').reset();document.getElementById('auPreview').style.display='none';openModal('addUserModal');}
function openEditUser(u){
  document.getElementById('euId').value=u.id;
  document.getElementById('euName').value=u.full_name;
  document.getElementById('euUsername').value=u.username;
  document.getElementById('euRole').value=u.role;
  document.getElementById('euPhone').value=u.phone||'';
  document.getElementById('euPreview').style.display='none';
  openModal('editUserModal');
}
function openResetPwd(uid,name){
  document.getElementById('rpUserId').value=uid;
  document.getElementById('resetUserName').textContent=name;
  document.getElementById('resetPwdForm').reset();
  document.getElementById('rpUserId').value=uid;
  openModal('resetPwdModal');
}
async function submitUser(formId,btn){
  const form=document.getElementById(formId);
  const fd=new FormData(form);
  const origHtml = btn ? btn.innerHTML : null;
  if(btn){btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> እየተላከ...';}
  try{
    const res=await fetch('admin_users.php',{method:'POST',body:fd});
    const data=await res.json();
    if(data.success){closeModal('addUserModal');closeModal('editUserModal');showToast('✓ ተሳክቷል');setTimeout(()=>location.reload(),1000);}
    else{showToast('❌ '+data.message,'error'); if(btn){btn.disabled=false;btn.innerHTML=origHtml;}}
  }catch(e){showToast('❌ ግንኙነት ስህተት','error'); if(btn){btn.disabled=false;btn.innerHTML=origHtml;}}
}
async function submitResetPwd(btn){
  const form=document.getElementById('resetPwdForm');
  const fd=new FormData(form);
  const origHtml = btn ? btn.innerHTML : null;
  if(btn){btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> እየተላከ...';}
  try{
    const res=await fetch('admin_users.php',{method:'POST',body:fd});
    const data=await res.json();
    if(data.success){closeModal('resetPwdModal');showToast('✓ ፓስዎርድ ተቀይሯል');}
    else{showToast('❌ '+data.message,'error'); if(btn){btn.disabled=false;btn.innerHTML=origHtml;}}
    if(btn && data.success){btn.disabled=false;btn.innerHTML=origHtml;}
  }catch(e){showToast('❌ ግንኙነት ስህተት','error'); if(btn){btn.disabled=false;btn.innerHTML=origHtml;}}
}
async function toggleUser(uid,btn,isActive){
  const confirmMsg = isActive ? 'ይህን ተጠቃሚ ማሰናከል ይፈልጋሉ?' : 'ይህን ተጠቃሚ ማንቃት ይፈልጋሉ?';
  if(!confirm(confirmMsg))return;
  const fd=new FormData();fd.append('action','toggle_user');fd.append('user_id',uid);fd.append('csrf_token',CSRF_TOKEN);
  const res=await fetch('admin_users.php',{method:'POST',body:fd});
  const data=await res.json();
  if(data.success){showToast('✓ ተቀይሯል');setTimeout(()=>location.reload(),800);}
  else showToast('❌ '+data.message,'error');
}
function previewImg(input,id){
  const f=input.files[0];if(!f)return;
  const r=new FileReader();r.onload=e=>{const img=document.getElementById(id);img.src=e.target.result;img.style.display='block';};r.readAsDataURL(f);
}
function showToast(msg,type='success'){
  const t=document.getElementById('toast');
  t.textContent=msg;t.style.background=type==='error'?'#C62828':'#2E7D32';
  t.style.opacity='1';t.style.transform='translateX(-50%) translateY(0)';
  setTimeout(()=>{t.style.opacity='0';t.style.transform='translateX(-50%) translateY(20px)';},2500);
}
</script>
</body>
</html>
