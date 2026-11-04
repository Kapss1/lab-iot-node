
$ErrorActionPreference = "Stop"

$url = "http://localhost/iot_backend/public/api/v1/telemetry"
$workers = 5
$perWorker = 20

# Utilizamos Nodo03 para no interferir con
# los contadores de los ESP32 activos.
$nodeId = "ESP32_Nodo03"

$secure = Read-Host "Token REST de ESP32_Nodo03" -AsSecureString
$token = [System.Net.NetworkCredential]::new("", $secure).Password

$seqBase = Get-Random -Minimum 1300000000 -Maximum 1800000000

Write-Host "`nPRUEBA DE CONCURRENCIA HTTP"
Write-Host "Trabajadores: $workers"
Write-Host "Solicitudes totales: $($workers * $perWorker)"

$timer = [System.Diagnostics.Stopwatch]::StartNew()

$jobs = @(
    for ($w = 0; $w -lt $workers; $w++) {
        Start-Job -ArgumentList $url, $token, $nodeId, $seqBase, $w, $perWorker -ScriptBlock {
            param($url, $token, $nodeId, $seqBase, $worker, $count)

            $headers = @{
                Authorization = "Bearer $token"
            }

            for ($i = 0; $i -lt $count; $i++) {
                $seq = $seqBase + ($worker * $count) + $i

                $body = @{
                    node_id = $nodeId
                    ts = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
                    rssi = -55
                    seq = $seq
                    value = @{
                        temperatura = 25.6
                        humedad = 68.2
                        lux = 350
                        particulas = 120.5
                    }
                } | ConvertTo-Json -Depth 5 -Compress

                $sw = [System.Diagnostics.Stopwatch]::StartNew()
                $status = 0
                $success = $false
                $errorText = ""

                try {
                    $response = Invoke-WebRequest `
                        -Uri $url `
                        -Method POST `
                        -Headers $headers `
                        -ContentType "application/json" `
                        -Body $body `
                        -UseBasicParsing `
                        -TimeoutSec 30

                    $status = [int]$response.StatusCode
                    $result = $response.Content | ConvertFrom-Json

                    $success = (
                        $status -eq 201 -and
                        $result.ok -eq $true -and
                        $result.data.duplicate -eq $false
                    )
                }
                catch {
                    $errorText = $_.Exception.Message

                    if ($_.Exception.Response) {
                        $status = [int]$_.Exception.Response.StatusCode
                    }
                }
                finally {
                    $sw.Stop()
                }

                [PSCustomObject]@{
                    worker = $worker
                    seq = $seq
                    latency_ms = [math]::Round($sw.Elapsed.TotalMilliseconds, 3)
                    status = $status
                    success = $success
                    error = $errorText
                }
            }
        }
    }
)

try {
    $jobs | Wait-Job | Out-Null
    $results = @($jobs | Receive-Job)
}
finally {
    $timer.Stop()
    $jobs | Remove-Job -Force
}

$valid = @($results | Where-Object { $_.success -eq $true })
$failed = @($results | Where-Object { $_.success -ne $true })

$duration = $timer.Elapsed.TotalSeconds
$throughput = [math]::Round($valid.Count / $duration, 2)

Write-Host "`n========== RESULTADOS =========="
Write-Host "Solicitudes totales: $($results.Count)"
Write-Host "Exitosas: $($valid.Count)"
Write-Host "Fallidas: $($failed.Count)"
Write-Host "Duracion: $([math]::Round($duration, 3)) s"
Write-Host "Throughput: $throughput req/s"

if ($valid.Count -gt 0) {
    $lat = @($valid | ForEach-Object { $_.latency_ms } | Sort-Object)

    $p50 = $lat[[math]::Ceiling($lat.Count * 0.50) - 1]
    $p95 = $lat[[math]::Ceiling($lat.Count * 0.95) - 1]
    $avg = ($lat | Measure-Object -Average).Average

    Write-Host "Latencia promedio: $([math]::Round($avg, 2)) ms"
    Write-Host "p50: $p50 ms"
    Write-Host "p95: $p95 ms"
}

Write-Host "================================"

$output = (Join-Path $PSScriptRoot "concurrent_results.local.csv")

$results | Export-Csv -Path $output -NoTypeInformation -Encoding UTF8

Write-Host "CSV guardado: $output"

if ($failed.Count -gt 0) {
    $failed | Select-Object -First 5 | Format-Table
}
