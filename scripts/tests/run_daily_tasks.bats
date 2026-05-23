#!/usr/bin/env bats
#
# Coverage for run_daily_tasks() from cdcf_queue_worker.lib.sh.
#
# The function:
#   1. Bails early if NOW - LAST_DAILY_RUN < DAILY_INTERVAL
#   2. Otherwise POSTs to DISPOSABLE_DOMAINS_ENDPOINT
#   3. On HTTP 200: parses domain count, updates LAST_DAILY_RUN
#   4. On HTTP error: logs warning, leaves LAST_DAILY_RUN unchanged
#
# curl is PATH-shimmed via scripts/tests/helpers/shims/curl — same shim
# used by process_one.bats. SHIM_CURL_HTTP_CODE + SHIM_CURL_BODY drive
# the response shape.

setup() {
    PATH="$BATS_TEST_DIRNAME/helpers/shims:$PATH"
    export PATH

    export DISPOSABLE_DOMAINS_ENDPOINT="http://test.invalid/update-disposable-domains"
    export AUTH="dGVzdDp0ZXN0"
    # 1 hour cadence by default. Individual tests override DAILY_INTERVAL +
    # LAST_DAILY_RUN to drive specific branches.
    export DAILY_INTERVAL=3600
    export LAST_DAILY_RUN=0

    # shellcheck source=../cdcf_queue_worker.lib.sh
    source "$BATS_TEST_DIRNAME/../cdcf_queue_worker.lib.sh"
}

@test "run_daily_tasks: bails early when interval not elapsed" {
    # Last run was 1 second ago — interval is 3600 seconds — should bail.
    LAST_DAILY_RUN=$(($(date +%s) - 1))
    SHIM_CURL_HTTP_CODE=200 SHIM_CURL_BODY='{"domains": 100}' run run_daily_tasks
    [ "$status" -eq 0 ]
    # No log line means the function returned before the echo at the
    # top of the work block.
    [ -z "$output" ]
}

@test "run_daily_tasks: HTTP 200 → updates list, logs domain count" {
    SHIM_CURL_HTTP_CODE=200 SHIM_CURL_BODY='{"domains": 1234}' run run_daily_tasks
    [ "$status" -eq 0 ]
    [[ "$output" == *"Running daily tasks"* ]]
    [[ "$output" == *"Disposable domains list updated (1234 domains)"* ]]
}

@test "run_daily_tasks: HTTP 200 advances LAST_DAILY_RUN" {
    # bats's `run` runs the function in a subshell, so we can't observe
    # the LAST_DAILY_RUN update through `run`. Source-execute directly
    # in the current shell instead.
    local before=$LAST_DAILY_RUN
    SHIM_CURL_HTTP_CODE=200 SHIM_CURL_BODY='{"domains": 5}' run_daily_tasks > /dev/null
    [ "$LAST_DAILY_RUN" -gt "$before" ]
}

@test "run_daily_tasks: HTTP 500 → logs warning, keeps LAST_DAILY_RUN unchanged" {
    local before=$LAST_DAILY_RUN
    SHIM_CURL_HTTP_CODE=500 SHIM_CURL_BODY='Server error' run_daily_tasks 2>&1 | grep -q "WARNING.*HTTP 500"
    [ "$?" -eq 0 ]
    [ "$LAST_DAILY_RUN" -eq "$before" ]
}

@test "run_daily_tasks: HTTP 200 with missing domains key → logs '?' as count" {
    SHIM_CURL_HTTP_CODE=200 SHIM_CURL_BODY='{"other":"value"}' run run_daily_tasks
    [ "$status" -eq 0 ]
    # parse_domain_count returns '?' when the key is missing; the log
    # message embeds it directly so the operator sees the upstream
    # returned 200 but the body shape was wrong.
    [[ "$output" == *"Disposable domains list updated (? domains)"* ]]
}
