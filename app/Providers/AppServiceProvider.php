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
use App\Domain\Catalogue\Services\UnitConverter;
use App\Domain\FieldForce\Models\FieldFollowUp;
use App\Domain\FieldForce\Models\FieldOrder;
use App\Domain\FieldForce\Models\FieldVisit;
use App\Domain\FieldForce\Models\Territory;
use App\Domain\FieldForce\Services\FieldForceScope;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\FinancialPeriod;
use App\Domain\Finance\Models\JournalEntry;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Services\ChartOfAccounts;
use App\Domain\Inventory\Models\Batch;
use App\Domain\Inventory\Models\BinLocation;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Services\LocationAccess;
use App\Domain\Organizations\Models\Branch;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationInvitation;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Models\Warehouse;
use App\Domain\Parties\Models\Customer;
use App\Domain\Parties\Models\PartyContact;
use App\Domain\Parties\Models\Supplier;
use App\Domain\Platform\Models\Attachment;
use App\Domain\Platform\Models\Country;
use App\Domain\Pos\Models\PosRefund;
use App\Domain\Pos\Models\PosSale;
use App\Domain\Pos\Models\PosShift;
use App\Domain\Pos\Models\PosTerminal;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseRequisition;
use App\Domain\Procurement\Models\SupplierInvoice;
use App\Domain\Procurement\Models\SupplierReturn;
use App\Domain\Sales\Models\CreditNote;
use App\Domain\Sales\Models\Delivery;
use App\Domain\Sales\Models\PriceList;
use App\Domain\Sales\Models\Quotation;
use App\Domain\Sales\Models\SalesInvoice;
use App\Domain\Sales\Models\SalesOrder;
use App\Http\Middleware\EnsureAccountSecurity;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ResolveTenant;
use App\Http\View\Composers\NavigationComposer;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

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
        $this->app->scoped(LocationAccess::class);
        $this->app->scoped(UnitConverter::class);
        $this->app->scoped(ChartOfAccounts::class);
        $this->app->scoped(FieldForceScope::class);
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
            'supplier' => Supplier::class,
            'customer' => Customer::class,
            'party_contact' => PartyContact::class,
            'batch' => Batch::class,
            'bin_location' => BinLocation::class,
            'stock_adjustment' => StockAdjustment::class,
            'stock_transfer' => StockTransfer::class,
            'stock_count' => StockCount::class,
            'purchase_requisition' => PurchaseRequisition::class,
            'purchase_order' => PurchaseOrder::class,
            'goods_receipt' => GoodsReceipt::class,
            'supplier_invoice' => SupplierInvoice::class,
            'supplier_return' => SupplierReturn::class,
            'account' => Account::class,
            'financial_period' => FinancialPeriod::class,
            'journal_entry' => JournalEntry::class,
            'payment' => Payment::class,
            'price_list' => PriceList::class,
            'quotation' => Quotation::class,
            'sales_order' => SalesOrder::class,
            'delivery' => Delivery::class,
            'sales_invoice' => SalesInvoice::class,
            'credit_note' => CreditNote::class,
            'pos_terminal' => PosTerminal::class,
            'pos_shift' => PosShift::class,
            'pos_sale' => PosSale::class,
            'pos_refund' => PosRefund::class,
            'territory' => Territory::class,
            'field_visit' => FieldVisit::class,
            'field_follow_up' => FieldFollowUp::class,
            'field_order' => FieldOrder::class,
            'attachment' => Attachment::class,
        ]);

        // Registry permissions (config/permissions.php) are decided here; any
        // other ability falls through to policies.
        Gate::before(function (User $user, string $ability) {
            $access = $this->app->make(AccessControl::class);

            return $access->isRegistered($ability) ? $access->check($user, $ability) : null;
        });

        View::composer(['layouts.app', 'layouts.guest', 'dashboard'], NavigationComposer::class);

        // Livewire component updates re-run the page's tenant and module checks.
        Livewire::addPersistentMiddleware([EnsureUserIsActive::class, EnsureAccountSecurity::class, ResolveTenant::class, EnsureModuleEnabled::class]);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);
    }
}
