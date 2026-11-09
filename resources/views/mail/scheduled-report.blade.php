<!DOCTYPE html>
<html lang="es">
<body style="font-family: Arial, sans-serif; color: #1f2937;">
    <h1 style="font-size: 18px;">Informe de {{ $brandName }}</h1>
    <p>Periodo: {{ $from }} - {{ $to }}.</p>
    @if ($attached)
        <p>El informe va adjunto en PDF.</p>
    @endif
    <p><a href="{{ $url }}">Descargar el informe</a> (el enlace caduca en {{ $hours }} horas).</p>
    <p style="font-size: 12px; color: #6b7280;">Recibes este mensaje porque alguien configuro un informe programado. Responde al propietario de la cuenta para dejar de recibirlo.</p>
</body>
</html>
