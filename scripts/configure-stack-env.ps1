$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$backendEnv = Join-Path $repoRoot 'backend\.env'
$backendExample = Join-Path $repoRoot 'backend\.env.example'
$infraEnv = Join-Path $repoRoot 'infra\.env'

function Get-EnvValue([string]$Path, [string]$Key) {
    if (-not (Test-Path -LiteralPath $Path)) {
        return $null
    }

    $line = Get-Content -LiteralPath $Path |
        Where-Object { $_ -match "^$([regex]::Escape($Key))=" } |
        Select-Object -Last 1
    if ($null -eq $line) {
        return $null
    }

    $value = ($line -split '=', 2)[1].Trim().Trim('"')
    return $(if ([string]::IsNullOrWhiteSpace($value)) { $null } else { $value })
}

function Set-EnvValue([string]$Path, [string]$Key, [string]$Value) {
    $lines = [System.Collections.Generic.List[string]]::new()
    foreach ($line in [System.IO.File]::ReadAllLines($Path)) {
        if ($line -notmatch "^$([regex]::Escape($Key))=") {
            $lines.Add($line)
        }
    }
    $lines.Add("$Key=$Value")
    [System.IO.File]::WriteAllLines($Path, $lines)
}

function New-HexSecret {
    $bytes = New-Object byte[] 32
    $generator = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $generator.GetBytes($bytes)
    }
    finally {
        $generator.Dispose()
    }
    return -join ($bytes | ForEach-Object { $_.ToString('x2') })
}

if (-not (Test-Path -LiteralPath $backendEnv)) {
    Copy-Item -LiteralPath $backendExample -Destination $backendEnv
}

if ($null -eq (Get-EnvValue $backendEnv 'APP_KEY')) {
    $keyBytes = New-Object byte[] 32
    $generator = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $generator.GetBytes($keyBytes)
    }
    finally {
        $generator.Dispose()
    }
    Set-EnvValue $backendEnv 'APP_KEY' ('base64:' + [Convert]::ToBase64String($keyBytes))
}

$reverbAppId = Get-EnvValue $backendEnv 'REVERB_APP_ID'
if ($null -eq $reverbAppId) { $reverbAppId = 'local' }

$reverbAppKey = Get-EnvValue $backendEnv 'REVERB_APP_KEY'
if ($null -eq $reverbAppKey -or $reverbAppKey -eq 'local-key') {
    $reverbAppKey = (New-HexSecret).Substring(0, 20)
    Set-EnvValue $backendEnv 'REVERB_APP_KEY' $reverbAppKey
}

$reverbAppSecret = Get-EnvValue $backendEnv 'REVERB_APP_SECRET'
if ($null -eq $reverbAppSecret -or $reverbAppSecret -eq 'local-secret') {
    $reverbAppSecret = New-HexSecret
    Set-EnvValue $backendEnv 'REVERB_APP_SECRET' $reverbAppSecret
}

$paymentWebhookSecret = Get-EnvValue $backendEnv 'PAYMENT_SIMULATOR_WEBHOOK_SECRET'
if ($null -eq $paymentWebhookSecret -or $paymentWebhookSecret.Length -lt 32) {
    Set-EnvValue $backendEnv 'PAYMENT_SIMULATOR_WEBHOOK_SECRET' (New-HexSecret)
}

& (Join-Path $PSScriptRoot 'configure-infra-env.ps1')
& (Join-Path $PSScriptRoot 'configure-ocpp-env.ps1')
& node (Join-Path $repoRoot 'infra\payment-simulator\generate-mappings.mjs')

Set-EnvValue $infraEnv 'REVERB_APP_ID' $reverbAppId
Set-EnvValue $infraEnv 'REVERB_APP_KEY' $reverbAppKey
Set-EnvValue $infraEnv 'REVERB_APP_SECRET' $reverbAppSecret
Set-EnvValue $infraEnv 'OCPP_SIMULATOR_CONTROL_TOKEN' (Get-EnvValue $backendEnv 'OCPP_SIMULATOR_CONTROL_TOKEN')

Write-Output 'The complete local stack is configured. Existing secrets were retained.'
