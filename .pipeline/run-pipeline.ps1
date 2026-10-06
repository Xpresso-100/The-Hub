<#
 Full-automation controller. The GRAPH lives here (deterministic code), each node is a fresh
 headless Claude Code session (-p) with its own context, the LOOP evidence is the test command
 run by this script (never the agent), the HARNESS is permissions + budgets + guards.
 Run from anywhere:  powershell -ExecutionPolicy Bypass -File .pipeline/run-pipeline.ps1 -Ref "<ordering system folder>"
#>
param(
  [string]$Ref = '',
  [int]$MaxRounds = 3,
  [double]$MaxBudgetUsd = 0,
  [ValidateSet('G0','G1','G2','G3','G4','G5')][string]$From = 'G0',
  [switch]$Push
)
$ErrorActionPreference = 'Continue'
$Pipe = $PSScriptRoot
$Root = Split-Path $Pipe -Parent
Set-Location $Root
$Role = (Get-Content -Raw "$Pipe/role.txt").Trim()
$RunDir = "$Root/.run"
New-Item -ItemType Directory -Force $RunDir | Out-Null
$Opus = 'claude-opus-5-5'
$Sonnet = 'claude-sonnet-5-5'
$order = @('G0','G1','G2','G3','G4','G5')
$Status = New-Object System.Collections.ArrayList
$script:Base = ''
if (Test-Path "$RunDir/base.txt") { $script:Base = (Get-Content -Raw "$RunDir/base.txt").Trim() }

function Want($g) { return ($order.IndexOf($g) -ge $order.IndexOf($From)) }
function Log($m) { $l = "[{0}] {1}" -f (Get-Date -Format 'HH:mm:ss'), $m; Write-Host $l; Add-Content -Path "$RunDir/pipeline.log" -Value $l }

function Write-Status {
  $sb = New-Object System.Text.StringBuilder
  [void]$sb.AppendLine("# STATUS - $Role pipeline")
  [void]$sb.AppendLine("Updated: $(Get-Date -Format 'yyyy-MM-dd HH:mm')")
  [void]$sb.AppendLine("")
  [void]$sb.AppendLine("| Gate | Result | Evidence |")
  [void]$sb.AppendLine("|---|---|---|")
  foreach ($s in $Status) { [void]$sb.AppendLine("| $($s.Gate) | $($s.Result) | $($s.Note) |") }
  [void]$sb.AppendLine("")
  [void]$sb.AppendLine("Review DECISIONS-LOG.md (assumptions made). Upload steps: deploy/DEPLOY.md.")
  Set-Content -Path "$Root/STATUS.md" -Value $sb.ToString() -Encoding Ascii
}
function Add-Status($gate, $result, $note) {
  [void]$Status.Add([pscustomobject]@{ Gate = $gate; Result = $result; Note = $note })
  Write-Status
  Log "$gate $result - $note"
}
function Cleanup-Worktrees {
  $list = git worktree list --porcelain | Where-Object { $_ -like 'worktree *' } | ForEach-Object { $_.Substring(9) }
  foreach ($w in $list) {
    if ((Resolve-Path $w).Path -ne (Resolve-Path $Root).Path) { git worktree remove --force $w | Out-Null }
  }
}
function Fail-Gate($gate, $note) {
  Add-Status $gate 'FAIL' $note
  Cleanup-Worktrees
  Log "PIPELINE STOPPED at $gate. Read STATUS.md and .run/*.log. Resume with: -From $($gate.Split(' ')[0])"
  exit 1
}
function Commit-All($msg) {
  git add -A | Out-Null
  git diff --cached --quiet
  if ($LASTEXITCODE -ne 0) { git commit -m $msg | Out-Null }
}

