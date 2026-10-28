# Installation and operations

## 1. Blank installation

Use PHP **8.4+**, SQLite **3.45+**, and PHP extensions `dom`, `libxml`, `pdo_sqlite`, `fileinfo`, `gd`, `exif`, and `tokenizer`. The PHP CLI backup command additionally needs `phar`. Sanitization has no mbstring dependency. Use one host and reliable local storage with SQLite WAL and working advisory locks; a successful probe does not certify a network filesystem.

1. Extract the package into the site root. Configure an HTTPS virtual host with the denial/routing rules below.
2. Grant the PHP service account ownership/write access to `var/` and `wp-content/uploads/`. Recommended private runtime mode is 0700; public uploaded files are 0644. Changing mode to 0755 alone does not grant a different service account write access. Keep source and theme PHP read-only for the web account where practical.
3. Set the same environment for PHP-FPM and CLI. External runtime storage is recommended. Set `upload_max_filesize=26M` or higher (the per-file document ceiling is 25 MiB) and `post_max_size=32M` or higher; application limits still apply. The enhanced uploader sends one file per request, so these values cover the largest single file plus multipart overhead; the no-JavaScript form submits its selected files in one request, so raise `post_max_size` — and the web server body limit below — to cover the batch total when several large files are submitted together.
4. Open `/wp-admin/install.php` and create the administrator (password at least 12 characters). The installer verifies extensions, SQLite version, and basic locking before seeding the database; the database layer enables and verifies WAL on every connection.
5. Log in at `/wp-admin/login.php`. Set the canonical HTTPS site address under Settings, especially when installing under a subdirectory.
6. Configure the supplied worker, maintenance, and backup commands. Rehearse restore before relying on backups.

The package has no database, users, uploads, site secret, sessions, logs, or cache state. Configuration creates a unique 0600 `var/auth.key` when no operator secret is supplied. Keep that key persistent. An invalid/short explicit secret fails closed. Production HTTP without trusted HTTPS information returns 503; local HTTP testing must explicitly use `WP_ENV=development`.

## 2. Environment

Server `getenv`/`$_ENV` values override optional local `.env` values. Configure absolute paths and a stable unique secret if managing secrets externally:

```bash
export WP_ENV="production"
export WP_VAR_DIR="/persistent-volume/var"
export WP_DB_PATH="/persistent-volume/var/data/site.sqlite"
export WP_AUTH_SALT="replace-with-at-least-32-random-characters"
export WP_DEFAULT_THEME="default"
```

PHP-FPM pools often clear shell environment variables; explicitly pass these values into the pool. For TLS termination at a trusted reverse proxy, configure `HTTPS=on` at the server/FastCGI layer or set operator-managed `WP_HTTPS=1`. Never derive this flag from an untrusted forwarded header. Block direct access to the upstream HTTP origin. HTTPS detection controls secure cookies and HSTS.

## 3. Server templates

Adjust hostname, paths, certificates, socket, and request limits. These host-root templates require adaptation for subdirectory hosting. Test actual denial of `/.env`, `/.git/config`, `/var/auth.key`, `/bin/wp`, `/wp-includes/`, `/docs/INSTALL.md`, and PHP paths beneath `/wp-content/`. Private backups must never be web-accessible.

### Apache 2.4 with PHP-FPM

Enable `rewrite`, `authz_core`, `proxy`, `proxy_fcgi`, `headers`, and `ssl`. The complete rules below belong in the virtual host. They work with `AllowOverride None`; `.htaccess` is only a secondary safeguard and cannot protect files when Apache ignores it.

