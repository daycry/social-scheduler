@php
    $isPage = ($b['kind'] ?? 'url') === 'page';
    $href = $isPage ? $view->pageUrl($b['page']) : (\App\Support\SafeUrl::isSafe($b['url'] ?? null) ? $b['url'] : null);
@endphp
@if ($href)
    <p><a class="btn" href="{{ $href }}" @if (! $isPage) rel="nofollow noopener noreferrer" @endif>{{ $b['label'] }}</a></p>
@endif
