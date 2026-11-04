
# LABORATORIO 2 - PRUEBA DE CARGA REST
# Compatible con Windows PowerShell 5.1

$ErrorActionPreference = "Stop"

$url = "http://localhost/iot_backend/public/api/v1/telemetry"
$total = 100

# Solicitar credencial sin guardarla en el script
$secure = Read-Host "Introduce el TOKEN REST del Nodo01" -AsSecureString
$token = [System.Net.NetworkCredential]::new("", $secure).Password

$headers = @{
    Authorization = "Bearer $token"
}

# Reservar un bloque de secuencias para esta ejecucion.
$seqBase = Get-Random -Minimum 1300000000 -Maximum 1800000000

$results = New-Object System.Collections.Generic.List[object]

Write-Host "`nINICIANDO PRUEBA DE CARGA"
Write-Host "Solicitudes: $total"
Write-Host "Endpoint: $url"
Write-Host "-----------------------------------"

$globalTimer = [System.Diagnostics.Stopwatch]::StartNew()

for ($i = 1; $i -le $total; $i++) {

    $data = @{
        node_id = "ESP32_ArequipaSimu"
        ts = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
        rssi = -55
        seq = $seqBase + $i
        value = @{
            temperatura = 25.6
            humedad = 68.2
            lux = 350
            particulas = 120.5
        }
    }

    $body = $data | ConvertTo-Json -Depth 5 -Compress
    $timer = [System.Diagnostics.Stopwatch]::StartNew()

    $status = 0
    $success = $false
    $errorMessage = ""

    try {
        $response = Invoke-WebRequest `
            -Uri $url `
            -Method POST `
            -Headers $headers `
            -ContentType "application/json" `
            -Body $body `
            -UseBasicParsing `
            -TimeoutSec 15

        $status = [int]$response.StatusCode
        $parsed = $response.Content | ConvertFrom-Json

        $success = (
            $status -eq 201 -and
            $parsed.ok -eq $true -and
            $parsed.data.duplicate -eq $false
        )
    }
    catch {
        $errorMessage = $_.Exception.Message

        if ($_.Exception.Response) {
            $status = [int]$_.Exception.Response.StatusCode
        }
    }
    finally {
        $timer.Stop()
    }

    $results.Add([PSCustomObject]@{
        request = $i
        seq = $seqBase + $i
        latency_ms = [math]::Round($timer.Elapsed.TotalMilliseconds, 3)
        http_status = $status
        success = $success
        error = $errorMessage
    })

    if ($i % 10 -eq 0) {
        Write-Host "Procesadas: $i / $total"
    }
}

$globalTimer.Stop()

$valid = @($results | Where-Object { $_.success })
$failed = @($results | Where-Object { -not $_.success })

$elapsed = $globalTimer.Elapsed.TotalSeconds

Write-Host "`n========== RESULTADOS =========="
Write-Host "Solicitudes totales: $total"
Write-Host "Exitosas: $($valid.Count)"
Write-Host "Fallidas: $($failed.Count)"
Write-Host "Duracion (s): $([math]::Round($elapsed, 3))"
Write-Host "Throughput exitoso (req/s): $([math]::Round($valid.Count / $elapsed, 2))"

if ($valid.Count -gt 0) {
    $latencies = @(
        $valid | ForEach-Object { $_.latency_ms } | Sort-Object
    )

    $p50Index = [math]::Ceiling(0.50 * $latencies.Count) - 1
    $p95Index = [math]::Ceiling(0.95 * $latencies.Count) - 1

    $average = (
        $latencies | Measure-Object -Average
    ).Average

    Write-Host "Latencia promedio (ms): $([math]::Round($average, 2))"
    Write-Host "Latencia p50 (ms): $($latencies[$p50Index])"
    Write-Host "Latencia p95 (ms): $($latencies[$p95Index])"
    Write-Host "Latencia minima (ms): $($latencies[0])"
    Write-Host "Latencia maxima (ms): $($latencies[-1])"
}

Write-Host "================================"

# Guardar evidencia reutilizable para el informe.
$output = (Join-Path $PSScriptRoot "load_results.local.csv")

$results | Export-Csv `
    -Path $output `
    -NoTypeInformation `
    -Encoding UTF8

Write-Host "Resultados guardados en: $output"
