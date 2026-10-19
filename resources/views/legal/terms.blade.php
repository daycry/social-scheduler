@extends('legal.layout')
@section('title', 'Condiciones del servicio')
@section('content')
<h1>Condiciones del servicio</h1>
<p>{{ config('app.name') }} es una herramienta de uso personal. Al usarla aceptas estas condiciones.</p>

<h2>Uso</h2>
<p>Solo puedes conectar cuentas de redes sociales de las que seas titular o estés autorizado a gestionar, y eres responsable del contenido que programas y publicas, que debe cumplir las normas de cada red.</p>

<h2>Servicios de terceros</h2>
<p>La publicación y las estadísticas dependen de las APIs de Meta, Google (YouTube) y TikTok. Sus límites, cambios o caídas pueden impedir o retrasar una publicación; la aplicación te avisará de los fallos, pero no garantiza la disponibilidad de esos servicios.</p>

<h2>Sin garantía</h2>
<p>El servicio se ofrece «tal cual», sin garantías de disponibilidad continua. En la medida permitida por la ley, no se responde de daños derivados de su uso.</p>

<h2>Cambios y contacto</h2>
<p>Estas condiciones pueden actualizarse. Contacto: <a href="mailto:{{ config('legal.contact_email') }}">{{ config('legal.contact_email') }}</a>.</p>
@endsection
