<?php

return [
    // Email de contacto mostrado en /privacy y /terms (exigidas por Google y TikTok).
    'contact_email' => env('LEGAL_CONTACT_EMAIL', env('MAIL_FROM_ADDRESS', 'hello@example.com')),
];
