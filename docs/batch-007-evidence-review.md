# Batch 007 Cebu Evidence Review

Research checked on 2026-10-06 Asia/Manila (2026-10-05 UTC). Source retrieval/check dates are stored in UTC, matching the application's date policy. This review records supported facts, not a claim that the full 12L service has been verified.

## 12L and Ayala

The 2026 CDN Digital route-code guide says its updated list is based on amendments under Cebu City Ordinance No. 2000 and City Transportation Office data. It lists 12-L as Labangon–Ayala via Escario–Banawa. Biyahe records this source against the 12L route identity only. It does not establish a current dispatch, exact route geometry, a full stop sequence, a reverse pattern, or curbside boarding. [CDN Digital route-code guide](https://cebudailynews.inquirer.net/728043/lost-in-cebu-heres-a-guide-to-cebu-citys-jeepney-route-codes)

Verbum Dei Philippines' Cebu Apostolate Center directions tell visitors to take route 12L from the Ayala Center Cebu terminal to Banawa. They direct visitors to alight near Good Shepherd Road for that destination. The page does not name a bay/platform or identify a Biyahe stop, and it has no visible publication/revision date. Biyahe therefore records only the terminal boarding area and the 12L service/boarding association at `pattern-12l-forward-stop-01`. It does not mark that association's sequence or alighting facts verified, and it does not verify the complete forward pattern. [Verbum Dei Cebu directions](https://www.verbumdeiphilippines.com/verbumdeiapostolatecenter-cebu.html)

The boarding instruction is intentionally limited to “within the Ayala Center Cebu transport terminal”; no bay, side, or exact curb is asserted. Its precision is `BOARDING_AREA`, not `EXACT_BOARDING_POINT`. The stop's OSM coordinate establishes mapped location only; it is not the boarding evidence.

The Metro Cebu BRT Social Management Plan includes a 12L route entry, Ayala to Labangon via Escario and R. Duterte, based on LTFRB franchise data. The report cautions that franchise data combine operating and non-operating routes and that routes/stations required revalidation. It is useful historical corridor context, not evidence of current operation or Biyahe's exact sequence. [World Bank-hosted Social Management Plan](https://documents1.worldbank.org/curated/en/432551628239337760/pdf/Social-Management-Plan-Minutes-of-Meeting.pdf)

### Claim boundaries

| Record/fact | Evidence recorded | Not established |
| --- | --- | --- |
| 12L route identity | 2026 route-code list citing city ordinance amendments and CCTO data; OSM relation retained for mapped geometry | Current operating frequency, complete path, or passenger stop sequence |
| Ayala terminal boarding area | Institution's directions explicitly name 12L and the Ayala Center Cebu terminal | Bay/platform, precise waiting side, or current queue position |
| 12L forward stop 01 service | Institution's route instruction supports 12L service at the Ayala terminal | Ordered sequence relative to every downstream stop |
| 12L forward stop 01 boarding | Same instruction supports boarding 12L at the terminal | Exact location within the terminal |
| Remaining 12L associations and reverse path | Existing OSM mapping remains a candidate source | No route geometry or coordinate has been used to promote service, sequence, boarding, or alighting claims |

The publicly mapped OSM relation and the community route lists are not treated as independent proof of every passenger stop. The 12L reverse pattern is not assumed to be the forward pattern played backwards.

## Fare and transfer findings

LTFRB's 2023 official traditional-PUJ guide is historical and superseded in the dataset. For the September 2026 adjustment, the Philippine News Agency reports ₱14 for the first four kilometres and ₱2 for each succeeding kilometre, effective September 28; the same report says the temporary guide is valid through December 31, 2026. PIA says the updated guide is downloadable from LTFRB's website, and PTV also reports issuance of the new guides. Searches did not locate an accessible primary LTFRB 2026 fare guide or matrix itself. The current general rule remains `UNVERIFIED` government reporting, not a Biyahe fare calculation. [PNA rates](https://www.pna.gov.ph/articles/1284951), [PNA temporary-guide validity](https://www.pna.gov.ph/index.php/articles/1285005), [PIA LTFRB advisory](https://pia.gov.ph/news/luzon/ncr/ltfrb-reminds-puv-operators-drivers-to-display-updated-fare-matrix), [PTV fare-guide report](https://ptvnews.ph/ltfrb-releases-new-matrices-for-puv-fare-hikes-effective-sept-28/)

No fare is calculated from straight-line distance or mapped route geometry. Biyahe still lacks an authoritative route-distance basis for a passenger-specific journey fare, so fare remains unavailable.

The current official LTFRB traditional-PUJ fare guide located during this review is effective October 8, 2023, and is not evidence for the September 2026 increase. Search results from the LTFRB site did not expose the current guide itself. A Cebu City Transportation Office notice from 2020 names route 17B only in an event-specific temporary detour; it is not used as current direction or sequence evidence. Neither source was used to promote a current pattern or fare. [LTFRB 2023 traditional-PUJ guide](https://ltfrb.gov.ph/wp-content/uploads/2024/11/Fare-Guide_Traditional-PUJ-Provisional-Fare-Increase_08Oct2023.pdf), [Cebu City 2020 temporary traffic rerouting notice](https://www.cebucity.gov.ph/traffic-re-routing-plan-during-the-sinulog-sa-kabataan-dakbayan-2020/)

The two existing transfer relationships remain unverified. Their mapped separation is a straight-line candidate distance; no reviewed source establishes a safe pedestrian connection, crossing, waiting side, or operational interchange. Proximity alone does not promote a transfer.

## Source and licensing policy

Use competent government sources for the facts they actually cover; then operator/terminal sources; official institutional travel directions; compatible open data; OSM; and credible secondary references. Authority does not transfer from a route list to an exact stop sequence or boarding bay. Current route/boarding/transfer facts require current evidence; an access/check date is not represented as a source publication date.

The OSM-derived network retains ODbL-1.0 attribution to OpenStreetMap contributors. For the cited news and institutional pages, Biyahe stores source metadata and short factual summaries only; it does not copy articles, maps, or proprietary datasets.

## Evidence-report performance

Before the run-scoped reuse change, the six-pair report plus eight curated journeys took 195.887 seconds and examined 46 candidates. It invoked the planner 14 times (8 curated scenarios and 6 sampled origin/destination pairs). The top gaps were the 12L forward pattern (14 candidate journeys across 6 pairs), Ayala terminal boarding (7/6), and 12L forward stop 01 service/sequence/boarding (7/6). A representative Ayala–Labangon two-transfer plan took 11.788 seconds and issued 84 queries; those queries accounted for 276.5 ms of measured database time. A transfer-cap profile took 0.458 seconds at 0 transfers, 1.366 seconds at 1, and 11.762 seconds at 2. Repeated transit-state expansion and trust evaluation, not database execution, was the demonstrated hot path.

The evidence command now shares identical plans across curated and priority analysis and reuses candidate pattern/transfer models and trust evaluations only for that report run. The pattern-model cache has a 250-record cap; candidate IDs, nearby stops, transfer relationships, and maximum transfers remain bounded. There is no persistent cache and public routing behavior is unchanged.

After optimization, the same six-pair report took 76.951 seconds wall-clock (74.870 seconds measured inside the command), a 2.55x speedup and 60.7% reduction. It still examined 46 candidate journeys across 6 pairs, used 13 planner executions instead of 14, and recorded one identical-plan cache hit. The run executed 307 SQL queries, loaded 16 candidate-pattern models in 3 batches, and recorded 174,541 trust-cache hits across 459 cached trust entries. An isolated contextual Ayala–Labangon two-transfer plan took 1.941 seconds versus 11.788 seconds without context (6.1x); journey count stayed 3. The six-pair workload's trust metrics remain the same as the verified dataset facts below; no candidate cap or public route semantics was relaxed.

The default SQLite database had been marked as migrated even though three transfer-review metadata columns and the fare applicability scope were missing. A forward-only compatibility migration adds only those missing columns/indexes, backfills null-route fares as vehicle-class rules, and preserves all existing records. The updated dataset now validates, dry-runs, and imports idempotently against both the default SQLite database and the PostGIS runtime.

## Evidence effect

After import, the production report remains 8/8 verified route identities, 0/16 verified patterns, and 0/8 verified curated journeys (8 mixed; 0%). Of 86 pattern-stop facts, one service association and one boarding association are verified; sequence and alighting remain 0. One of 48 boarding points is a verified boarding area; none is exact, and 47 remain unknown. The two explicit transfers remain unverified. The source-backed Ayala/12L fact improvements are intentionally too narrow to make any complete journey verified; the highest-value remaining work is the 12L forward directional pattern, its ordered sequence (including stop 01), further route-stop service/sequence facts such as 12L stop 04, and other central boarding gaps.

The updated priority sample still ranks `pattern-12l-forward` first (14 candidate journeys, 6 sampled O/D pairs), then 12L forward stop 01 sequence (7/6), 12L forward stop 04 service (8/6) and sequence (8/6), and the B. Rodriguez extension boarding point (6/5). `pattern-17b-forward` follows (15 candidate journeys, 3 pairs). A Cebu City Transportation Office notice mentioning 17B was reviewed but only describes a temporary 2020 event detour, so no current 17B pattern fact was promoted. No additional priority fact beyond the narrow 12L/Ayala evidence was verified.
