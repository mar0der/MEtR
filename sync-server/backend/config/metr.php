<?php

return [
    /*
     * Share of Claude cache writes assumed to use the 1-hour TTL when a client
     * did not report the split (clients before 26.40, or events uploaded before
     * the server stored it). Measured on the20th, Oct 2026: main-thread writes
     * are all 1-hour, subagent writes all 5-minute; 90.8% 1-hour by tokens.
     */
    'estimated_one_hour_cache_share' => (float) env('METR_ESTIMATED_1H_CACHE_SHARE', 0.9),

    /*
     * Since this time (UTC) the server stores the 1-hour split that clients
     * from 26.40 report. Events stored earlier lost it.
     */
    'one_hour_split_stored_since' => env('METR_1H_SPLIT_STORED_SINCE', '2026-10-30 03:00:00'),
];
