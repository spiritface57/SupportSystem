#!/bin/sh
set -e

echo "[clamav] preparing runtime directories"
mkdir -p /run/clamav /var/lib/clamav
chown -R clamav:clamav /run/clamav /var/lib/clamav

echo "[clamav] cleaning stale state"
rm -f /run/clamav/clamd.sock /run/clamav/clamd.pid

echo "[clamav] updating database"
freshclam
