<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use Concrete\Core\File\File;
use Concrete\Core\Entity\File\File as FileEntity;
use Concrete\Core\File\FileList;
use Concrete\Core\File\Importer;
use Concrete\Core\Permission\Checker;
use Concrete\Core\Tree\Node\Type\FileFolder;
use Concrete\Core\Support\Facade\Database;
use Concrete\Core\User\User;
use Katalysis\NeuronAi\Access\ObjectPermissions;
use NeuronAI\Tools\Tool;
use Katalysis\NeuronAi\Tools\Support\CallableTool;
use Katalysis\NeuronAi\Tools\Utilities\ListWindow;
use Katalysis\NeuronAi\Tools\Utilities\PermittedWindow;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * Concrete CMS Files toolkit for Neuron AI
 * 
 * Provides native tools for file management without HTTP overhead.
 */
class FilesToolkit extends AbstractToolkit
{
    public function guidelines(): ?string
    {
        return "Use these tools to manage files in Concrete CMS. Use resolve_file_id_from_path before assigning image/file references: Concrete URL folders like /application/files/9215/0056/1806/... are storage segments, not the fID. Never infer fID from URL path numbers. Use create_file_folder to create folders in the file manager, and move_file_to_folder to organize files into folders.";
    }

    public function provide(): array
    {
        return [
            $this->makeListFilesTool(),
            $this->makeGetFileTool(),
            $this->makeCreateFileFolderTool(),
            $this->makeMoveFileToFolderTool(),
            $this->makeResolveFileIdFromPathTool(),
            $this->makeDeleteFileTool(),
            $this->makeGetFileFoldersTool(),
        ];
    }

    protected function makeMoveFileToFolderTool(): Tool
    {
        $tool = new CallableTool(
            'move_file_to_folder',
            'Move a file into a target file manager folder.'
        );

        $tool->addProperty(new ToolProperty(
            'fileID',
            PropertyType::STRING,
            'ID or UUID of the file to move',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'folderID',
            PropertyType::INTEGER,
            'Target folder ID in the file manager',
            true
        ));

        $tool->setHandler($this->guarded(function (?string $fileID = null, ?int $folderID = null): array {
            if (!$fileID || trim($fileID) === '') {
                throw new \RuntimeException('move_file_to_folder needs fileID: the file\'s ID or UUID, from list_files. Nothing was changed.');
            }
            if (!$folderID || $folderID <= 0) {
                throw new \RuntimeException('move_file_to_folder needs folderID: the target folder\'s ID, from list_file_folders. Nothing was changed.');
            }

            $fileID = trim($fileID);
            $file = File::getByUUIDOrID($fileID);
            if ((!$file || $file->isError()) && ctype_digit($fileID)) {
                $file = File::getByID((int) $fileID);
            }

            if (!$file || $file->isError()) {
                throw new \RuntimeException(self::fileNotFound($fileID));
            }

            $destinationFolder = FileFolder::getByID((int) $folderID);
            if (!$destinationFolder) {
                throw new \RuntimeException(self::folderNotFound((int) $folderID));
            }

            // P3.5-1: the file manager's own checks: the file's Edit File Properties, the target's Add File.
            ObjectPermissions::deny(ObjectPermissions::editFileProperties($file));
            ObjectPermissions::deny(ObjectPermissions::addFile($destinationFolder));

            $sourceNode = $file->getFileNodeObject();
            if (!$sourceNode) {
                throw new \RuntimeException("File {$fileID} has no place in the file manager's folders, so it can't be moved. Nothing was changed.");
            }

            $currentFolder = $file->getFileFolderObject();
            $currentFolderID = $currentFolder ? (int) $currentFolder->getTreeNodeID() : null;
            if ($currentFolderID === (int) $folderID) {
                return [
                    'moved' => false,
                    'message' => 'File is already in the destination folder',
                    'file' => $this->serializeFile($file, includeDetails: true),
                    'sourceFolderID' => $currentFolderID,
                    'destinationFolderID' => (int) $folderID,
                ];
            }

            $sourceNode->move($destinationFolder);

            return [
                'moved' => true,
                'message' => 'File moved successfully',
                'file' => $this->serializeFile($file, includeDetails: true),
                'sourceFolderID' => $currentFolderID,
                'destinationFolderID' => (int) $folderID,
                'destinationFolderPath' => $destinationFolder->getTreeNodeDisplayPath(),
            ];
        }));

        return $tool;
    }

