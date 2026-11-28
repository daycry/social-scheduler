param([string]$Stage='red')
$ErrorActionPreference='Stop'
$project='C:/laragon/www/metricool/.cache/t80-physical'
$storage=Join-Path $project ('storage/framework/testing/t80-sqlite-race-'+[guid]::NewGuid())
New-Item -ItemType Directory -Force (Join-Path $storage 'framework/views'),(Join-Path $storage 'framework/cache/data') | Out-Null
New-Item -ItemType File -Path (Join-Path $storage 'db.sqlite') | Out-Null
$env:APP_ENV='testing'
$env:APP_KEY='base64:'+ [Convert]::ToBase64String([Security.Cryptography.RandomNumberGenerator]::GetBytes(32))
$env:DB_CONNECTION='sqlite'
$env:DB_DATABASE=Join-Path $storage 'db.sqlite'
$env:DB_URL=''
$env:CACHE_STORE='array'
$env:QUEUE_CONNECTION='database'
$env:LARAVEL_STORAGE_PATH=$storage
$env:VIEW_COMPILED_PATH=Join-Path $storage 'framework/views'
Set-Location -LiteralPath $project
python "$env:TEMP/metricool-t80-sqlite-race.py" *> "$env:TEMP/metricool-t80-$Stage-sqlite-race.log"
$qaExit=$LASTEXITCODE
Get-Content "$env:TEMP/metricool-t80-$Stage-sqlite-race.log" -Tail 10
exit $qaExit
