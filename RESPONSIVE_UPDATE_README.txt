EMMRC LEAVE MANAGEMENT SYSTEM - RESPONSIVE UPDATE

This package preserves the existing PHP pages, database queries, leave rules,
EL calculation, surrender workflow, cancellation workflow, and existing assets.
The update is limited to responsive presentation:
- Added a shared responsive finishing stylesheet: css/responsive.css
- Added mobile viewport metadata where a rendered PHP page was missing it.
- Kept existing page-specific styles and scripts.

INSTALLATION (XAMPP)
1. Keep a backup of your current Emmrc_Leave_mng folder and database.
2. Extract this ZIP.
3. Copy the extracted Emmrc_Leave_mng folder into C:\xampp\htdocs\.
   If Windows asks to merge/replace, use a separate folder first for testing.
4. Check db.php and set your local database connection values if needed.
5. Open http://localhost/Emmrc_Leave_mng/ in a browser.

RESPONSIVE TEST SIZES
- Small phone: 320-380 CSS px
- Typical phone: 390-430 CSS px
- Tablet: 768 CSS px
- Laptop: 1366 CSS px
- Desktop: 1920 CSS px

NOTES
- Tables use horizontal scrolling within their table area where necessary so
  columns and action buttons remain available without shrinking text excessively.
- No database migration is included or required for these visual changes.
- This ZIP does not include the .git repository.
- Please test using a copy of your live database before replacing a working site.
