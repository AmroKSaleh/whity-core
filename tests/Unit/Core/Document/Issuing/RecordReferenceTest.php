<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Document\Issuing;

use PHPUnit\Framework\TestCase;
use Whity\Core\Document\Issuing\TemplateIssuing;

/**
 * The record reference every document route takes, `type:id` (WHIT-742):
 * issuing, the editor's preview and its render share this one parser, so a
 * workspace plugin's record (`CakeOrders.order:12`) works in all of them.
 */
final class RecordReferenceTest extends TestCase
{
    public function testCoreAndWorkspacePluginTypesParse(): void
    {
        self::assertSame(['document', '42'], TemplateIssuing::parseRecord('document:42'));
        self::assertSame(['CakeOrders.order', '12'], TemplateIssuing::parseRecord('CakeOrders.order:12'));

        $long = str_repeat('P', 64) . '.' . str_repeat('t', 64);
        self::assertSame([$long, '7'], TemplateIssuing::parseRecord($long . ':7'), 'the longest type a manifest allows');
    }

    public function testAnythingElseIsNoReference(): void
    {
        self::assertNull(TemplateIssuing::parseRecord(null));
        self::assertNull(TemplateIssuing::parseRecord(42));
        self::assertNull(TemplateIssuing::parseRecord('document'));
        self::assertNull(TemplateIssuing::parseRecord('document:'));
        self::assertNull(TemplateIssuing::parseRecord('docu ment:1'));
        self::assertNull(TemplateIssuing::parseRecord(str_repeat('x', 161) . ':1'));
    }
}
