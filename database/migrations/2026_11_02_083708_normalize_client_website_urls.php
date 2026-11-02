<?php

use App\Models\Client;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Naprawia zapisane wcześniej uszkodzone adresy stron (np. „https://https/domena.pl”). */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('clients')->get(['id', 'website_url']) as $c) {
            $fixed = Client::normalizeUrl($c->website_url);
            if ($fixed && $fixed !== $c->website_url) {
                DB::table('clients')->where('id', $c->id)->update(['website_url' => $fixed]);
            }
        }
    }

    public function down(): void
    {
        // brak – poprawione adresy zostają
    }
};