function Build-Prompt($node, $vars) {
  $t = "NODE: $node`n" + (Get-Content -Raw "$Pipe/prompts/common.md") + "`n" + (Get-Content -Raw "$Pipe/prompts/$node.md")
  $t = $t.Replace('{{ROLE}}', (Get-Content -Raw "$Pipe/roles/$Role.md"))
  foreach ($k in $vars.Keys) { $t = $t.Replace("{{$k}}", [string]$vars[$k]) }
  return $t
}
function Get-CliArgs($model, $effort, $turns) {
  $a = @('-p', 'Follow the instructions given on stdin.', '--model', $model, '--effort', $effort,
         '--permission-mode', 'acceptEdits', '--allowedTools', 'Read,Edit,Write,Bash,Glob,Grep',
         '--max-turns', [string]$turns, '--output-format', 'json')
  if ($MaxBudgetUsd -gt 0) { $a += @('--max-budget-usd', [string]$MaxBudgetUsd) }
  if ($Ref) { $a += @('--add-dir', $Ref) }
  return ,$a
}
function Run-Node($name, $model, $effort, $turns, $prompt, $cwd) {
  Log "NODE $name start ($model / $effort / max $turns turns)"
  Set-Content -Path "$RunDir/$name.prompt.md" -Value $prompt -Encoding Ascii
  $cliArgs = Get-CliArgs $model $effort $turns
  Push-Location $cwd
  try { $prompt | & claude @cliArgs *> "$RunDir/$name.log"; $code = $LASTEXITCODE } finally { Pop-Location }
  Log "NODE $name exit=$code"
  return $code
}
function Run-Tests($tag) {
  $cmdFile = "$Pipe/test-cmd.txt"
  if (-not (Test-Path $cmdFile)) { return @{ Code = 2; Tail = 'ERROR: .pipeline/test-cmd.txt is missing'; Last = 'no test command' } }
  $cmd = (Get-Content -Raw $cmdFile).Trim()
  $lf = "$RunDir/tests-$tag.log"
  Set-Location $Root
  $global:LASTEXITCODE = 0
  try { Invoke-Expression $cmd *> $lf } catch { Add-Content -Path $lf -Value $_.Exception.Message; $global:LASTEXITCODE = 1 }
  $code = $LASTEXITCODE
  $lines = @(Get-Content $lf -ErrorAction SilentlyContinue)
  $tail = ($lines | Select-Object -Last 60) -join "`n"
  $last = ''
  if ($lines.Count -gt 0) { $last = $lines[$lines.Count - 1] }
  Log "TESTS $tag exit=$code"
  return @{ Code = $code; Tail = $tail; Last = $last }
}
function Guard-Tests {
  if (-not $script:Base) { return }
  $changed = @(git diff --name-only $script:Base -- tests/acceptance)
  if ($changed.Count -eq 0) { return }
  foreach ($f in $changed) {
    git cat-file -e "$($script:Base):$f" 2>$null
    if ($LASTEXITCODE -eq 0) { git checkout $script:Base -- $f } else { git rm -f -q $f }
  }
  Commit-All 'pipeline: revert edits to protected acceptance tests'
  Log "PROTECTED TEST EDITS REVERTED: $($changed -join ', ')"
}
function Fix-Loop($gate) {
  for ($r = 1; $r -le $MaxRounds; $r++) {
    $t = Run-Tests "$gate-r$r"
    if ($t.Code -eq 0) { return $t }
    if ($r -eq $MaxRounds) { return $t }
    Run-Node "$gate-fix$r" $Opus 'high' 60 (Build-Prompt 'fix' @{ FAIL = $t.Tail }) $Root | Out-Null
    Commit-All "pipeline: $gate fix round $r"
    Guard-Tests
  }
}
function Scan-Secrets {
  $hits = @(git grep -nIE "(AKIA[0-9A-Z]{16}|sk-[A-Za-z0-9]{20,}|-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----|xox[abp]-[0-9A-Za-z-]{10,})" -- . ':!.pipeline')
  $envTracked = @(git ls-files | Where-Object { $_ -match '(^|/)\.env$' })
  $all = @($hits) + @($envTracked | ForEach-Object { "tracked .env file: $_" })
  return @($all | Where-Object { $_ })
}

# ---------- preflight ----------
if (-not (Get-Command claude -ErrorAction SilentlyContinue)) { throw 'claude CLI not found on PATH' }
git rev-parse --show-toplevel *> $null
if ($LASTEXITCODE -ne 0) { throw 'Not inside a git repo' }
if ($Ref -and -not (Test-Path $Ref)) { throw "Reference folder not found: $Ref" }
Log "PIPELINE start role=$Role from=$From ref=$Ref"
$modLine = (Get-Content "$Pipe/roles/$Role.md" | Where-Object { $_ -like 'MODULES:*' } | Select-Object -First 1)
$modules = @($modLine.Substring(8).Trim().Split(',') | ForEach-Object { $_.Trim() } | Where-Object { $_ })

