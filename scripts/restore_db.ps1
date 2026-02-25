param(
  [string]$Host = "127.0.0.1",
  [string]$User = "root",
  [string]$Password = "",
  [string]$Database = "tagom_crm",
  [Parameter(Mandatory = $true)]
  [string]$InputFile
)

if (!(Test-Path $InputFile)) {
  throw "Input file not found: $InputFile"
}

$cmd = "mysql --host=$Host --user=$User $Database < `"$InputFile`""
if ($Password -ne "") {
  $cmd = "mysql --host=$Host --user=$User --password=$Password $Database < `"$InputFile`""
}

Invoke-Expression $cmd
Write-Host "Restore completed from: $InputFile"