    protected function makeCreateFileFolderTool(): Tool
    {
        $tool = new CallableTool(
            'create_file_folder',
            'Create a folder in the Concrete CMS file manager (supports single folder name or nested folder path).'
        );

        $tool->addProperty(new ToolProperty(
            'name',
            PropertyType::STRING,
            'Folder name, or nested path when createParents=true (e.g. "Media/People/2026")',
            true
        ));

        $tool->addProperty(new ToolProperty(
            'parentFolderID',
            PropertyType::INTEGER,
            'Optional parent folder ID; defaults to File Manager root folder',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'createParents',
            PropertyType::BOOLEAN,
            'If true, create missing folders in a nested path (default: false)',
            false
        ));

        $tool->setHandler($this->guarded(function (
            ?string $name = null,
            ?int $parentFolderID = null,
            ?bool $createParents = false
        ): array {
            $name = trim((string) $name);
            if ($name === '') {
                throw new \RuntimeException('create_file_folder needs name: the new folder\'s name. Nothing was changed.');
            }

            $filesystem = new \Concrete\Core\File\Filesystem();
            $root = $filesystem->getRootFolder();
            if (!$root) {
                throw new \RuntimeException('The file manager has no root folder on this site, so no folder can be made. Tell the user. Nothing was changed.');
            }

            $parent = $root;
            if ($parentFolderID !== null && $parentFolderID > 0) {
                $candidate = FileFolder::getByID((int) $parentFolderID);
                if (!$candidate) {
                    throw new \RuntimeException(self::folderNotFound((int) $parentFolderID));
                }
                $parent = $candidate;
            }

            ObjectPermissions::deny(ObjectPermissions::addFile($parent));

            $segments = array_values(array_filter(
                explode('/', str_replace('\\\\', '/', $name)),
                static fn(string $part): bool => trim($part) !== ''
            ));

            if ($segments === []) {
                throw new \RuntimeException("'{$name}' is not a folder name: give a name, or a path like \"Media/People\". Nothing was changed.");
            }

            if (($createParents ?? false) !== true && count($segments) > 1) {
                throw new \RuntimeException("'{$name}' is a nested path. Send createParents=true to make the missing folders too. Nothing was changed.");
            }

            $createdAny = false;
            $current = $parent;

            foreach ($segments as $index => $segment) {
                $clean = trim($segment);
                if ($clean === '' || $clean === '.' || $clean === '..') {
                    throw new \RuntimeException("'{$segment}' can't be a folder name. " . ($createdAny ? 'The folders before it were made.' : 'Nothing was changed.'));
                }

                $existing = $current->getChildFolderByName($clean, false);
                if ($existing instanceof FileFolder) {
                    $current = $existing;
                    continue;
                }

                if ($index > 0 && ($createParents ?? false) !== true) {
                    throw new \RuntimeException("The folder above '{$clean}' doesn't exist. Send createParents=true to make it. Nothing was changed.");
                }

                // P3.5-1: Add File on every folder a new one goes into, not only the first.
                ObjectPermissions::deny(ObjectPermissions::addFile($current));
                $current = $filesystem->addFolder($current, $clean);
                $createdAny = true;
            }

            return [
                'created' => $createdAny,
                'folder' => [
                    'id' => $current->getTreeNodeID(),
                    'name' => $current->getTreeNodeDisplayName('text'),
                    'path' => $current->getTreeNodeDisplayPath(),
                ],
                'parentFolderID' => $parent->getTreeNodeID(),
                'inputName' => $name,
            ];
        }));

        return $tool;
    }

