<?php

namespace Katalysis\NeuronAi\Toolkits;

use NeuronAI\Tools\Toolkits\AbstractToolkit;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\PropertyType;
use Concrete\Core\File\File;
use Concrete\Core\File\FileList;
use Concrete\Core\Tree\Node\Type\FileFolder;
use Concrete\Core\Permission\Checker;

/**
 * Native Concrete CMS Files Toolkit
 * 
 * Provides 4 tools for managing files:
 * - list_files: List files in the file manager
 * - get_file: Get detailed file information
 * - delete_file: Delete files
 * - list_file_folders: List folders in the file manager
 */
class FilesToolkit extends AbstractToolkit
{
    public function provide(): array
    {
        return [
            $this->createListFilesTool(),
            $this->createGetFileTool(),
            $this->createDeleteFileTool(),
            $this->createListFileFoldersTool(),
        ];
    }

    /**
     * Tool: list_files
     * List files in the file manager
     */
    protected function createListFilesTool(): Tool
    {
        $tool = new Tool(
            'list_files',
            'List files in the file manager with optional filtering',
            [
                ToolProperty::make('limit', PropertyType::INTEGER, 'Maximum number of files to return (default: 20, max: 100)', false),
                ToolProperty::make('extension', PropertyType::STRING, 'Filter by file extension (e.g., "pdf", "jpg")', false),
                ToolProperty::make('keywords', PropertyType::STRING, 'Search by keywords in title or description', false),
            ]
        );

        $tool->setCallable(function(?int $limit = null, ?string $extension = null, ?string $keywords = null): string {
            try {
                $fl = new FileList();
                $fl->ignorePermissions();
                
                // Apply filters
                if ($extension) {
                    $fl->filterByExtension($extension);
                }
                
                if ($keywords) {
                    $fl->filterByKeywords($keywords);
                }
                
                $fl->setItemsPerPage(min($limit ?? 20, 100));
                $fl->sortByDateAddedDescending();
                
                $files = $fl->getResults();
                
                if (empty($files)) {
                    return "No files found.";
                }
                
                $count = count($files);
                $output = "📁 **Found {$count} file(s):**\n\n";
                
                foreach ($files as $file) {
                    $fv = $file->getApprovedVersion();
                    if (!$fv) continue;
                    
                    $title = $fv->getTitle() ?: $fv->getFileName();
                    $fileName = $fv->getFileName();
                    $fID = $file->getFileID();
                    $size = $fv->getFullSize();
                    $ext = $fv->getExtension();
                    $dateAdded = $file->getDateAdded()->format('Y-m-d H:i:s');
                    $url = $fv->getURL();
                    
                    $output .= "**{$title}**\n";
                    $output .= "- File: `{$fileName}`\n";
                    $output .= "- ID: {$fID}\n";
                    $output .= "- Type: {$ext}\n";
                    $output .= "- Size: {$size}\n";
                    $output .= "- Added: {$dateAdded}\n";
                    $output .= "- URL: {$url}\n\n";
                }
                
                return $output;
                
            } catch (\Exception $e) {
                return "❌ **Error listing files:** " . $e->getMessage();
            }
        });

        return $tool;
    }

