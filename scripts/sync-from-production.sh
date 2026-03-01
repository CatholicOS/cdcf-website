#!/bin/bash
set -euo pipefail

# ============================================================================
# sync-from-production.sh
#
# Syncs the production WordPress database into the local Docker environment.
# Uses an SSH tunnel for DB access and wp-cli search-replace for safe
# serialization-aware URL/prefix replacements.
#
# Usage:
#   bash scripts/sync-from-production.sh [--with-uploads]
#
# Prerequisites:
#   - Docker containers running (docker compose up)
#   - SSH key configured for production access
#   - .env and .env.production files populated
# ============================================================================

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
DUMP_FILE="$(mktemp /tmp/cdcf-prod-dump.XXXXXX.sql)"
TUNNEL_PID=""
LOCAL_TUNNEL_PORT=13306
WITH_UPLOADS=false

# ── Parse flags ──

for arg in "$@"; do
  case "$arg" in
    --with-uploads) WITH_UPLOADS=true ;;
    *) echo "Unknown option: $arg"; exit 1 ;;
  esac
done

# ── Cleanup on exit ──

cleanup() {
  echo ""
  echo "Cleaning up..."
  [ -f "$DUMP_FILE" ] && rm -f "$DUMP_FILE" && echo "  Removed temp dump file"
  if [ -n "$TUNNEL_PID" ] && kill -0 "$TUNNEL_PID" 2>/dev/null; then
    kill "$TUNNEL_PID" 2>/dev/null
    echo "  Killed SSH tunnel (PID $TUNNEL_PID)"
  fi
}
trap cleanup EXIT

# ── Load config ──

echo "=== CDCF Production → Local Database Sync ==="
echo ""

if [ ! -f "$PROJECT_DIR/.env" ]; then
  echo "ERROR: .env file not found. Run from the project root."
  exit 1
fi

# shellcheck source=/dev/null
source "$PROJECT_DIR/.env"

if [ ! -f "$PROJECT_DIR/.env.production" ]; then
  echo "ERROR: .env.production not found."
  echo "Copy .env.production.example to .env.production and fill in the values."
  echo ""
  echo "To auto-populate from production wp-config.php, run:"
  echo "  bash scripts/sync-from-production.sh --init"
  exit 1
fi

# shellcheck source=/dev/null
source "$PROJECT_DIR/.env.production"

# ── Validate requirements ──

echo "Checking requirements..."

# Required env vars
for var in PROD_SSH_USER PROD_SSH_HOST PROD_DB_NAME PROD_DB_USER PROD_DB_PASSWORD PROD_DB_PREFIX PROD_SITE_URL; do
  if [ -z "${!var:-}" ]; then
    echo "ERROR: $var is not set in .env.production"
    exit 1
  fi
done

# SSH key
PROD_SSH_KEY="${PROD_SSH_KEY:-$HOME/.ssh/cdcf-deploy}"
PROD_SSH_KEY="${PROD_SSH_KEY/#\~/$HOME}"
if [ ! -f "$PROD_SSH_KEY" ]; then
  echo "ERROR: SSH key not found at $PROD_SSH_KEY"
  exit 1
fi

# Docker containers
DB_CONTAINER=$(docker compose -f "$PROJECT_DIR/docker-compose.yml" ps -q db 2>/dev/null || true)
if [ -z "$DB_CONTAINER" ]; then
  echo "ERROR: Database container is not running. Start it with: docker compose up -d db wordpress"
  exit 1
fi

WP_CONTAINER=$(docker compose -f "$PROJECT_DIR/docker-compose.yml" ps -q wordpress 2>/dev/null || true)
if [ -z "$WP_CONTAINER" ]; then
  echo "ERROR: WordPress container is not running. Start it with: docker compose up -d db wordpress"
  exit 1
fi

echo "  All checks passed."
echo ""

# ── Local DB config ──

LOCAL_DB_NAME="${WP_DB_NAME:-wordpress}"
LOCAL_DB_USER="${WP_DB_USER:-wordpress}"
LOCAL_DB_PASSWORD="${WP_DB_PASSWORD}"
LOCAL_DB_ROOT_PASSWORD="${WP_DB_ROOT_PASSWORD}"
LOCAL_PREFIX="wp_"
LOCAL_SITE_URL="http://localhost:8000"

# ── Phase 1: SSH tunnel ──

echo "Phase 1: Opening SSH tunnel to production MySQL..."

