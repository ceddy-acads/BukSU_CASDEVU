<?php
/**
 * CASMS — Secure file uploads (FR-5.3, FR-5.4)
 *
 * Files land outside the web root under storage/uploads/, renamed to random
 * bytes. The original filename is kept in the database for display only, so a
 * crafted name such as "../../evil.php" can never influence where we write.
 */

declare(strict_types=1);

/**
 * Validate and store one uploaded file.
 *
 * @param  array<string, mixed> $file  One entry from $_FILES
 * @param  string               $subdirectory  e.g. 'requirements'
 * @return array{ok: bool, error?: string, path?: string, original?: string,
 *               mime?: string, size?: int}
 */
function store_upload(array $file, string $subdirectory): array
{
    // ------------------------------------------------------- PHP-level errors
    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'No file was selected.'];
    }
    if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'error' => 'That file is too large.'];
    }
    if ($uploadError !== UPLOAD_ERR_OK) {
        error_log('[CASMS] Upload failed with PHP error code ' . $uploadError);
        return ['ok' => false, 'error' => 'The file could not be uploaded. Please try again.'];
    }

    $temporaryPath = (string) ($file['tmp_name'] ?? '');

    // Confirms the file really came through an HTTP upload, not a local path
    // an attacker managed to inject.
    if (!is_uploaded_file($temporaryPath)) {
        return ['ok' => false, 'error' => 'The upload could not be verified.'];
    }

    // ------------------------------------------------------------------ Size
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        return ['ok' => false, 'error' => 'The file appears to be empty.'];
    }
    if ($size > MAX_UPLOAD_BYTES) {
        return ['ok' => false, 'error' => 'That file is larger than '
            . (int) (MAX_UPLOAD_BYTES / 1024 / 1024) . ' MB.'];
    }

    // ------------------------------------------------------------------ Type
    // Read the real type from the file's contents. The browser-supplied
    // type and the extension are both attacker-controlled, so neither decides.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string) $finfo->file($temporaryPath);

    if (!array_key_exists($mime, ALLOWED_UPLOAD_MIME)) {
        return ['ok' => false, 'error' => 'Only PDF, JPG, and PNG files are accepted.'];
    }

    // The extension is taken from our own whitelist, never from the upload.
    $extension = ALLOWED_UPLOAD_MIME[$mime];

    // ----------------------------------------------------------------- Store
    $directory = UPLOAD_PATH . '/' . $subdirectory;
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        error_log('[CASMS] Could not create upload directory: ' . $directory);
        return ['ok' => false, 'error' => 'The file could not be saved. Please contact the office.'];
    }

    $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
    $target     = $directory . '/' . $storedName;

    if (!move_uploaded_file($temporaryPath, $target)) {
        error_log('[CASMS] move_uploaded_file failed for ' . $target);
        return ['ok' => false, 'error' => 'The file could not be saved. Please try again.'];
    }

    @chmod($target, 0644);

    return [
        'ok'       => true,
        'path'     => $subdirectory . '/' . $storedName,   // relative to UPLOAD_PATH
        'original' => sanitize_filename((string) ($file['name'] ?? 'document')),
        'mime'     => $mime,
        'size'     => $size,
    ];
}

/**
 * Clean a filename for display and for the Content-Disposition header.
 * Strips directory separators and control characters.
 */
