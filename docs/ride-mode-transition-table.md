# Ride Mode manual transition table

Ride Mode records user-confirmed progress. It does not track a vehicle or confirm physical arrival. Locations, if used by the existing optional foreground feature, are transient and are not included in the persisted session.

| Current leg | Current state | Action | Next state |
|---|---|---|---|
| Any first leg | `PLANNED` session | `ACTIVATE` | `ACTIVE`, first leg's initial state |
| Access/transfer/final walk | `NOT_STARTED` | `START_WALK` / `START_NEXT_LEG` | `WALKING` |
| Access/transfer walk followed by a ride | `WALKING` | `AT_BOARDING_AREA` | Next ride, `WAITING_TO_BOARD` |
| Ride | `WAITING_TO_BOARD` | `BOARDED` | `RIDING` |
| Ride | `RIDING` | `GOT_OFF` | Next leg's initial state |
| Final walk | `WALKING` | `ARRIVED` | `COMPLETED`, `ARRIVED` |
| Active session before completion | Any valid current state | `CANCEL` | `CANCELLED`, progress retained |
| Active session with reviewable history | Supported prior manual state | `REVIEW_PREVIOUS_STEP` | Previous progress state |

Repeated walking starts, premature arrival, boarding before the mapped access step, and transitions inconsistent with the current leg are rejected. A persisted session is accepted only when status, leg type, progress state, and timestamps agree. Older valid version-1 sessions remain readable.
