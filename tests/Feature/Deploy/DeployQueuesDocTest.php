<?php

/**
 * Auditoria A-06: cada cola que usan los jobs debe tener su worker documentado en Hestia y en systemd,
 * para que no vuelva a quedar una cola (media, studio...) sin nadie que la procese.
 */

/** @return list<string> */
function queuesUsedByJobs(): array
{
    $queues = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Jobs'), FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $code = file_get_contents($file->getPathname());

        // onQueue(config('clave', 'defecto')) -> valor efectivo de la config; onQueue('literal') -> literal.
        if (preg_match_all("/onQueue\(\s*(?:\(string\)\s*)?config\(\s*'([^']+)'\s*,\s*'([^']+)'\s*\)/", $code, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $queues[] = (string) config($hit[1], $hit[2]);
            }
        }
        if (preg_match_all("/onQueue\(\s*'([^']+)'\s*\)/", $code, $m)) {
            array_push($queues, ...$m[1]);
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

test('los jobs usan las colas esperadas (el escaner no es vacuo)', function () {
    expect(queuesUsedByJobs())->toContain('publish', 'poll', 'metrics', 'reports', 'media', 'studio');
});

test('cada cola usada por los jobs tiene un worker documentado en Hestia', function () {
    $hestia = deployDoc('deploy/hestia/README.md');

    foreach (queuesUsedByJobs() as $queue) {
        expect(mentionsQueueWorker($hestia, $queue))->toBeTrue("deploy/hestia/README.md no documenta un worker para la cola `$queue`");
    }
});

test('cada cola usada por los jobs tiene un worker documentado en systemd', function () {
    $systemd = deployDoc('deploy/systemd/README.md');

    foreach (queuesUsedByJobs() as $queue) {
        $documented = mentionsQueueWorker($systemd, $queue) || str_contains($systemd, "scheduler-worker@$queue");
        expect($documented)->toBeTrue("deploy/systemd/README.md no documenta un worker para la cola `$queue`");
    }
});

test('systemd incluye scheduler-worker@media y la unidad de studio existe', function () {
    expect(deployDoc('deploy/systemd/README.md'))->toContain('scheduler-worker@media');
    expect(file_exists(base_path('deploy/systemd/scheduler-studio.service')))->toBeTrue();
    expect(deployDoc('deploy/systemd/scheduler-studio.service'))->toContain('--queue=studio');
});
