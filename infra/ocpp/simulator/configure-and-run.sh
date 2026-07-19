#!/usr/bin/env sh
set -eu

: "${OCPP_SIMULATOR_STATION_SECRET:?Missing OCPP_SIMULATOR_STATION_SECRET}"
: "${OCPP_SIMULATOR_UI_PASSWORD:?Missing OCPP_SIMULATOR_UI_PASSWORD}"

config_file="/usr/app/dist/assets/config.json"
template_file="/usr/app/dist/assets/station-templates/chargetrackr.station-template.json"
stations_file="/usr/app/dist/assets/chargetrackr-stations.json"

node /usr/local/lib/chargetrackr/generate-simulator-config.mjs \
  "${config_file}" \
  "${template_file}" \
  "${stations_file}"

exec node dist/start.js
