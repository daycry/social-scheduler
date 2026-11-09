@php
    $results = $polls[$b['key']] ?? null;
    $total = $results ? array_sum($results) : 0;
    $show = $showResults || ($state['voted'] ?? null) === $b['key'];
@endphp
<section class="card" id="p-{{ $b['key'] }}" data-block="poll">
    @if (! $show)
        <form method="post" action="{{ $view->action('vote', $page['key'], $b['key']) }}">
            <fieldset @if ($preview) disabled @endif>
                <legend>{{ $b['question'] }}</legend>
                @foreach ($b['options'] as $i => $opt)
                    <label class="opt"><input type="radio" name="option" value="{{ $i }}" required> {{ $opt }}</label>
                @endforeach
                <p><button class="btn" type="submit">Votar</button></p>
            </fieldset>
        </form>
        <p class="note"><a href="{{ $view->pageUrl($page['key']) }}?results=1#p-{{ $b['key'] }}">Ver resultados</a></p>
    @else
        <h3>{{ $b['question'] }}</h3>
        @if (($state['voted'] ?? null) === $b['key'])
            <p class="ok" role="status">Gracias por votar.</p>
        @endif
        <div data-results>
            @foreach ($b['options'] as $i => $opt)
                @php $n = $results[$i] ?? 0; $pct = $total > 0 ? round($n * 100 / $total) : 0; @endphp
                <div class="res">
                    <label class="flat" for="r-{{ $b['key'] }}-{{ $i }}">{{ $opt }}: {{ $n }} {{ $n === 1 ? 'voto' : 'votos' }} ({{ $pct }} %)</label>
                    <progress id="r-{{ $b['key'] }}-{{ $i }}" max="{{ max($total, 1) }}" value="{{ $n }}">{{ $pct }} %</progress>
                </div>
            @endforeach
        </div>
        <p class="note">{{ $total }} {{ $total === 1 ? 'voto' : 'votos' }} en total.</p>
    @endif
</section>
