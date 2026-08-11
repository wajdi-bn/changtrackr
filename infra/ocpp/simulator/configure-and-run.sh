#!/usr/bin/env sh
set -eu

: "${OCPP_SIMULATOR_STATION_SECRET:?Missing OCPP_SIMULATOR_STATION_SECRET}"
: "${OCPP_SIMULATOR_UI_PASSWORD:?Missing OCPP_SIMULATOR_UI_PASSWORD}"

config_file="/usr/app/dist/assets/config.json"
template_file="/usr/app/dist/assets/station-templates/chargetrackr.station-template.json"
stations_seed_file="/usr/app/dist/assets/chargetrackr-stations.seed.json"
stations_file="${OCPP_SIMULATOR_STATIONS_FILE:-/runtime/stations.json}"
profiles_file="/usr/app/dist/assets/chargetrackr-simulator-profiles.json"

mkdir -p "$(dirname "${stations_file}")"
if [ ! -f "${stations_file}" ]; then
  cp "${stations_seed_file}" "${stations_file}"
fi

node /usr/local/lib/chargetrackr/generate-simulator-config.mjs \
  "${config_file}" \
  "${template_file}" \
  "${stations_file}" \
  "${profiles_file}"

exec node dist/start.js
