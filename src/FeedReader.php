<?php
declare(strict_types=1);

namespace PenguLab;

use RuntimeException;

final class FeedReader
{
    public function __construct(private HttpClient $http = new HttpClient()) {}

    public function read(string $url, int $limit = 8, bool $verifyTls = true): array
    {
        $response = $this->http->request('GET', $url, [
            'verify_tls' => $verifyTls,
            'timeout' => 8,
            'headers' => ['Accept' => 'application/rss+xml, application/atom+xml, application/xml, text/xml, */*'],
        ]);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('Feed returned HTTP ' . $response['status']);
        }
        $xml = trim((string)$response['body']);
        if ($xml === '') throw new RuntimeException('Feed is empty.');

        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);
        if ($doc === false) throw new RuntimeException('Feed XML could not be parsed.');

        $items = [];
        $title = '';
        if (isset($doc->channel)) {
            $title = trim((string)$doc->channel->title);
            foreach ($doc->channel->item as $item) {
                $items[] = $this->rssItem($item);
                if (count($items) >= $limit) break;
            }
        } else {
            $namespaces = $doc->getNamespaces(true);
            $title = trim((string)$doc->title);
            foreach ($doc->entry as $entry) {
                $items[] = $this->atomItem($entry, $namespaces);
                if (count($items) >= $limit) break;
            }
        }

        return ['title' => $title, 'items' => array_values(array_filter($items, fn(array $i): bool => $i['title'] !== ''))];
    }

    private function rssItem(\SimpleXMLElement $item): array
    {
        $link = trim((string)$item->link);
        $date = trim((string)($item->pubDate ?? ''));
        return [
            'title' => trim(strip_tags((string)$item->title)),
            'link' => $link,
            'date' => $this->date($date),
            'description' => $this->excerpt((string)($item->description ?? '')),
        ];
    }

    private function atomItem(\SimpleXMLElement $entry, array $namespaces): array
    {
        $link = '';
        foreach ($entry->link as $linkNode) {
            $attrs = $linkNode->attributes();
            $rel = (string)($attrs['rel'] ?? 'alternate');
            if ($rel === '' || $rel === 'alternate') {
                $link = trim((string)($attrs['href'] ?? ''));
                if ($link !== '') break;
            }
        }
        return [
            'title' => trim(strip_tags((string)$entry->title)),
            'link' => $link,
            'date' => $this->date((string)($entry->updated ?? $entry->published ?? '')),
            'description' => $this->excerpt((string)($entry->summary ?? $entry->content ?? '')),
        ];
    }

    private function excerpt(string $html): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($html, ENT_QUOTES | ENT_HTML5))) ?? '');
        return mb_strlen($text) > 180 ? mb_substr($text, 0, 177) . '…' : $text;
    }

    private function date(string $raw): ?string
    {
        if (trim($raw) === '') return null;
        $ts = strtotime($raw);
        return $ts === false ? null : gmdate(DATE_ATOM, $ts);
    }
}
