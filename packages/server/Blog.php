<?php
// The blog's own pages (2026-11-23): its contents page (index.html) and its feed (feed.xml),
// made again from the published posts each time one is published, taken back or renamed,
// and the frame around each post: its date and the way back to the contents (2026-11-24).
// The contents page's heading is written in the source format and rendered like any page;
// the list of posts and the frame are written here, with fixed classes and every value
// escaped, so a post's title or description never becomes markup. Spec: docs/design/blog.md.

declare(strict_types=1);

namespace WebGenEngine\Server;

use WebGenEngine\Source\Document;
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
     * The files to write, by name in the site folder. $posts are the published posts, newest first;
     * "image" is the post's first picture as seen from the contents page (Api::contentsImage).
     *
     * $header and $footer are the blog's published parts as seen from the contents page (blog.md §8).
     *
     * @param list<array{id: string, title: string, date: string, description?: string, image?: string|null}> $posts
     * @return array<string, string|null> null removes the file
     */
    public function files(array $posts, ?Document $header = null, ?Document $footer = null): array
    {
        $head = $this->siteUrl === null ? [] : ['url' => rtrim($this->siteUrl, '/') . '/', 'type' => 'website', 'siteName' => $this->title];
        // In a site (site.md §9) the contents page carries the site's header and footer, made for it.
        $html = $this->renderer->renderPage($this->format->parse($this->contentsSource()), $this->title, 'ja', 'style.css', false, $head, $header, $footer);
        return ['index.html' => self::insertIntoMain($html, '', $this->contentsList($posts)), 'feed.xml' => $this->feed($posts)];
    }

    // The contents page's heading: the blog's name on a band of the theme's main colour, in a
    // Stack so that it lines up with the list (a heading's own margin is 0, so it would not centre).
    public function contentsSource(): string
    {
        return "<Section background=\"primary\" padding=\"lg\" width=\"narrow\">\n  <Stack>\n    <Heading level=\"1\">" . self::text($this->title) . "</Heading>\n  </Stack>\n</Section>\n";
    }

    /**
     * The list of posts under the heading: date, title, description and first picture of each.
     * The whole row opens the post (CSS); its link is the title.
     *
     * @param list<array{id: string, title: string, date: string, description?: string, image?: string|null}> $posts
     */
    public function contentsList(array $posts): string
    {
        if ($posts === []) {
            return "<div class=\"wge-posts\">\n  <p class=\"wge-posts-empty\">まだ記事はありません。</p>\n</div>\n";
        }
        $lines = ['<div class="wge-posts">', '  <ol class="wge-posts-list">'];
        foreach ($posts as $post) {
            $lines[] = '    <li class="wge-posts-item">';
            $lines[] = '      <div class="wge-posts-body">';
            $lines[] = '        ' . self::time('wge-posts-date', $post['date']);
            $lines[] = '        <h2 class="wge-posts-title"><a href="' . self::h($post['id']) . '/">' . self::h($post['title']) . '</a></h2>';
            if (($post['description'] ?? '') !== '') {
                $lines[] = '        <p class="wge-posts-description">' . self::h($post['description']) . '</p>';
            }
            $lines[] = '      </div>';
            // Beside its title the picture says nothing more, so screen readers skip it (alt="").
            if (($post['image'] ?? null) !== null) {
                $lines[] = '      <img class="wge-posts-thumb" src="' . self::h($post['image']) . '" alt="" loading="lazy" decoding="async">';
            }
            $lines[] = '    </li>';
        }
        $lines[] = '  </ol>';
        if ($this->siteUrl !== null) {
            $lines[] = '  <p class="wge-posts-feed"><a href="feed.xml">RSS フィード（新しい記事の知らせを受け取る）</a></p>';
        }
        $lines[] = '</div>';
        return implode("\n", $lines) . "\n";
    }

    // A published post with its frame: the way back to the contents and the date above the
    // post's own text, and a link back to the contents after it.
    public function framePost(string $page, string $date): string
    {
        $head = "<div class=\"wge-post-head\">\n  <div>\n    <p class=\"wge-post-crumb\"><a href=\"../\">‹ " . self::h($this->title) . "</a></p>\n    "
            . self::time('wge-post-date', $date) . "\n  </div>\n</div>\n";
        $foot = "<div class=\"wge-post-foot\">\n  <div><a class=\"wge-post-back\" href=\"../\">‹ 記事の一覧へ</a></div>\n</div>\n";
        return self::insertIntoMain($page, $head, $foot);
    }

    // renderPage puts the page's body in <main class="wge-page"> (rendering.md §5); $before goes
    // first in it and $after last. Both are markup made here, never a page's source. The page's
    // own text comes between its first opening tag and its last closing tag, so whatever that
    // text holds (Html is sanitized and has no <main>, but still) cannot move them.
    private static function insertIntoMain(string $page, string $before, string $after): string
    {
        $open = "<main class=\"wge-page\">\n";
        $start = strpos($page, $open);
        $end = strrpos($page, "</main>\n");
        if ($start === false || $end === false || $end < $start) {
            return $page;
        }
        $start += strlen($open);
        return substr($page, 0, $start) . $before . substr($page, $start, $end - $start) . $after . substr($page, $end);
    }

    private static function time(string $class, string $date): string
    {
        return '<time class="' . $class . '" datetime="' . self::h($date) . '">' . self::h(self::dateLabel($date)) . '</time>';
    }

    /** @param list<array{id: string, title: string, date: string, description?: string}> $posts */
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
            $description = ($post['description'] ?? '') !== '' ? '<description>' . $x($post['description']) . '</description>' : '';
            $out[] = '<item><title>' . $x($post['title']) . '</title><link>' . $x($link) . '</link><guid>' . $x($link) . '</guid><pubDate>' . $at->format(DATE_RSS) . '</pubDate>' . $description . '</item>';
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

    // Text and attribute values in HTML: & < > " and ' as character references.
    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    // Text and attribute values of the source format: & < > and " as character references.
    private static function text(string $s): string
    {
        return str_replace(['&', '<', '>', '"'], ['&amp;', '&lt;', '&gt;', '&quot;'], $s);
    }
}
