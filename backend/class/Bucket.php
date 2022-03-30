<?php declare(strict_types = 1);
namespace noxkiwi\bucket;

use JetBrains\PhpStorm\NoReturn;
use noxkiwi\bucket\Exception\DownloadException;
use noxkiwi\bucket\Exception\FileHandlingException;
use noxkiwi\bucket\Interfaces\BucketInterface;
use noxkiwi\core\Environment;
use noxkiwi\core\Filesystem;
use noxkiwi\core\Helper\FilesystemHelper;
use noxkiwi\core\Helper\MimeHelper;
use noxkiwi\core\Helper\WebHelper;
use noxkiwi\log\Traits\LogTrait;
use noxkiwi\singleton\Singleton;
use function basename;
use function file_exists;
use function filemtime;
use function filesize;
use function flush;
use function gmdate;
use function header;
use function in_array;
use function is_readable;
use function is_writeable;
use function ob_clean;
use function preg_replace;
use function readfile;
use function str_replace;
use function str_starts_with;
use function time;
use function uniqid;
use function unlink;
use const E_ERROR;
use const E_WARNING;

/**
 * I am
 *
 * @package      noxkiwi\bucket
 * @author       Jan Nox <jan.nox@pm.me>
 * @license      https://nox.kiwi/license
 * @copyright    2020 noxkiwi
 * @version      1.0.0
 * @link         https://nox.kiwi/
 */
abstract class Bucket extends Singleton implements BucketInterface
{
    use LogTrait;

    protected const USE_DRIVER     = true;
    protected const TYPE           = 'bucket';
    public const    DOWNLOAD_FORCE = 'fdl';
    /** @var string Contains the base directory where all files in this Bucket will be stored. */
    protected string $baseDir;
    /** @var string I am the base URL for the Bucket. */
    protected string $baseUrl;
    /** @var bool I set the bucket to public (direct URLs). */
    protected bool $public;
    private array  $options;

    /**
     * Creates the instance, establishes the connection and authenticates
     *
     * @param array $data
     *
     */
    protected function __construct(array $data)
    {
        parent::__construct();
        $this->baseUrl = '';
        $this->public  = false;
        $this->baseDir = $data['basedir'];
        $this->public  = $data['public'];
        if ($this->public === true) {
            $this->baseUrl = $data['baseurl'];
        }
        $this->options = $data;
    }

    /**
     * @throws \noxkiwi\core\Exception
     * @return array
     */
    public static function getDrivers(): array
    {
        $buckets = Environment::getInstance()->get('bucket');
        $result  = [];
        foreach ($buckets as $bucketName => $bucketData) {
            $result[] = $bucketName;
        }

        return $result;
    }

    /**
     * @inheritDoc
     * @throws \noxkiwi\core\Exception
     */
    public function filePush(string $localFile, string $remoteFile): bool
    {
        if ($this->fileAvailable($remoteFile)) {
            return false;
        }
        if (! Filesystem::getInstance()->fileAvailable($localFile)) {
            return false;
        }
        $remoteDirectory = FilesystemHelper::getDirectory($remoteFile);
        if (! $this->dirAvailable($remoteDirectory)) {
            $this->dirCreate($remoteDirectory);
        }
        $this->sendFile($localFile, $remoteFile);

        return $this->fileAvailable($remoteFile);
    }

    /**
     * @inheritDoc
     */
    public function fileAvailable(string $remoteFile): bool
    {
        return $this->pathExists($remoteFile);
    }

    /**
     * @inheritDoc
     */
    public function dirAvailable(string $remoteDir): bool
    {
        if (! $this->fileAvailable($remoteDir)) {
            return false;
        }

        return $this->isDir($remoteDir);
    }

    /**
     * I am the nested method that will handle the upload of a file.
     *
     * @param string $localFile
     * @param string $remoteFile
     *
     * @return mixed
     */
    abstract protected function sendFile(string $localFile, string $remoteFile): bool;

    /**
     * @inheritDoc
     */
    public function fileGetUrl(string $remoteFile): string
    {
        if (! $this->public) {
            throw new FileHandlingException("$remoteFile cannot be downloaded, bucket is private.", E_ERROR);
        }
        if (! $this->fileAvailable($this->normalizePath($remoteFile))) {
            throw new FileHandlingException("$remoteFile is not available.", E_ERROR);
        }

        return $this->baseUrl . $remoteFile;
    }

