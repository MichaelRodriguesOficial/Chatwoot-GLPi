<?php

declare(strict_types=1);

namespace GlpiPlugin\Chatwoot\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Upgrading from 1.0.0: the plaintext Dashboard App token is encrypted in
 * place (so the panel URL already registered in Chatwoot keeps working),
 * and an already-encrypted value is never touched twice.
 */
final class LegacyTokenMigrationTest extends TestCase
{
    use TestHelpers;

    public function testPlaintextTokenIsEncryptedInPlaceAndStillDecryptsToTheSameValue(): void
    {
        $legacy = bin2hex(random_bytes(32));
        $this->freshDb()->responder = static fn () => [['id' => 1, 'dashboard_app_token' => $legacy]];

        plugin_chatwoot_encrypt_legacy_dashboard_token('glpi_plugin_chatwoot_configs');

        $this->assertCount(1, $this->db->updates);
        $stored = $this->db->updates[0]['values']['dashboard_app_token'];
        $this->assertNotSame($legacy, $stored);
        $this->assertSame($legacy, (new \GLPIKey())->decrypt($stored));
        $this->assertSame(['id' => 1], $this->db->updates[0]['where']);
    }

    public function testAlreadyEncryptedOrEmptyTokenIsLeftAlone(): void
    {
        $encrypted = (new \GLPIKey())->encrypt(bin2hex(random_bytes(32)));
        $this->freshDb()->responder = static fn () => [
            ['id' => 1, 'dashboard_app_token' => $encrypted],
            ['id' => 2, 'dashboard_app_token' => ''],
            ['id' => 3, 'dashboard_app_token' => null],
        ];

        plugin_chatwoot_encrypt_legacy_dashboard_token('glpi_plugin_chatwoot_configs');

        $this->assertCount(0, $this->db->updates);
    }
}
