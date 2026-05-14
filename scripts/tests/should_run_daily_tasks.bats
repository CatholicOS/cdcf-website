#!/usr/bin/env bats
#
# Coverage for should_run_daily_tasks() from cdcf_queue_worker.lib.sh.
#
# The helper reads LAST_DAILY_RUN and DAILY_INTERVAL from the
# environment and returns 0 iff (now - LAST_DAILY_RUN) >= DAILY_INTERVAL.
# We compute "now" with `printf -v NOW '%(%s)T' -1` (bash 4.2+) and then
# set LAST_DAILY_RUN to a value relative to that.

setup() {
    # shellcheck source=../cdcf_queue_worker.lib.sh
    source "$BATS_TEST_DIRNAME/../cdcf_queue_worker.lib.sh"
}

@test "should_run_daily_tasks: never run (LAST_DAILY_RUN=0) → returns 0 (true)" {
    LAST_DAILY_RUN=0 DAILY_INTERVAL=86400 run should_run_daily_tasks
    [ "$status" -eq 0 ]
}

@test "should_run_daily_tasks: last run 1 second ago, 24h interval → returns 1 (false)" {
    local NOW
    printf -v NOW '%(%s)T' -1
    LAST_DAILY_RUN=$((NOW - 1)) DAILY_INTERVAL=86400 run should_run_daily_tasks
    [ "$status" -eq 1 ]
}

@test "should_run_daily_tasks: last run exactly DAILY_INTERVAL ago → returns 0 (true at boundary)" {
    local NOW
    printf -v NOW '%(%s)T' -1
    LAST_DAILY_RUN=$((NOW - 86400)) DAILY_INTERVAL=86400 run should_run_daily_tasks
    [ "$status" -eq 0 ]
}

@test "should_run_daily_tasks: short interval override (DAILY_INTERVAL=1) → returns 0" {
    LAST_DAILY_RUN=0 DAILY_INTERVAL=1 run should_run_daily_tasks
    [ "$status" -eq 0 ]
}
