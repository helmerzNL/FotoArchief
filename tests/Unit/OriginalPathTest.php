<?php

declare(strict_types=1);

use App\Modules\Ingest\Support\OriginalPath;

it('normalises folder paths to relative, slash-separated NFC', function (string $input, string $expected): void {
    expect(OriginalPath::normalize($input))->toBe($expected);
})->with([
    'windows separators' => ['Straten\\Havenstraat\\foto 1.jpg', 'Straten/Havenstraat/foto 1.jpg'],
    'dot and empty segments' => ['./a//b/c.jpg', 'a/b/c.jpg'],
    'decomposed accents' => ["Caf\u{65}\u{301}/x.jpg", "Caf\u{E9}/x.jpg"],
]);

it('rejects paths that are not safe provenance', function (string $input): void {
    expect(fn () => OriginalPath::normalize($input))->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => [''],
    'absolute' => ['/etc/passwd'],
    'drive letter' => ['C:\\x.jpg'],
    'parent traversal' => ['../x.jpg'],
    'embedded traversal' => ['a/../../x.jpg'],
    'control character' => ["a\0b.jpg"],
    'too long' => [str_repeat('a', 1001)],
]);
