<?php
// layout_start.php — Shared CSS variables + layout shell opening
// Include after <head> meta tags, before page-specific CSS
// Call layout_end.php at bottom of page
?>
<link rel="stylesheet" href="assets/fontawesome/css/all.min.css">
<style>
/* ═══════════════════════════════════════════════
   GLOBAL DESIGN SYSTEM — አጸደ ትጉሃን POS v2.0
   Mobile-first, Ethiopian-themed
═══════════════════════════════════════════════ */
:root{
  --gold:#DAA520; --gold-light:#F0C040; --gold-pale:#FFF8E7;
  --brown:#8B4513; --brown-dark:#5C2E0B; --brown-light:#A0522D;
  --orange:#D96B2B;
  --green:#2E7D32; --green-light:#E8F5E9;
  --red:#C62828; --red-light:#FFEBEE;
  --blue:#1565C0; --blue-light:#E3F2FD;
  --purple:#6A1B9A; --purple-light:#F3E5F5;
  --bg:#F5F0E8; --card:#FFFFFF;
  --text:#1A1A1A; --text-muted:#666; --text-light:#999;
  --border:#E8E0D0; --border-light:#F0EAE0;
  --sidebar-w:256px;
  --topbar-h:60px; --bottomnav-h:64px;
  --radius:18px; --radius-md:12px; --radius-sm:8px;
  --shadow:0 4px 20px rgba(0,0,0,0.08);
  --shadow-md:0 8px 30px rgba(0,0,0,0.12);
  --shadow-lg:0 20px 60px rgba(0,0,0,0.18);
  --transition:.2s ease;
}
[data-theme="dark"]{
  --bg:#121212; --card:#1E1E1E; --text:#F0F0F0; --text-muted:#AAA; --text-light:#777;
  --border:#2E2E2E; --border-light:#252525; --gold-pale:#2A2000;
}
*{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;}
body{
  font-family:'Segoe UI','Nyala',Tahoma,Arial,sans-serif;
  background:var(--bg);color:var(--text);
  min-height:100vh;font-size:15px;line-height:1.5;
}

/* ── Scrollbar ── */
::-webkit-scrollbar{width:6px;height:6px;}
::-webkit-scrollbar-track{background:transparent;}
::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px;}
::-webkit-scrollbar-thumb:hover{background:var(--gold);}

