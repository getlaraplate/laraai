<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Widgets;

use Modules\Core\Graph\Data\GraphExpandToolInput;

/**
 * The request of a graph object, the relation map of one record, validated within the bounds of the graph tools.
 *
 * The model writes `resource`, `id`, `relations`, and optionally `depth` and `node_detail`, and nothing else. The
 * limits of the expansion are not the model's: the request carries {@see self::NODE_LIMIT} and
 * {@see self::RELATION_LIMIT}, which are also the limits of the expansion run for the model, so the map a client
 * draws from the stored request is the one the model was told about.
 */
final readonly class GraphWidgetRequest
{
    public const int MAX_RELATIONS = 10;

    public const int MAX_DEPTH = 2;

    /**
     * The most nodes of a map, and the most records of one relation: the maximums of the graph tools.
     */
    public const int NODE_LIMIT = 25;

    public const int RELATION_LIMIT = 10;

    private const array KEYS = ['resource', 'id', 'relations', 'depth', 'node_detail'];

    private const array NODE_DETAILS = ['minimal', 'summary'];

    private const int MAX_RECORD_KEY_LENGTH = 255;

    private const int MAX_RELATION_PATH_LENGTH = 128;

    private const string RELATION_PATH_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/D';

    /**
     * @param  list<string>  $relations  as the model wrote them
     */
    private function __construct(
        public string $resource,
        public int|string $id,
        public array $relations,
        public int $depth,
        public ?string $nodeDetail,
    ) {}

    /**
     * The request the model wrote, or why it is refused: `id` is an integer or a text of 1 to 255 characters that is
     * not blank; `relations` is a list of 1 to 10 paths of relation names joined by dots, of at most 128
     * characters, distinct in any case (relation names are method names, which PHP reads in any case) and with no
     * more steps than the depth; `depth` is 1 or 2, 1 when left out; `node_detail` is `minimal` or `summary`. An
     * optional key sent as null is refused, as any other value outside its list.
     *
     * @param  array<array-key, mixed>  $decoded
     */
    public static function validated(string $resource, array $decoded): self|string
    {
        if (array_diff(array_keys($decoded), self::KEYS) !== []) {
            return 'a graph request holds only resource, id, relations, depth and node_detail';
        }

        $id = $decoded['id'] ?? null;

        if (! is_int($id) && (! is_string($id) || mb_trim($id) === '' || self::MAX_RECORD_KEY_LENGTH < mb_strlen($id))) {
            return 'the id must be an integer or a text of 1 to ' . self::MAX_RECORD_KEY_LENGTH . ' characters';
        }

        $relations = $decoded['relations'] ?? null;

        if (! is_array($relations) || ! array_is_list($relations) || $relations === [] || self::MAX_RELATIONS < count($relations)) {
            return 'the relations must be a list of 1 to ' . self::MAX_RELATIONS . ' relation paths';
        }

        $paths = [];

        foreach ($relations as $relation) {
            if (! is_string($relation) || self::MAX_RELATION_PATH_LENGTH < mb_strlen($relation) || preg_match(self::RELATION_PATH_PATTERN, $relation) !== 1) {
                return 'each relation must be a path of relation names joined by dots, of at most ' . self::MAX_RELATION_PATH_LENGTH . ' characters';
            }

            $paths[] = $relation;
        }

        if (count(array_unique(array_map(mb_strtolower(...), $paths))) !== count($paths)) {
            return 'each relation may appear only once';
        }

        $depth = array_key_exists('depth', $decoded) ? $decoded['depth'] : 1;

        if (! is_int($depth) || $depth < 1 || $depth > self::MAX_DEPTH) {
            return 'the depth must be 1 or ' . self::MAX_DEPTH;
        }

        if (array_any($paths, static fn (string $path): bool => $depth < mb_substr_count($path, '.') + 1)) {
            return 'a relation path may not have more steps than the depth';
        }

        $nodeDetail = $decoded['node_detail'] ?? null;

        if (array_key_exists('node_detail', $decoded) && ! in_array($nodeDetail, self::NODE_DETAILS, true)) {
            return 'node_detail must be ' . implode(' or ', self::NODE_DETAILS);
        }

        return new self($resource, $id, $paths, $depth, is_string($nodeDetail) ? $nodeDetail : null);
    }

    /**
     * What the model is told of an expanded map: how many nodes of each entity and how many edges of each relation
     * it holds, and whether it was cut at its limits. Labels, attributes, node ids and record keys are left out:
     * they are text people wrote, or values of records.
     *
     * @param  array<array-key, mixed>  $graph  the result of the gateway
     * @return array{nodes: array{total: int, by_type: array<string, int>}, edges: array{total: int, by_relation: array<string, int>}, truncated: bool}
     */
    public static function counts(array $graph): array
    {
        $nodes = [];
        $edges = [];

        foreach (is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [] as $node) {
            if (is_array($node) && is_string($node['module'] ?? null) && is_string($node['entity'] ?? null)) {
                $type = $node['module'] . '/' . $node['entity'];
                $nodes[$type] = ($nodes[$type] ?? 0) + 1;
            }
        }

        foreach (is_array($graph['edges'] ?? null) ? $graph['edges'] : [] as $edge) {
            if (is_array($edge) && is_string($edge['relation'] ?? null)) {
                $edges[$edge['relation']] = ($edges[$edge['relation']] ?? 0) + 1;
            }
        }

        ksort($nodes);
        ksort($edges);

        return [
            'nodes' => ['total' => array_sum($nodes), 'by_type' => $nodes],
            'edges' => ['total' => array_sum($edges), 'by_relation' => $edges],
            'truncated' => ($graph['truncated'] ?? false) === true,
        ];
    }

    /**
     * What the object stores: the validated request, the default depth applied, `node_detail` only when it was
     * given, and the limits of the expansion.
     *
     * @return array{kind: string, resource: string, id: int|string, relations: list<string>, depth: int, node_detail?: string, limit: int, relation_limit: int}
     */
    public function toArray(): array
    {
        return [
            'kind' => 'graph',
            'resource' => $this->resource,
            'id' => $this->id,
            'relations' => $this->relations,
            'depth' => $this->depth,
            ...$this->nodeDetail === null ? [] : ['node_detail' => $this->nodeDetail],
            'limit' => self::NODE_LIMIT,
            'relation_limit' => self::RELATION_LIMIT,
        ];
    }

    /**
     * The request as one canonical JSON text, for the duplicate rule and the audit log: its keys sorted, the id as
     * text, the relations in lower case and sorted, and the node detail the route applies when none was given
     * (`graph.default_node_detail`), so that two spellings of one map give one text.
     */
    public function canonical(): string
    {
        $relations = array_map(mb_strtolower(...), $this->relations);
        sort($relations);
        $default = config('graph.default_node_detail', 'summary');
        $canonical = [
            ...$this->toArray(),
            'id' => (string) $this->id,
            'relations' => $relations,
            'node_detail' => $this->nodeDetail ?? (is_string($default) ? $default : 'summary'),
        ];
        unset($canonical['kind']);
        ksort($canonical);

        return json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The expansion the gateway runs: the module and the entity of the resource, the id as text, and the limits the
     * request carries.
     */
    public function toolInput(): GraphExpandToolInput
    {
        [$module, $entity] = explode('/', $this->resource, 2);

        return new GraphExpandToolInput($module, $entity, (string) $this->id, $this->relations, $this->depth, self::NODE_LIMIT, self::RELATION_LIMIT);
    }

    /**
     * The entity of the resource as the logs name it, `module.entity`.
     */
    public function entity(): string
    {
        return str_replace('/', '.', $this->resource);
    }
}
