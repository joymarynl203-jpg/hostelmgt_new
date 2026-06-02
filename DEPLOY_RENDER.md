# Deploying HMS on Render

Render failed with `Dockerfile: no such file or directory` because the repo had no Docker setup. This project now includes a **Dockerfile** for a PHP + Apache web service.

## Important: database

**Render does not provide MySQL.** You must use an external MySQL host, for example:

- [PlanetScale](https://planetscale.com/) (MySQL-compatible)
- [Aiven](https://aiven.io/) free trial
- [Railway](https://railway.app/) MySQL plugin
- Any university or cloud MySQL instance

Import the schema once:

1. In your MySQL provider, create a database (e.g. `hms_db`).
2. Import `HMS/database/all_in_one_import.sql` (or `schema.sql` plus migrations).

## 1. Push the Dockerfile to GitHub

Commit and push these new files:

- `Dockerfile`
- `docker/`
- `render.yaml`
- `HMS/lib/env_config.php` (env-based config)

```bash
git add Dockerfile docker render.yaml HMS/lib/env_config.php HMS/config.php hms_superadmin/lib/sa_bootstrap.php DEPLOY_RENDER.md .dockerignore
git commit -m "Add Docker and Render deployment support"
git push origin main
```

## 2. Create the Render web service

1. [Render Dashboard](https://dashboard.render.com/) → **New** → **Web Service**.
2. Connect repo `joymarynl203-jpg/hostelmgt_new`.
3. **Runtime:** Docker (Render will detect the `Dockerfile`).
4. **Root directory:** leave blank (repo root).
5. **Instance type:** Free (or paid for always-on).

Or use the blueprint: **New** → **Blueprint** → point at `render.yaml`.

## 3. Environment variables

Set these in **Environment** for the web service:

| Variable | Example | Required |
|----------|---------|----------|
| `HMS_DB_HOST` | `aws.connect.psdb.cloud` | Yes |
| `HMS_DB_NAME` | `hms_db` | Yes |
| `HMS_DB_USER` | your user | Yes |
| `HMS_DB_PASS` | your password | Yes |
| `HMS_BASE_URL` | `/` | Yes (Docker default) |
| `SA_BASE_URL` | `/superadmin/` | Yes (super admin URLs) |
| `HMS_APP_URL` | `https://hostelmgt-new.onrender.com` | Yes for Pesapal |
| `HMS_DEMO_SETUP_KEY` | *(empty)* | Recommended in production |
| `HMS_PESAPAL_*` | from Pesapal dashboard | For payments |

`HMS_APP_URL` can be omitted if you rely on Render’s auto `RENDER_EXTERNAL_URL` (set after first deploy; use your real `https://….onrender.com` URL).

**Pesapal callbacks** must use your public URL, for example:

- `https://YOUR_SERVICE.onrender.com/payment_callback.php`
- `https://YOUR_SERVICE.onrender.com/payment_ipn.php`

## 4. URLs after deploy

| App | URL |
|-----|-----|
| Main HMS | `https://YOUR_SERVICE.onrender.com/` |
| Login | `https://YOUR_SERVICE.onrender.com/login.php` |
| Super Admin | `https://YOUR_SERVICE.onrender.com/superadmin/login.php` |

## 5. Free tier notes

- Service **spins down** after inactivity; first request may take 30–60 seconds.
- **Disk is ephemeral** — user uploads in `HMS/public/uploads/` may be lost on redeploy. For production, plan object storage (S3, etc.) later.
- Use a **paid** plan if you need always-on for Pesapal IPN reliability.

## 6. Troubleshooting

| Issue | Fix |
|-------|-----|
| `Dockerfile: no such file` | Push the new `Dockerfile` to `main` and redeploy. |
| Database connection failed | Check `HMS_DB_*` and that your MySQL host allows connections from Render (IP allowlist / “allow external”). |
| CSS/links broken | Set `HMS_BASE_URL` to `/` exactly (leading and trailing slash as documented). |
| Super admin 404 | Set `SA_BASE_URL` to `/superadmin/` and use `/superadmin/login.php`. |
| Pesapal fails | Set `HMS_APP_URL` to your HTTPS Render URL; register IPN URL in Pesapal. |

## Local Docker test (optional)

```bash
docker build -t hostelmgt .
docker run --rm -p 8080:8080 \
  -e PORT=8080 \
  -e HMS_DB_HOST=host.docker.internal \
  -e HMS_DB_NAME=hms_db \
  -e HMS_DB_USER=root \
  -e HMS_DB_PASS= \
  -e HMS_BASE_URL=/ \
  -e SA_BASE_URL=/superadmin/ \
  hostelmgt
```

Open http://localhost:8080/
