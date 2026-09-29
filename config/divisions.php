<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Divisions (Divisi)
    |--------------------------------------------------------------------------
    |
    | Employees type their division by hand on the biodata form. With no groups
    | configured, the WhatsApp attendance report lists each division as its own
    | group. To merge divisions under one heading later, add groups here, e.g.
    |
    |     'STAFF OFFICE' => ['Office', 'Sekretaris Direktur'],
    |
    | "Security", "OB" and "Engineering" select their own work schedule.
    |
    */

    'groups' => [],

    // Report group for employees without a division (or one not listed in "groups").
    'fallback_group' => 'LAINNYA',

    // Report groups that list names without the employee's position.
    'hide_position_groups' => [],

];
