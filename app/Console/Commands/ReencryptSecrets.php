<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class ReencryptSecrets extends Command
{
    protected $signature = 'app:reencrypt {--dry-run : Verifica y cuenta las filas sin escribir}';

    protected $description = 'Recifra los secretos persistidos con la clave actual de la aplicación';

    private const COLUMNS = [
        'social_accounts' => ['access_token', 'refresh_token', 'credential_secret'],
        'canva_connections' => ['access_token', 'refresh_token'],
        'users' => ['two_factor_secret'],
        'webhooks' => ['secret'],
        'push_subscriptions' => ['endpoint', 'keys'],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        /** @var Encrypter $configured */
        $configured = Crypt::getFacadeRoot();
        $current = new Encrypter($configured->getKey(), config('app.cipher'));

        try {
            $count = $dryRun
                ? $this->scan($configured, $current, true)
                : DB::transaction(fn () => $this->scan($configured, $current, false));
        } catch (DecryptException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(($dryRun ? 'Registros que se recifrarían: ' : 'Registros recifrados: ').$count);

        return self::SUCCESS;
    }

    private function scan(Encrypter $configured, Encrypter $current, bool $dryRun): int
    {
        $count = 0;

        foreach (self::COLUMNS as $table => $columns) {
            $query = DB::table($table)->select('id', ...$columns)->where(function (Builder $query) use ($columns): void {
                foreach ($columns as $column) {
                    $query->orWhereNotNull($column);
                }
            });
            if (! $dryRun) {
                $query->lockForUpdate();
            }

            foreach ($query->lazyById(250) as $row) {
                $values = $this->previousValues($table, $row, $columns, $configured, $current);
                if ($values === []) {
                    continue;
                }

                $count++;
                if (! $dryRun) {
                    $encrypted = array_map($current->encryptString(...), $values);
                    DB::table($table)->where('id', $row->id)->update($encrypted);
                }
            }
        }

        return $count;
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, string>
     */
    private function previousValues(string $table, object $row, array $columns, Encrypter $configured, Encrypter $current): array
    {
        $values = [];
        foreach ($columns as $column) {
            $stored = $row->{$column};
            if ($stored === null) {
                continue;
            }

            try {
                $current->decryptString($stored);

                continue;
            } catch (DecryptException) {
                // The configured encrypter also tries APP_PREVIOUS_KEYS.
            }

            try {
                $values[$column] = $configured->decryptString($stored);
            } catch (DecryptException) {
                throw new DecryptException("No se pudo descifrar {$table}.{$column} (id={$row->id}).");
            }
        }

        return $values;
    }
}
