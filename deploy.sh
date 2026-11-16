#!/usr/bin/env bash
# Deploy the images built by CI: ./deploy.sh [tag]   (tag = commit SHA or "latest"; roll back by passing an older SHA)
set -euo pipefail
cd "$(dirname "$0")"

export DONKEY_TAG="${1:-latest}"
compose="docker compose --env-file .env.production -f compose.prod.yaml"

$compose pull
$compose up -d --remove-orphans
