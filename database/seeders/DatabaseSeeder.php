<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    /** Local development: load the content bundle so the content endpoints work. */
    public function run(): void
    {
        Artisan::call('content:import', ['--path' => config('aabigbook.content.bundle_path')]);
    }
}