/* ── Sidebar ── */
.sidebar{
  position:fixed;left:0;top:0;bottom:0;width:var(--sidebar-w);
  background:linear-gradient(180deg,var(--brown-dark) 0%,var(--brown) 100%);
  color:#fff;z-index:200;display:flex;flex-direction:column;
  box-shadow:4px 0 24px rgba(0,0,0,0.25);
  transition:transform var(--transition);
}
.sidebar-header{
  padding:20px 18px 16px;border-bottom:1px solid rgba(255,255,255,0.1);
  text-align:center;
}
.sidebar-logo{
  width:56px;height:56px;border-radius:50%;border:2px solid rgba(218,165,32,0.5);
  display:flex;align-items:center;justify-content:center;
  margin:0 auto 10px;background:rgba(255,255,255,0.1);overflow:hidden;
}
.sidebar-logo img{width:100%;height:100%;object-fit:cover;}
.sidebar-logo i{font-size:26px;color:var(--gold);}
.sidebar-header h2{font-size:0.75rem;color:var(--gold);line-height:1.3;}
.sidebar-header small{color:rgba(255,255,255,0.5);font-size:0.65rem;}
.sidebar-user{
  display:flex;align-items:center;gap:10px;
  padding:14px 18px;border-bottom:1px solid rgba(255,255,255,0.1);
}
.user-avatar{font-size:28px;color:var(--gold-light);flex-shrink:0;}
.user-name{font-size:0.85rem;font-weight:600;color:#fff;}
.user-role{font-size:0.7rem;color:rgba(255,255,255,0.6);}
.nav-menu{list-style:none;padding:12px 10px;flex:1;overflow-y:auto;}
.nav-menu li{margin-bottom:2px;}
.nav-menu a{
  display:flex;align-items:center;gap:12px;padding:11px 14px;
  color:rgba(255,255,255,0.8);text-decoration:none;border-radius:var(--radius-sm);
  font-size:0.85rem;transition:all var(--transition);font-family:inherit;
}
.nav-menu a:hover,.nav-menu a.active{
  background:rgba(218,165,32,0.2);color:var(--gold-light);
}
.nav-menu a.active{border-left:3px solid var(--gold);}
.nav-menu a i{width:18px;text-align:center;font-size:0.9rem;}
.sidebar-bottom{padding:14px 18px;border-top:1px solid rgba(255,255,255,0.1);}
.eth-clock{
  font-size:0.8rem;color:var(--gold);font-weight:600;margin-bottom:4px;
  display:flex;align-items:center;gap:6px;
}
.eth-date-display{font-size:0.72rem;color:rgba(255,255,255,0.6);margin-bottom:10px;}
.logout-btn{
  display:flex;align-items:center;gap:8px;padding:10px 14px;
  background:rgba(255,0,0,0.15);color:#FF8A80;border-radius:var(--radius-sm);
  text-decoration:none;font-size:0.83rem;transition:all var(--transition);
}
.logout-btn:hover{background:rgba(255,0,0,0.25);}

/* ── Mobile Top Bar ── */
.mobile-topbar{
  display:none;position:fixed;top:0;left:0;right:0;height:var(--topbar-h);
  background:linear-gradient(135deg,var(--brown-dark),var(--brown));
  color:#fff;z-index:150;align-items:center;padding:0 16px;
  box-shadow:0 2px 12px rgba(0,0,0,0.2);gap:12px;
}
.mobile-topbar span{flex:1;font-size:0.9rem;font-weight:700;color:var(--gold);}
.menu-toggle,.mobile-logout{
  background:none;border:none;color:#fff;font-size:1.2rem;
  cursor:pointer;padding:8px;border-radius:6px;text-decoration:none;
}
.menu-toggle:hover,.mobile-logout:hover{background:rgba(255,255,255,0.1);}

/* ── Sidebar Overlay ── */
.sidebar-overlay{
  display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:190;
}
.sidebar-overlay.show{display:block;}

/* ── Bottom Nav (mobile) ── */
.bottom-nav{
  display:none;position:fixed;bottom:0;left:0;right:0;height:var(--bottomnav-h);
  background:var(--card);border-top:1px solid var(--border);
  z-index:150;box-shadow:0 -4px 20px rgba(0,0,0,0.1);
}
.bottom-nav a{
  flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;
  gap:3px;text-decoration:none;color:var(--text-muted);font-size:0.62rem;
  padding:6px 2px;transition:color var(--transition);
}
.bottom-nav a i{font-size:1.1rem;}
.bottom-nav a.active,.bottom-nav a:hover{color:var(--brown);}
.bottom-nav a.active i{color:var(--gold);}

/* ── Main Content Area ── */
.main-content{
  margin-left:var(--sidebar-w);min-height:100vh;
  padding:24px;
}

/* ── Page Header ── */
.page-header{
  background:var(--card);border-radius:var(--radius);padding:20px 24px;
  margin-bottom:20px;box-shadow:var(--shadow);
  display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;
}
.page-header h1{font-size:1.3rem;color:var(--brown);display:flex;align-items:center;gap:10px;}
.page-header h1 i{color:var(--gold);}
.page-title-sub{font-size:0.78rem;color:var(--text-muted);margin-top:2px;}

/* ── Cards ── */
.card{
  background:var(--card);border-radius:var(--radius);
  box-shadow:var(--shadow);padding:20px;
}
.card-header{
  display:flex;align-items:center;justify-content:space-between;
  margin-bottom:18px;gap:12px;
}
.card-title{font-size:1rem;font-weight:700;color:var(--brown);display:flex;align-items:center;gap:8px;}
.card-title i{color:var(--gold);}

/* ── Stat Cards ── */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:20px;}
.stat-card{
  background:var(--card);border-radius:var(--radius);padding:20px 18px;
  box-shadow:var(--shadow);transition:all var(--transition);border-top:4px solid transparent;
  position:relative;overflow:hidden;
}
.stat-card::before{
  content:'';position:absolute;top:0;right:0;width:60px;height:60px;
  background:linear-gradient(135deg,transparent 50%,rgba(218,165,32,0.06) 50%);
}
.stat-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-md);}
.stat-card.gold{border-color:var(--gold);}
.stat-card.green{border-color:var(--green);}
.stat-card.red{border-color:var(--red);}
.stat-card.blue{border-color:var(--blue);}
.stat-icon{font-size:1.8rem;margin-bottom:10px;}
.stat-card.gold .stat-icon{color:var(--gold);}
.stat-card.green .stat-icon{color:var(--green);}
.stat-card.red .stat-icon{color:var(--red);}
.stat-card.blue .stat-icon{color:var(--blue);}
.stat-value{font-size:1.5rem;font-weight:800;color:var(--text);line-height:1;}
.stat-label{font-size:0.75rem;color:var(--text-muted);margin-top:6px;}

