param(
    [string]$ResourceGroup = 'chargetrackr-prod-rg',
    [string]$Location = 'francecentral',
    [string]$Prefix = 'chargetrackr-prod',
    [string]$VmSize = 'Standard_B2als_v2',
    [string]$AdminUsername = 'azureuser',
    [string]$SshPublicKeyPath = "$HOME/.ssh/id_ed25519.pub",
    [string]$AllowedSshCidr = ''
)

$ErrorActionPreference = 'Stop'
# Azure CLI reports failures through its exit code; do not let native stderr
# writes raise a terminating error before Invoke-Az can inspect the result.
$PSNativeCommandUseErrorActionPreference = $false
$az = (Get-Command az -ErrorAction SilentlyContinue).Source
if (-not $az) {
    $candidate = 'C:\Program Files\Microsoft SDKs\Azure\CLI2\wbin\az.cmd'
    if (Test-Path -LiteralPath $candidate) { $az = $candidate }
}
if (-not $az) { throw 'Azure CLI was not found.' }
if (-not (Test-Path -LiteralPath $SshPublicKeyPath)) {
    throw "SSH public key not found: $SshPublicKeyPath. Run ssh-keygen -t ed25519 first."
}
if ([string]::IsNullOrWhiteSpace($AllowedSshCidr)) {
    $AllowedSshCidr = "$(Invoke-RestMethod -Uri 'https://api.ipify.org')/32"
}

function Invoke-Az {
    param(
        [string[]]$Arguments,
        [int]$MaxAttempts = 5
    )
    # Newer Azure regions occasionally return transient control-plane read
    # errors (for example ResourceNotFound on a resource that was just
    # created) because ARM replication is eventually consistent. Retry those
    # with backoff, but fail fast on permanent capacity/quota/policy errors so
    # the operator can switch region instead of waiting on doomed retries.
    $permanent = 'SkuNotAvailable|QuotaExceeded|RequestDisallowedByPolicy|NotAvailableForSubscription|OperationNotAllowed|AuthorizationFailed|InvalidAuthenticationTokenTenant'
    for ($attempt = 1; $attempt -le $MaxAttempts; $attempt++) {
        # Windows PowerShell 5.1 turns redirected native stderr into a
        # terminating error under 'Stop', which would bypass the retry logic.
        # Relax the preference only around the CLI call, then restore it.
        $previousPreference = $ErrorActionPreference
        $ErrorActionPreference = 'Continue'
        try {
            $output = & $az @Arguments 2>&1
            $exit = $LASTEXITCODE
        } finally {
            $ErrorActionPreference = $previousPreference
        }
        Write-Host ($output | Out-String)
        if ($exit -eq 0) { return }
        $text = $output | Out-String
        if ($text -match $permanent) {
            throw "Azure CLI failed (non-retryable): az $($Arguments -join ' ')"
        }
        if ($attempt -lt $MaxAttempts) {
            $delay = [Math]::Min(30, 8 * $attempt)
            Write-Host "Transient Azure error on attempt $attempt/$MaxAttempts; retrying in $delay s..."
            Start-Sleep -Seconds $delay
        }
    }
    throw "Azure CLI failed after $MaxAttempts attempts: az $($Arguments -join ' ')"
}

$account = & $az account show --query '{id:id,state:state}' -o json | ConvertFrom-Json
if ($account.state -ne 'Enabled') { throw 'The selected Azure subscription is not enabled.' }

foreach ($provider in 'Microsoft.Compute','Microsoft.Network','Microsoft.Storage') {
    Invoke-Az -Arguments @('provider','register','--namespace',$provider,'--wait','--only-show-errors')
}

$vnet = "$Prefix-vnet"
$subnet = "$Prefix-subnet"
$nsg = "$Prefix-nsg"
$publicIp = "$Prefix-ip"
$nic = "$Prefix-nic"
$vm = "$Prefix-vm"
$cloudInit = Join-Path $PSScriptRoot 'cloud-init.yml'
$subscriptionHash = [System.BitConverter]::ToString(
    [System.Security.Cryptography.SHA256]::Create().ComputeHash(
        [System.Text.Encoding]::UTF8.GetBytes("$($account.id)-$ResourceGroup")
    )
).Replace('-', '').ToLowerInvariant()
$storage = "ctbackup$($subscriptionHash.Substring(0, 16))"

Invoke-Az -Arguments @('group','create','--name',$ResourceGroup,'--location',$Location,'--tags','application=ChargeTrackr','environment=production','--only-show-errors')
Invoke-Az -Arguments @('network','vnet','create','--resource-group',$ResourceGroup,'--location',$Location,'--name',$vnet,'--address-prefixes','10.40.0.0/16','--subnet-name',$subnet,'--subnet-prefixes','10.40.1.0/24','--only-show-errors')
Invoke-Az -Arguments @('network','nsg','create','--resource-group',$ResourceGroup,'--location',$Location,'--name',$nsg,'--only-show-errors')

