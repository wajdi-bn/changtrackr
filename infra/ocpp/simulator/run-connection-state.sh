#!/usr/bin/env sh
set -eu

: "${OCPP_SIMULATOR_UI_PASSWORD:?Missing OCPP_SIMULATOR_UI_PASSWORD}"
: "${OCPP_SIMULATOR_STATION_IDENTITY:=CT-TUN-001}"
: "${OCPP_SIMULATOR_CONNECTION_ACTION:?Missing OCPP_SIMULATOR_CONNECTION_ACTION}"

case "${OCPP_SIMULATOR_CONNECTION_ACTION}" in
  open|close) ;;
  *)
    echo "Unsupported connection action: ${OCPP_SIMULATOR_CONNECTION_ACTION}" >&2
    exit 1
    ;;
esac

config_file="/tmp/evse-cli-config.json"
cp /usr/app/cli-config-template.json "${config_file}"
sed -i "s|__OCPP_SIMULATOR_UI_PASSWORD__|${OCPP_SIMULATOR_UI_PASSWORD}|g" "${config_file}"

cli="node /usr/app/cli/cli.js --config ${config_file} --json"
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

station_action="stop"
if [ "${OCPP_SIMULATOR_CONNECTION_ACTION}" = "open" ]; then
  station_action="start"
fi

${cli} station "${station_action}" "${station_hash}"
echo "Simulator station ${station_action} requested for ${OCPP_SIMULATOR_STATION_IDENTITY}."
