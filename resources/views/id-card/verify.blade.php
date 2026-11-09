@php
    $status = $d['status'] ?? 'invalid';

    $map = [
        'active'    => ['ok',   'Valid ID Card',          'This ID card is verified and active.'],
        'expired'   => ['warn', 'ID Card Expired',        'The validity period of this ID card has ended.'],
        'suspended' => ['bad',  'ID Card Suspended',      'This ID card is currently suspended.'],
        'cancelled' => ['bad',  'ID Card Cancelled',      'This ID card has been cancelled.'],
        'inactive'  => ['bad',  'ID Card Inactive',       'This ID card is not active.'],
        'invalid'   => ['bad',  'Invalid / Fake ID Card', 'This ID card could not be verified.'],
    ];

    [$tone, $title, $subtitle] = $map[$status] ?? $map['invalid'];

    $isInvalid = $status === 'invalid';
    $name = trim($d['name'] ?? '');
    $initial = strtoupper(mb_substr($name !== '' ? $name : '?', 0, 1));
    $fmt = fn ($date) => $date ? \Carbon\Carbon::parse($date)->format('d M Y') : '-';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>ID Card Verification</title>
    <style>
        :root {
            --bg: #f3f5f9; --card: #ffffff; --text: #1b2433; --muted: #6b7686; --line: #e6e9ef;
            --ok: #1b8a4b; --ok-bg: #e6f6ed;
            --warn: #b86e00; --warn-bg: #fff3df;
            --bad: #c62828; --bad-bg: #fdeaea;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #10141b; --card: #1a202b; --text: #eef1f6; --muted: #98a2b3; --line: #2a3242;
                --ok: #4cd08a; --ok-bg: #12301f;
                --warn: #ffb44d; --warn-bg: #3a2a10;
                --bad: #ff7a7a; --bad-bg: #3a1717;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; background: var(--bg); color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            display: flex; align-items: center; justify-content: center; padding: 16px;
        }
        .card {
            width: 100%; max-width: 420px; background: var(--card);
            border-radius: 18px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,.10);
        }
        .banner { padding: 22px 20px; text-align: center; }
        .banner.ok   { background: var(--ok-bg);   color: var(--ok); }
        .banner.warn { background: var(--warn-bg); color: var(--warn); }
        .banner.bad  { background: var(--bad-bg);  color: var(--bad); }
        .icon { font-size: 44px; line-height: 1; margin-bottom: 8px; }
        .banner h1 { margin: 0; font-size: 22px; }
        .banner p { margin: 6px 0 0; font-size: 14px; opacity: .9; }
        .body { padding: 22px 20px 8px; }
        .avatar-wrap { text-align: center; margin-bottom: 14px; }
        .avatar, .avatar-fallback {
            width: 110px; height: 110px; border-radius: 50%; border: 4px solid var(--line);
            object-fit: cover; display: inline-block;
        }
        .avatar-fallback {
            line-height: 102px; font-size: 44px; font-weight: 700; background: var(--bg); color: var(--muted);
        }
        .name { text-align: center; font-size: 21px; font-weight: 700; margin: 0; }
        .designation { text-align: center; color: var(--muted); margin: 4px 0 18px; font-size: 15px; }
        .row { display: flex; justify-content: space-between; gap: 12px; padding: 12px 0; border-top: 1px solid var(--line); font-size: 14px; }
        .row span:first-child { color: var(--muted); }
        .row span:last-child { font-weight: 600; text-align: right; word-break: break-word; }
        .footer { padding: 14px 20px 20px; text-align: center; color: var(--muted); font-size: 12px; }
    </style>
</head>
<body>
<div class="card">
    <div class="banner {{ $tone }}">
        <div class="icon">{{ $tone === 'ok' ? '✔' : ($tone === 'warn' ? '!' : '✖') }}</div>
        <h1>{{ $title }}</h1>
        <p>{{ $subtitle }}</p>
    </div>

    @unless ($isInvalid)
        <div class="body">
            <div class="avatar-wrap">
                @if (! empty($d['profile_image_url']))
                    <img class="avatar" src="{{ $d['profile_image_url'] }}" alt="Profile photo">
                @else
                    <div class="avatar-fallback">{{ $initial }}</div>
                @endif
            </div>

            <h2 class="name">{{ $name !== '' ? $name : '-' }}</h2>
            <p class="designation">{{ $d['designation'] ?? '-' }}</p>

            <div class="row"><span>ID Number</span><span>{{ $d['id_number'] ?? '-' }}</span></div>
            <div class="row"><span>Organisation</span><span>{{ $d['organisation'] ?? '-' }}</span></div>
            <div class="row"><span>Valid From</span><span>{{ $fmt($d['valid_from'] ?? null) }}</span></div>
            <div class="row"><span>Valid Until</span><span>{{ $fmt($d['valid_until'] ?? null) }}</span></div>
            <div class="row"><span>Profile Status</span><span>{{ ucfirst($d['profile_status'] ?? 'inactive') }}</span></div>
        </div>
    @endunless

    <div class="footer">Verified on {{ now()->format('d M Y, h:i A') }}</div>
</div>
</body>
</html>