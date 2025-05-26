<?php

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;

// Cấu hình
$strapiUrl = 'https://cms.mrcheaps.com/api/deals';
$token = getenv('STRAPI_TOKEN'); 

if (!$token) {
    exit("Vui lòng thiết lập biến môi trường STRAPI_TOKEN với token Strapi API của bạn.\n");
}

$httpClient = HttpClient::create();

// Crawl
$response = $httpClient->request('GET', 'https://www.dealmoon.com/en/popular-deals-beauty-2');
$html = $response->getContent();
$crawler = new Crawler($html);

$items = $crawler->filter('div.Topclick_R ul.Topclick_list > li')->each(function (Crawler $node) use ($httpClient) {
    $title = $node->filter('.proname')->count() ? trim($node->filter('.proname')->text()) : null;
    $image = $node->filter('img')->count() ? $node->filter('img')->attr('src') : null;
    $promotion = $node->filter('.propoint')->count() ? $node->filter('.propoint')->text() : null;
    $link = $node->filter('a')->count() ? $node->filter('a')->attr('href') : null;

    $details = [];

    // Chi tiết nội dung
    if ($link) {
        try {
            $detailResponse = $httpClient->request('GET', $link);
            $detailHtml = $detailResponse->getContent();
            $detailCrawler = new Crawler($detailHtml);

            $details = $detailCrawler->filter('.edit-content li')->each(function (Crawler $li) {
                return trim($li->text());
            });
        } catch (\Exception $e) {
            $details = ['Lỗi tải chi tiết'];
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

// Hàm kiểm tra sản phẩm 
function itemExists($httpClient, $strapiUrl, $token, $title)
{
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

//Gửi dữ liệu lên Strapi
foreach ($items as $index => $item) {
    // bỏ qua nếu không có tên
    if (empty($item['proname'])) continue;

    $payload = [
        'data' => [
            'title' => $item['proname'],
            'image' => $item['proimg'],
            'link' => $item['a'],
            'order' => $index + 1,
            'source' => 'dealmoon',
            'crawledAt' => date('c'),
            'content' => [
                'ck' => implode('<br>', $item['details']),
            ],
            'viContent' => [
                'ck' => implode('<br>', $item['details']),
            ],
        ]
    ];

    $existingId = itemExists($httpClient, $strapiUrl, $token, $item['proname']);

    try {
        if ($existingId) {
            // PUT - cập nhật
            $res = $httpClient->request('PUT', $strapiUrl . '/' . $existingId, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($payload),
            ]);
            echo "Cập nhật: {$item['proname']} - Status: " . $res->getStatusCode() . "\n";
        } else {
            // POST - thêm mới
            $res = $httpClient->request('POST', $strapiUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($payload),
            ]);
            echo "Thêm mới: {$item['proname']} - Status: " . $res->getStatusCode() . "\n";
        }
    } catch (ClientExceptionInterface $e) {
        echo "Lỗi gửi dữ liệu: " . $e->getMessage() . "\n";
        echo "Response body: " . $e->getResponse()->getContent(false) . "\n";
    }
}
