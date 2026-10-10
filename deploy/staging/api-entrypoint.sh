#!/bin/sh
set -eu

if [ "$(id -u)" -ne 0 ]; then
    echo 'ERROR: staging API entrypoint must start as root to prepare private secret files.' >&2
    exit 78
fi
if [ "${APP_ENV:-}" != "staging" ] || [ "${APP_DEBUG:-true}" != "false" ]; then
    echo 'ERROR: staging API requires APP_ENV=staging and APP_DEBUG=false.' >&2
    exit 78
fi
if [ "${BIYAHE_OFFLINE_WALKING_PREVIEW:-false}" = "true" ]; then
    echo 'ERROR: development walking preview must remain disabled in staging.' >&2
    exit 78
fi
for secret in "${APP_KEY_SOURCE_FILE:-}" "${DB_PASSWORD_SOURCE_FILE:-}"; do
    if [ -z "$secret" ] || [ ! -r "$secret" ] || [ ! -s "$secret" ]; then
        echo 'ERROR: required secret file is missing, unreadable, or empty.' >&2
        exit 78
    fi
done
app_key_value="$(cat "$APP_KEY_SOURCE_FILE")"
db_password_value="$(cat "$DB_PASSWORD_SOURCE_FILE")"
if [ -z "$app_key_value" ] || [ -z "$db_password_value" ]; then
    echo 'ERROR: required secret contents are empty.' >&2
    exit 78
fi
unset app_key_value db_password_value

install -d -o www-data -g www-data -m 0500 /run/biyahe-secrets
install -o www-data -g www-data -m 0400 "$APP_KEY_SOURCE_FILE" /run/biyahe-secrets/app_key
install -o www-data -g www-data -m 0400 "$DB_PASSWORD_SOURCE_FILE" /run/biyahe-secrets/db_password
export APP_KEY_FILE=/run/biyahe-secrets/app_key
export DB_PASSWORD_FILE=/run/biyahe-secrets/db_password

command -v gosu >/dev/null 2>&1 || { echo 'ERROR: gosu is required to drop API privileges.' >&2; exit 78; }
exec gosu www-data "$@"
