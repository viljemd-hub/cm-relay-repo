<?php
/**
 * CM Relay
 * File: src/db/mapping_writer.php
 * Author: Viljem Dvojmoč
 * Assistant: Claude
 * Copyright (c) 2026 Viljem Dvojmoč. All rights reserved.
 *
 * Shared property_mapping/unit_mapping upsert logic - extracted from
 * api/v1/mappings.php so api/v1/properties/provision.php can write the
 * mapping it just created on Channex without duplicating the UNIQUE-key
 * upsert semantics (re-posting the same cm_property_id updates the stored
 * provider_property_id rather than erroring; re-posting the same
 * (property_mapping_id, cm_unit_id, provider_rate_plan_id) tuple updates
 * room_type/rate_plan ids in place and preserves an already-configured
 * price_corrector_pct unless the caller explicitly sends a new one).
 */

declare(strict_types=1);

require_once __DIR__ . '/registry.php';

/**
 * $fields: cm_property_id, provider, provider_property_id, cm_unit_id,
 * provider_room_type_id, provider_rate_plan_id, price_corrector_pct?
 * @return array{property_mapping_id: string, unit_mapping_id: string}
 */
function relay_write_mapping(PDO $db, string $installationId, array $fields): array
{
    foreach (['cm_property_id', 'provider', 'provider_property_id', 'cm_unit_id', 'provider_room_type_id', 'provider_rate_plan_id'] as $required) {
        if (empty($fields[$required])) {
            throw new InvalidArgumentException("$required required");
        }
    }

    $existingProp = $db->prepare('SELECT id FROM property_mappings WHERE installation_id = ? AND cm_property_id = ?');
    $existingProp->execute([$installationId, $fields['cm_property_id']]);
    $propRow = $existingProp->fetch(PDO::FETCH_ASSOC);

    if ($propRow === false) {
        $propId = relay_uuid_v4();
        $db->prepare('
            INSERT INTO property_mappings (id, installation_id, cm_property_id, provider, provider_property_id)
            VALUES (?, ?, ?, ?, ?)
        ')->execute([$propId, $installationId, $fields['cm_property_id'], $fields['provider'], $fields['provider_property_id']]);
    } else {
        $propId = $propRow['id'];
        $db->prepare('UPDATE property_mappings SET provider = ?, provider_property_id = ? WHERE id = ?')
            ->execute([$fields['provider'], $fields['provider_property_id'], $propId]);
    }

    $existingUnit = $db->prepare('SELECT id, price_corrector_pct FROM unit_mappings WHERE property_mapping_id = ? AND cm_unit_id = ? AND provider_rate_plan_id = ?');
    $existingUnit->execute([$propId, $fields['cm_unit_id'], $fields['provider_rate_plan_id']]);
    $unitRow = $existingUnit->fetch(PDO::FETCH_ASSOC);

    if ($unitRow === false) {
        $priceCorrectorPct = isset($fields['price_corrector_pct']) ? (float)$fields['price_corrector_pct'] : 0.0;
        $unitId = relay_uuid_v4();
        $db->prepare('
            INSERT INTO unit_mappings (id, property_mapping_id, cm_unit_id, provider_room_type_id, provider_rate_plan_id, price_corrector_pct)
            VALUES (?, ?, ?, ?, ?, ?)
        ')->execute([$unitId, $propId, $fields['cm_unit_id'], $fields['provider_room_type_id'], $fields['provider_rate_plan_id'], $priceCorrectorPct]);
    } else {
        $unitId = $unitRow['id'];
        $priceCorrectorPct = isset($fields['price_corrector_pct']) ? (float)$fields['price_corrector_pct'] : (float)$unitRow['price_corrector_pct'];
        $db->prepare('
            UPDATE unit_mappings SET provider_room_type_id = ?, provider_rate_plan_id = ?, price_corrector_pct = ? WHERE id = ?
        ')->execute([$fields['provider_room_type_id'], $fields['provider_rate_plan_id'], $priceCorrectorPct, $unitId]);
    }

    relay_audit('mapping.create', 'unit_mapping', $unitId, $installationId);

    return ['property_mapping_id' => $propId, 'unit_mapping_id' => $unitId];
}

/**
 * Read-only lookup for the "undo" (deprovision) flow - finds every
 * unit_mapping for $cmUnitId under this installation's $cmPropertyId (a
 * unit can have more than one, one per rate plan/channel, see registry.php's
 * UNIQUE(property_mapping_id, cm_unit_id, provider_rate_plan_id)). Ownership-
 * scoped to $installationId, same as every other mapping lookup, so one
 * installation can never discover or touch another's mapping ids.
 * Deliberately does NOT delete anything - the caller must confirm the
 * Channex-side delete succeeded first (see api/v1/properties/deprovision.php)
 * before calling relay_delete_unit_mapping_row() below, so a failed Channex
 * call never silently loses track of a mapping that's still real on Channex.
 *
 * @return array<int, array{unit_mapping_id: string, provider_room_type_id: string, provider_rate_plan_id: string, property_mapping_id: string, provider_property_id: string}>
 */
function relay_find_unit_mappings(PDO $db, string $installationId, string $cmPropertyId, string $cmUnitId): array
{
    $propStmt = $db->prepare('SELECT id, provider_property_id FROM property_mappings WHERE installation_id = ? AND cm_property_id = ?');
    $propStmt->execute([$installationId, $cmPropertyId]);
    $propRow = $propStmt->fetch(PDO::FETCH_ASSOC);
    if ($propRow === false) {
        throw new InvalidArgumentException('no property_mapping for this installation/cm_property_id');
    }

    $unitStmt = $db->prepare('
        SELECT id AS unit_mapping_id, provider_room_type_id, provider_rate_plan_id,
               ? AS property_mapping_id, ? AS provider_property_id
        FROM unit_mappings WHERE property_mapping_id = ? AND cm_unit_id = ?
    ');
    $unitStmt->execute([$propRow['id'], $propRow['provider_property_id'], $propRow['id'], $cmUnitId]);
    $rows = $unitStmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        throw new InvalidArgumentException('no unit_mapping for this cm_unit_id');
    }

    return $rows;
}

function relay_delete_unit_mapping_row(PDO $db, string $unitMappingId): void
{
    $db->prepare('DELETE FROM unit_mappings WHERE id = ?')->execute([$unitMappingId]);
    relay_audit('mapping.delete', 'unit_mapping', $unitMappingId);
}

/**
 * True if $propertyMappingId has zero remaining unit_mappings - the signal
 * api/v1/properties/deprovision.php uses to decide whether a unit removal
 * must also cascade into deleting the Channex property itself (an empty
 * property is not harmless - it can still carry real financial liability).
 */
function relay_property_mapping_is_empty(PDO $db, string $propertyMappingId): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM unit_mappings WHERE property_mapping_id = ?');
    $stmt->execute([$propertyMappingId]);
    return (int)$stmt->fetchColumn() === 0;
}

function relay_delete_property_mapping_row(PDO $db, string $propertyMappingId): void
{
    $db->prepare('DELETE FROM property_mappings WHERE id = ?')->execute([$propertyMappingId]);
    relay_audit('mapping.delete', 'property_mapping', $propertyMappingId);
}
