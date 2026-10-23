<?php

declare(strict_types=1);

if (! function_exists('class_uses_trait')) {
    function class_uses_trait(object|string $class, string $trait): bool
    {
        $uses = class_uses_recursive(is_object($class) ? $class::class : $class);

        return isset($uses[$trait]);
    }
}

if (! function_exists('models')) {
    /**
     * @return string[]
     */
    function models(bool $withAbstract = false, ?callable $filter = null): array
    {
        $models = config('_test_models', []);

        if ($filter !== null) {
            $models = array_filter($models, $filter);
        }

        return array_values($models);
    }
}

if (! function_exists('ai_test_unit_vector')) {
    /**
     * A vector of the given length whose norm is 1: what a server that normalises answers to the embedding probe.
     *
     * @return list<float>
     */
    function ai_test_unit_vector(int $dimensions): array
    {
        return array_fill(0, $dimensions, 1 / sqrt($dimensions));
    }
}
