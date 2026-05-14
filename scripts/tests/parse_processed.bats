#!/usr/bin/env bats
#
# Coverage for parse_processed() from cdcf_queue_worker.lib.sh.
#
# parse_processed runs a small `python3 -c '...'` script against stdin
# and prints either an integer count (for valid JSON) or the literal
# string `error` for any parse failure. We deliberately do NOT shim
# python3 here — the real JSON parser is the helper's actual dependency
# and is what we want to exercise.

setup() {
    # shellcheck source=../cdcf_queue_worker.lib.sh
    source "$BATS_TEST_DIRNAME/../cdcf_queue_worker.lib.sh"
}

# Each test pipes its input into `run parse_processed` via bash's
# here-string operator (`<<<`) so the function call stays in the test
# shell where the lib was sourced — `run bash -c '...'` would spawn a
# fresh shell with no parse_processed defined.

@test "parse_processed: flat {\"processed\": 5} → 5" {
    run parse_processed <<< '{"processed": 5}'
    [ "$status" -eq 0 ]
    [ "$output" = "5" ]
}

@test "parse_processed: nested {\"processed\": {\"processed\": 3}} → 3" {
    run parse_processed <<< '{"processed": {"processed": 3}}'
    [ "$status" -eq 0 ]
    [ "$output" = "3" ]
}

@test "parse_processed: missing key {} → 0" {
    run parse_processed <<< '{}'
    [ "$status" -eq 0 ]
    [ "$output" = "0" ]
}

@test "parse_processed: invalid JSON → error" {
    run parse_processed <<< 'not json'
    [ "$status" -eq 0 ]
    [ "$output" = "error" ]
}

@test "parse_processed: empty input → error" {
    run parse_processed < /dev/null
    [ "$status" -eq 0 ]
    [ "$output" = "error" ]
}
