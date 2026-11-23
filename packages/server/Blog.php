<?php
// The blog's own pages (2026-11-23): its contents page (index.html) and its feed (feed.xml),
// made again from the published posts each time one is published, taken back or renamed.
// The contents page is written in the source format and rendered like any page, so it looks
// like the posts and passes the same checks (R4). Spec: docs/design/blog.md.

declare(strict_types=1);

namespace WebGenEngine\Server;

use WebGenEngine\Source\Renderer;
use WebGenEngine\Source\SourceFormat;

final class Blog
{
    public function __construct(
        private readonly string $title,
        private readonly SourceFormat $format,
        private readonly Renderer $renderer,
        /** Where the site is seen (https://…/), for the feed's links; null writes no feed */
        private readonly ?string $siteUrl = null,
        private readonly string $timeZone = 'Asia/Tokyo',
    ) {
    }

    public function title(): string
    {
        return $this->title;
    }

    /**
     * The files to write, by name in the site folder. $posts are the published posts, newest first.
     *
     * @param list<array{id: string, title: string, date: string}> $posts
     * @return array<string, string|null> null removes the file
     */
    public function files(array $posts): array
    {
        $html = $this->renderer->renderPage($this->format->parse($this->contentsSource($posts)), $this->title, 'ja', 'style.css');
        return ['index.html' => $html, 'feed.xml' => $this->feed($posts)];
    }

    /** @param list<array{id: string, title: string, date: string}> $posts */
    public function contentsSource(array $posts): string
    {
        $lines = ['<Section padding="lg" width="narrow">', '  <Stack gap="lg">', '    <Heading level="1">' . self::text($this->title) . '</Heading>'];
        foreach ($posts as $post) {
            $lines[] = '    <Stack gap="xs">';
            $lines[] = '      <Text size="sm">' . self::text(self::dateLabel($post['date'])) . '</Text>';
            $lines[] = '      <Heading level="2"><Link href="' . self::text($post['id']) . '/">' . self::text($post['title']) . '</Link></Heading>';
            $lines[] = '    </Stack>';
        }
        if ($posts === []) {
            $lines[] = '    <Text>まだ記事はありません。</Text>';
        }
        $lines[] = '  </Stack>';
        $lines[] = '</Section>';
        return implode("\n", $lines) . "\n";
    }

    /** @param list<array{id: string, title: string, date: string}> $posts */
    public function feed(array $posts): ?string
    {
        if ($this->siteUrl === null) {
            return null;
        }
        $base = rtrim($this->siteUrl, '/') . '/';
        $x = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $out = ['<?xml version="1.0" encoding="UTF-8"?>', '<rss version="2.0">', '<channel>', '<title>' . $x($this->title) . '</title>', '<link>' . $x($base) . '</link>', '<description>' . $x($this->title) . '</description>'];
        foreach (array_slice($posts, 0, 50) as $post) {
            $link = $base . $post['id'] . '/';
            $at = new \DateTimeImmutable($post['date'] . ' 00:00:00', new \DateTimeZone($this->timeZone));
            $out[] = '<item><title>' . $x($post['title']) . '</title><link>' . $x($link) . '</link><guid>' . $x($link) . '</guid><pubDate>' . $at->format(DATE_RSS) . '</pubDate></item>';
        }
        $out[] = '</channel>';
        $out[] = '</rss>';
        return implode("\n", $out) . "\n";
    }

    // 2026-11-23 → 2026年11月23日
    private static function dateLabel(string $date): string
    {
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        return "{$y}年{$m}月{$d}日";
    }

    // Text and attribute values of the source format: & < > and " as character references.
    private static function text(string $s): string
    {
        return str_replace(['&', '<', '>', '"'], ['&amp;', '&lt;', '&gt;', '&quot;'], $s);
    }
}
