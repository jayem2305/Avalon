<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/game.php';

// Each room is one row holding the whole game state as JSON. Every change runs
// inside a transaction with the row locked, so simultaneous votes never clash.

function is_sqlite(): bool
{
    return DB_DRIVER === 'sqlite';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }

    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

    if (is_sqlite()) {
        $pdo = new PDO('sqlite:' . SQLITE_PATH, null, null, $opts);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('CREATE TABLE IF NOT EXISTS rooms (
            code TEXT PRIMARY KEY,
            state TEXT NOT NULL,
            updated_at INTEGER NOT NULL
        )');
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn . ';dbname=' . DB_NAME, DB_USER, DB_PASS, $opts);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) !== 1049) { // 1049 = unknown database
            throw $e;
        }
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE `' . DB_NAME . '`');
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS rooms (
        code VARCHAR(8) NOT NULL PRIMARY KEY,
        state MEDIUMTEXT NOT NULL,
        updated_at INT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    return $pdo;
}

function encode_state(array $s): string
{
    return json_encode($s, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function create_room(array $state): string
{
    $pdo = db();
    $pdo->prepare('DELETE FROM rooms WHERE updated_at < ?')->execute([time() - ROOM_TTL]);

    $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZ'; // no I or O
    for ($try = 0; $try < 25; $try++) {
        $code = '';
        for ($i = 0; $i < 4; $i++) {
            $code .= $letters[random_int(0, strlen($letters) - 1)];
        }
        $state['code'] = $code;
        try {
            $pdo->prepare('INSERT INTO rooms (code, state, updated_at) VALUES (?, ?, ?)')
                ->execute([$code, encode_state($state), time()]);
            return $code;
        } catch (PDOException $e) {
            if ($e->getCode() !== '23000') { // duplicate key: try another code
                throw $e;
            }
        }
    }
    throw new GameError('Could not allocate a room code. Please try again.');
}

function load_room(string $code): array
{
    $st = db()->prepare('SELECT state FROM rooms WHERE code = ?');
    $st->execute([$code]);
    $json = $st->fetchColumn();
    if ($json === false) {
        throw new GameError('Room not found. Check the code and try again.', 'no_room');
    }
    return json_decode($json, true);
}

/**
 * Loads a room with its row locked, lets $fn modify the state by reference,
 * and saves it (bumping the version) if anything changed.
 * Returns [$fnResult, $finalState].
 */
function mutate_room(string $code, callable $fn): array
{
    $pdo = db();
    is_sqlite() ? $pdo->exec('BEGIN IMMEDIATE') : $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT state FROM rooms WHERE code = ?' . (is_sqlite() ? '' : ' FOR UPDATE'));
        $st->execute([$code]);
        $json = $st->fetchColumn();
        if ($json === false) {
            throw new GameError('Room not found. Check the code and try again.', 'no_room');
        }

        $s = json_decode($json, true);
        $before = encode_state($s);
        $result = $fn($s);

        if (encode_state($s) !== $before) {
            $s['version']++;
            $pdo->prepare('UPDATE rooms SET state = ?, updated_at = ? WHERE code = ?')
                ->execute([encode_state($s), time(), $code]);
        }

        is_sqlite() ? $pdo->exec('COMMIT') : $pdo->commit();
        return [$result, $s];
    } catch (Throwable $e) {
        is_sqlite() ? $pdo->exec('ROLLBACK') : $pdo->rollBack();
        throw $e;
    }
}
