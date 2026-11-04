
$ErrorActionPreference = "Stop"

$url = "http://localhost/iot_backend/public/api/v1/telemetry"
$nodeId = "ESP32_Nodo03"

$secure = Read-Host "Token REST del Nodo03" -AsSecureString
$token = [System.Net.NetworkCredential]::new("", $secure).Password

# Secuencia de prueba aleatoria
$seq = Get-Random -Minimum 1200000000 -Maximum 1299999999

$data = @{
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
}

$body = $data | ConvertTo-Json -Depth 5 -Compress

Write-Host "`nPRUEBA DE IDEMPOTENCIA CONCURRENTE"
Write-Host "Nodo: $nodeId"
Write-Host "SEQ: $seq"
Write-Host "Solicitudes simultaneas: 5"

$jobs = @(
    for ($i = 1; $i -le 5; $i++) {
        Start-Job -ArgumentList $url, $token, $body, $i -ScriptBlock {
            param($url, $token, $body, $worker)

            $headers = @{
                Authorization = "Bearer $token"
            }

            $status = 0
            $result = $null
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
            }
            catch [System.Net.WebException] {
                $webResponse = $_.Exception.Response

                if ($null -ne $webResponse) {
                    $status = [int]$webResponse.StatusCode
                }

                $errorText = $_.Exception.Message
            }
            catch {
                $errorText = $_.Exception.Message
            }

            [PSCustomObject]@{
                worker = $worker
                status = $status
                telemetry_id = if ($result) {
                    $result.data.telemetry_id
                } else {
                    $null
                }
                duplicate = if ($result) {
                    $result.data.duplicate
                } else {
                    $null
                }
                error = $errorText
            }
        }
    }
)

try {
    $jobs | Wait-Job | Out-Null
    $results = @($jobs | Receive-Job)
}
finally {
    $jobs | Remove-Job -Force
}

Write-Host "`n========== RESULTADOS =========="

$results |
    Sort-Object worker |
    Format-Table -Property @('worker', 'status', 'telemetry_id', 'duplicate', 'error') -AutoSize

$created = @($results | Where-Object {
    $_.status -eq 201 -and $_.duplicate -eq $false
})

$duplicates = @($results | Where-Object {
    $_.status -eq 200 -and $_.duplicate -eq $true
})

$ids = @(
    $results |
    Where-Object { $null -ne $_.telemetry_id } |
    Select-Object -ExpandProperty telemetry_id -Unique
)

$passed = (
    $results.Count -eq 5 -and
    $created.Count -eq 1 -and
    $duplicates.Count -eq 4 -and
    $ids.Count -eq 1
)

if ($passed) {
    Write-Host "PRUEBA APROBADA" -ForegroundColor Green
    Write-Host "Un solo registro generado."
    Write-Host "ID unico: $($ids[0])"
}
else {
    Write-Host "PRUEBA FALLIDA" -ForegroundColor Red
    Write-Host "Revisar respuestas y base de datos."
}

Write-Host "SEQ PARA VERIFICAR EN SQL: $seq"

$results | Export-Csv `
    (Join-Path $PSScriptRoot "idempotency_results.local.csv") `
    -NoTypeInformation -Encoding UTF8
