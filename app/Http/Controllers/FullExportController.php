<?php

namespace App\Http\Controllers;

use App\Jobs\BuildFullExport;
use App\Models\DataExport;
use App\Services\AuditLog;
use App\Services\Export\ExportInventory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportación completa de los datos del usuario en un ZIP (T-84, F-10). Se pide desde el perfil, se genera en cola (`BuildFullExport`) y se
 * descarga con un enlace firmado y de vida corta que además exige la sesión del propio usuario. Solo una exportación en curso por usuario.
 */
class FullExportController extends Controller
{
    public function index(Request $request): Response
    {
        $latest = DataExport::ownedBy($request->user())->latest('id')->first();
        // La copia lista más reciente sigue disponible mientras se prepara otra nueva.
        $download = DataExport::ownedBy($request->user())->where('status', DataExport::STATUS_READY)->latest('id')->get()->first(fn (DataExport $export) => $export->isDownloadable());

        return Inertia::render('Profile/Export', [
            'latest' => $latest === null ? null : [
                'status' => $latest->status,
                'error' => $latest->error,
                'include_media' => $latest->include_media,
                'created_at' => $latest->created_at->toIso8601String(),
            ],
            'download' => $download === null ? null : [
                'url' => route('profile.export.link', $download),
                'size' => $download->size,
                'file_count' => $download->file_count,
                'include_media' => $download->include_media,
                'expires_at' => $download->expires_at->toIso8601String(),
            ],
            'excluded' => ExportInventory::EXCLUDED,
            'limits' => [
                'max_gb' => round((int) config('exports.max_bytes') / 1_073_741_824, 1),
                'retention_hours' => (int) config('exports.retention_hours'),
                'link_minutes' => (int) config('exports.link_minutes'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['include_media' => ['sometimes', 'boolean']]);
        $user = $request->user();
        if (DataExport::ownedBy($user)->where('in_progress', true)->exists()) {
            return to_route('profile.export')->with('error', 'Ya tienes una exportación en curso. Espera a que termine.');
        }
        // Las fallidas y caducadas se limpian; una copia lista se conserva hasta que la nueva se publique bien (`FullExport::run`).
        DataExport::ownedBy($user)->where('status', '!=', DataExport::STATUS_READY)->delete();
        try {
            $export = DataExport::create(['user_id' => $user->id, 'status' => DataExport::STATUS_PENDING, 'in_progress' => true, 'include_media' => $data['include_media'] ?? true]);
        } catch (UniqueConstraintViolationException) {
            return to_route('profile.export')->with('error', 'Ya tienes una exportación en curso. Espera a que termine.');
        }
        BuildFullExport::dispatch($export->id);

        return to_route('profile.export')->with('success', 'Estamos preparando tu exportación. Te avisaremos cuando esté lista.');
    }

    /** Genera un enlace firmado nuevo al pulsar Descargar: el botón nunca lleva un enlace caducado. */
    public function link(DataExport $export): RedirectResponse
    {
        abort_unless($export->isDownloadable(), 404);

        return redirect()->to($export->signedDownloadUrl());
    }

    /** La firma cubre el id y el dueño; además la sesión debe ser la de ese dueño, así que un enlace filtrado no sirve a nadie más. */
    public function download(Request $request, int $export, int $owner, AuditLog $audit): StreamedResponse
    {
        abort_unless($request->user()->id === $owner, 403);
        $record = DataExport::ownedBy($owner)->findOrFail($export);
        abort_unless($record->isDownloadable() && DataExport::disk()->exists($record->path), 404);

        $audit->record($request->user(), 'full_export_download', AuditLog::OK, 'full_export', $record->id, null, 'web');

        return DataExport::disk()->download($record->path, 'exportacion-'.now()->format('Y-m-d').'.zip', [
            'Content-Type' => 'application/zip', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }
}
