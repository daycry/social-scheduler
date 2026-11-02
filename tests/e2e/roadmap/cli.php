<?php

declare(strict_types=1);
use App\Analytics\Days;
use App\Jobs\CollectAccountSnapshot;
use App\Jobs\DispatchDuePosts;
use App\Jobs\RefreshSocialAccount;
use App\Models\AccountSnapshot;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostMetricSnapshot;
use App\Models\PostTarget;
use App\Models\Report;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostService;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

require __DIR__.'/bootstrap.php';
if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('T43 fixture commands are CLI-only.');
}
$action = $argv[1] ?? '';
if ($action === 'init') {
    t43Root(true);
}
$app = t43Bootstrap();
$kernel = $app->make(Kernel::class);
if ($action === 'init') {
    $exit = $kernel->call('migrate', ['--force' => true]);
    fwrite(STDERR, $exit === 0 ? "Disposable T43 SQLite migrations applied.\n" : $kernel->output());
    exit($exit);
}
$args = json_decode(base64_decode($argv[2] ?? '', true) ?: '{}', true, flags: JSON_THROW_ON_ERROR);
$project = $args['project'] ?? '';
if (! in_array($project, ['desktop', 'mobile', 'mobile-360'], true)) {
    throw new RuntimeException('Unknown T43 project.');
}
$email = 'e2e-sec-roadmap-'.$project.'@example.test';
$otherEmail = 'e2e-sec-roadmap-other-'.$project.'@example.test';
$password = 'T43-Disposable-Password1!';
$owner = fn () => User::where('email', $email)->firstOrFail();
$brand = fn () => Brand::withoutUserScope()->where('user_id', $owner()->id)->findOrFail($args['brandId'] ?? 0);
$worker = function () use ($kernel): void {
    $exit = $kernel->call('queue:work', ['--queue' => 'publish,poll,metrics,reports,default', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1, '--timeout' => 60, '--max-jobs' => 100]);
    if ($exit !== 0) {
        throw new RuntimeException('Disposable worker failed: '.$kernel->output());
    }
};
$result = match ($action) {
    'cache-isolation' => (function () use ($args): array {
        $cache = app('cache')->store('file');
        $store = $cache->getStore();
        $limiter = app(RateLimiter::class);
        $limiterCache = (new ReflectionProperty($limiter, 'cache'))->getValue($limiter);
        $key = (string) ($args['key'] ?? 't43-unique-target-1');
        $lock = $cache->lock($key, 120);
        $lockStore = (new ReflectionProperty($lock, 'store'))->getValue($lock);
        $paths = [
            'cache' => str_replace('\\', '/', $store->getDirectory()),
            'limiter' => str_replace('\\', '/', $limiterCache->getStore()->getDirectory()),
            'lock' => str_replace('\\', '/', $lockStore->getDirectory()),
        ];
        $inside = count(array_filter($paths, fn ($path) => $path === t43Root().'/cache')) === 3;
        // The RED regression inspects stale stores without writing a probe into the real cache.
        if (($args['acquire'] ?? false) && $inside) {
            $acquired = $lock->get();
            $rateKey = $key.':rate-counter';
            $limiter->hit($rateKey, 120);

            return ['paths' => $paths, 'isolated' => true, 'acquired' => $acquired, 'attempts' => $limiter->attempts($rateKey)];
        }

        return ['paths' => $paths, 'isolated' => $inside, 'acquired' => null];
    })(),
    'reset' => (function () use ($email, $otherEmail, $password): array {
        // Jobs from a previous scenario must not run after its dedicated users have been removed.
        DB::table('jobs')->delete();
        DB::table('failed_jobs')->delete();
        foreach ([$email, $otherEmail] as $address) {
            User::where('email', $address)->get()->each->delete();
        }
        $user = User::factory()->create(['name' => 'T43 Owner', 'email' => $email, 'password' => Hash::make($password)]);
        $other = User::factory()->create(['name' => 'T43 Other', 'email' => $otherEmail, 'password' => Hash::make($password)]);

        return ['user' => ['id' => $user->id, 'email' => $email, 'password' => $password], 'other' => ['id' => $other->id, 'email' => $otherEmail, 'password' => $password]];
    })(),
    'seed' => (function () use ($owner, $args): array {
        $user = $owner();
        $brand = Brand::factory()->for($user)->create(['name' => $args['brandName'] ?? 'e2e-t43-'.$args['project'], 'timezone' => 'Europe/Madrid']);
        $accounts = [];
        foreach ($args['networks'] ?? ['instagram', 'facebook', 'youtube', 'tiktok'] as $network) {
            $account = SocialAccount::factory()->for($user)->for($brand)->create(['network' => $network, 'external_id' => 't43-'.$network, 'name' => 'T43 '.ucfirst($network), 'status' => 'activa', 'access_token' => 't43-access', 'refresh_token' => 't43-refresh', 'token_expires_at' => now()->addDays(60)]);
            $accounts[$network] = $account->id;
        }
        $canvas = imagecreatetruecolor(1080, 1080);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 79, 70, 229));
        ob_start();
        imagejpeg($canvas, null, 80);
        $bytes = ob_get_clean();
        imagedestroy($canvas);
        $image = MediaAsset::factory()->for($user)->create(['disk' => 'local', 'path' => 't43/'.$brand->id.'/image.jpg', 'original_name' => 't43-image.jpg', 'mime' => 'image/jpeg', 'kind' => 'image', 'declared_size' => strlen($bytes), 'size' => strlen($bytes), 'width' => 1080, 'height' => 1080, 'status' => 'listo']);
        Storage::disk('local')->put($image->path, $bytes);

        return ['brandId' => $brand->id, 'brandName' => $brand->name, 'accounts' => $accounts, 'imageId' => $image->id];
    })(),
    'verify' => (function () use ($args, $email): array {
        $address = $args['email'] ?? $email;
        if (! str_starts_with($address, 'e2e-sec-roadmap-') || ! str_ends_with($address, '@example.test')) {
            throw new RuntimeException('Verification is restricted to disposable users.');
        }
        User::where('email', $address)->firstOrFail()->markEmailAsVerified();

        return ['verified' => true];
    })(),
    'upload-meta' => (function () use ($owner, $args): array {
        return MediaAsset::withoutUserScope()->where('user_id', $owner()->id)->findOrFail($args['assetId'])->toPublicArray();
    })(),
    'image-file' => (function () use ($owner, $args): array {
        $asset = MediaAsset::withoutUserScope()->where('user_id', $owner()->id)->findOrFail($args['assetId']);
        if ($asset->disk !== 'local' || $asset->kind !== 'image') {
            throw new RuntimeException('Only a disposable local image may be uploaded.');
        }
        $path = realpath(Storage::disk('local')->path($asset->path));
        if ($path === false || ! str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', realpath(t43Root().'/media')).'/')) {
            throw new RuntimeException('Image is outside disposable media storage.');
        }

        return ['path' => $path];
    })(),
    'long-video' => (function (): array {
        $path = t43Root().'/long-reel.mp4';
        if (! is_file($path)) {
            $process = new Process([
                (string) config('media.ffmpeg', 'ffmpeg'), '-hide_banner', '-loglevel', 'error',
                '-f', 'lavfi', '-i', 'color=c=black:s=160x288:r=24', '-t', '901',
                '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-an',
                '-movflags', '+faststart', '-y', $path,
            ]);
            $process->setTimeout(60)->mustRun();
        }

        return ['path' => $path];
    })(),
    'post' => (function () use ($owner, $brand, $args): array {
        $user = $owner();
        $account = SocialAccount::withoutUserScope()->where('user_id', $user->id)->findOrFail($args['accountId']);
        $post = app(PostService::class)->create($user, $brand(), ['text' => $args['text'] ?? 'e2e t43 publication', 'schedule' => $args['schedule'] ?? true, 'scheduled_at' => now()->addMinute()->toIso8601String(), 'media' => $args['media'] ?? [], 'targets' => [['account_id' => $account->id, 'content_type' => $args['contentType'] ?? 'facebook_text']]]);

        return ['postId' => $post->id, 'targetId' => $post->targets->first()->id];
    })(),
    'due' => (function () use ($args, $worker): array {
        $minutes = (int) ($args['minutes'] ?? 2);
        Carbon::setTestNow(now()->addMinutes($minutes));
        // Only this disposable database is reachable; future queue retries are made runnable explicitly.
        DB::table('jobs')->update(['available_at' => now()->timestamp]);
        app(DispatchDuePosts::class)();
        $worker();

        return ['processed' => true];
    })(),
    'worker' => (function () use ($worker): array {
        $worker();

        return ['processed' => true];
    })(),
    'refresh' => (function () use ($owner, $args, $app): array {
        $account = SocialAccount::withoutUserScope()->where('user_id', $owner()->id)->findOrFail($args['accountId']);
        $account->forceFill(['token_expires_at' => now()->addMinute(), 'meta' => ['t43_refresh_fail' => $args['fail'] ?? false]])->save();
        $app->call([new RefreshSocialAccount($account->id), 'handle']);

        return ['status' => $account->fresh()->status, 'renewed' => str_starts_with((string) $account->fresh()->access_token, 't43-renewed-')];
    })(),
    'snapshots' => (function () use ($owner, $app, $worker): array {
        foreach (SocialAccount::withoutUserScope()->where('user_id', $owner()->id)->get() as $account) {
            $app->call([new CollectAccountSnapshot($account->id, Days::closed($account->network)->format('Y-m-d'), true), 'handle']);
        }
        $worker();

        return ['collected' => true];
    })(),
    'history' => (function () use ($owner, $args): array {
        foreach (SocialAccount::withoutUserScope()->where('user_id', $owner()->id)->get() as $account) {
            for ($i = 29; $i >= 0; $i--) {
                $views = isset($args['views'][$account->network->value]) ? (int) $args['views'][$account->network->value] + 29 - $i : null;
                AccountSnapshot::withoutUserScope()->updateOrCreate(['social_account_id' => $account->id, 'date' => Days::closed($account->network)->subDays($i)->format('Y-m-d')], ['user_id' => $account->user_id, 'brand_id' => $account->brand_id, 'network' => $account->network, 'metrics' => ['followers' => 100 + 29 - $i, 'views' => $views, 'reach' => 30, 'impressions' => 50, 'interactions' => 5, 'profile_views' => 4, 'accounts_engaged' => 9, 'followers_gained' => 1], 'collected_at' => now(), 'settled_at' => now()]);
            }
        }

        return ['days' => 30];
    })(),
    'foreign-report-data' => (function () use ($otherEmail): array {
        $other = User::where('email', $otherEmail)->firstOrFail();
        $marker = 'T43 FOREIGN MUST NOT LEAK';
        $brand = Brand::factory()->for($other)->create(['name' => $marker, 'timezone' => 'Europe/Madrid']);
        $account = SocialAccount::factory()->for($other)->for($brand)->create(['network' => 'facebook', 'external_id' => 't43-foreign-facebook', 'name' => $marker]);
        $post = Post::factory()->create(['user_id' => $other->id, 'brand_id' => $brand->id, 'text' => $marker, 'status' => 'publicada', 'scheduled_at' => now()->subHour()]);
        $target = PostTarget::factory()->create(['user_id' => $other->id, 'post_id' => $post->id, 'social_account_id' => $account->id, 'network' => 'facebook', 'content_type' => 'facebook_text', 'status' => 'publicada', 'published_at' => now()->subHour(), 'external_id' => 't43-foreign-post']);
        PostMetricSnapshot::withoutUserScope()->create(['user_id' => $other->id, 'social_account_id' => $account->id, 'post_target_id' => $target->id, 'network' => 'facebook', 'date' => now()->format('Y-m-d'), 'metrics' => ['views' => 987654321, 'likes' => 100, 'interactions' => 200], 'collected_at' => now()]);
        AccountSnapshot::withoutUserScope()->create(['user_id' => $other->id, 'brand_id' => $brand->id, 'social_account_id' => $account->id, 'network' => 'facebook', 'date' => now()->format('Y-m-d'), 'metrics' => ['followers' => 987654321], 'collected_at' => now(), 'settled_at' => now()]);

        return ['marker' => $marker];
    })(),
    'state' => (function () use ($owner): array {
        $user = $owner();
        $accounts = SocialAccount::withoutUserScope()->where('user_id', $user->id)->get();
        $posts = Post::withoutUserScope()->where('user_id', $user->id)->get();
        $targets = PostTarget::withoutUserScope()->whereIn('post_id', $posts->modelKeys())->get();
        $fake = is_file(t43Root().'/externals.json') ? json_decode(file_get_contents(t43Root().'/externals.json'), true) : [];

        return ['accounts' => $accounts->map(fn ($a) => ['id' => $a->id, 'network' => $a->network->value, 'status' => $a->status, 'hasToken' => $a->access_token !== null])->all(), 'posts' => $posts->map(fn ($p) => ['id' => $p->id, 'text' => $p->text, 'status' => $p->status->value, 'scheduledAt' => $p->scheduled_at?->toIso8601String()])->all(), 'targets' => $targets->map(fn ($t) => ['id' => $t->id, 'postId' => $t->post_id, 'status' => $t->status->value, 'attempts' => $t->attempts, 'externalId' => $t->external_id, 'externalUrl' => $t->external_url])->all(), 'accountSnapshots' => AccountSnapshot::withoutUserScope()->whereIn('social_account_id', $accounts->modelKeys())->get()->toArray(), 'postSnapshots' => PostMetricSnapshot::withoutUserScope()->whereIn('post_target_id', $targets->modelKeys())->get()->toArray(), 'reports' => Report::withoutUserScope()->where('user_id', $user->id)->get()->toArray(), 'externals' => $fake, 'mail' => is_file(t43Root().'/mailbox.jsonl') ? array_map(fn ($line) => json_decode($line, true), file(t43Root().'/mailbox.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : []];
    })(),
    default => throw new RuntimeException('Unknown disposable T43 action.'),
};
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
