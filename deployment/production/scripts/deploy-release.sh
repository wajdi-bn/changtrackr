#!/usr/bin/env sh
set -eu

release_tag="${1:-latest}"
deployment_dir="/opt/chargetrackr/deployment"
env_file="${deployment_dir}/.env"
compose_file="${deployment_dir}/compose.yml"

if [ ! -f "${env_file}" ] || [ ! -f "${compose_file}" ]; then
  echo "ChargeTrackr production files are not installed." >&2
  exit 1
fi

ghcr_owner="$(sed -n 's/^GHCR_OWNER=//p' "${env_file}" | tail -n 1)"
bundle_image="ghcr.io/${ghcr_owner}/chargetrackr-deployment:${release_tag}"
stage_dir="${deployment_dir}.next"
container_id=""

cleanup() {
  if [ -n "${container_id}" ]; then docker rm -f "${container_id}" >/dev/null 2>&1 || true; fi
  rm -rf "${stage_dir}"
}
trap cleanup EXIT

docker pull "${bundle_image}"
container_id="$(docker create "${bundle_image}")"
rm -rf "${stage_dir}"
mkdir -p "${stage_dir}"
docker cp "${container_id}:/bundle/." "${stage_dir}/"
docker rm "${container_id}" >/dev/null
container_id=""
cp "${env_file}" "${stage_dir}/.env"
chmod 0640 "${stage_dir}/.env"
rm -rf "${deployment_dir}.previous"
mv "${deployment_dir}" "${deployment_dir}.previous"
mv "${stage_dir}" "${deployment_dir}"

install -m 0755 "${deployment_dir}/scripts/deploy-release.sh" /usr/local/sbin/chargetrackr-deploy
install -m 0755 "${deployment_dir}/scripts/backup-postgres.sh" /usr/local/sbin/chargetrackr-backup
install -m 0755 "${deployment_dir}/scripts/restore-postgres.sh" /usr/local/sbin/chargetrackr-restore
install -m 0644 "${deployment_dir}/systemd/chargetrackr-backup.service" /etc/systemd/system/chargetrackr-backup.service
install -m 0644 "${deployment_dir}/systemd/chargetrackr-backup.timer" /etc/systemd/system/chargetrackr-backup.timer
systemctl daemon-reload

if grep -q '^IMAGE_TAG=' "${env_file}"; then
  sed -i "s/^IMAGE_TAG=.*/IMAGE_TAG=${release_tag}/" "${env_file}"
else
  printf '\nIMAGE_TAG=%s\n' "${release_tag}" >> "${env_file}"
fi

cd "${deployment_dir}"
docker compose --env-file .env -f compose.yml config --quiet
docker compose --env-file .env -f compose.yml pull
docker compose --env-file .env -f compose.yml up -d --remove-orphans
docker compose --env-file .env -f compose.yml ps
docker image prune -f >/dev/null

echo "ChargeTrackr release ${release_tag} deployed."
