<?php

namespace App\Domain\Logistics\Events;

use App\Support\Events\DomainEvent;

/** A cold-chain shipment went outside its required range (webhook: logistics.temperature_excursion). */
class TemperatureExcursionDetected extends DomainEvent
{
    public function __construct(
        public readonly string $shipmentId,
        public readonly string $excursionId,
        public readonly string $organizationId,
    ) {
        parent::__construct();
    }
}
