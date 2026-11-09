<?php

namespace App\Domains\Purchases\Http\Resources;

use App\Domains\Accounts\Http\Resources\CompanyResource;
use App\Domains\Accounts\Http\Resources\UserResource;
use App\Domains\Contacts\Http\Resources\CustomerResource;
use App\Domains\Metadata\Http\Resources\CustomFieldValueResource;
use App\Domains\Money\Http\Resources\CurrencyResource;
use App\Domains\Purchases\Application\ExpenseTaxPosition;
use App\Domains\Purchases\Models\Expense;
use App\Domains\Receivables\Http\Resources\PaymentMethodResource;
use App\Domains\Taxation\Http\Resources\TaxResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A money-out record as the admin API publishes it.
 *
 * The stored columns come first, with the receipt threaded through the middle
 * of them. The file is reported three ways: a link the browser can follow
 * together with its kind, the path it occupies on disk, and the whole media
 * row behind it. All three ask the media library for the same first file, so
 * a serialised row costs three lookups even when no receipt was ever attached
 * and every one of them answers null.
 *
 * Alongside those sit two dates rendered through the owning company's date
 * format, each of which reads that company's settings as it goes.
 *
 * The associations are gated two different ways, and the difference is
 * deliberate. Purchase taxes are published only if the caller eager loaded
 * them, so a listing that does not ask for them omits the key entirely and a
 * detail response that does ask carries the rows. Everything after that is
 * gated on an existence probe run against the database, so those associations
 * are correct whether or not anything was eager loaded -- at the price of one
 * query apiece per serialised row, plus a second to fetch the record once the
 * probe says there is one. Both shapes are the payload's established contract
 * and are kept as they are.
 */
class ExpenseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     */
    public function toArray($request): array
    {
        $expense = $this->resource;

        return [
            'id' => $expense->id,
            'expense_date' => $expense->expense_date,
            'expense_number' => $expense->expense_number,
            'amount' => $expense->amount,
            'notes' => $expense->notes,
            'customer_id' => $expense->customer_id,
            'supplier_id' => $expense->supplier_id,
            'attachment_receipt_url' => $expense->receipt_url,
            'attachment_receipt' => $expense->receipt,
            'attachment_receipt_meta' => $expense->receipt_meta,
            'company_id' => $expense->company_id,
            'expense_category_id' => $expense->expense_category_id,
            'creator_id' => $expense->creator_id,
            'formatted_expense_date' => $expense->formattedExpenseDate,
            'formatted_created_at' => $expense->formattedCreatedAt,
            'exchange_rate' => $expense->exchange_rate,
            'currency_id' => $expense->currency_id,
            'base_amount' => $expense->base_amount,
            'payment_method_id' => $expense->payment_method_id,
            'tax_included' => (bool) $expense->tax_included,
            'business_use_percent' => (float) $expense->business_use_percent,
            'tax_deductible' => (bool) $expense->tax_deductible,
            'deduction_note' => $expense->deduction_note,
            ...$this->taxPosition($expense),
            'taxes' => TaxResource::collection($this->whenLoaded('taxes')),
            'supplier' => $this->whenLoaded(
                'supplier',
                fn () => $expense->supplier ? new SupplierResource($expense->supplier) : null
            ),
            'customer' => $this->when(
                $expense->customer()->exists(),
                fn () => new CustomerResource($expense->customer)
            ),
            'expense_category' => $this->when(
                $expense->category()->exists(),
                fn () => new ExpenseCategoryResource($expense->category)
            ),
            'creator' => $this->when(
                $expense->creator()->exists(),
                fn () => new UserResource($expense->creator)
            ),
            'fields' => $this->when(
                $expense->fields()->exists(),
                fn () => CustomFieldValueResource::collection($expense->fields)
            ),
            'company' => $this->when(
                $expense->company()->exists(),
                fn () => new CompanyResource($expense->company)
            ),
            'currency' => $this->when(
                $expense->currency()->exists(),
                fn () => new CurrencyResource($expense->currency)
            ),
            'payment_method' => $this->when(
                $expense->paymentMethod()->exists(),
                fn () => new PaymentMethodResource($expense->paymentMethod)
            ),
        ];
    }

    /**
     * The tax on the receipt and what can be claimed of it, in the expense's
     * own currency.
     *
     * A listing sums the tax in its query (`tax_total`), a detail response
     * has the rows loaded; anything else asks the database once.
     *
     * @return array{tax_total: int, net_amount: int, claimable_tax: int, deductible_amount: int}
     */
    private function taxPosition(Expense $expense): array
    {
        $summed = $expense->getAttribute('tax_total');

        $taxTotal = match (true) {
            $summed !== null => (int) $summed,
            $expense->relationLoaded('taxes') => (int) $expense->taxes->sum('amount'),
            default => (int) $expense->taxes()->sum('amount'),
        };

        $position = ExpenseTaxPosition::from(
            (int) $expense->amount,
            $taxTotal,
            $expense->business_use_percent,
            (bool) $expense->tax_deductible,
        );

        return [
            'tax_total' => $taxTotal,
            'net_amount' => $position->net(),
            'claimable_tax' => $position->claimableTax(),
            'deductible_amount' => $position->deductibleAmount(),
        ];
    }
}
