<?php

declare(strict_types=1);

namespace GlpiPlugin\Chatwoot\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The panel's attachment list: documents of the ticket, of the follow-ups and
 * of the solution actually shown (already privacy-filtered by the timeline),
 * never documents of anything else.
 */
final class AttachmentsTest extends TestCase
{
    use TestHelpers;

    private const ROWS = [
        ['id' => 5, 'itemtype' => 'Ticket', 'items_id' => 100, 'name' => 'contrato', 'filename' => 'contrato.pdf', 'filesize' => 2048],
        // Same file linked to the ticket AND to follow-up 11: must be shown under the follow-up.
        ['id' => 7, 'itemtype' => 'Ticket', 'items_id' => 100, 'name' => 'Documento do Chamado 521515', 'filename' => 'Sugestao Migração V1 1 (1) 2.xlsx', 'filesize' => 69580],
        ['id' => 7, 'itemtype' => 'ITILFollowup', 'items_id' => 11, 'name' => 'Documento do Chamado 521515', 'filename' => 'Sugestao Migração V1 1 (1) 2.xlsx', 'filesize' => 69580],
        // Inline image already shown as a thumbnail.
        ['id' => 8, 'itemtype' => 'ITILFollowup', 'items_id' => 11, 'name' => 'image.png', 'filename' => 'image.png', 'filesize' => 10],
        ['id' => 9, 'itemtype' => 'ITILSolution', 'items_id' => 20, 'name' => 'relatorio', 'filename' => '', 'filesize' => 0],
    ];

    protected function setUp(): void
    {
        $this->freshDb()->responder = static fn () => self::ROWS;
    }

    private function load(array $timeline, array $exclude = []): array
    {
        return self::callPrivate('getTicketAttachments', 100, $timeline, $exclude);
    }

    public function testQueriesOnlyTheTicketAndTheItemsShownInTheTimeline(): void
    {
        $this->load([['item_key' => 'ITILFollowup:11'], ['item_key' => 'ITILFollowup:12'], ['item_key' => 'ITILSolution:20']]);
        $where = end($this->db->queries)['WHERE'];

        $this->assertSame([
            ['glpi_documents_items.itemtype' => 'Ticket', 'glpi_documents_items.items_id' => 100],
            ['glpi_documents_items.itemtype' => 'ITILFollowup', 'glpi_documents_items.items_id' => [11, 12]],
            ['glpi_documents_items.itemtype' => 'ITILSolution', 'glpi_documents_items.items_id' => [20]],
        ], $where[0]['OR']);
        $this->assertSame(0, $where['glpi_documents.is_deleted']);
        $this->assertSame(0, $where['glpi_documents_items.is_deleted']);
    }

    public function testAHiddenPrivateFollowUpIsNeverQueried(): void
    {
        // A private follow-up hidden by configuration is simply absent from the
        // timeline, so its id must not appear in the query at all.
        $this->load([['item_key' => 'ITILFollowup:11']]);
        $or = end($this->db->queries)['WHERE'][0]['OR'];
        $this->assertSame([11], $or[1]['glpi_documents_items.items_id']);
        $this->assertCount(2, $or);
    }

    public function testWithoutTimelineItemsOnlyTheTicketIsQueried(): void
    {
        $this->load([]);
        $this->assertCount(1, end($this->db->queries)['WHERE'][0]['OR']);
    }

    public function testDeduplicatesExcludesInlineImagesAndPrefersTheMostSpecificSource(): void
    {
        $list = $this->load([['item_key' => 'ITILFollowup:11'], ['item_key' => 'ITILSolution:20']], [8]);

        $this->assertSame([5, 7, 9], array_column($list, 'id'));
        $this->assertSame('ITILFollowup:11', $list[1]['source']);
        $this->assertSame('Sugestao Migração V1 1 (1) 2.xlsx', $list[1]['name']);
        $this->assertSame('ITILSolution:20', $list[2]['source']);
    }
}
