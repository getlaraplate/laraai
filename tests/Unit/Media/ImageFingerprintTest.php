<?php

declare(strict_types=1);

use Modules\AI\Services\Media\ImageFingerprint;
use Modules\AI\Tests\Stubs\MediaAnalysis\GdImages;

it('gives a 16 character hexadecimal fingerprint', function (): void {
    $fingerprint = ImageFingerprint::of(GdImages::mosaicPng('a'));

    expect($fingerprint)->toMatch('/^[0-9a-f]{16}$/');
});

it('gives the same fingerprint to the same bytes', function (): void {
    expect(ImageFingerprint::of(GdImages::mosaicPng('a')))->toBe(ImageFingerprint::of(GdImages::mosaicPng('a')));
});

it('the same image resized has distance within the threshold', function (): void {
    $original = ImageFingerprint::of(GdImages::mosaicPng('a', 240, 200));
    $resized = ImageFingerprint::of(GdImages::mosaicPng('a', 120, 100));
    $enlarged = ImageFingerprint::of(GdImages::mosaicPng('a', 480, 400));

    expect(ImageFingerprint::distance($original, $resized))->toBeLessThanOrEqual(4)
        ->and(ImageFingerprint::distance($original, $enlarged))->toBeLessThanOrEqual(4);
});

it('different images exceed it', function (): void {
    $a = ImageFingerprint::of(GdImages::mosaicPng('a'));
    $b = ImageFingerprint::of(GdImages::mosaicPng('b'));

    expect(ImageFingerprint::distance($a, $b))->toBeGreaterThan(10);
});

it('counts the differing bits of two fingerprints', function (): void {
    expect(ImageFingerprint::distance('0000000000000000', '0000000000000000'))->toBe(0)
        ->and(ImageFingerprint::distance('0000000000000000', 'ffffffffffffffff'))->toBe(64)
        ->and(ImageFingerprint::distance('00000000000000f0', '000000000000000f'))->toBe(8)
        ->and(ImageFingerprint::distance('8000000000000001', '0000000000000000'))->toBe(2);
});

it('gives no fingerprint when the bytes are not an image', function (string $bytes): void {
    expect(ImageFingerprint::of($bytes))->toBeNull();
})->with([
    'empty' => [''],
    'text' => ['this is not an image'],
    'truncated image' => [fn (): string => mb_substr(GdImages::mosaicPng('a'), 0, 40, '8bit')],
]);

it('refuses to compare a malformed fingerprint', function (): void {
    ImageFingerprint::distance('abc', '0000000000000000');
})->throws(InvalidArgumentException::class);

it('tells a well formed fingerprint', function (): void {
    expect(ImageFingerprint::isValid('0123456789abcdef'))->toBeTrue()
        ->and(ImageFingerprint::isValid('0123456789abcde'))->toBeFalse()
        ->and(ImageFingerprint::isValid('0123456789abcdeg'))->toBeFalse();
});