```apache
<VirtualHost *:80>
    ServerName example.com
    Redirect permanent / https://example.com/
</VirtualHost>
<VirtualHost *:443>
    ServerName example.com
    DocumentRoot /var/www/wordpress-next
    SSLEngine on
    SSLCertificateFile /path/to/fullchain.pem
    SSLCertificateKeyFile /path/to/privkey.pem
    DirectoryIndex index.php
    Header always set X-Content-Type-Options "nosniff"
    <Directory /var/www/wordpress-next>
        Options -Indexes -MultiViews
        AllowOverride None
        Require all granted
        RewriteEngine On
        RewriteRule (^|/)\. - [F,L]
        RewriteRule ^(?:var|docs|vendor|tests|tools|bin|wp-includes)(?:/|$) - [F,L,NC]
        RewriteRule ^wp-content/.*\.(?:php|phtml|phar)(?:/|$) - [F,L,NC]
        RewriteRule ^wp-admin/.*\.php/ - [R=404,L,NC]
        RewriteRule ^(?:wp-config|wp-cron)\.php(?:/|$) - [R=404,L,NC]
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule . index.php [L,QSA]
    </Directory>
    <DirectoryMatch "/var/www/wordpress-next/(?:.*\/)?\.[^/]+(?:/.*)?$">
        Require all denied
    </DirectoryMatch>
    <DirectoryMatch "/var/www/wordpress-next/(var|docs|vendor|tests|tools|bin|wp-includes)(/.*)?$">
        Require all denied
    </DirectoryMatch>
    <FilesMatch "(?i)\.(sqlite|sqlite-wal|sqlite-shm|db|key|lock|log|md|env.*)$|^(composer\.(json|lock)|package(-lock)?\.json|phpstan\.neon|phpcs\.xml|wp-config\.php|wp-cron\.php)$">
        Require all denied
    </FilesMatch>
    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/php8.4-fpm.sock|fcgi://localhost/"
    </FilesMatch>
    <Directory /var/www/wordpress-next/wp-content>
        <FilesMatch "(?i)\.(php|phtml|phar)$">
            SetHandler none
            Require all denied
        </FilesMatch>
    </Directory>
    <Directory /var/www/wordpress-next/wp-admin>
        AcceptPathInfo Off
        <FilesMatch "^(admin|admin-header|admin-footer|admin-functions|class-wp-installer|post-editor)\.php$">
            Require all denied
        </FilesMatch>
    </Directory>
    <Directory /var/www/wordpress-next/wp-content/uploads>
        <FilesMatch "^[a-f0-9]{64}\.(jpg|png|webp|gif)$">
            Header set Cache-Control "public, max-age=31536000, immutable"
        </FilesMatch>
    </Directory>
</VirtualHost>
```

The virtual host supplies route fallback, private-file protection and execution denial under wp-content. Keep these rules together; do not substitute an unchecked hosting default. Run `apachectl configtest` and actual HTTP checks; configuration testing alone does not parse every per-directory request path. Verify the complete virtual host with actual HTTP requests, including with per-directory overrides disabled.

### Nginx and PHP-FPM

The following application server assumes trusted HTTPS termination in front of it; its HTTP listener must not be exposed directly. Configure `WP_HTTPS=1` in the PHP environment for this topology. Alternatively use a TLS listener and pass the real server HTTPS state. Add `client_max_body_size 32m;` where needed, so a 25 MiB document plus multipart overhead fits (see the PHP limits above). Nginx ignores `.htaccess`.

```nginx
# http {} context: this zone must be outside server {}.
limit_req_zone $binary_remote_addr zone=wp_login:10m rate=5r/m;
server {
    listen 80;
    server_name example.com;
    root /var/www/wordpress-next;
    index index.php;
    include /etc/nginx/mime.types;
    client_max_body_size 32m;
    add_header X-Content-Type-Options nosniff always;

    location ~ /\. { return 404; }
    location ~* \.(sqlite|sqlite-wal|sqlite-shm|db|key|lock|log|md|env.*)$ { return 404; }
    location ~ ^/(composer\.(json|lock)|package(-lock)?\.json|phpstan\.neon|phpcs\.xml|wp-config\.php|wp-cron\.php)$ { return 404; }
    location ~* ^/wp-content/.*\.(php|phtml|phar)(?:/|$) { return 404; }
    location ~* ^/(var|docs|vendor|tests|tools|bin|wp-includes)(/|$) { return 404; }
    location ~* ^/wp-admin/.*\.php/ { return 404; }

    # Regex location, placed after deny rules. Never use ^~ to bypass them.
    location ~ "^/wp-content/uploads/[a-f0-9]{2}/[a-f0-9]{64}\.(jpg|png|webp|gif)$" {
        try_files $uri =404;
        add_header X-Content-Type-Options nosniff always;
        add_header Cache-Control "public, max-age=31536000, immutable";
        types { image/jpeg jpg; image/png png; image/webp webp; image/gif gif; }
    }
    location / { try_files $uri $uri/ /index.php?$args; }
    location = /wp-admin/login.php {
        limit_req zone=wp_login burst=5 nodelay;
        limit_req_status 429;
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
    location ~ ^/(index\.php|wp-admin/(index|install|edit|post|post-new|media|themes|options-general|options-permalink|edit-tags|file-editor|users|profile|activity|portability|block-preview)\.php)$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
    location ~ \.php$ { return 404; }
}
```

