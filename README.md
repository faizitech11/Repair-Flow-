# RepairFlow Pro — PHP + MySQL

A database-backed repair-shop manager with per-shop data isolation, repair tickets, repair payments, inventory, stock movements, quick sales, branded PDF exports and WhatsApp invoice links.

## Requirements
- XAMPP with PHP 8.1+ (8.2+ recommended), MySQL/MariaDB, Apache
- PHP extensions: `pdo_mysql`, `fileinfo`, `mbstring`
- Internet connection for Bootstrap, Font Awesome and html2pdf CDN assets

## Install on Windows / XAMPP
1. Copy the `repairflow_pro` folder to `C:\xampp\htdocs\repairflow_pro`.
2. Start **Apache** and **MySQL** from XAMPP Control Panel.
3. Open `http://localhost/phpmyadmin`.
4. Select **Import**, choose `schema.sql`, and run the import. This creates the `repairflow_pro` database and tables.
5. Open `C:\xampp\htdocs\repairflow_pro\config.php`. Set `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASS` to your local database credentials. XAMPP default is commonly `root` with an empty password; set a password for any non-local deployment.
6. Ensure Apache can write to `uploads` (the application creates subfolders as needed). Create an empty `uploads` folder if desired. Keep PHP execution disabled inside uploads in production.
7. Visit `http://localhost/repairflow_pro/` and choose **Create shop account**.

## Included files
- `index.php` — responsive workspace UI and front-end interactions
- `api.php` — JSON API, authentication, ownership checks, stock and sales transactions
- `config.php` — PDO connection, sessions and shared helpers
- `schema.sql` — MySQL schema
- `uploads/` — created as images are uploaded

## Features
- Separate shop accounts; every data query checks the logged-in `shop_id`.
- Password hashing, regenerated session IDs, HTTP-only/SameSite session cookies, CSRF token checks, prepared SQL statements and basic security headers.
- Repair description, estimated/final bill, due date, status changes, repair payment ledger and invoice PDF.
- Product categories, sale price, purchase cost, stock threshold, edit/delete, quick sale, restock history and direct-sales ledger.
- Daily/monthly/yearly report generation with shop logo/name and PDF export.
- Logout does not delete MySQL data.

## Important production checklist
This is a working local MVP foundation, not a fully audited commercial SaaS. Before public deployment:
- Serve only over HTTPS; configure secure cookies and production secrets.
- Use a dedicated MySQL account with limited privileges, not the root account.
- Disable PHP execution in `uploads`, validate upload permissions, and apply stricter upload/image processing limits.
- Add login rate limiting, password reset/email verification, audit logging, backups/restore tests, monitoring and automated security testing.
- Set up database backups and a retention policy. Test recovery before storing live customer data.
- Use a trusted server-side PDF/report process if PDF generation must be guaranteed independently of the user's browser/CDN.
- Add authorization review and tests before allowing multiple technicians or subscription billing.

## Notes
- The included PDF export runs in the browser using html2pdf.js; records and report totals are loaded from MySQL.
- Daily/monthly/yearly reports filter records by their creation date. Repair payments are separately totalled by payment date.
- Deleting a product preserves existing sales rows; deleting a repair removes its payment history due to the foreign key cascade.

## Repair photos and invoices

- In Repairs, select a JPG, PNG, or WEBP device-condition photo (up to 3 MB).
- A preview appears before saving. The photo is stored under `uploads/repairs/<shop_id>/` and linked to that shop's repair record.
- The saved photo thumbnail appears in repair history and the dashboard; the branded repair invoice PDF includes the same photo.
- When editing a ticket, its existing photo is shown. Select a new photo only if you want to replace it.
- Ensure PHP's `fileinfo` extension is enabled and the `uploads` folder is writable by Apache.

## Install RepairFlow Pro as a PWA

- The project includes `manifest.webmanifest`, `sw.js`, app icons and an offline fallback screen.
- Open the project through `http://localhost/repairflow_pro/` for local testing, or deploy it over HTTPS for phones and other devices. Do not open `index.php` as a local file.
- On supported Chrome/Edge browsers, use the **Install App** button or the browser's install option. On iPhone/iPad, open the site in Safari and choose **Share → Add to Home Screen**.
- The layout adapts for mobile screens. The offline screen is cached, but private shop records and PHP/API responses are intentionally not cached offline.
- Browser installation support varies by device/browser. External CDN assets and PDF export require an internet connection.
