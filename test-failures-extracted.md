1. [Failure] Tests\Feature\Api\UserRegistrationsTest::authenticated user can list own registrations
2. [Failure] Tests\Feature\ContributionPagesTest::it applies direct institution address edits for owner maintainers from the suggest update page
3. [Failure] Tests\Feature\ContributionPagesTest::it applies direct institution cover edits for owner maintainers from the suggest update page
4. [Failure] Tests\Feature\ContributionPagesTest::it applies direct institution edits for owner maintainers from the suggest update page
5. [Failure] Tests\Feature\ContributionPagesTest::it applies direct institution edits when an existing phone contact is present on the suggest update page
6. [Failure] Tests\Feature\ContributionPagesTest::it applies direct institution gallery edits for owner maintainers from the suggest update page
7. [Error] Tests\Feature\ContributionWorkflowServiceTest::it applies structured institution updates through approval
8. [Error] Tests\Feature\ContributionWorkflowServiceTest::it creates staged pending institution records with structured relation data
9. [Failure] Tests\Feature\DawahShareImpactTest::event submissions are attributed after a shared landing
10. [Failure] Tests\Feature\DawahShareImpactTest::follow actions are attributed across supported public followable pages with data set "dataset "institution follow""
11. [Failure] Tests\Feature\DawahShareImpactTest::follow actions are attributed across supported public followable pages with data set "dataset "reference follow""
12. [Failure] Tests\Feature\DawahShareImpactTest::follow actions are attributed across supported public followable pages with data set "dataset "speaker follow""
13. [Error] Tests\Feature\DawahShareImpactTest::guest follow actions redirect to login with the current page as intended destination with data set "dataset "institution guest follow redirect""
14. [Error] Tests\Feature\DawahShareImpactTest::guest follow actions redirect to login with the current page as intended destination with data set "dataset "reference guest follow redirect""
15. [Error] Tests\Feature\DawahShareImpactTest::guest follow actions redirect to login with the current page as intended destination with data set "dataset "speaker guest follow redirect""
16. [Failure] Tests\Feature\DawahShareImpactTest::impact dashboard highlights event check-ins and submissions
17. [Failure] Tests\Feature\DawahShareImpactTest::share redirect resolves non uuid reference slugs without server errors
18. [Failure] Tests\Feature\DawahShareImpactTest::tracked share ui renders across supported public surfaces
19. [Error] Tests\Feature\EventChangeAnnouncementTest::it allows speaker members for listed event speakers to publish change announcements
20. [Failure] Tests\Feature\EventChangeAnnouncementTest::it blocks registration calendar and check-in surfaces for unknown postponements
21. [Error] Tests\Feature\EventChangeAnnouncementTest::it creates same-event slug aliases when a published change mutates the schedule
22. [Failure] Tests\Feature\EventChangeAnnouncementTest::it publishes cancellation announcements and notifies committed users only once
23. [Error] Tests\Feature\EventEscalationTest::it escalates events pending > 72 hours to super admin
24. [Error] Tests\Feature\EventGoingTest::`Event Going Feature` → it allows a user to mark as going to an event
25. [Error] Tests\Feature\EventGoingTest::`Event Going Feature` → it allows a user to unmark as going to an event
26. [Error] Tests\Feature\EventGoingTest::`Event Going Feature` → it going and saved are independent
27. [Error] Tests\Feature\EventGoingTest::`Event Going Feature` → it multiple users can be going to the same event
28. [Failure] Tests\Feature\EventSearchTest::`Event Search Filters` → it shows event location with subdistrict, district, and state on cards
29. [Error] Tests\Feature\EventSeederSubmitEventCompatibilityTest::it clears seeded online event physical location during backfill
30. [Error] Tests\Feature\EventSeederSubmitEventCompatibilityTest::it creates a dedicated schedule speaker when exactly one unrelated same-name speaker already exists
31. [Error] Tests\Feature\EventSeederSubmitEventCompatibilityTest::it creates and then reuses a dedicated schedule speaker when duplicate-name speakers already exist
32. [Error] Tests\Feature\EventSeederSubmitEventCompatibilityTest::it does not duplicate seeded schedule events when the seeder reruns
33. [Error] Tests\Feature\EventSeederSubmitEventCompatibilityTest::it does not overwrite unrelated events that share a schedule title and start time
34. [Error] Tests\Feature\EventSeederSubmitEventCompatibilityTest::it matches the original seeded schedule row after manual venue edits
35. [Error] Tests\Feature\EventSeederSubmitEventCompatibilityTest::it re-canonicalizes reused schedule speaker slugs before rebuilding seeded event slugs
36. [Error] Tests\Feature\EventSeederSubmitEventCompatibilityTest::it reuses the original seeded organizer speaker when the speaker pivot was detached before rerun
37. [Error] Tests\Feature\EventSeederSubmitEventCompatibilityTest::it reuses the same dedicated speaker across repeated schedule rows in a fresh seed run
38. [Error] Tests\Feature\EventSeederSubmitEventCompatibilityTest::it seeds schedule events with required submit-event fields
39. [Error] Tests\Feature\GeneratedFileFinalFixedPoskodSeederTest::it imports the postcode csv against the production geography seed
40. [Error] Tests\Feature\HomePageTest::it groups homepage date filter counts by the viewer local date
41. [Error] Tests\Feature\HomePageTest::it loads the featured events component with upcoming events
42. [Error] Tests\Feature\HomePageTest::it loads the stats component
43. [Error] Tests\Feature\HomePageTest::it renders the attached book title across homepage event components without parentheses
44. [Error] Tests\Feature\HomePageTest::it renders the featured homepage card date badge below the poster image
45. [Error] Tests\Feature\HomePageTest::it uses a 16:9 placeholder aspect ratio on featured home cards without posters
46. [Failure] Tests\Feature\HomepageRendersContentTest::it renders the events index with content
47. [Failure] Tests\Feature\InspirationTest::it does not show inactive inspiration on public pages
48. [Failure] Tests\Feature\InspirationTest::it does not show inspiration from a different locale
49. [Failure] Tests\Feature\InspirationTest::it shows sidebar inspiration image instead of text when media exists
50. [Failure] Tests\Feature\InspirationTest::it shows sidebar inspiration on institution page
51. [Failure] Tests\Feature\InspirationTest::it shows sidebar inspiration on speaker page
52. [Failure] Tests\Feature\InstitutionIndexTest::it allows users to submit a missing institution from institution index with pending status
53. [Error] Tests\Feature\InstitutionIndexTest::it deduplicates matching district and subdistrict labels on institution cards
54. [Failure] Tests\Feature\InstitutionIndexTest::it defaults institutions country filter from an unencrypted browser timezone cookie
55. [Failure] Tests\Feature\InstitutionIndexTest::it filters institutions by country
56. [Error] Tests\Feature\InstitutionIndexTest::it filters institutions by negeri, daerah, and subdistrict scopes
57. [Error] Tests\Feature\InstitutionIndexTest::it rejects duplicate institution submissions when name and locality all match
58. [Error] Tests\Feature\InstitutionIndexTest::it shows location hierarchy values without labels on institution cards
59. [Failure] Tests\Feature\InstitutionShowPageTest::it allows an authenticated user to follow and unfollow an institution
60. [Failure] Tests\Feature\InstitutionShowPageTest::it allows super_admin to view unverified institution
61. [Failure] Tests\Feature\InstitutionShowPageTest::it deduplicates matching district and subdistrict labels on institution show page
62. [Failure] Tests\Feature\InstitutionShowPageTest::it displays affiliated speakers
63. [Failure] Tests\Feature\InstitutionShowPageTest::it displays donation channels
64. [Failure] Tests\Feature\InstitutionShowPageTest::it displays institution type badge
65. [Failure] Tests\Feature\InstitutionShowPageTest::it displays public contacts
66. [Failure] Tests\Feature\InstitutionShowPageTest::it displays spaces and facilities
67. [Failure] Tests\Feature\InstitutionShowPageTest::it displays the institution contact address block in street locality and regional lines
68. [Failure] Tests\Feature\InstitutionShowPageTest::it displays upcoming events for the institution
69. [Failure] Tests\Feature\InstitutionShowPageTest::it does not render breadcrumb and removed hero/page summary actions
70. [Failure] Tests\Feature\InstitutionShowPageTest::it does not show events outside approved and pending statuses
71. [Failure] Tests\Feature\InstitutionShowPageTest::it does not show private events
72. [Error] Tests\Feature\InstitutionShowPageTest::it hides duplicated state for kuala lumpur putrajaya and labuan in institution event location list
73. [Error] Tests\Feature\InstitutionShowPageTest::it keeps institution detail sections revealed after following
74. [Error] Tests\Feature\InstitutionShowPageTest::it loads more past events via Livewire
75. [Error] Tests\Feature\InstitutionShowPageTest::it loads more upcoming events via Livewire
76. [Failure] Tests\Feature\InstitutionShowPageTest::it preserves the institution url in guest auth links
77. [Error] Tests\Feature\InstitutionShowPageTest::it redirects guest to login when trying to follow an institution
78. [Failure] Tests\Feature\InstitutionShowPageTest::it renders donation qr thumbnails without the rounded border shell
79. [Failure] Tests\Feature\InstitutionShowPageTest::it renders institution event cards cleanly when an event has no speakers
80. [Error] Tests\Feature\InstitutionShowPageTest::it renders institution event cards with localized prayer timing stacked speaker avatars and no institution fallback location
81. [Failure] Tests\Feature\InstitutionShowPageTest::it renders prayer-relative start time and event timezone end time in institution event list
82. [Failure] Tests\Feature\InstitutionShowPageTest::it renders the book title on institution event cards without parentheses
83. [Failure] Tests\Feature\InstitutionShowPageTest::it renders the institution show page for a verified institution
84. [Failure] Tests\Feature\InstitutionShowPageTest::it returns 404 for unverified institution for guest
85. [Failure] Tests\Feature\InstitutionShowPageTest::it shows cancelled public events with cancelled badge
86. [Failure] Tests\Feature\InstitutionShowPageTest::it shows pending public events
87. [Failure] Tests\Feature\InstitutionShowPageTest::it uses a public google maps embed on institution show pages instead of platform api urls
88. [Failure] Tests\Feature\InstitutionShowPageTest::it uses stronger calendar event colors on institution page
89. [Failure] Tests\Feature\InstitutionShowPageTest::it uses the institution logo as the public preview image when no cover exists
90. [Failure] Tests\Feature\Laravel13CacheSerializationTest::it hydrates the events index state cache into the current safe payload format
91. [Failure] Tests\Feature\Mcp\AdminServerTest::it batch-updates events and resolves speaker_keys via admin-batch-update-events
92. [Failure] Tests\Feature\Mcp\AdminServerTest::it batch-updates events detach or preserve speakers and references based on route-key array presence
93. [Failure] Tests\Feature\Mcp\AdminServerTest::it batch-updates events returns unresolved_key for invalid event key via admin-batch-update-events
94. [Failure] Tests\Feature\Mcp\AdminServerTest::it detaches speakers and references when empty route-key arrays are provided via admin-update-event
95. [Failure] Tests\Feature\Mcp\AdminServerTest::it preserves speakers and references when route-key arrays are omitted via admin-update-event
96. [Failure] Tests\Feature\Mcp\AdminServerTest::it updates an event via the admin-update-event MCP tool with speaker_keys resolved
97. [Error] Tests\Feature\MemberInvitationUiTest::it lets institution admins create and revoke institution member invitations from the ahli relation manager
98. [Failure] Tests\Feature\MembershipClaimPagesTest::it does not show membership claim call to action on public institution and speaker pages
99. [Failure] Tests\Feature\PublicListingCacheInvalidationTest::it clears majlis listing cache when event is submitted from public submit form
100. [Failure] Tests\Feature\PublicPagesTest::it does not leak share tracking javascript into public page body text
101. [Failure] Tests\Feature\PublicPagesTest::it hides unverified speakers and institutions from public pages
102. [Failure] Tests\Feature\PublicPagesTest::it loads institution detail page with upcoming event type enum collection
103. [Failure] Tests\Feature\PublicPagesTest::it loads public detail pages
104. [Failure] Tests\Feature\PublicPagesTest::it renders institution contribution links with institusi route segments
105. [Failure] Tests\Feature\PublicPagesTest::it renders noindex robots metadata for moderation-only or non-public detail pages
106. [Failure] Tests\Feature\PublicPagesTest::it renders optimized seo metadata on public detail pages
107. [Failure] Tests\Feature\PublicPagesTest::it renders reference contribution links with rujukan route segments
108. [Failure] Tests\Feature\PublicPagesTest::it renders speaker contribution links with penceramah route segments
109. [Failure] Tests\Feature\PublicPagesTest::it renders threads in public share modals instead of line
110. [Failure] Tests\Feature\PublicPagesTest::it shows share actions on public series and reference pages
111. [Failure] Tests\Feature\PublicPagesTest::it uses the real speaker avatar in public speaker share metadata and preview
112. [Error] Tests\Feature\PublicSubmissionLockActionsTest::it auto-reopens institution submission when lock credibility drifts
113. [Error] Tests\Feature\PublicSubmissionLockActionsTest::it locks institution submission through the toggle and stores lock metadata
114. [Error] Tests\Feature\PublicSubmissionLockActionsTest::it saves institution contact phone values on the edit page without nulling the database value
115. [Error] Tests\Feature\PublicSubmissionLockActionsTest::it supports locking and unlocking speaker records through the toggle
116. [Error] Tests\Feature\QuickAddSelectTest::it creates and selects a related record when the quick-add option is chosen
117. [Failure] Tests\Feature\RegistrationSeederTest::it seeds registrations when the users table has no phone column
118. [Failure] Tests\Feature\SavedSearchPageTest::it normalizes tampered saved search scalar filters before storing them from the page
119. [Failure] Tests\Feature\SavedSearchPageTest::it renders state filter chips using human-readable state names
120. [Error] Tests\Feature\ScrambleDocsTest::it serves stale docs json when lock acquisition times out
121. [Failure] Tests\Feature\SharedFormSchemaTest::it defaults address country_id to malaysia when null is submitted directly to the model
122. [Failure] Tests\Feature\SharedFormSchemaTest::it does not skip districts for non malaysian states with federal territory names
123. [Failure] Tests\Feature\SharedFormSchemaTest::it hides country fields in the admin speaker form
124. [Failure] Tests\Feature\SharedFormSchemaTest::it loads subdistricts directly from federal territory states and clears district persistence
125. [Failure] Tests\Feature\SharedFormSchemaTest::it requires country fields in the admin institution and venue forms
126. [Failure] Tests\Feature\SharedFormSchemaTest::it still requires districts for non federal territory subdistrict selection
127. [Failure] Tests\Feature\SharedFormSchemaTest::it stores country-only address data in institution and venue quick-create flows
128. [Error] Tests\Feature\SharedFormSchemaTest::it stores structured speaker quick-create details when creating a speaker via quick-create
129. [Failure] Tests\Feature\SharedFormSchemaTest::it uses a reduced country-plus-region address contract across speaker create and contribution forms
130. [Failure] Tests\Feature\SignalsIntegrationTest::it renders the centralized custom UI event tracker and discovery funnel hooks
131. [Error] Tests\Feature\SlugRedirectFeatureTest::it allows administrators to create slug redirects in the admin resource
132. [Error] Tests\Feature\SlugRedirectFeatureTest::it allows administrators to delete slug redirects in the admin resource
133. [Error] Tests\Feature\SlugRedirectFeatureTest::it allows administrators to edit slug redirects in the admin resource
134. [Error] Tests\Feature\SlugRedirectFeatureTest::it creates a speaker slug redirect when a visited slug changes
135. [Error] Tests\Feature\SlugRedirectFeatureTest::it creates an institution slug redirect only after the old public path has been visited
136. [Error] Tests\Feature\SlugRedirectFeatureTest::it does not create redirect rows for unvisited slug changes
137. [Failure] Tests\Feature\SlugRedirectFeatureTest::it redirects old event slugs when administrators change the event date
138. [Error] Tests\Feature\SlugRedirectFeatureTest::it redirects old institution slugs to the current canonical public url
139. [Error] Tests\Feature\SlugRedirectFeatureTest::it shows slug redirects in the admin resource table
140. [Failure] Tests\Feature\SocialMediaNormalizationTest::it renders resolved social url on speaker page when url column is null
141. [Error] Tests\Feature\SpeakerAdminEditSocialMediaLabelTest::it saves the speaker edit page when a social media row only has a username
142. [Failure] Tests\Feature\SpeakerFollowTest::it allows an authenticated user to follow a speaker
143. [Error] Tests\Feature\SpeakerFollowTest::it allows an authenticated user to unfollow a speaker
144. [Error] Tests\Feature\SpeakerFollowTest::it keeps the speaker detail sections revealed after following
145. [Error] Tests\Feature\SpeakerFollowTest::it redirects guest to login when trying to follow
146. [Failure] Tests\Feature\SpeakerIndexTest::it allows users to submit a missing speaker from speaker index with pending status
147. [Failure] Tests\Feature\SpeakerIndexTest::it rejects duplicate speaker submissions when name gender and titles all match
148. [Failure] Tests\Feature\SpeakerShowPageTimingTest::it deduplicates matching speaker subdistrict and district labels in the speaker location badge
149. [Failure] Tests\Feature\SpeakerShowPageTimingTest::it falls back to institution name for event location on speaker page when venue is missing
150. [Failure] Tests\Feature\SpeakerShowPageTimingTest::it hides state when district is kuala lumpur putrajaya or labuan
151. [Failure] Tests\Feature\SpeakerShowPageTimingTest::it renders event end time in event timezone on speaker page
152. [Failure] Tests\Feature\SpeakerShowPageTimingTest::it renders speaker page when linked event has online format and no location address
153. [Failure] Tests\Feature\SpeakerShowPageTimingTest::it renders the book title on speaker event cards without parentheses
154. [Failure] Tests\Feature\SpeakerShowPageTimingTest::it shows a moderation note when speaker page lists pending public events
155. [Failure] Tests\Feature\SpeakerShowPageTimingTest::it shows cancelled public events with cancelled badge on speaker page
156. [Failure] Tests\Feature\SpeakerShowPageTimingTest::it shows dedicated venue name for event location on speaker page when available
157. [Failure] Tests\Feature\SpeakerShowPageTimingTest::it shows linked non-speaker roles in a separate section on the speaker page
158. [Failure] Tests\Feature\SpeakerShowPageTimingTest::it shows prayer-relative timing text on speaker page instead of absolute time
159. [Failure] Tests\Feature\SpeakerShowPageTimingTest::it uses stronger calendar event colors on speaker page
160. [Failure] Tests\Feature\SpeakerShowPageTimingTest::it uses the localized tarawih label instead of the generic isha offset text
161. [Failure] Tests\Feature\SpeakerShowSocialPlacementTest::it does not show the biodata reveal control for short speaker biodata
162. [Failure] Tests\Feature\SpeakerShowSocialPlacementTest::it renders social media section below biodata on speaker show page
163. [Failure] Tests\Feature\SpeakerShowSocialPlacementTest::it shows a reveal control for long speaker biodata
164. [Failure] Tests\Feature\SubmitEventCaptchaTest::it accepts submission when turnstile verification succeeds
165. [Failure] Tests\Feature\SubmitEventCaptchaTest::it rejects submission when turnstile verification fails
166. [Failure] Tests\Feature\SubmitEventCustomTimeValidationTest::it can submit event for future dates
167. [Failure] Tests\Feature\SubmitEventCustomTimeValidationTest::it can submit event with custom prayer time (lain_waktu)
168. [Failure] Tests\Feature\SubmitEventCustomTimeValidationTest::it saves timing mode as prayer_relative when using prayer time
169. [Failure] Tests\Feature\SubmitEventDuplicatePrefillTest::it prefills duplicated event times in the event timezone instead of the viewer timezone
170. [Failure] Tests\Feature\SubmitEventDuplicatePrefillTest::it prefills the submit-event form from a duplicated public event
171. [Failure] Tests\Feature\SubmitEventEndTimeTest::it allows sebelum maghrib during ramadhan
172. [Failure] Tests\Feature\SubmitEventEntityAccessTest::it allows authenticated members to submit locked institution and speaker entities
173. [Failure] Tests\Feature\SubmitEventEntityAccessTest::it auto-approves institution-scoped dashboard submissions and locks the organizer institution
174. [Failure] Tests\Feature\SubmitEventEntityAccessTest::it rejects guest submission when selected speakers include locked speaker
175. [Failure] Tests\Feature\SubmitEventLanguageTest::it can submit event with multiple languages
176. [Failure] Tests\Feature\SubmitEventLanguageTest::it can submit event with single language
177. [Failure] Tests\Feature\SubmitEventLocationTest::it allows institution organizer to choose a different location
178. [Failure] Tests\Feature\SubmitEventLocationTest::it automatically sets location to institution when organizer is an institution
179. [Failure] Tests\Feature\SubmitEventLocationTest::it can submit an event as a speaker with a venue location
180. [Failure] Tests\Feature\SubmitEventLocationTest::it can submit an event as a speaker with an institution location
181. [Failure] Tests\Feature\SubmitEventMediaTest::it does not require guest details for authenticated users
182. [Failure] Tests\Feature\SubmitEventMediaTest::it stores cover, poster, and gallery uploads when submitting an event
183. [Failure] Tests\Feature\SubmitEventNotesTest::it allows submitting event without notes
184. [Failure] Tests\Feature\SubmitEventNotesTest::it saves notes to event submission when provided
185. [Failure] Tests\Feature\SubmitEventNotificationTest::it notifies moderators when a guest submits an event
186. [Failure] Tests\Feature\SubmitEventNotificationTest::it transitions event from draft to pending on submission
187. [Failure] Tests\Feature\SubmitEventOrganizerAutoSelectTest::it assigns the speaker as event speaker when speaker is the organizer
188. [Failure] Tests\Feature\SubmitEventOrganizerAutoSelectTest::it uses the organizer speaker slug when no explicit speakers are selected
189. [Failure] Tests\Feature\SubmitEventParentProgramTest::it attaches submitted child events to the selected parent program
190. [Error] Tests\Feature\UserTeamRelationsTest::it keeps the membership teams relation on the user model
