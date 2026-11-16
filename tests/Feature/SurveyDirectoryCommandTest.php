<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->folder = sys_get_temp_dir().'/fotoarchief-survey-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->folder.'/Straten/Havenstraat');
    File::put($this->folder.'/Straten/Havenstraat/haven-1.JPG', str_repeat('a', 3000));
    File::put($this->folder.'/Straten/haven-2.jpg', str_repeat('b', 1000));
    File::put($this->folder.'/Straten/leeg.jpg', '');
    File::put($this->folder.'/Straten/kaart.avif', str_repeat('c', 5000));
    File::put($this->folder.'/Thumbs.db', 'x');
});

afterEach(function (): void {
    File::deleteDirectory($this->folder);
});

it('counts files per extension and shows what the import would do without storing anything', function (): void {
    expect(Artisan::call('ingest:survey-directory', ['path' => $this->folder, '--json' => true]))->toBe(0);
    $result = json_decode(Artisan::output(), true);

    expect($result['files'])->toBe(5)
        ->and($result['bytes'])->toBe(9001)
        ->and($result['folders'])->toBe(3)
        ->and($result['types']['jpg'])->toMatchArray(['files' => 3, 'bytes' => 4000, 'largest' => 3000, 'average' => 1333, 'too_large' => 1, 'action' => 'import'])
        ->and($result['types']['avif'])->toMatchArray(['files' => 1, 'action' => 'unsupported'])
        ->and($result['types']['db'])->toMatchArray(['files' => 1, 'action' => 'ignored'])
        ->and(array_key_first($result['types']))->toBe('avif');
});

it('prints a readable table and refuses a folder that does not exist', function (): void {
    expect(Artisan::call('ingest:survey-directory', ['path' => $this->folder]))->toBe(0)
        ->and(Artisan::output())->toContain('nog niet ondersteund, overgeslagen')->toContain('Er is niets opgeslagen.');

    expect(Artisan::call('ingest:survey-directory', ['path' => $this->folder.'/bestaat-niet']))->toBe(1);
});
