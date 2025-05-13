# WP OpenTelemetry Test Script for Windows
# Run this in PowerShell

# Set your site URL
$siteUrl = "http://webbyte.local"

Write-Host "Starting WP OpenTelemetry Tests..." -ForegroundColor Green

# Function to make requests
function Test-Endpoint {
    param(
        [string]$url,
        [string]$method = "GET",
        [hashtable]$body = @{}
    )
    
    try {
        if ($method -eq "GET") {
            $response = Invoke-WebRequest -Uri $url -Method GET
        } else {
            $response = Invoke-WebRequest -Uri $url -Method POST -Body $body
        }
        Write-Host "OK - $method $url - Status: $($response.StatusCode)" -ForegroundColor Green
    } catch {
        Write-Host "ERROR - $method $url - Error: $_" -ForegroundColor Red
    }
}

# Test 1: Basic Page Loads
Write-Host "`nTest 1: Basic Page Loads" -ForegroundColor Yellow
Test-Endpoint "$siteUrl/"
Test-Endpoint "$siteUrl/wp-admin/"
Test-Endpoint "$siteUrl/sample-page/"

# Test 2: AJAX Requests
Write-Host "`nTest 2: AJAX Requests" -ForegroundColor Yellow
Test-Endpoint "$siteUrl/wp-admin/admin-ajax.php" -method POST -body @{action="heartbeat"}

# Test 3: REST API
Write-Host "`nTest 3: REST API Endpoints" -ForegroundColor Yellow
Test-Endpoint "$siteUrl/wp-json/wp/v2/posts"
Test-Endpoint "$siteUrl/wp-json/wp/v2/users"

# Test 4: Batch Testing - Generate multiple requests quickly
Write-Host "`nTest 4: Batch Processing - 50 requests" -ForegroundColor Yellow
for ($i = 1; $i -le 50; $i++) {
    Test-Endpoint "$siteUrl/?test=$i"
    if ($i % 10 -eq 0) {
        Write-Host "  Completed $i requests..." -ForegroundColor Cyan
    }
}

Write-Host "`nTest 5: Different Page Types" -ForegroundColor Yellow
Test-Endpoint "$siteUrl/category/uncategorized/"
Test-Endpoint "$siteUrl/author/admin/"
Test-Endpoint "$siteUrl/2024/01/"

Write-Host "`nAll tests completed!" -ForegroundColor Green
Write-Host "Check your error log for batch processing behavior" -ForegroundColor Yellow