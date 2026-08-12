param(
    [string]$Path = (Join-Path $PSScriptRoot '.env')
)

$ErrorActionPreference = 'Stop'
$example = Join-Path $PSScriptRoot '.env.example'

function New-HexSecret([int]$Bytes = 32) {
    $buffer = New-Object byte[] $Bytes
    $generator = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $generator.GetBytes($buffer) }
    finally { $generator.Dispose() }
    return -join ($buffer | ForEach-Object { $_.ToString('x2') })
}

function New-LaravelKey {
    $buffer = New-Object byte[] 32
    $generator = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $generator.GetBytes($buffer) }
    finally { $generator.Dispose() }
    return 'base64:' + [Convert]::ToBase64String($buffer)
}

function Get-EnvValue([string]$File, [string]$Key) {
    $line = Get-Content -LiteralPath $File |
        Where-Object { $_ -match "^$([regex]::Escape($Key))=" } |
        Select-Object -Last 1
    if ($null -eq $line) { return $null }
    $value = ($line -split '=', 2)[1].Trim().Trim('"')
    if ([string]::IsNullOrWhiteSpace($value)) { return $null }
    return $value
}

function Set-EnvValue([string]$File, [string]$Key, [string]$Value) {
    $lines = [System.Collections.Generic.List[string]]::new()
    foreach ($line in [System.IO.File]::ReadAllLines($File)) {
        if ($line -notmatch "^$([regex]::Escape($Key))=") { $lines.Add($line) }
    }
    $lines.Add("$Key=$Value")
    [System.IO.File]::WriteAllLines($File, $lines)
}

if (-not (Test-Path -LiteralPath $Path)) {
    Copy-Item -LiteralPath $example -Destination $Path
}

$generated = [ordered]@{
    APP_KEY = New-LaravelKey
    DB_PASSWORD = New-HexSecret
    POSTGRES_PASSWORD = New-HexSecret
    REDIS_PASSWORD = New-HexSecret
    REVERB_APP_SECRET = New-HexSecret
    PAYMENT_SIMULATOR_API_KEY = New-HexSecret
    PAYMENT_SIMULATOR_WEBHOOK_SECRET = New-HexSecret
    OCPP_GATEWAY_SHARED_SECRET = New-HexSecret
    OCPP_SIMULATOR_STATION_SECRET = New-HexSecret
    OCPP_SIMULATOR_UI_PASSWORD = New-HexSecret 16
    OCPP_SIMULATOR_CONTROL_TOKEN = New-HexSecret
}

foreach ($entry in $generated.GetEnumerator()) {
    if ($null -eq (Get-EnvValue $Path $entry.Key)) {
        Set-EnvValue $Path $entry.Key $entry.Value
    }
}

# PostgreSQL must receive exactly the password used by Laravel.
Set-EnvValue $Path 'POSTGRES_PASSWORD' (Get-EnvValue $Path 'DB_PASSWORD')

Write-Output "Production environment prepared at $Path."
Write-Output 'Fill ACME_EMAIL, RESEND_API_KEY, GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET before deployment.'
