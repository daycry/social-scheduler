<section aria-label="{{ $b['title'] ?: 'Preguntas frecuentes' }}" data-block="faq">
    @if ($b['title'])
        <h2>{{ $b['title'] }}</h2>
    @endif
    @foreach ($b['items'] as $it)
        <details>
            <summary>{{ $it['q'] }}</summary>
            <div class="rich">{!! \App\Support\SiteRichText::render($it['a']) !!}</div>
        </details>
    @endforeach
</section>