Denial regexes must precede the PHP handler. Run `nginx -t` and actual requests. The supplied static fixture test checks these denial rules; it does not certify PHP-FPM or TLS integration. Disable directory indexes and direct upstream access.

Live Site design preview posts to the existing `wp-admin/themes.php` route using a dedicated action-bound nonce. It returns private, non-cacheable JSON for a sandboxed `srcdoc` frame. No additional public PHP endpoint or relaxation of the site-wide frame-protection headers is required.

Export / Import lives at `wp-admin/portability.php`, which the allowlist above includes; omitting it returns 404 before PHP runs. The screen is administrator-only, and export archives include private document bytes, so treat downloaded archives like backups. Import requires the PHP `zip` extension on the server; without it the screen reports the feature as unavailable and changes nothing. Uploaded archives are stored under the private data directory (`var/portability`) so an interrupted import can be resumed; prune that folder like other operator state.

## 4. Workers and housekeeping

Use the same PHP version, extensions, service account, environment, and storage paths as HTTP. Workers process at most the requested number of jobs and 45 seconds per invocation. Lease fencing makes concurrent worker invocations safe; SQLite still has one writer.

```cron
* * * * * /usr/bin/php /var/www/wordpress-next/wp-cron.php >>/private/log/wp-worker.log 2>&1
17 3 * * * /usr/bin/php /var/www/wordpress-next/bin/wp maintain >>/private/log/wp-maintain.log 2>&1
30 3 * * * /usr/bin/php /var/www/wordpress-next/bin/wp backup >>/private/log/wp-backup.log 2>&1
```

```bash
php bin/wp status
php bin/wp queue:work 100
php bin/wp queue:retry
php bin/wp sessions:revoke
php bin/wp backup
```

`maintain` deletes expired sessions/throttles and completed jobs older than 30 days, and runs a passive WAL checkpoint. Audit and revision histories are retained. `queue:retry` explicitly retries failed jobs; inspect operator logs first. Transition jobs currently produce idempotent audit events, not external webhooks. Asset maintenance first reconciles pending registrations, then reclaims recognized unreferenced blobs and expired staging after the 24-hour grace period. Referenced blobs, unknown files and symbolic links are preserved. There is no automated off-host backup retention policy.

## 5. Upgrade and recovery

Back up application source/configuration and external secrets before deploying changed code. Run `php bin/wp upgrade` with native PHP 8.4+ and Phar. It seals admission, drains readers, creates a pre-upgrade backup, migrates transactionally, revokes sessions, then reopens traffic. Existing installed schemas are not migrated automatically by HTTP. A failed upgrade deliberately leaves `var/maintenance.json` sealed.

If an upgrade fails, keep traffic blocked, inspect server/CLI logs, correct the cause or restore a verified snapshot, then rerun the upgrade. Do not delete the barrier while workers or a coordinator are active. Restore application version and database schema together. A successful controlled upgrade clears the barrier.

`backup` writes private `var/backups/*.tar` archives containing `site.sqlite`, every registered ready asset verified against its hash (public images and derived variants under `uploads/`, protected documents in the asset tree), `manifest.json`, and `auth.key` when file-based. A missing or corrupt ready asset fails the backup loudly, naming the asset. The intermediate SQLite snapshot is also retained. Environment-managed secrets, source code, server configuration, and unregistered old image bytes are outside the archive. Copy backups off-host, encrypt/restrict them, and define retention. They contain private account/content data and potentially the site secret.

For restore, stop traffic and workers; inspect the archive manifest; restore `site.sqlite` to WP_DB_PATH, images beneath wp-content/uploads, document blobs beneath WP_ASSETS_DIR, and the matching site secret with private permissions. Use the matching source version, run upgrade and sessions:revoke, verify administrator login and public images, then reopen traffic. Do not copy a live SQLite database without its WAL or use a blind filesystem copy as a backup.

## 6. Local development and checks

### macOS with Homebrew

Install PHP 8.4 to match CI. Homebrew supplies SQLite as a PHP dependency; the system SQLite command does not determine which library PHP uses.

```bash
brew install php@8.4
brew link --force php@8.4
```

