<?php
declare(strict_types=1);

// JSON API. Every request sends {action, code, token, ...}.
// Returns {ok: true, ...} or {ok: false, error, kind}.

require __DIR__ . '/lib/db.php';

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$in = $_GET;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    if (is_array($body)) {
        $in = array_merge($in, $body);
    }
}

try {
    echo json_encode(['ok' => true] + handle((string) ($in['action'] ?? ''), $in), JSON_UNESCAPED_UNICODE);
} catch (GameError $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'kind' => $e->kind]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log('Avalon DB error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Database error. Is MySQL running in XAMPP? (' . $e->getMessage() . ')', 'kind' => 'server']);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('Avalon error: ' . $e);
    echo json_encode(['ok' => false, 'error' => 'Server error: ' . $e->getMessage(), 'kind' => 'server']);
}

function handle(string $action, array $in): array
{
    $token = (string) ($in['token'] ?? '');

    if ($action === 'create') {
        [$state, $host] = new_room_state($in['name'] ?? '');
        $code = create_room($state);
        return ['code' => $code, 'token' => $host['token'], 'playerId' => $host['id']];
    }

    $code = clean_code($in['code'] ?? '');

    if ($action === 'join') {
        [$p] = mutate_room($code, fn(array &$s) => join_room($s, $in['name'] ?? '', $token));
        return ['code' => $code, 'token' => $p['token'], 'playerId' => $p['id']];
    }

    if ($action === 'state') {
        $s = load_room($code);
        $me = require_player($s, $token);
        if (isset($in['v']) && (int) $in['v'] === $s['version']) {
            return ['unchanged' => true];
        }
        return ['state' => build_view($s, $me)];
    }

    [$me, $s] = mutate_room($code, function (array &$s) use ($action, $in, $token) {
        $me = require_player($s, $token);
        apply_action($s, $me, $action, $in);
        return $me;
    });

    // A player who just left no longer has a view.
    if (find_player($s, $me['id']) === null) {
        return ['left' => true];
    }
    return ['state' => build_view($s, $me)];
}
