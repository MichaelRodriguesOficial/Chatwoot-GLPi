<?php

declare(strict_types=1);

namespace GlpiPlugin\Chatwoot\Tests;

use GlpiPlugin\Chatwoot\Config;

trait TestHelpers
{
    private \FakeDB $db;

    protected function freshDb(): \FakeDB
    {
        $this->db = new \FakeDB();
        $GLOBALS['DB'] = $this->db;
        return $this->db;
    }

    /** Calls a private static method of Config. */
    protected static function callPrivate(string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod(Config::class, $method);
        return $ref->invoke(null, ...$args);
    }

    /** Extracts the `d` query value exactly as PHP would put it in $_GET. */
    protected static function linkToken(string $link): string
    {
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        return (string) ($query['d'] ?? '');
    }
}
