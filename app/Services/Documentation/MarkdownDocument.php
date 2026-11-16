<?php

namespace App\Services\Documentation;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Renders generated documentation to safe HTML with anchored headings, and lists them for a table of contents.
 */
class MarkdownDocument
{
    /**
     * @return array{html: string, toc: list<array{level: int, id: string, title: string}>}
     */
    public function render(string $markdown): array
    {
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'heading_permalink' => ['insert' => 'none', 'apply_id_to_heading' => true, 'min_heading_level' => 2, 'max_heading_level' => 3],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new HeadingPermalinkExtension);

        $html = (string) (new MarkdownConverter($environment))->convert($markdown);

        preg_match_all('/<h([23]) id="([^"]+)">(.*?)<\/h\1>/s', $html, $matches, PREG_SET_ORDER);

        return [
            'html' => $html,
            'toc' => array_map(fn (array $match): array => ['level' => (int) $match[1], 'id' => $match[2], 'title' => html_entity_decode(strip_tags($match[3]))], $matches),
        ];
    }
}
