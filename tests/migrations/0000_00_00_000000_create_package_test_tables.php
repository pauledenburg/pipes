<?php

declare(strict_types=1);

namespace Tests\migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// @codingStandardsIgnoreLine  
class CreatePackageTestTables_0000_00_00_000000 extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('test', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('first_name');
            $table->string('last_name');
            $table->date('dob');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::drop('test');
    }
}
