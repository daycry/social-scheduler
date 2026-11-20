<?php

namespace App\Services\Webhooks;

use App\Models\Brand;
use App\Models\User;
use App\Models\Webhook;
use App\Services\Media\UnsafeUrlException;
use App\Support\Ownership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Suscripciones de una marca propia. URL y secreto son inmutables: cambiar receptor requiere un alta nueva. */
final class WebhookSubscriptions
{
    public function __construct(private readonly WebhookTransport $transport) {}

    /** @param array<string, mixed> $data */
    public function create(User $user, Brand $brand, array $data): Webhook
    {
        Ownership::assert($brand, $user);
        $valid = $this->validated($data, creating: true);
        try {
            $url = $this->transport->prepare($valid['url']);
        } catch (UnsafeUrlException $error) {
            throw ValidationException::withMessages(['url' => $error->getMessage()]);
        }
        $valid['url'] = $url->url;

        return DB::transaction(function () use ($user, $brand, $valid): Webhook {
            // El lock del usuario también limita altas simultáneas en marcas distintas.
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $limit = self::limit();
            if (Webhook::ownedBy($user->id)->count() >= $limit) {
                throw ValidationException::withMessages(['webhook' => 'Se ha alcanzado el límite de '.$limit.' webhooks de la cuenta.']);
            }
            $hook = new Webhook($valid);
            $hook->user_id = $user->id;
            $hook->brand_id = $brand->id;
            $hook->save();

            return $hook->fresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $user, Webhook $hook, array $data): void
    {
        $this->assertOwner($user, $hook);
        $hook->fill($this->validated($data, creating: false))->save();
    }

    public function delete(User $user, Webhook $hook): void
    {
        $this->assertOwner($user, $hook);
        $hook->delete();
    }

    private function assertOwner(User $user, Webhook $hook): void
    {
        Ownership::assert($hook, $user);
        Ownership::assert(Brand::ownedBy($user->id)->find($hook->brand_id), $user);
    }

    public static function limit(): int
    {
        return max(1, (int) config('webhooks.max_per_user', 20));
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validated(array $data, bool $creating): array
    {
        $valid = Validator::make($data, [
            'name' => ['required', 'string', 'max:120', 'not_regex:/[\p{C}]/u'],
            'url' => $creating ? ['required', 'string', 'max:2048'] : ['prohibited'],
            'secret' => $creating ? ['required', 'string', 'regex:/\A[A-Za-z0-9_-]{32,128}\z/'] : ['prohibited'],
            'events' => ['required', 'array', 'min:1', 'max:'.count(Webhook::EVENTS)],
            'events.*' => ['required', 'string', 'distinct', Rule::in(array_keys(Webhook::EVENTS))],
            'enabled' => ['sometimes', 'boolean'],
        ])->validate();
        $valid['name'] = trim($valid['name']);

        return $valid;
    }
}
