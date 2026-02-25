param(
  [string]$Host = "127.0.0.1",
  [string]$User = "root",
  [string]$Password = "",
  [string]$Database = "tagom_crm",
  [string]$OutputDir = ".\\backups"
)

if (!(Test-Path $OutputDir)) {
  New-Item -ItemType Directory -Path $OutputDir | Out-Null
}

$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$file = Join-Path $OutputDir "$Database-$stamp.sql"

$cmd = "mysqldump --host=$Host --user=$User $Database > `"$file`""
if ($Password -ne "") {
  $cmd = "mysqldump --host=$Host --user=$User --password=$Password $Database > `"$file`""
}

Invoke-Expression $cmd
Write-Host "Backup created: $file"

