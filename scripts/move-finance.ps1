$ErrorActionPreference = 'Stop'
$path = 'd:\traidnet\wifi-hotspot\frontend\src\modules\common\components\layout\AppSidebar.vue'
Copy-Item -LiteralPath $path -Destination ($path + '.bak') -Force
$content = Get-Content -LiteralPath $path -Raw

# 1) Locate Products & Services and Billing anchors to scope the section
$psStart = $content.IndexOf('<!-- Section: Products & Services -->')
$billingStart = $content.IndexOf('<!-- Section: Billing & Payments -->', $psStart)
if ($psStart -lt 0 -or $billingStart -lt 0) { throw 'Could not find Products & Services or Billing anchors' }
$psSection = $content.Substring($psStart, $billingStart - $psStart)

# 2) Capture and remove the Finance block under Products & Services (10-space indent)
$financeRegex = [regex]'(?s)\r?\n\s{10}<!-- Finance -->\r?\n\s{10}<div>.*?\r?\n\s{10}</div>\r?\n'
$match = $financeRegex.Match($psSection)
if (-not $match.Success) { throw 'Finance block not found under Products & Services' }
$financeBlock = $match.Value.TrimEnd()
$psSectionUpdated = $financeRegex.Replace($psSection, '', 1)

# Rebuild content with Finance removed from P&S
$content = $content.Substring(0, $psStart) + $psSectionUpdated + $content.Substring($billingStart)

# 3) Insert Finance under Organization just before its wrapper closing </div> that precedes Branding
$orgStart = $content.IndexOf('<!-- Section: Organization -->')
$brandingStart = $content.IndexOf('<!-- Section: Branding & Customization -->', $orgStart)
if ($orgStart -lt 0 -or $brandingStart -lt 0) { throw 'Organization or Branding anchors not found' }
$orgToBrand = $content.Substring($orgStart, $brandingStart - $orgStart)
$lastCloseIdxRel = $orgToBrand.LastIndexOf('        </div>')
if ($lastCloseIdxRel -lt 0) { throw 'Organization closing </div> not found' }
$insertAt = $orgStart + $lastCloseIdxRel

# Insert with proper CRLFs
$content = $content.Insert($insertAt, "`r`n" + $financeBlock + "`r`n")

[System.IO.File]::WriteAllText($path, $content, [System.Text.Encoding]::UTF8)
Write-Host 'Finance moved under Organization. Backup at ' ($path + '.bak')
