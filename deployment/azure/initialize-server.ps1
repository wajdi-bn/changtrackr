param(
    [Parameter(Mandatory=$true)][string]$HostName,
    [string]$AdminUsername = 'azureuser',
    [string]$GhcrUsername = 'wajdi-bn'
)

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$source = Join-Path $repoRoot 'deployment\production'
$envFile = Join-Path $source '.env'
if (-not (Test-Path -LiteralPath $envFile)) {
    throw 'Run deployment/production/prepare-environment.ps1 and fill deployment/production/.env first.'
}

$required = 'APP_KEY','DB_PASSWORD','REDIS_PASSWORD','RESEND_API_KEY','GOOGLE_CLIENT_ID','GOOGLE_CLIENT_SECRET','REVERB_APP_SECRET','PAYMENT_SIMULATOR_API_KEY','PAYMENT_SIMULATOR_WEBHOOK_SECRET','OCPP_GATEWAY_SHARED_SECRET','OCPP_SIMULATOR_STATION_SECRET','OCPP_SIMULATOR_UI_PASSWORD','OCPP_SIMULATOR_CONTROL_TOKEN','AZURE_BACKUP_STORAGE_ACCOUNT'
$content = Get-Content -LiteralPath $envFile -Raw
foreach ($key in $required) {
    if ($content -notmatch "(?m)^$([regex]::Escape($key))=.+$") { throw "Missing production value: $key" }
}

$target = "$AdminUsername@$HostName"
& ssh $target 'sudo cloud-init status --wait'
if ($LASTEXITCODE -ne 0) { throw 'The VM cloud-init process did not complete successfully.' }
& ssh $target 'rm -rf /tmp/chargetrackr-deployment'
if ($LASTEXITCODE -ne 0) { throw 'Could not prepare the remote staging directory.' }
& scp -r $source "${target}:/tmp/chargetrackr-deployment"
if ($LASTEXITCODE -ne 0) { throw 'Could not transfer production files to the VM.' }

$install = @'
set -eu
sudo rm -rf /opt/chargetrackr/deployment.next
sudo mv /tmp/chargetrackr-deployment /opt/chargetrackr/deployment.next
sudo chown -R root:docker /opt/chargetrackr/deployment.next
sudo chmod 0750 /opt/chargetrackr/deployment.next
sudo chmod 0640 /opt/chargetrackr/deployment.next/.env
sudo find /opt/chargetrackr/deployment.next/scripts -type f -name '*.sh' -exec chmod 0755 {} \;
sudo rm -rf /opt/chargetrackr/deployment
sudo mv /opt/chargetrackr/deployment.next /opt/chargetrackr/deployment
sudo install -m 0755 /opt/chargetrackr/deployment/scripts/deploy-release.sh /usr/local/sbin/chargetrackr-deploy
sudo install -m 0755 /opt/chargetrackr/deployment/scripts/backup-postgres.sh /usr/local/sbin/chargetrackr-backup
sudo install -m 0755 /opt/chargetrackr/deployment/scripts/restore-postgres.sh /usr/local/sbin/chargetrackr-restore
sudo install -m 0644 /opt/chargetrackr/deployment/systemd/chargetrackr-backup.service /etc/systemd/system/chargetrackr-backup.service
sudo install -m 0644 /opt/chargetrackr/deployment/systemd/chargetrackr-backup.timer /etc/systemd/system/chargetrackr-backup.timer
sudo systemctl daemon-reload
sudo systemctl enable --now chargetrackr-backup.timer
'@
$install | & ssh $target 'bash -s'
if ($LASTEXITCODE -ne 0) { throw 'Could not initialize the ChargeTrackr deployment directory.' }

$secureToken = Read-Host 'GitHub classic PAT with read:packages only' -AsSecureString
$pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureToken)
try {
    $plainToken = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    $plainToken | & ssh $target "sudo docker login ghcr.io --username '$GhcrUsername' --password-stdin"
    if ($LASTEXITCODE -ne 0) { throw 'GHCR authentication failed.' }
}
finally {
    if ($pointer -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }
    $plainToken = $null
}

Write-Output 'Server initialized. DNS and GitHub OIDC can now be configured.'
