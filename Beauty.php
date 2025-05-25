<?php

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

// strapi
$strapiUrl = 'https://cms.mrcheaps.com/api/deals';

// API Token
$token = '475c4f10f36748cfe39d84f297699917a5f2873af9738859b760ce9449f7f1579305311fe7fc3ffa291f6703ffec00b88d63ecf202c980981a8db479ecc0e89cbf0763b94584016b59cb2489dab1ee8de225e9badd4b3ea545b02a211baf8a6d9389af5de7805df088624482b9ba86e4ef19b7589e2ae53ac06b565912745f7f';


$httpClient = HttpClient::create();
$response = $httpClient->request('GET', 'https://www.dealmoon.com/en/popular-deals-beauty-2');

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
            $details = ['Lỗi tải lên deatail'];
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

// Đưa dữ liệu lên Strapi
foreach ($items as $item) {
    $payload = [
        'data' => [
            'title' => $item['proname'],
            'image' => $item['proimg'],
            'promotion' => $item['propoint'],
            'link' => $item['a'],
            'content' => [
                'ck' => implode('<br>', $item['details']),
            ],
            'viContent' => [
                'ck' => implode('<br>', $item['details']), 
            ],
        ]
    ];

    try {
        $res = $httpClient->request('POST', $strapiApiUrl, [
            'headers' => [
                'Authorization' => 'Bearer ' . $strapiToken,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($payload),
        ]);

        echo "Đã gửi: " . $item['proname'] . " - Status: " . $res->getStatusCode() . "\n";
    } catch (TransportExceptionInterface $e) {
        echo "Lỗi gửi: " . $e->getMessage() . "\n";
    }
}
