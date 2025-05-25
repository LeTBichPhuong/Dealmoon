<?php

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\DomCrawler\Crawler;

$httpClient = HttpClient::create();
$response = $httpClient->request('GET', 'https://www.dealmoon.com/en/popular-deals');

$html = $response->getContent();

$crawler = new Crawler($html);

$items = $crawler->filter('div.Topclick_R ul.Topclick_list > li')->each(function (Crawler $node) use ($httpClient) {
    $title = $node->filter('.proname')->count() ? $node->filter('.proname')->text() : null;
    $image = $node->filter('img')->count() ? $node->filter('img')->attr('src') : null;
    $promotion = $node->filter('.propoint')->count() ? $node->filter('.propoint')->text() : null;
    $link = $node->filter('a')->count() ? $node->filter('a')->attr('href') : null;

    $details = [];

    if ($link) {
        try {
            $detailResponse = $httpClient->request('GET', $link);
            $detailHtml = $detailResponse->getContent();
            $detailCrawler = new Crawler($detailHtml);

            $details = $detailCrawler->filter('.edit-content li')->each(function (Crawler $li) {
                return trim($li->text());
            });
        } catch (\Exception $e) {
            $details = ['Lỗi'];
        }
    }

    return [
        'proname' => $title,
        'proimg' => $image,
        'propoint' => $promotion,
        'a' => $link,
        'details' => $details,
    ];
});

// Lưu ra file JSON
file_put_contents(__DIR__ . '/storage/app/topclick_data.json',json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "Đã crawl xong " . count($items) . " danh sách.\n";
