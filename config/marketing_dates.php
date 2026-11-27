<?php

/*
|--------------------------------------------------------------------------
| Fechas señaladas de marketing (T-90)
|--------------------------------------------------------------------------
|
| Fuente única y editable del calendario de fechas señaladas (calendario y feed .ics). Son datos propios de la aplicación, sin
| servicios externos ni tabla: añadir, quitar o renombrar una fecha es editar esta lista (ver `docs/modulos/calendario.md`).
| El conjunto actual es INICIAL: el listado definitivo lo fija el equipo de producto.
|
| Cada entrada lleva `key` (identificador único y estable: forma el UID del evento .ics, no lo cambies una vez publicado), `name`
| (texto que ve el usuario) y UNA de estas dos reglas, que se calculan para cada año:
|
|   - Fecha fija:  `month` + `day`.                              Ej.: ['month' => 2, 'day' => 14]
|   - Día móvil:   `month` + `weekday` (1 = lunes ... 7 = domingo) + `nth` (1..5, o -1 = el último), con `offset` opcional en días
|                  (positivo o negativo) respecto a ese día. Ej.: el primer domingo de mayo → ['month' => 5, 'weekday' => 7, 'nth' => 1].
|                  Black Friday es el día siguiente al cuarto jueves de noviembre → ['month' => 11, 'weekday' => 4, 'nth' => 4, 'offset' => 1].
|
| El orden de la lista da igual: se ordena por fecha.
*/

return [
    'dates' => [
        ['key' => 'new-year', 'name' => 'Año Nuevo', 'month' => 1, 'day' => 1],
        ['key' => 'epiphany', 'name' => 'Día de Reyes', 'month' => 1, 'day' => 6],
        ['key' => 'valentines-day', 'name' => 'San Valentín', 'month' => 2, 'day' => 14],
        ['key' => 'womens-day', 'name' => 'Día de la Mujer', 'month' => 3, 'day' => 8],
        ['key' => 'fathers-day', 'name' => 'Día del Padre (España)', 'month' => 3, 'day' => 19],
        ['key' => 'world-book-day', 'name' => 'Día del Libro', 'month' => 4, 'day' => 23],
        ['key' => 'labour-day', 'name' => 'Día del Trabajo', 'month' => 5, 'day' => 1],
        ['key' => 'mothers-day', 'name' => 'Día de la Madre (España)', 'month' => 5, 'weekday' => 7, 'nth' => 1],
        ['key' => 'halloween', 'name' => 'Halloween', 'month' => 10, 'day' => 31],
        ['key' => 'singles-day', 'name' => 'Día del Soltero', 'month' => 11, 'day' => 11],
        ['key' => 'black-friday', 'name' => 'Black Friday', 'month' => 11, 'weekday' => 4, 'nth' => 4, 'offset' => 1],
        ['key' => 'cyber-monday', 'name' => 'Cyber Monday', 'month' => 11, 'weekday' => 4, 'nth' => 4, 'offset' => 4],
        ['key' => 'christmas', 'name' => 'Navidad', 'month' => 12, 'day' => 25],
        ['key' => 'new-years-eve', 'name' => 'Nochevieja', 'month' => 12, 'day' => 31],
    ],
];
