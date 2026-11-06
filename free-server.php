<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

final class PanelApiException extends RuntimeException
{
    public int $status;

    public function __construct(int $status, string $message)
    {
        $this->status = $status;
        parent::__construct($message);
    }
}

function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

function requiredEnv(string $name): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        throw new RuntimeException("Missing required server setting: {$name}");
    }
    return trim($value);
}

function readRequest(): array
{
    $raw = file_get_contents('php://input', false, null, 0, 8192);
    $data = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($data)) {
        respond(400, ['error' => 'Invalid request.']);
    }
    return $data;
}

function pterodactylRequest(string $method, string $path, ?array $payload = null): array
{
    $base = rtrim(requiredEnv('PTERODACTYL_URL'), '/');
    if (parse_url($base, PHP_URL_SCHEME) !== 'https') {
        throw new RuntimeException('PTERODACTYL_URL must use HTTPS.');
    }
    $token = requiredEnv('PTERODACTYL_APPLICATION_TOKEN');
    $url = $base . '/api/application/' . ltrim($path, '/');
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Unable to initialize the Pterodactyl API request.');
    }

    $headers = [
        'Accept: Application/vnd.pterodactyl.v1+json',
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ];
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($payload !== null) {
        $json = json_encode($payload);
        if ($json === false) {
            curl_close($curl);
            throw new RuntimeException('Unable to encode the Pterodactyl API request.');
        }
        curl_setopt($curl, CURLOPT_POSTFIELDS, $json);
    }

    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    if ($body === false) {
        throw new RuntimeException('Pterodactyl API connection failed: ' . $curlError);
    }

    $decoded = json_decode($body, true);
    if ($status < 200 || $status >= 300 || !is_array($decoded)) {
        throw new PanelApiException($status, 'Pterodactyl API returned HTTP ' . $status . '.');
    }
    return $decoded;
}

function pterodactylList(string $path): array
{
    $items = [];
    $page = 1;
    do {
        $separator = strpos($path, '?') !== false ? '&' : '?';
        $response = pterodactylRequest('GET', $path . $separator . http_build_query([
            'per_page' => 100,
            'page' => $page,
        ]));
        $items = array_merge($items, $response['data'] ?? []);
        $pages = (int) ($response['meta']['pagination']['total_pages'] ?? $page);
        $page++;
    } while ($page <= $pages && $page <= 100);

    return $items;
}

function exactPanelUser(string $email): array
{
    $path = 'users?' . http_build_query(['filter[email]' => $email]);
    $users = pterodactylList($path);
    foreach ($users as $user) {
        $attributes = $user['attributes'] ?? [];
        if (strcasecmp((string) ($attributes['email'] ?? ''), $email) === 0) {
            return $attributes;
        }
    }
    respond(404, ['error' => 'No Pterodactyl account matches this email. Create a panel account first, then try again.']);
}

function findEgg(): array
{
    $nestId = (int) (getenv('PTERODACTYL_MINECRAFT_NEST_ID') ?: '1');
    $eggId = (int) (getenv('PTERODACTYL_MINECRAFT_EGG_ID') ?: '3');
    $response = pterodactylRequest(
        'GET',
        "nests/{$nestId}/eggs/{$eggId}?include=variables"
    );
    return $response['attributes'] ?? [];
}

function availableAllocations(array $nodeIds): array
{
    $available = [];
    foreach ($nodeIds as $nodeId) {
        $allocations = pterodactylList("nodes/{$nodeId}/allocations");
        foreach ($allocations as $allocation) {
            $attributes = $allocation['attributes'] ?? [];
            if (($attributes['assigned'] ?? true) === false
                && ($attributes['is_locked'] ?? false) === false) {
                $available[] = [
                    'node_id' => $nodeId,
                    'id' => (int) ($attributes['id'] ?? 0),
                ];
            }
        }
    }
    return array_values(array_filter($available, static fn(array $item): bool => $item['id'] > 0));
}

