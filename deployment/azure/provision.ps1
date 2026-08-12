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
    param([string[]]$Arguments)
    & $az @Arguments
    if ($LASTEXITCODE -ne 0) { throw "Azure CLI failed: az $($Arguments -join ' ')" }
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
$assignment = & $az role assignment list --assignee $principalId --scope $storageScope --role 'Storage Blob Data Contributor' --query 'length(@)' -o tsv
if ($assignment -eq '0') {
    Invoke-Az -Arguments @('role','assignment','create','--assignee-object-id',$principalId,'--assignee-principal-type','ServicePrincipal','--role','Storage Blob Data Contributor','--scope',$storageScope,'--only-show-errors')
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
