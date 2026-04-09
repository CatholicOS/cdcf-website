#!/bin/bash
# CDCF Redis Queue Worker
# Continuously processes translation jobs from the Redis queue
# by calling the WordPress REST API endpoint.
#
# Required environment variables (set in /etc/cdcf-queue.env):
#   WP_REST_URL       - e.g. https://cms.catholicdigitalcommons.org/wp-json
#   WP_APP_USERNAME   - WordPress Application Password username
#   WP_APP_PASSWORD   - WordPress Application Password
#
# Optional environment variables:
#   POLL_INTERVAL     - Seconds between polling cycles (default: 15)
#   MAX_TIME          - Max curl request timeout in seconds (default: 300)
#   CONCURRENCY       - Number of parallel workers per cycle (default: 1)
#   BATCH_SIZE        - Jobs per worker per cycle (default: 10, or 1 when CONCURRENCY > 1)
#
# ─── Deployment ───────────────────────────────────────────────────────
#
# 1. Copy this script to /usr/local/bin/ and make it executable:
#
#      sudo cp scripts/cdcf_queue_worker.sh /usr/local/bin/cdcf_queue_worker.sh
#      sudo chmod +x /usr/local/bin/cdcf_queue_worker.sh
#
# 2. Create the environment file at /etc/cdcf-queue.env:
#
#      WP_REST_URL=https://cms.catholicdigitalcommons.org/wp-json
#      WP_APP_USERNAME=your-username
#      WP_APP_PASSWORD=xxxx xxxx xxxx xxxx xxxx xxxx
#      CONCURRENCY=5
#
# 3. Create a systemd service at /etc/systemd/system/cdcf-queue-worker.service:
#
#      [Unit]
#      Description=CDCF Redis Queue Worker
#      After=network.target redis-server.service
#      Wants=redis-server.service
#
#      [Service]
#      Type=simple
#      EnvironmentFile=/etc/cdcf-queue.env
#      ExecStart=/usr/local/bin/cdcf_queue_worker.sh
#      Restart=always
#      RestartSec=10
#      StandardOutput=journal
#      StandardError=journal
#      SyslogIdentifier=cdcf-queue-worker
#
#      [Install]
#      WantedBy=multi-user.target
#
# 4. Enable and start the service:
#
#      sudo systemctl daemon-reload
#      sudo systemctl enable cdcf-queue-worker
#      sudo systemctl start cdcf-queue-worker
#
# 5. Check logs:
#
#      journalctl -u cdcf-queue-worker -f
#
# ──────────────────────────────────────────────────────────────────────

POLL_INTERVAL=${POLL_INTERVAL:-15}
MAX_TIME=${MAX_TIME:-300}
CONCURRENCY=${CONCURRENCY:-1}
# Default batch_size: 10 for single worker, 1 for parallel workers.
if [ "$CONCURRENCY" -gt 1 ]; then
    BATCH_SIZE=${BATCH_SIZE:-1}
else
    BATCH_SIZE=${BATCH_SIZE:-10}
fi

if [ -z "$WP_REST_URL" ] || [ -z "$WP_APP_USERNAME" ] || [ -z "$WP_APP_PASSWORD" ]; then
    echo "ERROR: WP_REST_URL, WP_APP_USERNAME, and WP_APP_PASSWORD must be set."
    exit 1
fi

ENDPOINT="${WP_REST_URL}/cdcf/v1/process-queue"
AUTH=$(echo -n "${WP_APP_USERNAME}:${WP_APP_PASSWORD}" | base64)

DISPOSABLE_DOMAINS_ENDPOINT="${WP_REST_URL}/cdcf/v1/update-disposable-domains"
DAILY_INTERVAL=${DAILY_INTERVAL:-86400}   # 24 hours in seconds

echo "Starting CDCF Redis Queue worker..."
echo "  Endpoint: ${ENDPOINT}"
echo "  Poll interval: ${POLL_INTERVAL}s"
echo "  Max request time: ${MAX_TIME}s"
echo "  Concurrency: ${CONCURRENCY}"
echo "  Batch size: ${BATCH_SIZE}"
echo "  Daily tasks interval: ${DAILY_INTERVAL}s"

