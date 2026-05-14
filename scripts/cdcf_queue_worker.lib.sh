# Helper functions for cdcf-queue-worker.
#
# Sourced by cdcf_queue_worker.sh at startup. Each function is also
# directly sourceable by bats tests in scripts/tests/, so external
# command dependencies (redis-cli, curl, python3, date) live inside
# the functions rather than as ambient globals — tests can PATH-shim
# the commands without monkey-patching variables.
#
# This file is NOT executable on its own; it expects the caller to
# have set ENDPOINT, AUTH, MAX_TIME, BATCH_SIZE, DAILY_INTERVAL,
# DISPOSABLE_DOMAINS_ENDPOINT, and LAST_DAILY_RUN in the environment.

# ─── JSON parsing helpers ────────────────────────────────────────────
# Pulled out of run_daily_tasks / process_one so they can be unit tested
# without a live HTTP response.

# parse_processed reads JSON from stdin and prints the processed count.
# Returns "error" on invalid JSON, or the processed value (string).
parse_processed() {
    python3 -c "
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
" 2>/dev/null
}

# parse_domain_count reads the disposable-domains response from stdin
# and prints d['domains'] (a count), or '?' on parse failure.
parse_domain_count() {
    python3 -c "
import json, sys
try:
    d = json.load(sys.stdin)
    print(d.get('domains', '?'))
except:
    print('?')
" 2>/dev/null
}

# ─── Daily maintenance tasks ──────────────────────────────────────────
# Tracks the last run time and fires once per DAILY_INTERVAL.

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
        DOMAIN_COUNT=$(echo "$RESPONSE" | parse_domain_count)
        echo "$(date -Iseconds) Disposable domains list updated (${DOMAIN_COUNT} domains)"
        LAST_DAILY_RUN=$NOW
    else
        echo "$(date -Iseconds) WARNING: disposable domains update failed (HTTP ${HTTP_CODE}): ${RESPONSE:0:200}"
    fi
}

# ─── Daily-tasks cadence check ───────────────────────────────────────
# Returns 0 (true) iff NOW - LAST_DAILY_RUN >= DAILY_INTERVAL.
# Reads LAST_DAILY_RUN and DAILY_INTERVAL from the environment.
should_run_daily_tasks() {
    local now
    now=$(date +%s)
    (( now - LAST_DAILY_RUN >= DAILY_INTERVAL ))
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
    PROCESSED=$(echo "$RESPONSE" | parse_processed)

    if [ "$PROCESSED" = "error" ]; then
        echo "$(date -Iseconds) WARNING: invalid JSON response: ${RESPONSE:0:200}"
    elif [ "$PROCESSED" != "0" ] && [ -n "$PROCESSED" ]; then
        echo "$(date -Iseconds) Processed ${PROCESSED} job(s)"
    fi
}
