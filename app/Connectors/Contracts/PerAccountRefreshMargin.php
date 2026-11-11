<?php

namespace App\Connectors\Contracts;

use App\Models\SocialAccount;

/**
 * El margen de renovacion depende de la cuenta (T-18): una cuenta sin forma de renovarse (LinkedIn sin `refresh_token`) no se adelanta,
 * porque marcarla `requiere_reconexion` antes de caducar retendria publicaciones con un token todavia valido. Con margen 0 solo hay
 * trabajo cuando el token ya caduco.
 */
interface PerAccountRefreshMargin
{
    public function refreshMarginHoursFor(SocialAccount $account): int;
}
