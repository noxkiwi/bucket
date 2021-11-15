<?php declare(strict_types = 1);
namespace noxkiwi\bucket\bucket;

use noxkiwi\bucket\bucket;
use noxkiwi\bucket\Exception\FileHandlingException;
use noxkiwi\core\Exception\FilesystemException;
use noxkiwi\core\Exception\InvalidArgumentException;
use noxkiwi\core\File\LocalFile;
use noxkiwi\core\Filesystem;
use noxkiwi\core\Helper\FilesystemHelper;
use noxkiwi\validator\Validator\Structure\Config\bucket\LocalValidator;
use function is_array;
use function is_dir;
use const E_WARNING;

/**
 * I am the Bucket for local files
 *
 * @package      noxkiwi\bucket
 * @author       Jan Nox <jan@nox.kiwi>
 * @license      https://nox.kiwi/license
 * @copyright    2020 noxkiwi
 * @version      1.0.0
 * @link         https://nox.kiwi/
 */
final class LocalBucket extends Bucket
{
    /** @var \noxkiwi\core\Filesystem I am the Filesystem instance to use. */
    private Filesystem $fileSystem;

    /**
     * I am the constructor
     *
     * @param array $data
     *
     * @throws \noxkiwi\core\Exception
     * @throws \noxkiwi\core\Exception\InvalidArgumentException
     */
    protected function __construct(array $data)
    {
        parent::__construct($data);
        $this->fileSystem = Filesystem::getInstance();
        $errors           = LocalValidator::getInstance()->validate($data);
        if (! empty($errors)) {
            $this->errorStack->addError('CONFIGURATION_INVALID', $errors);
            throw new InvalidArgumentException('EXCEPTION_CONSTRUCT_CONFIGURATIONINVALID', E_WARNING, $errors);
        }
    }

    /**
     * @inheritDoc
     * @throws \noxkiwi\core\Exception
     */
    public function dirDelete(string $remoteDir): bool
    {
        $remoteDir = $this->normalizePath($remoteDir);
        if (! $this->pathExists($remoteDir)) {
            throw new FileHandlingException("Path $remoteDir does not exist.", E_WARNING);
        }
        if (! $this->isDir($remoteDir)) {
            throw new FileHandlingException("Path $remoteDir is no directory.", E_WARNING);
        }
        $elements = $this->dirList($remoteDir);
        foreach ($elements as $element) {
            $element = $remoteDir . '/' . $element;
            if ($this->isDir($element)) {
                $this->dirDelete($element);
                continue;
            }
            $this->fileDelete($element);
        }
        $this->fileSystem->dirDelete($remoteDir);

        return $this->dirAvailable($remoteDir);
    }

    /**
     * @inheritDoc
     */
    public function isDir(string $remoteDir): bool
    {
        return $this->fileSystem->isDirectory($this->normalizePath($remoteDir));
    }

    /**
     * @inheritDoc
     */
    public function dirList(string $remoteDir): array
    {
        $remoteDir = $this->normalizePath($remoteDir);
        if (! $this->dirAvailable($remoteDir)) {
            $this->errorStack->addError('REMOTE_DIRECTORY_NOT_FOUND', $remoteDir);

            return [];
        }

        return $this->fileSystem->dirList($remoteDir);
    }

    /**
     * @inheritDoc
     * @throws \noxkiwi\bucket\Exception\FileHandlingException
     */
    public function fileDelete(string $remoteFile): bool
    {
        $remoteFile = $this->normalizePath($remoteFile);
        if (! $this->pathExists($remoteFile)) {
            throw new FileHandlingException("Path $remoteFile does not exist.", E_WARNING);
        }
        if (! $this->isFile($remoteFile)) {
            throw new FileHandlingException("Path $remoteFile is not a file.", E_WARNING);
        }
        $this->fileSystem->fileDelete($remoteFile);

        return ! $this->fileAvailable($remoteFile);
    }

    /**
     * @inheritDoc
     */
    public function isFile(string $remoteFile): bool
    {
        return $this->fileSystem->isFile($this->normalizePath($remoteFile));
    }

    /**
     * @inheritDoc
     */
    public function fileAvailable(string $remoteFile): bool
    {
        parent::fileAvailable($remoteFile);
        $remoteFile = $this->normalizePath($remoteFile);
        $this->logDebug('Searching file ' . $remoteFile);

        return $this->fileSystem->fileAvailable($remoteFile);
    }

    /**
     * @inheritDoc
     */
    public function dirCreate(string $remoteDir): bool
    {
        if ($this->dirAvailable($remoteDir)) {
            return true;
        }
        $concurrentDirectory = $this->normalizePath($remoteDir);
        if (! mkdir($concurrentDirectory) && ! is_dir($concurrentDirectory)) {
            return false;
        }

        return $this->dirAvailable($remoteDir);
    }

    /**
     * @inheritDoc
     */
    public function pathExists(string $remotePath): bool
    {
        return $this->fileSystem->fileAvailable($remotePath, true);
    }

    /**
     * @inheritDoc
     */
    public function dirListDetailed(string $remoteDir): array
    {
        $remoteDir = $this->normalizePath($remoteDir);
        $children  = $this->dirList($remoteDir);
        if (! is_array($children)) {
            return [];
        }
        $items = [];
        foreach ($children as $child) {
            $items[$child] = new LocalFile($this->fileGetInfo($remoteDir . '/' . $child));
        }
        ksort($items);

        return $items;
    }

    /**
     * @inheritDoc
     */
    public function fileGetInfo(string $remoteFile): array
    {
        $remoteFile = $this->normalizePath($remoteFile);
        $isDir      = is_dir($remoteFile);

        return [
            'name'        => FilesystemHelper::getFileName($remoteFile),
            'size'        => ! $isDir ? self::fileGetSize($remoteFile) : 0,
            'user'        => '',
            'group'       => '',
            'permissions' => '',
            'extension'   => ! $isDir ? FilesystemHelper::getFileExtension($remoteFile) : '',
            'type'        => $isDir ? Filesystem::TYPE_DIRECTORY : Filesystem::TYPE_FILE
        ];
    }

    /**
     * I will return the size of the given $remotefile in bytes.
     *
     * @param string $remoteFile
     *
     * @return int
     */
    private static function fileGetSize(string $remoteFile): int
    {
        $bytes = filesize($remoteFile);
        if ($bytes === false) {
            return 0;
        }

        return $bytes;
    }

    /**
     * @inheritDoc
     *
     * @throws \noxkiwi\core\Exception\FilesystemException
     */
    protected function sendFile(string $localFile, string $remoteFile): bool
    {
        $remoteFile = $this->normalizePath($remoteFile);
        if (! $this->fileSystem->fileCopy($localFile, $remoteFile)) {
            if (! is_writable($remoteFile)) {
                throw new FilesystemException('EXCEPTION_FILEPUSH_FILENOTWRITABLE', E_WARNING, $remoteFile);
            }
            throw new FilesystemException('EXCEPTION_FILEPUSH_FILEWRITABLE_UNKNOWN_ERROR', E_WARNING, $remoteFile);
        }

        return $this->fileAvailable($remoteFile);
    }

    /**
     * @inheritDoc
     */
    protected function pullFile(string $remoteFile, string $localFile): void
    {
        $this->fileSystem->fileCopy($remoteFile, $localFile);
    }
}

