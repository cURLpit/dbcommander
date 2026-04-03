#!/usr/bin/env bash
# =============================================================================
#  DBCommander – Full Stack Installer
#  Ubuntu 22.04 / 24.04 LTS
#
#  Usage:
#    sudo bash install.sh [OPTIONS]
#
#  Options:
#    --webserver apache|nginx   Web server (default: nginx)
#    --php-version 8.1          PHP version (default: 8.1)
#    --db-root-pass PASSWORD    MySQL root password (default: prompted)
#    --db-user USER             App DB user (default: dbcommander)
#    --db-pass PASSWORD         App DB user password (default: generated)
#    --app-dir PATH             Install directory (default: /var/www/dbcommander)
#    --domain DOMAIN            Server name / vhost domain (default: localhost)
#    --dev                      Enable dev mode (CORS *, debug errors)
#    --skip-mysql               Skip MySQL installation (use existing)
#    --skip-php                 Skip PHP installation (use existing)
#    -h, --help                 Show this help
#
#  Example:
#    sudo bash install.sh --webserver apache --domain dbcmd.example.com
# =============================================================================

set -euo pipefail
IFS=$'\n\t'

# ── Colours ──────────────────────────────────────────────────────────────────
RED='\033[0;31m';  GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m';     RESET='\033[0m'

log()     { echo -e "${GREEN}[✔]${RESET} $*"; }
info()    { echo -e "${CYAN}[→]${RESET} $*"; }
warn()    { echo -e "${YELLOW}[!]${RESET} $*"; }
error()   { echo -e "${RED}[✘]${RESET} $*" >&2; }
die()     { error "$*"; exit 1; }
section() { echo -e "\n${BOLD}${CYAN}══ $* ══${RESET}"; }

# ── Defaults ─────────────────────────────────────────────────────────────────
WEBSERVER="nginx"
PHP_VERSION="8.1"
DB_ROOT_PASS=""
DB_APP_USER="dbcommander"
DB_APP_PASS=""
APP_DIR="/var/www/dbcommander"
DOMAIN="localhost"
DEV_MODE=false
SKIP_MYSQL=false
SKIP_PHP=false

# ── Argument parsing ─────────────────────────────────────────────────────────
while [[ $# -gt 0 ]]; do
  case "$1" in
    --webserver)    WEBSERVER="$2";     shift 2 ;;
    --php-version)  PHP_VERSION="$2";   shift 2 ;;
    --db-root-pass) DB_ROOT_PASS="$2";  shift 2 ;;
    --db-user)      DB_APP_USER="$2";   shift 2 ;;
    --db-pass)      DB_APP_PASS="$2";   shift 2 ;;
    --app-dir)      APP_DIR="$2";       shift 2 ;;
    --domain)       DOMAIN="$2";        shift 2 ;;
    --dev)          DEV_MODE=true;      shift ;;
    --skip-mysql)   SKIP_MYSQL=true;    shift ;;
    --skip-php)     SKIP_PHP=true;      shift ;;
    -h|--help)
      sed -n '/^#  Usage:/,/^# =\+/p' "$0" | head -n -1 | sed 's/^#//'
      exit 0 ;;
    *) die "Unknown option: $1" ;;
  esac
done

# Validate webserver choice
[[ "$WEBSERVER" == "apache" || "$WEBSERVER" == "nginx" ]] \
  || die "--webserver must be 'apache' or 'nginx'"

# ── WSL2 detection + service helper ──────────────────────────────────────────
WSL2=false
if grep -qiE "microsoft|wsl" /proc/version 2>/dev/null; then
  WSL2=true
fi

# Start a service – works with both systemd and sysvinit (WSL2 default)
svc_start() {
  local svc="$1"
  if systemctl is-active --quiet "$svc" 2>/dev/null; then
    log "$svc already running"
  elif systemctl start "$svc" 2>/dev/null; then
    systemctl enable "$svc" --quiet 2>/dev/null || true
    log "$svc started via systemctl"
  elif service "$svc" start 2>/dev/null; then
    log "$svc started via service"
  else
    warn "Could not start $svc – may need manual start"
  fi
}

# ── Pre-flight checks ─────────────────────────────────────────────────────────
section "Pre-flight checks"

[[ $EUID -eq 0 ]] || die "This script must be run as root (sudo bash install.sh)"

