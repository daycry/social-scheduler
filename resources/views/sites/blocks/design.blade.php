@if ($view->mediaUrl($b['media_id']))
    <figure data-block="design">
        <img src="{{ $view->mediaUrl($b['media_id']) }}" alt="{{ $b['alt'] }}" loading="lazy">
    </figure>
@endif
