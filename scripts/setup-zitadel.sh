#!/usr/bin/env bash
# scripts/setup-zitadel.sh
#
# Fully automated Zitadel OIDC setup for CDCF.
# Reads the automation PAT (written by Zitadel on first init),
# then creates project, OIDC apps, and roles via the Management API.
# All credentials are written to .env.local.
#
# Usage:
#   ./scripts/setup-zitadel.sh                       # Full automated setup
#   ./scripts/setup-zitadel.sh --force-secrets        # Regenerate client secrets
#
# Prerequisites:
#   - Docker Compose stack running (zitadel + zitadel-db)
#   - jq installed

set -euo pipefail

# ── Colors ──────────────────────────────────────────────────────────

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# ── Configuration ───────────────────────────────────────────────────

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="${SCRIPT_DIR}/.."
ENV_FILE="${PROJECT_DIR}/.env.local"
PAT_FILE="${PROJECT_DIR}/.zitadel-data/automation-user.pat"

ZITADEL_PORT="${ZITADEL_EXTERNAL_PORT:-8085}"
ZITADEL_URL="http://localhost:${ZITADEL_PORT}"
NEXTJS_PORT=3000
WP_DEV_PORT="${WP_DEV_PORT:-8000}"

MAX_RETRIES=60
RETRY_INTERVAL=5

FORCE_SECRETS=false
for arg in "$@"; do
  case $arg in
    --force-secrets) FORCE_SECRETS=true ;;
  esac
done

# ── Helpers ─────────────────────────────────────────────────────────

log()    { echo -e "${BLUE}[setup]${NC} $*"; }
ok()     { echo -e "${GREEN}  ✓${NC} $*"; }
warn()   { echo -e "${YELLOW}  !${NC} $*"; }
fail()   { echo -e "${RED}  ✗${NC} $*" >&2; exit 1; }

env_get() {
  local key="$1"
  grep "^${key}=" "$ENV_FILE" 2>/dev/null | head -1 | cut -d= -f2- || true
}

env_set() {
  local key="$1" value="$2"
  if [ ! -f "$ENV_FILE" ]; then
    touch "$ENV_FILE"
  fi
  if grep -q "^${key}=" "$ENV_FILE" 2>/dev/null; then
    sed -i "s|^${key}=.*|${key}=${value}|" "$ENV_FILE"
  else
    echo "${key}=${value}" >> "$ENV_FILE"
  fi
}

# ── 1. Wait for Zitadel ────────────────────────────────────────────

log "Waiting for Zitadel at ${ZITADEL_URL}..."
for i in $(seq 1 $MAX_RETRIES); do
  if curl -sf "${ZITADEL_URL}/debug/healthz" > /dev/null 2>&1; then
    ok "Zitadel is ready"
    break
  fi
  if [ "$i" -eq "$MAX_RETRIES" ]; then
    fail "Zitadel did not become ready after $((MAX_RETRIES * RETRY_INTERVAL))s"
  fi
  echo "  Attempt $i/$MAX_RETRIES — waiting ${RETRY_INTERVAL}s..."
  sleep $RETRY_INTERVAL
done

# ── 2. Read automation PAT ──────────────────────────────────────────

log "Reading automation PAT..."

# First check if we have a saved PAT in .env.local that still works
SAVED_PAT=$(env_get "ZITADEL_AUTOMATION_PAT")
if [ -n "$SAVED_PAT" ]; then
  CHECK=$(curl -sf -o /dev/null -w "%{http_code}" \
    "${ZITADEL_URL}/auth/v1/users/me" \
    -H "Authorization: Bearer $SAVED_PAT" 2>/dev/null || echo "000")
  if [ "$CHECK" = "200" ]; then
    PAT="$SAVED_PAT"
    ok "Reusing saved automation PAT"
  else
    warn "Saved PAT no longer valid"
  fi
fi

