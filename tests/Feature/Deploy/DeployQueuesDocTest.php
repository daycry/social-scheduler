<?php

/**
 * Auditoria A-06: cada cola que usa el codigo de app/ debe tener su worker documentado en Hestia y en systemd,
 * para que no vuelva a quedar una cola (media, studio...) sin nadie que la procese.
 */

/**
 * Colas que usa el codigo de `app/`: `onQueue(...)`, `push(..., queue: ...)` y todo `ShouldQueue` sin cola explicita (= `default`).
 *
 * @return list<string>
 */
function queuesUsedByApp(): array
{
    $queues = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    $named = "(?:\(string\)\s*)?(?:config\(\s*'([^']+)'\s*,\s*'([^']+)'\s*\)|'([^']+)')";

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $code = file_get_contents($file->getPathname());
        $declared = false;

        // onQueue(config('clave', 'defecto')) / push(..., queue: config(...)) -> valor efectivo; literal -> literal.
        foreach (["/onQueue\(\s*$named/", "/\bqueue:\s*$named/"] as $regex) {
            preg_match_all($regex, $code, $m, PREG_SET_ORDER);
            foreach ($m as $hit) {
                $declared = true;
                $queues[] = ($hit[1] ?? '') !== '' ? (string) config($hit[1], $hit[2]) : $hit[3];
            }
        }

        // QueueProbeJob no fija cola: la elige `app:queue-probe` al despacharlo (una por cola real), no cae en `default`.
        if (basename($file->getPathname()) === 'QueueProbeJob.php') {
            continue;
        }

        if (! $declared && preg_match('/implements[^{]*\bShouldQueue\b/', $code)) {
            $queues[] = (string) config('queue.connections.database.queue', 'default');
        }
    }

    $queues = array_values(array_unique($queues));
    sort($queues);

    return $queues;
}

function deployDoc(string $path): string
{
    return (string) file_get_contents(base_path($path));
}

/** Una cola aparece como `--queue=<q>` (o en una lista `--queue=a,<q>`). */
function mentionsQueueWorker(string $doc, string $queue): bool
{
    return (bool) preg_match('/--queue=(?:[a-z_]+,)*'.preg_quote($queue, '/').'(?![a-z_])/', $doc);
}

test('el codigo usa las colas esperadas (el escaner no es vacuo)', function () {
    expect(queuesUsedByApp())->toContain('publish', 'poll', 'metrics', 'reports', 'media', 'studio');
});

test('cada cola usada por el codigo tiene un worker documentado en Hestia', function () {
    $hestia = deployDoc('deploy/hestia/README.md');

    foreach (queuesUsedByApp() as $queue) {
        expect(mentionsQueueWorker($hestia, $queue))->toBeTrue("deploy/hestia/README.md no documenta un worker para la cola `$queue`");
    }

    // `studio` no cabe en el cron: Hestia debe traer el paso de instalacion de su unidad, no solo mencionarla.
    expect($hestia)->toContain('systemctl enable --now scheduler-studio');
});

test('cada cola usada por el codigo tiene un worker documentado en systemd', function () {
    $systemd = deployDoc('deploy/systemd/README.md');

    foreach (queuesUsedByApp() as $queue) {
        $documented = mentionsQueueWorker($systemd, $queue) || str_contains($systemd, "scheduler-worker@$queue");
        expect($documented)->toBeTrue("deploy/systemd/README.md no documenta un worker para la cola `$queue`");
    }
});

test('systemd incluye scheduler-worker@media y la unidad de studio existe', function () {
    expect(deployDoc('deploy/systemd/README.md'))->toContain('scheduler-worker@media');
    expect(file_exists(base_path('deploy/systemd/scheduler-studio.service')))->toBeTrue();
    expect(deployDoc('deploy/systemd/scheduler-studio.service'))->toContain('--queue=studio');
});
