# ZDSPGC Organization Management System

**Zamboanga del Sur Provincial Government College** — a web-based platform for
managing student organizations end to end: registration and accreditation,
membership and officers, events with QR attendance, projects and activity
proposals, documents, announcements, notifications, reports, printing and a full
audit trail.

Built with **PHP 8 + MySQL (Apache/XAMPP)**, plain HTML5, CSS3 and vanilla
JavaScript. No frameworks, no build step, no internet connection required at
runtime.

| Layer | Technology |
|---|---|
| Frontend | HTML5, CSS3 (custom design system), vanilla JavaScript, Chart.js (graceful offline fallback) |
| Backend | PHP 8.1+ (PDO, prepared statements only), REST-style JSON endpoints |
| Database | MySQL 5.7+ / MariaDB 10.4+, 26 normalized tables, InnoDB, utf8mb4 |
| QR codes | HMAC-SHA256 signed tokens, QR generation + camera scanning bundled |

---

## Table of contents

0. [Implementation status (read first)](#0-implementation-status-read-first)
1. [What the system does](#1-what-the-system-does)
2. [Requirements](#2-requirements)
3. [Installation on XAMPP (recommended)](#3-installation-on-xampp-recommended)
4. [Installation without XAMPP](#4-installation-without-xampp)
5. [Account creation and registration](#5-account-creation-and-registration)
6. [Project structure](#6-project-structure)
7. [Roles and permissions](#7-roles-and-permissions)
8. [Main workflows](#8-main-workflows)
9. [QR attendance and the ZDSPGC QR bridge](#9-qr-attendance-and-the-zdspgc-qr-bridge)
10. [Printing](#10-printing)
11. [Security](#11-security)
12. [Reports and CSV export](#12-reports-and-csv-export)
13. [Configuration reference](#13-configuration-reference)
14. [Troubleshooting](#14-troubleshooting)
15. [Maintenance and self-check](#15-maintenance-and-self-check)
16. [Integration roadmap](#16-integration-roadmap)

---

## 0. Implementation status (read first)

This build is **complete and verified underneath, partially built on top**. Be
aware of the difference before you demo it.

### Working and verified (live, against MariaDB)

| Area | State |
|---|---|
| Database | 26 tables, `schema.sql` + `seed.sql`, FK integrity verified, one-click `install.php` |
| Core framework | PDO wrapper, CSRF, bcrypt auth, sessions, remember-me tokens, RBAC + organization scoping, audit log, notifications, secure uploads, HMAC QR tokens |
| Data layer | 7 repositories (`AcademicRepo`, `OrgRepo`, `StudentRepo`, `MemberRepo`, `OfficerRepo`, `EventRepo`, `AttendanceRepo`) |
| UI kit | Role-aware sidebar, topbar, breadcrumbs, flashes, modals, toasts, stat cards, badges, filters, pagination, empty states, A4 print sheet |
| Design | Full white/green/dark-green design system, charts with an offline fallback |
| Pages | `index.php` (public landing), `login.php`, `logout.php`, `install.php`, `organizations.php` (directory with search + category/department/type filters), `organization.php` (public profile) |
| Shared | `notifications.php` (inbox, mark read), `account.php` (profile, avatar upload, password change), `scan.php` (QR check-in: camera, deep link, manual) |
| Dashboards | `admin/`, `adviser/`, `organization/` (officer) and `student/` — including the **working approve/reject organization workflow** |
| QR attendance | `api/attendance/scan.php` — accepts event tokens and student ID tokens, re-validates signature + nonce + window + duplicates server-side |

Verified with `php tools/self-check.php` (**ALL CHECKS PASSED**) and with real
HTTP requests: sign-in routes every demo account to the right dashboard, the
public landing renders live data, and approving a pending registration updates
the queue.

### Built but not yet reached by a menu link

The data layer, security and UI kit for these modules exist and are tested; the
pages themselves still have to be written (each one is the same
guard → query → render shape used by the dashboards that do exist):

- `admin/` — organizations, students, advisers, members, officers, events,
  projects, proposals, attendance, documents, announcements, calendar, reports,
  academic years, categories, departments, users, activity log, settings
- `adviser/` and `organization/` (officer workspace) dashboards and modules
- `student/` — events, my organizations, my registrations, my attendance,
  announcements, QR check-in
- Root pages: public directory/profile, registration, password reset, profile,
  notifications, search, secure download
- `api/` — JSON endpoints (folders exist, files pending)

Until those exist, the sidebar links for them return 404. Nothing is faked:
every page that exists performs real, authorized, database-backed work.


---

## 1. What the system does

**Office of Student Affairs (administrator)** — full control: organizations
(approve/reject/archive), academic years, categories, departments, students,
advisers, memberships, officers, events, projects, proposals, documents,
announcements, reports, settings and the system audit log. The dashboard shows
live totals, charts, pending approvals and recent activity.

**Advisers** — everything about the organizations assigned to them: monitor
status, review officers and members, endorse events and proposals, verify
documents, post announcements, read attendance and print reports. They can never
change system-wide settings.

**Organization officers** — a private workspace for their organization: profile,
membership applications, officers, events (with QR poster and attendance),
attendance statistics, projects, proposals, documents, announcements, calendar and
reports.

**Students** — browse the public organization directory, apply for membership,
register for events, check in by scanning the event QR code, see their own
attendance history and read announcements. Students only ever see their own
personal data.

### Module coverage

| Module | Highlights |
|---|---|
| Authentication | Sign-in by e-mail/username/Student ID, bcrypt, sessions, remember-me tokens, CSRF, rate limiting, account activation |
| Students | Profile, search, filters, CSV import/export, archive, print, profile picture |
| Organizations | Registration workflow, approval, accreditation workflow, categories, departments, logo, constitution |
| Membership | Applications, approve/reject, positions, history per academic year, export |
| Officers | Assign/change/end positions, terms, history, printable officer list |
| Events | Draft → adviser → administrator → approved → attendance → post-activity report |
| Attendance | Event QR + student QR, present/late/excused/absent, trends, CSV, print sheet |
| Projects & proposals | Projects, proposals with reviewer comments, revision history, decisions |
| Documents | Upload/replace/version/archive, verification, private downloads, type checklist |
| Announcements | Audience targeting (all / organization / department / officers / members) |
| Notifications | In-app inbox with unread count, generated by every workflow decision |
| Calendar | Monthly grid of events and deadlines for each role |
| Reports | 9 report sets with filters, print layout and CSV export |
| Audit log | Every sign-in, approval, upload and deletion, filterable by user/module/action/date |

---

## 2. Requirements

| Item | Version |
|---|---|
| PHP | 8.1 or newer (8.2 recommended) with `pdo_mysql`, `mbstring`, `fileinfo` |
| MySQL / MariaDB | 5.7+ / 10.4+ |
| Web server | Apache 2.4 (XAMPP) — the app also runs on `php -S` |
| Browser | Any current Chrome, Edge, Firefox or Safari (camera needed for QR scanning) |
| PHP limits | `upload_max_filesize` and `post_max_size` ≥ `UPLOAD_MAX_MB` (default 8 MB) |

---

## 3. Installation on XAMPP (recommended)

### Step 1 — install XAMPP

1. Download XAMPP for Windows from <https://www.apachefriends.org> and install it

### Step 4 — configure the database credentials

`config/database.php` already contains the XAMPP defaults:

```php
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'zdspgc_orgsys');
define('DB_USER', 'root');
define('DB_PASS', '');
```

If your setup differs (a second MySQL instance on port 3307, or a root
password), **do not edit this file on a server**. Copy
`config/database.local.example.php` to `config/database.local.php`, change the
values there — it is loaded automatically:

```php
define('DB_PORT', '3307');
```

> Keep `config/database.local.php` UTF-8 **without** a byte-order mark (BOM).

### Step 5 — install through the browser

Open <http://localhost/zdspgc-organization-system/install.php>

The page shows the connection status and offers two buttons:

* **Install database** — creates the database (if missing), applies the schema
  and loads the demo data the first time.
* **Rebuild from scratch** — drops every table and reloads schema + demo data
  (destructive; it asks for confirmation).

When the status turns green, continue to the sign-in page.

### Step 6 — open the system

| Purpose | Address |
|---|---|
| Public directory (no login) | <http://localhost/zdspgc-organization-system/> |
| Sign in | `…/login.php` |
| Administrator dashboard | `…/admin/dashboard.php` (after signing in) |
| Student dashboard | `…/student/dashboard.php` |

### Step 7 — before going live

1. Change `APP_SECRET` in `config/config.php` to a long random string (this
   instantly voids every QR code printed so far).
2. Set `APP_ENV` to `'production'` (hides PHP errors from visitors).
3. Set `DEMO_MODE` to `false` (the installer then requires an administrator
   sign-in), or simply delete `install.php`.
4. Delete `config/database.local.php` if it is not needed.
5. Change the demo passwords listed in the next section.

---

## 4. Installation without XAMPP

Any Apache + PHP + MySQL stack works, as does the PHP built-in server:

```bash
cd zdspgc-organization-system
php -S localhost:8080
```

Then open <http://localhost:8080/install.php> and press **Install database**.

---

## 5. Account Creation and Registration

The system starts cleanly without predefined demo accounts.
- **Administrator Setup:** Created during initial setup via `install.php` or by existing administrators in `admin/users.php`.
- **Student Registration:** Students self-register through `register.php` with their student ID, department, course, and institutional email.
- **Advisers & Officers:** Managed and assigned through the Admin portal (`admin/users.php`, `admin/advisers.php`, and `admin/officers.php`).

---

## 6. Project structure

```
zdspgc-organization-system/
├── index.php                 Public landing + live counters
├── organizations.php         Public organization directory (search + filters)
├── organization.php          Public organization profile
├── login.php  register.php  logout.php
├── forgot-password.php  reset-password.php
├── account.php               Profile, avatar, change password (all roles)
├── notifications.php  search.php  scan.php  download.php
├── switch-organization.php  install.php
│
├── admin/ adviser/ organization/ student/     Role areas
├── api/                      JSON endpoints: auth, students, organizations,
│                             members, officers, events, attendance, projects,
│                             proposals, documents, announcements,
│                             notifications, reports
├── assets/
│   ├── css/style.css         Design system (white + campus green)
│   ├── js/app.js             Toasts, modals, confirmations, AJAX helper
│   ├── js/charts.js          Chart.js wiring + offline fallback
│   ├── js/qr.js  js/scanner.js
│   ├── js/vendor/            qrcode-generator.js, html5-qrcode.min.js
│   └── img/logo.png          ZDSPGC seal
├── config/
│   ├── config.php            Branding, security, uploads, attendance rules
│   ├── database.php          Database credentials (XAMPP defaults)
│   └── database.local.example.php   Optional per-machine override
├── includes/
│   ├── bootstrap.php         The single entry point of every page
│   ├── Database.php          PDO wrapper (bound parameters only)
│   ├── Helpers.php           Escaping, input, URLs, dates, formatting
│   ├── Security.php          CSRF, rate limits, upload validation, audit hook
│   ├── Auth.php              Sessions, sign-in, remember-me, guards
│   ├── Permissions.php       Capability matrix + organization scoping
│   ├── Audit.php  Notifications.php  Uploads.php  Qr.php  Chart.php
│   ├── Schema.php            Install, migration, seeding
│   ├── icons.php             Inline SVG icon set
│   ├── layout/               header.php, sidebar.php, footer.php, ui.php
│   └── models/               Data access per module
├── database/
│   ├── schema.sql            26 tables with keys and indexes
│   └── seed.sql              Demo data
├── tools/self-check.php      Command-line verification of the installation
├── uploads/                  profiles · organizations · events · documents · reports
├── .htaccess                 Blocks includes/, config/, database/, private uploads
└── README.md
```

---

## 7. Roles and permissions

| Capability | Admin | Adviser | Officer | Student |
|---|:--:|:--:|:--:|:--:|
| Manage users, settings, academic years, categories, departments | ✔ | – | – | – |
| Manage all organizations / approve / archive | ✔ | – | – | – |
| View organization information | ✔ | assigned | own | public |

---

## 8. Main workflows

### Organization registration

```
Officer/student  →  register the organization + upload documents
                →  status = pending  (administrators are notified)
Administrator    →  reviews the checklist of required documents
                →  approve  →  status = active, adviser assigned
                →  reject   →  status = rejected, with a reason
```

Required document types are configurable in **Settings → Categories**
(`required_document_types`), so the Office of Student Affairs can add or remove
checklist items without touching code.

### Accreditation

```
Organization  →  submits an accreditation application + documents
              →  adviser verifies  →  administrator decides
              →  approved (valid N years) · revision required · rejected
```

`organizations.accreditation_status` always mirrors the latest decision and the
expiry date is calculated from the *Accreditation validity* setting.

### Membership

```
Student       →  applies (membership = pending)
Organization  →  approves → membership = active, joined_at set
              →  rejects  → membership = rejected, with remarks
```

Memberships are unique per organization + student + academic year, so changing
the active academic year never destroys history.

### Events and attendance

```
Officer       →  creates the event (draft) → submits for approval
Adviser       →  endorses → status = pending administrator
Administrator →  approves → published, students may register
              →  event day: students scan the event QR (or officers scan
                 student QR IDs)
              →  officer submits the post-activity report
              →  administrator marks the event completed
```

### Projects and proposals

```
Officer  →  creates a project and submits its proposal
Adviser  →  endorses with comments
Admin    →  approves · requests a revision · rejects
Officer  →  resubmits (version + 1, previous comments kept)
Officer  →  implements the activity and files the post-activity report
```

### Archiving instead of deleting

Important records are never destroyed: organizations, memberships, officers,
events, projects and documents move to an *archived* / *ended* / *closed* status
and stay searchable in reports and the audit log.

---

## 9. QR attendance and the ZDSPGC QR bridge

### How a check-in works

```
Officer    →  Events → open the event → the QR poster is generated
Student    →  scans the poster with the phone camera (or the in-app scanner)
           →  the signed token arrives at scan.php
System     →  verifies the HMAC signature
           →  looks the event up and compares the stored nonce
           →  checks the event window (too early / too late is refused)
           →  checks the duplicate-attendance unique index
           →  decides present or late SERVER-SIDE from the grace period
           →  stores the record and shows a confirmation
```

### The token format

```
payload = 1|E|<event_code>|<nonce>      event poster QR
payload = 1|S|<student_id>|<nonce>      student attendance ID QR
token   = base64url(payload) + "." + base64url(HMAC-SHA256(payload)[0..15])
```

* The nonce lives in the database (`events.qr_nonce` / `students.qr_nonce`), so
  **re-issuing a QR code instantly voids every old printout**.
* The signature is compared with `hash_equals()` (constant time) and a forged or
  edited code is rejected.
* **No database id, e-mail or name is ever exposed inside a QR code** — only an
  opaque signed payload.

### Sharing codes with the existing ZDSPGC Event QR Attendance System

This module was designed to sit next to the standalone *"ZDSPGC Event QR
Attendance"* project: both use the same token format, and each event carries a
stable `event_code`. To let the two systems verify each other's codes:

1. Give both systems the **same `APP_SECRET`** in their `config` files.
2. Keep `INTEGRATE_ATTENDANCE_SHARE = true` in `config/config.php`.
3. `api/attendance/scan.php` already accepts either a student token or an event

---

## 11. Security

| Area | Implementation |
|---|---|
| Passwords | `password_hash()` / `password_verify()` (bcrypt). Plaintext is never stored or logged. |
| Sessions | HttpOnly, SameSite=Lax, `Secure` on HTTPS, idle timeout (`SESSION_IDLE_MINUTES`, default 60), ID regenerated on sign-in, remember-me tokens with a hashed validator that rotates on every use. |
| SQL injection | Every query goes through `Database::` with bound parameters; dynamic `ORDER BY`/`LIMIT` are cast to integers. |
| XSS | All output escaped with `Helpers::e()`; rich text is escaped before line breaks are added. |
| CSRF | `Security::csrfField()` in every form, `Security::requireCsrf()` on every state change; the API also accepts the `X-CSRF-Token` header. |
| Authorization | Role capability matrix + organization scoping, checked on every page and endpoint. |
| Uploads | Extension **and** real MIME type (finfo) must match an allowlist, size limited, randomised file name, stored outside any script path, private documents served only through `download.php` after an ownership check. |
| Login protection | Session rate limit + lockout after 5 failed attempts, constant-time password verification, no user enumeration. |
| Check-in protection | Rate limit on the scan API, unique `(event_id, student_id)` index, server-side window and grace calculation. |
| Audit | Append-only `activity_logs` with actor, role, action, module, description, IP and user agent. |
| Direct access | `.htaccess` denies `includes/`, `config/`, `database/`, private uploads and hidden folders; script execution is blocked inside the upload folders. |
| Headers | `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Cross-Origin-Opener-Policy`. |

Before production: change `APP_SECRET`, set `APP_ENV = 'production'`, disable
`DEMO_MODE`, delete `install.php` and `config/database.local.php`, and serve the
site over HTTPS (required for camera access on phones).

---

## 12. Reports and CSV export

Administrators: organization directory, active organizations, archived
organizations, student membership, officers, events, attendance, activities and
accreditation. Advisers and officers receive the same reports scoped to the
organizations they may manage.

Every report supports academic-year, organization and date filters, an A4 print
layout and a CSV export that is UTF-8 with a BOM (so Excel opens it correctly)
and is protected against CSV formula injection through `Security::csvSafe()`.

---

## 13. Configuration reference

`config/config.php` (the values most often changed):

| Constant | Default | Meaning |
|---|---|---|
| `APP_ENV` | `local` | `production` hides PHP errors |
| `APP_TIMEZONE` | `Asia/Manila` | All dates/times |
| `APP_SECRET` | change me | HMAC key for QR tokens and remember-me |
| `PUBLIC_BASE_URL` | empty | Absolute address used inside QR links |
| `SESSION_IDLE_MINUTES` | `60` | Automatic sign-out after inactivity |
| `LOGIN_MAX_ATTEMPTS` / `LOGIN_LOCKOUT_MINUTES` | `5` / `5` | Login protection |
| `UPLOAD_MAX_MB` | `8` | Maximum upload size |
| `ALLOWED_UPLOADS` / `ALLOWED_IMAGES` | PDF, DOC(X), XLS(X), JPG, PNG, CSV / JPG, PNG, GIF, WEBP | Upload allowlists |
| `DEFAULT_GRACE_MINUTES` | `15` | After the start time a scan is *late*, not *present* |
| `EARLY_CHECKIN_MINUTES` / `LATE_CHECKIN_HOURS` | `60` / `6` | Accepted check-in window |
| `INTEGRATE_ATTENDANCE_SHARE` | `true` | Accept tokens from the standalone QR attendance system |
| `DEMO_MODE` | `true` | Show demo credentials and allow a fresh install |

Runtime settings (editable in **Settings** without touching code): system name,
contact e-mail, maximum upload size, accreditation validity in years, whether

---

## 14. Troubleshooting

| Symptom | Fix |
|---|---|
| "The database is not installed yet" | Open `install.php` and press **Install database**. |
| *Connection refused* / *Access denied for user* | MySQL is not running, or the credentials are wrong. Check the XAMPP MySQL row and `config/database.local.php`. |
| Port 80 already in use | Change `Listen 80` to `Listen 8080` in `httpd.conf` and browse `http://localhost:8080/`. |
| Second MySQL instance on 3307 | Set `DB_PORT` to `3307` in `config/database.local.php`. |
| *strict_types declaration must be the very first statement* | `config/database.local.php` was saved with a UTF-8 BOM. Re-save it as UTF-8 **without** BOM. |
| Uploads fail silently | Raise `upload_max_filesize` and `post_max_size` in `php.ini`, then restart Apache. |
| Camera scanning does not start | The browser needs **HTTPS** (or `localhost`) plus camera permission; the manual Student-ID entry always works as a fallback. |
| A page says "not allowed" | The account's role lacks that capability — see the matrix in section 7. |
| Charts are empty | The page is offline and Chart.js could not load; the same numbers are then drawn as bars by `assets/js/charts.js`. |
| Want to start over | `install.php` → **Rebuild from scratch**, or run `php tools/self-check.php`. |

---

## 15. Maintenance and self-check

```bash
php tools/self-check.php
```

It rebuilds the schema and demo data, then verifies the table count, the seed
contents, the FK-sensitive joins, the QR token sign/verify round trip, tamper
rejection and the upload/path-validation helpers. **Never run it against a
production database** — it drops every table.

Syntax-checking a single file:

```bash
php -l admin/events.php
```

---

## 16. Integration roadmap

The system is modular on purpose, so the other ZDSPGC platforms can reuse it:

* **QR attendance system** — same token format and shared `APP_SECRET`
  (section 9); `api/attendance/scan.php` is the stable entry point.
* **Student Information System** — `students.student_id` is the stable key;
  `api/students/search.php` resolves a student by ID.
* **Library / Event / Voting systems** — `organizations.organization_code`,
  `organization_members` and `event_registrations` are the integration surface.
* **Notifications** — announcements and the inbox are role- and audience-aware,
  so an e-mail or SMS gateway can subscribe to `Notifications::push()`.
* **Public directory** — the public pages and `api/organizations/list.php` work
  without a session, so they can be embedded on the college website.

---

## 17. Development conventions

The codebase follows a few rules that keep ~90 pages consistent and safe:

1. Every page starts with `require_once …/includes/bootstrap.php;` and ends with
   `require …/includes/layout/footer.php;`.
2. Guard first (`Auth::requireRole()` / `Permissions::requireCapability()` /
   `Permissions::requireOrganization()`), then read, then render.
3. All data access lives in `includes/models/*Repo.php`; pages never build SQL.
4. All output goes through `Helpers::e()`; all inputs are trimmed with
   `Helpers::get()/post()/input()`.
5. Every state change: CSRF → validate → write → `Security::audit()` →
   `Notifications::…` → flash + redirect.
6. Reusable markup lives in `includes/layout/ui.php`
   (`ui_stat`, `ui_status_badge`, `ui_empty`, `ui_pagination`, `ui_input`, …) so
   pages stay short and consistent.

---

*Built for ZDSPGC · PHP 8 · MySQL · no build step, no framework, no internet
required at runtime.*

organization registration is open, the attendance grace period and maintenance
mode.

   token, so the same scanner screen works for both.

Attendance rows can be read by other ZDSPGC systems through
`api/reports/summary.php` (`format=json`) or the plain SQL tables — the schema
uses stable, documented column names for that reason.

### Verifying the device (and what is *not* used)

`attendance` stores the verification method, the timestamp and the IP address.
The IP is stored **for the audit trail only** — it is never used to identify a
student, because many students legitimately share one campus network. Identity
comes from the signed-in account plus the QR nonce.

---

## 10. Printing

Every report page supports `&print=1`, which switches the layout to a clean A4
sheet with:

* the ZDSPGC seal, institution name and the generating office,
* the document title, the date generated and the user who prepared it,
* the table itself (page-break-safe, no navigation, no buttons),
* a **Prepared by / Reviewed by / Approved by** signature block.

Available print layouts: organization profile, officer list, member list, event
attendance sheet, accreditation record, activity/project reports, student list
and the organization directory. The same pages offer **CSV export** for Excel.

| Edit the organization profile | ✔ | – | ✔ | – |
| Manage members and applications | ✔ | review | ✔ | apply |
| Manage officers | ✔ | review | ✔ | – |
| Create / manage events | ✔ | – | ✔ | – |
| Approve events | final | endorse | – | – |
| Register for events | – | – | – | ✔ |
| Record attendance / generate QR | ✔ | ✔ | ✔ | – |
| Scan the event QR to check in | – | – | – | ✔ |
| Submit projects and proposals | ✔ | – | ✔ | – |
| Review / decide proposals | ✔ | endorse | – | – |
| Upload / verify documents | ✔ | verify | upload | – |
| Post announcements | any audience | ✔ | own org | – |
| View reports | ✔ | assigned | own org | – |
| Activity log / system settings | ✔ | – | – | – |
| Download private documents | ✔ | assigned | own org | public only |

The matrix lives once in `includes/Permissions.php` and is enforced on **every**
page and API call through `Permissions::requireCapability()` (web) and
`Permissions::apiRequire()` (JSON). Organization scoping uses
`Permissions::managedOrganizationIds()`, so an officer can never touch another
organization's records even by editing the URL.


| Role | Sign in with | Password | Lands on |
|---|---|---|---|
| System Administrator | `admin` | `Admin@2026` | `admin/dashboard.php` |
| Organization Adviser | `adviser.mrsarmiento` | `Adviser@2026` | `adviser/dashboard.php` |
| Organization Officer (Supreme Student Council) | `juan.delosreyes` | `Officer@2026` | `organization/dashboard.php` |
| Organization Officer (SITE) | `ana.bautista` | `Officer@2026` | `organization/dashboard.php` |
| Student / Member | `student` | `Student@2026` | `student/dashboard.php` |

Other seeded accounts (same password as their group): `adviser.rlim`,
`adviser.gtolentino` (advisers) and the officers `kevin.salazar`,
`lorna.quijano`, `mark.rivera`, `rafael.mercado`, `dexter.padilla`.

The sign-in field also accepts the **Student ID** of any seeded student (for
example `2024-00412` with `Student@2026`).

### What the demo data contains

* 1 administrator, 3 advisers, 7 officer accounts, 20 students
* 8 organizations (5 active/accredited, 1 pending registration, 1 suspended,
  1 archived) across 6 categories and 5 departments
* 52 membership records for AY 2026–2027 plus archived AY 2025–2026 history
* 29 officer appointments (current and previous academic year)
* 5 accreditation applications (approved / under review)
* 10 events (approved, completed, pending adviser, pending admin, draft, rejected)
* 60 event registrations and 25 attendance records (present, late, excused,
  absent, with four different verification methods)
* 4 projects with proposals and a full review/comment history
* 16 document records (the demo PDFs are generated into `uploads/documents/`)
* 8 announcements, 12 notifications and 19 audit-log entries

   (default folder `C:\xampp`).
2. Open the **XAMPP Control Panel** and press **Start** next to **Apache** and
   **MySQL** — both rows must turn green. If Apache will not start, port 80 is
   taken: change `Listen 80` to `Listen 8080` in
   `C:\xampp\apache\conf\httpd.conf` and use `http://localhost:8080/…` below.

### Step 2 — copy the project

Copy the whole folder into the XAMPP web root:

```
C:\xampp\htdocs\zdspgc-organization-system
```

### Step 3 — create the database

*Fastest way:* skip to step 5 and press **Install database** on `install.php` —
it creates the database, the tables and the demo data for you.

*Manual way:*

1. Open <http://localhost/phpmyadmin>
2. Click **New**, name the database `zdspgc_orgsys`, collation
   `utf8mb4_unicode_ci`, press **Create**.
3. Select it, open the **Import** tab, choose `database/schema.sql`, press
   **Go** (creates all 26 tables).
4. Import `database/seed.sql` the same way (loads the demo school data).
