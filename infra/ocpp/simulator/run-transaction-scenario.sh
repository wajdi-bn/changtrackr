#!/usr/bin/env sh
set -eu

: "${OCPP_SIMULATOR_UI_PASSWORD:?Missing OCPP_SIMULATOR_UI_PASSWORD}"
: "${OCPP_SIMULATOR_STATION_IDENTITY:=CT-TUN-001}"
: "${OCPP_SIMULATOR_ID_TAG:=TEST-TAG-001}"

config_file="/tmp/evse-cli-config.json"
cp /usr/app/cli-config-template.json "${config_file}"
sed -i "s|__OCPP_SIMULATOR_UI_PASSWORD__|${OCPP_SIMULATOR_UI_PASSWORD}|g" "${config_file}"

cli="node /usr/app/cli/cli.js --config ${config_file} --json"

attempt=1
until ${cli} simulator state >/dev/null 2>&1; do
  if [ "${attempt}" -ge 30 ]; then
    echo "The SAP simulator UI did not become ready." >&2
    exit 1
  fi
  attempt=$((attempt + 1))
  sleep 2
done

# The UI can answer before all workers have registered their stations.
attempt=1
while true; do
  station_state="$(${cli} station list 2>/dev/null || true)"
  if station_hash="$(printf '%s' "${station_state}" | node /usr/app/read-station-state.mjs "${OCPP_SIMULATOR_STATION_IDENTITY}" hashId 2>/dev/null)"; then
    break
  fi
  if [ "${attempt}" -ge 30 ]; then
    echo "Station ${OCPP_SIMULATOR_STATION_IDENTITY} did not become ready." >&2
    exit 1
  fi
  attempt=$((attempt + 1))
  sleep 2
done

echo "1/6 Preparing connector 1"
${cli} ocpp status-notification --connector-id 1 --error-code NoError --status Available "${station_hash}"
sleep 1

echo "2/6 Authorizing ${OCPP_SIMULATOR_ID_TAG}"
${cli} ocpp authorize --id-tag "${OCPP_SIMULATOR_ID_TAG}" "${station_hash}"
sleep 1

echo "3/6 Starting a transaction"
${cli} transaction start --connector-id 1 --id-tag "${OCPP_SIMULATOR_ID_TAG}" "${station_hash}"
station_state="$(${cli} station list)"
if ! transaction_id="$(printf '%s' "${station_state}" | node /usr/app/read-station-state.mjs "${OCPP_SIMULATOR_STATION_IDENTITY}" transactionId 2>/dev/null)"; then
  echo "${station_state}"
  echo "The simulator did not return a transaction identifier for ${OCPP_SIMULATOR_STATION_IDENTITY}." >&2
  exit 1
fi
echo "Central transaction id: ${transaction_id}"
sleep 3

echo "4/6 Sending a meter sample"
${cli} ocpp meter-values --connector-id 1 "${station_hash}"
sleep 2

echo "5/6 Stopping the active transaction"
${cli} transaction stop --payload "{\"transactionId\":${transaction_id}}" "${station_hash}"
sleep 2

echo "6/6 Returning connector 1 to Available"
${cli} ocpp status-notification --connector-id 1 --error-code NoError --status Available "${station_hash}"

echo "OCPP transaction scenario completed for ${OCPP_SIMULATOR_STATION_IDENTITY}: Authorize -> StartTransaction -> MeterValues -> StopTransaction"
