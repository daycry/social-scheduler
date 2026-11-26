<?php

namespace App\Services\Posts;

use App\Connectors\Network;
use App\Enums\ContentType;
use App\Exceptions\PostValidationException;
use App\Http\Requests\PostRequest;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use App\Services\SocialAccountService;
use App\Support\CsvWriter;
use App\Support\LocalDateTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Importacion masiva de publicaciones por CSV (T-83). Cada fila se convierte en el MISMO payload que envia el compositor y se valida con
 * las reglas de `PostRequest` y con `PostService::validate`; se crea con `PostService::create`. No hay reglas propias de contenido: lo que el
 * compositor rechaza, la importacion lo rechaza con el mismo mensaje. Formato y limites: `docs/usuario/importar-csv.md` y `config/post_import.php`.
 *
 * Una fila es un array con `row` (numero de fila de la hoja, la cabecera es la 1) y una clave por columna.
 */
class PostCsvImport
{
    public const COLUMNS = ['fecha', 'texto', 'cuentas', 'tipo', 'medios', 'aprobacion'];

    public const REQUIRED = ['fecha', 'texto', 'cuentas'];

    private const YES = ['si', 'sí', 's', 'y', 'yes', '1', 'true'];

    private const NO = ['', 'no', 'n', '0', 'false'];

    public function __construct(private readonly PostService $posts, private readonly SocialAccountService $accounts) {}

    /** Identificador de una cuenta en la columna `cuentas`: `red:id`. El id es unico, no lleva espacios ni `|` y nunca confunde dos cuentas. */
    public static function identifier(SocialAccount $account): string
    {
        return $account->network->value.':'.$account->id;
    }

    /**
     * Lee el CSV: UTF-8 (con o sin BOM), separador `,` o `;` por la cabecera, comillas RFC 4180. Las filas totalmente vacias se ignoran.
     * Los errores de fichero (codificacion, columnas, limites) salen como un error de validacion del campo `file`.
     *
     * @return list<array<string, mixed>>
     */
    public function parse(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        if (! mb_check_encoding($content, 'UTF-8')) {
            $this->fileError('El fichero debe estar codificado en UTF-8.');
        }

        // Las lineas de comentario (#) y en blanco ANTES de la cabecera se ignoran: el CSV de ejemplo documenta ahi las columnas.
        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
        $skipped = 0;
        while ($lines !== [] && (trim($lines[0]) === '' || str_starts_with(ltrim($lines[0]), '#'))) {
            array_shift($lines);
            $skipped++;
        }
        if ($lines === []) {
            $this->fileError('El fichero está vacío.');
        }

        $delimiter = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, implode("\n", $lines));
        rewind($stream);

        $header = $this->header(fgetcsv($stream, null, $delimiter, '"', '') ?: []);
        $rows = [];
        $number = $skipped + 1;
        $max = (int) config('post_import.max_rows');
        while (($record = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $number++;
            if ($record === [null] || trim(implode('', $record)) === '') {
                continue;
            }
            if (count($rows) >= $max) {
                $this->fileError("El fichero supera el máximo de {$max} filas.");
            }
            $row = ['row' => $number, 'extra' => count($record) > count($header)];
            foreach (self::COLUMNS as $column) {
                $index = array_search($column, $header, true);
                $value = $index === false ? '' : (string) ($record[$index] ?? '');
                $row[$column] = $column === 'texto' ? trim(str_replace(["\r\n", "\r"], "\n", $value)) : trim($value);
            }
            $rows[] = $row;
        }
        fclose($stream);

        if ($rows === []) {
            $this->fileError('El fichero no tiene ninguna fila de publicaciones.');
        }

        return $rows;
    }

