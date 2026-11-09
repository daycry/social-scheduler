<?php

namespace App\Services\Studio;

use App\Models\Studio\Design;
use App\Models\Studio\DesignComment;
use App\Models\Studio\DesignLink;
use App\Models\Studio\DesignShare;
use App\Models\User;
use App\Notifications\StudioDesignActivity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Colaboración sobre un diseño: roles por usuario registrado, enlaces públicos revocables y comentarios.
 * Toda comprobación de acceso pasa por aquí: sin acceso responde 404 (no revela que el diseño existe) y con acceso
 * insuficiente 403.
 */
class StudioCollab
{
    public const ROLES = ['reader', 'commenter', 'editor'];

    public const LINK_ROLES = ['reader', 'commenter'];

    public const LABELS = ['owner' => 'Propietario', 'editor' => 'Editor', 'commenter' => 'Comentarista', 'reader' => 'Lector'];

    private const RANK = ['reader' => 1, 'commenter' => 2, 'editor' => 3, 'owner' => 4];

    private const NEEDS = ['view' => 1, 'comment' => 2, 'edit' => 3, 'manage' => 4];

    public const MAX_LINKS = 20;

    public const MAX_SHARES = 50;

    public function role(User $user, Design $design): ?string
    {
        if ((int) $design->user_id === (int) $user->id) {
            return 'owner';
        }
        if ($design->trashed()) {
            return null;
        }

        return DesignShare::query()->where('design_id', $design->id)->where('user_id', $user->id)->value('role');
    }

    /** Devuelve el propietario del diseño (con el que se opera su marca y sus recursos) o aborta con 404/403. */
    public function authorize(User $user, Design $design, string $ability): User
    {
        $role = $this->role($user, $design);
        abort_if($role === null, 404);
        abort_if(self::RANK[$role] < self::NEEDS[$ability], 403, 'Tu rol en este diseño no permite esta acción.');

        return $role === 'owner' ? $user : User::query()->findOrFail($design->user_id);
    }

    /** @return array{role: string, role_label: string, is_owner: bool, can_edit: bool, can_comment: bool, owner_name: string} */
    public function access(User $user, Design $design): array
    {
        return $this->summary($this->role($user, $design) ?? 'reader', $design);
    }

    public function summary(string $role, Design $design): array
    {
        return ['role' => $role, 'role_label' => self::LABELS[$role], 'is_owner' => $role === 'owner', 'can_edit' => self::RANK[$role] >= 3,
            'can_comment' => self::RANK[$role] >= 2, 'owner_name' => (string) User::query()->whereKey($design->user_id)->value('name')];
    }

    // ---- Usuarios invitados -------------------------------------------------------------------------------------

    public function shares(User $actor, Design $design): array
    {
        $this->authorize($actor, $design, 'manage');

        return DesignShare::query()->where('design_id', $design->id)->with('guest:id,name,email')->orderBy('id')->get()
            ->map(fn (DesignShare $share) => $this->presentShare($share))->all();
    }

    public function share(User $actor, Design $design, mixed $email, mixed $role): DesignShare
    {
        $this->authorize($actor, $design, 'manage');
        $data = validator(['email' => $email, 'role' => $role], ['email' => ['required', 'string', 'email', 'max:255'], 'role' => ['required', 'in:'.implode(',', self::ROLES)]],
            ['email.required' => 'Indica el email de la persona.', 'email.email' => 'Indica un email válido.', 'role.in' => 'El rol debe ser lector, comentarista o editor.'])->validate();
        $guest = User::query()->where('email', Str::lower(trim($data['email'])))->first();
        if ($guest === null) {
            throw ValidationException::withMessages(['email' => ['No hay ningún usuario registrado con ese email.']]);
        }
        if ($guest->id === $actor->id) {
            throw ValidationException::withMessages(['email' => ['Ya eres el propietario de este diseño.']]);
        }

        return DB::transaction(function () use ($actor, $design, $guest, $data): DesignShare {
            $existing = DesignShare::query()->where('design_id', $design->id)->where('user_id', $guest->id)->lockForUpdate()->first();
            if ($existing === null && DesignShare::query()->where('design_id', $design->id)->count() >= self::MAX_SHARES) {
                throw ValidationException::withMessages(['email' => ['Este diseño ya está compartido con el máximo de personas.']]);
            }
            $share = $existing ?? new DesignShare(['design_id' => $design->id, 'owner_id' => $actor->id, 'user_id' => $guest->id]);
            $changed = $existing === null || $existing->role !== $data['role'];
            $share->role = $data['role'];
            $share->save();
            if ($changed) {
                $guest->notify(new StudioDesignActivity('shared', $design->id, $design->name, $actor->name.' ha compartido contigo «'.$design->name.'» como '.mb_strtolower(self::LABELS[$data['role']]).'.'));
            }

            return $share->load('guest:id,name,email');
        });
    }

