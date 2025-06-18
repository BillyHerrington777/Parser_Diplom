<?php
require_once "config\constants.php";
require_once "db.php";
ini_set('max_execution_time', 7200);
set_time_limit(7200);

function getPriceFromURLHyper($url) {
    // Проверяем, является ли URL пустым
    if (empty($url)) {
        return 0; // Возвращаем цену 0, если URL пустой
    }

    // Инициализация cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Отключить проверку SSL, если необходимо
    $html = curl_exec($ch);
    curl_close($ch);

    // Поиск JSON-кода внутри тега <script>
    preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches);

    // Проверяем, найден ли JSON
    if (isset($matches[1])) {
        $jsonData = $matches[1];
        // Декодируем JSON в PHP-массив
        $data = json_decode($jsonData, true);

        // Извлекаем цену
        if (isset($data['offers'][0]['price'])) {
            return $data['offers'][0]['price']; // Возвращаем цену
        } else {
            return "Цена не найдена.";
        }
    } else {
        return "JSON-код не найден на странице.";
    }
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
    // Проверяем, если URL пустой, возвращаем 0
    if (empty($url)) {
        return 0;
    }

    // Инициализация cURL
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
    ]);

    // Выполнение запроса и получение HTML-кода страницы
    $html = curl_exec($ch);
    curl_close($ch);

    // Проверяем успешность загрузки
    if (!$html) {
        return "Не удалось загрузить страницу!";
    }

    // Загрузка HTML-кода в DOMDocument
    $dom = new DOMDocument();
    @$dom->loadHTML($html);

    // Поиск JSON-данных в HTML-коде
    $scripts = $dom->getElementsByTagName('script');

    foreach ($scripts as $script) {
        if ($script->getAttribute('type') === 'application/ld+json') {
            $jsonData = json_decode($script->nodeValue, true);
            if (isset($jsonData['offers']) && isset($jsonData['offers']['price'])) {
                return htmlspecialchars($jsonData['offers']['price']);
            }
        }
    }

    return 0;
}

require_once "db.php";

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

// Функция для получения минимальной цены и URL
function getMinPriceAndUrl($row) {
    $prices = [];
    
    // Проверяем каждый URL и получаем цену
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
    $prices = array_filter($prices, function($price) {
        return is_numeric($price) && $price > 0;
    });

    if (empty($prices)) {
        return ['price' => 0, 'url' => ''];
    }

    // Находим минимальную цену
    $minPrice = min($prices);
    $minStore = array_search($minPrice, $prices);
    $minUrl = $row[$minStore . '_url'];

    return ['price' => $minPrice, 'url' => $minUrl];
}

// Основной цикл по таблицам
foreach ($tables as $table => $idField) {
    echo "Обновление цен для таблицы: $table\n";

    // Получаем все товары из таблицы
    $query = "SELECT * FROM $table";
    $result = mysqli_query($con, $query);

    if (!$result) {
        echo "Ошибка при получении данных из $table: " . mysqli_error($con) . "\n";
        continue;
    }

    while ($row = mysqli_fetch_assoc($result)) {
        $id = $row[$idField];
        $priceInfo = getMinPriceAndUrl($row);

        // Экранируем строковые значения
        $escapedUrl = mysqli_real_escape_string($con, $priceInfo['url']);
        
        // Обновляем запись в БД
        $updateQuery = "UPDATE $table SET 
                        product_price = {$priceInfo['price']}, 
                        min_url_shop = '$escapedUrl'
                        WHERE $idField = $id";
        
        if (!mysqli_query($con, $updateQuery)) {
            echo "Ошибка при обновлении товара ID $id: " . mysqli_error($con) . "\n";
        } else {
            echo "Обновлен товар ID $id: цена = {$priceInfo['price']}, URL = {$priceInfo['url']}\n";
        }
    }
}

echo "Все цены обновлены!\n";
mysqli_close($con);
?>