/* ── Buttons ── */
.btn{
  display:inline-flex;align-items:center;gap:8px;padding:10px 18px;
  border:none;border-radius:var(--radius-sm);cursor:pointer;font-size:0.875rem;
  font-weight:600;transition:all var(--transition);font-family:inherit;text-decoration:none;
}
.btn:hover{transform:translateY(-1px);}
.btn-primary{background:linear-gradient(135deg,var(--brown),var(--gold));color:#fff;box-shadow:0 4px 15px rgba(139,69,19,0.3);}
.btn-primary:hover{box-shadow:0 8px 25px rgba(139,69,19,0.4);}
.btn-success{background:linear-gradient(135deg,#2E7D32,#4CAF50);color:#fff;box-shadow:0 4px 15px rgba(46,125,50,0.3);}
.btn-danger{background:linear-gradient(135deg,#C62828,#EF5350);color:#fff;box-shadow:0 4px 15px rgba(198,40,40,0.3);}
.btn-outline{background:transparent;border:2px solid var(--border);color:var(--text);}
.btn-outline:hover{border-color:var(--gold);color:var(--brown);}
.btn-sm{padding:7px 14px;font-size:0.8rem;}
.btn-lg{padding:14px 24px;font-size:1rem;}
.btn-icon{padding:10px;border-radius:50%;}
.btn-full{width:100%;justify-content:center;}

/* ── Form Controls ── */
.form-group{margin-bottom:16px;}
.form-group label{display:block;font-size:0.82rem;font-weight:600;color:var(--text);margin-bottom:6px;}
.form-control{
  width:100%;padding:12px 14px;border:2px solid var(--border);
  border-radius:var(--radius-sm);font-size:0.95rem;color:var(--text);
  background:var(--card);transition:border-color var(--transition);font-family:inherit;
}
.form-control:focus{outline:none;border-color:var(--gold);}
select.form-control{cursor:pointer;}
.input-group{position:relative;}
.input-group .form-control{padding-left:42px;}
.input-group .input-icon{
  position:absolute;left:14px;top:50%;transform:translateY(-50%);
  color:var(--brown);font-size:0.9rem;pointer-events:none;
}

/* ── Badge ── */
.badge{
  display:inline-flex;align-items:center;gap:4px;padding:3px 10px;
  border-radius:20px;font-size:0.72rem;font-weight:600;
}
.badge-gold{background:var(--gold-pale);color:var(--brown);}
.badge-green{background:var(--green-light);color:var(--green);}
.badge-red{background:var(--red-light);color:var(--red);}
.badge-blue{background:var(--blue-light);color:var(--blue);}
.badge-purple{background:var(--purple-light);color:var(--purple);}

/* ── Product Card Grid (for POS) ── */
.product-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px;}
.product-card{
  background:var(--card);border-radius:var(--radius-md);
  box-shadow:var(--shadow);overflow:hidden;cursor:pointer;
  transition:all var(--transition);border:2px solid transparent;
  position:relative;
}
.product-card:hover,.product-card.selected{
  border-color:var(--gold);transform:translateY(-2px);box-shadow:var(--shadow-md);
}
.product-card.out-of-stock{opacity:.5;cursor:not-allowed;}
.product-img{
  width:100%;height:100px;object-fit:cover;
  background:linear-gradient(135deg,var(--gold-pale),var(--border));
  display:flex;align-items:center;justify-content:center;font-size:2.5rem;
}
.product-img img{width:100%;height:100%;object-fit:cover;}
.product-info{padding:10px 10px 12px;}
.product-name{font-size:0.82rem;font-weight:700;color:var(--text);margin-bottom:4px;line-height:1.3;}
.product-price{font-size:1rem;font-weight:800;color:var(--brown);}
.product-stock{font-size:0.72rem;color:var(--text-muted);}
.product-stock.low{color:var(--red);}
.add-to-cart-btn{
  width:100%;padding:8px;background:linear-gradient(135deg,var(--brown),var(--gold));
  color:#fff;border:none;border-radius:0 0 var(--radius-md) var(--radius-md);
  cursor:pointer;font-size:0.82rem;font-weight:600;transition:all var(--transition);
  font-family:inherit;
}
.add-to-cart-btn:hover{background:linear-gradient(135deg,var(--brown-dark),var(--brown));}
.add-to-cart-btn:disabled{background:#ccc;cursor:not-allowed;}

/* ── Modal ── */
.modal-backdrop{
  position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:1000;
  display:flex;align-items:center;justify-content:center;padding:16px;
  opacity:0;pointer-events:none;transition:opacity .25s;
}
.modal-backdrop.open{opacity:1;pointer-events:all;}
.modal{
  background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow-lg);
  width:100%;max-width:520px;max-height:90vh;overflow-y:auto;
  transform:scale(0.9);transition:transform .25s;
}
.modal-backdrop.open .modal{transform:scale(1);}
.modal-header{
  padding:20px 24px 0;display:flex;align-items:center;justify-content:space-between;
  border-bottom:1px solid var(--border);padding-bottom:16px;margin-bottom:20px;
}
.modal-title{font-size:1.1rem;font-weight:700;color:var(--brown);}
.modal-close{background:none;border:none;font-size:1.3rem;cursor:pointer;color:var(--text-muted);padding:4px;}
.modal-body{padding:0 24px;}
.modal-footer{padding:20px 24px;border-top:1px solid var(--border);margin-top:20px;display:flex;gap:12px;justify-content:flex-end;}

/* ── Table ── */
.data-table{width:100%;border-collapse:collapse;}
.data-table th{
  padding:10px 14px;text-align:left;font-size:0.78rem;font-weight:700;
  text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);
  border-bottom:2px solid var(--border);background:var(--bg);
}
.data-table td{
  padding:12px 14px;font-size:0.87rem;border-bottom:1px solid var(--border-light);
  vertical-align:middle;
}
.data-table tr:hover td{background:var(--gold-pale);}
.data-table tr:last-child td{border-bottom:none;}

/* ── Alert / Toast ── */
.alert{
  padding:12px 16px;border-radius:var(--radius-sm);margin-bottom:16px;
  display:flex;align-items:flex-start;gap:10px;font-size:0.88rem;
}
.alert-success{background:var(--green-light);color:var(--green);}
.alert-danger{background:var(--red-light);color:var(--red);}
.alert-warning{background:#FFF8E1;color:#E65100;}
.alert-info{background:var(--blue-light);color:var(--blue);}

/* ── Responsive ── */
@media(max-width:900px){
  .sidebar{transform:translateX(-100%);}
  .sidebar.open{transform:translateX(0);}
  .mobile-topbar{display:flex;}
  .bottom-nav{display:flex;}
  .main-content{margin-left:0;padding:calc(var(--topbar-h)+16px) 14px calc(var(--bottomnav-h)+16px);}
  .stats-grid{grid-template-columns:repeat(2,1fr);}
  .product-grid{grid-template-columns:repeat(2,1fr);}
}
@media(max-width:480px){
  .stats-grid{grid-template-columns:repeat(2,1fr);}
  .product-grid{grid-template-columns:repeat(2,1fr);}
  .page-header{padding:14px 16px;}
  .card{padding:14px;}
}

/* ── Animations ── */
@keyframes fadeIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.fade-in{animation:fadeIn .3s ease forwards;}
@keyframes pulse{0%,100%{transform:scale(1)}50%{transform:scale(1.05)}}
.pulse{animation:pulse .6s ease;}

/* ── Utility ── */
.mt-1{margin-top:8px}.mt-2{margin-top:16px}.mt-3{margin-top:24px}
.mb-1{margin-bottom:8px}.mb-2{margin-bottom:16px}.mb-3{margin-bottom:24px}
.flex{display:flex}.flex-center{display:flex;align-items:center;justify-content:center}
.gap-1{gap:8px}.gap-2{gap:16px}
.text-gold{color:var(--gold)}.text-brown{color:var(--brown)}
.text-muted{color:var(--text-muted)}.text-sm{font-size:0.82rem}
.text-right{text-align:right}.text-center{text-align:center}
.fw-bold{font-weight:700}.fw-800{font-weight:800}
.hidden{display:none!important}
.w-full{width:100%}
.section-gap{margin-bottom:20px;}
</style>
