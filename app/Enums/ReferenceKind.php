<?php

declare(strict_types=1);

namespace Modules\AI\Enums;

/**
 * What a reference of a search item or an assistant citation points at, so a client opens it the right
 * way: `Documentation` is an opaque document key read through the documentation read route, `Content`
 * a canonical `/app/...` reference of application content, `Media` a media id, `Page` an app route.
 * Only `Documentation` and `Content` are produced by the backend today.
 */
enum ReferenceKind: string
{
    case Documentation = 'documentation';
    case Content = 'content';
    case Media = 'media';
    case Page = 'page';
}
