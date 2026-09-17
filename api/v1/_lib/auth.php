<?php
/**
 * CM Relay
 * File: api/v1/_lib/auth.php
 *
 * Per-installation bearer token auth. No global master secret (README
 * "Trdna pravila" - every installation has its own token, revoke/rotate
 * per installation, never a shared key).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../src/db/registry.php';

/**
 * @return array<string, mixed>|null the installations row, or null if the
 *   request has no valid bearer token.
 */
function relay_authenticate_request(): ?array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        return null;
    }
    $token = trim($m[1]);
    if ($token === '') {
        return null;
    }

    $tokenHash = hash('sha256', $token);
    $stmt = relay_registry_db()->prepare(
        'SELECT * FROM installations WHERE token_hash = ? AND status = "active"'
    );
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        return null;
    }

    relay_registry_db()
        ->prepare('UPDATE installations SET last_seen_at = ? WHERE id = ?')
        ->execute([gmdate('c'), $row['id']]);

    return $row;
}

function relay_require_auth(): array
{
    $installation = relay_authenticate_request();
    if ($installation === null) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'unauthorized']);
        exit;
    }
    return $installation;
}

function relay_generate_token(): string
{
    return bin2hex(random_bytes(32));
}
