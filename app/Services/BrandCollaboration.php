<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\BrandCollaborator;
use App\Models\User;
use App\Notifications\PostWorkflowActivity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Colaboradores invitados por marca (T-19), con el patron de `StudioCollab`: invitacion por email a un usuario ya registrado,
 * respuesta uniforme (nada distingue un email registrado de uno que no lo esta) y revocable. Solo el propietario de la marca gestiona.
 */
class BrandCollaboration
{
    public const INVITE_MESSAGE = 'Si existe una cuenta con ese email, podra aprobar las publicaciones de esta marca.';

    public const ROLES = [BrandCollaborator::ROLE_APPROVER];

    /** @return list<array<string, mixed>> */
    public function list(User $actor, Brand $brand): array
    {
        $this->assertOwner($actor, $brand);

        return BrandCollaborator::query()->where('brand_id', $brand->id)->with('guest:id,name,email')->orderBy('id')->get()
            ->map(fn (BrandCollaborator $c) => $this->present($c))->all();
    }

    /** Devuelve null (y no crea nada) si el email no existe o es el del propio propietario: la respuesta al cliente es siempre la misma. */
    public function invite(User $actor, Brand $brand, mixed $email, mixed $role = BrandCollaborator::ROLE_APPROVER): ?BrandCollaborator
    {
        $this->assertOwner($actor, $brand);
        ServiceThrottle::hit($actor, 'brand-collaborators');
        $data = validator(['email' => $email, 'role' => $role ?: BrandCollaborator::ROLE_APPROVER], [
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'in:'.implode(',', self::ROLES)],
        ], ['email.required' => 'Indica el email de la persona.', 'email.email' => 'Indica un email valido.', 'role.in' => 'El rol debe ser aprobador.'])->validate();

        $guest = User::query()->where('email', Str::lower(trim($data['email'])))->first();
        if ($guest === null || $guest->id === $actor->id) {
            return null;
        }

        return DB::transaction(function () use ($actor, $brand, $guest, $data): BrandCollaborator {
            Brand::withoutUserScope()->whereKey($brand->id)->lockForUpdate()->first();
            $existing = BrandCollaborator::query()->where('brand_id', $brand->id)->where('user_id', $guest->id)->lockForUpdate()->first();
            if ($existing === null && BrandCollaborator::query()->where('brand_id', $brand->id)->count() >= BrandCollaborator::MAX_PER_BRAND) {
                throw ValidationException::withMessages(['email' => ['Esta marca ya tiene el maximo de colaboradores.']]);
            }
            $collab = $existing ?? new BrandCollaborator(['brand_id' => $brand->id, 'owner_id' => $actor->id, 'user_id' => $guest->id]);
            $isNew = $existing === null;
            $collab->role = $data['role'];
            $collab->save();
            if ($isNew) {
                $guest->notify(new PostWorkflowActivity('collaborator_invited', null, $brand->id, $brand->name, $actor->name.' te ha invitado a aprobar las publicaciones de «'.$brand->name.'».'));
            }

            return $collab->load('guest:id,name,email');
        });
    }

    public function revoke(User $actor, Brand $brand, int $collaboratorId): void
    {
        $this->assertOwner($actor, $brand);
        $collab = BrandCollaborator::query()->where('brand_id', $brand->id)->findOrFail($collaboratorId);
        $collab->delete();
        User::query()->find($collab->user_id)?->notify(new PostWorkflowActivity('collaborator_revoked', null, $brand->id, $brand->name, 'Ya no puedes aprobar las publicaciones de «'.$brand->name.'».'));
    }

    public function isApprover(User $user, int $brandId): bool
    {
        return BrandCollaborator::query()->where('brand_id', $brandId)->where('user_id', $user->id)->where('role', BrandCollaborator::ROLE_APPROVER)->exists();
    }

    /** @return list<int> marcas de otros usuarios en las que el usuario es aprobador */
    public function approverBrandIds(User $user): array
    {
        return BrandCollaborator::query()->where('user_id', $user->id)->where('role', BrandCollaborator::ROLE_APPROVER)->pluck('brand_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<User> aprobadores invitados de la marca (el propietario aprueba por derecho propio y no se incluye) */
    public function approvers(int $brandId): array
    {
        return User::query()->whereIn('id', BrandCollaborator::query()->where('brand_id', $brandId)->where('role', BrandCollaborator::ROLE_APPROVER)->select('user_id'))->get()->all();
    }

    /** @return array<string, mixed> */
    public function present(BrandCollaborator $c): array
    {
        return ['id' => $c->id, 'brand_id' => $c->brand_id, 'role' => $c->role, 'name' => $c->guest?->name, 'email' => $c->guest?->email];
    }

    private function assertOwner(User $actor, Brand $brand): void
    {
        abort_unless((int) $brand->user_id === (int) $actor->id, 404);
    }
}
