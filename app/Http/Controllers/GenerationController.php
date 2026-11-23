<?php

namespace App\Http\Controllers;

use App\Jobs\SubmitGeneration;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use App\Support\Money;
use App\Support\Pricing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    public const SIZES = ['1024x1024', '1536x1024', '1024x1536'];

    public const QUALITIES = ['low', 'medium', 'high'];

    public function index(Request $request, PixelforgeClient $client): Response
    {
        $user = $request->user();

        return Inertia::render('Generations/Index', [
            'pricing' => fn () => $this->pricingOrNull($client),
            'sizes' => self::SIZES,
            'qualities' => self::QUALITIES,
            'creditPacks' => config('pixelforge.credit_packs'),
            'balance' => fn () => Money::display($user->fresh()->balance_nanos),
            'generations' => fn () => $user->generations()->latest('id')->limit(50)->get()->map->toFrontend(),
        ]);
    }

    public function store(Request $request, PixelforgeClient $client): RedirectResponse
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:4000'],
            'size' => ['required', Rule::in(self::SIZES)],
            'quality' => ['required', Rule::in(self::QUALITIES)],
            'reference_images' => ['array', 'max:4'],
            'reference_images.*' => ['required', 'url:http,https', 'max:2000'],
        ]);

        $references = array_values($data['reference_images'] ?? []);

        try {
            $pricing = $client->pricing();
        } catch (PixelforgeException) {
            throw ValidationException::withMessages(['prompt' => 'Pricing is unavailable right now. Please try again in a moment.']);
        }

        $estimate = Pricing::estimate($pricing, $data['prompt'], $data['size'], $data['quality'], count($references));
        $hold = $estimate['charge_max'];

        $generation = DB::transaction(function () use ($request, $data, $references, $estimate, $hold, $pricing) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();

            if ($user->balance_nanos < $hold) {
                throw ValidationException::withMessages([
                    'prompt' => 'Not enough credits. This could cost up to '.Money::display($hold).' and your balance is '.Money::display($user->balance_nanos).'.',
                ]);
            }

            $generation = $user->generations()->create([
                'status' => Generation::SUBMITTING,
                'prompt' => $data['prompt'],
                'size' => $data['size'],
                'quality' => $data['quality'],
                'reference_images' => $references,
                'estimate_min_nanos' => $estimate['charge_min'],
                'estimate_max_nanos' => $estimate['charge_max'],
                'estimate_expected_nanos' => $estimate['charge_expected'],
                'hold_nanos' => $hold,
                'rates' => $pricing['rates'],
            ]);

            CreditTransaction::create(['user_id' => $user->id, 'generation_id' => $generation->id, 'type' => CreditTransaction::HOLD, 'amount_nanos' => -$hold]);
            $user->decrement('balance_nanos', $hold);

            return $generation;
        });

        SubmitGeneration::dispatch($generation)->afterCommit();

        return back()->with('success', 'Generation queued.');
    }

    public function buyCredits(Request $request): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'integer', Rule::in(config('pixelforge.credit_packs'))]]);
        $nanos = $data['amount'] * Money::NANOS_PER_DOLLAR;

        DB::transaction(function () use ($request, $nanos) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            CreditTransaction::create(['user_id' => $user->id, 'type' => CreditTransaction::PURCHASE, 'amount_nanos' => $nanos]);
            $user->increment('balance_nanos', $nanos);
        });

        return back()->with('success', 'Added '.Money::display($nanos).' in credits.');
    }

    private function pricingOrNull(PixelforgeClient $client): ?array
    {
        try {
            return $client->pricing();
        } catch (PixelforgeException) {
            return null;
        }
    }
}
