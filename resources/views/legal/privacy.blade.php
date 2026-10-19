@extends('legal.layout')
@section('title', 'Política de privacidad')
@section('content')
<h1>Política de privacidad</h1>
<p>{{ config('app.name') }} es una herramienta de uso personal para programar publicaciones y consultar estadísticas de las cuentas de redes sociales que su titular conecta voluntariamente (Facebook, Instagram, YouTube y TikTok).</p>

<h2>Qué datos tratamos</h2>
<ul>
    <li>Datos de la cuenta de la aplicación: nombre, email y contraseña (cifrada).</li>
    <li>Tokens de acceso que conceden las redes al conectar una cuenta, guardados cifrados y usados solo para publicar y leer estadísticas en nombre del titular.</li>
    <li>Contenido que el titular sube para publicar (textos, imágenes y vídeos) y las métricas que devuelven las redes.</li>
</ul>

<h2>Para qué los usamos</h2>
<p>Exclusivamente para programar y publicar el contenido que el titular indica y para mostrarle sus estadísticas e informes. No vendemos ni cedemos datos a terceros ni los usamos con fines publicitarios.</p>

<h2>Con quién se comparten</h2>
<p>Solo con la red social de destino al publicar o consultar estadísticas, y con el proveedor de almacenamiento donde se guardan los ficheros subidos.</p>

<h2>Conservación y borrado</h2>
<p>Al desconectar una cuenta se eliminan sus tokens. El titular puede borrar sus publicaciones, ficheros, informes y su cuenta en cualquier momento; también puede revocar el acceso desde la configuración de cada red.</p>

<h2>Contacto</h2>
<p>Para cualquier consulta sobre privacidad: <a href="mailto:{{ config('legal.contact_email') }}">{{ config('legal.contact_email') }}</a>.</p>
@endsection
