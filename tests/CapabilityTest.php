<?php

declare(strict_types=1);

namespace GlpiPlugin\Chatwoot\Tests;

use GlpiPlugin\Chatwoot\Config;
use PHPUnit\Framework\TestCase;

/**
 * Capabilities (Config::mintCapability / verifyCapability) replace the raw
 * identifier/phone the 1.0.0 data endpoints trusted (HIGH 1 of the security
 * review): bound to one GLPI user id, short-lived, and re-checked against the
 * database on every use.
 */
final class CapabilityTest extends TestCase
{
    use TestHelpers;

    protected function setUp(): void
    {
        $this->freshDb()->responder = static function (array $criteria): array {
            $where = $criteria['WHERE'] ?? [];
            return ($where['id'] ?? null) === 7
                ? [['id' => 7, 'name' => 'jdoe', 'realname' => 'Doe', 'firstname' => 'John']]
                : [];
        };
    }

    public function testValidCapabilityResolvesTheBoundUser(): void
    {
        $user = Config::verifyCapability(Config::mintCapability(7));
        $this->assertSame(7, $user['id'] ?? null);
    }

    public function testUserIsRecheckedAsActiveAndNotDeletedAtVerificationTime(): void
    {
        Config::verifyCapability(Config::mintCapability(7));
        $where = end($this->db->queries)['WHERE'];
        $this->assertSame(7, $where['id']);
        $this->assertContains(['is_active' => 1], $where);
        $this->assertContains(['is_deleted' => 0], $where);
    }

    public function testCapabilityOfAUserNoLongerActiveIsRejected(): void
    {
        $this->assertNull(Config::verifyCapability(Config::mintCapability(8)));
    }

    public function testExpiredCapabilityIsRejected(): void
    {
        $expired = rawurlencode((new \GLPIKey())->encrypt(json_encode(['typ' => 'cap', 'uid' => 7, 'exp' => time() - 1])));
        $this->assertNull(Config::verifyCapability($expired));
    }

    public function testForgedOrEmptyCapabilityIsRejected(): void
    {
        $this->assertNull(Config::verifyCapability(''));
        $this->assertNull(Config::verifyCapability(base64_encode(json_encode(['uid' => 7, 'exp' => time() + 300]))));
    }

    public function testUntypedPayloadIsRejected(): void
    {
        $untyped = rawurlencode((new \GLPIKey())->encrypt(json_encode(['uid' => 7, 'exp' => time() + 300])));
        $this->assertNull(Config::verifyCapability($untyped));
    }

    public function testCapabilityExpiresAfterTheConfiguredTtl(): void
    {
        $payload = json_decode((new \GLPIKey())->decrypt(rawurldecode(Config::mintCapability(7))), true);
        $this->assertEqualsWithDelta(time() + Config::CAPABILITY_TTL, $payload['exp'], 2);
        $this->assertLessThanOrEqual(600, Config::CAPABILITY_TTL);
    }
}
