# Ejecutar despues de clonar: powershell -File .\scripts\preparar_local.ps1
# Crea plantillas locales; nunca modifica un archivo que ya exista.
$ErrorActionPreference = "Stop"
$repo = Split-Path -Parent $PSScriptRoot
$pairs = @(
    @("iot_backend\config\database.local.example.php", "iot_backend\config\database.local.php"),
    @("firmware\ESP32_MQTT_NTP\secrets.example.h", "firmware\ESP32_MQTT_NTP\secrets.h"),
    @("firmware\ESP32_MQTT_NTP_SIM\secrets.example.h", "firmware\ESP32_MQTT_NTP_SIM\secrets.h")
)
foreach ($pair in $pairs) {
    $source = Join-Path $repo $pair[0]
    $target = Join-Path $repo $pair[1]
    if (-not (Test-Path -LiteralPath $target)) {
        Copy-Item -LiteralPath $source -Destination $target
        Write-Host "Creado: $($pair[1])"
    } else {
        Write-Host "Se conserva: $($pair[1])"
    }
}
Write-Host "Completa las plantillas solo en tu computadora. Git las excluye."
Write-Host "En iot_backend ejecuta composer install."
Write-Host "Importa database\01_schema_iot_telemetry.sql en una BD vacia desde phpMyAdmin."
Write-Host "Para XAMPP utiliza iot_backend en htdocs como en la instalacion original."
