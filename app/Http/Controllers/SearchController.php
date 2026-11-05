<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\CareTask;
use App\Models\Client;
use App\Models\Quote;
use Carbon\Carbon;
use Illuminate\Http\Request;

/** Szybkie wyszukiwanie (Ctrl+K): klienci, agencje, oferty, zadania. */
class SearchController extends Controller
{
    public function __invoke(Request $request)
    {
        $q = trim((string) $request->input('q'));
        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
        $results = [];

        foreach (Client::where(fn ($w) => $w->where('name', 'like', $like)->orWhere('website_url', 'like', $like)
            ->orWhere('contact_name', 'like', $like)->orWhere('contact_email', 'like', $like)->orWhere('billing_name', 'like', $like))
            ->orderByRaw('archived_at is not null')->orderBy('name')->limit(8)->get(['id', 'name', 'website_url', 'archived_at']) as $c) {
            $results[] = ['type' => 'Klient', 'title' => $c->name, 'hint' => preg_replace('#^https?://#', '', $c->website_url).($c->archived_at ? ' · archiwum' : ''), 'url' => "/clients/{$c->id}"];
        }
        foreach (Agency::where(fn ($w) => $w->where('name', 'like', $like)->orWhere('contact_name', 'like', $like)->orWhere('contact_email', 'like', $like))
            ->orderBy('name')->limit(5)->get(['id', 'name', 'contact_name']) as $a) {
            $results[] = ['type' => 'Agencja', 'title' => $a->name, 'hint' => $a->contact_name, 'url' => "/agencies/{$a->id}"];
        }
        if ($request->user()->is_admin) {
            foreach (Quote::where(fn ($w) => $w->where('number', 'like', $like)->orWhere('title', 'like', $like))->with('client:id,name')
                ->latest('id')->limit(5)->get(['id', 'client_id', 'number', 'title']) as $o) {
                $results[] = ['type' => 'Oferta', 'title' => "{$o->number} – {$o->title}", 'hint' => $o->client?->name, 'url' => "/quotes/{$o->id}"];
            }
        }
        foreach (CareTask::where('title', 'like', $like)->where('status', 'todo')->with('client:id,name')->orderBy('due_date')->limit(6)
            ->get(['id', 'client_id', 'title', 'due_date']) as $t) {
            $results[] = ['type' => 'Zadanie', 'title' => $t->title, 'hint' => $t->client?->name.' · do '.Carbon::parse($t->due_date)->format('d.m'), 'url' => "/clients/{$t->client_id}/care"];
        }

        return response()->json(['results' => $results]);
    }
}
