<?php

namespace App\Http\Resources\Api;

use App\Enums\EqubPaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EqubMembershipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'equb_group_name' => $this->equbGroup?->name,
            'equb_package_name' => $this->equbGroup?->package?->name,
            'equb_group_id' => $this->equb_group_id,
            'member_id' => $this->member_id,

            // --- My Responsibility People ----------------------------
            // A place held for someone with no Niya account. member_id is
            // null on these and every obligation belongs to the sponsor, so
            // the name and the payer are stated explicitly rather than left
            // for the client to infer from a missing member.
            'is_responsibility_seat' => $this->isResponsibilitySeat(),
            'display_name' => $this->displayName(),
            'sponsor_member_id' => $this->sponsor_member_id,
            'sponsor_name' => $this->sponsor?->full_name ?? $this->sponsor?->user?->name,
            'responsibility_phone' => $this->responsibility_phone,
            'responsibility_relation' => $this->responsibility_relation,
            'payer_member_id' => $this->payerMemberId(),

            'contribution_amount' => $this->equbGroup?->fixed_contribution_amount,
            'contribution_frequency_days' => $this->equbGroup?->contribution_frequency_days,
            'join_date' => $this->join_date?->toIso8601String(),
            'next_draw_date' => $this->next_draw_date?->toIso8601String(),
            'calculated_end_date' => $this->calculated_end_date?->toIso8601String(),
            'duration' => $this->equbGroup?->duration_value . ' ' . $this->equbGroup?->duration_unit?->value,
            'draw_position' => $this->draw_position,
            'has_won' => $this->has_won,
            'win_date' => $this->win_date?->toIso8601String(),
            // Whether this membership may be left, and why not if not.
            //
            // Computed server-side and sent down rather than re-derived in the
            // app: the app cannot see the draw tables, and a client that works
            // the rule out for itself will eventually disagree with the server
            // — showing a Leave button that then fails is worse than not
            // showing one. See EqubMembership::exitBlockReason().
            'can_leave' => $this->canExit(),
            'exit_block_reason' => $this->exitBlockReason(),
            'has_received_payout' => $this->hasReceivedPayout(),
            'total_won_amount' => $this->totalWonAmount(),
            'is_settled' => $this->isSettled(),
            'status' => $this->status?->value,
            'total_paid' => $this->total_paid,
            'contributed_amount' => $this->total_paid,
            'expected_total_amount' => $this->expected_total_amount,
            'remaining_amount' => $this->remaining_amount,
            'amount_left' => $this->remaining_amount,
            // 'equb_group' => new EqubGroupResource($this->whenLoaded('equbGroup')),
            'equb_group_name'=> $this->equbGroup?->name,
            'equb_group_package_name' => $this->equbGroup?->package?->name,
            // A responsibility seat has no member row, so this stays null
            // instead of serialising an empty resource shell.
            'member' => $this->member ? new MemberResource($this->member) : null,
            // PAID CONTRIBUTIONS, AND THE ONES THE BANK IS STILL CONFIRMING.
            //
            // This used to send `paid` rows only. A member who had just paid
            // in the SuperApp therefore saw nothing at all in History until
            // settlement landed — not "confirming", not "pending", nothing —
            // and one whose payment was interrupted saw no trace of the
            // attempt when they came back. Dashen's QA reported both (items
            // 3, 5 and 6).
            //
            // What is sent, and for how long:
            //
            //   paid     always
            //   pending  three hours. A Dashen order expires 120 minutes after
            //            it is created; past that the bank cannot take the
            //            money, and an attempt still unconfirmed is one the
            //            member abandoned or cancelled, not one in flight.
            //            Showing it as "awaiting confirmation" all day would
            //            only worry them. The hour of margin covers a payment
            //            made in the order's last minutes and confirmed by the
            //            next sweep. Operators still see every row in admin.
            //   failed   one day, counted from when the attempt was MADE. That
            //            keeps a definite "the bank declined this" visible
            //            long enough to answer "did my payment go through?",
            //            and excludes the orders the sweep writes off as
            //            abandoned, which are always more than a day old by
            //            the time it does.
            //
            // Clients that only want settled money filter on status, as the
            // schedule calculator already does (it counts isPaid only).
            'payments' => EqubPaymentResource::collection(
                $this->whenLoaded('payments', function () {
                    $pendingSince = now()->subHours(3);
                    $failedSince = now()->subDay();

                    return $this->payments
                        ->filter(fn ($payment) => match ($payment->status) {
                            EqubPaymentStatus::Paid => true,
                            EqubPaymentStatus::Pending => $payment->created_at?->gte($pendingSince) ?? false,
                            EqubPaymentStatus::Failed => $payment->created_at?->gte($failedSince) ?? false,
                            default => false,
                        })
                        ->sortByDesc(fn ($payment) => $payment->created_at)
                        ->values();
                })
            ),
            'payment_schedule' => $this->payment_schedule,
            'draw_info' => new EqubDrawResource($this->winsAsWinner->first()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
