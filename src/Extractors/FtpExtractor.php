<?php

declare(strict_types=1);

namespace Jwhulette\Pipes\Extractors;

use Generator;
use Jwhulette\Pipes\Contracts\ExtractorInterface;
use Jwhulette\Pipes\Frame;

final class FtpExtractor implements ExtractorInterface
{
    protected Frame $frame;

    protected string $host;

    protected string $username;

    protected string $password;

    protected int $port = 21;

    protected bool $passive = true;

    /** @var array<int, string> */
    protected array $remoteFiles;

    protected ?string $localTempFile = null;

    /** @var resource|\FTP\Connection|false|null */
    protected $connection = null;

    protected ?ExtractorInterface $fileExtractor = null;

    protected int $timeout = 90;

    /**
     * @param string|array<int, string> $remoteFiles
     */
    public function __construct(string $host, string $username, string $password, string|array $remoteFiles)
    {
        $this->host = $host;
        $this->username = $username;
        $this->password = $password;
        $this->remoteFiles = is_array($remoteFiles) ? $remoteFiles : [$remoteFiles];
        $this->frame = new Frame();
    }

    public function extract(): Generator
    {
        try {
            // Connect to FTP server
            $this->connect();

            // Process each remote file
            foreach ($this->remoteFiles as $remoteFile) {
                // Download file to temporary location
                $this->downloadFile($remoteFile);

                // Extract data based on file type
                if ($this->fileExtractor === null) {
                    $this->detectFileType($remoteFile);
                }

                // Yield data from the file extractor
                if ($this->fileExtractor !== null) {
                    foreach ($this->fileExtractor->extract() as $frame) {
                        if ($frame instanceof Frame && !$frame->getEnd()) {
                            // Add source file information
                            $frameData = $frame->getData();
                            $frameData['_source_file'] = $remoteFile;
                            $frame->setData($frameData->toArray());
                            
                            // Add table attribute for XML files (useful for SqliteMergeTransformer)
                            $extension = strtolower(pathinfo($remoteFile, PATHINFO_EXTENSION));
                            if ($extension === 'xml') {
                                // Use filename without extension as table name
                                $tableName = pathinfo($remoteFile, PATHINFO_FILENAME);
                                $frame->setAttribute(['table' => $tableName]);
                            }
                        }
                        yield $frame;
                    }
                }

                // Clean up temp file after each extraction
                if ($this->localTempFile && file_exists($this->localTempFile)) {
                    unlink($this->localTempFile);
                    $this->localTempFile = null;
                }

                // Reset file extractor for next file
                $this->fileExtractor = null;
            }
        } finally {
            // Clean up
            $this->cleanup();
        }
    }

    /**
     * Connect to the FTP server.
     */
    protected function connect(): void
    {
        $this->connection = ftp_connect($this->host, $this->port, $this->timeout);

        if (! $this->connection) {
            throw new \Exception("Failed to connect to FTP server: {$this->host}:{$this->port}");
        }

        if (! ftp_login($this->connection, $this->username, $this->password)) {
            throw new \Exception("FTP login failed for user: {$this->username}");
        }

        if ($this->passive) {
            ftp_pasv($this->connection, true);
        }
    }

    /**
     * Download file from FTP server.
     */
    protected function downloadFile(string $remoteFile): void
    {
        // Create temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'ftp_extract_');

        if ($tempFile === false) {
            throw new \Exception('Failed to create temporary file');
        }

        $this->localTempFile = $tempFile;

        // Download file
        if ($this->connection !== false && $this->connection !== null) {
            /** @var resource|\FTP\Connection $connection */
            $connection = $this->connection;
            // @phpstan-ignore-next-line
            if (! ftp_get($connection, $this->localTempFile, $remoteFile, FTP_BINARY)) {
                throw new \Exception("Failed to download file: {$remoteFile}");
            }
        }
    }

