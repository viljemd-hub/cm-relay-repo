<?php
/**
 * CM Relay
 * File: src/lib/occupancy_to_ari.php
 *
 * Converts a CM occupancy_merged.json snapshot (list of start/end/status/
 * lock segments, end-exclusive nights - same convention as cm_regen_merged
 * _for_unit() in the CM repos) into per-date Channex ARI entries.
 */

declare(strict_types=1);

/**
 * Only 'hard'-locked segments count as unavailable - mirrors CM's own
 * hard-beats-soft precedence rule (see cm_regen_merged_for_unit() GAP-01
 * fix), so a soft/tentative hold never wrongly blocks a channel sale.
 *
 * @param array<int, array<string, mixed>> $segments
 * @return array<string, true> set of blocked dates, keyed 'Y-m-d'
 */
function relay_occupancy_segments_to_blocked_dates(array $segments): array
{
    $blocked = [];

    foreach ($segments as $seg) {
        if (($seg['lock'] ?? '') !== 'hard') {
            continue;
        }
        $start = (string)($seg['start'] ?? '');
        $end = (string)($seg['end'] ?? '');
        if ($start === '' || $end === '') {
            continue;
        }

        try {
            $cursor = new DateTimeImmutable($start);
            $endDate = new DateTimeImmutable($end);
        } catch (Exception $e) {
            continue;
        }

        while ($cursor < $endDate) {
            $blocked[$cursor->format('Y-m-d')] = true;
            $cursor = $cursor->modify('+1 day');
        }
    }

    return $blocked;
}

/**
 * Builds Channex /restrictions entries for a rolling window starting today.
 * 500 days matches Channex's own "full sync" window definition (PMS
 * Certification Tests §1) - this isn't a timer-driven full sync (that's
 * explicitly forbidden, §13), it's one event-driven push of the full
 * current picture, which is what their own full-sync spec describes.
 *
 * @param array<int, array<string, mixed>> $occupancySegments
 */
function relay_build_availability_entries(
    string $propertyId,
    string $ratePlanId,
    array $occupancySegments,
    int $minNights,
    int $windowDays = 500
): array {
    $blocked = relay_occupancy_segments_to_blocked_dates($occupancySegments);

    $entries = [];
    $cursor = new DateTimeImmutable('today');
    for ($i = 0; $i < $windowDays; $i++) {
        $date = $cursor->format('Y-m-d');
        $entries[] = [
            'property_id' => $propertyId,
            'rate_plan_id' => $ratePlanId,
            'date' => $date,
            'availability' => isset($blocked[$date]) ? 0 : 1,
            'min_stay_arrival' => $minNights,
            'min_stay_through' => $minNights,
            'min_stay' => $minNights,
        ];
        $cursor = $cursor->modify('+1 day');
    }

    return $entries;
}
