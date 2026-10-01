<?php

/**
 * Hotel Excel import (platform administration).
 *
 * Uploads are staged on disk and parsed with a streaming reader, so memory
 * stays flat regardless of file size. The PHP upload ceiling
 * (`upload_max_filesize` / `post_max_size`) still applies and must be raised
 * server-side to accept files above the default 2M/8M.
 */
return [
    /** Hard ceiling for the uploaded workbook, in kilobytes. */
    'max_upload_kb' => (int) env('HOTEL_IMPORT_MAX_UPLOAD_KB', 20480),

    /** Maximum number of data rows processed in one import. */
    'max_rows' => (int) env('HOTEL_IMPORT_MAX_ROWS', 20000),

    /** Data rows returned to the administrator as a preview after detection. */
    'preview_rows' => (int) env('HOTEL_IMPORT_PREVIEW_ROWS', 8),

    /** Rows buffered before each bulk insert. */
    'chunk_size' => (int) env('HOTEL_IMPORT_CHUNK_SIZE', 250),

    /** Upper bound on persisted duplicate/invalid row issues (audit detail). */
    'max_reported_issues' => (int) env('HOTEL_IMPORT_MAX_ISSUES', 200),

    /** How long a staged upload stays importable, in minutes. */
    'staged_ttl_minutes' => (int) env('HOTEL_IMPORT_STAGED_TTL', 240),
];
