#!/usr/bin/env sh
set -eu

if [ "$#" -ne 1 ] || [ ! -f "$1" ]; then
  echo "Usage: sudo chargetrackr-restore /path/to/backup.sql.gz" >&2
  exit 1
fi

deployment_dir="/opt/chargetrackr/deployment"
env_file="${deployment_dir}/.env"
database="$(sed -n 's/^POSTGRES_DB=//p' "${env_file}" | tail -n 1)"
username="$(sed -n 's/^POSTGRES_USER=//p' "${env_file}" | tail -n 1)"

printf 'Restore %s into database %s? Type RESTORE to continue: ' "$1" "${database}"
read -r confirmation
if [ "${confirmation}" != 'RESTORE' ]; then
  echo 'Restore cancelled.'
  exit 1
fi

cd "${deployment_dir}"
gzip -dc "$1" | docker compose --env-file .env -f compose.yml exec -T postgres \
  psql --set ON_ERROR_STOP=1 --username "${username}" "${database}"
echo 'Database restore completed.'
