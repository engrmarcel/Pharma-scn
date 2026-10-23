#!/usr/bin/env bash
# A/B load comparison: the same scenarios against two checkouts, alternating, on the same database.
#   bash tools/load/ab.sh <root A> <root B> <rounds> "<scenarios>" "<levels>" <seconds>
# Example: bash tools/load/ab.sh ../pharm-scn-abtest . 2 "catalogue_search,customers,stock_levels" "1,8" 15
set -uo pipefail
A=$1; B=$2; ROUNDS=${3:-2}; ONLY=${4:-catalogue_search,customers,stock_levels,pos_lookup}; LEVELS=${5:-1,8}; SECONDS_PER=${6:-15}
HERE="$(cd "$(dirname "$0")" && pwd)"
run() { # label root
    node "$HERE/cluster.mjs" --instances 8 --port 8300 --root "$2" --profile false > /tmp/ab-cluster.log 2>&1 &
    local pid=$!
    until grep -q "load balancer" /tmp/ab-cluster.log; do sleep 1; done
    sleep 2; curl -s -o /dev/null http://127.0.0.1:8300/api/v1/health
    node "$HERE/run.mjs" --only "$ONLY" --levels "$LEVELS" --seconds "$SECONDS_PER" 2>&1 | grep -v Saved | sed "s/^/$1 /"
    # Stop the balancer by the Windows PID listening on its port; its PHP instances end with it.
    local wpid
    wpid=$(netstat -ano | grep "127.0.0.1:8300 " | grep LISTEN | awk '{print $5}' | head -1)
    if [ -n "$wpid" ]; then taskkill //PID "$wpid" //F > /dev/null 2>&1 || kill "$pid"; else kill "$pid"; fi
    wait "$pid" 2>/dev/null || true
    # Let the per-user rate-limit window reset before the next round signs in again.
    sleep 61
}
for r in $(seq 1 "$ROUNDS"); do run "A$r" "$A"; run "B$r" "$B"; done
