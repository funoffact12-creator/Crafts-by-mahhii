<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
ini_set('display_errors', '0');

final class ApiException extends RuntimeException
{
    public int $status;

    public function __construct(string $message, int $status = 400)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

function respond(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function require_owner(): void
{
    if (empty($_SESSION['owner'])) {
        throw new ApiException('Please unlock the owner panel again.', 401);
    }
}

function require_csrf(array $input): void
{
    if (!isset($_SESSION['csrf'], $input['csrfToken']) ||
        !is_string($input['csrfToken']) ||
        !hash_equals($_SESSION['csrf'], $input['csrfToken'])) {
        throw new ApiException('The security token expired. Refresh the page and try again.', 403);
    }
}

function seed_from_page(PDO $pdo): void
{
    $pdo->beginTransaction();
    try {
        $settings = $pdo->query('SELECT initialized FROM shop_settings WHERE id = 1 FOR UPDATE')->fetch();
        if (!$settings) {
            throw new ApiException('Run database.sql in phpMyAdmin before opening the shop.', 503);
        }
        if ((int)$settings['initialized'] === 1) {
            $pdo->commit();
            return;
        }

        $pageFiles = scandir(__DIR__);
        if ($pageFiles === false) {
            throw new ApiException('The folder containing api.php could not be read.', 503);
        }
        usort($pageFiles, static function (string $a, string $b): int {
            $preferred = 'Crafts by Mahhiii (5).html';
            return ($a === $preferred ? 0 : 1) <=> ($b === $preferred ? 0 : 1);
        });

        $seedJson = null;
        foreach ($pageFiles as $pageFile) {
            if (strtolower(pathinfo($pageFile, PATHINFO_EXTENSION)) !== 'html') {
                continue;
            }
            $pagePath = __DIR__ . DIRECTORY_SEPARATOR . $pageFile;
            if (!is_file($pagePath)) {
                continue;
            }
            $html = file_get_contents($pagePath);
            if ($html === false) {
                continue;
            }
            $dataMarker = stripos($html, 'id="shop-data"');
            if ($dataMarker === false) {
                $dataMarker = stripos($html, "id='shop-data'");
            }
            if ($dataMarker === false) {
                continue;
            }
            $scriptStart = strripos(substr($html, 0, $dataMarker), '<script');
            $tagEnd = $scriptStart === false ? false : strpos($html, '>', $scriptStart);
            if ($tagEnd === false || $dataMarker > $tagEnd ||
                stripos(substr($html, $scriptStart, $tagEnd - $scriptStart), 'application/json') === false) {
                continue;
            }
            $scriptEnd = stripos($html, '</script', $tagEnd + 1);
            if ($scriptEnd === false) {
                continue;
            }
            $seedJson = substr($html, $tagEnd + 1, $scriptEnd - $tagEnd - 1);
            break;
        }
        if ($seedJson === null) {
            throw new ApiException('Shop data not found: the HTML file containing id="shop-data" must be in the same folder as the api.php being served. Restart the PHP server after copying the updated files.', 503);
        }

        $seed = json_decode($seedJson, true);
        if (!is_array($seed) || !isset($seed['shop']) || !is_array($seed['shop']) ||
            !isset($seed['products']) || !is_array($seed['products'])) {
            throw new ApiException('The product data in the HTML file could not be read.', 503);
        }

        $shop = $seed['shop'];
        $count = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
        if ($count === 0 && $seed['products']) {
            $insert = $pdo->prepare(
                'INSERT INTO products (id, code, name, price, category, hue, image_data, sold_out, is_example)
                 VALUES (:id, :code, :name, :price, :category, :hue, :image_data, :sold_out, :is_example)'
            );
            foreach ($seed['products'] as $product) {
                if (!is_array($product) || !isset($product['id'], $product['code'], $product['name'], $product['price'])) {
                    throw new ApiException('A product in the HTML file is missing required data.', 503);
                }
                $insert->execute([
                    ':id' => (string)$product['id'],
                    ':code' => (int)$product['code'],
                    ':name' => (string)$product['name'],
                    ':price' => (int)$product['price'],
                    ':category' => (string)($product['cat'] ?? ''),
                    ':hue' => (int)($product['hue'] ?? 0),
                    ':image_data' => (string)($product['img'] ?? ''),
                    ':sold_out' => !empty($product['soldOut']) ? 1 : 0,
                    ':is_example' => !empty($product['example']) ? 1 : 0,
                ]);
            }
        }

        $maxCode = (int)$pdo->query('SELECT COALESCE(MAX(code), 0) FROM products')->fetchColumn();
        $nextCode = max($maxCode + 1, (int)($seed['next'] ?? 1));
        $update = $pdo->prepare(
            'UPDATE shop_settings SET shop_name = :shop_name, tagline = :tagline, shop_strip = :shop_strip,
             tag_note = :tag_note, whatsapp = :whatsapp, delivery = :delivery, palette = :palette,
             code_hash = :code_hash, next_code = :next_code, initialized = 1 WHERE id = 1'
        );
        $update->execute([
            ':shop_name' => (string)($shop['name'] ?? 'My Shop'),
            ':tagline' => (string)($shop['tagline'] ?? ''),
            ':shop_strip' => (string)($shop['strip'] ?? ''),
            ':tag_note' => (string)($shop['tagNote'] ?? ''),
            ':whatsapp' => (string)($shop['whatsapp'] ?? ''),
            ':delivery' => (string)($shop['delivery'] ?? ''),
            ':palette' => (string)($shop['palette'] ?? 'lavender'),
            ':code_hash' => (string)($shop['codeHash'] ?? ''),
            ':next_code' => $nextCode,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function load_store(PDO $pdo): array
{
    seed_from_page($pdo);
    $settings = $pdo->query(
        'SELECT shop_name, tagline, shop_strip, tag_note, whatsapp, delivery, palette, next_code
         FROM shop_settings WHERE id = 1'
    )->fetch();
    $products = $pdo->query(
        'SELECT id, code, name, price, category, hue, image_data, sold_out, is_example
         FROM products ORDER BY code'
    )->fetchAll();

    $items = [];
    foreach ($products as $product) {
        $items[] = [
            'id' => $product['id'],
            'code' => (int)$product['code'],
            'name' => $product['name'],
            'price' => (int)$product['price'],
            'cat' => $product['category'],
            'hue' => (int)$product['hue'],
            'img' => $product['image_data'],
            'soldOut' => (bool)$product['sold_out'],
            'example' => (bool)$product['is_example'],
        ];
    }

    return [
        'shop' => [
            'name' => $settings['shop_name'],
            'tagline' => $settings['tagline'],
            'strip' => $settings['shop_strip'],
            'tagNote' => $settings['tag_note'],
            'whatsapp' => $settings['whatsapp'],
            'delivery' => $settings['delivery'],
            'palette' => $settings['palette'],
        ],
        'next' => (int)$settings['next_code'],
        'products' => $items,
    ];
}

function product_input(mixed $value): array
{
    if (!is_array($value)) {
        throw new ApiException('Product details are missing.');
    }
    $nameValue = $value['name'] ?? null;
    $categoryValue = $value['cat'] ?? '';
    $price = $value['price'] ?? null;
    $image = $value['img'] ?? '';
    $id = $value['id'] ?? '';
    $hue = $value['hue'] ?? null;
    if (!is_string($nameValue) || !is_string($categoryValue) || !is_string($id) ||
        ($hue !== null && !is_int($hue))) {
        throw new ApiException('Product details have an invalid format.');
    }
    $name = trim($nameValue);
    $category = trim($categoryValue);

    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
        throw new ApiException('Product name must be between 2 and 120 characters.');
    }
    if ($id !== '' && strlen($id) > 64) {
        throw new ApiException('The product ID is invalid.');
    }
    if (!is_int($price) || $price < 0 || $price > 2147483647) {
        throw new ApiException('Enter a valid product price.');
    }
    if (mb_strlen($category) > 80) {
        throw new ApiException('Product type cannot be longer than 80 characters.');
    }
    if (!is_string($image) || strlen($image) > 6000000 ||
        ($image !== '' && !preg_match('~^data:image/(?:jpeg|png);base64,[A-Za-z0-9+/]+={0,2}$~', $image))) {
        throw new ApiException('The product photo is invalid or too large.');
    }

    return [
        'id' => $id,
        'name' => $name,
        'price' => $price,
        'category' => $category,
        'hue' => $hue !== null ? max(0, min(359, $hue)) : null,
        'image' => $image,
        'soldOut' => !empty($value['soldOut']),
    ];
}

function public_product(array $row): array
{
    return [
        'id' => $row['id'],
        'code' => (int)$row['code'],
        'name' => $row['name'],
        'price' => (int)$row['price'],
        'cat' => $row['category'],
        'hue' => (int)$row['hue'],
        'img' => $row['image_data'],
        'soldOut' => (bool)$row['sold_out'],
        'example' => (bool)$row['is_example'],
    ];
}

try {
    $configPath = __DIR__ . DIRECTORY_SEPARATOR . 'db_config.php';
    if (!is_file($configPath)) {
        throw new ApiException('Create db_config.php beside api.php using the provided XAMPP settings.', 503);
    }
    $config = require $configPath;
    if (!is_array($config)) {
        throw new ApiException('db_config.php must return the database connection settings.', 503);
    }

    $dsn = 'mysql:host=' . $config['host'] . ';port=' . (int)$config['port'] .
        ';dbname=' . $config['database'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, (string)$config['username'], (string)$config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    session_name('crafts_shop_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = (string)($_GET['action'] ?? '');

    if ($method === 'GET' && $action === 'store') {
        $store = load_store($pdo);
        respond([
            'ok' => true,
            'shop' => $store['shop'],
            'next' => $store['next'],
            'products' => $store['products'],
            'owner' => !empty($_SESSION['owner']),
            'csrfToken' => $_SESSION['csrf'],
        ]);
    }

    if ($method !== 'POST') {
        throw new ApiException('Unsupported request method.', 405);
    }
    $raw = file_get_contents('php://input');
    $input = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($input)) {
        throw new ApiException('The request body must be valid JSON.');
    }
    require_csrf($input);

    if ($action === 'login') {
        $code = $input['code'] ?? null;
        if (!is_string($code) || $code === '') {
            throw new ApiException('Enter the owner secret code.', 401);
        }
        $expected = $pdo->query('SELECT code_hash FROM shop_settings WHERE id = 1')->fetchColumn();
        $actual = hash('sha256', 'kc|' . trim($code));
        if (!is_string($expected) || $expected === '' || !hash_equals($expected, $actual)) {
            throw new ApiException('That secret code is not correct.', 401);
        }
        session_regenerate_id(true);
        $_SESSION['owner'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        respond(['ok' => true, 'csrfToken' => $_SESSION['csrf']]);
    }

    if ($action === 'logout') {
        unset($_SESSION['owner']);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        respond(['ok' => true, 'csrfToken' => $_SESSION['csrf']]);
    }

    require_owner();

    if ($action === 'save-product') {
        $product = product_input($input['product'] ?? null);
        $pdo->beginTransaction();
        try {
            if ($product['id'] !== '') {
                $existing = $pdo->prepare('SELECT code, hue FROM products WHERE id = :id FOR UPDATE');
                $existing->execute([':id' => $product['id']]);
                $row = $existing->fetch();
                if (!$row) {
                    throw new ApiException('That product no longer exists. Refresh and try again.', 404);
                }
                $statement = $pdo->prepare(
                    'UPDATE products SET name = :name, price = :price, category = :category, hue = :hue,
                     image_data = :image_data, sold_out = :sold_out, is_example = 0 WHERE id = :id'
                );
                $statement->execute([
                    ':name' => $product['name'], ':price' => $product['price'],
                    ':category' => $product['category'], ':hue' => $product['hue'] ?? (int)$row['hue'],
                    ':image_data' => $product['image'], ':sold_out' => $product['soldOut'] ? 1 : 0,
                    ':id' => $product['id'],
                ]);
                $code = (int)$row['code'];
                $id = $product['id'];
            } else {
                $nextCode = $pdo->query('SELECT next_code FROM shop_settings WHERE id = 1 FOR UPDATE')->fetchColumn();
                if ($nextCode === false) {
                    throw new ApiException('Shop settings are missing. Run database.sql again.', 503);
                }
                $code = (int)$nextCode;
                $id = 'p' . bin2hex(random_bytes(8));
                $hue = $product['hue'] ?? (($code * 47) % 360);
                $statement = $pdo->prepare(
                    'INSERT INTO products (id, code, name, price, category, hue, image_data, sold_out, is_example)
                     VALUES (:id, :code, :name, :price, :category, :hue, :image_data, :sold_out, 0)'
                );
                $statement->execute([
                    ':id' => $id, ':code' => $code, ':name' => $product['name'],
                    ':price' => $product['price'], ':category' => $product['category'],
                    ':hue' => $hue, ':image_data' => $product['image'],
                    ':sold_out' => $product['soldOut'] ? 1 : 0,
                ]);
                $pdo->prepare('UPDATE shop_settings SET next_code = :next_code WHERE id = 1')
                    ->execute([':next_code' => $code + 1]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $saved = $pdo->prepare('SELECT id, code, name, price, category, hue, image_data, sold_out, is_example FROM products WHERE id = :id');
        $saved->execute([':id' => $id]);
        $next = (int)$pdo->query('SELECT next_code FROM shop_settings WHERE id = 1')->fetchColumn();
        respond(['ok' => true, 'product' => public_product($saved->fetch()), 'next' => $next]);
    }

    if ($action === 'delete-product') {
        $id = $input['id'] ?? null;
        if (!is_string($id) || $id === '' || strlen($id) > 64) {
            throw new ApiException('A valid product ID is required.');
        }
        $statement = $pdo->prepare('DELETE FROM products WHERE id = :id');
        $statement->execute([':id' => $id]);
        if ($statement->rowCount() !== 1) {
            throw new ApiException('That product no longer exists. Refresh and try again.', 404);
        }
        respond(['ok' => true]);
    }

    if ($action === 'delete-products') {
        $ids = $input['ids'] ?? null;
        if (!is_array($ids) || count($ids) > 100) {
            throw new ApiException('The product selection is invalid.');
        }
        foreach ($ids as $id) {
            if (!is_string($id) || $id === '' || strlen($id) > 64) {
                throw new ApiException('The product selection contains an invalid ID.');
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids) {
            $statement = $pdo->prepare('DELETE FROM products WHERE id = ?');
            $pdo->beginTransaction();
            try {
                foreach ($ids as $id) {
                    $statement->execute([$id]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }
        respond(['ok' => true]);
    }

    if ($action === 'save-settings') {
        $shop = $input['shop'] ?? null;
        if (!is_array($shop)) {
            throw new ApiException('Shop details are missing.');
        }
        $fields = [
            'name' => ['shop_name', 120],
            'tagline' => ['tagline', 180],
            'strip' => ['shop_strip', 255],
            'tagNote' => ['tag_note', 255],
            'whatsapp' => ['whatsapp', 32],
            'delivery' => ['delivery', 255],
        ];
        $values = [];
        foreach ($fields as $key => [$column, $maxLength]) {
            $rawValue = $shop[$key] ?? '';
            if (!is_string($rawValue)) {
                throw new ApiException('Shop detail "' . $key . '" has an invalid format.');
            }
            $value = trim($rawValue);
            if (mb_strlen($value) > $maxLength) {
                throw new ApiException('Shop detail "' . $key . '" is too long.');
            }
            $values[$column] = $value;
        }
        $palette = $shop['palette'] ?? 'lavender';
        if (!is_string($palette)) {
            throw new ApiException('Choose a valid colour theme.');
        }
        if (!in_array($palette, ['lavender', 'strawberry', 'mint', 'sky', 'peach', 'honey'], true)) {
            throw new ApiException('Choose a valid colour theme.');
        }
        $values['palette'] = $palette;
        $statement = $pdo->prepare(
            'UPDATE shop_settings SET shop_name = :shop_name, tagline = :tagline, shop_strip = :shop_strip,
             tag_note = :tag_note, whatsapp = :whatsapp, delivery = :delivery, palette = :palette WHERE id = 1'
        );
        $statement->execute($values);
        $store = load_store($pdo);
        respond(['ok' => true, 'shop' => $store['shop']]);
    }

    if ($action === 'change-code') {
        $codeHash = $input['codeHash'] ?? null;
        if (!is_string($codeHash) || !preg_match('/^[a-f0-9]{64}$/', $codeHash)) {
            throw new ApiException('The new secret code is invalid.');
        }
        $pdo->prepare('UPDATE shop_settings SET code_hash = :code_hash WHERE id = 1')
            ->execute([':code_hash' => $codeHash]);
        respond(['ok' => true]);
    }

    throw new ApiException('Unknown database action.', 404);
} catch (ApiException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log('Crafts shop API error: ' . $e->getMessage());
    respond(['ok' => false, 'error' => 'The database request failed. Check that MySQL is running and database.sql was imported.'], 500);
}
