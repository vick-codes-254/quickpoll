<?php
declare(strict_types=1);

/* Quickpoll — data layer (SQLite, created automatically; no MySQL setup). */

function db(): PDO
{
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $pdo = new PDO('sqlite:' . $dir . '/polls.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('CREATE TABLE IF NOT EXISTS polls (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        code        TEXT UNIQUE NOT NULL,
        question    TEXT NOT NULL,
        created_at  TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS options (
        id       INTEGER PRIMARY KEY AUTOINCREMENT,
        poll_id  INTEGER NOT NULL REFERENCES polls(id) ON DELETE CASCADE,
        label    TEXT NOT NULL,
        votes    INTEGER NOT NULL DEFAULT 0
    )');
    return $pdo;
}

function make_code(PDO $pdo, int $len = 6): string
{
    $alphabet = '23456789abcdefghijkmnpqrstuvwxyz';
    $max = strlen($alphabet) - 1;
    do {
        $code = '';
        for ($i = 0; $i < $len; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }
        $stmt = $pdo->prepare('SELECT 1 FROM polls WHERE code = ?');
        $stmt->execute([$code]);
    } while ($stmt->fetchColumn() !== false);
    return $code;
}

/** @return array{poll: array, options: array}|null */
function load_poll(PDO $pdo, string $code): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM polls WHERE code = ?');
    $stmt->execute([$code]);
    $poll = $stmt->fetch();
    if (!$poll) {
        return null;
    }
    $opt = $pdo->prepare('SELECT * FROM options WHERE poll_id = ? ORDER BY id');
    $opt->execute([$poll['id']]);
    return ['poll' => $poll, 'options' => $opt->fetchAll()];
}
