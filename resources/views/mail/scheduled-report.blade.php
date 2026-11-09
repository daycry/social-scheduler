<!DOCTYPE html>
<html lang="es">
<body style="font-family: Arial, sans-serif; color: #1f2937;">
    <h1 style="font-size: 18px;">Informe de {{ $brandName }}</h1>
    <p>Periodo: {{ $from }} - {{ $to }}.</p>
    @if ($attached)
        <p>El informe va adjunto en PDF.</p>
    @endif
    <p><a href="{{ $url }}">Descargar el informe</a> (el enlace caduca en {{ $hours }} horas).</p>
    <p style="font-size: 12px; color: #4b5563;">Recibes esto porque {{ $ownerName }} te añadió como destinatario de un informe programado.@if ($unsubscribeUrl) <a href="{{ $unsubscribeUrl }}">Darme de baja de este informe</a>.@endif</p>
</body>
</html>
