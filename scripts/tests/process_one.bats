#!/usr/bin/env bats
#
# Coverage for process_one() from cdcf_queue_worker.lib.sh.
#
# process_one shells out to curl (PATH-shimmed via
# scripts/tests/helpers/shims/curl) and, for 200 responses, pipes the
# body through parse_processed (which itself shells out to real
# python3 — left unshimmed, see parse_processed.bats for rationale).
#
# Each test configures the curl shim via SHIM_CURL_BODY and
# SHIM_CURL_HTTP_CODE, then runs process_one and asserts on the
# expected log line on stdout.

setup() {
    PATH="$BATS_TEST_DIRNAME/helpers/shims:$PATH"
    export PATH

    # process_one reads these from the environment. The shim ignores
    # the actual values, but the function still expands them into the
    # curl argv so they need to be non-empty.
    export ENDPOINT="http://test.invalid/process-queue"
    export AUTH="dGVzdDp0ZXN0"
    export MAX_TIME=30
    export BATCH_SIZE=10

    # shellcheck source=../cdcf_queue_worker.lib.sh
    source "$BATS_TEST_DIRNAME/../cdcf_queue_worker.lib.sh"
}

@test "process_one: HTTP 200 + valid JSON → logs 'Processed N job(s)'" {
    SHIM_CURL_HTTP_CODE=200 SHIM_CURL_BODY='{"processed": 5}' run process_one
    [ "$status" -eq 0 ]
    [[ "$output" == *"Processed 5 job(s)"* ]]
}

@test "process_one: HTTP 500 with HTML body → logs 'WARNING: HTTP 500' with HTML stripped" {
    SHIM_CURL_HTTP_CODE=500 SHIM_CURL_BODY='<html><body>boom</body></html>' run process_one
    [ "$status" -eq 0 ]
    [[ "$output" == *"WARNING: HTTP 500"* ]]
    [[ "$output" == *"boom"* ]]
    # Tags should have been stripped before logging.
    [[ "$output" != *"<html>"* ]]
    [[ "$output" != *"<body>"* ]]
}

@test "process_one: HTTP 000 (curl-level failure) → logs 'WARNING: request failed'" {
    SHIM_CURL_HTTP_CODE=000 SHIM_CURL_BODY='' run process_one
    [ "$status" -eq 0 ]
    [[ "$output" == *"WARNING: request failed (connection error or timeout)"* ]]
}

@test "process_one: HTTP 200 + invalid JSON → logs 'WARNING: invalid JSON response'" {
    SHIM_CURL_HTTP_CODE=200 SHIM_CURL_BODY='not json' run process_one
    [ "$status" -eq 0 ]
    [[ "$output" == *"WARNING: invalid JSON response"* ]]
    [[ "$output" == *"not json"* ]]
}
