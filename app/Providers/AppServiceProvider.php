<?php

namespace App\Providers;

use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Policies\ItemPolicy;
use App\Domains\Catalog\Policies\CategoryPolicy;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\FiscalDocuments\Policies\InvoicePolicy;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\Company;
use App\Domains\Companies\Policies\CompanyPolicy;
use App\Domains\Customers\Models\Customer;
use App\Domains\Customers\Policies\CustomerPolicy;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Policies\SalePolicy;
use App\Domains\Taxes\Models\CompanyTax;
use App\Domains\Taxes\Policies\CompanyTaxPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CompanyContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Item::class, ItemPolicy::class);
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(ElectronicDocument::class, InvoicePolicy::class);
        Gate::policy(CompanyTax::class, CompanyTaxPolicy::class);
        Gate::policy(Sale::class, SalePolicy::class);
    }
}
