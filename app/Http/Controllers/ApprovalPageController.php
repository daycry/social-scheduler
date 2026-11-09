<?php

namespace App\Http\Controllers;

use App\Enums\ContentType;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostSeries;
use App\Models\SocialAccount;
use App\Services\ApprovalService;
use App\Services\BrandCollaboration;
use App\Services\ManualPublishService;
use App\Services\SeriesService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Paginas del flujo de publicacion (T-19): bandeja de aprobaciones, aviso de publicacion manual y series. */
class ApprovalPageController extends Controller
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly ManualPublishService $manual,
        private readonly SeriesService $series,
        private readonly BrandCollaboration $collab,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $brands = Brand::withoutUserScope()->where('user_id', $user->id)->orderBy('name')->get(['id', 'name']);

        return Inertia::render('Approvals/Index', [
            'pending' => array_map(fn (Post $p) => $this->approvals->present($p), $this->approvals->pendingFor($user)),
            'mine' => $this->approvals->queryFor($user)->where('user_id', $user->id)->whereIn('approval_status', [Post::APPROVAL_REJECTED, Post::APPROVAL_APPROVED])
                ->latest('approval_decided_at')->limit(30)->get()->map(fn (Post $p) => $this->approvals->present($p))->values(),
            'brands' => $brands->map(fn (Brand $b) => ['id' => $b->id, 'name' => $b->name, 'collaborators' => $this->collab->list($user, $b)])->values(),
        ]);
    }

    public function manual(Request $request, int $post): Response
    {
        $model = Post::withoutUserScope()->where('user_id', $request->user()->id)->find($post);
        abort_if($model === null, 404);

        return Inertia::render('Posts/Manual', [
            'post' => ['id' => $model->id, 'text' => $model->text, 'brand_name' => Brand::withoutUserScope()->whereKey($model->brand_id)->value('name')],
            'targets' => $this->manual->content($request->user(), $model),
        ]);
    }

    public function series(Request $request): Response
    {
        $user = $request->user();
        $brands = Brand::withoutUserScope()->where('user_id', $user->id)->orderBy('name')->get();

        return Inertia::render('Series/Index', [
            'series' => PostSeries::withoutUserScope()->where('user_id', $user->id)->orderByDesc('id')->limit(100)->get()->map(fn (PostSeries $s) => $this->series->present($s))->values(),
            'brands' => $brands->map(fn (Brand $b) => [
                'id' => $b->id, 'name' => $b->name, 'timezone' => $b->timezone,
                'accounts' => $b->id ? SocialAccount::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $b->id)->where('status', '!=', SocialAccount::STATUS_DISCONNECTED)->get()
                    ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'network' => $a->network->value, 'network_label' => $a->network->label(),
                        'content_types' => collect(ContentType::forNetwork($a->network))->filter->isOffered()->map(fn ($t) => ['value' => $t->value, 'label' => $t->label()])->values()])->values() : [],
            ])->values(),
        ]);
    }
}
