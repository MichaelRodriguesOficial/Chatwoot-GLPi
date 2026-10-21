<?php

declare(strict_types=1);

namespace GlpiPlugin\Chatwoot\Tests;

use GlpiPlugin\Chatwoot\Config;
use PHPUnit\Framework\TestCase;

/**
 * Per-document links (Config::mintDocumentLink / verifyDocumentLink): the only
 * credential the panel ever puts in a URL, so it must be bound to one user,
 * one ticket and one document, short-lived, and impossible to forge or alter.
 */
final class DocumentLinkTest extends TestCase
{
    use TestHelpers;

    public function testLinkIsRelativeToThePanelPage(): void
    {
        $link = Config::mintDocumentLink(7, 42, 99);
        $this->assertStringStartsWith('dashboard_document.php?d=', $link);
    }

    public function testRoundTripReturnsTheBoundUserTicketAndDocument(): void
    {
        $token = self::linkToken(Config::mintDocumentLink(7, 42, 99));
        $this->assertSame(['uid' => 7, 'tid' => 42, 'did' => 99], Config::verifyDocumentLink($token));
    }

    public function testEmptyAndGarbageTokensAreRejected(): void
    {
        $this->assertNull(Config::verifyDocumentLink(''));
        $this->assertNull(Config::verifyDocumentLink('not-a-token'));
        $this->assertNull(Config::verifyDocumentLink(base64_encode(random_bytes(64))));
    }

    public function testTamperedTokenIsRejected(): void
    {
        $raw = base64_decode(self::linkToken(Config::mintDocumentLink(7, 42, 99)), true);
        $raw[30] = chr(ord($raw[30]) ^ 1);
        $this->assertNull(Config::verifyDocumentLink(base64_encode($raw)));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $expired = (new \GLPIKey())->encrypt(json_encode(['typ' => 'doc', 'uid' => 7, 'tid' => 42, 'did' => 99, 'exp' => time() - 1]));
        $this->assertNull(Config::verifyDocumentLink($expired));
    }

    public function testTokenMissingABindingIsRejected(): void
    {
        $no_doc = (new \GLPIKey())->encrypt(json_encode(['typ' => 'doc', 'uid' => 7, 'tid' => 42, 'exp' => time() + 60]));
        $this->assertNull(Config::verifyDocumentLink($no_doc));
    }

    public function testAnUntypedPayloadIsRejected(): void
    {
        $untyped = (new \GLPIKey())->encrypt(json_encode(['uid' => 7, 'tid' => 42, 'did' => 99, 'exp' => time() + 60]));
        $this->assertNull(Config::verifyDocumentLink($untyped));
    }

    public function testACapabilityCannotBeUsedAsADocumentLink(): void
    {
        $capability = rawurldecode(Config::mintCapability(7));
        $this->assertNull(Config::verifyDocumentLink($capability));
    }

    /**
     * The 1.0.1 re-review's LOW finding: a per-document link (it appears in
     * URLs and lives 10 minutes) must never work as a capability for the
     * user's whole ticket list.
     */
    public function testADocumentLinkCannotBeUsedAsACapability(): void
    {
        $GLOBALS['DB'] = new \FakeDB();
        $GLOBALS['DB']->responder = static fn () => [['id' => 7, 'name' => 'jdoe', 'realname' => 'Doe', 'firstname' => 'John']];

        $link = self::linkToken(Config::mintDocumentLink(7, 42, 99));
        $this->assertNull(Config::verifyCapability($link));
        $this->assertNull(Config::verifyCapability(rawurlencode($link)));
    }
}