function createPanelServer(PDO $db, array $panelUser, string $email): int
{
    $userId = (int) ($panelUser['id'] ?? 0);
    if ($userId < 1) {
        throw new RuntimeException('Pterodactyl returned an invalid user record.');
    }

    $db->beginTransaction();
    $findOrder = $db->prepare('SELECT status, panel_server_id, updated_at FROM free_server_orders WHERE panel_user_id = ? FOR UPDATE');
    $findOrder->execute([$userId]);
    $order = $findOrder->fetch(PDO::FETCH_ASSOC);
    if ($order && $order['status'] === 'active' && $order['panel_server_id'] !== null) {
        $db->commit();
        return (int) $order['panel_server_id'];
    }
    if ($order && $order['status'] === 'provisioning') {
        $updatedAt = strtotime($order['updated_at'] . ' UTC');
        if ($updatedAt !== false && $updatedAt > time() - 300) {
            $db->commit();
            respond(409, ['error' => 'A free server request for this account is already being processed.']);
        }
        $recoverOrder = $db->prepare('UPDATE free_server_orders SET updated_at = UTC_TIMESTAMP() WHERE panel_user_id = ?');
        $recoverOrder->execute([$userId]);
    }
    if ($order && $order['status'] === 'failed') {
        $updatedAt = strtotime($order['updated_at'] . ' UTC');
        if ($updatedAt !== false && $updatedAt > time() - 60) {
            $db->commit();
            respond(429, ['error' => 'Please wait a minute before retrying this server request.']);
        }
    }
    if ($order) {
        $updateOrder = $db->prepare("UPDATE free_server_orders SET status = 'provisioning', email = ?, updated_at = UTC_TIMESTAMP() WHERE panel_user_id = ?");
        $updateOrder->execute([$email, $userId]);
    } else {
        $insertOrder = $db->prepare("INSERT INTO free_server_orders (panel_user_id, email, status, created_at, updated_at) VALUES (?, ?, 'provisioning', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $insertOrder->execute([$userId, $email]);
    }
    $db->commit();

    $externalId = 'mtcore-free-' . $userId;
    $existing = pterodactylList('servers?' . http_build_query(['filter[external_id]' => $externalId]));
    foreach ($existing as $server) {
        $attributes = $server['attributes'] ?? [];
        if (($attributes['external_id'] ?? null) === $externalId) {
            $serverId = (int) ($attributes['id'] ?? 0);
            if ($serverId > 0) {
                $saveOrder = $db->prepare("UPDATE free_server_orders SET panel_server_id = ?, status = 'active', updated_at = UTC_TIMESTAMP() WHERE panel_user_id = ?");
                $saveOrder->execute([$serverId, $userId]);
                return $serverId;
            }
        }
    }

    $egg = findEgg();
    $dockerImage = (string) ($egg['docker_image'] ?? '');
    if ($dockerImage === '' && isset($egg['docker_images']) && is_array($egg['docker_images'])) {
        $dockerImage = (string) (reset($egg['docker_images']) ?: '');
    }
    $startup = (string) ($egg['startup'] ?? '');
    if ($dockerImage === '' || $startup === '') {
        throw new RuntimeException('Minecraft egg is missing its Docker image or startup command.');
    }

    $environment = [];
    foreach (($egg['relationships']['variables']['data'] ?? []) as $variable) {
        $attributes = $variable['attributes'] ?? [];
        $name = (string) ($attributes['env_variable'] ?? '');
        if ($name === '') {
            continue;
        }
        $default = $attributes['default_value'] ?? '';
        $rules = (string) ($attributes['rules'] ?? '');
        if ($default === '' && strpos($rules, 'required') !== false) {
            throw new RuntimeException('Minecraft egg has a required environment variable without a default.');
        }
        $environment[$name] = (string) $default;
    }

    $nodeIds = array_values(array_unique(array_filter(
        array_map('intval', explode(',', requiredEnv('PTERODACTYL_NODE_IDS'))),
        static fn(int $id): bool => $id > 0
    )));
    if ($nodeIds === []) {
        throw new RuntimeException('No Pterodactyl node IDs are configured.');
    }
    $allocations = availableAllocations($nodeIds);
    if ($allocations === []) {
        throw new RuntimeException('No free allocation is available on the configured nodes.');
    }

    $lastError = null;
    foreach ($allocations as $allocation) {
        $payload = [
            'name' => 'MTCore Free - ' . $userId,
            'user' => $userId,
            'external_id' => $externalId,
            'egg' => (int) (getenv('PTERODACTYL_MINECRAFT_EGG_ID') ?: '3'),
            'docker_image' => $dockerImage,
            'startup' => $startup,
            'environment' => $environment,
            'limits' => [
                'memory' => 2048,
                'swap' => 0,
                'disk' => 4096,
                'io' => 500,
                'cpu' => 200,
                'threads' => null,
                'oom_disabled' => false,
            ],
            'feature_limits' => [
                'databases' => 0,
                'allocations' => 1,
                'backups' => 0,
            ],
            'allocation' => ['default' => $allocation['id']],
        ];
        try {
            $response = pterodactylRequest('POST', 'servers', $payload);
            $serverId = (int) ($response['attributes']['id'] ?? 0);
            if ($serverId < 1) {
                throw new RuntimeException('Pterodactyl did not return a server ID.');
            }
            $saveOrder = $db->prepare("UPDATE free_server_orders SET panel_server_id = ?, status = 'active', updated_at = UTC_TIMESTAMP() WHERE panel_user_id = ?");
            $saveOrder->execute([$serverId, $userId]);
            return $serverId;
        } catch (PanelApiException $error) {
            $lastError = $error;
            if (!in_array($error->status, [400, 409, 422], true)) {
                throw $error;
            }
        }
    }

    throw $lastError ?? new RuntimeException('Pterodactyl could not create the server.');
}

function sendVerificationCode(string $email, string $name, string $code): void
{
    if (!function_exists('mail')) {
        throw new RuntimeException('PHP mail delivery is not available.');
    }
    $from = requiredEnv('MTCORE_MAIL_FROM');
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('MTCORE_MAIL_FROM is not a valid email address.');
    }
    $subject = 'Verify your MTCore free server request';
    $message = "Hello {$name},\n\nYour MTCore verification code is: {$code}\n\nThe code expires in 10 minutes. If you did not request a server, ignore this email.\n";
    $headers = [
        'From' => 'MTCore <' . $from . '>',
        'Content-Type' => 'text/plain; charset=UTF-8',
        'X-Mailer' => 'MTCore',
    ];
    $headerLines = [];
    foreach ($headers as $key => $value) {
        $headerLines[] = $key . ': ' . $value;
    }
    if (!mail($email, $subject, $message, implode("\r\n", $headerLines))) {
        throw new RuntimeException('PHP mail delivery failed.');
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        respond(405, ['error' => 'POST is required.']);
    }

    $allowedOrigins = array_filter(array_map('trim', explode(',', requiredEnv('MTCORE_ALLOWED_ORIGINS'))));
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '' || !in_array($origin, $allowedOrigins, true)) {
        respond(403, ['error' => 'This request origin is not allowed.']);
    }

    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (!preg_match('/^application\/json(?:\s*;|$)/', $contentType)) {
        respond(415, ['error' => 'JSON requests are required.']);
    }

    $appSecret = requiredEnv('MTCORE_APP_SECRET');
    if (strlen($appSecret) < 32) {
        throw new RuntimeException('MTCORE_APP_SECRET must contain at least 32 characters.');
    }

    $db = new PDO(requiredEnv('MTCORE_DB_DSN'), requiredEnv('MTCORE_DB_USER'), requiredEnv('MTCORE_DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $request = readRequest();
    $action = (string) ($request['action'] ?? '');
    $email = strtolower(trim((string) ($request['email'] ?? '')));
    $name = trim((string) ($request['name'] ?? ''));
    $game = trim((string) ($request['game'] ?? ''));
    $clientIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $ipHash = hash_hmac('sha256', $clientIp, $appSecret);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 || $name === '' || strlen($name) > 100
        || preg_match('/[\r\n\x00-\x1F\x7F]/', $name)) {
        respond(422, ['error' => 'Enter a valid email address and name.']);
    }
    if (!function_exists('curl_init') || !extension_loaded('pdo_mysql')) {
        error_log('MTCore free server endpoint requires PHP cURL and PDO MySQL.');
        respond(503, ['error' => 'The server request service is temporarily unavailable. Please contact support.']);
    }
    if ($game !== 'Minecraft') {
        respond(422, ['error' => 'Automatic free server creation is currently available for Minecraft only.']);
    }

    if ($action === 'send_code') {
        $db->exec('DELETE FROM free_server_challenges WHERE expires_at < (UTC_TIMESTAMP() - INTERVAL 1 DAY)');
        $emailRateLimit = $db->prepare("SELECT COUNT(*) FROM free_server_challenges WHERE email = ? AND created_at >= (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)");
        $emailRateLimit->execute([$email]);
        $ipRateLimit = $db->prepare("SELECT COUNT(*) FROM free_server_challenges WHERE ip_hash = ? AND created_at >= (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)");
        $ipRateLimit->execute([$ipHash]);
        if ((int) $emailRateLimit->fetchColumn() >= 3 || (int) $ipRateLimit->fetchColumn() >= 30) {
            respond(429, ['error' => 'Too many verification attempts. Please wait 15 minutes and try again.']);
        }

        $challengeId = bin2hex(random_bytes(32));
        $code = (string) random_int(100000, 999999);
        $codeHash = hash_hmac('sha256', $challengeId . ':' . $code, $appSecret);
        $saveChallenge = $db->prepare("INSERT INTO free_server_challenges (id, email, display_name, code_hash, ip_hash, created_at, expires_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), (UTC_TIMESTAMP() + INTERVAL 10 MINUTE))");
        $saveChallenge->execute([$challengeId, $email, $name, $codeHash, $ipHash]);
        try {
            sendVerificationCode($email, $name, $code);
        } catch (RuntimeException $error) {
            $deleteChallenge = $db->prepare('DELETE FROM free_server_challenges WHERE id = ?');
            $deleteChallenge->execute([$challengeId]);
            throw $error;
        }
        respond(200, ['challenge_id' => $challengeId]);
    }

    if ($action !== 'create') {
        respond(400, ['error' => 'Unknown action.']);
    }

    $challengeId = (string) ($request['challenge_id'] ?? '');
    $code = (string) ($request['code'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $challengeId) || !preg_match('/^[0-9]{6}$/', $code)) {
        respond(422, ['error' => 'Enter the verification code sent to your email.']);
    }

    $db->beginTransaction();
    $findChallenge = $db->prepare('SELECT email, code_hash, attempts, verified_at, used_at, expires_at FROM free_server_challenges WHERE id = ? FOR UPDATE');
    $findChallenge->execute([$challengeId]);
    $challenge = $findChallenge->fetch();
    if (!$challenge || $challenge['email'] !== $email || $challenge['used_at'] !== null || strtotime($challenge['expires_at'] . ' UTC') < time()) {
        $db->rollBack();
        respond(400, ['error' => 'This verification code has expired. Request a new code.', 'error_code' => 'expired']);
    }
    if ((int) $challenge['attempts'] >= 5) {
        $db->rollBack();
        respond(429, ['error' => 'Too many incorrect codes. Request a new code.']);
    }
    $expectedHash = hash_hmac('sha256', $challengeId . ':' . $code, $appSecret);
    if (!hash_equals($challenge['code_hash'], $expectedHash)) {
        $incrementAttempts = $db->prepare('UPDATE free_server_challenges SET attempts = attempts + 1 WHERE id = ?');
        $incrementAttempts->execute([$challengeId]);
        $db->commit();
        respond(400, ['error' => 'The verification code is incorrect.', 'error_code' => 'incorrect']);
    }
    $markVerified = $db->prepare('UPDATE free_server_challenges SET verified_at = COALESCE(verified_at, UTC_TIMESTAMP()) WHERE id = ?');
    $markVerified->execute([$challengeId]);
    $db->commit();

    try {
        $panelUser = exactPanelUser($email);
        try {
            $serverId = createPanelServer($db, $panelUser, $email);
        } catch (RuntimeException $error) {
            $userId = (int) ($panelUser['id'] ?? 0);
            if ($userId > 0) {
                $markFailed = $db->prepare("UPDATE free_server_orders SET status = 'failed', updated_at = UTC_TIMESTAMP() WHERE panel_user_id = ? AND status = 'provisioning'");
                $markFailed->execute([$userId]);
            }
            throw $error;
        }
        $markUsed = $db->prepare('UPDATE free_server_challenges SET used_at = UTC_TIMESTAMP() WHERE id = ?');
        $markUsed->execute([$challengeId]);
        respond(200, ['server_id' => $serverId]);
    } catch (PanelApiException $error) {
        error_log('MTCore Pterodactyl API error (' . $error->status . '): ' . $error->getMessage());
        respond(502, ['error' => 'The panel could not complete the server request. Please contact support.']);
    }
} catch (PDOException $error) {
    error_log('MTCore free server database error: ' . $error->getMessage());
    respond(500, ['error' => 'The server request service is temporarily unavailable. Please contact support.']);
} catch (RuntimeException $error) {
    error_log('MTCore free server configuration or delivery error: ' . $error->getMessage());
    respond(503, ['error' => 'The server request service is not configured yet. Please contact support.']);
}
