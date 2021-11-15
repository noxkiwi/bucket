<?php declare(strict_types = 1);
namespace noxkiwi\bucket\bucket;

use noxkiwi\bucket\bucket;
use noxkiwi\validator\Validator\Structure\Config\Bucket\FtpValidator;
use noxkiwi\core\Config;
use noxkiwi\core\Exception\AuthenticationException;
use noxkiwi\core\Exception\ConfigurationException;
use noxkiwi\core\Exception\ConnectionException;
use noxkiwi\core\Exception\SystemComponentException;
use noxkiwi\core\File\FtpFile;
use noxkiwi\core\Filesystem;
use noxkiwi\core\Helper\FilesystemHelper;
use function count;
use function extension_loaded;
use function in_array;
use function is_array;
use const E_WARNING;

/**
 * I am the Ftp driver for the noxkiwi Bucket system.
 * <br />I am capable of transferring files and directories with the methods of the BucketInterface.
 * <br />I can handle connections using FTP and FTPS. Maybe SFTP (?)
 *
 * @package      noxkiwi\bucket
 * @author       Jan Nox <jan@nox.kiwi>
 * @license      https://nox.kiwi/license
 * @copyright    2020 noxkiwi
 * @version      1.0.0
 * @link         https://nox.kiwi/
 */
final class FtpBucket extends Bucket
{
    /** @var resource */
    private $connection;
    /** @var \noxkiwi\core\Config */
    private Config $config;

    /**
     * I am the constructor of the FTP Bucket driver.
     * <br />I will establish the connection and authenticate at the server.
     *
     * @param array $data
     *
     * @throws \noxkiwi\core\Exception
     * @throws \noxkiwi\core\Exception\AuthenticationException in case the given user & pass combination doesn't lead into a login.
     * @throws \noxkiwi\core\Exception\ConfigurationException in case the given $data object cannot be validated without errors.
     * @throws \noxkiwi\core\Exception\ConnectionException in case the given host & port combination cannot create a conection.
     * @throws \noxkiwi\core\Exception\SystemComponentException in case the FTP Extension is unavailable.
     */
    protected function __construct(array $data)
    {
        if (! extension_loaded('ftp')) {
            throw new SystemComponentException('MISSING_EXTENSION_FTP', E_ERROR, 'You must install and enable php_ftp to use me.');
        }
        parent::__construct($data);
        $errors = FtpValidator::getInstance()->validate($data);
        if (! empty($errors)) {
            throw new ConfigurationException('EXCEPTION_CONSTRUCTOR_CONFIG_INVALID', E_WARNING, $errors);
        }
        $this->config = new Config($data);
        if ($this->config->get('ftpserver>secure', false) === true) {
            $this->connection = ftp_ssl_connect($this->config->get('ftpserver>host'), $this->config->get('ftpserver>port'), 2);
        } else {
            $this->connection = ftp_connect($this->config->get('ftpserver>host'), $this->config->get('ftpserver>port'), 2);
        }
        if ($this->connection === false) {
            throw new ConnectionException('EXCEPTION_CONSTRUCTOR_CONNECTION_FAILED', E_WARNING);
        }
        if (! ftp_login($this->connection, $this->config->get('ftpserver>user'), $this->config->get('ftpserver>pass'))) {
            throw new AuthenticationException('EXCEPTION_CONSTRUCTOR_LOGIN_FAILED', E_WARNING);
        }
        ftp_pasv($this->connection, true);
    }

