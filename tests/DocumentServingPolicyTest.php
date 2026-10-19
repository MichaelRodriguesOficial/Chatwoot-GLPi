<?php

declare(strict_types=1);

namespace GlpiPlugin\Chatwoot\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Which documents may be rendered `inline` (HIGH 2 of the security review):
 * exactly GLPI core's own allow-list — images except SVG, plus PDF. Anything
 * else, above all attacker-controlled HTML, must be forced to `attachment`.
 */
final class DocumentServingPolicyTest extends TestCase
{
    use TestHelpers;

    public function testInlineAllowList(): void
    {
        $cases = [
            'image/png'             => true,
            'image/jpeg'            => true,
            'image/gif'             => true,
            'IMAGE/PNG'             => true,
            'application/pdf'       => true,
            'image/svg+xml'         => false,
            'text/html'             => false,
            'application/xhtml+xml' => false,
            'text/xml'              => false,
            'text/javascript'       => false,
            'text/plain'            => false,
            'application/octet-stream' => false,
            ''                      => false,
        ];
        foreach ($cases as $mime => $expected) {
            $this->assertSame($expected, self::callPrivate('canDisplayInline', $mime), "inline($mime)");
        }
    }

    public function testRealUploadNameIsPreferredOverGlpisAutoTitle(): void
    {
        $this->assertSame(
            'Sugestao Migração V1 1 (1) 2.xlsx',
            self::callPrivate('documentDisplayName', 'Documento do Chamado 521515', 'Sugestao Migração V1 1 (1) 2.xlsx')
        );
        $this->assertSame('relatorio', self::callPrivate('documentDisplayName', 'relatorio', ''));
    }
}
