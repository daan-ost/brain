<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ __('invoice.invoice') }} {{ $invoice_number }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', Arial, sans-serif;
            font-size: 9pt;
            color: #333;
            line-height: 1.3;
        }

        .container {
            padding: 40px;
            max-width: 800px;
            margin: 0 auto;
        }

        /* Header */
        .header {
            display: table;
            width: 100%;
            margin-bottom: 25px;
            border-bottom: 3px solid #2C8ABF;
            padding-bottom: 15px;
        }

        .logo {
            display: table-cell;
            width: 30%;
            vertical-align: top;
        }

        .logo img {
            max-width: 120px;
            height: auto;
        }

        .invoice-title {
            display: table-cell;
            width: 70%;
            text-align: right;
            vertical-align: top;
        }

        .invoice-title h1 {
            font-size: 24pt;
            color: #2C8ABF;
            margin-bottom: 3px;
            font-weight: 700;
        }

        .invoice-number {
            font-size: 10pt;
            color: #666;
        }

        /* Company and Customer Info */
        .info-section {
            display: table;
            width: 100%;
            margin-bottom: 20px;
        }

        .company-info, .customer-info {
            display: table-cell;
            width: 48%;
            vertical-align: top;
        }

        .customer-info {
            text-align: right;
        }

        .info-label {
            font-size: 8pt;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
            font-weight: 600;
        }

        .info-content {
            font-size: 9pt;
            line-height: 1.4;
        }

        .info-content strong {
            font-weight: 600;
        }

        /* Invoice Details */
        .invoice-details {
            background-color: #f8f9fa;
            padding: 10px 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }

        .invoice-details table {
            width: 100%;
        }

        .invoice-details td {
            padding: 3px 0;
            font-size: 9pt;
        }

        .invoice-details td:first-child {
            color: #666;
            font-weight: 600;
        }

        .invoice-details td:last-child {
            text-align: right;
        }

        /* Line Items Table */
        .line-items {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }

        .line-items thead {
            background-color: #2C8ABF;
            color: white;
        }

        .line-items thead th {
            padding: 8px 8px;
            text-align: left;
            font-size: 9pt;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .line-items thead th:last-child,
        .line-items tbody td:last-child,
        .line-items tfoot td:last-child {
            text-align: right;
        }

        .line-items tbody td {
            padding: 8px 8px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 9pt;
        }

        .line-items tbody tr:last-child td {
            border-bottom: 2px solid #d1d5db;
        }

        /* Totals Section */
        .totals {
            float: right;
            width: 300px;
            margin-top: 10px;
        }

        .totals table {
            width: 100%;
            border-collapse: collapse;
        }

        .totals td {
            padding: 6px 8px;
            font-size: 9pt;
        }

        .totals td:first-child {
            text-align: left;
            color: #666;
        }

        .totals td:last-child {
            text-align: right;
            font-weight: 600;
        }

        .totals .total-row {
            background-color: #2C8ABF;
            color: white !important;
            font-size: 12pt;
            font-weight: 700;
        }

        .totals .total-row td {
            padding: 12px 10px;
            color: white !important;
        }

        /* Notes */
        .notes {
            clear: both;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            font-size: 9pt;
            color: #666;
        }

        .notes p {
            margin-bottom: 8px;
        }

        /* Footer */
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #e5e7eb;
            text-align: center;
            font-size: 9pt;
            color: #999;
        }

        .footer-content {
            margin-bottom: 10px;
        }

        .footer-thank-you {
            font-size: 11pt;
            color: #2C8ABF;
            font-weight: 600;
            margin-bottom: 15px;
        }

        /* Utilities */
        .text-muted {
            color: #999;
        }

        .text-bold {
            font-weight: 600;
        }

        .clearfix {
            clear: both;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="logo">
                <img src="{{ public_path('favicon.svg') }}" alt="{{ $company['name'] }}">
            </div>
            <div class="invoice-title">
                <h1>{{ __('invoice.invoice') }}</h1>
                <div class="invoice-number" style="margin-bottom: 2px;">
                    <strong>{{ __('invoice.invoice_date') }}:</strong> {{ format_date(\Carbon\Carbon::parse($invoice_date), $invoice_user ?? null) }}
                </div>
                <div class="invoice-number" style="margin-bottom: 2px;">
                    <strong>{{ __('invoice.invoice_number') }}:</strong> {{ $invoice_number }}
                </div>
                @if($order->id)
                <div class="invoice-number">
                    <strong>{{ __('invoice.order_reference') }}:</strong> {{ $order->id }}
                </div>
                @endif
            </div>
        </div>

        <!-- Company and Customer Info -->
        <div class="info-section">
            <div class="company-info">
                <div class="info-label">{{ __('invoice.bill_from') }}</div>
                <div class="info-content">
                    <strong>{{ $company['name'] }}</strong>
                    @if($company['legal_name'] && $company['legal_name'] !== $company['name'])
                        <span class="text-muted" style="font-size: 8pt;">({{ __('invoice.trade_name_of') }} {{ $company['legal_name'] }})</span>
                    @endif
                    <br>
                    {{ $company['address'] }}<br>
                    {{ $company['postal_code'] }} {{ $company['city'] }}<br>
                    {{ $company['country'] }}<br>
                    <br>
                    @if($company['vat_id'])
                        {{ __('invoice.vat_number') }}: {{ $company['vat_id'] }}<br>
                    @endif
                    @if($company['coc'])
                        {{ __('invoice.coc_number') }}: {{ $company['coc'] }}<br>
                    @endif
                </div>
            </div>
            <div class="customer-info">
                <div class="info-label">{{ __('invoice.bill_to') }}</div>
                <div class="info-content">
                    @if($customer['company'])
                        <strong>{{ $customer['company'] }}</strong><br>
                    @endif
                    @if($customer['name'])
                        <strong>{{ $customer['name'] }}</strong><br>
                    @endif
                    @if($customer['address'])
                        {{ $customer['address'] }}<br>
                    @endif
                    @if($customer['city'] || $customer['state'] || $customer['postal_code'])
                        @if(in_array($customer['country'], ['US', 'CA', 'AU']))
                            {{-- US/CA/AU format: City, State ZIP --}}
                            {{ $customer['city'] }}@if($customer['state']), {{ $customer['state'] }}@endif @if($customer['postal_code']){{ $customer['postal_code'] }}@endif<br>
                        @else
                            {{-- EU/Other format: Postal_code City --}}
                            {{ $customer['postal_code'] }} {{ $customer['city'] }}<br>
                        @endif
                    @endif
                    @if($customer['country'])
                        {{ $customer['country'] }}<br>
                    @endif
                    <br>
                    @if($customer['vat_id'])
                        {{ __('invoice.vat_number') }}: {{ $customer['vat_id'] }}<br>
                    @endif
                </div>
            </div>
        </div>

        <!-- Line Items -->
        <table class="line-items">
            <thead>
                <tr>
                    <th>{{ __('invoice.description') }}</th>
                    <th style="width: 80px;">{{ __('invoice.quantity') }}</th>
                    <th style="width: 100px;">{{ __('invoice.unit_price') }}</th>
                    <th style="width: 100px;">{{ __('invoice.total') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($line_items as $item)
                <tr>
                    <td>{{ $item['description'] }}</td>
                    <td>{{ $item['quantity'] }}</td>
                    <td>{{ $currency }} {{ format_number($item['unit_price'], 2, $invoice_user ?? null) }}</td>
                    <td>{{ $currency }} {{ format_number($item['total'], 2, $invoice_user ?? null) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Totals -->
        <div class="totals">
            <table>
                <tr>
                    <td>{{ __('invoice.subtotal') }}:</td>
                    <td>{{ $currency }} {{ format_number($net_amount, 2, $invoice_user ?? null) }}</td>
                </tr>
                <tr>
                    <td>
                        {{ __('invoice.vat') }}
                        @if($vat_info['is_reverse_charge'])
                            (0% - {{ __('invoice.reverse_charge') }})
                        @else
                            ({{ format_number($vat_rate, 0, $invoice_user ?? null) }}%)
                        @endif:
                    </td>
                    <td>{{ $currency }} {{ format_number($tax_amount, 2, $invoice_user ?? null) }}</td>
                </tr>
                <tr class="total-row">
                    <td>{{ __('invoice.gross_total') }}:</td>
                    <td>{{ $currency }} {{ format_number($gross_amount, 2, $invoice_user ?? null) }}</td>
                </tr>
            </table>
        </div>

        <div class="clearfix"></div>

        <!-- Notes -->
        <div class="notes">
            @if($vat_info['is_reverse_charge'])
                <p><strong>{{ __('invoice.reverse_charge') }}:</strong> {{ __('invoice.reverse_charge_note') }}</p>
            @endif

            @if($company['iban'] && $company['legal_name'])
                <p style="margin-top: 10px;">
                    <strong>{{ __('invoice.payment_notice', [
                        'days' => $due_days,
                        'legal_name' => $company['legal_name'],
                        'iban' => $company['iban'],
                        'bank_name' => $company['bank_name'] ?? ''
                    ]) }}</strong>
                </p>
            @endif
        </div>

        <!-- Footer -->
        <div class="footer">
            <div class="footer-thank-you">{{ __('invoice.thank_you') }}</div>
            <div class="footer-content">
                <strong>{{ $company['name'] }}</strong><br>
                @if($company['email'])
                    {{ __('invoice.email') }}: {{ $company['email'] }}
                    @if($company['phone']) | @endif
                @endif
                @if($company['phone'])
                    {{ __('invoice.phone') }}: {{ $company['phone'] }}
                @endif
                <br>
                @if($company['website'])
                    {{ __('invoice.website') }}: {{ $company['website'] }}
                @endif
            </div>
            @if($company['iban'])
            <div class="footer-content" style="margin-top: 10px;">
                {{ __('invoice.iban') }}: {{ $company['iban'] }}
                @if($company['bic'])
                    | {{ __('invoice.bic') }}: {{ $company['bic'] }}
                @endif
            </div>
            @endif
        </div>
    </div>
</body>
</html>
