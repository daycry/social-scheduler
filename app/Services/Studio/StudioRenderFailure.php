<?php

namespace App\Services\Studio;

/** Only deliberately authored messages from this exception may reach an export's public error field. */
class StudioRenderFailure extends \RuntimeException {}
