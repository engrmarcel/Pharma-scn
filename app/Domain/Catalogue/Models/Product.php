<?php

namespace App\Domain\Catalogue\Models;

use App\Domain\Catalogue\Enums\ProductStatus;
use App\Domain\Platform\Models\AdministrationRoute;
use App\Domain\Platform\Models\DosageForm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Canonical, platform-shared product record. Written only through
 * ProductCatalogueService so every change is versioned and audited.
 */
class Product extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'temperature_min_c' => 'decimal:2',
            'temperature_max_c' => 'decimal:2',
            'requires_cold_chain' => 'boolean',
            'approved_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function dosageForm(): BelongsTo
    {
        return $this->belongsTo(DosageForm::class, 'dosage_form_code');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(AdministrationRoute::class, 'route_code');
    }

    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class, 'product_ingredients')
            ->withPivot(['strength_value', 'strength_unit', 'per_value', 'per_unit', 'sequence'])
            ->orderByPivot('sequence');
    }

    public function packageLevels(): HasMany
    {
        return $this->hasMany(ProductPackageLevel::class)->orderBy('level');
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(ProductIdentifier::class);
    }

    public function classifications(): HasMany
    {
        return $this->hasMany(ProductClassification::class);
    }

    public function regulatoryIdentifiers(): MorphMany
    {
        return $this->morphMany(RegulatoryIdentifier::class, 'subject');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if ($term === null || trim($term) === '') {
            return $query;
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($term)).'%';

        return $query->where(fn (Builder $q) => $q
            ->where('display_name', 'like', $like)
            ->orWhere('generic_name', 'like', $like)
            ->orWhere('platform_sku', 'like', $like)
            ->orWhereHas('identifiers', fn ($i) => $i->where('value', trim($term)))
            ->orWhereHas('regulatoryIdentifiers', fn ($r) => $r->where('value', strtoupper(trim($term)))));
    }
}
