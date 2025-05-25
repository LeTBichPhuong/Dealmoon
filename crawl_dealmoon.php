<?php

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\DomCrawler\Crawler;

$client = HttpClient::create();
$response = $client->request('GET', 'https://www.dealmoon.com/en/popular-deals');

$html = $response->getContent();

$crawler = new Crawler($html);

$items = $crawler->filter('div.Topclick_R ul.Topclick_list > li')->each(function (Crawler $node) {
    $title = $node->filter('.proname')->count() ? $node->filter('.proname')->text() : null;
    $image = $node->filter('img')->count() ? $node->filter('img')->attr('src') : null;
    $promotion = $node->filter('.propoint')->count() ? $node->filter('.propoint')->text() : null;
    $link = $node->filter('a')->count() ? $node->filter('a')->attr('href') : null;

    return [
        'proname' => $title,
        'proimg' => $image,
        'propoint' => $promotion,
        'a' => $link,
    ];
});

file_put_contents(__DIR__ . '/storage/app/dealmoon_data.json', json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "Đã crawl xong " . count($items) . " sản phẩm.\n";
