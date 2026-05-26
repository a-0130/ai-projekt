<?php

namespace App\Http\Controllers;

use App\Http\Requests\Ticket\PurchaseTicketRequest;
use App\Models\DiscountCode;
use App\Models\TicketType;
use App\Models\UserTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TicketController extends Controller
{
    public function types(): JsonResponse
    {
        $types = TicketType::query()
            ->orderBy('price')
            ->get()
            ->map(fn (TicketType $type) => $this->typePayload($type));

        return response()->json(['data' => $types]);
    }

    public function index(Request $request): JsonResponse
    {
        $tickets = UserTicket::query()
            ->with('ticketType')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('purchase_date')
            ->get()
            ->map(fn (UserTicket $ticket) => $this->ticketPayload($ticket));

        return response()->json(['data' => $tickets]);
    }

    public function purchase(PurchaseTicketRequest $request): JsonResponse
    {
        $data = $request->validated();
        $ticketType = TicketType::query()->findOrFail($data['ticket_type_id']);
        $ticket = DB::transaction(function () use ($request, $data, $ticketType): UserTicket {
            $discountCode = $this->findActiveDiscountCode($request, $data['discount_code'] ?? null);
            $discountPercent = $discountCode?->discount_percent ?? 0;
            $discountAmount = round(((float) $ticketType->price * $discountPercent) / 100, 2);
            $finalPrice = max(0, round((float) $ticketType->price - $discountAmount, 2));
            $validFrom = $ticketType->is_long_term ? Carbon::parse($data['valid_from'])->startOfDay() : null;
            $validUntil = $validFrom?->copy()->addMinutes($ticketType->validity_minutes);

            $ticket = UserTicket::create([
                'user_id' => $request->user()->id,
                'ticket_type_id' => $ticketType->id,
                'discount_code_id' => $discountCode?->id,
                'purchase_date' => now(),
                'discount_amount' => $discountAmount,
                'final_price' => $finalPrice,
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
                'is_active' => $ticketType->is_long_term,
            ]);

            if ($discountCode) {
                $discountCode->update(['used_at' => now()]);
            }

            return $ticket;
        });

        $ticket->load('ticketType');

        return response()->json([
            'message' => 'Bilet zakupiony pomyslnie (platnosc zasymulowana).',
            'ticket' => $this->ticketPayload($ticket),
        ], 201);
    }

    public function activate(Request $request, int $ticket): JsonResponse
    {
        if ($ticket < 1) {
            throw ValidationException::withMessages([
                'ticket' => ['Nieprawidlowy identyfikator biletu.'],
            ]);
        }

        $userTicket = UserTicket::query()
            ->with('ticketType')
            ->where('user_id', $request->user()->id)
            ->whereKey($ticket)
            ->firstOrFail();

        if ($userTicket->ticketType->is_long_term) {
            throw ValidationException::withMessages([
                'ticket' => ['Bilety dlugoterminowe sa aktywne od daty zakupu i nie wymagaja aktywacji.'],
            ]);
        }

        if ($userTicket->is_active) {
            throw ValidationException::withMessages([
                'ticket' => ['Ten bilet jest juz aktywny.'],
            ]);
        }

        $validFrom = now();
        $validUntil = $validFrom->copy()->addMinutes($userTicket->ticketType->validity_minutes);

        $userTicket->update([
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'is_active' => true,
        ]);

        $userTicket->refresh()->load('ticketType');

        return response()->json([
            'message' => 'Bilet aktywowany.',
            'ticket' => $this->ticketPayload($userTicket),
        ]);
    }

    private function typePayload(TicketType $type): array
    {
        return [
            'id' => $type->id,
            'name' => $type->name,
            'price' => $type->price,
            'validity_minutes' => $type->validity_minutes,
            'is_long_term' => $type->is_long_term,
        ];
    }

    private function ticketPayload(UserTicket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'ticket_type' => $this->typePayload($ticket->ticketType),
            'purchase_date' => $ticket->purchase_date,
            'valid_from' => $ticket->valid_from,
            'valid_until' => $ticket->valid_until,
            'is_active' => $ticket->is_active,
            'status' => $ticket->status(),
            'can_activate' => $ticket->canActivate(),
            'discount_amount' => $ticket->discount_amount,
            'final_price' => $ticket->final_price,
        ];
    }

    private function findActiveDiscountCode(Request $request, ?string $code): ?DiscountCode
    {
        if (! $code) {
            return null;
        }

        $discountCode = DiscountCode::query()
            ->where('user_id', $request->user()->id)
            ->where('code', Str::upper(trim($code)))
            ->lockForUpdate()
            ->first();

        if (! $discountCode || ! $discountCode->isActive()) {
            throw ValidationException::withMessages([
                'discount_code' => ['Kod rabatowy jest nieprawidlowy, wykorzystany albo wygasl.'],
            ]);
        }

        return $discountCode;
    }
}
