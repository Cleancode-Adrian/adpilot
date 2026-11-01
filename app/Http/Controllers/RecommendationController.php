<?php

namespace App\Http\Controllers;

use App\Models\Recommendation;
use Illuminate\Http\Request;

class RecommendationController extends Controller
{
    public function update(Request $request, Recommendation $recommendation)
    {
        $data = $request->validate(['status' => 'required|in:open,done,dismissed']);
        $recommendation->update([
            'status' => $data['status'],
            'resolved_at' => $data['status'] === 'open' ? null : now(),
        ]);

        return back()->with('success', match ($data['status']) {
            'done' => 'Oznaczono jako wykonane – trafi do raportu dla klienta.',
            'dismissed' => 'Rekomendacja odrzucona.',
            default => 'Przywrócono.',
        });
    }
}
