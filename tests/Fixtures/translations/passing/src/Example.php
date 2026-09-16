<?php

declare(strict_types=1);

namespace Fixtures\Passing;

use Illuminate\Support\Facades\Lang;

class Example
{
    public function labels(int $count): array
    {
        return [
            trans_choice('catalogue.photos', $count),
            Lang::get('catalogue.help'),
            __("catalogue.double"),
        ];
    }
}
