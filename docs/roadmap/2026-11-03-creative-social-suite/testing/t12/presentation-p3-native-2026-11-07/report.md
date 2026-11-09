# QA P2+P3: fallo móvil localizado

La tanda con build11 terminó con **16 aprobados, 1 fallo y 16 sin ejecutar**, de 33 casos previstos. No se acepta P2/P3 completa ni se cierra T12. La parada adicional del runner indica maxFailures1; no es un segundo fallo funcional.

Pasaron los once casos de escritorio y los cinco primeros de móvil: medios propios reales, fuente privada/gráfico, fullscreen positivo, cancelación de medios, y en escritorio las herramientas y música. El caso móvil de herramientas falla en `studio-presentation-tools.spec.ts:91`: el centro del canvas permanece azul cuando se espera el láser rojo.

La [captura original](mobile-failure.png) muestra el pie de controles sobre la diapositiva. La traza original registra mouseMove187.5,668.515625. La investigación descarta que la compilación o el núcleo del láser estén globalmente rotos: escritorio pasa y el fallo coincide con el layout estrecho y herramientas desplegadas.

Una comprobación nativa aislada con el marcado del SFC y el CSS real de build11 confirma la causa: a375px el grid mide70.39px y el canvas188.44px; el centro recibe FOOTER. Al sustituir `min-h-0` por `shrink-0`, el grid conserva468.875px y el centro recibe CANVAS. A768/1440 el centro sigue siendo CANVAS; escritorio conserva su geometría. Esta comprobación es diagnóstica de CSS y no constituye un PASS de la aplicación.

El implementador aplicó exclusivamente esa clase en PresentationDialog.vue234. El caso funcional nativo rojo precede el cambio y se conserva sin relajar aserciones. No se añadió un test que sólo compruebe la cadena de clases ni se inventó un verde. Falta revisar y compilar esta fuente, y su aceptación nativa completa.

El supervisor terminó limpio, sin hijos propios y con8080 libre en IPv4/IPv6. No hubo deriva durante el run ni fallo de recursos. COMMIT pico734769152B bajo3GiB, físico mínimo9527115776B. Tiempo común419.478s incluyendo el intento EPERM previo y preparación/revisión; el runner empleó143.131s. El origen original1794027586.7066417 y deadline1794028486.7066417 se conservan, sin reset por retry. El intento EPERM creó sólo metadata del reporter y ejecutó cero casos.

Los archivos enlazados son copias por bytes de los originales, comprobadas en manifest.json; no se han re-renderizado. El PDF y la aprobación visual final quedan pendientes mientras la QA siga abierta. Sólo SQLite/recursos ficticios y loopback; sin proveedores reales.

La fuente corregida ya pasó revisión independiente A+B sin hallazgos y build12 terminó correctamente (95entradas/78assets, sin deriva). Esto actualiza la situación de revisión/compilación descrita arriba; la aceptación nativa completa posterior al fix sigue pendiente. No se reinició el plazo original para otra tanda.
