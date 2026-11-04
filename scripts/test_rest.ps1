
$ErrorActionPreference = "Stop"

$base = "http://localhost/iot_backend/public/api/v1/telemetry"

$secure = Read-Host "Token REST de ESP32_ArequipaSimu" -AsSecureString
$token = [System.Net.NetworkCredential]::new("", $secure).Password
if ([string]::IsNullOrWhiteSpace($token)) {
    throw "Debes introducir el token REST local del dispositivo."
}

$headers = @{
    Authorization = "Bearer $token"
}

$seq = Get-Random -Minimum 100000000 -Maximum 2000000000

$data = @{
    node_id = "ESP32_ArequipaSimu"
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

$body = $data | ConvertTo-Json -Depth 5

function Invoke-TestPost($testName, $headers, $body) {
    try {
        $response = Invoke-WebRequest `
            -Uri $base `
            -Method POST `
            -Headers $headers `
            -ContentType "application/json" `
            -Body $body `
            -UseBasicParsing

        $status = [int]$response.StatusCode
        $json = $response.Content | ConvertFrom-Json
    }
    catch [System.Net.WebException] {
        $response = $_.Exception.Response
        if ($null -eq $response) { throw }

        $status = [int]$response.StatusCode
        $reader = New-Object System.IO.StreamReader(
            $response.GetResponseStream()
        )
        $json = $reader.ReadToEnd() | ConvertFrom-Json
        $reader.Dispose()
    }

    Write-Host "$testName - HTTP $status"

    return @{
        status = $status
        body = $json
    }
}

# T01: Insercion valida
$t01 = Invoke-TestPost "T01 POST valido" $headers $body

if (
    $t01.status -eq 201 -and
    $t01.body.ok -eq $true -and
    $t01.body.data.duplicate -eq $false
) {
    Write-Host "T01 APROBADA" -ForegroundColor Green
}
else {
    Write-Host "T01 FALLIDA" -ForegroundColor Red
}

# T02: Token invalido
$badHeaders = @{
    Authorization = "Bearer token_invalido"
}

$t02 = Invoke-TestPost "T02 Token invalido" $badHeaders $body

if ($t02.status -eq 401) {
    Write-Host "T02 APROBADA" -ForegroundColor Green
}
else {
    Write-Host "T02 FALLIDA" -ForegroundColor Red
}

# T03: JSON incompleto
$incomplete = @{
    node_id = "ESP32_ArequipaSimu"
    seq = $seq + 1
} | ConvertTo-Json

$t03 = Invoke-TestPost "T03 JSON incompleto" $headers $incomplete

if ($t03.status -eq 422) {
    Write-Host "T03 APROBADA" -ForegroundColor Green
}
else {
    Write-Host "T03 FALLIDA" -ForegroundColor Red
}

# T04: Mensaje duplicado
$t04 = Invoke-TestPost "T04 Duplicado" $headers $body

if (
    $t04.status -eq 200 -and
    $t04.body.data.duplicate -eq $true -and
    $t04.body.data.telemetry_id -eq
        $t01.body.data.telemetry_id
) {
    Write-Host "T04 APROBADA" -ForegroundColor Green
}
else {
    Write-Host "T04 FALLIDA" -ForegroundColor Red
}
