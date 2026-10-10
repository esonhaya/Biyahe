export type RequestTicket = {
  id: number;
  signal: AbortSignal;
};

/** Cancel earlier work and prevent its late result from updating the interface. */
export class RequestGate {
  private generation = 0;
  private controller: AbortController | null = null;

  begin(): RequestTicket {
    this.cancel();
    const controller = new AbortController();
    this.controller = controller;

    return { id: this.generation, signal: controller.signal };
  }

  isCurrent(ticket: RequestTicket): boolean {
    return ticket.id === this.generation && !ticket.signal.aborted;
  }

  finish(ticket: RequestTicket): void {
    if (this.isCurrent(ticket)) this.controller = null;
  }

  cancel(): void {
    this.generation += 1;
    this.controller?.abort();
    this.controller = null;
  }
}

/** A response may update a detail view only while its request and selected entity still match. */
export function isRequestCurrentForIdentity(
  gate: RequestGate,
  ticket: RequestTicket,
  requestIdentity: string,
  selectedIdentity: string | null,
): boolean {
  return gate.isCurrent(ticket) && selectedIdentity !== null && requestIdentity === selectedIdentity;
}
