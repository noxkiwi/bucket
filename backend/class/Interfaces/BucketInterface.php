<?php declare(strict_types = 1);
namespace noxkiwi\bucket\Interfaces;

/**
 * I am the class interface for any Bucket driver that may exist one day.
 * <br />The noxkiwi Bucket Classes may handle a variety of different storage drivers.
 * <br />e.G. FTP, FTP(s), DropBox, HiDrive, WebDAV, SMB, Local, AmazonS3, etc will be handled in the same way using
 * this interface.
 *
 * @package      noxkiwi\bucket
 * @author       Jan Nox <jan.nox@pm.me>
 * @license      https://nox.kiwi/license
 * @copyright    2020 noxkiwi
 * @version      1.0.0
 * @link         https://nox.kiwi/
 */
interface BucketInterface
{
    /**
     * I will return true if the local $file could be pushed to $destination on the remote storage.
     * Pushing the file will fail if...
     *   - The remote file already exists.
     *   - The local file does not exist.
     *   - The remote file exists after pushing.
     *
     * @param string $localFile
     * @param string $remoteFile
     *
     * @return       bool
     */
    public function filePush(string $localFile, string $remoteFile): bool;

    /**
     * I will return true if the remote $file could be pulled to the local $file.
     * Pulling the file will fail if...
     *   - The remote file does not exist.
     *   - The local file already exists.
     *   - The local file does not exist after pulling.
     *
     * @param string $remoteFile
     * @param string $localFile
     *
     * @return       bool
     */
    public function filePull(string $remoteFile, string $localFile): bool;

    /**
     * I will return true if the $remotefile exists in the Bucket and the element actually is a file.
     *
     * @param string $remoteFile
     *
     * @return       bool
     */
    public function fileAvailable(string $remoteFile): bool;

    /**
     * I will return true if rhe given $file could be deleted from the Bucket.
     * Deleting the file from remote fails if...
     *    - The remote file exists after deleting.
     *
     * @param string $remoteFile
     *
     * @return       bool
     */
    public function fileDelete(string $remoteFile): bool;

    /**
     * I will return the correct URL for the given $file if the bucket is public.
     * I may return an empty string if the bucket is not public.
     *
     * @param string $remoteFile
     *
     * @throws \noxkiwi\bucket\Exception\FileHandlingException
     *
     * @return       string
     */
    public function fileGetUrl(string $remoteFile): string;

    /**
     * I will return an array of information about the given $remotefile.
     *
     * @example              file 'doggo.jpg'     folder 'test'
     *                       name                         doggo  |  test
     *                       size                           533  |  0
     *                       extension                      jpg  |  ''
     *                       type                          file  |  directory
     *
     * @param string $remoteFile
     *
     * @return       array
     */
    public function fileGetInfo(string $remoteFile): array;

    /**
     * I will return an array of elements that exist in the given $directory.
     * The result is a simple array of strings containing the names of the elements.
     *
     * @param string $remoteDir
     *
     * @return       array
     */
    public function dirList(string $remoteDir): array;

    /**
     * I will return true if the given $directory exists on the Bucket and actually is a directory.
     *
     * @param string $remoteDir
     *
     * @return       bool
     */
    public function dirAvailable(string $remoteDir): bool;

    /**
     * I will return true if the given $directory is a directory.
     *
     * @param string $remoteDir
     *
     * @return bool
     */
    public function isDir(string $remoteDir): bool;

    /**
     * I will return true if the given $remotefile exists and actually IS a file.
     *
     * @param string $remoteFile
     *
     * @return       bool
     */
    public function isFile(string $remoteFile): bool;

    /**
     * I am returning the public state of this bucket instance.
     * <br />If the bucket is public, there must be an URL to prefix the path.
     * <br />If public, getUrl() will return an actual URL.
     * <br />If not, getUrl() will return an empty string.
     *
     * @return       bool
     */
    public function isPublic(): bool;

    /**
     * I will download the given $remotefile to the Client and set the download stream's filename to $filename.
     *
     * @param string $remoteFile
     * @param string|null     $fileName
     */
    public function download(string $remoteFile, string $fileName = null): void;

    /**
     * I will return an array of File object instances to describe the content of the given $directory.
     *
     * @param string $remoteDir
     *
     * @return       array
     */
    public function dirListDetailed(string $remoteDir): array;

    /**
     * I will create the given $directory on the bucket remote.
     * Creating the directory will fail if...
     *   - The $directory does not exist after creating it.
     *
     * @param string $remoteDir
     *
     * @return       bool
     */
    public function dirCreate(string $remoteDir): bool;

    /**
     * I will remove the given $remotedir including the elements in it.
     * Removing the directory will fail if...
     *   - Any element in the $remotedir (may it be file or directory) cannot be deleted.
     *   - The $remotedir exists after deleting the elemnt.
     *
     * @param string $remoteDir
     *
     * @return       bool
     */
    public function dirDelete(string $remoteDir): bool;

    /**
     * I will return true if the given $remotePath exists in the Bucket
     *
     * @param string $remotePath
     *
     * @return bool
     */
    public function pathExists(string $remotePath): bool;
}

