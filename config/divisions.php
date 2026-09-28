<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Divisions (Divisi)
    |--------------------------------------------------------------------------
    |
    | The choices offered on the sign-up biodata form, grouped the way the
    | WhatsApp attendance report lists them. Adjust to your organisation.
    | "Security", "OB" and "Engineering" also select their own work schedule.
    |
    */

    'groups' => [
        'STAFF OFFICE' => ['Office', 'Sekretaris Direktur'],
        'LEADER' => ['Leader'],
        'STAFF IT' => ['IT'],
        'STAFF ADMIN MEDSOS' => ['Admin Medsos'],
        'STAFF LAINNYA' => ['Design', 'Editor', 'Engineering', 'Survey & Acara', 'Content Creator'],
        'STAFF RESTO' => ['Kitchen'],
        'OB, SECURITY & RECEPTIONIST' => ['OB', 'Security', 'Resepsionis'],
    ],

    // Report group for employees whose division is empty or not listed above.
    'fallback_group' => 'STAFF LAINNYA',

    // Report groups that list names without the employee's position.
    'hide_position_groups' => ['STAFF ADMIN MEDSOS'],

];
