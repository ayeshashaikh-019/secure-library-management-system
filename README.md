# Secure Library Management System

A responsive, role-based Library Management System built with the technology stack in the project proposal: HTML5, CSS3, JavaScript, Bootstrap, PHP, MySQL, XAMPP and Visual Studio Code.

## Main modules

- **Student:** register, sign in, search by title/author/ISBN/category, check stock, request a book, view borrowing history/current loans, and request a return.
- **Librarian:** add/update/delete eligible book records, review and approve/reject student requests, issue a book directly to a student, record physical returns, and view transactions.
- **Administrator:** create accounts, change user roles/status, reset passwords, configure the loan limit and loan duration, monitor activity, view inventory/borrowing reports, and export borrowing transactions to CSV.
- **Security:** `password_hash()` / `password_verify()`, prepared PDO statements, role checks on the server, CSRF tokens on POST operations, regenerated session ID at login, HTTP-only/SameSite session cookies, basic login-attempt throttling, input validation, database foreign keys, transactional stock updates, and audit logging.

## Requirements

- Windows, macOS or Linux
- XAMPP (Apache + MySQL/MariaDB + PHP 8.1 or newer recommended)
- Visual Studio Code
- A modern browser
- Internet connection for the Bootstrap, Bootstrap Icons, Google Fonts and Chart.js CDN assets. The app itself runs locally.

## Install with XAMPP

1. Install and open XAMPP.
2. Start **Apache** and **MySQL** from the XAMPP Control Panel.
3. Copy the entire `secure-library` folder into XAMPP's `htdocs` folder. On Windows the typical location is `C:\xampp\htdocs\secure-library`.
4. Open `http://localhost/phpmyadmin` in your browser.
5. Choose **Import**, select this project's `database.sql`, then click **Import** / **Go**. The script creates the `secure_library` database and its tables, categories, demo users and sample books. **Important:** this is an initial-install script; importing it again drops and recreates the project tables, so export/backup your database first if it contains work you want to keep.
6. Open the project folder in Visual Studio Code: **File → Open Folder → secure-library**.
7. Open `config.php`. The default XAMPP MySQL settings are `127.0.0.1`, database `secure_library`, user `root`, and an empty password. Change `$dbUser` / `$dbPass` if your local MySQL configuration is different.
8. Visit `http://localhost/secure-library` in your browser.

## Demo accounts

| Role | Email | Password |
|---|---|---|
| Administrator | `admin@library.local` | `Admin@123` |
| Librarian | `librarian@library.local` | `Librarian@123` |
| Student | `student@library.local` | `Student@123` |

These credentials are for local academic demonstration only. Change the demo passwords before using the system beyond a private local environment. Admin can reset them from **User management**. Students can also register their own accounts from the sign-in page; public registration always creates a Student account.

## Typical demonstration flow

1. Sign in as the Student and search the catalogue.
2. Request an available book. The request will be marked **Pending**.
3. Sign out and sign in as the Librarian.
4. Open **Transactions**, approve the request, and observe that available copies decrease.
5. Sign back in as the Student and submit a return request from **My borrowings**.
6. Sign in as the Librarian and choose **Record return**. The return date/status is saved and available copies increase.
7. Sign in as the Admin to create accounts, change roles/status, view reports, and export the borrowing CSV.

## Project rules / configurable defaults

- The demo starts with a limit of **3** pending or active loans per student and a **14-day** loan period.
- These are example rules because the proposal does not specify a numeric loan limit or duration. An administrator can change both from **System settings** after signing in.
- A student requests a book; the librarian approves the request and records the issuance. A student may request a return, but the librarian records the actual physical return. This follows the proposal's student workflow and librarian return-recording requirement.
- A book with borrowing history cannot be hard-deleted. This protects transaction history and referential integrity. Such a record should be retained instead of deleting historical data.
- Payment gateways, e-book reading, mobile applications, barcode/RFID and external library network integrations are not included, as stated in the proposal's out-of-scope section.

## Folder structure

```text
secure-library/
├── assets/
│   ├── css/style.css
│   └── js/app.js
├── config.php
├── database.sql
├── dashboard.php
├── books.php
├── transactions.php
├── users.php
├── reports.php
├── settings.php
├── login.php
├── register.php
├── logout.php
├── index.php
├── includes/
│   ├── header.php
│   └── footer.php
└── README.md
```

## Troubleshooting

- **Database connection failed:** confirm MySQL is running, database `secure_library` exists, and the username/password in `config.php` match your XAMPP MySQL configuration.
- **404 Not Found:** confirm the folder is inside `htdocs` and the address is `http://localhost/secure-library`.
- **Blank page / PHP error:** check `xampp/php/logs/php_error_log` or the Apache error log. Also verify PHP is 8.1+.
- **Styles not showing:** check the browser connection for CDN access and confirm `assets/css/style.css` exists under the project folder.
- **Changes do not appear:** save the file, reload the browser, and hard-refresh with `Ctrl + F5`.

## Security note

This is a teaching/demo application, not a production-hardened institutional deployment. Before public deployment, use HTTPS, set a strong database account instead of MySQL root, remove or rotate demo credentials, configure backups, restrict server/database access, add institutional password and retention policies, and perform a full security review. Never expose phpMyAdmin or development errors to the public internet.
