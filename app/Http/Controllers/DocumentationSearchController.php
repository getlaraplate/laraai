<?php

declare(strict_types=1);

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\AI\Http\Requests\DocumentationSearchRequest;
use Modules\AI\Services\Assistance\AssistantCapabilities;
use Modules\AI\Services\Documentation\Search\DocumentationPassage;
use Modules\AI\Services\Documentation\Search\DocumentationSearch;
use Modules\Core\Helpers\ResponseBuilder;
use Modules\Core\Models\User;
use Nwidart\Modules\Exceptions\ModuleNotFoundException;
use Nwidart\Modules\Facades\Module;
use RuntimeException;

/**
 * Passages of the end-user documentation of one module, for the command palette. It calls no
 * language model: it returns what the assistant would cite, as the signed-in user may read it.
 */
final class DocumentationSearchController extends Controller
{
    private const string MODULE_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    public function __construct(private readonly AssistantCapabilities $capabilities) {}

    /**
     * The checks run in order (user, guest, feature, module) before the search text is validated,
     * so a refused caller learns nothing about its input. The search runs only once all pass.
     */
    public function search(Request $request, string $module): JsonResponse
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        if ($user->isGuest()) {
            abort(Response::HTTP_FORBIDDEN);
        }

        if (! $this->capabilities->help()) {
            return response()->json(['code' => 'FEATURE_DISABLED', 'message' => 'The documentation is not available.'], Response::HTTP_FORBIDDEN);
        }

        if (! $this->isEnabledModule($module)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $query = resolve(DocumentationSearchRequest::class)->searchText();

        try {
            $passages = resolve(DocumentationSearch::class)->search($user, $module, $query);
        } catch (RuntimeException $exception) {
            report($exception);

            return new ResponseBuilder($request)
                ->setError('The documentation search is not available.')
                ->setStatus(Response::HTTP_SERVICE_UNAVAILABLE)
                ->json();
        }

        return new ResponseBuilder($request)
            ->setData(array_map(static fn (DocumentationPassage $passage): array => $passage->toArray(), $passages))
            ->json();
    }

    /**
     * Installed and enabled, checked like Core's application content retrieval does. An unknown
     * module is not an error of the module repository here: it is a 404.
     */
    private function isEnabledModule(string $module): bool
    {
        if (preg_match(self::MODULE_PATTERN, $module) !== 1) {
            return false;
        }

        try {
            return Module::isEnabled(Str::studly($module));
        } catch (ModuleNotFoundException) {
            return false;
        }
    }
}
