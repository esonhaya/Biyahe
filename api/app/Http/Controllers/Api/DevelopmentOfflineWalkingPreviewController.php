<?php

namespace App\Http\Controllers\Api;

use App\Services\Routing\OfflineJourneyWalkingPreview;
use App\Services\Routing\OfflineWalkingPreviewException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use JsonException;
use Throwable;

/** Local-only adapter for development previews; no production journey endpoint uses it. */
final class DevelopmentOfflineWalkingPreviewController
{
    public function __invoke(Request $request, OfflineJourneyWalkingPreview $preview): JsonResponse
    {
        $localNames = ['localhost', '127.0.0.1', '::1', '[::1]'];
        abort_unless(app()->environment('local') && config('app.debug') && config('offline_walking_preview.enabled')
            && in_array(strtolower($request->getHost()), $localNames, true)
            && in_array(strtolower((string) $request->server('REMOTE_ADDR')), ['127.0.0.1', '::1'], true), 404);
        if (! $request->isJson()) {
            return $this->error('invalid_request', 'Send the journey as an application/json request.', 415);
        }
        if (strlen($request->getContent()) > (int) config('offline_walking_preview.maximum_request_bytes', 2_000_000)) {
            return $this->error('resource_budget_exceeded', 'The local graph preview request exceeds its size limit.', 413);
        }

        $validator = Validator::make($request->all(), [
            'journey' => ['required', 'array'],
            'journey.id' => ['required', 'string', 'max:180'],
            'journey.legs' => ['required', 'array', 'max:12'],
            'journey.legs.*.type' => ['required', 'string', 'in:WALK_ACCESS,RIDE,TRANSFER_WALK,WALK_EGRESS'],
        ]);
        if ($validator->fails()) {
            return $this->error('invalid_journey', 'The journey payload does not match the local preview contract.', 422);
        }

        try {
            $journey = $request->input('journey');
            if (! is_array($journey)) {
                return $this->error('invalid_journey', 'The journey payload does not match the local preview contract.', 422);
            }
            $serialized = json_encode(
                ['data' => $preview->preview($journey)],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
            if (strlen($serialized) > (int) config('offline_walking_preview.maximum_response_bytes', 2_000_000)) {
                return $this->error('resource_budget_exceeded', 'The local graph preview response exceeds its size limit.', 503);
            }

            return response($serialized, 200)
                ->header('Content-Type', 'application/json; charset=utf-8')
                ->header('Cache-Control', 'no-store, private')
                ->header('X-Content-Type-Options', 'nosniff');
        } catch (OfflineWalkingPreviewException $exception) {
            $status = $exception->reasonCode === 'source_mismatch' ? 409
                : ($exception->reasonCode === 'invalid_journey' ? 422 : 503);

            return $this->error($exception->reasonCode, $exception->getMessage(), $status);
        } catch (JsonException) {
            return $this->error('graph_unavailable', 'The local graph preview result could not be serialized safely.', 503);
        } catch (Throwable) {
            return $this->error('graph_unavailable', 'The local graph preview could not be completed.', 503);
        }
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status, [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
