<?php

namespace App\Domains\Taxes\Actions;

use App\Domains\Catalog\Models\Category;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Taxes\Models\CompanyTax;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Moves every product of the company, or only those of one category, from one rate to another (e.g.
 * IVA 12 % → 15 %); the default rates of the categories move with them.
 * Only item configuration changes: sales and fiscal documents keep their own snapshots.
 */
final class ReplaceCompanyTaxOnItemsAction
{
    public function __construct(private readonly CompanyContext $context, private readonly AuditRecorder $audit) {}

    /** @return array{from: CompanyTax, to: CompanyTax, itemCount: int} */
    public function preview(string $fromPublicId, string $toPublicId, ?string $categoryPublicId = null): array
    {
        [$from, $to] = $this->resolve($fromPublicId, $toPublicId);
        $category = $this->category($categoryPublicId);

        return ['from' => $from, 'to' => $to, 'itemCount' => $from->items()->when($category, fn ($query) => $query->where('items.category_id', $category->id))->count()];
    }

    public function execute(string $fromPublicId, string $toPublicId, User $actor, ?string $categoryPublicId = null): int
    {
        $company = $this->context->company();
        [$from, $to] = $this->resolve($fromPublicId, $toPublicId);
        $category = $this->category($categoryPublicId);

        return DB::transaction(function () use ($company, $from, $to, $actor, $category): int {
            $rows = DB::table('item_taxes')->where('company_id', $company->id)->where('company_tax_id', $from->id)
                ->when($category, fn ($query) => $query->whereIn('item_id', DB::table('items')->select('id')->where('category_id', $category->id)));
            $categoryRows = DB::table('category_taxes')->where('company_id', $company->id)->where('company_tax_id', $from->id)
                ->when($category, fn ($query) => $query->where('category_id', $category->id));
            (clone $categoryRows)->whereIn('category_id', DB::table('category_taxes')->select('category_id')->where('company_tax_id', $to->id))->delete();
            (clone $categoryRows)->update(['company_tax_id' => $to->id, 'updated_at' => now()]);
            $itemCount = (clone $rows)->lockForUpdate()->count();
            // Items that already had the target rate keep a single row.
            (clone $rows)->whereIn('item_id', DB::table('item_taxes')->select('item_id')->where('company_tax_id', $to->id))->delete();
            (clone $rows)->update(['company_tax_id' => $to->id, 'updated_at' => now()]);
            $this->audit->record($actor, $company, 'company_tax.items_replaced', $to, ['companyTaxPublicId' => $from->public_id], [
                'companyTaxPublicId' => $to->public_id, 'itemCount' => $itemCount, 'categoryPublicId' => $category?->public_id,
            ]);

            return $itemCount;
        });
    }

    private function category(?string $publicId): ?Category
    {
        if ($publicId === null) {
            return null;
        }

        return $this->context->company()->categories()->where('public_id', $publicId)->first()
            ?? throw new ApiException(ApiErrorCode::CATEGORY_NOT_FOUND, 'La categoría no pertenece a la empresa.', 404);
    }

    /** @return array{0: CompanyTax, 1: CompanyTax} */
    private function resolve(string $fromPublicId, string $toPublicId): array
    {
        $taxes = $this->context->company()->taxes()->whereIn('public_id', [$fromPublicId, $toPublicId])->with('definition')->get()->keyBy('public_id');
        $from = $taxes->get($fromPublicId);
        $to = $taxes->get($toPublicId);
        if (! $from || ! $to) {
            throw new ApiException(ApiErrorCode::COMPANY_TAX_NOT_FOUND, 'Una de las tarifas no pertenece a la empresa.', 404);
        }
        if ($from->is($to) || $from->definition->tax_type !== $to->definition->tax_type || ! $to->isUsableOn(today())) {
            throw new ApiException(ApiErrorCode::TAX_REPLACEMENT_INVALID, 'Elige una tarifa de destino distinta, del mismo impuesto y vigente.', 422);
        }

        return [$from, $to];
    }
}
