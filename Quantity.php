//Кол-во магазинов с минимальной ценой
<?php
// Устанавливаем максимальное время выполнения скрипта в 1 час (3600 секунд)
set_time_limit(3600);

require_once "config\constants.php";
require_once "db.php";

// Глобальный счетчик минимальных цен по магазинам
$shopStats = [
    'hyper' => 0,
    'kns' => 0,
    'xcom' => 0,
    'regard' => 0,
    'citi' => 0
];

// Функции парсинга цен (с небольшими оптимизациями)
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
    // Проверяем, является ли URL пустым
    if (empty($url)) {
        return 0; // Возвращаем цену 0, если URL пустой
    }

    // Получаем HTML-код страницы
    $html = file_get_contents($url);

    if ($html === false) {
        return 0; // Возвращаем 0, если страницу не удалось загрузить
    }

    // Создаем DOMDocument и загружаем HTML
    $dom = new DOMDocument();
    libxml_use_internal_errors(true); // Отключаем ошибки парсинга
    $dom->loadHTML($html);
    libxml_clear_errors();

    // Создаем XPath для поиска
    $xpath = new DOMXPath($dom);

    // Ищем элемент с meta-тегом, содержащим цену
    $priceMeta = $xpath->query("//div[@itemprop='offers']//meta[@itemprop='price']");

    if ($priceMeta->length > 0) {
        // Извлекаем цену из атрибута 'content'
        $price = $priceMeta->item(0)->attributes->getNamedItem('content')->nodeValue;
        return $price; // Возвращаем только значение цены
    } else {
        return 0; // Возвращаем 0, если цена не найдена
    }
}



function getPriceXcom($url) {

    if (empty($url)) {
        return 0;
    }
    // Инициализация cURL
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $html = curl_exec($ch);
    curl_close($ch);

    // Проверяем успешность загрузки
    if (!$html) {
        return "Не удалось загрузить страницу!";
    }

    // Убираем лишние пробелы и ненужные символы для ускорения обработки
    $html = preg_replace('/\s+/', ' ', $html);

    // Загружаем HTML в DOMDocument
    libxml_use_internal_errors(true); // Отключаем ошибки для чистой обработки
    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    libxml_clear_errors();

    // XPath для быстрого поиска нужного элемента
    $xpath = new DOMXPath($dom);

    // Поиск тега <meta itemprop="price">
    $priceElement = $xpath->query('//meta[@itemprop="price"]')->item(0);

    return $priceElement instanceof DOMElement ? $priceElement->getAttribute('content') : "Цена не найдена!";
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

// Список таблиц для обработки
$tables = [
    'processors' => 'processor_id',
    'rammemoryes' => 'rammemory_id',
    'storages' => 'storage_id',
    'videocards' => 'videocard_id',
    'powerunits' => 'powerunit_id',
    'motherboards' => 'motherboard_id',
    'cpucoolings' => 'cpucooling_id',
    'bodyes' => 'body_id'
];

// Функция для анализа цен и обновления статистики
function analyzePrices($row, &$shopStats) {
    $prices = [];
    
    if (!empty($row['hyper_url'])) {
        $prices['hyper'] = getPriceFromURLHyper($row['hyper_url']);
    }
    if (!empty($row['kns_url'])) {
        $prices['kns'] = getPriceKNS($row['kns_url']);
    }
    if (!empty($row['xcom_url'])) {
        $prices['xcom'] = getPriceXcom($row['xcom_url']);
    }
    if (!empty($row['regard_url'])) {
        $prices['regard'] = parsePriceRegardCit($row['regard_url']);
    }
    if (!empty($row['citi_url'])) {
        $prices['citi'] = parsePriceRegardCit($row['citi_url']);
    }
    
    // Фильтруем нулевые цены
    $validPrices = array_filter($prices, function($price) {
        return is_numeric($price) && $price > 0;
    });
    
    if (!empty($validPrices)) {
        $minPrice = min($validPrices);
        $minStore = array_search($minPrice, $validPrices);
        $shopStats[$minStore]++;
    }
}

// Основной цикл по таблицам
foreach ($tables as $table => $idField) {
    echo "Анализ цен для таблицы: $table\n";
    
    $query = "SELECT * FROM $table";
    $result = mysqli_query($con, $query);
    
    if (!$result) {
        echo "Ошибка при получении данных из $table: " . mysqli_error($con) . "\n";
        continue;
    }
    
    while ($row = mysqli_fetch_assoc($result)) {
        analyzePrices($row, $shopStats);
    }
}

// Выводим статистику
echo "\nСтатистика минимальных цен по магазинам:\n";
echo "-------------------------------------\n";
foreach ($shopStats as $shop => $count) {
    echo str_pad(ucfirst($shop), 10) . ": " . str_pad($count, 5) . " | " . str_repeat("#", $count) . "\n";
}

// Закрываем соединение с БД
mysqli_close($con);

// Генерация простого HTML с графиком (можно сохранить в файл)
$htmlChart = '<!DOCTYPE html>
<html>
<head>
    <title>Статистика минимальных цен</title>
    <style>
        .chart { margin: 20px; width: 80%; }
        .bar { 
            height: 30px; 
            margin: 5px 0; 
            background-color: #4CAF50;
            text-align: right;
            padding-right: 10px;
            color: white;
            line-height: 30px;
        }
    </style>
</head>
<body>
    <h2>Распределение минимальных цен по магазинам</h2>
    <div class="chart">';

foreach ($shopStats as $shop => $count) {
    $width = ($count / max($shopStats)) * 100;
    $htmlChart .= '
        <div>
            <strong>' . ucfirst($shop) . ':</strong>
            <div class="bar" style="width: ' . $width . '%">' . $count . '</div>
        </div>';
}

$htmlChart .= '
    </div>
</body>
</html>';

// Сохраняем HTML с графиком в файл
file_put_contents('price_stats.html', $htmlChart);
echo "\nГрафик сохранен в файл price_stats.html\n";
?>

