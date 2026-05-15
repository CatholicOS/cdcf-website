#!/usr/bin/env bats
#
# Coverage for in_maintenance() from cdcf_queue_worker.lib.sh.
#
# The function shells out to redis-cli, so we PATH-shim that binary
# via scripts/tests/helpers/shims/redis-cli. Each test configures the
# shim's response via SHIM_REDIS_OUTPUT / SHIM_REDIS_EXIT env vars.

setup() {
    PATH="$BATS_TEST_DIRNAME/helpers/shims:$PATH"
    export PATH
    # shellcheck source=../cdcf_queue_worker.lib.sh
    source "$BATS_TEST_DIRNAME/../cdcf_queue_worker.lib.sh"
}

@test "in_maintenance: returns 0 when redis-cli reports EXISTS=1" {
    SHIM_REDIS_OUTPUT=1 SHIM_REDIS_EXIT=0 run in_maintenance
    [ "$status" -eq 0 ]
}

@test "in_maintenance: returns 1 when redis-cli reports EXISTS=0" {
    SHIM_REDIS_OUTPUT=0 SHIM_REDIS_EXIT=0 run in_maintenance
    [ "$status" -eq 1 ]
}

@test "in_maintenance: returns 1 when redis-cli fails (Redis-down means not paused)" {
    SHIM_REDIS_OUTPUT= SHIM_REDIS_EXIT=1 run in_maintenance
    [ "$status" -eq 1 ]
}
