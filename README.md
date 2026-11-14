# Coaching Institute Management

A complete website + software for a coaching institute, written in PHP and MySQL — the institute office, an online course store and a blog in one admin panel.

## What it does

**Institute (office work)**
- Students: admission form with photo, registration numbers, class, school, parents, documents; student profile and history
- Courses, classes, batches (timing, seats, teacher) and academic sessions
- Fees: full or by installments (kists), discounts, part payments, split payments (Cash + UPI …), due dates, late fees list
- Receipts (print / PDF), dues with one-click "Collect fee", fee collection by payment mode
- Reports as PDF: students, batches, courses, classes, fees, dues
- Dashboard: fee collected, admissions, due, period filter (today / week / month / session / custom), running batches, recent payments

**Online store (recorded courses & PDF notes)**
- Video courses with chapters, lectures, PDF lectures, notes, quiz, VVI questions, discussion and private doubts
- Locked video player (YouTube ids never in the page), signed PDF / video links for buyers only
- Free courses and free previews that open and share normally
- Student accounts (one device at a time), orders, Razorpay payments, reviews, support requests

**Website & blog**
- Posts, pages, categories, tags, authors, FAQs, SEO tools, sitemaps, RSS, web push notifications
- Customizer (header, footer, colours, home page sections), mobile bottom bar

**Admin tools**
- Roles and permissions (admin, teacher, accountant, editor …), activity log
- Display options on every page, backup & restore, import & export (posts, courses, students CSV), site health, cache, cron manager

## Requirements
- PHP 8.1+ with `pdo_mysql`, `mbstring`, `openssl`, `curl`, `zip`, `gd` (and `apcu` if available)
- MySQL 8 or MariaDB 10.6+
- Composer

## Install
1. Upload the files to your web folder (Apache / LiteSpeed — `.htaccess` is included).
2. `composer install --no-dev` (creates `vendor/`).
3. Create an empty database and import `database/schema.sql`.
4. Copy `includes/config.sample.php` to `includes/config.php` and fill in the site name, URL and database details.
   Push notification keys: `npx web-push generate-vapid-keys`.
5. Create the first admin (replace the values):
   ```sql
   INSERT INTO users (username, email, password_hash, role, status, permissions)
   VALUES ('admin', 'you@example.com', '<hash>', 'admin', 'active', '{"dashboard_access":true}');
   ```
   Make the hash with: `php -r "echo password_hash('YourPassword', PASSWORD_DEFAULT);"`
6. Open `/admin-login`, then set up sessions, classes, courses and batches under **Institute**.

Uploads, cache and `includes/config.php` are not part of the repository.
