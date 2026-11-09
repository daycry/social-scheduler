<?php

namespace App\Services\Studio;

use App\Models\Brand;
use App\Models\Studio\StudioDataset;
use App\Models\Studio\StudioDatasetRevision;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class StudioChartFiles
{
    public function materialize(User $user, Brand $brand, array $document, string $directory): array
    {
        abort_unless(Brand::withoutUserScope()->where('user_id', $user->id)->whereKey($brand->id)->exists(), 404);
        abort_unless(preg_match('~^studio-exports/'.(int) $user->id.'/[1-9][0-9]*/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$~D', $directory), 422);
        $refs = [];
        $walk = function (array $elements) use (&$walk, &$refs): void {
            foreach ($elements as $element) {
                if (($element['type'] ?? null) === 'group') {
                    $walk($element['elements']);
                }
                if (($element['type'] ?? null) !== 'chart') {
                    continue;
                }
                $ref = $element['chart']['binding']['dataset'] ?? [];
                abort_unless(is_int($ref['id'] ?? null) && $ref['id'] > 0 && is_int($ref['revision'] ?? null) && $ref['revision'] > 0 && is_string($ref['sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $ref['sha256']), 422);
                $refs[$ref['id'].':'.$ref['revision'].':'.$ref['sha256']] = $ref;
                abort_if(count($refs) > 32, 422);
            }
        };
        foreach ($document['pages'] as $page) {
            $walk($page['elements']);
        }
        if ($refs === []) {
            return [];
        }
        $ids = array_unique(array_column($refs, 'id'));
        $owned = StudioDataset::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->whereIn('id', $ids)->pluck('id')->all();
        abort_unless(count($owned) === count($ids), 404);
        $revisions = StudioDatasetRevision::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->whereIn('dataset_id', $ids)->whereIn('revision', array_unique(array_column($refs, 'revision')))->get()->keyBy(fn ($r) => $r->dataset_id.':'.$r->revision);
        $selected = [];
        $total = 0;
        foreach ($refs as $ref) {
            $revision = $revisions->get($ref['id'].':'.$ref['revision']);
            abort_unless($revision, 404);
            abort_unless(hash_equals($revision->sha256, $ref['sha256']), 422);
            abort_unless($revision->json_bytes >= 1 && $revision->json_bytes <= 52000000, 422);
            $total += $revision->json_bytes;
            abort_if($total > 64000000, 422);
            abort_unless(preg_match('~^studio-datasets/'.(int) $user->id.'/'.(int) $brand->id.'/[a-f0-9-]{36}\.json$~D', $revision->path), 503);
            $selected[] = [$ref, $revision];
        }
        $disk = Storage::disk('local');
        $created = [];
        $descriptors = [];
        try {
            // Local roots and each existing ancestor must be real directories, never symlinks.
            $root = $disk->path('');
            if (! is_dir($root) && ! mkdir($root, 0700, true)) {
                throw new \RuntimeException;
            }
            $root = realpath($root);
            if ($root === false || is_link($disk->path(''))) {
                throw new \RuntimeException;
            }
            $target = $root;
            foreach (explode('/', $directory.'/datasets') as $part) {
                $target .= DIRECTORY_SEPARATOR.$part;
                if (is_link($target) || (file_exists($target) && ! is_dir($target))) {
                    throw new \RuntimeException;
                }
                if (! is_dir($target) && ! mkdir($target, 0700)) {
                    throw new \RuntimeException;
                }
                if (realpath($target) !== $target) {
                    throw new \RuntimeException;
                }
            }
            foreach ($selected as [$ref, $revision]) {
                if ($disk->size($revision->path) !== $revision->json_bytes) {
                    throw new \RuntimeException;
                }
                $sourcePath = $root;
                foreach (explode('/', $revision->path) as $part) {
                    $sourcePath .= DIRECTORY_SEPARATOR.$part;
                    if (is_link($sourcePath)) {
                        throw new \RuntimeException;
                    }
                }
                $name = 'datasets/'.$ref['id'].'-'.$ref['revision'].'-'.$ref['sha256'].'.json';
                $final = $target.DIRECTORY_SEPARATOR.basename($name);
                $temp = $target.DIRECTORY_SEPARATOR.'.'.bin2hex(random_bytes(16)).'.tmp';
                $out = fopen($temp, 'x+b');
                if ($out === false) {
                    throw new \RuntimeException;
                }
                $created[] = $temp;
                $in = null;
                try {
                    $in = $disk->readStream($revision->path);
                    if (! is_resource($in)) {
                        throw new \RuntimeException;
                    }
                    $hash = hash_init('sha256');
                    $bytes = 0;
                    while (! feof($in)) {
                        $chunk = fread($in, 65536);
                        if ($chunk === false || ($chunk === '' && ! feof($in))) {
                            throw new \RuntimeException;
                        }
                        $bytes += strlen($chunk);
                        if ($bytes > $revision->json_bytes || fwrite($out, $chunk) !== strlen($chunk)) {
                            throw new \RuntimeException;
                        }
                        hash_update($hash, $chunk);
                    }
                    if ($bytes !== $revision->json_bytes || ! hash_equals($ref['sha256'], hash_final($hash))) {
                        throw new \RuntimeException;
                    }
                    rewind($out);
                    $published = @fopen($final, 'x+b');
                    if ($published === false) {
                        throw new \RuntimeException;
                    }
                    $created[] = $final;
                    try {
                        if (stream_copy_to_stream($out, $published) !== $bytes) {
                            throw new \RuntimeException;
                        }
                    } finally {
                        fclose($published);
                    }
                } finally {
                    if (is_resource($in)) {
                        fclose($in);
                    }
                    fclose($out);
                }
                unlink($temp);
                $descriptors[] = $ref + ['bytes' => $revision->json_bytes, 'name' => $name];
            }

            return $descriptors;
        } catch (Throwable) {
            foreach (array_reverse($created) as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            abort(503, 'Dataset snapshot unavailable.');
        }
    }
}