    /**
     * Detect file type and create appropriate extractor.
     */
    protected function detectFileType(string $remoteFile): void
    {
        $extension = strtolower(pathinfo($remoteFile, PATHINFO_EXTENSION));

        switch ($extension) {
            case 'csv':
                if ($this->localTempFile !== null) {
                    $this->fileExtractor = new CsvExtractor($this->localTempFile);
                }
                break;
            case 'xlsx':
            case 'xls':
                if ($this->localTempFile !== null) {
                    $this->fileExtractor = new XlsxExtractor($this->localTempFile);
                }
                break;
            case 'xml':
                if ($this->localTempFile !== null) {
                    // Check file size to decide between streaming and simple XML extractor
                    $fileSize = filesize($this->localTempFile);
                    if ($fileSize !== false && $fileSize > 10 * 1024 * 1024) { // 10MB threshold
                        // For large files, use streaming extractor
                        // Assume we'll extract the root element's children
                        $this->fileExtractor = new StreamingXmlExtractor($this->localTempFile, '*');
                    } else {
                        // For smaller files, use simple XML extractor
                        $this->fileExtractor = new XmlExtractor($this->localTempFile, '*');
                    }
                }
                break;
            case 'json':
                // Would need to create JsonExtractor or use existing one
                throw new \Exception('JSON extraction not yet implemented. Please set a custom extractor.');
            default:
                throw new \Exception("Unsupported file type: {$extension}. Please set a custom extractor using setFileExtractor()");
        }
    }

    /**
     * Clean up resources.
     */
    protected function cleanup(): void
    {
        // Close FTP connection
        if ($this->connection !== false && $this->connection !== null) {
            /** @var resource|\FTP\Connection $connection */
            $connection = $this->connection;
            // @phpstan-ignore-next-line
            ftp_close($connection);
            $this->connection = null;
        }

        // Remove temporary file
        if ($this->localTempFile && file_exists($this->localTempFile)) {
            unlink($this->localTempFile);
            $this->localTempFile = null;
        }
    }

    /**
     * Set custom port.
     */
    public function setPort(int $port): self
    {
        $this->port = $port;

        return $this;
    }

    /**
     * Set passive mode.
     */
    public function setPassive(bool $passive): self
    {
        $this->passive = $passive;

        return $this;
    }

    /**
     * Set connection timeout in seconds.
     */
    public function setTimeout(int $seconds): self
    {
        $this->timeout = $seconds;

        return $this;
    }

    /**
     * Set a custom file extractor for the downloaded file.
     */
    public function setFileExtractor(ExtractorInterface $extractor): self
    {
        $this->fileExtractor = $extractor;

        return $this;
    }

    /**
     * Add additional remote files to extract.
     * @param string|array<int, string> $remoteFiles
     */
    public function addRemoteFiles(string|array $remoteFiles): self
    {
        $files = is_array($remoteFiles) ? $remoteFiles : [$remoteFiles];
        $this->remoteFiles = array_merge($this->remoteFiles, $files);

        return $this;
    }

    /**
     * Set pattern for wildcard file matching
     * This replaces the current remote files with files matching the pattern.
     */
    public function withPattern(string $pattern, ?string $directory = null): self
    {
        try {
            $this->connect();

            // Use directory from first remote file if not specified
            if ($directory === null && ! empty($this->remoteFiles)) {
                $directory = dirname($this->remoteFiles[0]);
            }

            if ($directory === null) {
                $directory = '/';
            }

            // Get list of files matching pattern
            $this->remoteFiles = $this->getMatchingFiles($pattern, $directory);

            if (empty($this->remoteFiles)) {
                throw new \Exception("No files found matching pattern: {$pattern} in directory: {$directory}");
            }
        } finally {
            $this->cleanup();
        }

        return $this;
    }

    /**
     * Get files matching pattern.
     * @return array<int, string>
     */
    protected function getMatchingFiles(string $pattern, string $directory): array
    {
        $filePattern = basename($pattern);

        if ($this->connection === false || $this->connection === null) {
            return [];
        }

        /** @var resource|\FTP\Connection $connection */
        $connection = $this->connection;
        // @phpstan-ignore-next-line
        $allFiles = ftp_nlist($connection, $directory);

        if (! $allFiles) {
            return [];
        }

        // Filter files by pattern
        $matchingFiles = [];
        foreach ($allFiles as $file) {
            $filename = basename($file);
            if (fnmatch($filePattern, $filename)) {
                $matchingFiles[] = $file;
            }
        }

        return $matchingFiles;
    }

    /**
     * Create instance with basic authentication.
     * @param string|array<int, string> $remoteFiles
     */
    public static function make(string $host, string $username, string $password, string|array $remoteFiles): static
    {
        return new static($host, $username, $password, $remoteFiles);
    }

    /**
     * Create instance for anonymous FTP.
     * @param string|array<int, string> $remoteFiles
     */
    public static function anonymous(string $host, string|array $remoteFiles): static
    {
        return new static($host, 'anonymous', 'anonymous@example.com', $remoteFiles);
    }
}