    public function updateShare(User $actor, Design $design, int $shareId, mixed $role): DesignShare
    {
        $this->authorize($actor, $design, 'manage');
        $data = validator(['role' => $role], ['role' => ['required', 'in:'.implode(',', self::ROLES)]], ['role.in' => 'El rol debe ser lector, comentarista o editor.'])->validate();
        $share = DesignShare::query()->where('design_id', $design->id)->findOrFail($shareId);
        if ($share->role !== $data['role']) {
            $share->update(['role' => $data['role']]);
            User::query()->find($share->user_id)?->notify(new StudioDesignActivity('role', $design->id, $design->name, 'Tu rol en «'.$design->name.'» ahora es '.mb_strtolower(self::LABELS[$data['role']]).'.'));
        }

        return $share->load('guest:id,name,email');
    }

    public function revokeShare(User $actor, Design $design, int $shareId): void
    {
        $this->authorize($actor, $design, 'manage');
        $share = DesignShare::query()->where('design_id', $design->id)->findOrFail($shareId);
        $share->delete();
        User::query()->find($share->user_id)?->notify(new StudioDesignActivity('revoked', $design->id, $design->name, 'Ya no tienes acceso a «'.$design->name.'».'));
    }

    public function presentShare(DesignShare $share): array
    {
        return ['id' => $share->id, 'user_id' => $share->user_id, 'name' => $share->guest?->name, 'email' => $share->guest?->email, 'role' => $share->role, 'role_label' => self::LABELS[$share->role]];
    }

    /** Diseños que otros usuarios han compartido con este usuario (no archivados), del más reciente al más antiguo. */
    public function sharedWith(User $user): array
    {
        return DesignShare::query()->where('studio_design_shares.user_id', $user->id)
            ->join('studio_designs', 'studio_designs.id', '=', 'studio_design_shares.design_id')->whereNull('studio_designs.deleted_at')
            ->join('users', 'users.id', '=', 'studio_designs.user_id')
            ->orderByDesc('studio_designs.updated_at')->limit(100)
            ->get(['studio_designs.id', 'studio_designs.name', 'studio_designs.page_count', 'studio_designs.updated_at', 'studio_design_shares.role', 'users.name as owner_name'])
            ->map(fn ($row) => ['id' => (int) $row->id, 'name' => $row->name, 'page_count' => (int) $row->page_count, 'role' => $row->role, 'role_label' => self::LABELS[$row->role],
                'owner_name' => $row->owner_name, 'updated_at' => Carbon::parse($row->updated_at)->toIso8601String()])->all();
    }

    // ---- Enlaces ------------------------------------------------------------------------------------------------

    public function links(User $actor, Design $design): array
    {
        $this->authorize($actor, $design, 'manage');

        return DesignLink::query()->where('design_id', $design->id)->orderByDesc('id')->limit(50)->get()->map(fn (DesignLink $link) => $this->presentLink($link))->all();
    }

    /** @return array{0: DesignLink, 1: string} el enlace y su token en claro, que no se vuelve a poder consultar */
    public function createLink(User $actor, Design $design, mixed $role, mixed $expiresAt = null): array
    {
        $this->authorize($actor, $design, 'manage');
        $data = validator(['role' => $role, 'expires_at' => $expiresAt], ['role' => ['required', 'in:'.implode(',', self::LINK_ROLES)], 'expires_at' => ['nullable', 'date', 'after:now', 'before:'.now()->addYear()->toDateTimeString()]],
            ['role.in' => 'Un enlace solo puede ser de lectura o de comentario.', 'expires_at.after' => 'La caducidad debe ser futura.', 'expires_at.before' => 'La caducidad no puede superar un año.', 'expires_at.date' => 'Indica una fecha de caducidad válida.'])->validate();

        return DB::transaction(function () use ($actor, $design, $data): array {
            $active = DesignLink::query()->where('design_id', $design->id)->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->lockForUpdate()->count();
            if ($active >= self::MAX_LINKS) {
                throw ValidationException::withMessages(['role' => ['Revoca algún enlace activo antes de crear otro.']]);
            }
            $token = Str::random(48);
            $link = DesignLink::query()->create(['design_id' => $design->id, 'user_id' => $actor->id, 'token_hash' => self::hash($token), 'role' => $data['role'],
                'expires_at' => isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null]);

            return [$link, $token];
        });
    }

