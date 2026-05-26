<?php

namespace App\Http\Controllers;

use App\Models\DiscountCode;
use App\Models\TicketType;
use App\Services\AchievementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AchievementController extends Controller
{
    public function __construct(private readonly AchievementService $achievements)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->achievements->sync($request->user()));
    }

    public function validateDiscountCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'ticket_type_id' => ['required', 'integer', 'exists:ticket_types,id'],
        ]);

        $ticketType = TicketType::query()->findOrFail($data['ticket_type_id']);
        $code = $this->activeCode($request, $data['code']);
        $discountAmount = round(((float) $ticketType->price * $code->discount_percent) / 100, 2);
        $finalPrice = max(0, round((float) $ticketType->price - $discountAmount, 2));

        return response()->json([
            'valid' => true,
            'discount_code' => [
                'id' => $code->id,
                'code' => $code->code,
                'discount_percent' => $code->discount_percent,
                'discount_amount' => number_format($discountAmount, 2, '.', ''),
                'final_price' => number_format($finalPrice, 2, '.', ''),
                'expires_at' => $code->expires_at,
                'achievement_name' => $code->achievement?->name,
            ],
        ]);
    }

    private function activeCode(Request $request, string $code): DiscountCode
    {
        $discountCode = DiscountCode::query()
            ->with('achievement')
            ->where('user_id', $request->user()->id)
            ->where('code', Str::upper(trim($code)))
            ->first();

        if (! $discountCode || ! $discountCode->isActive()) {
            throw ValidationException::withMessages([
                'code' => ['Kod rabatowy jest nieprawidlowy, wykorzystany albo wygasl.'],
            ]);
        }

        return $discountCode;
    }
}
