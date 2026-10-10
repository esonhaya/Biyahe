export type NearbyLookupOutcome<T> = {
  status: "unsupported" | "denied" | "unavailable" | "cancelled" | "error" | "empty" | "results";
  routes: T[];
};

type NearbyPayload<T> = { data: T[] };
type ForegroundLocationProvider = Pick<Geolocation, "getCurrentPosition">;

type Located = { status: "located"; latitude: number; longitude: number };
type LocationOutcome = Located | { status: "unsupported" | "denied" | "unavailable" | "cancelled" };

function getForegroundLocation(
  provider: ForegroundLocationProvider | null,
  signal?: AbortSignal,
): Promise<LocationOutcome> {
  if (!provider) return Promise.resolve({ status: "unsupported" });

  return new Promise((resolve) => {
    let settled = false;
    const finish = (outcome: LocationOutcome) => {
      if (settled) return;
      settled = true;
      signal?.removeEventListener("abort", cancel);
      resolve(outcome);
    };
    const cancel = () => finish({ status: "cancelled" });
    if (signal?.aborted) {
      cancel();
      return;
    }
    signal?.addEventListener("abort", cancel, { once: true });
    try {
      provider.getCurrentPosition(
        (position) => finish({
          status: "located",
          latitude: position.coords.latitude,
          longitude: position.coords.longitude,
        }),
        (error) => finish({ status: error.code === 1 ? "denied" : "unavailable" }),
        { enableHighAccuracy: false, maximumAge: 60_000, timeout: 10_000 },
      );
    } catch {
      finish({ status: "unavailable" });
    }
  });
}

export async function requestNearbyCandidates<T>(
  provider: ForegroundLocationProvider | null,
  load: (latitude: number, longitude: number, signal?: AbortSignal) => Promise<NearbyPayload<T>>,
  signal?: AbortSignal,
): Promise<NearbyLookupOutcome<T>> {
  const location = await getForegroundLocation(provider, signal);
  if (location.status !== "located") return { status: location.status, routes: [] };

  try {
    const response = await load(location.latitude, location.longitude, signal);
    if (signal?.aborted) return { status: "cancelled", routes: [] };
    return response.data.length === 0
      ? { status: "empty", routes: [] }
      : { status: "results", routes: response.data };
  } catch {
    return { status: signal?.aborted ? "cancelled" : "error", routes: [] };
  }
}

export function nearbyOutcomeMessage<T>(outcome: NearbyLookupOutcome<T>): string | null {
  switch (outcome.status) {
    case "unsupported": return "This browser does not provide foreground location. Use route search instead.";
    case "denied": return "Location permission was denied. Search a place instead; no location was saved.";
    case "unavailable": return "Your current location is unavailable. Search a place instead; no location was saved.";
    case "error": return "Nearby routes could not be loaded. No location was saved.";
    case "empty": return "No mapped boarding candidates were found within 1 km. This does not mean no service exists.";
    case "results": return `${outcome.routes.length} route${outcome.routes.length === 1 ? "" : "s"} near mapped boarding candidates.`;
    case "cancelled": return null;
  }
}
