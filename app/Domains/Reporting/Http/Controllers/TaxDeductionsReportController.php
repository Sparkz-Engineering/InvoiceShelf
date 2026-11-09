<?php

namespace App\Domains\Reporting\Http\Controllers;

use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Reporting\Http\Requests\TaxDeductionsPdfRequest;
use App\Domains\Reporting\Queries\TaxDeductionsQuery;
use App\Platform\Http\Controller;
use App\Platform\Pdf\Facades\Pdf;
use App\Platform\Pdf\Rendering\PdfPageSetup;
use App\Platform\Pdf\Rendering\PdfTemplateUtils;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A period's tax deductions for the company named by the hash, as a PDF (or
 * its HTML preview) or, with `format=csv`, as a spreadsheet, for the tax
 * return or the accountant.
 */
class TaxDeductionsReportController extends Controller
{
    public function __invoke(TaxDeductionsPdfRequest $request, string $hash, TaxDeductionsQuery $query)
    {
        $company = $request->company();

        App::setLocale(CompanySetting::getSetting('language', $company->id) ?: config('app.locale'));

        $report = $query->report($company->id, $request->validated('from_date'), $request->validated('to_date'));

        if ($request->validated('format') === 'csv') {
            return $this->csv($report);
        }

        $pattern = CompanySetting::getSetting('carbon_date_format', $company->id) ?: 'Y-m-d';

        $data = [
            'company' => $company,
            'logo' => $company->logo_path,
            'currency' => $report['currency'],
            'report' => $report,
            'from_date' => CarbonImmutable::parse($report['from_date'])->translatedFormat($pattern),
            'to_date' => CarbonImmutable::parse($report['to_date'])->translatedFormat($pattern),
            'date_pattern' => $pattern,
        ];

        $view = PdfTemplateUtils::resolveView('reports', 'tax-deductions');
        view()->share($data);

        if ($request->exists('preview')) {
            return view($view, $data);
        }

        $document = Pdf::loadView($view, [], PdfPageSetup::forReports());

        return $request->exists('download') ? $document->download() : $document->stream();
    }

    private function csv(array $report): StreamedResponse
    {
        $filename = "tax-deductions-{$report['from_date']}-{$report['to_date']}.csv";

        return response()->streamDownload(function () use ($report): void {
            $out = fopen('php://output', 'w');
            $money = fn (int $cents): string => number_format($cents / 100, 2, '.', '');

            fputcsv($out, [
                __('pdf_deductions_kind'), __('pdf_deductions_category'), __('pdf_bas_date'), __('pdf_bas_reference'),
                __('pdf_deductions_description'), __('pdf_deductions_cost_excluding_tax'), __('pdf_deductions_business_use'),
                __('pdf_deductions_deductible'), __('pdf_deductions_note'),
            ]);

            foreach ($report['expenses'] as $category) {
                foreach ($category['lines'] as $line) {
                    fputcsv($out, [
                        __('pdf_deductions_from_expenses'),
                        $category['name'],
                        $line['date'],
                        $line['number'],
                        trim(($line['supplier'] ?? '').' '.($line['notes'] ?? '')),
                        $money($line['net']),
                        $line['business_use_percent'].'%',
                        $money($line['deductible']),
                        $line['deduction_note'],
                    ]);
                }
            }

            foreach ($report['other'] as $group) {
                foreach ($group['lines'] as $line) {
                    fputcsv($out, [
                        __('pdf_deductions_other'),
                        __('pdf_deductions_category_'.$group['category']),
                        $line['date'],
                        '',
                        $line['description'],
                        '',
                        '',
                        $money($line['amount']),
                        $line['notes'],
                    ]);
                }
            }

            fputcsv($out, []);
            fputcsv($out, [__('pdf_total'), '', '', '', '', '', '', $money($report['totals']['total']), '']);

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
