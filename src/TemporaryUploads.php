<?php
declare(strict_types=1);
ini_set('zend.exception_ignore_args', '1');

/** Owns only generated .upload files in a configured private directory. No upload endpoint. */
final class TemporaryUploads
{
    private string $directory;
    public function __construct(string $directory, string $publicRoot)
    {
        $dir = realpath($directory); $public = realpath($publicRoot);
        if ($dir === false || $public === false || !is_dir($dir) || is_link($directory)) { throw new RuntimeException('INVALID_UPLOAD_DIRECTORY'); }
        $norm = strtolower(str_replace('\\', '/', $dir));
        $web = strtolower(str_replace('\\', '/', $public));
        if ($norm === $web || str_starts_with($norm . '/', $web . '/')) { throw new RuntimeException('UPLOAD_DIRECTORY_IS_PUBLIC'); }
        $this->directory = $dir;
    }

    /** The callback receives a stream, not the original client filename. */
    public function processUploadedFile(string $phpTemporaryPath, callable $processor): mixed
    {
        // Never delete an arbitrary filesystem path supplied by a request.
        if (!is_uploaded_file($phpTemporaryPath)) { throw new RuntimeException('INVALID_HTTP_UPLOAD'); }
        $input = null;
        try {
            $input = fopen($phpTemporaryPath, 'rb');
            if ($input === false) { throw new RuntimeException('UPLOAD_OPEN_FAILED'); }
            return $this->processStream($input, $processor);
        } catch (Throwable) {
            throw new RuntimeException('PROCESSING_FAILED');
        } finally {
            if (is_resource($input)) { fclose($input); }
            if (is_file($phpTemporaryPath) && !unlink($phpTemporaryPath)) { throw new RuntimeException('UPLOAD_DELETE_FAILED'); }
        }
    }

    /** Stream entry point supports CLI/tests. Caller owns its input; managed copy is always deleted. */
    public function processStream($input, callable $processor): mixed
    {
        if (!is_resource($input)) { throw new RuntimeException('INVALID_UPLOAD_STREAM'); }
        $path = $this->directory . DIRECTORY_SEPARATOR . bin2hex(random_bytes(24)) . '.upload';
        $stream = fopen($path, 'x+b');
        if ($stream === false) { throw new RuntimeException('UPLOAD_CREATE_FAILED'); }
        try {
            if (!chmod($path, 0600) || !flock($stream, LOCK_EX)) { throw new RuntimeException('UPLOAD_LOCK_FAILED'); }
            if (stream_copy_to_stream($input, $stream) === false) { throw new RuntimeException('UPLOAD_COPY_FAILED'); }
            rewind($stream);
            return $processor($stream);
        } catch (Throwable) {
            // Do not attach the original exception: its message or trace could contain source values.
            throw new RuntimeException('PROCESSING_FAILED');
        } finally {
            // Windows requires closing before unlink. Cleanup also handles process crashes.
            fclose($stream);
            if (is_file($path) && !unlink($path)) { throw new RuntimeException('UPLOAD_DELETE_FAILED'); }
        }
    }

    /** maxAgeSeconds is an operational abandoned-job timeout, not a personal-data retention policy. */
    public function cleanup(int $maxAgeSeconds, ?int $now = null): int
    {
        if ($maxAgeSeconds < 1) { throw new RuntimeException('INVALID_CLEANUP_AGE'); }
        $count = 0; $now ??= time();
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.upload') as $path) {
            if (!preg_match('/^[a-f0-9]{48}\.upload$/D', basename($path)) || is_link($path) || filemtime($path) > $now - $maxAgeSeconds) { continue; }
            $stream = fopen($path, 'r+b');
            if ($stream === false) { continue; }
            if (!flock($stream, LOCK_EX | LOCK_NB)) { fclose($stream); continue; }
            fclose($stream);
            if (!unlink($path)) { throw new RuntimeException('UPLOAD_DELETE_FAILED'); }
            $count++;
        }
        return $count;
    }
}
