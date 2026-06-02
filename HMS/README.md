## Enhanced Web-Based Hostel Management System (HMS)

This project implements an **enhanced web-based Hostel Management System** for optimising hostel operations around Ugandan universities and secondary schools, based on the proposal by **Namirembe Joymary Lwanga** and **Kulabako Shamirah**.

### Features
- **Role-based authentication** for Students, Hostel Administrators/Wardens, and University Management.
- **Hostel & room management**: hostels, room types, occupancy and allocation tracking.
- **Student self-service**: online room browsing and booking requests.
- **Real Pesapal integration**: student can pay from booking page, callback and IPN verification update payment status.
- **Dashboards & reports**: basic occupancy and payments overview.

### Tech Stack
- **Backend**: PHP (compatible with XAMPP)
- **Database**: MySQL
- **Frontend**: HTML5, CSS3, JavaScript, Bootstrap 5

### Getting Started
1. Create a MySQL database, e.g. `hms_db`.
2. Import the SQL schema from `database/schema.sql`.
3. Update database credentials in `config.php`.
4. Configure Pesapal keys in `config.php`:
   - `HMS_PESAPAL_ENV`
   - `HMS_PESAPAL_CONSUMER_KEY`
   - `HMS_PESAPAL_CONSUMER_SECRET`
   - `HMS_APP_URL` (must be publicly reachable for callback/IPN in production)
5. Start Apache and MySQL via XAMPP.
6. Visit `http://localhost/HMS/public/` in your browser.

### Test Seed (Recommended)
**Option A — browser (easiest):** open once in your browser (key must match `HMS_DEMO_SETUP_KEY` in `config.php`, default below):

`http://localhost/HMS/public/seed_demo.php?key=hms-demo-setup-2026`

Then go to Login and use the accounts shown on that page.

**Option B — command line:**

`C:\xampp\php\php.exe c:\xampp\htdocs\HMS\scripts\seed.php`

Default demo accounts (reset every time you run seed):
- University Admin: `university.admin@hms.local` / `Admin12345`
- Warden: `warden@hms.local` / `Warden12345`

If login still fails: confirm MySQL database name/user/password in `config.php` match phpMyAdmin, and that you imported `database/schema.sql` into that database.

### Pesapal Payment Flow
1. Student opens `My Bookings`.
2. For approved or checked-in bookings with outstanding balances, click **Pay with Pesapal**.
3. System creates a pending payment row, registers an IPN URL with Pesapal, and redirects to checkout.
4. `payment_callback.php` and `payment_ipn.php` verify transaction status from Pesapal and update local records.

### Alignment with Proposal
- Replaces **manual, paper-based hostel processes** with a **centralised web system**.
- Supports **real-time visibility** into occupancy and payments.
- Designed to later integrate with **local payment channels** (MTN Mobile Money, Airtel Money via Pesapal).

