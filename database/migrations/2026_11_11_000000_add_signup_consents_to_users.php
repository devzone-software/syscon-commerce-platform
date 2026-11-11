<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Consents given on the sign-up form: SMS/WhatsApp contact and acceptance of terms and privacy. */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('contact_consent')->default(false);
            $table->timestamp('terms_accepted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['contact_consent', 'terms_accepted_at']));
    }
};