# Read PAT from the file created by Zitadel on first init
if [ -z "${PAT:-}" ]; then
  if [ ! -f "$PAT_FILE" ]; then
    fail "PAT file not found at ${PAT_FILE}. Is Zitadel running with the correct volume mount?"
  fi

  PAT=$(cat "$PAT_FILE")
  if [ -z "$PAT" ]; then
    fail "PAT file is empty"
  fi

  # Verify it works
  CHECK=$(curl -sf -o /dev/null -w "%{http_code}" \
    "${ZITADEL_URL}/auth/v1/users/me" \
    -H "Authorization: Bearer $PAT" 2>/dev/null || echo "000")

  if [ "$CHECK" != "200" ]; then
    fail "PAT from file is not valid (HTTP $CHECK). Zitadel may need to be re-initialized."
  fi

  ok "PAT loaded from ${PAT_FILE}"
  env_set "ZITADEL_AUTOMATION_PAT" "$PAT"
fi

# ── 3. Find or create CDCF project ─────────────────────────────────

log "Ensuring CDCF project exists..."
PROJECT_ID=$(curl -sf -X POST "${ZITADEL_URL}/management/v1/projects/_search" \
  -H "Authorization: Bearer $PAT" \
  -H "Content-Type: application/json" \
  -d '{"queries": [{"nameQuery": {"name": "CDCF", "method": "TEXT_QUERY_METHOD_EQUALS"}}]}' \
  | jq -r '(.result // [])[0].id // empty')

if [ -z "$PROJECT_ID" ]; then
  PROJECT_ID=$(curl -sf -X POST "${ZITADEL_URL}/management/v1/projects" \
    -H "Authorization: Bearer $PAT" \
    -H "Content-Type: application/json" \
    -d '{"name": "CDCF", "projectRoleAssertion": true}' \
    | jq -r '.id')
  ok "Project created: $PROJECT_ID"
else
  ok "Project exists: $PROJECT_ID"
fi

# ── 4. Create / find OIDC apps ─────────────────────────────────────

EXISTING_APPS=$(curl -sf -X POST "${ZITADEL_URL}/management/v1/projects/${PROJECT_ID}/apps/_search" \
  -H "Authorization: Bearer $PAT" \
  -H "Content-Type: application/json" \
  -d '{}')

create_or_get_app() {
  local app_name="$1"
  local redirect_uri="$2"
  local post_logout_uri="$3"
  local env_id_key="$4"
  local env_secret_key="$5"

  log "Ensuring app '${app_name}'..."

  local app_id client_id client_secret

  app_id=$(echo "$EXISTING_APPS" | jq -r --arg n "$app_name" '(.result // [])[] | select(.name == $n) | .id // empty')

  if [ -n "$app_id" ]; then
    client_id=$(echo "$EXISTING_APPS" | jq -r --arg n "$app_name" '(.result // [])[] | select(.name == $n) | .oidcConfig.clientId // empty')

    local existing_secret
    existing_secret=$(env_get "$env_secret_key")

    if [ -n "$existing_secret" ] && [ "$FORCE_SECRETS" != "true" ]; then
      client_secret="$existing_secret"
      ok "Reusing existing secret for ${app_name}"
    else
      client_secret=$(curl -sf -X POST \
        "${ZITADEL_URL}/management/v1/projects/${PROJECT_ID}/apps/${app_id}/oidc_config/_generate_client_secret" \
        -H "Authorization: Bearer $PAT" \
        -H "Content-Type: application/json" \
        | jq -r '.clientSecret')
      ok "Generated new secret for ${app_name}"
    fi

    # Ensure config is up to date (tolerate "no changes" 400 response)
    curl -s -o /dev/null -w '' -X PUT \
      "${ZITADEL_URL}/management/v1/projects/${PROJECT_ID}/apps/${app_id}/oidc_config" \
      -H "Authorization: Bearer $PAT" \
      -H "Content-Type: application/json" \
      -d "{
        \"redirectUris\": [\"${redirect_uri}\"],
        \"postLogoutRedirectUris\": [\"${post_logout_uri}\"],
        \"responseTypes\": [\"OIDC_RESPONSE_TYPE_CODE\"],
        \"grantTypes\": [\"OIDC_GRANT_TYPE_AUTHORIZATION_CODE\", \"OIDC_GRANT_TYPE_REFRESH_TOKEN\"],
        \"appType\": \"OIDC_APP_TYPE_WEB\",
        \"authMethodType\": \"OIDC_AUTH_METHOD_TYPE_POST\",
        \"accessTokenType\": \"OIDC_TOKEN_TYPE_JWT\",
        \"devMode\": true,
        \"accessTokenRoleAssertion\": true,
        \"idTokenRoleAssertion\": true,
        \"idTokenUserinfoAssertion\": true
      }" || true
  else
    local resp
    resp=$(curl -sf -X POST "${ZITADEL_URL}/management/v1/projects/${PROJECT_ID}/apps/oidc" \
      -H "Authorization: Bearer $PAT" \
      -H "Content-Type: application/json" \
      -d "{
        \"name\": \"${app_name}\",
        \"redirectUris\": [\"${redirect_uri}\"],
        \"postLogoutRedirectUris\": [\"${post_logout_uri}\"],
        \"responseTypes\": [\"OIDC_RESPONSE_TYPE_CODE\"],
        \"grantTypes\": [\"OIDC_GRANT_TYPE_AUTHORIZATION_CODE\", \"OIDC_GRANT_TYPE_REFRESH_TOKEN\"],
        \"appType\": \"OIDC_APP_TYPE_WEB\",
        \"authMethodType\": \"OIDC_AUTH_METHOD_TYPE_POST\",
        \"accessTokenType\": \"OIDC_TOKEN_TYPE_JWT\",
        \"devMode\": true,
        \"accessTokenRoleAssertion\": true,
        \"idTokenRoleAssertion\": true,
        \"idTokenUserinfoAssertion\": true
      }")
    client_id=$(echo "$resp" | jq -r '.clientId')
    client_secret=$(echo "$resp" | jq -r '.clientSecret')
    ok "App '${app_name}' created"
  fi

  env_set "$env_id_key" "$client_id"
  env_set "$env_secret_key" "$client_secret"
  ok "Saved ${env_id_key} and ${env_secret_key} to .env.local"
}

