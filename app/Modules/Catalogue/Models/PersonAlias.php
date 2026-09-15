<?php

declare(strict_types=1);

namespace App\Modules\Catalogue\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonAlias extends CatalogueModel
{
    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
