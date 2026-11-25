<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Unit;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Contracts\ISearchableModel;
use Modules\Core\Search\Traits\Searchable;

class SearchableModelStub extends Model implements ISearchableModel
{
    use HasFactory;
    use Searchable;

    // Protected, like real searchable models (Content, etc.) — the listener must
    // reach it through the trait's public isEmbeddable(), not by touching this
    // property directly (which fails silently via Eloquent __isset).
    protected array $embed = ['title'];

    public function getTable(): string
    {
        return 'test_searchable';
    }

    public function prepareDataToEmbed(): ?string
    {
        return null;
    }
}
