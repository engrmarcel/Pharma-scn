<?php

namespace App\Providers;

use App\Domain\Access\Services\AccessControl;
use App\Domain\Billing\Models\OrganizationSubscription;
use App\Domain\Billing\Models\SubscriptionPlan;
use App\Domain\Catalogue\Models\Manufacturer;
use App\Domain\Catalogue\Models\OrganizationProduct;
use App\Domain\Catalogue\Models\Product;
use App\Domain\Catalogue\Models\ProductChangeRequest;
use App\Domain\Catalogue\Models\RegulatoryIdentifier;
use App\Domain\Organizations\Models\Branch;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationInvitation;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Models\Warehouse;
use App\Domain\Platform\Models\Attachment;
use App\Domain\Platform\Models\Country;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Reset per request and per queued job.
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(AccessControl::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Mass-assigning a guarded attribute is a bug, not something to ignore quietly.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Stable type names in polymorphic columns; class names never leak into the database.
        Relation::enforceMorphMap([
            'user' => User::class,
            'organization' => Organization::class,
            'membership' => OrganizationMembership::class,
            'invitation' => OrganizationInvitation::class,
            'branch' => Branch::class,
            'warehouse' => Warehouse::class,
            'product' => Product::class,
            'manufacturer' => Manufacturer::class,
            'regulatory_identifier' => RegulatoryIdentifier::class,
            'product_change_request' => ProductChangeRequest::class,
            'organization_product' => OrganizationProduct::class,
            'subscription_plan' => SubscriptionPlan::class,
            'organization_subscription' => OrganizationSubscription::class,
            'country' => Country::class,
            'attachment' => Attachment::class,
        ]);

        // Registry permissions (config/permissions.php) are decided here; any
        // other ability falls through to policies.
        Gate::before(function (User $user, string $ability) {
            $access = $this->app->make(AccessControl::class);

            return $access->isRegistered($ability) ? $access->check($user, $ability) : null;
        });

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);
    }
}