# Detect Ubuntu version
if [[ -f /etc/os-release ]]; then
  source /etc/os-release
  info "Detected OS: ${PRETTY_NAME}"
  [[ "$ID" == "ubuntu" ]] || warn "Non-Ubuntu OS detected; proceeding anyway"
  UBUNTU_VERSION="${VERSION_ID:-22.04}"
else
  warn "Cannot detect OS version; assuming Ubuntu 22.04"
  UBUNTU_VERSION="22.04"
fi

log "Running as root"
log "Webserver: ${WEBSERVER}"
log "PHP: ${PHP_VERSION}"
log "App dir: ${APP_DIR}"
log "Domain: ${DOMAIN}"
log "Dev mode: ${DEV_MODE}"

# ── Password setup ────────────────────────────────────────────────────────────
section "Credentials"

if [[ -z "$DB_ROOT_PASS" ]] && [[ "$SKIP_MYSQL" == false ]]; then
  # Read from /dev/tty directly – works correctly in WSL2 and piped shells
  # Confirmation pass to catch typos
  while true; do
    printf "Enter MySQL root password (leave blank to auto-generate): " > /dev/tty
    read -rs DB_ROOT_PASS < /dev/tty
    echo > /dev/tty

    if [[ -z "$DB_ROOT_PASS" ]]; then
      break  # blank → will auto-generate below
    fi

    printf "Confirm password: " > /dev/tty
    read -rs DB_ROOT_PASS_CONFIRM < /dev/tty
    echo > /dev/tty

    if [[ "$DB_ROOT_PASS" == "$DB_ROOT_PASS_CONFIRM" ]]; then
      log "Password accepted"
      break
    else
      warn "Passwords do not match – try again"
      DB_ROOT_PASS=""
    fi
  done
fi

if [[ -z "$DB_ROOT_PASS" ]]; then
  DB_ROOT_PASS="$(openssl rand -base64 24)"
  warn "Generated MySQL root password: ${DB_ROOT_PASS}"
  warn "Save this somewhere safe!"
fi

if [[ -z "$DB_APP_PASS" ]]; then
  DB_APP_PASS="$(openssl rand -base64 18)"
  info "Generated app DB password: ${DB_APP_PASS}"
fi

# ── System update ─────────────────────────────────────────────────────────────
section "System update"

export DEBIAN_FRONTEND=noninteractive

# Remove stale MySQL APT repo if present (from previous install attempts)
rm -f /etc/apt/sources.list.d/mysql.list
rm -f /etc/apt/sources.list.d/mysql-community*.list
rm -f /usr/share/keyrings/mysql-archive-keyring.gpg
# Clean any mysql repo entries from main sources.list
sed -i '/repo\.mysql\.com/d' /etc/apt/sources.list 2>/dev/null || true

apt-get update -qq
apt-get upgrade -y -qq
apt-get install -y -qq \
  curl wget gnupg2 ca-certificates lsb-release \
  software-properties-common apt-transport-https \
  unzip git openssl
log "Base packages installed"

# ── PHP 8.1 (Ondřej PPA) ─────────────────────────────────────────────────────
if [[ "$SKIP_PHP" == false ]]; then
  section "PHP ${PHP_VERSION}"

  if ! grep -q "ondrej/php" /etc/apt/sources.list.d/*.list 2>/dev/null; then
    add-apt-repository -y ppa:ondrej/php
    apt-get update -qq
    log "Ondřej PPA added"
  else
    log "Ondřej PPA already present"
  fi

  PHP_PACKAGES=(
    "php${PHP_VERSION}"
    "php${PHP_VERSION}-cli"
    "php${PHP_VERSION}-fpm"
    "php${PHP_VERSION}-mysql"
    "php${PHP_VERSION}-mbstring"
    "php${PHP_VERSION}-xml"
    "php${PHP_VERSION}-curl"
    "php${PHP_VERSION}-opcache"
    "php${PHP_VERSION}-intl"
    "php${PHP_VERSION}-zip"
  )

  apt-get install -y -qq "${PHP_PACKAGES[@]}"
  log "PHP ${PHP_VERSION} installed"

  # Tune PHP-FPM
  PHP_FPM_CONF="/etc/php/${PHP_VERSION}/fpm/php.ini"
  if [[ -f "$PHP_FPM_CONF" ]]; then
    sed -i 's/^;*\s*opcache\.enable\s*=.*/opcache.enable=1/'           "$PHP_FPM_CONF"
    sed -i 's/^;*\s*opcache\.memory_consumption\s*=.*/opcache.memory_consumption=128/' "$PHP_FPM_CONF"
    sed -i 's/^;*\s*opcache\.interned_strings_buffer\s*=.*/opcache.interned_strings_buffer=16/' "$PHP_FPM_CONF"
    sed -i 's/^;*\s*opcache\.max_accelerated_files\s*=.*/opcache.max_accelerated_files=10000/' "$PHP_FPM_CONF"
    sed -i 's/^expose_php\s*=.*/expose_php = Off/'                     "$PHP_FPM_CONF"
    log "PHP-FPM tuned (opcache enabled, expose_php=Off)"
  fi

  # mod_php for Apache (installed even in nginx mode so the package is there)
  apt-get install -y -qq "libapache2-mod-php${PHP_VERSION}" 2>/dev/null || true

  PHP_BIN="php${PHP_VERSION}"
  PHP_FPM_SERVICE="php${PHP_VERSION}-fpm"
