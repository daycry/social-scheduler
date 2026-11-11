<?php

use App\Support\HealthCheck;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Auditoria A-03: `app:backup-verify` y el aviso de `app:health` leen la marca `last-backup` de deploy/backup/backup.sh.
 */
beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/backup-verify-'.uniqid();
    File::makeDirectory($this->dir);
    config(['backup.dir' => $this->dir]);
});

afterEach(fn () => File::deleteDirectory($this->dir));

function writeBackup(string $dir, string $timestamp, bool $media = true): void
{
    file_put_contents("$dir/db-x.sql.gz.enc", 'datos');
    $marker = "timestamp=$timestamp\ndb=db-x.sql.gz.enc\n";
    if ($media) {
        file_put_contents("$dir/media-x.tar.gz.enc", 'datos');
        $marker .= "media=media-x.tar.gz.enc\n";
    }
    file_put_contents("$dir/last-backup", $marker);
}

function backupStamp(int $hoursAgo): string
{
    return now()->subHours($hoursAgo)->utc()->format('Y-m-d\TH:i:s\Z');
}

it('da exito con un respaldo reciente', function () {
    writeBackup($this->dir, backupStamp(3));

    $this->artisan('app:backup-verify')->expectsOutputToContain('hace 3h')->assertExitCode(0);
});

it('avisa si el ultimo respaldo supera 26 horas', function () {
    writeBackup($this->dir, backupStamp(27));

    $this->artisan('app:backup-verify')->expectsOutputToContain('27h (limite 26h)')->assertExitCode(1);
});

it('respeta --max-hours y --dir', function () {
    $other = $this->dir.'/otro';
    File::makeDirectory($other);
    writeBackup($other, backupStamp(30));

    $this->artisan('app:backup-verify', ['--dir' => $other])->assertExitCode(1);
    $this->artisan('app:backup-verify', ['--dir' => $other, '--max-hours' => 48])->assertExitCode(0);
    $this->artisan('app:backup-verify', ['--max-hours' => 'x'])->assertExitCode(2);
});

it('falla si no hay marca, si falta el fichero o si la fecha no es valida', function () {
    $this->artisan('app:backup-verify')->expectsOutputToContain('no hay respaldos')->assertExitCode(1);

    writeBackup($this->dir, backupStamp(0));
    unlink("$this->dir/media-x.tar.gz.enc");
    $this->artisan('app:backup-verify')->expectsOutputToContain('fichero de media')->assertExitCode(1);

    writeBackup($this->dir, 'ayer');
    $this->artisan('app:backup-verify')->assertExitCode(1);
});

it('rechaza una marca que apunta fuera de la carpeta', function () {
    file_put_contents("$this->dir/last-backup", 'timestamp='.backupStamp(0)."\ndb=../secreto\n");

    $this->artisan('app:backup-verify')->assertExitCode(1);
});

it('app:health solo incluye el check backup si BACKUP_HEALTH esta activo y avisa sin tumbar', function () {
    expect(app(HealthCheck::class)->run(true)['checks'])->not->toHaveKey('backup');

    config(['backup.health' => true]);
    $result = app(HealthCheck::class)->run(true);
    expect($result['checks']['backup'])->toMatchArray(['ok' => true, 'warn' => true])
        ->and($result['status'])->not->toBe('down');

    writeBackup($this->dir, backupStamp(1));
    expect(app(HealthCheck::class)->run(true)['checks']['backup'])->not->toHaveKey('warn');
});

it('backup.sh y restore.sh hacen un viaje de ida y vuelta cifrado con mysqldump y mysql falsos', function () {
    $bash = 'bash';
    if (! @Process::run([$bash, '-c', 'command -v openssl && command -v gzip && command -v tar'])->successful()) {
        $this->markTestSkipped('Necesita bash, openssl, gzip y tar.');
    }

    $root = sys_get_temp_dir().'/backup-rt-'.uniqid();
    File::makeDirectory("$root/app/storage/app/public", 0777, true);
    File::makeDirectory("$root/bin");
    file_put_contents("$root/app/storage/app/public/a.txt", 'hola');
    file_put_contents("$root/app/.env", "DB_CONNECTION=mysql\nDB_HOST=h\nDB_PORT=3306\nDB_DATABASE=d\nDB_USERNAME=u\nDB_PASSWORD=\"clave-secreta-xyz\"\n");
    file_put_contents("$root/pass", "frase\n");
    file_put_contents("$root/bin/mysqldump", "#!/bin/sh\necho 'CREATE TABLE t (id int);'\n");
    file_put_contents("$root/bin/mysql", "#!/bin/sh\ncase \"\$*\" in *information_schema*) echo 0;; *) cat > \"\$FAKE_OUT\";; esac\n");
    chmod("$root/bin/mysqldump", 0755);
    chmod("$root/bin/mysql", 0755);

    $env = ['APP_DIR' => "$root/app", 'BACKUP_PASSFILE' => "$root/pass", 'MYSQLDUMP_BIN' => "$root/bin/mysqldump", 'MYSQL_BIN' => "$root/bin/mysql", 'FAKE_OUT' => "$root/restored.sql"];

    $backup = Process::env($env)->run([$bash, base_path('deploy/backup/backup.sh')]);
    expect($backup->successful())->toBeTrue($backup->errorOutput())
        ->and($backup->output().$backup->errorOutput())->not->toContain('clave-secreta-xyz');

    $this->artisan('app:backup-verify', ['--dir' => "$root/app/storage/backups"])->assertExitCode(0);

    $marker = parse_ini_file("$root/app/storage/backups/last-backup");
    expect(file_get_contents("$root/app/storage/backups/".$marker['db']))->not->toContain('CREATE TABLE');

    $restore = Process::env($env)->run([$bash, base_path('deploy/backup/restore.sh'), '--db', "$root/app/storage/backups/".$marker['db'],
        '--media', "$root/app/storage/backups/".$marker['media'], '--media-target', "$root/out", '--yes']);
    expect($restore->successful())->toBeTrue($restore->errorOutput())
        ->and(file_get_contents("$root/restored.sql"))->toContain('CREATE TABLE t')
        ->and(file_get_contents("$root/out/public/a.txt"))->toBe('hola');

    File::deleteDirectory($root);
});
