<?php

namespace App\Livewire\Organization;

use App\Livewire\WebPage;
use Livewire\Attributes\Title;

/** The organization's plan, its entitlements and current usage against limits. */
#[Title('Subscription')]
class Subscription extends WebPage
{
    public const ROUTE = 'organization.subscription';

    public function render()
    {
        return view('livewire.organization.subscription', [
            'sub' => $this->api()->get('organization/subscription')['data'],
            'keys' => config('entitlements.keys'),
        ]);
    }
}
