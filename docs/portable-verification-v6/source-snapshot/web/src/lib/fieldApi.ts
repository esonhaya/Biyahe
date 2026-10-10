import type { FieldMission, FieldObservationDraft } from "./fieldMode";

const apiUrl = (process.env.NEXT_PUBLIC_API_URL ?? "http://127.0.0.1:8000/api/v1").replace(/\/$/, "");

function headers(token: string): HeadersInit {
  return {
    Accept: "application/json",
    "Content-Type": "application/json",
    ...(token.trim() ? { "X-Biyahe-Field-Token": token.trim() } : {}),
  };
}

async function request<T>(path: string, token: string, init: RequestInit = {}): Promise<T> {
  const response = await fetch(apiUrl + path, { ...init, headers: { ...headers(token), ...(init.headers ?? {}) }, cache: "no-store" });
  if (!response.ok) throw new Error(`Field API request failed: ${response.status}`);
  return response.json() as Promise<T>;
}

export async function getFieldMissions(token: string, signal?: AbortSignal): Promise<FieldMission[]> {
  const response = await request<{ data: FieldMission[] }>("/field/missions", token, { method: "GET", signal });
  return response.data;
}

export async function startFieldSession(token: string, missionId: number): Promise<{ data: FieldMission; session_id: number }> {
  return request(`/field/missions/${missionId}/sessions`, token, { method: "POST", body: "{}" });
}

export async function syncFieldOutbox(token: string, sessionId: number, observations: FieldObservationDraft[]): Promise<{ data: { accepted: string[]; idempotent: string[]; rejected: { stable_id: string; message: string }[] } }> {
  return request(`/field/sessions/${sessionId}/sync`, token, { method: "POST", body: JSON.stringify({ observations }) });
}

export async function completeFieldSession(token: string, sessionId: number): Promise<void> {
  await request(`/field/sessions/${sessionId}/complete`, token, { method: "POST", body: "{}" });
}
