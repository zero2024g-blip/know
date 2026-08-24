#!/usr/bin/env bash
# Switch the panel between deployment modes.
#
#   ./tools/set-mode.sh cloudflare   # MODE A - behind Cloudflare
#   ./tools/set-mode.sh direct       # MODE B - no CDN in front
#   ./tools/set-mode.sh status
#
# Edits .htaccess in place (keeps a .bak). Also prints the .env line you
# must set, because PHP has to agree with Apache about who to trust.
set -euo pipefail
cd "$(dirname "$0")/.."
exec python3 tools/set_mode.py "${1:-status}"
