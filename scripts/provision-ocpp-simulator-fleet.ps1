$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$manifestPath = Join-Path $repoRoot 'infra\ocpp\simulator\stations.json'
$backendPath = Join-Path $repoRoot 'backend'
$php = 'C:\php\php.exe'

if (-not (Test-Path -LiteralPath $php)) {
    throw "PHP executable not found: $php"
}

$stations = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json

Push-Location $backendPath
try {
    foreach ($station in $stations) {
        & $php artisan ocpp:provision-station $station.identity
        if ($LASTEXITCODE -ne 0) {
            throw "Could not provision OCPP station $($station.identity)."
        }
    }
}
finally {
    Pop-Location
}

Write-Output "Provisioned $($stations.Count) OCPP simulator stations."
