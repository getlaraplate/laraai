<?php

declare(strict_types=1);

namespace Modules\AI\Services\Tools;

use function class_uses_trait;
use function user_class;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Fluent;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Enums\WriteProposalStatus;
use Modules\AI\Models\WriteProposal;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\AI\Services\Assistance\Writes\ActingUserName;
use Modules\AI\Services\Assistance\Writes\WriteApplyResult;
use Modules\AI\Services\Assistance\Writes\WriteProposalService;
use Modules\Core\Approvals\Operation;
use Modules\Core\Casts\CrudRequestData;
use Modules\Core\Casts\DetailRequestData;
use Modules\Core\Casts\ListRequestData;
use Modules\Core\Casts\ModifyRequestData;
use Modules\Core\Casts\SearchRequestData;
use Modules\Core\Enums\TimeBucket;
use Modules\Core\Exceptions\AggregateException;
use Modules\Core\Http\Requests\DetailRequest;
use Modules\Core\Http\Requests\ListRequest;
use Modules\Core\Http\Requests\ModifyRequest;
use Modules\Core\Http\Requests\PendingApprovalsRequest;
use Modules\Core\Http\Requests\SearchRequest;
use Modules\Core\Models\Concerns\HasApprovals;
use Modules\Core\Models\DynamicEntity;
use Modules\Core\Models\User;
use Modules\Core\Services\Authorization\AuthorizationService;
use Modules\Core\Services\Crud\Aggregate\AggregateGroup;
use Modules\Core\Services\Crud\Aggregate\AggregateLimits;
use Modules\Core\Services\Crud\Aggregate\AggregateMeasure;
use Modules\Core\Services\Crud\Aggregate\AggregateQuery;
use Modules\Core\Services\Crud\Aggregate\AggregateResult;
use Modules\Core\Services\Crud\CrudService;
use Modules\Core\Services\Crud\DTOs\CrudResult;
use Modules\Core\Services\Export\TabularCsvExporter;
use Modules\Core\Services\Export\TabularPdfExporter;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Exposes Core generic-CRUD operations as AI tools for the in-app assistant,
 * gated by an opt-in per-entity allowlist (`ai.features.tools.crud.entities`).
 *
 * Every call is delegated to {@see CrudService}, which enforces the acting
 * user's permission and row-level ACL — this provider adds no data access of
 * its own. A tool is exposed only for an operation the user is actually
 * permitted to perform: if the user cannot do it, no tool is offered (there is
 * no "escalate to approval" path). Approval, when it happens, belongs to the
 * model: entities using {@see HasApprovals}
 * capture writes as pending modifications on save unless the writer holds the
 * `approve` credit — that moderation is applied by Core inside the write, not
 * by this provider. All exposed tools therefore run inline.
 *
 * Writes are offered fail-closed: an entity without approvals gets no write
 * tool unless the operator listed it under `ai.features.tools.crud.unmoderated_writes`.
 * A decision on a pending change (approve, disapprove) is never offered.
 */
