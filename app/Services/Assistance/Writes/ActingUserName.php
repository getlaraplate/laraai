<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Writes;

use Modules\Core\Models\User;

/**
 * How the person the assistant acts for is named to the model and to the client.
 */
final class ActingUserName
{
    public static function of(User $user): string
    {
        $name = $user->getAttribute('name') ?? $user->getAttribute('username') ?? $user->getAttribute('email');

        return is_scalar($name) ? (string) $name : (string) $user->id;
    }
}
