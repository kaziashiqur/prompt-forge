<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function __invoke(Request $request, PixelforgeClient $client): Response
    {
        $request->validate([
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $end = Carbon::parse($request->input('end', now('UTC')->toDateString()), 'UTC')->startOfDay();
        $start = Carbon::parse($request->input('start', $end->copy()->subDays(6)->toDateString()), 'UTC')->startOfDay();
        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }
        if ($start->diffInDays($end) > 91) {
            $start = $end->copy()->subDays(91);
        }

        $generations = Generation::query()
            ->with('user:id,name,email')
            ->where('status', Generation::SUCCEEDED)
            ->whereNotNull('settled_at')
            ->whereBetween('completed_at', [$start, $end->copy()->endOfDay()])
            ->get();

        $days = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $days[$d->toDateString()] = ['date' => $d->toDateString(), 'generations' => 0, 'charged' => 0, 'cost' => 0, 'users' => []];
        }

        foreach ($generations as $gen) {
            $date = $gen->completed_at->copy()->utc()->toDateString();
            $day = &$days[$date];
            $day['generations']++;
            $day['charged'] += $gen->charge_nanos;
            $day['cost'] += $gen->provider_cost_nanos;

            $u = &$day['users'][$gen->user_id];
            $u ??= ['user' => $gen->user->name.' <'.$gen->user->email.'>', 'generations' => 0, 'charged' => 0, 'cost' => 0];
            $u['generations']++;
            $u['charged'] += $gen->charge_nanos;
            $u['cost'] += $gen->provider_cost_nanos;
            unset($day, $u);
        }

        $usage = null;
        $usageError = null;
        try {
            $usage = collect($client->usage($start->toDateString(), $end->toDateString())['data'])->keyBy('date');
        } catch (PixelforgeException $e) {
            $usageError = $e->getMessage();
        }

        $rows = [];
        $totals = ['generations' => 0, 'charged' => 0, 'cost' => 0, 'pixelforge' => 0];

        foreach (array_reverse($days) as $day) {
            $pf = $usage?->get($day['date']);
            $pfNanos = $pf ? Money::toNanos($pf['cost_usd']) : null;

            $rows[] = [
                'date' => $day['date'],
                'generations' => $day['generations'],
                'charged' => Money::display($day['charged']),
                'cost' => Money::format($day['cost']),
                'profit' => Money::display($day['charged'] - $day['cost']),
                'pixelforge_cost' => $pfNanos !== null ? Money::format($pfNanos) : null,
                'pixelforge_generations' => $pf['generations'] ?? null,
                'matches' => $pfNanos === null ? null : $pfNanos === $day['cost'],
                'users' => collect($day['users'])->map(fn ($u) => [
                    'user' => $u['user'],
                    'generations' => $u['generations'],
                    'charged' => Money::display($u['charged']),
                    'cost' => Money::format($u['cost']),
                    'profit' => Money::display($u['charged'] - $u['cost']),
                ])->values(),
            ];

            $totals['generations'] += $day['generations'];
            $totals['charged'] += $day['charged'];
            $totals['cost'] += $day['cost'];
            $totals['pixelforge'] += $pfNanos ?? 0;
        }

        return Inertia::render('Admin/Report', [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'rows' => $rows,
            'totals' => [
                'generations' => $totals['generations'],
                'charged' => Money::display($totals['charged']),
                'cost' => Money::format($totals['cost']),
                'profit' => Money::display($totals['charged'] - $totals['cost']),
                'pixelforge_cost' => $usage ? Money::format($totals['pixelforge']) : null,
                'matches' => $usage ? $totals['pixelforge'] === $totals['cost'] : null,
            ],
            'usageError' => $usageError,
        ]);
    }
}
