<?php

use App\Enums\ContentType;
use App\Services\MediaValidator;
use Tests\TestCase;

uses(TestCase::class);

/** Texto con `$n` hashtags distintos. */
function textWithHashtags(int $n, string $prefix = 'tag'): string
{
    return 'Lanzamiento '.implode(' ', array_map(fn ($i) => "#$prefix$i", range(1, $n)));
}

function checkText(ContentType $type, string $text): array
{
    return app(MediaValidator::class)->validate($type, [], $text);
}

function hashtagErrors(array $errors): array
{
    return array_values(array_filter($errors, fn ($e) => str_contains($e, 'hashtags')));
}

test('MediaValidatorHashtags: 30 hashtags pasan en Instagram y 31 fallan con el mensaje esperado', function () {
    expect(hashtagErrors(checkText(ContentType::InstagramReel, textWithHashtags(30))))->toBe([]);

    expect(hashtagErrors(checkText(ContentType::InstagramReel, textWithHashtags(31))))
        ->toBe(['El texto tiene 31 hashtags y un reel de Instagram admite como maximo 30.']);
});

test('MediaValidatorHashtags: el tope vale para todos los tipos de Instagram', function () {
    foreach ([ContentType::InstagramImage, ContentType::InstagramCarousel, ContentType::InstagramReel, ContentType::InstagramStory] as $type) {
        expect(hashtagErrors(checkText($type, textWithHashtags(31))))->toHaveCount(1, $type->value);
    }
});

test('MediaValidatorHashtags: un hashtag con emoji o unicode se cuenta por puntos de codigo, no por bytes', function () {
    // «#café» y «#日本語» son un hashtag cada uno; el emoji corta el hashtag («#fiesta🎉» cuenta una vez).
    expect(MediaValidator::countHashtags('#café #日本語 #fiesta🎉 sin hashtag, ni # solo ni a#b?'))->toBe(4)
        ->and(MediaValidator::countHashtags('texto sin etiquetas'))->toBe(0);

    $texto = str_repeat('#a ', 30).'#🎉';
    expect(MediaValidator::countHashtags($texto))->toBe(30);
    expect(hashtagErrors(checkText(ContentType::InstagramImage, $texto)))->toBe([]);
});

test('MediaValidatorHashtags: Facebook, YouTube y TikTok no tienen tope de hashtags', function () {
    foreach ([ContentType::FacebookText, ContentType::YouTubeVideo, ContentType::TikTokVideo] as $type) {
        expect(hashtagErrors(checkText($type, textWithHashtags(80))))->toBe([], $type->value);
    }
});