    /**
     * This method normaliuzes the remote path by trying
     * <br />to prepend the basepath if it is not prepended yet.
     *
     * @param string $path
     *
     * @return       string
     */
    final protected function normalizePath(string $path): string
    {
        $path = preg_replace('/\.+[\/\]]+/', '', $path);
        if (str_starts_with($path, $this->baseDir)) {
            return (string)str_replace('//', '/', $path);
        }

        return (string)str_replace('//', '/', $this->baseDir . $path);
    }

    /**
     * @inheritDoc
     *
     * @throws \noxkiwi\singleton\Exception\SingletonException
     * @throws \noxkiwi\bucket\Exception\DownloadException
     */
    #[NoReturn] final public function download(string $remoteFile, string $fileName = null): void
    {
        $mimeType = MimeHelper::getFromFile($remoteFile);
        if (! empty($this->options['download']['forbidden_mime_types'])) {
            if (in_array($mimeType, $this->options['download']['forbidden_mime_types'], true)) {
                throw new DownloadException("Downloading $mimeType files is forbidden for security reasons.", E_WARNING);
            }
        }
        $fileName ??= basename($remoteFile);
        if (! $this->fileAvailable($remoteFile)) {
            throw new DownloadException("Original file $remoteFile was not found.", E_WARNING);
        }
        $tempFile = Environment::getInstance()->get('paths>temp') . uniqid((string)time(), true);
        $this->filePull($remoteFile, $tempFile);
        if (! file_exists($tempFile)) {
            throw new DownloadException("Tempfile $tempFile was not found.", E_WARNING);
        }
        if (! is_readable($tempFile)) {
            throw new DownloadException("Tempfile $tempFile is not readable.", E_WARNING);
        }
        if (! is_writeable($tempFile)) {
            throw new DownloadException("Tempfile $tempFile is not writeable.", E_WARNING);
        }
        header('Expires: ' . gmdate('D, d M Y H:i:s', filemtime($tempFile)) . ' GMT');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($tempFile)) . ' GMT', true, 200);
        header('Cache-Control: no-cache, no-store, must-revalidate, post-check=0, pre-check=0, max-age=0, post-check=0, pre-check=0');
        header('Cache-Control: public', false);
        header('Content-type: application/octet-stream');
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Transfer-Encoding: binary');
        header('Pragma: public');
        header('Content-Length: ' . filesize($tempFile));
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        ob_clean();
        flush();
        readfile($tempFile);
        unlink($tempFile);
        exit(WebHelper::HTTP_OKAY);
    }

    /**
     * @inheritDoc
     */
    public function filePull(string $remoteFile, string $localFile): bool
    {
        $remoteFile = $this->normalizePath($remoteFile);
        if (! $this->fileAvailable($remoteFile)) {
            throw new FileHandlingException("$remoteFile is not available.", E_ERROR);
        }
        if (Filesystem::getInstance()->fileAvailable($localFile)) {
            throw new FileHandlingException("$localFile already exists.", E_ERROR);
        }
        $localDirectory = FilesystemHelper::getDirectory($localFile);
        if (Filesystem::getInstance()->dirAvailable($localDirectory)) {
            Filesystem::getInstance()->dirCreate($localDirectory);
        }
        $this->pullFile($remoteFile, $localFile);

        return Filesystem::getInstance()->fileAvailable($localFile);
    }

    /**
     * I will pull the file from $remoteFile to $localFile
     *
     * @param string $remoteFile
     * @param string $localFile
     */
    abstract protected function pullFile(string $remoteFile, string $localFile): void;

    /**
     * @inheritDoc
     */
    final public function isPublic(): bool
    {
        return $this->public;
    }

    /**
     * I will extract the directory path from $filename
     * <br /><b>example:</b>
     * <br />$name = extractDirectory('/path/to/file.mp3');
     * <br />
     * <br />// $name is now '/path/to/'
     *
     * @deprecated Use FilesystemHelper::getDirectory() instead!
     *
     * @param string $filename
     *
     * @return       string
     */
    protected function extractDirectory(string $filename): string
    {
        return FilesystemHelper::getDirectory($filename);
    }
}

