<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('job_title')->nullable()->after('team');
            $table->text('bio')->nullable()->after('job_title');
            $table->string('address_line_1')->nullable()->after('address');
            $table->string('address_line_2')->nullable()->after('address_line_1');
            $table->string('city')->nullable()->after('address_line_2');
            $table->string('region')->nullable()->after('city');
            $table->string('postal_code', 24)->nullable()->after('region');
            $table->string('country_code', 2)->nullable()->after('postal_code');
            $table->string('locale', 10)->nullable()->after('country_code');
            $table->string('timezone')->nullable()->after('locale');
            $table->string('linkedin_url', 2048)->nullable()->after('timezone');
            $table->string('website_url', 2048)->nullable()->after('linkedin_url');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'job_title',
                'bio',
                'address_line_1',
                'address_line_2',
                'city',
                'region',
                'postal_code',
                'country_code',
                'locale',
                'timezone',
                'linkedin_url',
                'website_url',
            ]);
        });
    }
};
