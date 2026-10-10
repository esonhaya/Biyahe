export type JourneyOptionState = {
  recommended: boolean;
  selected: boolean;
};

/** Keep the planner's first-ranked option independent of the active comparison filter. */
export function journeyOptionState(
  candidateIdentity: string,
  plannerRecommendationIdentity: string | null,
  selectedJourneyIdentity: string | null,
): JourneyOptionState {
  return {
    recommended: plannerRecommendationIdentity !== null && candidateIdentity === plannerRecommendationIdentity,
    selected: selectedJourneyIdentity !== null && candidateIdentity === selectedJourneyIdentity,
  };
}