# ---------- G0 DISCOVER ----------
if (Want 'G0') {
  Run-Node 'g0' $Opus 'xhigh' 80 (Build-Prompt 'g0' @{}) $Root | Out-Null
  $missing = @('SPEC.md','CODEBASE_MAP.md','feature_list.json') | Where-Object { -not (Test-Path "$Root/$_") }
  if ($missing) { Fail-Gate 'G0 discover' "missing: $($missing -join ', ')" }
  Commit-All 'pipeline: G0 discovery and spec'
  Add-Status 'G0 discover' 'PASS' 'SPEC.md, CODEBASE_MAP.md, feature_list.json written'
}

# ---------- G1 SCAFFOLD + ACCEPTANCE TESTS ----------
if (Want 'G1') {
  Run-Node 'g1' $Opus 'xhigh' 100 (Build-Prompt 'g1' @{}) $Root | Out-Null
  $accept = @(Get-ChildItem "$Root/tests/acceptance" -Recurse -File -ErrorAction SilentlyContinue)
  if (-not (Test-Path "$Pipe/test-cmd.txt")) { Fail-Gate 'G1 scaffold' '.pipeline/test-cmd.txt missing' }
  if ($accept.Count -eq 0) { Fail-Gate 'G1 scaffold' 'no acceptance tests written' }
  Commit-All 'pipeline: G1 scaffold and acceptance tests'
  $script:Base = (git rev-parse HEAD).Trim()
  Set-Content -Path "$RunDir/base.txt" -Value $script:Base -Encoding Ascii
  Add-Status 'G1 scaffold' 'PASS' "$($accept.Count) acceptance test file(s) locked at $($script:Base.Substring(0,7))"
}

# ---------- G2 BUILD (parallel worktrees if modules, else serial) ----------
if (Want 'G2') {
  if ($modules.Count -gt 0 -and $modules[0] -ne 'serial') {
    $repoName = Split-Path $Root -Leaf
    $parent = Split-Path $Root -Parent
    $jobs = @()
    foreach ($m in $modules) {
      $wt = Join-Path $parent "$repoName-wt-$m"
      git worktree add $wt -b "mod-$m" | Out-Null
      $p = Build-Prompt 'build' @{ MODULE = $m }
      Set-Content -Path "$RunDir/build-$m.prompt.md" -Value $p -Encoding Ascii
      $cliArgs = Get-CliArgs $Opus 'high' 80
      Log "NODE build-$m start in worktree $wt"
      $jobs += Start-Job -Name $m -ScriptBlock {
        param($cwd, $prompt, $cliArgs, $log)
        Set-Location $cwd
        $prompt | & claude @cliArgs *> $log
        $LASTEXITCODE
      } -ArgumentList $wt, $p, $cliArgs, "$RunDir/build-$m.log"
    }
    Wait-Job $jobs | Out-Null
    foreach ($j in $jobs) { $c = Receive-Job $j; Log "NODE build-$($j.Name) exit=$($c | Select-Object -Last 1)"; Remove-Job $j }
    $blocked = @()
    foreach ($m in $modules) {
      $wt = Join-Path $parent "$repoName-wt-$m"
      git -C $wt add -A | Out-Null
      git -C $wt diff --cached --quiet
      if ($LASTEXITCODE -ne 0) { git -C $wt commit -m "module ${m}: pipeline autocommit" | Out-Null }
      git merge --no-ff "mod-$m" -m "merge module $m" | Out-Null
      if ($LASTEXITCODE -ne 0) { git merge --abort | Out-Null; $blocked += $m; Log "LANE BREACH / merge conflict in module $m - skipped" }
      else { Guard-Tests }
    }
    Cleanup-Worktrees
    if ($blocked.Count -gt 0) { Fail-Gate 'G2 build' "merge conflict in: $($blocked -join ', ')" }
  } else {
    Run-Node 'build-all' $Opus 'high' 120 (Build-Prompt 'build' @{ MODULE = 'all' }) $Root | Out-Null
    Commit-All 'pipeline: G2 build'
    Guard-Tests
  }
  $t = Fix-Loop 'G2'
  if ($t.Code -ne 0) { Fail-Gate 'G2 build' "tests still failing after $MaxRounds rounds. See .run/tests-G2-r$MaxRounds.log" }
  Add-Status 'G2 build' 'PASS' "tests green: $($t.Last)"
}