    public function revokeLink(User $actor, Design $design, int $linkId): DesignLink
    {
        $this->authorize($actor, $design, 'manage');
        $link = DesignLink::query()->where('design_id', $design->id)->findOrFail($linkId);
        if ($link->revoked_at === null) {
            $link->update(['revoked_at' => now()]);
        }

        return $link;
    }

    public function presentLink(DesignLink $link, ?string $token = null): array
    {
        return ['id' => $link->id, 'role' => $link->role, 'role_label' => self::LABELS[$link->role], 'expires_at' => $link->expires_at?->toIso8601String(),
            'revoked_at' => $link->revoked_at?->toIso8601String(), 'last_used_at' => $link->last_used_at?->toIso8601String(), 'created_at' => $link->created_at?->toIso8601String(),
            'status' => $link->revoked_at !== null ? 'revoked' : (($link->expires_at !== null && $link->expires_at->isPast()) ? 'expired' : 'active')]
            + ($token === null ? [] : ['token' => $token, 'url' => route('studio.link.show', ['token' => $token], absolute: true)]);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return array{0: DesignLink, 1: Design, 2: User} 404 uniforme si el token no existe, caducó o se revocó */
    public function resolveLink(string $token): array
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{48}$/D', $token) === 1, 404);
        $link = DesignLink::query()->where('token_hash', self::hash($token))->first();
        abort_if($link === null || ! $link->active(), 404);
        $design = Design::withoutUserScope()->find($link->design_id);
        abort_if($design === null, 404);
        $owner = User::query()->findOrFail($design->user_id);

        return [$link, $design, $owner];
    }

    // ---- Comentarios --------------------------------------------------------------------------------------------

    public function comments(Design $design): array
    {
        $names = [];
        $rows = DesignComment::query()->where('design_id', $design->id)->orderBy('id')->limit(1000)->get();
        foreach ($rows->whereNotNull('resolved_by')->pluck('resolved_by')->unique() as $id) {
            $names[$id] = (string) User::query()->whereKey($id)->value('name');
        }

        return $rows->map(fn (DesignComment $c) => ['id' => $c->id, 'parent_id' => $c->parent_id, 'author_id' => $c->author_id, 'author_name' => $c->author_name,
            'is_guest' => $c->author_id === null, 'page_id' => $c->page_id, 'element_id' => $c->element_id, 'revision' => $c->revision, 'body' => $c->body,
            'resolved' => $c->resolved_at !== null, 'resolved_by' => $c->resolved_by !== null ? ($names[$c->resolved_by] ?? null) : null,
            'created_at' => $c->created_at?->toIso8601String()])->all();
    }

