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

/**
 * Entorno aislado para ejecutar backup.sh y restore.sh con mysqldump, mysql y rclone falsos.
 *
 * @return array{root: string, env: array<string, string>}
 */
function backupSandbox(string $password = 'clave-secreta-xyz'): array
{
    $root = sys_get_temp_dir().'/backup-rt-'.uniqid();
    File::makeDirectory("$root/app/storage/app/public", 0777, true);
    File::makeDirectory("$root/bin");
    File::makeDirectory("$root/remote");
    file_put_contents("$root/app/storage/app/public/a.txt", 'hola');
    file_put_contents("$root/app/.env", "DB_CONNECTION=mysql\nDB_HOST=h\nDB_PORT=3306\nDB_DATABASE=d\nDB_USERNAME=u\nDB_PASSWORD=$password\n");
    file_put_contents("$root/pass", "frase\n");
    // El mysqldump falso guarda una copia del fichero de opciones (para comprobar el escapado) y vuelca SQL.
    file_put_contents("$root/bin/mysqldump", "#!/bin/sh\ncp \"\${1#--defaults-extra-file=}\" \"\$FAKE_CNF\"\necho 'CREATE TABLE t (id int);'\n");
    file_put_contents("$root/bin/mysql", "#!/bin/sh\ncase \"\$*\" in *information_schema*) echo 0;; *) cat > \"\$FAKE_OUT\";; esac\n");
    // rclone falso: solo admite `copyto origen destino` (2 posicionales tras el subcomando), como el real; registra cada llamada.
    file_put_contents("$root/bin/rclone", <<<'SH'
#!/bin/sh
echo "$*" >> "$FAKE_RCLONE_LOG"
[ "${RCLONE_FAIL:-0}" = 1 ] && exit 1
n=0
for a in "$@"; do case "$a" in -*) ;; *) n=$((n+1));; esac; done
[ "$n" -eq 3 ] || { echo "rclone: argumentos incorrectos: $*" >&2; exit 2; }
[ "$1" = copyto ] || exit 3
cp "$2" "$FAKE_RCLONE_DEST/$(basename "$3")"
SH);
    foreach (['mysqldump', 'mysql', 'rclone'] as $bin) {
        chmod("$root/bin/$bin", 0755);
    }

    return ['root' => $root, 'env' => [
        'APP_DIR' => "$root/app", 'BACKUP_PASSFILE' => "$root/pass", 'MYSQLDUMP_BIN' => "$root/bin/mysqldump", 'MYSQL_BIN' => "$root/bin/mysql",
        'FAKE_OUT' => "$root/restored.sql", 'FAKE_CNF' => "$root/cnf.copy", 'FAKE_RCLONE_LOG' => "$root/rclone.log", 'FAKE_RCLONE_DEST' => "$root/remote",
        'PATH' => "$root/bin".PATH_SEPARATOR.getenv('PATH'),
    ]];
}

function needsBash(): void
{
    if (! Process::run(['bash', '-c', 'command -v openssl && command -v gzip && command -v tar'])->successful()) {
        test()->markTestSkipped('Necesita bash, openssl, gzip y tar.');
    }
}

it('backup.sh y restore.sh hacen un viaje de ida y vuelta cifrado con HMAC y mysqldump/mysql falsos', function () {
    needsBash();
    ['root' => $root, 'env' => $env] = backupSandbox();

    $backup = Process::env($env)->run(['bash', base_path('deploy/backup/backup.sh')]);
    expect($backup->successful())->toBeTrue($backup->errorOutput())
        ->and($backup->output().$backup->errorOutput())->not->toContain('clave-secreta-xyz');

    $this->artisan('app:backup-verify', ['--dir' => "$root/app/storage/backups"])->assertExitCode(0);

    $marker = parse_ini_file("$root/app/storage/backups/last-backup");
    $db = "$root/app/storage/backups/".$marker['db'];
    expect(file_get_contents($db))->not->toContain('CREATE TABLE')
        ->and(file_exists("$db.hmac"))->toBeTrue();

    $restore = Process::env($env)->run(['bash', base_path('deploy/backup/restore.sh'), '--db', $db,
        '--media', "$root/app/storage/backups/".$marker['media'], '--media-target', "$root/out", '--yes']);
    expect($restore->successful())->toBeTrue($restore->errorOutput())
        ->and(file_get_contents("$root/restored.sql"))->toContain('CREATE TABLE t')
        ->and(file_get_contents("$root/out/public/a.txt"))->toBe('hola');

    File::deleteDirectory($root);
});

