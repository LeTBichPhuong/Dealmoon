<?php

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

// 1. Cấu hình URL Strapi và Token API
$strapiUrl = 'https://cms.mrcheaps.com/api/deals';
$strapiToken = '475c4f10f36748cfe39d84f297699917a5f2873af9738859b760ce9449f7f1579305311fe7fc3ffa291f6703ffec00b88d63ecf202c980981a8db479ecc0e89cbf0763b94584016b59cb2489dab1ee8de225e9badd4b3ea545b02a211baf8a6d9389af5de7805df088624482b9ba86e4ef19b7589e2ae53ac06b565912745f7f'; 

$httpClient = HttpClient::create();

// 2. Crawler Dealmoon trang beauty
$response = $httpClient->request('GET', 'https://www.dealmoon.com/en/popular-deals-beauty-2');
$html = $response->getContent();
$crawler = new Crawler($html);

// 3. Lấy danh sách sản phẩm
$items = $crawler->filter('div.Topclick_R ul.Topclick_list > li')->each(function (Crawler $node) use ($httpClient) {
    $title = $node->filter('.proname')->count() ? $node->filter('.proname')->text() : null;
    $image = $node->filter('img')->count() ? $node->filter('img')->attr('src') : null;
    $promotion = $node->filter('.propoint')->count() ? $node->filter('.propoint')->text() : null;
    $link = $node->filter('a')->count() ? $node->filter('a')->attr('href') : null;

    $details = [];

    // Nếu có link sản phẩm thì crawl thêm chi tiết
    if ($link) {
        try {
            $detailResponse = $httpClient->request('GET', $link);
            $detailHtml = $detailResponse->getContent();
            $detailCrawler = new Crawler($detailHtml);

            $details = $detailCrawler->filter('.edit-content li')->each(function (Crawler $li) {
                return trim($li->text());
            });
        } catch (\Exception $e) {
            $details = ['Lỗi tải detail'];
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

// 4. Hàm kiểm tra sản phẩm đã có trong Strapi chưa (theo title)
function itemExists($httpClient, $strapiUrl, $token, $title) {
    try {
        $res = $httpClient->request('GET', $strapiUrl . '?filters[title][$eq]=' . urlencode($title), [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
        ]);
        $data = $res->toArray();
        return count($data['data']) > 0 ? $data['data'][0]['id'] : false;
    } catch (Exception $e) {
        return false;
    }
}

// 5. Gửi dữ liệu lên Strapi (POST hoặc PUT tuỳ trường hợp)
foreach ($items as $index => $item) {
    $payload = [
        'data' => [
            'title' => $item['proname'],
            'image' => $item['proimg'],
            'promotion' => $item['propoint'],
            'link' => $item['a'],
            'position' => $index + 1, // Thêm vị trí để sắp xếp đúng như trang web
            'content' => [
                'ck' => implode('<br>', $item['details']),
            ],
            'viContent' => [
                'ck' => implode('<br>', $item['details']),
            ],
        ]
    ];

    $existingId = itemExists($httpClient, $strapiUrl, $strapiToken, $item['proname']);

    try {
        if ($existingId) {
            // PUT - cập nhật sản phẩm đã tồn tại
            $res = $httpClient->request('PUT', $strapiUrl . '/' . $existingId, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $strapiToken,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($payload),
            ]);
            echo "Cập nhật: " . $item['proname'] . " - Status: " . $res->getStatusCode() . "\n";
        } else {
            // POST - thêm sản phẩm mới
            $res = $httpClient->request('POST', $strapiUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $strapiToken,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($payload),
            ]);
            echo "Thêm mới: " . $item['proname'] . " - Status: " . $res->getStatusCode() . "\n";
        }
    } catch (TransportExceptionInterface $e) {
        echo "Lỗi gửi: " . $e->getMessage() . "\n";
    }
}
