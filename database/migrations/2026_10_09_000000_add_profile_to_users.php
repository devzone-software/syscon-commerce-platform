<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('first_name', 80)->nullable();
            $t->string('last_name', 80)->nullable();
            $t->string('document_type', 10)->nullable();
            $t->string('document_number', 15)->nullable();
            $t->string('phone', 9)->nullable();
            $t->string('google_sub')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropUnique(['google_sub']);
            $t->dropColumn(['first_name', 'last_name', 'document_type', 'document_number', 'phone', 'google_sub']);
        });
    }
};
