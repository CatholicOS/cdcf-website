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

# Required for in_maintenance() — without this the worker silently
# ignores the deploy-time pause flag and keeps hitting FPM during
# deploys (the very condition this whole feature is meant to prevent).
if ! command -v redis-cli >/dev/null 2>&1; then
    echo "ERROR: redis-cli not found in PATH. Install it (Debian/Ubuntu: 'sudo apt install redis-tools'; RHEL: 'sudo dnf install redis')."
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

    echo "$(date -Iseconds) Running daily tasks..."

    # Update disposable email domain blocklist.
    local RESPONSE HTTP_CODE
    RESPONSE=$(curl -s -w "\n%{http_code}" --max-time 120 \
        -X POST "${DISPOSABLE_DOMAINS_ENDPOINT}" \
        -H "Authorization: Basic ${AUTH}" \
        -H "Content-Type: application/json" \
        -d '{}' 2>&1)
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
        LAST_DAILY_RUN=$NOW
    else
        echo "$(date -Iseconds) WARNING: disposable domains update failed (HTTP ${HTTP_CODE}): ${RESPONSE:0:200}"
    fi
}

# ─── Maintenance flag check ──────────────────────────────────────────
# Returns 0 (true) if the maintenance flag is set in Redis, 1 (false)
# otherwise. Redis-unreachable counts as "not in maintenance" so a
# Redis outage does not stall the worker indefinitely.
in_maintenance() {
    local result
    result=$(redis-cli -h 127.0.0.1 -p 6379 -n 0 EXISTS cdcf:maintenance:until 2>/dev/null) || return 1
    [ "$result" = "1" ]
}

# ─── Queue-depth fast path ───────────────────────────────────────────
# Returns 0 (true) when there is nothing to process *right now* — i.e.
# no immediate jobs in the default queue and no delayed jobs whose
# eligibility time has arrived. Returns 1 when work is available, and
# also returns 1 if redis-cli fails so an outage falls back to the
# existing HTTP poll (the safe path — the endpoint can tell us more
# than redis-cli can in error scenarios).
#
# Queue layout per Soderlind\RedisQueue:
#   redis_queue:queue:default → sorted set, immediate jobs
#   redis_queue:delayed       → sorted set, score = unix ts when eligible
queue_is_empty() {
    local now immediate delayed
    now=$(date +%s)
    immediate=$(redis-cli -h 127.0.0.1 -p 6379 -n 0 ZCARD redis_queue:queue:default 2>/dev/null) || return 1
    delayed=$(redis-cli -h 127.0.0.1 -p 6379 -n 0 ZCOUNT redis_queue:delayed -inf "$now" 2>/dev/null) || return 1
    [ $((immediate + delayed)) = "0" ]
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

IN_MAINTENANCE=0
while true; do
    if in_maintenance; then
        if [ "$IN_MAINTENANCE" = "0" ]; then
            echo "$(date -Iseconds) Entering maintenance mode (worker paused)"
            IN_MAINTENANCE=1
        fi
        sleep "${POLL_INTERVAL}"
        continue
    fi

    if [ "$IN_MAINTENANCE" = "1" ]; then
        echo "$(date -Iseconds) Exiting maintenance mode (worker resumed)"
        IN_MAINTENANCE=0
    fi

    run_daily_tasks

    # Fast path: if Redis says the queue is empty, skip the HTTP fan-out.
    # The 5-parallel polls would otherwise hit PHP-FPM every POLL_INTERVAL
    # seconds even when there is no work — which is most of the time —
    # and that traffic is what produces the baseline 504 bursts when the
    # VPS is under transient pressure from neighbour workloads (e.g.
    # Imunify360 scans, MediaWiki JobQueue activity on the same host).
    # New translation work resumes within POLL_INTERVAL of being enqueued.
    if queue_is_empty; then
        sleep "${POLL_INTERVAL}"
        continue
    fi

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
