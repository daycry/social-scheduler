<?php

// Importacion masiva de publicaciones por CSV (T-83). Documentado en docs/usuario/importar-csv.md.

return [
    // Tamano maximo del fichero, en KB.
    'max_kb' => 1024,

    // Filas de datos maximas por fichero (las filas totalmente vacias no cuentan).
    'max_rows' => 200,

    // Minutos que la vista previa admite ser confirmada. Pasado ese tiempo hay que subir el fichero otra vez.
    'ttl_minutes' => 30,
];
