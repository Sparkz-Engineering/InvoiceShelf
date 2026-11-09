<?php

namespace App\Domains\Reporting\Http\Controllers;

use App\Domains\Accounts\Models\Company;
use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Money\Models\Currency;
use App\Domains\Purchases\Models\Expense;
use App\Domains\Receivables\Models\Payment;
use App\Domains\Reporting\Queries\CashTaxQuery;
use App\Domains\Reporting\Queries\PurchasesQuery;
use App\Platform\Http\Controller;
use App\Platform\Pdf\Facades\Pdf;
use App\Platform\Pdf\Rendering\PdfPageSetup;
use App\Platform\Pdf\Rendering\PdfTemplateUtils;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Silber\Bouncer\BouncerFacade;

/**
 * Money in against money out over a period.
 *
 * Income is what was actually received in the window rather than what was
 * billed, so an unpaid invoice contributes nothing; the other side is spending
 * rolled up per category, each with a count and a sum. The two figures are
 * handed over side by side and the template is what subtracts one from the
 * other.
 */
class ProfitLossReportController extends Controller
{
    /**
     * Render the report for the company the hash names.
     *
     * @param  string  $hash
     */
    public function __invoke(Request $request, $hash)
    {
        $company = $this->reportedCompany($hash);

        App::setLocale(CompanySetting::getSetting('language', $company->id));

        $window = $request->only(['from_date', 'to_date']);

        $received = Payment::query()
            ->whereCompanyId($company->id)
            ->applyFilters($window)
            ->sum('base_amount');

        $spending = Expense::query()
            ->with('category')
            ->whereCompanyId($company->id)
            ->applyFilters($window)
            ->expensesAttributes()
            ->get();

        $cash = app(PurchasesQuery::class)->cash(
            $company->id,
            $window['from_date'] ?? null,
            $window['to_date'] ?? null,
        );

        $excludeTax = $request->boolean('exclude_tax') && isset($window['from_date'], $window['to_date']);

        if ($excludeTax) {
            [$received, $spending, $cash] = $this->withoutTax($company->id, $window['from_date'], $window['to_date'], $received, $cash);
        }

        foreach (['supplier_payments' => 1, 'supplier_refunds' => -1] as $key => $sign) {
            if ($cash[$key]) {
                $spending->push((object) [
                    'category' => (object) ['name' => __('pdf_'.$key.'_label')],
                    'total_amount' => $cash[$key] * $sign,
                ]);
            }
        }

        view()->share([
            'excludeTax' => $excludeTax,
            'income' => $received,
            'expenseCategories' => $spending,
            'totalExpense' => $spending->sum('total_amount'),
        ] + $this->pageChrome($request, $company));

        return $this->emit($request, 'profit-loss');
    }

    /**
     * The same figures without GST, for an income tax return: the tax inside
     * each payment received against an invoice comes off income, and the tax
     * on each receipt and paid bill comes off spending. Money received but not
     * yet set against an invoice stays as it is, since nobody can yet say how
     * much of it is tax.
     *
     * @return array{0: int, 1: Collection<int, object>, 2: array<string, int>}
     */
    private function withoutTax(int $companyId, string $from, string $to, int $received, array $cash): array
    {
        $tax = app(CashTaxQuery::class);
        $paid = $tax->paid($companyId, $from, $to);

        $spending = collect($paid['expenses'])
            ->groupBy(fn (array $line) => (string) $line['category'])
            ->map(fn ($lines, string $name): object => (object) [
                'category' => (object) ['name' => $name],
                'total_amount' => $lines->sum(fn (array $line): int => $line['amount'] - $line['tax']),
            ])
            ->values();

        $cash['supplier_payments'] -= array_sum(array_column($paid['bills'], 'tax'));
        $cash['supplier_refunds'] += array_sum(array_column($paid['refunds'], 'tax'));

        return [
            $received - $tax->received($companyId, $from, $to)['tax'],
            $spending,
            $cash,
        ];
    }

    /**
     * The company named by the hash, once the caller has been let through.
     *
     * Nothing upstream tells Bouncer which company to weigh abilities against:
     * these links carry no company header, and the report ability is stored
     * per company, so the unscoped check matched nothing and every report
     * answered 403. Pointing the scope at the company in the URL settles that
     * without widening access, because the policy still asks for membership.
     * The hash is an address, not a credential.
     *
     * @param  string  $hash
     */
    private function reportedCompany($hash): Company
    {
        $company = Company::query()->where('unique_hash', $hash)->firstOrFail();

        BouncerFacade::scope()->to($company->id);

        $this->authorize('view report', $company);

        return $company;
    }

    /**
     * What every report prints around its figures: the company and its logo,
     * the window in the company's own date format, and the currency the
     * amounts are stated in.
     *
     * @return array<string, mixed>
     */
    private function pageChrome(Request $request, Company $company): array
    {
        $pattern = CompanySetting::getSetting('carbon_date_format', $company->id);
        $opened = Carbon::createFromFormat('Y-m-d', $request->from_date)->translatedFormat($pattern);
        $closed = Carbon::createFromFormat('Y-m-d', $request->to_date)->translatedFormat($pattern);
        $currencyId = CompanySetting::getSetting('currency', $company->id);
        $currency = Currency::findOrFail($currencyId);

        return [
            'company' => $company,
            'logo' => $company->logo_path,
            'from_date' => $opened,
            'to_date' => $closed,
            'currency' => $currency,
        ];
    }

    /**
     * Hand the rendered report over in whichever of the three shapes the query
     * string asks for.
     *
     * Reports have no template chooser, so an override is a file of the same
     * name dropped into storage/app/templates/pdf/reports/, which the resolver
     * prefers over the built-in one.
     *
     * The document is built before the preview branch is taken and not after:
     * a preview costs a full render it never uses, which is wasteful but is
     * also what the templates have always been exercised through.
     */
    private function emit(Request $request, string $design)
    {
        $design = PdfTemplateUtils::resolveView('reports', $design);

        $document = Pdf::loadView($design, [], PdfPageSetup::forReports());

        if ($request->exists('preview')) {
            return view($design);
        }

        return $request->exists('download') ? $document->download() : $document->stream();
    }
}
