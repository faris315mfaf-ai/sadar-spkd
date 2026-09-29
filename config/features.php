<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Optional features
    |--------------------------------------------------------------------------
    |
    | payroll: salary processing, payslips and salary fields on employee
    | forms. Hidden by default; set FEATURE_PAYROLL=true to bring it back
    | (then run "php artisan optimize"). Data is kept either way.
    |
    */

    'payroll' => (bool) env('FEATURE_PAYROLL', false),

];
