# Sube la actualizacion "Plantillas de inspeccion" al NAS y la instala.
# Uso (PowerShell, desde esta carpeta):
#   cd C:\xampp82\htdocs\INSPEECIONLIGERAECOING\despliegue_plantillas
#   .\subir_plantillas_nas.ps1

$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot

$Nas = "Usuario_VPN@192.168.1.3"
$Destino = "/share/CACHEDEV1_DATA/InspeccionLigera/app-data"
$Paquete = "release_plantillas_20261015.tar.gz"
$Instalador = "instalar_plantillas_nas.sh"

# 1. Integridad del paquete
$esperado = (Get-Content SHA256SUMS.txt | Select-String $Paquete).ToString().Split(" ")[0]
$real = (Get-FileHash $Paquete -Algorithm SHA256).Hash.ToLower()
if ($esperado -ne $real) { throw "El paquete no coincide con SHA256SUMS.txt. No continuar." }
Write-Host "Paquete verificado. (script v2)" -ForegroundColor Green

# 2. Copiar paquete e instalador.
#    -O = protocolo scp clasico. El scp nuevo de Windows usa SFTP y el QNAP no
#    lo tiene ("subsystem request failed on channel 0").
Write-Host "Copiando al NAS (contrasena 1)..." -ForegroundColor Cyan
scp.exe -O $Paquete $Instalador "${Nas}:${Destino}/"
if ($LASTEXITCODE -ne 0) {
    # Plan B: enviar cada archivo por ssh con cat. cmd.exe redirige binario
    # sin alterarlo (PowerShell 5 no).
    Write-Host "scp -O no funciono; probando por ssh (pedira la contrasena por cada archivo)..." -ForegroundColor Yellow
    foreach ($Archivo in @($Paquete, $Instalador)) {
        cmd.exe /c "ssh.exe $Nas ""cat > $Destino/$Archivo"" < ""$Archivo"""
        if ($LASTEXITCODE -ne 0) { throw "Fallo la copia de $Archivo al NAS. No se instalo nada." }
    }
}
Write-Host "Archivos copiados." -ForegroundColor Green

# 3. Instalar (respaldo de BD, migracion, rebuild y comprobacion)
Write-Host "Instalando en el NAS (contrasena 2)..." -ForegroundColor Cyan
ssh.exe -t $Nas "bash $Destino/$Instalador"
if ($LASTEXITCODE -ne 0) { throw "La instalacion termino con error. Revisa los mensajes de arriba." }

Write-Host ""
Write-Host "Actualizacion instalada. Panel: http://192.168.1.3:8082/web/plantillas.html" -ForegroundColor Green
