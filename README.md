# አጸደ ትጉሃን ሰንበት ትምህርት ቤት — POS System v2.0
## Installation Guide

---

### Requirements
- PHP 8.0+ with extensions: mysqli, json, fileinfo, session
- MySQL 5.7+ or MariaDB 10.4+
- Apache with mod_rewrite

---

### Installation Steps

#### 1. Database Setup
```sql
-- Import the new schema:
mysql -u root -p < atsedeteguhan_pos_NEW.sql
```

#### 2. Upload Files
Copy all files to your web server root (e.g. `htdocs/limat/` or `/var/www/html/limat/`)

#### 3. Configure Database
Edit `config.php` lines 40–43:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('DB_NAME', 'atsedeteguhan_pos');
```

#### 4. Create Folders (writable by web server)
```bash
mkdir -p uploads/products uploads/users images
chmod 755 uploads uploads/products uploads/users images
```

#### 5. Default Login
| Role | Username | Password |
|------|----------|----------|
| Admin | `admin` | `Admin@123` |
| Seller | `selam` | `Admin@123` |
| Seller | `dawit` | `Admin@123` |

**Change all passwords immediately after first login!**

---

### File Structure
```
limat_pos/
├── config.php              ← Database + helpers (edit DB credentials here)
├── layout_start.php        ← Shared CSS design system
├── nav.php                 ← Shared sidebar + mobile nav
├── index.php               ← Login page
├── logout.php              ← Logout
├── admin_dashboard.php     ← Dashboard (admin)
├── admin_products.php      ← Products + FIFO stock receive
├── admin_reports.php       ← 6 report types
├── admin_users.php         ← User management
├── history.php             ← Full audit trail + edit history
├── stock_log.php           ← Stock receive log
├── settings.php            ← Business settings + expenses
├── seller_pos.php          ← POS (sellers + admins)
├── api_batches.php         ← AJAX: batch history
├── atsedeteguhan_pos_NEW.sql ← Complete NEW database schema
├── .htaccess               ← Apache security
└── uploads/                ← Product & user images (auto-created)
    ├── products/
    └── users/
```

---

### Key Fixes from Old System

| Problem | Fix |
|---------|-----|
| Used product_name as ID | Now uses product_id everywhere |
| No FIFO | stock_batches table, FIFO in all sales |
| Wrong profit (0 always) | buy_price stored per batch, profit = sell - buy |
| Double-submit on POS | idempotency_key unique constraint |
| No IP/device in logs | getClientIP() + getDeviceType() in every audit log |
| Missing old/new values | audit_log has old_value + new_value columns |
| Per-seller stock | Shared branch stock, all sellers see same |
| No CSRF on forms | csrf_token() on every POST |

---

### Security Notes
- All passwords hashed with `PASSWORD_ARGON2ID`
- CSRF tokens on every form
- Prepared statements everywhere (no SQL injection)
- Session regenerated after login
- Rate limiting: 5 failed logins = 5 minute lockout
- XSS protection via `htmlspecialchars()` (h() function)
- Audit log: every action logged with IP, device, user

---

### Money Flow Page — How To Use (`money_flow.php`)

This page (admin-only) answers "how is our money moving?" for a chosen period,
plus one all-time figure. Click **"እንዴት እንደሚነበብ" (How to read this)** on the
page itself for the same explanation in Amharic. In short:

| Card | Meaning | Time window |
|------|---------|-------------|
| ጠቅላላ ሽያጭ (Total Sales) | Sum of `transactions.total_amount` | Selected period only |
| የግዢ ወጪ (Purchase Cost / COGS) | Sum of `quantity × buy_price` for items actually **sold** in the period | Selected period only |
| ጠቅላላ ትርፍ (Gross Profit) | Total Sales − Purchase Cost | Selected period only |
| ወጪዎች (Expenses) | Sum of the `expenses` table | Selected period only |
| የተጣራ ትርፍ (Net Profit) | Gross Profit − Expenses | Selected period only |
| ያለ ገንዘብ (Available Cash) | All-time sales − all-time stock **purchases** (sold or not) − all-time expenses | **Always all-time**, ignores the date filter |

**Why "Available Cash" can be negative:** it subtracts the full cost of
*everything ever bought as stock*, not just what's been sold. If you recently
bought a lot of inventory that hasn't sold through yet, that money is
temporarily "in the shelf" rather than in the till, so the number goes
negative — that's expected, not a bug. It becomes positive again as that
stock sells.

**On double-counting (a common worry with POS reports):** none of the money
flow numbers can be counted twice. Total Sales and Available Cash's sales
component read `transactions.total_amount` directly — one row per sale, no
join to line items — so a sale is never multiplied by how many products were
in the cart. Purchase Cost, COGS, and profit are summed from
`transaction_items`, and each stock batch is decremented exactly once per
sale (guarded by `idempotency_key`, which also blocks accidental
double-submits of the same sale). See the code comments directly above the
"Available Cash" block in `money_flow.php` for the full explanation.

---

### Changelog — Reporting & Data Accuracy Fixes (2026-07)

A previous version of this system reported inflated sales figures (e.g. a
day's true revenue of 1,200 birr showing as 210,000+). Root cause and fixes:

1. **`admin_reports.php` — Product report (የምርት)**: the query joined
   `transaction_items` to `products` *before* filtering by date, so every
   product's sales were summed across its **entire history** regardless of
   the date range picked. Fixed by pre-filtering items to the selected
   date range + branch in a subquery before joining to products.
2. **`admin_reports.php` / `admin_dashboard.php` — Daily & monthly totals**:
   revenue was calculated by `SUM(transactions.total_amount)` after joining
   to `transaction_items`, which multiplies each sale's total once per line
   item in the cart ("fan-out"). Fixed by summing transaction totals from
   `transactions` alone and joining profit back in separately.
3. **Monthly charts** (reports + dashboard) now always start at **መስከረም**
   (month 1) of the current Ethiopian year and run through the current
   month, instead of a rolling "last 6 months" window.
4. **`seller_pos.php`**: completing a sale ("ሽያጭ ፍጸም") now finishes
   immediately and resets the cart for the next sale, instead of forcing the
   receipt modal open. The last receipt stays one tap away via the
   "የመጨረሻ ደረሰኝ" button for printing when a customer needs a copy.
5. **`seller_report.php`**: rebuilt from a static "today only" page into a
   filterable sales history — date-range presets, receipt/item/payment
   search, summary cards, a daily trend chart, payment-method breakdown, and
   a receipt view/print modal, scoped to the logged-in seller's own sales.
6. **`money_flow.php`**: calculations were already correct (verified against
   live data); added the inline help panel and this guide so the figures —
   especially the all-time "Available Cash" — are easy to interpret.

---

### Support
System built for: አጸደ ትጉሃን ሰንበት ትምህርት ቤት  
Version: 2.0 | Date: 2026
