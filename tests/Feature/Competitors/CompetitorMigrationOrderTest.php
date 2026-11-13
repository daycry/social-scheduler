<?php

/**
 * T-25 (revision): en MySQL, `brand_id` es una FK y el unico (brand_id, username) es el indice que la sostiene: borrarlo antes de crear
 * otro que empiece por `brand_id` falla (error 1553). No hay MySQL en los tests, asi que se comprueba el ORDEN de las operaciones de la
 * migracion (verificacion real pendiente en MySQL: docs/referencia/pendientes-manuales.md).
 */
function competitorMigration(): string
{
    return (string) file_get_contents(database_path('migrations/2026_12_20_090000_add_network_to_competitors.php'));
}

test('up() crea el unico nuevo antes de borrar el antiguo', function () {
    $up = explode('public function down', competitorMigration())[0];

    $create = strpos($up, "unique(['brand_id', 'network', 'username'])");
    $drop = strpos($up, "dropUnique(['brand_id', 'username'])");

    expect($create)->not->toBeFalse()->and($drop)->not->toBeFalse()->and($create)->toBeLessThan($drop);
});

test('down() recrea el unico antiguo antes de borrar el nuevo', function () {
    $down = explode('public function down', competitorMigration())[1];

    $create = strpos($down, "unique(['brand_id', 'username'])");
    $drop = strpos($down, "dropUnique(['brand_id', 'network', 'username'])");

    expect($create)->not->toBeFalse()->and($drop)->not->toBeFalse()->and($create)->toBeLessThan($drop);
});
