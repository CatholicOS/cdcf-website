#!/usr/bin/env bats
#
# Coverage for parse_domain_count() from cdcf_queue_worker.lib.sh.
# Pure-input helper — no shims required (uses real python3).

setup() {
    # shellcheck source=../cdcf_queue_worker.lib.sh
    source "$BATS_TEST_DIRNAME/../cdcf_queue_worker.lib.sh"
}

@test "parse_domain_count: valid JSON with 'domains' key → prints count" {
    result=$(echo '{"domains": 1234}' | parse_domain_count)
    [ "$result" = "1234" ]
}

@test "parse_domain_count: JSON without 'domains' key → prints '?'" {
    result=$(echo '{"other": "value"}' | parse_domain_count)
    [ "$result" = "?" ]
}

@test "parse_domain_count: invalid JSON → prints '?'" {
    result=$(echo 'not json at all' | parse_domain_count)
    [ "$result" = "?" ]
}

@test "parse_domain_count: empty input → prints '?'" {
    result=$(echo '' | parse_domain_count)
    [ "$result" = "?" ]
}

@test "parse_domain_count: handles string-typed 'domains' value" {
    # Python's json.load doesn't care about the value type — d.get('domains')
    # returns whatever is there. The worker only uses the result for a log
    # message, so any printable form is acceptable.
    result=$(echo '{"domains": "many"}' | parse_domain_count)
    [ "$result" = "many" ]
}
