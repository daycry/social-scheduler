<?php

namespace App\Policies;

use App\Models\Report;
use App\Models\User;
use App\Support\Ownership;
use Illuminate\Support\Facades\Log;

class ReportPolicy
{
    public function view(User $user, Report $report): bool
    {
        if (Ownership::matches($report, $user)) {
            return true;
        }

        Log::warning('access.denied', ['user_id' => $user->id, 'resource' => 'report', 'resource_id' => $report->id, 'ability' => 'view']);

        return false;
    }

    public function delete(User $user, Report $report): bool
    {
        return $this->view($user, $report);
    }
}
