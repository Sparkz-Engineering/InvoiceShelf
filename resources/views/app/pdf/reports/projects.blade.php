@extends('app.pdf.reports.partials.layout')

@section('report-title', __('pdf_projects_title'))
@section('footer-label', __('pdf_projects_profit'))
@section('footer-value'){!! format_money_pdf($report['totals']['profit'], $currency) !!}@endsection

@section('report-body')
    <p class="report-muted">@lang('pdf_projects_basis_note')</p>

    <div class="report-section">
        <table class="report-table">
            <thead>
                <tr>
                    <th>@lang('pdf_projects_project')</th>
                    <th class="report-amount">@lang('pdf_projects_income')</th>
                    <th class="report-amount">@lang('pdf_projects_costs')</th>
                    <th class="report-amount">@lang('pdf_projects_profit')</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($report['projects'] as $row)
                    <tr>
                        <td>{{ $row['project'] ?? __('pdf_projects_no_project') }}</td>
                        <td class="report-amount">{!! format_money_pdf($row['income'], $currency) !!}</td>
                        <td class="report-amount">{!! format_money_pdf($row['costs'], $currency) !!}</td>
                        <td class="report-amount">{!! format_money_pdf($row['profit'], $currency) !!}</td>
                    </tr>
                @empty
                    <tr>
                        <td class="report-muted" colspan="4">@lang('pdf_report_no_records')</td>
                    </tr>
                @endforelse
                <tr class="report-total-row">
                    <td class="report-total">@lang('pdf_total')</td>
                    <td class="report-amount report-total">{!! format_money_pdf($report['totals']['income'], $currency) !!}</td>
                    <td class="report-amount report-total">{!! format_money_pdf($report['totals']['costs'], $currency) !!}</td>
                    <td class="report-amount report-total">{!! format_money_pdf($report['totals']['profit'], $currency) !!}</td>
                </tr>
            </tbody>
        </table>
    </div>
@endsection
