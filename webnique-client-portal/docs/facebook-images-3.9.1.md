# Image persistence and publishing — 3.9.1 / companion 1.1.1

The old picker only changed a hidden form field. The image was not persisted until a
successful full-plan save. The new picker labels its confirmation button “Use these
images” and immediately saves just the selected client's image IDs through the
existing authenticated/nonce-protected AJAX endpoint. Removal also saves immediately.
The UI shows thumbnail previews and a save confirmation; validation or running-campaign
locks report an error and retain the previous saved selection. Other unsaved form
fields are not overwritten by image selection. The full plan still requires Save plan.

The publishing script explicitly depends on WordPress media-views. Both the page and
companion check the declared image count against the payload before publishing. The
upload step reacquires a rerendered composer, supports newly introduced portal file
inputs, verifies the browser accepted all files and requires matching attachment
controls before clicking Post. An image failure skips/holds according to the existing
pre/post-click rules; it never deliberately falls back to text only. The 4-image/4-MB
limits and cross-client guards remain in force.

Stop the schedule, update the plugin to 3.9.1, reload Facebook companion 1.1.1, and
refresh WordPress. Wait for any outstanding posting interval, choose images and click
Use these images. Look for “image(s) saved” and thumbnails. Test performs a real post;
already submitted groups retain their existing duplicate guards and are not resent.

Verified using `tests/facebook-images.php` (real temporary PNG bytes, save/persistence,
cross-client preservation and dispatch checks), `tests/facebook-controls.cjs` (picker,
immediate save, thumbnail, removal), and `tests/facebook-companion.cjs` (mocked upload,
payload mismatch rejection, missing-input no-text-only fallback). No production
WordPress actions or real Facebook posts were performed. The screenshot alone does not
establish whether the original failure was an unsaved form, media-load problem or
server validation failure; the new UI makes those failures explicit.
