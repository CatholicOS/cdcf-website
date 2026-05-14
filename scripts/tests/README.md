# Queue-worker bats tests

Unit tests for the helpers in [`scripts/cdcf_queue_worker.lib.sh`](../cdcf_queue_worker.lib.sh), written in [bats-core](https://github.com/bats-core/bats-core). Sister-suite to the Vitest pilot for the Next.js data layer (`__tests__/lib/wordpress/`) and the planned PHPUnit suite for the WordPress theme (`wordpress/themes/cdcf-headless/tests/`). All three are tracked under issue #63.

## Running

```bash
scripts/tests/bats/bin/bats scripts/tests/
```

Expected output: `1..20` with all tests `ok` and no `not ok` lines.

To run a single file:

```bash
scripts/tests/bats/bin/bats scripts/tests/in_maintenance.bats
```

## Requirements

- `bash` 4.2+ (uses `printf -v NOW '%(%s)T' -1` for time-based assertions)
- `python3` on `PATH` — the `parse_processed` / `parse_domain_count` helpers shell out to it for JSON parsing and the tests deliberately do **not** shim it (the real interpreter is the unit under test for those helpers)
- The `scripts/tests/bats/` git submodule populated — see "Updating bats-core" below

## Shim convention

External commands the helpers call (`redis-cli`, `curl`) are mocked by PATH-injected fakes in `helpers/shims/`. Each test prepends `helpers/shims` to `PATH` in its `setup()` and configures the shim via per-test env vars:

| Shim      | Env var               | Purpose                                                                 |
|-----------|-----------------------|-------------------------------------------------------------------------|
| redis-cli | `SHIM_REDIS_OUTPUT`   | stdout for the single-call case                                         |
| redis-cli | `SHIM_REDIS_EXIT`     | exit code for the single-call case                                      |
| redis-cli | `SHIM_REDIS_OUTPUTS`  | newline-separated list of outputs for multi-call helpers (e.g. `queue_is_empty`) |
| redis-cli | `SHIM_REDIS_STATE`    | path to the per-test state file the shim pops outputs from (typically `$BATS_TEST_TMPDIR/redis-state`) |
| curl      | `SHIM_CURL_BODY`      | response body                                                           |
| curl      | `SHIM_CURL_HTTP_CODE` | status code echoed on the last line when `-w '\n%{http_code}'` is passed |
| curl      | `SHIM_CURL_EXIT`      | exit code (defaults to 0; the worker treats `HTTP_CODE=000` as the failure signal regardless) |

The shims **ignore the actual argv** — every helper passes a fixed call shape, so we don't bother asserting on flags. If a future test needs to verify argv, wrap the shim or add a more elaborate fake.

## Adding a new test

1. Create `scripts/tests/<helper>.bats`. The file's `setup()` should both prepend the shim PATH and source the lib:
   ```bash
   setup() {
       PATH="$BATS_TEST_DIRNAME/helpers/shims:$PATH"
       export PATH
       # shellcheck source=../cdcf_queue_worker.lib.sh
       source "$BATS_TEST_DIRNAME/../cdcf_queue_worker.lib.sh"
   }
   ```
2. Write `@test "<name>" { ... }` blocks using `run <helper>` to capture status + output. Use `<<<` or `< /dev/null` for stdin.
3. Use `setup()` (per-test), not `setup_file()` — `setup_file()` runs in a different process where the sourced functions are not visible.
4. Run just your new file (`scripts/tests/bats/bin/bats scripts/tests/<helper>.bats`) before running the whole suite.

## Updating bats-core

bats-core is pinned as a git submodule. To bump the pinned tag:

```bash
cd scripts/tests/bats
git fetch --tags
git checkout v<new-tag>
cd ../../..
git add scripts/tests/bats
git commit -m "test(worker): bump bats-core to v<new-tag>"
```

Fresh checkouts of the repo need `git clone --recurse-submodules` (or `git submodule update --init --recursive` after a plain clone) to populate `scripts/tests/bats/`.

## File layout

```text
scripts/tests/
├── README.md                       (this file)
├── bats/                           (submodule — bats-core runner, do not edit)
├── helpers/
│   └── shims/
│       ├── curl                    PATH-injected fake for curl
│       └── redis-cli               PATH-injected fake for redis-cli
├── in_maintenance.bats             3 cases
├── queue_is_empty.bats             4 cases
├── parse_processed.bats            5 cases
├── should_run_daily_tasks.bats     4 cases
└── process_one.bats                4 cases
```

Total: 20 cases.
