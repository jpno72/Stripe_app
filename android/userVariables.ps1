Write-Host "`n=== USER ENVIRONMENT VARIABLE CHECK ===`n"

Write-Host "Current user:"
Write-Host $env:USERNAME

Write-Host "`n--- USER JAVA_HOME ---"
$userJavaHome = [Environment]::GetEnvironmentVariable("JAVA_HOME", "User")

if ($userJavaHome) {
    Write-Host "User JAVA_HOME = $userJavaHome"

    if (Test-Path "$userJavaHome\bin\java.exe") {
        Write-Host "User JAVA_HOME java.exe exists: YES"
        & "$userJavaHome\bin\java.exe" -version
    } else {
        Write-Host "User JAVA_HOME java.exe exists: NO"
    }
} else {
    Write-Host "User JAVA_HOME is not set"
}

Write-Host "`n--- USER PATH ---"
$userPath = [Environment]::GetEnvironmentVariable("Path", "User")

if ($userPath) {
    $userPath -split ";" | ForEach-Object {
        if ($_ -ne "") {
            Write-Host $_
        }
    }
} else {
    Write-Host "User Path is not set"
}

Write-Host "`n--- USER Java-related PATH entries ---"
if ($userPath) {
    $userPath -split ";" | Where-Object {
        $_ -match "Java|jdk|jre|Oracle|Microsoft|Adoptium|OpenJDK"
    } | ForEach-Object {
        Write-Host $_
    }
}

Write-Host "`n--- EFFECTIVE CURRENT SESSION JAVA INFO ---"
Write-Host "Session JAVA_HOME:"
Write-Host $env:JAVA_HOME

Write-Host "`njava -version:"
java -version

Write-Host "`nwhere.exe java:"
where.exe java

Write-Host "`n--- Gradle version check from current folder ---"
if (Test-Path ".\gradlew.bat") {
    .\gradlew --version --no-daemon
} elseif (Test-Path ".\gradlew") {
    .\gradlew --version --no-daemon
} else {
    Write-Host "No gradlew found in this folder."
}

Write-Host "`n=== USER ENV CHECK COMPLETE ==="