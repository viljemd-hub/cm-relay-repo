<?php
/**
 * CM Relay — skeleton
 * File: src/providers/ProviderResult.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * Return value shape for CmConnectivityProviderAdapter operations that don't
 * need a full status object. Not implemented yet - Faza D.
 */

declare(strict_types=1);

final class ProviderResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $error = null,
        public readonly array $data = []
    ) {
    }
}
