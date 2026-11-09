<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Services\Studio\StudioDatasetActions;
use App\Services\Studio\StudioDatasetInput;
use Illuminate\Http\Request;

final class StudioDatasetController extends Controller
{
    public function handle(Request $request)
    {
        $brand = $request->route('brand');
        $dataset = $request->route('dataset');
        $action = $request->route('dataset_action');
        $raw = $request->isJson() ? $request->getContent() : '';
        $emptyBody = $raw === '[]' && ($request->isMethod('GET') || in_array($action, ['archive', 'restore'], true));
        $input = $request->isJson() ? StudioDatasetInput::objectJson($emptyBody ? '{}' : ($raw ?: '{}'), 'body') : $request->all();
        $input = array_replace($request->query(), $input);
        if (! $request->is('api/*')) {
            unset($input['_token']);
        }
        if ($request->isJson() && $action === 'import') {
            StudioDatasetInput::fail('file', 'Import requires a multipart upload.');
        }
        if ($action === 'import') {
            StudioDatasetInput::keys($input, $dataset === null ? ['name', 'format', 'header', 'file'] : ['name', 'format', 'header', 'file', 'revision']);
        }
        if ($action === 'export') {
            $export = app(StudioDatasetActions::class)->export($request->user()->id, (int) $brand, (int) $dataset, $input);
            $mime = match ($export['format']) {
                'csv' => 'text/csv', 'tsv' => 'text/tab-separated-values', 'json' => 'application/json'
            };
            $headers = ['Content-Type' => $mime.'; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
            if ($export['format'] === 'json') {
                $headers['Content-Length'] = (string) $export['json_bytes'];
            }

            return response()->streamDownload(function () use ($export) {
                foreach ($export['chunks'] as $chunk) {
                    echo $chunk;
                }
            }, 'dataset-'.$dataset.'-r'.$export['revision'].'.'.$export['format'], $headers);
        }
        $result = app(StudioDatasetActions::class)->execute($request->user()->id, (int) $brand, $action, $dataset === null ? null : (int) $dataset, $input);

        return response()->json($result, $action === 'create' || ($action === 'import' && $dataset === null) ? 201 : 200, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'], StudioDatasetInput::JSON_FLAGS);
    }
}
