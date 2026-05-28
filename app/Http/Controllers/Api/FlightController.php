<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFlightRequest;
use App\Http\Requests\UpdateFlightRequest;
use App\Http\Resources\FlightResource;
use App\Models\Flight;
use App\Services\FlightCreationService;
use App\Services\FlightUpdateDispatcher;
use Symfony\Component\HttpFoundation\Response;

/**
 * FlightController.
 *
 * Exposes exactly the three endpoints required by the spec:
 *   - POST       /api/flights              -> store()
 *   - PUT|POST   /api/flights/{flight}     -> update()
 *   - GET        /api/flights/{flight}     -> show()
 *
 * Design principle: this controller is intentionally THIN. It does no business
 * logic and no persistence itself. Its only jobs are:
 *   1. Receive an already-validated request (validation lives in Form Requests).
 *   2. Delegate the real work to a Service.
 *   3. Shape the HTTP response (status code + Resource).
 *
 * Constructor injection is used so the framework's container resolves the
 * service dependencies — this keeps the controller testable and decoupled from
 * how those services are constructed.
 */
final class FlightController extends Controller
{
    public function __construct(
        // Encapsulates the transactional creation of a flight + its nested tree.
        private readonly FlightCreationService $creationService,
        // Encapsulates idempotency registration + queue-job dispatch for updates.
        // The controller never performs the DB update — that happens in the job.
        private readonly FlightUpdateDispatcher $updateDispatcher,
    ) {
    }

    /**
     * POST /api/flights
     * Create a new flight with nested legs and segments.
     * Validation (non-empty legs, non-empty segments, datetime rules, etc.) has
     * already passed by the time this method runs, because StoreFlightRequest is
     * resolved and authorized before the controller action is invoked.
     *
     * @return \Illuminate\Http\JsonResponse 201 Created with {"flightId": uuid}
     * @throws \Throwable
     */
    public function store(StoreFlightRequest $request)
    {
        // The service performs the multi-table insert inside a DB transaction
        // and returns the persisted aggregate root. We pass it only the
        // validated payload — never the raw request — so the service has a
        // clean, trusted input contract.
        $flight = $this->creationService->create($request->validatedLegs());

        // Per the spec the creation response is just the generated UUID. We return
        // 201 Created to correctly signal that a new resource was created.
        return response()->json(
            ['flightId' => $flight->uuid],
            Response::HTTP_CREATED,
        );
    }

    /**
     * PUT|POST /api/flights/{flight}
     *
     * Accept an asynchronous update request for an existing flight.
     *
     * Route-model binding resolves {flight} against the `uuid` column (see
     * Flight::getRouteKeyName), so a 404 is returned automatically if the UUID
     * does not exist — before this method even runs.
     *
     * This method does NOT mutate the database. It:
     *   1. Registers/validates the idempotency record (Idempotency-Key header).
     *   2. Dispatches the queued job that performs the real update.
     *   3. Returns 204 No Content immediately.
     *
     * @return \Illuminate\Http\Response 204 No Content
     */
    public function update(UpdateFlightRequest $request, Flight $flight)
    {
        // Hand off to the dispatcher, which owns the idempotency bookkeeping and
        // queue dispatch. It is given:
        //   - the target flight,
        //   - the validated legs payload,
        //   - the Idempotency-Key (already validated as required by the request).
        //
        // The dispatcher is responsible for ensuring the same Idempotency-Key is
        // only ever enqueued once; duplicate/concurrent submissions are no-ops
        // that still return 204 so clients see consistent behaviour on retry.
        $this->updateDispatcher->dispatch(
            flight: $flight,
            legs: $request->validatedLegs(),
            idempotencyKey: $request->idempotencyKey(),
        );

        // 204: accepted and enqueued; there is nobody to return. Using an empty
        // response with the explicit status keeps the contract crisp.
        return response()->noContent();
    }

    /**
     * GET /api/flights/{flight}
     * Return the flight's legs (with their segments) in submitted order.
     * Route-model binding has already loaded the Flight by UUID (or 404'd). We
     * eager-load the full ordered tree to avoid N+1 queries, then let the
     * Resource shape the JSON exactly as the spec requires.
     *
     * @param  \App\Models\Flight  $flight
     * @return FlightResource
     */
    public function show(Flight $flight): FlightResource
    {
        // Eager-load legs + segments in their stored order. We reuse the model's
        // `withFullTree` scope via a fresh query so ordering/eager-loading is
        // defined in exactly one place.
        $flight->load([
            'legs' => fn ($q) => $q->orderBy('leg_order'),
            'legs.segments' => fn ($q) => $q->orderBy('segment_order'),
        ]);

        // The Resource owns the response shape ({flightId, legs:[{segments:[...]}]})
        // so the controller stays free of formatting concerns.
        return new FlightResource($flight);
    }
}
