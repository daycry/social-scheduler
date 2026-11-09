<?php
$raw=file_get_contents(__DIR__.'/input.json');
$table=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
$canonical=json_encode($table,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
file_put_contents(__DIR__.'/php-canonical.json',$canonical);
echo json_encode(['bytes'=>strlen($canonical),'sha256'=>hash('sha256',$canonical),'rows'=>count($table['rows']),'integer_bits'=>PHP_INT_SIZE*8],JSON_THROW_ON_ERROR),PHP_EOL;
