# Pro Features Test Script

# Test 1: Verify Batch Manager is Active
Write-Host "Testing Pro Features..." -ForegroundColor Green

# Check if license is active
$adminUrl = "http://webbyte.local/wp-admin/admin.php?page=wp-otel-metrics#license"
Write-Host "1. Check license status at: $adminUrl" -ForegroundColor Yellow

# Generate traces to test batching
Write-Host "`n2. Testing Batch Processing..." -ForegroundColor Yellow
Write-Host "   - Batch size: 50 traces"
Write-Host "   - Export interval: 5 seconds"

# Generate exactly 50 requests to trigger a batch
1..50 | ForEach-Object {
    Invoke-WebRequest -Uri "http://webbyte.local/?batch_test=$_" -Method GET | Out-Null
    if ($_ % 10 -eq 0) {
        Write-Host "   Generated $_ traces..." -ForegroundColor Cyan
    }
}

Write-Host "`n3. Waiting 5 seconds for batch export..." -ForegroundColor Yellow
Start-Sleep -Seconds 5

# Test different exporters (if configured)
Write-Host "`n4. Test Exporter Configuration" -ForegroundColor Yellow
$exportersUrl = "http://webbyte.local/wp-admin/admin.php?page=wp-otel-metrics#exporters"
Write-Host "   Check exporters at: $exportersUrl" -ForegroundColor Cyan

# Test Performance Dashboard
Write-Host "`n5. Test Performance Dashboard" -ForegroundColor Yellow
$perfUrl = "http://webbyte.local/wp-admin/admin.php?page=wp-otel-metrics#performance"
Write-Host "   View metrics at: $perfUrl" -ForegroundColor Cyan

# Test Adaptive Sampling
Write-Host "`n6. Testing Adaptive Sampling..." -ForegroundColor Yellow
1..100 | ForEach-Object {
    Invoke-WebRequest -Uri "http://webbyte.local/?sampling_test=$_" -Method GET | Out-Null
}
Write-Host "   Generated 100 requests - check how many were sampled" -ForegroundColor Cyan