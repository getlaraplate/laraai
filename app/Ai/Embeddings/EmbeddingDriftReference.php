<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings;

use Illuminate\Support\Facades\Storage;
use JsonException;

/**
 * A reference of what an embedding profile produces for a fixed sample of short texts, kept on the
 * local disk (`ai/embedding-references/`) so a later probe of the same profile, on another server,
 * runtime or precision, can be compared with it. A vector length check cannot see a precision or
 * runtime mix; the cosine between the reference and the new vector of each text can.
 *
 * One file per profile key: the sample and the vectors, in the order of {@see self::SAMPLE}.
 */
final readonly class EmbeddingDriftReference
{
    /**
     * Short texts in Italian, English, French, German and Spanish, a mix of everyday sentences,
     * a proper name and a number, in a fixed order. Changing it invalidates every saved reference.
     *
     * @var list<string>
     */
    public const array SAMPLE = [
        'Il gatto dorme sul divano accanto alla finestra.',
        'The quarterly report is due on the fifteenth of March.',
        'Où se trouve la gare la plus proche de la cathédrale ?',
        'Der Zug nach München fährt um 7:45 Uhr ab.',
        'La reunión del equipo se ha trasladado al jueves por la tarde.',
        'Maria Rossi ha firmato il contratto numero 2024-118.',
        'Water boils at one hundred degrees Celsius at sea level.',
        'Quanto costa spedire un pacco da due chili in Germania?',
    ];

    private const string DIRECTORY = 'ai/embedding-references';

    private const string DISK = 'local';

    /**
     * The file name of a profile key: filesystem-safe, with a hash of the key so two keys that
     * sanitise to the same text do not share a reference.
     */
    public static function fileNameFor(string $key): string
    {
        $safe = mb_trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $key), '_.');

        return ($safe === '' ? 'profile' : $safe) . '-' . mb_substr(hash('sha256', $key), 0, 10) . '.json';
    }

    /**
     * @param  list<list<float>>  $vectors  one per text of {@see self::SAMPLE}, in the same order
     *
     * @throws EmbeddingDriftReferenceException when the vectors do not match the sample
     */
    public function save(EmbeddingModelProfile $profile, array $vectors): void
    {
        $vectors = self::normalised($vectors);

        Storage::disk(self::DISK)->put(self::path($profile), json_encode([
            'profile' => $profile->key,
            'model' => $profile->serviceModel,
            'sample' => self::sampleFingerprint(),
            'vectors' => $vectors,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * The lowest cosine between the saved reference and the given vectors over the sample.
     *
     * @param  list<list<float>>  $vectors  one per text of {@see self::SAMPLE}, in the same order
     *
     * @throws EmbeddingDriftReferenceException when there is no reference, it was made from another sample or a length differs
     */
    public function compare(EmbeddingModelProfile $profile, array $vectors): float
    {
        return $this->lowest($profile, $vectors)['cosine'];
    }

    /**
     * The lowest cosine and the sample text that has it.
     *
     * @param  list<list<float>>  $vectors  one per text of {@see self::SAMPLE}, in the same order
     *
     * @throws EmbeddingDriftReferenceException when there is no reference, it was made from another sample or a length differs
     *
     * @return array{cosine: float, text: string}
     */
    public function lowest(EmbeddingModelProfile $profile, array $vectors): array
    {
        $vectors = self::normalised($vectors);
        $reference = $this->load($profile);

        $lowest = null;

        foreach (self::SAMPLE as $index => $text) {
            $saved = $reference[$index];

            if (count($saved) !== count($vectors[$index])) {
                throw EmbeddingDriftReferenceException::lengthDiffers($profile, $text, count($saved), count($vectors[$index]));
            }

            $cosine = self::cosine($saved, $vectors[$index]);

            if ($lowest === null || $cosine < $lowest['cosine']) {
                $lowest = ['cosine' => $cosine, 'text' => $text];
            }
        }

        /** @var array{cosine: float, text: string} $lowest */
        return $lowest;
    }

    /**
     * @param  list<float>  $first
     * @param  list<float>  $second  of the same length
     */
    private static function cosine(array $first, array $second): float
    {
        $dot = 0.0;
        $firstNorm = 0.0;
        $secondNorm = 0.0;

        foreach ($first as $position => $value) {
            $dot += $value * $second[$position];
            $firstNorm += $value * $value;
            $secondNorm += $second[$position] * $second[$position];
        }

        if ($firstNorm <= 0.0 || $secondNorm <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($firstNorm) * sqrt($secondNorm));
    }

    /**
     * @param  list<list<float>>  $vectors
     * @return list<list<float>>
     */
    private static function normalised(array $vectors): array
    {
        if (count($vectors) !== count(self::SAMPLE)) {
            throw EmbeddingDriftReferenceException::wrongVectorCount(count(self::SAMPLE), count($vectors));
        }

        return array_map(
            static fn (array $vector): array => array_map(floatval(...), array_values($vector)),
            array_values($vectors),
        );
    }

    private static function path(EmbeddingModelProfile $profile): string
    {
        return self::DIRECTORY . '/' . self::fileNameFor($profile->key);
    }

    private static function sampleFingerprint(): string
    {
        return hash('sha256', implode("\n", self::SAMPLE));
    }

    /**
     * @return list<list<float>>
     */
    private function load(EmbeddingModelProfile $profile): array
    {
        $disk = Storage::disk(self::DISK);

        if (! $disk->exists(self::path($profile))) {
            throw EmbeddingDriftReferenceException::missing($profile);
        }

        try {
            $data = json_decode((string) $disk->get(self::path($profile)), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw EmbeddingDriftReferenceException::unreadable($profile);
        }

        if (! is_array($data) || ! is_array($data['vectors'] ?? null)) {
            throw EmbeddingDriftReferenceException::unreadable($profile);
        }

        if (($data['sample'] ?? null) !== self::sampleFingerprint() || count($data['vectors']) !== count(self::SAMPLE)) {
            throw EmbeddingDriftReferenceException::differentSample($profile);
        }

        /** @var list<list<float>> $vectors */
        $vectors = array_map(
            static fn (mixed $vector): array => array_map(floatval(...), array_values((array) $vector)),
            array_values($data['vectors']),
        );

        return $vectors;
    }
}
