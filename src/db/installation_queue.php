<?php
/**
 * CM Relay
 * File: src/db/installation_queue.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * One SQLite file per installation for events/commands (README 2026-09-17
 * decision) - this is the part that actually grows over time, so it's
 * partitioned from day one instead of one shared table. Almost every
 * operation (an installation draining its own commands, Relay delivering
 * to its own installation) is already naturally scoped to one installation,
 * so no real router is needed - registry.installations.id -> this file path.
 */

declare(strict_types=1);

require_once __DIR__ . '/registry.php';

function relay_installation_queue_path(string $installationId): string
{
    $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $installationId);
    return relay_data_root() . '/installations/' . $safeId . '.sqlite';
}

function relay_installation_queue_db(string $installationId): PDO
{
    $dir = dirname(relay_installation_queue_path($installationId));
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }

    $pdo = new PDO('sqlite:' . relay_installation_queue_path($installationId));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL');

    relay_installation_queue_migrate($pdo);

    return $pdo;
}

function relay_installation_queue_migrate(PDO $pdo): void
{
    // Local CM -> Relay -> provider (outbound: price/availability/restriction pushes)
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS commands (
            id TEXT PRIMARY KEY,
            type TEXT NOT NULL,
            unit TEXT,
            reason TEXT,
            idempotency_key TEXT,
            payload TEXT,
            status TEXT NOT NULL DEFAULT "pending",
            delivery_attempts INTEGER NOT NULL DEFAULT 0,
            next_retry_at TEXT,
            last_error TEXT,
            created_at TEXT NOT NULL,
            completed_at TEXT
        )
    ');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_commands_status ON commands(status)');

    // Provider -> Relay -> local CM (inbound: booking created/modified/cancelled)
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS events (
            id TEXT PRIMARY KEY,
            provider_event_id TEXT,
            idempotency_key TEXT UNIQUE,
            type TEXT NOT NULL,
            channel TEXT,
            occurred_at TEXT,
            payload TEXT,
            status TEXT NOT NULL DEFAULT "received",
            delivery_attempts INTEGER NOT NULL DEFAULT 0,
            next_retry_at TEXT,
            acknowledged_at TEXT,
            created_at TEXT NOT NULL
        )
    ');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_events_status ON events(status)');
}
