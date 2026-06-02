# Deploying HMS on Render (with PostgreSQL)

This app supports **Render PostgreSQL** (recommended) and external **MySQL** for local XAMPP.

## Quick setup: Render Postgres

### 1. Create PostgreSQL on Render

1. Dashboard → **New** → **PostgreSQL** (or use `render.yaml` Blueprint).
2. Note the database name (e.g. `hostelmgt`).

### 2. Import schema

In Render → your Postgres → **Connect** → copy **External Database URL**, then run locally:

```bash
psql "YOUR_EXTERNAL_DATABASE_URL" -f HMS/database/schema.postgresql.sql
```

Or use Render’s **PSQL** shell from the database page and paste the contents of `schema.postgresql.sql`.

### 3. Web service environment

If you link the database to the web service, Render sets **`DATABASE_URL`** automatically. You only need:

| Variable | Value |
|----------|--------|
| `DATABASE_URL` | *(auto from linked Postgres)* |
| `HMS_DB_DRIVER` | `pgsql` |
| `HMS_BASE_URL` | `/` |
| `SA_BASE_URL` | `/superadmin/` |
| `HMS_APP_URL` | `https://hostelmgt-new.onrender.com` |
| `HMS_DEMO_SETUP_KEY` | *(empty)* |

You do **not** need `HMS_DB_HOST`, `HMS_DB_USER`, etc. when `DATABASE_URL` is set.

### 4. Deploy Docker web service

- Connect GitHub repo
- **Runtime:** Docker
- Link the PostgreSQL instance (Environment → Link Database)

Push includes `Dockerfile` with `pdo_pgsql`.

---

## Manual env vars (without DATABASE_URL)

| Variable | Example |
|----------|---------|
| `HMS_DB_DRIVER` | `pgsql` |
| `HMS_DB_HOST` | `dpg-xxxxx-a.oregon-postgres.render.com` |
| `HMS_DB_PORT` | `5432` |
| `HMS_DB_NAME` | `hostelmgt` |
| `HMS_DB_USER` | `hostelmgt_user` |
| `HMS_DB_PASS` | *(from Render dashboard)* |
| `HMS_DB_SSLMODE` | `require` |

---

## URLs after deploy

| App | URL |
|-----|-----|
| Main HMS | `https://YOUR_SERVICE.onrender.com/` |
| Super Admin | `https://YOUR_SERVICE.onrender.com/superadmin/login.php` |

---

## Local XAMPP (MySQL)

Keep using `HMS_DB_DRIVER=mysql` (default) and import `HMS/database/all_in_one_import.sql`.

---

## Troubleshooting

| Issue | Fix |
|-------|-----|
| Database connection failed | Link Postgres to web service or set `DATABASE_URL`. Set `HMS_DB_DRIVER=pgsql`. |
| Relation "users" does not exist | Run `schema.postgresql.sql` on the database. |
| CSRF failed | Redeploy latest code (session path fix). |
| Debug DB errors | Set `HMS_DEBUG=1` temporarily, redeploy, read message, remove. |
