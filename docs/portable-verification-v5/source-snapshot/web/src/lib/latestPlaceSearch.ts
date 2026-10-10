export type PlaceSearchOutcome<T> =
  | { status: "results"; results: T[] }
  | { status: "empty" | "stale" | "error"; results: [] };

/** Keeps autocomplete results tied to the query that produced them. */
export class LatestPlaceSearch {
  private generation = 0;
  private controller: AbortController | null = null;

  cancel(): void {
    this.generation += 1;
    this.controller?.abort();
    this.controller = null;
  }

  async search<T>(
    query: string,
    load: (query: string, signal: AbortSignal) => Promise<T[]>,
  ): Promise<PlaceSearchOutcome<T>> {
    const normalized = query.trim();
    if (normalized.length < 2) {
      this.cancel();
      return { status: "empty", results: [] };
    }

    this.cancel();
    const generation = this.generation;
    const controller = new AbortController();
    this.controller = controller;
    const isCurrent = () => generation === this.generation && !controller.signal.aborted;
    try {
      const results = await load(normalized, controller.signal);
      if (!isCurrent()) return { status: "stale", results: [] };
      this.controller = null;
      return { status: "results", results };
    } catch {
      if (!isCurrent()) return { status: "stale", results: [] };
      this.controller = null;
      return { status: "error", results: [] };
    }
  }
}
