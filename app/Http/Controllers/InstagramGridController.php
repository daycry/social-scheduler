<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Services\GridService;
use App\Support\CurrentBrand;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** «Cuadricula» de Instagram (C-02): solo lectura, por cuenta de Instagram de la marca actual. */
class InstagramGridController extends Controller
{
    public function __construct(private readonly GridService $grid) {}

    public function index(Request $request): Response
    {
        $request->validate(['account_id' => ['nullable', 'integer'], 'brand_id' => ['nullable']]);

        $user = $request->user();
        $brand = CurrentBrand::resolve($request);
        $accounts = $brand ? $this->grid->accounts($brand) : [];
        $selected = null;

        if ($request->filled('account_id')) {
            $requested = SocialAccount::withoutUserScope()->where('user_id', $user->id)->find((int) $request->query('account_id'));
            abort_if($requested === null, 404); // la cuenta de otro usuario no existe para este
            // Una cuenta propia de OTRA marca (el selector de marca conserva `account_id`) cae a la primera de la actual.
            $selected = collect($accounts)->firstWhere('id', $requested->id);
        }

        $selected ??= $accounts[0] ?? null;
        $account = $selected ? SocialAccount::withoutUserScope()->where('user_id', $user->id)->find($selected['id']) : null;
        $data = $account ? $this->grid->build($user, $account) : ['items' => [], 'error' => null, 'cached' => false];

        return Inertia::render('Instagram/Grid', [
            'brand' => $brand?->only(['id', 'name']),
            'timezone' => $brand?->timezone ?? 'UTC',
            'accounts' => $accounts,
            'account' => $selected,
            'items' => $data['items'],
            'error' => $data['error'],
            'cached' => $data['cached'],
        ]);
    }
}
