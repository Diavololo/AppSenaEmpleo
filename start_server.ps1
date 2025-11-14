Param(
  [string]$Port = "8000"
)

$php = "C:\xampp\php\php.exe"
if (-not (Test-Path $php)) {
  $phpCmd = Get-Command php -ErrorAction SilentlyContinue
  if ($phpCmd) {
    $php = $phpCmd.Source
  } else {
    Write-Host "No se encontró PHP en C:\xampp\php\php.exe ni en el PATH. Instala/activa PHP o ajusta la ruta en start_server.ps1." -ForegroundColor Red
    exit 1
  }
}

$docroot = Split-Path -Parent $MyInvocation.MyCommand.Path
Write-Host "Iniciando servidor PHP en http://127.0.0.1:$Port/" -ForegroundColor Green
Write-Host "Docroot: $docroot" -ForegroundColor DarkGray

# Arranca el servidor en una nueva ventana para que no bloquee la terminal actual
Start-Process -FilePath $php -ArgumentList @("-S", "127.0.0.1:$Port", "-t", $docroot) -WorkingDirectory $docroot -WindowStyle Normal

Start-Sleep -Seconds 1
Write-Host "Abre: http://127.0.0.1:$Port/index.php?view=crear_vacante" -ForegroundColor Green