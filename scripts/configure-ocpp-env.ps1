param(
    [switch]$Rotate
)

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$backendEnv = Join-Path $repoRoot 'backend\.env'
$composeEnv = Join-Path $repoRoot 'infra\ocpp\.env'

if (-not (Test-Path -LiteralPath $backendEnv)) {
    throw "Backend environment file not found: $backendEnv"
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

function Get-EnvValue([string]$Path, [string]$Key) {
    if (-not (Test-Path -LiteralPath $Path)) {
        return $null
    }

    $line = Get-Content -LiteralPath $Path | Where-Object { $_ -match "^$([regex]::Escape($Key))=" } | Select-Object -Last 1
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

function Remove-EnvValue([string]$Path, [string]$Key) {
    $lines = [System.Collections.Generic.List[string]]::new()
    foreach ($line in [System.IO.File]::ReadAllLines($Path)) {
        if ($line -notmatch "^$([regex]::Escape($Key))=") {
            $lines.Add($line)
        }
    }
    [System.IO.File]::WriteAllLines($Path, $lines)
}

$gatewaySecret = if ($Rotate) { $null } else { Get-EnvValue $backendEnv 'OCPP_GATEWAY_SHARED_SECRET' }
if ($null -eq $gatewaySecret) { $gatewaySecret = Get-EnvValue $composeEnv 'OCPP_GATEWAY_SHARED_SECRET' }
if ($null -eq $gatewaySecret -or $Rotate) { $gatewaySecret = New-HexSecret }

$stationSecret = if ($Rotate) { $null } else { Get-EnvValue $backendEnv 'OCPP_SIMULATOR_STATION_SECRET' }
if ($null -eq $stationSecret) { $stationSecret = Get-EnvValue $composeEnv 'OCPP_SIMULATOR_STATION_SECRET' }
if ($null -eq $stationSecret -or $Rotate) { $stationSecret = New-HexSecret }

$uiPassword = if ($Rotate) { $null } else { Get-EnvValue $composeEnv 'OCPP_SIMULATOR_UI_PASSWORD' }
if ($null -eq $uiPassword -or $Rotate) { $uiPassword = New-HexSecret }

Set-EnvValue $backendEnv 'OCPP_GATEWAY_SHARED_SECRET' $gatewaySecret
Set-EnvValue $backendEnv 'OCPP_GATEWAY_SIGNATURE_TOLERANCE_SECONDS' '300'
Set-EnvValue $backendEnv 'OCPP_SIMULATOR_STATION_SECRET' $stationSecret
Remove-EnvValue $backendEnv 'OCPP_SIMULATOR_STATION_IDENTITY'

$composeLines = @(
    'OCPP_ENVIRONMENT=local',
    'OCPP_GATEWAY_TLS_MODE=disabled',
    'OCPP_GATEWAY_PUBLIC_URL=ws://localhost:9000/ocpp',
    'OCPP_LARAVEL_BASE_URL=http://host.docker.internal:8000/api/internal/ocpp',
    "OCPP_GATEWAY_SHARED_SECRET=$gatewaySecret",
    "OCPP_SIMULATOR_STATION_SECRET=$stationSecret",
    "OCPP_SIMULATOR_UI_PASSWORD=$uiPassword"
)
[System.IO.Directory]::CreateDirectory((Split-Path -Parent $composeEnv)) | Out-Null
[System.IO.File]::WriteAllLines($composeEnv, $composeLines)

Write-Output 'OCPP environment configured. Secrets were written only to ignored .env files.'
