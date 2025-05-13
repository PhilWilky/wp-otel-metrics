# Test Batch Export Functionality
# This tests if the Pro batch processing is actually exporting

Write-Host "Testing Batch Export..." -ForegroundColor Green

# Get current log size
$logFile = "C:\Users\Phil\Local Sites\webbyte\logs\php\error.log"
$startPos = (Get-Item $logFile).Length

# Generate exactly 50 requests to trigger batch export
Write-Host "Generating 50 requests (batch size)..." -ForegroundColor Yellow
for ($i = 1; $i -le 50; $i++) {
    Invoke-WebRequest -Uri "http://webbyte.local/?batch=$i" -Method GET | Out-Null
    if ($i % 10 -eq 0) {
        Write-Host "  $i requests sent..." -ForegroundColor Gray
    }
}

Write-Host "`nWaiting 2 seconds for batch to process..." -ForegroundColor Yellow
Start-Sleep -Seconds 2

# Check logs for batch export
Write-Host "`nChecking for batch export in logs..." -ForegroundColor Cyan
$newLogs = Get-Content $logFile | Select-Object -Skip ([Math]::Max(0, $startPos - 1000))
$batchExports = $newLogs | Select-String "Exporting batch"
$tracesAdded = $newLogs | Select-String "Trace added to batch"

Write-Host "`nResults:" -ForegroundColor Green
Write-Host "Traces added to batch: $($tracesAdded.Count)" -ForegroundColor Cyan
Write-Host "Batch exports found: $($batchExports.Count)" -ForegroundColor Cyan

if ($batchExports.Count -gt 0) {
    Write-Host "`nBatch export messages:" -ForegroundColor Yellow
    $batchExports | ForEach-Object { Write-Host $_.Line -ForegroundColor Gray }
}

# Test time-based export
Write-Host "`n`nTesting time-based export (5 second interval)..." -ForegroundColor Green
$timeStartPos = (Get-Item $logFile).Length

# Generate 10 requests
Write-Host "Generating 10 requests..." -ForegroundColor Yellow
for ($i = 1; $i -le 10; $i++) {
    Invoke-WebRequest -Uri "http://webbyte.local/?timetest=$i" -Method GET | Out-Null
}

Write-Host "Waiting 6 seconds for interval export..." -ForegroundColor Yellow
Start-Sleep -Seconds 6

# Check for interval-based export
$intervalLogs = Get-Content $logFile | Select-Object -Skip ([Math]::Max(0, $timeStartPos - 1000))
$intervalExports = $intervalLogs | Select-String "Exporting batch"

Write-Host "`nTime-based export results:" -ForegroundColor Green
Write-Host "Exports after interval: $($intervalExports.Count)" -ForegroundColor Cyan

if ($intervalExports.Count -gt 0) {
    Write-Host "`nInterval export messages:" -ForegroundColor Yellow
    $intervalExports | ForEach-Object { Write-Host $_.Line -ForegroundColor Gray }
}

Write-Host "`nBatch export test complete!" -ForegroundColor Green