create_or_get_app \
  "Next.js Frontend" \
  "http://localhost:${NEXTJS_PORT}/api/auth/callback/zitadel" \
  "http://localhost:${NEXTJS_PORT}" \
  "AUTH_ZITADEL_ID" \
  "AUTH_ZITADEL_SECRET"

create_or_get_app \
  "WordPress CMS" \
  "http://localhost:${WP_DEV_PORT}/wp-admin/admin-ajax.php?action=openid-connect-authorize" \
  "http://localhost:${WP_DEV_PORT}/wp-login.php" \
  "ZITADEL_WP_CLIENT_ID" \
  "ZITADEL_WP_CLIENT_SECRET"

# ── 5. Ensure project roles ────────────────────────────────────────

log "Ensuring project roles..."
for role in admin editor member; do
  curl -sf -X POST "${ZITADEL_URL}/management/v1/projects/${PROJECT_ID}/roles" \
    -H "Authorization: Bearer $PAT" \
    -H "Content-Type: application/json" \
    -d "{\"roleKey\": \"${role}\", \"displayName\": \"${role}\", \"group\": \"app-roles\"}" > /dev/null 2>&1 || true
done
ok "Roles ensured: admin, editor, member"

# ── 6. Generate AUTH_SECRET if missing ──────────────────────────────

if [ -z "$(env_get AUTH_SECRET)" ]; then
  log "Generating AUTH_SECRET..."
  env_set "AUTH_SECRET" "$(openssl rand -base64 32)"
  ok "AUTH_SECRET generated and saved"
else
  ok "AUTH_SECRET already set"
fi

# ── 7. Ensure ZITADEL_ISSUER_URL is set ────────────────────────────

if [ -z "$(env_get ZITADEL_ISSUER_URL)" ]; then
  env_set "ZITADEL_ISSUER_URL" "$ZITADEL_URL"
  ok "ZITADEL_ISSUER_URL set to $ZITADEL_URL"
fi

# ── Done ────────────────────────────────────────────────────────────

echo
echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}  Zitadel setup complete!${NC}"
echo -e "${GREEN}========================================${NC}"
echo
echo -e "  Zitadel Console:  ${ZITADEL_URL}/ui/console"
echo -e "  Credentials:      stored in .env.local"
echo
echo -e "  Next steps:"
echo -e "    1. Restart services to pick up new credentials:"
echo -e "       ${YELLOW}docker compose up -d --force-recreate wordpress${NC}"
echo -e "       ${YELLOW}npm run dev${NC}"
echo
