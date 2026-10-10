export const API_REQUEST_TIMEOUT_MILLISECONDS = 20_000;

export class ApiRequestTimeoutError extends Error {
  constructor(timeoutMilliseconds: number) {
    super(`API request timed out after ${timeoutMilliseconds} ms.`);
    this.name = "ApiRequestTimeoutError";
  }
}

/** Bound a network request while preserving cancellation from the caller. */
export async function fetchWithTimeout<T>(
  input: RequestInfo | URL,
  init: RequestInit = {},
  consumeResponse: (response: Response) => Promise<T> | T,
  timeoutMilliseconds = API_REQUEST_TIMEOUT_MILLISECONDS,
): Promise<T> {
  if (!Number.isFinite(timeoutMilliseconds) || timeoutMilliseconds <= 0) {
    throw new RangeError("Request timeout must be a positive finite number.");
  }

  const requestController = new AbortController();
  const callerSignal = init.signal;
  let timedOut = false;
  const abortFromCaller = () => requestController.abort(callerSignal?.reason);

  if (callerSignal?.aborted) {
    abortFromCaller();
  } else {
    callerSignal?.addEventListener("abort", abortFromCaller, { once: true });
  }

  const timeout = setTimeout(() => {
    timedOut = true;
    requestController.abort();
  }, timeoutMilliseconds);

  try {
    const response = await fetch(input, { ...init, signal: requestController.signal });
    return await consumeResponse(response);
  } catch (error) {
    if (timedOut) throw new ApiRequestTimeoutError(timeoutMilliseconds);
    throw error;
  } finally {
    clearTimeout(timeout);
    callerSignal?.removeEventListener("abort", abortFromCaller);
  }
}
