<?php

declare(strict_types=1);

namespace App\Services\Rathena;

/**
 * Turns rAthena's boolean attribute columns into labelled lists.
 *
 * Modern rAthena does not store an item's equip locations as a bitmask. It
 * stores one nullable `tinyint` column per location -- `location_head_top`,
 * `location_right_hand` and so on -- and the same for jobs, classes, trade
 * restrictions, item flags and monster modes. An item row therefore carries
 * around a hundred columns of which a handful are set.
 *
 * This is the part of FluxCP's Flux_Template that read those columns and
 * produced the labels the item and monster pages show. It is a service rather
 * than a view helper because the labels go into an API response now, and
 * because the same decoding is needed by the item shop and the character
 * equipment view.
 *
 * ---------------------------------------------------------------------------
 * Renewal
 * ---------------------------------------------------------------------------
 *
 * The third-class jobs and classes only exist as columns on a renewal server.
 * Asking a pre-renewal `item_db` for `job_summoner` is an SQL error, so the
 * renewal half of each map is only consulted when the row actually has the
 * column -- which is checked against the row, not against the server's
 * renewal flag, because an operator can run a renewal emulator against an
 * older schema.
 */
final readonly class AttributeDecoder
{
    /**
     * Labels for every attribute column that is set on this row.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $map  Column name => label.
     * @return list<string>
     */
    public function labels(array $row, array $map): array
    {
        $labels = [];

        foreach ($map as $column => $label) {
            if ($this->isSet($row, $column)) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /**
     * The same, keyed by column, for a client that wants to render its own
     * labels or icons rather than the ones configured here.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $map
     * @return array<string, string>
     */
    public function labelled(array $row, array $map): array
    {
        $set = [];

        foreach ($map as $column => $label) {
            if ($this->isSet($row, $column)) {
                $set[$column] = $label;
            }
        }

        return $set;
    }

    /**
     * Equip locations, in the order an inventory window shows them.
     *
     * A set of locations that means one thing together is collapsed to that
     * one label: an item set in both hands is "Two-Handed", not "Right Hand,
     * Left Hand", which describes the storage rather than the item. FluxCP's
     * equip_location_combinations.php did the same.
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    public function equipLocations(array $row): array
    {
        $map = (array) config('rathena_reference.equip_locations', []);
        $columns = array_keys($this->labelled($row, $map));

        if ($columns === []) {
            return [];
        }

        /*
         * Keys are the columns sorted and slash-joined, so the lookup does not
         * depend on the order the configuration happens to list them in.
         */
        sort($columns);
        $combinations = (array) config('rathena_reference.equip_location_combinations', []);
        $combined = $combinations[implode('/', $columns)] ?? null;

        if (is_string($combined)) {
            return [$combined];
        }

        return $this->labels($row, $map);
    }

    /**
     * Jobs that can equip this.
     *
     * `job_all` is collapsed to a single "All jobs" rather than listed
     * alongside the individual jobs, because rAthena sets it *instead of*
     * them and a list of twenty-six entries tells the reader nothing.
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    public function jobs(array $row): array
    {
        $map = (array) config('rathena_reference.equip_jobs.base', []);

        if ($this->isSet($row, 'job_all')) {
            return [$map['job_all'] ?? 'All jobs'];
        }

        unset($map['job_all']);

        return $this->labels(
            $row,
            [...$map, ...(array) config('rathena_reference.equip_jobs.renewal', [])],
        );
    }

    /**
     * Character classes that can equip this, with the same `class_all`
     * collapsing as jobs.
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    public function classes(array $row): array
    {
        $map = (array) config('rathena_reference.equip_classes.base', []);

        if ($this->isSet($row, 'class_all')) {
            return [$map['class_all'] ?? 'All classes'];
        }

        unset($map['class_all']);

        return $this->labels(
            $row,
            [...$map, ...(array) config('rathena_reference.equip_classes.renewal', [])],
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    public function tradeRestrictions(array $row): array
    {
        return $this->labels($row, (array) config('rathena_reference.trade_restrictions', []));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    public function itemFlags(array $row): array
    {
        return $this->labels($row, (array) config('rathena_reference.item_flags', []));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    public function monsterModes(array $row): array
    {
        return $this->labels($row, (array) config('rathena_reference.monster_modes', []));
    }

    /**
     * The display name for an item's `type` column.
     *
     * rAthena writes these as lowercase words. An unrecognised value is passed
     * through rather than hidden, because a server on a newer emulator may
     * have a type this panel's map predates, and showing it is more useful
     * than showing nothing.
     */
    public function itemType(?string $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }

        $types = (array) config('rathena_reference.item_types', []);

        return $types[strtolower($type)] ?? $type;
    }

    /**
     * Whether a column is present on the row and set.
     *
     * Present matters as much as set: a pre-renewal schema simply lacks the
     * third-class columns, and treating absent as false is right while
     * treating it as an error would break every item page on that server.
     *
     * @param  array<string, mixed>  $row
     */
    private function isSet(array $row, string $column): bool
    {
        // rAthena writes 1, but some tools write 'true' or 'yes'; anything
        // truthy that is not 0 counts.
        return array_key_exists($column, $row)
            && $row[$column] !== null
            && (int) $row[$column] !== 0;
    }
}