    /**
     * @param  array<int, string|null>  $cells
     * @return list<string>
     */
    private function header(array $cells): array
    {
        $header = array_map(fn ($c) => strtr(mb_strtolower(trim((string) $c)), ['ó' => 'o']), $cells);
        $unknown = array_values(array_unique(array_filter($header, fn (string $c) => ! in_array($c, self::COLUMNS, true))));
        if ($unknown !== []) {
            $shown = array_map(fn (string $c) => '«'.mb_strimwidth($c, 0, 30, '…').'»', array_slice($unknown, 0, 5));
            $more = count($unknown) > 5 ? ' y '.(count($unknown) - 5).' más' : '';
            $this->fileError('Columnas desconocidas: '.implode(', ', $shown).$more.'. Las columnas son: '.implode(', ', self::COLUMNS).'.');
        }
        if (count(array_unique($header)) !== count($header)) {
            $this->fileError('Hay columnas repetidas en la cabecera.');
        }
        $missing = array_diff(self::REQUIRED, $header);
        if ($missing !== []) {
            $this->fileError('Falta la columna obligatoria: '.implode(', ', $missing).'.');
        }

        return $header;
    }

    /**
     * Vista previa: cada fila con sus datos interpretados y sus errores. No crea nada.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function preview(User $user, Brand $brand, array $rows): array
    {
        $accounts = $this->accounts->listForBrand($user, $brand);

        return array_map(fn (array $row) => $this->view($this->evaluate($user, $brand, $accounts, $row)), $rows);
    }

    /**
     * Crea las filas validas. Vuelve a validar cada una (no se fia de la vista previa: la fecha puede haber pasado o la cuenta
     * haberse desconectado) y un fallo de una fila no detiene las demas.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  (callable(list<array<string, mixed>>): void)|null  $progress  se llama tras cada fila con los resultados acumulados
     * @return list<array<string, mixed>>
     */
    public function import(User $user, Brand $brand, array $rows, ?callable $progress = null): array
    {
        $accounts = $this->accounts->listForBrand($user, $brand);
        $results = [];
        foreach ($rows as $row) {
            $evaluated = $this->evaluate($user, $brand, $accounts, $row);
            $postId = null;
            if ($evaluated['payload'] !== null) {
                try {
                    $postId = $this->posts->create($user, $brand, $evaluated['payload'])->id;
                } catch (PostValidationException $e) {
                    $evaluated['errors'] = $this->flatten($e->errors());
                } catch (Throwable $e) {
                    report($e);
                    $evaluated['errors'] = ['No se pudo crear la publicación.'];
                }
            }
            $results[] = $this->view($evaluated) + ['post_id' => $postId];
            if ($progress !== null) {
                $progress($results);
            }
        }

        return $results;
    }

    /**
     * CSV de ejemplo con las columnas documentadas (lineas `#`), la leyenda de las cuentas reales de la marca y filas de muestra que SON validas
     * para esas cuentas: cada candidata pasa por la misma validacion que la importacion y solo entra la que no da errores. Sin cuentas o sin
     * ninguna que admita una fila sin media, no hay filas y un comentario lo explica. Se escribe con `CsvWriter`: separador de
     * `reports.csv_locale` y ninguna celda puede empezar por una formula.
     */
    public function sample(User $user, Brand $brand): string
    {
        $accounts = $this->accounts->listForBrand($user, $brand);
        $writer = CsvWriter::forConfiguredLocale();
        $zone = $brand->timezone ?: 'UTC';
        $when = now($zone)->addDay()->format('Y-m-d').' 10:00';

        $valid = fn (SocialAccount $a, string $date) => $this->evaluate($user, $brand, $accounts, ['row' => 0, 'fecha' => $date, 'texto' => 'Ejemplo', 'cuentas' => self::identifier($a), 'tipo' => '', 'medios' => '', 'aprobacion' => ''])['errors'] === [];
        $scheduled = $accounts->first(fn (SocialAccount $a) => $valid($a, $when));
        $drafts = $accounts->filter(fn (SocialAccount $a) => $valid($a, ''))->take(2);

        $out = CsvWriter::BOM;
        $comments = [
            'Importar publicaciones. Las líneas que empiezan por # antes de la cabecera se ignoran: borra estas explicaciones y las filas de muestra.',
            'fecha: hora local de la marca, AAAA-MM-DD HH:MM o DD/MM/AAAA HH:MM. Vacía = borrador.',
            'texto: el texto de la publicación; entre comillas si lleva comas.',
            'cuentas: una o varias cuentas de ESTA marca separadas por |, escritas red:id. Las de esta marca son:',
        ];
        foreach ($accounts as $a) {
            $comments[] = '  '.self::identifier($a).' = '.$a->network->label().': '.mb_strimwidth((string) preg_replace('/[\s;,]+/u', ' ', (string) CsvWriter::cell($a->name)), 0, 60, '…');
        }
        array_push($comments,
            'tipo (opcional): tipos de contenido separados por | (por ejemplo instagram_reel). Si falta, se usa el de cada red por defecto.',
            'medios (opcional): ids de archivos de tu biblioteca separados por |. Algunas redes (Instagram, TikTok, YouTube, Pinterest) los exigen.',
            'aprobacion (opcional): sí o no. Con sí la publicación nace como borrador pendiente de aprobar.',
        );
        if ($accounts->isEmpty()) {
            $comments[] = 'Esta marca no tiene cuentas conectadas: conecta una y vuelve a descargar este ejemplo.';
        } elseif ($scheduled === null && $drafts->isEmpty()) {
            $comments[] = 'Las redes de esta marca exigen archivos: añade los ids de tu biblioteca en medios para que una fila sea válida.';
        }
        foreach ($comments as $comment) {
            $out .= '# '.str_replace(["\r", "\n"], ' ', $comment)."\n";
        }

        $out .= $writer->line(self::COLUMNS);
        if ($scheduled !== null) {
            $out .= $writer->line([$when, 'Texto de ejemplo: una publicación programada.', self::identifier($scheduled), '', '', 'no']);
        }
        if ($drafts->isNotEmpty()) {
            $out .= $writer->line(['', 'Borrador de ejemplo, con una coma entre comillas.', $drafts->map(fn (SocialAccount $a) => self::identifier($a))->implode('|'), '', '', '']);
        }

        return $out;
    }

