<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Audyt zbiorczy: ponowny audyt tej samej listy (porównanie z poprzednim) i powiadomienie e-mail po zakończeniu. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_batches', function (Blueprint $table) {
            $table->foreignId('previous_id')->nullable()->after('id')->constrained('audit_batches')->nullOnDelete(); // poprzedni audyt tej listy
            $table->timestamp('notified_at')->nullable()->after('finished_at');
        });
    }

    public function down(): void
    {
        Schema::table('audit_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('previous_id');
            $table->dropColumn('notified_at');
        });
    }
};
