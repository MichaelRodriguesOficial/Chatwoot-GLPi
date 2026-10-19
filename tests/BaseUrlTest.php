<?php

declare(strict_types=1);

namespace GlpiPlugin\Chatwoot\Tests;

use GlpiPlugin\Chatwoot\Config;
use PHPUnit\Framework\TestCase;

/** MEDIUM 4 of the security review: the Chatwoot URL is validated before any outbound call. */
final class BaseUrlTest extends TestCase
{
    public function testOnlyHttpAndHttpsUrlsAreAccepted(): void
    {
        $this->assertTrue(Config::isValidBaseUrl('https://chatwoot.example.com'));
        $this->assertTrue(Config::isValidBaseUrl('http://10.0.0.5:3000'));

        foreach (['', 'chatwoot.example.com', 'file:///etc/passwd', 'gopher://x', 'ftp://x.y', 'javascript:alert(1)', 'https://'] as $url) {
            $this->assertFalse(Config::isValidBaseUrl($url), $url);
        }
    }
}
