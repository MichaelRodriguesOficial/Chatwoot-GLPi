<?php

declare(strict_types=1);

namespace GlpiPlugin\Chatwoot\Tests;

use GlpiPlugin\Chatwoot\Config;
use PHPUnit\Framework\TestCase;

/**
 * 1.0.1 re-review of HIGH 1: the panel only serves conversations from the
 * inboxes the administrator enabled, so a leaked panel token can't reach
 * every inbox of the Chatwoot account. Fails closed when none is enabled.
 */
final class PanelInboxTest extends TestCase
{
    use TestHelpers;

    protected function setUp(): void
    {
        $this->freshDb();
        \CommonDBTM::$testConfig = [];
    }

    public function testOnlyListedInboxesAreAllowed(): void
    {
        \CommonDBTM::$testConfig = ['panel_inbox_ids' => '3,7'];
        $this->assertTrue(Config::isPanelInboxAllowed(3));
        $this->assertTrue(Config::isPanelInboxAllowed(7));
        $this->assertFalse(Config::isPanelInboxAllowed(4));
        $this->assertFalse(Config::isPanelInboxAllowed(0));
    }

    public function testFailsClosedWhenNothingIsEnabled(): void
    {
        foreach ([null, '', ' , ,', '0', 'abc'] as $stored) {
            \CommonDBTM::$testConfig = ['panel_inbox_ids' => $stored];
            $this->assertSame([], Config::panelInboxIds(), var_export($stored, true));
            $this->assertFalse(Config::isPanelInboxAllowed(3), var_export($stored, true));
        }
    }

    public function testFormInputIsNormalized(): void
    {
        $this->assertSame('3,7,12', Config::normalizePanelInboxIds(['12', '3', 'x', '-4', '7', '3', '', '0']));
        $this->assertSame('', Config::normalizePanelInboxIds([]));
    }

    public function testInboxListIsReadFromTheChatwootApiResponse(): void
    {
        // GET /api/v1/accounts/{id}/inboxes answers { payload: [ { id, name, channel_type, ... } ] }.
        $inboxes = Config::extractInboxes(['payload' => [
            ['id' => 7, 'name' => 'WhatsApp CAV', 'channel_type' => 'Channel::Whatsapp'],
            ['id' => 3, 'name' => 'Site GLPI', 'channel_type' => 'Channel::WebWidget'],
            ['id' => 0, 'name' => 'broken'],
        ]]);
        $this->assertSame([
            ['id' => 3, 'name' => 'Site GLPI', 'channel' => 'WebWidget'],
            ['id' => 7, 'name' => 'WhatsApp CAV', 'channel' => 'Whatsapp'],
        ], $inboxes);
        $this->assertSame([], Config::extractInboxes([]));
    }

    public function testUpgradeSeedsTheWidgetInboxOnlyWhenNeverConfigured(): void
    {
        $this->freshDb()->responder = static fn () => [
            ['id' => 1, 'inbox_id' => '3', 'panel_inbox_ids' => null],  // upgrading: seed with widget inbox
            ['id' => 2, 'inbox_id' => '3', 'panel_inbox_ids' => ''],    // admin unchecked everything: keep
            ['id' => 3, 'inbox_id' => '3', 'panel_inbox_ids' => '3,7'], // already configured: keep
            ['id' => 4, 'inbox_id' => '', 'panel_inbox_ids' => null],   // fresh, not configured yet: keep
        ];

        plugin_chatwoot_init_panel_inboxes('glpi_plugin_chatwoot_configs');

        $this->assertSame(
            [['table' => 'glpi_plugin_chatwoot_configs', 'values' => ['panel_inbox_ids' => '3'], 'where' => ['id' => 1]]],
            $this->db->updates
        );
    }
}
