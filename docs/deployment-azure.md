# Deploy ChargeTrackr to Azure for Students

This runbook deploys the complete educational platform to one Ubuntu VM in
Poland Central. It is designed for the current Azure for Students subscription,
the `chargetrackr.me` Namecheap domain and the private GitHub repository.

## Target architecture

| Public endpoint | Destination |
|---|---|
| `https://chargetrackr.me` | React landing page and role workspaces |
| `https://api.chargetrackr.me` | Laravel API, Sanctum and Google OAuth callback |
| `wss://realtime.chargetrackr.me` | Laravel Reverb |
| `wss://ocpp.chargetrackr.me/ocpp/{identity}` | OCPP 1.6J gateway |

Caddy is the only container with published ports. PostgreSQL, Redis, the
payment simulator, the simulator control service and all application runtimes
remain private on the Docker network.

The default `Standard_B2als_v2` VM is the minimum recommended full-stack host.
It is not part of the 1 GB VM free allowance. Keep the Azure spending limit
enabled and create budget alerts in Cost Management. Deallocating the VM stops
compute charges, but managed disk and static public IP charges can continue.

## 1. Local prerequisites

Verify the authenticated accounts from PowerShell:

```powershell
az account show --query "{subscription:name,state:state,user:user.name}" -o table
gh auth status
```

Generate an SSH key only when one does not already exist:

```powershell
ssh-keygen -t ed25519 -C "chargetrackr-azure"
```

## 2. Prepare the private production environment

From the repository root:

```powershell
powershell -ExecutionPolicy Bypass -File deployment/production/prepare-environment.ps1
```

Open the ignored file `deployment/production/.env` and fill only these external
values:

```dotenv
ACME_EMAIL=wajdi.benabdeljelil@etudiant-fst.utm.tn
RESEND_API_KEY=your-current-resend-key
GOOGLE_CLIENT_ID=your-current-google-client-id
GOOGLE_CLIENT_SECRET=your-current-google-client-secret
```

Do not copy this file into chat, GitHub Actions or the repository. The generated
database, Redis, Reverb, payment and OCPP secrets must be left unchanged.

## 3. Provision Azure

The provisioning script registers required resource providers and creates:

- one resource group in Poland Central;
- one VNet and subnet;
- one NSG allowing HTTPS/HTTP publicly and SSH only from the detected public IP;
- one static public IP;
- one Ubuntu 24.04 VM with SSH-only authentication and managed identity;
- one private StorageV2 account and Blob container for backups.

Run:

```powershell
powershell -ExecutionPolicy Bypass -File deployment/azure/provision.ps1
```

If the current network IP cannot be detected, pass it explicitly:

```powershell
powershell -ExecutionPolicy Bypass -File deployment/azure/provision.ps1 `
  -AllowedSshCidr "YOUR.PUBLIC.IP/32"
```

The non-secret result is written to the ignored
`deployment/azure/output.json`. Copy `backupStorageAccount` into
`AZURE_BACKUP_STORAGE_ACCOUNT` in the private production `.env`.

Poland Central is used because `Standard_B2als_v2` is not offered to the Azure
for Students subscription in France Central or Italy North (`SkuNotAvailable`).
If the size is unavailable in the chosen region too, inspect available sizes and
pick another 4 GB Linux size, or switch region, before rerunning:

```powershell
az vm list-skus --location polandcentral --resource-type virtualMachines `
  --query "[?restrictions==null && contains(name, 'B2')].name" -o table
```

## 4. Configure Namecheap DNS

In **Domain List > Manage > Advanced DNS**, preserve every Resend SPF, DKIM,
DMARC and domain-verification record. Add these records using the static IP from
`deployment/azure/output.json`:

| Type | Host | Value | TTL |
|---|---|---|---|
| A | `@` | Azure public IP | Automatic |
| CNAME | `www` | `chargetrackr.me` | Automatic |
| A | `api` | Azure public IP | Automatic |
| A | `realtime` | Azure public IP | Automatic |
| A | `ocpp` | Azure public IP | Automatic |

Check propagation:

```powershell
Resolve-DnsName chargetrackr.me
Resolve-DnsName api.chargetrackr.me
Resolve-DnsName realtime.chargetrackr.me
Resolve-DnsName ocpp.chargetrackr.me
```

Do not initialize the HTTPS stack until all four A records return the Azure IP.

## 5. Configure Google OAuth and Resend

In the existing Google Cloud OAuth web client, add exactly:

```text
Authorized JavaScript origin: https://chargetrackr.me
Authorized redirect URI: https://api.chargetrackr.me/auth/oauth/google/callback
```

