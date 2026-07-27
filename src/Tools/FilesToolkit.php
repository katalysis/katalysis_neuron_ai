<?php

declare(strict_types=1);

namespace Katalysis\NeuronAi\Tools;

use Concrete\Core\File\File;
use Concrete\Core\Entity\File\File as FileEntity;
use Concrete\Core\File\FileList;
use Concrete\Core\File\Importer;
use Concrete\Core\Permission\Checker;
use Concrete\Core\Tree\Node\Type\FileFolder;
use NeuronAI\Tools\Tool;
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
        return "Use these tools to manage files in Concrete CMS. You can list, upload, update, and delete files. Always check permissions before modifying content.";
    }

    public function provide(): array
    {
        return [
            $this->makeListFilesTool(),
            $this->makeGetFileTool(),
            $this->makeDeleteFileTool(),
            $this->makeGetFileFoldersTool(),
        ];
    }

    protected function makeListFilesTool(): Tool
    {
        $tool = new Tool(
            'list_files',
            'List files in the file manager with optional filtering'
        );

        $tool->addProperty(new ToolProperty(
            'limit',
            PropertyType::INTEGER,
            'Maximum number of files to return (1-100)',
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

        $tool->setCallable($this->guarded(function (
            ?int $limit = 20,
            ?int $folderID = null,
            ?string $extension = null
        ): array {
            $list = new FileList();
            $list->setPermissionsChecker(function ($file) {
                $permissions = new Checker($file);
                return $permissions->canViewFileInFileManager();
            });
            $list->sortByDateAddedDescending();
            
            if ($folderID) {
                $folder = FileFolder::getByID($folderID);
                if ($folder) {
                    $list->filterByFileFolder($folder);
                }
            }
            
            if ($extension) {
                $list->filterByExtension($extension);
            }
            
            $list->setItemsPerPage(min(max($limit, 1), 100));
            $results = $list->getResults();
            
            // FileList returns FileEntity objects
            return array_map(fn($file) => $this->serializeFile($file), $results);
        }));

        return $tool;
    }

    protected function makeGetFileTool(): Tool
    {
        $tool = new Tool(
            'get_file',
            'Retrieve detailed information about a specific file by ID'
        );

        $tool->addProperty(new ToolProperty(
            'fileID',
            PropertyType::STRING,
            'ID or UUID of the file to retrieve',
            true
        ));

        $tool->setCallable($this->guarded(function (?string $fileID = null): array {
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
        $tool = new Tool(
            'delete_file',
            'Delete a file from the file manager'
        );

        $tool->addProperty(new ToolProperty(
            'fileID',
            PropertyType::INTEGER,
            'ID of the file to delete',
            true
        ));

        $tool->setCallable($this->guarded(function (?int $fileID = null): array {
            if (!$fileID) {
                throw new \RuntimeException("fileID is required");
            }
            $file = File::getByID($fileID);
            
            if (!$file || $file->isError()) {
                throw new \RuntimeException("File with ID {$fileID} not found");
            }
            
            // Check permissions
            $checker = new Checker($file);
            if (!$checker->canDeleteFile()) {
                throw new \RuntimeException("Permission denied: Cannot delete file {$fileID}");
            }
            
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
        $tool = new Tool(
            'list_file_folders',
            'List all file manager folders'
        );

        $tool->setCallable($this->guarded(function (): array {
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

    private function guarded(callable $callback): callable
    {
        return function (...$args) use ($callback): array {
            try {
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