    public function addComment(Design $design, ?User $author, ?DesignLink $link, array $input): DesignComment
    {
        $rules = ['body' => ['required', 'string', 'max:2000'], 'page_id' => ['nullable', 'string', 'max:64'], 'element_id' => ['nullable', 'string', 'max:64'],
            'parent_id' => ['nullable', 'integer', 'min:1'], 'guest_name' => [$author === null ? 'required' : 'nullable', 'string', 'max:80']];
        $data = validator($input, $rules, ['body.required' => 'Escribe el comentario.', 'body.max' => 'El comentario no puede superar 2000 caracteres.', 'guest_name.required' => 'Indica tu nombre para comentar.'])->validate();
        $body = trim($data['body']);
        if ($body === '') {
            throw ValidationException::withMessages(['body' => ['Escribe el comentario.']]);
        }
        $parent = null;
        if (! empty($data['parent_id'])) {
            $parent = DesignComment::query()->where('design_id', $design->id)->find($data['parent_id']);
            if ($parent === null) {
                throw ValidationException::withMessages(['parent_id' => ['El comentario al que respondes no existe en este diseño.']]);
            }
            if ($parent->parent_id !== null) {
                $parent = DesignComment::query()->find($parent->parent_id) ?? $parent;
            }
        }
        $pageId = $parent?->page_id ?? ($data['page_id'] ?? null);
        $elementId = $parent?->element_id ?? ($data['element_id'] ?? null);
        if ($parent === null) {
            $this->assertAnchor($design, $pageId, $elementId);
        }
        $name = $author?->name ?? trim((string) ($data['guest_name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['guest_name' => ['Indica tu nombre para comentar.']]);
        }
        $comment = DesignComment::query()->create(['design_id' => $design->id, 'author_id' => $author?->id, 'link_id' => $link?->id, 'author_name' => mb_substr($name, 0, 80),
            'parent_id' => $parent?->id, 'page_id' => $pageId ?: null, 'element_id' => $elementId ?: null, 'revision' => $design->revision, 'body' => $body]);
        $this->notifyComment($design, $comment, $author, $parent);

        return $comment;
    }

    public function setResolved(User $actor, Design $design, int $commentId, bool $resolved): DesignComment
    {
        $role = $this->role($actor, $design);
        abort_if($role === null, 404);
        $comment = DesignComment::query()->where('design_id', $design->id)->findOrFail($commentId);
        $root = $comment->parent_id === null ? $comment : DesignComment::query()->findOrFail($comment->parent_id);
        $allowed = self::RANK[$role] >= 3 || ($role === 'commenter' && (int) $root->author_id === (int) $actor->id);
        abort_unless($allowed, 403, 'Tu rol en este diseño no permite resolver este hilo.');
        $root->update(['resolved_at' => $resolved ? now() : null, 'resolved_by' => $resolved ? $actor->id : null]);

        return $root;
    }

    public function deleteComment(User $actor, Design $design, int $commentId): void
    {
        $role = $this->role($actor, $design);
        abort_if($role === null, 404);
        $comment = DesignComment::query()->where('design_id', $design->id)->findOrFail($commentId);
        abort_unless($role === 'owner' || (self::RANK[$role] >= 2 && (int) $comment->author_id === (int) $actor->id), 403, 'Solo puedes borrar tus propios comentarios.');
        DB::transaction(function () use ($comment): void {
            DesignComment::query()->where('parent_id', $comment->id)->delete();
            $comment->delete();
        });
    }

    // ---- Recursos del documento ----------------------------------------------------------------------------------

    /** @return list<int> ids de recursos que referencia el documento */
    public static function assetIds(array $document): array
    {
        $ids = [];
        $walk = function (mixed $node) use (&$walk, &$ids): void {
            if (! is_array($node)) {
                return;
            }
            if (isset($node['asset_id']) && is_int($node['asset_id'])) {
                $ids[$node['asset_id']] = true;
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($document);

        return array_keys($ids);
    }

    private function assertAnchor(Design $design, ?string $pageId, ?string $elementId): void
    {
        if ($pageId === null || $pageId === '') {
            if ($elementId !== null && $elementId !== '') {
                throw ValidationException::withMessages(['element_id' => ['Indica la página del elemento.']]);
            }

            return;
        }
        $page = collect($design->document['pages'] ?? [])->firstWhere('id', $pageId);
        if ($page === null) {
            throw ValidationException::withMessages(['page_id' => ['La página no existe en este diseño.']]);
        }
        if ($elementId !== null && $elementId !== '' && ! $this->hasElement($page['elements'] ?? [], $elementId)) {
            throw ValidationException::withMessages(['element_id' => ['El elemento no existe en esa página.']]);
        }
    }

    private function hasElement(array $elements, string $id): bool
    {
        foreach ($elements as $element) {
            if (($element['id'] ?? null) === $id || (is_array($element['elements'] ?? null) && $this->hasElement($element['elements'], $id))) {
                return true;
            }
        }

        return false;
    }

    private function notifyComment(Design $design, DesignComment $comment, ?User $author, ?DesignComment $parent): void
    {
        $ids = [(int) $design->user_id];
        if ($parent !== null) {
            $ids[] = (int) $parent->author_id;
            $ids = array_merge($ids, DesignComment::query()->where('parent_id', $parent->id)->whereNotNull('author_id')->pluck('author_id')->map(fn ($id) => (int) $id)->all());
        }
        $ids = array_filter(array_unique($ids), fn (int $id) => $id > 0 && $id !== (int) $author?->id);
        foreach (User::query()->whereIn('id', $ids)->get() as $user) {
            if ($this->role($user, $design) !== null) {
                $user->notify(new StudioDesignActivity('comment', $design->id, $design->name, $comment->author_name.' ha comentado en «'.$design->name.'»: '.Str::limit($comment->body, 80)));
            }
        }
    }
}
