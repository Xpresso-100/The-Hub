# Builds deploy/upload/hub/ from the repo. Run from the repo root: powershell -File deploy\build-bundle.ps1
# The bundle is the exact folder uploaded to xneelo public_html/hub/. No secrets, no tests, no .env.
# To rebuild, move or delete deploy\upload\hub first; the script will not overwrite an existing bundle.
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$out = Join-Path $PSScriptRoot 'upload\hub'
if (Test-Path $out) { throw "$out already exists - remove it first" }
New-Item -ItemType Directory $out | Out-Null
foreach ($d in 'public', 'src', 'db', 'bin') {
    Copy-Item (Join-Path $root $d) (Join-Path $out $d) -Recurse
}
New-Item -ItemType Directory (Join-Path $out 'var') | Out-Null
Copy-Item (Join-Path $root 'var\.htaccess') (Join-Path $out 'var\.htaccess')
Copy-Item (Join-Path $PSScriptRoot 'htaccess-root.txt') (Join-Path $out '.htaccess')
Copy-Item (Join-Path $PSScriptRoot 'config.local.php.TEMPLATE') (Join-Path $out 'config.local.php.TEMPLATE')
# File list with byte sizes, so the uploader can check what landed on the server.
$files = Get-ChildItem $out -Recurse -File -Force
"{0} files, {1} bytes" -f $files.Count, ($files | Measure-Object Length -Sum).Sum
$files | ForEach-Object { '{0}  {1}' -f $_.Length, $_.FullName.Substring($out.Length + 1).Replace('\', '/') } |
    Set-Content -Encoding ascii (Join-Path $PSScriptRoot 'FILE-LIST.txt')
