<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('email');
            $table->boolean('daily_digest')->default(true)->after('is_admin');
            $table->timestamp('last_login_at')->nullable();
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('is_demo');
        });

        Schema::table('integrations', function (Blueprint $table) {
            $table->timestamp('alerted_at')->nullable()->after('last_error');
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->text('note')->nullable()->after('ai_summary');
        });

        // Ustawienia agencji (nazwa, logo, kontakt, kolor) – klucz/wartość
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::table('reports', fn (Blueprint $t) => $t->dropColumn('note'));
        Schema::table('integrations', fn (Blueprint $t) => $t->dropColumn('alerted_at'));
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('archived_at'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['is_admin', 'daily_digest', 'last_login_at']));
    }
};