    /**
     * @param  Collection<int, SocialAccount>  $accounts
     * @param  array<string, mixed>  $row
     * @return array{row: array<string, mixed>, view: array<string, mixed>, errors: list<string>, payload: ?array<string, mixed>}
     */
    private function evaluate(User $user, Brand $brand, Collection $accounts, array $row): array
    {
        $errors = [];
        if ($row['extra'] ?? false) {
            $errors[] = 'La fila tiene más celdas que columnas: revisa las comillas y los separadores.';
        }

        [$wall, $date] = $this->date((string) $row['fecha'], $brand, $errors);
        $targets = $this->targets((string) $row['cuentas'], (string) $row['tipo'], trim((string) $row['medios']) !== '', $accounts, $errors);
        $media = $this->media((string) $row['medios'], $errors);
        $approval = $this->approval((string) $row['aprobacion'], $errors);

        $payload = null;
        if ($errors === []) {
            $payload = [
                'text' => $row['texto'] === '' ? null : (string) $row['texto'],
                'schedule' => $wall !== null,
                'scheduled_at' => $wall,
                'requires_approval' => $approval,
                'media' => $media,
                'targets' => array_map(fn (array $t) => ['account_id' => $t['id'], 'content_type' => $t['content_type']], $targets),
            ];
            $errors = $this->commonErrors($user, $brand, $payload);
            if ($errors !== []) {
                $payload = null;
            }
        }

        return [
            'row' => $row, 'errors' => $errors, 'payload' => $payload,
            'view' => [
                'date' => $date, 'text' => (string) $row['texto'], 'approval' => $approval, 'media' => $media,
                'accounts' => array_map(fn (array $t) => ['id' => $t['id'], 'network' => $t['network'], 'network_label' => $t['network_label'], 'name' => $t['name'], 'content_type' => $t['content_type']], $targets),
            ],
        ];
    }

