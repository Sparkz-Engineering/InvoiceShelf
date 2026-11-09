@extends('app.pdf.reports.partials.layout')

@section('report-title', __('pdf_deductions_title'))
@section('footer-label', __('pdf_deductions_total'))
@section('footer-value'){!! format_money_pdf($report['totals']['total'], $currency) !!}@endsection

@section('report-body')
    @php
        $categoryLabels = [
            'home_office' => __('pdf_deductions_category_home_office'),
            'vehicle' => __('pdf_deductions_category_vehicle'),
            'depreciation' => __('pdf_deductions_category_depreciation'),
            'travel' => __('pdf_deductions_category_travel'),
            'professional_fees' => __('pdf_deductions_category_professional_fees'),
            'education' => __('pdf_deductions_category_education'),
            'insurance' => __('pdf_deductions_category_insurance'),
            'phone_internet' => __('pdf_deductions_category_phone_internet'),
            'other' => __('pdf_deductions_category_other'),
        ];
    @endphp

    <p class="report-muted">@lang('pdf_deductions_basis_note')</p>

    <div class="report-section">
        <p class="report-section-heading">@lang('pdf_deductions_summary')</p>
        <table class="report-table">
            <tbody>
                <tr>
                    <td>@lang('pdf_deductions_from_expenses')</td>
                    <td class="report-amount">{!! format_money_pdf($report['totals']['expenses'], $currency) !!}</td>
                </tr>
                <tr>
                    <td>@lang('pdf_deductions_other')</td>
                    <td class="report-amount">{!! format_money_pdf($report['totals']['other'], $currency) !!}</td>
                </tr>
                <tr class="report-total-row">
                    <td class="report-total">@lang('pdf_deductions_total')</td>
                    <td class="report-amount report-total">{!! format_money_pdf($report['totals']['total'], $currency) !!}</td>
                </tr>
            </tbody>
        </table>
        @if ($report['not_deductible']['count'])
            <p class="report-muted">{{ __('pdf_deductions_not_deductible_note', ['count' => $report['not_deductible']['count']]) }} {!! format_money_pdf($report['not_deductible']['amount'], $currency) !!}</p>
        @endif
    </div>

    <div class="report-section">
        <p class="report-section-heading">@lang('pdf_deductions_from_expenses')</p>
        <table class="report-table">
            <thead>
                <tr>
                    <th>@lang('pdf_bas_date')</th>
                    <th>@lang('pdf_deductions_description')</th>
                    <th class="report-amount">@lang('pdf_deductions_cost_excluding_tax')</th>
                    <th class="report-amount">@lang('pdf_deductions_business_use')</th>
                    <th class="report-amount">@lang('pdf_deductions_deductible')</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($report['expenses'] as $category)
                    <tr>
                        <td class="report-total" colspan="4">{{ $category['name'] }}</td>
                        <td class="report-amount report-total">{!! format_money_pdf($category['total'], $currency) !!}</td>
                    </tr>
                    @foreach ($category['lines'] as $line)
                        <tr>
                            <td>{{ \Carbon\CarbonImmutable::parse($line['date'])->format($date_pattern) }}</td>
                            <td>
                                {{ trim(($line['number'] ? $line['number'].' · ' : '').($line['supplier'] ?? $line['notes'] ?? '')) }}
                                @if ($line['deduction_note'])
                                    <br><span class="report-muted">{{ $line['deduction_note'] }}</span>
                                @endif
                            </td>
                            <td class="report-amount">{!! format_money_pdf($line['net'], $currency) !!}</td>
                            <td class="report-amount">{{ rtrim(rtrim(number_format($line['business_use_percent'], 2), '0'), '.') }}%</td>
                            <td class="report-amount">{!! format_money_pdf($line['deductible'], $currency) !!}</td>
                        </tr>
                    @endforeach
                @empty
                    <tr>
                        <td class="report-muted" colspan="5">@lang('pdf_report_no_records')</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($report['other'])
        <div class="report-section">
            <p class="report-section-heading">@lang('pdf_deductions_other')</p>
            <table class="report-table">
                <thead>
                    <tr>
                        <th>@lang('pdf_bas_date')</th>
                        <th>@lang('pdf_deductions_description')</th>
                        <th class="report-amount">@lang('pdf_deductions_deductible')</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['other'] as $group)
                        <tr>
                            <td class="report-total" colspan="2">{{ $categoryLabels[$group['category']] ?? $group['category'] }}</td>
                            <td class="report-amount report-total">{!! format_money_pdf($group['total'], $currency) !!}</td>
                        </tr>
                        @foreach ($group['lines'] as $line)
                            <tr>
                                <td>{{ \Carbon\CarbonImmutable::parse($line['date'])->format($date_pattern) }}</td>
                                <td>
                                    {{ $line['description'] }}
                                    @if ($line['notes'])
                                        <br><span class="report-muted">{{ $line['notes'] }}</span>
                                    @endif
                                </td>
                                <td class="report-amount">{!! format_money_pdf($line['amount'], $currency) !!}</td>
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
