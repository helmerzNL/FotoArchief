<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

try {
    require __DIR__.'/bootstrap.php';
    if (DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'") !== []) {
        throw new RuntimeException('Benchmark seeding requires an empty database; existing data will not be replaced.');
    }
    Artisan::call('migrate', ['--force' => true]);
    Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);
    $user = User::query()->create([
        'name' => 'Synthetic benchmark owner', 'email' => 'benchmark@example.test', 'password' => Hash::make('disposable-benchmark-password'),
    ]);
    $user->roles()->attach(Role::query()->where('key', 'administrator')->firstOrFail());
    for ($batch = 0; $batch < 50; $batch++) {
        $rows = [];
        for ($index = 0; $index < 1000; $index++) {
            $number = $batch * 1000 + $index;
            $year = 1850 + $number % 150;
            $rows[] = [
                'id' => (string) Str::ulid(), 'accession_number' => sprintf('BENCH-%06d', $number),
                'title' => 'Historische straat '.($number % 200).' foto '.$number,
                'description' => 'Synthetische metadata voor capaciteitstest; geen erfgoedmateriaal.',
                'date_precision' => 'year', 'date_earliest' => $year.'-01-01', 'date_latest' => $year.'-12-31',
                'created_by_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::table('assets')->insert($rows);
    }
    DB::statement('ANALYZE assets');
    if (DB::table('assets')->count() !== 50000) {
        throw new RuntimeException('Benchmark requires exactly 50,000 records.');
    }
    echo "Seeded exactly 50,000 synthetic private metadata records.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
