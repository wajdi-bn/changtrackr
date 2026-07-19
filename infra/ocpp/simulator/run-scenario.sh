#!/usr/bin/env sh
set -eu

: "${OCPP_SIMULATOR_UI_PASSWORD:?Missing OCPP_SIMULATOR_UI_PASSWORD}"
: "${OCPP_SIMULATOR_STATION_IDENTITY:=CT-TUN-001}"

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

echo "1/3 Sending Available for ${OCPP_SIMULATOR_STATION_IDENTITY} connector 1"
${cli} ocpp status-notification --connector-id 1 --error-code NoError --status Available "${station_hash}"
sleep 2

echo "2/3 Sending Charging for ${OCPP_SIMULATOR_STATION_IDENTITY} connector 1"
${cli} ocpp status-notification --connector-id 1 --error-code NoError --status Charging "${station_hash}"
sleep 2

echo "3/3 Sending Faulted for ${OCPP_SIMULATOR_STATION_IDENTITY} connector 1"
${cli} ocpp status-notification --connector-id 1 --error-code ConnectorLockFailure --status Faulted "${station_hash}"

echo "OCPP scenario completed for ${OCPP_SIMULATOR_STATION_IDENTITY}: Available -> Charging -> Faulted"
