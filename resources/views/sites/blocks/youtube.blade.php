@if (! empty($b['video_id']) && preg_match('/^[A-Za-z0-9_-]{11}$/', $b['video_id']) === 1)
    <div class="video"><iframe src="https://www.youtube-nocookie.com/embed/{{ $b['video_id'] }}" title="{{ $b['title'] }}" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="encrypted-media; picture-in-picture" allowfullscreen></iframe></div>
@endif