ssh -f -N \
  -o StrictHostKeyChecking=accept-new \
  -o ConnectTimeout=10 \
  -i "$PROD_SSH_KEY" \
  -L "${LOCAL_TUNNEL_PORT}:localhost:3306" \
  "${PROD_SSH_USER}@${PROD_SSH_HOST}"

# Find the SSH tunnel PID
TUNNEL_PID=$(pgrep -f "ssh.*-L.*${LOCAL_TUNNEL_PORT}:localhost:3306.*${PROD_SSH_HOST}" | head -1)

if [ -z "$TUNNEL_PID" ]; then
  echo "ERROR: Failed to establish SSH tunnel"
  exit 1
fi

echo "  SSH tunnel established (PID $TUNNEL_PID, local port $LOCAL_TUNNEL_PORT)"

# Brief pause to let the tunnel stabilize
sleep 1

# ── Phase 2: Dump production database ──

echo ""
echo "Phase 2: Dumping production database..."

docker exec "$DB_CONTAINER" \
  mariadb-dump \
    -h host.docker.internal \
    -P "$LOCAL_TUNNEL_PORT" \
    -u "$PROD_DB_USER" \
    -p"$PROD_DB_PASSWORD" \
    --single-transaction \
    --routines \
    --triggers \
    "$PROD_DB_NAME" > "$DUMP_FILE"

DUMP_SIZE=$(du -h "$DUMP_FILE" | cut -f1)
echo "  Dump complete ($DUMP_SIZE)"

# ── Phase 3: Rename table prefixes in SQL ──

echo ""
echo "Phase 3: Renaming table prefix ${PROD_DB_PREFIX} → ${LOCAL_PREFIX} in SQL dump..."

if [ "$PROD_DB_PREFIX" != "$LOCAL_PREFIX" ]; then
  sed -i \
    -e "s/\`${PROD_DB_PREFIX}/\`${LOCAL_PREFIX}/g" \
    -e "s/CREATE TABLE IF NOT EXISTS ${PROD_DB_PREFIX}/CREATE TABLE IF NOT EXISTS ${LOCAL_PREFIX}/g" \
    -e "s/INSERT INTO ${PROD_DB_PREFIX}/INSERT INTO ${LOCAL_PREFIX}/g" \
    -e "s/DROP TABLE IF EXISTS \`${PROD_DB_PREFIX}/DROP TABLE IF EXISTS \`${LOCAL_PREFIX}/g" \
    -e "s/LOCK TABLES \`${PROD_DB_PREFIX}/LOCK TABLES \`${LOCAL_PREFIX}/g" \
    "$DUMP_FILE"
  echo "  Prefix replacement complete"
else
  echo "  Prefixes match, no renaming needed"
fi

# ── Phase 4: Import into local database ──

echo ""
echo "Phase 4: Importing dump into local database..."

# Drop and recreate the local database to ensure a clean slate
docker exec "$DB_CONTAINER" \
  mariadb -u root -p"$LOCAL_DB_ROOT_PASSWORD" \
    -e "DROP DATABASE IF EXISTS \`${LOCAL_DB_NAME}\`; CREATE DATABASE \`${LOCAL_DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON \`${LOCAL_DB_NAME}\`.* TO '${LOCAL_DB_USER}'@'%';"

# Import the dump
docker exec -i "$DB_CONTAINER" \
  mariadb -u root -p"$LOCAL_DB_ROOT_PASSWORD" "$LOCAL_DB_NAME" < "$DUMP_FILE"

echo "  Import complete"

# ── Phase 5: wp-cli search-replace (serialization-safe) ──

echo ""
echo "Phase 5: Running wp-cli search-replace (serialization-safe)..."

# Helper function to run wp-cli via docker compose
wpcli() {
  docker compose -f "$PROJECT_DIR/docker-compose.yml" run --rm -T wpcli wp "$@" --allow-root 2>&1
}

# Replace production site URL → local
echo "  Replacing site URLs..."
wpcli search-replace "$PROD_SITE_URL" "$LOCAL_SITE_URL" --all-tables --precise --skip-columns=guid

# Replace protocol-relative URLs
PROD_HOST="${PROD_SITE_URL#https://}"
PROD_HOST="${PROD_HOST#http://}"
LOCAL_HOST="${LOCAL_SITE_URL#http://}"
LOCAL_HOST="${LOCAL_HOST#https://}"
echo "  Replacing protocol-relative URLs..."
wpcli search-replace "//${PROD_HOST}" "//${LOCAL_HOST}" --all-tables --precise --skip-columns=guid