else
  PHP_BIN="php"
  PHP_FPM_SERVICE="php${PHP_VERSION}-fpm"
  log "PHP installation skipped"
fi

# ── Composer ──────────────────────────────────────────────────────────────────
section "Composer"

# Prevent Composer interactive prompts globally
export COMPOSER_NO_INTERACTION=1
export COMPOSER_FUND=0
# NOTE: COMPOSER_DISABLE_NETWORK is intentionally NOT exported globally –
# it would break "composer install". It is set inline only for the version check.

info "[1/5] Checking if Composer is already installed..."
if ! command -v composer &>/dev/null; then
  info "[2/5] Composer not found – starting download..."

  COMPOSER_INSTALLER="/tmp/composer-setup.php"
  COMPOSER_SIG_URL="https://composer.github.io/installer.sig"
  COMPOSER_INSTALLER_URL="https://getcomposer.org/installer"

  info "[3/5] Fetching installer checksum from ${COMPOSER_SIG_URL}..."
  EXPECTED_CHECKSUM="$(curl -sSf --connect-timeout 15 --max-time 30 "${COMPOSER_SIG_URL}")"     || die "Could not fetch Composer checksum – check network connectivity"
  info "[3/5] Checksum fetched: ${EXPECTED_CHECKSUM:0:20}..."

  info "[4/5] Downloading installer from ${COMPOSER_INSTALLER_URL}..."
  wget --timeout=30 --tries=3 --progress=bar:force     -O "${COMPOSER_INSTALLER}" "${COMPOSER_INSTALLER_URL}"     || die "Could not download Composer installer – check network connectivity"
  info "[4/5] Installer downloaded ($(wc -c < "${COMPOSER_INSTALLER}") bytes)"

  info "[5/5] Verifying checksum..."
  ACTUAL_CHECKSUM="$($PHP_BIN -r "echo hash_file('sha384', '${COMPOSER_INSTALLER}');")"
  info "[5/5] Actual checksum:   ${ACTUAL_CHECKSUM:0:20}..."

  if [[ "$EXPECTED_CHECKSUM" != "$ACTUAL_CHECKSUM" ]]; then
    rm -f "${COMPOSER_INSTALLER}"
    die "Composer installer checksum mismatch!"
  fi
  info "[5/5] Checksum OK – running installer..."

  $PHP_BIN "${COMPOSER_INSTALLER}" --install-dir=/usr/local/bin --filename=composer
  rm -f "${COMPOSER_INSTALLER}"
  log "Composer installed: $(composer --version --no-ansi 2>&1 | head -1)"
else
  COMPOSER_VER="$(COMPOSER_DISABLE_NETWORK=1 timeout 5 composer --version --no-ansi 2>/dev/null | head -1 || echo "unknown")"
  log "Composer already installed: ${COMPOSER_VER}"
fi

