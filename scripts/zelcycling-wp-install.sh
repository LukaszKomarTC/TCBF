#!/usr/bin/env bash
set -euo pipefail

#
# zelcycling.com — WordPress Install Script
# Run from any machine with SSH/curl access to 212.227.105.0
#
# Usage:  chmod +x zelcycling-wp-install.sh && ./zelcycling-wp-install.sh
#

VPS_IP="212.227.105.0"
VPS_PASS="XKbZ6eZFI3Xg"
DOMAIN="zelcycling.com"
PLESK_API="https://${VPS_IP}:8443/api/v2"
WP_ADMIN_USER="Lukasz"
WP_ADMIN_PASS="Lukasz000_"
WP_ADMIN_EMAIL="info@tossacycling.com"
FTP_LOGIN="zelcycling"
FTP_PASS="FtpZel2026!"
DB_NAME="zelcycling_wp"
DB_USER="zelcycling_wp"
DB_PASS="DbZel2026!"

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

ok()   { echo -e "${GREEN}[✓] $1${NC}"; }
fail() { echo -e "${RED}[✗] $1${NC}"; }
info() { echo -e "${YELLOW}[→] $1${NC}"; }

SSH_CMD="sshpass -p '${VPS_PASS}' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@${VPS_IP}"

# Check dependencies
for cmd in curl sshpass ssh; do
  if ! command -v "$cmd" &>/dev/null; then
    fail "Required command '$cmd' not found. Install it first."
    exit 1
  fi
done

echo ""
echo "=========================================="
echo " zelcycling.com — WordPress Install"
echo "=========================================="
echo ""

# ─── Step 1: Create Domain via Plesk REST API ───────────────────────
info "Step 1/6 — Creating domain subscription in Plesk..."

STEP1=$(curl -k -s -w "\n%{http_code}" -X POST "${PLESK_API}/domains" \
  -u "root:${VPS_PASS}" \
  -H "Content-Type: application/json" \
  -d "{
    \"name\": \"${DOMAIN}\",
    \"hosting_type\": \"virtual\",
    \"hosting_settings\": {
      \"ftp_login\": \"${FTP_LOGIN}\",
      \"ftp_password\": \"${FTP_PASS}\"
    }
  }" 2>&1)

STEP1_CODE=$(echo "$STEP1" | tail -1)
STEP1_BODY=$(echo "$STEP1" | sed '$d')

if [[ "$STEP1_CODE" =~ ^2 ]] || echo "$STEP1_BODY" | grep -qi "already exists\|DomainExists"; then
  ok "Domain '${DOMAIN}' created (or already exists). HTTP ${STEP1_CODE}"
else
  fail "Domain creation failed. HTTP ${STEP1_CODE}"
  echo "$STEP1_BODY"
  echo ""
  info "Continuing anyway — domain may already exist..."
fi

# ─── Step 2: Verify SSH Access ──────────────────────────────────────
info "Step 2/6 — Verifying SSH access..."

STEP2=$(sshpass -p "${VPS_PASS}" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
  -o ConnectTimeout=15 root@${VPS_IP} "echo SSH_OK && plesk version" 2>&1)

if echo "$STEP2" | grep -q "SSH_OK"; then
  ok "SSH access verified"
  echo "    $(echo "$STEP2" | grep -v SSH_OK | head -1)"
else
  fail "SSH access failed:"
  echo "$STEP2"
  exit 1
fi

# ─── Step 3: Install WordPress ──────────────────────────────────────
info "Step 3/6 — Installing WordPress via WP Toolkit..."

STEP3=$(sshpass -p "${VPS_PASS}" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
  root@${VPS_IP} "plesk ext wp-toolkit --install -domain ${DOMAIN}" 2>&1)

if echo "$STEP3" | grep -qi "success\|installed\|already"; then
  ok "WordPress installed via WP Toolkit"
else
  info "WP Toolkit install returned: $(echo "$STEP3" | head -2)"
  info "Falling back to manual WP-CLI install..."

  sshpass -p "${VPS_PASS}" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
    root@${VPS_IP} bash << REMOTE_EOF
set -e

# Find document root
DOCROOT=\$(plesk bin domain --info ${DOMAIN} | grep "Document root" | awk '{print \$NF}')
echo "Document root: \$DOCROOT"

# Download WP core
if [ ! -f "\$DOCROOT/wp-load.php" ]; then
  wp --allow-root core download --path="\$DOCROOT" --locale=en_US
  echo "WordPress core downloaded"
else
  echo "WordPress core already present"
fi

# Create database
plesk db "CREATE DATABASE IF NOT EXISTS ${DB_NAME} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" || true
plesk db "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';" || true
plesk db "GRANT ALL PRIVILEGES ON ${DB_NAME}.* TO '${DB_USER}'@'localhost'; FLUSH PRIVILEGES;"
echo "Database ready"

# Create wp-config
if [ ! -f "\$DOCROOT/wp-config.php" ]; then
  wp --allow-root config create \
    --path="\$DOCROOT" \
    --dbname="${DB_NAME}" \
    --dbuser="${DB_USER}" \
    --dbpass="${DB_PASS}" \
    --dbhost="localhost"
  echo "wp-config.php created"
