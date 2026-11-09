<div class="gallery" role="list">
    @foreach ($b['items'] as $it)
        @if ($view->mediaUrl($it['media_id']))
            <div role="listitem"><img src="{{ $view->mediaUrl($it['media_id']) }}" alt="{{ $it['alt'] }}" loading="lazy"></div>
        @endif
    @endforeach
</div>
