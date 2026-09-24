<?php
/**
 * CM Relay — skeleton
 * File: src/providers/ProviderStatus.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * Connection status shape returned by CmConnectivityProviderAdapter::connectionStatus().
 * Not implemented yet - Faza D.
 */

declare(strict_types=1);

final class ProviderStatus
{
    public function __construct(
        public readonly bool $connected,
        public readonly ?string $lastSyncAt = null,
        public readonly ?string $error = null
    ) {
    }
}