else
  echo "wp-config.php already exists"
fi

# Install WP
if ! wp --allow-root core is-installed --path="\$DOCROOT" 2>/dev/null; then
  wp --allow-root core install \
    --path="\$DOCROOT" \
    --url="https://${DOMAIN}" \
    --title="Zel Cycling" \
    --admin_user="${WP_ADMIN_USER}" \
    --admin_password="${WP_ADMIN_PASS}" \
    --admin_email="${WP_ADMIN_EMAIL}" \
    --skip-email
  echo "WordPress installed successfully."
else
  echo "WordPress already installed"
fi
REMOTE_EOF

  if [ $? -eq 0 ]; then
    ok "WordPress installed via manual WP-CLI"
  else
    fail "WordPress installation failed"
    exit 1
  fi
fi

# ─── Step 4: Configure WordPress ────────────────────────────────────
info "Step 4/6 — Configuring WordPress..."

sshpass -p "${VPS_PASS}" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
  root@${VPS_IP} bash << REMOTE_EOF
set -e
DOCROOT=\$(plesk bin domain --info ${DOMAIN} | grep "Document root" | awk '{print \$NF}')

# Set permalinks
wp --allow-root rewrite structure '/%postname%/' --path="\$DOCROOT" --hard
echo "Permalinks set to /%postname%/"

# Set timezone
wp --allow-root option update timezone_string 'Europe/Madrid' --path="\$DOCROOT"
echo "Timezone set to Europe/Madrid"

# Remove default content
wp --allow-root post delete 1 --force --path="\$DOCROOT" 2>/dev/null || true
wp --allow-root post delete 2 --force --path="\$DOCROOT" 2>/dev/null || true
echo "Default content removed"

# Verify
echo "WP Version: \$(wp --allow-root core version --path="\$DOCROOT")"
echo "Site URL: \$(wp --allow-root option get siteurl --path="\$DOCROOT")"
REMOTE_EOF

if [ $? -eq 0 ]; then
  ok "WordPress configured"
else
  fail "WordPress configuration had errors"
fi

# ─── Step 5: Request Let's Encrypt SSL ──────────────────────────────
info "Step 5/6 — Requesting Let's Encrypt SSL certificate..."

STEP5=$(curl -k -s -w "\n%{http_code}" -X POST \
  "${PLESK_API}/domains/${DOMAIN}/ssl-certificates/lets-encrypt" \
  -u "root:${VPS_PASS}" \
  -H "Content-Type: application/json" \
  -d "{
    \"email\": \"${WP_ADMIN_EMAIL}\",
    \"include_www\": true,
    \"secure_plesk\": false
  }" 2>&1)

STEP5_CODE=$(echo "$STEP5" | tail -1)
STEP5_BODY=$(echo "$STEP5" | sed '$d')

if [[ "$STEP5_CODE" =~ ^2 ]]; then
  ok "SSL certificate requested successfully. HTTP ${STEP5_CODE}"
else
  fail "SSL request returned HTTP ${STEP5_CODE}"
  echo "    ${STEP5_BODY}"
  info "Note: DNS A record must point ${DOMAIN} → ${VPS_IP} for Let's Encrypt to work"
fi

# ─── Step 6: Final Verification ─────────────────────────────────────
info "Step 6/6 — Running final verification..."

echo ""
sshpass -p "${VPS_PASS}" ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
  root@${VPS_IP} bash << REMOTE_EOF
DOCROOT=\$(plesk bin domain --info ${DOMAIN} | grep "Document root" | awk '{print \$NF}')
echo "=== WP Version ==="
wp --allow-root core version --path="\$DOCROOT"
echo "=== Site URL ==="
wp --allow-root option get siteurl --path="\$DOCROOT"
echo "=== Home URL ==="
wp --allow-root option get home --path="\$DOCROOT"
echo "=== Users ==="
wp --allow-root user list --path="\$DOCROOT" --fields=ID,user_login,user_email,roles
echo "=== Permalinks ==="
wp --allow-root option get permalink_structure --path="\$DOCROOT"
REMOTE_EOF

echo ""
info "Checking HTTPS response..."
HTTP_CODE=$(curl -k -s -o /dev/null -w "%{http_code}" "https://${DOMAIN}" 2>&1 || echo "000")
if [ "$HTTP_CODE" = "200" ]; then
  ok "https://${DOMAIN} returns HTTP 200"
else
  info "https://${DOMAIN} returned HTTP ${HTTP_CODE} (SSL may still be provisioning)"
fi

echo ""
echo "=========================================="
echo " INSTALLATION COMPLETE"
echo "=========================================="
echo ""
echo " Site:     https://${DOMAIN}"
echo " Admin:    https://${DOMAIN}/wp-admin"
echo " Username: ${WP_ADMIN_USER}"
echo " Password: ${WP_ADMIN_PASS}"
echo ""
echo " ⚠  ROTATE ALL PASSWORDS after confirming install works!"
echo ""
