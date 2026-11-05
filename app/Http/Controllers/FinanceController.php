<?php

namespace App\Http\Controllers;

use App\Services\Billing\Profitability;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Finanse (tylko administrator): rentowność klientów. */
class FinanceController extends Controller
{
    public function profitability(Request $request, Profitability $profitability)
    {
        $months = in_array((int) $request->input('months'), [1, 3, 6, 12], true) ? (int) $request->input('months') : 3;

        return Inertia::render('finance/profitability', ['data' => $profitability->build($months)]);
    }
}
