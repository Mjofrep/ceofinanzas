# AGENTS.md

This repository is a PHP/MySQL web app for CEO Finanzas. Use this guide when making changes.

## Repository Layout
- `public/` HTTP entry points and pages
- `includes/` shared layout (`header.php`, `menu.php`, `footer.php`)
- `config/` application and database configuration
- `assets/` static CSS/JS/images
- `storage/schema.sql` database schema

## Build / Lint / Test Commands
There is no formal build, lint, or test runner configured.

Common manual checks:
- PHP syntax check (single file):
  - `php -l path/to/file.php`
- DB connectivity check:
  - Open `/ceofinanzas/public/db_status.php` in the browser

Composer:
- Dependencies are defined in `composer.json`.
- Install vendor packages: `composer install`

Single “test” equivalent:
- There are no automated tests. Use targeted smoke checks:
  - Open `/ceofinanzas/public/import_presupuesto.php` to verify import UI
  - Open `/ceofinanzas/public/presupuesto.php` to verify queries render
  - Open `/ceofinanzas/public/ejecucion.php` and `/ceofinanzas/public/pagos.php` to verify form submits

## Code Style Guidelines

### PHP
- Use `declare(strict_types=1);` at the top of PHP files.
- Use PDO via `config/db.php` (`db()` helper). Do not open new raw connections.
- Always use prepared statements for user input.
- Prefer explicit casting for numeric values coming from forms.
- Handle errors with `try/catch` where IO or parsing is involved.
- Keep functions small and focused (single responsibility).

### Formatting
- Indent with 2 spaces in PHP and HTML mixed templates.
- Keep PHP opening tags `<?php` on their own line.
- Use short echo `<?= ... ?>` for template output.
- Use `htmlspecialchars` for any output derived from user input or DB values.

### Naming Conventions
- Variables: `snake_case` in PHP (e.g., `$proyecto_id`, `$fecha_entrega`).
- Functions: `snake_case` (e.g., `dividir_codigo_nombre`).
- Classes: `PascalCase` if introduced.
- DB tables: `ceo_` prefix and `snake_case`.
- DB columns: `snake_case`.

### Error Handling
- Validate required inputs before DB writes. Return readable error messages.
- For imports, collect validation errors and render them as a list.
- Use early exits for invalid request states.

### Database
- Schema lives in `storage/schema.sql`.
- Keep referential integrity via foreign keys.
- Use `ON DUPLICATE KEY UPDATE` for idempotent imports.
- Monetary fields use `DECIMAL(18,2)`.

### HTML/CSS/JS
- UI uses Bootstrap 5 (CDN). Keep it consistent.
- Custom styles live in `assets/css/app.css`.
- Keep UI sober and consistent with the CEO system style.
- Avoid inline styles unless matching an existing pattern in this repo.

### Imports / Dependencies
- For Excel import, use PhpSpreadsheet.
- Always require `vendor/autoload.php` when using external libraries.

### Security
- Do not commit new secrets.
- Assume any user input is untrusted.
- Always escape output in templates.

## Conventions in This Codebase
- Shared layout: include `includes/header.php` and `includes/footer.php` in pages.
- Navigation: `includes/menu.php`.
- Configuration: `config/app.php` and `config/db.php`.

## Notes on Current Features
- Presupuesto import supports Resumen/Opex/Capex.
- Opex/Resumen “Descripcion de Actividad” is split into code + name by the second dash.
- No currency conversion is performed; amounts are stored as entered.

## Import Rules (Presupuesto)
- Supported formats: `.xlsx`, `.xls`, `.csv` (semicolon-separated CSV).
- First row in CSV/Excel is treated as totals and ignored.
- Header row is the second row and is validated before import.
- Opex/Resumen columns: `Area`, `Ceco`, `Descripcion de Actividad`, months, `Total`.
- Capex columns: `Area`, `Proyecto`, `Clase Coste`, months, `Total General`.
- Month columns map to numeric months 1..12 using Spanish names.
- Empty or `-` values are imported as zero.
- Imports are idempotent per area + proyecto + ceco + clase + anio + mes + hoja.
- Default currency for presupuesto is CLP.

## UI Patterns
- Use `card` containers for each functional block.
- Use Bootstrap tables with `.table-striped` and `.table-responsive` wrappers.
- Place primary actions on the right, use outline buttons for navigation.
- Keep form labels above inputs; group related fields in `.row.g-3`.
- Reuse the topbar and navbar layout from `includes/header.php`.

## Forms and Validation
- Validate required fields on the server; client-side validation is optional.
- Use clear, short error messages and list them in an alert.
- Normalize numeric inputs by removing thousand separators before casting.
- Use `POST` for write actions and `GET` for filters.
- Keep form submit buttons inside the form for consistent behavior.

## SQL Guidelines
- Prefer `INNER JOIN` for required relations and `LEFT JOIN` for optional ones.
- Add indexes for frequent filter columns (anio, area_id, proyecto_id).
- Use `LIMIT` for list views to avoid excessive rows.
- Keep migrations in `storage/schema.sql` and update it with changes.

## Assets
- Global styles go in `assets/css/app.css`.
- Place images under `assets/img/`.
- Keep JS minimal; attach behavior in `assets/js/app.js`.

## Localization
- Use Spanish labels in the UI.
- Keep numeric formatting with thousands separator `.` when rendering.
- Dates should be stored as `YYYY-MM-DD` and rendered as-is.

## Accessibility
- Ensure form inputs have associated labels.
- Use semantic headings in cards and pages.
- Do not rely on color alone for status (use text labels or badges).

## Data Handling
- Trim and normalize user input before using it.
- Treat empty strings as `NULL` when appropriate for DB writes.
- Keep monetary values in numeric columns (no formatted strings in DB).
- Prefer storing raw values and format only on output.
- When parsing CSV, honor the semicolon separator and handle BOM.

## Cursor / Copilot Rules
- No `.cursor/rules/`, `.cursorrules`, or `.github/copilot-instructions.md` found in this repository.