Keep the localhost entries for local development. Do not create a second OAuth
client unless the current client belongs to another project or organization.

In Resend, confirm `chargetrackr.me` remains verified and that
`no-reply@chargetrackr.me` is an allowed sender. DNS verification alone does not
replace the private `RESEND_API_KEY` in the production `.env`.

## 6. Publish the first image set

The repository is private, so the VM needs a GitHub classic PAT restricted to
`read:packages`. GitHub Actions uses its built-in token to publish images.

First run the production workflow manually:

1. Open **GitHub > Actions > Deploy production**.
2. Select **Run workflow** on `main` and enable `publish_only`.
3. The publish job creates immutable images tagged with the commit SHA.
4. The Azure deployment job is skipped cleanly during this bootstrap run.

## 7. Initialize the VM

Wait for DNS propagation and for the image publish job to finish, then run:

```powershell
$azure = Get-Content deployment/azure/output.json | ConvertFrom-Json
powershell -ExecutionPolicy Bypass -File deployment/azure/initialize-server.ps1 `
  -HostName $azure.publicIp `
  -AdminUsername $azure.adminUsername
```

When prompted, paste the GitHub PAT with `read:packages`. It is sent through
standard input to `docker login`; it is not written by the script.

## 8. Configure GitHub-to-Azure OIDC

Run after the VM exists:

```powershell
powershell -ExecutionPolicy Bypass -File deployment/azure/configure-github-oidc.ps1
```

This creates a federated Entra application scoped to the GitHub `production`
environment and grants it VM-level deployment rights. It stores Azure resource
identifiers as GitHub environment secrets but creates no Azure client secret.

Protect the GitHub `production` environment with a required reviewer before
using it for routine deployments.

## 9. Deploy the first release

Rerun **Actions > Deploy production**. The workflow builds the images and uses
Azure Run Command to execute the immutable release on the VM.

Verify from PowerShell:

```powershell
curl.exe -I https://chargetrackr.me
curl.exe https://api.chargetrackr.me/up
```

Then test in the browser:

1. local login and Google OAuth;
2. registration and a real Resend verification email;
3. Reverb notifications without browser WebSocket errors;
4. simulation lab connection, heartbeat, plug/unplug and fault recovery;
5. client charging flow, payment simulator callback and receipt;
6. uploaded document and PDF preview.

## 10. Backups and operations

The initialization script enables the daily backup timer. Check it with:

```powershell
ssh azureuser@YOUR_AZURE_IP "systemctl status chargetrackr-backup.timer"
ssh azureuser@YOUR_AZURE_IP "sudo /usr/local/sbin/chargetrackr-backup"
```

Download a backup from Blob before testing a restore. Restoration is deliberately
interactive and must never be run against the live database without a confirmed
maintenance window:

```bash
sudo chargetrackr-restore /path/to/chargetrackr-TIMESTAMP.sql.gz
```

Useful operational commands:

```bash
cd /opt/chargetrackr/deployment
sudo docker compose --env-file .env -f compose.yml ps
sudo docker compose --env-file .env -f compose.yml logs --tail=150 backend queue-worker
sudo docker compose --env-file .env -f compose.yml logs --tail=150 ocpp-gateway ocpp-simulator
```

To stop compute billing when the platform is not needed:

```powershell
az vm deallocate --resource-group chargetrackr-prod-rg --name chargetrackr-prod-vm
```

To resume:

```powershell
az vm start --resource-group chargetrackr-prod-rg --name chargetrackr-prod-vm
```

The static IP is retained, so Namecheap records do not change.

To redeploy or roll back to any published commit SHA:

```bash
sudo chargetrackr-deploy FULL_GIT_COMMIT_SHA
```

## 11. Acceptance checklist

- [ ] Azure budget alerts exist at 50, 75 and 90 USD.
- [ ] The spending limit remains enabled.
- [ ] SSH accepts only the maintainer's current `/32` address.
- [ ] PostgreSQL, Redis and simulator-control ports are not publicly reachable.
- [ ] Every public endpoint has a valid certificate.
- [ ] Google OAuth redirects only to the expected callback.
- [ ] Resend sends to an address other than the original test account.
- [ ] A database backup exists in the private Azure Blob container.
- [ ] GitHub `production` requires approval.
- [ ] The OCPP simulator recovers after a VM or container restart.

## What remains deliberately manual

The scripts do not modify Namecheap, Google Cloud or Resend because those
accounts are separate security boundaries. They also do not remove Azure
resources, rotate application secrets or restore a database automatically.
Those operations require a human decision and an explicit maintenance window.
