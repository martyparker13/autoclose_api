<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Delivery\StoreDeliveryAppointmentRequest;
use App\Http\Requests\Delivery\UpdateDeliveryAppointmentRequest;
use App\Http\Resources\DeliveryAppointmentResource;
use App\Jobs\SendDeliveryReminderJob;
use App\Models\Deal;
use App\Models\DeliveryAppointment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeliveryAppointmentController extends BaseController
{
    /**
     * Schedule a delivery or pickup for a deal (buyer).
     */
    public function store(StoreDeliveryAppointmentRequest $request, int $deal): JsonResponse
    {
        /** @var \App\Models\User $buyer */
        $buyer     = $request->user();
        $dealer    = app('current_dealer');
        $dealModel = Deal::where('dealer_id', $dealer->id)
            ->where('buyer_id', $buyer->id)
            ->whereIn('status', ['docs_signed', 'awaiting_delivery'])
            ->findOrFail($deal);

        // Only one appointment per deal
        $existing = DeliveryAppointment::where('deal_id', $dealModel->id)->first();
        if ($existing) {
            return response()->json(['message' => 'A delivery appointment already exists for this deal.'], 422);
        }

        $appointment = DeliveryAppointment::create(
            array_merge($request->validated(), [
                'deal_id'   => $dealModel->id,
                'dealer_id' => $dealer->id,
                'status'    => 'scheduled',
            ])
        );

        // Transition deal to awaiting_delivery if docs are signed
        if ($dealModel->status === 'docs_signed') {
            $dealModel->update(['status' => 'awaiting_delivery']);
        }

        $this->dispatchReminders($appointment);

        return $this->resourceResponse(new DeliveryAppointmentResource($appointment), 201);
    }

    /**
     * Show the delivery appointment for a deal.
     */
    public function show(Request $request, int $deal): JsonResponse
    {
        $dealer      = app('current_dealer');
        $dealModel   = $this->resolveDeal($request, $dealer->id, $deal);
        $appointment = DeliveryAppointment::where('deal_id', $dealModel->id)->firstOrFail();

        return $this->resourceResponse(new DeliveryAppointmentResource($appointment));
    }

    /**
     * Update appointment status or driver assignment (dealer staff).
     */
    public function update(UpdateDeliveryAppointmentRequest $request, int $deal, int $appointment): JsonResponse
    {
        $dealer      = app('current_dealer');
        $dealModel   = Deal::where('dealer_id', $dealer->id)->findOrFail($deal);
        $model       = DeliveryAppointment::where('deal_id', $dealModel->id)
            ->where('dealer_id', $dealer->id)
            ->findOrFail($appointment);

        $model->update($request->validated());

        // Re-dispatch reminders if the scheduled time changed or status returned to scheduled
        if ($model->wasChanged('scheduled_at') || $model->wasChanged('status')) {
            if ($model->status === 'scheduled') {
                $this->dispatchReminders($model);
            }
        }

        // If completed, mark the deal as delivered
        if (($request->validated()['status'] ?? null) === 'completed') {
            $dealModel->update(['status' => 'delivered']);
        }

        return $this->resourceResponse(new DeliveryAppointmentResource($model));
    }

    private function resolveDeal(Request $request, int $dealerId, int $dealId): Deal
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        if ($user->isBuyer()) {
            return Deal::where('buyer_id', $user->id)->where('dealer_id', $dealerId)->findOrFail($dealId);
        }

        return Deal::where('dealer_id', $dealerId)->findOrFail($dealId);
    }

    /**
     * Queue 24-hour and 1-hour SMS reminders for the appointment.
     */
    private function dispatchReminders(DeliveryAppointment $appointment): void
    {
        if (! $appointment->scheduled_at) {
            return;
        }

        $twentyFourHours = $appointment->scheduled_at->copy()->subHours(24);
        $oneHour         = $appointment->scheduled_at->copy()->subHour();

        if ($twentyFourHours->isFuture()) {
            SendDeliveryReminderJob::dispatch($appointment->id, '24 hours')
                ->delay($twentyFourHours);
        }

        if ($oneHour->isFuture()) {
            SendDeliveryReminderJob::dispatch($appointment->id, '1 hour')
                ->delay($oneHour);
        }
    }
}
