<?php

/*
|--------------------------------------------------------------------------
| Currencies (ISO 4217)
|--------------------------------------------------------------------------
|
| minor_units: decimal places of the currency's smallest unit. Document
| amounts are rounded to them (a Rwandan franc invoice has no decimals).
| Symbols are display hints only; formatting uses the viewer's locale.
| A country cannot be activated with a currency missing here.
|
*/

return [
    'NGN' => ['name' => 'Nigerian naira', 'minor_units' => 2, 'symbol' => '₦'],
    'GHS' => ['name' => 'Ghanaian cedi', 'minor_units' => 2, 'symbol' => 'GH₵'],
    'KES' => ['name' => 'Kenyan shilling', 'minor_units' => 2, 'symbol' => 'KSh'],
    'UGX' => ['name' => 'Ugandan shilling', 'minor_units' => 0, 'symbol' => 'USh'],
    'TZS' => ['name' => 'Tanzanian shilling', 'minor_units' => 2, 'symbol' => 'TSh'],
    'RWF' => ['name' => 'Rwandan franc', 'minor_units' => 0, 'symbol' => 'FRw'],
    'ZAR' => ['name' => 'South African rand', 'minor_units' => 2, 'symbol' => 'R'],
    'EGP' => ['name' => 'Egyptian pound', 'minor_units' => 2, 'symbol' => 'E£'],
    'XOF' => ['name' => 'West African CFA franc', 'minor_units' => 0, 'symbol' => 'CFA'],
    'XAF' => ['name' => 'Central African CFA franc', 'minor_units' => 0, 'symbol' => 'FCFA'],
    'USD' => ['name' => 'US dollar', 'minor_units' => 2, 'symbol' => '$'],
    'EUR' => ['name' => 'Euro', 'minor_units' => 2, 'symbol' => '€'],
    'GBP' => ['name' => 'Pound sterling', 'minor_units' => 2, 'symbol' => '£'],
];
