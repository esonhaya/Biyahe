<?php

namespace App\Http\Controllers\Api;

use App\Enums\CommuterReportCategory;
use App\Models\CommuterReport;
use App\Services\Disruptions\CommuterReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CommuterReportController
{
    public function __invoke(Request $request, CommuterReportService $service): JsonResponse
    {
        $maxDescription = (int) config('biyahe.disruptions.report_description_max_length', 1000);
        $validator = Validator::make($request->all(), [
            'category' => ['required', 'string', Rule::in(CommuterReportCategory::values())],
            'description' => ['required', 'string', 'min:3', 'max:'.$maxDescription],
            'observed_at' => ['required', 'date'],
            'source_reference' => ['nullable', 'url', 'max:'.(int) config('biyahe.disruptions.report_source_reference_max_length', 500)],
            'location' => ['nullable', 'array'],
            'route_id' => ['nullable', 'integer', 'min:1', 'exists:routes,id'],
            'route_pattern_id' => ['nullable', 'integer', 'min:1', 'exists:route_patterns,id'],
            'stop_id' => ['nullable', 'integer', 'min:1', 'exists:stops,id'],
            'place_id' => ['nullable', 'integer', 'min:1', 'exists:places,id'],
            'transfer_relationship_id' => ['nullable', 'integer', 'min:1', 'exists:transfer_relationships,id'],
            'location.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'location.lng' => ['nullable', 'numeric', 'between:-180,180'],
            'location_accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:10000'],
        ]);
        $validator->after(function ($validator) use ($request): void {
            $observed = $request->date('observed_at');
            if ($observed === null) {
                return;
            }
            if ($observed->lt(now()->subDays((int) config('biyahe.disruptions.report_max_age_days', 7)))) {
                $validator->errors()->add('observed_at', 'Reports older than the bounded operational window are not accepted.');
            }
            if ($observed->gt(now()->addMinutes((int) config('biyahe.disruptions.report_future_skew_minutes', 5)))) {
                $validator->errors()->add('observed_at', 'Observation time cannot be materially in the future.');
            }
            $location = $request->input('location');
            if (is_array($location) && (! array_key_exists('lat', $location) || ! array_key_exists('lng', $location))) {
                $validator->errors()->add('location', 'Provide both latitude and longitude, or neither.');
            }
        });

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The commuter report could not be submitted.',
                'error' => ['code' => 'INVALID_REPORT', 'message' => 'Check the bounded report fields and try again.'],
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $data['description'] = trim(strip_tags((string) $data['description']));
        if (mb_strlen($data['description']) < 3) {
            return response()->json([
                'message' => 'The commuter report could not be submitted.',
                'error' => ['code' => 'INVALID_REPORT', 'message' => 'Description must contain at least three readable characters.'],
            ], 422);
        }
        $report = $service->submit(
            $data,
            ((string) $request->ip()).'|'.((string) $request->userAgent()),
        );

        return response()->json([
            'data' => $this->publicSummary($report),
            'meta' => [
                'submitted_for_review' => $report->status?->value !== 'DUPLICATE',
                'canonical_transport_data_changed' => false,
                'automatic_advisory_promotion' => false,
            ],
        ], 201);
    }

    /** @return array<string, mixed> */
    private function publicSummary(CommuterReport $report): array
    {
        return [
            'id' => $report->getKey(),
            'stable_id' => $report->stable_id,
            'category' => $report->category?->value ?? $report->category,
            'status' => $report->status?->value ?? $report->status,
            'description' => $report->description,
            'observed_at' => $report->observed_at?->toIso8601String(),
            'location' => $report->location_point,
            'affected_entity' => [
                'route_id' => $report->route_id,
                'route_pattern_id' => $report->route_pattern_id,
                'stop_id' => $report->stop_id,
                'place_id' => $report->place_id,
                'transfer_relationship_id' => $report->transfer_relationship_id,
            ],
        ];
    }
}
