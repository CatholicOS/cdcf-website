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
# 1. Copy this script AND its sibling library to /usr/local/bin/ and make
#    the main script executable:
#
#      sudo cp scripts/cdcf_queue_worker.sh scripts/cdcf_queue_worker.lib.sh /usr/local/bin/
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

# Source the library — must be next to this script.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=./cdcf_queue_worker.lib.sh
source "$SCRIPT_DIR/cdcf_queue_worker.lib.sh"

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

    if should_run_daily_tasks; then
        run_daily_tasks
    fi

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
