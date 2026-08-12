param(
    [string]$Repository = 'wajdi-bn/changtrackr',
    [string]$Environment = 'production',
    [string]$ResourceGroup = 'chargetrackr-prod-rg',
    [string]$VmName = 'chargetrackr-prod-vm',
    [string]$ApplicationName = 'chargetrackr-github-deploy'
)

$ErrorActionPreference = 'Stop'
$az = (Get-Command az -ErrorAction SilentlyContinue).Source
if (-not $az) { $az = 'C:\Program Files\Microsoft SDKs\Azure\CLI2\wbin\az.cmd' }
$gh = (Get-Command gh -ErrorAction SilentlyContinue).Source
if (-not $gh) { $gh = 'C:\Program Files\GitHub CLI\gh.exe' }
if (-not (Test-Path -LiteralPath $az)) { throw 'Azure CLI was not found.' }
if (-not (Test-Path -LiteralPath $gh)) { throw 'GitHub CLI was not found.' }

& $gh auth status | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Authenticate GitHub CLI with gh auth login first.' }

$account = & $az account show --query '{subscriptionId:id,tenantId:tenantId}' -o json | ConvertFrom-Json
$appId = & $az ad app list --display-name $ApplicationName --query '[0].appId' -o tsv
if ([string]::IsNullOrWhiteSpace($appId)) {
    $appId = & $az ad app create --display-name $ApplicationName --query appId -o tsv
}

$servicePrincipalId = & $az ad sp list --filter "appId eq '$appId'" --query '[0].id' -o tsv
if ([string]::IsNullOrWhiteSpace($servicePrincipalId)) {
    $servicePrincipalId = & $az ad sp create --id $appId --query id -o tsv
}

$credentialName = "github-$($Repository.Replace('/', '-'))-$Environment"
$existingCredential = & $az ad app federated-credential list --id $appId --query "[?name=='$credentialName'] | length(@)" -o tsv
if ($existingCredential -eq '0') {
    $parameters = [ordered]@{
        name = $credentialName
        issuer = 'https://token.actions.githubusercontent.com'
        subject = "repo:${Repository}:environment:${Environment}"
        audiences = @('api://AzureADTokenExchange')
        description = "GitHub Actions deployment for ${Repository}"
    }
    $temporaryFile = [System.IO.Path]::GetTempFileName()
    try {
        $parameters | ConvertTo-Json | Set-Content -LiteralPath $temporaryFile -Encoding UTF8
        & $az ad app federated-credential create --id $appId --parameters "@$temporaryFile" --only-show-errors | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Could not create the GitHub federated credential.' }
    }
    finally { Remove-Item -LiteralPath $temporaryFile -Force -ErrorAction SilentlyContinue }
}

$vmId = & $az vm show --resource-group $ResourceGroup --name $VmName --query id -o tsv
# `az role assignment create/list --scope <resource-id>` fails with
# MissingSubscription on some Azure CLI builds (observed on 2.89.0). Check with
# `--all` (unaffected) and create through the ARM REST API instead. Retry the
# grant to absorb the freshly created service principal's directory replication.
$vmContributorRoleId = '9980e02c-c2be-4d73-94e8-173b1dc7cf3c' # Virtual Machine Contributor
$assignmentCount = & $az role assignment list --assignee $servicePrincipalId --all --query "[?scope=='$vmId' && roleDefinitionName=='Virtual Machine Contributor'] | length(@)" -o tsv
if ($assignmentCount -ne '1') {
    $assignmentName = [guid]::NewGuid().ToString()
    $roleBody = @{ properties = @{
        roleDefinitionId = "/subscriptions/$($account.subscriptionId)/providers/Microsoft.Authorization/roleDefinitions/$vmContributorRoleId"
        principalId      = $servicePrincipalId
        principalType    = 'ServicePrincipal'
    } } | ConvertTo-Json -Compress
    $roleUrl = "https://management.azure.com$vmId/providers/Microsoft.Authorization/roleAssignments/$($assignmentName)?api-version=2022-04-01"
    $granted = $false
    for ($attempt = 1; $attempt -le 5 -and -not $granted; $attempt++) {
        & $az rest --method put --url $roleUrl --headers 'Content-Type=application/json' --body $roleBody --only-show-errors | Out-Null
        if ($LASTEXITCODE -eq 0) { $granted = $true; break }
        Start-Sleep -Seconds (8 * $attempt)
    }
    if (-not $granted) { throw 'Could not grant the VM deployment role.' }
}

& $gh api --method PUT "repos/$Repository/environments/$Environment" | Out-Null
$secrets = [ordered]@{
    AZURE_CLIENT_ID = $appId
    AZURE_TENANT_ID = $account.tenantId
    AZURE_SUBSCRIPTION_ID = $account.subscriptionId
    AZURE_RESOURCE_GROUP = $ResourceGroup
    AZURE_VM_NAME = $VmName
}
foreach ($entry in $secrets.GetEnumerator()) {
    $entry.Value | & $gh secret set $entry.Key --repo $Repository --env $Environment
    if ($LASTEXITCODE -ne 0) { throw "Could not set GitHub environment secret $($entry.Key)." }
}

Write-Output "GitHub OIDC configured for $Repository environment $Environment."
Write-Output 'No Azure client secret was created or stored.'