# Wait a bit after service start to let WordPress finish booting.
sleep 10

# ─── Daily maintenance tasks ──────────────────────────────────────────
# Tracks the last run time and fires once per DAILY_INTERVAL.

LAST_DAILY_RUN=0

run_daily_tasks() {
    local NOW
    NOW=$(date +%s)
    if (( NOW - LAST_DAILY_RUN < DAILY_INTERVAL )); then
        return
    fi
    LAST_DAILY_RUN=$NOW

    echo "$(date -Iseconds) Running daily tasks..."

    # Update disposable email domain blocklist.
    local RESPONSE HTTP_CODE
    RESPONSE=$(curl -s -w "\n%{http_code}" --max-time 60 \
        -X POST "${DISPOSABLE_DOMAINS_ENDPOINT}" \
        -H "Authorization: Basic ${AUTH}" 2>&1)
    HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
    RESPONSE=$(echo "$RESPONSE" | sed '$d')

    if [ "$HTTP_CODE" = "200" ]; then
        local DOMAIN_COUNT
        DOMAIN_COUNT=$(echo "$RESPONSE" | python3 -c "
import json, sys
try:
    d = json.load(sys.stdin)
    print(d.get('domains', '?'))
except:
    print('?')
" 2>/dev/null)
        echo "$(date -Iseconds) Disposable domains list updated (${DOMAIN_COUNT} domains)"
    else
        echo "$(date -Iseconds) WARNING: disposable domains update failed (HTTP ${HTTP_CODE}): ${RESPONSE:0:200}"
    fi
}

# ─── Queue processing ────────────────────────────────────────────────

# process_one fires a single REST call and logs the result.
process_one() {
    local RESPONSE HTTP_CODE

    # Capture HTTP status code separately from response body.
    RESPONSE=$(curl -s -w "\n%{http_code}" --max-time "${MAX_TIME}" \
        -X POST "${ENDPOINT}" \
        -H "Authorization: Basic ${AUTH}" \
        -H "Content-Type: application/json" \
        -d "{\"batch_size\":${BATCH_SIZE}}" 2>&1)

    # Split response body from HTTP status code (last line).
    HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
    RESPONSE=$(echo "$RESPONSE" | sed '$d')

    # Handle curl-level failures (empty response, connection refused, timeout).
    if [ -z "$HTTP_CODE" ] || [ "$HTTP_CODE" = "000" ]; then
        echo "$(date -Iseconds) WARNING: request failed (connection error or timeout)"
        return
    fi

    # Handle non-200 HTTP responses with a concise single-line message.
    if [ "$HTTP_CODE" != "200" ]; then
        # Strip HTML tags for cleaner log output.
        local PLAIN
        PLAIN=$(echo "$RESPONSE" | sed 's/<[^>]*>//g' | tr -s '[:space:]' ' ' | head -c 200)
        echo "$(date -Iseconds) WARNING: HTTP ${HTTP_CODE}: ${PLAIN}"
        return
    fi

    # Parse JSON response.
    local PROCESSED
    PROCESSED=$(echo "$RESPONSE" | python3 -c "
import json, sys
try:
    d = json.load(sys.stdin)
    p = d.get('processed', {})
    if isinstance(p, dict):
        print(p.get('processed', 0))
    else:
        print(p)
except:
    print('error')
" 2>/dev/null)

    if [ "$PROCESSED" = "error" ]; then
        echo "$(date -Iseconds) WARNING: invalid JSON response: ${RESPONSE:0:200}"
    elif [ "$PROCESSED" != "0" ] && [ -n "$PROCESSED" ]; then
        echo "$(date -Iseconds) Processed ${PROCESSED} job(s)"
    fi
}

while true; do
    run_daily_tasks

    if [ "$CONCURRENCY" -le 1 ]; then
        process_one
    else
        # Fire N parallel workers and wait for all to finish.
        for i in $(seq 1 "$CONCURRENCY"); do
            process_one &
        done
        wait
    fi

    sleep "${POLL_INTERVAL}"
done
