<?php
// Устанавливаем максимальное время выполнения скрипта в 1 час (3600 секунд)
set_time_limit(3600);

require_once "config\constants.php";
require_once "db.php";

// Функции парсинга цен (оставлены без изменений)
function getPriceFromURLHyper($url) {
    if (empty($url)) return 0;
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);
    $html = curl_exec($ch);
    curl_close($ch);

    if (preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches)) {
        $data = json_decode($matches[1], true);
        return isset($data['offers'][0]['price']) ? (float)$data['offers'][0]['price'] : 0;
    }
    return 0;
}

function getPriceKNS($url) {
    if (empty($url)) return 0;
    $html = file_get_contents($url);
    if ($html === false) return 0;
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    $priceMeta = $xpath->query("//div[@itemprop='offers']//meta[@itemprop='price']");
    if ($priceMeta->length > 0) {
        return $priceMeta->item(0)->attributes->getNamedItem('content')->nodeValue;
    }
    return 0;
}

function getPriceXcom($url) {
    if (empty($url)) return 0;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    if (!$html) return 0;
    $html = preg_replace('/\s+/', ' ', $html);
    libxml_use_internal_errors(true);
    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);
    $priceElement = $xpath->query('//meta[@itemprop="price"]')->item(0);
    return $priceElement instanceof DOMElement ? $priceElement->getAttribute('content') : 0;
}

function parsePriceRegardCit($url) {
    if (empty($url)) return 0;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    if (!$html) return 0;
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $scripts = $dom->getElementsByTagName('script');
    foreach ($scripts as $script) {
        if ($script->getAttribute('type') === 'application/ld+json') {
            $jsonData = json_decode($script->nodeValue, true);
            if (isset($jsonData['offers']['price'])) {
                return (float)$jsonData['offers']['price'];
            }
        }
    }
    return 0;
}

// Получаем данные из таблицы motherboards
$products = [];
$query = "SELECT * FROM motherboards";
$result = mysqli_query($con, $query);

if (!$result) {
    die("Ошибка при получении данных из motherboards: " . mysqli_error($con));
}

while ($row = mysqli_fetch_assoc($result)) {
    $product = [
        'name' => $row['product_title'],
        'prices' => [],
        'avg_price' => 0
    ];
    
    // Получаем цены из всех магазинов
    if (!empty($row['hyper_url'])) {
        $product['prices']['hyper'] = getPriceFromURLHyper($row['hyper_url']);
    }
    if (!empty($row['kns_url'])) {
        $product['prices']['kns'] = getPriceKNS($row['kns_url']);
    }
    if (!empty($row['xcom_url'])) {
        $product['prices']['xcom'] = getPriceXcom($row['xcom_url']);
    }
    if (!empty($row['regard_url'])) {
        $product['prices']['regard'] = parsePriceRegardCit($row['regard_url']);
    }
    if (!empty($row['citi_url'])) {
        $product['prices']['citi'] = parsePriceRegardCit($row['citi_url']);
    }
    
    // Фильтруем нулевые цены и считаем среднюю
    $validPrices = array_filter($product['prices'], function($price) {
        return is_numeric($price) && $price > 0;
    });
    
    if (!empty($validPrices)) {
        $product['avg_price'] = array_sum($validPrices) / count($validPrices);
    }
    
    $products[] = $product;
}

// Генерируем CSV файл с процентными отклонениями
// Генерируем CSV файл с процентными отклонениями
$csvData = [];
$headers = ['Product Name', 'Average Price'];

// Собираем все возможные магазины
$allShops = ['hyper', 'kns', 'xcom', 'regard', 'citi'];
foreach ($allShops as $shop) {
    $headers[] = ucfirst($shop) . ' Price';
    $headers[] = ucfirst($shop) . ' Deviation (%)';
}

$csvData[] = $headers;

foreach ($products as $product) {
    // Формируем строку с защитой форматов
    $row = [
        $product['name'],
        $product['avg_price'] > 0 ? '="' . round($product['avg_price'], 2) . '"' : ''
    ];
    
    foreach ($allShops as $shop) {
        $price = isset($product['prices'][$shop]) && $product['prices'][$shop] > 0 
               ? '="' . $product['prices'][$shop] . '"' 
               : '';
        
        $deviation = '';
        if ($price !== '' && $product['avg_price'] > 0) {
            $deviationValue = round((($product['prices'][$shop] - $product['avg_price']) / $product['avg_price'] * 100), 2);
            $deviation = '="' . $deviationValue . '%"'; // Добавляем знак % внутри кавычек
        }
        
        $row[] = $price;
        $row[] = $deviation;
    }
    
    $csvData[] = $row;
}

// Сохраняем CSV файл с правильным форматированием
$csvFileName = 'motherboards_price_deviations.csv';
$fp = fopen($csvFileName, 'w');

if ($fp === false) {
    die("Не удалось создать файл для записи.");
}

// Добавляем BOM и указываем формат
fwrite($fp, "\xEF\xBB\xBF");
fwrite($fp, "sep=;\n"); // Явно указываем разделитель

foreach ($csvData as $row) {
    fputcsv($fp, $row, ';');
}

fclose($fp);

echo "Файл с отклонениями цен сохранен как: " . $csvFileName . "\n";
mysqli_close($con);
?>