# Replace table prefix in meta keys (e.g. fZEmu9_capabilities → wp_capabilities)
if [ "$PROD_DB_PREFIX" != "$LOCAL_PREFIX" ]; then
  echo "  Replacing prefix in meta keys..."
  wpcli search-replace "${PROD_DB_PREFIX}capabilities" "${LOCAL_PREFIX}capabilities" --all-tables --precise
  wpcli search-replace "${PROD_DB_PREFIX}user_level" "${LOCAL_PREFIX}user_level" --all-tables --precise
  wpcli search-replace "${PROD_DB_PREFIX}autosave_draft_ids" "${LOCAL_PREFIX}autosave_draft_ids" --all-tables --precise
  wpcli search-replace "${PROD_DB_PREFIX}user-settings" "${LOCAL_PREFIX}user-settings" --all-tables --precise
  wpcli search-replace "${PROD_DB_PREFIX}user-settings-time" "${LOCAL_PREFIX}user-settings-time" --all-tables --precise
  wpcli search-replace "${PROD_DB_PREFIX}dashboard_quick_press_last_post_id" "${LOCAL_PREFIX}dashboard_quick_press_last_post_id" --all-tables --precise
fi

# Fix plugin directory name mismatches
echo "  Fixing plugin paths..."
wpcli search-replace "wp-graphql-polylang-0.7.1" "wp-graphql-polylang" --all-tables --precise

# ── Phase 6: Reset admin credentials ──

echo ""
echo "Phase 6: Resetting admin credentials..."

wpcli user update 1 --user_pass=admin --user_login=admin --user_email=admin@cdcf.dev --skip-email

echo "  Admin credentials reset (admin / admin)"

# ── Phase 7: Flush rewrite rules and caches ──

echo ""
echo "Phase 7: Flushing rewrite rules and caches..."

wpcli rewrite structure '/%postname%/' --hard
wpcli rewrite flush --hard
wpcli cache flush

echo "  Rewrite rules and caches flushed"

# ── Phase 8: Optional media sync ──

if [ "$WITH_UPLOADS" = true ]; then
  echo ""
  echo "Phase 8: Syncing uploads from production..."

  PROD_WP_PATH="${PROD_WP_PATH:-/cms.catholicdigitalcommons.org}"

  # Re-fetch container ID since wpcli runs may have recreated it
  WP_CONTAINER=$(docker compose -f "$PROJECT_DIR/docker-compose.yml" ps -q wordpress 2>/dev/null || true)
  if [ -z "$WP_CONTAINER" ]; then
    echo "ERROR: WordPress container is not running."
    exit 1
  fi

  WP_CONTENT_DIR=$(docker exec "$WP_CONTAINER" bash -c 'echo ${WORDPRESS_DATA_DIR:-/var/www/html}' 2>/dev/null)

  # Create a temp directory for the download
  UPLOADS_TMP="$(mktemp -d /tmp/cdcf-uploads.XXXXXX)"

  echo "  Downloading uploads from production..."
  scp -r -i "$PROD_SSH_KEY" \
    "${PROD_SSH_USER}@${PROD_SSH_HOST}:${PROD_WP_PATH}/wp-content/uploads" \
    "$UPLOADS_TMP/"

  echo "  Copying uploads into WordPress container..."
  docker cp "$UPLOADS_TMP/uploads/." "$WP_CONTAINER:${WP_CONTENT_DIR}/wp-content/uploads/"

  # Fix ownership
  docker exec "$WP_CONTAINER" chown -R www-data:www-data "${WP_CONTENT_DIR}/wp-content/uploads"

  rm -rf "$UPLOADS_TMP"
  echo "  Uploads synced"
else
  echo ""
  echo "Skipping uploads sync (pass --with-uploads to include media files)"
fi

# ── Phase 9: Restore container port mappings ──

echo ""
echo "Phase 9: Restoring container port mappings..."

# wpcli runs can recreate the wordpress container without its port mappings
# from docker-compose.override.yml, so re-up it to restore them.
docker compose -f "$PROJECT_DIR/docker-compose.yml" up -d wordpress 2>&1 | grep -v "^$"

echo "  Port mappings restored"

# ── Done ──

echo ""
echo "========================================="
echo "  Database sync complete!"
echo "========================================="
echo ""
echo "  WP Admin:  $LOCAL_SITE_URL/wp-admin/"
echo "  Username:  admin"
echo "  Password:  admin"
echo ""
echo "  GraphQL:   $LOCAL_SITE_URL/graphql"
echo ""
echo "  To verify:"
echo "    curl -s $LOCAL_SITE_URL/graphql -H 'Content-Type: application/json' \\"
echo "      -d '{\"query\":\"{ __typename }\"}'"
echo ""
