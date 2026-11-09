<?php

namespace App\Domains\Reporting\Http\Controllers;

use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Reporting\Http\Requests\ProjectReportPdfRequest;
use App\Domains\Reporting\Queries\ProjectReportQuery;
use App\Platform\Http\Controller;
use App\Platform\Pdf\Facades\Pdf;
use App\Platform\Pdf\Rendering\PdfPageSetup;
use App\Platform\Pdf\Rendering\PdfTemplateUtils;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Income and costs per project for the company named by the hash, as a PDF
 * (or its HTML preview) or, with `format=csv`, as a spreadsheet.
 */
class ProjectReportController extends Controller
{
    public function __invoke(ProjectReportPdfRequest $request, string $hash, ProjectReportQuery $query)
    {
        $company = $request->company();

        App::setLocale(CompanySetting::getSetting('language', $company->id) ?: config('app.locale'));

        $report = $query->report(
            $company->id,
            $request->validated('from_date'),
            $request->validated('to_date'),
            $request->integer('invoice_field_id') ?: null,
            $request->integer('expense_field_id') ?: null,
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

        $view = PdfTemplateUtils::resolveView('reports', 'projects');
        view()->share($data);

        if ($request->exists('preview')) {
            return view($view, $data);
        }

        $document = Pdf::loadView($view, [], PdfPageSetup::forReports());

        return $request->exists('download') ? $document->download() : $document->stream();
    }

    private function csv(array $report): StreamedResponse
    {
        $filename = "projects-{$report['from_date']}-{$report['to_date']}.csv";

        return response()->streamDownload(function () use ($report): void {
            $out = fopen('php://output', 'w');
            $money = fn (int $cents): string => number_format($cents / 100, 2, '.', '');

            fputcsv($out, [__('pdf_projects_project'), __('pdf_projects_income'), __('pdf_projects_costs'), __('pdf_projects_profit')]);

            foreach ($report['projects'] as $row) {
                fputcsv($out, [
                    $row['project'] ?? __('pdf_projects_no_project'),
                    $money($row['income']),
                    $money($row['costs']),
                    $money($row['profit']),
                ]);
            }

            fputcsv($out, [__('pdf_total'), $money($report['totals']['income']), $money($report['totals']['costs']), $money($report['totals']['profit'])]);

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