# ── MySQL 8.4 ─────────────────────────────────────────────────────────────────
if [[ "$SKIP_MYSQL" == false ]]; then
  section "MySQL 8.4 LTS"

  if [[ "$WSL2" == true ]]; then
    warn "WSL2 detected – using service fallback if systemctl unavailable"
  fi

  if ! command -v mysql &>/dev/null; then
    info "Installing mysql-server from Ubuntu repos..."
    # Ubuntu 24.04 ships MySQL 8.4 natively – no external repo needed
    echo "mysql-server mysql-server/root_password       password ${DB_ROOT_PASS}" | debconf-set-selections
    echo "mysql-server mysql-server/root_password_again password ${DB_ROOT_PASS}" | debconf-set-selections
    DEBIAN_FRONTEND=noninteractive apt-get install -y mysql-server
    log "MySQL installed: $(mysql --version 2>&1 | head -1)"
  else
    log "MySQL already installed: $(mysql --version 2>&1 | head -1)"
  fi

  # Ensure MySQL is running
  svc_start mysql
  sleep 2
  log "MySQL service running"

  # Secure + create app user
  info "Configuring MySQL root password and app user..."

  # Detect how to connect as root and wrap in a function – avoids
  # the "command not found" bash pitfall of storing flags in a variable.
  mysql_root() { mysql -uroot "$@"; }

  if mysql -uroot --connect-timeout=5 -e "SELECT 1;" &>/dev/null 2>&1; then
    info "MySQL root: auth_socket (no password)"
    mysql_root() { mysql -uroot "$@"; }
  elif mysql -uroot -p"${DB_ROOT_PASS}" --connect-timeout=5 -e "SELECT 1;" &>/dev/null 2>&1; then
    info "MySQL root: native password already set"
    mysql_root() { mysql -uroot -p"${DB_ROOT_PASS}" "$@"; }
  elif sudo mysql --connect-timeout=5 -e "SELECT 1;" &>/dev/null 2>&1; then
    info "MySQL root: sudo mysql fallback"
    mysql_root() { sudo mysql "$@"; }
  else
    die "Cannot connect to MySQL as root. Reset with: sudo mysqld_safe --skip-grant-tables &"
  fi

  # Step 1: switch root to native password auth
  mysql_root <<SQL
ALTER USER 'root'@'localhost'
  IDENTIFIED WITH mysql_native_password
  BY '${DB_ROOT_PASS}';
FLUSH PRIVILEGES;
SQL
  info "Root auth updated – using password from here on"

  # Step 2: password auth for remaining operations
  # Note: information_schema is readable by all users in MySQL 8.x – no explicit GRANT needed
  mysql -uroot -p"${DB_ROOT_PASS}" <<SQL
DELETE FROM mysql.user WHERE User='';
DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\_%';
CREATE USER IF NOT EXISTS '${DB_APP_USER}'@'localhost' IDENTIFIED BY '${DB_APP_PASS}';
GRANT SELECT, SHOW DATABASES, SHOW VIEW ON *.* TO '${DB_APP_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
  log "MySQL root password set, app user '${DB_APP_USER}' created"
fi

# ── Application files ─────────────────────────────────────────────────────────
section "Application"

mkdir -p "${APP_DIR}"/{public,src,config}

# Copy project files if running from repo root, otherwise create stubs
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ -d "${SCRIPT_DIR}/src" ]]; then
  info "Copying project files from ${SCRIPT_DIR}..."
  cp -r "${SCRIPT_DIR}/src"     "${APP_DIR}/"
  cp -r "${SCRIPT_DIR}/public"  "${APP_DIR}/"
  cp    "${SCRIPT_DIR}/composer.json" "${APP_DIR}/"
  log "Project files copied"
else
  warn "src/ not found next to install.sh – skipping file copy."
  warn "Place your project files in ${APP_DIR} manually, then run: composer install"
fi

# Write connections.json
CONNECTIONS_FILE="${APP_DIR}/config/connections.json"
DB_HOST="${DB_HOST:-127.0.0.1}"

cat > "${CONNECTIONS_FILE}" <<JSON
{
  "connections": {
    "local": {
      "driver":   "pdo",
      "host":     "${DB_HOST}",
      "port":     3306,
      "user":     "${DB_APP_USER}",
      "password": "${DB_APP_PASS}",
      "charset":  "utf8mb4"
    }
  },
  "default": "local"
}
JSON
chmod 640 "${CONNECTIONS_FILE}"
log "connections.json written"

# Write .env equivalent: APP_ENV
ENV_FILE="${APP_DIR}/.env"
if [[ "$DEV_MODE" == true ]]; then
  echo "APP_ENV=dev" > "${ENV_FILE}"
  warn "Dev mode enabled: debug errors and CORS * are active"
else
  echo "APP_ENV=prod" > "${ENV_FILE}"
