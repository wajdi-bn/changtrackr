#!/usr/bin/env sh
set -eu

: "${PAYMENT_SIMULATOR_API_KEY:?Missing PAYMENT_SIMULATOR_API_KEY}"
if [ "${#PAYMENT_SIMULATOR_API_KEY}" -lt 32 ]; then
  echo "PAYMENT_SIMULATOR_API_KEY must contain at least 32 characters." >&2
  exit 1
fi

rm -rf /home/wiremock/mappings
mkdir -p /home/wiremock/mappings
for file in /opt/chargetrackr/templates/*.json; do
  sed "s/__PAYMENT_SIMULATOR_API_KEY__/${PAYMENT_SIMULATOR_API_KEY}/g" "$file" \
    > "/home/wiremock/mappings/$(basename "$file")"
done

exec /docker-entrypoint.sh "$@"
