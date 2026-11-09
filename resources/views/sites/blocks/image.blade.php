@if ($view->mediaUrl($b['media_id']))
    <figure>
        <img src="{{ $view->mediaUrl($b['media_id']) }}" alt="{{ $b['alt'] }}" loading="lazy">
        @if (! empty($b['caption']))
            <figcaption>{{ $b['caption'] }}</figcaption>
        @endif
    </figure>
@endif
