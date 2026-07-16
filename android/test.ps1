Write-Host "`n=== JAVA ENVIRONMENT CHECK ===`n"

Write-Host "Current folder:"
Get-Location

Write-Host "`n--- JAVA_HOME ---"
if ($env:JAVA_HOME) {
    Write-Host "JAVA_HOME = $env:JAVA_HOME"
    if (Test-Path "$env:JAVA_HOME\bin\java.exe") {
        Write-Host "JAVA_HOME java.exe exists: YES"
        & "$env:JAVA_HOME\bin\java.exe" -version
    } else {
        Write-Host "JAVA_HOME java.exe exists: NO"
    }
} else {
    Write-Host "JAVA_HOME is not set"
}

Write-Host "`n--- java -version from current PATH ---"
try {
    java -version
} catch {
    Write-Host "java command not found"
}

Write-Host "`n--- where.exe java ---"
try {
    where.exe java
} catch {
    Write-Host "No java.exe found in PATH"
}

Write-Host "`n--- Java-related PATH entries ---"
$env:Path -split ";" | Where-Object {
    $_ -match "Java|jdk|jre|Oracle|Microsoft|Adoptium|OpenJDK"
} | ForEach-Object {
    Write-Host $_
}

Write-Host "`n--- Common Java install folders ---"
$folders = @(
    "C:\Program Files\Java",
    "C:\Program Files\Microsoft",
    "C:\Program Files\Eclipse Adoptium",
    "C:\Program Files\AdoptOpenJDK",
    "C:\Program Files\Amazon Corretto",
    "C:\Program Files\OpenJDK",
    "C:\Program Files\Common Files\Oracle\Java\javapath"
)

foreach ($folder in $folders) {
    if (Test-Path $folder) {
        Write-Host "`n$folder"
        Get-ChildItem $folder -ErrorAction SilentlyContinue | Select-Object FullName
    }
}

Write-Host "`n--- Gradle version check ---"
if (Test-Path ".\gradlew.bat") {
    .\gradlew --version --no-daemon
} elseif (Test-Path ".\gradlew") {
    .\gradlew --version --no-daemon
} else {
    Write-Host "No gradlew found in this folder."
    Write-Host "Run this script from your Android project folder:"
    Write-Host "D:\Documents\WebSites\BMA2026\Stripe app\android"
}

Write-Host "`n=== CHECK COMPLETE ==="