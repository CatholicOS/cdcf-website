# Redis Queue Worker Setup

The CDCF Redis Queue Worker processes AI translation jobs asynchronously. When content is created or translated via the WordPress REST API, translation jobs are pushed onto a Redis queue. The worker continuously polls the queue and processes jobs by calling the `POST /cdcf/v1/process-queue` WordPress REST endpoint.

## Architecture

```
REST API (create post)
  |
  |--> cdcf_enqueue_translation()
  |      |
  |      |--> Redis Queue (preferred)
  |      '--> WP-Cron (fallback)
  |
  '--> HTTP 202 (immediate response)

Queue Worker (systemd service)
  |
  |--> POST /cdcf/v1/process-queue  (N concurrent requests)
  |      |
  |      '--> cdcf_process_translation()
  |             |--> OpenAI API (translate)
  |             |--> Write translated content
  |             |--> Copy non-translatable ACF fields
  |             |--> Copy featured image (translated media ID)
  |             '--> Auto-publish
  |
  '--> sleep, repeat
```

## Prerequisites

- Redis server running on the production host
- The `redis-queue` and `cdcf-redis-translations` WordPress plugins activated
- A WordPress user with `manage_options` capability and an Application Password
- `curl` and `python3` available on the server

## 1. Install the worker script

```bash
sudo cp scripts/cdcf_queue_worker.sh /usr/local/bin/cdcf_queue_worker.sh
sudo chmod +x /usr/local/bin/cdcf_queue_worker.sh
```

## 2. Create the environment file

Create `/etc/cdcf-queue.env` with the required credentials and configuration:

```env
# Required
WP_REST_URL=https://cms.catholicdigitalcommons.org/wp-json
WP_APP_USERNAME=your-wordpress-username
WP_APP_PASSWORD=xxxx xxxx xxxx xxxx xxxx xxxx

# Optional
CONCURRENCY=5
POLL_INTERVAL=15
MAX_TIME=300
BATCH_SIZE=1
```

Restrict permissions since this file contains credentials:

```bash
sudo chmod 600 /etc/cdcf-queue.env
sudo chown root:root /etc/cdcf-queue.env
```

### Environment variables

| Variable | Required | Default | Description |
|---|---|---|---|
| `WP_REST_URL` | Yes | | WordPress REST API base URL |
| `WP_APP_USERNAME` | Yes | | WordPress username with `manage_options` capability |
| `WP_APP_PASSWORD` | Yes | | WordPress Application Password for the above user |
| `CONCURRENCY` | No | `1` | Number of parallel HTTP requests per polling cycle |
| `POLL_INTERVAL` | No | `15` | Seconds to wait between polling cycles |
| `MAX_TIME` | No | `300` | Max timeout in seconds for each curl request |
| `BATCH_SIZE` | No | `10` (single) / `1` (parallel) | Number of jobs each request dequeues from Redis |

### Concurrency and batch size

When `CONCURRENCY=1` (default), the worker fires a single request per cycle that processes up to `BATCH_SIZE` jobs sequentially.

When `CONCURRENCY` is greater than 1, the worker fires N concurrent requests per cycle. `BATCH_SIZE` defaults to `1` in this mode so each request dequeues exactly one job, achieving true parallelism. Redis atomic dequeue operations prevent any job from being processed twice.

For example, with `CONCURRENCY=5` and `BATCH_SIZE=1`, a 5-language translation batch will process all 5 languages in parallel rather than sequentially.

## 3. Create the systemd service

Create `/etc/systemd/system/cdcf-queue-worker.service`:

```ini
[Unit]
Description=CDCF Redis Queue Worker
After=network.target redis-server.service
Wants=redis-server.service

[Service]
Type=simple
EnvironmentFile=/etc/cdcf-queue.env
ExecStart=/usr/local/bin/cdcf_queue_worker.sh
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal
SyslogIdentifier=cdcf-queue-worker

[Install]
WantedBy=multi-user.target
```

## 4. Enable and start the service

```bash
sudo systemctl daemon-reload
sudo systemctl enable cdcf-queue-worker
sudo systemctl start cdcf-queue-worker
```

## Managing the service

```bash
# Check status
sudo systemctl status cdcf-queue-worker

# View logs (follow mode)
journalctl -u cdcf-queue-worker -f

# View recent logs
journalctl -u cdcf-queue-worker --since "1 hour ago"

# Restart after config changes
sudo systemctl restart cdcf-queue-worker

# Stop the service
sudo systemctl stop cdcf-queue-worker
```

## Updating the worker script

After pulling new changes from the repository:

```bash
sudo cp scripts/cdcf_queue_worker.sh /usr/local/bin/cdcf_queue_worker.sh
sudo chmod +x /usr/local/bin/cdcf_queue_worker.sh
sudo systemctl restart cdcf-queue-worker
```

## Troubleshooting

### Worker logs show "WARNING: unexpected response"

The WordPress REST endpoint may be unreachable. Check:
- WordPress is running: `curl -s https://cms.catholicdigitalcommons.org/wp-json/`
- Redis is running: `redis-cli ping`
- Credentials are correct in `/etc/cdcf-queue.env`

### Translations stay as drafts

The queue worker may not be running or jobs are failing. Check:
- Service status: `sudo systemctl status cdcf-queue-worker`
- Worker logs: `journalctl -u cdcf-queue-worker --since "30 min ago"`
- WordPress debug log for `cdcf_process_translation` errors

### Jobs are processed but content is not translated

The OpenAI API key may not be configured in WordPress:
- Check `Settings > CDCF` in wp-admin for the API key
- Check the WordPress debug log for OpenAI API errors
