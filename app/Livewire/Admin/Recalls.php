<?php

namespace App\Livewire\Admin;

use App\Livewire\Compliance\Recalls as OrganizationRecalls;
use Livewire\Attributes\Title;

/** Platform-initiated recalls (a regulator's instruction, for example), with network-wide exposure. */
#[Title('Platform recalls')]
class Recalls extends OrganizationRecalls
{
    public const ROUTE = 'admin.recalls';

    protected string $prefix = 'admin/';
}
