<?php

declare(strict_types=1);

namespace Yjs;

final class UndefinedValue
{
    private static ?self $instance = null;

    private function __construct()
    {
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }
}
