# CDCF Scripts

Python API client and utilities for the CDCF CMS.

## Python API Client (`cdcf_api.py`)

CLI and library for the CDCF WordPress REST API and WPGraphQL.

**Full documentation:** [`docs/python-api-client.md`](../docs/python-api-client.md)

### Quick Start

```bash
# Setup
uv venv scripts/.venv
uv pip install -r scripts/requirements.txt --python scripts/.venv/bin/python

# Example commands
scripts/.venv/bin/python scripts/cdcf_api.py get-relationship --post-id 5 --field team_members
scripts/.venv/bin/python scripts/cdcf_api.py graphql --query '{ pages(first: 5) { nodes { databaseId title } } }'
```

## Queue Worker (`cdcf_queue_worker.sh`)

Processes AI translation jobs from Redis. See [`docs/redis-queue-worker.md`](../docs/redis-queue-worker.md).
