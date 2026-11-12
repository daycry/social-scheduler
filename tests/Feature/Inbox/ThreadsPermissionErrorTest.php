<?php

use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Threads\ThreadsComments;
use App\Exceptions\PostValidationException;
use App\Models\InboxComment;
use App\Services\InboxService;
use Illuminate\Support\Facades\Http;
use Tests\Support\ThreadsFake;

/** T-22 · si el usuario quita los permisos de respuesta en Threads, la cuenta pasa a «reconecta» y pierde ese scope. */
beforeEach(function () {
    Http::preventStrayRequests();
    $this->account = ThreadsFake::account(['scopes' => ['threads_basic', 'threads_content_publish', 'threads_read_replies', 'threads_manage_replies']]);
    $this->denied = Http::response(['error' => ['message' => '(#10) Application does not have permission for this action', 'code' => 10]], 403);
});

test('leer sin el permiso de lectura retira threads_read_replies y pide reconectar', function () {
    Http::fake(['graph.threads.com/*' => $this->denied]);

    expect(fn () => app(ThreadsComments::class)->fetch($this->account, '1800'))->toThrow(ReconnectRequiredException::class);
    expect($this->account->fresh()->scopes)->not->toContain('threads_read_replies')->toContain('threads_manage_replies');
});

test('responder sin el permiso de respuestas retira threads_manage_replies y pide reconectar', function () {
    Http::fake(['graph.threads.com/*' => $this->denied]);

    expect(fn () => app(ThreadsComments::class)->reply($this->account, 't1', 'hola'))->toThrow(ReconnectRequiredException::class);
    expect($this->account->fresh()->scopes)->not->toContain('threads_manage_replies')->toContain('threads_read_replies');
});

test('desde el inbox un permiso opcional rechazado retira el scope y NO marca la cuenta (T-24 revision)', function () {
    Http::fake(['graph.threads.com/*' => $this->denied]);
    $c = InboxComment::factory()->create(['user_id' => $this->account->user_id, 'social_account_id' => $this->account->id, 'network' => $this->account->network]);

    expect(fn () => app(InboxService::class)->reply($this->account->user, $c, 'hola'))->toThrow(PostValidationException::class);

    $fresh = $this->account->fresh();
    expect($fresh->status)->toBe('activa')->and($fresh->scopes)->not->toContain('threads_manage_replies');
});

test('un error que no es de permisos no toca los scopes', function () {
    Http::fake(['graph.threads.com/*' => Http::response(['error' => ['message' => 'boom', 'code' => 1]], 500)]);

    expect(fn () => app(ThreadsComments::class)->fetch($this->account, '1800'))->toThrow(TransientPublishException::class);
    expect($this->account->fresh()->scopes)->toContain('threads_read_replies');
});
