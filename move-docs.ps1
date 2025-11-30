# Move all documentation to docs folder

Write-Host "Moving documentation files to docs folder..." -ForegroundColor Green

# Create docs folder if it doesn't exist
if (-not (Test-Path "docs")) {
    New-Item -ItemType Directory -Path "docs" | Out-Null
}

# Move all markdown files to docs
Get-ChildItem -Path "." -Filter "*.md" -File | ForEach-Object {
    if ($_.Name -ne "README.md") {
        Write-Host "Moving $($_.Name)..." -ForegroundColor Yellow
        Move-Item -Path $_.FullName -Destination "docs\" -Force
    }
}

Write-Host "Documentation files moved successfully!" -ForegroundColor Green
