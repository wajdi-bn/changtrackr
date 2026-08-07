#!/usr/bin/env sh
set -eu

: "${OCPP_SIMULATOR_UI_PASSWORD:?Missing OCPP_SIMULATOR_UI_PASSWORD}"
: "${OCPP_SIMULATOR_STATION_IDENTITY:=CT-TUN-001}"
: "${OCPP_SIMULATOR_CONNECTOR_STATUS:?Missing OCPP_SIMULATOR_CONNECTOR_STATUS}"
: "${OCPP_SIMULATOR_CONNECTOR_ID:=1}"

case "${OCPP_SIMULATOR_CONNECTOR_STATUS}" in
  Available|Preparing) ;;
  *)
    echo "Unsupported connector status: ${OCPP_SIMULATOR_CONNECTOR_STATUS}" >&2
    exit 1
    ;;
esac

config_file="/tmp/evse-cli-config.json"
node /usr/app/write-cli-config.mjs /usr/app/cli-config-template.json "${config_file}"

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

${cli} ocpp status-notification \
  --connector-id "${OCPP_SIMULATOR_CONNECTOR_ID}" \
  --error-code NoError \
  --status "${OCPP_SIMULATOR_CONNECTOR_STATUS}" \
  "${station_hash}"

echo "${OCPP_SIMULATOR_STATION_IDENTITY} connector ${OCPP_SIMULATOR_CONNECTOR_ID} reported ${OCPP_SIMULATOR_CONNECTOR_STATUS}."
