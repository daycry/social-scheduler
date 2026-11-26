# Buscar en la aplicación

La paleta de búsqueda encuentra páginas, acciones y tu contenido desde cualquier pantalla, tanto de la parte social como del Estudio.

## Cómo abrirla

- **Con el teclado:** `Ctrl + K` (en macOS, `⌘ + K`). Ningún editor de la aplicación usa esa combinación, así que funciona también dentro del Estudio y del compositor (en el compositor, si hay cambios sin guardar, te pregunta antes de salir, igual que con cualquier otro enlace). Si ya hay otro diálogo abierto, el atajo no hace nada: ciérralo primero.
- **Con el botón** de la lupa, en la cabecera (con el texto **Buscar** en pantallas anchas). En pantallas pequeñas la paleta ocupa toda la pantalla y tiene un botón **Cerrar**.

Al abrirla, el cursor ya está en el campo de búsqueda. `Esc` (o **Cerrar**, o pulsar fuera) la cierra y el foco vuelve al elemento donde estabas.

## Qué busca

| Qué | Cómo se busca | Dónde te lleva |
|---|---|---|
| **Páginas y acciones** (Calendario, Crear publicación, Importar CSV, Campañas, Informes, Perfil…) | Al momento, sin tildes ni mayúsculas, con las mismas condiciones que el menú: si una pantalla no te aparece en el menú, tampoco aquí | La propia pantalla |
| **Publicaciones** | Por el texto (desde 2 letras) | Su edición |
| **Marcas** | Por el nombre | La ficha de la marca |
| **Diseños** del Estudio | Por el nombre | El editor |
| **Archivos** de la biblioteca | Por el nombre del fichero (imágenes, vídeos y PDF ya subidos) | El archivo, en otra pestaña |
| **Campañas** | Por el nombre (las archivadas llevan la etiqueta «Archivada») | El panel de la campaña |
| **Plantillas** de publicación | Por el nombre | La biblioteca de plantillas de la marca |
| **Sitios** y **smart links** | Por el título | Su editor |

Cada resultado muestra su tipo (el encabezado del grupo), el título y, según el caso, la marca, la fecha en la **zona horaria de esa marca** y el estado. Se muestran como máximo 5 resultados por tipo y 20 en total, repartidos entre los tipos; si no ves lo que buscas, escribe algo más concreto.

## Cómo moverte

`↓` y `↑` cambian de resultado (al llegar al final vuelven al otro extremo), `Inicio`, `Fin` y las flechas laterales mueven el cursor dentro del campo, y `Intro` abre el seleccionado. También puedes pulsar un resultado con el ratón o el dedo. Un lector de pantalla anuncia cuántos resultados hay, si se está buscando o si no hay ninguno.

## Qué no busca

- **Solo tu contenido.** Nunca aparece nada de otra persona, ni siquiera cuántas coincidencias tiene.
- **Colaboradores de una marca ajena:** ves lo mismo que ya ves en el resto de la aplicación, ni más ni menos. Si eres aprobador de la marca de otra persona, la búsqueda encuentra sus publicaciones **pendientes de aprobar** (las de tu bandeja de **Aprobaciones**) y sus diseños del Estudio. No encuentra sus otras publicaciones, su marca, sus archivos, campañas, sitios ni plantillas, porque ninguna pantalla te los muestra.
- Las publicaciones y los diseños que están en la papelera, y los archivos aún sin procesar o no válidos.
- Texto dentro de los diseños, comentarios del buzón, métricas ni nombres de cuentas de redes: solo los títulos y textos de la tabla de arriba.
- Consultas de menos de 2 o de más de 100 caracteres: con menos de 2 letras solo se filtran las páginas.

## Detalles de la búsqueda

- No distingue mayúsculas de minúsculas. Los acentos dependen de la base de datos: en el entorno de pruebas (SQLite) `cafe` no encuentra «café»; en MySQL con la collation habitual (`utf8mb4_unicode_ci`) sí. Las páginas y acciones se buscan siempre sin tildes.
- Los caracteres `%`, `_` y `!` se buscan tal cual, no son comodines.
- Hay un límite de 60 búsquedas por minuto y persona; con el uso normal no se nota (se espera un instante antes de buscar mientras escribes). Si lo superas, la paleta lo dice y puedes seguir con las páginas.
