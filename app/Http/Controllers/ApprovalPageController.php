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
        $brands = Brand::ownedBy($user->id)->orderBy('name')->get();

        return Inertia::render('Approvals/Index', $this->approvals->inbox($user) + [
            'brands' => $brands->map(fn (Brand $b) => ['id' => $b->id, 'name' => $b->name, 'collaborators' => $this->collab->list($user, $b)])->values(),
        ]);
    }

    public function manual(Request $request, int $post): Response
    {
        $model = Post::ownedBy($request->user()->id)->find($post);
        abort_if($model === null, 404);

        return Inertia::render('Posts/Manual', [
            'post' => ['id' => $model->id, 'text' => $model->text, 'brand_name' => Brand::withoutUserScope()->whereKey($model->brand_id)->value('name')],
            'targets' => $this->manual->content($request->user(), $model),
        ]);
    }

    public function series(Request $request): Response
    {
        $user = $request->user();
        $brands = Brand::ownedBy($user->id)->orderBy('name')->get();

        return Inertia::render('Series/Index', [
            'series' => PostSeries::ownedBy($user->id)->orderByDesc('id')->limit(100)->get()->map(fn (PostSeries $s) => $this->series->present($s))->values(),
            'brands' => $brands->map(fn (Brand $b) => [
                'id' => $b->id, 'name' => $b->name, 'timezone' => $b->timezone,
                'accounts' => $b->id ? SocialAccount::ownedBy($user->id)->where('brand_id', $b->id)->where('status', '!=', SocialAccount::STATUS_DISCONNECTED)->get()
                    ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'network' => $a->network->value, 'network_label' => $a->network->label(),
                        'content_types' => collect(ContentType::forNetwork($a->network))->filter->isOffered()->map(fn ($t) => ['value' => $t->value, 'label' => $t->label()])->values()])->values() : [],
            ])->values(),
        ]);
    }
}
