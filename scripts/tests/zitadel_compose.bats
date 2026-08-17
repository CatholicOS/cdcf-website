#!/usr/bin/env bats
#
# Coverage for the local Zitadel services in docker-compose.yml.
#
# These assert the RESOLVED compose config (`docker compose config`), not the
# raw YAML, so ${ZITADEL_PORT:-8090} is evaluated the way Compose evaluates it.
# -f docker-compose.yml is explicit: docker-compose.override.yml is merged by
# default and would otherwise make the result depend on local overrides.
#
# The port cases are the point of this file. ZITADEL_PORT is restated in four
# places across two repos and nothing validates that they agree (spec §4); a
# published port that disagrees with ZITADEL_EXTERNALPORT yields issuer and
# discovery URLs that look right and do not resolve.

setup() {
    cd "$BATS_TEST_DIRNAME/../.." || return 1
    # The cases below invoke this through `run bash -c`, which starts a fresh
    # shell that does NOT inherit shell functions. Without the export they
    # fail with "compose_service_json: command not found" — a failure that
    # reads like a broken compose file rather than a broken harness.
    export -f compose_service_json
}

# Emit the resolved config for one service as JSON.
compose_service_json() {
    docker compose -f docker-compose.yml config --format json 2>/dev/null \
        | python3 -c "import sys,json;print(json.dumps(json.load(sys.stdin)['services']['$1']))"
}

@test "zitadel: image is pinned to v4.15.0, never :latest" {
    run bash -c "compose_service_json zitadel | python3 -c \"import sys,json;print(json.load(sys.stdin)['image'])\""
    [ "$status" -eq 0 ]
    [ "$output" = "ghcr.io/zitadel/zitadel:v4.15.0" ]
}

@test "zitadel-db: is its own postgres, not the stack's mariadb" {
    run bash -c "compose_service_json zitadel-db | python3 -c \"import sys,json;print(json.load(sys.stdin)['image'])\""
    [ "$status" -eq 0 ]
    [ "$output" = "postgres:16-alpine" ]
}

@test "zitadel: default published port is 8090, not 8080" {
    run bash -c "compose_service_json zitadel | python3 -c \"
import sys,json
p=json.load(sys.stdin)['ports'][0]
print(f\\\"{p['published']}:{p['target']}\\\")\""
    [ "$status" -eq 0 ]
    [ "$output" = "8090:8080" ]
}

@test "zitadel: EXTERNALPORT tracks the published port under an override" {
    run bash -c "ZITADEL_PORT=9099 compose_service_json zitadel | python3 -c \"
import sys,json
s=json.load(sys.stdin)
print(f\\\"{s['ports'][0]['published']}:{s['environment']['ZITADEL_EXTERNALPORT']}\\\")\""
    [ "$status" -eq 0 ]
    [ "$output" = "9099:9099" ]
}

@test "zitadel: both postgres SSL modes are disabled" {
    run bash -c "compose_service_json zitadel | python3 -c \"
import sys,json
e=json.load(sys.stdin)['environment']
print(e['ZITADEL_DATABASE_POSTGRES_ADMIN_SSL_MODE'], e['ZITADEL_DATABASE_POSTGRES_USER_SSL_MODE'])\""
    [ "$status" -eq 0 ]
    [ "$output" = "disable disable" ]
}

@test "zitadel: the machine-user block that produces the PAT is complete" {
    run bash -c "compose_service_json zitadel | python3 -c \"
import sys,json
e=json.load(sys.stdin)['environment']
keys=['ZITADEL_FIRSTINSTANCE_PATPATH',
      'ZITADEL_FIRSTINSTANCE_ORG_MACHINE_MACHINE_USERNAME',
      'ZITADEL_FIRSTINSTANCE_ORG_MACHINE_MACHINE_NAME',
      'ZITADEL_FIRSTINSTANCE_ORG_MACHINE_PAT_EXPIRATIONDATE']
print('ok' if all(e.get(k) for k in keys) else 'missing')
print(e['ZITADEL_FIRSTINSTANCE_PATPATH'])\""
    [ "$status" -eq 0 ]
    [ "${lines[0]}" = "ok" ]
    [ "${lines[1]}" = "/zitadel-data/automation-user.pat" ]
}

@test "zitadel: Login V2 is disabled, since no zitadel-login service exists" {
    run bash -c "compose_service_json zitadel | python3 -c \"
import sys,json
print(json.load(sys.stdin)['environment']['ZITADEL_DEFAULTINSTANCE_FEATURES_LOGINV2_REQUIRED'])\""
    [ "$status" -eq 0 ]
    [ "$output" = "false" ]
}

@test "zitadel: master key is exactly 32 characters" {
    run bash -c "compose_service_json zitadel | python3 -c \"
import sys,json,re
c=json.load(sys.stdin)['command']
c=' '.join(c) if isinstance(c,list) else c
m=re.search(r'--masterkey\s+\\\"?([^\\\" ]+)', c)
print(len(m.group(1)) if m else 'nomatch')\""
    [ "$status" -eq 0 ]
    [ "$output" = "32" ]
}

@test "zitadel: runs as uid 0 so the host can read the PAT" {
    run bash -c "compose_service_json zitadel | python3 -c \"import sys,json;print(json.load(sys.stdin).get('user',''))\""
    [ "$status" -eq 0 ]
    [ "$output" = "0" ]
}
