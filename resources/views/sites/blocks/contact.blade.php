@php $sent = ($state['sent'] ?? null); @endphp
<section class="card" id="contacto" data-block="contact">
    @if ($b['title'])
        <h2>{{ $b['title'] }}</h2>
    @endif
    @if ($sent === '1')
        <p class="ok" role="status">Gracias, hemos recibido tu mensaje.</p>
    @else
        @if ($sent === '0')
            <p class="ok" role="alert">No hemos podido enviar el mensaje. Revisa los datos e inténtalo de nuevo.</p>
        @endif
        <form method="post" action="{{ $view->action('contact', $page['key'], $b['key']) }}">
            <fieldset @if ($preview) disabled @endif>
                <label for="c-name-{{ $b['key'] }}">Nombre</label>
                <input id="c-name-{{ $b['key'] }}" type="text" name="name" maxlength="100" required autocomplete="name">
                <label for="c-email-{{ $b['key'] }}">Correo electrónico</label>
                <input id="c-email-{{ $b['key'] }}" type="email" name="email" maxlength="254" required autocomplete="email">
                <label for="c-msg-{{ $b['key'] }}">Mensaje</label>
                <textarea id="c-msg-{{ $b['key'] }}" name="message" rows="4" maxlength="2000" required></textarea>
                <div class="trap" aria-hidden="true"><label for="c-web-{{ $b['key'] }}">No rellenar</label><input id="c-web-{{ $b['key'] }}" type="text" name="website" tabindex="-1" autocomplete="off"></div>
                <input type="hidden" name="ts" value="{{ $view->formToken($b['key']) }}">
                @if (! empty($b['consent']))
                    <p class="note">{{ $b['consent'] }}</p>
                @endif
                <p><button class="btn" type="submit">{{ $b['cta'] ?? 'Enviar' }}</button></p>
            </fieldset>
        </form>
    @endif
</section>
