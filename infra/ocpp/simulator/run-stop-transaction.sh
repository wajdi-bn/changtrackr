#!/usr/bin/env sh
set -eu

: "${OCPP_SIMULATOR_UI_PASSWORD:?Missing OCPP_SIMULATOR_UI_PASSWORD}"
: "${OCPP_SIMULATOR_STATION_IDENTITY:=CT-TUN-001}"

config_file="/tmp/evse-cli-config.json"
node /usr/app/write-cli-config.mjs /usr/app/cli-config-template.json "${config_file}"

cli="node /usr/app/cli/cli.js --config ${config_file} --json"
station_state="$(${cli} station list)"
station_hash="$(printf '%s' "${station_state}" | node /usr/app/read-station-state.mjs "${OCPP_SIMULATOR_STATION_IDENTITY}" hashId)"
transaction_id="$(printf '%s' "${station_state}" | node /usr/app/read-station-state.mjs "${OCPP_SIMULATOR_STATION_IDENTITY}" transactionId)"

${cli} transaction stop --payload "{\"transactionId\":${transaction_id}}" "${station_hash}"
sleep 2
${cli} ocpp status-notification --connector-id 1 --error-code NoError --status Available "${station_hash}"

echo "Stopped transaction ${transaction_id} on ${OCPP_SIMULATOR_STATION_IDENTITY}."
