<?php

namespace App\Console\Commands;

use App\Services\ConnectionExpiryNotifier;
use Illuminate\Console\Command;

class NotifyConnectionExpiry extends Command
{
    protected $signature = 'connections:notify-expiry';

    protected $description = 'Avisa (en la app y, si el usuario lo pide, por correo) de las conexiones que caducan pronto y hay que reconectar: una vez por umbral';

    public function handle(ConnectionExpiryNotifier $notifier): int
    {
        $this->info('Avisos de caducidad enviados: '.$notifier->run());

        return self::SUCCESS;
    }
}
