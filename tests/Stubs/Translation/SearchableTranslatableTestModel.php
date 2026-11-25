<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\Translation;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\AI\Tests\Unit\TranslatableModelStubTranslation;
use Modules\Core\Contracts\ITranslatableModel;
use Modules\Core\Models\Concerns\HasTranslations;
use Modules\Core\Search\Traits\Searchable;
use Override;

/**
 * A model that is both translatable and searchable, with vector search on and automatic translation on.
 */
class SearchableTranslatableTestModel extends Model implements ITranslatableModel
{
    use HasFactory;
    use HasTranslations;
    use Searchable;

    protected bool $auto_translate_enabled = true;

    #[Override]
    public function getTable(): string
    {
        return 'test_searchable_translatable';
    }

    public function vectorSearchEnabled(): bool
    {
        return true;
    }

    protected static function getTranslationModelClass(): string
    {
        return TranslatableModelStubTranslation::class;
    }
}
