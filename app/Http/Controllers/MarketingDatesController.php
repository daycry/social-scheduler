<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Preferencia por usuario (T-90): mostrar u ocultar las fechas señaladas en el calendario y en su feed .ics. */
class MarketingDatesController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['hidden' => ['required', 'boolean']]);
        $request->user()->forceFill(['hide_marketing_dates' => (bool) $data['hidden']])->save();

        return back()->with('success', $data['hidden'] ? 'Fechas señaladas ocultas.' : 'Fechas señaladas visibles.');
    }
}
