<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Unit;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Contracts\ITranslatableModel;
use Modules\Core\Models\Concerns\HasTranslations;

class TranslatableModelStub extends Model implements ITranslatableModel
{
    use HasFactory;
    use HasTranslations;

    protected bool $auto_translate_enabled = true;

    public function getTable(): string
    {
        return 'test_translatable';
    }
}
