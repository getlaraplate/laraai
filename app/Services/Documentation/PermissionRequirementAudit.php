<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation;

use NeuronAI\RAG\Document;

/**
 * Finds the permission requirements of user documents that no known permission satisfies.
 *
 * Such a requirement is not an error: the page is visible to nobody. The audit exists so that a misspelled
 * requirement can be found, it never blocks anything.
 */
final readonly class PermissionRequirementAudit
{
    private const string UNNAMED_DOCUMENT = '(unnamed document)';

    /**
     * @param  list<string>  $knownPermissions  concrete permission names, as stored
     * @param  iterable<Document>  $documents
     * @return list<array{document: string, requirement: string}> unique, sorted by document then requirement
     */
    public static function unmatched(array $knownPermissions, iterable $documents): array
    {
        $satisfiable = array_flip(PermissionRequirementPatterns::satisfiedBy($knownPermissions));
        $found = [];

        foreach ($documents as $document) {
            $metadata = $document->getMetadata();
            $name = $metadata['canonical_source'] ?? null;
            $name = is_string($name) && mb_trim($name) !== '' ? $name : self::UNNAMED_DOCUMENT;
            $requirements = $metadata['required_permissions'] ?? [];

            if (! is_array($requirements)) {
                continue;
            }

            foreach ($requirements as $requirement) {
                if (is_string($requirement) && ! isset($satisfiable[$requirement])) {
                    $found[$name . "\0" . $requirement] = ['document' => $name, 'requirement' => $requirement];
                }
            }
        }

        $entries = array_values($found);
        usort($entries, static fn (array $a, array $b): int => [$a['document'], $a['requirement']] <=> [$b['document'], $b['requirement']]);

        return $entries;
    }
}
