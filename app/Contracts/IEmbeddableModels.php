<?php

declare(strict_types=1);

namespace Modules\AI\Contracts;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Contracts\ISearchableModel;

/**
 * The searchable models whose records get an embedding.
 */
interface IEmbeddableModels
{
    /**
     * @return list<class-string<Model&ISearchableModel>>
     */
    public function all(): array;
}
