param(
    [switch]$Rotate,
    [switch]$SyncBackend
)

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$infraEnv = Join-Path $repoRoot 'infra\.env'
$backendEnv = Join-Path $repoRoot 'backend\.env'

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
    if (Test-Path -LiteralPath $Path) {
        foreach ($line in [System.IO.File]::ReadAllLines($Path)) {
            if ($line -notmatch "^$([regex]::Escape($Key))=") {
                $lines.Add($line)
            }
        }
    }

    $lines.Add("$Key=$Value")
    [System.IO.Directory]::CreateDirectory((Split-Path -Parent $Path)) | Out-Null
    [System.IO.File]::WriteAllLines($Path, $lines)
}

$postgresPassword = if ($Rotate) { $null } else { Get-EnvValue $infraEnv 'POSTGRES_PASSWORD' }
if ($null -eq $postgresPassword) { $postgresPassword = New-HexSecret }

$redisPassword = if ($Rotate) { $null } else { Get-EnvValue $infraEnv 'REDIS_PASSWORD' }
if ($null -eq $redisPassword) { $redisPassword = New-HexSecret }

$postgresDatabase = Get-EnvValue $infraEnv 'POSTGRES_DB'
if ($null -eq $postgresDatabase) { $postgresDatabase = 'changetrackr' }

$postgresUser = Get-EnvValue $infraEnv 'POSTGRES_USER'
if ($null -eq $postgresUser) { $postgresUser = 'changetrackr' }

$infraValues = [ordered]@{
    POSTGRES_DB = $postgresDatabase
    POSTGRES_USER = $postgresUser
    POSTGRES_PASSWORD = $postgresPassword
    POSTGRES_FORWARD_PORT = '5432'
    REDIS_PASSWORD = $redisPassword
    REDIS_FORWARD_PORT = '6379'
    MAILPIT_SMTP_FORWARD_PORT = '1025'
    MAILPIT_UI_FORWARD_PORT = '8025'
}

foreach ($entry in $infraValues.GetEnumerator()) {
    Set-EnvValue $infraEnv $entry.Key $entry.Value
}

if ($SyncBackend) {
    if (-not (Test-Path -LiteralPath $backendEnv)) {
        throw "Backend environment file not found: $backendEnv"
    }

    Set-EnvValue $backendEnv 'DB_HOST' '127.0.0.1'
    Set-EnvValue $backendEnv 'DB_PORT' '5432'
    Set-EnvValue $backendEnv 'DB_DATABASE' $postgresDatabase
    Set-EnvValue $backendEnv 'DB_USERNAME' $postgresUser
    Set-EnvValue $backendEnv 'DB_PASSWORD' $postgresPassword
    Set-EnvValue $backendEnv 'REDIS_HOST' '127.0.0.1'
    Set-EnvValue $backendEnv 'REDIS_PORT' '6379'
    Set-EnvValue $backendEnv 'REDIS_PASSWORD' $redisPassword
}

$syncMessage = if ($SyncBackend) {
    ' The ignored backend/.env file was synchronized.'
}
else {
    ' Existing backend database settings were not changed.'
}

Write-Output "Local infrastructure configured in the ignored infra/.env file.$syncMessage"
