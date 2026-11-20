<?php

namespace App\Services\Posts;

use App\Models\Post;

final readonly class DuplicatePostResult
{
    /** @param list<string> $resetOptions */
    public function __construct(public Post $post, public int $omitted, public array $resetOptions) {}
}