fi

# APP_ENV is passed via webserver SetEnv / fastcgi_param – no index.php modification needed

# Composer install
info "Running composer install..."
cd "${APP_DIR}"
COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_DISABLE_NETWORK=0 composer install --no-dev --optimize-autoloader
log "Composer dependencies installed"

# Permissions
WEB_USER="www-data"
chown -R "${WEB_USER}:${WEB_USER}" "${APP_DIR}"
find "${APP_DIR}" -type d -exec chmod 755 {} \;
find "${APP_DIR}" -type f -exec chmod 644 {} \;
chmod 640 "${APP_DIR}/config/connections.json"
chmod 640 "${APP_DIR}/.env"

# Writable directories
for WRITABLE_DIR in "${APP_DIR}/logs" "${APP_DIR}/public/assets"; do
  mkdir -p "${WRITABLE_DIR}"
  chown "${WEB_USER}:${WEB_USER}" "${WRITABLE_DIR}"
  chmod 775 "${WRITABLE_DIR}"
done
log "Permissions set (owner: ${WEB_USER})"

# ── Web server ────────────────────────────────────────────────────────────────
section "Web server: ${WEBSERVER}"

if [[ "$WEBSERVER" == "nginx" ]]; then
  # ── Nginx ──────────────────────────────────────────────────
  apt-get install -y -qq nginx

  NGINX_CONF="/etc/nginx/sites-available/dbcommander"
  cat > "${NGINX_CONF}" <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};

    root ${APP_DIR}/public;
    index index.php dbcommander.html;

    # Security headers
    add_header X-Frame-Options           "SAMEORIGIN"   always;
    add_header X-Content-Type-Options    "nosniff"      always;
    add_header X-XSS-Protection          "1; mode=block" always;
    add_header Referrer-Policy           "strict-origin-when-cross-origin" always;

    # Static files
    location ~* \.(html|css|js|ico|png|jpg|svg|woff2?)$ {
        expires 7d;
        add_header Cache-Control "public, immutable";
        try_files \$uri =404;
    }

    # API – route everything under /api/ through PHP
    location /api/ {
        try_files \$uri /index.php\$is_args\$args;
    }

    # PHP-FPM
    location ~ \.php$ {
        include        fastcgi_params;
        fastcgi_pass   unix:/run/php/php${PHP_VERSION}-fpm.sock;
        fastcgi_index  index.php;
        fastcgi_param  SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param  APP_ENV         $(${DEV_MODE} && echo dev || echo prod);
        fastcgi_intercept_errors on;
    }

    # Block dot-files
    location ~ /\. { deny all; }

    # Block direct access to config
    location ~* /config/ { deny all; }

    access_log /var/log/nginx/dbcommander.access.log;
    error_log  /var/log/nginx/dbcommander.error.log;
}
NGINX

  # Enable site
  ln -sf "${NGINX_CONF}" /etc/nginx/sites-enabled/dbcommander
  rm -f /etc/nginx/sites-enabled/default

  nginx -t
  svc_start nginx
  svc_start "${PHP_FPM_SERVICE}"
  log "Nginx configured and restarted"

else
  # ── Apache ─────────────────────────────────────────────────
  apt-get install -y -qq apache2

  # Enable required modules
  a2enmod rewrite headers php${PHP_VERSION} 2>/dev/null || true
  # Also enable proxy_fcgi + setenvif for FPM support
  a2enmod proxy_fcgi setenvif 2>/dev/null || true
  a2enconf php${PHP_VERSION}-fpm 2>/dev/null || true

  APACHE_CONF="/etc/apache2/sites-available/dbcommander.conf"
  cat > "${APACHE_CONF}" <<APACHE
<VirtualHost *:80>
    ServerName ${DOMAIN}
    DocumentRoot ${APP_DIR}/public

    DirectoryIndex index.php dbcommander.html

    # Security headers
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"

    <Directory "${APP_DIR}/public">
        Options -Indexes -MultiViews +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Block config directory
    <Directory "${APP_DIR}/config">
        Require all denied
    </Directory>

    # PHP-FPM via proxy
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php${PHP_VERSION}-fpm.sock|fcgi://localhost"
    </FilesMatch>

    SetEnv APP_ENV $(${DEV_MODE} && echo dev || echo prod)

    ErrorLog  \${APACHE_LOG_DIR}/dbcommander.error.log
    CustomLog \${APACHE_LOG_DIR}/dbcommander.access.log combined
