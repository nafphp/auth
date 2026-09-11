<?php

declare(strict_types=1);

namespace Tests\Fixtures;

enum Permission: string
{
    case PostsEdit = 'posts.edit';
    case PostsPublish = 'posts.publish';
}