    /**
     * Tool: get_file
     * Get detailed information about a specific file
     */
    protected function createGetFileTool(): Tool
    {
        $tool = new Tool(
            'get_file',
            'Get detailed information about a specific file by ID',
            [
                ToolProperty::make('fileID', PropertyType::INTEGER, 'File ID to retrieve', true),
            ]
        );

        $tool->setCallable(function(?int $fileID): string {
            try {
                if (!$fileID) {
                    return "❌ File ID is required.";
                }

                $file = File::getByID($fileID);
                
                if (!$file) {
                    return "❌ File not found (ID: {$fileID}).";
                }

                $fv = $file->getApprovedVersion();
                if (!$fv) {
                    return "❌ File has no approved version.";
                }
                
                $title = $fv->getTitle() ?: $fv->getFileName();
                $fileName = $fv->getFileName();
                $description = $fv->getDescription();
                $ext = $fv->getExtension();
                $size = $fv->getFullSize();
                $type = $fv->getType();
                $dateAdded = $file->getDateAdded()->format('Y-m-d H:i:s');
                $url = $fv->getURL();
                $downloadURL = $fv->getForceDownloadURL();
                
                // Get folder info
                $folder = $file->getFileFolderObject();
                $folderName = $folder ? $folder->getTreeNodeDisplayName() : 'File Manager';
                
                $output = "📁 **File Details**\n\n";
                $output .= "**{$title}**\n\n";
                $output .= "| Property | Value |\n";
                $output .= "|----------|-------|\n";
                $output .= "| ID | {$fileID} |\n";
                $output .= "| File Name | `{$fileName}` |\n";
                $output .= "| Type | {$type} ({$ext}) |\n";
                $output .= "| Size | {$size} |\n";
                $output .= "| Folder | {$folderName} |\n";
                $output .= "| Added | {$dateAdded} |\n";
                $output .= "| URL | {$url} |\n";
                $output .= "| Download | {$downloadURL} |\n";
                
                if ($description) {
                    $output .= "\n**Description:** {$description}\n";
                }
                
                return $output;
                
            } catch (\Exception $e) {
                return "❌ **Error getting file:** " . $e->getMessage();
            }
        });

        return $tool;
    }

    /**
     * Tool: delete_file
     * Delete a file from the file manager
     */
    protected function createDeleteFileTool(): Tool
    {
        $tool = new Tool(
            'delete_file',
            'Delete a file from the file manager',
            [
                ToolProperty::make('fileID', PropertyType::INTEGER, 'File ID to delete', true),
            ]
        );

        $tool->setCallable(function(?int $fileID): string {
            try {
                if (!$fileID) {
                    return "❌ File ID is required.";
                }

                $file = File::getByID($fileID);
                
                if (!$file) {
                    return "❌ File not found (ID: {$fileID}).";
                }

                // Check permissions
                $fp = new Checker($file);
                if (!$fp->canDeleteFile()) {
                    return "❌ Error: You don't have permission to delete this file.";
                }

                $fv = $file->getApprovedVersion();
                $fileName = $fv ? $fv->getFileName() : "File #{$fileID}";
                
                // Delete the file
                $file->delete();
                
                return "✅ **File deleted successfully!**\n\n" .
                       "- **File:** `{$fileName}`\n" .
                       "- **ID:** {$fileID}";
                
            } catch (\Exception $e) {
                return "❌ **Error deleting file:** " . $e->getMessage();
            }
        });

        return $tool;
    }

    /**
     * Tool: list_file_folders
     * List folders in the file manager
     */
    protected function createListFileFoldersTool(): Tool
    {
        $tool = new Tool(
            'list_file_folders',
            'List folders in the file manager. Returns folder structure.',
            []
        );

        $tool->setCallable(function(): string {
            try {
                $fileManager = \Core::make('app')->make('Concrete\Core\Tree\Type\FileManager');
                $rootNode = $fileManager->getRootTreeNodeObject();
                
                if (!$rootNode) {
                    return "❌ Could not access file manager folders.";
                }
                
                $output = "📂 **File Manager Folders:**\n\n";
                
                // Get all folder nodes
                $folders = [];
                $this->collectFolders($rootNode, $folders);
                
                if (empty($folders)) {
                    return "No folders found in file manager.";
                }
                
                foreach ($folders as $folder) {
                    $name = $folder['name'];
                    $id = $folder['id'];
                    $depth = $folder['depth'];
                    $prefix = str_repeat('  ', $depth);
                    
                    $output .= "{$prefix}📁 **{$name}** (ID: {$id})\n";
                }
                
                return $output;
                
            } catch (\Exception $e) {
                return "❌ **Error listing folders:** " . $e->getMessage();
            }
        });

        return $tool;
    }

    /**
     * Helper to recursively collect folders
     */
    private function collectFolders($node, &$folders, $depth = 0)
    {
        $children = $node->getChildNodes();
        
        foreach ($children as $child) {
            if ($child instanceof FileFolder) {
                $folders[] = [
                    'name' => $child->getTreeNodeDisplayName(),
                    'id' => $child->getTreeNodeID(),
                    'depth' => $depth,
                ];
                
                // Recurse into subfolders
                $this->collectFolders($child, $folders, $depth + 1);
            }
        }
    }
}