    protected function makeResolveFileIdFromPathTool(): Tool
    {
        $tool = new CallableTool(
            'resolve_file_id_from_path',
            'Resolve a Concrete CMS file URL/path to the actual file ID (fID). Never infer fID from URL folder segments.'
        );

        $tool->addProperty(new ToolProperty(
            'path',
            PropertyType::STRING,
            'File URL or path (e.g. https://site.com/application/files/9215/0056/1806/photo.jpg)',
            true
        ));

        $tool->setHandler($this->guarded(function (?string $path = null): array {
            if (!$path || trim($path) === '') {
                throw new \RuntimeException('path is required');
            }

            $normalizedPath = $this->normalizeFilePath($path);
            $fileName = basename($normalizedPath);
            if (!$fileName || $fileName === '.' || $fileName === '/') {
                throw new \RuntimeException('Unable to extract filename from the provided path');
            }

            $prefix = $this->extractConcretePrefix($normalizedPath);
            $db = Database::connection();

            $match = null;
            $matchType = null;

            if ($prefix !== null) {
                $match = $db->fetchAssociative(
                    'SELECT fID, fvPrefix, fvFilename FROM FileVersions WHERE fvFilename = ? AND fvPrefix = ? ORDER BY fvDateAdded DESC LIMIT 1',
                    [$fileName, $prefix]
                );
                $matchType = $match ? 'exact_prefix' : null;
            }

            if (!$match && $prefix !== null) {
                $match = $db->fetchAssociative(
                    'SELECT fID, fvPrefix, fvFilename FROM FileVersions WHERE fvFilename = ? AND fvPrefix LIKE ? ORDER BY LENGTH(fvPrefix) DESC, fvDateAdded DESC LIMIT 1',
                    [$fileName, $prefix . '%']
                );
                $matchType = $match ? 'prefix_partial' : null;
            }

            if (!$match) {
                $match = $db->fetchAssociative(
                    'SELECT fID, fvPrefix, fvFilename FROM FileVersions WHERE fvFilename = ? ORDER BY fvDateAdded DESC LIMIT 1',
                    [$fileName]
                );
                $matchType = $match ? 'filename_only' : null;
            }

            if (!$match) {
                return [
                    'resolved' => false,
                    'inputPath' => $path,
                    'normalizedPath' => $normalizedPath,
                    'fileName' => $fileName,
                    'prefix' => $prefix,
                    'message' => 'No matching file version found for this path',
                ];
            }

            $fID = (int) ($match['fID'] ?? 0);
            $file = $fID > 0 ? File::getByID($fID) : null;
            if (!$file || $file->isError()) {
                return [
                    'resolved' => false,
                    'inputPath' => $path,
                    'normalizedPath' => $normalizedPath,
                    'fileName' => $fileName,
                    'prefix' => $prefix,
                    'matchedID' => $fID,
                    'matchType' => $matchType,
                    'message' => 'A database match was found, but the file record could not be loaded',
                ];
            }

            $checker = new Checker($file);
            if (!$checker->canViewFileInFileManager()) {
                throw new \RuntimeException("Permission denied: Cannot view resolved file {$fID}");
            }

            $details = $this->serializeFile($file, includeDetails: true);

            return [
                'resolved' => true,
                'fileID' => $fID,
                'matchType' => $matchType,
                'confidence' => $matchType === 'exact_prefix' ? 'high' : ($matchType === 'prefix_partial' ? 'medium' : 'low'),
                'inputPath' => $path,
                'normalizedPath' => $normalizedPath,
                'fileName' => $fileName,
                'prefix' => $prefix,
                'matchedPrefix' => (string) ($match['fvPrefix'] ?? ''),
                'file' => $details,
                'message' => 'Resolved using FileVersions lookup. Do not use URL folder numbers as fID.',
            ];
        }));

        return $tool;
    }

    private function normalizeFilePath(string $input): string
    {
        $input = trim($input);
        $decoded = rawurldecode($input);
        $parts = parse_url($decoded);

        if (is_array($parts) && isset($parts['path']) && is_string($parts['path'])) {
            return $parts['path'];
        }

        return $decoded;
    }

