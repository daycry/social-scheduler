<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class GettingStartedController extends Controller
{
    public function onboarding(Request $request): RedirectResponse
    {
        $data = $request->validate(['step' => ['required', Rule::in(['brand', 'connection', 'post', 'skipped', 'completed'])]]);
        $request->user()->forceFill(['onboarding_step' => $data['step']])->save();

        return back();
    }

    public function shortcuts(Request $request): RedirectResponse
    {
        $data = $request->validate(['single_key_shortcuts' => ['required', 'boolean']]);
        $request->user()->forceFill($data)->save();

        return back();
    }
}