it('restore.sh rechaza un fichero alterado (HMAC) y admite un respaldo anterior sin .hmac', function () {
    needsBash();
    ['root' => $root, 'env' => $env] = backupSandbox();
    Process::env($env)->run(['bash', base_path('deploy/backup/backup.sh'), '--db-only'])->throw();
    $marker = parse_ini_file("$root/app/storage/backups/last-db-backup");
    $db = "$root/app/storage/backups/".$marker['db'];

    file_put_contents($db, 'x', FILE_APPEND);
    $bad = Process::env($env)->run(['bash', base_path('deploy/backup/restore.sh'), '--db', $db, '--yes']);
    expect($bad->successful())->toBeFalse()
        ->and($bad->errorOutput())->toContain('HMAC')
        ->and(file_exists("$root/restored.sql"))->toBeFalse();

    // Formato anterior: 10000 iteraciones por defecto y sin .hmac.
    $legacy = "$root/legacy.sql.gz.enc";
    $make = 'printf "CREATE TABLE legacy;" | gzip -c | openssl enc -aes-256-cbc -pbkdf2 -salt -pass "file:$(cygpath -m "$BACKUP_PASSFILE" 2>/dev/null || echo "$BACKUP_PASSFILE")" > "$OUT"';
    Process::env($env + ['OUT' => $legacy])->run(['bash', '-c', $make])->throw();
    $old = Process::env($env)->run(['bash', base_path('deploy/backup/restore.sh'), '--db', $legacy, '--yes']);
    expect($old->successful())->toBeTrue($old->errorOutput())
        ->and(file_get_contents("$root/restored.sql"))->toContain('CREATE TABLE legacy');

    File::deleteDirectory($root);
});

it('copia al destino rclone fichero a fichero (copyto) y escribe la marca solo si la copia externa va bien', function () {
    needsBash();
    ['root' => $root, 'env' => $env] = backupSandbox();
    $env['BACKUP_DEST'] = 'rclone:remoto:bucket';

    $ok = Process::env($env)->run(['bash', base_path('deploy/backup/backup.sh')]);
    expect($ok->successful())->toBeTrue($ok->errorOutput())
        ->and(file_exists("$root/app/storage/backups/last-backup"))->toBeTrue()
        ->and(file_exists("$root/remote/last-backup"))->toBeTrue()
        ->and(count(glob("$root/remote/*.enc*")))->toBe(4);
    foreach (file("$root/rclone.log", FILE_IGNORE_NEW_LINES) as $call) {
        expect($call)->toStartWith('copyto ')->and($call)->toContain('remoto:bucket/');
    }

    // Copia externa rota: el script sale != 0 y no deja marca de frescura.
    File::deleteDirectory("$root/app/storage/backups");
    $fail = Process::env($env + ['RCLONE_FAIL' => '1'])->run(['bash', base_path('deploy/backup/backup.sh')]);
    expect($fail->successful())->toBeFalse()
        ->and(file_exists("$root/app/storage/backups/last-backup"))->toBeFalse();
    $this->artisan('app:backup-verify', ['--dir' => "$root/app/storage/backups"])->assertExitCode(1);

    File::deleteDirectory($root);
});

it('--db-only usa una marca aparte y no pisa la del respaldo completo', function () {
    needsBash();
    ['root' => $root, 'env' => $env] = backupSandbox();

    Process::env($env)->run(['bash', base_path('deploy/backup/backup.sh')])->throw();
    $before = file_get_contents("$root/app/storage/backups/last-backup");
    sleep(1);
    Process::env($env)->run(['bash', base_path('deploy/backup/backup.sh'), '--db-only'])->throw();

    expect(file_get_contents("$root/app/storage/backups/last-backup"))->toBe($before)
        ->and(file_get_contents("$root/app/storage/backups/last-db-backup"))->not->toContain('media=');

    File::deleteDirectory($root);
});

it('escapa comillas y barras invertidas de la contrasena en el fichero de opciones de MySQL', function () {
    needsBash();
    ['root' => $root, 'env' => $env] = backupSandbox('pa"ss\\w');

    $r = Process::env($env)->run(['bash', base_path('deploy/backup/backup.sh'), '--db-only']);
    expect($r->successful())->toBeTrue($r->errorOutput());

    expect(file_get_contents("$root/cnf.copy"))->toContain('password="pa\\"ss\\\\w"');

    File::deleteDirectory($root);
});

it('BACKUP_DIR y BACKUP_MAX_AGE_HOURS vacios en el .env caen en sus valores por defecto', function () {
    foreach (['BACKUP_DIR', 'BACKUP_MAX_AGE_HOURS'] as $key) {
        putenv("$key=");
        $_ENV[$key] = $_SERVER[$key] = '';
    }

    $config = require config_path('backup.php');

    foreach (['BACKUP_DIR', 'BACKUP_MAX_AGE_HOURS'] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    expect($config['dir'])->toBe(storage_path('backups'))->and($config['max_age_hours'])->toBe(26);
});

it('storage/backups esta en .gitignore y el aviso de CI cubre cancelaciones y timeouts', function () {
    expect(file_get_contents(base_path('.gitignore')))->toContain('/storage/backups')
        ->and(file_get_contents(base_path('.github/workflows/ci-failure-notify.yml')))
        ->toContain('failure', 'cancelled', 'timed_out', 'startup_failure');
});
