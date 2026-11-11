# QA3 — preparación y perfil independiente

2026-11-05. **READY; ninguna ejecución hasta `revisión aprobada; ejecuta intento3`.** No se han ejecutado tests, nativos, observadores ni build. QA1 y QA2 permanecen inmutables: sus gates y el Important visual de QA2 no se reescriben.

## Entrada y coordinación

La nueva unión revisada está en `storage/framework/testing/t11-caption-contrast-root-review/reviewed-union-manifest.json`, con39 fuentes actuales y76 generados; los siete descriptores actuales están en descriptors.json de esa misma carpeta. El delta de tres fuentes tiene su manifiesto en `storage/framework/testing/t11-caption-contrast/final-freeze/manifest.json`. A3/B3 y el cierre documental se reciben del orquestador antes de la señal. No se usa el freeze anterior ni su helper histórico contra hashes nuevos.

El fix descrito por root cambia solo paintFirst a stroke en los textos temporales de captions, sin modificar styleJSON/tamaños/defaults globales. Evidencia previa: causa aislada con canvas real, RED/GREEN permanente6casos. Los dos focales de render fallidos siguen siendo fallos de fixture/comparador conservados; no se cuentan como PASS. La primera ejecución integrada de esa versión corresponde a QA3 si su resultado lo acredita.

Helper nuevo: `storage/framework/testing/t11-independent-qa3.py`, basado en una copia de QA2, sin modificarla. Exige autorización exacta,12specs presentes, puerto8080 libre y una sola ejecución registrada. El conteo se obtiene del JSON real, no se presupone72. Misma raízUUIDt43 aislada y tres viewports. Slot actual libre, pero no se consume hasta la señal. Se anuncian sesión/PID/root al iniciar y no se reinicia por timeout de observación.

## Integridad y recursos

El snapshot directo incorpora las39 rutas de la unión, incluidos el test de ZIP y los tres helpers que antes tenían verificación adicional. Inventario actual842rutas; es preparación, no baseline anterior a una ejecución. Incluye OpenAPI, guía API/MCP, README, móduloMCP, deploy, document-contract, matriz/contratos/criterios y fonts con el case real `tests/Fixtures/fonts`, además de renderer/vector/estático/soporte `.mjs` y `.d.mts` y public/build completo.

En el arranque y terminal se comprueban39fuentes+76generados+7descriptores desde la unión NUEVA. Se conserva una copia de ambos manifiestos antes de probar. El conjunto esperado anterior se reutiliza para la comparación posterior; no se adapta el esperado a una deriva. No hay exclusiones arbitrarias. Guardrail localhost y ledger/coverage se ejecutan antes de iniciar.

El runner y sus hijos, observador y PDF utilizan JobObject3GiB de COMMIT agregado, kill-on-close y sin breakaway, con consulta al kernel y registro de pico. No se llama RSS ni se reserva esa memoria previamente. No se reutiliza servidor/Node ajeno. No ONNX/imaging/test_background, MySQL/VPS/R2/socialreal, ASR/traducciónT14 ni cierre global.

## Perfil de medición — conservar lo aceptado en QA2

El observador conserva las42 comprobaciones propias previas: ventana `[start,end)`, opacidad durante fade, fuente privada/ZIP/SVGportable/PNG/PDF, selección A/C con reloj fuente y SRT/VTT Unicode/timestamps. Se siguen observando bytes nuevos de QA3 con FFmpeg/ffprobe/XML/SHA/PyMuPDF/Chromium sin red, sin importar los oráculos del producto. Los parámetros del perfil de la fixture privada no se cambian para acomodar la salida nueva.

## Perfil añadido para QA2-V01

Fixture fijada por E2E24:320×180/FPS12, Arial22normal, texto sin emoji `Modificado\nsegunda línea`, blanco sobre negro. CONTROL declara strokeWidth0; DEFAULT omite el ancho y conserva el default2. Cue global[0,25,1,25). Se observan PNG a0,5s y MP4/GIF completos de18frames, con frame6 activo y frame0 antes del cue.

Archivos actuales esperados:

- `contrast-control.png/.mp4/.gif` y `contrast-outline.png/.mp4/.gif`.
- `captions-contrast-control.png` y `captions-contrast-outline.png`: screenshots completos de preview real.
- `captions-default-contrast-measurements.json`: recibo del test para contraste de información, nunca único oráculo.
- `captions-ui-contrast.json` y `captions-editor.png`: recorrido UI con texto/emoji editado y estilo22.

`inspect-default-contrast.py` está preparado pero no ejecutado. Decodifica de nuevo los archivos y aplica **min(R,G,B)>=200** consistentemente en esta serie, junto a energía=sum(minRGB/255) y umbrales inclusivos16/64/128/199/200/201/240. Registra explícitamente los píxeles exactamente200; no cambia comparadores ni umbrales tras ver resultados. El control PNG calibrado debe superar1000blancos; controlMP4/GIF>500; DEFAULT debe conservar al menos50% de blancos y energía del CONTROL. El frame anterior debe tener0blancos. Se guardan imágenes activas/previas, probes, hashes y razones porviewport/formato. Las42 comprobaciones de la serie privada conservan su comparador propio, sin mezclar definiciones.

Para preview375 se usa el screenshot real, no el DTO de cobertura. Se identifica una única ROI negra con la geometría de la fixture320:180 y escala uniforme: ancho200–360,alto100–205, diferencia de aspecto<0,03 y componente negra>85% de su bbox. Headers/HTML blanco no satisfacen el perfil. Una ROI ambigua o ausente falla la observación; no se elige otra para dar verde. Se guardan coordenadas exactas, dimensiones originales, hash, PNG recortado y métricas. La comparación se hace entre CONTROL y DEFAULT bajo la misma escala CSS. El control físico debe superar500blancos y se exige la misma razón mínima50%; la calibración absoluta>1000 se contrasta en PNG320×180 sin escalado. Esta distinción de tamaño se fija antes de ejecutar, no según el resultado.

Las imágenes de QA3 deben ser visualmente legibles. El recorte oscuro de QA2 se conserva como historial identificado, sin usarlo como esperado de la nueva salida ni editarlo. La medición numérica no sustituye inspección visual ni permite afirmar un ratioWCAG no calculado.

## Salida y pendientes de entrada

Se preparan report.md/PDF rasterizado e inspeccionado, raw real, manifiesto fuente/artefactos y separación entre gate automático y aceptación visual. No se cerrará QA2-V01 por un nombre de test; requiere nuevas métricas e imágenes. Root decide estadosT07/T11, nunca esta QA ni el plan completo.

Falta únicamente recibir el cierre final A3/B3/addendum, ledger y siete descriptores definitivos más la señal exacta. Todos los12specs existen. No se pide otro permiso de instalación ni se instala nada. No se detecta un hueco adicional de artefactos en la definición actual; si falta alguno en la ejecución se declara como evidencia ausente, sin omitirlo del veredicto.
