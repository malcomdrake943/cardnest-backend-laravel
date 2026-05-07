param (
    [Parameter(Mandatory=$true)]
    [string]$Service,

    [Parameter(ValueFromRemainingArguments=$true)]
    [string[]]$ArtisanArgs
)

$PHP_PATH = "C:\Users\imran\.config\herd\bin\php82\php.exe"
$SERVICES = @("auth-services", "card-scans-service", "subscriptions-service")

if ($Service -eq "all") {
    foreach ($s in $SERVICES) {
        Write-Host "`n>>> Running artisan in $s..." -ForegroundColor Cyan
        if (Test-Path "$s\artisan") {
            Set-Location $s
            & $PHP_PATH artisan $ArtisanArgs
            Set-Location ..
        } else {
            Write-Warning "Artisan not found in $s"
        }
    }
} else {
    if ($SERVICES -contains $Service) {
        Write-Host "`n>>> Running artisan in $Service..." -ForegroundColor Cyan
        if (Test-Path "$Service\artisan") {
            Set-Location $Service
            & $PHP_PATH artisan $ArtisanArgs
            Set-Location ..
        } else {
            Write-Error "Artisan not found in $Service"
        }
    } else {
        Write-Error "Service '$Service' not found. Available services: $($SERVICES -join ', ')"
    }
}
