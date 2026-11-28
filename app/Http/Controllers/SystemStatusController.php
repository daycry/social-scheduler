<?php

namespace App\Http\Controllers;

use App\Services\SystemStatus;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SystemStatusController extends Controller
{
    public function __invoke(Request $request, SystemStatus $status): Response
    {
        return Inertia::render('System/Index', ['report' => $status->snapshot($request->user())]);
    }
}