final readonly class CrudToolProvider implements ContextualToolProviderInterface
{
    /**
     * How many times the model may call one of these tools in a turn: they read records, and a model
     * that keeps asking is looping.
     */
    public const int MAX_RUNS = 3;

    /**
     * `approve` and `disapprove` are not here on purpose: a decision on a pending change is made by a
     * person in the panel. A model that could vote could be made to vote.
     */
    private const array VALID_OPERATIONS = ['view', 'list', 'detail', 'search', 'summarize', 'export', 'create', 'update', 'delete', 'bulk_update', 'bulk_delete', 'pending_approvals'];

    /**
     * The operations that change data.
     */
    private const array WRITE_OPERATIONS = ['create', 'update', 'delete', 'bulk_update', 'bulk_delete'];

    /**
     * Upper bound on rows written to an export file.
     */
    private const int EXPORT_ROW_CAP = 5000;

    /**
     * Hard upper bound on records a single bulk operation may affect. A bulk
     * call matching more than this refuses to apply and asks for a narrower
     * filter, so a runaway update/delete is impossible.
     */
    private const int BULK_CAP = 200;

    /**
     * Aggregation functions the `summarize` tool takes as metrics. The count of each group is implicit.
     */
    private const array SUMMARIZE_FUNCTIONS = ['sum', 'avg', 'min', 'max'];

    /**
     * Maps a tool operation to the CRUD permission ability it needs.
     */
    private const array ABILITY = [
        'view' => 'select',
        'list' => 'select',
        'detail' => 'select',
        'search' => 'select',
        'summarize' => 'select',
        'export' => 'select',
        'create' => 'insert',
        'update' => 'update',
        'delete' => 'forceDelete',
        'bulk_update' => 'update',
        'bulk_delete' => 'forceDelete',
        'pending_approvals' => 'approve',
    ];

    public function __construct(
        private CrudService $crud,
        private AuthorizationService $authorization,
        private Request $request,
        private TabularCsvExporter $csvExporter,
        private TabularPdfExporter $pdfExporter,
        private WriteProposalService $proposals,
    ) {}

    public function tools(AssistantAccessContext $context): array
    {
        if ($context->profile !== AssistantProfile::InAppAssistance) {
            return [];
        }

        if (! $this->actingUserMatches($context)) {
            return [];
        }

        return array_map(
            fn (array $offered): ToolDefinition => $this->buildTool($offered['module'], $offered['entity'], $offered['operation'], $context),
            $this->offeredOperations(),
        );
    }

    /**
     * What the assistant may do for the acting person right now: the opted-in entities and operations
     * that the person is permitted to perform. Tools are built from exactly this list, and the client is
     * told the same thing, so what the person is shown and what the model is offered cannot differ.
     *
     * @return list<array{module: string, entity: string, operation: string, write: bool, requires_approval: bool}>
     */
    public function offeredOperations(): array
    {
        $offered = [];

        foreach ($this->allowlist() as $entityKey => $operations) {
            [$module, $entity] = $this->splitEntityKey((string) $entityKey);

            if ($module === null) {
                continue;
            }

            $model = $this->resolveModel($module, $entity);

            if (! $model instanceof Model) {
                continue;
            }

            foreach ($operations as $operation) {
                if (! $this->isOperationOffered((string) $entityKey, $model, $operation)) {
                    continue;
                }

                $write = in_array($operation, self::WRITE_OPERATIONS, true);
                $offered[] = [
                    'module' => $module,
                    'entity' => $entity,
                    'operation' => (string) $operation,
                    'write' => $write,
                    'requires_approval' => $write && $this->wouldRequireApproval($model, (string) $operation),
                ];
            }
        }

        return $offered;
    }

    /**
     * Applies a stored proposal as the person who confirmed it: through CrudService, so permission and
     * row-level ACL are decided again now, and only if the operator still offers the operation.
     */
    public function applyProposal(WriteProposal $proposal): WriteApplyResult
    {
        $user = $this->request->user();
        $model = $this->resolveModel($proposal->module, $proposal->entity);
        $entityKey = mb_strtolower($proposal->module . '.' . $proposal->entity);

        $offered = $user instanceof User
            && (int) $user->getKey() === (int) $proposal->user_id
            && $model instanceof Model
            && $this->isOperationStillListed($entityKey, $proposal->operation)
            && $this->isOperationOffered($entityKey, $model, $proposal->operation);

        if (! $offered) {
            return new WriteApplyResult(WriteProposalStatus::Failed, ['error' => 'This change is no longer available to you.']);
        }

        $payload = $proposal->payload;
        $attributes = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];

        return match ($proposal->operation) {
            'create' => $this->singleResult($this->runCreate($proposal->module, $proposal->entity, $attributes)),
            'update' => $this->singleResult($this->runUpdate($proposal->module, $proposal->entity, $payload['id'] ?? null, $attributes)),
            'delete' => $this->singleResult($this->runDelete($proposal->module, $proposal->entity, $payload['id'] ?? null)),
            'bulk_update', 'bulk_delete' => $this->applyBulk($proposal->operation, $proposal->module, $proposal->entity, is_array($payload['ids'] ?? null) ? $payload['ids'] : [], $attributes),
            default => new WriteApplyResult(WriteProposalStatus::Failed, ['error' => 'Unsupported operation.']),
        };
    }

    /**
     * The figures of an aggregate request on an entity, as the acting person may read it: through
     * {@see CrudService::aggregate()}, so the permission, the row-level ACL and the structured filters decide
     * which rows every figure covers. The caller decides whether the entity is offered to the assistant.
     *
     * @param  mixed  $filters  Structured filters of the list vocabulary; what is not a filter node is ignored.
     *
     * @throws AggregateException when the engine refuses the request: the caller turns it into its own answer
     */
    public function aggregate(string $module, string $entity, mixed $filters, AggregateQuery $query): AggregateResult
    {
        $normalizedFilters = $this->normalizeFilters($filters);
        $validated = $normalizedFilters === [] ? [] : ['filters' => $normalizedFilters];

        $data = new ListRequestData($this->makeRequest(ListRequest::class), $entity, $validated, $this->primaryKey($module, $entity), $module);

        return $this->crud->aggregate($data, $query);
    }

    /**
     * Whether this operation is offered to the acting user on this entity: opted in by the operator,
     * allowed as a write where it is one, and permitted to the user. The same answer decides what a
     * tool is built for and whether a stored proposal may still be applied.
     */
    private function isOperationOffered(string $entityKey, Model $model, string $operation): bool
    {
        if (in_array($operation, self::WRITE_OPERATIONS, true) && ! $this->writesAllowed($entityKey, $model)) {
            return false;
        }

        return $this->userCanAll($model, $this->requiredAbilities($operation));
    }

    /**
     * Whether the assistant may be offered write operations on this entity at all.
     *
     * An entity whose model uses approvals sends a write to a vote unless the writer is allowed to write
     * directly, so listing it is enough. An entity without approvals applies a write as it comes: it is
     * offered only when the operator listed it under `ai.features.tools.crud.unmoderated_writes`, which
     * says, on purpose, "applied directly, no vote".
     */
    private function writesAllowed(string $entityKey, Model $model): bool
    {
        if (class_uses_trait($model, HasApprovals::class)) {
            return true;
        }

        $unmoderated = config('ai.features.tools.crud.unmoderated_writes', []);

        if (! is_array($unmoderated)) {
            return false;
        }

        return in_array(mb_strtolower($entityKey), array_map(static fn (mixed $key): string => mb_strtolower((string) $key), $unmoderated), true);
    }

    /**
     * @return array<string, list<string>>
     */
    private function allowlist(): array
    {
        $entities = config('ai.features.tools.crud.entities', []);

        if (! is_array($entities)) {
            return [];
        }

        $normalized = [];

        foreach ($entities as $key => $operations) {
            if (! is_string($key) || ! is_array($operations)) {
                continue;
            }

            $normalized[$key] = array_values(array_filter(
                array_map(static fn (mixed $op): string => mb_strtolower((string) $op), $operations),
                static fn (string $op): bool => in_array($op, self::VALID_OPERATIONS, true),
            ));
        }

        return $normalized;
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function splitEntityKey(string $key): array
    {
        if (! str_contains($key, '.')) {
            return [null, $key];
        }

        [$module, $entity] = explode('.', $key, 2);

        return [$module === '' ? null : $module, $entity];
    }

    private function actingUserMatches(AssistantAccessContext $context): bool
    {
        $identifier = $this->request->user()?->getAuthIdentifier();

        return $identifier !== null
            && $context->userId !== null
            && $context->userId === (string) $identifier;
    }

    private function buildTool(string $module, string $entity, string $operation, AssistantAccessContext $context): ToolDefinition
    {
        $name = sprintf('crud_%s_%s_%s', $operation, mb_strtolower($module), mb_strtolower($entity));
        $label = mb_strtolower($module) . '.' . mb_strtolower($entity);

        return new ToolDefinition(
            name: $name,
            description: $this->describe($operation, $label),
            parameters: $this->parameters($operation),
            riskLevel: 'low',
            handler: $this->handlerFor($operation, $module, $entity, $context),
            maxRuns: self::MAX_RUNS,
            entity: $label,
            operation: $operation,
        );
    }

    private function resolveModel(string $module, string $entity): ?Model
    {
        try {
            return DynamicEntity::resolve($entity, request: $this->request, module: $module);
        } catch (Throwable) {
            return null;
        }
    }

    private function userCan(Model $model, string $ability): bool
    {
        try {
            return $this->authorization->checkPermission(
                $this->request,
                $model->getTable(),
                $ability,
                $model->getConnectionName(),
            );
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  list<string>  $abilities
     */
    private function userCanAll(Model $model, array $abilities): bool
    {
        foreach ($abilities as $ability) {
            if (! $this->userCan($model, $ability)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The CRUD abilities an operation requires to be offered. Bulk operations
     * also require `select`, since they resolve the affected records by reading
     * them first.
     *
     * @return list<string>
     */
    private function requiredAbilities(string $operation): array
    {
        return match ($operation) {
            'bulk_update' => ['select', 'update'],
            'bulk_delete' => ['select', 'forceDelete'],
            default => [self::ABILITY[$operation]],
        };
    }

    private function describe(string $operation, string $label): string
    {
        return match ($operation) {
            'view' => "Propose a {$label} table view (filters/sort) for the UI to apply and load itself. Does not fetch data — use this when the user wants to filter the on-screen table.",
            'list' => "List {$label} records the current user may read.",
            'detail' => "Fetch a single {$label} record by id.",
            'search' => "Full-text search {$label} records.",
            'summarize' => "Aggregate {$label} records: group by one column (optionally cut a date column into day, week, month, quarter or year buckets over a period) and compute a count plus optional sum/avg/min/max metrics. Honours the same structured filters as list.",
            'export' => "Export {$label} records the current user may read to a CSV or PDF file. Honours the same structured filters and sort as list; returns the file inline (base64).",
            'create' => "Propose creating a {$label} record. Nothing is created: the proposal waits for the signed-in person to confirm it in the interface. Tell them what you propose and that it needs their confirmation.",
            'update' => "Propose changing a {$label} record by id. Nothing is changed: the proposal waits for the signed-in person to confirm it in the interface. Tell them what you propose and that it needs their confirmation.",
            'delete' => "Propose deleting a {$label} record by id. Nothing is deleted: the proposal waits for the signed-in person to confirm it in the interface. Tell them what you propose and that it needs their confirmation.",
            'bulk_update' => "Propose changing many {$label} records matched by filters, at most " . self::BULK_CAP . ' at a time. Nothing is changed: the proposal lists the matched records and waits for the signed-in person to confirm it in the interface.',
            'bulk_delete' => "Propose deleting many {$label} records matched by filters, at most " . self::BULK_CAP . ' at a time. Nothing is deleted: the proposal lists the matched records and waits for the signed-in person to confirm it in the interface.',
            'pending_approvals' => "List pending {$label} changes awaiting approval, each with its author; optionally filter by author (name, email or user id).",
            default => "Operate on {$label}.",
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parameters(string $operation): array
    {
        $filters = ['name' => 'filters', 'type' => 'array', 'required' => false, 'items' => ['type' => 'object'], 'description' => 'Structured filters combined with AND. Each item is {property, operator, value}; operator is one of =, !=, >, >=, <, <=, like, in, between (in/between take an array value). Nested groups {operator: and|or, filters: [...]} are allowed.'];
        $sort = ['name' => 'sort', 'type' => 'array', 'required' => false, 'items' => ['type' => 'object'], 'description' => 'Sort order. Each item is {property, direction} where direction is asc or desc.'];
        $limit = ['name' => 'limit', 'type' => 'integer', 'description' => 'Maximum rows to return.', 'required' => false];

        $page = ['name' => 'page', 'type' => 'integer', 'description' => 'Page number (1-based).', 'required' => false];

        return match ($operation) {
            'view', 'list' => [
                $filters,
                $sort,
                $limit,
                $page,
            ],
            'search' => [
                ['name' => 'query', 'type' => 'string', 'description' => 'Text to search for.', 'required' => true],
                $filters,
                $sort,
                $limit,
            ],
            'summarize' => [
                $filters,
                ['name' => 'group_by', 'type' => 'array', 'required' => false, 'maxItems' => 1, 'items' => ['type' => 'string'], 'description' => 'The one column to group by: at most one is allowed. Omit for a single overall bucket.'],
                ['name' => 'metrics', 'type' => 'array', 'required' => false, 'items' => ['type' => 'object'], 'description' => 'Numeric metrics per group. Each item is {property, function} where function is one of sum, avg, min, max; sum and avg need a numeric column that is not a key. A per-group count is always returned.'],
                ['name' => 'bucket', 'type' => 'string', 'required' => false, 'enum' => TimeBucket::names(), 'description' => 'Cuts the date column named in group_by into buckets (a week starts on Monday). Needs a period over the same column.'],
                ['name' => 'period', 'type' => 'object', 'required' => false, 'description' => 'Restricts the rows to a date range of a date column: {field, and exactly one of last: {n, unit: day|week|month|quarter|year}, preset: today|yesterday|this_week|last_week|this_month|last_month|this_quarter|last_quarter|this_year|last_year, or from and to as YYYY-MM-DD}.'],
            ],
            'export' => [
                ['name' => 'format', 'type' => 'string', 'required' => false, 'description' => 'Output format: csv (default) or pdf.'],
                $filters,
                $sort,
                ['name' => 'columns', 'type' => 'array', 'required' => false, 'items' => ['type' => 'string'], 'description' => 'Column names to include, in order. Omit to export all columns of the first row.'],
                $limit,
            ],
            'detail', 'delete' => [
                ['name' => 'id', 'type' => 'string', 'description' => 'Record identifier.', 'required' => true],
            ],
            'create' => [
                ['name' => 'attributes', 'type' => 'object', 'description' => 'Field values for the new record.', 'required' => true],
            ],
            'update' => [
                ['name' => 'id', 'type' => 'string', 'description' => 'Record identifier.', 'required' => true],
                ['name' => 'attributes', 'type' => 'object', 'description' => 'Field values to change.', 'required' => true],
            ],
            'bulk_update' => [
                $filters,
                ['name' => 'attributes', 'type' => 'object', 'description' => 'Field values to apply to every matched record.', 'required' => true],
            ],
            'bulk_delete' => [
                $filters,
            ],
            'pending_approvals' => [
                ['name' => 'author', 'type' => 'string', 'description' => 'Optional filter: modifier name, email, or user id.', 'required' => false],
            ],
            default => [],
        };
    }

    /**
     * Named-argument handler matching the tool's parameter names (NeuronAI
     * invokes tools with named arguments, as GraphToolProvider does).
     */
    private function handlerFor(string $operation, string $module, string $entity, AssistantAccessContext $context): callable
    {
        return match ($operation) {
            'view' => fn (mixed $filters = null, mixed $sort = null, mixed $limit = null, mixed $page = null): array => $this->runView($module, $entity, $filters, $sort, $limit, $page),
            'list' => fn (mixed $filters = null, mixed $sort = null, mixed $limit = null, mixed $page = null): array => $this->runList($module, $entity, $filters, $sort, $limit, $page),
            'search' => fn (mixed $query = null, mixed $filters = null, mixed $sort = null, mixed $limit = null): array => $this->runSearch($module, $entity, $query, $filters, $sort, $limit),
            'summarize' => fn (mixed $filters = null, mixed $group_by = null, mixed $metrics = null, mixed $bucket = null, mixed $period = null): array => $this->runSummarize($module, $entity, $filters, $group_by, $metrics, $bucket, $period),
            'export' => fn (mixed $format = null, mixed $filters = null, mixed $sort = null, mixed $columns = null, mixed $limit = null): array => $this->runExport($module, $entity, $format, $filters, $sort, $columns, $limit),
            'detail' => fn (mixed $id = null): array => $this->runDetail($module, $entity, $id),
            'create' => fn (mixed $attributes = null): array => $this->proposeWrite($context, 'create', $module, $entity, ['attributes' => $this->toArray($attributes)]),
            'update' => fn (mixed $id = null, mixed $attributes = null): array => $this->proposeWrite($context, 'update', $module, $entity, ['id' => $this->toString($id), 'attributes' => $this->toArray($attributes)]),
            'delete' => fn (mixed $id = null): array => $this->proposeWrite($context, 'delete', $module, $entity, ['id' => $this->toString($id)]),
            'bulk_update' => fn (mixed $filters = null, mixed $attributes = null): array => $this->proposeWrite($context, 'bulk_update', $module, $entity, ['filters' => $this->normalizeFilters($filters), 'attributes' => $this->toArray($attributes)]),
            'bulk_delete' => fn (mixed $filters = null): array => $this->proposeWrite($context, 'bulk_delete', $module, $entity, ['filters' => $this->normalizeFilters($filters)]),
            'pending_approvals' => fn (mixed $author = null): array => $this->runPendingApprovals($module, $entity, $author),
            default => static fn (): array => ['error' => 'Unsupported operation.'],
        };
    }

    /**
     * Configure mode: return the filter/sort spec for the UI to apply and load
     * itself. No data is fetched. The frontend's own load enforces the ACL.
     *
     * @return array<string, mixed>
     */
    private function runView(string $module, string $entity, mixed $filters, mixed $sort, mixed $limit, mixed $page): array
    {
        return [
            'apply' => true,
            'request' => [
                'verb' => 'view',
                'module' => $module,
                'entity' => $entity,
                'filters' => $this->normalizeFilters($filters),
                'sort' => $this->normalizeSort($sort),
                'page' => $this->toInt($page),
                'limit' => $this->toInt($limit),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runList(string $module, string $entity, mixed $filters, mixed $sort, mixed $limit, mixed $page): array
    {
        $normalizedFilters = $this->normalizeFilters($filters);
        $normalizedSort = $this->normalizeSort($sort);
        $limitInt = $this->toInt($limit);
        $pageInt = $this->toInt($page);

        $request = ['verb' => 'list', 'module' => $module, 'entity' => $entity, 'filters' => $normalizedFilters, 'sort' => $normalizedSort, 'page' => $pageInt, 'limit' => $limitInt];

        try {
            $validated = [];

            if ($normalizedFilters !== []) {
                $validated['filters'] = $normalizedFilters;
            }

            if ($normalizedSort !== []) {
                $validated['sort'] = $normalizedSort;
            }

            if ($limitInt !== null) {
                $validated['limit'] = $limitInt;
            }

            if ($pageInt !== null) {
                $validated['page'] = $pageInt;
            }

            $data = new ListRequestData($this->makeRequest(ListRequest::class), $entity, $validated, $this->primaryKey($module, $entity), $module);

            return $this->present($this->crud->list($data), $request);
        } catch (Throwable $exception) {
            return $this->fail($request, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runSearch(string $module, string $entity, mixed $query, mixed $filters, mixed $sort, mixed $limit): array
    {
        $qs = $this->toString($query);
        $normalizedFilters = $this->normalizeFilters($filters);
        $normalizedSort = $this->normalizeSort($sort);
        $limitInt = $this->toInt($limit);

        $request = ['verb' => 'search', 'module' => $module, 'entity' => $entity, 'query' => $qs, 'filters' => $normalizedFilters, 'sort' => $normalizedSort, 'limit' => $limitInt];

        try {
            $validated = ['qs' => $qs];

            if ($normalizedFilters !== []) {
                $validated['filters'] = $normalizedFilters;
            }

            if ($normalizedSort !== []) {
                $validated['sort'] = $normalizedSort;
            }

            if ($limitInt !== null) {
                $validated['limit'] = $limitInt;
            }

            $data = new SearchRequestData($this->makeRequest(SearchRequest::class), $entity, $validated, $this->primaryKey($module, $entity), $module);

            return $this->present($this->crud->search($data), $request);
        } catch (Throwable $exception) {
            return $this->fail($request, $exception);
        }
    }

    /**
     * Group-by aggregation on Core's aggregate engine: the figures are computed by the database under the
     * acting user's permission and row-level ACL, and no row is read into PHP. The answer keeps the compact
     * shape the model reads (a count and the requested metrics per group), with the same figures for every
     * matching row under `total`.
     *
     * The engine groups by one dimension, so a second `group_by` column is refused, and a refusal of the
     * engine or of its validator comes back in the error shape with its code.
     *
     * @return array<string, mixed>
     */
    private function runSummarize(string $module, string $entity, mixed $filters, mixed $groupBy, mixed $metrics, mixed $bucket, mixed $period): array
    {
        $normalizedFilters = $this->normalizeFilters($filters);
        $groups = $this->normalizeStringList($groupBy);
        $metricSpecs = $this->normalizeMetrics($metrics);
        $bucketName = $this->toString($bucket);
        $periodSpec = $period === '' || $period === [] ? null : $period;

        $request = [
            'verb' => 'summarize',
            'module' => $module,
            'entity' => $entity,
            'filters' => $normalizedFilters,
            'group_by' => $groups,
            'metrics' => $metricSpecs,
            'bucket' => $bucketName === '' ? null : $bucketName,
            'period' => is_array($periodSpec) ? $periodSpec : null,
        ];

        if (count($groups) > 1) {
            return ['request' => $request, 'error' => 'Only one group_by column is allowed: name one column, or summarize once per column.'];
        }

        if ($bucketName !== '' && $groups === []) {
            return ['request' => $request, 'error' => 'A bucket cuts the date column named in group_by: name that column, or leave the bucket out.'];
        }

        try {
            $query = AggregateQuery::fromArray([
                'measures' => $this->summarizeMeasures($metricSpecs),
                'group_by' => $groups === [] ? null : ['field' => $groups[0], 'bucket' => $bucketName === '' ? null : $bucketName],
                'period' => $periodSpec,
            ], AggregateLimits::fromConfig());

            return $this->presentSummary($this->aggregate($module, $entity, $normalizedFilters, $query), $query, $request);
        } catch (AggregateException $exception) {
            return ['request' => $request, ...$this->aggregateRefusal($exception)];
        } catch (Throwable $exception) {
            return $this->fail($request, $exception);
        }
    }

    /**
     * The aggregate engine's measures for the metrics of a summarize call: the count of the rows first, then each
     * metric once, so that repeating one is not a refusal.
     *
     * @param  list<array{property: string, function: string}>  $metrics
     * @return list<array{fn: string, field?: string}>
     */
    private function summarizeMeasures(array $metrics): array
    {
        $measures = ['count' => ['fn' => 'count']];

        foreach ($metrics as $metric) {
            $measures[$metric['function'] . '(' . $metric['property'] . ')'] = ['fn' => $metric['function'], 'field' => $metric['property']];
        }

        return array_values($measures);
    }

    /**
     * The result of the engine in the compact shape of the tool: a list of `{group, count, metrics}` (one entry
     * with an empty group when the call has no group), the figures of every matching row, the rest of the groups
     * when the engine left some out, and the facts the model should state (the currency and its warnings).
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function presentSummary(AggregateResult $result, AggregateQuery $query, array $request): array
    {
        $group = $query->group;
        $data = $group instanceof AggregateGroup
            ? array_map(fn (array $entry): array => ['group' => [$group->field => $entry['key']], ...$this->summaryFigures($entry['values'], $query->measures)], $result->series)
            : [['group' => [], ...$this->summaryFigures($result->total, $query->measures)]];

        $rowCount = is_int($result->meta['row_count'] ?? null) ? $result->meta['row_count'] : 0;
        $meta = ['total_records' => $rowCount, 'aggregated_rows' => $rowCount, 'truncated' => ($result->meta['truncated'] ?? false) === true];

        if (is_string($result->meta['currency'] ?? null)) {
            $meta['currency'] = $result->meta['currency'];
        }

        if (is_array($result->meta['warnings'] ?? null) && $result->meta['warnings'] !== []) {
            $meta['warnings'] = $result->meta['warnings'];
        }

        $summary = ['request' => $request, 'data' => $data, 'total' => $this->summaryFigures($result->total, $query->measures)];

        if ($result->other !== null) {
            $summary['other'] = [...$this->summaryFigures($result->other, $query->measures), 'groups' => $result->other['groups'] ?? 0];
        }

        $summary['meta'] = $meta;

        return $summary;
    }

    /**
     * The count and the metrics of one cell of the engine's result, the metrics named the way the model asked
     * for them: `sum(total)`, not the engine's `sum_total`. A figure no row gave stays `null`.
     *
     * @param  array<string, mixed>  $values  The figures of the cell by measure name.
     * @param  list<AggregateMeasure>  $measures
     * @return array{count: mixed, metrics: array<string, mixed>}
     */
    private function summaryFigures(array $values, array $measures): array
    {
        $metrics = [];

        foreach ($measures as $measure) {
            if ($measure->field !== null) {
                $metrics[sprintf('%s(%s)', $measure->fn, $measure->field)] = $values[$measure->name()] ?? null;
            }
        }

        return ['count' => $values['count'] ?? 0, 'metrics' => $metrics];
    }

    /**
     * A refusal of the aggregate engine for the model to read: the message, with the code and the part of the
     * request that it is about.
     *
     * @return array<string, string>
     */
    private function aggregateRefusal(AggregateException $exception): array
    {
        $refusal = $exception->toArray();

        return [
            'error' => $refusal['message'],
            'code' => $refusal['code'],
            ...isset($refusal['field']) ? ['field' => $refusal['field']] : [],
        ];
    }

    private function rowValue(mixed $row, string $property): mixed
    {
        if ($row instanceof Model) {
            return $row->getAttribute($property);
        }

        if (is_array($row)) {
            return $row[$property] ?? null;
        }

        return null;
    }

    /**
     * Export an ACL-scoped, filtered recordset to CSV or PDF. Delegates row
     * access to CrudService::list (permission + ACL enforced) and reuses Core's
     * tabular exporters. The file is returned inline as base64 so the caller can
     * offer it as a download without a second round-trip.
     *
     * @return array<string, mixed>
     */
    private function runExport(string $module, string $entity, mixed $format, mixed $filters, mixed $sort, mixed $columns, mixed $limit): array
    {
        $formatName = mb_strtolower($this->toString($format));
        $formatName = in_array($formatName, ['csv', 'pdf'], true) ? $formatName : 'csv';
        $normalizedFilters = $this->normalizeFilters($filters);
        $normalizedSort = $this->normalizeSort($sort);
        $requestedColumns = $this->normalizeStringList($columns);
        $cap = min($this->toInt($limit) ?? self::EXPORT_ROW_CAP, self::EXPORT_ROW_CAP);

        $request = ['verb' => 'export', 'format' => $formatName, 'module' => $module, 'entity' => $entity, 'filters' => $normalizedFilters, 'sort' => $normalizedSort, 'columns' => $requestedColumns, 'limit' => $cap];

        try {
            $validated = ['pagination' => $cap, 'page' => 1];

            if ($normalizedFilters !== []) {
                $validated['filters'] = $normalizedFilters;
            }

            if ($normalizedSort !== []) {
                $validated['sort'] = $normalizedSort;
            }

            $data = new ListRequestData($this->makeRequest(ListRequest::class), $entity, $validated, $this->primaryKey($module, $entity), $module);
            $result = $this->crud->list($data);

            $rows = $result->data;
            $rowList = $rows instanceof Collection || $rows instanceof EloquentCollection
                ? array_map(static fn (mixed $row): array => $row instanceof Model ? $row->toArray() : (array) $row, $rows->all())
                : [];

            $exportColumns = $this->exportColumns($rowList, $requestedColumns);
            $filename = sprintf('%s_%s_export.%s', mb_strtolower($module), mb_strtolower($entity), $formatName);

            [$contents, $mime] = $formatName === 'pdf'
                ? [$this->pdfExporter->export($exportColumns, $rowList, $filename), 'application/pdf']
                : [$this->csvExporter->export($exportColumns, $rowList), 'text/csv'];

            $totalRecords = $result->meta?->totalRecords ?? count($rowList);

            return [
                'request' => $request,
                'file' => [
                    'filename' => $filename,
                    'mime' => $mime,
                    'encoding' => 'base64',
                    'contents' => base64_encode($contents),
                ],
                'meta' => [
                    'total_records' => $totalRecords,
                    'exported_rows' => count($rowList),
                    'truncated' => $totalRecords > count($rowList),
                ],
            ];
        } catch (Throwable $exception) {
            return $this->fail($request, $exception);
        }
    }

    /**
     * Build the exporter column spec: the requested columns in order, or every
     * column of the first row when none are requested.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $requested
     * @return list<array{key: string, label: string}>
     */
    private function exportColumns(array $rows, array $requested): array
    {
        $keys = $requested !== []
            ? $requested
            : array_keys($rows[0] ?? []);

        return array_values(array_map(
            static fn (string $key): array => ['key' => $key, 'label' => $key],
            array_map(static fn (mixed $key): string => (string) $key, $keys),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function runDetail(string $module, string $entity, mixed $id): array
    {
        $key = $this->primaryKey($module, $entity);
        $recordId = $this->toString($id);
        $request = ['verb' => 'detail', 'module' => $module, 'entity' => $entity, 'id' => $recordId];

        try {
            $data = new DetailRequestData($this->makeRequest(DetailRequest::class, [$key => $recordId]), $entity, [], $key, $module);

            return $this->present($this->crud->detail($data), $request);
        } catch (Throwable $exception) {
            return $this->fail($request, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runCreate(string $module, string $entity, mixed $attributes): array
    {
        $request = ['verb' => 'create', 'module' => $module, 'entity' => $entity];

        try {
            $data = $this->modifyData($module, $entity, $this->toArray($attributes));

            return $this->present($this->crud->insert($data), $request);
        } catch (Throwable $exception) {
            return $this->fail($request, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runUpdate(string $module, string $entity, mixed $id, mixed $attributes): array
    {
        $key = $this->primaryKey($module, $entity);
        $recordId = $this->toString($id);
        $request = ['verb' => 'update', 'module' => $module, 'entity' => $entity, 'id' => $recordId];

        try {
            $data = $this->modifyData($module, $entity, [$key => $recordId] + $this->toArray($attributes));

            return $this->present($this->crud->update($data), $request);
        } catch (Throwable $exception) {
            return $this->fail($request, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runDelete(string $module, string $entity, mixed $id): array
    {
        $key = $this->primaryKey($module, $entity);
        $recordId = $this->toString($id);
        $request = ['verb' => 'delete', 'module' => $module, 'entity' => $entity, 'id' => $recordId];

        try {
            $data = $this->modifyData($module, $entity, [$key => $recordId]);

            return $this->present($this->crud->delete($data), $request);
        } catch (Throwable $exception) {
            return $this->fail($request, $exception);
        }
    }

    /**
     * Stores what the model asked for as a proposal and changes nothing. What is applied later is the stored
     * payload, so the person confirms exactly what was shown; for a bulk call that includes the ids that
     * matched now. The record is read as the acting person, so one they cannot see is not proposed.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function proposeWrite(AssistantAccessContext $context, string $operation, string $module, string $entity, array $payload): array
    {
        $request = ['verb' => $operation, 'module' => $module, 'entity' => $entity];

        try {
            $user = $this->request->user();
            $model = $this->resolveModel($module, $entity);

            if (! $user instanceof User || ! $model instanceof Model || ! $this->actingUserMatches($context) || $context->conversationId === null) {
                return ['request' => $request, 'error' => 'This change cannot be proposed.'];
            }

            $prepared = $this->prepareProposal($operation, $module, $entity, $payload);

            if (isset($prepared['error'])) {
                return ['request' => $request, 'error' => $prepared['error']];
            }

            $requiresApproval = $this->wouldRequireApproval($model, $operation);
            $proposal = $this->proposals->propose(
                $user,
                $context->conversationId,
                sprintf('crud_%s_%s_%s', $operation, mb_strtolower($module), mb_strtolower($entity)),
                $module,
                $entity,
                $operation,
                $prepared['payload'],
                $prepared['summary'],
                $requiresApproval,
            );

            if (! $proposal instanceof WriteProposal) {
                return ['request' => $request, 'refused' => true, 'error' => 'Too many changes were proposed in this turn. Propose the rest in a later message.'];
            }

            return [
                'request' => $request,
                'status' => 'awaiting_confirmation',
                'proposal_id' => $proposal->getKey(),
                'acting_user' => ActingUserName::of($user),
                'summary' => $prepared['summary'],
                'requires_approval' => $requiresApproval,
                'expires_at' => $proposal->expires_at->toIso8601String(),
                'message' => 'Nothing has been changed. The signed-in person must confirm this proposal in the interface'
                    . ($requiresApproval ? ', and the change is then sent for approval before it takes effect.' : '.'),
            ];
        } catch (Throwable $exception) {
            return $this->fail($request, $exception);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{payload: array<string, mixed>, summary: array<string, mixed>}|array{error: string}
     */
    private function prepareProposal(string $operation, string $module, string $entity, array $payload): array
    {
        $attributes = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : [];

        if (in_array($operation, ['create', 'update', 'bulk_update'], true) && $attributes === []) {
            return ['error' => 'At least one attribute to set is required.'];
        }

        if (in_array($operation, ['update', 'delete'], true)) {
            $id = $this->toString($payload['id'] ?? null);

            if ($id === '') {
                return ['error' => 'A record identifier is required.'];
            }

            $current = $this->findRecord($module, $entity, $id);

            if ($current === null) {
                return ['error' => 'The record was not found or is not available to you.'];
            }

            $summary = $operation === 'update'
                ? ['record_id' => $id, 'changes' => $this->diff($current, $attributes)]
                : ['record_id' => $id, 'record' => $this->clipRecord($current)];

            return ['payload' => $payload, 'summary' => $summary];
        }

        if ($operation === 'create') {
            return ['payload' => $payload, 'summary' => ['changes' => $this->clipRecord($attributes)]];
        }

        $filters = $payload['filters'] ?? [];

        if (! is_array($filters) || $filters === []) {
            return ['error' => 'Bulk operations require at least one filter to scope the affected records.'];
        }

        $ids = $this->matchedIds($module, $entity, $filters, $this->primaryKey($module, $entity));

        if ($ids === []) {
            return ['error' => 'No record matches those filters.'];
        }

        if (count($ids) > self::BULK_CAP) {
            return ['error' => sprintf('%d or more records match: the hard cap is %d. Narrow the filters and retry.', self::BULK_CAP + 1, self::BULK_CAP)];
        }

        return [
            'payload' => ['ids' => $ids, 'attributes' => $attributes, 'filters' => $filters],
            'summary' => [
                'matched_records' => count($ids),
                'sample_ids' => array_slice($ids, 0, 20),
                'changes' => $this->clipRecord($attributes),
                'cap' => self::BULK_CAP,
            ],
        ];
    }

    /**
     * The record as the acting person may read it, through CrudService::list so permission and ACL apply.
     *
     * @return array<string, mixed>|null
     */
    private function findRecord(string $module, string $entity, string $id): ?array
    {
        $key = $this->primaryKey($module, $entity);
        $validated = ['filters' => [['property' => $key, 'operator' => '=', 'value' => $id]], 'pagination' => 1, 'page' => 1];
        $data = new ListRequestData($this->makeRequest(ListRequest::class), $entity, $validated, $key, $module);
        $rows = $this->crud->list($data)->data;

        if (! $rows instanceof Collection && ! $rows instanceof EloquentCollection) {
            return null;
        }

        $row = $rows->first();

        if ($row === null) {
            return null;
        }

        return $row instanceof Model ? $row->toArray() : (array) $row;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function singleResult(array $result): WriteApplyResult
    {
        if (isset($result['error'])) {
            return new WriteApplyResult(WriteProposalStatus::Failed, ['error' => $result['error']]);
        }

        if (($result['status'] ?? null) === 'pending_approval') {
            return new WriteApplyResult(WriteProposalStatus::PendingApproval, [
                'modification' => $result['modification'] ?? null,
                'operation' => $result['operation'] ?? null,
            ]);
        }

        return new WriteApplyResult(WriteProposalStatus::Applied, ['record' => $result['data'] ?? null]);
    }

    /**
     * Applies a bulk write to exactly the ids the person was shown. Each record is written on its own, so
     * every write is authorized, ACL-scoped and, on moderated entities, captured for approval per record.
     *
     * @param  list<mixed>  $ids
     * @param  array<string, mixed>  $changes
     */
    private function applyBulk(string $operation, string $module, string $entity, array $ids, array $changes): WriteApplyResult
    {
        $key = $this->primaryKey($module, $entity);
        $applied = 0;
        $captured = 0;
        $failed = 0;
        $modifications = [];

        foreach (array_slice($ids, 0, self::BULK_CAP) as $id) {
            try {
                $data = $this->modifyData($module, $entity, [$key => $id] + $changes);
                $result = $operation === 'bulk_update' ? $this->crud->update($data) : $this->crud->delete($data);

                if ($result->statusCode === Response::HTTP_ACCEPTED) {
                    $captured++;
                    $modifications[] = is_array($result->data) ? ($result->data['modification'] ?? null) : null;
                } else {
                    $applied++;
                }
            } catch (Throwable) {
                $failed++;
            }
        }

        $status = match (true) {
            $applied === 0 && $captured === 0 => WriteProposalStatus::Failed,
            $applied === 0 => WriteProposalStatus::PendingApproval,
            default => WriteProposalStatus::Applied,
        };

        return new WriteApplyResult($status, [
            'applied' => $applied,
            'captured_for_approval' => $captured,
            'failed' => $failed,
            'modifications' => array_values(array_filter($modifications, static fn (mixed $id): bool => $id !== null)),
        ]);
    }

    private function isOperationStillListed(string $entityKey, string $operation): bool
    {
        foreach ($this->allowlist() as $key => $operations) {
            if ($entityKey === mb_strtolower((string) $key) && in_array($operation, $operations, true)) {
                return true;
            }
        }

        return false;
    }

    private function wouldRequireApproval(Model $model, string $operation): bool
    {
        if (! class_uses_trait($model, HasApprovals::class) || ! method_exists($model, 'wouldRequireApproval')) {
            return false;
        }

        return (bool) $model->wouldRequireApproval(match ($operation) {
            'create' => Operation::Create,
            'update', 'bulk_update' => Operation::Update,
            default => Operation::Delete,
        });
    }

    /**
     * What each attribute is now and what the proposal would make it.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $attributes
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function diff(array $current, array $attributes): array
    {
        $changes = [];

        foreach ($attributes as $field => $value) {
            $changes[(string) $field] = ['from' => $this->clip($current[$field] ?? null), 'to' => $this->clip($value)];
        }

        return $changes;
    }

    /**
     * A bounded view of a record for the person to read: scalar values only, clipped, a limited number.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function clipRecord(array $record): array
    {
        $clipped = [];

        foreach (array_slice($record, 0, 12, true) as $field => $value) {
            $clipped[(string) $field] = $this->clip($value);
        }

        return $clipped;
    }

    private function clip(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_strlen($value) > 200 ? mb_substr($value, 0, 200) . '…' : $value;
        }

        return is_scalar($value) || $value === null ? $value : '[' . gettype($value) . ']';
    }

    /**
     * Resolve the primary keys of the records matched by the bulk filters,
     * ACL-scoped through CrudService::list, up to one past the cap so an
     * over-cap match is detectable.
     *
     * @param  list<array<string, mixed>>  $filters
     * @return list<string>
     */
    private function matchedIds(string $module, string $entity, array $filters, string $key): array
    {
        $validated = ['filters' => $filters, 'pagination' => self::BULK_CAP + 1, 'page' => 1];
        $data = new ListRequestData($this->makeRequest(ListRequest::class), $entity, $validated, $key, $module);
        $result = $this->crud->list($data);
        $rows = $result->data;

        if (! $rows instanceof Collection && ! $rows instanceof EloquentCollection) {
            return [];
        }

        return $rows
            ->map(fn (mixed $row): string => $this->toString($this->rowValue($row instanceof Model ? $row : (array) $row, $key)))
            ->filter(static fn (string $id): bool => $id !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function runPendingApprovals(string $module, string $entity, mixed $author): array
    {
        $authorFilter = $this->toString($author);
        $request = ['verb' => 'pending_approvals', 'module' => $module, 'entity' => $entity, 'author' => $authorFilter === '' ? null : $authorFilter];

        try {
            $data = new CrudRequestData($this->makeRequest(PendingApprovalsRequest::class), $entity, [], $this->primaryKey($module, $entity), $module);
            $result = $this->crud->pendingApprovals($data);

            return ['request' => $request, 'data' => $this->enrichApprovals($result->data, $authorFilter)];
        } catch (Throwable $exception) {
            return $this->fail($request, $exception);
        }
    }

    /**
     * Turn pending-approval rows into arrays, add the modifier's display name,
     * and optionally keep only those authored by the given user (name/email/id).
     *
     * @return list<array<string, mixed>>
     */
    private function enrichApprovals(mixed $rows, string $author): array
    {
        if (! $rows instanceof Collection && ! $rows instanceof EloquentCollection) {
            return [];
        }

        $authorIds = $author === '' ? null : $this->resolveAuthorIds($author);

        return $rows
            ->map(fn (mixed $row): array => $this->approvalRow($row))
            ->filter(static fn (array $row): bool => $authorIds === null || in_array($row['modifier_id'] ?? null, $authorIds, true))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function approvalRow(mixed $row): array
    {
        $data = $row instanceof Fluent ? $row->toArray() : (array) $row;
        $data['modifier_name'] = $this->modifierName($data['modifier_id'] ?? null, $data['modifier_type'] ?? null);

        return $data;
    }

    /**
     * @return list<int>
     */
    private function resolveAuthorIds(string $author): array
    {
        $ids = is_numeric($author) ? [(int) $author] : [];

        try {
            $matched = user_class()::query()
                ->where('name', 'like', "%{$author}%")
                ->orWhere('email', 'like', "%{$author}%")
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
            $ids = array_merge($ids, $matched);
        } catch (Throwable) {
            // Fall through to whatever numeric id was provided.
        }

        return array_values(array_unique($ids));
    }

    private function modifierName(mixed $id, mixed $type): ?string
    {
        if ($id === null) {
            return null;
        }

        $class = is_string($type) && class_exists($type) ? $type : user_class();

        try {
            $name = $class::query()->whereKey($id)->value('name');

            return is_string($name) ? $name : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function modifyData(string $module, string $entity, array $changes): ModifyRequestData
    {
        return new ModifyRequestData(
            $this->makeRequest(ModifyRequest::class),
            $entity,
            $changes,
            $this->primaryKey($module, $entity),
            $module,
        );
    }

    /**
     * @param  array<string, mixed>  $routeParameters
     */
    private function makeRequest(string $requestClass, array $routeParameters = []): Request
    {
        /** @var Request $request */
        $request = $requestClass::create('/app/ai/crud-tool', 'POST');
        $request->setUserResolver(fn (): mixed => $this->request->user());

        if ($routeParameters !== []) {
            $request->merge($routeParameters);
            $request->setRouteResolver(static fn (): object => new class($routeParameters)
            {
                /**
                 * @param  array<string, mixed>  $parameters
                 */
                public function __construct(private array $parameters) {}

                public function parameter(string $name): mixed
                {
                    return $this->parameters[$name] ?? null;
                }
            });
        }

        return $request;
    }

    private function primaryKey(string $module, string $entity): string
    {
        $key = DynamicEntity::resolve($entity, request: $this->request, module: $module)->getKeyName();

        return is_array($key) ? (string) head($key) : $key;
    }

    /**
     * Present a CrudResult alongside the structured request that produced it, so
     * the frontend can reapply the same filters/sort/pagination to its tables.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function present(CrudResult $result, array $request): array
    {
        $data = $result->data;

        // A write sent for approval did not happen: report the request, not a record.
        if ($result->statusCode === Response::HTTP_ACCEPTED && is_array($data)) {
            return ['request' => $request, 'status' => 'pending_approval', ...$data];
        }

        $payload = match (true) {
            $data instanceof Model => $data->toArray(),
            $data instanceof EloquentCollection, $data instanceof Collection => $data->toArray(),
            is_scalar($data) => ['value' => $data],
            default => (array) $data,
        };

        $out = ['request' => $request, 'data' => $payload];

        if ($result->meta !== null) {
            $out['meta'] = ['total_records' => $result->meta->totalRecords, 'current_page' => $result->meta->currentPage];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private function fail(array $request, Throwable $exception): array
    {
        return ['request' => $request, 'error' => $exception->getMessage()];
    }

    /**
     * Keep only well-formed filter nodes; the shape is passed through unchanged
     * so the same structure round-trips to the frontend.
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeFilters(mixed $filters): array
    {
        if (! is_array($filters)) {
            return [];
        }

        return array_values(array_filter($filters, static fn (mixed $node): bool => is_array($node)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeSort(mixed $sort): array
    {
        if (! is_array($sort)) {
            return [];
        }

        return array_values(array_filter(
            $sort,
            static fn (mixed $node): bool => is_array($node) && isset($node['property']) && is_string($node['property']),
        ));
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value),
            static fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * Keep only well-formed metric specs {property, function} with a supported
     * aggregation function.
     *
     * @return list<array{property: string, function: string}>
     */
    private function normalizeMetrics(mixed $metrics): array
    {
        if (! is_array($metrics)) {
            return [];
        }

        $normalized = [];

        foreach ($metrics as $metric) {
            if (! is_array($metric) || ! isset($metric['property']) || ! is_scalar($metric['property'])) {
                continue;
            }

            $function = isset($metric['function']) && is_scalar($metric['function'])
                ? mb_strtolower((string) $metric['function'])
                : 'sum';

            if (! in_array($function, self::SUMMARIZE_FUNCTIONS, true)) {
                continue;
            }

            $normalized[] = ['property' => (string) $metric['property'], 'function' => $function];
        }

        return $normalized;
    }

    private function toInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function toString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
