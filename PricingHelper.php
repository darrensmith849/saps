<?php

namespace NGD_THEME\Functions;

if (!defined('ABSPATH'))
    exit;

class PricingHelper
{
    /**
     * Detects the type of a listing based on its category terms.
     * Rules:
     * - 'pre' matches slugs/names containing: 'pre', 'preschool', 'pre-school'
     * - 'primary' matches: 'primary'
     * - 'high' matches: 'high', 'secondary'
     */
    public static function detect_type_from_listing($listing_id)
    {
        $terms = wp_get_object_terms($listing_id, 'job_listing_category', ['fields' => 'all']);

        if (is_wp_error($terms) || empty($terms)) {
            return 'unknown';
        }

        // Slug-first matching (preferred), then name matching
        foreach ($terms as $t) {
            $slug = strtolower($t->slug);

            // Checks by Slug
            if (strpos($slug, 'pre') !== false)
                return 'pre';
            if (strpos($slug, 'primary') !== false)
                return 'primary';
            if (strpos($slug, 'high') !== false || strpos($slug, 'secondary') !== false)
                return 'high';
        }

        // Fallback to Name
        foreach ($terms as $t) {
            $name = strtolower($t->name);

            if (strpos($name, 'preschool') !== false || strpos($name, 'pre-school') !== false || strpos($name, 'pre') !== false)
                return 'pre';
            if (strpos($name, 'primary') !== false)
                return 'primary';
            if (strpos($name, 'high') !== false || strpos($name, 'secondary') !== false)
                return 'high';
        }

        return 'unknown';
    }

    /**
     * Calculates the total upgrade price for a set of listing IDs strictly based on campuses.
     * Campus = Up to 1 Pre, 1 Primary, 1 High.
     * Price:
     * - 1 Type in Campus: R 2499
     * - 2 or 3 Types in Campus: R 4999
     */
    public static function calculate_price_from_listing_ids(array $listing_ids): array
    {
        $types = ['pre' => [], 'primary' => [], 'high' => []];
        $unknown_ids = [];

        foreach ($listing_ids as $id) {
            $type = self::detect_type_from_listing($id);
            if ($type === 'unknown') {
                $unknown_ids[] = $id;
            } else {
                $types[$type][] = $id;
            }
        }

        if (!empty($unknown_ids)) {
            return [
                'ok' => false,
                'total' => 0,
                'error' => 'Could not classify listing types for IDs: ' . implode(', ', $unknown_ids),
                'debug' => [
                    'unknown_ids' => $unknown_ids
                ]
            ];
        }

        // Counts
        $count_pre = count($types['pre']);
        $count_primary = count($types['primary']);
        $count_high = count($types['high']);

        // Max campus count is determined by the type with the most listings (greedy allocation)
        $campus_count = max($count_pre, $count_primary, $count_high);
        $campuses = [];
        $total = 0;

        for ($i = 0; $i < $campus_count; $i++) {
            $campus_types = [];

            // Pop one of each if available
            if (!empty($types['pre'])) {
                $campus_types[] = 'pre';
                array_shift($types['pre']);
            }
            if (!empty($types['primary'])) {
                $campus_types[] = 'primary';
                array_shift($types['primary']);
            }
            if (!empty($types['high'])) {
                $campus_types[] = 'high';
                array_shift($types['high']);
            }

            $type_count = count($campus_types);
            $price = ($type_count >= 2) ? 4999 : 2499;
            $total += $price;

            $campuses[] = [
                'index' => $i,
                'types' => $campus_types,
                'price' => $price
            ];
        }

        return [
            'ok' => true,
            'total' => $total,
            'total_formatted' => number_format($total, 2, '.', ''),
            'error' => '',
            'debug' => [
                'counts' => [
                    'pre' => $count_pre,
                    'primary' => $count_primary,
                    'high' => $count_high
                ],
                'campus_count' => $campus_count,
                'campuses' => $campuses
            ]
        ];
    }
}
