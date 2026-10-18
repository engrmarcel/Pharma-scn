<?php

namespace App\Domain\Catalogue\Enums;

enum ProductStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Discontinued = 'discontinued';
    case UnderReview = 'under_review';
}
