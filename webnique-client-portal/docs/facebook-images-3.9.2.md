# Facebook image save 3.9.2

Live diagnosis found the save was rejected by the campaign editing lock, not image validation. The inline message hid the actual reason shown at the top of the page.

After Stop, image selection can now save during the posting cooldown. Existing dispatched jobs retain their image bytes; the dispatch lease and daily duplicate guard are unchanged. Running schedules still reject image changes with an explicit instruction to press Stop. Actual errors appear beside the image selector.

Install plugin 3.9.2 and refresh the WordPress page. No companion update is required (1.1.1 remains current for this change). Press Stop, choose images, and confirm the saved thumbnail/status. Images save independently of Save plan. Resume when ready; no live posting was performed during verification.

Verified with tests/facebook-images.php and tests/facebook-controls.cjs.
