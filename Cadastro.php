<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function readJsonBody(): array
{
    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || strlen($rawBody) > 16384) {
        respond(400, ['error' => 'A solicitação está vazia ou excede o tamanho permitido.']);
    }

    try {
        $body = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        respond(400, ['error' => 'Os dados enviados não são um JSON válido.']);
    }

    if (!is_array($body)) {
        respond(400, ['error' => 'O formato dos dados enviados é inválido.']);
    }

    return $body;
}

function requiredText(array $data, string $field, int $maxLength, string $label): string
{
    $value = $data[$field] ?? null;
    if (!is_string($value)) {
        respond(422, ['error' => "Informe $label."]);
    }

    $value = trim($value);
    if ($value === '' || preg_match('/^.{1,' . $maxLength . '}$/us', $value) !== 1) {
        respond(422, ['error' => "$label deve ter entre 1 e $maxLength caracteres."]);
    }

    return $value;
}

function formatCpf(string $cpf): string
{
    return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cpf);
}

try {
    $configuredPath = getenv('PONTO_PAUTA_DB_PATH');
    $databasePath = $configuredPath !== false && $configuredPath !== ''
        ? $configuredPath
        : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Ponto-Pauta-data' . DIRECTORY_SEPARATOR . 'ponto-pauta.sqlite';
    $databaseDirectory = dirname($databasePath);

    if (!is_dir($databaseDirectory) && !mkdir($databaseDirectory, 0770, true) && !is_dir($databaseDirectory)) {
        throw new RuntimeException('Não foi possível criar o diretório do banco de dados.');
    }

    $database = new PDO('sqlite:' . $databasePath);
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $database->exec('PRAGMA foreign_keys = ON');
    $database->exec(
        'CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            price_cents INTEGER NOT NULL CHECK (price_cents > 0),
            description TEXT NOT NULL,
            illustration TEXT NOT NULL
        )'
    );
    $database->exec(
        'CREATE TABLE IF NOT EXISTS employees (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            cpf TEXT NOT NULL UNIQUE,
            email TEXT NOT NULL
        )'
    );
    $database->exec('CREATE TABLE IF NOT EXISTS app_settings (setting TEXT PRIMARY KEY, value TEXT NOT NULL)');

    $database->beginTransaction();
    $seedMarker = $database->prepare(
        "INSERT OR IGNORE INTO app_settings (setting, value) VALUES ('papelaria_seeded', '1')"
    );
    $seedMarker->execute();
    if ($seedMarker->rowCount() > 0) {
        $starterProducts = [
            ['Caderno Aurora', 4290, 'Capa dura, papel pólen e 160 páginas para ideias que merecem ficar.', 'notebook'],
            ['Caneta-tinteiro Pétala', 5890, 'Ponta fina e escrita macia para cartas, estudos e projetos.', 'pen'],
            ['Planner Semanal', 3690, 'Uma semana por página, com espaço para prioridades e notas.', 'planner'],
            ['Kit de marca-textos', 2790, 'Quatro tons suaves para destacar sem esconder o texto.', 'markers'],
            ['Bloco de notas Botânica', 1890, 'Bloco destacável com 80 folhas para listas e lembretes.', 'notepad'],
            ['Estojo Linho', 3290, 'Estojo compacto em tecido resistente para levar na bolsa.', 'case'],
            ['Fita decorativa Jardim', 1490, 'Washi tape estampada para personalizar páginas e presentes.', 'tape'],
            ['Lápis grafite 6B', 990, 'Traço macio para esboços, lettering e desenho.', 'pencil'],
            ['Cartões para colorir', 2490, 'Doze cartões ilustrados em papel encorpado para pintar e enviar.', 'cards'],
            ['Kit de desenho essencial', 8490, 'Lápis, borracha e bloco de papel para começar a criar.', 'artkit']
        ];
        $insertProduct = $database->prepare(
            'INSERT INTO products (name, price_cents, description, illustration) VALUES (?, ?, ?, ?)'
        );
        foreach ($starterProducts as $product) {
            $insertProduct->execute($product);
        }
    }
    $database->commit();

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        $products = $database->query(
            'SELECT id, name, price_cents, description, illustration FROM products ORDER BY id'
        )->fetchAll();
        foreach ($products as &$product) {
            $product['id'] = (int) $product['id'];
            $product['price'] = (int) $product['price_cents'] / 100;
            unset($product['price_cents']);
        }
        unset($product);

        $employees = $database->query('SELECT id, name, cpf, email FROM employees ORDER BY id')->fetchAll();
        foreach ($employees as &$employee) {
            $employee['id'] = (int) $employee['id'];
            $employee['cpf'] = formatCpf($employee['cpf']);
        }
        unset($employee);

        respond(200, ['products' => $products, 'employees' => $employees]);
    }

    if ($method === 'POST') {
        $body = readJsonBody();
        $type = $body['type'] ?? '';

        if ($type === 'product') {
            $name = requiredText($body, 'name', 80, 'o nome do produto');
            $description = requiredText($body, 'description', 240, 'a descrição do produto');
            $price = $body['price'] ?? null;
            if (!is_numeric($price) || !is_finite((float) $price) || (float) $price <= 0 || (float) $price > 9999999.99) {
                respond(422, ['error' => 'Informe um preço maior que zero e menor que R$ 10.000.000,00.']);
            }

            $priceCents = (int) round((float) $price * 100);
            $insert = $database->prepare(
                'INSERT INTO products (name, price_cents, description, illustration) VALUES (?, ?, ?, ?)'
            );
            $insert->execute([$name, $priceCents, $description, 'package']);
            respond(201, ['id' => (int) $database->lastInsertId()]);
        }

        if ($type === 'employee') {
            $name = requiredText($body, 'name', 100, 'o nome do funcionário');
            $email = requiredText($body, 'email', 120, 'o e-mail');
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                respond(422, ['error' => 'Informe um e-mail válido.']);
            }
            $cpfValue = $body['cpf'] ?? null;
            if (!is_string($cpfValue)) {
                respond(422, ['error' => 'Informe um CPF com 11 números.']);
            }
            $cpf = preg_replace('/\D/', '', $cpfValue);
            if (strlen($cpf) !== 11) {
                respond(422, ['error' => 'Informe um CPF com 11 números.']);
            }

            $insert = $database->prepare('INSERT INTO employees (name, cpf, email) VALUES (?, ?, ?)');
            $insert->execute([$name, $cpf, $email]);
            respond(201, ['id' => (int) $database->lastInsertId()]);
        }

        respond(400, ['error' => 'Tipo de cadastro inválido.']);
    }

    if ($method === 'DELETE') {
        $type = $_GET['type'] ?? '';
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        if (!in_array($type, ['product', 'employee'], true) || $id === false || $id < 1) {
            respond(400, ['error' => 'O cadastro solicitado para remoção é inválido.']);
        }

        $table = $type === 'product' ? 'products' : 'employees';
        $delete = $database->prepare("DELETE FROM $table WHERE id = ?");
        $delete->execute([$id]);
        if ($delete->rowCount() === 0) {
            respond(404, ['error' => 'O cadastro não foi encontrado.']);
        }

        respond(200, ['deleted' => true]);
    }

    header('Allow: GET, POST, DELETE');
    respond(405, ['error' => 'Método não permitido.']);
} catch (PDOException $error) {
    error_log('Falha no banco da loja: ' . $error->getMessage());
    if ($error->getCode() === '23000') {
        respond(409, ['error' => 'Este CPF já está cadastrado.']);
    }
    respond(500, ['error' => 'Não foi possível acessar o banco de dados. Verifique a configuração do PHP e do SQLite.']);
} catch (Throwable $error) {
    error_log('Falha na API da loja: ' . $error->getMessage());
    respond(500, ['error' => 'Ocorreu um erro interno ao processar a solicitação.']);
}