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
#      CONCURRENCY=3
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

if [ -z "$WP_REST_URL" ] || [ -z "$WP_APP_USERNAME" ] || [ -z "$WP_APP_PASSWORD" ]; then
    echo "ERROR: WP_REST_URL, WP_APP_USERNAME, and WP_APP_PASSWORD must be set."
    exit 1
fi

ENDPOINT="${WP_REST_URL}/cdcf/v1/process-queue"
AUTH=$(echo -n "${WP_APP_USERNAME}:${WP_APP_PASSWORD}" | base64)

echo "Starting CDCF Redis Queue worker..."
echo "  Endpoint: ${ENDPOINT}"
echo "  Poll interval: ${POLL_INTERVAL}s"
echo "  Max request time: ${MAX_TIME}s"
echo "  Concurrency: ${CONCURRENCY}"

# Wait a bit after service start to let WordPress finish booting.
sleep 10

# process_one fires a single REST call and logs the result.
process_one() {
    local RESPONSE
    RESPONSE=$(curl -s --max-time "${MAX_TIME}" \
        -X POST "${ENDPOINT}" \
        -H "Authorization: Basic ${AUTH}" \
        -H "Content-Type: application/json" \
        -d '{}' 2>&1)

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
        echo "$(date -Iseconds) WARNING: unexpected response: ${RESPONSE:0:200}"
    elif [ "$PROCESSED" != "0" ] && [ -n "$PROCESSED" ]; then
        echo "$(date -Iseconds) Processed ${PROCESSED} job(s)"
    fi
}

while true; do
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
