<?php

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\ConnectionExpiring;
use App\Services\ConnectionExpiryNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/** T-23 · aviso de caducidad de conexion para cualquier red con caducidad conocida: una sola vez por umbral y por fecha, correo solo si el usuario lo pide. */
beforeEach(function () {
    Carbon::setTestNow('2026-11-12 09:00:00');
    config(['social.expiry_notice_days' => [7, 1]]);
    $this->user = User::factory()->create();
    $this->brand = Brand::factory()->for($this->user)->create();
});

function expiryAccount(object $t, Network $network, array $over = []): SocialAccount
{
    return SocialAccount::factory()->for($t->user)->create($over + ['brand_id' => $t->brand->id, 'network' => $network, 'name' => 'Cuenta '.$network->value, 'refresh_token' => null]);
}

function expiryNotices(User $user)
{
    return $user->notifications()->where('data->type', 'connection_expiring')->get();
}

test('Meta avisa cuando el acceso a datos de 90 dias esta a punto de caducar, con enlace a Conexiones', function () {
    $a = expiryAccount($this, Network::Instagram, ['data_access_expires_at' => now()->addDays(5), 'token_expires_at' => null]);

    expect(app(ConnectionExpiryNotifier::class)->run())->toBe(1);

    $n = expiryNotices($this->user);
    expect($n)->toHaveCount(1)
        ->and($n[0]->data['social_account_id'])->toBe($a->id)
        ->and($n[0]->data['network_label'])->toBe('Instagram')
        ->and($n[0]->data['brand_id'])->toBe($this->brand->id)
        ->and($n[0]->data['message'])->toContain('acceso a datos')
        ->and($n[0]->data['url'])->toBe(route('brands.connections.index', $this->brand, false));
});

test('LinkedIn sin refresh_token avisa por su token; con refresh_token no (se renueva solo)', function () {
    expiryAccount($this, Network::LinkedIn, ['token_expires_at' => now()->addDays(6)]);
    expiryAccount($this, Network::LinkedIn, ['token_expires_at' => now()->addDays(6), 'refresh_token' => 'RT', 'external_id' => 'li-2']);

    expect(app(ConnectionExpiryNotifier::class)->run())->toBe(1);
    expect(expiryNotices($this->user))->toHaveCount(1);
});

test('una sola vez por umbral: repetir la pasada no duplica y el umbral de 1 dia avisa de nuevo', function () {
    expiryAccount($this, Network::Facebook, ['data_access_expires_at' => now()->addDays(6)]);
    $notifier = app(ConnectionExpiryNotifier::class);

    expect($notifier->run())->toBe(1)->and($notifier->run())->toBe(0);
    Carbon::setTestNow(now()->addDays(3));
    expect($notifier->run())->toBe(0); // quedan 3 dias: sigue en el umbral de 7
    Carbon::setTestNow(now()->addDays(2)->addHours(6)); // quedan menos de 24 h
    expect($notifier->run())->toBe(1)->and($notifier->run())->toBe(0);
    expect(expiryNotices($this->user))->toHaveCount(2);
});

test('al reconectar (nueva fecha) vuelve a avisar; lejos, caducadas o desconectadas no avisan', function () {
    $a = expiryAccount($this, Network::Facebook, ['data_access_expires_at' => now()->addDays(6)]);
    expiryAccount($this, Network::Instagram, ['data_access_expires_at' => now()->addDays(60), 'external_id' => 'far']);
    expiryAccount($this, Network::Threads, ['data_access_expires_at' => now()->subDay(), 'external_id' => 'past']);
    expiryAccount($this, Network::TikTok, ['data_access_expires_at' => now()->addDays(2), 'status' => SocialAccount::STATUS_DISCONNECTED, 'external_id' => 'gone']);
    $notifier = app(ConnectionExpiryNotifier::class);

    expect($notifier->run())->toBe(1);
    $a->forceFill(['data_access_expires_at' => now()->addDays(90)])->save(); // reconectada
    expect($notifier->run())->toBe(0);
    $a->forceFill(['data_access_expires_at' => now()->addDays(5)])->save();
    expect($notifier->run())->toBe(1);
});

test('el correo es opcional: solo viaja si el usuario lo ha activado', function () {
    Notification::fake();
    expiryAccount($this, Network::Facebook, ['data_access_expires_at' => now()->addDays(5)]);
    app(ConnectionExpiryNotifier::class)->run();
    Notification::assertSentTo($this->user, ConnectionExpiring::class, fn ($n) => $n->via($this->user) === ['database']);

    Notification::fake();
    $this->user->forceFill(['notify_by_mail' => true])->save();
    SocialAccount::withoutUserScope()->update(['meta' => null]);
    app(ConnectionExpiryNotifier::class)->run();
    Notification::assertSentTo($this->user, ConnectionExpiring::class, function ($n) {
        $mail = $n->toMail($this->user);

        return $n->via($this->user) === ['database', 'mail'] && str_contains($mail->subject, 'Facebook') && str_contains($mail->actionUrl, '/connections');
    });
});

test('el comando programado ejecuta la pasada', function () {
    expiryAccount($this, Network::Facebook, ['data_access_expires_at' => now()->addDays(5)]);

    $this->artisan('connections:notify-expiry')->assertSuccessful();

    expect(expiryNotices($this->user))->toHaveCount(1);
});

test('Conexiones marca «reconecta antes de» en cualquier red con caducidad, no solo LinkedIn', function () {
    $a = expiryAccount($this, Network::Instagram, ['data_access_expires_at' => now()->addDays(5)]);

    $this->actingAs($this->user)->get(route('brands.connections.index', $this->brand))->assertInertia(fn ($page) => $page
        ->where('accounts.0.id', $a->id)->where('accounts.0.reconnect_reason', 'data_access')->whereNot('accounts.0.reconnect_before', null));
});
