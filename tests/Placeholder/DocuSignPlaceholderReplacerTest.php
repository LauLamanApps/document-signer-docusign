<?php

declare(strict_types=1);

namespace LauLamanApps\DocumentSigner\DocuSign\Tests\Placeholder;

use LauLamanApps\DocumentSigner\Sdk\Placeholder\PlaceholderParser;
use LauLamanApps\DocumentSigner\DocuSign\Placeholder\DocuSignPlaceholderReplacer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocuSignPlaceholderReplacerTest extends TestCase
{
    #[Test]
    public function it_emits_asterisk_bracketed_anchor_tokens(): void
    {
        $html = '<p>{[signature:s1:sig]} and {[date:s1:signdate]}</p>';
        $parsed = (new PlaceholderParser())->parse($html);

        $prepared = (new DocuSignPlaceholderReplacer())->replace($html, $parsed);

        self::assertCount(2, $prepared->fields);
        self::assertSame('**DS:signature:s1:sig**', $prepared->fields[0]->anchorString);
        self::assertSame('**DS:date:s1:signdate**', $prepared->fields[1]->anchorString);

        self::assertStringContainsString('**DS:signature:s1:sig**', $prepared->html);
        self::assertStringContainsString('**DS:date:s1:signdate**', $prepared->html);
    }
}
