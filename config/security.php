<?php

return [
    'hsts_max_age' => env('SECURITY_HSTS_MAX_AGE'),
    'csp_report_global_per_minute' => 300,
    'csp_report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', true),
];
