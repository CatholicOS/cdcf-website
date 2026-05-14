#!/usr/bin/env bats
#
# Coverage for queue_is_empty() from cdcf_queue_worker.lib.sh.
#
# queue_is_empty calls redis-cli twice in one invocation:
#   1. ZCARD redis_queue:queue:default     → immediate-job count
#   2. ZCOUNT redis_queue:delayed -inf now → delayed-and-ready count
#
# We drive both calls from one test via the shim's multi-call mode:
# SHIM_REDIS_OUTPUTS is a newline-separated list, popped from a
# per-test state file at SHIM_REDIS_STATE.

setup() {
    PATH="$BATS_TEST_DIRNAME/helpers/shims:$PATH"
    export PATH
    export SHIM_REDIS_STATE="$BATS_TEST_TMPDIR/redis-state"
    # shellcheck source=../cdcf_queue_worker.lib.sh
    source "$BATS_TEST_DIRNAME/../cdcf_queue_worker.lib.sh"
}

@test "queue_is_empty: ZCARD=0 + ZCOUNT=0 → returns 0 (queue truly empty)" {
    SHIM_REDIS_OUTPUTS=$'0\n0' run queue_is_empty
    [ "$status" -eq 0 ]
}

@test "queue_is_empty: ZCARD=3 + ZCOUNT=0 → returns 1 (immediate work waiting)" {
    SHIM_REDIS_OUTPUTS=$'3\n0' run queue_is_empty
    [ "$status" -eq 1 ]
}

@test "queue_is_empty: ZCARD=0 + ZCOUNT=2 → returns 1 (delayed work ready)" {
    SHIM_REDIS_OUTPUTS=$'0\n2' run queue_is_empty
    [ "$status" -eq 1 ]
}

@test "queue_is_empty: first redis-cli call fails → returns 1 (fall back to HTTP poll)" {
    SHIM_REDIS_OUTPUT= SHIM_REDIS_EXIT=1 run queue_is_empty
    [ "$status" -eq 1 ]
}