# ---------- G3 INTEGRATE + E2E ----------
if (Want 'G3') {
  Run-Node 'e2e' $Opus 'high' 80 (Build-Prompt 'e2e' @{}) $Root | Out-Null
  Commit-All 'pipeline: G3 e2e smoke test'
  Guard-Tests
  $t = Fix-Loop 'G3'
  if ($t.Code -ne 0) { Fail-Gate 'G3 e2e' "tests/e2e failing after $MaxRounds rounds. See .run/tests-G3-r$MaxRounds.log" }
  Add-Status 'G3 e2e' 'PASS' "full suite incl. e2e green: $($t.Last)"
}

# ---------- G4 VERIFY + SECURITY ----------
if (Want 'G4') {
  $shipped = $false
  for ($r = 1; $r -le 3; $r++) {
    $secrets = Scan-Secrets
    if ($secrets.Count -gt 0) {
      Log "SECRET SCAN FAILED: $($secrets -join ' | ')"
      Run-Node "G4-secretfix$r" $Opus 'high' 40 (Build-Prompt 'fix' @{ FAIL = "Secret scan found: `n$($secrets -join "`n")`nRemove them from git history-visible files, load from environment variables, and make sure .env is ignored." }) $Root | Out-Null
      Commit-All "pipeline: G4 secret fix $r"
      continue
    }
    Remove-Item "$RunDir/VERDICT.md" -ErrorAction SilentlyContinue
    Run-Node "verify$r" $Sonnet 'medium' 60 (Build-Prompt 'verify' @{}) $Root | Out-Null
    $verdict = ''
    if (Test-Path "$RunDir/VERDICT.md") { $verdict = ((Get-Content "$RunDir/VERDICT.md" -TotalCount 1) | Out-String).Trim() }
    if ($verdict -eq 'SHIP') { $shipped = $true; break }
    Log "VERIFIER verdict='$verdict' (round $r)"
    $body = ''
    if (Test-Path "$RunDir/VERDICT.md") { $body = (Get-Content -Raw "$RunDir/VERDICT.md") } else { $body = 'Verifier produced no VERDICT.md.' }
    Run-Node "G4-fix$r" $Opus 'high' 60 (Build-Prompt 'fix' @{ FAIL = $body }) $Root | Out-Null
    Commit-All "pipeline: G4 fix round $r"
    Guard-Tests
    $t = Fix-Loop 'G4'
    if ($t.Code -ne 0) { Fail-Gate 'G4 verify' 'tests red after verifier fixes' }
  }
  if (-not $shipped) { Fail-Gate 'G4 verify' 'verifier did not return SHIP in 3 rounds. See .run/VERDICT.md' }
  Add-Status 'G4 verify+security' 'PASS' 'independent verifier SHIP (Sonnet); secret scan clean'
}

# ---------- G5 PACKAGE ----------
if (Want 'G5') {
  Run-Node 'package' $Sonnet 'medium' 60 (Build-Prompt 'package' @{}) $Root | Out-Null
  if (-not (Test-Path "$Root/deploy/DEPLOY.md")) { Fail-Gate 'G5 package' 'deploy/DEPLOY.md missing' }
  $secrets = Scan-Secrets
  Commit-All 'pipeline: G5 deploy bundle'
  $secrets = Scan-Secrets
  if ($secrets.Count -gt 0) { Fail-Gate 'G5 package' "secret scan hit in bundle: $($secrets -join ' | ')" }
  Add-Status 'G5 package' 'PASS' 'deploy/ and deploy/DEPLOY.md ready'
}

Cleanup-Worktrees
Commit-All 'pipeline: final status'
if ($Push) { git push -u origin HEAD | Out-Null; Log 'Pushed to origin.' }
Log 'PIPELINE COMPLETE. Read STATUS.md and DECISIONS-LOG.md, then upload deploy/ per deploy/DEPLOY.md.'
try { [console]::Beep(880, 400) } catch { }
exit 0
