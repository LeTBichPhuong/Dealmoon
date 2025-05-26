<?php

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

// 1. Cấu hình URL Strapi và Token API 
$strapiUrl = 'https://cms.mrcheaps.com/api/deals';
$token = getenv('STRAPI_TOKEN');

if (!$token) {
    die("Vui lòng thiết lập biến môi trường STRAPI_TOKEN với token Strapi API của bạn.\n");
}

$httpClient = HttpClient::create();

// 2. Crawler
try {
    $response = $httpClient->request('GET', 'https://www.dealmoon.com/en/popular-deals-beauty-2');
    $html = $response->getContent();
} catch (\Exception $e) {
    die("Lỗi khi lấy trang Dealmoon: " . $e->getMessage() . "\n");
}

$crawler = new Crawler($html);

// 3. Lấy danh sách sản phẩm
$items = $crawler->filter('div.Topclick_R ul.Topclick_list > li')->each(function (Crawler $node) use ($httpClient) {
    $title = $node->filter('.proname')->count() ? $node->filter('.proname')->text() : null;
    $image = $node->filter('img')->count() ? $node->filter('img')->attr('src') : null;
    $promotion = $node->filter('.propoint')->count() ? $node->filter('.propoint')->text() : null;
    $link = $node->filter('a')->count() ? $node->filter('a')->attr('href') : null;

    // Chuyển link relative thành link tuyệt đối nếu cần
    if ($link && strpos($link, 'http') !== 0) {
        $link = 'https://www.dealmoon.com' . $link;
    }

    $details = [];

    // Chi tiết sản phẩm
    if ($link) {
        try {
            $detailResponse = $httpClient->request('GET', $link);
            $detailHtml = $detailResponse->getContent();
            $detailCrawler = new Crawler($detailHtml);

            $details = $detailCrawler->filter('.edit-content li')->each(function (Crawler $li) {
                return trim($li->text());
            });
        } catch (\Exception $e) {
            $details = ['Lỗi tải chi tiết sản phẩm'];
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

// 4. Hàm kiểm tra strapi
function itemExists($httpClient, $strapiUrl, $token, $title) {
    try {
        $res = $httpClient->request('GET', $strapiUrl . '?filters[title][$eq]=' . urlencode($title), [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
        ]);
        $data = $res->toArray();
        return count($data['data']) > 0 ? $data['data'][0]['id'] : false;
    } catch (\Exception $e) {
        return false;
    }
}

// 5. Gửi dữ liệu lên Strapi 
foreach ($items as $index => $item) {
    if (empty($item['proname'])) {
        // Bỏ qua
        continue;
    }

    $payload = [
        'data' => [
            'title' => $item['proname'],
            'image' => $item['proimg'],
            'promotion' => $item['propoint'],
            'link' => $item['a'],
            'position' => $index + 1,
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
            // Cập nhật 
            $res = $httpClient->request('PUT', $strapiUrl . '/' . $existingId, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($payload),
            ]);
            echo "Cập nhật: " . $item['proname'] . " - Status: " . $res->getStatusCode() . "\n";
        } else {
            // Thêm mới
            $res = $httpClient->request('POST', $strapiUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
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
