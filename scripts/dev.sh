#!/usr/bin/env bash
# scripts/dev.sh — manage the Next.js dev server
#
# Usage:
#   ./scripts/dev.sh start    # Start the dev server in the background
#   ./scripts/dev.sh stop     # Stop the dev server
#   ./scripts/dev.sh restart  # Restart the dev server
#   ./scripts/dev.sh status   # Check if the dev server is running

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="${SCRIPT_DIR}/.."
PID_FILE="${PROJECT_DIR}/.dev.pid"
LOG_FILE="${PROJECT_DIR}/.dev.log"

is_running() {
  if [ -f "$PID_FILE" ]; then
    local pid
    pid=$(cat "$PID_FILE")
    if kill -0 "$pid" 2>/dev/null; then
      return 0
    fi
    # Stale PID file
    rm -f "$PID_FILE"
  fi
  return 1
}

do_start() {
  if is_running; then
    echo "Dev server is already running (PID $(cat "$PID_FILE"))"
    return 0
  fi

  echo "Starting Next.js dev server..."
  cd "$PROJECT_DIR"
  setsid nohup npm run dev > "$LOG_FILE" 2>&1 &
  echo $! > "$PID_FILE"
  echo "Dev server started (PID $(cat "$PID_FILE"))"
  echo "Logs: tail -f $LOG_FILE"
}

do_stop() {
  if ! is_running; then
    echo "Dev server is not running"
    return 0
  fi

  local pid
  pid=$(cat "$PID_FILE")
  echo "Stopping dev server (PID $pid)..."
  # Kill the entire process group (npm + next-server child)
  kill -- -"$pid" 2>/dev/null || kill "$pid" 2>/dev/null || true
  # Wait up to 5 seconds for graceful shutdown
  for i in $(seq 1 10); do
    if ! kill -0 "$pid" 2>/dev/null; then
      break
    fi
    sleep 0.5
  done
  # Force kill if still running
  if kill -0 "$pid" 2>/dev/null; then
    kill -9 -- -"$pid" 2>/dev/null || kill -9 "$pid" 2>/dev/null || true
  fi
  rm -f "$PID_FILE"
  echo "Dev server stopped"
}

do_status() {
  if is_running; then
    echo "Dev server is running (PID $(cat "$PID_FILE"))"
  else
    echo "Dev server is not running"
  fi
}

case "${1:-}" in
  start)   do_start ;;
  stop)    do_stop ;;
  restart) do_stop; do_start ;;
  status)  do_status ;;
  *)
    echo "Usage: $0 {start|stop|restart|status}"
    exit 1
    ;;
esac
