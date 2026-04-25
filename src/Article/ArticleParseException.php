<?php

declare(strict_types=1);

namespace App\Article;

final class ArticleParseException extends \RuntimeException
{
    public static function missingRequiredField(string $url, string $field): self
    {
        return new self(sprintf('Unable to parse required field "%s" from article "%s".', $field, $url));
    }
}