</VirtualHost>
APACHE

  # Enable required modules
  a2enmod rewrite headers proxy_fcgi setenvif 2>/dev/null || true
  a2enconf "php${PHP_VERSION}-fpm" 2>/dev/null || true

  # Enable dbcommander site, disable default
  a2ensite dbcommander 2>/dev/null || true
  a2dissite 000-default 2>/dev/null || true

  apache2ctl configtest
  svc_start apache2
  svc_start "${PHP_FPM_SERVICE}"
  log "Apache configured and restarted"
fi

# ── Firewall (UFW) ────────────────────────────────────────────────────────────
section "Firewall"

if command -v ufw &>/dev/null; then
  ufw allow OpenSSH  >/dev/null 2>&1 || true
  ufw allow 80/tcp   >/dev/null 2>&1 || true
  ufw allow 443/tcp  >/dev/null 2>&1 || true
  # Enable only if not already active to avoid lockouts
  if ! ufw status | grep -q "Status: active"; then
    ufw --force enable >/dev/null 2>&1 || true
    log "UFW enabled (SSH + 80 + 443)"
  else
    log "UFW already active – rules added"
  fi
else
  warn "UFW not available; skipping firewall config"
fi

# ── Logrotate ────────────────────────────────────────────────────────────────
section "Log rotation"

cat > /etc/logrotate.d/dbcommander <<LOGROTATE
/var/log/${WEBSERVER}/dbcommander.*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 640 www-data adm
    sharedscripts
    postrotate
        systemctl reload ${WEBSERVER} > /dev/null 2>&1 || true
    endscript
}
LOGROTATE
log "Logrotate config written"

# ── Smoke test ────────────────────────────────────────────────────────────────
section "Smoke test"

sleep 2  # give services a moment to fully start

HTTP_CODE="$(curl -s -o /dev/null -w "%{http_code}" "http://localhost/api/databases" 2>/dev/null || echo "000")"

if [[ "$HTTP_CODE" == "200" ]]; then
  log "Smoke test passed: GET /api/databases → HTTP 200"
elif [[ "$HTTP_CODE" == "000" ]]; then
  warn "Could not reach http://localhost – check webserver logs"
else
  warn "GET /api/databases returned HTTP ${HTTP_CODE} (check logs if unexpected)"
fi

# ── Summary ───────────────────────────────────────────────────────────────────
section "Installation complete"

echo
echo -e "${BOLD}${GREEN}DBCommander is ready!${RESET}"
echo
echo -e "  ${CYAN}Frontend:${RESET}    http://${DOMAIN}/dbcommander.html"
echo -e "  ${CYAN}API base:${RESET}    http://${DOMAIN}/api"
echo -e "  ${CYAN}App dir:${RESET}     ${APP_DIR}"
echo -e "  ${CYAN}Web server:${RESET}  ${WEBSERVER}"
echo -e "  ${CYAN}PHP:${RESET}         ${PHP_VERSION} (FPM: ${PHP_FPM_SERVICE})"
if [[ "$SKIP_MYSQL" == false ]]; then
  echo
  echo -e "  ${YELLOW}MySQL root pass:${RESET} ${DB_ROOT_PASS}"
  echo -e "  ${YELLOW}App DB user:${RESET}     ${DB_APP_USER}"
  echo -e "  ${YELLOW}App DB pass:${RESET}     ${DB_APP_PASS}"
  echo
  echo -e "  ${RED}⚠  Save these credentials – they won't be shown again!${RESET}"
fi
echo
echo -e "  ${CYAN}Logs:${RESET}"
echo -e "    /var/log/${WEBSERVER}/dbcommander.access.log"
echo -e "    /var/log/${WEBSERVER}/dbcommander.error.log"
echo
if [[ "$DEV_MODE" == true ]]; then
  echo -e "  ${YELLOW}Dev mode is ON – disable for production:${RESET}"
  echo -e "    echo 'APP_ENV=prod' > ${APP_DIR}/.env"
  echo
fi
echo -e "  Switch web server anytime:"
echo -e "    ${CYAN}sudo bash install.sh --webserver apache --skip-mysql --skip-php${RESET}"
echo -e "    ${CYAN}sudo bash install.sh --webserver nginx  --skip-mysql --skip-php${RESET}"
echo
