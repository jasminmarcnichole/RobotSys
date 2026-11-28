param(
    [string]$CaptureUrl = "http://192.168.4.2/capture",
    [string]$OutputRoot = (Join-Path (Split-Path -Parent $PSScriptRoot) "storage\app\private\waste-training\esp32-captures")
)

$categories = @{
    ""  = "trash"
    "b" = "background"
    "1" = "plastic_bottle"
    "2" = "other_plastic"
    "3" = "plastic_wrapper"
    "4" = "paper"
    "5" = "metal_can"
    "6" = "aluminum_can"
}

foreach ($folder in $categories.Values) {
    New-Item -ItemType Directory -Force -Path (Join-Path $OutputRoot $folder) | Out-Null
}

Write-Host "=============================================" -ForegroundColor Cyan
Write-Host "  ESP32-S3-CAM Dataset Capture Tool" -ForegroundColor Cyan
Write-Host "=============================================" -ForegroundColor Cyan
Write-Host "Source : $CaptureUrl"
Write-Host "Output : $OutputRoot"
Write-Host ""
Write-Host "Keys (press Enter after typing):"
Write-Host "  [Enter] = trash (general / multi-object)"
Write-Host "  b       = background (empty floor/room, auto-creates empty .txt label)"
Write-Host "  1       = plastic_bottle"
Write-Host "  2       = other_plastic"
Write-Host "  3       = plastic_wrapper"
Write-Host "  4       = paper"
Write-Host "  5       = metal_can"
Write-Host "  6       = aluminum_can"
Write-Host "  q       = quit"
Write-Host ""

$count = 0

while ($true) {
    $choice = (Read-Host "Capture [Enter/b/1-6/q]").Trim().ToLower()
    if ($choice -eq "q") {
        break
    }
    if (-not $categories.ContainsKey($choice)) {
        Write-Host "Unknown option '$choice'. Use Enter, b, 1-6, or q." -ForegroundColor Yellow
        continue
    }

    $folder = $categories[$choice]
    $timestamp = Get-Date -Format "yyyyMMdd_HHmmss_fff"
    $baseName = "${folder}_${timestamp}"
    $jpgPath = Join-Path (Join-Path $OutputRoot $folder) "$baseName.jpg"

    try {
        Invoke-WebRequest -Uri $CaptureUrl -OutFile $jpgPath -TimeoutSec 5 -UseBasicParsing
        $sizeKb = [Math]::Round((Get-Item $jpgPath).Length / 1KB, 1)
        if ($folder -eq "background") {
            $txtPath = Join-Path (Join-Path $OutputRoot $folder) "$baseName.txt"
            New-Item -ItemType File -Force -Path $txtPath | Out-Null
        }
        $count++
        Write-Host "[$count] Saved $folder\$baseName.jpg ($sizeKb KB)" -ForegroundColor Green
    } catch {
        Write-Host "Failed to capture from $CaptureUrl : $($_.Exception.Message)" -ForegroundColor Red
    }
}

Write-Host ""
Write-Host "Done! Captured $count photo(s) into: $OutputRoot" -ForegroundColor Cyan