$rules = @(
    @{ Name='AllowHttps'; Priority='100'; Port='443'; Source='Internet' },
    @{ Name='AllowHttp'; Priority='110'; Port='80'; Source='Internet' },
    @{ Name='AllowSshFromMaintainer'; Priority='120'; Port='22'; Source=$AllowedSshCidr }
)
foreach ($rule in $rules) {
    Invoke-Az -Arguments @('network','nsg','rule','create','--resource-group',$ResourceGroup,'--nsg-name',$nsg,'--name',$rule.Name,'--priority',$rule.Priority,'--access','Allow','--protocol','Tcp','--direction','Inbound','--source-address-prefixes',$rule.Source,'--destination-port-ranges',$rule.Port,'--only-show-errors')
}

Invoke-Az -Arguments @('network','public-ip','create','--resource-group',$ResourceGroup,'--location',$Location,'--name',$publicIp,'--sku','Standard','--allocation-method','Static','--only-show-errors')
Invoke-Az -Arguments @('network','nic','create','--resource-group',$ResourceGroup,'--location',$Location,'--name',$nic,'--vnet-name',$vnet,'--subnet',$subnet,'--network-security-group',$nsg,'--public-ip-address',$publicIp,'--only-show-errors')

$vmExists = (& $az vm list --resource-group $ResourceGroup --query "[?name=='$vm'] | length(@)" -o tsv) -eq '1'
if (-not $vmExists) {
    Invoke-Az -Arguments @('vm','create','--resource-group',$ResourceGroup,'--location',$Location,'--name',$vm,'--nics',$nic,'--image','Canonical:ubuntu-24_04-lts:server:latest','--size',$VmSize,'--admin-username',$AdminUsername,'--authentication-type','ssh','--ssh-key-values',$SshPublicKeyPath,'--assign-identity','--os-disk-name',"$Prefix-osdisk",'--os-disk-size-gb','64','--storage-sku','StandardSSD_LRS','--custom-data',$cloudInit,'--only-show-errors')
}

Invoke-Az -Arguments @('storage','account','create','--resource-group',$ResourceGroup,'--location',$Location,'--name',$storage,'--sku','Standard_LRS','--kind','StorageV2','--https-only','true','--min-tls-version','TLS1_2','--allow-blob-public-access','false','--allow-shared-key-access','false','--only-show-errors')
$containerExists = (& $az storage container-rm list --storage-account $storage --query "[?name=='backups'] | length(@)" -o tsv) -eq '1'
if (-not $containerExists) {
    Invoke-Az -Arguments @('storage','container-rm','create','--name','backups','--storage-account',$storage,'--only-show-errors')
}

$principalId = & $az vm identity show --resource-group $ResourceGroup --name $vm --query principalId -o tsv
$storageScope = & $az storage account show --resource-group $ResourceGroup --name $storage --query id -o tsv
# `az role assignment create/list --scope <resource-id>` fails with
# MissingSubscription on some Azure CLI builds (observed on 2.89.0). The
# `--all` list form works, so use it for the idempotency check, and create the
# assignment through the ARM REST API, which is unaffected by the CLI bug.
$blobRoleId = 'ba92f5b4-2d11-453d-a403-e96b0029c9fe' # Storage Blob Data Contributor
$assignment = & $az role assignment list --assignee $principalId --all --query "[?scope=='$storageScope' && roleDefinitionName=='Storage Blob Data Contributor'] | length(@)" -o tsv
if ($assignment -ne '1') {
    $assignmentName = [guid]::NewGuid().ToString()
    $roleBody = @{ properties = @{
        roleDefinitionId = "/subscriptions/$($account.id)/providers/Microsoft.Authorization/roleDefinitions/$blobRoleId"
        principalId      = $principalId
        principalType    = 'ServicePrincipal'
    } } | ConvertTo-Json -Compress
    $roleUrl = "https://management.azure.com$storageScope/providers/Microsoft.Authorization/roleAssignments/$($assignmentName)?api-version=2022-04-01"
    Invoke-Az -Arguments @('rest','--method','put','--url',$roleUrl,'--body',$roleBody,'--only-show-errors')
}
$address = & $az network public-ip show --resource-group $ResourceGroup --name $publicIp --query ipAddress -o tsv
$result = [ordered]@{
    resourceGroup = $ResourceGroup
    location = $Location
    vmName = $vm
    vmSize = $VmSize
    adminUsername = $AdminUsername
    publicIp = $address
    allowedSshCidr = $AllowedSshCidr
    backupStorageAccount = $storage
    backupContainer = 'backups'
}

$outputPath = Join-Path $PSScriptRoot 'output.json'
$result | ConvertTo-Json | Set-Content -LiteralPath $outputPath -Encoding UTF8
$result | ConvertTo-Json
Write-Output "Provisioning output saved to ignored file: $outputPath"
