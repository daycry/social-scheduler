<?php

namespace App\Policies;

use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class ReportPolicy
{
    public function view(User $user, Report $report): bool
    {
        if ($report->user_id === $user->id) {
            return true;
        }

        Log::warning('access.denied', ['user_id' => $user->id, 'resource' => 'report', 'resource_id' => $report->id, 'ability' => 'view']);

        return false;
    }
}
