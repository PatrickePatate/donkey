#!/usr/bin/env bash
# Deploy the images built by CI: ./deploy.sh [tag]   (tag = commit SHA or "latest"; roll back by passing an older SHA)
set -euo pipefail
cd "$(dirname "$0")"

export DONKEY_TAG="${1:-latest}"
compose="docker compose -f compose.prod.yaml"
# Older installs keep their settings in .env.production instead of .env.
if [ -f .env.production ]; then compose="$compose --env-file .env.production"; fi

$compose pull
$compose up -d --remove-orphans
