<?php

namespace App\Domain\Organizations\Enums;

enum OrganizationType: string
{
    case Manufacturer = 'manufacturer';
    case Importer = 'importer';
    case NationalDistributor = 'national_distributor';
    case RegionalDistributor = 'regional_distributor';
    case Wholesaler = 'wholesaler';
    case Pharmacy = 'pharmacy';
    case PharmacyChain = 'pharmacy_chain';
    case Hospital = 'hospital';
    case HealthcareFacility = 'healthcare_facility';
    case InstitutionalProcurement = 'institutional_procurement';
    case LogisticsProvider = 'logistics_provider';
    case ColdChainLogisticsProvider = 'cold_chain_logistics_provider';
    case ServiceProvider = 'service_provider';

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }
}