    private function extractConcretePrefix(string $path): ?string
    {
        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $trimmed), static fn(string $part): bool => $part !== ''));
        $filesIndex = array_search('files', $segments, true);
        if ($filesIndex === false) {
            return null;
        }

        $afterFiles = array_slice($segments, $filesIndex + 1);
        if (count($afterFiles) < 2) {
            return null;
        }

        $prefixSegments = array_slice($afterFiles, 0, -1);
        $prefixSegments = array_values(array_filter($prefixSegments, static function (string $part): bool {
            return preg_match('/^[0-9]+$/', $part) === 1;
        }));

        if (empty($prefixSegments)) {
            return null;
        }

        return implode('/', $prefixSegments);
    }

    protected function makeListFilesTool(): Tool
    {
        $tool = new CallableTool(
            'list_files',
            'List files in the file manager with optional filtering, newest first. '
            . 'The result gives the total and whether there are more; use offset for the next files.'
        );

        $tool->addProperty(new ToolProperty(
            'limit',
            PropertyType::INTEGER,
            'Maximum number of files to return (1-100, default 20)',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'offset',
            PropertyType::INTEGER,
            ListWindow::OFFSET_DESCRIPTION,
            false
        ));

        $tool->addProperty(new ToolProperty(
            'folderID',
            PropertyType::INTEGER,
            'Filter by folder ID',
            false
        ));

        $tool->addProperty(new ToolProperty(
            'extension',
            PropertyType::STRING,
            'Filter by file extension (e.g., "pdf", "jpg")',
            false
        ));

        $tool->setHandler($this->guarded(function (
            ?int $limit = 20,
            ?int $folderID = null,
            ?string $extension = null,
            ?int $offset = null
        ): array {
            $window = ListWindow::of($limit, $offset, 20, 100);
            $list = new FileList();
            $list->setPermissionsChecker(function ($file) {
                $permissions = new Checker($file);
                return $permissions->canViewFileInFileManager();
            });
            $list->sortByDateAddedDescending();
            $list->getQueryObject()->addOrderBy('f.fID', 'desc');
            
            if ($folderID) {
                $folder = FileFolder::getByID($folderID);
                if ($folder) {
                    $list->filterByFileFolder($folder);
                }
            }
            
            if ($extension) {
                $list->filterByExtension($extension);
            }
            
            // BF-6: with a permission checker Concrete has no count query, so the permitted matches are counted here.
            // P3.3-5: only until the window is full and the check limit is reached (PermittedWindow).
            $found = PermittedWindow::of($list, $window);

            // FileList returns FileEntity objects
            $files = array_map(fn($file) => $this->serializeFile($file), $found['items']);

            return ['files' => $files] + $window->summary($found['total'], count($files), 'list_files', 'folderID or extension', $found['exact']);
        }));

        return $tool;
    }

    protected function makeGetFileTool(): Tool
    {
        $tool = new CallableTool(
            'get_file',
            'Retrieve detailed information about a specific file by ID'
        );

        $tool->addProperty(new ToolProperty(
            'fileID',
            PropertyType::STRING,
            'ID or UUID of the file to retrieve',
            true
        ));

        $tool->setHandler($this->guarded(function (?string $fileID = null): array {
            if (!$fileID) {
                throw new \RuntimeException("fileID is required");
            }

            $fileID = trim($fileID);
            $file = File::getByUUIDOrID($fileID);
            if ((!$file || $file->isError()) && ctype_digit($fileID)) {
                $file = File::getByID((int) $fileID);
            }
            
            if (!$file || $file->isError()) {
                throw new \RuntimeException("File with ID {$fileID} not found");
            }

            $checker = new Checker($file);
            if (!$checker->canViewFileInFileManager()) {
                throw new \RuntimeException("Permission denied: Cannot view file {$fileID}");
            }
            
            return $this->serializeFile($file, includeDetails: true);
        }));

        return $tool;
    }

    protected function makeDeleteFileTool(): Tool
    {
        $tool = new CallableTool(
            'delete_file',
            'Delete a file from the file manager'
        );

        $tool->addProperty(new ToolProperty(
            'fileID',
            PropertyType::INTEGER,
            'ID of the file to delete',
            true
        ));

        $tool->setHandler($this->guarded(function (?int $fileID = null): array {
            if (!$fileID) {
                throw new \RuntimeException('delete_file needs fileID: the file\'s ID, from list_files. Nothing was changed.');
            }
            $file = File::getByID($fileID);

            if (!$file || $file->isError()) {
                throw new \RuntimeException(self::fileNotFound((string) $fileID));
            }

            ObjectPermissions::deny(ObjectPermissions::deleteFile($file));

            $file->delete();
            
            return [
                'id' => $fileID,
                'status' => 'deleted',
                'message' => 'File deleted successfully'
            ];
        }));

        return $tool;
    }

    protected function makeGetFileFoldersTool(): Tool
    {
        $tool = new CallableTool(
            'list_file_folders',
            'List all file manager folders'
        );

        $tool->setHandler($this->guarded(function (): array {
            // Get file manager tree
            $filesystem = new \Concrete\Core\File\Filesystem();
            $rootFolder = $filesystem->getRootFolder();

            if (!$rootFolder) {
                return ['folders' => []];
            }

            $folders = [];
            $this->collectFolders($rootFolder, $folders);

            return ['folders' => $folders];
        }));

        return $tool;
    }

    protected function collectFolders(FileFolder $folder, array &$folders): void
    {
        $folders[] = [
            'id' => $folder->getTreeNodeID(),
            'name' => $folder->getTreeNodeDisplayName(),
            'path' => $folder->getTreeNodeDisplayPath(),
        ];
        
        foreach ($folder->getChildNodes() as $child) {
            if ($child instanceof FileFolder) {
                $this->collectFolders($child, $folders);
            }
        }
    }

    protected function serializeFile(FileEntity $file, bool $includeDetails = false): array
    {
        $version = $file->getVersion();
        
        $data = [
            'id' => $file->getFileID(),
            'name' => $file->getFileName(),
            'title' => $file->getTitle(),
            'extension' => $file->getExtension(),
            'size' => $file->getSize(),
            'url' => $file->getURL(),
            'dateAdded' => $file->getDateAdded()->format('Y-m-d H:i:s'),
        ];

        if ($includeDetails && $version) {
            $data['mimeType'] = $version->getMimeType();
            $data['description'] = $version->getDescription();
            $data['tags'] = $version->getTags();
            $data['author'] = $version->getAuthorName();
            
            // Try to get thumbnail if available
            try {
                $thumbnailURL = $version->getThumbnailURL('file_manager_listing');
                if ($thumbnailURL) {
                    $data['thumbnail'] = $thumbnailURL;
                }
            } catch (\Exception $e) {
                // Thumbnail not available
            }
        }

        return $data;
    }

    /**
     * P3.5-1 (P3.3-2's standard): what is wrong, the next step, and that nothing was changed.
     */
    private static function fileNotFound(string $fileID): string
    {
        return "File {$fileID} not found: it may have been deleted, or the ID is wrong. Find it with list_files or "
            . 'resolve_file_id_from_path and use the ID they give. Nothing was changed.';
    }

    private static function folderNotFound(int $folderID): string
    {
        return "Folder {$folderID} not found: it may have been deleted, or the ID is wrong. list_file_folders lists "
            . 'the folders and their IDs. Nothing was changed.';
    }

    private function guarded(callable $callback): callable
    {
        return function (...$args) use ($callback): array {
            try {
                // P3.5-1: a login check, as every other toolkit's guarded() makes (this one checked nothing).
                if (!(new User())->isRegistered()) {
                    throw new \RuntimeException('Authentication required: Please log in to use this tool.');
                }
                $result = $callback(...$args);

                return $this->normalizeToolResult($result);
            } catch (\Throwable $e) {
                return [
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];
            }
        };
    }

    private function normalizeToolResult($result): array
    {
        if (!is_array($result)) {
            return [
                'ok' => true,
                'result' => $result,
                'error' => null,
            ];
        }

        if ($this->isListArray($result)) {
            return [
                'ok' => true,
                'items' => $result,
                'error' => null,
            ];
        }

        if (!array_key_exists('ok', $result)) {
            $result['ok'] = true;
        }
        if (!array_key_exists('error', $result)) {
            $result['error'] = null;
        }

        return $result;
    }

    private function isListArray(array $array): bool
    {
        return array_values($array) === $array;
    }
}
