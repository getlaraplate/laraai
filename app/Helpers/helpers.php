<?php

declare(strict_types=1);

if (! function_exists('ai_config_string')) {
    function ai_config_string(string $key, ?string $default = null): string
    {
        $value = config($key, $default);

        if (is_string($value)) {
            return $value;
        }

        if ($value === null) {
            return $default ?? '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return $default ?? '';
    }
}

if (! function_exists('ai_config_nullable_string')) {
    function ai_config_nullable_string(string $key): ?string
    {
        $value = config($key);

        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return null;
    }
}

if (! function_exists('ai_config_bool')) {
    function ai_config_bool(string $key, bool $default = false): bool
    {
        $value = config($key, $default);

        return is_bool($value) ? $value : $default;
    }
}

if (! function_exists('ai_config_int')) {
    function ai_config_int(string $key, int $default = 0): int
    {
        $value = config($key, $default);

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }
}

if (! function_exists('ai_config_float')) {
    function ai_config_float(string $key, float $default = 0.0): float
    {
        $value = config($key, $default);

        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return $default;
    }
}

if (! function_exists('rag_paths')) {
    /**
     * Resolve RAG roots.
     *
     * Rules:
     * - Native Laraplate modules (same vendor as laraai): only docs/rag
     * - App and non-native modules: docs/rag + configured relative subpaths
     * - Configured absolute paths are appended when they exist and are not under a native module path
     *
     * @return list<string>
     */
    function rag_paths(bool $onlyActive = true, ?string $onlyModule = null, ?bool $prioritySort = false, ?callable $filter = null): array
    {
        $module_class = Nwidart\Modules\Facades\Module::class;

        $module_names = modules(
            showMainApp: false,
            fullpath: false,
            onlyActive: $onlyActive,
            onlyModule: $onlyModule,
            prioritySort: $prioritySort,
            filter: $filter,
        );

        $native_vendor = '';

        if (class_exists($module_class)) {
            $ai_module = $module_class::find('AI');
            $native_vendor = (string) $ai_module->getComposerAttr('vendor', '');
        }

        if ($native_vendor === '') {
            $ai_composer = base_path('Modules/AI/composer.json');

            if (is_file($ai_composer)) {
                $decoded = json_decode((string) file_get_contents($ai_composer), true);

                if (is_array($decoded)) {
                    $vendor = $decoded['vendor'] ?? '';
                    $native_vendor = is_string($vendor) ? $vendor : '';
                }
            }
        }

        $configured = config('ai.features.faq.documentation_path');
        $configured_paths = [];

        if (is_string($configured) && mb_trim($configured) !== '') {
            $configured_paths = preg_split('/[\r\n,;]+/', $configured) ?: [];
        }

        if (is_array($configured)) {
            $configured_paths = $configured;
        }

        $relative_subpaths = [];
        $absolute_paths = [];

        foreach ($configured_paths as $configured_path) {
            if (! is_string($configured_path)) {
                continue;
            }

            $configured_path = mb_trim($configured_path);

            if ($configured_path === '') {
                continue;
            }

            if (str_starts_with($configured_path, '/')) {
                $absolute_paths[] = normalize_path($configured_path);

                continue;
            }

            $relative_subpaths[] = mb_trim($configured_path, '/');
        }

        // Convention default.
        $default_subpath = 'docs/rag';

        $rag_paths = [];
        $native_module_paths = [];

        if (class_exists($module_class)) {
            foreach ($module_names as $module_name) {
                $module_name = (string) $module_name;
                $module = $module_class::find($module_name);

                $module_path = normalize_path((string) $module->getPath());
                $module_vendor = (string) ($module->getComposerAttr('vendor', ''));

                if ($native_vendor !== '' && $module_vendor === $native_vendor) {
                    $native_module_paths[] = mb_rtrim($module_path, '/');
                }

                $subpaths = [$default_subpath];

                if ($native_vendor === '' || $module_vendor !== $native_vendor) {
                    $subpaths = array_merge($subpaths, $relative_subpaths);
                }

                foreach ($subpaths as $subpath) {
                    if ($subpath === '') {
                        continue;
                    }

                    $candidate = normalize_path($module_path . '/' . $subpath);

                    if (is_dir($candidate)) {
                        $rag_paths[] = $candidate;
                    }
                }
            }
        }

        $should_include_app = in_array($onlyModule, [null, '', '0', 'App'], true)
            && ($filter === null || $filter('App'));

        if ($should_include_app) {
            $app_root = normalize_path(base_path());

            $app_subpaths = array_merge([$default_subpath], $relative_subpaths);

            foreach ($app_subpaths as $subpath) {
                if ($subpath === '') {
                    continue;
                }

                $candidate = normalize_path($app_root . '/' . $subpath);

                if (is_dir($candidate)) {
                    $rag_paths[] = $candidate;
                }
            }
        }

        foreach ($absolute_paths as $absolute_path) {
            if (! is_dir($absolute_path)) {
                continue;
            }

            $normalized_absolute = mb_rtrim(normalize_path($absolute_path), '/');
            $is_native_module_path = array_any($native_module_paths, static fn (string $native_module_path): bool => $normalized_absolute === $native_module_path
                || str_starts_with($normalized_absolute, $native_module_path . '/'));

            if (! $is_native_module_path) {
                $rag_paths[] = $absolute_path;
            }
        }

        return array_values(array_unique($rag_paths));
    }
}
