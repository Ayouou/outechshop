# Assemble build\app : PHP minimal + l'application web (www), à côté d'OUTECH.exe.
# Utilisé par GitHub Actions ; se lance aussi à la main depuis la racine du dépôt :
#   powershell -File desktop\preparer.ps1 [-PhpZip chemin\php.zip]
param(
    [string]$PhpZip = "",
    [string]$Sortie = "build\app"
)
$ErrorActionPreference = "Stop"

# Version de PHP figée, avec son empreinte publiée par windows.php.net.
$PhpVersion = "8.3.36"
$PhpFichier = "php-$PhpVersion-nts-Win32-vs16-x64.zip"
$PhpSha256  = "3c9242622c7402b47c38d2ec2ed779cef3a290fd6f2bde6c35f9c482699e7900"

$racine = Split-Path -Parent $PSScriptRoot
Set-Location $racine
New-Item -ItemType Directory -Force $Sortie | Out-Null
$tmp = Join-Path $env:TEMP ("outech-php-" + [guid]::NewGuid())
New-Item -ItemType Directory -Force $tmp | Out-Null

# ------------------------------------------------------------------ PHP
if ($PhpZip -eq "") {
    $PhpZip = Join-Path $tmp $PhpFichier
    Write-Host "Telechargement de $PhpFichier"
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    Invoke-WebRequest "https://downloads.php.net/~windows/releases/$PhpFichier" -OutFile $PhpZip -UseBasicParsing
}
$empreinte = (Get-FileHash $PhpZip -Algorithm SHA256).Hash.ToLower()
if ($empreinte -ne $PhpSha256) { throw "SHA-256 inattendu pour $PhpZip : $empreinte" }

$phpComplet = Join-Path $tmp "php"
Expand-Archive $PhpZip -DestinationPath $phpComplet -Force

# Seulement ce dont OUTECH a besoin : l'installateur reste petit.
$php = Join-Path $Sortie "php"
New-Item -ItemType Directory -Force (Join-Path $php "ext") | Out-Null
foreach ($f in @("php.exe", "php8.dll", "libsqlite3.dll", "license.txt")) {
    Copy-Item (Join-Path $phpComplet $f) $php
}
foreach ($f in @("php_pdo_sqlite.dll", "php_mbstring.dll", "php_zip.dll")) {
    Copy-Item (Join-Path $phpComplet "ext\$f") (Join-Path $php "ext")
}
Copy-Item "desktop\php.ini" $php

# Runtime Visual C++ posé à côté de php.exe (déploiement local autorisé par
# Microsoft) : rien à installer en plus sur le PC du client.
foreach ($f in @("vcruntime140.dll", "vcruntime140_1.dll")) {
    Copy-Item (Join-Path $env:WINDIR "System32\$f") $php
}

# ----------------------------------------------------------- application web
$www = Join-Path $Sortie "www"
if (Test-Path $www) { Remove-Item -Recurse -Force $www }
New-Item -ItemType Directory -Force $www | Out-Null
foreach ($element in @("app", "assets", "install", "index.php", "router.php", "config.php")) {
    Copy-Item -Recurse (Join-Path "public_html" $element) $www
}
# Jamais d'identifiants ni de copies de sauvegarde dans l'installateur.
Get-ChildItem $www -Recurse -Force -Include ".env*", "*.avant-*", "*.bak" | Remove-Item -Force

[IO.Directory]::Delete($tmp, $true)
Write-Host "Pret : $Sortie"
