# Portfolio

Personal portfolio website built with vanilla HTML/CSS/JS on the frontend and a PHP REST API backend, backed by MySQL.

## Stack

| Layer     | Technology                        |
|-----------|-----------------------------------|
| Frontend  | HTML, CSS, JavaScript (vanilla)   |
| Backend   | PHP (REST API)                    |
| Database  | MySQL                             |
| Mail      | PHPMailer (SMTP)                  |
| CAPTCHA   | Altcha (self-hosted, HMAC-based)  |
| i18n      | JSON translation files (fr / en)  |

## Project structure

```
portfolio/
├── bdd/                  # Database scripts
│   ├── schema.sql        # Table definitions
│   ├── seed.sql          # Initial data
│   ├── migrations/       # Incremental migrations
│   ├── create_db.sh      # Create the database
│   ├── apply.sh          # Apply schema + seed
│   └── migrate.sh        # Run all pending migrations
├── site/
│   ├── index.html        # Public portfolio page
│   ├── admin/            # Admin panel (login, dashboard, change password)
│   ├── api/              # PHP REST endpoints
│   │   ├── auth.php      # Login / session
│   │   ├── auth_guard.php
│   │   ├── profile.php
│   │   ├── skills.php
│   │   ├── experiences.php
│   │   ├── formations.php
│   │   ├── projects.php
│   │   ├── contact.php
│   │   ├── altcha.php    # CAPTCHA challenge endpoint
│   │   └── db.php        # PDO connection helper
│   ├── assets/
│   │   ├── css/main.css
│   │   └── js/app.js
│   └── i18n/
│       ├── fr.json
│       └── en.json
└── .env                  # Environment variables (not committed)
```

## Setup

### 1. Environment variables

Copy the example below to `.env` at the project root and fill in your values:

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USER=your_db_user
DB_PASSWORD=your_db_password
DB_NAME=portfolio

SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=you@gmail.com
SMTP_PASS=your_app_password
SMTP_FROM=you@gmail.com

ALTCHA_HMAC_KEY=your_random_secret_key
```

### 2. Install PHP dependencies

```bash
composer install
```

### 3. Initialize the database

```bash
# Create the database
bash bdd/create_db.sh

# Apply schema and seed data
bash bdd/apply.sh

# (Optional) run incremental migrations
bash bdd/migrate.sh
```

### 4. Create the first admin user

```bash
php bdd/create_admin.php
```

### 5. Serve the site

Point your web server (Apache / Nginx / XAMPP) at `site/` as the document root. PHP must be enabled for the `api/` directory.

For HTTPS in local development, see [docs-ssl-xampp-windows.md](docs-ssl-xampp-windows.md).

## Admin panel

The admin panel is available at `/admin/`. It lets you manage all portfolio content (profile, skills, experiences, formations, projects) and supports bilingual content (fr / en). On first login you will be prompted to change the default password.

## Contact form

The contact form uses [Altcha](https://altcha.org/) for spam protection (no third-party service required) and PHPMailer to send messages via SMTP.

## i18n

Language files live in `site/i18n/`. The frontend reads the browser locale and falls back to French. API endpoints return the locale-appropriate translation when a `?lang=` query parameter is provided.
