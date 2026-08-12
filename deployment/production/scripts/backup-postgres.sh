#!/usr/bin/env sh
set -eu

deployment_dir="/opt/chargetrackr/deployment"
backup_dir="/var/backups/chargetrackr"
env_file="${deployment_dir}/.env"
stamp="$(date -u +%Y%m%dT%H%M%SZ)"
archive="${backup_dir}/chargetrackr-${stamp}.sql.gz"

read_env() {
  sed -n "s/^$1=//p" "${env_file}" | tail -n 1
}

storage_account="$(read_env AZURE_BACKUP_STORAGE_ACCOUNT)"
container="$(read_env AZURE_BACKUP_CONTAINER)"
retention_days="$(read_env BACKUP_RETENTION_DAYS)"
database="$(read_env POSTGRES_DB)"
username="$(read_env POSTGRES_USER)"

if [ -z "${storage_account}" ] || [ -z "${container}" ]; then
  echo "Azure backup storage is not configured." >&2
  exit 1
fi

mkdir -p "${backup_dir}"
cd "${deployment_dir}"
docker compose --env-file .env -f compose.yml exec -T postgres \
  pg_dump --clean --if-exists --no-owner --username "${username}" "${database}" \
  | gzip -9 > "${archive}"

az login --identity --allow-no-subscriptions >/dev/null
az storage blob upload \
  --auth-mode login \
  --account-name "${storage_account}" \
  --container-name "${container}" \
  --name "database/$(basename "${archive}")" \
  --file "${archive}" \
  --overwrite true \
  --only-show-errors >/dev/null

find "${backup_dir}" -type f -name 'chargetrackr-*.sql.gz' \
  -mtime "+${retention_days:-7}" -delete
echo "Backup uploaded: $(basename "${archive}")"
