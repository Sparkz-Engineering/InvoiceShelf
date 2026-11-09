@extends('app.pdf.reports.partials.layout')

@php
    $payable = $report['totals']['9'];
@endphp

@section('report-title', __('pdf_bas_title'))
@section('footer-label', $payable >= 0 ? __('pdf_bas_payable') : __('pdf_bas_refundable'))
@section('footer-value'){!! format_money_pdf(abs($payable) * 100, $currency) !!}@endsection

@section('report-body')
    @php
        $gstRows = ['G1' => 'pdf_bas_g1', '1A' => 'pdf_bas_1a', '1B' => 'pdf_bas_1b'];
        $paygRows = ['T1' => 'pdf_bas_t1', 'T2' => 'pdf_bas_t2', 'T7' => 'pdf_bas_t7', 'T11' => 'pdf_bas_t11'];
        $summaryRows = ['8A' => 'pdf_bas_8a', '8B' => 'pdf_bas_8b', '9' => 'pdf_bas_9'];
        $purchaseSections = [
            'expenses' => __('pdf_bas_purchases_expenses'),
            'bills' => __('pdf_bas_purchases_bills'),
            'refunds' => __('pdf_bas_purchases_refunds'),
        ];
        $checkLabels = [
            'receipt_missing' => __('pdf_bas_check_receipt_missing', ['amount' => strip_tags(format_money_pdf(\App\Domains\Reporting\Queries\BasQuery::TAX_INVOICE_THRESHOLD, $currency))]),
            'unallocated_payments' => __('pdf_bas_check_unallocated_payments'),
            'expenses_without_tax' => __('pdf_bas_check_expenses_without_tax'),
            'supplier_credits' => __('pdf_bas_check_supplier_credits'),
            'lodged_figures_changed' => __('pdf_bas_check_lodged_figures_changed'),
        ];
    @endphp

    <p class="report-muted">{{ $report['basis'] === 'accrual' ? __('pdf_bas_basis_accrual') : __('pdf_bas_basis_cash') }}</p>
    @if ($report['lodgement'])
        <p class="report-muted">{{ __('pdf_bas_lodged_on', ['date' => \Carbon\CarbonImmutable::parse($report['lodgement']['lodged_on'])->format($date_pattern)]) }}@if ($report['lodgement']['reference']) &middot; {{ $report['lodgement']['reference'] }}@endif</p>
    @endif

    <div class="report-section">
        <p class="report-section-heading">@lang('pdf_bas_gst_section')</p>
        <p class="report-muted">@lang('pdf_bas_cents_note')</p>
        <table class="report-table">
            <thead>
                <tr>
                    <th>@lang('pdf_bas_label')</th>
                    <th>@lang('pdf_bas_label_description')</th>
                    <th class="report-amount">@lang('pdf_bas_exact_amount')</th>
                    <th class="report-amount">@lang('pdf_bas_form_amount')</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($gstRows as $label => $key)
                    <tr>
                        <td>{{ $label }}</td>
                        <td>@lang($key)</td>
                        <td class="report-amount">{!! format_money_pdf($report['labels'][$label]['cents'], $currency) !!}</td>
                        <td class="report-amount">{{ number_format($report['labels'][$label]['dollars']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($report['payg']['method'] !== 'none')
        <div class="report-section">
            <p class="report-section-heading">@lang('pdf_bas_payg_section')</p>
            <table class="report-table">
                <tbody>
                    @foreach ($paygRows as $label => $key)
                        @if ($report['payg'][$label] !== null)
                            <tr>
                                <td>{{ $label }}</td>
                                <td>@lang($key)</td>
                                <td class="report-amount">{{ $label === 'T2' ? $report['payg'][$label].'%' : number_format($report['payg'][$label]) }}</td>
                            </tr>
                        @endif
                    @endforeach
                    <tr class="report-total-row">
                        <td class="report-total">5A</td>
                        <td class="report-total">@lang('pdf_bas_5a')</td>
                        <td class="report-amount report-total">{{ number_format($report['payg']['5A']) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    @endif

    <div class="report-section">
        <p class="report-section-heading">@lang('pdf_bas_summary_section')</p>
        <table class="report-table">
            <tbody>
                @foreach ($summaryRows as $label => $key)
                    <tr @if ($label === '9') class="report-total-row" @endif>
                        <td>{{ $label }}</td>
                        <td>@lang($key)</td>
                        <td class="report-amount">{{ number_format($report['totals'][$label]) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="report-section">
        <p class="report-section-heading">@lang('pdf_bas_reconciliation')</p>
        <table class="report-table">
            <tbody>
                <tr>
                    <td>@lang('pdf_bas_tax_on_receipts')</td>
                    <td class="report-amount">{!! format_money_pdf($report['purchases']['tax'], $currency) !!}</td>
                </tr>
                <tr>
                    <td>@lang('pdf_bas_private_use')</td>
                    <td class="report-amount">{!! format_money_pdf(-$report['purchases']['private_use'], $currency) !!}</td>
                </tr>
                <tr class="report-total-row">
                    <td class="report-total">1B &middot; @lang('pdf_bas_1b')</td>
                    <td class="report-amount report-total">{!! format_money_pdf($report['purchases']['claimable'], $currency) !!}</td>
                </tr>
            </tbody>
        </table>
    </div>

    @if ($report['checks'])
        <div class="report-section">
            <p class="report-section-heading">@lang('pdf_bas_checks')</p>
            <table class="report-table">
                <tbody>
                    @foreach ($report['checks'] as $check)
                        <tr>
                            <td>{{ $checkLabels[$check['key']] ?? $check['key'] }}</td>
                            <td class="report-amount">{{ $check['count'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="report-section">
        <p class="report-section-heading">@lang('pdf_bas_sales')</p>
        <table class="report-table">
            <thead>
                <tr>
                    <th>@lang('pdf_bas_date')</th>
                    <th>@lang('pdf_bas_reference')</th>
                    <th>@lang('pdf_bas_party')</th>
                    <th class="report-amount">@lang('pdf_amount_label')</th>
                    <th class="report-amount">@lang('pdf_bas_gst')</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($report['sales']['lines'] as $line)
                    <tr>
                        <td>{{ \Carbon\CarbonImmutable::parse($line['payment_date'] ?? $line['date'])->format($date_pattern) }}</td>
                        <td>{{ trim(($line['payment_number'] ?? '').' '.($line['invoice_number'] ?? '')) }}</td>
                        <td>{{ $line['customer'] }}</td>
                        <td class="report-amount">{!! format_money_pdf($line['amount'], $currency) !!}</td>
                        <td class="report-amount">{!! format_money_pdf($line['tax'], $currency) !!}</td>
                    </tr>
                @empty
                    <tr>
                        <td class="report-muted" colspan="5">@lang('pdf_report_no_records')</td>
                    </tr>
                @endforelse
                <tr class="report-total-row">
                    <td class="report-total" colspan="3">@lang('pdf_total')</td>
                    <td class="report-amount report-total">{!! format_money_pdf($report['sales']['total'], $currency) !!}</td>
                    <td class="report-amount report-total">{!! format_money_pdf($report['sales']['tax'], $currency) !!}</td>
                </tr>
            </tbody>
        </table>
    </div>

    @foreach ($purchaseSections as $section => $title)
        @if ($section === 'expenses' || $report['purchases'][$section])
            <div class="report-section">
                <p class="report-section-heading">{{ $title }}</p>
                <table class="report-table">
                    <thead>
                        <tr>
                            <th>@lang('pdf_bas_date')</th>
                            <th>@lang('pdf_bas_reference')</th>
                            <th>@lang('pdf_bas_party')</th>
                            <th class="report-amount">@lang('pdf_amount_label')</th>
                            <th class="report-amount">@lang('pdf_bas_gst')</th>
                            <th class="report-amount">@lang('pdf_bas_gst_claimed')</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($report['purchases'][$section] as $line)
                            <tr>
                                <td>{{ \Carbon\CarbonImmutable::parse($line['date'])->format($date_pattern) }}</td>
                                <td>{{ $line['number'] ?? trim(($line['payment_number'] ?? '').' '.($line['bill_number'] ?? '')) }}</td>
                                <td>{{ $line['supplier'] ?? ($line['category'] ?? '') }}</td>
                                <td class="report-amount">{!! format_money_pdf($line['amount'], $currency) !!}</td>
                                <td class="report-amount">{!! format_money_pdf($line['tax'], $currency) !!}</td>
                                <td class="report-amount">{!! format_money_pdf($line['claimable'], $currency) !!}</td>
                            </tr>
                        @empty
                            <tr>
                                <td class="report-muted" colspan="6">@lang('pdf_report_no_records')</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    @endforeach
@endsection