function sanitize_filename(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[\x00-\x1F\x7F"]+/', '', $name) ?? '';
    $name = trim($name);

    return $name === '' ? 'document' : mb_substr($name, 0, 200);
}

/** Absolute path of a stored upload, or null when it is missing. */
function upload_absolute_path(?string $relativePath): ?string
{
    if (!$relativePath) {
        return null;
    }

    // Resolve and confirm the result is still inside UPLOAD_PATH — defence
    // against any stored value containing traversal segments.
    $candidate = realpath(UPLOAD_PATH . '/' . $relativePath);
    $root      = realpath(UPLOAD_PATH);

    if ($candidate === false || $root === false) {
        return null;
    }
    if (!str_starts_with(str_replace('\\', '/', $candidate), str_replace('\\', '/', $root) . '/')) {
        return null;
    }

    return $candidate;
}

/** Delete a stored upload, ignoring a file that is already gone. */
function delete_upload(?string $relativePath): void
{
    $absolute = upload_absolute_path($relativePath);
    if ($absolute !== null && is_file($absolute)) {
        @unlink($absolute);
    }
}

/** '2.4 MB', '812 KB' */
function format_filesize(?int $bytes): string
{
    if (!$bytes) {
        return 'Unknown';
    }
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    return max(1, (int) round($bytes / 1024)) . ' KB';
}

// =====================================================================
// Profile photos
// =====================================================================

/** A profile photo stays small: the browser already resizes it to 512 px. */
const PROFILE_PHOTO_MAX_BYTES = 2 * 1024 * 1024;

/**
 * Validate and store a profile photo: JPG or PNG only, at most 2 MB, with
 * JPG metadata (camera details, GPS location) removed. The browser normally
 * crops, resizes and re-encodes the photo first, which already drops that
 * metadata; stripping here covers uploads made without JavaScript.
 *
 * @param  array<string, mixed> $file One entry from $_FILES
 * @return array{ok: bool, error?: string, path?: string}
 */
function store_profile_photo(array $file): array
{
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
        && (int) ($file['size'] ?? 0) > PROFILE_PHOTO_MAX_BYTES) {
        return ['ok' => false, 'error' => 'That photo is larger than 2 MB. Choose a smaller one.'];
    }

    $stored = store_upload($file, 'avatars');
    if (!$stored['ok']) {
        // Reword the general upload messages for a photo.
        return ['ok' => false, 'error' => match ($stored['error']) {
            'No file was selected.'                      => 'Choose a photo first.',
            'Only PDF, JPG, and PNG files are accepted.' => 'Choose a JPG or PNG image.',
            default                                      => $stored['error'],
        }];
    }

    if (!in_array($stored['mime'], ['image/jpeg', 'image/png'], true)) {
        delete_upload($stored['path']);
        return ['ok' => false, 'error' => 'Choose a JPG or PNG image.'];
    }

    if ($stored['mime'] === 'image/jpeg') {
        $absolute = upload_absolute_path($stored['path']);
        if ($absolute === null || !strip_jpeg_metadata($absolute)) {
            delete_upload($stored['path']);
            return ['ok' => false, 'error' => 'That photo could not be read. Try another JPG or PNG.'];
        }
    }

    return ['ok' => true, 'path' => $stored['path']];
}

/**
 * Remove metadata segments from a JPEG in place: EXIF and XMP (APP1), the
 * other application segments, and comments. Kept: JFIF (APP0), the colour
 * profile (APP2) and Adobe colour data (APP14), which affect how it looks.
 * Pure PHP, because the GD extension is not available on this server.
 * Returns false for anything that does not parse as a JPEG.
 */
function strip_jpeg_metadata(string $path): bool
{
    $data = @file_get_contents($path);
    if ($data === false || strlen($data) < 4 || substr($data, 0, 2) !== "\xFF\xD8") {
        return false;
    }

    $out    = "\xFF\xD8";
    $pos    = 2;
    $length = strlen($data);

    while ($pos + 4 <= $length) {
        if ($data[$pos] !== "\xFF") {
            return false;
        }
        $marker = ord($data[$pos + 1]);

        if ($marker === 0xFF) {          // fill byte before a marker
            $pos++;
            continue;
        }
        if ($marker === 0xDA) {          // start of scan: image data to the end
            $out .= substr($data, $pos);
            return file_put_contents($path, $out) !== false;
        }
        if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
            $out .= substr($data, $pos, 2);   // markers without a length
            $pos += 2;
            continue;
        }

        $segmentLength = (ord($data[$pos + 2]) << 8) | ord($data[$pos + 3]);
        if ($segmentLength < 2 || $pos + 2 + $segmentLength > $length) {
            return false;
        }

        $isMetadata = ($marker >= 0xE1 && $marker <= 0xEF && $marker !== 0xE2 && $marker !== 0xEE)
                   || $marker === 0xFE;
        if (!$isMetadata) {
            $out .= substr($data, $pos, 2 + $segmentLength);
        }
        $pos += 2 + $segmentLength;
    }

    return false;                        // no image data found
}
