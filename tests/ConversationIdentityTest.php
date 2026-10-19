<?php

declare(strict_types=1);

namespace GlpiPlugin\Chatwoot\Tests;

use GlpiPlugin\Chatwoot\Config;
use PHPUnit\Framework\TestCase;

/**
 * HIGH 1 of the security review: who the contact is must come from Chatwoot
 * itself (the conversation fetched server-side), never from a login or phone
 * number sent by the browser. The GLPI lookup stays the same: identifier
 * (GLPI login) first, then phone number (WhatsApp).
 */
final class ConversationIdentityTest extends TestCase
{
    use TestHelpers;

    protected function setUp(): void
    {
        $this->freshDb();
        \CommonDBTM::$testConfig = [];
    }

    public function testContactIsReadFromTheConversationsSender(): void
    {
        $conversation = ['id' => 439, 'meta' => ['sender' => ['id' => 12, 'name' => 'Michael Silva', 'identifier' => 'michael.silva', 'phone_number' => '+5511999998888']]];
        $this->assertSame(
            ['identifier' => 'michael.silva', 'phone' => '+5511999998888'],
            Config::extractConversationContact($conversation)
        );
    }

    public function testWhatsappContactWithOnlyAPhoneNumber(): void
    {
        $conversation = ['meta' => ['sender' => ['identifier' => null, 'phone_number' => '+5519988887777']]];
        $this->assertSame(['identifier' => '', 'phone' => '+5519988887777'], Config::extractConversationContact($conversation));
    }

    public function testMissingOrMalformedSenderYieldsNoIdentity(): void
    {
        $this->assertSame(['identifier' => '', 'phone' => ''], Config::extractConversationContact([]));
        $this->assertSame(['identifier' => '', 'phone' => ''], Config::extractConversationContact(['meta' => ['sender' => 'x']]));
    }

    public function testInvalidConversationIdNeverReachesChatwootOrGlpi(): void
    {
        \CommonDBTM::$testConfig = ['base_url' => 'https://chatwoot.example.com', 'api_key' => '', 'account_id' => '1'];
        $this->assertSame(['ok' => false, 'error' => 'invalid_conversation'], Config::resolveConversationUser(0));
        $this->assertSame(['ok' => false, 'error' => 'invalid_conversation'], Config::resolveConversationUser(-5));
        $this->assertCount(0, $this->db->queries);
    }

    public function testNoOutboundCallWithAnInvalidChatwootUrl(): void
    {
        \CommonDBTM::$testConfig = ['base_url' => 'file:///etc/passwd', 'api_key' => '', 'account_id' => '1'];
        $this->assertSame(['ok' => false, 'error' => 'not_configured'], Config::resolveConversationUser(439));
        $this->assertCount(0, $this->db->queries);
    }

    public function testCapabilityCarriesTheConversationItWasMintedFor(): void
    {
        $payload = json_decode((new \GLPIKey())->decrypt(rawurldecode(Config::mintCapability(7, 439))), true);
        $this->assertSame(7, $payload['uid']);
        $this->assertSame(439, $payload['cid']);
    }

    public function testIdentifyEndpointNoLongerAcceptsALoginOrPhoneFromTheBrowser(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/public/dashboard_app/dashboard_identify.php');
        $code   = preg_replace('#//[^\n]*#', '', $source); // ignore comments
        $this->assertStringNotContainsString("['identifier']", $code);
        $this->assertStringNotContainsString("['phone']", $code);
        $this->assertStringContainsString("\$_POST['conversation_id']", $code);
        $this->assertStringContainsString('resolveConversationUser(', $code);
    }
}
