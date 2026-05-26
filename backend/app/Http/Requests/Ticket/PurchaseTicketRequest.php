<?php

namespace App\Http\Requests\Ticket;

use App\Http\Requests\ApiFormRequest;
use App\Models\TicketType;
use App\Support\ValidationRules;
use Illuminate\Validation\Validator;

class PurchaseTicketRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return array_merge($this->prohibitedPrivilegeRules(), [
            'ticket_type_id' => ValidationRules::positiveId('ticket_types'),
            'valid_from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:'.now()->addYears(2)->format('Y-m-d')],
            'discount_code' => ['nullable', 'string', 'max:32'],
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $ticketType = TicketType::query()->find($this->integer('ticket_type_id'));

            if (! $ticketType) {
                return;
            }

            $validFrom = $this->input('valid_from');

            if ($ticketType->is_long_term && empty($validFrom)) {
                $validator->errors()->add(
                    'valid_from',
                    'Dla biletow dlugoterminowych wymagana jest data rozpoczecia waznosci (format RRRR-MM-DD).',
                );
            }

            if (! $ticketType->is_long_term && ! empty($validFrom)) {
                $validator->errors()->add(
                    'valid_from',
                    'Bilet 60-minutowy nie wymaga daty rozpoczecia — aktywuj go po zakupie.',
                );
            }
        });
    }
}
