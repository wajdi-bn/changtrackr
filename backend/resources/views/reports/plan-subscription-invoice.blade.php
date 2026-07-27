<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 38px; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #183129; font-family: DejaVu Sans, sans-serif; font-size: 10px; }
        .top { padding: 22px 25px; border-radius: 8px; color: #fff; background: #16845a; }
        .brand { font-size: 19px; font-weight: bold; }
        .brand b { margin-right: 7px; padding: 4px 7px; border-radius: 5px; color: #16845a; background: #fff; }
        .doc { float: right; margin-top: -22px; text-align: right; }
        .doc strong { display: block; font-size: 14px; }
        .doc span { font-size: 8px; opacity: .82; }
        h1 { margin: 28px 0 4px; font-size: 25px; }
        .status { display: inline-block; padding: 5px 10px; border-radius: 12px; color: #0e7749; background: #e4f7ec; font-size: 8px; font-weight: bold; text-transform: uppercase; }
        .status.failed { color: #b42318; background: #fee4e2; }
        .status.pending { color: #9a5d00; background: #fff3d9; }
        .parties { width: 100%; margin: 22px 0; border-collapse: collapse; }
        .parties td { width: 50%; padding: 14px; vertical-align: top; border: 1px solid #e1ebe6; }
        small { display: block; margin-bottom: 4px; color: #7a8982; font-size: 7px; text-transform: uppercase; }
        strong { font-size: 10px; }
        .line { width: 100%; margin-top: 20px; border-collapse: collapse; }
        .line th { padding: 10px; color: #fff; background: #263d32; font-size: 8px; text-align: left; }
        .line td { padding: 12px 10px; border-bottom: 1px solid #e2eae6; }
        .line .right { text-align: right; }
        .total td { color: #117a4d; font-size: 13px; font-weight: bold; }
        .payment { margin-top: 24px; padding: 15px; border-left: 3px solid #7548dc; background: #faf8ff; }
        .payment p { margin: 5px 0 0; color: #66766e; }
        footer { position: fixed; bottom: 0; left: 0; right: 0; padding-top: 9px; border-top: 1px solid #dfe8e3; color: #7b8982; font-size: 7px; text-align: center; }
    </style>
</head>
<body>
<div class="top">
    <div class="brand"><b>CT</b>ChargeTrackr</div>
    <div class="doc"><strong>Charging plan invoice</strong><span>{{ $invoice->reference }}</span></div>
</div>
<h1>{{ $invoice->chargingPlan->name }}</h1>
<span class="status {{ $invoice->status }}">{{ $invoice->status }}</span>
<table class="parties">
    <tr>
        <td>
            <small>Billed to</small>
            <strong>{{ $invoice->user->name }}</strong><br>
            {{ $invoice->user->email }}
        </td>
        <td>
            <small>Charging network</small>
            <strong>{{ $invoice->organization->name }}</strong><br>
            {{ ucfirst($invoice->billing_reason) }} billing<br>
            Issued {{ $invoice->created_at?->format('d M Y') }}
        </td>
    </tr>
</table>
<table class="line">
    <thead>
    <tr><th>Description</th><th>Service period</th><th class="right">Amount</th></tr>
    </thead>
    <tbody>
    <tr>
        <td><strong>{{ $invoice->chargingPlan->name }}</strong><br>Monthly EV charging-network membership</td>
        <td>{{ $invoice->period_starts_at?->format('d M Y') }} - {{ $invoice->period_ends_at?->format('d M Y') }}</td>
        <td class="right">{{ number_format($invoice->amount_millimes / 1000, 3) }} {{ $invoice->currency }}</td>
    </tr>
    <tr class="total"><td colspan="2">Total</td><td class="right">{{ number_format($invoice->amount_millimes / 1000, 3) }} {{ $invoice->currency }}</td></tr>
    </tbody>
</table>
<div class="payment">
    <strong>{{ $invoice->status === 'paid' ? 'Payment completed' : ($invoice->status === 'failed' ? 'Payment failed' : 'Payment pending') }}</strong>
    <p>
        @if($invoice->status === 'paid')
            Paid through {{ str_replace('_', ' ', $invoice->payment_method) }} on {{ $invoice->paid_at?->format('d M Y, H:i') }}.
            Provider reference: {{ $invoice->provider_transaction_id }}.
        @elseif($invoice->status === 'failed')
            {{ $invoice->failure_reason ?: 'The provider rejected this payment.' }}
        @else
            This payment is awaiting confirmation from the configured provider.
        @endif
    </p>
</div>
<footer>Generated {{ $issuedAt }} - ChargeTrackr client billing</footer>
</body>
</html>
