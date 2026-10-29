<?php

namespace App\Domains\Integrations\Actions;

use App\Domains\Catalog\Models\FiscalPaymentMethod;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Customers\Actions\CreateCustomerAction;
use App\Domains\Customers\Actions\EnsureFinalConsumerAction;
use App\Domains\Customers\Enums\IdentificationType;
use App\Domains\Customers\Models\Customer;
use App\Domains\Customers\Services\CustomerIdentificationService;
use App\Domains\FiscalDocuments\Actions\IssueSaleInvoiceAction;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Integrations\Models\ApiClient;
use App\Domains\Sales\Actions\CreateSaleDraftAction;
use App\Domains\Sales\DTOs\SaleDraftData;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Services\SaleQuoteService;
use App\Models\User;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/invoices — a module (Taller, Lavadora…) invoices in one call. Items are referenced by
 * their catalog code, the buyer by its identification (created when new, CONSUMIDOR FINAL when absent)
 * and payments by their SRI code. The Idempotency-Key makes a repeated call return the same invoice
 * instead of a second one. The sale then follows exactly the same path as one typed in Ventas.
 */
final class IssueModuleInvoiceAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly CustomerIdentificationService $identifications,
        private readonly CreateCustomerAction $createCustomer,
        private readonly EnsureFinalConsumerAction $finalConsumer,
        private readonly SaleQuoteService $quotes,
        private readonly CreateSaleDraftAction $createSale,
        private readonly IssueSaleInvoiceAction $issue,
    ) {}

    /** @return array{sale: Sale, invoice: ?ElectronicDocument, error: ?ApiException, replayed: bool} */
    public function execute(ApiClient $client, string $idempotencyKey, array $data, User $actor): array
    {
        $existing = $this->existing($client, $idempotencyKey);
        if ($existing) {
            if (($data['sourceReference'] ?? null) !== $existing->source_reference) {
                throw new ApiException(ApiErrorCode::IDEMPOTENCY_KEY_REUSED, 'La clave de idempotencia ya se usó para otra referencia.', 409, ['salePublicId' => $existing->public_id]);
            }

            return ['sale' => $existing, 'invoice' => $existing->electronicDocument, 'error' => null, 'replayed' => true];
        }

        $draft = [
            'customerPublicId' => $this->customer($data['customer'] ?? null, $actor)->public_id,
            'lines' => $this->lines($data['lines']),
            'notes' => $data['notes'] ?? null,
            'tipAmount' => $data['tipAmount'] ?? '0.00',
        ];
        $draft['paymentMethods'] = $this->payments($data['payments'] ?? [], $this->quotes->quote($draft)['total']);

        try {
            $sale = DB::transaction(function () use ($client, $idempotencyKey, $data, $draft, $actor): Sale {
                $sale = $this->createSale->execute(SaleDraftData::fromValidated($draft), $actor);
                $sale->forceFill([
                    'api_client_id' => $client->id,
                    'source_module' => $client->module,
                    'source_reference' => $data['sourceReference'] ?? null,
                    'idempotency_key' => $idempotencyKey,
                ])->save();

                return $sale;
            });
        } catch (UniqueConstraintViolationException) {
            // Two simultaneous calls with the same key: the second one returns the first one's sale.
            $sale = $this->existing($client, $idempotencyKey) ?? throw new ApiException(ApiErrorCode::IDEMPOTENCY_KEY_REUSED, 'La clave de idempotencia está en uso.', 409);

            return ['sale' => $sale, 'invoice' => $sale->electronicDocument, 'error' => null, 'replayed' => true];
        }

        try {
            $invoice = $this->issue->execute($sale, $actor);
            $error = null;
        } catch (ApiException $exception) {
            // The sale is kept: the module may retry with the same key or the user finishes it in Ventas.
            $invoice = ElectronicDocument::query()->where('sale_id', $sale->id)->first();
            $error = $exception;
        }

        return ['sale' => $sale->refresh(), 'invoice' => $invoice, 'error' => $error, 'replayed' => false];
    }

    private function existing(ApiClient $client, string $key): ?Sale
    {
        return $this->context->company()->sales()->where('api_client_id', $client->id)->where('idempotency_key', $key)->with('electronicDocument')->first();
    }

    private function customer(?array $buyer, User $actor): Customer
    {
        if ($buyer === null || ($buyer['identification'] ?? '') === CustomerIdentificationService::FINAL_CONSUMER_IDENTIFICATION) {
            return $this->finalConsumer->execute($actor);
        }
        $identification = strtoupper(trim((string) $buyer['identification']));
        $type = isset($buyer['identificationType']) ? IdentificationType::from($buyer['identificationType']) : $this->identifications->detect($identification);
        if ($type === null) {
            throw new ApiException(ApiErrorCode::INVALID_CUSTOMER_IDENTIFICATION, 'La identificación del cliente no es una cédula ni un RUC válido; envía identificationType para pasaporte o exterior.', 422);
        }
        $customer = $this->context->company()->customers()->where('identification_type', $type->value)->where('identification', $identification)->first();
        if ($customer) {
            if (! $customer->is_active) {
                $customer->forceFill(['is_active' => true])->save();
            }

            return $customer;
        }

        return $this->createCustomer->execute([
            'identification_type' => $type->value, 'identification' => $identification,
            'legal_name' => (string) $buyer['legalName'], 'email' => $buyer['email'] ?? null,
            'address' => $buyer['address'] ?? null, 'phone' => $buyer['phone'] ?? null,
        ], $actor);
    }

    /** @return list<array{itemPublicId: string, quantity: string, discountAmount: string}> */
    private function lines(array $lines): array
    {
        $codes = array_values(array_unique(array_column($lines, 'itemCode')));
        $items = $this->context->company()->items()->whereIn('internal_code', $codes)->where('is_active', true)->pluck('public_id', 'internal_code');
        $missing = array_values(array_diff($codes, $items->keys()->all()));
        if ($missing !== []) {
            throw new ApiException(ApiErrorCode::ITEM_NOT_FOUND, 'Hay códigos que no existen o están inactivos en el catálogo de FACSTO.', 422, ['itemCodes' => $missing]);
        }

        return array_map(fn (array $line): array => [
            'itemPublicId' => $items[$line['itemCode']],
            'quantity' => (string) $line['quantity'],
            'discountAmount' => (string) ($line['discountAmount'] ?? '0'),
        ], $lines);
    }

    /** @return list<array<string, mixed>> without payments the whole total goes to code 01 (sin sistema financiero) */
    private function payments(array $payments, string $total): array
    {
        $payments = $payments === [] ? [['code' => '01', 'amount' => $total]] : $payments;
        $methods = FiscalPaymentMethod::query()->whereIn('code', array_column($payments, 'code'))->where('is_active', true)
            ->whereDate('valid_from', '<=', today())
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()))
            ->pluck('public_id', 'code');

        return array_map(function (array $payment) use ($methods, $total, $payments): array {
            $method = $methods[$payment['code']] ?? throw new ApiException(ApiErrorCode::FISCAL_PAYMENT_METHOD_NOT_FOUND, "La forma de pago {$payment['code']} no está vigente en la tabla del SRI.", 422);

            return [
                'fiscalPaymentMethodPublicId' => $method,
                'amount' => (string) ($payment['amount'] ?? (count($payments) === 1 ? $total : '0')),
                'reference' => $payment['reference'] ?? null,
                'term' => isset($payment['term']) ? (string) $payment['term'] : null,
                'timeUnit' => $payment['timeUnit'] ?? null,
            ];
        }, $payments);
    }
}