    /**
     * Las mismas reglas que el compositor: `PostRequest::rules()` y `PostService::validate`.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function commonErrors(User $user, Brand $brand, array $payload): array
    {
        $validator = Validator::make($payload, (new PostRequest)->rules());
        if ($validator->fails()) {
            return array_values(array_unique($validator->errors()->all()));
        }

        return $this->flatten($this->posts->validate($user, $brand, $validator->validated()));
    }

    /**
     * Fecha local de la marca. Formatos: `AAAA-MM-DD HH:MM` (con `T` o espacio) y `DD/MM/AAAA HH:MM` (como lo reescribe Excel en espanol; dia y mes de
     * una o dos cifras), con segundos opcionales si son `00`. Nunca se interpreta `MM/DD`: el primer numero de una fecha con barras es el dia.
     *
     * @param  list<string>  $errors
     * @return array{0: ?string, 1: ?string} valor `AAAA-MM-DDTHH:MM` para el compositor y fecha legible
     */
    private function date(string $value, Brand $brand, array &$errors): array
    {
        if ($value === '') {
            return [null, null];
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{1,2}):(\d{2})(?::(\d{2}))?$/D', $value, $m) === 1) {
            [$year, $month, $day] = [$m[1], $m[2], $m[3]];
        } elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4}) (\d{1,2}):(\d{2})(?::(\d{2}))?$/D', $value, $m) === 1) {
            [$day, $month, $year] = [$m[1], $m[2], $m[3]];
            if ((int) $month > 12) {
                $errors[] = "La fecha «{$value}» tiene el mes {$month}: con barras el formato es DD/MM/AAAA (primero el día, después el mes).";

                return [null, $value];
            }
        } else {
            $errors[] = "La fecha «{$value}» no tiene un formato válido: usa AAAA-MM-DD HH:MM o DD/MM/AAAA HH:MM.";

            return [null, $value];
        }
        [$hour, $minute, $second] = [(int) $m[4], (int) $m[5], (int) ($m[6] ?? 0)];
        if (! checkdate((int) $month, (int) $day, (int) $year) || $hour > 23 || $minute > 59 || $second > 59) {
            $errors[] = "La fecha «{$value}» no existe en el calendario.";

            return [null, $value];
        }
        if ($second !== 0) {
            $errors[] = "La fecha «{$value}» lleva segundos distintos de 00: las publicaciones se programan al minuto.";

            return [null, $value];
        }

        $wall = sprintf('%04d-%02d-%02dT%02d:%02d', $year, $month, $day, $hour, $minute);
        if (LocalDateTime::parseUnique($wall, $brand->timezone ?: 'UTC') === null) {
            $errors[] = "La hora «{$value}» no existe o es ambigua en la zona horaria de la marca (cambio de hora).";

            return [null, $value];
        }

        return [$wall, str_replace('T', ' ', $wall)];
    }

    /**
     * @param  Collection<int, SocialAccount>  $accounts
     * @param  list<string>  $errors
     * @return list<array{id: int, network: string, network_label: string, name: string, content_type: string}>
     */
    private function targets(string $accountsCell, string $typesCell, bool $hasMedia, Collection $accounts, array &$errors): array
    {
        $types = $this->types($typesCell, $errors);
        $targets = [];
        $tokens = array_values(array_filter(array_map('trim', explode('|', $accountsCell)), fn (string $t) => $t !== ''));
        if ($tokens === []) {
            $errors[] = 'Indica al menos una cuenta (red:id, como aparece en la página de importación).';
        }

        foreach ($tokens as $token) {
            $account = $this->account($token, $accounts, $errors);
            if ($account === null || isset($targets[$account->id])) {
                continue;
            }
            $type = $types[$account->network->value] ?? $this->defaultType($account->network, $hasMedia);
            $targets[$account->id] = ['id' => $account->id, 'network' => $account->network->value, 'network_label' => $account->network->label(), 'name' => $account->name, 'content_type' => $type->value];
        }

        $used = array_map(fn (array $t) => $t['network'], $targets);
        foreach (array_diff_key($types, array_flip($used)) as $network => $type) {
            $errors[] = "El tipo «{$type->value}» no corresponde a ninguna cuenta de la fila (no hay cuentas de {$network}).";
        }

        return array_values($targets);
    }

    /**
     * @param  list<string>  $errors
     * @return array<string, ContentType> tipo pedido por red
     */
    private function types(string $cell, array &$errors): array
    {
        $types = [];
        foreach (array_filter(array_map('trim', explode('|', $cell)), fn (string $t) => $t !== '') as $token) {
            $type = ContentType::tryFrom(strtolower($token));
            if ($type === null) {
                $errors[] = "Tipo de contenido desconocido: «{$token}».";
            } elseif (isset($types[$type->network()->value])) {
                $errors[] = 'Hay dos tipos para la misma red: «'.$types[$type->network()->value]->value."» y «{$type->value}».";
            } else {
                $types[$type->network()->value] = $type;
            }
        }

        return $types;
    }

    /**
     * Resuelve `red:id` contra las cuentas de la marca. Solo por id: no hay forma de elegir una cuenta equivocada.
     *
     * @param  Collection<int, SocialAccount>  $accounts
     * @param  list<string>  $errors
     */
    private function account(string $token, Collection $accounts, array &$errors): ?SocialAccount
    {
        if (preg_match('/^([A-Za-z]+)\s*:\s*(\d{1,18})$/D', $token, $m) !== 1) {
            $errors[] = "Cuenta «{$token}» no válida: usa red:id, como aparece en la página de importación.";

            return null;
        }
        $network = Network::tryFrom(strtolower($m[1]));
        $account = $network === null ? null : $accounts->first(fn (SocialAccount $a) => $a->network === $network && $a->id === (int) $m[2]);

        // Una cuenta inexistente, de otra red, de otra marca o de otro usuario dan el mismo mensaje: no se revela si existe en otro sitio.
        if ($account === null) {
            $errors[] = "No hay ninguna cuenta «{$token}» en esta marca.";
        }

        return $account;
    }

    /** Tipo de cada red cuando la columna `tipo` no lo indica. */
    private function defaultType(Network $network, bool $hasMedia): ContentType
    {
        return match ($network) {
            Network::Instagram => ContentType::InstagramImage,
            Network::Facebook => $hasMedia ? ContentType::FacebookPhoto : ContentType::FacebookText,
            Network::YouTube => ContentType::YouTubeVideo,
            Network::TikTok => ContentType::TikTokVideo,
            Network::Bluesky => ContentType::BlueskyPost,
            Network::Threads => $hasMedia ? ContentType::ThreadsImage : ContentType::ThreadsText,
            Network::LinkedIn => $hasMedia ? ContentType::LinkedInImage : ContentType::LinkedInText,
            Network::Pinterest => ContentType::PinterestPin,
            Network::Mastodon => ContentType::MastodonPost,
            Network::Telegram => $hasMedia ? ContentType::TelegramPhoto : ContentType::TelegramText,
        };
    }

    /**
     * Ids de la biblioteca; que sean del usuario lo comprueba el planner como en el compositor.
     *
     * @param  list<string>  $errors
     * @return list<int>
     */
    private function media(string $cell, array &$errors): array
    {
        $ids = [];
        foreach (array_filter(array_map('trim', explode('|', $cell)), fn (string $t) => $t !== '') as $token) {
            if (preg_match('/^[1-9]\d{0,17}$/D', $token) !== 1) {
                $errors[] = "El identificador de archivo «{$token}» no es válido.";

                continue;
            }
            $ids[] = (int) $token;
        }

        return array_values(array_unique($ids));
    }

    /** @param  list<string>  $errors */
    private function approval(string $cell, array &$errors): bool
    {
        $value = mb_strtolower($cell);
        if (in_array($value, self::NO, true)) {
            return false;
        }
        if (in_array($value, self::YES, true)) {
            return true;
        }
        $errors[] = "El valor de aprobación «{$cell}» no es válido: usa sí o no.";

        return false;
    }

    /**
     * @param  array{row: array<string, mixed>, view: array<string, mixed>, errors: list<string>, payload: ?array<string, mixed>}  $evaluated
     * @return array<string, mixed>
     */
    private function view(array $evaluated): array
    {
        return ['row' => $evaluated['row']['row'], 'valid' => $evaluated['errors'] === [], 'errors' => $evaluated['errors']] + $evaluated['view'];
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return list<string>
     */
    private function flatten(array $errors): array
    {
        return array_values(array_unique(array_merge(...array_values($errors ?: [[]]))));
    }

    private function fileError(string $message): never
    {
        throw ValidationException::withMessages(['file' => [$message]]);
    }
}
