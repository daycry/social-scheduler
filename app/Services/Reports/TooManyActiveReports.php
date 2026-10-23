<?php

namespace App\Services\Reports;

use InvalidArgumentException;

/** Se superó el límite de informes en curso por usuario. */
class TooManyActiveReports extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('Ya hay varios informes en curso. Espera a que terminen.');
    }
}
