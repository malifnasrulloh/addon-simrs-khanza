#!/bin/bash
set -e

SYNC_INTERVAL="${MWL_SYNC_INTERVAL:-10}"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] MWL Service Starting..."
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Sync Interval: ${SYNC_INTERVAL}s"

# Start the sync daemon in background
(
  while true; do
    php /app/index.php
    sleep "${SYNC_INTERVAL}"
  done
) &

# Start the web server in foreground
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Dashboard listening on port 8080"
exec php -S 0.0.0.0:8080 -t /app
