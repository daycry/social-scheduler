@if (($b['level'] ?? 2) === 3)
    <h3>{{ $b['text'] }}</h3>
@else
    <h2>{{ $b['text'] }}</h2>
@endif
