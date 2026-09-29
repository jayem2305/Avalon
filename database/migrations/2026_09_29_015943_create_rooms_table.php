<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each room holds its whole game as one JSON document (jsonb on PostgreSQL),
     * see App\Game\Avalon for its shape.
     */
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('code', 4)->unique();
            $table->jsonb('state');
            $table->timestamps();
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
