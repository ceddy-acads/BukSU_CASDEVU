<?php
/**
 * CASMS — Inventory availability, reservations, and borrowing (FR-6)
 *
 * Availability is always derived, never stored. Two things consume stock:
 *   - an open borrowing (released, not yet returned) — consumes it now
 *   - an approved reservation — consumes it for its date window only
 * A stored "quantity_available" column would drift out of step with both.
 */

declare(strict_types=1);

/**
 * How many units of an item are free *right now*.
 * Mirrors the v_item_availability view for a single item.
 */
function quantity_available_now(int $itemId): int
{
    $row = fetch_one(
        'SELECT i.quantity_total,
                COALESCE((SELECT SUM(b.quantity) FROM borrowings b
                           WHERE b.item_id = i.item_id AND b.returned_at IS NULL), 0) AS out_now
           FROM inventory_items i
          WHERE i.item_id = ?',
        [$itemId]
    );

    if ($row === null) {
        return 0;
    }

    return max(0, (int) $row['quantity_total'] - (int) $row['out_now']);
}

/**
 * How many units are free across a date window — what a reservation needs.
 *
 * Counts open borrowings (they may not be back in time) plus approved
 * reservations whose window overlaps. Two windows overlap when each starts
 * before the other ends.
 *
 * @param int|null $ignoreReservationId  Exclude a reservation from the maths,
 *                                       so editing one does not clash with itself.
 */
function quantity_available_between(
    int $itemId,
    string $from,
    string $until,
    ?int $ignoreReservationId = null
): int {
    $item = fetch_one('SELECT quantity_total FROM inventory_items WHERE item_id = ?', [$itemId]);
    if ($item === null) {
        return 0;
    }

    $outNow = (int) fetch_value(
        'SELECT COALESCE(SUM(quantity), 0) FROM borrowings
          WHERE item_id = ? AND returned_at IS NULL',
        [$itemId]
    );

    $reserved = (int) fetch_value(
        "SELECT COALESCE(SUM(ri.quantity), 0)
           FROM reservation_items ri
           JOIN reservations r ON r.reservation_id = ri.reservation_id
          WHERE ri.item_id = ?
            AND r.status = 'approved'
            AND r.reservation_id <> ?
            AND r.needed_from < ?
            AND r.needed_until > ?",
        [$itemId, $ignoreReservationId ?? 0, $until, $from]
    );

    return max(0, (int) $item['quantity_total'] - $outNow - $reserved);
}

/**
 * Check every line of a reservation against availability.
 *
 * @param  array<int, array{item_id: int, quantity: int}> $lines
 * @return array<int, string>  Human-readable conflicts; empty means it fits.
 */
function reservation_conflicts(array $lines, string $from, string $until, ?int $ignoreReservationId = null): array
{
    $conflicts = [];

    foreach ($lines as $line) {
        $itemId   = (int) $line['item_id'];
        $wanted   = (int) $line['quantity'];
        $item     = fetch_one('SELECT name, item_code FROM inventory_items WHERE item_id = ?', [$itemId]);
        if ($item === null) {
            continue;
        }

        $free = quantity_available_between($itemId, $from, $until, $ignoreReservationId);
        if ($wanted > $free) {
            $conflicts[] = sprintf(
                '%s (%s): %d requested, only %d available for that period.',
                $item['name'], $item['item_code'], $wanted, $free
            );
        }
    }

    return $conflicts;
}

/** Items released and not yet returned, past their expected return date. */
function overdue_borrowing_count(): int
{
    return (int) fetch_value(
        'SELECT COUNT(*) FROM borrowings
          WHERE returned_at IS NULL AND expected_return_at < NOW()'
    );
}

/** Is this loan past due? */
function is_overdue(array $borrowing): bool
{
    return $borrowing['returned_at'] === null
        && $borrowing['expected_return_at'] !== null
        && strtotime((string) $borrowing['expected_return_at']) < time();
}

/**
 * Recompute an item's status from what is actually happening to it.
 *
 * Statuses the office sets by hand — damaged, under_maintenance, unavailable —
 * are left alone; only the automatic three are derived.
 */
function refresh_item_status(int $itemId): void
{
    $item = fetch_one('SELECT status, quantity_total FROM inventory_items WHERE item_id = ?', [$itemId]);
    if ($item === null || in_array($item['status'], ['damaged', 'under_maintenance', 'unavailable'], true)) {
        return;
    }

    $outNow = (int) fetch_value(
        'SELECT COALESCE(SUM(quantity), 0) FROM borrowings WHERE item_id = ? AND returned_at IS NULL',
        [$itemId]
    );

    $hasUpcomingReservation = (bool) fetch_value(
        "SELECT 1 FROM reservation_items ri
           JOIN reservations r ON r.reservation_id = ri.reservation_id
          WHERE ri.item_id = ? AND r.status = 'approved' AND r.needed_until > NOW()
          LIMIT 1",
        [$itemId]
    );

    $status = match (true) {
        $outNow > 0             => 'borrowed',
        $hasUpcomingReservation => 'reserved',
        default                 => 'available',
    };

    query('UPDATE inventory_items SET status = ? WHERE item_id = ?', [$status, $itemId]);
}

/** Every item status, for filter dropdowns. */
function inventory_statuses(): array
{
    return ['available', 'reserved', 'borrowed', 'damaged', 'under_maintenance', 'unavailable'];
}