    /**
     * @inheritDoc
     */
    public function isFile(string $remoteFile): bool
    {
        if (! $this->pathExists($remoteFile)) {
            return false;
        }
        $files = $this->getRawlist(FilesystemHelper::getDirectory($remoteFile));
        if (empty($files)) {
            return false;
        }
        $remoteFile = FilesystemHelper::getFileName($remoteFile);
        if ($remoteFile === '') {
            return false;
        }
        foreach ($files as $file) {
            if (str_contains($file, $remoteFile)) {
                return $file[0] !== 'd';
            }
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function pathExists(string $remotePath): bool
    {
        $remotePathData = explode('/', $remotePath);
        $filename       = $remotePathData[count($remotePathData) - 1];
        unset($remotePathData[count($remotePathData) - 1]);
        $directory = implode('/', $remotePathData);
        $dirlist   = $this->dirList($directory);

        return in_array($filename, $dirlist, true);
    }

    /**
     * @inheritDoc
     */
    public function dirList(string $remoteDir): array
    {
        $list   = $this->getDirlist($remoteDir);
        $myList = [];
        if (! is_array($list)) {
            return $myList;
        }
        foreach ($list as $element) {
            $myList[] = str_replace([str_replace('//', '/', $this->baseDir . $remoteDir), '/'], '', $element);
        }

        return $myList;
    }

    /**
     * I will return the correct type of directory list
     *
     * @param string $remoteDir
     *
     * @return       array | null
     */
    private function getDirlist(string $remoteDir): ?array
    {
        return ftp_nlist($this->connection, $this->baseDir . $remoteDir);
    }

    /**
     * I will return the raw directory list
     *
     * @param string $remoteDir
     *
     * @return       array
     */
    private function getRawlist(string $remoteDir): array
    {
        $data = ftp_rawlist($this->connection, $this->baseDir . $remoteDir);
        if (! is_array($data)) {
            return [];
        }

        return $data;
    }

    /**
     * @inheritDoc
     */
    public function fileGetInfo(string $remoteFile): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function dirListDetailed(string $remoteDir): array
    {
        $remoteDir = $this->normalizePath($remoteDir);
        $children  = ftp_rawlist($this->connection, $remoteDir);
        if (! is_array($children)) {
            return [];
        }
        $items = [];
        foreach ($children as $child) {
            $chunks = [];
            if ($child) {
                $chunks = preg_split("/\s+/", $child);
            }
            [$item['permissions'], $item['number'], $item['user'], $item['group'], $item['size'], $month, $day, $time] = $chunks;
            $item['type']       = strncmp($chunks[0], 'd', 1) === 0 ? Filesystem::TYPE_DIRECTORY : Filesystem::TYPE_FILE;
            $item['lastchange'] = $day . '.' . $month . ' ' . $time;
            array_splice($chunks, 0, 8);
            $name = implode(' ', $chunks);
            if ($name === '..') {
                continue;
            }
            $item['name'] = $name;
            $items[$name] = new FtpFile($item);
        }
        ksort($items);

        return $items;
    }

    /**
     * @inheritDoc
     */
    public function dirCreate(string $remoteDir): bool
    {
        $remoteDir = $this->normalizePath($remoteDir);
        if ($this->dirAvailable($remoteDir)) {
            return true;
        }
        ftp_mkdir($this->connection, $remoteDir);

        return $this->dirAvailable($remoteDir);
    }

    /**
     * @inheritDoc
     */
    public function dirDelete(string $remoteDir): bool
    {
        $remoteDir = $this->normalizePath($remoteDir);
        if (! $this->isDir($remoteDir)) {
            return false;
        }
        $elements = $this->dirList($remoteDir);
        foreach ($elements as $element) {
            $element = $remoteDir . '/' . $element;
            if ($this->isDir($element)) {
                if (! $this->dirDelete($element)) {
                    return false;
                }
            } elseif (! $this->fileDelete($element)) {
                return false;
            }
        }
        ftp_rmdir($this->connection, $remoteDir);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function isDir(string $remoteDir): bool
    {
        if ($remoteDir === '/') {
            return true;
        }
        if (! $this->pathExists($remoteDir)) {
            return false;
        }
        foreach ($this->getRawlist(FilesystemHelper::getDirectory($remoteDir)) as $file) {
            if (str_contains($file, $remoteDir)) {
                return $file[0] !== '-';
            }
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function fileDelete(string $remoteFile): bool
    {
        if (! $this->fileAvailable($remoteFile)) {
            $this->errorStack->addError('REMOTE_FILE_NOT_FOUND', $remoteFile);

            return true;
        }
        ftp_delete($this->connection, $this->baseDir . $remoteFile);

        return ! $this->fileAvailable($remoteFile);
    }

    /**
     * @inheritDoc
     */
    protected function sendFile(string $localFile, string $remoteFile): bool
    {
        ftp_put($this->connection, $this->baseDir . $remoteFile, $localFile);

        return $this->fileAvailable($remoteFile);
    }

    /**
     * @inheritDoc
     */
    protected function pullFile(string $remoteFile, string $localFile): void
    {
        ftp_get($this->connection, $localFile, $this->normalizePath($remoteFile));
    }
}

