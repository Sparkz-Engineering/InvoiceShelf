<?php

namespace App\Domains\Reporting\Http\Controllers;

use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Reporting\Http\Requests\BasReportPdfRequest;
use App\Domains\Reporting\Queries\BasQuery;
use App\Platform\Http\Controller;
use App\Platform\Pdf\Facades\Pdf;
use App\Platform\Pdf\Rendering\PdfPageSetup;
use App\Platform\Pdf\Rendering\PdfTemplateUtils;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The BAS worksheet for the company named by the hash, as a PDF (or its HTML
 * preview) or, with `format=csv`, as a spreadsheet of the figures and every
 * row behind them, for an accountant or for keeping with the records.
 */
class BasReportController extends Controller
{
    public function __invoke(BasReportPdfRequest $request, string $hash, BasQuery $query)
    {
        $company = $request->company();

        App::setLocale(CompanySetting::getSetting('language', $company->id) ?: config('app.locale'));

        $report = $query->report(
            $company->id,
            $request->validated('from_date'),
            $request->validated('to_date'),
            $request->overrides(),
        );

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

        $view = PdfTemplateUtils::resolveView('reports', 'bas');
        view()->share($data);

        if ($request->exists('preview')) {
            return view($view, $data);
        }

        $document = Pdf::loadView($view, [], PdfPageSetup::forReports());

        return $request->exists('download') ? $document->download() : $document->stream();
    }

    /**
     * The worksheet as CSV: the labels first, then the rows behind them.
     */
    private function csv(array $report): StreamedResponse
    {
        $filename = "bas-{$report['from_date']}-{$report['to_date']}.csv";

        return response()->streamDownload(function () use ($report): void {
            $out = fopen('php://output', 'w');
            $money = fn (?int $cents): string => $cents === null ? '' : number_format($cents / 100, 2, '.', '');

            fputcsv($out, [__('pdf_bas_label'), __('pdf_bas_label_description'), __('pdf_bas_exact_amount'), __('pdf_bas_form_amount')]);

            foreach (['G1', '1A', '1B'] as $label) {
                fputcsv($out, [$label, __('pdf_bas_'.strtolower($label)), $money($report['labels'][$label]['cents']), $report['labels'][$label]['dollars']]);
            }

            foreach (['T1', 'T2', 'T7', 'T11'] as $label) {
                if ($report['payg'][$label] !== null) {
                    fputcsv($out, [$label, __('pdf_bas_'.strtolower($label)), '', $report['payg'][$label]]);
                }
            }

            fputcsv($out, ['5A', __('pdf_bas_5a'), '', $report['payg']['5A']]);

            foreach (['8A', '8B', '9'] as $label) {
                fputcsv($out, [$label, __('pdf_bas_'.strtolower($label)), '', $report['totals'][$label]]);
            }

            fputcsv($out, []);
            fputcsv($out, [__('pdf_bas_section'), __('pdf_date'), __('pdf_bas_reference'), __('pdf_bas_party'), __('pdf_amount_label'), __('pdf_bas_gst'), __('pdf_bas_gst_claimed')]);

            foreach ($report['sales']['lines'] as $line) {
                fputcsv($out, [
                    __('pdf_bas_sales'),
                    $line['payment_date'] ?? $line['date'],
                    trim(($line['payment_number'] ?? '').' '.($line['invoice_number'] ?? '')),
                    $line['customer'],
                    $money($line['amount']),
                    $money($line['tax']),
                    '',
                ]);
            }

            $purchaseRows = [
                'expenses' => fn (array $line) => $line['number'] ?: $line['category'],
                'bills' => fn (array $line) => trim(($line['payment_number'] ?? '').' '.$line['bill_number']),
                'refunds' => fn (array $line) => $line['number'] ?? '',
            ];

            foreach ($purchaseRows as $section => $reference) {
                foreach ($report['purchases'][$section] as $line) {
                    fputcsv($out, [
                        __('pdf_bas_purchases_'.$section),
                        $line['date'],
                        $reference($line),
                        $line['supplier'] ?? '',
                        $money($line['amount']),
                        $money($line['tax']),
                        $money($line['claimable']),
                    ]);
                }
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
