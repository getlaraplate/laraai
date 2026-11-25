<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Contracts\ISearchableModel;
use Modules\Core\Contracts\ITranslatableModel;
use Modules\Core\Models\Concerns\HasTranslations;
use Modules\Core\Search\Traits\Searchable;
use Override;

/**
 * Translated + embeddable model for exercising per-locale embedding
 * generation (GenerateEmbeddingsJob, Searchable::prepareDataToEmbedByLocale)
 * without depending on a production model.
 *
 * Fallback is disabled so a locale with no real translation row yields no
 * entry, instead of silently reusing the default locale's text.
 */
class TranslatedEmbeddableTestModel extends Model implements ISearchableModel, ITranslatableModel
{
    use HasTranslations;
    use Searchable;

    public $timestamps = false;

    protected $guarded = [];

    protected bool $translation_fallback_enabled = false;

    /**
     * @var list<string>
     */
    protected array $embed = ['title'];

    #[Override]
    protected $table = 'translated_embeddable_test_models';

    #[Override]
    public function getTable(): string
    {
        return 'translated_embeddable_test_models';
    }
}
