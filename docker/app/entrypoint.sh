#!/bin/sh
set -e
cd /var/www/html

# Secrets nobody needs to choose are generated once into the storage volume and reused on every restart.
# The `migrate` service runs before the others, so it is the one that creates them.
secrets=storage/app/private/generated.env
if [ ! -f "$secrets" ]; then
    mkdir -p "$(dirname "$secrets")"
    (
        umask 077
        echo "APP_KEY=base64:$(head -c 32 /dev/urandom | base64)" > "$secrets.tmp"
        echo "REVERB_APP_KEY=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')" >> "$secrets.tmp"
        echo "REVERB_APP_SECRET=$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')" >> "$secrets.tmp"
    )
    mv "$secrets.tmp" "$secrets"
fi

# Values set in the env file win over the generated ones.
for name in APP_KEY REVERB_APP_KEY REVERB_APP_SECRET; do
    eval "current=\${$name:-}"
    if [ -z "$current" ]; then
        export "$(grep "^$name=" "$secrets")"
    fi
done

php artisan optimize >/dev/null

exec "$@"
