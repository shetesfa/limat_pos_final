<?php
// nav.php — Shared navigation include
// Usage: require_once 'nav.php'; at top of admin pages, after config.php
// $active_page must be set before including (e.g. $active_page = 'dashboard';)

$nav_user = $_SESSION['full_name'] ?? 'User';
$nav_role = $_SESSION['role'] ?? 'seller';
$eth_now  = getCurrentEthDatetime();
$biz_name = getSetting($conn, 'business_name', 'አጸደ ትጉሃን');

$nav_items = [
  ['href'=>'admin_dashboard.php','icon'=>'fas fa-tachometer-alt','label'=>'ዳሽቦርድ','key'=>'dashboard'],
  ['href'=>'admin_products.php','icon'=>'fas fa-boxes','label'=>'ምርቶች','key'=>'products'],
  ['href'=>'stock_log.php','icon'=>'fas fa-warehouse','label'=>'ክምችት ሎግ','key'=>'stocklog'],
  ['href'=>'seller_pos.php','icon'=>'fas fa-cash-register','label'=>'ሽያጭ (POS)','key'=>'pos'],
  ['href'=>($nav_role==='admin'?'admin_reports.php':'seller_report.php'),'icon'=>'fas fa-chart-bar','label'=>'ሪፖርቶች','key'=>'reports'],
  ['href'=>'seller_report.php','icon'=>'fas fa-receipt','label'=>'ደረሰኞች','key'=>'receipts'],
  ['href'=>'money_flow.php','icon'=>'fas fa-money-bill-trend-up','label'=>'የገንዘብ ፍሰት','key'=>'moneyflow'],
  ['href'=>'history.php','icon'=>'fas fa-history','label'=>'ታሪክ','key'=>'history'],
  ['href'=>'admin_users.php','icon'=>'fas fa-users','label'=>'ተጠቃሚዎች','key'=>'users'],
  ['href'=>'expense.php','icon'=>'fas fa-receipt','label'=>'ወጪዎች','key'=>'expenses'],
];

if ($nav_role !== 'admin') {
    $nav_items = array_filter($nav_items, fn($i) => in_array($i['key'], ['pos','receipts']));
}
?>
<!-- SIDEBAR NAV -->
<nav class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <div class="sidebar-logo">
      <?php if (file_exists('images/icon.png')): ?>
        <img src="images/icon.png" alt="Logo">
      <?php else: ?>
        <i class="fas fa-cross"></i>
      <?php endif; ?>
    </div>
    <h2><?= h($biz_name) ?></h2>
    <small>POS v2.0</small>
  </div>

  <div class="sidebar-user">
    <div class="user-avatar"><i class="fas fa-user-circle"></i></div>
    <div>
      <div class="user-name"><?= h($nav_user) ?></div>
      <div class="user-role"><?= $nav_role === 'admin' ? 'አስተዳዳሪ' : 'ሻጭ' ?></div>
    </div>
  </div>

  <ul class="nav-menu">
    <?php foreach ($nav_items as $item): ?>
    <li>
      <a href="<?= h($item['href']) ?>" class="<?= (($active_page ?? '') === $item['key']) ? 'active' : '' ?>">
        <i class="<?= $item['icon'] ?>"></i>
        <span><?= $item['label'] ?></span>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>

  <div class="sidebar-bottom">
    <div class="eth-clock">
      <i class="fas fa-clock"></i>
      <span id="sidebarTime"><?= h($eth_now['greg_time']) ?></span>
    </div>
    <div class="eth-date-display"><?= h($eth_now['eth_date']) ?></div>
    <a href="logout.php" class="logout-btn">
      <i class="fas fa-sign-out-alt"></i> ውጣ
    </a>
  </div>
</nav>

<!-- MOBILE TOP BAR -->
<div class="mobile-topbar">
  <button class="menu-toggle" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
  <span><?= h($biz_name) ?></span>
  <a href="logout.php" class="mobile-logout"><i class="fas fa-sign-out-alt"></i></a>
</div>

<!-- MOBILE BOTTOM NAV -->
<nav class="bottom-nav">
  <?php foreach (array_values($nav_items) as $item): ?>
  <a href="<?= h($item['href']) ?>" class="<?= (($active_page ?? '') === $item['key']) ? 'active' : '' ?>">
    <i class="<?= $item['icon'] ?>"></i>
    <span><?= $item['label'] ?></span>
  </a>
  <?php endforeach; ?>
</nav>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<script>
function toggleSidebar(){
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('sidebarOverlay').classList.toggle('show');
  document.body.classList.toggle('sidebar-open');
}
// Update clock
setInterval(()=>{
  const now=new Date();
  let h=now.getHours()%12||12,m=String(now.getMinutes()).padStart(2,'0'),
      s=String(now.getSeconds()).padStart(2,'0'),ap=now.getHours()<12?'AM':'PM';
  const el=document.getElementById('sidebarTime');
  if(el) el.textContent=`${String(h).padStart(2,'0')}:${m}:${s} ${ap}`;
},1000);
</script>
