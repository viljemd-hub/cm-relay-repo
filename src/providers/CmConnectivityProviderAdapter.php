<?php
/**
 * CM Relay — skeleton
 * File: src/providers/CmConnectivityProviderAdapter.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * Provider-neutral interface (see CM_Relay_OTA_arhitektura_2026-08-10.md §8).
 * Relay must never be built as "ChannexRelay" - every upstream OTA connectivity
 * provider (Channex, Beds24, WuBook, native Booking/Airbnb adapters, ...)
 * implements this same interface. Local CM must never bind its own model to a
 * single provider's IDs or payload structure - only normalized data crosses
 * this boundary.
 *
 * Not implemented yet - Faza D, after the first upstream provider is chosen.
 */

declare(strict_types=1);

interface CmConnectivityProviderAdapter
{
    public function createOrAttachProperty(array $property): ProviderResult;
    public function mapUnit(array $mapping): ProviderResult;

    public function pushAvailability(array $payload): ProviderResult;
    public function pushRates(array $payload): ProviderResult;
    public function pushRestrictions(array $payload): ProviderResult;

    public function requestFullSync(string $propertyId): ProviderResult;

    public function normalizeIncomingEvent(array $providerPayload): array;
    public function connectionStatus(string $propertyId): ProviderStatus;
}