Install Composer 2 using the [official verified installer](https://getcomposer.org/download/), placing `composer` in `/opt/homebrew/bin` on Apple silicon (`/usr/local/bin` on Intel). Check the installer checksum before executing it.

If Composer downloads time out with LuLu installed, check outbound permissions for `/opt/homebrew/opt/php@8.4/bin/php` and its resolved Cellar path. Composer makes its network requests through PHP.

On Apple silicon, PHP configuration lives in `/opt/homebrew/etc/php/8.4/`. Add `conf.d/99-wordpress-next-dev.ini` with upload limits that accommodate the 25 MiB document ceiling:

```ini
upload_max_filesize=26M
post_max_size=32M
```

Set `WP_ENV=development` in the project's ignored `.env` file for local HTTP. Leave `WP_AUTH_SALT` unset to generate a private per-install secret. The web installer creates the database and administrator; no separate SQLite service is needed.

Verify PHP's actual SQLite library and extensions before starting:

```bash
php --version
composer --version
php -r 'echo "SQLite ", (new PDO("sqlite::memory:"))->query("SELECT sqlite_version()")->fetchColumn(), PHP_EOL;'
php -m
```

PHP must report 8.4+, SQLite must report 3.45+, and extensions must include `dom`, `libxml`, `pdo_sqlite`, `fileinfo`, `gd`, `exif`, `tokenizer`, and `Phar`. Composer's development tools also require the XML extensions listed in the CI workflow.

### Run the application

From the extracted `wordpress-next/` directory:

```bash
composer install
composer check
composer test:http
python3 tests/upgrade-smoke.py
WP_ENV=development php -S 127.0.0.1:8080 -t . tools/router.php
```

Open `http://127.0.0.1:8080/wp-admin/install.php` to create the local site. The development server stays in the foreground; stop it with Control-C. Restart it after changing PHP configuration.

The local router rejects private paths for workflow testing. It does not validate Apache/Nginx rules and is not a production server. See QUALITY.md and reviews/REVIEW.md for scoped verification.

## Permalink configuration

After installation, use Settings → Permalinks to select canonical Post addresses. Existing sites retain Post name until changed. Pretty links use the existing front-controller server rules. For the requested `/month/day/slug/` format, select Custom structure and enter `/%monthnum%/%day%/%postname%/`. See reviews/PERMALINK_REVIEW.md for supported tags, redirects, Page behavior, and scope.

## Login capacity and uploaded images

The application reserves at most 30 password-verification attempts per peer IP per fixed 60-second window, across all usernames. A separate account/IP failure limit remains. Budgets are atomic and successful logins do not reset the IP budget. `REMOTE_ADDR` is authoritative; forwarded headers are ignored. Shared NAT addresses share the budget. Trusted reverse proxies must set the real client address at the web-server layer with an explicit proxy allowlist. Never trust arbitrary client-supplied headers.

The nginx example also limits login requests before PHP admission (5/minute with burst 5). Define the zone in `http {}` and the rest in `server {}` as shown. Apache operators should apply equivalent request-rate controls at their trusted reverse proxy or WAF. The application budget still operates without those controls.

Install PHP GD with JPEG, PNG, WebP and GIF support, plus EXIF. Incoming nonanimated images are fully decoded and re-encoded. JPEG orientation is baked into pixels; other metadata is removed. Maximum input/output size is 10 MB, dimensions 12000px per side and 12 megapixels, further constrained by available PHP memory. Animated GIF/WebP/APNG uploads are rejected explicitly. JPEG is re-encoded at quality 90; PNG and WebP use lossless output. Existing upload files are not rewritten automatically. Review and replace any older originals containing sensitive metadata before exposing them publicly. Backups may still contain originals.

Published content accepts only this site's content-addressed upload URLs. External, inline-data and arbitrary application image URLs are removed during rendering. Upload images manually through Media; the server does not fetch remote URLs. CSP independently restricts image loads to the same origin.

Static uploads receive `nosniff` and immutable caching only for the generated content-addressed filenames. Do not overwrite those URLs with different bytes. The nginx deny rules must precede the upload regex location. Avoid `^~` upload locations, which bypass regex denial rules.

## Schema 5 asset foundation and the document library

Run `php bin/wp upgrade` with the existing backup and maintenance procedure. `php bin/wp status` now reports registered asset bytes, physical bytes in managed blobs/staging, and pending records. `php bin/wp maintain` reconciles pending assets before cleanup and reports missing/corrupt ready blobs. Automatic reclamation is restricted to recognized, unreferenced files older than 24 hours. Unknown files and symbolic links are preserved. Backups and storage writers share a lock; a busy operation returns a retryable failure after bounded waiting.

`WP_ASSETS_DIR` defaults to `WP_VAR_DIR/assets`; this default is protected by the existing var denial rules and is not outside the webroot. An overridden asset directory must be private, dedicated to assets and outside the served tree, or explicitly denied by the web server. Do not overlap it with public uploads. Document upload and download are enabled: PDF, text, Markdown and CSV files up to 25 MiB are stored unmodified in the protected asset tree and delivered only through the authorizing `/documents/{id}` route (or its pretty form), with authorization rechecked on every request and `Cache-Control: no-store` on every response. Backups archive ready document blobs alongside images and variants.

## Schema 6 design and managed files

Run `php bin/wp upgrade` before serving this build. Existing theme, media and Page assignments remain in place; the new tables store design revisions and managed-file history. Publication and rollback require an administrator, POST/CSRF and an expected live version. Preview links are session-bound, private and no-store.

Theme parsing requires the tokenizer extension. Theme publication requires PHP CLI process support (`proc_open`) and a PHP 8.4+ CLI binary. Set `WP_PHP_CLI=/absolute/path/to/php` if `php` is not on the FPM service's PATH. The render probe uses a private database snapshot, a separate process with a 128 MB limit, bounded output and a five-second process deadline; temporary state is removed after normal completion/failure. Trusted theme PHP is not an OS sandbox. Existing source/configuration and operator secrets still require separate backup.

Both bundled themes include theme.json, whose requires=1 field identifies renderer contract 1 and whose token/template lists are validated. Operator-deployed themes must supply that manifest. Template IDs map only to regular PHP files within the theme; path/symlink escapes and invalid PHP syntax reject the manifest. Invalid active manifests fall back to the default theme. Keep deployed sources stable during publication; a fingerprint change rejects activation.

The nginx PHP entrypoint allowlist now includes wp-admin/file-editor.php. Managed files are delivered through the front controller at /site-assets/custom.css and /site-assets/site-notice.txt, with fixed MIME, nosniff and no-store. They are database-backed public files, not arbitrary filesystem paths. Their history is included in the existing database snapshot backup. Keep the dedicated /site-assets/ namespace available for these resources; unrelated existing routes continue through normal routing.

## Mail and account recovery

Account email (invitations, password recovery, and optional sign-in links)
uses SMTP when a host is configured in Settings → General → Mail, and the
server's PHP mail transport otherwise. The same settings can be provided as
environment variables, which take precedence: `WP_MAIL_SMTP_HOST`,
`WP_MAIL_SMTP_PORT`, `WP_MAIL_SMTP_ENCRYPTION` (`tls`, `ssl` or `none`),
`WP_MAIL_SMTP_USERNAME`, `WP_MAIL_SMTP_PASSWORD`, `WP_MAIL_FROM` and
`WP_MAIL_FROM_NAME`. The settings screen shows the last recorded delivery
result; failures are recorded there and in the activity log, and are never
reported as sent. A password saved in settings is stored in the site
database and is therefore inside database backups; prefer
`WP_MAIL_SMTP_PASSWORD` when the credential must stay outside the
database. Use TLS (`tls` or `ssl`) with certificate validation on any real
mail host.

Account links are purpose-bound, hashed at rest, single-use and expiring
(invitations and resets: one hour; sign-in links: thirty minutes). Opening a
link never consumes it — every flow asks for an explicit confirmation or
form submission first, so mail scanners cannot burn a link.

If mail is unavailable, an operator with shell access can mint a one-time
reset link directly (this does not depend on mail delivery):

```sh
php bin/wp user:reset-link <username-or-email>
```

Deliver the printed link to the account holder through a trusted channel.

The optional embedded Page editor reuses `wp-admin/post.php`, so no endpoint allowlist change is needed. Authorized workspace-mode Page responses use SAMEORIGIN and CSP frame-ancestors self; ordinary editor and other application responses retain DENY and frame-ancestors none. An upstream server that overrides every response with DENY will block this embedded editor; preserve the scoped application policy rather than relaxing framing globally.
