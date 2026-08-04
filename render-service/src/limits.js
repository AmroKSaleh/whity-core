'use strict';

/**
 * Defense-in-depth batch-size ceilings (ADR 0012 / WC-docdesigner Track 2).
 *
 * The PRIMARY, operator-tunable limits live in whity-core's PHP settings
 * registry (documents.render_max_rows / _max_pages / _max_template_bytes —
 * see src/Core/Settings/SettingsRegistry.php) and are enforced BEFORE this
 * service is ever called. These are a second, hardcoded-with-env-override
 * line of defense inside the render service itself, in case it is ever
 * called by something other than the core PHP endpoint — never the primary
 * source of truth, just a sane ceiling this standalone service refuses to
 * exceed regardless of caller.
 */

const MAX_DATA_ROWS = Number(process.env.RENDER_HARD_MAX_ROWS || 2000);
const MAX_TOTAL_UNITS = Number(process.env.RENDER_HARD_MAX_UNITS || 5000);
const MAX_TEMPLATE_BYTES = Number(process.env.RENDER_HARD_MAX_TEMPLATE_BYTES || 10 * 1024 * 1024);

/**
 * Validate a render payload's shape + size against the hard ceilings.
 *
 * @param {unknown} payload
 * @returns {string|null} An error message, or null when valid.
 */
function validatePayload(payload) {
  if (typeof payload !== 'object' || payload === null || Array.isArray(payload)) {
    return 'Request body must be a JSON object';
  }
  const { template, dataRows } = payload;

  if (typeof template !== 'object' || template === null || Array.isArray(template)) {
    return '"template" must be an object';
  }
  if (!template.page || typeof template.page.widthMm !== 'number' || typeof template.page.heightMm !== 'number') {
    return '"template.page" must have numeric widthMm/heightMm';
  }
  if (!Array.isArray(template.pages) || template.pages.length === 0) {
    return '"template.pages" must be a non-empty array';
  }

  if (dataRows !== undefined) {
    if (!Array.isArray(dataRows)) {
      return '"dataRows" must be an array';
    }
    if (dataRows.length > MAX_DATA_ROWS) {
      return `"dataRows" exceeds the render service's hard limit (${MAX_DATA_ROWS} rows)`;
    }
    for (const row of dataRows) {
      if (typeof row !== 'object' || row === null || Array.isArray(row)) {
        return '"dataRows" entries must be flat objects';
      }
    }
  }

  const rows = Array.isArray(dataRows) && dataRows.length > 0 ? dataRows.length : 1;
  const totalUnits = rows * template.pages.length;
  if (totalUnits > MAX_TOTAL_UNITS) {
    return `Render would produce too many pages (${totalUnits}, hard limit ${MAX_TOTAL_UNITS})`;
  }

  const templateBytes = Buffer.byteLength(JSON.stringify(template), 'utf8');
  if (templateBytes > MAX_TEMPLATE_BYTES) {
    return `"template" exceeds the render service's hard size limit (${MAX_TEMPLATE_BYTES} bytes)`;
  }

  return null;
}

module.exports = { validatePayload, MAX_DATA_ROWS, MAX_TOTAL_UNITS, MAX_TEMPLATE_BYTES };
